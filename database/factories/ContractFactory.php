<?php

namespace Database\Factories;

use App\Models\Contract;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Contract> */
class ContractFactory extends Factory
{
    protected $model = Contract::class;

    public function definition(): array
    {
        return [
            'title' => 'عقد ' . fake()->words(2, true),
            'type' => 'عقد عميل',
            'status' => 'مسودة',
            'date_start' => now()->toDateString(),
            'date_end' => now()->addYear()->toDateString(),
        ];
    }
}
