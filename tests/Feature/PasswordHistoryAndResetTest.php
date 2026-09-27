<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\PasswordResetController;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Support\Security\PasswordHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **بندُ الدَّين #15 (AUTH-08/09)**: سجلُّ كلمات المرور السابقة + الاستعادةُ الذاتيّة.
 *
 * كلُّ اختبارٍ هنا كُتب يفشل أولاً على v2.615.0: لا جدولَ `password_histories` ولا
 * مسارَ `password.*` — فتغييرُ الكلمة إلى نفسِها كان يمرّ، والناسي لا بابَ له إلا الإدارة.
 */
class PasswordHistoryAndResetTest extends TestCase
{
    private const PW = 'Secret!2026x';           // كلمةُ seedCore

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        RateLimiter::clear('pwreset-ip:127.0.0.1');
    }

    /* ═══════════════ ١ · سجلُّ كلمات المرور (AUTH-08) ═══════════════ */

    private function changeOwn(User $u, string $current, string $new)
    {
        return $this->actingAs($u)->from('/profile')->put('/profile/password', [
            'current' => $current, 'password' => $new, 'password_confirmation' => $new,
        ]);
    }

    public function test_changing_to_the_current_password_is_rejected(): void
    {
        $this->changeOwn($this->employee, self::PW, self::PW)->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::PW, $this->employee->fresh()->password));
        $this->assertDatabaseMissing('audits', ['action' => 'تغيير كلمة المرور', 'user_id' => $this->employee->id]);
    }

    public function test_a_recent_password_cannot_be_reused_and_an_old_one_can(): void
    {
        $this->hubSetting('auth.pw_history', '3');

        $this->changeOwn($this->employee, self::PW, 'SecondPass!2026')->assertSessionHasNoErrors();
        $this->changeOwn($this->employee->fresh(), 'SecondPass!2026', 'ThirdPass!2026')->assertSessionHasNoErrors();

        // الأولى ضمن آخر ثلاث (الحاليّةُ منها) ⇒ مرفوضة
        $this->changeOwn($this->employee->fresh(), 'ThirdPass!2026', self::PW)->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('ThirdPass!2026', $this->employee->fresh()->password));

        // بعد رابعةٍ خرجت الأولى من النافذة ⇒ مقبولة
        $this->changeOwn($this->employee->fresh(), 'ThirdPass!2026', 'FourthPass!2026')->assertSessionHasNoErrors();
        $this->changeOwn($this->employee->fresh(), 'FourthPass!2026', self::PW)->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check(self::PW, $this->employee->fresh()->password));
    }

    public function test_history_stores_hashes_only_and_is_pruned_to_the_depth(): void
    {
        $this->hubSetting('auth.pw_history', '2');
        $u = $this->employee;
        foreach (['PassOne!2026a', 'PassTwo!2026b', 'PassThree!2026c', 'PassFour!2026d'] as $pw) {
            $u->forceFill(['password' => $pw])->save();
        }

        $rows = DB::table(PasswordHistory::TABLE)->where('user_id', $u->id)->orderBy('id')->get();
        $this->assertCount(2, $rows, 'السجلُّ يُقصّ إلى العمق لكلِّ مستخدم');
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('Pass', (string) $row->password_hash, 'لا نصَّ صريحاً في السجلّ');
        }
        // الصفّان الباقيان هما الأحدثان — بالترتيب التزايديّ للمعرّف
        $this->assertTrue(Hash::check('PassThree!2026c', (string) $rows[0]->password_hash));
        $this->assertTrue(Hash::check('PassFour!2026d', (string) $rows[1]->password_hash));
        // ومستخدمٌ آخر لم يُمَسّ قصُّه
        $this->assertSame(1, DB::table(PasswordHistory::TABLE)->where('user_id', $this->owner->id)->count());
    }

    public function test_zero_disables_the_check(): void
    {
        $this->hubSetting('auth.pw_history', '0');

        $this->changeOwn($this->employee, self::PW, self::PW)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audits', ['action' => 'تغيير كلمة المرور', 'user_id' => $this->employee->id]);
    }

    public function test_an_admin_reset_is_recorded_but_not_rejected(): void
    {
        $e = $this->employee;
        $before = DB::table(PasswordHistory::TABLE)->where('user_id', $e->id)->count();

        $this->actingAs($this->owner)->put('/admin/users/' . $e->id, [
            'name' => $e->name, 'email' => $e->email, 'role_id' => $e->role_id, 'status' => 'نشط',
            'password' => 'AdminSet!2026z',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('AdminSet!2026z', $e->fresh()->password));
        $this->assertSame($before + 1, DB::table(PasswordHistory::TABLE)->where('user_id', $e->id)->count());

        // والكلمةُ التي وضعتها الإدارةُ لا يعيدها صاحبُها كلمةً «جديدة»
        $this->changeOwn($e->fresh(), 'AdminSet!2026z', 'AdminSet!2026z')->assertSessionHasErrors('password');
    }

    /* ═══════════════ ٢ · الاستعادةُ الذاتيّة (AUTH-09) ═══════════════ */

    /** الرمزُ الخامُّ من رابط رسالة الصادر — ما يصل صاحبَ البريد وحدَه */
    private function tokenFromOutbox(string $email): string
    {
        $text = (string) OutboxMessage::where('kind', 'password_reset')->where('target', $email)
            ->orderByDesc('id')->value('text');
        $this->assertMatchesRegularExpression('#/password/reset/([A-Za-z0-9]+)#', $text);
        preg_match('#/password/reset/([A-Za-z0-9]+)#', $text, $m);

        return $m[1];
    }

    public function test_login_page_links_to_the_reset_and_the_switch_hides_it(): void
    {
        $this->get('/login')->assertOk()->assertSee('نسيت كلمة المرور؟')->assertSee(route('password.request'), false);
        $this->get('/password/forgot')->assertOk()->assertSee('أرسل رابط الاستعادة');

        $this->hubSetting('auth.pw_reset_on', '0');
        $this->get('/login')->assertOk()->assertDontSee('نسيت كلمة المرور؟');
        $this->get('/password/forgot')->assertNotFound();
        $this->post('/password/forgot', ['email' => 'emp@test.local'])->assertNotFound();
        $this->get('/password/reset/abc')->assertNotFound();
        $this->post('/password/reset', ['token' => 'x', 'email' => 'emp@test.local',
            'password' => 'Brand!New2026', 'password_confirmation' => 'Brand!New2026'])->assertNotFound();
        $this->assertSame(0, OutboxMessage::where('kind', 'password_reset')->count());
    }

    public function test_request_queues_a_mail_with_a_hashed_expiring_token(): void
    {
        Notification::fake();

        $this->from('/password/forgot')->post('/password/forgot', ['email' => 'emp@test.local'])
            ->assertRedirect('/password/forgot')->assertSessionHas('ok', PasswordResetController::SENT);

        $msg = OutboxMessage::where('kind', 'password_reset')->orderBy('id')->first();
        $this->assertNotNull($msg);
        $this->assertSame('mail', $msg->channel);
        $this->assertSame('emp@test.local', $msg->target);
        $this->assertSame('queued', $msg->state);

        // الرمزُ في القاعدة مُجزَّأٌ لا خام
        $token = $this->tokenFromOutbox('emp@test.local');
        $stored = (string) DB::table('password_reset_tokens')->where('email', 'emp@test.local')->value('token');
        $this->assertNotSame($token, $stored);
        $this->assertTrue(Hash::check($token, $stored));

        // التسليمُ عبر الصادر لا إشعارِ الإطار، والطلبُ مُدوَّن
        Notification::assertNothingSent();
        $this->assertDatabaseHas('audits', ['action' => PasswordResetController::AUDIT_REQUEST, 'user_id' => $this->employee->id]);
    }

    public function test_the_queued_link_leaves_through_the_outbound_mailer(): void
    {
        Mail::fake();
        config(['mail.default' => 'array']);

        $this->post('/password/forgot', ['email' => 'emp@test.local']);
        $this->artisan('hub:outbox')->assertSuccessful();

        $this->assertSame('sent', OutboxMessage::where('kind', 'password_reset')->value('state'));
    }

    public function test_the_response_does_not_reveal_whether_the_account_exists(): void
    {
        $this->employee->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $responses = [];
        foreach (['emp@test.local', 'nobody@test.local', 'view@test.local'] as $email) {
            $r = $this->from('/password/forgot')->post('/password/forgot', ['email' => $email]);
            $r->assertRedirect('/password/forgot')->assertSessionHas('ok', PasswordResetController::SENT)
                ->assertSessionHasNoErrors();
            $responses[] = $r->getStatusCode();
        }

        $this->assertSame([302, 302, 302], $responses);
        // الموقوفُ والغائبُ لا يُرسَل لهما شيء، والنشطُ وحدَه يُرسَل له
        $this->assertSame(['view@test.local'],
            OutboxMessage::where('kind', 'password_reset')->orderBy('id')->pluck('target')->all());
    }

    public function test_an_unactivated_client_cannot_use_reset_to_skip_activation(): void
    {
        $this->employee->forceFill(['account_type' => 'client', 'password_changed_at' => null])->save();

        $this->post('/password/forgot', ['email' => 'emp@test.local'])->assertSessionHas('ok', PasswordResetController::SENT);
        $this->assertSame(0, OutboxMessage::where('kind', 'password_reset')->count());
    }

    public function test_reset_end_to_end_revokes_sessions_audits_and_does_not_log_in(): void
    {
        $e = $this->employee;
        $e->forceFill(['failed_attempts' => 3, 'remember_token' => 'old-remember-token'])->save();
        DB::table('sessions_log')->insert(['id' => (string) Str::uuid(), 'user_id' => $e->id,
            'started_at' => now(), 'last_seen_at' => now(), 'revoked' => false]);

        $this->post('/password/forgot', ['email' => 'emp@test.local']);
        $token = $this->tokenFromOutbox('emp@test.local');

        $this->get('/password/reset/' . $token . '?email=emp@test.local')->assertOk()
            ->assertSee('ضع كلمة مرورٍ جديدة');

        $this->post('/password/reset', ['token' => $token, 'email' => 'emp@test.local',
            'password' => 'Brand!New2026', 'password_confirmation' => 'Brand!New2026'])
            ->assertRedirect(route('login'))->assertSessionHasNoErrors();

        $this->assertGuest();
        $fresh = $e->fresh();
        $this->assertTrue(Hash::check('Brand!New2026', $fresh->password));
        $this->assertNotNull($fresh->password_changed_at);
        $this->assertSame(0, (int) $fresh->failed_attempts);
        $this->assertNotSame('old-remember-token', $fresh->remember_token, '«تذكّرني» يُدوَّر');
        $this->assertSame(0, DB::table('sessions_log')->where('user_id', $e->id)->where('revoked', false)->count(),
            'كلُّ جلسةٍ قائمةٍ تُنهى');
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'emp@test.local')->count(), 'الرمزُ يُستهلك');

        $audit = DB::table('audits')->where('action', 'إعادة تعيين كلمة مرور')->where('user_id', $e->id)->orderByDesc('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('self_service', json_decode((string) $audit->after, true)['via'] ?? null);

        // الرمزُ لا يُستعمل مرّتين
        $this->post('/password/reset', ['token' => $token, 'email' => 'emp@test.local',
            'password' => 'Other!New2026', 'password_confirmation' => 'Other!New2026'])
            ->assertSessionHasErrors(['email' => PasswordResetController::INVALID]);
        $this->assertTrue(Hash::check('Brand!New2026', $e->fresh()->password));

        // والدخولُ بالكلمة الجديدة يعمل
        $this->post('/login', ['email' => 'emp@test.local', 'password' => 'Brand!New2026'])->assertRedirect();
        $this->assertAuthenticatedAs($e->fresh());
    }

    public function test_two_factor_is_still_required_after_a_reset(): void
    {
        $this->employee->forceFill(['totp_enabled' => true, 'totp_secret_cipher' => 'JBSWY3DPEHPK3PXP'])->save();

        $this->post('/password/forgot', ['email' => 'emp@test.local']);
        $token = $this->tokenFromOutbox('emp@test.local');
        $this->post('/password/reset', ['token' => $token, 'email' => 'emp@test.local',
            'password' => 'Brand!New2026', 'password_confirmation' => 'Brand!New2026'])->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post('/login', ['email' => 'emp@test.local', 'password' => 'Brand!New2026'])
            ->assertRedirect(route('login.otp'));
        $this->assertGuest();
    }

    public function test_invalid_foreign_and_expired_tokens_are_rejected_with_one_message(): void
    {
        $this->post('/password/forgot', ['email' => 'emp@test.local']);
        $token = $this->tokenFromOutbox('emp@test.local');
        $body = fn (string $t, string $email) => ['token' => $t, 'email' => $email,
            'password' => 'Brand!New2026', 'password_confirmation' => 'Brand!New2026'];

        // رمزٌ مختلَق
        $this->post('/password/reset', $body(Str::random(64), 'emp@test.local'))
            ->assertSessionHasErrors(['email' => PasswordResetController::INVALID]);
        // رمزٌ صحيحٌ لبريدٍ آخر، ولبريدٍ غائب — الرسالةُ نفسُها
        $this->post('/password/reset', $body($token, 'view@test.local'))
            ->assertSessionHasErrors(['email' => PasswordResetController::INVALID]);
        $this->post('/password/reset', $body($token, 'nobody@test.local'))
            ->assertSessionHasErrors(['email' => PasswordResetController::INVALID]);

        // رمزٌ منتهٍ (بعد المهلة)
        $this->travel((int) config('auth.passwords.users.expire', 60) + 1)->minutes();
        $this->post('/password/reset', $body($token, 'emp@test.local'))
            ->assertSessionHasErrors(['email' => PasswordResetController::INVALID]);

        foreach (['emp@test.local', 'view@test.local'] as $email) {
            $this->assertTrue(Hash::check(self::PW, User::where('email', $email)->value('password')));
        }
        $this->assertDatabaseMissing('audits', ['action' => 'إعادة تعيين كلمة مرور']);
    }

    public function test_reset_respects_password_rules_and_history(): void
    {
        $this->post('/password/forgot', ['email' => 'emp@test.local']);
        $token = $this->tokenFromOutbox('emp@test.local');

        // الكلمةُ الحاليّةُ نفسُها مرفوضة، والرمزُ يبقى صالحاً لمحاولةٍ صحيحة
        $this->post('/password/reset', ['token' => $token, 'email' => 'emp@test.local',
            'password' => self::PW, 'password_confirmation' => self::PW])
            ->assertSessionHasErrors(['password' => PasswordHistory::message()]);
        // وضعيفةٌ مرفوضةٌ بقاعدة الكلمات
        $this->post('/password/reset', ['token' => $token, 'email' => 'emp@test.local',
            'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::PW, $this->employee->fresh()->password));
        $this->post('/password/reset', ['token' => $token, 'email' => 'emp@test.local',
            'password' => 'Brand!New2026', 'password_confirmation' => 'Brand!New2026'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Brand!New2026', $this->employee->fresh()->password));
    }

    public function test_requests_are_throttled_per_email_and_per_address(): void
    {
        // على البريد: خمسٌ في الساعة — والبريدُ الغائبُ يُخنق كالموجود (لا أوراكل)
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.' . ($i + 1)])
                ->post('/password/forgot', ['email' => 'nobody@test.local'])->assertRedirect();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->post('/password/forgot', ['email' => 'nobody@test.local'])->assertStatus(429);

        // على العنوان: خمسٌ في الدقيقة بأيِّ بريد
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
                ->post('/password/forgot', ['email' => "scan{$i}@test.local"])->assertRedirect();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
            ->post('/password/forgot', ['email' => 'scan9@test.local'])->assertStatus(429);
    }

    public function test_the_broker_sends_one_link_per_minute_per_account(): void
    {
        $this->post('/password/forgot', ['email' => 'emp@test.local']);
        $this->post('/password/forgot', ['email' => 'emp@test.local'])
            ->assertSessionHas('ok', PasswordResetController::SENT);

        $this->assertSame(1, OutboxMessage::where('kind', 'password_reset')->count());
    }

    public function test_a_live_reset_link_is_never_previewed_on_an_ops_screen(): void
    {
        $this->post('/password/forgot', ['email' => 'emp@test.local']);
        $token = $this->tokenFromOutbox('emp@test.local');
        $msg = OutboxMessage::where('kind', 'password_reset')->orderBy('id')->first();

        $preview = \App\Support\Ops\Integrations::outboxPreview($msg->kind, $msg->text, 500);
        $this->assertStringNotContainsString($token, $preview);
        $this->assertStringNotContainsString('/password/reset/', $preview);
    }
}
