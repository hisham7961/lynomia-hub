<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Support\Security\PasswordHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * **استعادةُ كلمة المرور ذاتيّاً** (بندُ الدَّين #15 · AUTH-09).
 *
 * كان الناسي لا يملك إلا أن يطلب من الإدارة إعادةَ الضبط. الآن مساران عامّان
 * (ما قبل المصادقة) على **وسيط كلمات المرور في الإطار** — لا محرّكَ رموزٍ ثانٍ:
 *
 *  · **الطلب** (`password.email`): ردٌّ **واحدٌ حرفاً** وُجد البريدُ أم لم يوجد، ولحسابٍ
 *    موقوفٍ أو منتهٍ أو عميلٍ لم يُفعَّل بعدُ كما لغائب — فلا أوراكلَ تعداد. وعملُ تجزيءٍ
 *    مكافئٌ حين لا يُرسَل شيء، فلا يفضح الزمنُ ما تكتمه الرسالة.
 *  · **الرمز**: يُجزَّأ في `password_reset_tokens` (الوسيطُ يخزّن `Hash::make`)، وينتهي بعد
 *    `auth.passwords.users.expire` دقيقة، ويُستهلك بالاستعمال. والرابطُ يسلك **صندوقَ
 *    الصادر** (`outbox` · قناةُ البريد) كرابطِ تفعيل العميل حرفاً — المسارُ الصادرُ الوحيد.
 *  · **الوضع** (`password.update`): يفرض `password_rules()` وسجلَّ الكلمات السابقة، ثم
 *    **يُنهي كلَّ الجلسات** (الويب والجوال) ويُدوّر «تذكّرني»، ويُدوَّن في التدقيق
 *    ويُشعَر صاحبُ الحساب. **ولا يُدخِله**: يعود إلى شاشة الدخول فيبقى التحقّقُ بخطوتين
 *    مطلوباً لمن فعّله — البريدُ وحدَه لا يتجاوز العاملَ الثاني.
 *
 * والسقوفُ: خانقُ `password-reset` المسمّى (البريد/ساعة + العنوان/دقيقة) قبل المتحكّم،
 * وخانقُ الوسيط نفسِه (رابطٌ واحدٌ لكلِّ بريدٍ كلَّ دقيقة).
 */
class PasswordResetController extends Controller
{
    /** الردُّ الواحد على كلِّ طلب — وُجد الحسابُ أم لم يوجد */
    public const SENT = 'إن كان هذا البريدُ مسجّلاً لحسابٍ نشط فستصله رسالةٌ برابط استعادة كلمة المرور خلال دقائق — صالحٌ مدّةً محدودةً ولمرّةٍ واحدة.';

    /** رمزٌ غائبٌ أو منتهٍ أو مستعمَل أو لبريدٍ آخر — رسالةٌ واحدةٌ لا تميّز بينها */
    public const INVALID = 'رابطُ الاستعادة غيرُ صالحٍ أو انتهت صلاحيّتُه — اطلب رابطاً جديداً';

    /**
     * فعلُ التدقيق لإصدار رابطٍ — ثابتٌ مُعلَن (نمطُ `Settings::AUDIT_ACTION`)؛ والوضعُ نفسُه
     * يُدوَّن بفعلِ إعادة الضبط القائم «إعادة تعيين كلمة مرور» بـ`via=self_service` لا بصياغةٍ ثانية.
     */
    public const AUDIT_REQUEST = 'طلب استعادة كلمة المرور';

    /** رايةُ الميزة — مطفأةً تردّ المساراتُ ٤٠٤ ويختفي الرابطُ من شاشة الدخول */
    public static function enabled(): bool
    {
        return (string) setting('auth.pw_reset_on', '1') === '1';
    }

    /**
     * مَن تُرسَل له الاستعادة: حسابٌ نشطٌ غيرُ منتهٍ، وليس عميلاً لم يُفعِّل حسابَه —
     * ذاك له مسارُ التفعيل برمزه (C8)، والاستعادةُ لا تكون باباً خلفيّاً لتجاوزه.
     */
    public static function eligible(User $u): bool
    {
        if (! $u->isActive()) return false;
        if ($u->expires_at && now()->toDateString() > substr((string) $u->expires_at, 0, 10)) return false;
        if ($u->isClientAccount() && $u->password_changed_at === null) return false;

        return true;
    }

    /** GET — نموذجُ طلب الرابط */
    public function request()
    {
        abort_unless(self::enabled(), 404);

        return view('auth.forgot');
    }

    /** POST — إصدارُ الرابط (إن استحقّ) والردُّ الواحد في كلِّ حال */
    public function email(Request $r)
    {
        abort_unless(self::enabled(), 404);

        $data = $r->validate(['email' => ['required', 'email', 'max:190']],
            [], ['email' => 'البريد الإلكتروني']);

        $broker = Password::broker();
        $user = $broker->getUser(['email' => $data['email']]);
        $sent = false;

        if ($user instanceof User && self::eligible($user)) {
            // الوسيطُ يُنشئ الرمزَ ويجزّئه ويخنق التكرار؛ والتسليمُ عبر الصادر لا عبر إشعار الإطار
            $status = $broker->sendResetLink(['email' => $user->email], function (User $u, string $token) use (&$sent) {
                self::deliver($u, $token);
                $sent = true;
            });
            if ($status === Password::RESET_LINK_SENT && $sent) {
                hub_audit(self::AUDIT_REQUEST, 'users', (string) $user->id, $user->name, ['user_id' => $user->id]);
            }
        }

        // عملُ تجزيءٍ مكافئٌ حين لا يُصدَر رمز — فالزمنُ لا يقول ما لا تقوله الرسالة
        if (! $sent) Hash::make(Str::random(40));

        return back()->with('ok', self::SENT);
    }

    /** GET — نموذجُ الكلمة الجديدة (الرمزُ في الرابط، والبريدُ في الاستعلام) */
    public function show(Request $r, string $token)
    {
        abort_unless(self::enabled(), 404);

        return view('auth.reset', [
            'token' => $token,
            'email' => (string) $r->query('email', ''),
        ]);
    }

    /** POST — وضعُ الكلمة الجديدة: يستهلك الرمز، يُنهي الجلسات، يدوِّن، ولا يُدخِل */
    public function update(Request $r)
    {
        abort_unless(self::enabled(), 404);

        $data = $r->validate([
            'token' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'confirmed', password_rules()],
        ], [
            'password.confirmed' => 'تأكيد كلمة المرور غير مطابق',
        ], [
            'email' => 'البريد الإلكتروني',
            'password' => 'كلمة المرور الجديدة',
        ]);

        $invalid = fn () => back()->withErrors(['email' => self::INVALID])->onlyInput('email');

        $broker = Password::broker();
        $user = $broker->getUser(['email' => $data['email']]);
        // غيابُ الحساب وعدمُ استحقاقه وفسادُ الرمز — رسالةٌ واحدة
        if (! $user instanceof User || ! self::eligible($user) || ! $broker->tokenExists($user, $data['token'])) {
            return $invalid();
        }

        // سجلُّ الكلمات السابقة — بعد ثبوت الرمز فقط، فلا يُفحص لمن لم يُثبت ملكيّةَ البريد
        $r->validate(['password' => [PasswordHistory::rule($user)]]);

        $status = $broker->reset([
            'email' => $user->email,
            'token' => $data['token'],
            'password' => $data['password'],
            'password_confirmation' => $data['password'],
        ], function (User $u, string $password): void {
            $u->forceFill([
                'password' => $password,                 // cast hashed يتكفّل بالتجزئة
                'password_changed_at' => now(),
                // مَن أثبت ملكيّةَ بريده يُرفع عنه قفلُ المحاولات (كالتفعيل حرفاً)
                'failed_attempts' => 0, 'locked_until' => null,
            ])->save();
        });

        if ($status !== Password::PASSWORD_RESET) return $invalid();

        $user->refresh();
        // كلُّ ما فُتح بالكلمة القديمة يُغلق: جلساتُ الويب + «تذكّرني» + الجوال (AUTH-1)
        \App\Support\Security\Sessions::revokeAll($user, null, 'استعادة كلمة المرور');
        \App\Support\Mobile\MobileSessionService::revokeAllForUser($user, 'استعادةُ كلمة المرور');

        hub_audit('إعادة تعيين كلمة مرور', 'users', (string) $user->id, $user->name,
            ['user_id' => $user->id, 'after' => ['via' => 'self_service', 'sessions_revoked' => true]]);
        hub_notify($user->id, 'security',
            'أُعيد ضبطُ كلمة مرورك برابط الاستعادة المرسَل إلى بريدك وأُنهيت جلساتُك القديمة — إن لم تفعل ذلك راجع مدير النظام فوراً',
            'users', (string) $user->id);

        return redirect()->route('login')->with('ok', 'غُيّرت كلمة المرور — ادخل الآن بكلمتك الجديدة');
    }

    /**
     * تسليمُ الرابط عبر صندوق الصادر (قناةُ البريد) — نمطُ `AccountActivation::issue`.
     * النصُّ يحمل الرابطَ وحدَه: لا كلمةَ سرّ، والرمزُ الخامُّ لا يُخزَّن إلا في الرسالة.
     */
    public static function deliver(User $user, string $token): void
    {
        $app = (string) setting('app.name', config('app.name'));
        $ttl = (int) config('auth.passwords.users.expire', 60);
        // من العنوان المضبوط لا من ترويسة Host للطلب المجهول (تسميمُ رابط الاستعادة)
        $url = \App\Support\Platform\PublicUrl::route('password.reset', ['token' => $token, 'email' => $user->email]);

        OutboxMessage::create([
            'kind' => 'password_reset', 'channel' => 'mail', 'target' => $user->email,
            'text' => 'استعادةُ كلمة المرور في «' . Str::limit($app, 60) . '»: افتح الرابط ' . $url
                . ' وضع كلمةً جديدة — صالحٌ ' . $ttl . ' دقيقة ولمرّةٍ واحدة.'
                . ' إن لم تطلب ذلك فتجاهل هذه الرسالة؛ كلمتُك الحاليّةُ لم تتغيّر.',
            'state' => 'queued', 'created_at' => now(),
        ]);
    }
}
