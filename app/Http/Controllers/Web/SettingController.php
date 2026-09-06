<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\ConnectionProbe;
use App\Support\Settings;
use Illuminate\Http\Request;

/**
 * إعدادات النظام — كتالوجٌ لا نموذجٌ مسطّح.
 *
 * كانت الشاشة تسميةً وحقلاً: لا شرحَ لأثر المفتاح، ولا ذكرَ لافتراضيه، ولا
 * تحذيرَ عند ما يُبطل حارساً أمنياً — و**١٩ مفتاحاً حيّاً في الشيفرة بلا مدخلٍ
 * فيها إطلاقاً** (منها ساعات العمل التي تُحسب عليها لوحة القدرات كلها).
 * التعريف الآن واحدٌ في `config/hub_settings.php` يقرؤه العرض والحفظ والحارس.
 *
 * (WP-9.1) وقواعدُ التحقّق وقائمةُ الأسرار **انتقلتا إلى الكتالوج**: كانتا
 * ثابتَين منسوخَين هنا بجانبه، فقاعدةٌ تُضاف لا يعرفها إلا الحفظ، ومفتاحُ سرٍّ
 * يُنسى فيُكتب نصّاً صريحاً من الطرفية بينما شاشتُه تشفّره (`n8n.key`).
 * فالقارئُ الواحد الآن `App\Support\Settings`.
 */
class SettingController extends Controller
{
    /**
     * (WP-9.3) مفتاحُ الجلسة الذي تُسلَّم فيه خطّةُ المعاينة إلى خطوة التأكيد —
     * لا حقولٌ مخفيّة: حقلٌ مخفيّ يعيد رسمَ السرِّ الذي كُتب في الصفحة.
     */
    public const PREVIEW_SESSION = 'settings.preview';

    /**
     * تحقّقٌ من الصيغة لما تُفسده قيمةٌ صامتة: القيمة الفارغة مسموحةٌ دوماً
     * (تعني «عُد للافتراضي»)، وما عداها يُردّ بسببه بدل أن يُحفَظ ولا يسري.
     * **مصدرُها `validation` في الكتالوج** — لا نسخةَ ثانية هنا.
     */
    protected static function checks(): array
    {
        return Settings::checks();
    }

    /** المفاتيح التي تُخزَّن مشفّرةً ولا تُعرض بعد الحفظ — من `sensitive` في الكتالوج */
    public static function secrets(): array
    {
        return Settings::secrets();
    }

    /** كتالوج المجموعات: مجموعة => [مفتاح => وصفٌ كامل] */
    public static function catalog(): array
    {
        return Settings::catalog();
    }

    /** كل مفتاحٍ له مدخلٌ في الشاشة، مسطَّحاً */
    public static function exposedKeys(): array
    {
        return Settings::exposedKeys();
    }

    /** مفاتيحُ حالةٍ تديرها شاشةٌ أخرى أو أمر طرفية — مُعلَنةٌ بسببها لا متروكة */
    public static function internal(): array
    {
        return Settings::internal();
    }

    /**
     * قيمةٌ صالحةٌ للعرض في مدخل.
     *
     * عمود `settings.value` مصبوبٌ `array`، والمنصِّب يبذر خرائط JSON حقيقية
     * (`finance.accounts` و`notify.quiet`) — فتعود القيمة **مصفوفةً** وتُطبع في
     * مدخلٍ نصّي فتسقط الشاشة بـ`htmlspecialchars(): array given` على **كل
     * تنصيبٍ جديد**. تُعاد هنا نصّاً JSON مقروءاً وقابلاً للتحرير،
     * وقارئا الخريطة (المالية والرواتب) يقبلان النصّ والمصفوفة معاً.
     */
    public static function displayValue($v): string
    {
        if ($v === null || is_bool($v)) return $v ? '1' : '';
        if (is_scalar($v)) return (string) $v;

        return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403);
    }

    public function edit()
    {
        $this->gate();

        $secrets = self::secrets();
        $values = collect(self::exposedKeys())
            ->mapWithKeys(fn ($k) => [$k => in_array($k, $secrets, true)
                ? (setting($k, '') !== '' ? '••••' : '')      // السرّ لا يعود للشاشة أبداً
                : self::displayValue(setting($k, ''))]);

        // (WP-9.1) «ما الساري؟ ومن أين؟» لكل مفتاح — ولا قيمةَ بيئةٍ ولا سرٍّ فيها
        $facts = collect(self::exposedKeys())->mapWithKeys(fn ($k) => [$k => Settings::effective($k)]);

        return view('settings.form', [
            'groups'   => self::catalog(),
            'values'   => $values,
            'internal' => self::internal(),
            'secrets'  => $secrets,
            'facts'    => $facts,
            'dash'     => Settings::dashboard(),
            // (WP-9.2 · §7.6) «من غيّره آخر مرة ومتى» — استعلامان لكل الشاشة
            // (‏`MAX(id)` لكل مفتاح ثم صفوفُها)، وارتدادٌ محدودٌ إلى `audits`
            // للمفاتيح التي غُيّرت قبل وجود جدول التاريخ.
            'lastBy'   => Settings::lastChanges(self::exposedKeys()),
            // (WP-9.4 · §7.13) بطاقةُ الرايات **قراءةً فقط**: حالةٌ ورابطٌ إلى مالكها
            'runtimeFlags' => self::runtimeFlags(),
            // (WP-9.4 · §7.12) خطّةُ استيرادٍ تنتظر التأكيد، إن وُجدت
            'importPlan'   => session(self::IMPORT_SESSION),
            // (WP-9.3 · §7.7) خطّةُ معاينةٍ تنتظر التأكيد، إن وُجدت — تُقرأ ولا
            // تُستهلك هنا: استهلاكُها عند التطبيق وحدَه (‏`pull` في `update`)
            'preview'      => session(self::PREVIEW_SESSION),
        ]);
    }

    /**
     * (WP-9.4 · §7.13) حالُ راياتِ التشغيل الخمس ومَن يملك تبديلَها.
     *
     * تُقرأ من `Settings::RUNTIME_FLAGS` — تعريفٌ واحد — وتُعرَض بلا أي مفتاح
     * تبديل: رفعُ قفل الطوارئ من هنا يعني تخطّي التأكيد والتصعيد والرسالة التي
     * بُنيت في مركز الأمان، وتبديلُ الصيانة يعني تخطّي `hub_require_ops_stepup`.
     *
     * @return array<int, array{key:string, label:string, on:bool, route:string, url:string}>
     */
    protected static function runtimeFlags(): array
    {
        $out = [];
        foreach (Settings::RUNTIME_FLAGS as $key => [$label, $route]) {
            $out[] = [
                'key' => $key, 'label' => $label, 'on' => Settings::flagOn($key),
                'route' => $route,
                'url' => \Illuminate\Support\Facades\Route::has($route) ? route($route) : '',
            ];
        }

        return $out;
    }

    /**
     * (WP-9.2 · critic #4) **الحفظُ صار دفعةً على الكاتب الواحد**: جسمُ
     * `put()` القديم — قراءةُ القديم والكتابةُ ورصدُ التغيّر وبصمةُ الأسرار —
     * انتقل بحرفه إلى `Settings::put`، ومعه ما كان هنا وحدَه: إبطالُ الخبيئة
     * وقيدُ التدقيق. فلا نسختان لمنطق الفرق، ولا كاتبان جنباً إلى جنب.
     * وتبقى الدفعةُ **قيدَ تدقيقٍ واحداً** بـ`after._keys` كما كانت.
     */
    public function update(Request $r)
    {
        $this->gate();

        $errors = [];

        // (WP-9.3) **تأكيدُ معاينة**: الحمولةُ تُقرأ من الجلسة لا من الطلب —
        // نمطُ `ImportController` (رفعٌ → فرقٌ → تأكيدٌ → تطبيق)، و`pull` تستهلكها
        // مرّةً واحدة فلا تُطبَّق دفعةٌ مرّتين بزرِّ رجوعٍ أو إعادةِ إرسال.
        $confirming = $r->boolean('_preview');
        $held = $confirming ? $r->session()->pull(self::PREVIEW_SESSION) : null;

        // ولا يُقال «حُفظت» لما لم يُحفظ: الحمولةُ تُستهلَك مرّةً، فالتأكيدُ بعد
        // انتهاء الجلسة أو بعد تطبيقٍ في لسانٍ آخر يصل **فارغاً** — وردُّ نجاحٍ
        // عن صفرِ كتابةٍ يُخرج المشغّلَ مطمئنّاً إلى ضبطٍ لم يقع. والطلبُ لا يحمل
        // بديلاً: النموذجُ الأصليُّ لم يُرسَل مع زرِّ التأكيد.
        if ($confirming && ! is_array($held)) {
            return back()->with('warn', 'انتهت صلاحيةُ المعاينة أو طُبّقت من قبل، ولم يُكتب شيء — '
                . 'أعِد إدخال ما تريد تغييره ثم عايِن وأكّد من جديد.');
        }

        $map = $confirming ? (array) ($held['map'] ?? []) : $this->intended($r, $errors);

        // **مجموعاتُ التبعية تُتحقَّق قبل أيّ كتابة** (§7.9): الشاشةُ تحفظ فرادى،
        // والمجموعةُ نصفَ المضبوطة تعمل حيّاً — `MailSettings::apply` تُشعَل
        // بـ`mail.host` وحدَه ثم تكتب مستخدماً فارغاً فوق `.env`. فالرفضُ كلّيٌّ
        // هنا لا جزئيّ: نصفُ مجموعةٍ محفوظٌ أسوأُ من لا شيء.
        if ($dep = Settings::dependencyErrors($map)) {
            return back()->withErrors($dep)->withInput($this->inputWithoutSecrets($r))
                ->with('warn', 'لم يُحفظ شيء — ' . implode(' ', $dep));
        }

        $changed = [];
        Settings::batch('screen', function () use ($r, $confirming, $map, &$errors, &$changed) {
            // الصورةُ خارج الخطّة المقصودة: قيمتُها ملفٌّ يُخزَّن على القرص، فلا
            // تُعاين ولا تُسلَّم في جلسة — وتُحفظ من الطلب المباشر وحدَه
            if (! $confirming) $this->storeImages($r, $errors, $changed);
            foreach ($map as $key => $value) {
                // الكاتبُ يتحقّق مرّةً أخرى **عند الكتابة** (وهو موضعُ الحقّ)، ورفضُه
                // يُجمع كسائر الأخطاء لا يُرمى: خطّةٌ صحّت عند المعاينة وبطلت عند
                // التأكيد (تبدّلت قاعدةُ الكتالوج بينهما) تستحقّ رسالةً لا صفحةَ ٥٠٠.
                try {
                    if (Settings::put($key, $value, 'screen')) $changed[] = $key;
                } catch (\InvalidArgumentException $e) {
                    $errors[str_replace('.', '_', $key)] = $e->getMessage();
                }
            }
        });

        if ($errors) {
            return back()->withErrors($errors)->withInput($this->inputWithoutSecrets($r))
                ->with('warn', 'حُفظ ما صحّ، ورُدّ ما لا يسري: ' . count($errors) . ' قيمة');
        }

        // «لا تغيير» ليس «حُفظ»: حفظُ ٩٥ مفتاحاً بقيمها نفسِها لا يكتب حرفاً،
        // فيُقال ذلك بدل شارةِ نجاحٍ توحي بأنّ شيئاً تبدّل.
        return back()->with('ok', $changed === []
            ? 'لا تغيير — كلُّ ما أُرسل يطابق القيمةَ السارية، فلم يُكتب شيء'
            : 'حُفظت الإعدادات وطُبّقت فوراً — ' . count($changed) . ' مفتاحاً');
    }

    /**
     * (WP-9.3 · §7.7 · §18) **معاينةٌ جافّة قبل الحفظ**: `A → B` لكل مفتاحٍ
     * يتبدّل مع وسمِ عالي الخطورة — ولا كتابةَ ولا أثرَ ولا إبطالَ خبيئة. §18
     * يشترط للإعداد الخطر خطوةَ تأكيدٍ **تعرض الأثر**، لا «هل أنت متأكد؟».
     *
     * والحمولةُ تُسلَّم عبر الجلسة لا في حقولٍ مخفيّة: حقلٌ مخفيّ يعيد رسمَ
     * السرِّ الذي كُتب في الصفحة — وهو ما لا يخرج من هنا أبداً.
     */
    public function preview(Request $r)
    {
        $this->gate();

        // «اصرف النظر» — إسقاطُ المعاينة المعلّقة بلا مسارٍ ثانٍ لها
        if ($r->boolean('drop')) {
            $r->session()->forget(self::PREVIEW_SESSION);

            return back()->with('ok', 'أُلغيت المعاينة — لم يُكتب شيءٌ أصلاً');
        }

        $errors = [];
        $map = $this->intended($r, $errors);

        if ($dep = Settings::dependencyErrors($map)) {
            return back()->withErrors($dep)->withInput($this->inputWithoutSecrets($r))
                ->with('warn', 'لا معاينةَ لخطّةٍ لا تسري — ' . implode(' ', $dep));
        }

        $rows = Settings::diff($map);
        $r->session()->put(self::PREVIEW_SESSION, [
            'at'    => now()->toIso8601String(),
            'map'   => $map,
            'rows'  => $rows,
            'risky' => count(array_filter($rows, fn ($x) => $x['risky'])),
        ]);

        return back()->withErrors($errors)->withInput($this->inputWithoutSecrets($r));
    }

    /**
     * مدخلاتُ الطلب **بلا حقول الأسرار** لإعادة التعبئة (`old()`).
     *
     * الشاشةُ لا تعيد رسمَ حقلِ سرٍّ أصلاً (‏`type=password` بلا `value`)، فحفظُ
     * النصّ الصريح في `_old_input` تعريضٌ بلا مقابل: يبقى في الجلسة إلى الطلب
     * التالي ولا يقرؤه أحد. والقاعدةُ مطلقة — لا يخرج السرُّ إلى مخزنٍ لا يحتاجه.
     */
    protected function inputWithoutSecrets(Request $r): array
    {
        $drop = ['_preview'];
        foreach (Settings::secrets() as $k) $drop[] = str_replace('.', '_', $k);

        return $r->except($drop);
    }

    /**
     * (WP-9.3 · §7.8 · critic #7) **استعادةُ الافتراضي — كتابةٌ لا حذف.**
     *
     * لمفتاحٍ واحد (`key`) أو لمجموعةٍ كاملة (`group`)، بتأكيدٍ في الشاشة
     * وتصعيدِ هويةٍ لعالي الخطورة (§18) وأثرٍ بفعله المستقلّ. وحذفُ الصفّ —
     * الاقتراحُ الأول — كان يُعيد إشعالَ `sec.hours_on`/`sec.strict_files`
     * لأنّ افتراضيَّهما «مُشغَّل» و`setting()` تسقط إليه عند غياب الصفّ.
     */
    public function restore(Request $r)
    {
        $this->gate();

        $key = trim(hub_str($r->input('key', '')));
        $group = trim(hub_str($r->input('group', '')));
        $catalog = self::catalog();
        // مفتاحٌ تملكه شاشةٌ أخرى (‏`readonly`) لا يُستعاد من هنا كما لا يُكتب:
        // إعادةُ `maintenance.on` إلى افتراضيّه رفعٌ للصيانة بلا رسالتها ولا تصعيدِها
        $writable = fn (string $k) => empty($catalog[$this->groupOf($k)][$k]['readonly'] ?? null);

        if ($key !== '' && Settings::entry($key) !== null && $writable($key)) {
            $keys = [$key];
            $name = $key;
        } elseif ($group !== '' && isset($catalog[$group])) {
            $keys = array_values(array_filter(array_keys($catalog[$group]), $writable));
            $name = $group;
        } else {
            return back()->with('err', 'لا مدخلَ لهذا المفتاح في الكتالوج، أو تملكه شاشةٌ أخرى — '
                . 'والاستعادةُ لا تطال المفاتيحَ الداخلية: لا افتراضيَّ مُعلَنَ لها، وتُدار من شاشاتها.');
        }

        // (§18) عاليةُ الخطورة تطلب تصعيدَ هوية: جلسةٌ مسروقة لا تُعيد ضبطَ
        // حارسٍ أطفأه المالك. وهو **غيرُ مشروطٍ برايةٍ** — الاستعادةُ فعلٌ نادرٌ
        // عريضُ الأثر، فلا يُشترى تخفيفُها بمفتاحٍ يُطفأ ثم يُنسى.
        $risky = array_values(array_filter($keys, fn ($k) => Settings::isHighRisk($k)));
        if ($risky !== [] && ($stop = hub_require_stepup())) return $stop;

        // ولا تُترك المجموعةُ نصفَ مضبوطةٍ باستعادةٍ جزئية: حارسُ الحفظ نفسُه
        $plan = [];
        foreach ($keys as $k) $plan[$k] = Settings::restoreValue($k);
        if ($dep = Settings::dependencyErrors($plan)) {
            return back()->withErrors($dep)
                ->with('warn', 'لم تُستعَد — ' . implode(' ', $dep)
                    . ' استعِد المجموعةَ كاملةً أو أكمِل ما ينقصها.');
        }

        $done = [];
        Settings::batch('restore', function () use ($keys, &$done) {
            foreach ($keys as $k) {
                if (Settings::restore($k, 'استعادةُ الافتراضي من شاشة الإعدادات')) $done[] = $k;
            }
        }, ['action' => Settings::RESTORE_ACTION, 'module' => 'settings',
            'name' => $name, 'reason' => 'استعادةُ الافتراضي من شاشة الإعدادات']);

        return back()->with('ok', $done === []
            ? 'لا شيءَ لاستعادته — لا صفَّ مكتوباً لهذه المفاتيح أصلاً، فهي على افتراضيّها'
            : 'استُعيد الافتراضيُّ لـ' . count($done) . ' مفتاحاً — وكُتبت القيمةُ صراحةً لا حُذف الصفّ');
    }

    /** مجموعةُ الكتالوج التي ينتمي إليها مفتاح (نصٌّ فارغ لغير المعروض) */
    protected function groupOf(string $key): string
    {
        foreach (self::catalog() as $gLabel => $items) if (isset($items[$key])) return (string) $gLabel;

        return '';
    }

    /**
     * **ما ستؤول إليه المفاتيحُ لو حُفظ هذا الطلب — بلا كتابة.**
     *
     * قارئٌ واحدٌ يستهلكه الحفظُ والمعاينةُ معاً: ماشيان على الكتالوج جنباً إلى
     * جنبٍ يفترقان بأوّل تعديل، فتَعِد المعاينةُ بما لا يقع الحفظُ عليه — وهو
     * بالضبط ما يجعل «المعاينة» أسوأَ من لا معاينة.
     *
     * ‏`img` خارجَه: قيمتُه ملفٌّ يُخزَّن على القرص (كتابةٌ لا تجوز في معاينة)
     * ولا يُنقل في جلسة — فيُحفظ من الطلب المباشر في `storeImages`.
     *
     * @return array<string, string> مفتاح ⇐ القيمةُ المقصودة
     */
    protected function intended(Request $r, array &$errors): array
    {
        $checks = self::checks();                  // من الكتالوج — مصدرٌ واحد
        $map = [];

        foreach (self::catalog() as $items) {
            foreach ($items as $key => $meta) {
                // (WP-9.4 · §7.13) مفتاحٌ تملكه شاشةٌ أخرى (‏`readonly`): يُعرَض هنا
                // ولا يُكتب من هنا. `maintenance.on` نموذجُه — كان له مفتاحُ تبديلٍ
                // ثانٍ في هذه الشاشة يرفع الصيانةَ بلا `hub_require_ops_stepup`
                // ولا رسالةِ مركز التشغيل ولا أثرِه.
                if (! empty($meta['readonly'])) continue;

                $input = str_replace('.', '_', $key);
                $type = (string) ($meta['type'] ?? 'text');

                if ($type === 'img') continue;                    // تُحفظ وحدَها — لا تُعاين

                if ($type === 'onoff') {
                    // **العلامة المُرسَلة**: مربّعٌ مطفأ لا يصل في الطلب أصلاً، فبغير
                    // هذه العلامة لا يُفرَّق بين «أطفأه» و«لم يرسل هذه الشاشة».
                    if (! $r->has($input . '__sent')) continue;
                    // ونخزّن «0» لا الفراغ: `setting()` تردّ الافتراضي عند الفراغ،
                    // فمفتاحٌ افتراضيه مفعَّل كان يستحيل إطفاؤه من الشاشة.
                    // (‏`SettingsCenterTest::test_a_switch_that_defaults_on_can_actually_be_turned_off`)
                    $map[$key] = $r->boolean($input) ? '1' : '0';
                    continue;
                }

                if ($type === 'pass') {
                    $v = trim(hub_str($r->input($input, '')));
                    if ($v === '' || $v === '••••') continue;     // فارغ = إبقاء المخزَّن
                    // النصُّ الصريح يُمرَّر كما هو — **التشفيرُ عند الكاتب** لا هنا
                    $map[$key] = $v;
                    continue;
                }

                if (! $r->has($input)) continue;                  // حفظٌ جزئي لا يمحو الباقي
                $v = trim(hub_str($r->input($input, '')));

                // **ويُجمع الخطأُ ولا يُرمى**: الصفحةُ تُعلن سياستَها «حُفظ ما صحّ،
                // ورُدّ ما لا يسري». (وخطأُ **المجموعة** يردّ الدفعةَ كلَّها: تلك
                // حالةُ نصفِ ضبطٍ تعمل حيّاً، وهذه قيمةٌ واحدةٌ لا تسري.)
                if ($v !== '' && isset($checks[$key]) && ! preg_match($checks[$key]['re'], $v)) {
                    $errors[$input] = ($meta['label'] ?? $key) . ' — ' . $checks[$key]['msg'];
                    continue;
                }
                if ($v !== '' && $type === 'ta' && json_decode($v, true) === null) {
                    $errors[$input] = ($meta['label'] ?? $key) . ' — JSON غير صالح، فلن يسري وسيعمل النظام بالافتراضي بصمت';
                    continue;
                }

                $map[$key] = $v;
            }
        }

        return $map;
    }

    /** الصورُ وحدَها: رفعٌ إلى القرص أو إزالة — داخل دفعةِ `Settings::batch` */
    protected function storeImages(Request $r, array &$errors, array &$changed): void
    {
        foreach (self::catalog() as $items) {
            foreach ($items as $key => $meta) {
                if (! empty($meta['readonly'])) continue;
                if ((string) ($meta['type'] ?? 'text') !== 'img') continue;
                $input = str_replace('.', '_', $key);

                if ($r->hasFile($input)) {
                    // صيغٌ نقطية فقط: قاعدة image في Laravel تقبل SVG، وهو نصٌّ
                    // قد يحمل سكربتاً — ويُخزَّن على القرص العام ويُخدَم مباشرةً
                    // بلا Content-Disposition، فيصير XSS مخزَّناً. الحصرُ يمنعه.
                    //
                    // **ويُجمع الخطأُ ولا يُرمى**: `validate` من داخل الحلقة كان
                    // يقطع الطلبَ فوراً — فما كُتب قبله يبقى بلا `Cache::forget`
                    // ولا أثرِ تدقيق: الشاشةُ تعرض القديمَ والسجلُّ لا يعرف من
                    // غيّر. والصفحةُ نفسُها تُعلن سياستَها: «حُفظ ما صحّ، ورُدّ
                    // ما لا يسري».
                    $v = \Illuminate\Support\Facades\Validator::make(
                        [$input => $r->file($input)],
                        [$input => ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:4096']]);
                    if ($v->fails()) {
                        $errors[$input] = ($meta['label'] ?? $key) . ' — صورة غير صالحة: '
                            . 'يُقبل jpg أو png أو webp أو gif حتى ٤ ميجابايت';
                        continue;
                    }
                    if (Settings::put($key, $r->file($input)->store('hub/branding', 'public'), 'screen')) $changed[] = $key;
                } elseif ($r->boolean($input . '_clear')) {
                    // شعارٌ يُزال: حذفُ الصفّ هو معناه (لا افتراضيَّ لصورة)
                    if (Settings::forget($key, 'screen', 'إزالة الشعار من شاشة الإعدادات')) $changed[] = $key;
                }
            }
        }
        // ولا `Cache::forget('settings:all')` هنا ولا `hub_audit`: الكاتبُ الواحد
        // يفعلهما — الإبطالُ عند كل كتابة، والقيدُ **واحدٌ للدفعة** عند ختمها.
        // وكاشُ أودو الافتراضي يتدوّر ببصمة الاعتماد في مفتاحه — لا نسفَ يدوياً.
    }

    /**
     * (WP-9.4 · §7.10) اختبار اتصال أودو الافتراضي — **على الفاحص الواحد**.
     *
     * كان هنا نصفُ فاحصٍ ثانٍ (‏`Odoo::version` ثم `Odoo::uid`) يعيد
     * `$e->getMessage()` **خاماً** إلى الجلسة والشاشة. الرسالةُ الآن تمرّ
     * بالمُطهِّر، ومعها زمنُ الاستجابة، وبالشكل نفسِه الذي يراه مركزُ التكاملات.
     */
    public function odooTest()
    {
        $this->gate();
        $res = ConnectionProbe::odoo(null);

        return $res['up'] === true
            ? back()->with('ok', ConnectionProbe::line($res))
            : back()->withErrors(['odoo' => ConnectionProbe::line($res)]);
    }

    /* ══════ (WP-9.4) نقلُ الإعدادات بين التنصيبات · spec §7.11 · §7.12 · §35 ══════ */

    /** مفتاحُ الجلسة الذي تعبر فيه خطّةُ الاستيراد من خطوة الفرق إلى خطوة التطبيق */
    public const IMPORT_SESSION = 'hub.settings.import';

    /**
     * **تصديرُ الإعدادات** (§7.11): ملفُّ JSON لما ضُبط فعلاً، بلا سرٍّ وبلا حالة.
     *
     * وهو **تصديرُ بياناتٍ جماعيّ** كأيّ تصدير: يخضع لمفتاح تجميد التصدير (٤٢٣)
     * ويترك بصمةً في التدقيق تُصنَّف `DATA_EXPORT` — فسحبُ إعدادات المنشأة كاملةً
     * لا يمرّ بلا أثرٍ ولا يُستثنى من مفتاح الطوارئ.
     */
    public function export()
    {
        $this->gate();
        abort_if((string) setting('security.freeze_exports', '0') === '1', 423,
            'التصدير مجمَّدٌ الآن بمفتاح طوارئٍ أمنيّ — يُرفع من مركز الأمان');

        $payload = Settings::exportPayload();
        hub_audit('تصدير', 'settings', null, count($payload['settings']) . ' مفتاح إعدادات');

        $json = (string) json_encode($payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return response($json, 200, [
            'Content-Type'           => 'application/json; charset=UTF-8',
            'Content-Disposition'    => 'attachment; filename="lynomia-settings-'
                . now()->format('Y-m-d-Hi') . '.json"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * **الاستيراد — الخطوات ١ إلى ٤** (§7.12): رفعٌ ثم تحقّقُ مفاتيح ثم فرقٌ ثم
     * وسمُ خطر. **ولا كتابةَ هنا البتّة**: الخطّةُ تُحفظ في الجلسة وتُعرض للمراجعة،
     * والتطبيقُ فعلٌ ثانٍ بتأكيد.
     *
     * وهذا **ليس** `hub:import`: ذاك استعادةُ نسخةٍ كاملة (جداولُ المنشأة كلُّها)
     * ويبقى كما هو ولا يُخلط بهذا — خلطُهما يجعل «استوردتُ إعداداتي» تعني أحياناً
     * «دهستُ قاعدتي».
     */
    public function import(Request $r)
    {
        $this->gate();
        $r->session()->forget(self::IMPORT_SESSION);      // لا خطّةَ قديمةٌ تُطبَّق بملفٍّ جديد

        $r->validate(['file' => ['required', 'file', 'max:512']], [],
            ['file' => 'ملفّ الإعدادات']);

        $raw = (string) @file_get_contents($r->file('file')->getRealPath());
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return back()->withErrors(['file' => 'الملفُّ ليس JSON صالحاً — صدّر ملفاً من هذه الشاشة ثم استورده']);
        }
        if (! is_array($data['settings'] ?? null)) {
            return back()->withErrors(['file' => 'لا مفتاح settings في الملف — هذه ليست حمولةَ تصدير إعدادات. '
                . 'واستعادةُ نسخةٍ كاملة أمرٌ آخر: php artisan hub:import']);
        }

        $plan = Settings::importPlan($data['settings']);
        $plan['from'] = [
            'version'     => (string) hub_fit(hub_str($data['version'] ?? ''), 40),
            'exported_at' => (string) hub_fit(hub_str($data['exported_at'] ?? ''), 40),
        ];
        $r->session()->put(self::IMPORT_SESSION, $plan);

        $msg = 'قُرئ الملف: ' . count($plan['ok']) . ' مفتاحاً سيتغيّر · '
            . count($plan['same']) . ' بلا تغيير · ' . count($plan['bad']) . ' مرفوضاً. '
            . ($plan['ok'] ? 'راجع الفرق أدناه ثم أكّد التطبيق.' : 'لا شيء يُطبَّق.');

        return back()->with($plan['bad'] ? 'warn' : 'ok', $msg);
    }

    /**
     * **الاستيراد — الخطوتان ٥ و٦**: تأكيدٌ ثم تطبيقٌ عبر `Settings::put` بمصدر
     * `import` — فكلُّ مفتاحٍ يترك صفَّ تاريخٍ، وللدفعة قيدُ تدقيقٍ واحد.
     *
     * والتحقّقُ يُعاد هنا كاملاً: الخطّةُ حُسبت في طلبٍ سابق، والقاعدةُ الذهبية
     * أن يكون **الفحصُ عند الكتابة** لا عند العرض.
     */
    public function importApply(Request $r)
    {
        $this->gate();

        $plan = (array) $r->session()->get(self::IMPORT_SESSION, []);
        $rows = (array) ($plan['ok'] ?? []);
        if ($rows === []) {
            return back()->withErrors(['file' => 'لا خطّةَ استيرادٍ قائمة — ارفع الملفَّ أولاً وراجع الفرق']);
        }

        // مفتاحٌ عالي الخطورة في الحمولة ⇐ تأكيدُ هوية قبل التطبيق (§18).
        // والخطّةُ تبقى في الجلسة كي يمضي التطبيقُ بعد التأكيد بلا رفعٍ ثانٍ.
        if (! empty($plan['risky']) && ($resp = hub_require_stepup())) return $resp;

        $allowed = array_flip(Settings::exportableKeys());
        $applied = [];
        $refused = [];

        Settings::batch('import', function () use ($rows, $allowed, &$applied, &$refused) {
            foreach ($rows as $row) {
                $key = trim((string) ($row['key'] ?? ''));
                $val = (string) ($row['after'] ?? '');

                if ($key === '' || ! isset($allowed[$key]) || Settings::isSecret($key)
                    || Settings::isState($key) || Settings::validate($key, $val) !== null) {
                    $refused[] = $key;
                    continue;
                }
                if (Settings::put($key, $val, 'import', 'استيراد ملفّ إعدادات')) $applied[] = $key;
            }
        }, ['name' => 'استيراد إعدادات · ' . implode(' · ', array_column($rows, 'key'))]);

        $r->session()->forget(self::IMPORT_SESSION);

        return back()->with($refused ? 'warn' : 'ok',
            'طُبّق الاستيراد: ' . count($applied) . ' مفتاحاً'
            . ($refused ? ' — ورُدَّ ' . count($refused) . ' عند الكتابة (تغيّر الكتالوج بعد قراءة الملف)' : ''));
    }
}
