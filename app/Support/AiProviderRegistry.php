<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * **سجلُّ المزوّدين** — الهويّةُ من البوّابة، والشكلُ من عائلة. (المرحلة ٢ · إغلاقُ التغطية)
 *
 * **المشكلةُ التي يحلّها:** البوّابةُ تدعم مئةً وخمسةً وخمسين مزوّداً، وكتالوجُ
 * ‏W1 يصف خمسةً. وكتابةُ مئةٍ وخمسين مدخلاً بيدٍ تُنتج ملفّاً يتقادم عند أوّلِ
 * ترقيةٍ للبوّابة، ويصير Hub **نسخةً متخلّفةً** من معرفةٍ يملكها غيرُه.
 *
 * **والحلُّ ثلاثُ طبقاتٍ لكلٍّ مالكٌ واحد:**
 *
 *  ① **الهويّة — من البوّابةِ حيّاً.** `GET /model/settings` يدور على تعدادِ
 *     المزوّدين كلِّه. فمزوّدٌ أُضيف في ترقيةٍ يظهر **بلا سطرٍ واحدٍ عندنا**.
 *     واللقطةُ المولَّدةُ احتياطٌ حين تسقط البوّابةُ أو لا تكون مُعدَّةً بعد.
 *
 *  ② **الشكل — عائلةُ مصادقةٍ من `AiAuthSchemas`.** ثمانِ عائلاتٍ تغطّي
 *     الأشكالَ كلَّها، مقروءةٌ من مجموعاتِ `CredentialLiteLLMParams` نفسِها.
 *
 *  ③ **الربط — قواعدُ مقيسةٌ + افتراض.** و**الافتراضُ ليس اختراعَنا**: تعليقُ
 *     LiteLLM في `_infer_valid_provider_from_env_vars` يقول إنّ اصطلاحَها
 *     الموحَّدَ `PROVIDER_API_KEY`. فمزوّدٌ بلا استثناءٍ مقيسٍ شكلُه مفتاحٌ واحد.
 *
 * ── **أربعةُ ثوابتَ يحرسها هذا الصنف** ──
 *
 *  ① **لا اسمَ مزوّدٍ في هذه الشيفرة.** القواعدُ **أنماطٌ** (لاحقةُ اسمِ
 *     متغيّرِ بيئة، مفتاحٌ في `litellm_params`، صنفُ أساسٍ في التنفيذ) لا
 *     أسماء. وأيُّ `if ($slug === '…')` هنا نقضٌ للعقدِ يسقطه اختبار.
 *
 *  ② **مزوّدٌ مجهولٌ يعمل.** سلسلةُ القواعدِ تنتهي بافتراضٍ لا بخطأ. فوصولُ
 *     اسمٍ لم يُقَس قطّ — من ترقيةِ بوّابةٍ بعد سنة — يُنتج مدخلاً صالحاً.
 *
 *  ③ **الإلزامُ يُرفَع ولا يُخفَّض** (انظر `AiAuthSchemas`). فأسوأُ أثرٍ لقياسٍ
 *     ناقصٍ سؤالٌ زائد، لا اعتمادٌ ناقصٌ يُحفَظ ثمّ يسقط عند أوّلِ طلب.
 *
 *  ④ **المكتوبُ بيدٍ يعلو على المُشتقّ.** مدخلُ `config/ai_catalog.php`
 *     يُبقي عنوانَه وتلميحاتِه ووضعَ اكتشافِه، ولا يُنشأ له توأمٌ مشتقٌّ
 *     يقسم الشاشةَ بينهما.
 */
final class AiProviderRegistry
{
    /** حالاتُ التغطيةِ الأربع — لا خامسةَ لها */
    public const STATUSES = [
        'SUPPORTED',
        'SUPPORTED_WITH_MANUAL_MODEL',
        'SUPPORTED_WITH_SPECIAL_AUTH',
        'NOT_CONFIGURABLE_FROM_HUB',
    ];

    /** العائلةُ الافتراضيّة — اصطلاحُ LiteLLM المُعلَن لا اختيارُنا */
    public const DEFAULT_AUTH = 'api_key';

    /**
     * وضعُ الاكتشافِ الافتراضيّ لمزوّدٍ لم يُقَس.
     *
     * **`manual` لا `catalog`** — لأنّ ادّعاءَ اكتشافٍ لا نملك دليلَه كذبٌ على
     * الشاشة، وأنّ الإضافةَ اليدويّةَ تعمل دائماً. فالافتراضُ يُقلّل الوعدَ ولا
     * يُقلّل القدرة.
     */
    public const DEFAULT_DISCOVERY = 'manual';

    /** مهلةُ خبءِ قائمةِ المزوّدين — قصيرةٌ لأنّها تتبع ترقيةَ البوّابة */
    public const SLUG_CACHE_MINUTES = 30;

    public const CACHE_KEY = 'ai.providers.slugs';

    // ── ① الهويّة ──────────────────────────────────────────────────────

    /**
     * **أسماءُ المزوّدين المتاحةُ الآن** — من آخرِ قراءةٍ حيّةٍ محفوظة، وإلّا اللقطة.
     *
     * **ولا نداءَ شبكةٍ هنا البتّة — وهذا قرارٌ لا سهو.** قراءةُ الكتالوجِ
     * تحدث في كلِّ صفحةٍ تعرض مزوّداً وفي كلِّ تحقّقٍ من حقل؛ فلو استدعت
     * البوّابةَ لصار وصفُ **مخطَّطٍ** رهينةَ شبكةٍ قد تتأخّر أو تسقط، ولَما
     * أمكن لاختبارٍ أن يؤكّد «لا نداءَ في هذا المسار».
     *
     * فالتحديثُ **فعلٌ صريح** (`refresh()`) من زرٍّ أو أمر، كما استُقرّ في W8
     * للاستهلاك: تُسحَب البياناتُ بطلبٍ لا بخلفيّةٍ تستنزف.
     *
     * @return array{slugs: list<string>, source: string}
     */
    public static function slugs(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && $cached !== []) {
            return ['slugs' => array_values(array_filter($cached, 'is_string')), 'source' => 'gateway'];
        }

        return ['slugs' => self::snapshot(), 'source' => 'snapshot'];
    }

    /**
     * **قراءةٌ حيّةٌ صريحةٌ من البوّابةِ تُحدِّث المحفوظ** — كلفتُها صفرٌ ماليّاً.
     *
     * **ولا يُخبَّأ الفراغ:** ردٌّ فارغٌ يترك المحفوظَ كما هو ويُعيد `ok=false`.
     * فبوّابةٌ أُعيد تشغيلُها للتوّ لا تمحو معرفتَنا بالمزوّدين — وهو الدرسُ
     * نفسُه الذي فرض حارسَ الردِّ الفارغِ في التصالح (W9).
     *
     * @return array{ok: bool, count: int, source: string}
     */
    public static function refresh(): array
    {
        $live = self::readLive();

        if ($live === []) {
            return ['ok' => false, 'count' => count(self::slugs()['slugs']), 'source' => self::slugs()['source']];
        }

        Cache::put(self::CACHE_KEY, $live, now()->addMinutes(self::SLUG_CACHE_MINUTES));

        return ['ok' => true, 'count' => count($live), 'source' => 'gateway'];
    }

    /** @return list<string> */
    private static function readLive(): array
    {
        $res = LiteLlmAdmin::providerSettings();
        if (! ($res['ok'] ?? false) || ! is_array($res['data'] ?? null)) return [];

        $out = [];
        foreach ($res['data'] as $row) {
            $name = is_array($row) ? ($row['name'] ?? null) : null;
            if (is_string($name) && $name !== '') $out[] = $name;
        }
        sort($out);

        return array_values(array_unique($out));
    }

    /** اللقطةُ المولَّدةُ من القياس — احتياطٌ لا مصدرُ حقيقة @return list<string> */
    public static function snapshot(): array
    {
        $raw = config('ai_providers.snapshot', []);

        return is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];
    }

    public static function measuredVersion(): ?string
    {
        $v = config('ai_providers.source.litellm_version');

        return is_string($v) ? $v : null;
    }

    // ── ② الربطُ والشكل ────────────────────────────────────────────────

    /** @return array<string,array> الخريطةُ المولَّدة */
    public static function map(): array
    {
        $raw = config('ai_providers.providers', []);

        return is_array($raw) ? $raw : [];
    }

    /** مفتاحُ Hub من اسمِ البوّابة — الشرطةُ لا تجوز في مفاتيحِ الكتالوج */
    public static function hubKey(string $slug): string
    {
        return str_replace('-', '_', strtolower($slug));
    }

    /**
     * **وصفُ مزوّدٍ بشكلِ مدخلِ كتالوج** — أو `null` إن كان غيرَ قابلٍ للإعداد.
     *
     * والمجهولُ لا يُرَدُّ: يسقط إلى الافتراضِ ويُنتج مدخلاً صالحاً.
     */
    public static function describe(string $slug): ?array
    {
        $row  = self::map()[$slug] ?? [];
        $stat = (string) ($row['status'] ?? 'SUPPORTED');

        if ($stat === 'NOT_CONFIGURABLE_FROM_HUB') return null;

        $family = (string) ($row['auth'] ?? self::DEFAULT_AUTH);
        if (! AiAuthSchemas::exists($family)) $family = self::DEFAULT_AUTH;

        $options = is_array($row['options'] ?? null) ? $row['options'] : ['require_api_key' => true];

        return [
            'label'       => (string) ($row['label'] ?? self::humanise($slug)),
            'litellm_key' => $slug,
            'auth'        => $family,
            'discovery'   => (string) ($row['discovery'] ?? self::DEFAULT_DISCOVERY),
            'fields'      => AiAuthSchemas::fields($family, $options),
            'source'      => isset(self::map()[$slug]) ? 'measured' : 'default',
            'status'      => $stat,
            'modalities'  => is_array($row['modalities'] ?? null) ? $row['modalities'] : ['chat'],
        ];
    }

    /**
     * **كلُّ المداخلِ المشتقّة** — بمفاتيحِ Hub، ودون مَن كُتب بيدٍ في الكتالوج.
     *
     * @return array<string,array>
     */
    public static function catalog(): array
    {
        $curated = [];
        foreach ((array) config('ai_catalog', []) as $entry) {
            if (is_array($entry) && isset($entry['litellm_key'])) {
                $curated[(string) $entry['litellm_key']] = true;
            }
        }

        $out = [];
        foreach (self::slugs()['slugs'] as $slug) {
            if (isset($curated[$slug])) continue;
            $entry = self::describe($slug);
            if ($entry === null) continue;
            $out[self::hubKey($slug)] = $entry;
        }
        ksort($out);

        return $out;
    }

    /** اسمٌ مقروءٌ من الاسمِ التقنيّ — لا جدولَ ترجمةٍ يُنسى نصفُه */
    public static function humanise(string $slug): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $slug));
    }

    /**
     * **علامةُ المزوّدِ البصريّة** — حرفٌ أو حرفان ولونٌ، مشتقّانِ من اسمِه.
     *
     * **ولماذا ليست شعاراتٍ حقيقيّة؟** لأنّ شعارَ مزوّدٍ علامةٌ تجاريّةٌ مملوكة:
     * حزمُ مئةٍ وستّةٍ وعشرين منها في المستودعِ يعني أصولاً ثقيلةً تتقادم مع كلِّ
     * تغييرِ هويّة، ورخصاً تُراجَع واحدةً واحدة، **ومزوّدٌ جديدٌ يظهر بمربّعٍ
     * فارغ**. وهو نقضُ المبدأِ نفسِه الذي بُني عليه السجلّ: لا أصلَ يدويٌّ
     * لكلِّ مزوّد.
     *
     * فالعلامةُ **مشتقّةٌ حتميّاً**: اللونُ من بصمةِ الاسم، والحرفُ من الاسم.
     * فمزوّدٌ يصل غداً يأخذ علامتَه بلا ملفٍّ ولا سطر، ولونُه ثابتٌ لا يتبدّل
     * بين صفحةٍ وأخرى — وهذا ما يجعلها تُميّز فعلاً لا تُزيّن فقط.
     *
     * @return array{initials: string, hue: int}
     */
    public static function mark(string $slug): array
    {
        $parts = array_values(array_filter(preg_split('/[_\-]+/', $slug) ?: []));

        // حرفان دائماً: حرفٌ واحدٌ يجعل عشراتِ المزوّدين علامةً واحدة، فتُميّز
        // العلامةُ لونَها فقط لا اسمَها. ومن اسمٍ مركّبٍ يُؤخَذ أوّلُ كلِّ جزء.
        $initials = count($parts) >= 2
            ? mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1)
            : mb_substr($slug, 0, 2);
        $initials = mb_strtoupper($initials);

        return [
            'initials' => $initials === '' ? '?' : $initials,
            // بصمةٌ ثابتةٌ للاسم — فاللونُ نفسُه في كلِّ شاشةٍ وكلِّ جلسة.
            'hue'      => (int) (crc32($slug) % 360),
        ];
    }

    // ── ③ التصنيفُ المقيس ──────────────────────────────────────────────

    /**
     * **قواعدُ التصنيفِ — أنماطٌ لا أسماء.** دالّةٌ خالصةٌ تُختبَر بصفٍّ مصنوع.
     *
     * الترتيبُ مقصود: الأخصُّ أوّلاً. وأوّلُ قاعدةٍ تُطابق تحسم.
     *
     * @param  array  $m  صفُّ القياسِ لمزوّدٍ واحد — ومعه مفتاحُ `slug` باسمِه
     * @return array{auth?:string, discovery:string, options?:array, status:string, reason?:string, modalities:list<string>}
     */
    public static function classify(array $m): array
    {
        $modalities = array_values(array_filter((array) ($m['modalities'] ?? []), 'is_string'));
        $envSecret  = array_values(array_filter((array) ($m['env_secret'] ?? []), 'is_string'));
        $params     = array_values(array_filter((array) ($m['param_keys'] ?? []), 'is_string'));

        // **دليلانِ لا واحد، ولكلٍّ موضعُه.** المباشرُ يخصُّ هذا المزوّدَ وحدَه
        // (مجلَّدُه وفرعُه وسجلُّه)؛ والواسعُ يضيف نسبةً بالبادئةِ تلتقط أحياناً
        // متغيّرَ مزوّدٍ يشاركه البادئة. فـ**اختيارُ العائلةِ من المباشرِ وحدَه**
        // — وإلّا صار مزوّدٌ أصيلٌ رهينةَ اسمِ جارِه — و**رفعُ الإلزامِ من الاثنين**
        // لأنّ زيادةَ الحذرِ هناك لا تضرّ.
        $envDirect  = array_values(array_filter((array) ($m['env_direct_config'] ?? []), 'is_string'));

        // R0 — ليس مزوّدَ محادثة: استثناءٌ تقنيٌّ موثّقٌ لا «لم يُضَف بعد».
        if (empty($m['chat_config'])) {
            return [
                'status'     => 'NOT_CONFIGURABLE_FROM_HUB',
                'reason'     => $modalities === [] ? 'no_chat_surface' : 'modality:' . implode(',', $modalities),
                'discovery'  => self::DEFAULT_DISCOVERY,
                'modalities' => $modalities,
            ];
        }

        $discovery = ! empty($m['live_discovery']) ? 'live'
            : (! empty($m['static_models']) ? 'catalog' : 'manual');

        // R1 — تدفّقُ جهازٍ تفاعليّ: تسجيلُ دخولٍ بشريٌّ على مضيفِ البوّابةِ ثمّ
        //      ملفُّ رمز. لا اعتمادَ ساكنٌ يُدخَل من شاشة، فادّعاءُ حقلٍ هنا وعدٌ
        //      لا يُوفى. والعلامةُ عنوانُ رمزِ الجهازِ وحدَه — وهو لا يحتمل تأويلاً.
        if (self::anySuffix($envDirect, ['_DEVICE_CODE_URL'])) {
            return [
                'status'     => 'NOT_CONFIGURABLE_FROM_HUB',
                'reason'     => 'interactive_oauth_on_gateway_host',
                'discovery'  => $discovery,
                'modalities' => $modalities,
            ];
        }

        // R2 — حسابُ خدمةٍ سحابيٌّ بصيغةِ JSON (يسبق سلسلةَ AWS: بعضُ التنفيذِ
        //      يشترك في صنفِ الأساسِ نفسِه، فالأخصُّ أوّلاً).
        if (! empty($m['vertex_base']) && self::anyPrefix($params, ['vertex_'])) {
            return self::row('gcp_service_account', $discovery, [], $modalities);
        }

        // R3 — سلسلةُ اعتمادٍ بتوقيعٍ إقليميّ.
        if (! empty($m['aws_base'])) {
            return self::row('aws_signature', $discovery, [], $modalities);
        }

        // R4 — إصدارُ واجهةٍ مقروءٌ في التنفيذ.
        if ((array) ($m['reads_api_version'] ?? []) !== []) {
            return self::row('api_key_endpoint_version', $discovery, [], $modalities);
        }

        // R5 — توقيعٌ بزوجِ مفاتيح: بصمةٌ ومستأجِرٌ في حمولةِ الطلب.
        if (self::anySuffix($params, ['_fingerprint']) && self::anySuffix($params, ['_tenancy'])) {
            return self::row('key_pair', $discovery, [], $modalities);
        }

        // R6 — مساحةُ مشروعٍ أو مستأجِرٍ في حمولةِ الطلب.
        if (self::anySuffix($params, ['_project', '_tenant_id'])) {
            return self::row('api_key_project', $discovery, [], $modalities);
        }

        // R7 — معرّفُ حسابٍ لازم: يدخل في بناءِ عنوانِ الطلبِ نفسِه فلا طلبَ
        //      بدونه. والمطابقةُ على **الاسمِ القانونيِّ للمزوّد** لا على أيِّ
        //      لاحقةٍ تشبهه: متغيّرٌ مؤهَّلٌ بكلمةٍ وسطى شيءٌ آخرُ تماماً، ولو
        //      قُبِل لصار حقلٌ إلزاميٌّ في وجهِ أشهرِ المزوّدين بلا سبب.
        //      ويُستثنى مَن أعلنته البوّابةُ في قائمةِ التوافقِ العامّة: مفتاحٌ
        //      ونهايةٌ يكفيانه، فرفعُ معرّفِ الحسابِ إلزاميّاً يمنع إعداداً صحيحاً.
        $canonicalAccount = strtoupper(str_replace('-', '_', (string) ($m['slug'] ?? ''))) . '_ACCOUNT_ID';
        if (in_array($canonicalAccount, array_map('strtoupper', $envDirect), true)
            && empty($m['declared_openai_compatible'])) {
            return self::row('api_key_account', $discovery, [], $modalities);
        }

        // R8 — الافتراض. والإلزامُ من الدليل: مفتاحٌ حيث قِيس سرٌّ، ونهايةٌ حيث
        //      لا عنوانَ افتراضيَّ ولا سرَّ — أي نهايةٌ يملكها المُشغِّل.
        return self::row(self::DEFAULT_AUTH, $discovery, [
            'require_api_key'  => $envSecret !== [],
            'require_api_base' => $envSecret === [] && empty($m['default_api_base']),
        ], $modalities);
    }

    /** @return array{auth:string, discovery:string, options:array, status:string, modalities:list<string>} */
    private static function row(string $family, string $discovery, array $options, array $modalities): array
    {
        $status = AiAuthSchemas::isSpecial($family)
            ? 'SUPPORTED_WITH_SPECIAL_AUTH'
            : ($discovery === 'manual' ? 'SUPPORTED_WITH_MANUAL_MODEL' : 'SUPPORTED');

        return [
            'auth'       => $family,
            'discovery'  => $discovery,
            'options'    => $options === [] ? ['require_api_key' => true] : $options,
            'status'     => $status,
            'modalities' => $modalities,
        ];
    }

    private static function anySuffix(array $names, array $suffixes): bool
    {
        foreach ($names as $n) {
            foreach ($suffixes as $s) {
                if (str_ends_with(strtolower($n), strtolower($s))) return true;
            }
        }

        return false;
    }

    private static function anyPrefix(array $names, array $prefixes): bool
    {
        foreach ($names as $n) {
            foreach ($prefixes as $p) {
                if (str_starts_with(strtolower($n), strtolower($p))) return true;
            }
        }

        return false;
    }

    // ── التوليد ────────────────────────────────────────────────────────

    /** يبني نصَّ `config/ai_providers.php` من ملفِّ القياس */
    public static function renderMap(array $measured): string
    {
        $rows = [];
        foreach ((array) ($measured['providers'] ?? []) as $slug => $m) {
            if (! is_string($slug) || ! is_array($m)) continue;
            // الاسمُ جزءٌ من صفِّ التصنيف: قاعدةُ معرّفِ الحسابِ تطابق **الاسمَ
            // القانونيَّ للمزوّد**، فلا بدّ أن يراه المُصنِّف.
            $rows[$slug] = self::classify($m + ['slug' => $slug]);
        }
        ksort($rows);

        $version = (string) ($measured['litellm_version'] ?? 'unknown');
        $out  = "<?php\n\n";
        $out .= "/*\n";
        $out .= "|--------------------------------------------------------------------------\n";
        $out .= "| خريطةُ المزوّدين — **مولَّدةٌ لا تُحرَّر**\n";
        $out .= "|--------------------------------------------------------------------------\n";
        $out .= "|\n";
        $out .= "| المصدر: قياسُ شيفرةِ LiteLLM v{$version} بـ\n";
        $out .= "| `deploy/litellm/tools/measure_providers.py`، ثمّ تصنيفُ\n";
        $out .= "| `AiProviderRegistry::classify()`. لتوليدِها من جديد:\n";
        $out .= "|\n";
        $out .= "|     php artisan hub:ai-provider-map\n";
        $out .= "|\n";
        $out .= "| **وتحريرُها بيدٍ يُمحى عند أوّلِ توليد.** التصحيحُ موضعُه قاعدةٌ في\n";
        $out .= "| `AiProviderRegistry` أو مدخلٌ مكتوبٌ بيدٍ في `config/ai_catalog.php`.\n";
        $out .= "|\n";
        $out .= "| **ومزوّدٌ غائبٌ عن هذه الخريطةِ يعمل**: السجلُّ يسقط إلى الافتراضِ\n";
        $out .= "| (`api_key`) فيُنتج مدخلاً صالحاً بلا تغييرِ شيفرة. فالخريطةُ تسريعٌ\n";
        $out .= "| ودقّةٌ، لا شرطُ عمل.\n";
        $out .= "|\n";
        $out .= "*/\n\n";
        $out .= "return [\n\n";
        $out .= "    'source' => [\n";
        $out .= "        'litellm_version' => " . var_export($version, true) . ",\n";
        $out .= "        'generator'       => 'deploy/litellm/tools/measure_providers.py',\n";
        $out .= "        'classifier'      => 'App\\\\Support\\\\AiProviderRegistry::classify',\n";
        $out .= "        'providers'       => " . count($rows) . ",\n";
        $out .= "    ],\n\n";
        $out .= "    'snapshot' => [\n";
        foreach (array_keys($rows) as $slug) {
            $out .= "        " . var_export($slug, true) . ",\n";
        }
        $out .= "    ],\n\n";
        $out .= "    'providers' => [\n\n";
        foreach ($rows as $slug => $row) {
            $out .= "        " . var_export($slug, true) . " => [\n";
            foreach ($row as $k => $v) {
                $out .= "            " . var_export($k, true) . " => " . self::export($v) . ",\n";
            }
            $out .= "        ],\n";
        }
        $out .= "\n    ],\n\n];\n";

        return $out;
    }

    private static function export(mixed $v): string
    {
        if (! is_array($v)) return var_export($v, true);
        if ($v === []) return '[]';

        $parts = [];
        foreach ($v as $k => $x) {
            $parts[] = array_is_list($v)
                ? var_export($x, true)
                : var_export($k, true) . ' => ' . var_export($x, true);
        }

        return '[' . implode(', ', $parts) . ']';
    }
}
