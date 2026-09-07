<?php

namespace Tests\Feature\Mobile;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * مساعِداتٌ مشتركةٌ لاختبارات سطح الجوال الأصيل (Mobile Readiness · الطور B).
 *
 * تُبقي كلَّ نداءٍ HTTP على المسارات الحقيقية `/api/mobile/v1/*` بترويسة
 * `Accept: application/json`، ولا تلمس منطقَ التطبيق — الاختباراتُ تفحص العقدَ
 * كما يراه العميلُ الأصيل.
 */
trait InteractsWithMobileAuth
{
    /** حمولةُ دخولٍ صحيحةُ الشكل — بمُعرّفِ تنصيبٍ فريدٍ افتراضاً (36 حرفاً، ضمن 8..36) */
    protected function loginPayload(string $email, string $password = 'Secret!2026x',
                                    ?string $uuid = null, string $platform = 'ios'): array
    {
        return [
            'email'             => $email,
            'password'          => $password,
            'installation_uuid' => $uuid ?? (string) Str::uuid(),
            'platform'          => $platform,
            'device_model'      => 'TestPhone 1',
            'os_version'        => '17.0',
            'app_version'       => '1.0.0',
        ];
    }

    /** POST auth/login — يعيد الردَّ الخام (نجاحاً أو خطأً) */
    protected function mobileLoginRequest(string $email, string $password = 'Secret!2026x',
                                          ?string $uuid = null, string $platform = 'ios'): TestResponse
    {
        return $this->postJson('/api/mobile/v1/auth/login',
            $this->loginPayload($email, $password, $uuid, $platform));
    }

    /**
     * دخولٌ ناجحٌ لمستخدمٍ **بلا MFA** — يؤكّد النجاح ويعيد حمولةَ `data`
     * (access_token/refresh_token/session_id/installation_id/user...).
     *
     * @return array<string,mixed>
     */
    protected function mobileLogin(User $user, ?string $uuid = null): array
    {
        $res = $this->mobileLoginRequest($user->email, 'Secret!2026x', $uuid);
        $res->assertOk();
        $data = $res->json('data');
        $this->assertIsArray($data);
        $this->assertArrayHasKey('access_token', $data);
        $this->assertArrayHasKey('refresh_token', $data);

        return $data;
    }

    /** ترويسةُ حاملِ رمزِ الوصول للطلبات المُصادَقة */
    protected function bearer(string $accessToken): array
    {
        return ['Authorization' => 'Bearer ' . $accessToken];
    }

    /** يفعّل TOTP لمستخدمٍ ويعيد السرَّ الخام (لحساب الرموز في الاختبار) */
    protected function enableTotp(User $user): string
    {
        $secret = Totp::secret();
        $user->forceFill(['totp_enabled' => true, 'totp_secret_cipher' => $secret])->save();

        return $secret;
    }

    /** رمزٌ TOTP خاطئٌ **حتماً** — 6 أرقامٍ خارج نوافذِ ±1 كلِّها (حتميّةٌ لا حظّ) */
    protected function wrongTotpCode(string $secret): string
    {
        $windows = [Totp::code($secret, time() - 30), Totp::code($secret), Totp::code($secret, time() + 30)];
        for ($i = 0; $i < 1000000; $i++) {
            $candidate = str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            if (! in_array($candidate, $windows, true)) return $candidate;
        }

        return '000000';
    }

    /** جسمُ الردِّ منزوعَ `request_id` (وحدَه المتغيّرُ بين طلبين) — لمقارنةِ تطابقِ الردود */
    protected function bodyWithoutRequestId(TestResponse $res): array
    {
        $body = $res->json();
        unset($body['request_id']);

        return ['status' => $res->status()] + $body;
    }
}
