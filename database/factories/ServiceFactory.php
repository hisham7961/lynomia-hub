<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Service> */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'name' => 'خدمة ' . fake()->words(2, true),
            'status' => 'نشطة',
            'kind' => 'دعم وصيانة',
            'price' => 50,
            'cycle' => 'شهري',
        ];
    }
}
