<?php

namespace App\Support;

use App\Models\AiModel;
use App\Models\AiProvider;

/**
 * **سجلُّ النماذجِ والاكتشاف** (المرحلة ٢ · W5).
 *
 * المسارُ المعتمَدُ في الخطّة، أربعُ خطواتٍ لا واحدة:
 *
 * ```
 * اكتشاف  →  مراجعة  →  اختيار  →  تهيئة
 * ```
 *
 * ── **الثابتُ الأوّلُ: الاكتشافُ لا يكتب صفّاً ولا يُفعِّل نموذجاً** ──
 *
 * ‏`discover()` **قراءةٌ محضة**: تسأل البوّابةَ وتُعيد مرشَّحين. ولا صفَّ يولد
 * إلّا بـ`import()` باختيارٍ صريح، ويولد **مُعطَّلاً**. والخطرُ الذي ينفيه هذا
 * مكتوبٌ في الخطّة: حسابٌ يتيح ستّين نموذجاً فتُفعَّل كلُّها تلقائيّاً، فيُوجَّه
 * طلبٌ إلى نموذجٍ باهظٍ **لم يختره أحد**.
 *
 * ── **الثابتُ الثاني: التحديثُ لا يدهس ما يفوقه رتبةً** ──
 *
 * أعمدةُ المعرفةِ «نسخةٌ لا مصدرُ حقيقة» — تُبنى من البوّابةِ في كلِّ تحديث.
 * **إلّا حقيقةً مصدرُها أعلى رتبةً** (`Tri::SOURCES` مرتّبةٌ بالثقة): تجاوزُ
 * المديرِ `hub_override`، **وما أثبته اختبارُ قدرةٍ حقيقيّ** `verified`.
 * وحفظُ الثاني ليس ترفاً: إعادةُ اشتقاقِه تُنفق رصيداً فعليّاً (المستوى E في
 * W6)، فدهسُه بتحديثٍ مجّانيٍّ يجعل التحديثَ مُكلِفاً في صمت.
 *
 * ── **الثابتُ الثالث: قرارُ الإنسانِ ينجو من كلِّ مزامنة** ──
 *
 * ‏`enabled` و`priority` و`display_name` و`tags` قرارُ مديرٍ لا بيانُ بوّابة.
 * ولا يمسّها `refresh()` بحال.
 *
 * ── **ولا اسمَ نموذجٍ في هذا الملفّ** ──
 *
 * المرشَّحون يأتون من `/model/info`، والتسجيلُ اليدويُّ يأخذ ما يكتبه المديرُ
 * حرفاً. فنموذجٌ جديدٌ لا يحتاج سطراً هنا، ومزوّدٌ جديدٌ لا يحتاج فرعاً شرطيّاً.
 */
final class AiModels
{
    /** شكلُ الجوابِ الموحَّد */
    public const SHAPE = ['ok', 'models', 'error'];

    // ── ① الاكتشافُ — قراءةٌ محضة ───────────────────────────────────────

    /**
     * **ما تعرفه البوّابةُ الآن** — بلا كتابةِ صفٍّ واحد.
     *
     * والمرشَّحُ يُنسَب إلى مزوّدِنا بمرجعِ اعتمادِه (`litellm_credential_name`)
     * — وهو الرباطُ الذي أثبت W0 وجودَه. وما سُجِّل عند البوّابةِ خارجَ Hub لا
     * يُنسَب إلينا، ويُعَدُّ صراحةً كي لا تدّعي الشاشةُ أنّ البوّابةَ فارغة.
     *
     * @return array{ok: bool, candidates: list<array<string,mixed>>, unowned: int, error: ?string}
     */
    public static function discover(AiProvider $provider): array
    {
        $res = LiteLlmAdmin::modelInfo();
        if (! $res['ok']) {
            return ['ok' => false, 'candidates' => [], 'unowned' => 0, 'error' => (string) $res['error']];
        }

        $known = AiModel::query()->where('provider_id', $provider->id)
            ->pluck('litellm_model_name')->flip();

        $candidates = [];
        $unowned    = 0;

        foreach ((array) (($res['data']['data'] ?? $res['data']) ?: []) as $entry) {
            if (! is_array($entry)) continue;

            $params = (array) ($entry['litellm_params'] ?? []);
            $name   = trim((string) ($entry['model_name'] ?? ''));
            if ($name === '') continue;

            if ((string) ($params['litellm_credential_name'] ?? '') !== (string) $provider->credential_name) {
                $unowned++;
                continue;
            }

            $info = (array) ($entry['model_info'] ?? []);
            $candidates[] = [
                'litellm_model_name' => $name,
                'upstream_model'     => (string) ($params['model'] ?? ''),
                'already_imported'   => $known->has($name),
                'capabilities'       => AiModelFacts::capabilities($info),
                'limits'             => AiModelFacts::limits($info),
                'params'             => AiModelFacts::params($info),
                'pricing'            => AiModelFacts::pricing($info),
            ];
        }

        return ['ok' => true, 'candidates' => $candidates, 'unowned' => $unowned, 'error' => null];
    }

    // ── ② الاختيار — استيرادُ المُنتقى مُعطَّلاً ──────────────────────────

    /**
     * **يولد الصفُّ مُعطَّلاً** — والتفعيلُ قرارٌ ثالثٌ بعد الاختيارِ والتهيئة.
     *
     * @param  list<string>  $names  أسماءُ ما اختاره المديرُ من المرشَّحين
     * @return array{ok: bool, models: list<AiModel>, error: ?string}
     */
    public static function import(AiProvider $provider, array $names): array
    {
        if ($names === []) return self::fail('لم يُختَر نموذجٌ — الاستيرادُ بلا اختيارٍ يُرَدّ');

        $found = self::discover($provider);
        if (! $found['ok']) return self::fail((string) $found['error']);

        $byName = [];
        foreach ($found['candidates'] as $c) $byName[$c['litellm_model_name']] = $c;

        $models  = [];
        $skipped = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            $c = $byName[$name] ?? null;
            if ($c === null)            { $skipped[] = $name; continue; }
            if ($c['already_imported']) { $skipped[] = $name; continue; }

            $models[] = AiModel::create([
                'provider_id'        => $provider->id,
                'litellm_model_name' => $name,
                'upstream_model'     => $c['upstream_model'],
                // **نشرٌ قائمٌ عند البوّابةِ** — وهو أعلى المصادرِ رتبةً
                'discovery_source'   => AiModelSources::GATEWAY,
                'display_name'       => $name,              // يُعاد تسميتُه في التهيئة
                'enabled'            => false,              // **الاكتشافُ لا يُفعِّل**
                'capabilities'       => $c['capabilities'],
                'limits'             => $c['limits'],
                'params'             => $c['params'],
                'pricing'            => $c['pricing'],
                'pricing_source'     => 'litellm',
                'pricing_updated_at' => now(),
                'health'             => 'UNKNOWN',
                'created_by'         => auth()->id(),
                'updated_by'         => auth()->id(),
            ]);
        }

        if ($models === []) {
            return self::fail('لا نموذجَ جديدٌ يُستورَد — ' . ($skipped !== []
                ? 'المُختار مستورَدٌ سلفاً أو لم تعُد البوّابةُ تعرفه'
                : 'البوّابةُ لا تُعلن نماذجَ لهذا المزوّد'));
        }

        self::trace('استيراد نماذج ذكاء', $provider, [
            'imported' => array_map(static fn (AiModel $m) => $m->litellm_model_name, $models),
            'skipped'  => $skipped,
            'enabled'  => false,   // **يُسجَّل صراحةً**: الاستيرادُ لم يُفعِّل شيئاً
        ]);

        return ['ok' => true, 'models' => $models, 'error' => null];
    }

    // ── ③ التحديث — بناءٌ يحفظ ما يفوقه رتبةً ───────────────────────────

    /**
     * **يُعاد بناءُ المعرفةِ ولا يُمَسّ القرار.**
     *
     * @return array{ok: bool, models: list<AiModel>, error: ?string}
     */
    public static function refresh(AiProvider $provider): array
    {
        $found = self::discover($provider);
        if (! $found['ok']) return self::fail((string) $found['error']);

        $byName = [];
        foreach ($found['candidates'] as $c) $byName[$c['litellm_model_name']] = $c;

        $touched = [];
        foreach (AiModel::query()->where('provider_id', $provider->id)->orderBy('litellm_model_name')->get() as $m) {
            $c = $byName[$m->litellm_model_name] ?? null;
            if ($c === null) continue;       // غائبٌ عن البوّابة: تصالحُه في W9 لا هنا

            $m->forceFill([
                'capabilities'       => self::mergeByRank((array) $m->capabilities, $c['capabilities']),
                'limits'             => self::mergeByRank((array) $m->limits, $c['limits']),
                'params'             => self::mergeByRank((array) $m->params, $c['params']),
                'pricing'            => self::mergeByRank((array) $m->pricing, $c['pricing']),
                'pricing_source'     => 'litellm',
                'pricing_updated_at' => now(),
                'updated_by'         => auth()->id(),
                // **ولا تُمَسّ**: enabled · priority · display_name · tags
            ])->save();

            $touched[] = $m;
        }

        return ['ok' => true, 'models' => $touched, 'error' => null];
    }

    // ── التسجيلُ اليدويّ — لمزوّدٍ لا اكتشافَ حقيقيَّ له ─────────────────

    /**
     * **يُسجَّل النموذجُ عند البوّابةِ باسمٍ يكتبه المديرُ ثمّ يُستورَد.**
     *
     * لا اكتشافَ لكلِّ مزوّد — والكتالوجُ (W1) يقول ذلك صراحةً لكلِّ مدخل
     * (`discovery: manual`) بدل أن تتظاهر الشاشةُ باكتشافٍ لا وجودَ له. ومن
     * غيرِ هذا المسارِ يبقى ذلك المزوّدُ بلا نموذجٍ واحدٍ إلى الأبد.
     *
     * **ولا كلفةَ في التسجيل**: كتابةُ إعدادٍ عند البوّابةِ لا طلبُ توليد.
     *
     * @return array{ok: bool, models: list<AiModel>, error: ?string}
     */
    public static function register(AiProvider $provider, string $hubName, string $upstream): array
    {
        $hubName  = trim($hubName);
        $upstream = trim($upstream);

        if ($hubName === '' || $upstream === '') {
            return self::fail('يلزم اسمٌ في Hub واسمُ النموذجِ عند المزوّد');
        }
        if ((string) $provider->credential_state === 'missing') {
            return self::fail('لا اعتمادَ لهذا المزوّد — اضبط الاعتمادَ قبل تسجيلِ نموذج');
        }
        if (AiModel::query()->where('litellm_model_name', $hubName)->exists()) {
            return self::fail('الاسمُ مستعمَلٌ في Hub — اختر اسماً آخر');
        }

        $res = LiteLlmAdmin::createModel($hubName, $upstream, (string) $provider->credential_name);
        if (! $res['ok']) {
            return self::fail('تعذّر تسجيلُ النموذجِ عند البوّابة: ' . (string) $res['error']);
        }

        self::trace('تسجيل نموذج ذكاء', $provider, [
            'litellm_model_name' => $hubName,
            'upstream_model'     => $upstream,
        ]);

        // ويُقرأ ما تقوله البوّابةُ عنه — **لا ما نظنّه نحن**
        $import = self::import($provider, [$hubName]);

        // **ويُوسَم بمصدرِه**: ادّعاءُ إنسانٍ لا نشرٌ مُكتشَفٌ ولا كتالوجٌ مقروء
        foreach (($import['models'] ?? []) as $m) {
            $m->forceFill(['discovery_source' => AiModelSources::MANUAL])->save();
        }

        return $import['ok'] ? $import : ['ok' => true, 'models' => [], 'error' => null];
    }

    // ── ③٫٥ التبنّي — اختيارٌ من كتالوجِ البوّابةِ بلا كتابةِ معرّف ──────

    /**
     * **اسمٌ داخليٌّ يُولَّد ولا يُخترَع.**
     *
     * كان المديرُ يُطالَب باسمَين لكلِّ نموذج: معرّفِه عند المزوّد، **واسمٍ
     * يخترعه له في Hub**. والثاني لا قيمةَ قرارٍ فيه في الأغلب — مجرّدُ
     * عَقَبةٍ أمام من يريد نموذجاً يعمل.
     *
     * فيُشتَقّ من المصدرِ نفسِه: `hub-{مفتاحُ المزوّد}-{المعرّفُ مُطبَّعاً}`.
     *
     * **وثلاثُ خصالٍ يحرسها التوليد:**
     *
     *  ① **حتميٌّ**: المُدخَلُ نفسُه يعطي الاسمَ نفسَه في كلِّ مرّة، فإعادةُ
     *     الاستيرادِ لا تُنتج صفّاً ثانياً لنموذجٍ واحد.
     *  ② **مطابقٌ للقيد**: حروفٌ وأرقامٌ و`. _ -` فقط — وهو القيدُ نفسُه
     *     المفروضُ على التسجيلِ اليدويّ، فلا مسارانِ بقاعدتَين.
     *  ③ **فريدٌ بلا قرعة**: عند التصادمِ يُضاف بصمةٌ قصيرةٌ من المعرّفِ
     *     الكاملِ لا رقمٌ تسلسليٌّ يختلف باختلافِ ترتيبِ الاستيراد.
     */
    public static function mintAlias(AiProvider $provider, string $upstream): string
    {
        $upstream = trim($upstream);
        if ($upstream === '') return '';

        // بعضُ المعرّفاتِ تحمل مفتاحَ مزوّدِها سابقةً — فلا يُكرَّر في الاسم
        $tail = mb_strtolower($upstream);
        $key  = mb_strtolower(trim((string) $provider->catalog_key));
        if ($key !== '' && str_starts_with($tail, $key . '/')) {
            $tail = mb_substr($tail, mb_strlen($key) + 1);
        }

        $slug = preg_replace('/[^a-z0-9._-]+/u', '-', $tail) ?? '';
        $slug = trim(preg_replace('/-{2,}/', '-', $slug) ?? '', '-._');

        $base = trim('hub-' . preg_replace('/[^a-z0-9._-]+/u', '-', $key) . '-' . $slug, '-._');
        if ($base === '' || $slug === '') $base = 'hub-' . substr(sha1($upstream), 0, 12);

        $base = mb_substr($base, 0, 160);

        if (! AiModel::query()->where('litellm_model_name', $base)->exists()) return $base;

        // **بصمةُ المعرّفِ الكامل** — حتميّةٌ ولا تتعلّق بترتيبِ الاستيراد
        return mb_substr($base, 0, 160) . '-' . substr(sha1($upstream), 0, 8);
    }

    /**
     * **تبنّي نماذجَ مُختارةٍ من الاكتشاف** — بضغطةٍ واحدةٍ وبلا كتابةِ معرّف.
     *
     * والمُدخَلُ **اختياراتٌ** لا نصٌّ حرّ: كلُّ اختيارٍ `مصدر|معرّف`، ويُطابَق
     * على مرشَّحي الاكتشافِ الحقيقيّين. فما لم يُكتشَف لا يُتبنّى — ولا يعبر
     * معرّفٌ من المتصفّحِ إلى البوّابةِ بلا أن يمرَّ على قائمةِ ما اكتُشف.
     *
     * **والمُسجَّلُ عند البوّابةِ سلفاً لا يُسجَّل ثانيةً**: مرشَّحُ السجلِّ
     * يُستورَد كما كان، ومرشَّحُ الكتالوجِ يُسجَّل أوّلاً ثمّ يُستورَد.
     *
     * @param  list<string>  $picks
     * @return array{ok: bool, models: list<AiModel>, skipped: list<string>, error: ?string}
     */
    public static function adopt(AiProvider $provider, array $picks): array
    {
        if ($picks === []) {
            return ['ok' => false, 'models' => [], 'skipped' => [], 'error' => 'لم يُختَر نموذجٌ — التبنّي بلا اختيارٍ يُرَدّ'];
        }
        if ((string) $provider->credential_state === 'missing') {
            return ['ok' => false, 'models' => [], 'skipped' => [], 'error' => 'لا اعتمادَ لهذا المزوّد — اضبطه قبل تبنّي نموذج'];
        }

        $found = AiModelSources::discover($provider);
        if (! $found['ok']) {
            return ['ok' => false, 'models' => [], 'skipped' => [], 'error' => (string) $found['error']];
        }

        $byPick = [];
        foreach ($found['candidates'] as $c) {
            $byPick[$c['source'] . '|' . $c['upstream_model']] = $c;
        }

        $models  = [];
        $skipped = [];
        $fromRegistry = [];

        foreach (array_values(array_unique(array_map('strval', $picks))) as $pick) {
            $c = $byPick[$pick] ?? null;
            if ($c === null || $c['already_imported']) { $skipped[] = $pick; continue; }

            /*
             * **وجذعُ العائلةِ لا يُتبنّى بضغطة.**
             *
             * معرّفٌ كهذا يُعلنه الكتالوجُ **مفتاحَ تسعيرٍ لعائلة**، لا نموذجاً
             * يُنادى: الحقيقيُّ يحمل بعده مقاطعَ حسابِك. فتبنّيه بضغطةٍ يُنتج
             * صفّاً يبدو سليماً حتّى يبلغ المزوّدَ فيردّ «لا نموذجَ بهذا
             * الاسم» — وهو ما وقع فعلاً في القبول.
             *
             * فيُرَدُّ هنا، ويُوجَّه المديرُ إلى إكمالِ معرّفِه من بابِ
             * التسجيلِ اليدويّ — حيث المعرّفُ الكاملُ يُقبَل بلا حَرَج.
             */
            if (! AiModelSources::adoptableInOneClick((string) ($c['availability'] ?? ''))) {
                $skipped[] = $pick;
                continue;
            }

            if ($c['source'] === AiModelSources::GATEWAY) {
                $fromRegistry[] = (string) $c['litellm_model_name'];
                continue;
            }

            $alias = self::mintAlias($provider, (string) $c['upstream_model']);
            if ($alias === '') { $skipped[] = $pick; continue; }

            // **البوّابةُ أوّلاً** — فلا صفٌّ في Hub يدّعي نشراً لم يقع
            $res = LiteLlmAdmin::createModel($alias, (string) $c['upstream_model'],
                (string) $provider->credential_name);
            if (! $res['ok']) { $skipped[] = $pick; continue; }

            $models[] = AiModel::create([
                'provider_id'        => $provider->id,
                'litellm_model_name' => $alias,
                'upstream_model'     => (string) $c['upstream_model'],
                'discovery_source'   => AiModelSources::CATALOG,
                'display_name'       => (string) $c['display_name'],
                'enabled'            => false,          // **التبنّي لا يُفعِّل**
                'capabilities'       => (array) $c['capabilities'],
                'limits'             => (array) $c['limits'],
                'params'             => (array) $c['params'],
                'pricing'            => (array) $c['pricing'],
                'pricing_source'     => AiModelSources::CATALOG,
                'pricing_updated_at' => now(),
                'health'             => 'UNKNOWN',
                'created_by'         => auth()->id(),
                'updated_by'         => auth()->id(),
            ]);
        }

        // ما كان مُسجَّلاً عند البوّابةِ يمرّ بمسارِ الاستيرادِ القائمِ نفسِه
        if ($fromRegistry !== []) {
            $imported = self::import($provider, $fromRegistry);
            if ($imported['ok']) {
                foreach ($imported['models'] as $m) $models[] = $m;
            } else {
                $skipped = array_merge($skipped, $fromRegistry);
            }
        }

        if ($models === []) {
            return ['ok' => false, 'models' => [], 'skipped' => $skipped,
                    'error' => 'لا نموذجَ جديدٌ يُتبنّى — المُختارُ مستورَدٌ سلفاً، أو هو '
                               . '**جذعُ عائلةٍ** يلزمه معرّفُك الكامل، أو تعذّر تسجيلُه'];
        }

        self::trace('تبنّي نماذج ذكاء', $provider, [
            'adopted' => array_map(static fn (AiModel $m) => $m->litellm_model_name, $models),
            'skipped' => $skipped,
            'enabled' => false,
        ]);

        return ['ok' => true, 'models' => $models, 'skipped' => $skipped, 'error' => null];
    }

    // ── ④ التهيئةُ والاختيار — قرارُ إنسان ──────────────────────────────

    /** @return array{ok: bool, models: list<AiModel>, error: ?string} */
    public static function configure(AiModel $model, array $input): array
    {
        $patch = [];
        if (array_key_exists('display_name', $input) && trim((string) $input['display_name']) !== '') {
            $patch['display_name'] = mb_substr(trim((string) $input['display_name']), 0, 300);
        }
        if (array_key_exists('priority', $input)) $patch['priority'] = (int) $input['priority'];
        if (array_key_exists('family', $input))   $patch['family']   = mb_substr(trim((string) $input['family']), 0, 120) ?: null;
        if (array_key_exists('version', $input))  $patch['version']  = mb_substr(trim((string) $input['version']), 0, 80) ?: null;
        if (array_key_exists('tags', $input)) {
            $tags = is_array($input['tags']) ? $input['tags'] : preg_split('/[,\s]+/u', (string) $input['tags'], -1, PREG_SPLIT_NO_EMPTY);
            $patch['tags'] = array_values(array_unique(array_map(static fn ($t) => mb_substr(trim((string) $t), 0, 40), (array) $tags)));
        }

        if ($patch === []) return self::fail('لا تغييرَ في التهيئة');

        $model->forceFill($patch + ['updated_by' => auth()->id()])->save();
        self::trace('تهيئة نموذج ذكاء', $model->provider, ['model' => $model->litellm_model_name, 'fields' => array_keys($patch)]);

        return ['ok' => true, 'models' => [$model], 'error' => null];
    }

    /**
     * **التفعيلُ قرارٌ صريحٌ بعد التهيئة** — ولا يُفعَّل نموذجٌ على مزوّدٍ بلا
     * اعتماد: زرٌّ يقول «مُشغَّل» وكلُّ طلبٍ يسقط ٤٠١ يُطارَد عطلاً لا وجودَ له.
     *
     * @return array{ok: bool, models: list<AiModel>, error: ?string}
     */
    public static function setEnabled(AiModel $model, bool $on): array
    {
        if ($on) {
            $provider = $model->provider;
            if ($provider === null || (string) $provider->credential_state === 'missing') {
                return self::fail('لا يُفعَّل نموذجٌ على مزوّدٍ بلا اعتماد');
            }
            if (! $provider->enabled) {
                return self::fail('المزوّدُ مُطفأ — شغّلِ المزوّدَ أوّلاً');
            }
        }

        $model->forceFill(['enabled' => $on, 'updated_by' => auth()->id()])->save();
        self::trace($on ? 'تفعيل نموذج ذكاء' : 'تعطيل نموذج ذكاء', $model->provider,
            ['model' => $model->litellm_model_name, 'enabled' => $on]);

        return ['ok' => true, 'models' => [$model], 'error' => null];
    }

    /**
     * **تجاوزٌ يدويٌّ موسومٌ `hub_override`** — يعلو الكلَّ ولا يُدهَس بتحديث.
     *
     * @param  'capabilities'|'limits'|'params'|'pricing'  $group
     * @return array{ok: bool, models: list<AiModel>, error: ?string}
     */
    public static function override(AiModel $model, string $group, string $key, mixed $value): array
    {
        if (! in_array($group, ['capabilities', 'limits', 'params', 'pricing'], true)) {
            return self::fail('مجموعةُ حقائقَ غيرُ معروفة');
        }

        $facts = (array) $model->{$group};
        if (! array_key_exists($key, $facts)) return self::fail('حقيقةٌ غيرُ معروفةٍ في هذه المجموعة');

        $fact = AiModelFacts::override($value, (string) (auth()->id() ?? ''));
        // الوسائطُ تستعمل `supported` لا `v` — والشكلُ يُحترَم كما هو
        if ($group === 'params') $fact = ['supported' => Tri::of($value)] + $fact;

        $facts[$key] = $fact;
        $model->forceFill([$group => $facts, 'updated_by' => auth()->id()])->save();

        self::trace('تجاوز يدويّ لحقيقة نموذج', $model->provider, [
            'model' => $model->litellm_model_name, 'group' => $group, 'key' => $key,
        ]);

        return ['ok' => true, 'models' => [$model], 'error' => null];
    }

    /**
     * **يُوسَم `verified` — ولا يكتبه إلّا فحصُ قدرةٍ ناجح** (المستوى E · W6).
     *
     * وهو أعلى رتبةً من `litellm`، فلا يدهسه تحديثٌ من الخريطة. ولا يُكتب
     * **إلّا عند النجاح**: فشلُ الطلبِ قد يكون مهلةً أو حدَّ معدّلٍ أو عطلاً
     * عابراً، فجعلُه «لا يدعم» يُغلق باباً مفتوحاً بدليلٍ لا يخصّ القدرة.
     *
     * @return array{ok: bool, models: list<AiModel>, error: ?string}
     */
    public static function recordVerified(AiModel $model, string $capability): array
    {
        $caps = (array) $model->capabilities;
        if (! array_key_exists($capability, $caps)) return self::fail('قدرةٌ غيرُ معروفة');

        $caps[$capability] = ['v' => true, 'src' => 'verified', 'at' => now()->toIso8601String()];
        $model->forceFill(['capabilities' => $caps, 'updated_by' => auth()->id()])->save();

        self::trace('إثبات قدرة نموذج باختبار', $model->provider, [
            'model' => $model->litellm_model_name, 'capability' => $capability,
        ]);

        return ['ok' => true, 'models' => [$model], 'error' => null];
    }

    // ── الداخل ─────────────────────────────────────────────────────────

    /**
     * **دمجٌ بالرتبة**: القادمُ يكتب، إلّا حيث المخزّنُ أعلى ثقةً.
     *
     * والمفاتيحُ غيرُ الحقائقِ (‏`currency`/`unit`/`fetched_at`) تُؤخَذ من القادمِ
     * دائماً — فوقتُ الجلبِ يجب أن يتحرّك وإلّا كذبت الشاشةُ عن طزاجةِ السعر.
     */
    private static function mergeByRank(array $stored, array $incoming): array
    {
        $out = [];
        foreach ($incoming as $k => $fact) {
            $out[$k] = (is_array($fact) && AiModelFacts::outranks($stored[$k] ?? null, (string) ($fact['src'] ?? 'unknown')))
                ? $stored[$k]
                : $fact;
        }

        return $out;
    }

    /** أثرٌ صريحٌ عند الكاتب — الجدولُ بلا `Auditable` (قرارُ المالك · B) */
    private static function trace(string $action, ?AiProvider $provider, array $extra): void
    {
        if ($provider === null) return;
        hub_audit($action, AiModel::MODULE, (string) $provider->id, (string) $provider->label,
            ['after' => Redactor::arr($extra)]);
    }

    /** @return array{ok: bool, models: list<AiModel>, error: string} */
    private static function fail(string $why): array
    {
        return ['ok' => false, 'models' => [], 'error' => $why];
    }
}
