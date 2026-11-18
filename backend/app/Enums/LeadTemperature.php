<?php

namespace App\Enums;

use App\Models\ScoringSetting;

enum LeadTemperature: string
{
    case Hot = 'hot';
    case Warm = 'warm';
    case Cold = 'cold';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Band a 0–100 score using config/scoring.php thresholds. */
    public static function fromScore(int|float $score): self
    {
        $bands = ScoringSetting::config('bands');

        return match (true) {
            $score >= $bands['hot'] => self::Hot,
            $score >= $bands['warm'] => self::Warm,
            default => self::Cold,
        };
    }
}
