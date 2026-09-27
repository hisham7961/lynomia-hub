<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Role> */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'name' => 'دور ' . fake()->unique()->numerify('####'),
            'is_owner' => false,
            'scope' => 'all',
            'flags' => [],
            'matrix' => [],
        ];
    }

    /** دورُ المالك: كلُّ الأعلام والنطاقُ كلُّه — كما يبنيه `TestCase::seedCore` */
    public function owner(): static
    {
        return $this->state(fn () => [
            'name' => 'مالك ' . fake()->unique()->numerify('####'),
            'is_owner' => true,
            'flags' => ['secrets' => 1, 'approve' => 1, 'users' => 1, 'audit' => 1, 'exp' => 1, 'monitor' => 1],
        ]);
    }
}
