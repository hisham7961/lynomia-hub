<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Server> */
class ServerFactory extends Factory
{
    protected $model = Server::class;

    public function definition(): array
    {
        return [
            'name' => 'srv-' . fake()->unique()->numerify('####'),
            'type' => 'Cloud VPS',
            'status' => 'يعمل',
        ];
    }
}
