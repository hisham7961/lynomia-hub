<?php

namespace Database\Factories;

use App\Models\Purchase;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Purchase> */
class PurchaseFactory extends Factory
{
    protected $model = Purchase::class;

    public function definition(): array
    {
        return [
            'doc_no' => 'PO-F-' . fake()->unique()->numerify('######'),
            'date' => now()->toDateString(),
            'amount' => 100,
            'currency' => 'د.ك',
            'status' => 'مسودة',
        ];
    }
}
