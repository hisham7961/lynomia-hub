<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SessionLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WP-4.4 — مركزُ الجلسات (security.sessions) وسكّةُ الإنهاء الواحدة.
 *
 * القواعد المُثبَتة هنا:
 *  - ترتيبٌ حتميّ (last_seen_at desc, id desc) فلا يتكرّر صفٌّ بين صفحتين
 *    ولا يسقط صفٌّ بينهما — القرعةُ على MySQL 8 كانت تُخفي النقص.
 *  - المرشِّحات: مستخدم/عنوان/حيّة أو مُنهاة/مدى زمنيّ (TimeRange).
 *  - وسمُ «غير معتادة» من ذاكرة العناوين القائمة (user_ips) — لا بصمةَ جديدة.
 *  - إنهاءُ كل الجلسات (security.user.revoke) يتطلّب تصعيداً (§18)، يُدقَّق،
 *    يدوّر «تذكّرني»، ويكتب revoked_at/by/reason (لا خلطَ خروجٍ بإنهاءٍ إداريّ).
 *  - «إنهاءُ الباقي» (security.user.revokeothers) يُبقي جلسةَ المنفّذ الحالية.
 *  - كلُّ سكك الإنهاء (الإداريّ والذاتيّ) تكتب أثرَ الإبطال عبر سكّة Sessions الواحدة.
 *  - القراءةُ للمالك كاملةً، ولحامل monitor مطموسةَ البريد والعنوان ومنطَّقةً
 *    بالشركة (critic #9)، وللموظف ٤٠٣ — ولا سرَّ في أيّ ردّ.
 */
class SessionCenterTest extends TestCase
{
    /** مستخدمٌ بعلم monitor وحده (غير مالك) — بشركاتٍ محدَّدة اختيارياً */
    protected function monitorUser(array $companies = []): User
    {
        $role = Role::create(['name' => 'مراقب ' . Str::random(4), 'scope' => 'all',
            'flags' => ['monitor' => 1], 'matrix' => []]);

        return User::create(['name' => 'مراقب', 'email' => 'mon' . Str::random(4) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'companies' => $companies]);
    }

    /** صفُّ جلسةٍ خام — كما يكتبه الدخول (قبل دقيقة: حدُّ المدى `< to` حصريٌّ باللحظة) */
    protected function makeSession(User $u, array $over = []): SessionLog
    {
        return SessionLog::create($over + ['id' => (string) Str::uuid(), 'user_id' => $u->id,
            'ip' => '10.9.9.9', 'device' => 'Mozilla/5.0 Chrome/120', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120',
            'started_at' => now()->subHour(), 'last_seen_at' => now()->subMinute()]);
    }

    /** ترقيمٌ ثابت: صفوفٌ متعادلةُ last_seen_at لا تتكرّر ولا تسقط بين الصفحتين */
    public function test_pagination_is_deterministic_across_pages(): void
    {
        $this->seedCore();
        $tie = now()->subMinutes(5);
        for ($i = 1; $i <= 30; $i++) {
            $this->makeSession($this->employee, ['ip' => '10.0.0.' . $i, 'last_seen_at' => $tie]);
        }

        $p1 = $this->actingAs($this->owner)->get(route('security.sessions'))->assertOk()->getContent();
        $p2 = $this->actingAs($this->owner)->get(route('security.sessions', ['page' => 2]))->assertOk()->getContent();

        preg_match_all('/10\.0\.0\.\d+/', $p1, $m1);
        preg_match_all('/10\.0\.0\.\d+/', $p2, $m2);
        $a = array_unique($m1[0]);
        $b = array_unique($m2[0]);

        $this->assertSame([], array_values(array_intersect($a, $b)),
            'صفٌّ ظهر في صفحتين — الترتيبُ بلا فاصل تعادلٍ (id) قرعةٌ على MySQL');
        $this->assertCount(30, array_unique(array_merge($a, $b)),
            'صفٌّ سقط بين الصفحتين — الترقيمُ غيرُ ثابتٍ عبر الصفحات');
    }

    /** المرشِّحات: مستخدم/عنوان/حالة/مدى — كلٌّ يضيّق فعلاً */
    public function test_filters_narrow_by_user_ip_state_and_range(): void
    {
        $this->seedCore();
        $this->makeSession($this->employee, ['ip' => '1.1.1.1']);
        $this->makeSession($this->owner, ['ip' => '2.2.2.2', 'revoked' => true, 'last_seen_at' => now()->subHours(2)]);
        $this->makeSession($this->employee, ['ip' => '3.3.3.3', 'last_seen_at' => now()->subDays(40),
            'started_at' => now()->subDays(40)]);

        $this->actingAs($this->owner)->get(route('security.sessions', ['u' => $this->employee->id]))
            ->assertOk()->assertSee('1.1.1.1')->assertDontSee('2.2.2.2');

        $this->actingAs($this->owner)->get(route('security.sessions', ['state' => 'revoked']))
            ->assertOk()->assertSee('2.2.2.2')->assertDontSee('1.1.1.1');

        $this->actingAs($this->owner)->get(route('security.sessions', ['ip' => '1.1.1.1']))
            ->assertOk()->assertSee('1.1.1.1')->assertDontSee('2.2.2.2');

        // المدى الافتراضي ٧ أيام يستبعد جلسةً آخرُ ظهورها قبل ٤٠ يوماً — و٩٠ يوماً تُظهرها
        $this->actingAs($this->owner)->get(route('security.sessions'))->assertOk()->assertDontSee('3.3.3.3');
        $this->actingAs($this->owner)->get(route('security.sessions', ['range' => '90d']))->assertOk()->assertSee('3.3.3.3');
    }

    /** وسمُ «غير معتادة» للعنوان غير المألوف وحدَه — من user_ips القائمة لا من بصمةٍ جديدة */
    public function test_unusual_marker_flags_only_the_unfamiliar_ip(): void
    {
        $this->seedCore();
        DB::table('user_ips')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->employee->id,
            'ip' => '5.5.5.5', 'hits' => 5, 'last_seen_at' => now()]);
        $this->makeSession($this->employee, ['ip' => '5.5.5.5']);
        $this->makeSession($this->employee, ['ip' => '6.6.6.6']);

        $html = $this->actingAs($this->owner)->get(route('security.sessions'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'غير معتادة'),
            'الوسمُ يجب أن يصيب العنوانَ الغريب وحدَه (6.6.6.6) لا المألوف (5.5.5.5)');
    }

    /** §18: إنهاءُ كل الجلسات يطلب تصعيداً، يُدقَّق، يدوّر «تذكّرني»، ويكتب أثرَ الإبطال */
    public function test_revoke_all_requires_stepup_is_audited_rotates_remember_and_writes_metadata(): void
    {
        $this->seedCore();
        $this->employee->forceFill(['remember_token' => 'tok-before-revoke'])->saveQuietly();
        $sl = $this->makeSession($this->employee);

        // بلا تصعيدٍ: تحويلٌ لبوابة التأكيد والجلسةُ باقية
        $r = $this->actingAs($this->owner)->post(route('security.user.revoke', $this->employee->id));
        $this->assertStringContainsString('/stepup?next=', (string) $r->headers->get('Location'),
            'إنهاءُ كل الجلسات فعلٌ حسّاس (§18) — يجب أن يمرّ ببوابة التصعيد');
        $this->assertFalse((bool) SessionLog::find($sl->id)->revoked, 'الفعلُ نفذ قبل التصعيد');

        // بعد التصعيد يمضي: إبطالٌ بأثرٍ كامل + تدوير + قيدُ تدقيق
        $this->actingAs($this->owner)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/admin/security']);
        $this->actingAs($this->owner)->post(route('security.user.revoke', $this->employee->id))->assertRedirect();

        $row = DB::table('sessions_log')->where('id', $sl->id)->first();
        $this->assertTrue((bool) $row->revoked);
        $this->assertNotNull($row->revoked_at, 'الإنهاءُ الإداريّ بلا revoked_at يخلط الخروجَ بالإبطال');
        $this->assertSame((string) $this->owner->id, (string) $row->revoked_by);
        $this->assertNotSame('', (string) $row->revoke_reason);
        $this->assertNotSame('tok-before-revoke', User::find($this->employee->id)->remember_token,
            'رمز «تذكّرني» لم يُدوَّر — الكعكة تُعيد بعث الجلسة المُنهاة');
        $this->assertDatabaseHas('audits', ['action' => 'إنهاء جلسات مستخدم']);
    }

    /** «إنهاءُ الباقي» يُبقي جلسةَ المنفّذ الحالية ويُبطل سواها بأثرٍ كامل */
    public function test_revoke_others_keeps_the_callers_current_session(): void
    {
        $this->seedCore();
        $mine = $this->makeSession($this->owner, ['ip' => '10.1.1.1']);
        $other = $this->makeSession($this->owner, ['ip' => '10.2.2.2']);

        // بلا تصعيدٍ يُصدّ كذلك (§18)
        $r = $this->actingAs($this->owner)->withSession(['hub.sl' => $mine->id])
            ->post(route('security.user.revokeothers', $this->owner->id));
        $this->assertStringContainsString('/stepup?next=', (string) $r->headers->get('Location'));

        $this->actingAs($this->owner)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/admin/security']);
        $this->actingAs($this->owner)->withSession(['hub.sl' => $mine->id])
            ->post(route('security.user.revokeothers', $this->owner->id))->assertRedirect();

        $this->assertFalse((bool) DB::table('sessions_log')->where('id', $mine->id)->value('revoked'),
            '«إنهاءُ الباقي» أنهى جلسةَ المنفّذ نفسِه');
        $row = DB::table('sessions_log')->where('id', $other->id)->first();
        $this->assertTrue((bool) $row->revoked);
        $this->assertNotNull($row->revoked_at);
        $this->assertSame((string) $this->owner->id, (string) $row->revoked_by);
        $this->assertDatabaseHas('audits', ['action' => 'إنهاء جلسات مستخدم']);
    }

    /** كلُّ سكك الإنهاء — الإداريُّ لجلسةٍ واحدة والذاتيُّ — تكتب الأثرَ عبر السكّة الواحدة */
    public function test_single_and_self_service_revocations_write_metadata_through_one_rail(): void
    {
        $this->seedCore();

        // الإداريّ: جلسةٌ واحدة من مركز الأمان
        $sl = $this->makeSession($this->employee);
        $this->actingAs($this->owner)->post(route('security.session.revoke', $sl->id))->assertRedirect();
        $row = DB::table('sessions_log')->where('id', $sl->id)->first();
        $this->assertTrue((bool) $row->revoked);
        $this->assertNotNull($row->revoked_at, 'إنهاءُ جلسةٍ واحدة لا يكتب revoked_at — سكّةٌ خارج التوحيد');
        $this->assertSame((string) $this->owner->id, (string) $row->revoked_by);

        // الذاتيّ: «إنهاء بقية أجهزتي» يُبقي جلستي ويدوّر «تذكّرني»
        $this->employee->forceFill(['remember_token' => 'tok-my-old'])->saveQuietly();
        $mine = $this->makeSession($this->employee, ['ip' => '10.5.5.5']);
        $other = $this->makeSession($this->employee, ['ip' => '10.6.6.6']);
        $this->actingAs($this->employee)->withSession(['hub.sl' => $mine->id])
            ->post(route('mysec.session.others'))->assertRedirect();

        $this->assertFalse((bool) DB::table('sessions_log')->where('id', $mine->id)->value('revoked'));
        $o = DB::table('sessions_log')->where('id', $other->id)->first();
        $this->assertTrue((bool) $o->revoked);
        $this->assertNotNull($o->revoked_at, 'الإنهاءُ الذاتيّ خارج سكّة الأثر الواحدة');
        $this->assertSame((string) $this->employee->id, (string) $o->revoked_by);
        $this->assertNotSame('tok-my-old', User::find($this->employee->id)->remember_token);
    }

    /** اختبارُ التسريب الإلزاميّ (critic #9): مالكٌ كامل · مراقبٌ مطموسٌ ومنطَّق · موظفٌ ٤٠٣ */
    public function test_monitor_reads_masked_and_scoped_owner_full_employee_forbidden(): void
    {
        $this->seedCore();
        $coA = (string) Str::uuid();
        $coB = (string) Str::uuid();
        DB::table('companies')->insert([
            ['id' => $coA, 'name_ar' => 'شركة أ', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $coB, 'name_ar' => 'شركة ب', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $target = User::create(['name' => 'هدف التسريب', 'email' => 'target9@test.local',
            'password' => 'Secret!2026x', 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => [$coA], 'remember_token' => 'tok-secret-value']);
        $this->makeSession($target, ['ip' => '9.9.9.9']);

        // موظفٌ بلا علم: ٤٠٣
        $this->actingAs($this->employee)->get(route('security.sessions'))->assertForbidden();

        // المالك: بريدٌ وعنوانٌ كاملان
        $this->actingAs($this->owner)->get(route('security.sessions'))
            ->assertOk()->assertSee('9.9.9.9')->assertSee('target9@test.local');

        // مراقبٌ غيرُ منطَّق: يقرأ الصفوف مطموسةَ البريد والعنوان — ولا سرَّ في الردّ
        $html = $this->actingAs($this->monitorUser())->get(route('security.sessions'))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('9.9.9.9', $html, 'عنوانُ الشبكة تسرّب لقارئ المراقبة');
        $this->assertStringNotContainsString('target9@test.local', $html, 'البريدُ تسرّب لقارئ المراقبة');
        $this->assertStringContainsString('عنوان محجوب', $html);
        $this->assertStringNotContainsString('tok-secret-value', $html);
        $this->assertStringNotContainsString(User::find($target->id)->password, $html);

        // مراقبٌ منطَّق على شركةٍ أخرى: صفُّ الهدف لا يظهر له أصلاً
        $this->actingAs($this->monitorUser([$coB]))->get(route('security.sessions'))
            ->assertOk()->assertDontSee('هدف التسريب');
    }
}
