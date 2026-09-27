<?php

namespace Database\Factories;

use App\Models\StockItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockItem> */
class StockItemFactory extends Factory
{
    protected $model = StockItem::class;

    public function definition(): array
    {
        return [
            'name' => 'صنف ' . fake()->words(2, true),
            'sku' => 'SKU-' . fake()->unique()->numerify('######'),
            'qty' => 10,
            'status' => 'متاح',
        ];
    }
}
