<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'type' => InvoiceType::Deposit,
            'status' => InvoiceStatus::Sent,
            'amount' => fake()->numberBetween(5, 120) * 1000,
            'issued_at' => now()->subDays(5),
            'due_at' => now()->addDays(9),
        ];
    }
}
