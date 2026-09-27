<?php

namespace Database\Factories;

use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Ticket> */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        return [
            'subject' => 'تذكرة ' . fake()->words(3, true),
            'status' => 'جديدة',
            'priority' => 'متوسطة',
            'channel' => 'بريد',
        ];
    }
}
