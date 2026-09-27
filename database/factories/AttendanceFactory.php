<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Attendance> */
class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    public function definition(): array
    {
        return [
            // موظّفٌ ويومٌ = صفٌّ واحد (`Attendance::booted`) — فلكلِّ صفٍّ موظّفُه
            'emp_id' => Employee::factory(),
            'date' => now()->toDateString(),
            'time_in' => '08:00:00',
            'time_out' => '16:00:00',
            'status' => 'حاضر',
        ];
    }
}
