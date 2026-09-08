<?php

namespace Tests\Feature\MobilePlatform;

use App\Models\Role;
use App\Models\User;
use App\Support\MobilePlatform;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مركزُ منصّة الجوال — الملفات والماسح والتتبّع (MPC-7)**: حالةٌ وتجميعٌ فقط —
 * **لا مراقبة**. موقفُ فحصِ الفيروسات تجميعاً (لا أسماءَ ملفاتٍ)، وحالةُ التتبّع
 * أعداداً وموافقةً — **لا إحداثيّاتٍ ولا مساراتٍ ولا نقاطَ موقعٍ قطّ** (مُثبَتٌ).
 */
class MobilePlatformFieldTest extends TestCase
{
    private function mobileAdmin(): User
    {
        $role = Role::create(['name' => 'مسؤولُ جوال', 'scope' => 'all', 'flags' => ['mobile' => 1], 'matrix' => []]);

        return User::create(['name' => 'مسؤول', 'email' => 'mob7@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    private function attachment(string $av, string $name = 'file.pdf'): void
    {
        DB::table('attachments')->insert([
            'id' => (string) Str::uuid(), 'module' => 'invoices', 'record_id' => (string) Str::uuid(),
            'disk' => 'local', 'path' => 'p/' . Str::random(8), 'original_name' => $name,
            'size' => 100, 'av_status' => $av, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function trackSession(string $status, bool $consent): string
    {
        $id = (string) Str::uuid();
        DB::table('track_sessions')->insert([
            'id' => $id, 'emp_id' => (string) Str::uuid(), 'field_day' => now()->toDateString(),
            'status' => $status, 'consent_at' => $consent ? now() : null,
            'point_count' => 0, 'distance_m' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_field_tab_shows_files_scanner_tracking(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=field')->assertOk()
            ->assertSee('الملفات')
            ->assertSee('الماسح')
            ->assertSee('تتبّعُ الموقع')
            ->assertSee('لا مراقبة');
    }

    public function test_employee_cannot_access_field_tab(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get('/admin/mobile-platform?tab=field')->assertForbidden();
    }

    public function test_mobile_admin_can_view_field_tab(): void
    {
        $this->seedCore();
        $this->actingAs($this->mobileAdmin())->get('/admin/mobile-platform?tab=field')->assertOk()
            ->assertSee('الملفات والماسح والتتبّع');
    }

    /** موقفُ الفيروسات تجميعٌ — أعدادٌ لا أسماءُ ملفات */
    public function test_files_av_posture_is_aggregate_no_names(): void
    {
        $this->seedCore();
        $this->attachment('clean');
        $this->attachment('infected', 'CANARY-SECRET-FILE.pdf');
        $this->attachment('pending');

        $files = MobilePlatform::filesStatus();
        $this->assertSame(3, $files['av']['total']);
        $this->assertSame(1, $files['av']['infected']);
        $this->assertSame(1, $files['av']['clean']);

        $html = $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=field')->assertOk()->getContent();
        $this->assertStringNotContainsString('CANARY-SECRET-FILE.pdf', $html, 'اسمُ ملفٍ بلغ الشاشة');
    }

    /** حالةُ التتبّع أعدادٌ وموافقةٌ — تجميعٌ لا تفصيل */
    public function test_tracking_status_is_aggregate_and_consent(): void
    {
        $this->seedCore();
        $this->trackSession('نشطة', true);
        $this->trackSession('منتهية', true);

        $t = MobilePlatform::trackingStatus();
        $this->assertSame(2, $t['sessions']['total']);
        $this->assertSame(1, $t['sessions']['active']);
        $this->assertSame(1, $t['sessions']['ended']);
        $this->assertSame(2, $t['consent']['with']);
        $this->assertSame(0, $t['consent']['without']);
    }

    /** شذوذُ «تتبّعٌ بلا إقرار» يظهر صادقاً (يجب أن يكون صفراً) */
    public function test_tracking_consent_anomaly_surfaces(): void
    {
        $this->seedCore();
        $this->trackSession('نشطة', false);

        $this->assertSame(1, MobilePlatform::trackingStatus()['consent']['without']);
        $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=field')->assertOk()
            ->assertSee('شذوذ');
    }

    /** **الأهمّ (لا مراقبة):** لا إحداثيّةٌ ولا نقطةُ موقعٍ تبلغ الشاشة أبداً */
    public function test_tracking_never_exposes_coordinates(): void
    {
        $this->seedCore();
        $sid = $this->trackSession('نشطة', true);
        // نقطةٌ بإحداثيّةٍ مميّزةٍ + خطُّ مسارٍ مبسَّط — يجب ألّا يبلغ أيٌّ منهما الشاشة
        DB::table('track_points')->insert([
            'id' => (string) Str::uuid(), 'session_id' => $sid,
            'lat' => 29.3733999, 'lng' => 47.9776543, 'accuracy_m' => 5,
            'captured_at' => now(), 'client_operation_id' => 'op-' . Str::random(6),
        ]);
        DB::table('track_sessions')->where('id', $sid)->update(['simplified' => json_encode([[29.3733999, 47.9776543]])]);

        $html = $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=field')->assertOk()->getContent();
        $this->assertStringNotContainsString('29.3733999', $html, 'إحداثيّةُ خطِّ العرض بلغت الشاشة — مراقبة');
        $this->assertStringNotContainsString('47.9776543', $html, 'إحداثيّةُ خطِّ الطول بلغت الشاشة — مراقبة');
    }

    public function test_no_secret_rendered_on_field_tab(): void
    {
        $this->seedCore();
        $this->hubSetting('mobile.push_fcm_access_token', 'SECRET-FCM-FIELD-333');

        $html = $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=field')->assertOk()->getContent();
        $this->assertStringNotContainsString('SECRET-FCM-FIELD-333', $html);
    }
}
