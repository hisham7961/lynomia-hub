<?php

namespace Database\Factories;

use App\Models\KeyResult;
use App\Models\Objective;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KeyResult> */
class KeyResultFactory extends Factory
{
    protected $model = KeyResult::class;

    public function definition(): array
    {
        return [
            'objective_id' => Objective::factory(),
            'title' => 'نتيجة ' . fake()->words(2, true),
            'start_value' => 0,
            'target_value' => 100,
            'current_value' => 0,
            'status' => 'على المسار',
        ];
    }
}
