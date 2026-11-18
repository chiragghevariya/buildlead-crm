<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        $budget = fake()->numberBetween(20, 800) * 1000;

        return [
            'name' => 'Renovation '.fake('de_DE')->streetName(),
            'site_address' => fake('de_DE')->streetAddress().', '.fake()->randomElement(['10115', '12043']).' Berlin',
            'status' => ProjectStatus::Planning,
            'progress' => 0,
            'budget' => $budget,
            'spent' => 0,
            'start_date' => now()->addWeek(),
            'due_date' => now()->addMonths(4),
        ];
    }
}
