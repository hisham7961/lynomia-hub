<?php

namespace App\Support;

/**
 * **بدائيّةُ ECDSA P-256 / SHA-256 الواحدة** — Work OS · الطور J · WP-J.1 · §43 (درسُ النقد C5).
 *
 * مُستخرَجةٌ من `Webauthn` (COSE→PEM + تحقّقُ openssl) لتكون **مسارَ التعمية الوحيد**
 * المشترَك بين مفاتيح المرور (WebAuthn) وتوقيع أجهزة النقاط الطرفية — لا مسارَ
 * تعميةٍ ثالثَ في النظام. `Webauthn::verifyRegistration/verifyAssertion` تبقيان
 * لطقوس WebAuthn وحدَها (challenge/origin/rpIdHash/signCount) وتفوّضان إلى هنا؛
 * وهما **غيرُ قابلتين لإعادة الاستعمال** خارج طقسِهما — هذا الصنفُ هو القابل.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * **عقدُ توقيع النقاط الطرفية (THE SIGNING CONTRACT)** — يُكتب هنا **مرةً واحدة**
 * ويُشار إليه من كل موضع؛ وكيلُ Go في الطور K يعكسه حرفياً (mirror):
 *
 *  ١) الجهازُ يوقّع السلسلةَ UTF-8 التالية (خمسةُ أسطرٍ يفصلها "\n" حرفياً):
 *
 *         METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + sha256hex(BODY)
 *
 *     •  METHOD    فعلُ HTTP بحروفٍ كبيرة (POST، GET…).
 *     •  PATH      مسارُ الطلب المطلق مبدوءاً بـ«/» **بلا** سلسلة الاستعلام
 *                  (مثل ‎/api/v1/endpoint/heartbeat).
 *     •  TIMESTAMP ثوانى يونكس عدداً صحيحاً نصّياً (unix seconds).
 *     •  NONCE     مُعرّفٌ عشوائيّ فريدٌ لكل طلبٍ **لكل جهاز** (8–64 من [A-Za-z0-9._-]).
 *     •  sha256hex(BODY) تجزئةُ جسم الطلب الخام (hex أحرفٌ صغيرة) — والجسمُ
 *                  الفارغ تجزئتُه تجزئةُ السلسلة الفارغة.
 *
 *  ٢) الخوارزمية: **ECDSA على منحنى P-256 (prime256v1) مع SHA-256** — لا سواها.
 *
 *  ٣) التوقيعُ يسافر **base64(DER)** في الترويسة `X-Endpoint-Signature` ومعه:
 *     •  `X-Endpoint-Id`        — معرّفُ الجهاز الصادرُ عن الخادم لحظةَ التسجيل
 *                                 (`endpoint_devices.id`).
 *     •  `X-Endpoint-Timestamp` — TIMESTAMP نفسُه؛ نافذةُ القبول **±300 ثانية**.
 *     •  `X-Endpoint-Nonce`     — NONCE نفسُه؛ يُدَّعى مرةً واحدةً لكل جهاز
 *                                 (UNIQUE(device_id,nonce) — الإعادةُ 409).
 *
 *  ٤) الخادمُ يتحقّق بالمفتاح **العامّ** PEM المخزَّن لحظةَ التسجيل عبر
 *     `Es256::verify` — الخاصُّ يُولَّد على الجهاز ولا يُرسَل ولا يُخزَّن قط.
 *
 *  ٥) فرضُ العقد في `App\Http\Middleware\EndpointSignature` **قبل** أيّ منطقِ
 *     معالج: طابعٌ خارج النافذة أو توقيعٌ لا يصحّ = 401؛ nonce معاد = 409.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * حدودٌ صريحة (أمانةٌ لا تقصير):
 *  - ES256/P-256 **فقط** — مفتاحٌ على منحنى آخر أو خوارزميةٍ أخرى يُرفض.
 *  - التحقّقُ بـ`openssl_verify` لا بتحليلٍ يدويّ — وخطأُ التحليل يُفشِل لا يتجاوز.
 */
class Es256
{
    /** مُعرِّف الخوارزمية في COSE (alg = -7 أي ES256) */
    public const COSE_ALG = -7;

    /**
     * السلسلةُ القانونية الموقَّعة — نصُّ العقد أعلاه حرفياً (البند ١).
     * تُبنى هنا **مرةً واحدة** فلا تفترق نسخةُ الوسيط عن نسخةِ وكيل الطور K.
     */
    public static function canonical(string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
    }

    /**
     * تحقّقُ ES256: هل التوقيعُ (DER خام) صحيحٌ على `$data` بالمفتاح العامّ PEM؟
     * مفتاحٌ لا يفكّه openssl يرمي صراحةً (سلوكُ Webauthn المحفوظ حرفياً) —
     * فمفتاحٌ فاسدٌ مخزَّنٌ عطلٌ يُبلَّغ لا «توقيعٌ خاطئ» صامت.
     */
    public static function verify(string $data, string $signature, string $publicKeyPem): bool
    {
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) throw new \RuntimeException('مفتاحٌ عامٌّ غير صالح');

        return openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * يحوّل مفتاحَ COSE (EC2/P-256) إلى PEM بتجميع DER SubjectPublicKeyInfo
     * قياسيّ. البادئةُ ثابتةٌ لـP-256 (منحنى prime256v1)، ثم `04 || x || y`.
     * (منقولةٌ حرفياً من `Webauthn::coseEs256ToPem` — وهي تفوّض إلى هنا الآن.)
     */
    public static function coseToPem(array $cose): string
    {
        // 1=kty(2 EC2) · 3=alg(-7) · -1=crv(1 P-256) · -2=x · -3=y
        if (($cose[1] ?? null) !== 2 || ($cose[3] ?? null) !== self::COSE_ALG || ($cose[-1] ?? null) !== 1) {
            throw new \RuntimeException('مفتاحٌ غير مدعوم — ES256/P-256 فقط');
        }
        $x = (string) ($cose[-2] ?? '');
        $y = (string) ($cose[-3] ?? '');
        if (strlen($x) !== 32 || strlen($y) !== 32) throw new \RuntimeException('إحداثيّاتٌ غير صالحة');

        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";

        return $pem;
    }

    /**
     * هل هذا PEM مفتاحٌ **عامٌّ** على منحنى P-256 تحديداً؟ — بوّابةُ قبولِ مفتاحِ
     * التسجيل: نصٌّ مهمل، أو RSA، أو منحنى آخر (P-384…)، أو مفتاحٌ خاصّ — كلُّها false.
     */
    public static function isP256PublicKey(string $pem): bool
    {
        // مادةُ مفتاحٍ خاصّ لا تُقبل أبداً — ولو قَبِلها openssl كمفتاحٍ قابلٍ للاشتقاق
        if (stripos($pem, 'PRIVATE') !== false) return false;

        $key = @openssl_pkey_get_public($pem);
        if ($key === false) return false;
        $d = openssl_pkey_get_details($key);

        return is_array($d)
            && ($d['type'] ?? null) === OPENSSL_KEYTYPE_EC
            && (($d['ec']['curve_name'] ?? '') === 'prime256v1');
    }

    /**
     * بصمةُ المفتاح العامّ: sha256 hex على بايتات DER (لا على نصّ PEM) — فتثبت
     * البصمةُ مهما اختلف التغليف (فواصلُ أسطر، ترتيبُ chunk) بين مُرسِلَين.
     */
    public static function fingerprint(string $publicKeyPem): string
    {
        $body = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $publicKeyPem);
        $der = base64_decode((string) $body, true);
        if ($der === false || $der === '') throw new \RuntimeException('تعذّر استخراج DER من المفتاح العامّ');

        return hash('sha256', $der);
    }
}
