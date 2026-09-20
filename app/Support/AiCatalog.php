<?php

namespace App\Support;

/**
 * **محرّكُ كتالوجِ المزوّدين** — يقرأ الوصفَ ويُصادِقه ويوجّه كلَّ حقلٍ إلى
 * وجهتِه. (المرحلة ٢ · W1)
 *
 * **ولا اسمَ مزوّدٍ واحدٍ في هذا الصنف.** كلُّ ما يعرفه: أنّ للمزوّدِ حقولاً،
 * ولكلِّ حقلٍ وجهةً وشرطَ ظهورٍ وتحقّقاً. وأيُّ `if ($key === '…')` هنا نقضٌ
 * للعقد — ويسقطه اختبارٌ صريح.
 *
 * **والوجهتان معناهما دقيقٌ لا اصطلاحيّ** (أثبته W0):
 *
 *  · `credential` → يصير جزءاً من `credential_values` عند البوّابة، مشفَّراً
 *    بمفتاحِ الملح. **مشترَكٌ بين كلِّ نماذجِ المزوّد**، وتغييرُه تدويرُ اعتماد.
 *    **وليس كلُّ ما هنا سرّاً**: نقطةُ النهايةِ ليست سرّاً وهي جزءٌ من
 *    الاعتماد، إذ المفتاحُ صالحٌ لها وحدَها.
 *
 *  · `config` → يبقى في Hub ويُحقَن في `litellm_params` وقتَ تسجيلِ النموذج.
 *    مرئيٌّ وقابلٌ للتحرير **بلا مساسٍ بالاعتماد**.
 *
 * **و`secret` ليست الوجهةَ بل صفةَ القيمة.** فحقلٌ سرّيٌّ **يجب** أن يذهب إلى
 * `credential` (لا يُخزَّن في Hub إطلاقاً)، ولا عكسَ ذلك.
 *
 * **وW1 لا يُرسل شيئاً ولا يخزّن شيئاً.** هذا الصنفُ **دالّةٌ خالصة**: يقرأ
 * ملفَّ إعدادٍ ويُعيد مصفوفات. لا قاعدةَ بيانات، ولا شبكة، ولا حالة.
 */
final class AiCatalog
{
    /** وجهتا الحقلِ — لا ثالثةَ لهما */
    public const DESTINATIONS = ['credential', 'config'];

    /**
     * أساليبُ اكتشافِ النماذج — **ثلاثٌ لأنّ الواقعَ ثلاث** (W0 · `utils.py:7422`):
     *  · `live`    استعلامٌ حيٌّ من واجهةِ المزوّد
     *  · `catalog` قائمةٌ ساكنةٌ من خريطةِ الإصدار
     *  · `manual`  **لا اكتشافَ البتّة** — تُضاف النماذجُ يدويّاً
     */
    public const DISCOVERY_MODES = ['live', 'catalog', 'manual'];

    public const FIELD_TYPES = ['text', 'password', 'select', 'number', 'url', 'bool'];

    /**
     * أساليبُ المصادقةِ المعروفةُ للكتالوج — وصفيّةٌ لا سلوكيّة.
     *
     * **وهذه المفرداتُ الخمسُ أصليّةٌ تبقى** (W1)، ويُضاف إليها وقتَ التحقّقِ
     * ما تُعلنه `AiAuthSchemas` من عائلاتٍ مولَّدة — فالمداخلُ المكتوبةُ بيدٍ
     * والمداخلُ المشتقّةُ من عائلةٍ تمرّان بمُصادِقٍ واحدٍ لا باثنين.
     */
    public const AUTH_MODES = ['api_key', 'api_key_endpoint', 'bearer_token', 'cloud_iam', 'none'];

    /** @return list<string> كلُّ ما يُقبَل في `auth`: الخمسُ الأصليّةُ + العائلات */
    public static function authModes(): array
    {
        return array_values(array_unique(array_merge(self::AUTH_MODES, AiAuthSchemas::names())));
    }

    /** **قائمةٌ صارمةٌ لخصائصِ الحقل** — ما ليس فيها خطأٌ مطبعيٌّ لا ميزة */
    public const FIELD_PROPS = [
        'key', 'label', 'type', 'required', 'secret', 'sends_to',
        'rules', 'options', 'hint', 'placeholder', 'default', 'show_if',
    ];

    /**
     * خصائصُ المزوّد. **والتسعُ الأولى أصليّةٌ من W1**؛ والأربعُ الأخيرةُ
     * أُضيفت مع التغطيةِ الكاملةِ ليحمل المدخلُ **أصلَه وحالتَه**: أمن كتالوجٍ
     * مكتوبٍ بيدٍ هو أم مشتقٌّ من عائلة، وأيُّ وسائطَ يخدم، وإن كان غيرَ قابلٍ
     * للإعدادِ فلماذا. وبلا هذه الأربعِ تصير المصفوفةُ ادّعاءً لا بياناً.
     */
    public const PROVIDER_PROPS = [
        'label', 'label_en', 'icon', 'litellm_key', 'auth',
        'discovery', 'discovery_note', 'docs_url', 'fields',
        'source', 'status', 'status_reason', 'modalities',
    ];

    public const PROVIDER_REQUIRED = ['label', 'litellm_key', 'auth', 'discovery', 'fields'];
    public const FIELD_REQUIRED    = ['key', 'label', 'type', 'sends_to'];

    // ── القراءة ────────────────────────────────────────────────────────

    /**
     * **الكتالوجُ كلُّه: المكتوبُ بيدٍ فوق المشتقِّ من السجلّ.**
     *
     * المشتقُّ يغطّي كلَّ ما تدعمه البوّابةُ (`AiProviderRegistry`)، والمكتوبُ
     * بيدٍ يعلو عليه حيث كُتب — بعنوانِه وتلميحاتِه ووضعِ اكتشافِه. فلا يُفقَد
     * ما صِيغ بعناية، ولا يبقى المزوّدون الباقون خارجَ الشاشة.
     *
     * @return array<string,array>
     */
    public static function all(): array
    {
        $curated = config('ai_catalog', []);
        $curated = is_array($curated) ? $curated : [];

        return array_replace(AiProviderRegistry::catalog(), $curated);
    }

    /** المكتوبُ بيدٍ وحدَه — يقرؤه حارسُ «لا اسمَ مزوّدٍ في app/» @return list<string> */
    public static function curatedKeys(): array
    {
        $curated = config('ai_catalog', []);

        return is_array($curated) ? array_keys($curated) : [];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function provider(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function exists(string $key): bool
    {
        return self::provider($key) !== null;
    }

    /** @return list<array> */
    public static function fields(string $key): array
    {
        return array_values(self::provider($key)['fields'] ?? []);
    }

    public static function field(string $key, string $fieldKey): ?array
    {
        foreach (self::fields($key) as $f) {
            if (($f['key'] ?? null) === $fieldKey) return $f;
        }

        return null;
    }

    /** مفاتيحُ الحقولِ السرّيّة — يقرؤها كلُّ حارسِ حجبٍ لاحقاً */
    public static function secretKeys(string $key): array
    {
        return array_values(array_map(
            static fn (array $f) => $f['key'],
            array_filter(self::fields($key), static fn (array $f) => ! empty($f['secret']))
        ));
    }

    public static function discovery(string $key): ?string
    {
        return self::provider($key)['discovery'] ?? null;
    }

    /** أيُّ وجهةٍ لهذا الحقل؟ — `null` إن لم يكن معرّفاً */
    public static function destinationOf(string $key, string $fieldKey): ?string
    {
        return self::field($key, $fieldKey)['sends_to'] ?? null;
    }

    // ── السلوكُ الديناميّ ──────────────────────────────────────────────

    /**
     * **أيُّ حقلٍ يظهر بالقيمِ الحاليّة؟** — تقييمُ `show_if`.
     *
     * الحقلُ بلا `show_if` يظهر دائماً. وذو الشرطِ يظهر إن طابقت **كلُّ**
     * شروطِه القيمَ الحاليّة، وإلّا اختفى. والقيمةُ الغائبةُ تسقط إلى افتراضِ
     * الحقلِ المُوجِّهِ إن وُجد — فلا تختفي الحقولُ كلُّها عند أوّلِ فتحِ نموذج.
     */
    public static function visibleFields(string $key, array $values = []): array
    {
        $out = [];
        foreach (self::fields($key) as $f) {
            $cond = $f['show_if'] ?? null;
            if (! is_array($cond) || $cond === []) { $out[] = $f; continue; }

            $show = true;
            foreach ($cond as $onKey => $expect) {
                $actual = $values[$onKey] ?? (self::field($key, $onKey)['default'] ?? null);
                if ((string) $actual !== (string) $expect) { $show = false; break; }
            }
            if ($show) $out[] = $f;
        }

        return $out;
    }

    /** الحقولُ الإلزاميّةُ **فعلاً** بالقيمِ الحاليّة — الشرطيُّ المخفيُّ ليس إلزاميّاً */
    public static function requiredFields(string $key, array $values = []): array
    {
        return array_values(array_filter(
            self::visibleFields($key, $values),
            static fn (array $f) => ! empty($f['required'])
        ));
    }

    /**
     * **فرزُ المُدخَلِ إلى وجهتيه** — دالّةٌ خالصةٌ لا تُخزّن ولا تُرسل.
     *
     * ‏W1 يُعرِّف العقدَ وحدَه؛ ومستهلِكُه W3/W4. والفرزُ **آليٌّ من المخطَّط**
     * لا قرارٌ بشريٌّ يُنسى: `sends_to` تقوله، لا ذاكرةُ من يكتب الشيفرة.
     *
     * @return array{credential: array<string,mixed>, config: array<string,mixed>, ignored: list<string>}
     */
    public static function split(string $key, array $input): array
    {
        $out = ['credential' => [], 'config' => [], 'ignored' => []];
        foreach ($input as $k => $v) {
            $dest = self::destinationOf($key, (string) $k);
            if ($dest === null) { $out['ignored'][] = (string) $k; continue; }
            $out[$dest][(string) $k] = $v;
        }

        return $out;
    }

    /**
     * **حالةُ الحقلِ السرّيِّ للعرض** — ولا قيمةَ فيها أبداً.
     *
     * ‏Hub لا يملك أسرارَ المزوّدين (قرارُ الاعتمادات B). والقناعُ حين يُعرَض
     * يأتي **من البوّابة** لا من عندنا — أثبت W0 أنّها تُقنِّع عند القراءة.
     */
    public static function secretState(bool $storedAtGateway, bool $verified = false): string
    {
        if (! $storedAtGateway) return 'missing';

        return $verified ? 'verified' : 'configured';
    }

    // ── التحقّقُ من سلامةِ الوصف ───────────────────────────────────────

    /**
     * **يُصادِق الكتالوجَ كلَّه ويُعيد قائمةَ أخطاء** — فارغةٌ = سليم.
     *
     * والفشلُ **مبكرٌ وحتميّ**: خطأٌ في الوصفِ يُكشَف عند التحميلِ لا عند أوّلِ
     * مستخدمٍ يحاول ربطَ مزوّد.
     *
     * @return list<string>
     */
    public static function validate(?array $catalog = null): array
    {
        $catalog = $catalog ?? self::all();
        $errors  = [];

        if ($catalog === []) return ['الكتالوجُ فارغ'];

        foreach ($catalog as $pKey => $p) {
            $at = "[$pKey]";

            if (! is_string($pKey) || ! preg_match('/^[a-z][a-z0-9_]*$/', (string) $pKey)) {
                $errors[] = "$at مفتاحُ المزوّدِ يجب أن يكون حروفاً صغيرةً وأرقاماً وشرطاتٍ سفليّة";
            }
            if (! is_array($p)) { $errors[] = "$at التعريفُ ليس مصفوفة"; continue; }

            foreach (self::PROVIDER_REQUIRED as $req) {
                if (! array_key_exists($req, $p) || $p[$req] === '' || $p[$req] === null) {
                    $errors[] = "$at ينقصه `$req`";
                }
            }
            foreach (array_keys($p) as $prop) {
                if (! in_array($prop, self::PROVIDER_PROPS, true)) {
                    $errors[] = "$at خاصّيّةٌ غيرُ معروفة `$prop`";
                }
            }
            if (isset($p['discovery']) && ! in_array($p['discovery'], self::DISCOVERY_MODES, true)) {
                $errors[] = "$at أسلوبُ اكتشافٍ غيرُ معروف `{$p['discovery']}`";
            }
            if (isset($p['auth']) && ! in_array($p['auth'], self::authModes(), true)) {
                $errors[] = "$at أسلوبُ مصادقةٍ غيرُ معروف `{$p['auth']}`";
            }

            $fields = $p['fields'] ?? null;
            if (! is_array($fields) || $fields === []) {
                $errors[] = "$at لا حقولَ معرّفة";
                continue;
            }

            $errors = array_merge($errors, self::validateFields($at, $fields));
        }

        return $errors;
    }

    /** @return list<string> */
    private static function validateFields(string $at, array $fields): array
    {
        $errors = [];
        $seen   = [];
        $byKey  = [];

        foreach ($fields as $i => $f) {
            $fAt = "$at حقل#$i";
            if (! is_array($f)) { $errors[] = "$fAt ليس مصفوفة"; continue; }

            foreach (self::FIELD_REQUIRED as $req) {
                if (! array_key_exists($req, $f) || $f[$req] === '' || $f[$req] === null) {
                    $errors[] = "$fAt ينقصه `$req`";
                }
            }
            foreach (array_keys($f) as $prop) {
                if (! in_array($prop, self::FIELD_PROPS, true)) {
                    $errors[] = "$fAt خاصّيّةٌ غيرُ معروفة `$prop`";
                }
            }

            $k = $f['key'] ?? null;
            if (is_string($k) && $k !== '') {
                if (isset($seen[$k])) $errors[] = "$at مفتاحُ حقلٍ مكرّر `$k`";
                $seen[$k] = true;
                $byKey[$k] = $f;
                $fAt = "$at [$k]";
            }

            $type   = $f['type']     ?? null;
            $dest   = $f['sends_to'] ?? null;
            $secret = ! empty($f['secret']);

            if ($type !== null && ! in_array($type, self::FIELD_TYPES, true)) {
                $errors[] = "$fAt نوعٌ غيرُ معروف `$type`";
            }
            if ($dest !== null && ! in_array($dest, self::DESTINATIONS, true)) {
                $errors[] = "$fAt وجهةٌ غيرُ معروفة `$dest`";
            }

            // ── ثوابتُ السرّ — أصلبُ ما في المُصادِق ──
            if ($secret && $dest !== 'credential') {
                $errors[] = "$fAt حقلٌ سرّيٌّ وجهتُه `$dest` — والسرُّ لا يُخزَّن في Hub إطلاقاً";
            }
            if ($secret && $type !== 'password') {
                $errors[] = "$fAt حقلٌ سرّيٌّ نوعُه `$type` — يجب `password`";
            }
            if ($secret && array_key_exists('default', $f)) {
                $errors[] = "$fAt حقلٌ سرّيٌّ له قيمةٌ افتراضيّة — ولا سرَّ افتراضيٌّ يعمل";
            }
            if (! $secret && $type === 'password') {
                $errors[] = "$fAt نوعُه `password` وليس موسوماً `secret` — تناقض";
            }

            if ($type === 'select' && (! isset($f['options']) || ! is_array($f['options']) || $f['options'] === [])) {
                $errors[] = "$fAt من نوع `select` بلا `options`";
            }
            if (isset($f['rules']) && ! is_array($f['rules'])) {
                $errors[] = "$fAt `rules` يجب أن تكون مصفوفة";
            }
        }

        return array_merge($errors, self::validateConditions($at, $byKey));
    }

    /**
     * **تحقّقُ الشروط** — `show_if` مرجعٌ صحيحٌ ولا دورةَ فيه ولا خيارَ وهميّ.
     *
     * وأدقُّ قاعدةٍ هنا: حقلٌ شرطُه `['x' => 'v']` **يلزمه** أن يكون `x` قائمةَ
     * خياراتٍ تحوي `v`. فشرطٌ على خيارٍ لا وجودَ له يُنتج حقلاً **لا يظهر
     * أبداً** — وذاك عطلٌ صامتٌ لا يكشفه إلّا مستخدمٌ يبحث عن حقلٍ مفقود.
     *
     * @return list<string>
     */
    private static function validateConditions(string $at, array $byKey): array
    {
        $errors = [];

        foreach ($byKey as $k => $f) {
            $cond = $f['show_if'] ?? null;
            if ($cond === null) continue;
            if (! is_array($cond) || $cond === []) {
                $errors[] = "$at [$k] `show_if` يجب أن تكون مصفوفةً غيرَ فارغة";
                continue;
            }

            foreach ($cond as $onKey => $expect) {
                if (! is_string($onKey) || ! isset($byKey[$onKey])) {
                    $errors[] = "$at [$k] `show_if` يشير إلى حقلٍ غيرِ موجود `$onKey`";
                    continue;
                }
                if ($onKey === $k) {
                    $errors[] = "$at [$k] `show_if` يشير إلى نفسِه";
                    continue;
                }

                $on = $byKey[$onKey];
                if (($on['type'] ?? null) === 'select') {
                    $opts = array_map('strval', array_keys($on['options'] ?? []));
                    if (! in_array((string) $expect, $opts, true)) {
                        $errors[] = "$at [$k] `show_if` يشترط `$onKey = $expect` وهي ليست من خياراتِه — حقلٌ لا يظهر أبداً";
                    }
                } elseif (! empty($f['required'])) {
                    $errors[] = "$at [$k] إلزاميٌّ ومشروطٌ بحقلٍ ليس قائمةَ خيارات `$onKey` — لا سبيلَ لضمانِ ظهورِه";
                }
            }
        }

        // ── الدورات: شرطٌ يقود إلى نفسِه عبر وسيط ──
        foreach (array_keys($byKey) as $start) {
            $seen = [];
            $cur  = $start;
            while (true) {
                $cond = $byKey[$cur]['show_if'] ?? null;
                if (! is_array($cond) || $cond === []) break;
                $next = (string) array_key_first($cond);
                if (! isset($byKey[$next])) break;
                if (isset($seen[$next]) || $next === $start) {
                    $errors[] = "$at دورةٌ في `show_if` تبدأ من `$start`";
                    break;
                }
                $seen[$cur] = true;
                $cur = $next;
            }
        }

        return array_values(array_unique($errors));
    }

    /** يُلقي عند أوّلِ خطأ — للاستعمالِ في الإقلاعِ والاختبار */
    public static function validateOrFail(?array $catalog = null): void
    {
        $e = self::validate($catalog);
        if ($e !== []) {
            throw new \RuntimeException('كتالوجُ المزوّدين غيرُ سليم: ' . implode(' · ', $e));
        }
    }
}
