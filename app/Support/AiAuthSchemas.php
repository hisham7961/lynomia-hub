<?php

namespace App\Support;

/**
 * **عائلاتُ المصادقة** — أشكالُ الاعتمادِ المتكرّرة. (المرحلة ٢ · إغلاقُ التغطية)
 *
 * **ولا اسمَ مزوّدٍ في هذا الصنف ولا في ملفِّه.** يعرف أنّ للاعتمادِ أشكالاً
 * قليلةً متكرّرة، وأنّ لكلِّ شكلٍ حقولاً بوجهاتٍ وسرّيّةٍ وتحقّق. وربطُ المزوّدِ
 * بشكلِه **مولَّدٌ من قياسِ مصدرِ البوّابة**، لا مكتوبٌ هنا.
 *
 * **ولماذا وُجدت العائلاتُ أصلاً؟** لأنّ البوّابةَ تكشف مساراً لحقولِ المزوّدِ
 * (`GET /model/settings`) لكنّه يُعيد حقولاً لثلاثةِ مزوّدين من مئةٍ وخمسةٍ
 * وخمسين وقائمةً فارغةً لما عداهم. فالهويّةُ تُقرأ حيّاً، والشكلُ يُستنتَج من
 * عائلةٍ — وبينهما لا كتالوجَ يدويٌّ يتقادم.
 *
 * **وثلاثةُ ثوابتَ تحكم كلَّ عائلة:**
 *
 *  ① **اسمُ الحقلِ هو اسمُ `litellm_params`.** ما يُكتَب في الشاشةِ هو نفسُه
 *     ما يُرسَل في `credential_values` — فلا طبقةَ ترجمةٍ تُخطئ بين اثنين.
 *
 *  ② **السرُّ وجهتُه `credential` دائماً.** يفرضه `AiCatalog::validate()`
 *     على كلِّ عائلةٍ كما يفرضه على كلِّ مزوّدٍ مكتوبٍ بيدٍ — فلا بابَ خلفيّ.
 *
 *  ③ **الإلزامُ يُرفَع ولا يُخفَّض.** خياراتُ التوليد (`require_api_key`
 *     و`require_api_base`) تجعل حقلاً اختياريّاً **إلزاميّاً** حين يقول
 *     القياسُ ذلك، ولا تجعل إلزاميّاً اختياريّاً أبداً. فأسوأُ ما يفعله خطأٌ
 *     في القياسِ سؤالٌ زائدٌ — لا اعتمادٌ ناقصٌ يُحفَظ ويسقط عند أوّلِ طلب.
 */
final class AiAuthSchemas
{
    /** خياراتُ الرفعِ المسموحة — ما ليس فيها يُتجاهَل لا يُصدَّق */
    public const OPTIONS = ['require_api_key', 'require_api_base'];

    /** @return array<string,array> */
    public static function all(): array
    {
        $raw = config('ai_auth', []);

        return is_array($raw) ? $raw : [];
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $family): bool
    {
        return isset(self::all()[$family]);
    }

    public static function family(string $family): ?array
    {
        return self::all()[$family] ?? null;
    }

    /** أهي عائلةُ مصادقةٍ خاصّة؟ — تُغذّي حالةَ `SUPPORTED_WITH_SPECIAL_AUTH` */
    public static function isSpecial(string $family): bool
    {
        return (bool) (self::family($family)['special'] ?? false);
    }

    public static function label(string $family): string
    {
        return (string) (self::family($family)['label'] ?? $family);
    }

    /**
     * **حقولُ العائلةِ بعد تطبيقِ خياراتِ الرفع** — وهي ما تُعرَض ويُرسَل.
     *
     * @param  array<string,bool>  $options
     * @return list<array>
     */
    public static function fields(string $family, array $options = []): array
    {
        $def = self::family($family);
        if ($def === null) return [];

        $raise = [];
        foreach (self::OPTIONS as $opt) {
            if (! empty($options[$opt])) {
                // `require_api_key` ⇒ الحقلُ `api_key` — اشتقاقٌ من اسمِ الخيارِ
                // لا جدولُ ترجمةٍ يُنسى أحدُ صفوفِه.
                $raise[substr($opt, strlen('require_'))] = true;
            }
        }

        $out = [];
        foreach ($def['fields'] ?? [] as $f) {
            if (isset($raise[$f['key'] ?? ''])) $f['required'] = true;
            $out[] = $f;
        }

        return $out;
    }

    /** مفاتيحُ الحقولِ السرّيّةِ في العائلة — يقرؤها حارسُ الحجب */
    public static function secretKeys(string $family): array
    {
        return array_values(array_map(
            static fn (array $f) => (string) $f['key'],
            array_filter(self::fields($family), static fn (array $f) => ! empty($f['secret']))
        ));
    }

    /**
     * **يُصادِق العائلاتِ ويُعيد قائمةَ أخطاء** — فارغةٌ = سليمة.
     *
     * والمُصادِقُ **هو نفسُه مُصادِقُ الكتالوج**: عائلةٌ تُبنى منها مداخلُ
     * كتالوجٍ يجب أن تجتازَ ما يجتازه المدخلُ المكتوبُ بيد. ولا مُصادِقَ ثانٍ
     * يفترق عن الأوّلِ بعد شهرين.
     *
     * @return list<string>
     */
    public static function validate(): array
    {
        $errors = [];
        $all    = self::all();

        if ($all === []) return ['لا عائلةَ مصادقةٍ معرّفة'];

        foreach ($all as $name => $def) {
            $at = "[$name]";

            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_]*$/', (string) $name)) {
                $errors[] = "$at اسمُ العائلةِ يجب أن يكون حروفاً صغيرةً وأرقاماً وشرطاتٍ سفليّة";
            }
            if (! is_array($def)) { $errors[] = "$at التعريفُ ليس مصفوفة"; continue; }
            foreach (['label', 'fields'] as $req) {
                if (empty($def[$req])) $errors[] = "$at ينقصه `$req`";
            }
            if (! isset($def['special']) || ! is_bool($def['special'])) {
                $errors[] = "$at `special` يجب أن تكون قيمةً منطقيّة";
            }

            $fields = $def['fields'] ?? null;
            if (! is_array($fields) || $fields === []) { $errors[] = "$at لا حقولَ"; continue; }

            // نُصادِقها بمُصادِقِ الكتالوجِ نفسِه عبر مدخلٍ صوريٍّ كاملِ الشكل.
            $probe = ['family_' . $name => [
                'label'       => (string) ($def['label'] ?? $name),
                'litellm_key' => '__probe__',
                'auth'        => $name,
                'discovery'   => 'manual',
                'fields'      => $fields,
            ]];
            foreach (AiCatalog::validate($probe) as $e) {
                $errors[] = str_replace('[family_' . $name . ']', $at, $e);
            }

            // حقلٌ واحدٌ على الأقلّ يذهب إلى الاعتماد — وإلّا فالعائلةُ لا تُنشئ اعتماداً.
            $toCredential = array_filter($fields, static fn ($f) => ($f['sends_to'] ?? null) === 'credential');
            if ($toCredential === []) {
                $errors[] = "$at لا حقلَ يذهب إلى الاعتماد — عائلةٌ لا تُنشئ اعتماداً أصلاً";
            }
        }

        return array_values(array_unique($errors));
    }

    public static function validateOrFail(): void
    {
        $e = self::validate();
        if ($e !== []) {
            throw new \RuntimeException('عائلاتُ المصادقةِ غيرُ سليمة: ' . implode(' · ', $e));
        }
    }
}
