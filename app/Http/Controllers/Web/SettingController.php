<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** إعدادات النظام بدون مبرمج — مجموعات مصنفة بأنواع حقول */
class SettingController extends Controller
{
    /** المجموعات: key => [label, type] — الأنواع: text · number · color · img · onoff · ta */
    protected array $groups = [
        '🏷️ الهوية' => [
            'app.name'     => ['اسم النظام', 'text'],
            'app.company'  => ['اسم الشركة الأم', 'text'],
            'app.currency' => ['العملة الافتراضية', 'text'],
            'app.logo'     => ['الشعار (يظهر في القائمة وشاشة الدخول)', 'img'],
            'app.color'    => ['اللون الأساسي للنظام', 'color'],
        ],
        '🔐 الدخول والأمان' => [
            'auth.pw_min'      => ['الحد الأدنى لطول كلمة المرور', 'number'],
            'auth.max_fail'    => ['محاولات فاشلة قبل قفل الحساب', 'number'],
            'auth.lock_min'    => ['مدة القفل (دقائق)', 'number'],
            'auth.session_min' => ['مدة الجلسة (دقائق — فارغ = افتراضي النظام)', 'number'],
        ],
        '🕗 ساعات العمل والوصول' => [
            'sec.hours_on'       => ['تفعيل نظام ساعات العمل للموظفين', 'onoff'],
            'sec.hours_start'    => ['بداية الدوام (HH:MM — افتراضي 08:00)', 'text'],
            'sec.hours_end'      => ['نهاية الدوام: بعدها الدخول يُعد «خارج الدوام» (افتراضي 16:00)', 'text'],
            'sec.strict_from'    => ['بداية الوضع الصارم للموظفين (افتراضي 17:00)', 'text'],
            'sec.strict_minutes' => ['خارج الدوام: تجديد الجلسة كل كم دقيقة (افتراضي 10)', 'number'],
            'sec.strict_files'   => ['منع نقل الملفات خارج الدوام (تنزيل/رفع/تصدير)', 'onoff'],
        ],
        '📎 الملفات' => [
            'files.max_kb' => ['أقصى حجم للملف المرفوع (كيلوبايت — فارغ = 512000)', 'number'],
        ],
        '🔔 التنبيهات الخارجية' => [
            'notify.tg_token' => ['توكن بوت تلجرام (يُخزن مشفراً)', 'pass'],
            'notify.tg_chat'  => ['معرّف قناة/مجموعة تلجرام الافتراضي', 'text'],
        ],
        '🔏 الموافقات المُلزِمة' => [
            'approval.rules' => ['وحدات محمية — وحدة:أحرف (e تعديل · d حذف) مثال: vault:ed fin:d', 'text'],
        ],
        '📜 العقود والتوقيع' => [
            'contracts.doc_no_format' => ['صيغة ترقيم العقود — {YEAR} السنة و{SEQ} تسلسل سنوي (الافتراضي CTR-{YEAR}-{SEQ})', 'text'],
            'esign.link_days_default' => ['صلاحية رابط التوقيع الافتراضية بالأيام (فارغ = بلا انتهاء)', 'text'],
            'contracts.approval_steps' => ['مراحل اعتماد العقود قبل الإرسال — JSON مرتب: [{"kind":"مالية","min_value":10000,"approver_id":"معرف مستخدم أو فارغ للمالكين","label":"وصف"}] (فارغ = بلا موافقات)', 'text'],
        ],
        '🚧 وضع الصيانة' => [
            'maintenance.on'  => ['تفعيل وضع الصيانة (الدخول للمالكين فقط)', 'onoff'],
            'maintenance.msg' => ['رسالة تظهر للمستخدمين أثناء الصيانة', 'text'],
        ],
        '🎫 دعم العملاء (SLA)' => [
            'sla.rules' => ['قواعد SLA — أولوية:ساعات_الاستجابة:ساعات_الحل (مثال: عاجلة:1:8 عالية:4:24 افتراضي:8:72)', 'text'],
        ],
        '🔗 ربط أودو — عرض فقط' => [
            'odoo.url'  => ['رابط خادم أودو (مثال: https://mycompany.odoo.com)', 'text'],
            'odoo.db'   => ['اسم قاعدة البيانات', 'text'],
            'odoo.user' => ['بريد مستخدم القراءة', 'text'],
            'odoo.key'  => ['مفتاح API (من إعدادات أمان الحساب في أودو)', 'pass'],
        ],
    ];

    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403);
    }

    public function edit()
    {
        $this->gate();
        $flat = collect($this->groups)->flatMap(fn ($g) => array_keys($g));
        $values = $flat->mapWithKeys(fn ($k) => [$k => setting($k, '')]);

        return view('settings.form', ['groups' => $this->groups, 'values' => $values]);
    }

    public function update(Request $r)
    {
        $this->gate();

        foreach ($this->groups as $items) {
            foreach ($items as $key => [$label, $type]) {
                $input = str_replace('.', '_', $key);

                if ($type === 'img') {
                    if ($r->hasFile($input)) {
                        $r->validate([$input => ['image', 'max:4096']]);
                        Setting::updateOrCreate(['key' => $key], ['value' => $r->file($input)->store('hub/branding', 'public')]);
                    } elseif ($r->boolean($input . '_clear')) {
                        Setting::where('key', $key)->delete();
                    }
                    continue;
                }

                $v = $type === 'onoff' ? ($r->boolean($input) ? '1' : '') : trim((string) $r->input($input, ''));
                if ($type === 'pass') {
                    if ($v === '') continue;                    // فارغ = إبقاء المفتاح المخزن
                    $v = 'enc:' . \Illuminate\Support\Facades\Crypt::encryptString($v);   // الأسرار مشفرة في القاعدة
                }
                Setting::updateOrCreate(['key' => $key], ['value' => $v]);
            }
        }

        Cache::forget('settings:all');
        Cache::forget('odoo:uid');

        return back()->with('ok', 'حُفظت الإعدادات وطُبّقت فوراً');
    }

    /** اختبار اتصال أودو — يعرض إصدار الخادم أو سبب الفشل */
    public function odooTest()
    {
        $this->gate();
        if (! \App\Support\Odoo::configured()) {
            return back()->withErrors(['odoo' => 'أكمل حقول أودو الأربعة أولاً ثم احفظ']);
        }
        try {
            $ver = \App\Support\Odoo::version();
            $uid = \App\Support\Odoo::uid();

            return back()->with('ok', "✅ الاتصال ناجح — أودو {$ver} · معرف المستخدم {$uid}");
        } catch (\Throwable $e) {
            return back()->withErrors(['odoo' => '❌ ' . $e->getMessage()]);
        }
    }
}
