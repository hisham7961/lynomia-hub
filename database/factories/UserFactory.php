<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            // الكاستُ `hashed` يجزّئها — وتحقّق كلمة المرور يقبلها (طولٌ ورموزٌ وأرقام)
            'password' => 'Secret!2026x',
            'role_id' => Role::factory(),
            'status' => User::STATUS_ACTIVE,
            'password_changed_at' => now(),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => User::STATUS_SUSPENDED]);
    }
}
