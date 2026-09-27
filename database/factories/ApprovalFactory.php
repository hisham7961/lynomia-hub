<?php

namespace Database\Factories;

use App\Models\Approval;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Approval> */
class ApprovalFactory extends Factory
{
    protected $model = Approval::class;

    public function definition(): array
    {
        return [
            'title' => 'اعتماد ' . fake()->words(2, true),
            'type' => 'مصروف',
            'status' => 'معلّق',
            'amount' => 50,
        ];
    }
}
