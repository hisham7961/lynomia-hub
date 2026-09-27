<?php

namespace Database\Factories;

use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Task> */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'title' => 'مهمة ' . fake()->words(3, true),
            'status' => 'جديدة',
            'priority' => 'متوسطة',
        ];
    }
}
