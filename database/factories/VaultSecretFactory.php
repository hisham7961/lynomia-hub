<?php

namespace Database\Factories;

use App\Models\VaultSecret;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VaultSecret> */
class VaultSecretFactory extends Factory
{
    protected $model = VaultSecret::class;

    public function definition(): array
    {
        return [
            'title' => 'سرّ ' . fake()->words(2, true),
            'type' => 'كلمة مرور',
            'username' => fake()->userName(),
            // الكاستُ يعمّيه عند الكتابة — لا نصَّ صريحاً في القاعدة
            'secret_cipher' => 'factory-' . fake()->password(12, 16),
        ];
    }
}
