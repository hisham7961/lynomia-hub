<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\VaultSecret;
use Tests\TestCase;

/**
 * تصليبٌ أمنيّ — الموجة الأولى من منصة الأمن (المرحلة ب).
 *
 * كلُّ عيبٍ كشفه التدقيق يُكتَب هنا **إثباتاً** لا ادّعاءً: الاختبار يصف
 * السلوك الصحيح، والإصلاحُ يجعله أخضر — فلا تعود الثغرةُ دون أن يحمرَّ.
 */
class SecurityHardeningRound1Test extends TestCase
{
    public function test_api_token_creation_is_audited_and_capped(): void
    {
        $this->seedCore();

        // مفتاحٌ بلا مدّةٍ يأخذ العمرَ الأقصى الافتراضي (٣٦٥) لا الدوام
        $this->actingAs($this->employee)->post('/profile/token', ['tname' => 'مفتاح'])
            ->assertRedirect();
        $tok = \App\Models\ApiToken::where('user_id', $this->employee->id)->first();
        $this->assertNotNull($tok->expires_at, 'المفتاح بلا مدّة صار دائماً — يجب فرض العمر الأقصى');

        // والسكُّ يدخل سلسلة التدقيق باسمه — كان يمرّ صامتاً
        $this->assertDatabaseHas('audits', ['action' => 'إنشاء مفتاح API', 'user_id' => $this->employee->id]);

        // ولا يتجاوز السقف حتى لو طُلب أطول منه (٧٠٠ مقبولٌ في التحقق لكنه يُقصَّر لـ٣٦٥)
        $this->actingAs($this->employee)->post('/profile/token', ['tname' => 'طويل', 'tdays' => 700]);
        $long = \App\Models\ApiToken::where('user_id', $this->employee->id)->where('name', 'طويل')->first();
        $this->assertNotNull($long);
        $this->assertLessThanOrEqual(now()->addDays(366)->timestamp, $long->expires_at->timestamp);

        // والإبطال يُدوَّن أيضاً
        $this->actingAs($this->employee)->delete('/profile/token/' . $tok->id)->assertRedirect();
        $this->assertDatabaseHas('audits', ['action' => 'إبطال مفتاح API']);
    }

    public function test_zero_cap_allows_a_permanent_token_deliberately(): void
    {
        $this->seedCore();
        $this->hubSetting('api.token_max_days', '0');
        $this->actingAs($this->employee)->post('/profile/token', ['tname' => 'دائم عمداً']);
        $tok = \App\Models\ApiToken::where('name', 'دائم عمداً')->first();
        $this->assertNull($tok->expires_at, 'التصفير الصريح يعيد الدوام لمن أراده');
    }

    public function test_successful_login_logout_and_password_change_are_audited(): void
    {
        $this->seedCore();

        $this->post('/login', ['email' => 'emp@test.local', 'password' => 'Secret!2026x']);
        $this->assertDatabaseHas('audits', ['action' => 'دخول ناجح', 'user_id' => $this->employee->id]);

        $this->actingAs($this->employee)->post('/logout');
        $this->assertDatabaseHas('audits', ['action' => 'خروج']);

        $this->actingAs($this->employee)->put('/profile/password', [
            'current' => 'Secret!2026x',
            'password' => 'NewSecret!2026x', 'password_confirmation' => 'NewSecret!2026x',
        ]);
        $this->assertDatabaseHas('audits', ['action' => 'تغيير كلمة المرور', 'user_id' => $this->employee->id]);
    }

    public function test_dataroom_link_creation_and_revocation_are_audited(): void
    {
        $this->seedCore();
        \Illuminate\Support\Facades\Storage::fake('local');

        $this->actingAs($this->owner)->post('/dataroom', [
            'title' => 'مستند سري',
            'file' => \Illuminate\Http\Testing\File::create('doc.pdf', 10),
        ])->assertRedirect();
        $this->assertDatabaseHas('audits', ['action' => 'إنشاء رابط مشاركة']);

        $link = \App\Models\ShareLink::first();
        $this->actingAs($this->owner)->post('/dataroom/' . $link->id . '/revoke')->assertRedirect();
        $this->assertDatabaseHas('audits', ['action' => 'إلغاء رابط مشاركة']);
    }

    public function test_comment_pin_and_resolve_respect_record_scope(): void
    {
        $this->seedCore();

        // مشروعٌ خارج نطاق موظفٍ مقيَّد بالمشاريع، وعليه تعليق
        $p = Project::create(['name' => 'مشروع خارج النطاق', 'status' => 'نشط']);
        $c = Comment::create(['module' => 'projects', 'record_id' => $p->id,
            'user_id' => $this->owner->id, 'body' => 'تعليق', 'created_at' => now(), 'updated_at' => now()]);

        $projRole = Role::create(['name' => 'مقيّد بالمشاريع', 'scope' => 'proj', 'flags' => [],
            'matrix' => ['projects' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]]]);
        $scoped = User::create(['name' => 'مقيّد', 'email' => 'scoped@test.local',
            'password' => 'Secret!2026x', 'role_id' => $projRole->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        // لا مشروعَ مُسنَدٌ له → السجل خارج نطاقه → التثبيت والحل يُردّان 404
        $this->actingAs($scoped)->post('/comments/' . $c->id . '/pin')->assertNotFound();
        $this->actingAs($scoped)->post('/comments/' . $c->id . '/resolve')->assertNotFound();
    }

    public function test_secret_reveal_and_export_routes_are_rate_limited(): void
    {
        $this->seedCore();
        $names = collect(\Illuminate\Support\Facades\Route::getRoutes())->mapWithKeys(function ($r) {
            return [$r->getName() => array_map('strval', $r->gatherMiddleware())];
        });
        foreach (['m.secret', 'm.export', 'm.bulk', 'profile.token.store'] as $rn) {
            $mw = $names[$rn] ?? [];
            $this->assertTrue(
                (bool) collect($mw)->contains(fn ($m) => str_starts_with($m, 'throttle')),
                "المسار {$rn} بلا حدِّ معدل — وهو مسارُ استنزافٍ حسّاس"
            );
        }
    }

    public function test_executable_web_extensions_are_blocked_on_attachments(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع', 'status' => 'نشط']);

        foreach (['x.svg', 'x.html', 'x.js'] as $bad) {
            $this->actingAs($this->owner)->post('/attachments', [
                'module' => 'projects', 'record_id' => $p->id,
                'file' => \Illuminate\Http\Testing\File::create($bad, 5),
            ])->assertStatus(422);
        }
    }

    public function test_login_registers_a_pending_device_and_self_service_can_manage_it(): void
    {
        $this->seedCore();

        $this->post('/login', ['email' => 'emp@test.local', 'password' => 'Secret!2026x']);
        $dev = \App\Models\UserDevice::where('user_id', $this->employee->id)->first();
        $this->assertNotNull($dev, 'الدخول لم يسجّل جهازاً');
        $this->assertSame('معلّق', $dev->trust, 'أول ظهورٍ يجب أن يكون معلّقاً لا موثوقاً');

        // شاشة «جلساتي وأجهزتي» تفتح وتعرض الجهاز
        $this->actingAs($this->employee)->get('/my/security')->assertOk()->assertSee('جلساتي وأجهزتي');

        // توثيقٌ ذاتي
        $this->actingAs($this->employee)->post('/my/security/devices/' . $dev->id . '/trust')->assertRedirect();
        $this->assertSame('موثوق', $dev->fresh()->trust);
    }

    public function test_a_user_cannot_manage_another_users_device_or_session(): void
    {
        $this->seedCore();
        $dev = \App\Models\UserDevice::create([
            'user_id' => $this->owner->id, 'cookie_hash' => hash('sha256', 'x'),
            'label' => 'جهاز المالك', 'trust' => 'معلّق', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        // موظفٌ يحاول إبطال جهاز المالك → 404 (ليس صفَّه)
        $this->actingAs($this->employee)->post('/my/security/devices/' . $dev->id . '/revoke')->assertNotFound();

        $sess = \App\Models\SessionLog::create([
            'user_id' => $this->owner->id, 'device' => 'x', 'ip' => '1.1.1.1',
            'started_at' => now(), 'last_seen_at' => now(),
        ]);
        $this->actingAs($this->employee)->post('/my/security/sessions/' . $sess->id . '/revoke')->assertNotFound();
    }

    public function test_logout_marks_the_session_revoked(): void
    {
        $this->seedCore();
        $this->post('/login', ['email' => 'emp@test.local', 'password' => 'Secret!2026x']);
        $sl = \App\Models\SessionLog::where('user_id', $this->employee->id)->latest('started_at')->first();
        $this->assertFalse((bool) $sl->revoked);
        $this->actingAs($this->employee)->post('/logout');
        $this->assertTrue((bool) $sl->fresh()->revoked, 'الخروج لم يُنهِ صفَّ الجلسة');
    }

    public function test_workday_card_does_not_leak_out_of_scope_project_names(): void
    {
        $this->seedCore();
        // مشروعٌ لا يخصّ موظفاً مقيَّداً بالمشاريع
        Project::create(['name' => 'مشروع سرّي جداً', 'status' => 'نشط']);

        $projRole = Role::create(['name' => 'مقيّد٢', 'scope' => 'proj', 'flags' => [],
            'matrix' => array_merge(
                collect(array_keys(config('hub.modules')))->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all()
            )]);
        $emp = \App\Models\Employee::create(['name' => 'مندوب مقيّد', 'status' => 'نشط']);
        $scoped = User::create(['name' => 'مقيّد٢', 'email' => 'sc2@test.local',
            'password' => 'Secret!2026x', 'role_id' => $projRole->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
        $emp->update(['user_id' => $scoped->id]);

        $mine = \App\Support\Workday::mine($scoped);
        $names = collect($mine['projects'] ?? [])->values()->all();
        $this->assertNotContains('مشروع سرّي جداً', $names,
            'قائمةُ مشاريع بطاقة اليوم سرّبت مشروعاً خارج نطاق المستخدم');
    }
}
