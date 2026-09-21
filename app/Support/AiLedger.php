<?php

namespace App\Support;

use App\Models\AiUsageEvent;

/**
 * **سجلُّ الاستهلاك — صفٌّ لكلِّ محاولة** (المرحلة ٤ · P4-W2).
 *
 * ── **لماذا المحاولةُ لا الطلب؟** ──
 *
 * الخطّةُ تشترط حرفاً: «‏Model A → failure → Model B → success يجب أن نرى
 * المحاولتين وعلاقتهما بنفسِ الطلبِ المنطقيّ». وصفٌّ واحدٌ للطلبِ **يُخفي
 * محاولةً أُنفقت**، فتبدو الرحلةُ نجاحاً واحداً بكلفةِ الناجحِ وحدَه — وهو
 * كذبٌ على الفاتورةِ وعلى التشخيصِ معاً:
 *
 *  · **على الفاتورة** لأنّ الفاشلةَ أُنفقت رموزَ مدخلٍ كاملةً عند المزوّد.
 *  · **وعلى التشخيص** لأنّ «هذا النموذجُ يفشل دائماً» لا تُرى إن لم تُسجَّل.
 *
 * فـ`request_id` يجمع المحاولاتِ في طلبٍ منطقيٍّ واحد، و`parent_id` يقول
 * «هذه قفزةٌ عن تلك»، و`relation` يفرّق **الإعادةَ** من **الاحتياط**.
 *
 * ── **وما لا يُكتَب هنا** ──
 *
 * **لا نصَّ سؤالٍ ولا جوابٍ ولا حرفاً منهما.** القرارُ من المرحلةِ الثالثة
 * قائم: السجلُّ يُقرَأ بصلاحيّةِ الرقابةِ لا بصلاحيّةِ السائل، فحفظُ «كم راتبُ
 * فلان؟» فيه يفتح التسريبَ الذي أُغلق بالمسارِ كلِّه. ولا سرَّ ولا ترويسةَ ولا
 * متنَ ردّ — والتصنيفُ وحدَه يُحفَظ.
 *
 * ── **ولا يكتب العميلُ صفّاً هنا أبداً** ──
 *
 * لا مسارَ HTTP يستقبل استهلاكاً، ولا حقلَ في طلبٍ يُترجَم إلى كلفة. كلُّ
 * صفٍّ يُكتَب من **داخلِ** مسارِ التوليدِ بأرقامٍ قرأها الخادمُ من ردِّ
 * البوّابة. فاختلاقُ استهلاكٍ أو تكرارُه لا سطحَ له أصلاً.
 */
final class AiLedger
{
    public const STATUSES  = ['reserved', 'ok', 'failed', 'released', 'expired'];
    public const RELATIONS = ['initial', 'retry', 'fallback'];

    /**
     * **يفتح صفَّ محاولةٍ قبل النداء** — بحالةِ `reserved`.
     *
     * **والفتحُ قبل النداءِ لا بعدَه** عن قصد: عمليّةٌ تموت في منتصفِ رحلةِ
     * الشبكةِ تترك صفّاً يقول «بدأتُ ولم أُغلَق» — وهو دليلٌ يُقرَأ. أمّا
     * الكتابةُ بعد العودةِ وحدَها فتجعل **كلَّ نداءٍ مات صامتاً كأنّه لم يقع**،
     * وقد أُنفق ماله.
     */
    public static function open(array $ctx): AiUsageEvent
    {
        return AiUsageEvent::create([
            'request_id'     => (string) ($ctx['request_id'] ?? \Illuminate\Support\Str::uuid()),
            'correlation'    => self::clip($ctx['correlation'] ?? null, 64),
            'parent_id'      => $ctx['parent_id'] ?? null,
            'attempt'        => max(1, (int) ($ctx['attempt'] ?? 1)),
            'relation'       => in_array(($ctx['relation'] ?? ''), self::RELATIONS, true)
                ? (string) $ctx['relation'] : 'initial',
            'company_id'     => $ctx['company_id'] ?? null,
            'user_id'        => $ctx['user_id'] ?? null,
            'purpose'        => self::clip($ctx['purpose'] ?? null, 80),
            'feature'        => self::clip($ctx['feature'] ?? null, 60),
            'provider_id'    => $ctx['provider_id'] ?? null,
            'model_id'       => $ctx['model_id'] ?? null,
            'model_name'     => self::clip($ctx['model_name'] ?? null, 191),
            'status'         => 'reserved',
            'cost_micro'     => null,
            'cost_source'    => AiCost::UNKNOWN,
            'currency'       => (string) ($ctx['currency'] ?? AiCost::CURRENCY),
            'reserved_micro' => max(0, (int) ($ctx['reserved_micro'] ?? 0)),
            'budget_id'      => $ctx['budget_id'] ?? null,
            'period_key'     => self::clip($ctx['period_key'] ?? null, 24),
            'started_at'     => now(),
        ]);
    }

    /**
     * **يُغلق صفّاً بنجاح** — بكلفةٍ موسومةٍ بمصدرِها.
     *
     * **ولا يُحوَّل مجهولٌ إلى صفر:** `cost_micro` تبقى `null` و`cost_source`
     * تقول `unknown`، فتقرأ الشاشةُ «—» لا «٠٫٠٠».
     */
    public static function succeed(AiUsageEvent $e, array $settled, int $ms,
                                   ?int $httpStatus = 200, array $diag = []): AiUsageEvent
    {
        $t = (array) ($settled['tokens'] ?? []);
        self::diagnose($e, $diag);

        $e->forceFill([
            'status'        => 'ok',
            'failure'       => null,
            'cause'         => null,
            'http_status'   => $httpStatus,
            'input_tokens'  => $t['in']     ?? null,
            'output_tokens' => $t['out']    ?? null,
            'total_tokens'  => $t['total']  ?? self::sum($t['in'] ?? null, $t['out'] ?? null),
            'cached_tokens' => $t['cached'] ?? null,
            'cost_micro'    => $settled['micro'] ?? null,
            'cost_source'   => (string) ($settled['source'] ?? AiCost::UNKNOWN),
            'latency_ms'    => max(0, $ms),
            'settled_at'    => now(),
        ])->save();

        return $e;
    }

    /**
     * **يُغلق صفّاً بإخفاق** — **والمحاولةُ تبقى مُسجَّلةً لأنّها وقعت**.
     *
     * وطلبٌ فاشلٌ **لا يُحسَب كلفةَ نجاح**: `cost_source` يبقى `unknown` ما لم
     * يُبلِّغ المزوّدُ رقماً، فلا يُعَدّ إنفاقاً مؤكَّداً بينما هو ليس كذلك.
     * وبعضُ الإخفاقاتِ تُنفق فعلاً (ردٌّ بدأ ثمّ قُطع) وبعضُها لا (رفضُ
     * اعتماد) — **والسجلُّ يقول ما يعرف ولا يُخمّن**.
     */
    public static function fail(AiUsageEvent $e, ?string $failure, ?string $cause,
                                ?int $httpStatus, int $ms, array $settled = [],
                                array $diag = []): AiUsageEvent
    {
        $t = (array) ($settled['tokens'] ?? []);
        self::diagnose($e, $diag);

        $e->forceFill([
            'status'        => 'failed',
            'failure'       => self::clip($failure, 40),
            'cause'         => self::clip($cause, 32),
            'http_status'   => $httpStatus,
            'input_tokens'  => $t['in']    ?? null,
            'output_tokens' => $t['out']   ?? null,
            'total_tokens'  => $t['total'] ?? null,
            'cost_micro'    => $settled['micro'] ?? null,
            'cost_source'   => (string) ($settled['source'] ?? AiCost::UNKNOWN),
            'latency_ms'    => max(0, $ms),
            'settled_at'    => now(),
        ])->save();

        return $e;
    }

    /** **يُلغي صفّاً لم يقع نداؤه** — حُجز ثمّ تُرك، فلا كلفةَ ولا محاولة */
    public static function release(AiUsageEvent $e, ?string $why = null): AiUsageEvent
    {
        $e->forceFill([
            'status'     => 'released',
            'failure'    => self::clip($why, 40),
            'settled_at' => now(),
        ])->save();

        return $e;
    }

    /**
     * **تليمتري الدورةِ — تصنيفٌ وأرقامٌ لا محتوى** (قبولُ الإنتاج `92dbd557`).
     *
     * وثلاثُ محاولاتٍ مدفوعةٍ مضت خُمِّن سببُها بدل أن يُقرأ، لأنّ ما يلزم
     * التشخيصَ لم يكن يُسجَّل: سببُ الانتهاء، ورموزُ التفكير، والسقفُ
     * المُرسَل، واسمُ الأداةِ المطلوبة.
     *
     * **ولا وسائطَ أداةٍ ولا نصَّ**: الاسمُ من مفرداتٍ مغلقةٍ خمسٍ لا غير.
     */
    private static function diagnose(AiUsageEvent $e, array $diag): void
    {
        if ($diag === []) return;

        $tool = (string) ($diag['tool'] ?? '');

        $e->forceFill(array_filter([
            'finish_reason'     => self::clip($diag['finish'] ?? null, 40),
            'reasoning_tokens'  => isset($diag['reasoning']) ? max(0, (int) $diag['reasoning']) : null,
            'max_output_tokens' => isset($diag['max_output']) ? max(0, (int) $diag['max_output']) : null,
            'tool_requested'    => in_array($tool, \App\Support\AskTools::TOOLS, true) ? $tool : null,
        ], static fn ($v) => $v !== null));
    }

    /**
     * **المِقصُّ الدوريّ** — حجزٌ معلّقٌ فوق عمرِه يُفرَج عنه ويُوسَم `expired`.
     *
     * **ولولاه لَخُنقت ميزانيّةٌ سليمةٌ بمالٍ لم يُنفَق قطّ**: مسارٌ مات بين
     * الحجزِ والالتزامِ يترك الحجزَ قائماً إلى الأبد.
     *
     * @return int عددُ ما أُفرج عنه
     */
    public static function expireStale(?int $minutes = null): int
    {
        /*
         * **والمفتاحُ يُكتَب حرفيّاً هنا** — لا يُمرَّر متغيّراً.
         *
         * `SettingsCenterTest` يمسح `app/` على النمطِ `setting('<مفتاح>'`
         * ليُثبِت أنّ كلَّ مدخلٍ في شاشةِ الإعداداتِ **له قارئٌ حقيقيّ**، و
         * `SettingsModelTest` يقارن الافتراضيَّ المُعلَنَ بالوسيطِ الثاني
         * الحرفيّ. ومفتاحٌ يصل متغيّراً لا يراه المسحُ — **فتَعِد الشاشةُ بأثرٍ
         * لا دليلَ عليه**.
         *
         * **والأرضيّةُ الصلبةُ دقيقةٌ واحدة**: صفرٌ في الشاشةِ كان سيُفرج عن
         * حجزِ كلِّ طلبٍ لحظةَ وضعِه — فيُبطل السقفَ كلَّه بإدخالٍ واحد.
         */
        $ttl  = $minutes ?? max(1, (int) setting('ai.reservation_ttl_min', 15));
        $cut  = now()->subMinutes($ttl);
        $rows = AiUsageEvent::query()
            ->where('status', 'reserved')
            ->where('started_at', '<', $cut)
            ->orderBy('started_at')->orderBy('id')
            ->limit(500)->get();

        foreach ($rows as $e) {
            if ($e->budget_id !== null && $e->period_key !== null) {
                AiBudgets::releaseAll([[
                    'budget_id'  => (string) $e->budget_id,
                    'period_key' => (string) $e->period_key,
                    'micro'      => (int) $e->reserved_micro,
                ]]);
            }

            $e->forceFill(['status' => 'expired', 'settled_at' => now()])->save();
        }

        return $rows->count();
    }

    /**
     * **مجموعُ طلبٍ منطقيٍّ واحد** — بكلِّ محاولاتِه وعلاقتِها.
     *
     * **والمصدرُ الأضعفُ يحكم المجموع**: مجموعٌ فيه رقمٌ مُبلَّغٌ وآخرُ مجهولٌ
     * ليس «مُبلَّغاً»، **وليس صفراً عن المجهول** — بل مجموعُ ما عُرف مع عدِّ
     * ما لم يُعرَف.
     *
     * @return array{attempts:int, ok:int, failed:int, cost_micro:?int, cost_source:string,
     *               unknown:int, tokens:int}
     */
    public static function summarize(string $requestId): array
    {
        $rows = AiUsageEvent::query()
            ->where('request_id', $requestId)
            ->orderBy('started_at')->orderBy('id')->get();

        $cost = null; $unknown = 0; $tokens = 0; $ok = 0; $failed = 0;
        $sources = [];

        foreach ($rows as $r) {
            if ((string) $r->status === 'ok')     $ok++;
            if ((string) $r->status === 'failed') $failed++;
            $tokens += (int) ($r->total_tokens ?? 0);

            if ($r->cost_micro === null) {
                // **المجهولُ يُعَدّ ولا يُجمَع** — فلا يصير صفراً في الطريق
                if (in_array((string) $r->status, ['ok', 'failed'], true)) $unknown++;
                continue;
            }

            $cost = (int) ($cost ?? 0) + (int) $r->cost_micro;
            $sources[] = (string) $r->cost_source;
        }

        return [
            'attempts'    => $rows->count(),
            'ok'          => $ok,
            'failed'      => $failed,
            'cost_micro'  => $cost,
            'cost_source' => $unknown > 0 || $sources === []
                ? AiCost::UNKNOWN
                : AiCost::weakest(...$sources),
            'unknown'     => $unknown,
            'tokens'      => $tokens,
        ];
    }

    private static function sum(?int $a, ?int $b): ?int
    {
        return ($a === null && $b === null) ? null : (int) $a + (int) $b;
    }

    private static function clip(mixed $v, int $len): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : mb_substr($s, 0, $len);
    }
}
