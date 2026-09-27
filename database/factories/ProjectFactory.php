<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'name' => 'مشروع ' . fake()->words(2, true),
            'status' => 'نشط',
            'priority' => 'متوسطة',
            'type' => 'نظام داخلي',
        ];
    }
}
