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
        // **مفتاحُ طوارئٍ مفصول**: تجميدُ الرموز يمنع فتحَ قنواتِ وصولٍ برمجيّةٍ
        // جديدةٍ أثناء الحادثة (الإبطالُ يبقى متاحاً). يُرفع من مركز الأمان.
        abort_if((string) setting('security.freeze_tokens', '0') === '1', 423,
            'سكُّ مفاتيح API مجمَّدٌ الآن بمفتاح طوارئٍ أمنيّ — يُرفع من مركز الأمان');
        // سكُّ رمزٍ كامل الصلاحيات لسنة = اعتمادٌ دائم: يتطلب تأكيدَ الهوية أولاً (v2.399)
        if ($resp = hub_require_credential_stepup()) return $resp;
        $d = $r->validate([
            'tname'   => ['required', 'string', 'max:110'],
            'tdays'   => ['nullable', 'integer', 'min:1', 'max:730'],
            // (v2.399) نطاقٌ بوحدةٍ مجهولة أو عنوانٌ مشوَّه كانا يُخزَّنان صامتَين فيصير المفتاحُ «يرفض كلَّ شيء» بلا سبب
            'tscopes' => ['nullable', 'string', 'max:1900', function ($attr, $v, $fail) {
                foreach (preg_split('/[،,\s]+/u', (string) $v, -1, PREG_SPLIT_NO_EMPTY) as $part) {
                    [$mod, $ops] = array_pad(explode(':', $part, 2), 2, '');
                    if ($mod !== '*' && ! hub_mod($mod)) return $fail("نطاقٌ يسمّي وحدةً غير معروفة: {$mod}");
                    if ($ops !== '' && preg_match('/[^vaed]/', $ops)) return $fail("عمليات النطاق «{$part}» يجب أن تكون من الحروف v a e d فقط");
                }
            }],
            'tips'    => ['nullable', 'string', 'max:390', function ($attr, $v, $fail) {
                foreach (preg_split('/[،,\s]+/u', (string) $v, -1, PREG_SPLIT_NO_EMPTY) as $e) {
                    [$net, $bits] = array_pad(explode('/', $e, 2), 2, null);
                    $bin = @inet_pton((string) $net);
                    if ($bin === false) return $fail("عنوانٌ غير صالح: {$e}");
                    if ($bits !== null && (! ctype_digit((string) $bits) || (int) $bits > (strlen($bin) === 4 ? 32 : 128))) return $fail("قناعٌ غير صالح: {$e}");
                }
            }],
        ], [], ['tname' => 'اسم المفتاح', 'tdays' => 'أيام الصلاحية', 'tscopes' => 'النطاقات', 'tips' => 'قائمة IP']);

        $plain = 'lyn_' . \Illuminate\Support\Str::random(44);
        // انتهاءٌ افتراضيٌّ مضبوط: مفتاحٌ بلا مدّةٍ صار خطراً دائماً — إن لم
        // يُحدَّد يوماً، تُفرض العتبة القصوى (api.token_max_days، ٣٦٥ افتراضاً).
        // من أراد الدوام يضبطها إلى 0 من مركز الإعدادات صراحةً لا سهواً.
        $cap = (int) setting('api.token_max_days', 365);
        $days = filled($d['tdays'] ?? null) ? (int) $d['tdays'] : ($cap > 0 ? $cap : null);
        if ($cap > 0 && $days !== null) $days = min($days, $cap);
        $tok = \App\Models\ApiToken::create([
            'user_id'     => auth()->id(),
            'name'        => $d['tname'],
            'token_hash'  => hash('sha256', $plain),
            'expires_at'  => $days !== null ? now()->addDays($days) : null,
            'scopes'      => trim((string) ($d['tscopes'] ?? '')) ?: null,
            'allowed_ips' => trim((string) ($d['tips'] ?? '')) ?: null,
            'created_at'  => now(),
        ]);
        // سكُّ رمزٍ حدثٌ أمنيّ: كان يمرّ دون أثرٍ في السلسلة — يُدوَّن باسمه
        // ونطاقه ومدّته (لا قيمته)، وحقلُ full يُنبّه إلى رمزٍ كامل الصلاحيات.
        hub_audit('إنشاء مفتاح API', null, $tok->id, $d['tname'], ['after' => [
            'scopes' => $tok->scopes ?: '(كامل صلاحيات المستخدم)',
            'full' => $tok->scopes ? false : true,
            'expires' => $tok->expires_at?->toDateString() ?: 'دائم',
            'ips' => $tok->allowed_ips ?: '(أي عنوان)',
        ]]);

        return back()->with('ok', 'أُنشئ المفتاح — انسخه الآن فلن يظهر مرة أخرى')->with('newtoken', $plain);
    }

    /** تدوير مفتاح: قيمة جديدة بنفس الاسم والنطاقات — القديمة تتوقف فوراً */
    public function tokenRotate(string $id)
    {
        // التدويرُ يسكّ قيمةً جديدة — فيخضع لتجميد الرموز نفسِه (الإبطالُ لا يخضع)
        abort_if((string) setting('security.freeze_tokens', '0') === '1', 423,
            'تدويرُ مفاتيح API مجمَّدٌ الآن بمفتاح طوارئٍ أمنيّ — يُرفع من مركز الأمان');
        if ($resp = hub_require_credential_stepup()) return $resp;
        $t = \App\Models\ApiToken::where('user_id', auth()->id())->findOrFail($id);
        $plain = 'lyn_' . \Illuminate\Support\Str::random(44);

        /*
         * **والمدّةُ تُجدَّد مع القيمة.**
         *
         * `created_at` كان يُعاد إلى الآن و`expires_at` يبقى كما هو — فمفتاحٌ
         * انتهت مدّتُه يُدوَّر فيُسلَّم للمستخدم بقيمةٍ جديدة ورسالةِ نجاح تقول
         * «انسخ الجديدة الآن»، **وهو ميتٌ لحظةَ نسخِه**. والتدويرُ فعلُ إحياءٍ
         * لا فعلُ تسمية: تُمدَّد المدّةُ بطولها الأصليّ من الآن، ويبقى المفتاحُ
         * الدائم دائماً.
         */
        $fill = ['token_hash' => hash('sha256', $plain), 'created_at' => now(), 'last_used_at' => null];
        if ($t->expires_at) {
            $span = max(1, (int) round(
                \Illuminate\Support\Carbon::parse($t->created_at)->diffInDays(
                    \Illuminate\Support\Carbon::parse($t->expires_at), false)));
            $fill['expires_at'] = now()->addDays(min(730, $span));
        }
        $t->forceFill($fill)->save();
        hub_audit('تدوير مفتاح API', null, $t->id, $t->name);

        return back()->with('ok', 'دُوّر المفتاح «' . $t->name . '» — القيمة القديمة توقفت فوراً، انسخ الجديدة الآن')
                     ->with('newtoken', $plain);
    }

    /** إبطال مفتاح API */
    public function tokenRevoke(string $id)
    {
        $t = \App\Models\ApiToken::where('user_id', auth()->id())->where('id', $id)->first();
        if ($t) {
            $name = $t->name;
            $t->delete();
            hub_audit('إبطال مفتاح API', null, $id, $name);
        }

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
        if (! \App\Support\Totp::verifyOnce($secret, hub_str($r->input('code')), '2fa-confirm:' . auth()->id())) {
            return back()->withErrors(['code' => 'الرمز غير صحيح — تأكد من إدخال السر في التطبيق وأن ساعة الجوال مضبوطة']);
        }

        $u = auth()->user();
        $u->totp_secret_cipher = $secret;
        $u->totp_enabled = true;
        $u->saveQuietly();
        $r->session()->forget('2fa:pending');
        // حدثٌ أمنيّ قانونيّ (MFA_ENABLED) — كان يمرّ بلا أثر (v2.399)
        hub_audit('تفعيل التحقق بخطوتين', 'users', $u->id, $u->name, ['after' => ['totp_enabled' => true, 'by' => 'صاحب الحساب']]);

        return redirect()->route('profile.edit')->with('ok', '✅ فُعّلت المصادقة الثنائية — ستُطلب عند كل دخول');
    }

    /** تعطيل — يتطلب رمزاً صحيحاً */
    public function twofaDisable(Request $r)
    {
        $u = auth()->user();
        abort_unless($u->totp_enabled, 422);
        if (! \App\Support\Totp::verifyOnce((string) $u->totp_secret_cipher, hub_str($r->input('code')), '2fa-disable:' . $u->id)) {
            return back()->withErrors(['code' => 'الرمز غير صحيح']);
        }

        $u->totp_secret_cipher = null;
        $u->totp_enabled = false;
        $u->saveQuietly();
        hub_audit('إطفاء التحقق بخطوتين', 'users', $u->id, $u->name, ['after' => ['totp_enabled' => false, 'by' => 'صاحب الحساب']]);

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
        // تدوير رمز «تذكّرني»: كعكاتُه على كل الأجهزة (٤٠٠ يوم) تموت فوراً — وإلا
        // بقيت بيد المهاجم صالحةً رغم تغيير الكلمة، فلا يتحقق غرضُ التغيير الأمني.
        $u->save();

        // وجلساتُ الأجهزة الأخرى الحيّة تُوسم منتهية فيطردها SessionSentry مع طلبها
        // التالي، ويُدوَّر «تذكّرني» — بالسكّة الواحدة (Sessions::revokeAll).
        \App\Support\Sessions::revokeAll($u, (string) $r->session()->get('hub.sl', '') ?: null);

        // تدوير معرّف الجلسة الحالية بعد تغيير كلمة المرور
        $r->session()->regenerate();

        // تغييرُ كلمة المرور حدثٌ أمنيّ — يُدوَّن في السلسلة (بلا قيمةٍ طبعاً)
        // كي يُرى في مركز الأمن وخطِّ المستخدم الزمني، وقد كان يمرّ صامتاً.
        hub_audit('تغيير كلمة المرور', null, null, $u->name);

        return redirect()->route('profile.edit')->with('ok', 'غُيّرت كلمة المرور بنجاح');
    }
}
