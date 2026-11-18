<?php

namespace App\Console\Commands;

use App\Jobs\ScoreLeadJob;
use App\Models\Lead;
use Illuminate\Console\Command;

class RescoreLeads extends Command
{
    protected $signature = 'leads:rescore
        {--all : Rescore every lead (default: only leads that were never scored)}
        {--lead=* : Lead ID or reference (BL-2026-0001); repeatable}
        {--sync : Run now instead of queueing}
        {--no-assign : Do not auto-assign unassigned leads}';

    protected $description = 'Re-run hybrid scoring (rules + AI) for leads';

    public function handle(): int
    {
        $identifiers = array_filter((array) $this->option('lead'));

        $query = Lead::query()->orderBy('id');
        if ($identifiers) {
            $query->where(fn ($q) => $q
                ->whereIn('reference', $identifiers)
                ->orWhereIn('id', array_filter($identifiers, 'ctype_digit')));
        } elseif (! $this->option('all')) {
            $query->whereNull('scored_at');
        }

        $ids = $query->pluck('id');
        $count = $ids->count();
        if ($count === 0) {
            $this->info($identifiers ? 'No matching leads found.' : 'Nothing to rescore.');

            return $identifiers ? self::FAILURE : self::SUCCESS;
        }

        $sync = (bool) $this->option('sync');
        $assign = ! $this->option('no-assign');

        $this->info(($sync ? 'Scoring' : 'Queueing').' '.$count.' '.str('lead')->plural($count).'…');
        $bar = $this->output->createProgressBar($count);

        foreach ($ids->chunk(100) as $chunk) {
            foreach (Lead::query()->whereIn('id', $chunk)->orderBy('id')->get() as $lead) {
                $sync
                    ? ScoreLeadJob::dispatchSync($lead, $assign)
                    : ScoreLeadJob::dispatch($lead, $assign);
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine();

        if ($sync) {
            Lead::query()->whereIn('id', $ids)
                ->selectRaw('temperature, COUNT(*) AS c')->groupBy('temperature')->pluck('c', 'temperature')
                ->each(fn ($c, $t) => $this->line("  {$t}: {$c}"));
        } else {
            $this->comment('Run `php artisan queue:work --queue=scoring,notifications,default` to process.');
        }

        return self::SUCCESS;
    }
}
