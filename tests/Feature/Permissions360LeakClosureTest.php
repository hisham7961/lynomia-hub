<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * **إغلاقُ تسريباتٍ محدَّدة** (Permissions 360 · م2 · 06.2 · 09.1 · 11.1).
 *
 * إثباتٌ لا ادّعاء: كلُّ اختبارٍ يفشلُ على الشيفرةِ قبلَ الإصلاح.
 */
class Permissions360LeakClosureTest extends TestCase
{
    private function userWith(string $email, array $matrix, array $flags = [],
        array $companies = [], string $scope = 'all'): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => $scope, 'flags' => $flags,
            'matrix' => $matrix, 'companies' => $companies ?: null]);

        return User::create(['name' => 'مستخدم', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => $companies ?: null]);
    }

    /* ═══════════ 06.2 — حارسُ لوحاتِ المنشأةِ يمنعُ المعزولَ بمشاريعه ═══════════ */

    public function test_org_analytics_guard_blocks_project_scoped_user(): void
    {
        $this->seedCore();
        $scoped = $this->userWith('proj@test.local', ['perf' => ['v' => 1]], scope: 'proj');

        $this->actingAs($scoped);
        try {
            hub_org_analytics_guard();
            $this->fail('حسابٌ محدودُ النطاقِ بمشاريعه اجتازَ حارسَ لوحةِ المنشأة');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // نطاقٌ شاملٌ لا يُمنَع
        $all = $this->userWith('all@test.local', ['perf' => ['v' => 1]]);
        $this->actingAs($all);
        hub_org_analytics_guard();          // لا يرمي
        $this->assertTrue(true);
    }

    /* ═══════════ 09.1 — بياناتُ الحضورِ خلفَ صلاحيتِها في تقارير 360 ═══════════ */

    public function test_daily_reports_null_attendance_without_attend_view(): void
    {
        $this->seedCore();
        $emp = Employee::create(['name' => 'موظّف', 'status' => 'نشط']);
        Attendance::create(['emp_id' => $emp->id, 'date' => now()->toDateString(),
            'time_in' => '08:00', 'time_out' => '16:00', 'status' => 'حاضر', 'hours' => 8]);

        $e360 = new \App\Support\Employee360();

        // يرى التقاريرَ (updates:v) لكن بلا حضور (attend:v) ⇒ وقتُ الحضورِ محجوب
        $noAttend = $this->userWith('noatt@test.local', ['updates' => ['v' => 1]]);
        $rows = $e360->dailyReports($emp, $noAttend);
        $this->assertNotEmpty($rows, 'صفُّ اليومِ يجب أن يبقى (سجلُّ التقرير)');
        $this->assertNull($rows[0]['time_in'], 'وقتُ الحضورِ محجوبٌ بلا attend:v');
        $this->assertNull($rows[0]['physical'], 'الحضورُ الفعليُّ محجوب');

        // ومن يملكُ attend:v يرى الوقت
        $withAttend = $this->userWith('att@test.local', ['updates' => ['v' => 1], 'attend' => ['v' => 1]]);
        $rows2 = $e360->dailyReports($emp, $withAttend);
        $this->assertSame('08:00', $rows2[0]['time_in'], 'حاملُ attend:v يرى الوقت');
    }

    /* ═══════════ 11.1 — API لا يُعيدُ عمودَ meta الخام ═══════════ */

    public function test_api_shape_drops_unshaped_meta_column(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $def = hub_mod('quotes');
        $this->assertNotNull($def, 'وحدةُ العروضِ مُعرَّفة');
        $row = ['id' => 'q1', 'title' => 'عرض', 'meta' => ['unit_cost' => 999, 'secret' => 'x']];

        $ctrl = new \App\Http\Controllers\Api\V1Controller();
        $ref = new \ReflectionMethod($ctrl, 'shape');
        $ref->setAccessible(true);
        $out = $ref->invoke($ctrl, $def, $row, null);

        $this->assertArrayNotHasKey('meta', $out, 'عمودُ meta الخامُ لا يُعاد من الـAPI');
        $this->assertSame('q1', $out['id'] ?? null);
    }
}
