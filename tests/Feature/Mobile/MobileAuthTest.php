<?php

namespace Tests\Feature\Mobile;

use App\Models\AuditEntry;
use App\Models\HubNotification;
use App\Models\MobileInstallation;
use App\Models\MobileSession;
use App\Models\MobileStepupGrant;
use App\Support\MobileSessionService;
use App\Support\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **سطحُ مصادقةِ الجوال الأصيل** — Mobile Readiness · الطور B · §109.
 *
 * اختباراتُ عقدٍ وسلوكٍ على المسارات الحقيقية `/api/mobile/v1/auth/*`: دخولٌ
 * (بحراسه الخمسة ودفاعِه ضد التعداد F12/F13)، MFA صادقة (F10)، سكُّ جلسةٍ
 * **تجزئةً لا نصّاً**، انتهاءُ الوصول، تدويرُ التحديث وكشفُ الإعادة (F عائلة)،
 * خروجٌ/خروجٌ شامل، إدارةُ الجلسات (بلا IDOR)، وتصعيدٌ عديمُ الحالة (F11).
 *
 * كلُّ اختبارٍ إثباتٌ لسلوكٍ صحيحٍ — لا يُضعَّف اختبارٌ ليمرّ.
 */
class MobileAuthTest extends TestCase
{
    use InteractsWithMobileAuth;

    // ════════════════════════════ الدخول ════════════════════════════

    public function test_valid_login_issues_access_and_refresh_pair(): void
    {
        $this->seedCore();

        $res = $this->mobileLoginRequest($this->employee->email);
        $res->assertOk();

        $data = $res->json('data');
        $this->assertNotEmpty($data['access_token']);
        $this->assertNotEmpty($data['refresh_token']);
        $this->assertNotSame($data['access_token'], $data['refresh_token'], 'الرمزان مختلفان');
        $this->assertSame('Bearer', $data['token_type']);
        $this->assertNotEmpty($data['session_id']);
        $this->assertNotEmpty($data['installation_id']);
        $this->assertNotEmpty($data['access_expires_at']);
        $this->assertNotEmpty($data['refresh_expires_at']);
        $this->assertSame($this->employee->id, $data['user']['id']);
        $this->assertSame($this->employee->email, $data['user']['email']);
        $this->assertFalse($data['user']['is_owner']);

        // صفٌّ واحدٌ في mobile_sessions لهذا المستخدم، حيّ
        $this->assertSame(1, MobileSession::where('user_id', $this->employee->id)->count());
        $this->assertSame('mobile', AuditEntry::where('action', 'دخول ناجح')->orderByDesc('id')->value('source'),
            'أثرُ الدخول الناجح يحمل source=mobile');
    }

    public function test_invalid_password_returns_unauthenticated(): void
    {
        $this->seedCore();

        $res = $this->mobileLoginRequest($this->employee->email, 'wrong-password');
        $res->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->assertSame(0, MobileSession::count(), 'كلمةٌ خاطئة لا تسكّ جلسة');
    }

    public function test_validation_failure_on_missing_fields(): void
    {
        $this->seedCore();

        $this->postJson('/api/mobile/v1/auth/login', ['email' => 'not-an-email'])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    /**
     * **F12 — دفاعُ التعداد:** مجهولُ البريد، وخاطئُ الكلمة، وحسابٌ
     * موقوف/مقفول/منتهٍ **بكلمةٍ خاطئة** — كلُّها ردٌّ واحدٌ لا يميّز (اعتمادٌ أولاً).
     */
    public function test_enumeration_parity_identical_response_for_unknown_email_and_wrong_password_and_restricted_accounts(): void
    {
        $this->seedCore();

        // حساباتٌ محجوبةٌ بحالاتٍ مختلفة — لكن كلُّ المحاولات هنا بكلمةٍ **خاطئة**
        $suspended = \App\Models\User::create(['name' => 'موقوف', 'email' => 'susp@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id, 'status' => 'موقوف',
            'password_changed_at' => now()]);
        $locked = \App\Models\User::create(['name' => 'مقفول', 'email' => 'lock@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id, 'status' => 'نشط',
            'locked_until' => now()->addHour(), 'password_changed_at' => now()]);
        $expired = \App\Models\User::create(['name' => 'منتهٍ', 'email' => 'exp@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id, 'status' => 'نشط',
            'expires_at' => now()->subDay()->toDateString(), 'password_changed_at' => now()]);

        $baseline = $this->bodyWithoutRequestId($this->mobileLoginRequest('nobody@test.local', 'whatever'));

        $this->assertSame(401, $baseline['status']);
        $this->assertSame('UNAUTHENTICATED', $baseline['code']);

        foreach ([
            'wrong-password-active'    => [$this->employee->email, 'wrong'],
            'wrong-password-suspended' => [$suspended->email, 'wrong'],
            'wrong-password-locked'    => [$locked->email, 'wrong'],
            'wrong-password-expired'   => [$expired->email, 'wrong'],
        ] as $label => [$email, $pw]) {
            $got = $this->bodyWithoutRequestId($this->mobileLoginRequest($email, $pw));
            $this->assertSame($baseline, $got, "التعداد: {$label} يجب أن يطابق مجهولَ البريد حرفاً بحرف");
        }

        // ولا جلسةَ سُكّت لأيٍّ منها
        $this->assertSame(0, MobileSession::count());
    }

    public function test_suspended_account_with_correct_password_returns_account_restricted(): void
    {
        $this->seedCore();
        $this->employee->forceFill(['status' => 'موقوف'])->saveQuietly();

        $res = $this->mobileLoginRequest($this->employee->email);
        $res->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_RESTRICTED')
            ->assertJsonPath('details.reason', 'account_suspended');
        $this->assertSame(0, MobileSession::count());
    }

    public function test_locked_account_with_correct_password_returns_account_restricted(): void
    {
        $this->seedCore();
        $this->employee->forceFill(['locked_until' => now()->addMinutes(15)])->saveQuietly();

        $res = $this->mobileLoginRequest($this->employee->email);
        $res->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_RESTRICTED')
            ->assertJsonPath('details.reason', 'account_locked');
    }

    public function test_expired_account_with_correct_password_returns_account_restricted(): void
    {
        $this->seedCore();
        $this->employee->forceFill(['expires_at' => now()->subDay()->toDateString()])->saveQuietly();

        $res = $this->mobileLoginRequest($this->employee->email);
        $res->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_RESTRICTED')
            ->assertJsonPath('details.reason', 'account_expired');
    }

    public function test_ip_restricted_account_with_correct_password_returns_account_restricted(): void
    {
        $this->seedCore();
        // عنوانٌ لا يطابق 127.0.0.1 (عنوانُ طلبِ الاختبار)
        $this->employee->forceFill(['allowed_ips' => '203.0.113.5'])->saveQuietly();

        $res = $this->mobileLoginRequest($this->employee->email);
        $res->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_RESTRICTED')
            ->assertJsonPath('details.reason', 'account_ip_allowlist');
    }

    public function test_lockdown_blocks_non_owner_login_with_lockdown_code(): void
    {
        $this->seedCore();
        $this->hubSetting('security.lockdown', '1');

        $res = $this->mobileLoginRequest($this->employee->email);
        $res->assertStatus(503)->assertJsonPath('code', 'LOCKDOWN');
        $this->assertSame(0, MobileSession::count());
    }

    // ════════════════════════════ حدُّ المعدّل (F4) ════════════════════════════

    public function test_login_rate_limit_is_ten_per_minute_not_the_loose_api_limit(): void
    {
        $this->seedCore();

        // عشرُ محاولاتٍ (ببريدٍ مجهولٍ كي لا يتدخّل قفلُ الحساب) تمرّ، والحاديةَ عشرةَ تُخنق
        for ($i = 0; $i < 10; $i++) {
            $this->mobileLoginRequest('ghost@test.local', 'nope', (string) Str::uuid())
                ->assertStatus(401);
        }
        $this->mobileLoginRequest('ghost@test.local', 'nope', (string) Str::uuid())
            ->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
    }

    // ════════════════════════════ MFA (F10) ════════════════════════════

    public function test_totp_user_login_returns_mfa_required_with_only_totp_method(): void
    {
        $this->seedCore();
        $this->enableTotp($this->employee);

        $res = $this->mobileLoginRequest($this->employee->email);
        $res->assertStatus(401)->assertJsonPath('code', 'MFA_REQUIRED');
        $this->assertNotEmpty($res->json('details.challenge_id'));
        $this->assertSame(['totp'], $res->json('details.methods'), 'يُعلَن totp حصراً — لا webauthn (F10)');
        $this->assertNotEmpty($res->json('details.expires_at'));

        // لا جلسةَ سُكّت بعد — التنصيبُ سُجّل فقط
        $this->assertSame(0, MobileSession::count());
        $this->assertSame(1, MobileInstallation::count());
    }

    public function test_mfa_verify_wrong_code_fails_bumps_counter_and_keeps_challenge(): void
    {
        $this->seedCore();
        $secret = $this->enableTotp($this->employee);

        $challenge = $this->mobileLoginRequest($this->employee->email)->json('details.challenge_id');

        $res = $this->postJson('/api/mobile/v1/auth/mfa/verify',
            ['challenge_id' => $challenge, 'code' => $this->wrongTotpCode($secret)]);
        $res->assertStatus(401)->assertJsonPath('code', 'MFA_REQUIRED');

        $this->assertSame(1, (int) $this->employee->fresh()->failed_attempts, 'الرمزُ الخاطئ يرفع العدّاد');
        $this->assertSame(0, MobileSession::count());

        // التحدّي **يبقى** لإعادة المحاولة — رمزٌ صحيحٌ الآن ينجح
        $ok = $this->postJson('/api/mobile/v1/auth/mfa/verify',
            ['challenge_id' => $challenge, 'code' => Totp::code($secret)]);
        $ok->assertOk();
        $this->assertNotEmpty($ok->json('data.access_token'));
    }

    public function test_mfa_verify_correct_code_issues_session(): void
    {
        $this->seedCore();
        $secret = $this->enableTotp($this->employee);

        $challenge = $this->mobileLoginRequest($this->employee->email)->json('details.challenge_id');

        $res = $this->postJson('/api/mobile/v1/auth/mfa/verify',
            ['challenge_id' => $challenge, 'code' => Totp::code($secret)]);
        $res->assertOk();
        $this->assertNotEmpty($res->json('data.access_token'));
        $this->assertNotEmpty($res->json('data.refresh_token'));
        $this->assertSame(1, MobileSession::where('user_id', $this->employee->id)->count());
        $this->assertSame(0, (int) $this->employee->fresh()->failed_attempts, 'النجاحُ يصفّر العدّاد');
    }

    public function test_mfa_challenge_is_single_use(): void
    {
        $this->seedCore();
        $secret = $this->enableTotp($this->employee);

        $challenge = $this->mobileLoginRequest($this->employee->email)->json('details.challenge_id');

        $this->postJson('/api/mobile/v1/auth/mfa/verify',
            ['challenge_id' => $challenge, 'code' => Totp::code($secret)])->assertOk();

        // إعادةُ استخدامِ التحدّي نفسِه (برمزٍ جديدٍ صالح) تُرفَض — استُهلك لمرّة
        $again = $this->postJson('/api/mobile/v1/auth/mfa/verify',
            ['challenge_id' => $challenge, 'code' => Totp::code($secret)]);
        $again->assertStatus(401)->assertJsonPath('code', 'MFA_REQUIRED');
        $this->assertSame(1, MobileSession::where('user_id', $this->employee->id)->count(),
            'التحدّي أحاديُّ الاستعمال — لا جلسةٌ ثانيةٌ منه');
    }

    /**
     * **الحراسُ الخمسة يُعادون على `mfa/verify` كما على `login`** — حسابٌ صار
     * موقوفاً **بين** الدخول والتحقّق لا يُسَكّ له وصولٌ (حتى برمزٍ صحيح)، ولا
     * يُصفَّر عدّادُه، ولا يُختم «دخول ناجح». الدخولُ يفحص الحراسَ قبل التحدّي؛
     * وهذه الخطوةُ الثانيةُ تفحصها ثانيةً كي لا تنفتح نافذةُ تغيّرِ الحالة.
     */
    public function test_mfa_verify_reruns_account_gates_for_account_suspended_after_login(): void
    {
        $this->seedCore();
        $secret = $this->enableTotp($this->employee);

        // دخولٌ بكلمةٍ صحيحة والحسابُ سليمٌ الآن ⇒ تحدٍّ
        $challenge = $this->mobileLoginRequest($this->employee->email)->json('details.challenge_id');

        // بين الدخول والتحقّق: أُوقِف الحساب
        $this->employee->forceFill(['status' => 'موقوف'])->saveQuietly();

        // رمزٌ صحيحٌ لكن الحسابَ صار محجوباً ⇒ ACCOUNT_RESTRICTED لا جلسة
        $res = $this->postJson('/api/mobile/v1/auth/mfa/verify',
            ['challenge_id' => $challenge, 'code' => Totp::code($secret)]);
        $res->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_RESTRICTED')
            ->assertJsonPath('details.reason', 'account_suspended');

        $this->assertSame(0, MobileSession::count(), 'لا جلسةَ وصولٍ لحسابٍ محجوب');
        $this->assertFalse(AuditEntry::where('action', 'دخول ناجح')->exists(),
            'لا يُختم «دخول ناجح» لحسابٍ محجوب');
        $this->assertSame(0, (int) $this->employee->fresh()->failed_attempts);
    }

    // ════════════════════════════ سكُّ الجلسة: تجزئةٌ لا نصّ ════════════════════════════

    public function test_session_stores_hashes_never_plaintext_and_never_logs_the_token(): void
    {
        $this->seedCore();

        $data = $this->mobileLogin($this->employee);
        $access = $data['access_token'];
        $refresh = $data['refresh_token'];

        // (١) لا صفَّ يحمل النصَّ الصريحَ في أيٍّ من عمودَي التجزئة
        $this->assertSame(0, MobileSession::where('access_hash', $access)->orWhere('refresh_hash', $access)->count());
        $this->assertSame(0, MobileSession::where('access_hash', $refresh)->orWhere('refresh_hash', $refresh)->count());

        // (٢) المخزَّنُ هو sha256 hex للنصّ الصريح — بالضبط
        $this->assertTrue(MobileSession::where('access_hash', hash('sha256', $access))->exists());
        $this->assertTrue(MobileSession::where('refresh_hash', hash('sha256', $refresh))->exists());

        // (٣) لا نصَّ صريحاً في أيّ سجلِّ تدقيق (أيّ عمود، بما فيه after/before JSON)
        foreach (AuditEntry::all() as $a) {
            $blob = json_encode($a->getAttributes(), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString($access, (string) $blob, 'رمزُ وصولٍ في سجل تدقيق!');
            $this->assertStringNotContainsString($refresh, (string) $blob, 'رمزُ تحديثٍ في سجل تدقيق!');
        }

        // (٤) ولا في سجلّ التطبيق (الرمزُ عشوائيٌّ طازج، فالبحثُ حتميّ)
        $log = storage_path('logs/laravel.log');
        if (is_file($log)) {
            $contents = (string) file_get_contents($log);
            $this->assertStringNotContainsString($access, $contents);
            $this->assertStringNotContainsString($refresh, $contents);
        }
    }

    // ════════════════════════════ انتهاءُ الوصول ════════════════════════════

    public function test_expired_access_token_is_rejected_as_unauthenticated(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->employee);

        // ادفع انتهاءَ الوصول إلى الماضي
        MobileSession::where('id', $data['session_id'])->update(['access_expires_at' => now()->subMinute()]);

        $this->withHeaders($this->bearer($data['access_token']))
            ->getJson('/api/mobile/v1/auth/sessions')
            ->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    // ════════════════════════════ تدويرُ التحديث + كشفُ الإعادة ════════════════════════════

    public function test_refresh_rotates_to_a_new_pair(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->employee);

        $res = $this->postJson('/api/mobile/v1/auth/refresh', ['refresh_token' => $data['refresh_token']]);
        $res->assertOk();
        $new = $res->json('data');

        $this->assertNotSame($data['refresh_token'], $new['refresh_token'], 'رمزُ تحديثٍ جديد');
        $this->assertNotSame($data['access_token'], $new['access_token'], 'رمزُ وصولٍ جديد');
        $this->assertNotEmpty($new['access_token']);

        // الرمزُ الجديد يعمل
        $this->withHeaders($this->bearer($new['access_token']))
            ->getJson('/api/mobile/v1/auth/sessions')->assertOk();
    }

    public function test_missing_or_invalid_refresh_token_is_rejected_without_family_revocation(): void
    {
        $this->seedCore();
        $this->mobileLogin($this->employee);

        $this->postJson('/api/mobile/v1/auth/refresh', [])
            ->assertStatus(401)->assertJsonPath('code', 'REFRESH_TOKEN_INVALID');
        $this->postJson('/api/mobile/v1/auth/refresh', ['refresh_token' => 'lymr_garbage_not_real'])
            ->assertStatus(401)->assertJsonPath('code', 'REFRESH_TOKEN_INVALID');

        // رمزٌ مجهولٌ = انتهاءٌ لا هجوم: الجلسةُ الحيّةُ تبقى حيّة (لا إبطالَ عائلة)
        $this->assertSame(1, MobileSession::where('user_id', $this->employee->id)->whereNull('revoked_at')->count());
    }

    /**
     * **لا تجديدَ اعتمادٍ لحسابٍ صار محجوباً** («لا دخولَ موازٍ أضعف»): حسابٌ
     * أُوقِف بعد الدخول لا يُجدَّد له زوجُ رمزَين حيّ عبر التحديث — الجلسةُ الجديدةُ
     * تُبطَل فوراً، ويُعاد `ACCOUNT_RESTRICTED`، فلا يبقى وصولٌ ولا يُمدَّد رمزُ تحديث.
     */
    public function test_refresh_rejects_and_revokes_when_account_became_restricted(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->employee);

        // بعد الدخول: أُوقِف الحساب
        $this->employee->forceFill(['status' => 'موقوف'])->saveQuietly();

        $res = $this->postJson('/api/mobile/v1/auth/refresh', ['refresh_token' => $data['refresh_token']]);
        $res->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_RESTRICTED')
            ->assertJsonPath('details.reason', 'account_suspended');

        // لا جلسةَ حيّةً بقيت: القديمةُ دُوِّرت والجديدةُ أُبطِلت فوراً
        $this->assertSame(0, MobileSession::where('user_id', $this->employee->id)->whereNull('revoked_at')->count(),
            'لا زوجُ رمزَين حيّ لحسابٍ محجوب');
    }

    public function test_reused_old_refresh_after_rotation_revokes_the_whole_family_and_alerts(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->employee);
        $oldRefresh = $data['refresh_token'];
        $family = MobileSession::where('id', $data['session_id'])->value('family_id');

        // تدويرٌ ناجح — الرمزُ القديم صار مُستهلَكاً
        $this->postJson('/api/mobile/v1/auth/refresh', ['refresh_token' => $oldRefresh])->assertOk();

        // إعادةُ استعمالِ الرمزِ القديم = إشارةُ هجوم
        $reuse = $this->postJson('/api/mobile/v1/auth/refresh', ['refresh_token' => $oldRefresh]);
        $reuse->assertStatus(401)->assertJsonPath('code', 'REFRESH_TOKEN_INVALID');

        // العائلةُ كلُّها أُبطلت (لا صفَّ حيّاً فيها)
        $this->assertSame(0, MobileSession::where('family_id', $family)->whereNull('revoked_at')->count(),
            'إعادةُ الاستعمال تُبطل العائلةَ كاملةً');
        $this->assertGreaterThanOrEqual(2, MobileSession::where('family_id', $family)->count());

        // تنبيهٌ أمنيّ 'sec' للمستخدم (قيمةً مفتاحاً مفتاحاً لا مصفوفةً كاملة)
        $sec = HubNotification::where('user_id', $this->employee->id)->where('kind', 'sec')
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        $this->assertNotNull($sec, 'تنبيهُ إعادةِ الاستخدام موجود');
        $this->assertStringContainsString('إعادة', (string) $sec->text);

        // ورادارُ الأمن سجّل الحادثة
        $this->assertTrue(
            DB::table('access_denials')->where('kind', 'like', '%إعادةُ استخدامِ رمزِ تحديث%')->exists(),
            'SecurityRadar سجّل إعادةَ استخدامِ رمزِ التحديث'
        );

        // وأُدرِج قيدُ تدقيقٍ للحادثة (بلا أيّ رمز)
        $this->assertTrue(AuditEntry::where('action', 'إعادةُ استخدامِ رمزِ تحديث')->exists());
    }

    // ════════════════════════════ خروجٌ / خروجٌ شامل ════════════════════════════

    public function test_logout_revokes_this_session_so_next_call_is_session_revoked(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->employee);
        $h = $this->bearer($data['access_token']);

        $this->withHeaders($h)->postJson('/api/mobile/v1/auth/logout')
            ->assertOk()->assertJsonPath('data.revoked', true);

        $this->withHeaders($h)->getJson('/api/mobile/v1/auth/sessions')
            ->assertStatus(401)->assertJsonPath('code', 'SESSION_REVOKED');
    }

    public function test_logout_all_revokes_every_session_of_the_user(): void
    {
        $this->seedCore();
        $one = $this->mobileLogin($this->employee, 'inst-one-11111111');
        $this->mobileLogin($this->employee, 'inst-two-22222222');

        $this->assertSame(2, MobileSession::where('user_id', $this->employee->id)->whereNull('revoked_at')->count());

        $this->withHeaders($this->bearer($one['access_token']))
            ->postJson('/api/mobile/v1/auth/logout-all')
            ->assertOk()->assertJsonPath('data.revoked', 2);

        $this->assertSame(0, MobileSession::where('user_id', $this->employee->id)->whereNull('revoked_at')->count());
    }

    // ════════════════════════════ إدارةُ الجلسات (بلا IDOR) ════════════════════════════

    public function test_sessions_list_returns_only_own_sessions_in_deterministic_order(): void
    {
        $this->seedCore();
        $emp = $this->mobileLogin($this->employee, 'inst-emp-a-1111111');
        $this->mobileLogin($this->employee, 'inst-emp-b-2222222');
        $owner = $this->mobileLogin($this->owner, 'inst-owner-9999999');

        $res = $this->withHeaders($this->bearer($emp['access_token']))
            ->getJson('/api/mobile/v1/auth/sessions');
        $res->assertOk();
        $ids = collect($res->json('data.sessions'))->pluck('id')->all();

        $this->assertContains($emp['session_id'], $ids);
        $this->assertNotContains($owner['session_id'], $ids, 'لا تُرى جلسةُ مستخدمٍ آخر (لا IDOR)');
        $this->assertCount(2, $ids);

        // الترتيبُ حتميّ: نداءان يعيدان التسلسلَ نفسَه
        $ids2 = collect($this->withHeaders($this->bearer($emp['access_token']))
            ->getJson('/api/mobile/v1/auth/sessions')->json('data.sessions'))->pluck('id')->all();
        $this->assertSame($ids, $ids2, 'ترتيبٌ حتميّ عبر النداءين');
    }

    public function test_delete_own_session_revokes_it(): void
    {
        $this->seedCore();
        $keep = $this->mobileLogin($this->employee, 'inst-keep-1111111');
        $drop = $this->mobileLogin($this->employee, 'inst-drop-2222222');

        $this->withHeaders($this->bearer($keep['access_token']))
            ->deleteJson('/api/mobile/v1/auth/sessions/' . $drop['session_id'])
            ->assertOk()->assertJsonPath('data.revoked', true);

        // الجلسةُ المُلغاة يردّها الوسيطُ في طلبها التالي
        $this->withHeaders($this->bearer($drop['access_token']))
            ->getJson('/api/mobile/v1/auth/sessions')
            ->assertStatus(401)->assertJsonPath('code', 'SESSION_REVOKED');

        // والجلسةُ المُبقاة ما تزال تعمل
        $this->withHeaders($this->bearer($keep['access_token']))
            ->getJson('/api/mobile/v1/auth/sessions')->assertOk();
    }

    public function test_deleting_another_users_session_returns_404_and_does_not_revoke_it(): void
    {
        $this->seedCore();
        $emp = $this->mobileLogin($this->employee, 'inst-emp-x-1111111');
        $owner = $this->mobileLogin($this->owner, 'inst-owner-2222222');

        $this->withHeaders($this->bearer($emp['access_token']))
            ->deleteJson('/api/mobile/v1/auth/sessions/' . $owner['session_id'])
            ->assertStatus(404)->assertJsonPath('code', 'RESOURCE_NOT_FOUND');

        // جلسةُ المالك سليمةٌ حيّة — لم تُمَسّ
        $this->assertNull(MobileSession::where('id', $owner['session_id'])->value('revoked_at'));
    }

    public function test_revoked_session_authed_call_is_session_revoked(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->employee);
        MobileSessionService::revokeSession(
            MobileSession::findOrFail($data['session_id']), 'اختبارُ إبطال'
        );

        $this->withHeaders($this->bearer($data['access_token']))
            ->getJson('/api/mobile/v1/auth/sessions')
            ->assertStatus(401)->assertJsonPath('code', 'SESSION_REVOKED');
    }

    // ════════════════════════════ تصعيدُ المصادقة (F11) ════════════════════════════

    public function test_step_up_wrong_credential_is_rejected(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->employee);

        $res = $this->withHeaders($this->bearer($data['access_token']))
            ->postJson('/api/mobile/v1/auth/step-up',
                ['purpose' => 'reveal_secret', 'credential' => 'wrong-password']);
        $res->assertStatus(428)->assertJsonPath('code', 'STEP_UP_REQUIRED');

        $this->assertSame(0, MobileStepupGrant::count(), 'فشلُ الاعتماد لا يُنشئ مِنحة');
    }

    public function test_step_up_correct_credential_creates_a_session_bound_grant(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->employee);

        $res = $this->withHeaders($this->bearer($data['access_token']))
            ->postJson('/api/mobile/v1/auth/step-up',
                ['purpose' => 'reveal_secret', 'credential' => 'Secret!2026x']);
        $res->assertOk()->assertJsonPath('data.granted', true)->assertJsonPath('data.method', 'password');

        $grant = MobileStepupGrant::first();
        $this->assertNotNull($grant);
        $this->assertSame($data['session_id'], $grant->mobile_session_id, 'المِنحةُ مربوطةٌ بالجلسة');
        $this->assertSame($this->employee->id, $grant->user_id);
        $this->assertSame('reveal_secret', $grant->purpose);
        $this->assertTrue(now()->lt($grant->expires_at), 'لها انتهاءٌ في المستقبل');

        // النقطةُ الواحدةُ التي تستهلكها الأطوارُ اللاحقة تُكرِمها وهي طازجة
        $session = MobileSession::findOrFail($data['session_id']);
        $this->assertTrue(MobileSessionService::mobileStepUpFresh($session, 'reveal_secret'));
    }

    /** المِنحةُ مربوطةٌ بـ(الجلسة+الغرض) لا رايةً عامّةً في الجلسة (F11) */
    public function test_step_up_grant_is_purpose_bound_not_a_global_flag(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->employee);

        $this->withHeaders($this->bearer($data['access_token']))
            ->postJson('/api/mobile/v1/auth/step-up',
                ['purpose' => 'reveal_secret', 'credential' => 'Secret!2026x'])->assertOk();

        $session = MobileSession::findOrFail($data['session_id']);
        $this->assertTrue(MobileSessionService::mobileStepUpFresh($session, 'reveal_secret'));
        $this->assertFalse(MobileSessionService::mobileStepUpFresh($session, 'other_purpose'),
            'غرضٌ آخرُ لا يُكرَم بمِنحةِ غرضٍ مختلف — ليست رايةً عامّة');
    }

    public function test_step_up_grant_expires_after_its_window(): void
    {
        $this->seedCore();
        $this->hubSetting('security.stepup_minutes', '10');
        $data = $this->mobileLogin($this->employee);

        $this->withHeaders($this->bearer($data['access_token']))
            ->postJson('/api/mobile/v1/auth/step-up',
                ['purpose' => 'reveal_secret', 'credential' => 'Secret!2026x'])->assertOk();

        $session = MobileSession::findOrFail($data['session_id']);
        $this->assertTrue(MobileSessionService::mobileStepUpFresh($session, 'reveal_secret'));

        $this->travel(11)->minutes();
        $this->assertFalse(MobileSessionService::mobileStepUpFresh($session, 'reveal_secret'),
            'المِنحةُ تنتهي بعد نافذتها');
    }
}
