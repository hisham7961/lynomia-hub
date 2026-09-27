<?php

namespace Database\Factories;

use App\Models\Quote;
use App\Models\QuoteLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QuoteLine> */
class QuoteLineFactory extends Factory
{
    protected $model = QuoteLine::class;

    public function definition(): array
    {
        return [
            'quote_id' => Quote::factory(),
            'title' => 'بند ' . fake()->words(2, true),
            'qty' => 1,
            'unit_price' => 100,
            'rev_type' => 'one_time',
        ];
    }
}
