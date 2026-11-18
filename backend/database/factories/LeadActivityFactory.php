<?php

namespace Database\Factories;

use App\Enums\ActivityType;
use App\Models\Lead;
use App\Models\LeadActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadActivity>
 */
class LeadActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'user_id' => null,
            'type' => ActivityType::Note,
            'title' => 'Note added',
            'body' => fake()->sentence(12),
            'meta' => null,
            'due_at' => null,
        ];
    }
}
