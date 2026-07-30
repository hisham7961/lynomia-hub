<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Task;
use Tests\TestCase;

/** التقويم الموحّد: العرض، الملاحة الشهرية، احترام الصلاحيات والعزل */
class CalendarTest extends TestCase
{
    public function test_calendar_shows_dated_records_in_month(): void
    {
        $this->seedCore();
        Task::create(['title' => 'مهمة التقويم', 'due' => now()->startOfMonth()->addDays(9)]);

        $resp = $this->actingAs($this->owner)->get('/calendar');
        $resp->assertOk()->assertSee('مهمة التقويم')->assertSee('التقويم الموحّد');

        // شهر آخر لا يعرضها — وشهر تالف يسقط لشهرنا بلا انفجار
        $far = now()->addMonths(3)->format('Y-m');
        $this->actingAs($this->owner)->get('/calendar?m=' . $far)->assertOk()->assertDontSee('مهمة التقويم');
        $this->actingAs($this->owner)->get('/calendar?m=xx-99')->assertOk();
    }

    public function test_calendar_respects_company_isolation(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);
        Client::create(['name' => 'متابعة ألف', 'company_id' => $coA->id, 'next_date' => now()->startOfMonth()->addDays(4)]);
        Client::create(['name' => 'متابعة باء', 'company_id' => $coB->id, 'next_date' => now()->startOfMonth()->addDays(4)]);
        $this->employee->update(['companies' => [$coA->id]]);

        $this->actingAs($this->employee)->get('/calendar')
            ->assertOk()->assertSee('متابعة ألف')->assertDontSee('متابعة باء');
    }
}
