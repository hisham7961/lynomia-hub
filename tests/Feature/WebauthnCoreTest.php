<?php

namespace Tests\Feature;

use App\Support\Webauthn;
use Tests\TestCase;

/**
 * نواةُ مفاتيح المرور (WebAuthn) — تُثبَت بـ«مُصادِقٍ افتراضيّ» حقيقيّ:
 * زوجُ مفاتيح P-256 يُولَّد بـopenssl، ويُبنى authenticatorData وclientDataJSON
 * ويُوقَّع كما يفعل المتصفّح — ثم يُطعَم للمحقّق. فنُثبت أن الصحيحَ يُقبل
 * والمُلاعَبَ (تحدٍّ/أصل/توقيع/عدّاد) يُرفض — بلا متصفّح.
 */
class WebauthnCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost']);
    }

    /* ── مُصادِقٌ افتراضيّ ── */

    /** ترميز CBOR مُصغَّر (يكفي لبناء لقيمات الاختبار) */
    protected function cbor($v): string
    {
        if (is_int($v)) {
            [$major, $n] = $v >= 0 ? [0, $v] : [1, -1 - $v];

            return $this->cborHead($major, $n);
        }
        if (is_string($v)) return $this->cborHead(2, strlen($v)) . $v;      // بايتات
        if (is_array($v) && array_is_list($v)) {
            $o = $this->cborHead(4, count($v));
            foreach ($v as $e) $o .= $this->cbor($e);

            return $o;
        }
        if (is_array($v)) {                                                 // خريطة
            $o = $this->cborHead(5, count($v));
            foreach ($v as $k => $e) {
                $o .= is_int($k) ? $this->cbor($k) : ($this->cborHead(3, strlen($k)) . $k);
                $o .= $this->cbor($e);
            }

            return $o;
        }
        throw new \RuntimeException('cbor: نوعٌ غير مدعوم');
    }

    protected function cborHead(int $major, int $n): string
    {
        $b = $major << 5;
        if ($n < 24) return chr($b | $n);
        if ($n < 256) return chr($b | 24) . chr($n);
        if ($n < 65536) return chr($b | 25) . pack('n', $n);

        return chr($b | 26) . pack('N', $n);
    }

    protected function p32(string $s): string
    {
        return str_pad($s, 32, "\0", STR_PAD_LEFT);
    }

    /** يُولّد زوجَ مفاتيح ويُرجع [privatePem, coseArray, x, y] */
    protected function keypair(): array
    {
        $pk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($pk, $priv);
        $d = openssl_pkey_get_details($pk);
        $x = $this->p32($d['ec']['x']);
        $y = $this->p32($d['ec']['y']);
        $cose = [1 => 2, 3 => -7, -1 => 1, -2 => $x, -3 => $y];

        return [$priv, $cose, $x, $y];
    }

    protected function authData(string $rpId, int $flags, int $signCount, ?string $credId = null, ?array $cose = null): string
    {
        $d = hash('sha256', $rpId, true) . chr($flags) . pack('N', $signCount);
        if ($credId !== null) {
            $d .= str_repeat("\0", 16) . pack('n', strlen($credId)) . $credId . $this->cbor($cose);
        }

        return $d;
    }

    protected function clientData(string $type, string $challenge, string $origin): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => Webauthn::b64uEncode($challenge),
            'origin' => $origin,
        ], JSON_UNESCAPED_SLASHES);
    }

    /* ── الاختبارات ── */

    public function test_registration_then_assertion_roundtrip(): void
    {
        [$priv, $cose] = $this->keypair();
        $rpId = Webauthn::rpId();
        $origin = Webauthn::origin();
        $credId = random_bytes(20);

        // ١) التسجيل: attestationObject(fmt=none) بعلمِ AT+UP
        $challenge = Webauthn::challenge();
        $authData = $this->authData($rpId, 0x41, 0, $credId, $cose);   // UP|AT
        $attObj = $this->cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => $authData]);
        $reg = Webauthn::verifyRegistration($this->clientData('webauthn.create', $challenge, $origin), $attObj, $challenge);

        $this->assertSame(Webauthn::b64uEncode($credId), $reg['credentialId']);
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $reg['publicKey']);
        $pem = $reg['publicKey'];

        // ٢) المصادقة: توقيعٌ حقيقيّ على authData . sha256(clientData)
        $ch2 = Webauthn::challenge();
        $ad2 = $this->authData($rpId, 0x05, 5);       // UP|UV، عدّاد ٥
        $cd2 = $this->clientData('webauthn.get', $ch2, $origin);
        openssl_sign($ad2 . hash('sha256', $cd2, true), $sig, $priv, OPENSSL_ALGO_SHA256);

        $newCount = Webauthn::verifyAssertion($cd2, $ad2, $sig, $pem, $ch2, 0);
        $this->assertSame(5, $newCount);
    }

    public function test_wrong_challenge_is_rejected(): void
    {
        [$priv, $cose] = $this->keypair();
        [$pem, $ch, $ad, $cd, $sig] = $this->signedAssertion($priv, $cose);

        $this->expectExceptionMessageMatches('/التحدّي/');
        Webauthn::verifyAssertion($cd, $ad, $sig, $pem, Webauthn::challenge() /* آخر */, 0);
    }

    public function test_wrong_origin_is_rejected(): void
    {
        [$priv, $cose] = $this->keypair();
        $pem = Webauthn::coseEs256ToPem($cose);
        $rpId = Webauthn::rpId();
        $ch = Webauthn::challenge();
        $ad = $this->authData($rpId, 0x05, 1);
        $cd = $this->clientData('webauthn.get', $ch, 'https://evil.example');   // أصلٌ مزوَّر
        openssl_sign($ad . hash('sha256', $cd, true), $sig, $priv, OPENSSL_ALGO_SHA256);

        $this->expectExceptionMessageMatches('/الأصل/');
        Webauthn::verifyAssertion($cd, $ad, $sig, $pem, $ch, 0);
    }

    public function test_tampered_signature_is_rejected(): void
    {
        [$priv, $cose] = $this->keypair();
        [$pem, $ch, $ad, $cd, $sig] = $this->signedAssertion($priv, $cose);
        $sig[10] = $sig[10] === 'A' ? 'B' : 'A';   // عبثٌ ببايتٍ من التوقيع

        $this->expectExceptionMessageMatches('/التوقيع/');
        Webauthn::verifyAssertion($cd, $ad, $sig, $pem, $ch, 0);
    }

    public function test_signcount_regression_is_rejected_clone_detection(): void
    {
        [$priv, $cose] = $this->keypair();
        $pem = Webauthn::coseEs256ToPem($cose);
        $rpId = Webauthn::rpId();
        $ch = Webauthn::challenge();
        $ad = $this->authData($rpId, 0x05, 3);      // العدّاد الجديد ٣
        $cd = $this->clientData('webauthn.get', $ch, Webauthn::origin());
        openssl_sign($ad . hash('sha256', $cd, true), $sig, $priv, OPENSSL_ALGO_SHA256);

        // المخزَّن ٥ > الجديد ٣ → استنساخٌ محتمل → يُرفض
        $this->expectExceptionMessageMatches('/استنساخ/');
        Webauthn::verifyAssertion($cd, $ad, $sig, $pem, $ch, 5);
    }

    public function test_wrong_type_is_rejected(): void
    {
        [$priv, $cose] = $this->keypair();
        $pem = Webauthn::coseEs256ToPem($cose);
        $rpId = Webauthn::rpId();
        $ch = Webauthn::challenge();
        $ad = $this->authData($rpId, 0x05, 1);
        // نوعٌ خاطئ: create بدل get
        $cd = $this->clientData('webauthn.create', $ch, Webauthn::origin());
        openssl_sign($ad . hash('sha256', $cd, true), $sig, $priv, OPENSSL_ALGO_SHA256);

        $this->expectExceptionMessageMatches('/النوع|نوع/');
        Webauthn::verifyAssertion($cd, $ad, $sig, $pem, $ch, 0);
    }

    /** مساعد: يبني assertionً موقَّعةً صحيحة ويُرجع [pem, challenge, authData, clientData, sig] */
    protected function signedAssertion(string $priv, array $cose): array
    {
        $pem = Webauthn::coseEs256ToPem($cose);
        $rpId = Webauthn::rpId();
        $ch = Webauthn::challenge();
        $ad = $this->authData($rpId, 0x05, 1);
        $cd = $this->clientData('webauthn.get', $ch, Webauthn::origin());
        openssl_sign($ad . hash('sha256', $cd, true), $sig, $priv, OPENSSL_ALGO_SHA256);

        return [$pem, $ch, $ad, $cd, $sig];
    }
}
