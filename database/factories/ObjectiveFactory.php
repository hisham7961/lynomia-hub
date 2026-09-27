<?php

namespace Database\Factories;

use App\Models\Objective;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Objective> */
class ObjectiveFactory extends Factory
{
    protected $model = Objective::class;

    public function definition(): array
    {
        return [
            'title' => 'هدف ' . fake()->words(2, true),
            'level' => 'الشركة',
            'status' => 'مخطط',
            'date_start' => now()->startOfQuarter()->toDateString(),
            'due' => now()->endOfQuarter()->toDateString(),
        ];
    }
}
