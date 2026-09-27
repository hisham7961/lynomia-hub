<?php

namespace App\Support\Ai\Catalog;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Support\Ai\Gateway\LiteLlmAdmin;

/**
 * **من أين يعرف Hub نماذجَ مزوّدٍ؟** — مصادرُ الاكتشافِ مرتَّبةً بموثوقيّتِها.
 *
 * ── **العيبُ الذي وُلد منه هذا الصنف** ──
 *
 * كان «اكتشافُ النماذج» يقرأ `GET /model/info` — وهو **سجلُّ البوّابةِ عمّا
 * سُجّل فيها سلفاً**، لا قائمةُ نماذجِ المزوّد. فمزوّدٌ اعتمادُه جديدٌ وسجلُّه
 * فارغ **يُكتَشف منه صفرُ نماذج**، ويُدفَع المديرُ إلى التسجيلِ اليدويِّ —
 * فيبحث عن معرّفِ النموذجِ خارجَ النظام ويكتبه بيدِه. وهذا ما جرى حرفيّاً في
 * أوّلِ قبولِ إنتاج.
 *
 * ــ **والاكتشافُ لا يُخمَّن.** فالمصادرُ ثلاثةٌ لا واحد، ولكلٍّ **مرتبةٌ
 * وحدود**، ويُعرَض مصدرُ كلِّ مرشَّحٍ مع المرشَّح:
 *
 * | # | المصدر | ما يُثبِته | كلفته |
 * |---|---|---|---|
 * | ١ | `gateway_registry` | نشرٌ **قائمٌ فعلاً** عند البوّابةِ مربوطٌ باعتمادِنا | صفر |
 * | ٢ | `litellm_catalog` | ما **تعرفه** الحزمةُ المثبّتةُ عن نماذجِ هذا المزوّدِ في السوق | صفر |
 * | ٣ | `manual` | ما يكتبه المديرُ حين لا يعرف المصدرانِ شيئاً | صفر |
 *
 * ── **ولماذا لا يُسأل المزوّدُ مباشرةً؟** ──
 *
 * لأنّ الإصدارَ المثبَّتَ لا يُتيح ذلك لنا. `litellm.utils.get_valid_models`
 * تسأل نقطةَ المزوّدِ **فقط** حين يكون `check_provider_endpoint` مرفوعاً في
 * تهيئةِ البوّابةِ نفسِها، ولا مسارَ إداريٌّ يطلبه عند الحاجة. ورفعُ تلك
 * الرايةِ تغييرُ بنيةِ إنتاجٍ خارجَ نطاقِنا. **فالنقصُ يُقال ولا يُسَدُّ
 * بادّعاء** — ويُترَك السطرُ هنا ليُراجَع إن تغيّر الإصدار.
 *
 * ── **ولا اسمَ مزوّدٍ في هذا الصنف** ــ
 *
 * الترشيحُ يجري بمفتاحِ المزوّدِ كما يُعلنه الكتالوجُ (`litellm_key`) —
 * فمزوّدٌ جديدٌ يعمل بلا سطرٍ يُضاف هنا.
 */
final class AiModelSources
{
    /** نشرٌ قائمٌ عند البوّابةِ مربوطٌ باعتمادِنا */
    public const GATEWAY = 'gateway_registry';

    /** ما تعرفه الحزمةُ المثبّتةُ عن السوق */
    public const CATALOG = 'litellm_catalog';

    /** ما يكتبه المديرُ بيدِه */
    public const MANUAL = 'manual';

    /** المصادرُ **مرتَّبةً**: الأوّلُ يغلب الثاني عند التكرار */
    public const SOURCES = [self::GATEWAY, self::CATALOG, self::MANUAL];

    // ═══ دلالاتُ الإتاحة — «معروفٌ في الكتالوج» ليس «متاحٌ لحسابِك» ═══

    /** **مُسجَّلٌ عند البوّابةِ بمرجعِ اعتمادِنا** — أقوى ما نملك بلا إنفاق */
    public const AVAIL_REGISTERED = 'gateway_registered';

    /** **يعرفه كتالوجُ البوّابة** — ولا يُثبِت أنّ حسابَك يبلغه */
    public const AVAIL_CATALOG = 'catalog_known';

    /**
     * **معرّفُ عائلةٍ لا معرّفُ نموذج** — الحقيقيُّ يحمل لاحقةَ حسابِك.
     *
     * وهذه أخطرُ الثلاث: يبدو معرّفاً كاملاً وهو **جذعٌ** لا يُنادى.
     */
    public const AVAIL_ACCOUNT = 'account_specific';

    public const AVAILABILITY = [self::AVAIL_REGISTERED, self::AVAIL_CATALOG, self::AVAIL_ACCOUNT];

    /**
     * **بادئاتُ العائلاتِ التي يُجرَّد معرّفُها إلى جذع.**
     *
     * ── **لماذا هذه القائمةُ ليست ترقيعاً لمزوّدٍ بعينه؟** ──
     *
     * لأنّها **مقروءةٌ من منطقِ الحزمةِ المثبّتةِ نفسِها**: `utils.py` يحمل
     * دالّةً تُجرّد المعرّفَ الكاملَ إلى جذعِه بحذفِ **ثلاثةِ مقاطعَ** من آخرِه
     * (`(:[^:]+){3}$`)، وتُستدعى حين يحمل المعرّفُ هذه البادئة. فالجذعُ —
     * وهو ما يحمله الكتالوجُ — **مفتاحُ تسعيرٍ لا معرّفُ نموذجٍ يُنادى**.
     *
     * فالقاعدةُ عامّةٌ في صياغتِها: *معرّفٌ يعرف الإصدارُ المثبَّتُ أنّه جذعٌ
     * لعائلةٍ، ولا يحمل مقاطعَ الحسابِ بعدُ* — ولا اسمَ مزوّدٍ فيها.
     * وإن أضاف الإصدارُ عائلةً أخرى، تُمَدُّ من المصدرِ نفسِه لا من الذاكرة.
     */
    public const STEM_MARKERS = ['ft:'];

    /**
     * **أهذا المعرّفُ جذعُ عائلةٍ لا نموذجاً يُنادى؟**
     *
     * الجذعُ يحمل البادئةَ **ولا يحمل بعدها إلّا مقطعاً واحداً** — وهو اسمُ
     * الأساس. والمعرّفُ الحقيقيُّ يزيد عليه مقاطعَ حسابِك.
     *
     * ── **ولمَ لا يُنسَخ تعبيرُ المصدرِ حرفاً؟** ──
     *
     * لأنّ **القياسَ كشف فيه ثغرة**: تعبيرُه يطلب ثلاثةَ مقاطعَ غيرَ فارغةٍ
     * في الآخر، فمعرّفٌ حقيقيٌّ أحدُ مقاطعِه فارغٌ **لا يُجرَّده المصدرُ
     * نفسُه** — ولو نسخناه لَعَدَدْنا معرّفاً كاملاً جذعاً ومنعنا تبنّيَه.
     *
     * فالمعيارُ هنا **أبسطُ وأمتن**: وجودُ مقاطعَ بعد الأساسِ من عدمِه. وهو
     * يوافق المصدرَ في كلِّ حالةٍ يعمل فيها، ويصيب حيث يُخطئ.
     */
    public static function isFamilyStem(string $upstream): bool
    {
        $id = trim($upstream);
        if ($id === '') return false;

        foreach (self::STEM_MARKERS as $marker) {
            $at = mb_strpos($id, $marker);
            if ($at === false) continue;

            // ما بعد البادئة: مقطعٌ واحدٌ ⇒ جذع · أكثرُ ⇒ معرّفٌ يحمل حسابَه
            $tail = mb_substr($id, $at + mb_strlen($marker));

            return $tail !== '' && ! str_contains($tail, ':');
        }

        return false;
    }

    /** دلالةُ إتاحةِ مرشَّحٍ — من مصدرِه وشكلِ معرّفِه، لا من ظنّ */
    public static function availabilityOf(string $source, string $upstream): string
    {
        if ($source === self::GATEWAY) return self::AVAIL_REGISTERED;

        return self::isFamilyStem($upstream) ? self::AVAIL_ACCOUNT : self::AVAIL_CATALOG;
    }

    /** **أيُتبنّى بضغطة؟** — الجذعُ لا، فمعرّفُه ليس معرّفَ نموذج */
    public static function adoptableInOneClick(string $availability): bool
    {
        return $availability !== self::AVAIL_ACCOUNT;
    }

    /**
     * **سقفُ ما يُعرَض** — قائمةٌ بلا حدٍّ تُعطِّل الشاشةَ ولا تُفيد أحداً.
     *
     * وأكبرُ مزوّدٍ في الكتالوجِ المقيسِ يحمل مئاتِ النماذجِ لا آلافَها، فالسقفُ
     * يتّسع للواقعِ ويقطع الشاذّ. **ويُعلَن حين يُبلَغ** — فالقصُّ الصامتُ يُقرأ
     * «هذا كلُّ شيء» وهو ليس كذلك.
     */
    public const MAX_CANDIDATES = 500;

    /** سقفُ ما يُقرأ من الكتالوجِ قبل أن يُعَدَّ ردّاً شاذّاً */
    public const MAX_CATALOG_ENTRIES = 20000;

    /** أطولُ معرّفٍ يُقبَل — عمودُ `upstream_model` ثلاثُ مئة */
    public const MAX_ID_CHARS = 300;

    // ═══ الواجهة ═══

    /**
     * **كلُّ ما نعرفه عن نماذجِ هذا المزوّد** — من المصادرِ كلِّها، مرتَّباً
     * ومنزوعَ التكرار.
     *
     * @return array{
     *     ok: bool,
     *     candidates: list<array<string,mixed>>,
     *     sources: array<string, array{ok: bool, count: int, error: ?string}>,
     *     unowned: int,
     *     truncated: bool,
     *     error: ?string
     * }
     */
    public static function discover(AiProvider $provider): array
    {
        $sources = [];

        $gateway = self::fromGateway($provider);
        $sources[self::GATEWAY] = ['ok' => $gateway['ok'], 'count' => count($gateway['candidates']),
                                   'error' => $gateway['error']];

        $catalog = self::fromCatalog($provider);
        $sources[self::CATALOG] = ['ok' => $catalog['ok'], 'count' => count($catalog['candidates']),
                                   'error' => $catalog['error']];

        // **الأوّلُ يغلب**: نشرٌ قائمٌ أصدقُ من معرفةٍ عن السوق
        $merged = [];
        foreach ([$gateway['candidates'], $catalog['candidates']] as $batch) {
            foreach ($batch as $c) {
                $key = (string) $c['upstream_model'];
                if ($key === '' || isset($merged[$key])) continue;
                $merged[$key] = $c;
            }
        }

        // **الترتيبُ يُطلَب صراحةً** — فلا يتبادل صفّان موضعَيهما بين قراءةٍ وأخرى
        $out = array_values($merged);
        usort($out, static function (array $a, array $b): int {
            return [$a['already_imported'], array_search($a['source'], self::SOURCES, true), $a['upstream_model']]
               <=> [$b['already_imported'], array_search($b['source'], self::SOURCES, true), $b['upstream_model']];
        });

        $truncated = count($out) > self::MAX_CANDIDATES;
        if ($truncated) $out = array_slice($out, 0, self::MAX_CANDIDATES);

        // فشلُ المصدرَين معاً وحدَه فشلٌ — وسقوطُ أحدِهما يُقال ولا يُسقِط الشاشة
        $ok = $gateway['ok'] || $catalog['ok'];

        return [
            'ok'         => $ok,
            'candidates' => $out,
            'sources'    => $sources,
            'unowned'    => (int) $gateway['unowned'],
            'truncated'  => $truncated,
            'error'      => $ok ? null : ((string) ($gateway['error'] ?? $catalog['error'] ?? 'تعذّر الاكتشاف')),
        ];
    }

    /**
     * **أَأَثمرَ الاكتشافُ لهذا المزوّد؟** — يُقاس على النتيجةِ لا على تصنيف.
     *
     * ── **ولمَ لا يُسأل التصنيفُ المُعلَن؟** ──
     *
     * لأنّه **يُخطئ في الاتّجاهَين**، وقياسُ الكتالوجِ المثبَّتِ أثبت ذلك:
     * خمسةَ عشرَ مزوّداً يُعلَن لهم اكتشافٌ و**لا مُدخَلَ واحداً** تحتهم في
     * الكتالوج، وتسعةٌ يُعلَن لهم `manual` و**الكتالوجُ يحمل نماذجَهم** —
     * أحدُهم بمئتَين وثمانيةٍ وتسعين نموذجاً.
     *
     * فالتصنيفُ المُعلَنُ يُفيد تقريرَ التغطيةِ ولا يحكم الشاشة: الشاشةُ تعرض
     * **ما جاء فعلاً**، وتقول «لا اكتشافَ» حين لا يجيء شيءٌ والمصدرانِ قُرئا
     * بلا عطل. **وعدمُ الوجدانِ يُقال وجداناً لعدم** — لا يُقاس على ادّعاء.
     *
     * @param  array  $found  ناتجُ `discover()` نفسِه
     */
    public static function yieldedCandidates(array $found): bool
    {
        return ($found['candidates'] ?? []) !== [];
    }

    /** **أَقُرئ المصدرانِ كلاهما بلا عطل؟** — ففراغُ القائمةِ عندئذٍ حقيقةٌ لا عُطل */
    public static function bothSourcesRead(array $found): bool
    {
        $s = (array) ($found['sources'] ?? []);

        return (bool) ($s[self::GATEWAY]['ok'] ?? false) && (bool) ($s[self::CATALOG]['ok'] ?? false);
    }

    // ═══ ① سجلُّ البوّابة ═══

    /**
     * **ما سُجِّل عند البوّابةِ ومربوطٌ باعتمادِنا.**
     *
     * وهو السلوكُ القائمُ منذ W5 — يُستدعى كما هو ولا يُعاد بناؤه.
     */
    private static function fromGateway(AiProvider $provider): array
    {
        $r = AiModels::discover($provider);
        if (! $r['ok']) {
            return ['ok' => false, 'candidates' => [], 'unowned' => 0, 'error' => (string) $r['error']];
        }

        $out = [];
        foreach ($r['candidates'] as $c) {
            $upstream = trim((string) ($c['upstream_model'] ?? ''));
            if ($upstream === '' || mb_strlen($upstream) > self::MAX_ID_CHARS) continue;

            $out[] = [
                'upstream_model'     => $upstream,
                'litellm_model_name' => (string) $c['litellm_model_name'],
                'display_name'       => (string) $c['litellm_model_name'],
                'source'             => self::GATEWAY,
                'availability'       => self::AVAIL_REGISTERED,
                'already_imported'   => (bool) $c['already_imported'],
                'mode'               => null,
                'capabilities'       => (array) $c['capabilities'],
                'limits'             => (array) $c['limits'],
                'params'             => (array) $c['params'],
                'pricing'            => (array) $c['pricing'],
                'deprecated_on'      => null,
            ];
        }

        return ['ok' => true, 'candidates' => $out, 'unowned' => (int) $r['unowned'], 'error' => null];
    }

    // ═══ ② كتالوجُ البوّابةِ عن السوق ═══

    /**
     * **ما تعرفه الحزمةُ المثبّتةُ عن نماذجِ هذا المزوّد.**
     *
     * والمفتاحُ في الكتالوجِ هو `litellm_provider` في كلِّ مُدخَل، ويُطابَق
     * بمفتاحِ المزوّدِ عندنا كما يُعلنه سجلُّ المزوّدين — **بلا اسمِ مزوّدٍ
     * مكتوبٍ في الشيفرة**.
     */
    private static function fromCatalog(AiProvider $provider): array
    {
        $key = self::catalogKeyOf($provider);
        if ($key === null) {
            return ['ok' => false, 'candidates' => [],
                    'error' => 'لا مفتاحَ لهذا المزوّدِ في كتالوجِ البوّابة — فلا اكتشافَ تلقائيَّ منه'];
        }

        $res = LiteLlmAdmin::modelCostMap();
        if (! $res['ok']) {
            return ['ok' => false, 'candidates' => [], 'error' => (string) $res['error']];
        }

        $map = (array) ($res['data'] ?? []);
        if (count($map) > self::MAX_CATALOG_ENTRIES) {
            return ['ok' => false, 'candidates' => [],
                    'error' => 'ردٌّ أكبرُ ممّا يُعقَل من كتالوجِ البوّابة — لم يُقرَأ'];
        }

        $known = AiModel::query()->where('provider_id', $provider->id)
            ->pluck('upstream_model')->flip();

        $out = [];
        foreach ($map as $id => $entry) {
            if (! is_array($entry)) continue;
            if ((string) ($entry['litellm_provider'] ?? '') !== $key) continue;

            // **المعرّفُ يأتي من مصدرٍ خارجيّ** — فيُقاس طولاً ومحتوًى قبل أن يُعرَض
            $upstream = self::cleanId((string) $id);
            if ($upstream === null) continue;

            $info = self::toModelInfo($entry);

            $out[] = [
                'upstream_model'     => $upstream,
                'litellm_model_name' => null,           // يُولَّد عند الاستيراد
                'display_name'       => $upstream,
                'source'             => self::CATALOG,
                'availability'       => self::availabilityOf(self::CATALOG, $upstream),
                'already_imported'   => $known->has($upstream),
                'mode'               => self::cleanMode($entry['mode'] ?? null),
                'capabilities'       => AiModelFacts::capabilities($info),
                'limits'             => AiModelFacts::limits($info),
                'params'             => AiModelFacts::params($info),
                'pricing'            => AiModelFacts::pricing($info),
                'deprecated_on'      => self::cleanDate($entry['deprecation_date'] ?? null),
            ];
        }

        return ['ok' => true, 'candidates' => $out, 'error' => null];
    }

    /**
     * **مفتاحُ هذا المزوّدِ في الكتالوج** — من سجلِّ المزوّدين لا من قائمةٍ هنا.
     *
     * **والأوضاعُ العامّةُ تُستبعِد نفسَها بالقياسِ لا بقائمةِ استثناءات**:
     * مفتاحُ الوضعِ العامِّ يشير إلى *لهجةِ* نقطةٍ لا إلى شركة، ولا مُدخَلَ
     * واحداً في الكتالوجِ يحمله — فالترشيحُ عليه يعود صفراً من نفسِه. وهذا
     * أصدقُ من قائمةٍ نكتبها هنا وتتعفّن: فما جُهل اسمُ صاحبِه **لا يُسمّى**.
     */
    private static function catalogKeyOf(AiProvider $provider): ?string
    {
        $def = AiCatalog::provider((string) $provider->catalog_key);
        $key = trim((string) ($def['litellm_key'] ?? ''));

        return ($key === '' || str_contains($key, '*')) ? null : $key;
    }

    // ═══ ③ تطهيرُ ما يأتي من خارج ═══

    /**
     * **معرّفٌ مقبولٌ أو لا شيء.**
     *
     * المعرّفُ يأتي من ردٍّ خارجيّ، ويُعرَض في صفحةٍ ويُخزَّن في عمود. فيُقاس:
     * لا فراغَ، ولا أطولَ من العمود، ولا محارفَ تحكّمٍ أو اتّجاهٍ خفيّة —
     * فمحرفُ قلبِ اتّجاهٍ واحدٌ يجعل معرّفاً يُقرَأ غيرَ ما هو.
     */
    private static function cleanId(string $raw): ?string
    {
        $id = trim($raw);
        if ($id === '' || mb_strlen($id) > self::MAX_ID_CHARS) return null;
        if (! mb_check_encoding($id, 'UTF-8')) return null;

        // محارفُ التحكّمِ وتوجيهِ النصِّ ثنائيِّ الاتّجاه ومحرفُ الصفرِ العرض
        if (preg_match('/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $id)) {
            return null;
        }

        return $id;
    }

    /** الوضعُ من مفرداتٍ معلومة — وما سواه `null` لا تخمين */
    private static function cleanMode(mixed $raw): ?string
    {
        $m = is_string($raw) ? trim($raw) : '';

        return in_array($m, self::MODES, true) ? $m : null;
    }

    /** مفرداتُ الوضعِ المعروضة — مقيسةٌ من الكتالوجِ نفسِه */
    public const MODES = [
        'chat', 'completion', 'embedding', 'image_generation', 'image_edit',
        'audio_transcription', 'audio_speech', 'video_generation',
        'rerank', 'responses', 'realtime', 'moderation', 'search', 'ocr',
    ];

    /** تاريخٌ بصيغةٍ واحدةٍ أو `null` — ولا يُعرَض نصٌّ حرٌّ من الخارج */
    private static function cleanDate(mixed $raw): ?string
    {
        $d = is_string($raw) ? trim($raw) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 ? $d : null;
    }

    /**
     * **مُدخَلُ الكتالوجِ بلغةِ `AiModelFacts`.**
     *
     * فمعرفةُ القدراتِ والحدودِ والتسعيرِ تُستخرَج بالمُستخرِجِ القائمِ نفسِه —
     * ولا يُبنى ثانٍ يفترق عنه بعد شهر.
     */
    private static function toModelInfo(array $entry): array
    {
        $info = [];
        foreach ($entry as $k => $v) {
            if (! is_string($k)) continue;
            if (is_array($v) || is_object($v)) continue;   // لا بنيةَ متداخلةً تُنقَل كما هي
            $info[$k] = $v;
        }

        return $info;
    }
}
