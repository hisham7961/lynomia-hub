<?php

namespace App\Support\Ai\Routing;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Support\Redactor;

/**
 * **رحلةُ طلبٍ واحدةٍ عبر سلسلةِ غرض** (المرحلة ٢ · W7 · §٨).
 *
 * ```
 * ميزةُ Hub → ملفُّ سياسة → أساسيّ → احتياطيّ ١ → احتياطيّ ٢ → ✋
 * ```
 *
 * `AiRouting` يقول **ما القرار**، وهذا الصنفُ **ينفّذه على سلسلةٍ حقيقيّة**:
 * يمسك النموذجَ الحاليَّ وعددَ المحاولاتِ المبذولةِ والكلفةَ المقدَّرةَ حتّى
 * الآن، ويطبّق الحرّاسَ الأربعةَ عند كلِّ إخفاق.
 *
 * ── **ولا نداءَ شبكةٍ هنا ولا توليدَ ولا كلفة** ──
 *
 * الصنفُ **محرّكُ قرارٍ خالص**: يُغذّى بإشارةِ إخفاقٍ (`['code' => 429]`)
 * فيُجيب بما يُفعَل. ومن يُجري الطلبَ الحقيقيَّ يأتي في مرحلةٍ لاحقة. وهذا
 * يجعل **محاكاةَ كلِّ صفٍّ في §٨ اختباراً حقيقيّاً لا تمثيلاً**.
 *
 * ── **الحرّاسُ الأربعةُ في مواضعِهم** ──
 *
 *  ① **العمق** — `AiRouting::MAX_DEPTH` قفزتان، ثلاثةُ نماذجَ لا أكثر.
 *  ② **ميزانيّةُ الطلب** — قفزةٌ تتجاوز السقفَ **لا تُقفَز**، والرحلةُ تُغلَق.
 *  ③ **تهدئةُ المزوّد** — مزوّدٌ مُستبعَدٌ يُتخطّى فوراً بلا مهلةٍ تُهدَر.
 *  ④ **كلُّ قفزةٍ تُسجَّل** — بالنموذجِ والسببِ والكلفةِ المقدَّرة.
 *
 * **والحارسُ ④ يُسجّل عند كلِّ إخفاقٍ لا عند الإغلاق.** إغلاقٌ متأخّرٌ يضيع
 * إن مات الطابورُ في منتصفِ الرحلة، **والكلفةُ تكون قد أُنفقت بالفعل**. وليس
 * في ذلك ثقلٌ: الإخفاقُ حدثٌ نادرٌ سبقته رحلةُ شبكةٍ كاملة، فسطرُ تدقيقٍ
 * بجانبِه لا يُحسّ. **والنجاحُ لا يُسجَّل شيئاً.**
 */
final class AiRouteRun
{
    public const ACTIONS = ['retry', 'fallback', 'stop', 'done'];

    /** @var list<AiModel> */
    private array $chain;

    private int $index = 0;

    /** المحاولاتُ المُعادةُ على النموذجِ الحاليِّ — ولا تُحسَب الأولى منها */
    private int $attempt = 0;

    private float $spent = 0.0;

    private array $trail = [];

    private bool $closed = false;

    private ?string $closedWhy = null;

    /** كم مرشَّحاً تخطّاه المسحُ الأخيرُ لتهدئةِ مزوّدِه — ليُقال السببُ لا «لا شيء» */
    private int $skippedCooling = 0;

    /** حاكمُ السياسةِ المحقون — `null` يعني «بلا حوكمةٍ مُركَّبة» */
    private ?\Closure $gate = null;

    private function __construct(
        array $chain,
        private readonly ?AiProfile $profile,
        private readonly float $ceiling,
        private readonly int $inTokens,
        private readonly int $outTokens,
    ) {
        $this->chain = array_values($chain);
    }

    /**
     * **تبدأ الرحلةُ من أوّلِ نموذجٍ صالحٍ لا من أوّلِ نموذجٍ مكتوب.**
     *
     * ── **و«صالحٌ» تشمل الملاءمةَ للغرض** (المرحلة ٥ · W2) ──
     *
     * `AskPolicy::profile()` يفحص الملاءمةَ قبل أن يُسلّم الغرضَ، لكنّ فحصاً
     * عند البابِ وحدَه **ليس حارساً**: هذا الصنفُ يُبقي السلسلةَ كلَّها في
     * يدِه ويقفز فيها عند كلِّ إخفاق، فنموذجٌ غيرُ مُلائمٍ في الموضعِ الثاني
     * يُوصَل إليه بالاحتياطِ بعد أن مرّ الأوّلُ بالفحص. **فالسلسلةُ نفسُها
     * تُبنى مُصفّاةً** — ومَن لم يُمرّر `feature` يحصل على السلوكِ القديمِ حرفاً.
     *
     * @param  array{ceiling?: float, in_tokens?: int, out_tokens?: int, feature?: string}  $opts
     */
    public static function for(AiProfile $profile, array $opts = []): self
    {
        $run = new self(
            AiProfiles::chain($profile, (string) ($opts['feature'] ?? ''))->all(),
            $profile,
            (float) ($opts['ceiling'] ?? 0.0),
            (int) ($opts['in_tokens'] ?? 0),
            (int) ($opts['out_tokens'] ?? 0),
        );

        $run->open();

        return $run;
    }

    /** رحلةٌ على سلسلةٍ مُعطاةٍ — للاختبارِ ولمن يبني سلسلتَه بيدِه */
    public static function over(array $chain, array $opts = []): self
    {
        $run = new self($chain, null, (float) ($opts['ceiling'] ?? 0.0),
            (int) ($opts['in_tokens'] ?? 0), (int) ($opts['out_tokens'] ?? 0));

        $run->open();

        return $run;
    }

    // ── الحالة ─────────────────────────────────────────────────────────

    public function model(): ?AiModel
    {
        return $this->closed ? null : ($this->chain[$this->index] ?? null);
    }

    /** عمقُ الاحتياطِ المبذول — ٠ يعني أنّنا ما زلنا على الأساسيّ */
    public function depth(): int
    {
        return $this->index;
    }

    public function closed(): bool
    {
        return $this->closed;
    }

    public function why(): ?string
    {
        return $this->closedWhy;
    }

    /** **كلفةٌ مقدَّرةٌ لا مفوترة** (§١٣) — ومن خريطةِ أسعارٍ قد تكون قديمة */
    public function estimatedSpend(): float
    {
        return $this->spent;
    }

    /** أثرُ الرحلةِ كاملاً — الحارسُ ④ */
    public function trail(): array
    {
        return $this->trail;
    }

    // ── القرار عند الإخفاق ─────────────────────────────────────────────

    /**
     * **إخفاقٌ وقع — فماذا يُفعَل؟**
     *
     * @param  array{code?: ?int, body?: ?string, streamed?: bool, retry_after?: ?int}  $signal
     * @return array{action: string, cause: string, why: string, delay: ?int,
     *               model: ?string, depth: int, estimated_spend: float}
     */
    public function fail(array $signal): array
    {
        if ($this->closed) {
            return $this->answer('stop', 'closed', (string) ($this->closedWhy ?? 'الرحلةُ مُغلَقة'), null);
        }

        $model    = $this->model();
        $decision = AiRouting::decideFrom($signal);
        $cause    = (string) $decision['cause'];

        // ④ الحارسُ الرابع — كلُّ قفزةٍ تُسجَّل عند وقوعِها لا عند الإغلاق
        $this->record($model, $cause, $decision['why']);

        if ($model !== null && in_array($cause, AiRouting::FAULTS, true)) {
            AiRouting::noteFailure((string) $model->provider_id);
        }

        // النموذجُ أُزيل من المنبع ⇒ حقيقةٌ دائمةٌ تُكتَب فلا يُعاد إليه أبداً
        if ($decision['mark'] === 'unavailable' && $model !== null && $model->exists) {
            $model->forceFill(['health' => 'UNAVAILABLE', 'last_error' => null])->save();
        }

        // ① إعادةُ محاولةٍ على النموذجِ نفسِه؟
        $next  = $this->attempt + 1;
        $delay = AiRouting::delayFor($decision, $next,
            isset($signal['retry_after']) ? (int) $signal['retry_after'] : null);

        if ($delay !== null) {
            $this->attempt = $next;

            return $this->answer('retry', $cause, (string) $decision['why'], $delay);
        }

        // ② احتياطٌ؟ — والصفوفُ الستّةُ التي تمنعه هي لبُّ الجدول
        if (! $decision['fallback']) {
            return $this->close($cause, (string) $decision['why']);
        }

        // ① الحارسُ الأوّل — عمقٌ أقصاه اثنتان
        if ($this->index + 1 > AiRouting::MAX_DEPTH) {
            return $this->close($cause, 'بلغت الرحلةُ عمقَ الاحتياطِ الأقصى ('
                . AiRouting::MAX_DEPTH . ') — لا قفزةَ بعدَه');
        }

        $target = $this->nextEligible($decision);
        if ($target === null) {
            /*
             * **والسببُ يُقال كما هو.** «لا احتياطَ» و«كلُّ احتياطٍ على مزوّدٍ
             * مُستبعَد» حالتان مختلفتان تماماً: الأولى نقصُ تهيئةٍ يُعالَج بإضافةِ
             * نموذج، والثانيةُ **سلسلةٌ بلا تكرارٍ حقيقيّ** — ثلاثةُ نماذجَ على
             * مزوّدٍ واحدٍ تسقط معاً حين يسقط. وخلطُهما يُخفي عيبَ التهيئةِ الأهمّ.
             */
            return $this->close($cause, $this->skippedCooling > 0
                ? 'كلُّ ما تبقّى في السلسلةِ على مزوّدٍ في تهدئةٍ الآن — والسلسلةُ بلا تكرارٍ حقيقيّ'
                : 'لا نموذجَ احتياطيَّ صالحٌ بعد هذا في السلسلة');
        }

        // ② الحارسُ الثاني — ميزانيّةُ الطلب
        $est    = AiRouting::estimateHop((array) ($this->chain[$target]->pricing ?? []),
            $this->inTokens, $this->outTokens);
        $budget = AiRouting::withinBudget($this->spent, $est, $this->ceiling);

        if (! $budget['ok']) {
            return $this->close($cause, (string) $budget['why']);
        }

        $this->spent   = (float) $budget['spent'];
        $this->index   = $target;
        $this->attempt = 0;

        return $this->answer('fallback', $cause, (string) $decision['why'], null);
    }

    /** نجاحٌ — يمسح تهدئةَ المزوّدِ ويُغلق الرحلة */
    public function succeed(): array
    {
        if (! $this->closed && ($m = $this->model()) !== null) {
            AiRouting::noteSuccess((string) $m->provider_id);
        }

        $this->closed    = true;
        $this->closedWhy = null;

        return $this->answer('done', 'ok', 'تمّ', null);
    }

    // ── الداخل ─────────────────────────────────────────────────────────

    /**
     * **فتحُ الرحلةِ على أوّلِ نموذجٍ يصحُّ الطلبُ إليه.**
     *
     * والحارسُ ③ يعمل هنا أيضاً: مزوّدٌ في تهدئةٍ يُتخطّى **قبل** إنفاقِ مهلةٍ
     * عليه، لا بعدَ أن يفشل. والحارسُ ② كذلك: أساسيٌّ كلفتُه المقدَّرةُ تتجاوز
     * سقفَ الطلبِ لا تبدأ رحلةٌ إليه أصلاً.
     */
    private function open(): void
    {
        if ($this->chain === []) {
            $this->closed    = true;
            $this->closedWhy = 'سلسلةُ هذا الغرضِ فارغةٌ أو لا نموذجَ فيها صالحٌ الآن';

            return;
        }

        $i = 0;
        while (isset($this->chain[$i])
            && AiRouting::inCooldown((string) $this->chain[$i]->provider_id)) {
            $this->record($this->chain[$i], 'provider_cooldown',
                (string) AiRouting::TABLE['provider_cooldown']['why']);
            $i++;
        }

        if (! isset($this->chain[$i])) {
            $this->closed    = true;
            $this->closedWhy = 'كلُّ مزوّدي هذه السلسلةِ في تهدئةٍ الآن';

            return;
        }

        $est    = AiRouting::estimateHop((array) ($this->chain[$i]->pricing ?? []),
            $this->inTokens, $this->outTokens);
        $budget = AiRouting::withinBudget(0.0, $est, $this->ceiling);

        if (! $budget['ok']) {
            $this->closed    = true;
            $this->closedWhy = (string) $budget['why'];

            return;
        }

        $this->index = $i;
        $this->spent = (float) $budget['spent'];
    }

    /**
     * **النموذجُ التالي الصالحُ للقفز إليه** — أو `null`.
     *
     * ويُتخطّى مزوّدٌ في تهدئة (الحارس ③). **والاحتياطُ المشروطُ يُفحَص هنا**:
     * تجاوزُ السياقِ لا يُحتاط إلّا إلى نافذةٍ **أوسعَ مُثبَتة** — ونافذةٌ
     * مجهولةٌ ليست أوسع، فقفزةٌ إليها تُعيد الفشلَ نفسَه بكلفةٍ ثانية. وهذه
     * قاعدةُ `Tri` ① في موضعِها: المجهولُ لا يمرّ عند التنفيذ.
     */
    private function nextEligible(array $decision): ?int
    {
        $this->skippedCooling = 0;

        $currentCtx = $decision['conditional'] === 'wider_context'
            ? $this->contextWindow($this->chain[$this->index] ?? null)
            : null;

        /*
         * **ومزوّدُ القفزةِ الحالية يُمسَك قبل المسح** — فشرطُ `other_provider`
         * يُقاس عليه. والرصيدُ رصيدُ حسابٍ عند مزوّدٍ بعينِه: كلُّ نموذجٍ على
         * اعتمادِه يسقط سقوطَه، **فقفزةٌ إليه إنفاقُ نداءٍ على بابٍ مغلقٍ سلفاً**.
         */
        $currentProvider = $decision['conditional'] === 'other_provider'
            ? (string) ($this->chain[$this->index]->provider_id ?? '')
            : null;

        for ($i = $this->index + 1; $i < count($this->chain); $i++) {
            $m = $this->chain[$i];

            if (AiRouting::inCooldown((string) $m->provider_id)) {
                $this->skippedCooling++;
                $this->record($m, 'provider_cooldown',
                    (string) AiRouting::TABLE['provider_cooldown']['why']);
                continue;
            }

            if ($decision['conditional'] === 'wider_context') {
                $ctx = $this->contextWindow($m);
                if ($ctx === null || $currentCtx === null || $ctx <= $currentCtx) continue;
            }

            if ($currentProvider !== null && (string) $m->provider_id === $currentProvider) continue;

            /*
             * **والحوكمةُ تُعاد عند كلِّ قفزة** (المرحلة ٤ · P4-W5).
             *
             * السماحُ بالنموذجِ «أ» ليس سماحاً بالنموذجِ «ب». فلو قُرئت
             * السياسةُ مرّةً عند فتحِ الرحلةِ وحدَها لصار **الاحتياطُ بابَ
             * التفافٍ عليها**: يُمنَع نموذجٌ غالٍ في السياسة، ثمّ يُبلَغ
             * بإسقاطِ الأرخصِ منه قصداً أو صدفة.
             */
            if (! $this->governed($m)) continue;

            return $i;
        }

        return null;
    }

    /**
     * **أتسمح الحوكمةُ بهذا النموذجِ الآن؟** — والافتراضُ «نعم» بلا حاكمٍ مُركَّب.
     *
     * `AiRouteRun` محرّكُ قرارٍ خالصٌ يُستعمَل في اختباراتٍ بلا مستخدمٍ ولا
     * سياق، **فحقنُ الحاكمِ اختياريٌّ عمداً**: من لم يحقنه يحصل على السلوكِ
     * القديمِ حرفاً، ومن حقنه تُعاد سياستُه عند كلِّ قفزة.
     */
    private function governed(AiModel $m): bool
    {
        return $this->gate === null || (bool) ($this->gate)($m);
    }

    /** يُحقَن من `AiGovernance` — لا يُبنى هنا فلا يعرف هذا الصنفُ مستخدماً */
    public function governBy(?\Closure $gate): self
    {
        $this->gate = $gate;

        return $this;
    }

    /** نافذةُ السياقِ **المُثبَتةُ** — ومجهولةُ المصدرِ ليست رقماً يُقاس عليه */
    private function contextWindow(?AiModel $m): ?int
    {
        if ($m === null) return null;

        $fact = ((array) $m->limits)['context_window'] ?? null;
        if (! is_array($fact)) return null;
        if ((string) ($fact['src'] ?? 'unknown') === 'unknown') return null;

        return is_numeric($fact['v'] ?? null) ? (int) $fact['v'] : null;
    }

    /** الحارسُ ④ — بالنموذجِ والسببِ والكلفةِ المقدَّرة، وبلا سرٍّ ولا متنِ ردّ */
    private function record(?AiModel $model, string $cause, string $why): void
    {
        $hop = [
            'model'           => $model?->litellm_model_name === null ? '—' : (string) $model->litellm_model_name,
            'cause'           => $cause,
            'why'             => $why,
            'depth'           => $this->index,
            'attempt'         => $this->attempt,
            'estimated_spend' => round($this->spent, 6),
            'at'              => now()->toIso8601String(),
        ];

        $this->trail[] = $hop;

        hub_audit('قفزةُ توجيهٍ بعد إخفاق',
            $this->profile === null ? \App\Models\AiProfileModel::MODULE : AiProfile::MODULE,
            (string) ($this->profile?->id ?? ($model?->id ?? '')),
            (string) ($this->profile?->label ?? ($model?->display_name ?? '—')),
            ['after' => Redactor::arr($hop)]);
    }

    private function close(string $cause, string $why): array
    {
        $this->closed    = true;
        $this->closedWhy = $why;

        return $this->answer('stop', $cause, $why, null);
    }

    private function answer(string $action, string $cause, string $why, ?int $delay): array
    {
        return [
            'action'          => $action,
            'cause'           => $cause,
            'why'             => $why,
            'delay'           => $delay,
            'model'           => $this->model()?->litellm_model_name === null
                ? null : (string) $this->model()->litellm_model_name,
            'depth'           => $this->index,
            'estimated_spend' => round($this->spent, 6),
        ];
    }
}
