<?php

namespace App\Support\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * **اعتمادُ FCM بحساب الخدمة — رمزُ OAuth يُسكّ خادميّاً لا يُلصَق باليد.**
 *
 * رمزُ الوصول الثابت (`mobile.push_fcm_access_token`) يعيش ساعةً ثم يسقط الدفعُ كلُّه صامتاً.
 * هنا يُحفظ **ملفُّ حساب الخدمة** (سرٌّ مشفَّرٌ للكتابة فقط: `mobile.push_fcm_service_account`)
 * ويُسكّ منه رمزٌ عند الحاجة:
 *   ١) JWT بتوقيع RS256 (`openssl_sign`) — `iss` بريدُ الحساب، `scope` نطاقُ FCM وحدَه،
 *      `aud` نقطةُ الرموز، مدّةٌ ساعة.
 *   ٢) مبادلتُه في `oauth2.googleapis.com/token` (منحةُ jwt-bearer) عبر **حارس الصادر**
 *      (`hub_outbound_ok`) — والنقطةُ ثابتةٌ هنا لا تُقرأ من الملفّ (لا SSRF بملفٍّ مصنوع).
 *   ٣) خبيئةُ الرمز **مشفَّراً** حتى ما قبل انتهائه بخمس دقائق.
 *
 * لا يُسجَّل شيءٌ من الملفّ أو الرمز أو ردِّ المزوّد: الفشلُ يعيد null فيقول المزوّدُ
 * الحقيقةَ (`failed` بصنف `auth_failed`) لا نجاحاً مُزيَّفاً.
 */
final class FcmServiceAccount
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** هامشُ الأمان قبل انتهاء الرمز (ثوانٍ) */
    public const EARLY = 300;

    /**
     * يُحلّل ملفَّ حساب الخدمة ويتحقّق من حقوله الثلاثة ومن صلاحية المفتاح الخاصّ —
     * يعيد `['client_email','private_key','project_id']` أو null.
     */
    public static function parse(?string $json): ?array
    {
        $d = json_decode(trim((string) $json), true);
        if (! is_array($d)) return null;

        $email = trim((string) ($d['client_email'] ?? ''));
        $key = (string) ($d['private_key'] ?? '');
        $project = trim((string) ($d['project_id'] ?? ''));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $project === '' || ! str_contains($key, 'PRIVATE KEY')) return null;
        if (@openssl_pkey_get_private($key) === false) return null;

        return ['client_email' => $email, 'private_key' => $key, 'project_id' => $project,
            'private_key_id' => (string) ($d['private_key_id'] ?? '')];
    }

    /** رسالةُ رفضٍ للنموذج أو null — دون ذكرِ أيِّ قيمةٍ من الملفّ */
    public static function validationError(string $json): ?string
    {
        if (mb_strlen($json) > 20000) return 'الملفُّ أكبرُ من المعقول لحساب خدمة';
        $d = json_decode($json, true);
        if (! is_array($d)) return 'ليس JSON صالحاً (الصق محتوى ملفّ حساب الخدمة كاملاً)';
        foreach (['client_email', 'private_key', 'project_id'] as $f) {
            if (trim((string) ($d[$f] ?? '')) === '') return 'الحقلُ «' . $f . '» غائب';
        }

        return self::parse($json) === null ? 'المفتاحُ الخاصّ أو البريدُ غيرُ صالح' : null;
    }

    /** JWT موقَّعٌ RS256 لمنحة jwt-bearer — أو null إن تعذّر التوقيع */
    public static function assertion(array $sa, ?int $now = null): ?string
    {
        $now = $now ?? time();
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $header = ['alg' => 'RS256', 'typ' => 'JWT'] + (($sa['private_key_id'] ?? '') !== '' ? ['kid' => $sa['private_key_id']] : []);
        $claims = ['iss' => $sa['client_email'], 'scope' => self::SCOPE, 'aud' => self::TOKEN_URL,
            'iat' => $now, 'exp' => $now + 3600];
        $input = $b64((string) json_encode($header)) . '.' . $b64((string) json_encode($claims));

        $key = @openssl_pkey_get_private((string) $sa['private_key']);
        if ($key === false || ! openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256)) return null;

        return $input . '.' . $b64($sig);
    }

    /**
     * رمزُ وصولٍ صالح — من الخبيئة (مشفَّراً) أو بسكٍّ جديد. null عند أيِّ فشل (بلا تسجيلِ سرّ).
     */
    public static function accessToken(array $sa): ?string
    {
        $key = 'push:fcm:oauth:' . sha1($sa['client_email'] . '|' . ($sa['private_key_id'] ?? '') . '|' . sha1((string) $sa['private_key']));

        try {
            $hit = Cache::get($key);
            if (is_string($hit) && $hit !== '') return Crypt::decryptString($hit);
        } catch (\Throwable $e) {
            // خبيئةٌ فاسدة أو مفتاحُ تطبيقٍ تغيّر — يُسكّ رمزٌ جديد
        }

        if (! (hub_outbound_ok(self::TOKEN_URL)['ok'] ?? false)) return null;
        $jwt = self::assertion($sa);
        if ($jwt === null) return null;

        try {
            $res = Http::asForm()->timeout(8)->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
            $token = (string) $res->json('access_token', '');
            if (! $res->successful() || $token === '') return null;

            $ttl = max(60, (int) $res->json('expires_in', 3600) - self::EARLY);
            Cache::put($key, Crypt::encryptString($token), $ttl);

            return $token;
        } catch (\Throwable $e) {
            return null;   // شبكة/مهلة — لا نصَّ استثناءٍ قد يحمل الطلب
        }
    }
}
