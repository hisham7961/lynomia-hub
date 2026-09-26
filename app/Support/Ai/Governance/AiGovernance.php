<?php

namespace App\Support\Ai\Governance;

use App\Models\AiModel;
use App\Models\AiUsageEvent;
use Illuminate\Support\Str;
use App\Support\Ai\Ask\AskFailures;
use App\Support\Platform\Redactor;

/**
 * **بابُ الحوكمةِ الواحد** (المرحلة ٤ · P4-W5) — حيث تلتقي الطبقاتُ الثلاث.
 *
 * ```
 * تخويلُ Hub → سياسةُ الذكاء → الميزانيّة → الغرض → المرشَّحون
 *   → صحّةُ المزوّد/النموذج → التوجيه → التنفيذ → السجلّ → الأثر
 * ```
 *
 * ── **ولماذا بابٌ واحدٌ لا ثلاثةُ نداءاتٍ في كلِّ مُستدعٍ؟** ──
 *
 * لأنّ **الترتيبَ نفسَه ضمانٌ**، وثلاثةُ نداءاتٍ متفرّقةٍ تُرتَّب في كلِّ
 * موضعٍ من جديد — فيُقدَّم الحجزُ على السياسةِ في موضعٍ واحدٍ سهواً،
 * **فيُحجَز مالٌ لعمليّةٍ ممنوعةٍ ثمّ يُنسى الإفراجُ عنه**. وهنا الترتيبُ
 * مكتوبٌ مرّةً ويُختبَر مرّةً.
 *
 * ── **وإعادةُ التقييمِ عند كلِّ قفزةٍ هي لبُّ هذه الطبقة** ──
 *
 * > **السماحُ بالنموذجِ «أ» ليس سماحاً بالنموذجِ «ب».**
 *
 * فلو قُرئت السياسةُ والميزانيّةُ مرّةً عند فتحِ الرحلةِ وحدَها، لَصار
 * **الاحتياطُ بابَ التفافٍ عليهما**: تُمنَع سلسلةٌ غاليةٌ في السياسة، ثمّ
 * يُبلَغُها بإسقاطِ الأرخصِ منها — قصداً أو صدفة. ولذلك `gateFor()` تُحقَن في
 * `AiRouteRun` فتُقرَأ السياسةُ **لكلِّ مرشَّحٍ قبل القفزِ إليه**.
 *
 * ── **وثابتُ الاستئجارِ مفروضٌ هنا لا موعود** ──
 *
 * لا صفَّ استهلاكٍ يُنسَب إلى شركةٍ خارج شركاتِ صاحبِ الطلب. ومعرّفُ شركةٍ
 * يصل من خارجٍ **لا يُصدَّق**: يُشتَقُّ من المستخدمِ نفسِه، وإن خالف قائمتَه
 * سقط الطلب. فاختلاقُ شركةٍ لإنفاقِ ميزانيّتِها لا سطحَ له.
 */
final class AiGovernance
{
    /** شكلُ نتيجةِ القبول — لا شكلَ يُخترَع في مُستدعٍ */
    public const SHAPE = ['ok', 'code', 'why', 'holds', 'event', 'limits', 'tools'];

    /**
     * **سياقُ طلبٍ منطقيٍّ واحد** — ويُشتَقُّ من المستخدمِ لا يُستقبَل.
     *
     * @return array{request_id:string, correlation:?string, user:mixed, user_id:?string,
     *               role_id:?string, company_id:?string, purpose:?string, feature:?string,
     *               hub_allowed:bool}
     */
    public static function context(mixed $user, ?string $purpose = null,
                                   ?string $feature = null, ?string $correlation = null): array
    {
        $u = $user ?? auth()->user();

        return [
            'request_id'  => (string) Str::uuid(),
            'correlation' => $correlation,
            'user'        => $u,
            'user_id'     => $u === null ? null : (string) ($u->id ?? ''),
            'role_id'     => $u === null ? null : (string) ($u->role_id ?? ''),
            'company_id'  => self::companyOf($u),
            'purpose'     => $purpose,
            'feature'     => $feature,
            'hub_allowed' => false,   // **يُرفَع بالتخويلِ لا بالبناء**
        ];
    }

    /**
     * **شركةُ صاحبِ الطلبِ — مشتقّةٌ لا مُستقبَلة.**
     *
     * `hub_company_ids()` تعود `null` لمن لا عزلَ عليه (المالكُ مثلاً)، وقائمةً
     * لمن عليه عزل. **وواحدةٌ بعينِها تُنسَب فقط حين لا لبس**: من له شركتان
     * لا تُنسَب عمليّتُه إلى إحداهما بالقرعة — تُترَك بلا شركةٍ وتُحسَب على
     * الميزانيّةِ العامّة، **وهذا أصدقُ من نسبةٍ مخترَعة**.
     */
    public static function companyOf(mixed $user): ?string
    {
        if ($user === null) return null;

        $ids = hub_company_ids($user);
        if (is_array($ids) && count($ids) === 1) return (string) $ids[0];

        $own = trim((string) ($user->company_id ?? ''));

        // **وشركةُ العمودِ تُقبَل فقط إن كانت ضمنَ المسموحِ له** — أو بلا عزلٍ أصلاً
        if ($own !== '' && ($ids === null || in_array($own, array_map('strval', $ids), true))) {
            return $own;
        }

        return null;
    }

    /**
     * **هل يجوز هذا النموذجُ الآن؟** — سياسةٌ وصحّةٌ بلا حجزِ مال.
     *
     * تُستعمَل لتصفيةِ المرشَّحين **قبل** أن يُنفَق عليهم شيء، وتُحقَن في
     * `AiRouteRun` فتحكم كلَّ قفزةِ احتياط.
     *
     * @return array{ok:bool, code:?string, why:?string}
     */
    public static function admitModel(array $ctx, AiModel $model, bool $requireEnabled = true): array
    {
        /*
         * ① **النموذجُ المُعطَّلُ أو المُزالُ لا يُنادى** — منعٌ بالأصلِ لا سياسة.
         *
         * **و`$requireEnabled = false` للفاحصِ وحدَه، وهو ليس ثغرة:** وظيفةُ
         * الفاحصِ أن يُجرِّب نموذجاً **قبل** أن يُوثَق به ويُفعَّل — فاشتراطُ
         * التفعيلِ يجعله عديمَ الفائدةِ في غرضِه الأوّل، ويدفع المالكَ إلى
         * تفعيلِ نموذجٍ مجهولٍ لِيفحصَه. والسياسةُ والميزانيّةُ تبقيان فوقَه
         * كاملتين، والمُزالُ من المنبعِ يبقى ممنوعاً في الحالين.
         */
        if (! $model->exists || $model->trashed() || ($requireEnabled && ! $model->enabled)) {
            return ['ok' => false, 'code' => AskFailures::MODEL_UNAVAILABLE,
                    'why' => 'النموذجُ غيرُ مُفعَّلٍ الآن'];
        }
        if (in_array((string) $model->health, ['UNAVAILABLE'], true)) {
            return ['ok' => false, 'code' => AskFailures::MODEL_UNAVAILABLE,
                    'why' => 'النموذجُ مُزالٌ من المنبع'];
        }

        // ② السياسةُ — بأبعادِ هذا النموذجِ بعينِه
        $verdict = AiPolicy::evaluate($ctx + [
            'provider_id' => (string) $model->provider_id,
            'model_id'    => (string) $model->id,
        ]);

        return $verdict['allowed']
            ? ['ok' => true, 'code' => null, 'why' => null]
            : ['ok' => false, 'code' => (string) $verdict['code'], 'why' => (string) $verdict['why']];
    }

    /** **حاكمٌ يُحقَن في الرحلة** — فتُعاد السياسةُ عند كلِّ قفزةِ احتياط */
    public static function gateFor(array $ctx): \Closure
    {
        return static fn (AiModel $m): bool => (bool) self::admitModel($ctx, $m)['ok'];
    }

    /**
     * **القبولُ الكاملُ لمحاولةٍ واحدة** — بالترتيبِ الذي لا يُعاد ترتيبُه.
     *
     * @param  ?int  $estMicro  تقديرُ الكلفةِ بالميكرو، أو `null` إن لا تُقدَّر
     * @return array{ok:bool, code:?string, why:?string, holds:list<array>,
     *               event:?AiUsageEvent, limits:array, tools:bool}
     */
    public static function admit(array $ctx, AiModel $model, ?int $estMicro,
                                 int $estTokens = 0, array $attempt = [],
                                 bool $requireEnabled = true): array
    {
        // ① تخويلُ Hub — والسياسةُ لا تُقرَأ قبلَه
        $verdict = AiPolicy::evaluate($ctx + [
            'provider_id' => (string) $model->provider_id,
            'model_id'    => (string) $model->id,
        ]);

        if (! $verdict['allowed']) {
            self::auditDenied($ctx, $model, (string) $verdict['code'], (string) $verdict['why']);

            return self::no((string) $verdict['code'], (string) $verdict['why']);
        }

        // ② صحّةُ النموذجِ — **بعد السياسةِ عمداً**: سياسةٌ تمنع تمنع ولو كان سليماً
        $health = self::admitModel($ctx, $model, $requireEnabled);
        if (! $health['ok']) {
            return self::no((string) $health['code'], (string) $health['why']);
        }

        // ③ الميزانيّةُ — **آخرَ ما يُفحَص لأنّها وحدَها تكتب**
        $reserve = AiBudgets::reserve($ctx, $estMicro, $estTokens);
        if (! $reserve['ok']) {
            self::auditDenied($ctx, $model, (string) $reserve['code'], (string) $reserve['why']);

            return self::no((string) $reserve['code'], (string) $reserve['why']);
        }

        $holds = (array) $reserve['holds'];
        $first = $holds[0] ?? null;

        $event = AiLedger::open([
            'request_id'     => (string) $ctx['request_id'],
            'correlation'    => $ctx['correlation'] ?? null,
            'parent_id'      => $attempt['parent_id'] ?? null,
            'attempt'        => (int) ($attempt['attempt'] ?? 1),
            'relation'       => (string) ($attempt['relation'] ?? 'initial'),
            'company_id'     => $ctx['company_id'] ?? null,
            'user_id'        => $ctx['user_id'] ?? null,
            'purpose'        => $ctx['purpose'] ?? null,
            'feature'        => $ctx['feature'] ?? null,
            'provider_id'    => (string) $model->provider_id,
            'model_id'       => (string) $model->id,
            'model_name'     => (string) $model->litellm_model_name,
            'reserved_micro' => (int) ($first['micro'] ?? 0),
            'budget_id'      => $first['budget_id'] ?? null,
            'period_key'     => $first['period_key'] ?? null,
        ]);

        return [
            'ok' => true, 'code' => null, 'why' => null,
            'holds' => $holds, 'event' => $event,
            'limits' => (array) $verdict['limits'], 'tools' => (bool) $verdict['tools'],
        ];
    }

    /** **إغلاقُ محاولةٍ ناجحة** — كلفةٌ موسومةٌ بمصدرِها في السجلِّ والعدّاد معاً */
    public static function settleOk(AiUsageEvent $e, array $holds, array $usage,
                                    array $pricing, int $ms, ?int $status = 200,
                                    array $diag = []): array
    {
        $settled = AiCost::settle($usage, $pricing);
        AiLedger::succeed($e, $settled, $ms, $status, $diag);

        $tokens = (int) (($settled['tokens']['total'] ?? null)
            ?? ((int) ($settled['tokens']['in'] ?? 0) + (int) ($settled['tokens']['out'] ?? 0)));

        AiBudgets::commit($holds, $settled['micro'], $tokens);

        return $settled;
    }

    /**
     * **إغلاقُ محاولةٍ فاشلة** — **والالتزامُ يقع لأنّ المحاولةَ وقعت**.
     *
     * فالنداءُ بلغ المزوّدَ وأنفق رموزَ مدخلِه ولو لم يعد بجواب. وإفراجٌ هنا
     * بدل التزامٍ كان سيجعل **الفشلَ مجّانيّاً في دفاترِنا** وهو ليس كذلك عند
     * المزوّد. وما أبلغه المزوّدُ من كلفةٍ يُسجَّل، وما لم يُبلِغه يبقى مجهولاً.
     */
    public static function settleFailed(AiUsageEvent $e, array $holds, ?string $failure,
                                        ?string $cause, ?int $status, int $ms,
                                        array $usage = [], array $pricing = [],
                                        array $diag = []): array
    {
        $settled = $usage === [] ? ['micro' => null, 'source' => AiCost::UNKNOWN, 'tokens' => []]
            : AiCost::settle($usage, $pricing);

        AiLedger::fail($e, $failure, $cause, $status, $ms, $settled, $diag);
        AiBudgets::commit($holds, $settled['micro'],
            (int) ($settled['tokens']['total'] ?? 0));

        return $settled;
    }

    /** **إفراجٌ عن محاولةٍ لم تقع** — لا كلفةَ ولا عدَّ طلب */
    public static function abandon(AiUsageEvent $e, array $holds, ?string $why = null): void
    {
        AiLedger::release($e, $why);
        AiBudgets::releaseAll($holds);
    }

    // ── الأثر ─────────────────────────────────────────────────────────

    /**
     * **كلُّ منعِ حوكمةٍ يُسجَّل** — فمنعٌ لا أثرَ له شكوى لا دليل.
     *
     * **ولا سؤالَ ولا جوابَ ولا سرَّ في الأثر** — تصنيفٌ وسببٌ ومعرّفاتٌ فقط.
     */
    private static function auditDenied(array $ctx, AiModel $model, string $code, string $why): void
    {
        hub_audit('منعُ حوكمةِ ذكاءٍ', AiUsageEvent::MODULE,
            (string) ($ctx['request_id'] ?? ''), (string) $model->display_name,
            ['after' => Redactor::arr([
                'code'    => $code,
                'why'     => mb_substr($why, 0, 300),
                'purpose' => (string) ($ctx['purpose'] ?? ''),
                'feature' => (string) ($ctx['feature'] ?? ''),
                'model'   => (string) $model->litellm_model_name,
            ])]);
    }

    private static function no(string $code, string $why): array
    {
        return ['ok' => false, 'code' => $code, 'why' => $why,
                'holds' => [], 'event' => null,
                'limits' => ['max_output_tokens' => null, 'max_calls_per_request' => null],
                'tools' => true];
    }
}
