<?php

namespace App\Services\Leads;

use App\Enums\ActivityType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Jobs\ScoreLeadJob;
use App\Models\Lead;
use App\Services\Scoring\LeadScoringService;
use App\Support\LeadNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Creates leads from inbound channels, merging repeat enquiries
 * (same email or phone within the duplicate window) into the existing lead.
 */
class LeadIngestService
{
    /** Fields a repeat enquiry may fill in when the original lead left them empty. */
    private const FILLABLE_ON_DUPLICATE = [
        'company', 'email', 'phone', 'project_type', 'description', 'budget_min', 'budget_max',
        'timeline', 'street', 'postcode', 'city', 'area_sqm',
    ];

    /**
     * @return array{lead: Lead, duplicate: bool}
     */
    public function ingest(array $data, LeadSource $source, array $rawPayload = []): array
    {
        return DB::transaction(function () use ($data, $source, $rawPayload) {
            $existing = $this->findDuplicate($data['email'] ?? null, $data['phone'] ?? null);

            return $existing
                ? ['lead' => $this->mergeInto($existing, $data, $source, $rawPayload), 'duplicate' => true]
                : ['lead' => $this->create($data, $source, $rawPayload), 'duplicate' => false];
        });
    }

    public function findDuplicate(?string $email, ?string $phone): ?Lead
    {
        $since = now()->subDays((int) config('services.leads.duplicate_window_days', 30));
        $phoneKey = LeadNormalizer::phoneKey($phone);

        if (blank($email) && $phoneKey === null) {
            return null;
        }

        return Lead::query()
            ->where('created_at', '>=', $since)
            ->where(fn ($q) => $q
                ->when(filled($email), fn ($q) => $q->orWhereRaw('LOWER(email) = ?', [mb_strtolower($email)]))
                ->when($phoneKey !== null, fn ($q) => $q->orWhereNotNull('phone')))
            ->latest('created_at')
            ->get()
            // Phone formats vary ("030 123…", "+49 30 123…"): compare normalised in PHP.
            ->first(fn (Lead $lead) => (filled($email) && mb_strtolower((string) $lead->email) === mb_strtolower($email))
                || ($phoneKey !== null && LeadNormalizer::phoneKey($lead->phone) === $phoneKey));
    }

    private function create(array $data, LeadSource $source, array $rawPayload): Lead
    {
        $lead = Lead::create(array_merge($data, [
            'source' => $source,
            'status' => LeadStatus::New,
            'consent_given' => true,
            'consent_at' => now(),
            'raw_payload' => $rawPayload,
            'estimated_value' => $data['budget_max'] ?? $data['budget_min'] ?? null,
        ]));

        $lead->activities()->create([
            'user_id' => null,
            'type' => ActivityType::Created,
            'title' => 'Lead received via '.$source->label(),
            'meta' => ['source' => $source->value],
        ]);

        // After commit: the worker must be able to load the lead.
        ScoreLeadJob::dispatch($lead)->afterCommit();

        return $lead;
    }

    private function mergeInto(Lead $lead, array $data, LeadSource $source, array $rawPayload): Lead
    {
        $filled = [];
        foreach (self::FILLABLE_ON_DUPLICATE as $field) {
            if (blank($lead->{$field}) && filled($data[$field] ?? null)) {
                $lead->{$field} = $data[$field];
                $filled[] = $field;
            }
        }
        $lead->save(); // also bumps updated_at so the lead surfaces again

        $lead->activities()->create([
            'user_id' => null,
            'type' => ActivityType::Note,
            'title' => 'Repeat enquiry via '.$source->label(),
            'body' => $data['description'] ?? null,
            'meta' => ['source' => $source->value, 'filled' => $filled, 'payload' => $rawPayload],
        ]);

        if (array_intersect($filled, LeadScoringService::INPUT_FIELDS)) {
            ScoreLeadJob::dispatch($lead)->afterCommit();
        }

        return $lead;
    }
}
