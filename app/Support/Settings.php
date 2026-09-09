<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * **نموذجُ معلومات الإعداد** — القارئُ الواحد للكتالوج (WP-9.1 · spec §7.1–§7.4).
 *
 * الشاشةُ كانت تعرض `def` نثراً («فارغ — يسقط إلى اسم النظام»)، فلا هي ولا
 * الاستعادةُ ولا التصديرُ ولا التدقيق يعرف **ما افتراضيُّ المفتاح آليّاً، ولا
 * من أين تأتي قيمتُه السارية حين لا صفَّ له**. وثلاثةُ أسئلةٍ من §48 كانت بلا
 * جواب: «ما افتراضيّه؟ ما الساري؟ من أين؟».
 *
 * فهنا جوابُها من مصدرٍ واحد:
 *   · `effective()` تعيد {المخزَّن · الافتراضيّ · الساري · المصدر · أسرّيٌّ هو}
 *     وتصنّف المصدرَ في أربعة: `default` (لا صفّ) · `database` (صفٌّ بقيمة) ·
 *     `environment` (‏`env_key` مضبوطٌ في البيئة — **وقيمتُه لا تخرج من هنا**) ·
 *     `module` (مفتاحٌ داخليٌّ تملكه شاشةٌ بعينها).
 *   · وتقرأ **الأرضياتِ الحقيقية من الشيفرة** فتقول من فرض الحدّ — على نموذج
 *     `hub_upload_cap` الذي يقول أيُّهما القاطع: الإعدادُ أم الخادم.
 *   · و`secrets()` تُشتقّ من `sensitive` في الكتالوج وحدها — فأُغلق عيبٌ حيّ:
 *     `hub:set n8n.key X` كان يخزّن نصّاً صريحاً بينما شاشةُ n8n تشفّره، لأنّ
 *     قائمةَ الأسرار كانت مكتوبةً بيدٍ ثانيةٍ تنسى.
 *
 * **قاعدةُ السرّ مطلقة:** لا قيمةَ سرٍّ تخرج من هذا الصنف أبداً — بصمةٌ
 * (`Redactor::fingerprint`) أو `null`، لا ثالث. ولا قيمةَ بيئةٍ تخرج إطلاقاً:
 * يُقال إنها مضبوطة ولا يُقال ماذا.
 */
class Settings
{
    /** مصادرُ القيمة السارية — أربعةٌ لا خامسَ لها */
    public const SOURCES = ['default', 'database', 'environment', 'module'];

    /**
     * (WP-9.2) أبوابُ الكتابة المُعلَنة — كلُّ صفٍّ في `setting_changes` يقول من
     * أيّها جاء، فسؤالُ «من غيّره؟» يسبقه «من أين؟». عرضُ العمود ٢٠ حرفاً.
     */
    public const WRITERS = ['screen', 'messaging', 'odoo', 'n8n', 'security',
                            'ops', 'cli', 'import', 'restore', 'demo', 'features'];

    /**
     * كم قيدَ تدقيقٍ يُمسح ارتداداً حين لا صفَّ تاريخٍ للمفتاح. الجدولُ حديث،
     * وما قبله محفوظٌ في `audits` وحدَها بصيغةِ **دفعةٍ** (المفاتيحُ في
     * `after._keys`) — فلا فهرسَ يقود إليها بالمفتاح، والمسحُ محدودٌ عمداً:
     * «آخرُ تعديل» معلومةٌ مساعِدة لا تستحقّ مسحَ جدولِ تدقيقٍ كامل.
     */
    public const AUDIT_FALLBACK_SCAN = 150;

    /** فعلُ التدقيق الافتراضيّ لكل كتابةِ إعداد — مفرداتُ `SecurityEvents` نفسُها */
    public const AUDIT_ACTION = 'تعديل إعدادات النظام';

    /**
     * ما يبذره المنصِّب (`CoreSeeder`) **بقيمه الافتراضية** — صفٌّ موجودٌ بها
     * ليس «تغييراً» من أحد، فلا يُعدّ في لوحة «المُغيَّر عن الافتراضي».
     * يحرسه `SettingsModelTest` بمسحٍ ساكن على `CoreSeeder` نفسِه.
     */
    public const SEEDED = [
        'auth.session_min' => 240,
        'auth.max_fail'    => 5,
        'auth.lock_min'    => 15,
        'auth.pw_min'      => 10,
        'files.max_kb'     => 1048576,
        'notify.quiet'     => ['on' => false, 'from' => 22, 'to' => 7],
        'finance.accounts' => ['ar' => '1200', 'ap' => '2100', 'cash' => '1010', 'bank' => '1020',
                               'sales' => '4100', 'tax' => '2200', 'exp' => '5200', 'custody' => '1250'],
    ];

    /**
     * ما يُعدّ «عالي الخطورة»: نمطُ `SecurityEvents::codeFor` نفسُه (وهو ما
     * يجعل تعديلَ المفتاح حدثاً أمنياً في التدقيق) مضافاً إليه أربعةٌ يفسدها
     * الضبطُ الخطأ فساداً صامتاً: البريد، وأودو (بلا حارس SSRF)، وسقفُ الرفع،
     * ومقاما أجر الساعة. تعريفٌ واحدٌ يستهلكه الجميع — لا تقديرَ لكل شاشة.
     */
    public const HIGH_RISK_RE = '/security\.|auth\.|sec\.|api\.token|risk\.|2fa|maintenance\.|mail\.|odoo\.|files\.max_kb|cost\.work_/u';

    /**
     * (WP-9.4 · spec §7.11) **بادئاتُ صفوف الحالة** — ما يكتبه النظامُ عن نفسه
     * لا ما يضبطه المشغّل: نبضاتُ المجدولات، وآخرُ نجاحِ تكاملٍ وفشلِه ومدّتُه،
     * ورايةُ الوضع التجريبي، وعدّادُ الحقول المخصَّصة، وقوالبُ التوقيع المبذورة.
     *
     * **ولماذا بادئةٌ لا مدخلُ كتالوج؟** لأنّ أكثرها **عائلاتٌ ديناميّة** لا
     * مفاتيحُ ثابتة: `heartbeat.<job>` و`integration.<key>.last_ok|last_fail|last_ms`
     * تُولَّد باسم المهمّة أو التكامل وقتَ التشغيل، فلا مدخلَ لها في الكتالوج
     * إطلاقاً ولا يمكن أن يكون. فالتصنيفُ صريحٌ هنا، وليس «ما لا نعرفه يُصدَّر».
     *
     * نقلُ صفٍّ من هذه إلى تنصيبٍ آخر يكذب على مراقبته من أول دقيقة: نبضةُ نسخةٍ
     * احتياطيةٍ لم تُؤخذ قطّ تجعل `Health` يقول «سليم» عن خادمٍ بلا نسخة.
     */
    public const STATE_PREFIXES = ['heartbeat.', 'integration.', 'demo.',
                                   'custom.fields_seq', 'esign.tpl_seeded', 'ops.last_version'];

    /**
     * رايات التشغيل الحيّة: مفتاحٌ ⇐ [التسمية، الشاشةُ المالكة]. تُقرأ هنا
     * **قراءةً فقط** — تبديلُها من شاشتها كي يبقى مسجَّلاً في التدقيق.
     */
    public const RUNTIME_FLAGS = [
        'maintenance.on'          => ['وضع الصيانة', 'ops.index'],
        'demo.on'                 => ['الوضع التجريبي', 'ops.index'],
        'security.lockdown'       => ['قفل الطوارئ', 'security.index'],
        'security.freeze_exports' => ['تجميد التصدير', 'security.index'],
        'security.freeze_tokens'  => ['تجميد سكّ الرموز', 'security.index'],
    ];

    /* ───────────────────────── الكتالوج ───────────────────────── */

    /** المجموعاتُ كما هي في الكتالوج */
    public static function catalog(): array
    {
        return (array) config('hub_settings.groups', []);
    }

    /** كل مفتاحٍ معروضٍ في الشاشة، مسطَّحاً وبترتيب الكتالوج */
    public static function exposedKeys(): array
    {
        $out = [];
        foreach (self::catalog() as $items) foreach (array_keys($items) as $k) $out[] = $k;

        return $out;
    }

    /** مدخلُ مفتاحٍ معروض — أو null إن لم يكن معروضاً */
    public static function entry(string $key): ?array
    {
        foreach (self::catalog() as $items) if (isset($items[$key])) return (array) $items[$key];

        return null;
    }

    /**
     * المفاتيحُ الداخلية **مُطبَّعة**: الشكلُ النصّي القديم (`'key' => 'السبب'`)
     * والشكلُ الجديد (`['why' => …, 'owner_route' => …, 'sensitive' => …]`)
     * كلاهما مقبول، فلا يُكسر مدخلٌ قائم بإضافة حقلٍ لآخر.
     */
    public static function internal(): array
    {
        $out = [];
        foreach ((array) config('hub_settings.internal', []) as $key => $meta) {
            $out[$key] = is_array($meta)
                ? ['why' => (string) ($meta['why'] ?? ''),
                   'owner_route' => (string) ($meta['owner_route'] ?? ''),
                   'sensitive' => (bool) ($meta['sensitive'] ?? false),
                   // (WP-9.4) مفتاحٌ داخليٌّ **قابلٌ للنقل** بين التنصيبات: سياسةُ
                   // احتفاظٍ أو عتبةُ تشغيلٍ لا حالةٌ. الافتراضيُّ لا — فالإغفال
                   // يمنع التصدير ولا يفتحه.
                   'exportable' => (bool) ($meta['exportable'] ?? false)]
                : ['why' => (string) $meta, 'owner_route' => '', 'sensitive' => false, 'exportable' => false];
        }

        return $out;
    }

    public static function internalEntry(string $key): ?array
    {
        return self::internal()[$key] ?? null;
    }

    /**
     * قواعدُ التحقّق من الكتالوج — **المصدرُ الواحد**. كانت منسوخةً في
     * `SettingController::CHECKS` بجانب الكتالوج، فقاعدةٌ تُضاف هناك لا يعرفها
     * أحدٌ غير الحفظ: لا الشاشةُ تُظهرها، ولا الاستيرادُ يحترمها.
     */
    public static function checks(): array
    {
        $out = [];
        foreach (self::catalog() as $items) {
            foreach ($items as $key => $meta) {
                $v = $meta['validation'] ?? null;
                if (is_array($v) && ($v['re'] ?? '') !== '') $out[$key] = ['re' => $v['re'], 'msg' => (string) ($v['msg'] ?? '')];
            }
        }

        return $out;
    }

    /** المفاتيحُ التي تُخزَّن مشفَّرةً ولا تُعرض — معروضةً كانت أو داخلية */
    public static function secrets(): array
    {
        $out = [];
        foreach (self::catalog() as $items) foreach ($items as $key => $meta) if (! empty($meta['sensitive'])) $out[] = $key;
        foreach (self::internal() as $key => $meta) if ($meta['sensitive']) $out[] = $key;

        return $out;
    }

    public static function isSecret(string $key): bool
    {
        return in_array($key, self::secrets(), true);
    }

    public static function isHighRisk(string $key): bool
    {
        return (bool) preg_match(self::HIGH_RISK_RE, $key);
    }

    /** صفُّ حالةٍ يكتبه النظامُ عن نفسه؟ — بالبادئة الصريحة لا بغياب المدخل */
    public static function isState(string $key): bool
    {
        foreach (self::STATE_PREFIXES as $p) if (str_starts_with($key, $p)) return true;

        return false;
    }

    /**
     * (WP-9.4 · §7.13) مفتاحٌ معروضٌ **تملكه شاشةٌ أخرى** فيُعرَض هنا ولا يُحرَّر:
     * `maintenance.on` نموذجُه — مفتاحُ تبديلٍ ثانٍ له هنا يعني رفعَ الصيانة بلا
     * تأكيدِ الهوية والرسالةِ والأثرِ التي بُنيت في مركز التشغيل.
     */
    public static function isReadonly(string $key): bool
    {
        return (bool) (self::entry($key)['readonly'] ?? false);
    }

    /** الافتراضيّ الآليّ لمفتاحٍ معروض (null لغيره) */
    public static function defaultOf(string $key)
    {
        $meta = self::entry($key);

        return $meta === null ? null : ($meta['default'] ?? null);
    }

    /* ───────────────────── القيمة السارية والمصدر ───────────────────── */

    /**
     * صفوفُ الإعدادات كما هي — من **الخبيئة نفسِها** التي تقرأها `setting()`،
     * فلا استعلامَ ثانٍ ولا اختلافَ في اللحظة. ووجودُ المفتاح هنا يعني وجودَ
     * صفٍّ له في القاعدة (ولو بقيمةٍ فارغة).
     */
    public static function rows(): array
    {
        return (array) Cache::remember('settings:all', 600, fn () => Setting::pluck('value', 'key')->all());
    }

    /**
     * هل متغيّرُ البيئة مضبوطٌ فعلاً؟ — **بلا إعادة قيمته أبداً** ولا حفظِها ولا
     * تمريرِها لأحد: يخرج من هنا `true`/`false` لا غير.
     *
     * **قيدٌ يُقال لا يُخفى:** `env()` تقرأ ما حمّله `LoadEnvironmentVariables`،
     * وهو يتخطّى ملفّ `.env` إن كان الإعدادُ مخبّأً (`config:cache`) — فتعود
     * `false` لمتغيّرٍ مضبوطٍ في الملف. والنظامُ لا يخبّئ الإعدادَ في أيّ مسار
     * (‏`OpsController` يُشغّل `optimize:clear` وحده، ووضعيةُ الأمان توصي به بعد
     * كلّ تحرير `.env`)، فالقيدُ نظريٌّ هنا — ولو خُبِّئ يوماً صار الجوابُ
     * «الافتراضي» لا قيمةً خاطئة.
     */
    public static function envSet(string $envKey): bool
    {
        if ($envKey === '') return false;

        return trim((string) env($envKey)) !== '';
    }

    /**
     * الصورةُ الكاملة لمفتاحٍ واحد.
     *
     * @return array{key:string, stored:?string, default:?string, effective:?string,
     *               source:string, sensitive:bool, imposed:?string, env_key:?string,
     *               owner_route:?string, exposed:bool}
     */
    public static function effective(string $key): array
    {
        $meta = self::entry($key);
        $int = $meta === null ? self::internalEntry($key) : null;
        $sensitive = (bool) ($meta['sensitive'] ?? $int['sensitive'] ?? false);

        $rows = self::rows();
        $hasRow = array_key_exists($key, $rows);
        $stored = $hasRow ? self::flat($rows[$key]) : null;
        $default = self::flat($meta === null ? null : ($meta['default'] ?? null));

        $envKey = (string) ($meta['env_key'] ?? '');
        $owner = (string) ($meta['owner_route'] ?? $int['owner_route'] ?? '');

        // مفتاحٌ داخليٌّ له شاشةٌ مالكة: مصدرُه تلك الشاشة سواءٌ كتبت بعدُ أم لا —
        // فالجوابُ الصادق عن «من أين؟» اسمُها لا اسمُ الجدول.
        if ($int !== null && ($int['owner_route'] ?? '') !== '') $source = 'module';
        elseif ($stored !== null && $stored !== '') $source = 'database';
        elseif (self::envSet($envKey)) $source = 'environment';
        else $source = 'default';

        // صفٌّ فارغٌ ليس قيمةً: `setting()` تردّ الافتراضيَّ عند الفراغ
        $effective = ($stored !== null && $stored !== '') ? $stored : $default;
        $imposed = null;

        if ($source === 'environment') {
            $effective = null;                       // قيمةُ البيئة لا تخرج — يكفي أنها مضبوطة
        } else {
            [$effective, $imposed] = self::applyFloor($key, $meta ?? [], $effective);
        }

        if ($sensitive) {                            // سرٌّ: بصمةٌ أو لا شيء، لا ثالث
            $stored = self::fingerprint($stored);
            $default = self::fingerprint($default);
            $effective = self::fingerprint($effective);
        }

        return [
            'key'         => $key,
            'stored'      => $stored,
            'default'     => $default,
            'effective'   => $effective,
            'source'      => $source,
            'sensitive'   => $sensitive,
            'imposed'     => $imposed,
            'env_key'     => $envKey !== '' ? $envKey : null,
            'owner_route' => $owner !== '' ? $owner : null,
            'exposed'     => $meta !== null,
        ];
    }

    /**
     * الأرضياتُ والسقوفُ التي **تفرضها الشيفرة** لا الكتالوج — فالمكتوبُ في
     * الحقل قد لا يكون السارِيَ. `hub_upload_cap` هو النموذجُ العامل: يقول
     * أيُّهما القاطع، الإعدادُ أم سقفُ الخادم.
     *
     * @return array{0:?string, 1:?string}   [الساري بعد الفرض، من فرضه]
     */
    protected static function applyFloor(string $key, array $meta, ?string $value): array
    {
        if ($key === 'files.max_kb') {
            $cap = rescue(fn () => hub_upload_cap(), null, false);
            if (is_array($cap) && ! empty($cap['byPhp'])) {
                return [(string) $cap['kb'], 'سقفُ الخادم أصغر (‏upload_max_filesize / post_max_size) '
                    . 'فالفعليُّ ' . $cap['label'] . ' — لا هذا الرقم'];
            }

            return [$value, null];
        }

        $v = $meta['validation'] ?? [];
        if ($value === null || $value === '' || ! is_numeric($value) || ! is_array($v)) return [$value, null];

        if (isset($v['min']) && (float) $value < (float) $v['min']) {
            return [(string) $v['min'], 'الأرضيةُ المفروضة ' . $v['min'] . (($v['by'] ?? '') !== '' ? ' — ' . $v['by'] : '')];
        }
        if (isset($v['max']) && (float) $value > (float) $v['max']) {
            return [(string) $v['max'], 'السقفُ المفروض ' . $v['max'] . (($v['by'] ?? '') !== '' ? ' — ' . $v['by'] : '')];
        }

        return [$value, null];
    }

    /* ───────────────────────── لوحة §7.2 ───────────────────────── */

    /**
     * ستّةُ أرقامٍ تقول حالَ الإعدادات في سطر: كم مفتاحاً، وكم غُيِّر عن
     * افتراضيّه، وكم منها عالي الخطورة، وكم سرّاً مضبوطاً، وكم تكاملاً ينتظر
     * إعدادَه، وكم رايةَ تشغيلٍ مرفوعة. كلُّ رقمٍ من قارئه لا من تخمين.
     */
    public static function dashboard(): array
    {
        $rows = self::rows();
        $exposed = self::exposedKeys();

        $changed = 0;
        foreach ($exposed as $k) {
            if (! array_key_exists($k, $rows)) continue;
            // صفٌّ بذره المنصِّب بقيمته الافتراضية ليس تغييراً من أحد
            if (array_key_exists($k, self::SEEDED) && self::flat($rows[$k]) === self::flat(self::SEEDED[$k])) continue;
            $changed++;
        }

        $secrets = 0;
        foreach (self::secrets() as $k) {
            if (array_key_exists($k, $rows) && self::flat($rows[$k]) !== null && self::flat($rows[$k]) !== '') $secrets++;
        }

        $flags = [];
        foreach (self::RUNTIME_FLAGS as $k => [$label, $route]) {
            if (self::flagOn($k)) $flags[$k] = ['label' => $label, 'route' => $route];
        }

        $integrations = rescue(fn () => count(array_filter(
            Integrations::installed(),
            fn ($i) => ($i['health'] ?? '') === Integrations::CONFIGURATION_REQUIRED)), 0, false);

        return [
            'total'        => count($exposed),
            'internal'     => count(self::internal()),
            'changed'      => $changed,
            'risky'        => count(array_filter($exposed, fn ($k) => self::isHighRisk($k))),
            'secrets'      => $secrets,
            'secrets_all'  => count(self::secrets()),
            'integrations' => (int) $integrations,
            'flags'        => count($flags),
            'flags_on'     => $flags,
        ];
    }

    /** رايةٌ مرفوعة؟ — النصُّ «1» وحده يرفعها (كما يقرؤها حرّاسُها) */
    public static function flagOn(string $key): bool
    {
        return (string) rescue(fn () => setting($key, ''), '', false) === '1';
    }

    /* ───────────────────────── أدوات ───────────────────────── */

    /**
     * قيمةٌ مسطَّحةٌ للمقارنة والعرض: العمود مصبوبٌ `array` فقد تعود خريطةً،
     * و«مفعَّل» يُخزَّن نصّاً «1». وnull تعني **لا صفَّ** لا «فارغ».
     */
    public static function flat($v): ?string
    {
        if ($v === null) return null;
        if (is_bool($v)) return $v ? '1' : '';
        if (is_scalar($v)) return (string) $v;

        return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** بصمةُ سرٍّ — أو null حين لا سرَّ مضبوطاً. القيمةُ نفسُها لا تخرج أبداً */
    protected static function fingerprint(?string $v): ?string
    {
        return ($v === null || $v === '') ? null : Redactor::fingerprint($v);
    }

    /* ═════════════════ (WP-9.2) الكاتبُ الواحد · spec §7.5 · §7.6 · §31 ═════════════════ */

    /**
     * الدفعةُ المفتوحة — أو null حين لا دفعة. لا كومةَ دفعاتٍ: طلبٌ واحدٌ يكتب
     * دفعةً واحدة، والدفعةُ المتداخلة تنضمّ إلى المفتوحة بدل أن تفتح ثانيةً
     * (فيبقى قيدُ التدقيق واحداً لدفعةٍ واحدة كما هو اليوم).
     */
    protected static ?array $open = null;

    /**
     * **نقطةُ الكتابة الوحيدة إلى جدول الإعدادات.**
     *
     * كان الجدولُ يُكتب من عشرة مواضع، كلٌّ يتذكّر (أو ينسى) شيئاً: التشفيرَ
     * للحسّاس، و`Cache::forget('settings:all')` المنسوخَ في خمسةَ عشرَ موضعاً،
     * وأثرَ التدقيق (ثلاثةٌ منهم بلا أثرٍ إطلاقاً). فهنا كلُّه في سطرٍ واحد:
     *   تحقّقٌ من الكتالوج → تشفيرُ الحسّاس → كتابة → إبطالُ الخبيئة →
     *   قيدُ تدقيقٍ بـ«قبل/بعد» (الأسرارُ بصمةً) → صفٌّ في `setting_changes`.
     *
     * والقيمةُ تُمرَّر **صريحةً** لمفتاحٍ حسّاس: التشفيرُ هنا لا عند المُنادي —
     * وهو ما أغلق عيبَ `hub:set n8n.key` الذي كان يخزّن نصّاً صريحاً.
     *
     * @param  string      $key     مفتاحُ الإعداد
     * @param  mixed       $value   القيمةُ الجديدة (نصٌّ أو خريطةٌ أو عدد) — صريحةً للحسّاس
     * @param  string      $source  بابُ الكتابة من `WRITERS`
     * @param  string|null $reason  لماذا — يُختم في التدقيق ويُحفظ في التاريخ
     * @return bool                 هل تغيّرت القيمة فعلاً (لا كتابةَ لما لم يتبدّل)
     *
     * @throws \InvalidArgumentException حين تخالف القيمةُ قاعدةَ الكتالوج
     */
    public static function put(string $key, $value, string $source, ?string $reason = null): bool
    {
        return self::commit($key, $value, $source, $reason, false);
    }

    /**
     * حذفُ الصفّ — عودةٌ إلى ما تسقط إليه الشيفرة. **لا تُستعمل استعادةً
     * لافتراضيٍّ مُعلَن** (critic #7): مفتاحٌ افتراضيُّه «مُشغَّل» (‏`sec.hours_on`
     * و`sec.strict_files`) يعود مشتعلاً بالحذف، فيُعاد إشعالُ حارسٍ أطفأه
     * المالكُ عمداً. بابُها الصحيح: مفاتيحُ الطوارئ التي افتراضيُّها «مطفأ»
     * (تجميدٌ/قفلٌ) وأنواعُ `img`/`pass` التي لا افتراضيَّ لها.
     */
    public static function forget(string $key, string $source, ?string $reason = null): bool
    {
        return self::commit($key, null, $source, $reason, true);
    }

    /**
     * دفعةٌ واحدة: كلُّ `put` داخلها يكتب صفَّ تاريخٍ لنفسه، ويخرج للدفعة كلِّها
     * **قيدُ تدقيقٍ واحد** — كما كانت شاشةُ الإعدادات تفعل قبل هذه الحزمة
     * (‏`EnterpriseHardeningRound1Test::test_settings_change_audit_carries_before_and_after`
     * يثبّت ذلك: قيدٌ واحدٌ يحمل «قبل/بعد» وأسماءَ المفاتيح في `after._keys`).
     *
     * @param  array  $audit  ‏`['action' => …, 'module' => …, 'name' => …, 'reason' => …]`
     *                        لمن له فعلٌ خاصّ (تجميدٌ/قفلٌ/صيانة) — وإلا فالافتراضيّ
     * @return mixed          ما أعادته الدالّة
     */
    public static function batch(string $source, \Closure $fn, array $audit = [])
    {
        if (self::$open !== null) return $fn();          // دفعةٌ مفتوحة: انضمّ إليها

        self::$open = ['source' => $source, 'audit' => $audit, 'snapshot' => null,
                       'keys' => [], 'before' => [], 'after' => [], 'rows' => [], 'reason' => $audit['reason'] ?? null];

        try {
            return $fn();
        } finally {
            $batch = self::$open;
            self::$open = null;
            self::seal($batch);
        }
    }

    /**
     * قاعدةُ الرفض من الكتالوج — **المصدرُ الواحد** لكل الأبواب. القيمةُ
     * الفارغة مسموحةٌ دوماً (تعني «عُد للافتراضي»)، والخريطةُ لا صيغةَ لها.
     *
     * @return string|null رسالةُ الرفض بالعربية، أو null إن صحّت
     */
    public static function validate(string $key, $value): ?string
    {
        if ($value === null || is_array($value) || is_bool($value)) return null;

        $meta = self::entry($key);
        $re = $meta['validation']['re'] ?? null;
        if (! is_string($re) || $re === '') return null;

        $v = trim((string) $value);
        if ($v === '' || preg_match($re, $v)) return null;

        return (string) ($meta['label'] ?? $key) . ' — '
            . (string) ($meta['validation']['msg'] ?? 'قيمةٌ لا تطابق الصيغة المطلوبة');
    }

    /**
     * «آخرُ تعديل (من/متى)» لمجموعة مفاتيح (spec §7.6 · §48).
     *
     * القارئُ الأول `setting_changes`، والثاني **ارتدادٌ إلى `audits`** لما سبق
     * الجدول: القيدُ هناك يخصّ دفعةً لا مفتاحاً، فمفاتيحُه تُقرأ من
     * `after._keys` (أو من الاسم المفصول بـ` · ` للقيود الأقدم من v2.399).
     *
     * @return array<string, array{at:string, user:?string, user_id:?string, source:?string, from:string}>
     */
    public static function lastChanges(array $keys): array
    {
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys), fn ($k) => $k !== '')));
        if ($keys === []) return [];

        $out = [];
        $userIds = [];

        // ١) التاريخُ الحقيقيّ — أحدثُ صفٍّ لكل مفتاح. المعيارُ `MAX(id)` لا
        //    `created_at`: الدفعةُ كلُّها بطابعٍ واحدٍ بدقّة الثانية، فالفرزُ
        //    على الطابع قرعةٌ تختلف بين المحرّكين. و`id` تزايديٌّ لا يتساوى.
        // ‏`key` كلمةٌ محجوزة في MySQL — تُمرَّر عبر `select()` ليقتبسها المحرّكُ
        // لا في `selectRaw` نصّاً خاماً (يمرّ على SQLite ويسقط على MySQL وحدَه)
        $latest = rescue(fn () => DB::table('setting_changes')->whereIn('key', $keys)
            ->groupBy('key')->select('key')->selectRaw('MAX(id) as mid')
            ->pluck('mid', 'key')->all(), [], false);

        if ($latest) {
            $rows = DB::table('setting_changes')->whereIn('id', array_map('intval', array_values($latest)))
                ->orderBy('id')->get(['id', 'key', 'user_id', 'source', 'created_at']);
            foreach ($rows as $r) {
                $out[(string) $r->key] = ['at' => (string) $r->created_at, 'user' => null,
                    'user_id' => $r->user_id === null ? null : (string) $r->user_id,
                    'source' => (string) $r->source, 'from' => 'history'];
                if ($r->user_id) $userIds[] = (string) $r->user_id;
            }
        }

        // ٢) الارتداد — مسحٌ محدودٌ لأحدث قيود «تعديل إعدادات النظام»
        $missing = array_flip(array_values(array_diff($keys, array_keys($out))));
        if ($missing !== []) {
            $olds = rescue(fn () => DB::table('audits')->where('action', self::AUDIT_ACTION)
                ->orderByDesc('id')->limit(self::AUDIT_FALLBACK_SCAN)
                ->get(['id', 'user_id', 'name', 'after', 'created_at']), collect(), false);

            foreach ($olds as $a) {
                if ($missing === []) break;
                foreach (self::auditKeys($a) as $k) {
                    if (! isset($missing[$k])) continue;
                    unset($missing[$k]);
                    $out[$k] = ['at' => (string) $a->created_at, 'user' => null,
                        'user_id' => $a->user_id === null ? null : (string) $a->user_id,
                        'source' => null, 'from' => 'audit'];
                    if ($a->user_id) $userIds[] = (string) $a->user_id;
                }
            }
        }

        // ٣) الأسماء دفعةً واحدة — والمحذوفُ يُقال محذوفاً لا يُخفى
        $names = $userIds
            ? rescue(fn () => \App\Models\User::withTrashed()->whereIn('id', array_values(array_unique($userIds)))
                ->orderBy('id')->pluck('name', 'id')->all(), [], false)
            : [];
        foreach ($out as $k => $v) {
            $out[$k]['user'] = $v['user_id'] === null ? null : (string) ($names[$v['user_id']] ?? 'مستخدم محذوف');
        }

        return $out;
    }

    /* ───────────────────── داخلُ الكاتب ───────────────────── */

    /** مفاتيحُ قيدِ تدقيقِ إعداداتٍ واحد: `after._keys` أوّلاً ثم الاسمُ المفصول */
    protected static function auditKeys(object $a): array
    {
        $after = json_decode((string) ($a->after ?? ''), true);
        if (is_array($after) && is_array($after['_keys'] ?? null)) {
            return array_values(array_filter(array_map('strval', $after['_keys'])));
        }

        $name = trim((string) ($a->name ?? ''));
        if ($name === '') return [];

        // الاسمُ قد يكون «mail.* — من مركز المراسلة» لا قائمةَ مفاتيح: النجمةُ
        // تُسقط المدخلَ فلا يُنسب تعديلٌ إلى مفتاحٍ لم يُذكر اسمُه كاملاً.
        return array_values(array_filter(array_map('trim', explode('·', $name)),
            fn ($k) => $k !== '' && ! str_contains($k, '*') && ! str_contains($k, ' ')));
    }

    /** الكتابةُ الفعلية — بابُ `put`/`forget` الواحد */
    protected static function commit(string $key, $value, string $source, ?string $reason, bool $delete): bool
    {
        $key = (string) hub_fit(trim($key), 120);
        if ($key === '') return false;

        // خارج دفعةٍ: افتح واحدةً لهذه الكتابة وحدها — فمسارُ الأثر واحدٌ دائماً
        if (self::$open === null) {
            return (bool) self::batch($source, fn () => self::commit($key, $value, $source, $reason, $delete));
        }

        if (! $delete && ($why = self::validate($key, $value)) !== null) {
            throw new \InvalidArgumentException($why);
        }

        if (self::$open['snapshot'] === null) {
            // لقطةٌ واحدةٌ للدفعة كلِّها بدل استعلامٍ لكل مفتاح (كان الكاتبُ
            // القديم يستعلم لكلّ واحدٍ من ٩٥ مفتاحاً في حفظِ الشاشة الواحد)
            self::$open['snapshot'] = rescue(fn () => Setting::pluck('value', 'key')->all(), [], false);
        }

        $had = array_key_exists($key, self::$open['snapshot']);
        $old = self::wire($had ? self::$open['snapshot'][$key] : null);

        $store = $value;
        if (! $delete && self::isSecret($key) && is_string($store) && $store !== '' && ! str_starts_with($store, 'enc:')) {
            $store = 'enc:' . Crypt::encryptString($store);
        }
        $new = $delete ? null : self::wire($store);

        $changed = $delete ? $had : ($old !== $new);
        if (! $changed) return false;

        if ($delete) Setting::where('key', $key)->delete();
        else Setting::updateOrCreate(['key' => $key], ['value' => $store]);

        // الإبطالُ عند الكاتب لا عند المُنادي — كان منسوخاً في خمسةَ عشرَ موضعاً،
        // ومن نسيه (‏`HubImportJson` قبل v2.399) أدار المنشأةَ بإعدادات ما قبله
        Cache::forget('settings:all');
        if ($delete) unset(self::$open['snapshot'][$key]);
        else self::$open['snapshot'][$key] = $store;

        self::$open['keys'][] = $key;
        self::$open['before'][$key] = self::mask($key, $old);
        self::$open['after'][$key] = $delete ? '' : self::mask($key, $new);
        self::$open['rows'][] = ['key' => $key, 'reason' => $reason,
            'before' => $had ? self::mask($key, $old) : null,
            'after'  => $delete ? null : self::mask($key, $new)];
        if (self::$open['reason'] === null && $reason !== null && $reason !== '') self::$open['reason'] = $reason;

        return true;
    }

    /** ختمُ الدفعة: قيدُ تدقيقٍ واحد ثم صفوفُ التاريخ موصولةً به */
    protected static function seal(array $b): void
    {
        if (($b['keys'] ?? []) === []) return;

        $meta = (array) ($b['audit'] ?? []);
        $entry = hub_audit(
            (string) ($meta['action'] ?? self::AUDIT_ACTION),
            array_key_exists('module', $meta) ? $meta['module'] : 'settings',
            null,
            (string) ($meta['name'] ?? implode(' · ', $b['keys'])),
            array_filter([
                'before' => $b['before'],
                'after'  => $b['after'] + ['_keys' => $b['keys']],
                'reason' => $b['reason'],
            ], fn ($v) => $v !== null));

        $rid = hub_fit((string) rescue(fn () => \App\Support\Api::requestId(), '', false), 40);
        $uid = rescue(fn () => auth()->id(), null, false);
        $src = (string) hub_fit($b['source'], 20);
        $now = now();

        $insert = [];
        foreach ($b['rows'] as $r) {
            $insert[] = [
                'key'        => $r['key'],
                'before'     => self::wireJson($r['before']),
                'after'      => self::wireJson($r['after']),
                'user_id'    => $uid,
                'source'     => $src,
                'request_id' => ($rid === '' ? null : $rid),
                'audit_id'   => $entry?->id,
                'reason'     => hub_fit($r['reason'], 300),
                'created_at' => $now,
            ];
        }
        DB::table('setting_changes')->insert($insert);
    }

    /**
     * تسطيحُ القيمة للمقارنة — **بحرف السلوك القديم** (`SettingController::put`):
     * غيابُ الصفّ فراغٌ لا null، والمنطقيُّ «1»/«»، والخريطةُ JSON. تغييرُ هذا
     * يقلب معنى «تغيّرت» لكل مفتاحٍ في النظام.
     */
    protected static function wire($v): string
    {
        if ($v === null) return '';
        if (is_bool($v)) return $v ? '1' : '';
        if (is_scalar($v)) return (string) $v;

        return (string) json_encode($v, JSON_UNESCAPED_UNICODE);
    }

    /** قيمةٌ إلى عمود json — وnull تبقى null (لا صفَّ / حُذف الصفّ) */
    protected static function wireJson(?string $v): ?string
    {
        return $v === null ? null : (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * ما يُكتب في التدقيق والتاريخ. **السرُّ بصمةٌ لا نصّ** — والحكمُ بوسم
     * الكتالوج (`sensitive`) لا ببادئة `enc:` وحدها: صفٌّ قديمٌ لمفتاحٍ حسّاس
     * قد يكون نصّاً صريحاً في القاعدة، فبادئةُ التشفير وحدها كانت تُمرّره.
     */
    protected static function mask(string $key, string $v): string
    {
        if ($v === '') return '';
        if (self::isSecret($key) || str_starts_with($v, 'enc:')) return Redactor::fingerprint($v);

        return (string) hub_fit($v, 200);
    }

    /* ═════════ (WP-9.4) التصدير والاستيراد الآمنان · spec §7.11 · §7.12 ═════════ */

    /** سقفُ مفاتيح ملفِّ الاستيراد — ملفٌّ أكبر ليس إعداداتٍ بل مَهرباً لاستنزاف الطلب */
    public const MAX_IMPORT_KEYS = 500;

    /**
     * **المفاتيحُ القابلة للنقل بين التنصيبات.**
     *
     * خمسةُ استبعاداتٍ لا اجتهادَ فيها: السرُّ (لا يُصدَّر ولا يُستورد — وهو
     * مشفَّرٌ بمفتاح تطبيقٍ لا يعمل هناك أصلاً)، وصفُّ الحالة (بادئةٌ صريحة)،
     * والداخليُّ الذي لم يُعلَن `exportable` (سياسةُ الاحتفاظ نعم، وقفلُ الطوارئ لا)،
     * و**ما تملكه شاشةٌ أخرى** (‏`readonly`): `maintenance.on` معروضٌ في الكتالوج
     * لكنّ بابَه مركزُ التشغيل — فاستيرادُه من ملفٍّ نصّي يرفع صيانةَ التنصيب
     * الجديد بلا تأكيدِ هويةٍ ولا رسالةٍ ولا أثر. **وما لا تكتبه هذه الشاشةُ لا
     * يكتبه ملفُّها.**
     *
     * والخامسُ نوعُ `img`: قيمتُه ليست إعداداً بل **مسارُ ملفٍّ على قرص هذا
     * التنصيب**. نقلُه يكتب مساراً لا ملفَّ خلفه — شعارٌ مكسورٌ في الشريط الجانبي
     * وبطاقةِ الدخول وترويسةِ كلّ عرضِ سعرٍ وأمرِ شراءٍ يُطبع للعميل. وهو العيبُ
     * نفسُه الذي تُستبعد لأجله صفوفُ الحالة: قيمةٌ صادقةٌ هنا تكذب هناك.
     */
    public static function exportableKeys(): array
    {
        $out = [];
        foreach (self::exposedKeys() as $k) {
            if (self::isSecret($k) || self::isState($k) || self::isReadonly($k)) continue;
            if (in_array((string) (self::entry($k)['type'] ?? 'text'), ['img', 'pass'], true)) continue;
            $out[] = $k;
        }
        foreach (self::internal() as $k => $meta) {
            if (! $meta['exportable'] || $meta['sensitive'] || self::isState($k)) continue;
            $out[] = $k;
        }
        $out = array_values(array_unique($out));
        sort($out);                                   // ترتيبٌ حتميّ: ملفّان لتنصيبين متطابقين يتطابقان

        return $out;
    }

    /**
     * حمولةُ التصدير: **ما ضُبط فعلاً** لا الكتالوجَ كلَّه.
     *
     * مفتاحٌ بلا صفٍّ مصدرُه `default`، وتصديرُه ثم استيرادُه يكتب له صفّاً
     * فيتحوّل مصدرُه إلى `database` بلا أن يغيّر أحدٌ شيئاً — فيضيع الفرقُ بين
     * «تُرك على افتراضيّه» و«ضُبط عمداً على قيمة الافتراضيّ». فلا يُصدَّر إلا
     * ما له صفّ.
     *
     * وحزامٌ ثانٍ فوق الكتالوج: أيُّ قيمةٍ ببادئة `enc:` تُستبعد ولو لم يكن
     * مفتاحُها موسوماً `sensitive` — صفٌّ قديمٌ لمفتاحٍ وُسم متأخّراً لا يمرّ.
     */
    public static function exportPayload(): array
    {
        $rows = self::rows();
        $out = [];
        $enc = [];

        foreach (self::exportableKeys() as $k) {
            if (! array_key_exists($k, $rows)) continue;
            $v = self::flat($rows[$k]);
            if ($v === null || $v === '') continue;
            if (str_starts_with($v, 'enc:')) { $enc[] = $k; continue; }
            $out[$k] = $v;
        }
        ksort($out);

        return [
            'exported_at' => now()->toIso8601String(),
            'version'     => (string) config('hub.version'),
            'settings'    => $out,
            // **أسماءٌ لا قيم**: أسماءُ الأسرار معلنةٌ في الكتالوج أصلاً، وذكرُها
            // هنا يقول للمستورِد «هذه لم تأتِ معك — اضبطها من شاشتها» بدل أن
            // يكتشف صمتَها بعد أسبوع.
            'excluded'    => [
                'sensitive'      => self::secrets(),
                'state_prefixes' => self::STATE_PREFIXES,
                'encrypted_rows' => $enc,
            ],
        ];
    }

    /**
     * **خطّةُ الاستيراد** — الخطواتُ ٢ إلى ٤ (تحقّقُ المفاتيح · الفرق · وسمُ
     * الخطر) في دالّةٍ واحدة **لا تكتب شيئاً**. التطبيقُ خطوةٌ لاحقةٌ بتأكيد.
     *
     * @param  array $incoming  خريطةُ `مفتاح => قيمة` كما جاءت من الملف
     * @return array{ok:array, bad:array, same:array, risky:bool, count:int}
     */
    public static function importPlan(array $incoming): array
    {
        $allowed = array_flip(self::exportableKeys());
        $rows = self::rows();
        $ok = [];
        $bad = [];
        $same = [];
        $risky = false;
        $n = 0;

        foreach ($incoming as $rawKey => $value) {
            if (++$n > self::MAX_IMPORT_KEYS) {
                $bad[] = ['key' => '…', 'why' => 'تجاوز الملفُّ سقفَ ' . self::MAX_IMPORT_KEYS
                    . ' مفتاحاً — قُصّ ما بعده ولم يُقرأ'];
                break;
            }

            $key = trim((string) $rawKey);
            $short = (string) hub_fit($key, 120);
            if ($key === '' || $key !== $short) {
                $bad[] = ['key' => $short, 'why' => 'مفتاحٌ فارغٌ أو أطولُ من عرض عموده (١٢٠ حرفاً)'];
                continue;
            }
            if ($value === null) {
                $bad[] = ['key' => $key, 'why' => 'قيمةٌ خالية (null) — الاستيرادُ يضبط ولا يحذف صفّاً'];
                continue;
            }

            // **السرُّ أولاً**: قبل الكتالوج وقبل الصيغة — فلا يمرّ بأي بابٍ آخر
            if (self::isSecret($key)) {
                $bad[] = ['key' => $key, 'why' => 'مفتاحٌ حسّاس — لا يُستورد أبداً؛ اضبطه من شاشته بعد النقل'];
                continue;
            }
            if (self::isState($key)) {
                $bad[] = ['key' => $key, 'why' => 'صفُّ حالةٍ تشغيلية يكتبه النظامُ عن نفسه — نقلُه يكذب على المراقبة'];
                continue;
            }
            if (self::isReadonly($key)) {
                $bad[] = ['key' => $key, 'why' => 'رايةُ تشغيلٍ تملكها شاشةٌ أخرى — تُبدَّل من شاشتها '
                    . 'بتأكيدِ هويةٍ ورسالةٍ وأثرٍ في التدقيق، لا من ملفّ'];
                continue;
            }
            if (! isset($allowed[$key])) {
                $bad[] = ['key' => $key, 'why' => 'لا مدخلَ له في الكتالوج (أو داخليٌّ غيرُ مُعلَنٍ قابلاً للنقل) — لا قارئَ له هنا'];
                continue;
            }

            $after = self::flat($value);
            if ($after === null) {
                $bad[] = ['key' => $key, 'why' => 'قيمةٌ لا تُسطَّح إلى نصّ'];
                continue;
            }
            if (str_starts_with($after, 'enc:')) {
                $bad[] = ['key' => $key, 'why' => 'قيمةٌ مشفَّرةٌ بمفتاح تطبيقٍ آخر — لن تُفكّ هنا'];
                continue;
            }
            if (($why = self::validate($key, $after)) !== null) {
                $bad[] = ['key' => $key, 'why' => $why];
                continue;
            }

            $before = array_key_exists($key, $rows) ? (string) self::flat($rows[$key]) : '';
            if ($before === $after) { $same[] = $key; continue; }

            $isRisky = self::isHighRisk($key);
            $risky = $risky || $isRisky;
            $ok[] = ['key' => $key, 'label' => (string) (self::entry($key)['label'] ?? $key),
                     'before' => $before, 'after' => $after, 'risky' => $isRisky];
        }

        // ترتيبٌ حتميّ بالمفتاح — الشاشةُ والاختبارُ يريان القائمةَ نفسَها دائماً
        usort($ok, fn ($a, $b) => strcmp($a['key'], $b['key']));
        usort($bad, fn ($a, $b) => strcmp($a['key'], $b['key']));
        sort($same);

        return ['ok' => $ok, 'bad' => $bad, 'same' => $same, 'risky' => $risky,
                'count' => count($ok) + count($bad) + count($same)];
    }

    /* ═════ (WP-9.3) المعاينة · استعادةُ الافتراضي · تحقّقُ التبعية · spec §7.7–§7.9 ═════ */

    /**
     * (WP-9.3) فعلُ **استعادة الافتراضي** — فعلٌ مستقلٌّ لا صنفٌ من «تعديل»:
     * قارئُ السجلّ يحتاج أن يفرّق بين «ضبطه المالكُ إلى كذا» و«أعاده إلى ما
     * تسقط إليه الشيفرة». مسجَّلٌ في `SecurityEvents::CODES` (‏`SETTINGS_RESTORED`)،
     * ومفتاحٌ أمنيٌّ يرفعه إلى `SECURITY_POLICY_CHANGED` بالنمط نفسِه الذي يرفع
     * التعديل — فاستعادةُ `auth.pw_min` تغييرُ سياسةٍ بكل معنى.
     */
    public const RESTORE_ACTION = 'استعادة افتراضي الإعدادات';

    /**
     * **مجموعاتُ التبعية** — قواعدُ «لا يصحّ هذا وحدَه» التي يفرضها كودٌ قائم
     * ويفقدها الحفظُ المفرَد في شاشة الإعدادات.
     *
     * لكل مجموعةٍ: `keys` المفاتيحُ التي **يُسلّح مسُّها** فحصَ المجموعة (فلا
     * تُحاسَب دفعةٌ على مجموعةٍ لم تلمسها)، و`label` اسمُها في الرسالة، و`why`
     * القارئُ الذي يجعل القاعدةَ قاعدةً لا رأياً.
     *
     * **ولا قاعدةَ بلا قارئ:** `notify.quiet` مبذورٌ في `CoreSeeder` ولا يقرؤه
     * سطرٌ واحدٌ في `app/` — فلا مجموعةَ له هنا. اختراعُ قاعدةٍ له كان سيمنع
     * حفظاً مشروعاً باسم اتّساقٍ لا يقرؤه أحد.
     */
    public const DEPENDS = [
        'mail' => [
            'label' => 'بريد النظام (SMTP)',
            'keys'  => ['mail.host', 'mail.port', 'mail.encryption', 'mail.username',
                        'mail.password', 'mail.from_address', 'mail.from_name'],
            'why'   => 'MailSettings::apply — يُشعلها `mail.host` وحدَه ثم تغلب .env كلَّه',
        ],
        'odoo' => [
            'label' => 'تكامل أودو',
            'keys'  => ['odoo.url', 'odoo.db', 'odoo.user', 'odoo.key'],
            'why'   => 'Odoo::ready يشترط الأربعة · hub_outbound_ok يحرس الوجهة في مركز التكامل',
        ],
        'telegram' => [
            'label' => 'تلجرام',
            'keys'  => ['notify.tg_token', 'notify.tg_chat'],
            'why'   => 'HubOutbox::tg — يرمي «إعداد notify.tg_token فارغ» قبل أن ينظر إلى القناة',
        ],
        'work_hours' => [
            'label' => 'ساعات العمل',
            'keys'  => ['sec.hours_start', 'sec.hours_end', 'sec.strict_from'],
            'why'   => 'Middleware/WorkHours::handle — مقارنةٌ نصّيةٌ بين الوقتين ترسم نافذة التضييق',
        ],
        'risk_bands' => [
            'label' => 'نطاقات خطر الجلسة',
            'keys'  => ['risk.band_medium', 'risk.band_high', 'risk.band_critical'],
            'why'   => 'Risk::band — يقارن تنازلياً، فنطاقٌ لا يعلو سابقَه نطاقٌ ميّت',
        ],
    ];

    /** صيغةُ الوقت التي يشترطها `WorkHours` نصّاً: أربعُ خاناتٍ مصفَّرة */
    protected const HHMM_RE = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * **تحقّقُ التبعية على حالة ما بعد الحفظ.**
     *
     * `$pending` خريطةُ `مفتاح => القيمةُ المقصودة` (ما سيُكتب)، وما ليس فيها
     * يُقرأ من القاعدة ثم من الافتراضيّ — فالحكمُ على **ما سيسري**، لا على ما
     * في الطلب وحده. ولا تُفحص مجموعةٌ لم يمسّها الطلب: حالةٌ ناقصةٌ قديمةٌ في
     * البريد يجب ألّا تمنع تعديلَ اسم المنشأة.
     *
     * @param  array<string, mixed> $pending
     * @return array<string, string> اسمُ المجموعة ⇐ سببُ الرفض بالعربية
     */
    public static function dependencyErrors(array $pending): array
    {
        $rows = self::rows();

        // القيمةُ السارية بعد الحفظ — والمخزَّنُ الفارغ ليس قيمةً (‏`setting()`
        // تردّ الافتراضيَّ عند الفراغ) فيسقط إلى الافتراضيّ كما يسقط القارئ
        $val = function (string $k) use ($pending, $rows): string {
            if (array_key_exists($k, $pending)) return (string) (self::flat($pending[$k]) ?? '');
            $stored = array_key_exists($k, $rows) ? (string) (self::flat($rows[$k]) ?? '') : '';

            return $stored !== '' ? $stored : (string) (self::flat(self::defaultOf($k)) ?? '');
        };

        // «مضبوط» يشمل المضبوطَ من البيئة — **بلا قراءة قيمته**: قولُ «ناقص»
        // لمفتاحٍ يملؤه `.env` رفضٌ كاذب يمنع حفظاً صحيحاً
        $set = function (string $k) use ($val): bool {
            if ($val($k) !== '') return true;
            $meta = self::entry($k);

            return self::envSet((string) ($meta['env_key'] ?? ''));
        };

        $keys = array_keys($pending);
        $touched = fn (string $g) => array_intersect($keys, self::DEPENDS[$g]['keys']) !== [];

        $out = [];

        // ١) البريد: `mail.host` مفتاحُ الإشعال، وما بعده لا يُترك فارغاً — لأنّ
        //    `MailSettings::apply` تكتب المستخدمَ وكلمةَ المرور **فوق** ما في
        //    `.env`، فخادمٌ وحدَه يعني مُرسِلاً حيّاً بمستخدمٍ فارغ: كلُّ رسالةٍ
        //    تفشل بلا رسالةٍ واحدة. القاعدةُ منقولةٌ بحرفها من `MessagingController::mail`.
        if ($touched('mail') && $set('mail.host')) {
            $miss = [];
            foreach (['mail.port' => 'المنفذ', 'mail.encryption' => 'التشفير',
                      'mail.username' => 'مستخدم SMTP', 'mail.from_address' => 'عنوان المُرسِل'] as $k => $label) {
                if (! $set($k)) $miss[] = $label;
            }
            $from = $val('mail.from_address');
            $enc = mb_strtolower($val('mail.encryption'));
            $port = (int) $val('mail.port');

            if ($miss !== []) {
                $out['mail'] = 'بريد النظام: خادمُ SMTP مضبوطٌ وبقيّةُ المجموعة ناقصة ('
                    . implode(' · ', $miss) . ') — والمُرسِلُ يغلب .env فيفشل كلُّ بريدٍ صامتاً. أكمِلها أو أفرِغ الخادم.';
            } elseif ($from !== '' && ! filter_var($from, FILTER_VALIDATE_EMAIL)) {
                $out['mail'] = 'بريد النظام: «عنوان المُرسِل» ليس بريداً صالحاً — ومركزُ المراسلة يرفضه كذلك.';
            } elseif ($enc !== '' && ! in_array($enc, ['tls', 'ssl', 'none'], true)) {
                $out['mail'] = 'بريد النظام: التشفيرُ يكون tls أو ssl أو none — وأيُّ قيمةٍ غيرها تُعامل «بلا تشفير» فتمرّ كلمةُ المرور نصّاً.';
            } elseif ($port < 1 || $port > 65535) {
                $out['mail'] = 'بريد النظام: المنفذُ خارج المدى ١–٦٥٥٣٥ (‏٥٨٧ مع tls أو ٤٦٥ مع ssl).';
            }
        }

        // ٢) أودو: الأربعةُ معاً أو لا شيء (‏`Odoo::ready`)، **وحارسُ الوجهة**
        //    الذي يطبّقه مركزُ التكامل ولا تطبّقه هذه الشاشة — فرابطٌ داخليٌّ
        //    يحوّل الخادمَ مِجَسّاً على الشبكة الداخلية (SSRF).
        if ($touched('odoo')) {
            $labels = ['odoo.url' => 'الرابط', 'odoo.db' => 'قاعدة البيانات',
                       'odoo.user' => 'المستخدم', 'odoo.key' => 'مفتاح API'];
            $miss = [];
            $any = false;
            foreach ($labels as $k => $label) $set($k) ? $any = true : $miss[] = $label;

            if ($any && $miss !== []) {
                $out['odoo'] = 'تكامل أودو: الحقولُ الأربعة تُشترط مجتمعةً وينقصها ('
                    . implode(' · ', $miss) . ') — وبنقصان واحدٍ تختفي بطاقةُ أودو تماماً بلا رسالة.';
            } elseif ($any) {
                $gate = rescue(fn () => hub_outbound_ok($val('odoo.url')),
                    ['ok' => false, 'why' => 'تعذّر فحصُ الوجهة'], false);
                if (! ($gate['ok'] ?? false)) {
                    $out['odoo'] = 'تكامل أودو: الوجهةُ مرفوضةٌ من حارس الطلبات الصادرة — '
                        . (string) ($gate['why'] ?? '') . '. مركزُ التكامل يفرض الحارسَ نفسَه على الروابط.';
                }
            }
        }

        // ٣) تلجرام: التوكن قبل القناة — `HubOutbox` يرمي على التوكن الفارغ قبل
        //    أن ينظر إلى الوجهة، فقناةٌ بلا توكنٍ صفٌّ يعِد بتسليمٍ لا يقع
        if ($touched('telegram') && $val('notify.tg_chat') !== '' && ! $set('notify.tg_token')) {
            $out['telegram'] = 'تلجرام: قناةٌ افتراضيةٌ بلا توكن بوت — وعاملُ الصادر يرفض كلَّ رسالةٍ قبل أن ينظر إلى القناة.';
        }

        // ٤) ساعاتُ العمل: الصيغةُ أربعُ خاناتٍ (المقارنةُ نصّيةٌ في الوسيط)،
        //    وبدايةُ الدوام تسبق بدايةَ الوضع الصارم وإلا فالنافذةُ مقلوبةٌ
        //    والتضييقُ المسائيُّ ملغىً بلا إشعار
        if ($touched('work_hours')) {
            foreach (['sec.hours_start' => 'بداية الدوام', 'sec.hours_end' => 'نهاية الدوام',
                      'sec.strict_from' => 'بداية الوضع الصارم'] as $k => $label) {
                if (isset($out['work_hours'])) break;
                if ($val($k) !== '' && ! preg_match(self::HHMM_RE, $val($k))) {
                    $out['work_hours'] = 'ساعات العمل: «' . $label . '» بصيغة HH:MM بأربع خاناتٍ مصفَّرة — '
                        . '«8:00» تقلب المقارنة النصّية صمتاً فيبطل الوضعُ الصارم.';
                }
            }
            $start = $val('sec.hours_start');
            $strict = $val('sec.strict_from');
            if (! isset($out['work_hours']) && $start !== '' && $strict !== '' && strcmp($start, $strict) >= 0) {
                $out['work_hours'] = 'ساعات العمل: بدايةُ الدوام (' . $start . ') يجب أن تسبق بدايةَ الوضع الصارم ('
                    . $strict . ') — وإلا فكلُّ ساعةٍ «خارج الدوام» ولا نافذةَ عملٍ طبيعيةٍ إطلاقاً.';
            }
        }

        // ٥) نطاقاتُ الخطر: `Risk::band` يقارن تنازلياً (حرج ← عالٍ ← متوسط)،
        //    فنطاقٌ لا يعلو سابقَه لا يُصنَّف فيه شيءٌ أبداً — عتبةٌ ميّتة
        if ($touched('risk_bands')) {
            $m = (float) $val('risk.band_medium');
            $h = (float) $val('risk.band_high');
            $c = (float) $val('risk.band_critical');
            if (! ($m < $h && $h < $c)) {
                $out['risk_bands'] = 'نطاقات الخطر: يجب أن تصعد متوسط < عالٍ < حرج (الآن ' . $m . ' · ' . $h . ' · ' . $c
                    . ') — ونطاقٌ لا يعلو سابقَه لا يُصنَّف فيه شيءٌ أبداً.';
            }
        }

        return $out;
    }

    /**
     * **استعادةُ افتراضيّ مفتاحٍ معروض — كتابةٌ لا حذف** (critic #7).
     *
     * حذفُ الصفّ كان الاقتراحَ الأول (كما تفعل شاشةُ الأمن لرايات الطوارئ)، وهو
     * صحيحٌ لمفتاحٍ افتراضيُّه «مطفأ». لكنّ `sec.hours_on` و`sec.strict_files`
     * افتراضيُّهما **مُشغَّل**، و`setting()` تردّ الافتراضيَّ عند غياب الصفّ —
     * فحذفُ صفِّ حارسٍ أطفأه المالكُ عمداً **يُعيد إشعالَه**. وهو نفسُ العيب
     * الذي أغلقه `SettingController` بتخزين «0» صراحةً بدل الفراغ.
     *
     * فتُكتب هنا قيمةُ `default` المُعلَنة في الكتالوج (WP-9.1). **والاستثناءُ
     * الوحيد** نوعا `img` و`pass`: لا افتراضيَّ يُكتب لمسار صورةٍ، ولا يُخزَّن
     * «سرٌّ افتراضيّ» في القاعدة مشفَّراً ليُقرأ بعدُ بوصفه إرادةَ المالك —
     * فيُفرَّغ صفُّهما ويعود القارئُ إلى أرضيّته.
     *
     * ومفتاحٌ **بلا صفٍّ أصلاً** على افتراضيّه بالفعل: لا كتابةَ له ولا صفَّ
     * تاريخ (وإلا لانتفخ عدّادُ «المُغيَّر عن الافتراضي» بكلّ استعادةِ مجموعة).
     *
     * @return bool هل تبدّل شيءٌ فعلاً
     */
    public static function restore(string $key, ?string $reason = null): bool
    {
        $meta = self::entry($key);
        if ($meta === null) return false;               // غيرُ معروضٍ: لا افتراضيَّ مُعلَنَ له
        if (! array_key_exists($key, self::rows())) return false;

        $type = (string) ($meta['type'] ?? 'text');
        if ($type === 'img' || $type === 'pass') return self::forget($key, 'restore', $reason);

        return self::put($key, (string) (self::flat($meta['default'] ?? null) ?? ''), 'restore', $reason);
    }

    /** القيمةُ التي تعيدها الاستعادةُ لمفتاح — للمعاينة وللتحقّق قبل الكتابة */
    public static function restoreValue(string $key): string
    {
        $meta = self::entry($key);
        if ($meta === null) return '';
        $type = (string) ($meta['type'] ?? 'text');

        return in_array($type, ['img', 'pass'], true) ? '' : (string) (self::flat($meta['default'] ?? null) ?? '');
    }

    /**
     * **فرقُ `A → B`** لخطّةِ حفظٍ مقصودة — بلا كتابةٍ ولا أثرٍ ولا إبطالِ خبيئة.
     *
     * يُعيد **ما يتبدّل فعلاً** لا ما أُرسل: حفظُ ٩٥ مفتاحاً بقيمها نفسِها ليس
     * خمسةً وتسعين تغييراً. والمقارنةُ بحرف `commit` نفسِه (‏`flat` على الصفّ)
     * فلا تعِد المعاينةُ بما لا يقع الحفظُ عليه.
     *
     * **والسرُّ لا يُقارَن بقيمته:** المخزَّنُ مشفَّرٌ والوارد صريح، فيُقال
     * «مضبوط/غير مضبوط» للطرف القديم وبصمةٌ (`Redactor::fingerprint`) للجديد —
     * ولا تخرج قيمةُ سرٍّ من هنا إلى شاشةٍ ولا إلى جلسة.
     *
     * @param  array<string, mixed> $map  مفتاح ⇐ القيمةُ المقصودة
     * @return list<array{key:string, label:string, group:string, before:string, after:string, risky:bool, secret:bool}>
     */
    public static function diff(array $map): array
    {
        $rows = self::rows();
        $groupOf = [];
        foreach (self::catalog() as $gLabel => $items) foreach (array_keys($items) as $k) $groupOf[$k] = $gLabel;

        $out = [];
        foreach ($map as $key => $value) {
            $key = (string) $key;
            $meta = self::entry($key);
            $secret = self::isSecret($key);
            $old = array_key_exists($key, $rows) ? (string) (self::flat($rows[$key]) ?? '') : '';
            $new = (string) (self::flat($value) ?? '');

            if ($secret) {
                if ($new === '') continue;              // فارغٌ يُبقي المخزون — ليس تغييراً
                $before = $old === '' ? 'غير مضبوط' : 'مضبوط';
                $after = 'قيمةٌ جديدة · بصمتُها ' . Redactor::fingerprint($new);
            } else {
                if ($old === $new) continue;
                $before = $old === '' ? '— (لا صفّ)' : (string) hub_fit($old, 120);
                $after = $new === '' ? '— (يعود للافتراضي)' : (string) hub_fit($new, 120);
            }

            $out[] = [
                'key'    => $key,
                'label'  => (string) ($meta['label'] ?? $key),
                'group'  => (string) ($groupOf[$key] ?? ''),
                'before' => $before,
                'after'  => $after,
                'risky'  => self::isHighRisk($key),
                'secret' => $secret,
            ];
        }

        // ترتيبٌ حتميّ: الخطرُ أولاً ثم بالمفتاح — الشاشةُ والاختبارُ يريان الترتيبَ نفسَه
        usort($out, fn ($a, $b) => ($b['risky'] <=> $a['risky']) ?: strcmp($a['key'], $b['key']));

        return $out;
    }
}
