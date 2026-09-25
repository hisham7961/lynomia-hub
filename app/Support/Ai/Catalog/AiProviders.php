<?php

namespace App\Support\Ai\Catalog;

use App\Models\AiProvider;
use Illuminate\Support\Str;
use App\Support\Ai\Gateway\LiteLlmAdmin;
use App\Support\Redactor;

/**
 * **دورةُ حياةِ اعتمادِ المزوّد** (المرحلة ٢ · W4).
 *
 * الكاتبُ الواحدُ لكلِّ ما يُنشئ اعتماداً أو يُدوّره أو يُبطله — فلا يتفرّق
 * القرارُ على شاشاتٍ ولا يُنسى حارسٌ في مسار.
 *
 * **والسرُّ يعبر ولا يستقرّ.** قيمُ الاعتمادِ تُقرأ من الطلبِ، وتُفرَز بوجهتِها
 * من الكتالوج (`AiCatalog::split`)، ويُرسَل شقُّ `credential` إلى البوّابةِ في
 * الذاكرة. ولا يُكتَب في `ai_providers` إلّا **المرجعُ والحالةُ والحقولُ غيرُ
 * السرّيّة** — ولا في التدقيقِ إلّا **بصمةٌ** لا تُعكَس.
 *
 * ── **أربعةُ ثوابتَ يحرسها هذا الصنف** ──
 *
 *  ① **لا صفَّ قبل أن تقبل البوّابة.** الاعتمادُ يُنشَأ أوّلاً؛ فإن رُفض لم
 *     يُكتَب صفٌّ البتّة. وإلّا لبقي في Hub مزوّدٌ يُعلِن `credential_name`
 *     لا وجودَ له في الخزنة — شاشةٌ تقول «مُهيَّأ» وكلُّ طلبٍ يسقط ٤٠١.
 *
 *  ② **التدويرُ لا يُغيّر الاسم — وهذا هو الثابتُ الحاسم.** أثبت W0 أنّ كلَّ
 *     نموذجٍ مُسجَّلٍ يحمل `litellm_credential_name` **اسماً** لا مفتاحاً
 *     (`types/router.py:320`). فلو وُلِّد اسمٌ جديدٌ عند كلِّ تدويرٍ لانفصل
 *     **كلُّ** نموذجٍ عن اعتمادِه صامتاً: الاسمُ القديمُ يُحذَف والنماذجُ تشير
 *     إليه. فالاسمُ يُولَّد **مرّةً عند الإنشاء** ويبقى مدى حياةِ المزوّد.
 *
 *  ③ **الإبطالُ يقول الحقيقةَ عن الخزنةِ لا عن نيّتنا.** بعد حذفِ الاعتمادِ
 *     عند البوّابةِ تصير الحالةُ `missing` — لأنّه **غائبٌ فعلاً**. ولا حالةَ
 *     رابعةٌ اسمُها «مُبطَل»: مفرداتُ W1 ثلاثٌ
 *     (`missing`/`configured`/`verified`)، و«مَن أبطلَ ومتى» جوابُه سجلُّ
 *     التدقيقِ لا عمودُ حالة. وإن رفضت البوّابةُ الحذفَ **لا تُغيَّر الحالة** —
 *     فصفٌّ يقول `missing` وسرٌّ حيٌّ في الخزنةِ أسوأُ من عطلٍ معلن.
 *
 *  ④ **لا اسمَ مزوّدٍ في هذا الملفّ.** الفرزُ والتحقّقُ من المخطَّط، والنداءُ
 *     من `LiteLlmAdmin` — فمزوّدٌ جديدٌ من شكلٍ مدعومٍ لا يحتاج سطراً هنا.
 */
final class AiProviders
{
    /** الحالاتُ الثلاثُ — مفرداتُ `AiCatalog::secretState` نفسُها، لا مفرداتٌ ثانية */
    public const STATES = ['missing', 'configured', 'verified'];

    /** شكلُ الجواب الموحَّد — كشكلِ `LiteLlmAdmin::SHAPE` فلا شكلَ يُخترَع */
    public const SHAPE = ['ok', 'provider', 'error'];

    /**
     * **حارسُ النهايةِ الصادرة** — يُغلق SSRF عبر البوّابة، لا عبر Hub.
     *
     * **ولماذا هنا لا في الشاشة؟** لأنّ العنوانَ الذي يُكتَب في حقلِ نهايةٍ
     * **لا يتّصل به Hub**: يُخزَّن في الاعتمادِ ثمّ يتّصل به **مُحرّكُ البوّابةِ
     * نفسُه** عند أوّلِ طلبِ توليد. فحارسُ الصادرِ في مسارِ نداءاتِنا لا يراه
     * أبداً، وقواعدُ `url` تقبل `http://169.254.169.254` وهي «رابطٌ صالح».
     *
     * فبلا هذا الحارسِ يصير حقلُ إعدادٍ في شاشةِ إدارةٍ **بابَ قراءةٍ لشبكةٍ
     * داخليّةٍ من داخلِها**: عنوانُ بياناتِ سحابةٍ أو خدمةٌ خلفَ الجدار، يقرؤها
     * المُحرّكُ ويُعيد ما قرأ في ردِّ نموذج.
     *
     * **والفحصُ من المخطَّطِ لا من اسمِ الحقل:** كلُّ حقلٍ نوعُه `url` أو في
     * قواعدِه `url` — فعائلةٌ جديدةٌ تُضاف غداً تُحرَس بلا سطرٍ هنا.
     *
     * ويُستدعى **قبل أيِّ نداء**: الرفضُ لا يُرسِل شيئاً إلى البوّابة.
     */
    private static function endpointGuard(string $catalogKey, array $input): ?string
    {
        foreach (AiCatalog::fields($catalogKey) as $f) {
            $isEndpoint = ($f['type'] ?? null) === 'url'
                || in_array('url', (array) ($f['rules'] ?? []), true);
            if (! $isEndpoint) continue;

            $value = trim((string) ($input[$f['key']] ?? ''));
            if ($value === '') continue;   // الفارغُ يُحكَم عليه بالإلزامِ لا بالحارس

            $gate = hub_outbound_ok($value);
            if (! ($gate['ok'] ?? false)) {
                return 'نهايةٌ مرفوضة في «' . (string) $f['label'] . '»: ' . (string) ($gate['why'] ?? 'غيرُ مسموحة');
            }
        }

        return null;
    }

    /**
     * **اسمُ الاعتماد عند البوّابة** — مشتقٌّ لا مُدخَل.
     *
     * تركُه للمستخدم يفتح بابَ تصادمٍ بين مزوّدَين ويسرّب تسميةً داخليّةً إلى
     * واجهةٍ لا تحتاجها. والبادئةُ `hub-` تُميّز ما أنشأه Hub عمّا أُنشئ يدويّاً
     * في البوّابة، فلا يمحو التصالحُ في W9 ما ليس لنا.
     */
    public static function mintCredentialName(string $catalogKey): string
    {
        return mb_substr('hub-' . $catalogKey . '-' . Str::lower(Str::random(10)), 0, 191);
    }

    /**
     * **إضافةُ مزوّدٍ وإنشاءُ اعتمادِه** — الخطوةُ الوحيدةُ التي يعبرها سرٌّ أوّلَ مرّة.
     *
     * @param  array<string,mixed>  $input  مُدخَلُ النموذجِ بمفاتيحِ الكتالوج
     * @return array{ok: bool, provider: ?AiProvider, error: ?string}
     */
    public static function add(string $catalogKey, string $label, array $input): array
    {
        if (! AiCatalog::exists($catalogKey)) {
            return self::fail('مزوّدٌ غيرُ معروفٍ في الكتالوج');
        }

        if ($why = self::missingRequired($catalogKey, $input)) return self::fail($why);
        if ($why = self::endpointGuard($catalogKey, $input)) return self::fail($why);

        $split = AiCatalog::split($catalogKey, $input);
        if ($split['credential'] === []) {
            return self::fail('لا قيمَ اعتمادٍ في المُدخَل — لا يُنشَأ اعتمادٌ فارغ');
        }

        $name = self::mintCredentialName($catalogKey);

        // ① البوّابةُ أوّلاً: لا صفَّ يدّعي اعتماداً لم يُقبَل
        $res = LiteLlmAdmin::createCredential($name, $split['credential'], [
            'hub_catalog_key' => $catalogKey,   // **وصفيٌّ غيرُ مشفَّرٍ عند البوّابة** — فلا سرَّ فيه
        ]);
        if (! $res['ok']) {
            return self::fail('تعذّر إنشاءُ الاعتمادِ عند البوّابة: ' . (string) $res['error']);
        }

        $provider = AiProvider::create([
            'catalog_key'      => $catalogKey,
            'label'            => $label !== '' ? $label : (string) (AiCatalog::provider($catalogKey)['label'] ?? $catalogKey),
            'enabled'          => false,        // **الإنشاءُ لا يُشغّل** — التشغيلُ قرارٌ ثانٍ صريح
            'credential_name'  => $name,
            'config'           => self::safeConfig($catalogKey, $split['config']),
            'credential_state' => AiCatalog::secretState(true, false),   // `configured`
            'health'           => 'UNKNOWN',
            'created_by'       => auth()->id(),
            'updated_by'       => auth()->id(),
        ]);

        self::trace('إضافة مزوّد ذكاء', $provider, [
            'catalog_key'     => $catalogKey,
            'credential_name' => $name,
            'fields'          => array_keys($split['credential']),          // **الأسماءُ لا القيم**
            'secret_digest'   => self::digest($catalogKey, $split['credential']),
        ]);

        return ['ok' => true, 'provider' => $provider, 'error' => null];
    }

    /**
     * **تدويرُ السرّ** — الاسمُ نفسُه والقيمُ جديدة (الثابتُ ②).
     *
     * @return array{ok: bool, provider: ?AiProvider, error: ?string}
     */
    public static function rotate(AiProvider $provider, array $input): array
    {
        $key = (string) $provider->catalog_key;
        if (! AiCatalog::exists($key)) return self::fail('مزوّدٌ غيرُ معروفٍ في الكتالوج');
        if ($why = self::endpointGuard($key, $input)) return self::fail($why);

        $split = AiCatalog::split($key, $input);
        if ($split['credential'] === []) {
            return self::fail('لا قيمَ اعتمادٍ في المُدخَل — التدويرُ بلا قيمٍ يُرَدّ');
        }

        $before = (string) $provider->credential_state;

        $res = LiteLlmAdmin::updateCredential((string) $provider->credential_name, $split['credential'], [
            'hub_catalog_key' => $key,
        ]);
        if (! $res['ok']) {
            return self::fail('تعذّر تدويرُ الاعتمادِ عند البوّابة: ' . (string) $res['error']);
        }

        // التدويرُ يُبطِل أيَّ تحقّقٍ سابق: السرُّ الجديدُ لم يُختبَر بعد
        $provider->forceFill([
            'credential_state' => AiCatalog::secretState(true, false),
            'config'           => self::safeConfig($key, $split['config']) + (array) $provider->config,
            'updated_by'       => auth()->id(),
        ])->save();

        self::trace('تدوير اعتماد ذكاء', $provider, [
            'credential_name' => (string) $provider->credential_name,   // **لم يتغيّر — وهذا هو المقصود**
            'state_before'    => $before,
            'state_after'     => (string) $provider->credential_state,
            'fields'          => array_keys($split['credential']),
            'secret_digest'   => self::digest($key, $split['credential']),
        ]);

        return ['ok' => true, 'provider' => $provider, 'error' => null];
    }

    /**
     * **إبطالٌ قاطع** — السرُّ يزول عند البوّابة، والمزوّدُ يبقى صفّاً مُعطَّلاً.
     *
     * ولا تُغيَّر الحالةُ إن رفضت البوّابةُ الحذف (الثابتُ ③).
     *
     * @return array{ok: bool, provider: ?AiProvider, error: ?string}
     */
    public static function revoke(AiProvider $provider): array
    {
        $res = LiteLlmAdmin::deleteCredential((string) $provider->credential_name);
        if (! $res['ok']) {
            return self::fail('تعذّر إبطالُ الاعتمادِ عند البوّابة: ' . (string) $res['error']
                . ' — والحالةُ لم تُغيَّر، فلا تقول الشاشةُ «مُبطَل» وسرٌّ حيّ');
        }

        $before = (string) $provider->credential_state;
        $provider->forceFill([
            'credential_state' => 'missing',
            'enabled'          => false,        // مزوّدٌ بلا اعتمادٍ لا يُشغَّل
            'health'           => 'UNKNOWN',
            'updated_by'       => auth()->id(),
        ])->save();

        self::trace('إبطال اعتماد ذكاء', $provider, [
            'credential_name' => (string) $provider->credential_name,
            'state_before'    => $before,
            'state_after'     => 'missing',
        ]);

        return ['ok' => true, 'provider' => $provider, 'error' => null];
    }

    /**
     * **حذفُ المزوّد** — إبطالٌ أوّلاً ثمّ حذفٌ ناعم.
     *
     * والترتيبُ ليس تفصيلاً: حذفُ الصفِّ أوّلاً يُفقِد اسمَ الاعتمادِ فيبقى
     * السرُّ في الخزنةِ بلا من يعرف اسمَه — **تسريبٌ دائمٌ بفعلِ تنظيف**.
     *
     * @return array{ok: bool, provider: ?AiProvider, error: ?string}
     */
    public static function remove(AiProvider $provider): array
    {
        if ((string) $provider->credential_state !== 'missing') {
            $rev = self::revoke($provider);
            if (! $rev['ok']) return $rev;
        }

        self::trace('حذف مزوّد ذكاء', $provider, [
            'catalog_key'     => (string) $provider->catalog_key,
            'credential_name' => (string) $provider->credential_name,
        ]);

        $provider->delete();

        return ['ok' => true, 'provider' => $provider, 'error' => null];
    }

    /**
     * **تشغيلٌ وإطفاء** — تعطيلٌ غيرُ متلِفٍ يُفضَّل على الحذف.
     *
     * ولا يُشغَّل مزوّدٌ بلا اعتماد: زرٌّ يقول «مُشغَّل» وكلُّ طلبٍ يسقط ٤٠١
     * يُطارَد عطلاً لا وجودَ له.
     *
     * @return array{ok: bool, provider: ?AiProvider, error: ?string}
     */
    public static function setEnabled(AiProvider $provider, bool $on): array
    {
        if ($on && (string) $provider->credential_state === 'missing') {
            return self::fail('لا يُشغَّل مزوّدٌ بلا اعتماد — اضبط الاعتمادَ أوّلاً');
        }

        $provider->forceFill(['enabled' => $on, 'updated_by' => auth()->id()])->save();

        self::trace($on ? 'تشغيل مزوّد ذكاء' : 'إطفاء مزوّد ذكاء', $provider, ['enabled' => $on]);

        return ['ok' => true, 'provider' => $provider, 'error' => null];
    }

    // ── الداخل ─────────────────────────────────────────────────────────

    /**
     * الحقولُ الإلزاميّةُ **بالقيمِ المُرسَلة** — فالشرطيُّ المخفيُّ ليس إلزاميّاً.
     * تُعاد رسالةٌ عربيّةٌ تُسمّي الحقلَ الناقصَ، أو `null` إن اكتمل.
     */
    private static function missingRequired(string $catalogKey, array $input): ?string
    {
        $missing = [];
        foreach (AiCatalog::requiredFields($catalogKey, $input) as $f) {
            $v = $input[$f['key']] ?? null;
            if ($v === null || $v === '') $missing[] = (string) $f['label'];
        }

        return $missing === [] ? null : 'حقولٌ إلزاميّةٌ ناقصة: ' . implode('، ', $missing);
    }

    /**
     * **حزامٌ فوق المخطَّط**: يُسقط من شقِّ `config` أيَّ حقلٍ يصفه الكتالوجُ
     * سرّاً. القاعدةُ F6 في W1 تمنع ذلك أصلاً (سرٌّ ⇐ اعتماد)، لكنّ الكتالوجَ
     * نصٌّ يُحرَّر — وحزامٌ ثانٍ أرخصُ من سرٍّ في عمودِ JSON.
     */
    private static function safeConfig(string $catalogKey, array $config): array
    {
        $secret = AiCatalog::secretKeys($catalogKey);

        return array_diff_key($config, array_flip($secret));
    }

    /**
     * **بصمةُ السرِّ للتدقيق** — تُجيب «أتغيّر المفتاحُ فعلاً؟» ولا تكشفه.
     *
     * بصيغةِ `Redactor::fingerprint` نفسِها فلا صيغةَ ثانيةٌ في النظام.
     */
    private static function digest(string $catalogKey, array $credential): string
    {
        $secret = AiCatalog::secretKeys($catalogKey);
        $parts  = [];
        foreach ($secret as $k) {
            if (isset($credential[$k]) && $credential[$k] !== '') $parts[] = $k . '=' . $credential[$k];
        }

        return $parts === [] ? 'sha256:—' : Redactor::fingerprint(implode('|', $parts));
    }

    /**
     * **أثرٌ صريحٌ عند الكاتب** — لا سمةُ `Auditable` (قرارُ المالك · B).
     *
     * والمصفوفةُ تمرّ بـ`Redactor::arr` قبل الكتابة: حزامٌ أخيرٌ لو تسرّب
     * مفتاحٌ سرّيٌّ إلى `$extra` يوماً بتعديلِ مُستدعٍ.
     */
    private static function trace(string $action, AiProvider $provider, array $extra): void
    {
        hub_audit($action, AiProvider::MODULE, (string) $provider->id, (string) $provider->label,
            ['after' => Redactor::arr($extra)]);
    }

    /** @return array{ok: bool, provider: ?AiProvider, error: string} */
    private static function fail(string $why): array
    {
        return ['ok' => false, 'provider' => null, 'error' => $why];
    }
}
