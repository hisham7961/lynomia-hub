<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Support\StepUp;
use App\Support\Webauthn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * مفاتيحُ المرور (Passkeys / WebAuthn) — التسجيلُ والتحقّقُ والدخولُ بلا كلمة سر.
 *
 * يُبنى على القائم لا يكرّره: يُتمّ الدخولَ عبر `AuthController::finishLogin`
 * (سجلُّ الجلسة والجهاز والتدقيق نفسُها)، ويُصعّد المصادقةَ عبر `StepUp::stamp`
 * (النافذةُ نفسُها). المحقّقُ الأمنيّ كلُّه في `Support\Webauthn`.
 */
class PasskeyController extends Controller
{
    protected function on(): bool
    {
        return (string) setting('auth.passkeys_on', '1') === '1';
    }

    /* ───────────── التسجيل (مستخدمٌ داخل) ───────────── */

    public function registerOptions(Request $r)
    {
        abort_unless($this->on(), 404);
        // تسجيلُ مفتاح مرورٍ = اعتمادٌ دائم بلا كلمة: جلسةٌ مختطفة كانت تزرعه بلا إعادة تحقّق (v2.399)
        if ($resp = hub_require_credential_stepup()) return $resp;
        $u = $r->user();
        $challenge = Webauthn::challenge();
        session(['wa.reg' => $challenge]);

        $exclude = WebauthnCredential::where('user_id', $u->id)->pluck('credential_id')
            ->map(fn ($c) => ['type' => 'public-key', 'id' => $c])->values();

        return response()->json([
            'challenge' => Webauthn::b64uEncode($challenge),
            'rp' => ['id' => Webauthn::rpId(), 'name' => (string) setting('app.name', 'Lynomia')],
            'user' => [
                'id' => Webauthn::b64uEncode($u->id),   // userHandle = معرّفُ المستخدم
                'name' => (string) $u->email,
                'displayName' => (string) $u->name,
            ],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => Webauthn::ES256]],
            'excludeCredentials' => $exclude,
            'authenticatorSelection' => ['userVerification' => 'preferred', 'residentKey' => 'preferred'],
            'attestation' => 'none',
            'timeout' => 120000,
        ]);
    }

    public function registerVerify(Request $r)
    {
        abort_unless($this->on(), 404);
        if ($resp = hub_require_credential_stepup()) return $resp;
        $u = $r->user();
        $challenge = (string) session('wa.reg', '');
        session()->forget('wa.reg');   // أحاديُّ الاستعمال
        abort_if($challenge === '', 422, 'انتهت جلسةُ التسجيل — أعد المحاولة');

        $d = $r->validate([
            'clientDataJSON' => 'required|string',
            'attestationObject' => 'required|string',
            'label' => 'nullable|string|max:160',
        ]);

        try {
            $reg = Webauthn::verifyRegistration(
                Webauthn::b64uDecode($d['clientDataJSON']),
                Webauthn::b64uDecode($d['attestationObject']),
                $challenge
            );
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        // مفتاحٌ مسجَّلٌ سلفاً (لأيٍّ كان) لا يُسجَّل ثانية
        if (WebauthnCredential::where('credential_id', $reg['credentialId'])->exists()) {
            return response()->json(['ok' => false, 'error' => 'هذا المفتاح مسجَّلٌ بالفعل'], 422);
        }

        WebauthnCredential::create([
            'user_id' => $u->id,
            'credential_id' => $reg['credentialId'],
            'public_key' => $reg['publicKey'],
            'sign_count' => $reg['signCount'],
            'label' => $d['label'] ?? ('مفتاحٌ — ' . now()->format('Y-m-d')),
        ]);
        hub_audit('تسجيل مفتاح مرور', null, null, $u->name);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $r, string $id)
    {
        if ($resp = hub_require_credential_stepup()) return $resp;
        $u = $r->user();
        $cred = WebauthnCredential::where('user_id', $u->id)->findOrFail($id);
        $cred->delete();
        hub_audit('حذف مفتاح مرور', null, null, $u->name . ' — ' . ($cred->label ?: $id));

        return back()->with('ok', '🔑 حُذف مفتاح المرور');
    }

    /* ───────────── تصعيد المصادقة بمفتاح المرور (مستخدمٌ داخل) ───────────── */

    public function stepupOptions(Request $r)
    {
        abort_unless($this->on(), 404);
        $u = $r->user();
        $challenge = Webauthn::challenge();
        session(['wa.stepup' => $challenge]);

        return response()->json([
            'challenge' => Webauthn::b64uEncode($challenge),
            'rpId' => Webauthn::rpId(),
            'allowCredentials' => $this->allowFor($u->id),
            'userVerification' => 'preferred',
            'timeout' => 120000,
        ]);
    }

    public function stepupVerify(Request $r)
    {
        abort_unless($this->on(), 404);
        $u = $r->user();
        $challenge = (string) session('wa.stepup', '');
        session()->forget('wa.stepup');
        abort_if($challenge === '', 422, 'انتهت الجلسة — أعد المحاولة');

        $cred = $this->verifyAssertionInput($r, $challenge, $u->id);
        if (! $cred instanceof WebauthnCredential) return $cred;   // استجابةُ خطأ

        StepUp::stamp();
        hub_audit('تصعيد مصادقة بمفتاح مرور', null, null, $u->name);

        return response()->json(['ok' => true]);
    }

    /* ───────────── الدخول بلا كلمة سر (زائر) ───────────── */

    public function loginOptions(Request $r)
    {
        abort_unless($this->on(), 404);
        $challenge = Webauthn::challenge();
        session(['wa.login' => $challenge]);

        // مفاتيحُ مقيمة (resident): بلا allowCredentials — يُعرَّف المستخدمُ من userHandle
        return response()->json([
            'challenge' => Webauthn::b64uEncode($challenge),
            'rpId' => Webauthn::rpId(),
            'userVerification' => 'required',
            'timeout' => 120000,
        ]);
    }

    public function loginVerify(Request $r)
    {
        abort_unless($this->on(), 404);
        // حدُّ معدلٍ على الدخول بلا كلمة سر كنظيره بكلمة المرور
        $key = 'pk-login:' . $r->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json(['ok' => false, 'error' => 'محاولاتٌ كثيرة — انتظر دقيقة'], 429);
        }
        RateLimiter::hit($key, 60);

        $challenge = (string) session('wa.login', '');
        session()->forget('wa.login');
        abort_if($challenge === '', 422, 'انتهت الجلسة — أعد المحاولة');

        $d = $r->validate(['id' => 'required|string|max:512']);
        $cred = WebauthnCredential::where('credential_id', $d['id'])->first();
        if (! $cred) return response()->json(['ok' => false, 'error' => 'مفتاحٌ غير معروف'], 422);

        $u = User::find($cred->user_id);
        if (! $u) return response()->json(['ok' => false, 'error' => 'الحساب غير موجود'], 422);

        // نفسُ حراس الدخول: موقوف / قفل طوارئ / حساب مقفل
        if ($u->status === 'موقوف') return response()->json(['ok' => false, 'error' => 'الحساب موقوف'], 403);
        if (setting('security.lockdown') && ! hub_is_owner($u)) {
            return response()->json(['ok' => false, 'error' => 'النظام في قفل طوارئ'], 403);
        }
        if ($u->locked_until && now()->lt($u->locked_until)) {
            return response()->json(['ok' => false, 'error' => 'الحساب مقفلٌ مؤقتاً بعد محاولاتٍ فاشلة'], 423);
        }

        $verified = $this->verifyAssertionInput($r, $challenge, $u->id, requireUv: true);
        if (! $verified instanceof WebauthnCredential) return $verified;

        RateLimiter::clear($key);
        \Illuminate\Support\Facades\Auth::login($u, remember: false);

        // إتمامُ الدخول الموحّد نفسُه (سجلُّ الجلسة والجهاز والتدقيق) — via=مفتاح مرور
        app(AuthController::class)->finishLogin($u, $r, 'مفتاح مرور');

        return response()->json(['ok' => true, 'redirect' => hub_home_url($u)]);
    }

    /* ───────────── مشترك ───────────── */

    /** allowCredentials لمستخدم: قائمةُ مفاتيحه */
    protected function allowFor(string $userId)
    {
        return WebauthnCredential::where('user_id', $userId)->pluck('credential_id')
            ->map(fn ($c) => ['type' => 'public-key', 'id' => $c])->values();
    }

    /**
     * يتحقّق من assertionٍ واردة ويُحدّث العدّاد — يُرجع الصفَّ عند النجاح، أو
     * استجابةَ JSON خطأ. `$userId` يقيّد المفتاحَ لصاحبه (لا مفتاحُ غيرِه).
     */
    protected function verifyAssertionInput(Request $r, string $challenge, string $userId, bool $requireUv = false)
    {
        $d = $r->validate([
            'id' => 'required|string|max:512',
            'clientDataJSON' => 'required|string',
            'authenticatorData' => 'required|string',
            'signature' => 'required|string',
        ]);

        $cred = WebauthnCredential::where('credential_id', $d['id'])->where('user_id', $userId)->first();
        if (! $cred) return response()->json(['ok' => false, 'error' => 'مفتاحٌ غير معروف لهذا الحساب'], 422);

        try {
            $newCount = Webauthn::verifyAssertion(
                Webauthn::b64uDecode($d['clientDataJSON']),
                Webauthn::b64uDecode($d['authenticatorData']),
                Webauthn::b64uDecode($d['signature']),
                $cred->public_key,
                $challenge,
                (int) $cred->sign_count,
                $requireUv
            );
        } catch (\Throwable $e) {
            hub_audit('فشل تحقّق مفتاح مرور', null, null, $e->getMessage());

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        $cred->forceFill(['sign_count' => $newCount, 'last_used_at' => now()])->save();

        return $cred;
    }
}
