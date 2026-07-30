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

        if ($user) {
            if ($user->status === 'موقوف') {
                return $fail('الحساب موقوف — راجع مالك النظام');
            }
            if ($user->expires_at && now()->toDateString() > substr((string) $user->expires_at, 0, 10)) {
                return $fail('انتهت صلاحية الحساب — راجع مالك النظام');
            }
            if ($user->locked_until && now()->lt($user->locked_until)) {
                $m = max(1, (int) ceil(now()->diffInSeconds($user->locked_until) / 60));
                return $fail("الحساب مقفل مؤقتاً بعد محاولات فاشلة — أعد المحاولة بعد {$m} دقيقة");
            }
            if ($user->allowed_ips && ! ip_allowed((string) $r->ip(), $user->allowed_ips)) {
                return $fail('الدخول غير مسموح من عنوان الشبكة الحالي');
            }
        }

        // قفل الطوارئ: يدخل المالكون فقط
        if (setting('security.lockdown') && $user && ! hub_is_owner($user)) {
            return $fail('النظام في قفل طوارئ مؤقت — الدخول للمالكين فقط');
        }

        if (! Auth::attempt($data, remember: true)) {
            if ($user) {
                $user->failed_attempts = ((int) $user->failed_attempts) + 1;
                if ($user->failed_attempts >= (int) setting('auth.max_fail', 5)) {
                    $user->locked_until    = now()->addMinutes((int) setting('auth.lock_min', 15));
                    $user->failed_attempts = 0;
                }
                $user->saveQuietly();   // عدّاد أمني — بلا تدقيق ولا إصدارات
            }
            // بصمة المحاولة الفاشلة في التدقيق — تُعرض في مركز الأمان
            // البريد يُحفظ كاملاً (٢٩٠) لا مبتوراً عند ٦٠ — أثرٌ أمني يُقرأ لاحقاً
            hub_audit('دخول فاشل', null, null, null,
                ['user_id' => $user?->id, 'name' => substr($data['email'], 0, 290)]);

            return $fail();
        }

        $u = Auth::user();

        // مصادقة ثنائية مفعّلة؟ أوقف الدخول حتى إدخال الرمز
        if ($u->totp_enabled) {
            Auth::logout();
            $r->session()->put('2fa:uid', $u->id);
            $r->session()->regenerate();

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

        if (! \App\Support\Totp::verify((string) $u->totp_secret_cipher, (string) $r->input('code'))) {
            return back()->withErrors(['code' => 'الرمز غير صحيح أو انتهى — جرّب الرمز الحالي في التطبيق']);
        }

        $r->session()->forget('2fa:uid');
        Auth::login($u, remember: true);

        return $this->finishLogin($u, $r);
    }

    /** إتمام الدخول الموحد: تصفير العدادات + سجل الجلسة + تدوير المعرف */
    protected function finishLogin(User $u, Request $r)
    {
        $u->forceFill([
            'failed_attempts' => 0,
            'locked_until'    => null,
            'last_login_at'   => now(),
            'last_login_ip'   => $r->ip(),
        ])->saveQuietly();

        // حارس الدخول: يتعلم العناوين المعتادة ويرصد الغريب وخارج الدوام
        \App\Support\LoginSentry::inspect($u, (string) $r->ip());

        \App\Models\SessionLog::create([
            'user_id'      => $u->id,
            'device'       => substr((string) $r->header('X-Device', $r->userAgent()), 0, 200),
            'ip'           => $r->ip(),
            'user_agent'   => substr((string) $r->userAgent(), 0, 400),
            'started_at'   => now(),
            'last_seen_at' => now(),
        ]);

        $r->session()->regenerate();

        // شاشة البداية من تفضيل المستخدم — والرابط المقصود قبل الدخول يفوز عليها
        return redirect()->intended(hub_home_url(auth()->user()));
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login');
    }
}
