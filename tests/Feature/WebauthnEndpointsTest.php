<?php

namespace Tests\Feature;

use App\Models\WebauthnCredential;
use App\Support\StepUp;
use App\Support\Webauthn;
use Tests\TestCase;

/**
 * مفاتيحُ المرور عبر المسارات الحقيقية — تسجيلٌ ثم دخولٌ بلا كلمة سر وتصعيد،
 * بمُصادِقٍ افتراضيّ يوقّع كما المتصفّح. يُثبت السلسلةَ كاملة: خيارات ← توقيع ← تحقّق.
 */
class WebauthnEndpointsTest extends TestCase
{
    protected string $priv = '';
    protected array $cose = [];
    protected string $credId = '';
    protected int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost']);
        $pk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($pk, $this->priv);
        $d = openssl_pkey_get_details($pk);
        $x = str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $this->cose = [1 => 2, 3 => -7, -1 => 1, -2 => $x, -3 => $y];
        $this->credId = random_bytes(20);
    }

    /* ── مُصادِقٌ افتراضيّ مُصغَّر ── */
    protected function cbor($v): string
    {
        if (is_int($v)) { [$m, $n] = $v >= 0 ? [0, $v] : [1, -1 - $v]; return $this->hd($m, $n); }
        if (is_string($v)) return $this->hd(2, strlen($v)) . $v;
        if (is_array($v) && array_is_list($v)) { $o = $this->hd(4, count($v)); foreach ($v as $e) $o .= $this->cbor($e); return $o; }
        $o = $this->hd(5, count($v));
        foreach ($v as $k => $e) { $o .= is_int($k) ? $this->cbor($k) : ($this->hd(3, strlen($k)) . $k); $o .= $this->cbor($e); }
        return $o;
    }
    protected function hd(int $m, int $n): string
    {
        $b = $m << 5;
        if ($n < 24) return chr($b | $n);
        if ($n < 256) return chr($b | 24) . chr($n);
        return chr($b | 25) . pack('n', $n);
    }
    protected function authData(int $flags, int $count, bool $withCred = false): string
    {
        $d = hash('sha256', Webauthn::rpId(), true) . chr($flags) . pack('N', $count);
        if ($withCred) $d .= str_repeat("\0", 16) . pack('n', strlen($this->credId)) . $this->credId . $this->cbor($this->cose);
        return $d;
    }
    protected function clientData(string $type, string $challengeB64u): string
    {
        return json_encode(['type' => $type, 'challenge' => $challengeB64u, 'origin' => Webauthn::origin()], JSON_UNESCAPED_SLASHES);
    }

    /* ── التسجيل عبر المسار ── */
    protected function registerFor($user): void
    {
        $opts = $this->actingAs($user)->postJson('/passkey/register/options')->assertOk()->json();
        $cd = $this->clientData('webauthn.create', $opts['challenge']);
        $att = $this->cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => $this->authData(0x41, 0, true)]);
        $this->actingAs($user)->postJson('/passkey/register/verify', [
            'clientDataJSON' => Webauthn::b64uEncode($cd),
            'attestationObject' => Webauthn::b64uEncode($att),
            'label' => 'مفتاحُ الاختبار',
        ])->assertOk()->assertJson(['ok' => true]);
    }

    /** يبني assertionً موقَّعةً لتحدٍّ معطى */
    protected function assertion(string $challengeB64u, int $count): array
    {
        $ad = $this->authData(0x05, $count);   // UP|UV
        $cd = $this->clientData('webauthn.get', $challengeB64u);
        openssl_sign($ad . hash('sha256', $cd, true), $sig, $this->priv, OPENSSL_ALGO_SHA256);

        return [
            'id' => Webauthn::b64uEncode($this->credId),
            'clientDataJSON' => Webauthn::b64uEncode($cd),
            'authenticatorData' => Webauthn::b64uEncode($ad),
            'signature' => Webauthn::b64uEncode($sig),
        ];
    }

    public function test_register_then_passwordless_login(): void
    {
        $this->seedCore();
        $this->registerFor($this->owner);
        $this->assertDatabaseHas('webauthn_credentials', [
            'user_id' => $this->owner->id, 'credential_id' => Webauthn::b64uEncode($this->credId),
        ]);

        // دخولٌ بلا كلمة سر: زائرٌ يطلب الخيارات ثم يوقّع
        auth()->logout();
        $opts = $this->postJson('/passkey/login/options')->assertOk()->json();
        $res = $this->postJson('/passkey/login/verify', $this->assertion($opts['challenge'], 3))
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertAuthenticatedAs($this->owner->fresh());
        $this->assertDatabaseHas('audits', ['action' => 'دخول ناجح']);
    }

    public function test_passkey_satisfies_stepup(): void
    {
        $this->seedCore();
        $this->registerFor($this->owner);

        $opts = $this->actingAs($this->owner)->postJson('/passkey/stepup/options')->assertOk()->json();
        $this->actingAs($this->owner)->postJson('/passkey/stepup/verify', $this->assertion($opts['challenge'], 7))
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertTrue(StepUp::fresh(), 'مفتاحُ المرور صعّد المصادقة');
    }

    public function test_tampered_login_assertion_is_rejected(): void
    {
        $this->seedCore();
        $this->registerFor($this->owner);
        auth()->logout();

        $opts = $this->postJson('/passkey/login/options')->assertOk()->json();
        $a = $this->assertion($opts['challenge'], 3);
        $a['signature'] = Webauthn::b64uEncode(random_bytes(70));   // توقيعٌ مزيَّف
        $this->postJson('/passkey/login/verify', $a)->assertStatus(422);
        $this->assertGuest();
    }

    public function test_user_can_delete_own_passkey(): void
    {
        $this->seedCore();
        $this->registerFor($this->owner);
        $cred = WebauthnCredential::where('user_id', $this->owner->id)->firstOrFail();
        $this->actingAs($this->owner)->delete('/passkey/' . $cred->id)->assertRedirect();
        $this->assertSoftDeleted('webauthn_credentials', ['id' => $cred->id]);
    }

    public function test_feature_off_returns_404(): void
    {
        $this->seedCore();
        $this->hubSetting('auth.passkeys_on', '0');
        $this->actingAs($this->owner)->postJson('/passkey/register/options')->assertNotFound();
    }
}
