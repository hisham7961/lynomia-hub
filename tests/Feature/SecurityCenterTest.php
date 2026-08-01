<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\AuditEntry;
use App\Models\SessionLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * مركز الأمان كان **لوحة عرضٍ لا مركز تحكّم**: يسرد آخر اثنتي عشرة جلسة ولا
 * يملك إنهاء واحدةٍ منها — وعمود `sessions_log.revoked` قائمٌ في القاعدة منذ
 * الهجرة الأولى **لا يكتبه أحد ولا يقرؤه أحد**. فجهازٌ مسروق يبقى داخلاً حتى
 * يخرج بنفسه، وكعكة «تذكّرني» عمرها أربعمئة يوم تُعيد بعثه بعد كل انتهاء جلسة.
 *
 * والوضعية الأمنية كانت ستة أرقامٍ عارية: كم مستخدماً وكم مقفلاً — لا «ما
 * المكسور الآن ومن يصلحه». وسجل التدقيق يختم كل صفٍّ ببصمةٍ متشابكة ثم
 * **لا يعرض للمالك أن السلسلة سليمة** إلا بأمر طرفية لا يشغّله أحد.
 */
class SecurityCenterTest extends TestCase
{
    // ── الجلسات: العمود الميت يحيا ──

    public function test_revoking_a_session_actually_ends_it(): void
    {
        $this->seedCore();

        $this->post('/login', ['email' => 'emp@test.local', 'password' => 'Secret!2026x'])
            ->assertRedirect();
        $this->assertAuthenticatedAs($this->employee);

        $sl = SessionLog::where('user_id', $this->employee->id)->firstOrFail();
        $this->assertNotEmpty(session('hub.sl'), 'الجلسة لا تعرف صفَّها في السجل فلا يمكن إنهاؤها');

        // المالك ينهيها من مركز الأمان
        $this->actingAs($this->owner)->post("/admin/security/sessions/{$sl->id}/revoke")->assertRedirect();
        $this->assertTrue((bool) SessionLog::find($sl->id)->revoked);

        // ثم أول طلبٍ لصاحبها يُخرجه فعلاً — لا مجرد وسمٍ في جدول
        $this->withSession(['hub.sl' => $sl->id])->actingAs($this->employee)
            ->get('/')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_revoking_kills_the_remember_me_cookie_too(): void
    {
        $this->seedCore();
        $this->employee->forceFill(['remember_token' => 'tok-before-revoke'])->saveQuietly();
        $sl = SessionLog::create(['id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->employee->id, 'ip' => '10.0.0.9',
            'started_at' => now(), 'last_seen_at' => now()]);

        $this->actingAs($this->owner)->post("/admin/security/users/{$this->employee->id}/revoke")->assertRedirect();

        $this->assertNotSame('tok-before-revoke', User::find($this->employee->id)->remember_token,
            'رمز «تذكّرني» لم يُدوَّر — الكعكة تُعيد بعث الجلسة المُنهاة صامتاً');
        $this->assertTrue((bool) SessionLog::find($sl->id)->revoked, 'إنهاء المستخدم يشمل كل جلساته');
    }

    public function test_session_revocation_is_owner_only_and_audited(): void
    {
        $this->seedCore();
        $sl = SessionLog::create(['id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->owner->id, 'started_at' => now(), 'last_seen_at' => now()]);

        $this->actingAs($this->employee)->post("/admin/security/sessions/{$sl->id}/revoke")->assertForbidden();
        $this->assertFalse((bool) SessionLog::find($sl->id)->revoked);

        $this->actingAs($this->owner)->post("/admin/security/sessions/{$sl->id}/revoke");
        $this->assertTrue(AuditEntry::where('action', 'LIKE', '%إنهاء جلسة%')->exists(),
            'إنهاء جلسةٍ إجراءٌ أمني — يجب أن يبقى له أثرٌ باسم من نفّذه');
    }

    public function test_a_live_session_keeps_a_fresh_heartbeat(): void
    {
        $this->seedCore();
        $sl = SessionLog::create(['id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->employee->id, 'started_at' => now()->subHours(3),
            'last_seen_at' => now()->subHours(3)]);

        $this->withSession(['hub.sl' => $sl->id])->actingAs($this->employee)->get('/')->assertOk();

        $this->assertTrue(SessionLog::find($sl->id)->last_seen_at->gt(now()->subMinutes(2)),
            'آخر ظهورٍ لا يُحدَّث فـ«الجلسات النشطة» تسرد أشباحاً');
    }

    // ── وضعية الأمان: ما المكسور الآن ──

    public function test_posture_names_what_is_broken_not_just_counts(): void
    {
        $this->seedCore();
        $this->hubSetting('monitor.allow_private', '1');            // حارس SSRF مُبطَل

        $checks = collect(\App\Support\SecurityPosture::checks());
        $this->assertGreaterThanOrEqual(10, $checks->count(), 'فحوصٌ حقيقية لا أرقامٌ عارية');

        $ssrf = $checks->firstWhere('key', 'ssrf');
        $this->assertSame('bad', $ssrf['tone'], 'إبطال حارس SSRF لا يُعرض كأنه سليم');
        $this->assertNotEmpty($ssrf['fix'], 'كل فحصٍ مكسور يقول كيف يُصلَح');

        // كلمة سرّ QuoteFlow الافتراضية مكتوبةٌ في شيفرةٍ عامة
        $this->assertSame('bad', $checks->firstWhere('key', 'qf_pass')['tone']);
        $this->hubSetting('quoteflow.pass', 'enc:' . \Illuminate\Support\Facades\Crypt::encryptString('KwT!9912xz'));
        $this->assertSame('ok', collect(\App\Support\SecurityPosture::checks())->firstWhere('key', 'qf_pass')['tone']);
    }

    public function test_posture_flags_privileged_accounts_without_two_factor(): void
    {
        $this->seedCore();

        $c = collect(\App\Support\SecurityPosture::checks())->firstWhere('key', 'twofa_priv');
        $this->assertSame('bad', $c['tone'], 'مالكٌ بلا تحقّقٍ بخطوتين خطرٌ يُسمّى');
        $this->assertGreaterThanOrEqual(1, $c['n']);

        User::whereKey($this->owner->id)->update(['totp_enabled' => 1]);
        $this->assertSame('ok', collect(\App\Support\SecurityPosture::checks())->firstWhere('key', 'twofa_priv')['tone']);
    }

    public function test_posture_flags_stale_api_tokens_and_open_share_links(): void
    {
        $this->seedCore();
        ApiToken::create(['user_id' => $this->owner->id, 'name' => 'قديم',
            'token_hash' => hash('sha256', 'x'), 'created_at' => now()->subDays(200),
            'last_used_at' => now()->subDays(190)]);
        DB::table('share_links')->insert(['id' => (string) \Illuminate\Support\Str::uuid(),
            'token' => \Illuminate\Support\Str::random(40), 'title' => 'ملف مفتوح', 'path' => 'x/y.pdf',
            'expires_at' => null, 'revoked' => false, 'views' => 0,
            'created_by' => $this->owner->id, 'created_at' => now()]);

        $checks = collect(\App\Support\SecurityPosture::checks());
        $this->assertContains($checks->firstWhere('key', 'api_stale')['tone'], ['wn', 'bad']);
        $this->assertContains($checks->firstWhere('key', 'share_open')['tone'], ['wn', 'bad'],
            'رابطٌ عامٌّ بلا انتهاء يبقى مفتوحاً للأبد ولا أحد يراه');
    }

    public function test_security_screen_shows_posture_and_revoke_controls(): void
    {
        $this->seedCore();
        SessionLog::create(['id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->employee->id, 'device' => 'Firefox',
            'started_at' => now(), 'last_seen_at' => now()]);

        $html = $this->actingAs($this->owner)->get('/admin/security')->assertOk()->getContent();
        $this->assertStringContainsString('وضعية الأمان', $html);
        $this->assertStringContainsString('أنهِ الجلسة', $html, 'لا زرّ لإنهاء جلسة — المركز يعرض ولا يتحكّم');
    }

    // ── التدقيق ──

    public function test_audit_filters_by_who_did_what_and_when(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        hub_audit('تصدير', 'hr', null, 'كشف الرواتب');
        $this->actingAs($this->employee);
        hub_audit('حذف', 'tasks', null, 'مهمة قديمة');

        $html = $this->actingAs($this->owner)
            ->get('/admin/audit?user=' . $this->owner->id . '&action=' . urlencode('تصدير'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('كشف الرواتب', $html);
        $this->assertStringNotContainsString('مهمة قديمة', $html, 'المرشِّح لا يرشّح');
    }

    public function test_audit_shows_only_what_changed_in_arabic(): void
    {
        $this->seedCore();
        $t = \App\Models\Task::create(['title' => 'مهمة الاختبار', 'status' => 'جديدة']);
        $this->actingAs($this->owner);
        $t->update(['status' => 'قيد التنفيذ']);

        $html = $this->get('/admin/audit')->assertOk()->getContent();
        $this->assertStringContainsString('قيد التنفيذ', $html, 'القيمة الجديدة لا تُعرض');
        $this->assertStringContainsString('الحالة', $html, 'اسم الحقل يُعرض بالعربية لا بمفتاح العمود');
    }

    public function test_audit_screen_reports_a_broken_chain(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        hub_audit('إضافة', 'tasks', null, 'قيدٌ سليم');

        $ok = $this->get('/admin/audit')->assertOk()->getContent();
        $this->assertStringContainsString('سلسلة سليمة', $ok);

        // عبثٌ مباشر بالقاعدة يلتفّ على كل حارس تطبيقي — والبصمة تكشفه
        DB::table('audits')->where('name', 'قيدٌ سليم')->update(['name' => 'قيدٌ مُحرَّف']);

        $this->assertStringContainsString('عبث', $this->get('/admin/audit')->assertOk()->getContent(),
            'السلسلة مكسورة والشاشة تقول «سليمة» — بصمةٌ لا يقرؤها أحد لا تحمي شيئاً');
    }

    /** المُنطَّق لا يرى غيره مهما رشّح — المرشِّح ليس باباً خلفياً */
    public function test_audit_filters_do_not_bypass_scope(): void
    {
        $this->seedCore();
        $role = \App\Models\Role::create(['name' => 'مدقّق مقيّد', 'scope' => 'proj',
            'flags' => ['audit' => 1], 'matrix' => []]);
        $u = User::create(['name' => 'مقيّد', 'email' => 'aud@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($this->owner);
        hub_audit('تصدير', 'hr', null, 'سرٌّ لا يخصّه');

        $this->actingAs($u)->get('/admin/audit?user=' . $this->owner->id)
            ->assertOk()->assertDontSee('سرٌّ لا يخصّه');
    }

    /**
     * عزل الشركات كان يسري على البيانات ولا يسري على أثرها: القيد يحمل
     * `company_id` منذ سمة Auditable، وشاشة التدقيق تقرؤه كله — فمن يرى شركةً
     * واحدة يقرأ في السجل ما فُعل في بقية الشركات باسم السجل وقيمته.
     */
    public function test_audit_respects_company_isolation(): void
    {
        $this->seedCore();
        $mine = \App\Models\Company::create(['name_ar' => 'شركتي', 'status' => 'نشطة']);
        $other = \App\Models\Company::create(['name_ar' => 'شركة أخرى', 'status' => 'نشطة']);

        $role = \App\Models\Role::create(['name' => 'محاسبة شركة', 'scope' => 'all',
            'flags' => ['audit' => 1], 'matrix' => []]);
        $u = User::create(['name' => 'معزولة', 'email' => 'iso@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => [$mine->id]]);

        $this->actingAs($this->owner);
        hub_audit('تعديل', 'clients', null, 'عميل الشركة الأخرى', ['company_id' => $other->id]);
        hub_audit('تعديل', 'clients', null, 'عميل شركتي', ['company_id' => $mine->id]);

        $this->actingAs($u)->get('/admin/audit')->assertOk()
            ->assertSee('عميل شركتي')
            ->assertDontSee('عميل الشركة الأخرى');
    }
}
