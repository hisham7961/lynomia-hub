<?php

namespace Tests\Feature\UltimateReview;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **المعوّقُ يصير التزاماً لا موعظة** (قرارُ المالك · v2.558).
 *
 * القياسُ على شهرِ المحاكاة:
 *
 * ```
 * تقاريرُ عملٍ تذكر معوّقاً: ٤٧٣   ·   بلاغاتٌ في القاعدة كلِّها: ٦
 * ```
 *
 * وبطاقةُ «حواجبُ مبلَّغة» نصُّها «راجع المعوّقات مع الفريق» وفعلُها الوحيد
 * «افتح المشروع» — بلا مالكٍ ولا موعدٍ ولا حالةٍ تُغلَق.
 *
 * والقرار: **زرُّ «حوّله إلى بلاغ»** يُنشئ بلاغاً مملوءاً من نصِّ التقرير،
 * مالكُه مديرُ المشروع، وموعدُه من إعدادٍ قابلٍ للضبط، ومربوطٌ بمصدرِه فلا
 * يُنشأ مرّتين.
 */
class BlockerBecomesACommitmentTest extends TestCase
{
    private function actor(array $matrix): User
    {
        $role = Role::create(['name' => 'فاعلٌ' . Str::random(5), 'scope' => 'all',
            'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => 'فاعلُ التحويل', 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /** تقريرٌ يوميٌّ يذكر معوّقاً، على مشروعٍ له مديرٌ معلوم */
    private function blocker(string $text, ?string $manager = null): array
    {
        $pid = (string) Str::uuid();
        DB::table('projects')->insert(['id' => $pid, 'name' => 'مشروعُ الاختبار',
            'manager_id' => $manager, 'created_at' => now(), 'updated_at' => now()]);

        $wid = (string) Str::uuid();
        DB::table('work_updates')->insert(['id' => $wid, 'project_id' => $pid,
            'done' => 'عملُ اليوم', 'problems' => $text,
            'work_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

        return [$wid, $pid];
    }

    // ── ① التحويل: مالكٌ وموعدٌ وحالةٌ تُغلَق ────────────────────────────

    public function test_a_reported_blocker_becomes_an_issue_with_an_owner_and_a_due_date(): void
    {
        $this->seedCore();
        $mgr = $this->actor(['projects' => ['v' => 1]]);
        [$wid] = $this->blocker('الخادمُ الاختباريُّ متوقّفٌ منذ يومين', (string) $mgr->id);

        $u = $this->actor(['updates' => ['v' => 1], 'issues' => ['v' => 1, 'a' => 1]]);
        $this->actingAs($u)->post("/reports/blocker/{$wid}/to-issue")->assertRedirect();

        $issue = DB::table('issues')->whereNull('deleted_at')->first();
        $this->assertNotNull($issue, 'لم يُنشأ بلاغٌ — فبقيت البطاقةُ موعظةً كما كانت');

        $this->assertSame('مفتوحة', $issue->status, 'البلاغُ بلا حالةٍ تُغلَق');
        $this->assertSame((string) $mgr->id, (string) $issue->assignee_id,
            'البلاغُ بلا مالك — ومديرُ المشروعِ معلوم');
        $this->assertNotNull($issue->due, 'البلاغُ بلا موعد — فالمقبِضُ ثلثان');
        $this->assertStringContainsString('الخادمُ الاختباريُّ متوقّفٌ', (string) $issue->cause,
            'نصُّ المعوّقِ الأصليُّ ضاع — والعنوانُ يُقصّ فالأصلُ يُحفَظ في السبب');
    }

    /** والموعدُ من الإعدادِ لا من رقمٍ مدفون */
    public function test_the_due_date_follows_the_setting(): void
    {
        $this->seedCore();
        [$wid] = $this->blocker('معوّقٌ آخر');
        \App\Support\Settings::put('issues.blocker_due_days', '3', 'test');

        $u = $this->actor(['updates' => ['v' => 1], 'issues' => ['v' => 1, 'a' => 1]]);
        $this->actingAs($u)->post("/reports/blocker/{$wid}/to-issue");

        // **اليومُ وحدَه يُقارَن لا الطابعُ الكامل**: `due` مصبوبٌ `date` كنظيرَيه
        // `found` و`closed`، وصبُّ لارافيل يكتب `Y-m-d H:i:s` — فـMySQL تقتطع
        // الوقتَ لأنّ العمودَ DATE، وSQLite تحفظ السلسلةَ كما هي. فمقارنةُ
        // السلسلةِ الخامِ **قرعةٌ بين المحرّكين**، والعرضُ نفسُه يقتطع عشراً
        // (`_field.blade.php:67`). فيُقارَن ما يُقارِنه المنتج.
        $this->assertSame(now()->addDays(3)->toDateString(),
            substr((string) DB::table('issues')->value('due'), 0, 10),
            'الموعدُ لا يتبع الإعدادَ — فالمفتاحُ زينةٌ لا رافعة');
    }

    // ── ② ولا يُنشأ مرّتين ───────────────────────────────────────────────

    public function test_converting_twice_opens_the_same_issue_and_never_duplicates(): void
    {
        $this->seedCore();
        [$wid] = $this->blocker('معوّقٌ يُضغط زرُّه مرّتين');
        $u = $this->actor(['updates' => ['v' => 1], 'issues' => ['v' => 1, 'a' => 1]]);

        $this->actingAs($u)->post("/reports/blocker/{$wid}/to-issue");
        $first = (string) DB::table('issues')->value('id');

        $this->actingAs($u)->post("/reports/blocker/{$wid}/to-issue")
            ->assertRedirect(route('m.show', ['issues', $first]));

        $this->assertSame(1, DB::table('issues')->whereNull('deleted_at')->count(),
            'النقرةُ الثانيةُ أنشأت بلاغاً ثانياً لنفسِ المعوّق');
    }

    // ── ③ والحرّاس ───────────────────────────────────────────────────────

    public function test_a_user_without_issue_write_cannot_convert(): void
    {
        $this->seedCore();
        [$wid] = $this->blocker('معوّقٌ لا يملك أحدٌ تحويلَه');
        $u = $this->actor(['updates' => ['v' => 1]]);          // بلا issues:a

        $this->actingAs($u)->post("/reports/blocker/{$wid}/to-issue")->assertStatus(403);
        $this->assertSame(0, DB::table('issues')->count(), 'أُنشئ بلاغٌ بلا صلاحيّةِ كتابة');
    }

    public function test_a_report_without_a_blocker_is_refused(): void
    {
        $this->seedCore();
        $pid = (string) Str::uuid();
        DB::table('projects')->insert(['id' => $pid, 'name' => 'مشروع', 'created_at' => now(), 'updated_at' => now()]);
        $wid = (string) Str::uuid();
        DB::table('work_updates')->insert(['id' => $wid, 'project_id' => $pid, 'done' => 'يومٌ بلا معوّق',
            'problems' => null, 'work_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

        $u = $this->actor(['updates' => ['v' => 1], 'issues' => ['v' => 1, 'a' => 1]]);
        $this->actingAs($u)->post("/reports/blocker/{$wid}/to-issue")->assertStatus(422);
        $this->assertSame(0, DB::table('issues')->count(), 'أُنشئ بلاغٌ من تقريرٍ بلا معوّق');
    }

    // ── ⑥ الالتزامُ يُسائَل: أثرٌ ولقطةٌ ورقمُ نسخة ───────────────────────

    /**
     * **الإدراجُ الخامُ كان يُسقط ثلاثةً بصمت** — وهذا الاختبارُ يسقط عليه.
     *
     * البلاغُ التزامٌ مُسائَلٌ عنه، فلا بدّ أن يقول مَن أنشأه (`audit_entries`)،
     * وأن تُحفَظ لقطتُه في `record_versions` (وهي عينُها ما يُستَرجَع به سجلٌّ
     * حُذف حذفاً قاسياً). و`DB::table()->insert()` يتخطّى `Auditable`
     * و`HasVersions` معاً لأنّه لا يمرّ بالموديل — وقد قِيس سقوطُ هذا الاختبارِ
     * عليه فعلاً عند اللقطة. (أمّا `version` فله افتراضُ قاعدةٍ = ١ فيبقى
     * صحيحاً حتّى في الإدراجِ الخام — ويُؤكَّد هنا حارساً لا اتّهاماً.)
     */
    public function test_the_created_issue_carries_an_audit_trail_and_a_restorable_snapshot(): void
    {
        $u = $this->actor(['updates' => ['v' => 1], 'issues' => ['v' => 1, 'a' => 1]]);
        [$wid] = $this->blocker('الشبكةُ تنقطع كلَّ ساعةٍ فيسقط النشر');

        $this->actingAs($u)->post("/reports/blocker/{$wid}/to-issue")->assertRedirect();

        $issue = DB::table('issues')->whereNull('deleted_at')->first();
        $this->assertNotNull($issue, 'لم يُنشأ بلاغ');

        // ① رقمُ النسخةِ واحد (حارسٌ — وهو افتراضُ القاعدةِ أصلاً)
        $this->assertSame(1, (int) $issue->version, 'البلاغُ بلا رقمِ نسخة');

        // ② لقطةٌ تُسترجَع منها — الجدولُ نفسُه الذي يُستعاد به المحذوفُ قسراً
        $this->assertTrue(
            DB::table('record_versions')->where('module', 'issues')
                ->where('record_id', (string) $issue->id)->exists(),
            'لا لقطةَ في record_versions — فالبلاغُ لا يُستَرجَع إن حُذف قسراً'
        );

        // ③ أثرُ تدقيقٍ يقول مَن أنشأه
        $this->assertTrue(
            DB::table('audits')->where('module', 'issues')
                ->where('record_id', (string) $issue->id)->exists(),
            'لا أثرَ تدقيقٍ — فلا يُعرَف مَن حوّل هذا المعوّقَ إلى التزام'
        );

        // ④ والمُنشئُ هو الفاعلُ لا عدم
        $this->assertSame((string) $u->id, (string) $issue->created_by,
            'البلاغُ بلا مُنشئٍ مختوم');
    }
}
