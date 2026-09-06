<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\SecurityFindings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WP-4.1 — محرّكُ النتائج الأمنية (security_findings) فوق فحوص SecurityPosture.
 *
 * القواعد المُثبَتة هنا:
 *  - تشغيلان لـreconcile() ⇒ صفٌّ واحد لكل (code, entity) مع first_seen_at محفوظ
 *    (critic #6: قيمُ الكيان الحارسة 'org'/'' لا NULL — فالفريد يعمل على المحرّكين).
 *  - reconcile() منظّمةٌ فقط (critic #24): صفُّ كيانٍ مزروعٌ لا يُمَسّ ولا يُحَلّ.
 *  - زوالُ الشرط يُغلق النتيجة تلقائياً (resolved + resolved_at) **بلا حذف**.
 *  - الإقرارُ للمالك وحده ومسجَّلٌ في التدقيق (ق٤: لا إقرارَ ثانياً في signal_states).
 *  - الشدّةُ من خريطة SEVERITY_BY_CODE الصريحة (critic #10: بالرمز لا بالنبرة).
 *  - قارئُ monitor يرى قراءةً منطَّقةً بالشركة ومطموسةَ البريد والعنوان (critic #9)،
 *    والموظفُ بلا علمٍ يُصَدّ ٤٠٣، ولا سرَّ في أي ردّ.
 */
class SecurityFindingsTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** مستخدمٌ بعلم monitor وحده (غير مالك) — بشركاتٍ محدَّدة اختيارياً */
    protected function monitorUser(array $companies = []): User
    {
        $role = Role::create(['name' => 'مراقب', 'scope' => 'all', 'flags' => ['monitor' => 1], 'matrix' => []]);

        return User::create(['name' => 'مراقب', 'email' => 'mon@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => $companies]);
    }

    /** صفُّ كيانٍ مزروع — يحاكي ما ستكتبه ذيولُ WP-4.3/4.5 لاحقاً */
    protected function seedEntityFinding(array $over = []): string
    {
        $id = (string) Str::uuid();
        DB::table('security_findings')->insert($over + [
            'id' => $id, 'code' => 'api_stale', 'entity_type' => 'token', 'entity_id' => (string) Str::uuid(),
            'severity' => 'high', 'title' => 'مفتاح API خامل', 'description' => 'لم يُستعمل منذ ٩٠ يوماً',
            'evidence' => json_encode(['n' => 1]), 'remediation' => 'ألغِه من الملف الشخصي',
            'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** تشغيلان ⇒ صفٌّ واحد لكل (code, entity) — وfirst_seen_at لا يتجدّد (critic #6) */
    public function test_two_reconciles_produce_one_row_per_code_and_preserve_first_seen(): void
    {
        $this->seedCore();
        $this->hubSetting('monitor.allow_private', '1');   // يُشعل فحص ssrf (bad) حتماً

        Carbon::setTestNow(Carbon::parse('2026-09-06 10:00:00'));
        SecurityFindings::reconcile();

        $row = DB::table('security_findings')->where('code', 'ssrf')
            ->where('entity_type', 'org')->where('entity_id', '')->first();
        $this->assertNotNull($row, 'فحصٌ مكسور لم يُنتج نتيجةً على مستوى المنظّمة');
        $this->assertSame('open', $row->status);
        $firstSeen = $row->first_seen_at;

        // الكيانُ المزروع (سكّة WP-4.5) يبقى كما هو — reconcile منظّمةٌ فقط (critic #24)
        $entityId = $this->seedEntityFinding();

        Carbon::setTestNow(Carbon::parse('2026-09-07 10:00:00'));
        SecurityFindings::reconcile();

        $rows = DB::table('security_findings')->where('code', 'ssrf')
            ->where('entity_type', 'org')->where('entity_id', '')->get();
        $this->assertCount(1, $rows, 'التشغيلُ الثاني كرّر نتيجةَ المنظّمة بدل تحديثها');
        $this->assertSame($firstSeen, $rows[0]->first_seen_at, 'first_seen_at تجدّد — ضاع عمرُ المشكلة');
        $this->assertGreaterThan($firstSeen, $rows[0]->last_seen_at, 'last_seen_at لم يُحدَّث');

        // ولا تكرارَ على مستوى الرمز كلِّه: صفٌّ واحد لكل (code, entity_type, entity_id)
        $dupes = DB::table('security_findings')
            ->select('code', 'entity_type', 'entity_id', DB::raw('COUNT(*) as c'))
            ->groupBy('code', 'entity_type', 'entity_id')->havingRaw('COUNT(*) > 1')->get();
        $this->assertCount(0, $dupes, 'مفتاحٌ (code, entity) تكرّر: ' . $dupes->pluck('code')->implode('، '));

        $entity = DB::table('security_findings')->where('id', $entityId)->first();
        $this->assertSame('open', $entity->status, 'reconcile المنظّمة مسّ صفَّ كيانٍ ليس له');
    }

    /** زوالُ الشرط يُغلق تلقائياً بلا حذف — وعودتُه تُعيد الفتح بعمرٍ محفوظ */
    public function test_vanished_condition_auto_resolves_without_deleting_and_reopens(): void
    {
        $this->seedCore();
        $this->hubSetting('monitor.allow_private', '1');
        Carbon::setTestNow(Carbon::parse('2026-09-06 10:00:00'));
        SecurityFindings::reconcile();
        $firstSeen = DB::table('security_findings')->where('code', 'ssrf')->value('first_seen_at');
        $this->assertNotNull($firstSeen);

        // الشرطُ زال — النتيجةُ تُغلق ولا تُحذف
        $this->hubSetting('monitor.allow_private', '0');
        Carbon::setTestNow(Carbon::parse('2026-09-07 10:00:00'));
        SecurityFindings::reconcile();

        $row = DB::table('security_findings')->where('code', 'ssrf')->first();
        $this->assertNotNull($row, 'الإغلاقُ التلقائي حذف الصفَّ — التاريخُ ضاع');
        $this->assertSame('resolved', $row->status);
        $this->assertNotNull($row->resolved_at);

        // عاد الشرطُ — تُفتح النتيجةُ نفسُها من جديد وfirst_seen_at باقٍ
        $this->hubSetting('monitor.allow_private', '1');
        Carbon::setTestNow(Carbon::parse('2026-09-08 10:00:00'));
        SecurityFindings::reconcile();

        $row = DB::table('security_findings')->where('code', 'ssrf')->first();
        $this->assertSame('open', $row->status, 'عودةُ الشرط لم تُعِد فتحَ النتيجة');
        $this->assertNull($row->resolved_at);
        $this->assertSame($firstSeen, $row->first_seen_at);
        $this->assertSame(1, DB::table('security_findings')->where('code', 'ssrf')->count());
    }

    /** الشدّةُ بالرمز من الخريطة الصريحة — لا استنتاجَ من النبرة (critic #10) */
    public function test_severity_matches_the_explicit_code_map(): void
    {
        $this->seedCore();
        $this->hubSetting('monitor.allow_private', '1');
        SecurityFindings::reconcile();

        $rows = DB::table('security_findings')->where('entity_type', 'org')->get();
        $this->assertGreaterThan(0, $rows->count());
        foreach ($rows as $f) {
            if (! isset(SecurityFindings::SEVERITY_BY_CODE[$f->code])) continue;
            $this->assertSame(SecurityFindings::SEVERITY_BY_CODE[$f->code], $f->severity,
                "شدّةُ {$f->code} لا تطابق خريطةَ SEVERITY_BY_CODE");
        }
        // والخريطةُ تغطّي رموزَ الفحوص كلَّها — لا رمزَ يسقط إلى الاستنتاج بالنبرة.
        // (WP-9.4 أضاف التجميدَين، فالتغطيةُ تُشتقّ من الوضعية نفسِها بدل رقمٍ
        //  مكتوبٍ بيدٍ يُنسى تحديثُه مع كل فحصٍ جديد.)
        $postureKeys = array_column(\App\Support\SecurityPosture::checks(), 'key');
        $this->assertSame([], array_values(array_diff($postureKeys,
            array_keys(SecurityFindings::SEVERITY_BY_CODE))),
            'فحصٌ في وضعية الأمان بلا شدّةٍ صريحة في SEVERITY_BY_CODE');
        // **والاتجاه الآخر كذلك** — وهو ما كان `assertCount` يضمنه: مدخلٌ في
        // الخريطة لا يقابله فحصٌ في الوضعية شدّةٌ ميّتة لرمزٍ لا يُنتَج أبداً،
        // تبقى بعد حذف الفحص أو إعادة تسميته فتوهم بتغطيةٍ ليست هناك.
        $this->assertSame([], array_values(array_diff(
            array_keys(SecurityFindings::SEVERITY_BY_CODE), $postureKeys)),
            'شدّةٌ في SEVERITY_BY_CODE لرمزٍ لا تنتجه وضعيةُ الأمان');
        foreach (SecurityFindings::SEVERITY_BY_CODE as $code => $sev) {
            $this->assertContains($sev, \App\Support\Severity::LEVELS, "شدّةُ {$code} خارج سلّم Severity");
        }
    }

    /** الإقرارُ والإغلاقُ للمالك وحده — ومسجَّلان في التدقيق، ولا يمحوهما reconcile ما دام الشرط */
    public function test_ack_and_resolve_are_owner_only_and_audited(): void
    {
        $this->seedCore();
        $this->hubSetting('monitor.allow_private', '1');
        SecurityFindings::reconcile();
        $id = DB::table('security_findings')->where('code', 'ssrf')->value('id');

        // الموظفُ والمراقبُ يُصدّان عن الفعل
        $this->actingAs($this->employee)->post("/admin/security/findings/{$id}/ack")->assertForbidden();
        $this->actingAs($this->monitorUser())->post("/admin/security/findings/{$id}/ack")->assertForbidden();
        $this->assertSame('open', DB::table('security_findings')->where('id', $id)->value('status'));

        // المالكُ يُقرّ — الحالةُ تتغيّر والقيدُ يُكتب
        $this->actingAs($this->owner)->post("/admin/security/findings/{$id}/ack")->assertRedirect();
        $row = DB::table('security_findings')->where('id', $id)->first();
        $this->assertSame('acknowledged', $row->status);
        $this->assertSame((string) $this->owner->id, (string) $row->acknowledged_by);
        $this->assertNotNull($row->acknowledged_at);
        $this->assertSame(1, DB::table('audits')->where('action', 'إقرار نتيجة أمنية')->count());
        // ق٤: لا إقرارَ ثانياً في signal_states
        $this->assertSame(0, DB::table('signal_states')->count(), 'الإقرارُ ازدوج في signal_states خلافاً لق٤');

        // reconcile آخر والشرطُ قائم: الإقرارُ يبقى (لا يعود open)
        SecurityFindings::reconcile();
        $this->assertSame('acknowledged', DB::table('security_findings')->where('id', $id)->value('status'));

        // الإغلاقُ اليدويّ مالكٌ فقط ومدقَّق
        $this->actingAs($this->employee)->post("/admin/security/findings/{$id}/resolve")->assertForbidden();
        $this->actingAs($this->owner)->post("/admin/security/findings/{$id}/resolve")->assertRedirect();
        $this->assertSame('resolved', DB::table('security_findings')->where('id', $id)->value('status'));
        $this->assertSame(1, DB::table('audits')->where('action', 'إغلاق نتيجة أمنية')->count());
    }

    /** التوصيةُ من fix/url القائمين في SecurityPosture — لا نصَّ مُخترَعاً */
    public function test_remediation_comes_from_posture_fix_and_url(): void
    {
        $this->seedCore();
        $this->hubSetting('monitor.allow_private', '1');
        SecurityFindings::reconcile();

        $row = DB::table('security_findings')->where('code', 'ssrf')->first();
        $check = collect(\App\Support\SecurityPosture::checks())->firstWhere('key', 'ssrf');
        $this->assertSame($check['fix'], $row->remediation, 'التوصيةُ ليست حقلَ fix القائم');
        $this->assertSame($check['label'], $row->title);
        $this->assertSame($check['why'], $row->description);
        $this->assertSame($check['url'], json_decode((string) $row->evidence, true)['url'] ?? null);

        // والشاشةُ تعرضها للمالك
        $this->actingAs($this->owner)->get('/admin/security/findings')
            ->assertOk()->assertSee($check['label'])->assertSee($check['fix']);
    }

    /**
     * اختبارُ التسريب الصريح (critic #9): monitor يقرأ منطَّقاً بالشركة ومطموسَ
     * البريد والعنوان؛ المالكُ يقرأ كاملاً؛ الموظفُ بلا علمٍ ٤٠٣؛ ولا سرَّ في أي ردّ.
     */
    public function test_monitor_reads_masked_and_company_scoped_owner_full_employee_forbidden(): void
    {
        $this->seedCore();
        $co = (string) Str::uuid();
        $other = (string) Str::uuid();
        DB::table('companies')->insert([
            ['id' => $co, 'name_ar' => 'شركة المراقب', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $other, 'name_ar' => 'شركة أخرى', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // نتيجةُ كيانٍ ببياناتٍ شخصية وسرٍّ مزروعٍ عمداً في الدليل — يجب ألا يظهر لأحد
        $fid = $this->seedEntityFinding([
            'code' => 'twofa_priv', 'entity_type' => 'user',
            'title' => 'حسابٌ مميّز بلا تحقّق بخطوتين',
            'description' => 'الحساب target@corp.example دخل من 10.1.2.3 بلا تحقّقٍ بخطوتين',
            'evidence' => json_encode(['email' => 'target@corp.example', 'ip' => '10.1.2.3',
                                       'token' => 'lyn_abcdef1234567890abcd']),
        ]);
        // ونتيجةٌ لشركةٍ خارج نطاق المراقب — لا يراها
        $this->seedEntityFinding(['code' => 'idle', 'entity_type' => 'user',
            'title' => 'نتيجة شركة أخرى معزولة', 'description' => 'خمول', 'company_id' => $other]);

        $monitor = $this->monitorUser([$co]);

        // الموظفُ بلا علمٍ يُصَدّ عن القائمة والتفصيل
        $this->actingAs($this->employee)->get('/admin/security/findings')->assertForbidden();
        $this->actingAs($this->employee)->get("/admin/security/findings/{$fid}")->assertForbidden();

        // المراقبُ: قائمةٌ بلا بريدٍ ولا عنوانٍ ولا سرّ — ولا نتيجةَ الشركةِ الأخرى
        $list = $this->actingAs($monitor)->get('/admin/security/findings');
        $list->assertOk();
        $list->assertDontSee('target@corp.example');
        $list->assertDontSee('نتيجة شركة أخرى معزولة');
        $this->assertStringNotContainsString('lyn_abcdef1234567890abcd', $list->getContent());

        $show = $this->actingAs($monitor)->get("/admin/security/findings/{$fid}");
        $show->assertOk();
        $show->assertDontSee('target@corp.example');
        $this->assertStringNotContainsString('10.1.2.3', $show->getContent());
        $this->assertStringNotContainsString('lyn_abcdef1234567890abcd', $show->getContent());

        // المالكُ يرى البريدَ والعنوان كاملين — والسرُّ مطموسٌ حتى عنه
        $full = $this->actingAs($this->owner)->get("/admin/security/findings/{$fid}");
        $full->assertOk()->assertSee('target@corp.example');
        $this->assertStringContainsString('10.1.2.3', $full->getContent());
        $this->assertStringNotContainsString('lyn_abcdef1234567890abcd', $full->getContent());
        $this->actingAs($this->owner)->get('/admin/security/findings')
            ->assertOk()->assertSee('نتيجة شركة أخرى معزولة');
    }
}
