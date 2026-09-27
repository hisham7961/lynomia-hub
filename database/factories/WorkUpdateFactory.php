<?php

namespace Database\Factories;

use App\Models\WorkUpdate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkUpdate> */
class WorkUpdateFactory extends Factory
{
    protected $model = WorkUpdate::class;

    public function definition(): array
    {
        return [
            'done' => fake()->sentence(),
            'hours' => 2,
        ];
    }
}
