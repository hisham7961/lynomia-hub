<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\TrackPoint;
use App\Models\TrackSession;
use App\Support\Tracking;
use Tests\TestCase;

/**
 * المرحلة ج — تتبّع المسار الميدانيّ، **بقواعد الخصوصية مُثبَتةً**:
 * لا تتبّعَ بلا موافقةٍ، ولا خارج جلسةٍ نشطة، ومنعُ تكرارٍ بنيويّ، والاحتفاظُ مقنَّن.
 */
class FieldTrackingTest extends TestCase
{
    protected function rep(): Employee
    {
        return Employee::create(['name' => 'مندوب مسار', 'status' => 'نشط',
            'field_role' => 'مندوب طبي', 'user_id' => $this->employee->id]);
    }

    public function test_start_requires_consent_and_stamps_it(): void
    {
        $this->seedCore();
        $emp = $this->rep();

        $s = Tracking::start($emp, ['user_id' => $this->employee->id]);
        $this->assertSame('نشطة', $s->status);
        $this->assertNotNull($s->consent_at, 'لا تتبّعَ بلا ختمِ موافقة');

        // جلسةٌ نشطةٌ لليوم نفسه تُعاد لا تُكرَّر
        $s2 = Tracking::start($emp, ['user_id' => $this->employee->id]);
        $this->assertSame($s->id, $s2->id);
    }

    public function test_batch_ingest_is_idempotent_and_quality_filtered(): void
    {
        $this->seedCore();
        $emp = $this->rep();
        $s = Tracking::start($emp);

        $points = [
            ['lat' => 29.37, 'lng' => 47.97, 'acc' => 10, 'op' => 'a', 'at' => now()->timestamp],
            ['lat' => 29.38, 'lng' => 47.98, 'acc' => 15, 'op' => 'b', 'at' => now()->timestamp + 60],
            ['lat' => 29.39, 'lng' => 47.99, 'acc' => 500, 'op' => 'c', 'at' => now()->timestamp + 120], // دقّة سيئة → تُهمَل
            ['lat' => 999, 'lng' => 47.99, 'acc' => 10, 'op' => 'd', 'at' => now()->timestamp + 180],     // خارج المدى → تُهمَل
        ];
        $r1 = Tracking::ingest($s, $points);
        $this->assertSame(2, $r1['saved']);
        $this->assertSame(2, $r1['skipped']);

        // إعادةُ الإرسال (نفس op) لا تُضاعف — القيدُ الفريد يمنع بنيوياً
        $r2 = Tracking::ingest($s, $points);
        $this->assertSame(0, $r2['saved']);
        $this->assertSame(2, TrackPoint::where('session_id', $s->id)->count());
        $this->assertSame(2, (int) $s->fresh()->point_count);
    }

    public function test_no_points_accepted_after_session_ends(): void
    {
        $this->seedCore();
        $emp = $this->rep();
        $s = Tracking::start($emp);
        Tracking::ingest($s, [['lat' => 29.37, 'lng' => 47.97, 'op' => 'a']]);
        Tracking::end($s);

        // نافذةُ التتبّع أُغلقت — لا نقطةَ بعدها
        $res = Tracking::ingest($s->fresh(), [['lat' => 29.40, 'lng' => 48.0, 'op' => 'z']]);
        $this->assertSame(0, $res['saved']);
        $this->assertSame('منتهية', $s->fresh()->status);
    }

    public function test_end_simplifies_route_and_computes_distance(): void
    {
        $this->seedCore();
        $emp = $this->rep();
        $s = Tracking::start($emp);
        // مسارٌ مستقيمٌ من عدة نقاط — التبسيطُ يُبقي طرفيه
        $pts = [];
        for ($i = 0; $i < 6; $i++) $pts[] = ['lat' => 29.37 + $i * 0.001, 'lng' => 47.97, 'op' => "p$i", 'at' => now()->timestamp + $i * 30];
        Tracking::ingest($s, $pts);
        Tracking::end($s);

        $fresh = $s->fresh();
        $this->assertLessThanOrEqual(count($pts), count($fresh->simplified));
        $this->assertGreaterThanOrEqual(2, count($fresh->simplified));
        $this->assertGreaterThan(0, $fresh->distance_m, 'المسافةُ لم تُحسب');
    }

    public function test_api_flow_start_ingest_end_for_a_field_rep(): void
    {
        $this->seedCore();
        $this->rep();
        $token = $this->apiToken($this->employee);

        // بلا موافقة → ٤٢٢
        $this->withToken($token)->postJson('/api/v1/track/start', ['consent' => false])
            ->assertStatus(422);

        // بموافقة → جلسة
        $start = $this->withToken($token)->postJson('/api/v1/track/start', ['consent' => true])
            ->assertStatus(201)->json();
        $sid = $start['session'];
        $this->assertNotEmpty($sid);

        // استيعاب دفعة
        $this->withToken($token)->postJson("/api/v1/track/{$sid}/points", ['points' => [
            ['lat' => 29.37, 'lng' => 47.97, 'op' => 'x1', 'acc' => 12],
            ['lat' => 29.38, 'lng' => 47.98, 'op' => 'x2', 'acc' => 12],
        ]])->assertOk()->assertJsonPath('saved', 2);

        // إنهاء
        $this->withToken($token)->postJson("/api/v1/track/{$sid}/end")
            ->assertOk()->assertJsonPath('points', 2);
    }

    public function test_a_non_field_employee_cannot_start_tracking_via_api(): void
    {
        $this->seedCore();
        // موظفٌ بلا دورٍ ميدانيّ
        Employee::create(['name' => 'موظف مكتبيّ', 'status' => 'نشط', 'user_id' => $this->employee->id]);
        $token = $this->apiToken($this->employee);

        $this->withToken($token)->postJson('/api/v1/track/start', ['consent' => true])
            ->assertStatus(403);
    }

    public function test_gps_never_writes_to_the_attendance_table(): void
    {
        $this->seedCore();
        $emp = $this->rep();
        $before = \Illuminate\Support\Facades\DB::table('attendance')->count();
        $s = Tracking::start($emp);
        Tracking::ingest($s, [['lat' => 29.37, 'lng' => 47.97, 'op' => 'a']]);
        Tracking::end($s);
        // التتبّعُ سجلٌّ منفصلٌ تماماً — لا يحدّد الحضور ولا يمسّ جدولَه
        $this->assertSame($before, \Illuminate\Support\Facades\DB::table('attendance')->count());
    }

    public function test_permissions_policy_allows_self_geolocation(): void
    {
        $this->seedCore();
        $res = $this->actingAs($this->owner)->get('/');
        $this->assertStringContainsString('geolocation=(self)',
            (string) $res->headers->get('Permissions-Policy'),
            'ترويسة الموقع ما زالت تحجب الجغرافيا كلياً');
    }

    public function test_retention_prunes_old_raw_points(): void
    {
        $this->seedCore();
        $emp = $this->rep();
        $s = Tracking::start($emp);
        TrackPoint::create(['session_id' => $s->id, 'lat' => 29.37, 'lng' => 47.97,
            'client_operation_id' => 'old', 'captured_at' => now()->subDays(200)]);
        TrackPoint::create(['session_id' => $s->id, 'lat' => 29.38, 'lng' => 47.98,
            'client_operation_id' => 'new', 'captured_at' => now()]);

        Tracking::prune();
        $this->assertSame(0, TrackPoint::where('client_operation_id', 'old')->count());
        $this->assertSame(1, TrackPoint::where('client_operation_id', 'new')->count());
    }
}
