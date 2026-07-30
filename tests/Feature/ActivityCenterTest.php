<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** مركز نشاط الموظفين: تتبّع التنقل داخل النظام، ساعات العمل، للمالك فقط */
class ActivityCenterTest extends TestCase
{
    public function test_visits_are_tracked_for_full_get_pages_only(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get('/m/tasks')->assertOk();

        $this->assertDatabaseHas('page_visits', ['user_id' => $this->employee->id, 'path' => '/m/tasks']);

        // أجزاء htmx لا تُسجَّل — السجل مسارٌ لا ضجيج
        $before = DB::table('page_visits')->count();
        $this->actingAs($this->employee)->get('/search/mini?q=x', ['HX-Request' => 'true']);
        $this->assertSame($before, DB::table('page_visits')->count());
    }

    public function test_owner_sees_activity_center_employee_cannot(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get('/admin/activity')->assertForbidden();
        $this->actingAs($this->employee)->get("/admin/activity/{$this->employee->id}")->assertForbidden();

        $this->actingAs($this->owner)->get('/admin/activity')->assertOk()->assertSee('نشاط الموظفين');
    }

    public function test_employee_detail_shows_work_hours_and_trail(): void
    {
        $this->seedCore();
        // نشاط للموظف: زيارتان + فعل
        $this->actingAs($this->employee)->get('/m/tasks')->assertOk();
        $this->actingAs($this->employee)->get('/m/clients')->assertOk();
        Task::create(['title' => 'من الموظف']);   // فعل تدقيق

        $html = $this->actingAs($this->owner)->get("/admin/activity/{$this->employee->id}")
            ->assertOk()->getContent();

        $this->assertStringContainsString('ساعات العمل الفعلية', $html);
        $this->assertStringContainsString('مسار التنقل', $html);
        $this->assertStringContainsString('/m/tasks', $html);
        $this->assertStringContainsString('/m/clients', $html);
    }

    /** الخنق: الصفحة نفسها لا تُسجَّل مرتين خلال دقيقتين */
    public function test_same_page_is_throttled(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee);
        $this->get('/m/tasks');
        $this->get('/m/tasks');
        $this->assertSame(1, DB::table('page_visits')
            ->where('user_id', $this->employee->id)->where('path', '/m/tasks')->count());
    }
}
