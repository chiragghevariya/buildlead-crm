<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\ScoringSetting;
use App\Services\Assignment\LeadAssignmentService;
use App\Services\Scoring\LeadScoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Score a lead (rules + AI), then auto-assign it if it has no owner yet.
 */
class ScoreLeadJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Seconds to hold the unique lock if the job never finishes. */
    public int $uniqueFor = 600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Lead $lead,
        public bool $autoAssign = true,
    ) {
        $this->onQueue(config('scoring.queues.scoring', 'scoring'));
    }

    public function uniqueId(): string
    {
        return (string) $this->lead->getKey();
    }

    /** @return list<int> seconds between attempts */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(LeadScoringService $scoring, LeadAssignmentService $assignment): void
    {
        $lead = $this->lead->fresh();
        if (! $lead) {
            return; // deleted meanwhile
        }

        // The OpenAI call happens outside the transaction; persisting + assigning is atomic,
        // and HotLeadDetected (ShouldDispatchAfterCommit) fires once the assignee is saved.
        $result = $scoring->evaluate($lead);

        DB::transaction(function () use ($scoring, $assignment, $lead, $result) {
            $scoring->apply($lead, $result);

            if ($this->autoAssign && ScoringSetting::config('assignment.enabled', true) && ! $lead->assigned_to) {
                $assignment->assign($lead);
            }
        });
    }
}
