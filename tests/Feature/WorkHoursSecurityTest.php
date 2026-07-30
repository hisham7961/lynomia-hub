<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * حارس الدخول ونظام ساعات العمل:
 *  - IP غريب أو دخول خارج الدوام → تدقيق + تنبيه المالكين + نافذة اشتباه للمستخدم.
 *  - بعد strict_from: جلسات الموظفين تُجدَّد كل ١٠ دقائق ونقل الملفات ممنوع.
 */
class WorkHoursSecurityTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function login(string $email)
    {
        return $this->post('/login', ['email' => $email, 'password' => 'Secret!2026x']);
    }

    /** دخول من IP جديد داخل الدوام: اشتباه «مكان غير مألوف» بنافذة وتدقيق وتنبيه */
    public function test_unfamiliar_ip_triggers_suspicion_popup_and_alerts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-02 10:00'));
        $this->seedCore();

        $this->login('emp@test.local')->assertRedirect();
        $this->assertDatabaseHas('audits', ['action' => 'دخول مريب']);
        $this->assertTrue(
            \App\Models\HubNotification::where('kind', 'sec')
                ->where('user_id', $this->owner->id)->exists(), 'المالك لم يُنبَّه');

        // النافذة تظهر مرة واحدة ثم تختفي
        $this->get('/')->assertSee('اشتباه وصول غير معتاد')->assertSee('غير مألوف');
        $this->get('/')->assertDontSee('اشتباه وصول غير معتاد');
    }

    /** العنوان المعتاد (٣ مرات+) داخل الدوام: لا اشتباه — مكان العمل معروف */
    public function test_familiar_ip_inside_hours_is_silent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-02 10:00'));
        $this->seedCore();
        DB::table('user_ips')->insert(['id' => (string) Str::uuid(),
            'user_id' => $this->employee->id, 'ip' => '127.0.0.1', 'hits' => 5, 'last_seen_at' => now()]);

        $this->login('emp@test.local')->assertRedirect();
        $this->assertDatabaseMissing('audits', ['action' => 'دخول مريب']);
        $this->get('/')->assertDontSee('اشتباه وصول غير معتاد');
    }

    /** خارج الدوام يُشتبه حتى بالعنوان المعتاد — والسبب «خارج وقت العمل» */
    public function test_after_hours_login_is_flagged_even_from_known_ip(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-02 20:00'));
        $this->seedCore();
        DB::table('user_ips')->insert(['id' => (string) Str::uuid(),
            'user_id' => $this->employee->id, 'ip' => '127.0.0.1', 'hits' => 9, 'last_seen_at' => now()]);

        $this->login('emp@test.local');
        $this->get('/')->assertSee('اشتباه وصول غير معتاد')->assertSee('خارج وقت العمل');
    }

    /** بعد ٥ مساءً: خمول ١١ دقيقة يُخرج الموظف — والمالك لا يمسّه النظام */
    public function test_employee_session_expires_every_10_minutes_after_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-02 18:00'));
        $this->seedCore();
        $this->hubSetting('sec.hours_on', '1');

        $this->actingAs($this->employee)->get('/')->assertOk();       // يثبّت wh.last
        Carbon::setTestNow(Carbon::parse('2026-08-02 18:11'));
        $this->get('/')->assertRedirect(route('login'));               // خمول ١١ دقيقة → خروج
        $this->assertGuest();

        // المالك في الوقت نفسه حر
        Carbon::setTestNow(Carbon::parse('2026-08-02 18:00'));
        $this->actingAs($this->owner)->get('/')->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-08-02 18:30'));
        $this->actingAs($this->owner)->get('/')->assertOk();
    }

    /** داخل الدوام (٨→٤): لا قفل متكرر مهما طال الخمول */
    public function test_inside_work_hours_no_forced_relogin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-02 09:00'));
        $this->seedCore();
        $this->hubSetting('sec.hours_on', '1');
        $this->actingAs($this->employee)->get('/')->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-08-02 11:30'));         // خمول ساعتين ونصف
        $this->get('/')->assertOk();
    }

    /** نقل الملفات ممنوع خارج الدوام للموظف — ومتاح للمالك وفي الصباح */
    public function test_file_transfer_blocked_after_hours_for_employees(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_on', '1');
        // نمنح الموظف علم التصدير كي يُختبر منطقُ الساعات لا الصلاحية
        $this->employee->role->update(['flags' => ['exp' => 1]]);
        $this->employee->refresh();

        Carbon::setTestNow(Carbon::parse('2026-08-02 20:00'));
        $this->actingAs($this->employee)->get('/m/tasks/export')->assertForbidden();
        $this->actingAs($this->owner)->get('/m/tasks/export')->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-08-02 10:00'));
        $this->actingAs($this->employee)->get('/m/tasks/export')->assertOk();
    }

    /** زر عدّة الانطلاق في مركز التشغيل: يولّد المسارات والقواعد، وللمالك فقط */
    public function test_ops_starters_button_generates_everything(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->post('/admin/ops/starters')->assertForbidden();

        $this->actingAs($this->owner)->post('/admin/ops/starters')->assertRedirect(route('ops.index'));
        $this->assertGreaterThanOrEqual(30, \App\Models\Flow::count());
        $this->assertGreaterThanOrEqual(16, DB::table('alert_rules')->count());
        $this->actingAs($this->owner)->get('/admin/ops')->assertSee('عدّة الانطلاق');
    }
}
