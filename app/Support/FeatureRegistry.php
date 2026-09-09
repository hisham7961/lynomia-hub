<?php

namespace App\Support;

/**
 * **سجلُّ القدرات — مصدرُ الحقيقةِ الواحد** (§2/§6/§10/§11).
 *
 * كتالوجٌ كوديٌّ (`config/hub_features.php`) يحمل **الحقيقةَ البنيويّة** لكلِّ قدرة، وهذا
 * الصنفُ **يشتقُّ حالتَها السارية** من ثلاثةِ مصادرَ لا يكرّرها: الكودُ (الحالةُ المُعلَنة)،
 * والإعداداتُ (`Settings` — رايةُ التبديل)، وفاحصاتُ الاتصال/الحالةِ القائمة (`PushService`
 * · `MobilePlatform` · `MdmService` · `EdgeDefense` · `Integrations`). لا محرّكَ أعلامٍ ثانٍ،
 * ولا كاتبَ إعداداتٍ ثانٍ: التبديلُ عبر `Settings::put(..., 'features', ...)` حصراً.
 *
 * **التوافرُ ≠ الصلاحية:** هذا الصنفُ يجيب «أمتاحةٌ القدرة؟» فقط؛ «أمصرَّحٌ للمستخدم؟» يبقى
 * لـ`hub_can`+الحرّاس. والفحصان مستقلّان — كلاهما يجب أن يمرّ.
 */
class FeatureRegistry
{
    /** بابُ كتابةِ الإعدادات لتبديلِ القدرات — مُعلَنٌ في `Settings::WRITERS` */
    public const WRITER = 'features';

    /** ذاكرةُ الطلبِ لِلحالاتِ المُشتقّة — خفّةٌ (§21): لا إعادةَ اشتقاقٍ لكلِّ استدعاء */
    private static ?array $resolved = null;

    /** القيمُ الافتراضيّةُ لمدخلِ قدرةٍ — فلا يذكر الكتالوجُ إلا ما يختلف */
    private const DEFAULTS = [
        'domain' => 'platform', 'category' => '', 'title_ar' => '', 'title_en' => '',
        'desc_ar' => '', 'desc_en' => '', 'status' => FeatureStatus::ENABLED, 'derive' => null,
        'toggleable' => false, 'setting_key' => null, 'security_class' => null,
        'depends' => [], 'optional_depends' => [], 'external_depends' => [], 'provider' => null,
        'permissions' => [], 'account_types' => ['internal'], 'admin_surface' => null,
        'web_routes' => [], 'api_routes' => [], 'mobile_backend' => 'not_applicable',
        'native_mobile' => 'not_applicable', 'openapi' => 'not_applicable', 'introduced' => null,
        'docs' => null, 'limitations' => null, 'deferred_notes' => null,
    ];

    /* ───────────────────────── الكتالوج ───────────────────────── */

    /** بيانات المجالات (مفتاح ⇐ {label_ar,label_en,icon,order,ia_domain}) */
    public static function domains(): array
    {
        return (array) config('hub_features.domains', []);
    }

    /** مدخلاتُ الكتالوج الخام (قبل الاشتقاق) — مفتاح ⇐ مصفوفة */
    public static function raw(): array
    {
        return (array) config('hub_features.features', []);
    }

    /** كلُّ مفاتيحِ القدرات */
    public static function keys(): array
    {
        return array_keys(self::raw());
    }

    /** مدخلٌ مُطبَّعٌ بالقيم الافتراضيّة (بلا اشتقاقِ حالة) — أو null */
    public static function entry(string $key): ?array
    {
        $raw = self::raw();
        if (! isset($raw[$key])) return null;

        return array_merge(self::DEFAULTS, ['key' => $key], (array) $raw[$key]);
    }

    /* ───────────────────── اشتقاقُ الحالة السارية ───────────────────── */

    /**
     * الحالةُ السارية لقدرةٍ + سببُها: تُشتقُّ من الكود/الإعدادات/الفاحصات، ثم
     * تُخفَّض إن عطّلها اعتمادٌ إلزاميٌّ غائب.
     *
     * @return array{status:string, reason:string}
     */
    public static function status(string $key): array
    {
        $all = self::resolveAll();

        return $all[$key] ?? ['status' => FeatureStatus::DEVELOPMENT, 'reason' => 'قدرةٌ غيرُ مسجَّلة'];
    }

    /** هل القدرةُ متاحةٌ تشغيليّاً الآن؟ (التوافرُ لا الصلاحية) */
    public static function available(string $key): bool
    {
        return FeatureStatus::isAvailable(self::status($key)['status']);
    }

    /** قدرةٌ اختياريّةٌ (قابلةٌ للتبديل) وليست ثابتاً نظاميّاً؟ */
    public static function isToggleable(string $key): bool
    {
        $e = self::entry($key);

        return $e !== null && $e['toggleable'] && $e['security_class'] !== 'invariant'
            && (string) ($e['setting_key'] ?? '') !== '';
    }

    /**
     * يشتقُّ حالاتِ كلِّ القدرات مرّةً لكلِّ طلب (مع خفضِ الاعتماديّة) — {key ⇒ {status,reason}}.
     */
    public static function resolveAll(): array
    {
        if (self::$resolved !== null) return self::$resolved;

        $base = [];
        foreach (self::keys() as $key) {
            $base[$key] = self::deriveOne(self::entry($key));
        }

        // خفضُ الاعتماديّة: قدرةٌ متاحةٌ يعطّلها اعتمادٌ إلزاميٌّ غيرُ متاح ⇒ NOT_CONFIGURED
        foreach ($base as $key => &$st) {
            if (! FeatureStatus::isAvailable($st['status'])) continue;
            if ($st['status'] === FeatureStatus::SYSTEM_INVARIANT) continue;   // الثوابتُ لا تُخفَّض
            foreach ((array) (self::entry($key)['depends'] ?? []) as $dep) {
                $depSt = $base[$dep] ?? null;
                if ($depSt !== null && ! FeatureStatus::isAvailable($depSt['status'])) {
                    $st = ['status' => FeatureStatus::NOT_CONFIGURED,
                           'reason' => 'يتوقّف على «' . self::title($dep) . '» وهي ' . FeatureStatus::labelAr($depSt['status'])];
                    break;
                }
            }
        }
        unset($st);

        return self::$resolved = $base;
    }

    /** يُفرِّغ ذاكرةَ الاشتقاق (بعد تبديلٍ يغيّر رايةً) — الاختباراتُ تستدعيه أيضاً */
    public static function flush(): void
    {
        self::$resolved = null;
    }

    /**
     * اشتقاقُ حالةِ قدرةٍ واحدةٍ من مصادرها (بلا خفضِ الاعتماديّة — تُطبَّق فوقُ).
     *
     * @return array{status:string, reason:string}
     */
    private static function deriveOne(array $e): array
    {
        // ١) الثابتُ الأمنيُّ يغلب كلَّ شيء — لا يصير مفتاحاً أبداً
        if ($e['security_class'] === 'invariant') {
            return ['status' => FeatureStatus::SYSTEM_INVARIANT,
                    'reason' => 'سلوكٌ أمنيٌّ/منصّيٌّ إلزاميّ — للرؤية فقط، لا يُطفأ'];
        }

        $declared = FeatureStatus::valid((string) $e['status']) ? (string) $e['status'] : FeatureStatus::DEVELOPMENT;

        // ٢) المؤجَّل/قيدَ التطوير حالةٌ بنيويّةٌ لا تُشتقُّ من فاحصٍ (التنفيذُ غائب)
        if (in_array($declared, [FeatureStatus::DEFERRED, FeatureStatus::DEVELOPMENT], true)) {
            return ['status' => $declared, 'reason' => self::declaredReason($e, $declared)];
        }

        // ٣) القابلُ للتبديل: الرايةُ تحسم مُفعَّل/مُطفأ (فوق الحالةِ المُعلَنة)
        if ($e['toggleable'] && (string) ($e['setting_key'] ?? '') !== '') {
            $on = self::flagOn((string) $e['setting_key'], $declared !== FeatureStatus::DISABLED);

            return $on
                ? ['status' => FeatureStatus::ENABLED, 'reason' => 'مُفعَّلةٌ من مركز القدرات']
                : ['status' => FeatureStatus::DISABLED, 'reason' => 'مُطفأةٌ عمداً من مركز القدرات'];
        }

        // ٤) الاشتقاقُ من فاحصٍ حيٍّ (لا تكرار)
        $derive = (string) ($e['derive'] ?? '');
        if ($derive !== '') return self::deriveFromSource($derive, $e, $declared);

        // ٥) وإلا: الحالةُ المُعلَنةُ كما هي
        return ['status' => $declared, 'reason' => self::declaredReason($e, $declared)];
    }

    /** سببُ حالةٍ مُعلَنةٍ ساكنة (READY/ENABLED/EXTERNAL…) */
    private static function declaredReason(array $e, string $status): string
    {
        return match ($status) {
            FeatureStatus::READY    => 'التنفيذُ مكتملٌ وخطوةٌ تاليةٌ (تفعيلٌ/مزوّد) غيرُ نشطةٍ عمداً',
            FeatureStatus::DEFERRED => (string) ($e['deferred_notes'] ?? 'مؤجَّلةٌ عمداً في هذا النطاق'),
            FeatureStatus::EXTERNAL => 'تعتمد أساساً على مزوّدٍ خارجيّ: ' . (string) ($e['provider'] ?? 'غير محدَّد'),
            FeatureStatus::ENABLED  => 'منفَّذةٌ ومُختبَرةٌ ومتاحة',
            default                 => FeatureStatus::labelAr($status),
        };
    }

    /**
     * يقرأ فاحصاً حيّاً ويعيد حالةً — **يقرأ لا يكرّر**. كلُّ فرعٍ محاطٌ بـ`rescue`
     * كي لا يُسقط جدولٌ غائبٌ مركزَ القدرات (تدهورٌ رشيق).
     */
    private static function deriveFromSource(string $derive, array $e, string $declared): array
    {
        // مزوّدُ الدفع (Production Push): الصدقُ من PushService::status()['configured']
        if ($derive === 'push') {
            $ok = (bool) rescue(fn () => PushService::status()['configured'] ?? false, false, false);

            return $ok
                ? ['status' => FeatureStatus::ENABLED, 'reason' => 'مزوّدُ الدفعِ مُهيَّأ']
                : ['status' => FeatureStatus::NOT_CONFIGURED, 'reason' => 'لا اعتماداتِ مزوّدِ دفعٍ إنتاجيّ (السائقُ الصفريُّ لا يزيّف نجاحاً)'];
        }

        // تنفيذُ USB (المرحلةُ الطرفيّة): بلا مزوّدِ MDM مُهيَّأً لا إنفاذ — النِّظامُ يرصد لا يحجب.
        // الإنفاذُ الفعليُّ لكلِّ سياسةٍ/جهازٍ عبر MDM؛ فالحالةُ العامّةُ READY حين يُهيَّأ MDM، وإلا NOT_CONFIGURED.
        if ($derive === 'usb.enforce') {
            $configured = (bool) rescue(fn () => MdmService::status()['configured'] ?? false, false, false);

            return $configured
                ? ['status' => FeatureStatus::READY, 'reason' => 'MDM مُهيَّأٌ — الإنفاذُ متاحٌ لكلِّ سياسةٍ/جهاز (رصدٌ افتراضاً)']
                : ['status' => FeatureStatus::NOT_CONFIGURED, 'reason' => 'لا مزوّدَ MDM مُهيَّأً — النظامُ يرصد USB ولا يحجب'];
        }

        // حجبُ الحافّة (IP): طبقةُ التطبيقِ نشطةٌ دائماً؛ الحافّةُ تحتاج مزوّداً
        if ($derive === 'edge') {
            $edge = rescue(fn () => (string) (EdgeDefense::status()['edge']['state'] ?? 'not_configured'), 'not_configured', false);

            return $edge === 'ready' || $edge === 'active'
                ? ['status' => FeatureStatus::EXTERNAL, 'reason' => 'مزوّدُ الحافّةِ مُهيَّأ — الإنفاذُ عند الحافّة']
                : ['status' => FeatureStatus::NOT_CONFIGURED, 'reason' => 'لا مزوّدَ حجبٍ عند الحافّة (الحجبُ الفعّالُ في طبقةِ التطبيق)'];
        }

        // تكاملٌ مُسمّى (أودو/تلجرام/بريد…): صحّةُ Integrations تُحدِّد
        if (str_starts_with($derive, 'integration:')) {
            $ik = substr($derive, strlen('integration:'));
            $health = (string) rescue(function () use ($ik) {
                foreach (Integrations::installed() as $row) {
                    if ((string) ($row['key'] ?? '') === $ik) return (string) ($row['health'] ?? Integrations::UNKNOWN);
                }

                return Integrations::UNKNOWN;
            }, Integrations::UNKNOWN, false);

            return self::fromIntegrationHealth($health, $e);
        }

        // مزوّدُ الزمنِ الحقيقيّ (WebSocket): العقدُ READY والمزوّدُ غيرُ مُهيَّأ (بلا بثٍّ)
        if ($derive === 'websocket') {
            return ['status' => FeatureStatus::NOT_CONFIGURED,
                    'reason' => 'عقدُ الأحداثِ جاهزٌ (READY) لكنْ لا مزوّدَ بثٍّ مُهيَّأ — النقلُ الحاليُّ استطلاع'];
        }

        // غيرُ معروف ⇒ الحالةُ المُعلَنة (لا تخمين)
        return ['status' => $declared, 'reason' => self::declaredReason($e, $declared)];
    }

    /** يحوّل صحّةَ Integrations إلى حالةِ قدرة */
    private static function fromIntegrationHealth(string $health, array $e): array
    {
        return match ($health) {
            Integrations::CONNECTED  => ['status' => FeatureStatus::ENABLED, 'reason' => 'التكاملُ متّصلٌ وسليم'],
            Integrations::DEGRADED   => ['status' => FeatureStatus::DEGRADED, 'reason' => 'التكاملُ يعمل بوضعٍ مُخفَّض'],
            Integrations::FAILED     => ['status' => FeatureStatus::DEGRADED, 'reason' => 'آخرُ محاولةِ اتّصالٍ فشلت'],
            Integrations::DISABLED   => ['status' => FeatureStatus::DISABLED, 'reason' => 'مُطفأٌ عمداً'],
            default                  => ['status' => FeatureStatus::NOT_CONFIGURED,
                                         'reason' => 'يحتاج إعداداً: ' . (string) ($e['provider'] ?? 'مزوّدٌ خارجيّ')],
        };
    }

    /** رايةُ إعدادٍ مرفوعة؟ — عبر `Settings` (لا محرّكَ أعلامٍ ثانٍ). $default حين لا صفّ */
    private static function flagOn(string $key, bool $default): bool
    {
        $stored = rescue(fn () => Settings::flat(Settings::rows()[$key] ?? null), null, false);
        if ($stored === null || $stored === '') return $default;   // لا صفّ ⇒ الافتراضيّ المُعلَن

        return $stored === '1';
    }

    /* ───────────────────── استعلاماتُ العرض ───────────────────── */

    /** كلُّ القدرات مُطبَّعةً + حالتُها السارية، بترتيبِ (المجال، ثم المفتاح) */
    public static function all(): array
    {
        $resolved = self::resolveAll();
        $domainsOrder = self::domainOrder();

        $out = [];
        foreach (self::keys() as $key) {
            $e = self::entry($key);
            $e['status'] = $resolved[$key]['status'];
            $e['reason'] = $resolved[$key]['reason'];
            $e['available'] = FeatureStatus::isAvailable($e['status']);
            $e['toggleable_now'] = self::isToggleable($key);
            $out[$key] = $e;
        }

        uasort($out, function ($a, $b) use ($domainsOrder) {
            $da = $domainsOrder[$a['domain']] ?? 999;
            $db = $domainsOrder[$b['domain']] ?? 999;

            return [$da, (string) $a['title_ar']] <=> [$db, (string) $b['title_ar']];
        });

        return $out;
    }

    /** القدرات مجمَّعةً بالمجال (بترتيبِ المجالات) — للعرضِ لا الجدولِ الواحد */
    public static function byDomain(): array
    {
        $all = self::all();
        $domains = self::domains();
        $groups = [];
        foreach (array_keys($domains) as $dk) $groups[$dk] = ['meta' => $domains[$dk], 'features' => []];
        foreach ($all as $key => $f) {
            $dk = $f['domain'];
            if (! isset($groups[$dk])) $groups[$dk] = ['meta' => ['label_ar' => $dk, 'label_en' => $dk, 'icon' => '•', 'order' => 999], 'features' => []];
            $groups[$dk]['features'][$key] = $f;
        }

        return array_filter($groups, fn ($g) => $g['features'] !== []);
    }

    /** عدُّ القدرات لكلِّ حالة (لبطاقاتِ الملخّص) — كلُّ الحالاتِ التسع حاضرة */
    public static function counts(): array
    {
        $out = array_fill_keys(FeatureStatus::ALL, 0);
        foreach (self::resolveAll() as $st) $out[$st['status']] = ($out[$st['status']] ?? 0) + 1;
        $out['total'] = array_sum($out);

        return $out;
    }

    /** مدخلٌ كاملٌ محلولٌ لقدرةٍ واحدة (للتفصيل) — أو null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** بحثٌ حرّ: الاسمُ العربيّ/الإنجليزيّ/المفتاح/المجال/الحالة/الاعتماد/المزوّد */
    public static function search(string $q): array
    {
        $q = mb_strtolower(trim($q));
        if ($q === '') return self::all();

        return array_filter(self::all(), function ($f) use ($q) {
            $hay = mb_strtolower(implode(' ', [
                $f['key'], $f['title_ar'], $f['title_en'], $f['domain'], $f['status'],
                implode(' ', (array) $f['depends']), implode(' ', (array) $f['external_depends']),
                (string) ($f['provider'] ?? ''),
            ]));

            return str_contains($hay, $q);
        });
    }

    /* ───────────────────── الاعتماديّاتُ والحواجز ───────────────────── */

    /**
     * حواجزُ قدرةٍ: اعتماديّاتُها الإلزاميّةُ غيرُ المتاحة + الخارجيّةُ المطلوبة.
     *
     * @return array{blocking:array, external:array}
     */
    public static function blockers(string $key): array
    {
        $e = self::entry($key);
        if ($e === null) return ['blocking' => [], 'external' => []];

        $blocking = [];
        foreach ((array) $e['depends'] as $dep) {
            $st = self::status($dep);
            if (! FeatureStatus::isAvailable($st['status'])) {
                $blocking[] = ['key' => $dep, 'title' => self::title($dep), 'status' => $st['status']];
            }
        }

        return ['blocking' => $blocking, 'external' => (array) $e['external_depends']];
    }

    /** عنوانُ قدرةٍ (عربيٌّ) للعرضِ في الرسائل — أو المفتاحُ إن غابت */
    public static function title(string $key): string
    {
        $e = self::raw()[$key] ?? null;

        return $e === null ? $key : (string) ($e['title_ar'] ?? $key);
    }

    /** ترتيبُ المجالات (مفتاح ⇒ order) */
    private static function domainOrder(): array
    {
        $out = [];
        foreach (self::domains() as $k => $d) $out[$k] = (int) ($d['order'] ?? 999);

        return $out;
    }

    /* ───────────────────── التبديلُ الآمن (عبر Settings) ───────────────────── */

    /**
     * يبدّل قدرةً اختياريّةً — **عبر `Settings::put` حصراً** (كاتبٌ واحد + تاريخٌ + تدقيق).
     * يرفض ما ليس اختياريّاً (ثابتٌ نظاميّ/غيرُ قابلٍ للتبديل) وما لا يجوز انتقالُه.
     *
     * @throws \InvalidArgumentException حين يُمنع التبديل
     */
    public static function setEnabled(string $key, bool $on, ?string $reason = null): void
    {
        $e = self::entry($key);
        if ($e === null) throw new \InvalidArgumentException('قدرةٌ غيرُ مسجَّلة: ' . $key);

        if ($e['security_class'] === 'invariant') {
            throw new \InvalidArgumentException('ثابتٌ نظاميٌّ لا يُطفأ — للرؤية فقط');
        }
        if (! self::isToggleable($key)) {
            throw new \InvalidArgumentException('هذه القدرةُ غيرُ قابلةٍ للتبديل الزمنيّ');
        }

        $current = self::status($key)['status'];
        $target = $on ? FeatureStatus::ENABLED : FeatureStatus::DISABLED;
        // منعُ الانتقالِ غيرِ المشروع (NOT_CONFIGURED/DEFERRED → ENABLED)
        if ($current !== $target && ! FeatureStatus::transitionAllowed($current, $target)
            && ! in_array($current, [FeatureStatus::ENABLED, FeatureStatus::DISABLED], true)) {
            throw new \InvalidArgumentException(
                'انتقالٌ غيرُ مشروع: ' . FeatureStatus::labelAr($current) . ' → ' . FeatureStatus::labelAr($target));
        }

        // «0» صريحةٌ للإطفاء لا «» (فالفراغُ يلتبس بالغياب الذي يعني الافتراضيَّ المُفعَّل)
        Settings::put((string) $e['setting_key'], $on ? '1' : '0', self::WRITER,
            $reason ?? ('تبديلُ قدرة: ' . $e['title_ar']));
        self::flush();
    }

    /* ───────────────────── تحقّقُ النزاهة (للاختبار) ───────────────────── */

    /**
     * مشاكلُ الكتالوجِ البنيويّة — قائمةٌ فارغةٌ حين يصحّ كلُّ شيء. يحرسها اختبارُ النزاهة:
     * مفاتيحُ مكرّرة · حالةٌ غيرُ مشروعة · مجالٌ مجهول · اعتمادٌ مجهول · دورةُ اعتماد ·
     * عنوانٌ عربيٌّ/إنجليزيٌّ ناقص · قابلٌ للتبديلِ بلا مفتاحِ إعدادٍ · ثابتٌ نظاميٌّ مُعلَنٌ
     * قابلاً للتبديل · مؤجَّلٌ مُعلَنٌ مُفعَّلاً · مفتاحُ إعدادٍ غيرُ مُعلَنٍ في كتالوج الإعدادات.
     *
     * @return array<int,string>
     */
    public static function integrity(): array
    {
        $problems = [];
        $domains = self::domains();
        $raw = self::raw();
        $keys = array_keys($raw);

        foreach ($raw as $key => $r) {
            $e = array_merge(self::DEFAULTS, (array) $r);
            $where = "«{$key}»";

            if (! FeatureStatus::valid((string) $e['status'])) {
                $problems[] = "$where: حالةٌ غيرُ مشروعة ({$e['status']})";
            }
            if (! isset($domains[$e['domain']])) {
                $problems[] = "$where: مجالٌ مجهول ({$e['domain']})";
            }
            if (trim((string) $e['title_ar']) === '') $problems[] = "$where: عنوانٌ عربيٌّ ناقص";
            if (trim((string) $e['title_en']) === '') $problems[] = "$where: عنوانٌ إنجليزيٌّ ناقص";

            // اعتماديّاتٌ معروفة
            foreach (array_merge((array) $e['depends'], (array) $e['optional_depends']) as $dep) {
                if (! in_array($dep, $keys, true)) $problems[] = "$where: اعتمادٌ مجهول ($dep)";
            }

            // ثابتٌ نظاميٌّ لا يكون قابلاً للتبديل
            if (($e['security_class'] ?? null) === 'invariant' && ! empty($e['toggleable'])) {
                $problems[] = "$where: ثابتٌ نظاميٌّ مُعلَنٌ قابلاً للتبديل";
            }
            // القابلُ للتبديلِ يحتاج مفتاحَ إعدادٍ **مُعلَناً في كتالوج الإعدادات** (لا مفتاحٌ يتيم)
            if (! empty($e['toggleable'])) {
                $sk = (string) ($e['setting_key'] ?? '');
                if ($sk === '') {
                    $problems[] = "$where: قابلٌ للتبديلِ بلا مفتاحِ إعداد";
                } elseif (! self::settingKeyDeclared($sk)) {
                    $problems[] = "$where: مفتاحُ الإعداد ($sk) غيرُ مُعلَنٍ في كتالوج الإعدادات (مفتاحٌ يتيم)";
                }
            }
            // المؤجَّلُ/قيدَ التطوير لا يُعلَن قابلاً للتبديل (لا تنفيذَ ليُفعَّل)
            if (in_array((string) $e['status'], [FeatureStatus::DEFERRED, FeatureStatus::DEVELOPMENT], true) && ! empty($e['toggleable'])) {
                $problems[] = "$where: {$e['status']} مُعلَنٌ قابلاً للتبديل (لا تنفيذَ ليُفعَّل)";
            }
        }

        // مفاتيحُ مكرّرة (config تمنعها بنيويّاً، لكن نحرس صراحةً)
        if (count($keys) !== count(array_unique($keys))) $problems[] = 'مفاتيحُ قدراتٍ مكرّرة';

        // دوراتُ الاعتماد
        foreach (self::cycles() as $cycle) $problems[] = 'دورةُ اعتماد: ' . implode(' → ', $cycle);

        return $problems;
    }

    /** مفتاحُ إعدادٍ مُعلَنٌ في كتالوج الإعدادات (معروضاً أو داخليّاً)؟ */
    private static function settingKeyDeclared(string $key): bool
    {
        return rescue(fn () => Settings::entry($key) !== null || Settings::internalEntry($key) !== null, false, false);
    }

    /** دوراتُ الاعتماد على رسمِ `depends` (DFS بألوانٍ ثلاثة) */
    private static function cycles(): array
    {
        $graph = [];
        foreach (self::raw() as $key => $r) {
            $graph[$key] = array_values(array_intersect((array) ($r['depends'] ?? []), self::keys()));
        }
        $color = [];   // 0=أبيض 1=رماديّ 2=أسود
        $cycles = [];
        $stack = [];

        $visit = function ($node) use (&$visit, &$graph, &$color, &$cycles, &$stack) {
            $color[$node] = 1;
            $stack[] = $node;
            foreach ($graph[$node] ?? [] as $next) {
                if (($color[$next] ?? 0) === 1) {
                    $i = array_search($next, $stack, true);
                    $cycles[] = array_slice($stack, $i === false ? 0 : $i);
                } elseif (($color[$next] ?? 0) === 0) {
                    $visit($next);
                }
            }
            array_pop($stack);
            $color[$node] = 2;
        };

        foreach (array_keys($graph) as $node) {
            if (($color[$node] ?? 0) === 0) $visit($node);
        }

        return $cycles;
    }
}
