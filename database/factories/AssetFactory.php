<?php

namespace Database\Factories;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Asset> */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        return [
            'name' => 'أصل ' . fake()->words(2, true),
            'type' => 'لابتوب',
            'status' => 'متاح',
        ];
    }
}
