<?php

namespace App\Support;

/**
 * محرّكُ مفاتيح المرور (WebAuthn) — **بلا مكتبةٍ خارجية**، آمنٌ بحدوده الصريحة.
 *
 * ما يُتحقَّق منه (وهو ما يصنع الأمان):
 *  1. **التحدّي**: عشوائيٌّ خادميّ، يُخزَّن في الجلسة، أحاديُّ الاستعمال، ويطابق
 *     `clientDataJSON.challenge`.
 *  2. **الأصل (origin)**: يطابق أصلَ التطبيق تماماً — لا تصيّدَ عبر نطاقٍ آخر.
 *  3. **النوع**: `webauthn.create` عند التسجيل، `webauthn.get` عند التحقّق.
 *  4. **rpIdHash**: `SHA256(rpId)` يطابق أوّلَ ٣٢ بايت من authenticatorData.
 *  5. **حضورُ المستخدم (UP)**: رايةُ الحضور مضبوطة.
 *  6. **التوقيع**: يُتحقَّق بـ`openssl_verify` (ES256) على
 *     `authenticatorData || SHA256(clientDataJSON)` بالمفتاح العامّ المخزَّن.
 *  7. **عدّاد التوقيع**: تصاعديٌّ — تراجعُه إشارةُ استنساخٍ فيُرفَض.
 *
 * حدودٌ صريحة (أمانةٌ لا تقصير):
 *  - **`attestation:none`**: لا قرارَ ثقةٍ بصانع المفتاح — المعيارُ لمفاتيح
 *    المنشأة الداخلية. لا CBOR للـattStmt يُحلَّل ولا سلسلةُ شهاداتٍ تُبنى.
 *  - **ES256 فقط** (P-256): خوارزميةٌ واحدةٌ مُعلَنة ومُختبَرة — لا مسارَ COSE
 *    ثانٍ. المفتاحُ الذي لا يدعمها لا يُسجَّل (لا يُقبل ناقصاً).
 *  - **التوقيعُ يُتحقَّق بـopenssl لا بيدٍ**: فالتحليلُ هنا تحليلُ بايتاتٍ لا
 *    تعميةٌ يدوية — وخطؤه يُفشِل التحقّقَ لا يتجاوزه.
 */
class Webauthn
{
    /** الخوارزمية الوحيدة المُعلَنة: ES256 (COSE alg = -7) */
    public const ES256 = -7;

    /* ───────────────── ترميز base64url ───────────────── */

    public static function b64uEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) $s .= str_repeat('=', 4 - $pad);

        return (string) base64_decode($s, true);
    }

    /** تحدٍّ عشوائيٌّ خادميّ (٣٢ بايت) */
    public static function challenge(): string
    {
        return random_bytes(32);
    }

    /* ───────────────── هويّة الطرف المعتمِد (RP) ───────────────── */

    /** rpId = النطاقُ المجرَّد (بلا منفذ) — من app.url أو المضيف الحاليّ */
    public static function rpId(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: request()->getHost();

        return (string) $host;
    }

    /** الأصلُ المتوقَّع تماماً: مخطَّطٌ + مضيفٌ + منفذٌ إن وُجد */
    public static function origin(): string
    {
        $url = (string) config('app.url');
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: request()->getScheme();
        $host = parse_url($url, PHP_URL_HOST) ?: request()->getHost();
        $port = parse_url($url, PHP_URL_PORT);
        $o = $scheme . '://' . $host;
        if ($port && ! in_array((int) $port, [80, 443], true)) $o .= ':' . $port;

        return $o;
    }

    /* ───────────────── محلّلُ CBOR مُصغَّر (تحتاجه WebAuthn فقط) ───────────────── */

    /**
     * يفكّ القيمةَ عند `$off` (تُحدَّث بالمرجع). يدعم الأنواعَ التي تستعملها
     * WebAuthn: uint/negint/bytes/text/array/map. يرفض ما عداها صراحةً.
     */
    public static function cborDecode(string $data, int &$off)
    {
        if ($off >= strlen($data)) throw new \RuntimeException('CBOR: نهايةٌ مبكّرة');
        $ib = ord($data[$off++]);
        $major = $ib >> 5;
        $minor = $ib & 0x1f;
        $val = self::cborArg($data, $off, $minor);

        switch ($major) {
            case 0: return $val;                    // عددٌ موجب
            case 1: return -1 - $val;               // عددٌ سالب
            case 2:                                 // سلسلةُ بايتات
            case 3:                                 // نصّ
                $s = substr($data, $off, $val);
                if (strlen($s) !== $val) throw new \RuntimeException('CBOR: سلسلةٌ ناقصة');
                $off += $val;

                return $s;
            case 4:                                 // مصفوفة
                $out = [];
                for ($i = 0; $i < $val; $i++) $out[] = self::cborDecode($data, $off);

                return $out;
            case 5:                                 // خريطة
                $out = [];
                for ($i = 0; $i < $val; $i++) {
                    $k = self::cborDecode($data, $off);
                    $out[is_int($k) ? $k : (string) $k] = self::cborDecode($data, $off);
                }

                return $out;
            default:
                throw new \RuntimeException('CBOR: نوعٌ غير مدعوم ' . $major);
        }
    }

    /** يقرأ حِملَ الطول/القيمة حسب المُعامل المُصغَّر (0..27) */
    protected static function cborArg(string $data, int &$off, int $minor): int
    {
        if ($minor < 24) return $minor;
        $len = match ($minor) {
            24 => 1, 25 => 2, 26 => 4, 27 => 8,
            default => throw new \RuntimeException('CBOR: طولٌ غير مدعوم'),
        };
        $chunk = substr($data, $off, $len);
        if (strlen($chunk) !== $len) throw new \RuntimeException('CBOR: حِملُ طولٍ ناقص');
        $off += $len;
        $n = 0;
        foreach (str_split($chunk) as $c) $n = ($n << 8) | ord($c);

        return $n;
    }

    /* ───────────────── COSE ES256 → PEM ───────────────── */

    /**
     * يحوّل مفتاحَ COSE (EC2/P-256) إلى PEM — **تفويضٌ كامل** للبدائيّة الواحدة
     * `Es256::coseToPem` (WP-J.1 · درسُ C5): المنطقُ انتقل إلى هناك حرفياً كي
     * تتشاركه مفاتيحُ المرور وتوقيعُ النقاط الطرفية — لا مسارَ تعميةٍ ثانٍ هنا.
     */
    public static function coseEs256ToPem(array $cose): string
    {
        return Es256::coseToPem($cose);
    }

    /* ───────────────── تحليل authenticatorData ───────────────── */

    /** يفصل rpIdHash(32) · flags(1) · signCount(4) · [بيانات المفتاح المُعتمَد] */
    public static function parseAuthData(string $auth): array
    {
        if (strlen($auth) < 37) throw new \RuntimeException('authData قصيرٌ جداً');
        $rpIdHash = substr($auth, 0, 32);
        $flags = ord($auth[32]);
        $signCount = unpack('N', substr($auth, 33, 4))[1];

        $out = [
            'rpIdHash' => $rpIdHash,
            'flags' => $flags,
            'up' => (bool) ($flags & 0x01),      // حضور المستخدم
            'uv' => (bool) ($flags & 0x04),      // تحقّق المستخدم (PIN/بصمة)
            'at' => (bool) ($flags & 0x40),      // بيانات مفتاحٍ مُعتمَد مرفقة
            'signCount' => $signCount,
        ];

        if ($out['at']) {
            // aaguid(16) · credIdLen(2) · credId · COSEKey
            $off = 37 + 16;
            $credLen = unpack('n', substr($auth, 53, 2))[1];
            $off += 2;
            $credId = substr($auth, $off, $credLen);
            $off += $credLen;
            $coseOff = $off;
            $cose = self::cborDecode($auth, $coseOff);
            $out['aaguid'] = substr($auth, 37, 16);
            $out['credentialId'] = $credId;
            $out['cose'] = $cose;
        }

        return $out;
    }

    /* ───────────────── التحقّق من بيانات العميل ───────────────── */

    /** يتحقّق من clientDataJSON: النوع + التحدّي + الأصل (يرمي عند أيّ خلل) */
    protected static function checkClientData(string $json, string $wantType, string $challenge): array
    {
        $c = json_decode($json, true);
        if (! is_array($c)) throw new \RuntimeException('clientData غير صالح');
        if (($c['type'] ?? '') !== $wantType) throw new \RuntimeException('نوعُ العملية غير متطابق');
        if (! hash_equals(self::b64uEncode($challenge), (string) ($c['challenge'] ?? ''))) {
            throw new \RuntimeException('التحدّي غير متطابق');
        }
        if (! hash_equals(self::origin(), (string) ($c['origin'] ?? ''))) {
            throw new \RuntimeException('الأصل غير متطابق');
        }

        return $c;
    }

    /* ───────────────── التسجيل ───────────────── */

    /**
     * يتحقّق من ردّ التسجيل ويُرجع [credentialId(b64u), publicKeyPem, signCount].
     * `attestation:none` — لا نتحقّق من ثقة الصانع (بحكم الحدّ الصريح).
     */
    public static function verifyRegistration(string $clientDataJSON, string $attestationObject, string $challenge): array
    {
        self::checkClientData($clientDataJSON, 'webauthn.create', $challenge);

        $off = 0;
        $att = self::cborDecode($attestationObject, $off);
        if (! is_array($att) || ! isset($att['authData'])) throw new \RuntimeException('attestationObject غير صالح');
        $authData = self::parseAuthData((string) $att['authData']);

        if (! hash_equals(hash('sha256', self::rpId(), true), $authData['rpIdHash'])) {
            throw new \RuntimeException('rpIdHash غير متطابق');
        }
        if (! $authData['up']) throw new \RuntimeException('حضورُ المستخدم غير مؤكَّد');
        if (empty($authData['at']) || empty($authData['cose'])) throw new \RuntimeException('بيانات المفتاح مفقودة');

        $pem = self::coseEs256ToPem($authData['cose']);

        return [
            'credentialId' => self::b64uEncode($authData['credentialId']),
            'publicKey' => $pem,
            'signCount' => $authData['signCount'],
        ];
    }

    /* ───────────────── التحقّق (المصادقة) ───────────────── */

    /**
     * يتحقّق من ردّ المصادقة (assertion). يُرجع عدّادَ التوقيع الجديد عند النجاح،
     * أو يرمي استثناءً بسببٍ صريح. `$prevCount` هو المخزَّن لكشف الاستنساخ.
     */
    public static function verifyAssertion(
        string $clientDataJSON, string $authenticatorData, string $signature,
        string $publicKeyPem, string $challenge, int $prevCount, bool $requireUv = false
    ): int {
        self::checkClientData($clientDataJSON, 'webauthn.get', $challenge);

        $auth = self::parseAuthData($authenticatorData);
        if (! hash_equals(hash('sha256', self::rpId(), true), $auth['rpIdHash'])) {
            throw new \RuntimeException('rpIdHash غير متطابق');
        }
        if (! $auth['up']) throw new \RuntimeException('حضورُ المستخدم غير مؤكَّد');
        if ($requireUv && ! $auth['uv']) throw new \RuntimeException('تحقّقُ المستخدم مطلوبٌ ولم يتمّ');

        // التوقيعُ على authenticatorData الخام + تجزئة clientDataJSON — التحقّقُ
        // بالبدائيّة الواحدة `Es256::verify` (WP-J.1 · C5): المفتاحُ الفاسد يرمي
        // «مفتاحٌ عامٌّ غير صالح» هناك كما كان يرمي هنا — سلوكٌ محفوظٌ حرفياً.
        $signed = $authenticatorData . hash('sha256', $clientDataJSON, true);
        if (! Es256::verify($signed, $signature, $publicKeyPem)) {
            throw new \RuntimeException('التوقيعُ غير صحيح');
        }

        // كشفُ الاستنساخ: العدّادُ تصاعديٌّ إلا أن يكون الطرفان صفراً (مفاتيحُ
        // المنصّة كثيراً ما تُبقيه صفراً — فالصفرُ مقابلَ الصفر مقبول).
        $new = $auth['signCount'];
        if (! ($new === 0 && $prevCount === 0) && $new <= $prevCount) {
            throw new \RuntimeException('عدّادُ التوقيع تراجع — احتمالُ استنساخٍ للمفتاح');
        }

        return $new;
    }
}
