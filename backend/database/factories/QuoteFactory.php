<?php

namespace Database\Factories;

use App\Enums\QuoteStatus;
use App\Models\Lead;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'status' => QuoteStatus::Draft,
            'vat_rate' => 19,
            'valid_until' => now()->addDays(30),
            'notes' => 'Prices exclude unforeseen structural work. Valid for 30 days.',
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => ['status' => QuoteStatus::Sent, 'sent_at' => now()]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => [
            'status' => QuoteStatus::Accepted,
            'sent_at' => now()->subWeek(),
            'accepted_at' => now(),
        ]);
    }
}
