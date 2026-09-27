<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveRequest> */
class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    public function definition(): array
    {
        return [
            // «مقدّم» لا «معتمد»: الاعتمادُ يخصم الرصيدَ ويفحص سقفَه (`LeaveRequest::booted`)
            'emp_id' => Employee::factory(),
            'type' => 'إجازة سنوية',
            'date_from' => now()->addDays(10)->toDateString(),
            'date_to' => now()->addDays(11)->toDateString(),
            'status' => 'مقدّم',
        ];
    }
}
