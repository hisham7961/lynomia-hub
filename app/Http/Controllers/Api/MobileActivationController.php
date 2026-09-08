<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountActivation;
use App\Models\ClientMembership;
use App\Models\User;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * **تفعيلُ حساب العميل من الجوال** (تطبيق العميل · §13) — يُغلق فجوةَ
 * «التفعيلُ ويبيٌّ فقط» على سكّة `AccountActivation` **نفسِها** حرفاً: تجزيءُ
 * الرمز، ورمزٌ سداسيٌّ بمهلةٍ وسقفِ محاولاتٍ يحرق الصفَّ، واستهلاكٌ لمرّة،
 * وكلمةُ سرٍّ يضعها العميلُ بنفسه تحت `password_rules()` — **لا محرّكَ تفعيلٍ ثانٍ
 * ولا كلمةَ سرٍّ تُولَّد/تُرسَل/تُخزَّن صريحةً أبداً**.
 *
 * فرقُ الوسيلة عن الويب (جلسةُ متصفّحٍ بين خطوتَي الرمز والكلمة): الجوالُ عديمُ
 * جلسةِ ويب، فالخطوتان تُدمَجان ذرّيّاً في نداءٍ واحد (`complete`) — التحقّقُ من
 * الكلمة **قبل** حرق الرمز (كلمةٌ ضعيفةٌ لا تُهدر رمزاً صالحاً)، ثم حرقُ الرمز
 * لمرّةٍ (تفريغُ `otp_hash` المشروط — طلبان متزامنان لا يفعّلان مرّتين)، ثم
 * الاستهلاكُ ووضعُ الكلمة وترقيةُ العضويّات «المدعوّة» فعّالةً في معاملةٍ مقفولة.
 *
 * لا جلسةَ جوالٍ تُسكّ هنا: بعد التفعيل يدخل العميلُ عبر `auth/login` فيمرّ
 * بالحراس الخمسة وLoginSentry وMFA كأيّ دخول — سكّةُ دخولٍ واحدة (لا التفاف).
 * والمجهولُ والمُستهلَك 404 (لا كشفَ وجود)، والبريدُ لا يُعاد إلا **مقنَّعاً** في
 * الفحص و**كاملاً بعد إثبات (الرمزَين معاً)** في الإتمام — لتعبئة شاشة الدخول.
 */
class MobileActivationController extends Controller
{
    /** السجلُّ الحيّ أو رفضٌ صلب: المجهولُ والمُستهلَك 404 (نظيرُ الويب حرفاً) */
    private function resolve(string $token): AccountActivation
    {
        $act = AccountActivation::pendingByToken($token);
        if ($act === null) {
            abort(Api::error(Api::RESOURCE_NOT_FOUND, 404, 'رابطُ التفعيل غير صالح'));
        }

        return $act;
    }

    /**
     * `GET activation/{token}` — فحصُ حال الدعوة قبل عرض الشاشة:
     * `pending` (أدخِل الرمز وكلمتَك) أو `expired` (اطلب رابطاً جديداً).
     * البريدُ مقنَّعٌ للعرض فقط — لا كشفَ هويّةٍ كاملةٍ قبل إثبات الرمز.
     */
    public function show(Request $r, string $token): JsonResponse
    {
        $this->tagMobile($r);
        $act = $this->resolve($token);

        return $this->ok([
            'status' => $act->isExpired() ? 'expired' : 'pending',
            'email_masked' => $this->maskEmail((string) $act->email),
        ]);
    }

    /**
     * `POST activation/{token}/complete` — `{otp, password, password_confirmation}`:
     * التحقّقُ والحرقُ والاستهلاكُ ووضعُ الكلمة وترقيةُ العضويّات — ذرّيّاً كما وُصف أعلاه.
     */
    public function complete(Request $r, string $token): JsonResponse
    {
        $this->tagMobile($r);
        $act = $this->resolve($token);

        // حدُّ المعدّل لكل (صفّ، IP) — نظيرُ الويب حرفاً
        $key = 'activate-otp:' . $act->id . ':' . $r->ip();
        if (RateLimiter::tooManyAttempts($key, AccountActivation::MAX_OTP_ATTEMPTS)) {
            return Api::error(Api::RATE_LIMITED, 429,
                'محاولاتٌ كثيرة — انتظر قليلاً ثم أعد المحاولة، أو اطلب رابطَ تفعيلٍ جديداً');
        }

        // الصفُّ محروقٌ بعد بلوغ السقف — حتى الرمزُ الصحيح لا يُقبل
        if ((int) $act->attempts >= AccountActivation::MAX_OTP_ATTEMPTS) {
            return Api::error(Api::VALIDATION_FAILED, 422,
                'استُنفدت محاولاتُ الرمز — اطلب رابطَ تفعيلٍ جديداً');
        }

        if ($act->isExpired()) {
            return Api::error(Api::VALIDATION_FAILED, 422,
                'انتهت صلاحيةُ التفعيل — اطلب رابطَ تفعيلٍ جديداً',
                ['reason' => 'expired']);
        }

        // الكلمةُ **قبل** حرق الرمز: كلمةٌ ضعيفةٌ لا تُهدر رمزاً صالحاً (422 حقلية)
        $data = $r->validate([
            'otp' => ['required', 'string'],
            'password' => ['required', 'confirmed', password_rules()],
        ], [], ['otp' => 'رمز التحقق', 'password' => 'كلمة المرور']);

        $input = preg_replace('/\D/', '', hub_str($data['otp']));
        $ok = strlen($input) === 6
            && $act->otp_hash !== null && Hash::check($input, $act->otp_hash);

        if (! $ok) {
            // زيادةٌ ذرّيّةٌ للعدّاد + حدُّ IP — نظيرُ الويب حرفاً
            AccountActivation::where('id', $act->id)->increment('attempts');
            RateLimiter::hit($key, 600);

            return Api::error(Api::VALIDATION_FAILED, 422,
                'رمزُ التحقّق غير صحيحٍ أو منتهٍ — تأكّد من الرمز أو اطلب رابطاً جديداً',
                ['errors' => ['otp' => ['رمزُ التحقّق غير صحيح']]]);
        }

        // حرقٌ لمرّة (whoWins): تفريغٌ مشروطٌ — متزامنان بالرمز نفسِه لا يفعّلان مرّتين
        $burned = AccountActivation::where('id', $act->id)->whereNotNull('otp_hash')
            ->update(['otp_hash' => null]);
        if (! $burned) {
            return Api::error(Api::VALIDATION_FAILED, 422,
                'استُعمل هذا الرمزُ بالفعل — اطلب رابطَ تفعيلٍ جديداً');
        }

        RateLimiter::clear($key);

        // ذرّيّاً: قفلُ الصفّ + حارسُ «لم يُستهلَك بعد» — نظيرُ set الويبيّ حرفاً
        $user = DB::transaction(function () use ($act, $data, $r) {
            $fresh = AccountActivation::where('id', $act->id)->lockForUpdate()->first();
            if ($fresh === null || $fresh->consumed_at !== null) {
                abort(Api::error(Api::RESOURCE_NOT_FOUND, 404, 'رابطُ التفعيل غير صالح'));
            }

            $user = User::find($fresh->user_id);
            if ($user === null || ! $user->isClientAccount()) {
                abort(Api::error(Api::RESOURCE_NOT_FOUND, 404, 'رابطُ التفعيل غير صالح'));
            }

            // كلمةُ السرّ التي وضعها العميلُ (cast 'hashed' يُجزّئها) + «مُفعَّل»
            $user->forceFill([
                'password' => $data['password'],
                'password_changed_at' => now(),
                'failed_attempts' => 0, 'locked_until' => null,
            ])->save();

            // استهلاكٌ لمرّة — الصفُّ لا يُعاد استعماله
            $fresh->forceFill(['consumed_at' => now(), 'otp_hash' => null, 'ip' => $r->ip()])->save();

            // التفعيلُ = الانضمام: «المدعوّةُ» تصير فعّالةً؛ «المعلَّقةُ» تبقى (سحبٌ متعمَّد)
            ClientMembership::where('user_id', $user->id)
                ->where('status', 'invited')
                ->update(['status' => 'active', 'activated_at' => now()]);

            return $user;
        });

        // حدثٌ دلاليّ + أثرُ تدقيق — السكّةُ نفسُها (source=mobile عبر الوسم أعلاه)
        try {
            \App\Support\FlowRunner::fire('account_activated', 'users', $user);
        } catch (\Throwable $e) {
            report($e);
        }
        hub_audit('تفعيلُ حساب عميل', 'users', $user->id, $user->name, ['user_id' => $user->id]);

        // بعد إثبات (الرابط + الرمز) يُعاد البريدُ كاملاً لتعبئة شاشة الدخول —
        // لا جلسةَ تُسكّ: الدخولُ عبر auth/login بحراسه كاملةً (سكّةٌ واحدة).
        return $this->ok(['activated' => true, 'email' => (string) $user->email]);
    }

    /** قناعُ بريدٍ للعرض: `a***@d***.com` — إشارةُ طمأنةٍ لا كشفُ هويّة */
    private function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '***';
        }
        [$local, $domain] = explode('@', $email, 2);
        $l = Str::substr($local, 0, 1) . '***';
        $dotPos = strrpos($domain, '.');
        $d = $dotPos === false
            ? Str::substr($domain, 0, 1) . '***'
            : Str::substr($domain, 0, 1) . '***' . substr($domain, $dotPos);

        return $l . '@' . $d;
    }

    /** غلافُ نجاحٍ موحَّد: `data` + `request_id` */
    private function ok(array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'request_id' => Api::requestId()], 200);
    }

    /** وسمُ مصدرِ الطلب `mobile` — للتدقيق (وسمٌ لا تخويل) */
    private function tagMobile(Request $r): void
    {
        $r->attributes->set('request_source', 'mobile');
    }
}
