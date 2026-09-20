<?php

namespace App\Support;

/**
 * **مصفوفةُ التغطية** — ما يُعَدُّ مدعوماً، وما لا يُعَدّ، **ولماذا**. (المرحلة ٢ · إغلاق)
 *
 * **مولَّدةٌ من الواقعِ لا مكتوبةٌ بيد.** كلُّ صفٍّ يُشتَقُّ من خريطةٍ ولّدها
 * قياسُ مصدرِ البوّابةِ وصنّفتها قواعدُ `AiProviderRegistry`. فلا يُكتَب هنا
 * «مدعوم» إلّا بصفٍّ يسنده، ولا «غيرُ مدعوم» إلّا بسببٍ يُقرأ.
 *
 * **والفرقُ الذي تحرسه هذه المصفوفة:** بين «لم يُضَف بعد» و«استثناءٌ تقنيٌّ
 * موثّق». الأوّلُ دَينٌ صامت، والثاني قرارٌ معلنٌ له سبب. وكلُّ صفٍّ هنا من
 * النوعِ الثاني أو ليس استثناءً أصلاً.
 *
 * **وترتيبُ الحسمِ مقصود:** غيرُ القابلِ للإعداد يسبق المصادقةَ الخاصّة، وهي
 * تسبق النموذجَ اليدويّ. فمزوّدٌ بمصادقةٍ خاصّةٍ **ونماذجَ يدويّةٍ** يُعرَض
 * بالأخصِّ منهما — لأنّ الأخصَّ هو ما يحتاج القارئُ معرفتَه أوّلاً.
 */
final class AiProviderCoverage
{
    /** ترتيبُ العرضِ — من الأعمِّ إلى الاستثناء، ليقرأَ القارئُ الخيرَ أوّلاً */
    public const ORDER = [
        'SUPPORTED',
        'SUPPORTED_WITH_MANUAL_MODEL',
        'SUPPORTED_WITH_SPECIAL_AUTH',
        'NOT_CONFIGURABLE_FROM_HUB',
    ];

    public const LABELS = [
        'SUPPORTED'                   => 'مدعومٌ كاملاً',
        'SUPPORTED_WITH_MANUAL_MODEL' => 'مدعومٌ بنماذجَ تُضاف يدويّاً',
        'SUPPORTED_WITH_SPECIAL_AUTH' => 'مدعومٌ بمصادقةٍ خاصّة',
        'NOT_CONFIGURABLE_FROM_HUB'   => 'غيرُ قابلٍ للإعدادِ من Hub',
    ];

    /**
     * **سببُ الاستثناءِ مقروءاً** — ترجمةُ رمزِ القياسِ إلى جملةٍ تُقرأ.
     *
     * والرمزُ يبقى في البيانات؛ وهذه الترجمةُ للعرضِ وحدَه. فتغييرُ صياغةٍ
     * هنا لا يُحرّك بياناً، وتغييرُ قاعدةٍ هناك لا يُسكِت شاشة.
     */
    public const REASONS = [
        'no_chat_surface'                   => 'ليس مزوّدَ إكمالٍ نصيّ — لا سطحَ محادثةٍ له في البوّابة',
        'interactive_oauth_on_gateway_host' => 'مصادقتُه تدفّقُ جهازٍ تفاعليٌّ يكتب ملفَّ رمزٍ على مضيفِ البوّابة — ولا اعتمادَ ساكنٌ يُدخَل من شاشة',
    ];

    public const MODALITY_LABELS = [
        'chat'                => 'محادثة',
        'embedding'           => 'تضمين',
        'rerank'              => 'إعادةُ ترتيب',
        'vector_stores'       => 'مخزنُ متّجهات',
        'image_generation'    => 'توليدُ صور',
        'image_edit'          => 'تحريرُ صور',
        'image_variation'     => 'تنويعُ صور',
        'audio_transcription' => 'نسخُ صوت',
        'text_to_speech'      => 'تركيبُ كلام',
        'video'               => 'فيديو',
        'ocr'                 => 'قراءةٌ ضوئيّة',
        'files'               => 'ملفّات',
        'responses_api'       => 'واجهةُ ردود',
        'text_completion'     => 'إكمالُ نصّ',
        'batches'             => 'دُفعات',
        'passthrough'         => 'تمريرٌ مباشر',
    ];

    /**
     * **كلُّ المزوّدين بحالاتِهم** — من الخريطةِ المولَّدة، مرتّبةً حتميّاً.
     *
     * @return list<array{slug:string,hub_key:string,label:string,status:string,auth:?string,discovery:string,modalities:list<string>,reason:?string,configurable:bool}>
     */
    public static function matrix(): array
    {
        $rows = [];

        foreach (AiProviderRegistry::map() as $slug => $r) {
            if (! is_string($slug) || ! is_array($r)) continue;

            $status = (string) ($r['status'] ?? 'SUPPORTED');
            $rows[] = [
                'slug'         => $slug,
                'hub_key'      => AiProviderRegistry::hubKey($slug),
                'label'        => AiProviderRegistry::humanise($slug),
                'status'       => $status,
                'auth'         => isset($r['auth']) ? (string) $r['auth'] : null,
                'discovery'    => (string) ($r['discovery'] ?? AiProviderRegistry::DEFAULT_DISCOVERY),
                'modalities'   => array_values(array_filter((array) ($r['modalities'] ?? []), 'is_string')),
                'reason'       => isset($r['reason']) ? (string) $r['reason'] : null,
                'configurable' => $status !== 'NOT_CONFIGURABLE_FROM_HUB',
            ];
        }

        // ترتيبٌ حتميٌّ: بالحالةِ ثمّ بالاسم — فلا يتبدّل الجدولُ بين تشغيلين.
        usort($rows, static function (array $a, array $b): int {
            $ra = array_search($a['status'], self::ORDER, true);
            $rb = array_search($b['status'], self::ORDER, true);
            $ra = $ra === false ? 99 : $ra;
            $rb = $rb === false ? 99 : $rb;

            return $ra === $rb ? strcmp($a['slug'], $b['slug']) : $ra <=> $rb;
        });

        return $rows;
    }

    /** @return array<string,int> عددُ كلِّ حالةٍ — وكلُّ الحالاتِ حاضرةٌ ولو بصفر */
    public static function counts(): array
    {
        $out = array_fill_keys(self::ORDER, 0);
        foreach (self::matrix() as $r) {
            $out[$r['status']] = ($out[$r['status']] ?? 0) + 1;
        }

        return $out;
    }

    /** @return list<array> صفوفُ حالةٍ واحدة */
    public static function byStatus(string $status): array
    {
        return array_values(array_filter(self::matrix(), static fn (array $r) => $r['status'] === $status));
    }

    /** @return array<string,int> توزيعُ عائلاتِ المصادقةِ على القابلِ للإعداد */
    public static function byAuth(): array
    {
        $out = [];
        foreach (self::matrix() as $r) {
            if (! $r['configurable'] || $r['auth'] === null) continue;
            $out[$r['auth']] = ($out[$r['auth']] ?? 0) + 1;
        }
        arsort($out);

        return $out;
    }

    /** @return array<string,int> توزيعُ أوضاعِ الاكتشافِ على القابلِ للإعداد */
    public static function byDiscovery(): array
    {
        $out = array_fill_keys(AiCatalog::DISCOVERY_MODES, 0);
        foreach (self::matrix() as $r) {
            if (! $r['configurable']) continue;
            $out[$r['discovery']] = ($out[$r['discovery']] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * **الاستثناءاتُ بأسبابِها مجموعةً** — لا سطراً بلا سبب.
     *
     * @return array<string,list<string>>
     */
    public static function exceptions(): array
    {
        $out = [];
        foreach (self::byStatus('NOT_CONFIGURABLE_FROM_HUB') as $r) {
            $out[self::reasonText((string) $r['reason'])][] = $r['slug'];
        }
        ksort($out);

        return $out;
    }

    /** سببٌ مقروء — والوسائطُ تُترجَم واحداً واحداً بلا جدولٍ مفقود */
    public static function reasonText(?string $reason): string
    {
        if ($reason === null || $reason === '') return 'بلا سببٍ مسجَّل';
        if (isset(self::REASONS[$reason])) return self::REASONS[$reason];

        if (str_starts_with($reason, 'modality:')) {
            $kinds = array_filter(explode(',', substr($reason, strlen('modality:'))));
            $named = array_map(static fn (string $k) => self::MODALITY_LABELS[$k] ?? $k, $kinds);

            return 'وسيطُه ' . implode(' و', $named) . ' لا المحادثة';
        }

        return $reason;
    }

    /**
     * سقفٌ اختياريٌّ للتصفّح — **ولا يُطبَّق افتراضاً**.
     *
     * كان سقفاً افتراضيّاً بأربعةٍ وعشرين، وكان خطأً: الشاشةُ تقول «ظهر ٢٤ من
     * ١٢٦ — ضيِّق البحث» لمن لا يعرف ما يبحث عنه أصلاً. **وتصفّحُ ما هو متاحٌ
     * نصفُ الغرضِ من التغطيةِ الكاملة**؛ فالقطعُ يُخفي المعروضَ ولا يُنظّمه.
     * والتنظيمُ موضعُه العرضُ (شبكةٌ تُمسح بالعين) لا العدد.
     */
    public const BROWSE_LIMIT = 24;

    /** أقلُّ طولٍ لنصِّ البحث — حرفٌ واحدٌ يُعيد كلَّ شيءٍ فلا يُصفّي */
    public const MIN_QUERY = 2;

    /**
     * **تصفّحُ المزوّدين القابلين للإعداد** — بحثٌ وتصفيةٌ، **وبلا قطعٍ افتراضيّ**.
     *
     * الإغراقُ الذي يُخشى ليس عددَ البطاقاتِ بل عددَ **النماذج**: مئةٌ وستّةٌ
     * وعشرون نموذجَ إعدادٍ مفتوحةً معاً هي ما يمنع الاختيار. وقد فُصل الأمران:
     * البطاقاتُ تُعرَض كلُّها ليُمسَح المتاحُ بالعين، **والنموذجُ واحدٌ عند
     * الاختيار**. فالتصفيةُ أداةُ تضييقٍ لمن يعرف ما يريد، لا شرطاً لرؤيةِ ما هو متاح.
     *
     * @param  array{q?:string,status?:string,auth?:string,discovery?:string}  $filters
     * @param  int|null  $limit  سقفٌ اختياريّ؛ `null` = الكلّ
     * @return array{rows:list<array>,total:int,shown:int,truncated:bool}
     */
    public static function browse(array $filters = [], ?int $limit = null): array
    {
        $q         = trim((string) ($filters['q'] ?? ''));
        $status    = (string) ($filters['status'] ?? '');
        $auth      = (string) ($filters['auth'] ?? '');
        $discovery = (string) ($filters['discovery'] ?? '');

        $rows = [];
        foreach (AiCatalog::all() as $key => $entry) {
            $slug   = (string) ($entry['litellm_key'] ?? $key);
            $family = (string) ($entry['auth'] ?? '');
            $mode   = (string) ($entry['discovery'] ?? '');
            $state  = (string) ($entry['status'] ?? 'SUPPORTED');

            if ($status !== '' && $state !== $status) continue;
            if ($auth !== '' && $family !== $auth) continue;
            if ($discovery !== '' && $mode !== $discovery) continue;

            if (mb_strlen($q) >= self::MIN_QUERY) {
                $haystack = mb_strtolower(implode(' ', [
                    $key, $slug, (string) ($entry['label'] ?? ''), (string) ($entry['label_en'] ?? ''),
                ]));
                if (! str_contains($haystack, mb_strtolower($q))) continue;
            }

            $rows[] = [
                'key'        => $key,
                'slug'       => $slug,
                'label'      => (string) ($entry['label'] ?? $key),
                'label_en'   => (string) ($entry['label_en'] ?? $slug),
                'auth'       => $family,
                'auth_label' => AiAuthSchemas::label($family),
                'discovery'  => $mode,
                'status'     => $state,
                'curated'    => ($entry['source'] ?? null) !== 'measured' && ($entry['source'] ?? null) !== 'default',
                'fields'     => count($entry['fields'] ?? []),
                'mark'       => AiProviderRegistry::mark($slug),
            ];
        }

        // ترتيبٌ حتميّ: المكتوبُ بيدٍ أوّلاً (وصفُه أدقُّ)، ثمّ بالاسم.
        usort($rows, static fn (array $a, array $b) => $a['curated'] === $b['curated']
            ? strcmp($a['key'], $b['key'])
            : ($a['curated'] ? -1 : 1));

        $total = count($rows);

        if ($limit === null) {
            return ['rows' => $rows, 'total' => $total, 'shown' => $total, 'truncated' => false];
        }

        $limit = max(1, $limit);

        return [
            'rows'      => array_slice($rows, 0, $limit),
            'total'     => $total,
            'shown'     => min($total, $limit),
            'truncated' => $total > $limit,
        ];
    }

    /** خياراتُ التصفيةِ المتاحةُ فعلاً — لا خيارٌ يُعيد صفراً دائماً @return array<string,array<string,string>> */
    public static function facets(): array
    {
        $status = $auth = $discovery = [];

        foreach (AiCatalog::all() as $entry) {
            $s = (string) ($entry['status'] ?? 'SUPPORTED');
            $status[$s] = self::LABELS[$s] ?? $s;
            $a = (string) ($entry['auth'] ?? '');
            if ($a !== '') $auth[$a] = AiAuthSchemas::label($a);
            $d = (string) ($entry['discovery'] ?? '');
            if ($d !== '') $discovery[$d] = $d;
        }

        ksort($status); ksort($auth); ksort($discovery);

        return ['status' => $status, 'auth' => $auth, 'discovery' => $discovery];
    }

    /** @return array{litellm_version:?string,total:int,configurable:int,source:string} */
    public static function summary(): array
    {
        $rows = self::matrix();

        return [
            'litellm_version' => AiProviderRegistry::measuredVersion(),
            'total'           => count($rows),
            'configurable'    => count(array_filter($rows, static fn (array $r) => $r['configurable'])),
            'source'          => AiProviderRegistry::slugs()['source'],
        ];
    }
}
