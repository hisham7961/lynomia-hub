<?php

namespace Database\Factories;

use App\Models\BankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BankAccount> */
class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    public function definition(): array
    {
        return [
            'name' => 'حساب ' . fake()->words(2, true),
            'kind' => 'حساب بنكي',
            'currency' => 'د.ك',
            'balance' => 1000,
            'status' => 'نشط',
        ];
    }
}
