<?php

namespace Database\Factories;

use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Station> */
class StationFactory extends Factory
{
    protected $model = Station::class;

    public function definition(): array
    {
        return [
            // `code` يُولَّد في `Station::booted`
            'facility' => 'مبنى ' . fake()->randomLetter(),
            'type' => 'مكتب',
        ];
    }
}
