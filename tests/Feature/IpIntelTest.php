<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\SecurityRadar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WP-4.4 — ذكاءُ العناوين (security.ips + التفصيل): قارئٌ واحدٌ لكل IP
 * يوحّد نسختَي «العناوين الطارقة» (SecurityRadar::threats وknocking في مركز
 * الأمان) — أوّل/آخر ظهورٍ من audits(created_at, ip) **تقريبيٌّ ومصرَّحٌ به**
 * (critic: لا مصدرَ لما قبل عمود first_seen_at)، ودخولٌ ناجح/فاشل، ورفضٌ،
 * وأفعالٌ مريبة، ووسمٌ (عاديّ/جديد/فشل متكرّر/تعدّد حسابات/مريب). بلا geo خارجيّ.
 *
 * التجميعاتُ تُبنى بأعمدةٍ مجمَّعةٍ أو مفتاحِ التجميع وحدَه — فتعمل تحت
 * ONLY_FULL_GROUP_BY على MySQL 8 (الحزمةُ المركزية تشغّلها هناك).
 */
class IpIntelTest extends TestCase
{
    protected function monitorUser(array $companies = []): User
    {
        $role = Role::create(['name' => 'مراقب ' . Str::random(4), 'scope' => 'all',
            'flags' => ['monitor' => 1], 'matrix' => []]);

        return User::create(['name' => 'مراقب', 'email' => 'mon' . Str::random(4) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'companies' => $companies]);
    }

    /** قيدُ تدقيقٍ خام بعنوانٍ وفعلٍ وزمن (قبل دقيقة: حدُّ المدى `< to` حصريٌّ باللحظة) */
    protected function audit(string $ip, string $action, string $name, $at = null, ?string $userId = null): void
    {
        DB::table('audits')->insert(['action' => $action, 'name' => $name, 'ip' => $ip,
            'user_id' => $userId, 'created_at' => $at ?? now()->subMinute()]);
    }

    /** القارئُ الواحد يعدّ صحيحاً: نجاحٌ وفشلٌ وأهدافٌ متمايزة ورفضٌ وأوّلُ ظهورٍ تقريبيّ */
    public function test_rollup_groups_strictly_and_counts_signals(): void
    {
        $this->seedCore();
        $ip = '203.0.113.7';
        $this->audit($ip, 'دخول فاشل', 'u1@corp.com');
        $this->audit($ip, 'دخول فاشل', 'u1@corp.com');
        $this->audit($ip, 'دخول فاشل', 'u2@corp.com');
        $this->audit($ip, 'دخول ناجح', 'المالك', null, $this->owner->id);
        // أوّلُ ظهورٍ حقيقيّ قبل ٤٠ يوماً — خارج نافذة القراءة لكنه أوّلُ الأثر
        $this->audit($ip, 'دخول ناجح', 'المالك', now()->subDays(40), $this->owner->id);
        DB::table('access_denials')->insert(['kind' => 'وصول مرفوض', 'ip' => $ip,
            'method' => 'GET', 'path' => '/admin/x', 'created_at' => now()->subMinute()]);

        $rows = SecurityRadar::intel(now()->subDays(7), now());
        $row = $rows->firstWhere('ip', $ip);

        $this->assertNotNull($row, 'العنوانُ غائبٌ عن القارئ الواحد');
        $this->assertSame(3, (int) $row->fails);
        $this->assertSame(1, (int) $row->success);
        $this->assertSame(2, (int) $row->fail_targets, 'أهدافُ الفشل المتمايزة (بريدان) لم تُعدّ صحيحاً');
        $this->assertSame(1, (int) $row->denials);
        $this->assertNotNull($row->first_seen);
        $this->assertTrue(\Illuminate\Support\Carbon::parse($row->first_seen)->lt(now()->subDays(30)),
            'أوّلُ الظهور التقريبيّ يُقرأ من كامل سجلّ التدقيق لا من النافذة وحدها');
    }

    /** منطقُ الوسم: مريب > تعدّد حسابات > فشل متكرّر > جديد > عاديّ */
    public function test_label_logic_orders_by_severity(): void
    {
        $this->seedCore();

        // أ: بريدان مستهدفان ⇒ تعدّد حسابات
        foreach (['a1@x.com', 'a1@x.com', 'a2@x.com'] as $n) $this->audit('198.51.100.1', 'دخول فاشل', $n);
        // ب: فشلٌ متكرّر على هدفٍ واحد
        for ($i = 0; $i < 5; $i++) $this->audit('198.51.100.2', 'دخول فاشل', 'b@x.com');
        // ج: نشاطٌ مريبٌ مرصود
        $this->audit('198.51.100.3', 'دخول مريب', 'c@x.com');
        // د: ناجحٌ أولُ ظهوره اليوم ⇒ جديد
        $this->audit('198.51.100.4', 'دخول ناجح', 'المالك', null, $this->owner->id);
        // هـ: ناجحٌ معتادٌ منذ شهر ⇒ عاديّ
        $this->audit('198.51.100.5', 'دخول ناجح', 'المالك', now()->subDays(30), $this->owner->id);
        $this->audit('198.51.100.5', 'دخول ناجح', 'المالك', null, $this->owner->id);

        $rows = SecurityRadar::intel(now()->subDays(90), now())->keyBy('ip');

        $this->assertSame('تعدّد حسابات', $rows['198.51.100.1']->label['label']);
        $this->assertSame('فشل متكرّر', $rows['198.51.100.2']->label['label']);
        $this->assertSame('مريب', $rows['198.51.100.3']->label['label']);
        $this->assertSame('جديد', $rows['198.51.100.4']->label['label']);
        $this->assertSame('عاديّ', $rows['198.51.100.5']->label['label']);
    }

    /** الشاشتان تُجيبان: القائمةُ بالوسم، والتفصيلُ بأوّل ظهورٍ «تقريبيّ» مصرَّحٍ به */
    public function test_screens_answer_with_data_and_declare_approximation(): void
    {
        $this->seedCore();
        $ip = '203.0.113.9';
        foreach (['v1@corp.com', 'v2@corp.com'] as $n) $this->audit($ip, 'دخول فاشل', $n);

        $this->actingAs($this->owner)->get(route('security.ips'))
            ->assertOk()->assertSee($ip)->assertSee('تعدّد حسابات');

        $this->actingAs($this->owner)->get(route('security.ip', $ip))
            ->assertOk()->assertSee($ip)->assertSee('تقريبيّ');

        // حالةٌ فارغة صادقة لعنوانٍ بلا أثر
        $this->actingAs($this->owner)->get(route('security.ip', '192.0.2.250'))
            ->assertOk()->assertSee('لا أثر');
    }

    /** اختبارُ التسريب الإلزاميّ: مالكٌ كامل · مراقبٌ مطموسٌ ومنطَّق · موظفٌ ٤٠٣ */
    public function test_monitor_reads_masked_and_scoped_owner_full_employee_forbidden(): void
    {
        $this->seedCore();
        $coA = (string) Str::uuid();
        $coB = (string) Str::uuid();
        DB::table('companies')->insert([
            ['id' => $coA, 'name_ar' => 'شركة أ', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $coB, 'name_ar' => 'شركة ب', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $ip = '203.0.113.77';
        $this->audit($ip, 'دخول فاشل', 'victim@corp.com');
        $target = User::create(['name' => 'مستخدم معروف من العنوان', 'email' => 'known9@test.local',
            'password' => 'Secret!2026x', 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => [$coA]]);
        DB::table('user_ips')->insert(['id' => (string) Str::uuid(), 'user_id' => $target->id,
            'ip' => $ip, 'hits' => 4, 'last_seen_at' => now()]);

        // موظفٌ بلا علم: ٤٠٣ على القائمة والتفصيل معاً
        $this->actingAs($this->employee)->get(route('security.ips'))->assertForbidden();
        $this->actingAs($this->employee)->get(route('security.ip', $ip))->assertForbidden();

        // المالك: كامل
        $this->actingAs($this->owner)->get(route('security.ips'))->assertOk()->assertSee($ip);
        $this->actingAs($this->owner)->get(route('security.ip', $ip))
            ->assertOk()->assertSee('victim@corp.com')->assertSee('known9@test.local');

        // المراقب: القائمةُ بلا عناوينَ صريحة، والتفصيلُ بلا بريدٍ صريح
        $list = $this->actingAs($this->monitorUser())->get(route('security.ips'))->assertOk()->getContent();
        $this->assertStringNotContainsString($ip, $list, 'العنوانُ تسرّب لقارئ المراقبة في القائمة');
        $this->assertStringContainsString('عنوان محجوب', $list);

        $detail = $this->actingAs($this->monitorUser())->get(route('security.ip', $ip))->assertOk()->getContent();
        $this->assertStringNotContainsString('victim@corp.com', $detail, 'بريدُ محاولةِ الدخول تسرّب للمراقب');
        $this->assertStringNotContainsString('known9@test.local', $detail);
        $this->assertStringContainsString('بريد محجوب', $detail);

        // مراقبٌ منطَّق على شركةٍ أخرى لا يرى مستخدمي الشركة الأولى المعروفين من العنوان
        $this->actingAs($this->monitorUser([$coB]))->get(route('security.ip', $ip))
            ->assertOk()->assertDontSee('مستخدم معروف من العنوان');
    }
}
