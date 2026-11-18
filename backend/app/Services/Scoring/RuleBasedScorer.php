<?php

namespace App\Services\Scoring;

use App\Models\Lead;
use App\Models\ScoringSetting;
use App\Support\Money;

/**
 * Deterministic rule score (0–100) from the effective scoring config.
 * Every factor returns points plus a human-readable note for the UI.
 */
class RuleBasedScorer
{
    private const FIELD_LABELS = [
        'phone' => 'phone', 'email' => 'email', 'budget_max' => 'budget',
        'postcode' => 'postcode', 'area_sqm' => 'area', 'description' => 'description',
    ];

    /**
     * @return array{score: int, factors: array<string, array{points: float, max: int, fraction: float, note: string}>}
     */
    public function score(Lead $lead, ?array $config = null): array
    {
        $config ??= ScoringSetting::config();

        $evaluated = [
            'budget' => $this->budget($lead, $config),
            'project_type' => $this->projectType($lead, $config),
            'location' => $this->location($lead, $config),
            'urgency' => $this->urgency($lead, $config),
            'source' => $this->source($lead, $config),
            'completeness' => $this->completeness($lead, $config),
        ];

        $factors = [];
        $total = 0.0;
        foreach ($config['weights'] as $factor => $max) {
            [$fraction, $note] = $evaluated[$factor] ?? [0.0, 'Unknown factor'];
            $fraction = max(0.0, min(1.0, (float) $fraction));
            $points = round($max * $fraction, 1);
            $total += $points;

            $factors[$factor] = [
                'points' => $points,
                'max' => (int) $max,
                'fraction' => round($fraction, 2),
                'note' => $note,
            ];
        }

        return ['score' => (int) round(max(0, min(100, $total))), 'factors' => $factors];
    }

    /** @return array{0: float, 1: string} */
    private function budget(Lead $lead, array $config): array
    {
        $budget = $lead->budget_max ?? $lead->budget_min;
        if ($budget === null || (float) $budget <= 0) {
            return [0.0, 'No budget given'];
        }

        foreach ($config['budget_bands'] as $band) {
            if ((float) $budget >= $band['min']) {
                $range = $lead->budget_min && $lead->budget_max
                    ? Money::format($lead->budget_min).'–'.Money::format($lead->budget_max)
                    : Money::format($budget);

                return [(float) $band['fraction'], $band['min'] > 0
                    ? "Budget {$range} (band ≥ ".Money::format($band['min']).')'
                    : "Budget {$range} (small job)"];
            }
        }

        return [0.0, 'Budget below smallest band'];
    }

    private function projectType(Lead $lead, array $config): array
    {
        if (! $lead->project_type) {
            return [0.0, 'Project type unknown'];
        }

        $fraction = (float) ($config['project_types'][$lead->project_type->value] ?? 0);
        $quality = match (true) {
            $fraction >= 0.9 => 'high-value project type',
            $fraction >= 0.6 => 'good fit',
            default => 'lower-value job type',
        };

        return [$fraction, $lead->project_type->label().' — '.$quality];
    }

    private function location(Lead $lead, array $config): array
    {
        $postcode = trim((string) $lead->postcode);
        if ($postcode === '') {
            return [0.0, 'No postcode given'];
        }

        $prefix = substr($postcode, 0, 2);
        $place = trim($postcode.' '.$lead->city);
        $cfg = $config['location'];

        return match (true) {
            in_array($prefix, $cfg['core_prefixes'], true) => [1.0, "{$place} — core service area"],
            in_array($prefix, $cfg['secondary_prefixes'], true) => [(float) $cfg['secondary_fraction'], "{$place} — outer service area"],
            default => [(float) $cfg['other_fraction'], "{$place} — outside service area"],
        };
    }

    private function urgency(Lead $lead, array $config): array
    {
        if (! $lead->timeline) {
            return [0.0, 'No timeline given'];
        }

        return [(float) ($config['urgency'][$lead->timeline->value] ?? 0), 'Timeline: '.$lead->timeline->label()];
    }

    private function source(Lead $lead, array $config): array
    {
        if (! $lead->source) {
            return [0.0, 'Unknown source'];
        }

        return [(float) ($config['sources'][$lead->source->value] ?? 0), 'Source: '.$lead->source->label()];
    }

    private function completeness(Lead $lead, array $config): array
    {
        $fields = $config['completeness']['fields'];
        $minDescription = (int) $config['completeness']['min_description_length'];

        $missing = collect($fields)->reject(function (string $field) use ($lead, $minDescription) {
            $value = $lead->{$field};

            return $field === 'description'
                ? mb_strlen(trim((string) $value)) >= $minDescription
                : filled($value);
        })->values();

        $present = count($fields) - $missing->count();
        $note = $missing->isEmpty()
            ? 'All key details provided'
            : "{$present} of ".count($fields).' key details (missing: '.$missing->map(fn ($f) => self::FIELD_LABELS[$f] ?? $f)->implode(', ').')';

        return [count($fields) ? $present / count($fields) : 0.0, $note];
    }
}
