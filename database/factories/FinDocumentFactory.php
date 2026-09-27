<?php

namespace Database\Factories;

use App\Models\FinDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinDocument> */
class FinDocumentFactory extends Factory
{
    protected $model = FinDocument::class;

    public function definition(): array
    {
        return [
            'doc_no' => 'INV-F-' . fake()->unique()->numerify('######'),
            'kind' => 'فاتورة مبيعات',
            'date' => now()->toDateString(),
            'amount' => 100,
            'tax' => 0,
            'total' => 100,
            'paid' => 0,
            'currency' => 'د.ك',
            'state' => 'مسودة',
        ];
    }
}
