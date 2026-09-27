<?php

namespace Database\Factories;

use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Quote> */
class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    public function definition(): array
    {
        return [
            // `doc_no` يُرقَّم تلقائيّاً في `Quote::booted` — والمجموعُ يُشتقّ من المبلغ والضريبة
            'title' => 'عرض ' . fake()->words(2, true),
            'status' => 'مسودة',
            'currency' => 'د.ك',
            'amount' => 0,
            'tax' => 0,
            'total' => 0,
        ];
    }
}
