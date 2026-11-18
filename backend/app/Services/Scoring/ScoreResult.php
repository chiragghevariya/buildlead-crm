<?php

namespace App\Services\Scoring;

use App\Enums\LeadTemperature;

/**
 * Immutable outcome of scoring one lead (rules ± AI, blended).
 */
final class ScoreResult
{
    /**
     * @param  array<string, array{points: float, max: int, fraction: float, note: string}>  $factors
     * @param  list<string>  $aiReasons
     */
    public function __construct(
        public readonly int $score,
        public readonly int $ruleScore,
        public readonly ?int $aiScore,
        public readonly LeadTemperature $temperature,
        public readonly array $factors,
        public readonly float $aiWeight,
        public readonly array $aiReasons = [],
        public readonly ?string $summary = null,
        public readonly ?string $nextAction = null,
        public readonly ?string $aiProjectType = null,
        public readonly ?string $model = null,
    ) {}

    public function usedAi(): bool
    {
        return $this->aiScore !== null;
    }

    public function isHot(): bool
    {
        return $this->temperature === LeadTemperature::Hot;
    }

    /** Shape stored in leads.score_breakdown (and rendered by the frontend). */
    public function toBreakdown(): array
    {
        return [
            'method' => $this->usedAi() ? 'hybrid' : 'rules_only',
            'final' => $this->score,
            'rule_score' => $this->ruleScore,
            'rules' => $this->factors,
            'ai' => $this->usedAi() ? [
                'score' => $this->aiScore,
                'reasons' => $this->aiReasons,
                'project_type' => $this->aiProjectType,
            ] : null,
            'ai_weight' => $this->usedAi() ? $this->aiWeight : 0.0,
            'model' => $this->usedAi() ? $this->model : null,
        ];
    }
}
