<?php

namespace Database\Factories;

use App\Models\LedgerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LedgerAccount> */
class LedgerAccountFactory extends Factory
{
    protected $model = LedgerAccount::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify('9####'),
            'name' => 'حساب ' . fake()->words(2, true),
            'type' => 'أصول',
        ];
    }
}
