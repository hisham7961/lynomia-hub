<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** الملف الشخصي: بيانات المستخدم نفسه + تغيير كلمة مروره بنفسه */
class ProfileController extends Controller
{
    public function edit(Request $r)
    {
        // سر مصادقة ثنائية معلّق (لم يؤكد بعد) — يبقى في الجلسة حتى التأكيد
        $pending = $r->session()->get('2fa:pending');

        return view('profile', [
            'u' => auth()->user(),
            'pending2fa' => $pending,
            'otpUri' => $pending ? \App\Support\Totp::uri($pending, auth()->user()->email, (string) setting('app.name', 'Lynomia Hub')) : null,
            'tokens' => \App\Models\ApiToken::where('user_id', auth()->id())->orderByDesc('created_at')->get(),
        ]);
    }

    /** إنشاء مفتاح API — يظهر النص الكامل مرة واحدة فقط */
    public function tokenStore(Request $r)
    {
        $d = $r->validate([
            'tname'   => ['required', 'string', 'max:110'],
            'tdays'   => ['nullable', 'integer', 'min:1', 'max:730'],
            'tscopes' => ['nullable', 'string', 'max:1900'],
            'tips'    => ['nullable', 'string', 'max:390'],
        ], [], ['tname' => 'اسم المفتاح', 'tdays' => 'أيام الصلاحية', 'tscopes' => 'النطاقات', 'tips' => 'قائمة IP']);

        $plain = 'lyn_' . \Illuminate\Support\Str::random(44);
        \App\Models\ApiToken::create([
            'user_id'     => auth()->id(),
            'name'        => $d['tname'],
            'token_hash'  => hash('sha256', $plain),
            'expires_at'  => filled($d['tdays'] ?? null) ? now()->addDays((int) $d['tdays']) : null,
            'scopes'      => trim((string) ($d['tscopes'] ?? '')) ?: null,
            'allowed_ips' => trim((string) ($d['tips'] ?? '')) ?: null,
            'created_at'  => now(),
        ]);

        return back()->with('ok', 'أُنشئ المفتاح — انسخه الآن فلن يظهر مرة أخرى')->with('newtoken', $plain);
    }

    /** تدوير مفتاح: قيمة جديدة بنفس الاسم والنطاقات — القديمة تتوقف فوراً */
    public function tokenRotate(string $id)
    {
        $t = \App\Models\ApiToken::where('user_id', auth()->id())->findOrFail($id);
        $plain = 'lyn_' . \Illuminate\Support\Str::random(44);
        $t->forceFill(['token_hash' => hash('sha256', $plain), 'created_at' => now(), 'last_used_at' => null])->save();

        return back()->with('ok', 'دُوّر المفتاح «' . $t->name . '» — القيمة القديمة توقفت فوراً، انسخ الجديدة الآن')
                     ->with('newtoken', $plain);
    }

    /** إبطال مفتاح API */
    public function tokenRevoke(string $id)
    {
        \App\Models\ApiToken::where('user_id', auth()->id())->where('id', $id)->delete();

        return back()->with('ok', 'أُبطل المفتاح فوراً');
    }

    /** بدء تفعيل المصادقة الثنائية: توليد سر وعرضه للمستخدم */
    public function twofaStart(Request $r)
    {
        $r->session()->put('2fa:pending', \App\Support\Totp::secret());

        return redirect()->route('profile.edit')->with('ok', 'أدخل السر في تطبيق المصادقة ثم أكّد بالرمز');
    }

    /** تأكيد التفعيل برمز صحيح من التطبيق */
    public function twofaConfirm(Request $r)
    {
        $secret = (string) $r->session()->get('2fa:pending');
        abort_unless($secret !== '', 422);
        if (! \App\Support\Totp::verify($secret, hub_str($r->input('code')))) {
            return back()->withErrors(['code' => 'الرمز غير صحيح — تأكد من إدخال السر في التطبيق وأن ساعة الجوال مضبوطة']);
        }

        $u = auth()->user();
        $u->totp_secret_cipher = $secret;
        $u->totp_enabled = true;
        $u->saveQuietly();
        $r->session()->forget('2fa:pending');

        return redirect()->route('profile.edit')->with('ok', '✅ فُعّلت المصادقة الثنائية — ستُطلب عند كل دخول');
    }

    /** تعطيل — يتطلب رمزاً صحيحاً */
    public function twofaDisable(Request $r)
    {
        $u = auth()->user();
        abort_unless($u->totp_enabled, 422);
        if (! \App\Support\Totp::verify((string) $u->totp_secret_cipher, hub_str($r->input('code')))) {
            return back()->withErrors(['code' => 'الرمز غير صحيح']);
        }

        $u->totp_secret_cipher = null;
        $u->totp_enabled = false;
        $u->saveQuietly();

        return redirect()->route('profile.edit')->with('ok', 'عُطّلت المصادقة الثنائية');
    }

    public function update(Request $r)
    {
        $d = $r->validate([
            'name'      => ['required', 'string', 'max:160'],
            'phone'     => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:160'],
        ]);

        auth()->user()->fill($d)->save();

        return redirect()->route('profile.edit')->with('ok', 'حُفظت بياناتك');
    }

    /**
     * **وجهات التنبيه الشخصية** — الحلقةُ التي كانت ميتة: عاملُ التسليم يقرأ
     * `notify_prefs` (تلجرام المستخدم وبريده البديل) منذ بنائه، ولا واجهةَ
     * كانت تكتبها — فمحطةُ «تفضيل المستخدم» في سلسلة الوجهات لم تعمل يوماً.
     */
    public function notifyPrefs(Request $r)
    {
        $d = $r->validate([
            'tg'    => ['nullable', 'string', 'max:60', 'regex:/^-?\d+$/'],
            'email' => ['nullable', 'email', 'max:200'],
        ], ['tg.regex' => 'معرف تلجرام رقمٌ (موجب للمحادثات، سالب للقنوات والمجموعات) — يستخرجه دليل مركز المراسلة'],
           ['tg' => 'معرف تلجرام', 'email' => 'البريد البديل']);

        $u = auth()->user();
        $prefs = is_array($u->notify_prefs) ? $u->notify_prefs : [];
        foreach (['tg', 'email'] as $k) {
            $v = trim((string) ($d[$k] ?? ''));
            if ($v === '') unset($prefs[$k]); else $prefs[$k] = $v;
        }
        $u->forceFill(['notify_prefs' => $prefs])->save();

        return redirect()->route('profile.edit')->with('ok', 'حُفظت وجهات تنبيهك — تنبيهاتك القادمة ستسلك الوجهة الجديدة');
    }

    public function password(Request $r)
    {
        $r->validate([
            'current'  => ['required', 'current_password'],
            'password' => ['required', 'confirmed', password_rules()],
        ], [
            'current.current_password' => 'كلمة المرور الحالية غير صحيحة',
            'password.confirmed'       => 'تأكيد كلمة المرور غير مطابق',
        ], [
            'current'  => 'كلمة المرور الحالية',
            'password' => 'كلمة المرور الجديدة',
        ]);

        $u = auth()->user();
        $u->password = $r->input('password');            // cast hashed يتكفّل بالتجزئة
        $u->password_changed_at = now();
        $u->save();

        // تدوير معرّف الجلسة بعد تغيير كلمة المرور
        $r->session()->regenerate();

        return redirect()->route('profile.edit')->with('ok', 'غُيّرت كلمة المرور بنجاح');
    }
}
