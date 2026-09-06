<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AccountActivation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * **تفعيلُ حساب العميل الآمن** (Work OS · الطور B · WP-B.1 · §12):
 * بريدٌ ← رمزُ تحقّقٍ ← كلمةُ سرٍّ يضعها العميلُ بنفسه — ما قبل المصادقة، برموزٍ
 * لمرّةٍ ومقيَّدةٍ بالمعدّل، على سكّةِ OTP التوقيع الإلكتروني نفسِها (لا محرّكَ ثانٍ).
 *
 * ثلاثُ خطوات: `show` (GET — يعرض نموذجَ الرمز، أو نموذجَ الكلمة بعد نجاحه)،
 * `otp` (POST — تحقّقٌ لمرّةٍ + مهلةٌ + سقفُ محاولات)، `set` (POST — يفرض
 * `password_rules()`، يضع كلمةَ السرّ، يستهلك الصفَّ، ويكتب سلسلةَ تدقيقِ الدخول).
 *
 * القاعدةُ الحاكمة (§12): لا كلمةَ سرٍّ تُولَّد/تُرسَل/تُخزَّن صريحة — العميلُ وحدَه
 * يضعها هنا. والرمزُ المُستهلَكُ لا يُكشَف وجودُه (٤٠٤ لا ٤٠٣، نظيرُ PortalGuard).
 */
class ActivationController extends Controller
{
    /**
     * السجلُّ الحيّ أو رفضٌ صلب: المجهولُ **والمُستهلَك** ٤٠٤ (لا نكشف وجودَ ما
     * لا يخصّه). المنتهي يُترَك للخطوة تعالجه رسالةً ناعمة (نظيرُ e-sign unlock).
     */
    protected function resolve(string $token): AccountActivation
    {
        $act = AccountActivation::pendingByToken($token);
        abort_if($act === null, 404);

        return $act;
    }

    /** GET — نموذجُ الرمز، أو نموذجُ كلمة السرّ متى نجح الرمزُ (علامةُ الجلسة) */
    public function show(Request $r, string $token)
    {
        $act = $this->resolve($token);

        if ($r->session()->get("activate.ok.{$token}") === true && ! $act->isExpired()) {
            return view('auth.activate_set', ['token' => $token]);
        }

        return view('auth.activate', ['token' => $token, 'expired' => $act->isExpired()]);
    }

    /** POST — تحقّقُ الرمز: لمرّةٍ واحدة، بمهلةٍ، بسقفِ محاولاتٍ يحرق الصفّ */
    public function otp(Request $r, string $token)
    {
        $act = $this->resolve($token);

        // حدُّ المعدّل لكل (صفّ، IP) — نظيرُ e-sign unlock
        $key = 'activate-otp:' . $act->id . ':' . $r->ip();
        if (RateLimiter::tooManyAttempts($key, AccountActivation::MAX_OTP_ATTEMPTS)) {
            return back()->withErrors(['otp' => 'محاولاتٌ كثيرة — انتظر قليلاً ثم أعد المحاولة، أو اطلب رابطَ تفعيلٍ جديداً']);
        }

        // الصفُّ محروقٌ بعد بلوغِ السقفِ المُثبَّت — حتى الرمزُ الصحيحُ لا يُقبل
        if ((int) $act->attempts >= AccountActivation::MAX_OTP_ATTEMPTS) {
            return back()->withErrors(['otp' => 'استُنفدت محاولاتُ الرمز — اطلب رابطَ تفعيلٍ جديداً']);
        }

        $input = preg_replace('/\D/', '', hub_str($r->input('otp')));
        $ok = strlen($input) === 6 && ! $act->isExpired()
            && $act->otp_hash !== null && Hash::check($input, $act->otp_hash);

        if (! $ok) {
            // زيادةٌ ذرّيّةٌ للعدّاد (نظيرُ bumpFailedAttempts — لا قراءةٌ ثم كتابة) + حدُّ IP
            AccountActivation::where('id', $act->id)->increment('attempts');
            RateLimiter::hit($key, 600);

            return back()->withErrors(['otp' => 'رمزُ التحقّق غير صحيحٍ أو منتهٍ — تأكّد من الرمز أو اطلب رابطاً جديداً']);
        }

        // نجاحٌ يُستهلَك مرّةً (whoWins): تفريغُ otp_hash بشرطِ أنه لم يُفرَّغ بعد،
        // فطلبان متزامنان بالرمز نفسِه لا يمنحان الجلسةَ مرّتين (نظيرُ Totp::verifyOnce).
        $burned = AccountActivation::where('id', $act->id)->whereNotNull('otp_hash')->update(['otp_hash' => null]);
        if (! $burned) {
            return back()->withErrors(['otp' => 'استُعمل هذا الرمزُ بالفعل — اطلب رابطَ تفعيلٍ جديداً']);
        }

        RateLimiter::clear($key);
        $r->session()->put("activate.ok.{$token}", true);

        return redirect()->route('activate.show', $token);
    }

    /** POST — وضعُ كلمة السرّ: يفرض password_rules، يستهلك الصفَّ، يكتب سلسلةَ الدخول */
    public function set(Request $r, string $token)
    {
        $act = $this->resolve($token);
        abort_unless($r->session()->get("activate.ok.{$token}") === true, 403, 'أكمِل خطوةَ رمز التحقّق أولاً');

        if ($act->isExpired()) {
            $r->session()->forget("activate.ok.{$token}");

            return back()->withErrors(['password' => 'انتهت صلاحيةُ التفعيل — اطلب رابطَ تفعيلٍ جديداً']);
        }

        // كلمةُ السرّ النهائيةُ تحترم password_rules() — لا كلمةَ ضعيفة (§12)
        $data = $r->validate(['password' => ['required', 'confirmed', password_rules()]]);

        // ذرّيّاً: قفلُ الصفّ + حارسُ «لم يُستهلَك بعد» — فقبولان متزامنان لا يفعّلان مرّتين
        $user = DB::transaction(function () use ($act, $data, $r) {
            $fresh = AccountActivation::where('id', $act->id)->lockForUpdate()->first();
            abort_if($fresh === null || $fresh->consumed_at !== null, 404);

            $user = User::find($fresh->user_id);
            abort_if($user === null || ! $user->isClientAccount(), 404);

            // كلمةُ السرّ التي وضعها العميلُ — تُكتب على users.password وحدَها (cast 'hashed' يُجزّئها)
            $user->forceFill([
                'password' => $data['password'],
                'password_changed_at' => now(),        // «مُفعَّل» — العميلُ وضع كلمتَه
                'failed_attempts' => 0, 'locked_until' => null,
            ])->save();

            // استهلاكٌ لمرّةٍ: الصفُّ لا يُعاد استعماله، وotp_hash يُفرَّغ نهائيّاً
            $fresh->forceFill(['consumed_at' => now(), 'otp_hash' => null, 'ip' => $r->ip()])->save();

            // ── وصلٌ بـWP-B.3: التفعيلُ = الانضمام. عضويّاتُه «المدعوّة» (invited) تصير
            // «فعّالة» (active) الآن فيبلغ العميلُ نطاقَه فور دخوله؛ المعلَّقةُ (suspended)
            // تبقى معلَّقةً (سحبٌ متعمَّد لا يُبطله تفعيلٌ لاحق). كتابةٌ ذرّيّةٌ داخل المعاملة.
            \App\Models\ClientMembership::where('user_id', $user->id)
                ->where('status', 'invited')
                ->update(['status' => 'active', 'activated_at' => now()]);

            return $user;
        });

        $r->session()->forget("activate.ok.{$token}");

        // حدثٌ دلاليّ (يُطلق التدفّقاتِ كأيّ حدث) + أثرُ تدقيقٍ للتفعيل
        try {
            \App\Support\FlowRunner::fire('account_activated', 'users', $user);
        } catch (\Throwable $e) {
            report($e);
        }
        hub_audit('تفعيلُ حساب عميل', 'users', $user->id, $user->name, ['user_id' => $user->id]);

        // سلسلةُ تدقيقِ الدخول نفسُها (SessionLog + «دخول ناجح» المختوم) — لا سكّةَ ثانية
        Auth::login($user, remember: false);

        return app(AuthController::class)->finishLogin($user, $r, 'تفعيل');
    }
}
