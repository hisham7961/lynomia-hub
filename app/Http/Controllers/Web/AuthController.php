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

        if (! Auth::attempt($data, remember: true)) {
            if ($user) {
                $user->failed_attempts = ((int) $user->failed_attempts) + 1;
                if ($user->failed_attempts >= (int) setting('auth.max_fail', 5)) {
                    $user->locked_until    = now()->addMinutes((int) setting('auth.lock_min', 15));
                    $user->failed_attempts = 0;
                }
                $user->saveQuietly();   // عدّاد أمني — بلا تدقيق ولا إصدارات
            }
            return $fail();
        }

        $u = Auth::user();
        $u->forceFill([
            'failed_attempts' => 0,
            'locked_until'    => null,
            'last_login_at'   => now(),
            'last_login_ip'   => $r->ip(),
        ])->saveQuietly();

        \App\Models\SessionLog::create([
            'user_id'      => $u->id,
            'device'       => substr((string) $r->header('X-Device', $r->userAgent()), 0, 200),
            'ip'           => $r->ip(),
            'user_agent'   => substr((string) $r->userAgent(), 0, 400),
            'started_at'   => now(),
            'last_seen_at' => now(),
        ]);

        $r->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login');
    }
}
