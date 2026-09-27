<?php

namespace Database\Factories;

use App\Models\PayrollRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PayrollRun> */
class PayrollRunFactory extends Factory
{
    protected $model = PayrollRun::class;

    public function definition(): array
    {
        return [
            // شهرٌ لا يتكرّر للشركة نفسِها (`PayrollRun::booted`) — فالفريدُ من `unique()`
            'name' => 'مسيّر ' . fake()->words(2, true),
            'month' => fake()->unique()->dateTimeBetween('-15 years', '-1 month')->format('Y-m'),
            'status' => 'مسودة',
            'currency' => 'د.ك',
        ];
    }
}
