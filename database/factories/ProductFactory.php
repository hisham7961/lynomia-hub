<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'name' => 'منتج ' . fake()->words(2, true),
            'type' => 'لابتوب',
            'status' => 'غير موثّق',
        ];
    }
}
