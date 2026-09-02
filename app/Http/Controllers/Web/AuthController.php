<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function login(Request $r)
    {
        $data = $r->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $fail = fn (string $msg = 'بيانات الدخول غير صحيحة') => back()->withErrors(['email' => $msg])->onlyInput('email');

        $user = User::where('email', $data['email'])->first();

        /*
         * **لا إفصاحَ عن حالة الحساب قبل صحّة كلمة المرور** (v2.319).
         *
         * كانت خمسةُ فروعٍ تردّ برسائل مميَّزة (موقوف/منتهٍ/مقفول/محصورٌ بشبكة/
         * قفلُ طوارئ) **قبل** `Auth::attempt` — فأربعُ رسائل مختلفة تقول للمهاجم
         * «هذا البريد موجود» بطلبٍ واحد بلا كلمة سرّ: أوراكلُ تعدادٍ كامل.
         * وأسوأُها رسالةُ القفل: تُخبره أن هجومَه نجح ومتى ينتهي.
         *
         * الآن تُحسَب الأسبابُ ولا يُفصَح عنها إلا بعد التحقق. والحسابُ الموقوف
         * أو المقفول لا يدخل: `$blocked` تُفحَص بعد `attempt` فيُطرد ويُبلَّغ
         * بسببه هو (وقد أثبت أنه صاحبُ الحساب).
         */
        $blocked = null;
        if ($user) {
            if ($user->status === 'موقوف') {
                $blocked = 'الحساب موقوف — راجع مالك النظام';
            } elseif ($user->expires_at && now()->toDateString() > substr((string) $user->expires_at, 0, 10)) {
                $blocked = 'انتهت صلاحية الحساب — راجع مالك النظام';
            } elseif ($user->locked_until && now()->lt($user->locked_until)) {
                $m = max(1, (int) ceil(now()->diffInSeconds($user->locked_until) / 60));
                $blocked = "الحساب مقفل مؤقتاً بعد محاولات فاشلة — أعد المحاولة بعد {$m} دقيقة";
            } elseif ($user->allowed_ips && ! ip_allowed((string) $r->ip(), $user->allowed_ips)) {
                $blocked = 'الدخول غير مسموح من عنوان الشبكة الحالي';
            } elseif (setting('security.lockdown') && ! hub_is_owner($user)) {
                $blocked = 'النظام في قفل طوارئ مؤقت — الدخول للمالكين فقط';
            }
        }

        // **لا دخول إلا بالبريد وكلمة المرور** (v2.354): `remember: false` — لا تُصدَر
        // كعكةُ «تذكّرني» بعد اليوم. كانت الكعكةُ تُعيد بعثَ الجلسة دون كلمة مرور،
        // فكعكةٌ مسروقةٌ أو عودةٌ بعد انتهاء الجلسة تدخل بلا الباسورد — وهو ما يناقض
        // «لا يُدخَل إلا بالبريد وكلمة المرور». الجلسةُ تدوم عمرَها المعتاد ثم يُعاد
        // الدخولُ بالبريد وكلمة المرور. (وحارسُ الجلسة يردّ أيَّ كعكةٍ قديمة باقية.)
        if (! Auth::attempt($data, remember: false)) {
            if ($user) {
                $this->bumpFailedAttempts($user);
            }
            // بصمة المحاولة الفاشلة في التدقيق — تُعرض في مركز الأمان
            // البريد يُحفظ كاملاً (٢٩٠) لا مبتوراً عند ٦٠ — أثرٌ أمني يُقرأ لاحقاً
            hub_audit('دخول فاشل', null, null, null,
                ['user_id' => $user?->id, 'name' => substr($data['email'], 0, 290)]);

            return $fail();
        }

        $u = Auth::user();

        // كلمةُ المرور صحيحة — الآن فقط يُفصَح عن سبب المنع، ولصاحب الحساب وحده
        if ($blocked !== null) {
            Auth::logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();

            return $fail($blocked);
        }

        // مصادقة ثنائية مفعّلة؟ أوقف الدخول حتى إدخال الرمز
        if ($u->totp_enabled) {
            Auth::logout();
            $r->session()->put('2fa:uid', $u->id);
            $r->session()->regenerate();
            // تحدّي الخطوة الثانية حدثٌ أمنيّ (MFA_CHALLENGE): كلمةٌ صحيحة بلا إتمامِ رمزٍ أثرٌ يُقرأ
            hub_audit('تحدّي التحقق بخطوتين', null, null, $u->name, ['user_id' => $u->id]);

            return redirect()->route('login.otp');
        }

        return $this->finishLogin($u, $r);
    }

    /** خطوة رمز المصادقة الثنائية */
    public function otpShow(Request $r)
    {
        abort_unless($r->session()->has('2fa:uid'), 404);

        return view('auth.otp');
    }

    public function otpVerify(Request $r)
    {
        $u = User::find($r->session()->get('2fa:uid'));
        abort_unless($u, 404);

        // قفل الحساب يسري على خطوة الرمز أيضاً: خطوةُ كلمة المرور تقفل بعد
        // محاولاتٍ فاشلة، وكان رمزُ TOTP بلا قفلٍ — تخمينٌ غير محدودٍ لحسابٍ بعينه.
        if ($u->locked_until && now()->lt($u->locked_until)) {
            $m = max(1, (int) ceil(now()->diffInSeconds($u->locked_until) / 60));

            return back()->withErrors(['code' => "الحساب مقفل مؤقتاً بعد محاولات فاشلة — أعد المحاولة بعد {$m} دقيقة"]);
        }

        if (! \App\Support\Totp::verify((string) $u->totp_secret_cipher, hub_str($r->input('code')))) {
            $this->bumpFailedAttempts($u);
            // فشلُ الرمز الثاني حدثٌ أمنيّ يستحق أثراً كفشل كلمة المرور — كان
            // يزيد العدّاد بصمتٍ فلا يظهر «كلمةٌ صحيحةٌ ورمزٌ يُخمَّن» في التدقيق
            hub_audit('فشل رمز التحقق', null, null, $u->name, ['user_id' => $u->id]);

            return back()->withErrors(['code' => 'الرمز غير صحيح أو انتهى — جرّب الرمز الحالي في التطبيق']);
        }

        $r->session()->forget('2fa:uid');
        Auth::login($u, remember: false);   // لا كعكةَ «تذكّرني» — الدخول بالبريد وكلمة المرور فقط

        return $this->finishLogin($u, $r);
    }

    /** إتمام الدخول الموحد: تصفير العدادات + سجل الجلسة + تدوير المعرف */
    public function finishLogin(User $u, Request $r, ?string $via = null)
    {
        $u->forceFill([
            'failed_attempts' => 0,
            'locked_until'    => null,
            'last_login_at'   => now(),
            'last_login_ip'   => $r->ip(),
        ])->saveQuietly();

        // هوية الجهاز: كوكي ثابتٌ يُربط بصفٍّ «معلّق» عند أول ظهور — إشارةٌ
        // لحارس الدخول ولخطر الجلسة، لا سلاحُ حجب. يُلحق الكوكي على الاستجابة.
        $newDevice = ! \App\Support\Devices::isKnown($u, $r);
        $device = \App\Support\Devices::bindOnLogin($u, $r);

        // حارس الدخول: يتعلم العناوين المعتادة ويرصد الغريب وخارج الدوام
        \App\Support\LoginSentry::inspect($u, (string) $r->ip(), $newDevice);

        // معرّف صفّ الجلسة يُحفَظ في الجلسة نفسها: بغيره لا نبضةَ حضورٍ ولا
        // إنهاءَ عن بُعد — وهو ما جعل عمود revoked ميتاً منذ الهجرة الأولى
        $log = \App\Models\SessionLog::create([
            'user_id'      => $u->id,
            'device'       => substr((string) $r->header('X-Device', $r->userAgent()), 0, 200),
            'device_id'    => $device?->id,
            'ip'           => $r->ip(),
            'user_agent'   => substr((string) $r->userAgent(), 0, 400),
            'started_at'   => now(),
            'last_seen_at' => now(),
        ]);

        $r->session()->regenerate();
        $r->session()->put('hub.sl', $log->id);

        // دخولٌ ناجح حدثٌ أمنيّ يدخل سلسلة التدقيق الموقَّعة — كان يُسجَّل في
        // sessions_log فقط (بلا ختمٍ متسلسل)، فالتحقيق في «متى ومن أين دخل»
        // لم يكن مضموناً ضد العبث كما الفشل. يُختم مع رقم صفّ الجلسة.
        hub_audit('دخول ناجح', null, null, $u->name, ['after' => ['session' => $log->id, 'via' => $via ?: ($u->totp_enabled ? '2FA' : 'كلمة مرور')]]);

        // شاشة البداية من تفضيل المستخدم — والرابط المقصود قبل الدخول يفوز عليها
        return redirect()->intended(hub_home_url(auth()->user()));
    }

    public function logout(Request $r)
    {
        $name = auth()->user()?->name;
        $sl = (string) $r->session()->get('hub.sl', '');
        // خروجٌ مقصود: يُدوَّن قبل إبطال الجلسة، ويُعلَّم صفُّ الجلسة منتهياً
        // كي لا يبقى «حيّاً» في مركز الأمن بعد خروج صاحبه.
        if ($name !== null) hub_audit('خروج', null, null, $name);
        if ($sl !== '') \Illuminate\Support\Facades\DB::table('sessions_log')->where('id', $sl)->update(['revoked' => true]);

        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * زيادةُ عدّاد المحاولات الفاشلة **ذرّيّاً** ثم قفلُ الحساب عند السقف.
     *
     * كان `$u->failed_attempts = $u->failed_attempts + 1; save()` قراءةً في الذاكرة
     * ثم كتابةً — فطلبان متوازيان يقرآن القيمةَ نفسها فتُكتب زيادةٌ واحدةٌ عن اثنتين،
     * فيضعف قفلُ الحساب أمام التخمين المتوازي. الآن الزيادةُ على مستوى القاعدة
     * (`increment`) لا تُفقد شيئاً، والقفلُ يُكتب بشرطٍ يمنع كتابتين متسابقتين.
     */
    protected function bumpFailedAttempts(User $u): void
    {
        $max = max(1, (int) setting('auth.max_fail', 5));
        $min = max(1, (int) setting('auth.lock_min', 15));
        $t   = \Illuminate\Support\Facades\DB::table('users')->where('id', $u->id);

        $t->increment('failed_attempts');
        $n = (int) \Illuminate\Support\Facades\DB::table('users')->where('id', $u->id)->value('failed_attempts');
        if ($n >= $max) {
            // شرطُ `>= max` يمنع صفرَ العدّاد مرّتين متسابقتين: أوّلُ من يبلغ السقف
            // يقفل ويُصفّر، والثاني لا يجد ما يصفّره فلا يُمدّد القفلَ بلا داعٍ.
            $locked = $t->where('failed_attempts', '>=', $max)
                ->update(['locked_until' => now()->addMinutes($min), 'failed_attempts' => 0]);
            // قفلُ حسابٍ حدثٌ أمنيّ يستحق **حالةً تُحقَّق** لا سطرَ تدقيقٍ يمرّ:
            // يُفتح (أو يُثرى) حادثةٌ أمنيّة — للتحقيق البشريّ لا للعقاب الآليّ.
            if ($locked) {
                hub_security_incident('قفلُ حسابٍ بعد محاولاتٍ فاشلة: ' . $u->name, 'عالي', [
                    'user_id' => $u->id, 'ip' => request()?->ip(), 'threshold' => $max,
                ]);
            }
        }
    }
}
