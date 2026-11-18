<?php

namespace App\Services\Scoring;

use App\Enums\ActivityType;
use App\Enums\LeadTemperature;
use App\Enums\ProjectType;
use App\Events\HotLeadDetected;
use App\Models\Lead;
use App\Models\ScoringSetting;
use App\Support\Money;

/**
 * Hybrid scoring: rules + OpenAI, blended as ai × w + rules × (1 − w).
 *
 * evaluate() has no side effects (it may call OpenAI, so keep it outside DB
 * transactions); apply() persists the result, logs an activity and fires
 * HotLeadDetected when an open lead *becomes* hot. score() does both.
 */
class LeadScoringService
{
    /** Lead fields that influence the score: changing any of them triggers a re-score. */
    public const INPUT_FIELDS = [
        'source', 'project_type', 'description', 'budget_min', 'budget_max', 'timeline',
        'postcode', 'city', 'area_sqm', 'email', 'phone', 'company',
    ];

    public function __construct(
        private readonly RuleBasedScorer $rules,
        private readonly OpenAiScorer $ai,
    ) {}

    public function score(Lead $lead): ScoreResult
    {
        return $this->apply($lead, $this->evaluate($lead));
    }

    public function evaluate(Lead $lead): ScoreResult
    {
        $config = ScoringSetting::config();

        return $this->blend($this->rules->score($lead, $config), $this->ai->score($lead), $config);
    }

    /**
     * Combine a rule result with an (optional) AI result.
     *
     * @param  array{score: int, factors: array}  $rules
     * @param  array{score: int, reasons?: list<string>, summary?: string, next_action?: string, project_type?: ?string}|null  $ai
     */
    public function blend(array $rules, ?array $ai, ?array $config = null): ScoreResult
    {
        $config ??= ScoringSetting::config();
        $weight = max(0.0, min(1.0, (float) $config['ai_weight']));

        $final = $ai === null
            ? $rules['score']
            : (int) round($ai['score'] * $weight + $rules['score'] * (1 - $weight));
        $final = max(0, min(100, $final));

        $temperature = match (true) {
            $final >= $config['bands']['hot'] => LeadTemperature::Hot,
            $final >= $config['bands']['warm'] => LeadTemperature::Warm,
            default => LeadTemperature::Cold,
        };

        return new ScoreResult(
            score: $final,
            ruleScore: $rules['score'],
            aiScore: $ai['score'] ?? null,
            temperature: $temperature,
            factors: $rules['factors'],
            aiWeight: $weight,
            aiReasons: $ai['reasons'] ?? [],
            summary: filled($ai['summary'] ?? null) ? $ai['summary'] : null,
            nextAction: filled($ai['next_action'] ?? null) ? $ai['next_action'] : self::defaultNextAction($temperature),
            aiProjectType: $ai['project_type'] ?? null,
            model: $ai === null ? null : (string) ($config['ai']['model'] ?? null),
        );
    }

    public function apply(Lead $lead, ScoreResult $result): ScoreResult
    {
        $wasHot = $lead->temperature === LeadTemperature::Hot;

        $lead->forceFill([
            'score' => $result->score,
            'rule_score' => $result->ruleScore,
            'ai_score' => $result->aiScore,
            'temperature' => $result->temperature,
            'score_breakdown' => $result->toBreakdown(),
            'ai_summary' => $result->summary ?? $this->ruleSummary($lead),
            'ai_next_action' => $result->nextAction,
            'scored_at' => now(),
        ]);

        // AI may classify leads that arrived without a project type.
        if (! $lead->project_type && $result->aiProjectType) {
            $lead->project_type = ProjectType::from($result->aiProjectType);
        }

        $lead->save();

        $lead->activities()->create([
            'user_id' => null,
            'type' => ActivityType::Score,
            'title' => "Scored {$result->score} · {$result->temperature->label()}",
            'body' => $lead->ai_summary,
            'meta' => [
                'score' => $result->score,
                'rule_score' => $result->ruleScore,
                'ai_score' => $result->aiScore,
                'temperature' => $result->temperature->value,
                'method' => $result->usedAi() ? 'hybrid' : 'rules_only',
            ],
        ]);

        if ($result->isHot() && ! $wasHot && ! $lead->status?->isClosed()) {
            HotLeadDetected::dispatch($lead, $result);
        }

        return $result;
    }

    public static function defaultNextAction(LeadTemperature $temperature): string
    {
        return match ($temperature) {
            LeadTemperature::Hot => 'Call within the hour and offer a site visit this week.',
            LeadTemperature::Warm => 'Call within 2 days and send 2–3 similar reference projects.',
            LeadTemperature::Cold => 'Add to the nurture sequence and check back in 4 weeks.',
        };
    }

    /** Fallback summary when AI is unavailable. */
    private function ruleSummary(Lead $lead): string
    {
        $who = $lead->company ?: 'Private client';
        $type = $lead->project_type?->label() ?? 'Project';
        $where = $lead->city ? " in {$lead->city}" : '';
        $size = $lead->area_sqm ? ", ~{$lead->area_sqm} m²" : '';
        $budget = $lead->budget_max
            ? ', budget '.($lead->budget_min ? Money::format($lead->budget_min).'–' : 'up to ').Money::format($lead->budget_max)
            : ', no budget stated';
        $timeline = $lead->timeline ? '. Timeline: '.$lead->timeline->label() : '';

        return "{$who}: {$type}{$where}{$size}{$budget}{$timeline}. (Scored by rules only.)";
    }
}
