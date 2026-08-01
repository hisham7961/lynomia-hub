<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * إعدادات النظام — كتالوجٌ لا نموذجٌ مسطّح.
 *
 * كانت الشاشة تسميةً وحقلاً: لا شرحَ لأثر المفتاح، ولا ذكرَ لافتراضيه، ولا
 * تحذيرَ عند ما يُبطل حارساً أمنياً — و**١٩ مفتاحاً حيّاً في الشيفرة بلا مدخلٍ
 * فيها إطلاقاً** (منها ساعات العمل التي تُحسب عليها لوحة القدرات كلها).
 * التعريف الآن واحدٌ في `config/hub_settings.php` يقرؤه العرض والحفظ والحارس.
 */
class SettingController extends Controller
{
    /**
     * تحقّقٌ من الصيغة لما تُفسده قيمةٌ صامتة: القيمة الفارغة مسموحةٌ دوماً
     * (تعني «عُد للافتراضي»)، وما عداها يُردّ بسببه بدل أن يُحفَظ ولا يسري.
     */
    protected const CHECKS = [
        'app.color'               => ['re' => '/^#[0-9a-fA-F]{6}$/', 'msg' => 'لونٌ سداسيٌّ بستّ خانات مثل #0E7C66 — الصيغة المختصرة تُحفَظ ولا تسري'],
        'sec.hours_start'         => ['re' => '/^([01]\d|2[0-3]):[0-5]\d$/', 'msg' => 'الوقت بصيغة HH:MM بأربع خانات مثل 08:00 — «8:00» تقلب المقارنة صمتاً'],
        'sec.hours_end'           => ['re' => '/^([01]\d|2[0-3]):[0-5]\d$/', 'msg' => 'الوقت بصيغة HH:MM بأربع خانات مثل 16:00'],
        'sec.strict_from'         => ['re' => '/^([01]\d|2[0-3]):[0-5]\d$/', 'msg' => 'الوقت بصيغة HH:MM بأربع خانات مثل 17:00'],
        'cost.weekend'            => ['re' => '/^[1-7](\s*[,،]\s*[1-7])*$/u', 'msg' => 'أرقام ١-٧ مفصولةً بفاصلة (٥ الجمعة · ٦ السبت · ٧ الأحد) — الصفر لا يطابق يوماً'],
        'esign.remind_days'       => ['re' => '/^\d+(\s*[,،]\s*\d+)*$/u', 'msg' => 'أيامٌ مفصولةٌ بفاصلة مثل 3,7'],
        'contracts.doc_no_format' => ['re' => '/\{SEQ\}$/', 'msg' => 'يجب أن تنتهي الصيغة بـ{SEQ} وإلا علَق إنشاء العقد الثاني'],
    ];

    /** المفاتيح التي تُخزَّن مشفّرةً ولا تُعرض بعد الحفظ */
    protected const SECRETS = ['notify.tg_token', 'odoo.key', 'quoteflow.pass'];

    /** كتالوج المجموعات: مجموعة => [مفتاح => وصفٌ كامل] */
    public static function catalog(): array
    {
        return (array) config('hub_settings.groups', []);
    }

    /** كل مفتاحٍ له مدخلٌ في الشاشة، مسطَّحاً */
    public static function exposedKeys(): array
    {
        return collect(self::catalog())->flatMap(fn ($g) => array_keys($g))->values()->all();
    }

    /** مفاتيحُ حالةٍ تديرها شاشةٌ أخرى أو أمر طرفية — مُعلَنةٌ بسببها لا متروكة */
    public static function internal(): array
    {
        return (array) config('hub_settings.internal', []);
    }

    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403);
    }

    public function edit()
    {
        $this->gate();

        $values = collect(self::exposedKeys())
            ->mapWithKeys(fn ($k) => [$k => in_array($k, self::SECRETS, true)
                ? (setting($k, '') !== '' ? '••••' : '')      // السرّ لا يعود للشاشة أبداً
                : setting($k, '')]);

        return view('settings.form', [
            'groups'   => self::catalog(),
            'values'   => $values,
            'internal' => self::internal(),
            'secrets'  => self::SECRETS,
        ]);
    }

    public function update(Request $r)
    {
        $this->gate();

        $errors = [];
        $changed = [];

        foreach (self::catalog() as $items) {
            foreach ($items as $key => $meta) {
                $input = str_replace('.', '_', $key);
                $type = (string) ($meta['type'] ?? 'text');

                if ($type === 'img') {
                    if ($r->hasFile($input)) {
                        $r->validate([$input => ['image', 'max:4096']]);
                        $this->put($key, $r->file($input)->store('hub/branding', 'public'), $changed);
                    } elseif ($r->boolean($input . '_clear')) {
                        Setting::where('key', $key)->delete();
                        $changed[] = $key;
                    }
                    continue;
                }

                if ($type === 'onoff') {
                    // **العلامة المُرسَلة**: مربّعٌ مطفأ لا يصل في الطلب أصلاً، فبغير
                    // هذه العلامة لا يُفرَّق بين «أطفأه» و«لم يرسل هذه الشاشة».
                    if (! $r->has($input . '__sent')) continue;
                    // ونخزّن «0» لا الفراغ: `setting()` تردّ الافتراضي عند الفراغ،
                    // فمفتاحٌ افتراضيه مفعَّل كان يستحيل إطفاؤه من الشاشة.
                    $this->put($key, $r->boolean($input) ? '1' : '0', $changed);
                    continue;
                }

                if ($type === 'pass') {
                    $v = trim((string) $r->input($input, ''));
                    if ($v === '' || $v === '••••') continue;     // فارغ = إبقاء المخزَّن
                    $this->put($key, 'enc:' . Crypt::encryptString($v), $changed);
                    continue;
                }

                if (! $r->has($input)) continue;                  // حفظٌ جزئي لا يمحو الباقي
                $v = trim((string) $r->input($input, ''));

                if ($v !== '' && isset(self::CHECKS[$key]) && ! preg_match(self::CHECKS[$key]['re'], $v)) {
                    $errors[$input] = ($meta['label'] ?? $key) . ' — ' . self::CHECKS[$key]['msg'];
                    continue;
                }
                if ($v !== '' && $type === 'ta' && json_decode($v, true) === null) {
                    $errors[$input] = ($meta['label'] ?? $key) . ' — JSON غير صالح، فلن يسري وسيعمل النظام بالافتراضي بصمت';
                    continue;
                }

                $this->put($key, $v, $changed);
            }
        }

        Cache::forget('settings:all');
        Cache::forget('odoo:uid');

        if ($changed) {
            // الإعدادات لا تمرّ بسمة التدقيق التلقائي — وتغييرُ حارسٍ أمني
            // يجب أن يبقى له أثرٌ باسم من غيّره ومتى.
            hub_audit('تعديل إعدادات النظام', 'settings', null, implode(' · ', array_slice($changed, 0, 8)),
                ['meta' => ['keys' => $changed]]);
        }

        if ($errors) {
            return back()->withErrors($errors)->withInput()
                ->with('warn', 'حُفظ ما صحّ، ورُدّ ما لا يسري: ' . count($errors) . ' قيمة');
        }

        return back()->with('ok', 'حُفظت الإعدادات وطُبّقت فوراً' . ($changed ? ' — ' . count($changed) . ' مفتاحاً' : ''));
    }

    protected function put(string $key, string $value, array &$changed = []): void
    {
        $old = Setting::where('key', $key)->value('value');
        $old = is_scalar($old) || $old === null ? (string) $old : json_encode($old, JSON_UNESCAPED_UNICODE);
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        if ($old !== $value) $changed[] = $key;
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
