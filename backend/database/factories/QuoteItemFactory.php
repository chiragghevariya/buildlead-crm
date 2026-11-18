<?php

namespace Database\Factories;

use App\Enums\QuoteUnit;
use App\Models\Quote;
use App\Models\QuoteItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuoteItem>
 */
class QuoteItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quote_id' => Quote::factory(),
            'description' => fake()->randomElement([
                'Site preparation & demolition',
                'Drywall partitions incl. insulation',
                'Electrical installation',
                'Screed and flooring',
                'Project management',
            ]),
            'quantity' => fake()->numberBetween(1, 120),
            'unit' => fake()->randomElement(QuoteUnit::cases()),
            'unit_price' => fake()->numberBetween(40, 900),
            'sort' => 0,
        ];
    }
}
