<?php

namespace Database\Factories;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\ProjectType;
use App\Enums\Timeline;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    public function definition(): array
    {
        $faker = fake('de_DE');
        $min = $faker->numberBetween(8, 600) * 1000;
        $max = (int) round($min * $faker->randomFloat(2, 1.1, 1.6), -3);
        $score = $faker->numberBetween(15, 95);
        $postcode = $faker->randomElement(['10115', '10437', '12043', '13353', '14467', '15232', '16225', '03046']);

        return [
            'contact_name' => $faker->name(),
            'company' => $faker->boolean(60) ? $faker->company() : null,
            'email' => $faker->unique()->safeEmail(),
            'phone' => $faker->phoneNumber(),
            'source' => $faker->randomElement(LeadSource::cases()),
            'project_type' => $faker->randomElement(ProjectType::cases()),
            'description' => $faker->randomElement([
                'Komplettsanierung einer Altbauwohnung, Elektrik, Bäder und Böden neu. Start flexibel.',
                'Two-storey rear extension for a growing family, planning approval granted last month.',
                'Office fit-out for our new HQ with meeting rooms, kitchen and server room.',
                'Dach neu eindecken, alte Ziegel aus den 70ern, an einigen Stellen undicht.',
                'Water damage in the bathroom, tiles and screed need replacing.',
                'Neubau Einfamilienhaus mit Keller auf eigenem Grundstück, KfW 40 Standard.',
            ]),
            'budget_min' => $min,
            'budget_max' => $max,
            'timeline' => $faker->randomElement(Timeline::cases()),
            'street' => $faker->streetAddress(),
            'postcode' => $postcode,
            'city' => str_starts_with($postcode, '1') && $postcode < '14400' ? 'Berlin' : $faker->city(),
            'area_sqm' => $faker->numberBetween(20, 1500),
            'score' => $score,
            'rule_score' => $score,
            'ai_score' => null,
            'temperature' => LeadTemperature::fromScore($score),
            'scored_at' => now(),
            'status' => LeadStatus::New,
            'estimated_value' => $max,
            'consent_given' => true,
            'consent_at' => now(),
        ];
    }

    public function hot(): static
    {
        return $this->state(fn () => ['score' => 88, 'rule_score' => 85, 'temperature' => LeadTemperature::Hot]);
    }

    public function cold(): static
    {
        return $this->state(fn () => ['score' => 30, 'rule_score' => 30, 'temperature' => LeadTemperature::Cold]);
    }

    public function unscored(): static
    {
        return $this->state(fn () => [
            'score' => null, 'rule_score' => null, 'ai_score' => null,
            'temperature' => null, 'scored_at' => null,
        ]);
    }

    public function status(LeadStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'first_contacted_at' => $status === LeadStatus::New ? null : now()->subDays(3),
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn () => ['assigned_to' => $user->id, 'assigned_at' => now()]);
    }
}
