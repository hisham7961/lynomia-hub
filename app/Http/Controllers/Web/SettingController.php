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
        '📎 الملفات' => [
            'files.max_kb' => ['أقصى حجم للملف المرفوع (كيلوبايت — فارغ = 512000)', 'number'],
        ],
        '🔔 التنبيهات الخارجية' => [
            'notify.tg_token' => ['توكن بوت تلجرام', 'text'],
            'notify.tg_chat'  => ['معرّف قناة/مجموعة تلجرام الافتراضي', 'text'],
        ],
        '🔏 الموافقات المُلزِمة' => [
            'approval.rules' => ['وحدات محمية — وحدة:أحرف (e تعديل · d حذف) مثال: vault:ed fin:d', 'text'],
        ],
        '🚧 وضع الصيانة' => [
            'maintenance.on'  => ['تفعيل وضع الصيانة (الدخول للمالكين فقط)', 'onoff'],
            'maintenance.msg' => ['رسالة تظهر للمستخدمين أثناء الصيانة', 'text'],
        ],
    ];

    protected function gate(): void
    {
        abort_unless(auth()->user()?->role?->is_owner, 403);
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
                Setting::updateOrCreate(['key' => $key], ['value' => $v]);
            }
        }

        Cache::forget('settings:all');

        return back()->with('ok', 'حُفظت الإعدادات وطُبّقت فوراً');
    }
}
