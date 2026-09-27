<?php

namespace App\Support\Ai;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Support\Ai\Ask\AskContext;
use App\Support\Ai\Ask\AskFailures;
use App\Support\Ai\Gateway\AiChat;
use App\Support\Ai\Governance\AiCost;
use App\Support\Ai\Governance\AiGovernance;
use App\Support\Ai\Governance\AiPolicy;
use App\Support\Ai\Routing\AiRouteRun;
use App\Support\Ai\Routing\AiRouting;

/**
 * **النداءُ المحكومُ الواحد — البابُ الوحيدُ إلى `AiChat::complete`.**
 * (`docs/ai-hub/46-ai-roadmap.md` §٢ — الخطوةُ صفر)
 *
 * كانت حلقةُ «اقبل ← نادِ ← سوِّ ← قرِّر» محبوسةً داخل مولِّدِ «اسأل Hub»، فكلُّ
 * ميزةِ ذكاءٍ ثانيةٍ كانت ستنسخها — **أو تتجاوزها**. وهنا تعيش مرّةً واحدة، فتنال
 * كلُّ ميزةٍ مجاناً ما ناله المساعد:
 *
 *  · **الحوكمةُ عند كلِّ محاولة** — `AiGovernance::admit` (سياسةٌ + حجزُ ميزانيّة +
 *    صفٌّ في السجلّ) قبل النداء، ثمّ تسويةٌ بعده: `settleOk` · `settleFailed` ·
 *    `abandon` لِما لم يغادر الخادمَ أصلاً (فلا يُحاسَب).
 *  · **سقفُ النداءاتِ للطلبِ كلِّه** — يُفحَص قبل النداءِ لا بعدَه.
 *  · **سقفُ المخرَج بعد تضييقِ السياسة** — والأشدُّ يفوز (`min` لا `max`).
 *  · **رحلةٌ واحدةٌ عبر سلسلةِ الغرض** (`AiRouteRun`) — إعادةٌ واحتياطٌ وتهدئةُ مزوّد.
 *  · **ولا نومَ في مسارٍ متزامن** — مهلةٌ أطولُ من `MAX_SYNC_DELAY` تُحوَّل قراراً.
 *
 * وما لا يعرفه هذا الصنفُ عمداً: **معنى الردّ**. يُعيد متنَ البوّابةِ كما هو
 * (`['ok' => true, 'data' => …]`) أو رمزَ إخفاقٍ مصنَّفاً (`AskFailures`)، والميزةُ
 * تقرأ الردَّ بشكلِها هي — أداةً كان أو جواباً أو JSON مُهيكلاً.
 *
 * **ولا يُعيد بناءَ ثقة:** كلُّ ما يعود من النموذجِ طلبٌ غيرُ موثوقٍ تُصادقه الميزة.
 */
final class GovernedCompletion
{
    /** أطولُ انتظارٍ يُقبَل داخلَ طلبٍ متزامن — وما فوقَه يُحوَّل قراراً لا نوماً */
    public const MAX_SYNC_DELAY = 2;

    private int $calls = 0;

    private int $attempts = 0;

    private array $lastUsage = [];

    private ?string $lastFailure = null;

    private ?string $lastEventId = null;

    private function __construct(
        private AiRouteRun $run,
        private array $gov,
        private int $maxCalls,
        private int $maxOutput,
    ) {}

    /**
     * **سياقُ الحوكمةِ لميزةٍ — بعد أن مرّ تخويلُ Hub الخاصُّ بها.**
     *
     * ما يفعله «اسأل Hub» في منسّقه، مرّةً واحدةً لكلِّ ميزةٍ أخرى: يبني السياق،
     * ويرفع `hub_allowed` — **والمُنادي مسؤولٌ أنّه لم يُنادِ إلّا بعد تخويلِه
     * هو** (فالرايةُ اسمُها ما تعنيه: «مرّ تخويلُ Hub») — ثمّ يقرأ السياسةَ للغرضِ
     * ويحمل سقوفَها في السياقِ لقطةً واحدةً للطلبِ كلِّه.
     *
     * @return array{ok: bool, code: ?string, gov: array}
     */
    public static function authorize(mixed $user, AiProfile $profile, string $feature, ?string $correlation = null): array
    {
        $gov = AiGovernance::context($user, (string) $profile->key, $feature, $correlation);
        $gov['hub_allowed'] = true;

        $verdict = AiPolicy::evaluate($gov);
        if (! $verdict['allowed']) {
            return ['ok' => false, 'code' => (string) ($verdict['code'] ?? AskFailures::POLICY_DENIED), 'gov' => $gov];
        }

        $gov['limits'] = (array) $verdict['limits'];
        $gov['tools']  = (bool) $verdict['tools'];

        return ['ok' => true, 'code' => null, 'gov' => $gov];
    }

    /**
     * **يفتح رحلةً واحدةً للطلبِ المنطقيِّ كلِّه.**
     *
     * @param  array  $gov   سياقُ `AiGovernance::context(...)` — أو `[]` في اختباراتِ عقدٍ بلا مستخدم
     * @param  array{feature: string, max_calls: int, max_output: int, in_tokens?: int, ceiling?: ?float}  $opts
     */
    public static function open(AiProfile $profile, array $gov, array $opts): self
    {
        $run = AiRouteRun::for($profile, [
            'ceiling'    => $opts['ceiling'] ?? null,
            'in_tokens'  => (int) ($opts['in_tokens'] ?? 0),
            'out_tokens' => (int) $opts['max_output'],
            'feature'    => (string) $opts['feature'],
        ])->governBy($gov === [] ? null : AiGovernance::gateFor($gov));

        return new self($run, $gov, (int) $opts['max_calls'], (int) $opts['max_output']);
    }

    /**
     * **رحلةٌ مُغلَقةٌ** — كلُّ نموذجٍ في السلسلةِ مُستبعَدٌ تشغيليّاً الآن (تهدئةٌ أو سقفُ كلفة).
     */
    public function closed(): bool
    {
        return $this->run->closed();
    }

    /**
     * **نداءٌ واحدٌ وما يليه من قرار** — والحلقةُ محدودةٌ بحاجزين: سقفُ النداءاتِ
     * وإغلاقُ الرحلة.
     *
     * @param  array  $payload     متنُ الطلبِ بلا `model` ولا `max_tokens` — يُضافان هنا
     * @param  int    $inputChars  طولُ المدخلِ بالحروف — لتقديرِ رموزِه عند الحجز
     * @return array{ok: true, data: array}|array{ok: false, code: string}
     */
    public function call(array $payload, int $inputChars): array
    {
        return $this->loop($payload, $inputChars, false);
    }

    /**
     * **تضمينُ نصوصٍ بالحوكمة نفسِها** (العقلُ الثاني · المرحلة ٤) — الحلقةُ ذاتُها: سقفُ النداءات،
     * والقبولُ والحجزُ والتسويةُ لكلِّ محاولة، والرحلةُ عبر سلسلة الغرض. والمخرَجُ متّجهاتٌ لا رموز.
     *
     * @param  list<string>  $texts
     * @return array{ok: true, data: array}|array{ok: false, code: string}
     */
    public function embed(array $texts, int $inputChars): array
    {
        return $this->loop(['input' => array_values($texts)], $inputChars, true);
    }

    private function loop(array $payload, int $inputChars, bool $embed): array
    {
        // سببُ آخرِ إخفاقٍ **في هذا النداءِ المنطقيِّ وحدَه** — لا في الطلبِ كلِّه.
        $stepFailure = null;

        while (true) {
            // ① الحاجزُ الصلب — يُفحَص **قبل** النداءِ لا بعدَه
            if ($this->calls >= $this->maxCalls) {
                return $this->fail($stepFailure ?? AskFailures::TOOL_BUDGET);
            }

            $model = $this->run->model();
            if ($model === null) {
                return $this->fail($this->lastFailure ?? AskFailures::PROVIDER_FAILURE);
            }

            // التضمينُ لا مخرَجَ رمزيَّ له — يُقدَّر بمدخله وحدَه
            $outCap = $embed ? 1 : $this->outputCap();

            // ② الحوكمةُ تسبق كلَّ محاولة — إعادةً كانت أم احتياطاً
            $this->attempts++;
            $admit = $this->admit($model, $outCap, $inputChars);

            if (! $admit['ok']) {
                return $this->fail($this->lastFailure = (string) $admit['code']);
            }

            $t0 = microtime(true);
            $this->calls++;
            // ترتيبُ المفاتيحِ كما كان حرفيّاً: `model` أوّلاً و`max_tokens` آخراً
            $res = $embed
                ? AiChat::embed(['model' => (string) $model->litellm_model_name] + $payload)
                : AiChat::complete(
                    ['model' => (string) $model->litellm_model_name] + $payload + ['max_tokens' => $outCap],
                    $outCap,
                );

            $ms = (int) ($res['ms'] ?? round((microtime(true) - $t0) * 1000));
            $this->lastUsage = $res['usage'] ?: $this->lastUsage;

            if ($res['ok']) {
                if ($admit['event'] !== null) {
                    AiGovernance::settleOk($admit['event'], $admit['holds'],
                        (array) $res['usage'], (array) ($model->pricing ?? []), $ms, $res['status'],
                        AiChat::shape((array) $res['data']) + ['max_output' => $outCap]);
                    $this->lastEventId = (string) $admit['event']->id;
                }

                // النجاحُ يمسح تهدئةَ المزوّدِ ولا يُغلق الرحلة
                AiRouting::noteSuccess((string) $model->provider_id);

                // **والنموذجُ الذي خدم فعلاً** — قد يكون احتياطيّاً؛ والتضمينُ يحتاجه (فضاءُ المتّجهات لكلِّ نموذج)
                return ['ok' => true, 'data' => (array) $res['data'], 'model' => (string) $model->litellm_model_name];
            }

            if ($admit['event'] !== null) {
                // ما لم يغادر الخادمَ لا يُحاسَب — وانقطاعُ النقلِ يبقى التزاماً
                if (($res['sent'] ?? true) === false) {
                    AiGovernance::abandon($admit['event'], $admit['holds'],
                        (string) $res['failure']);
                } else {
                    AiGovernance::settleFailed($admit['event'], $admit['holds'],
                        (string) $res['failure'], (string) $res['cause'], $res['status'], $ms,
                        (array) $res['usage'], (array) ($model->pricing ?? []),
                        AiChat::shape((array) ($res['data'] ?? [])) + ['max_output' => $outCap]);
                }

                $this->lastEventId = (string) $admit['event']->id;
            }

            $this->lastFailure = $stepFailure = (string) $res['failure'];

            $decision = $this->run->fail([
                'code'        => $res['status'],
                'body'        => (string) $res['error'],
                'retry_after' => $res['retry_after'],
            ]);

            // ④ ولا نومَ طويلٌ في مسارٍ متزامن
            if ($decision['action'] === 'retry') {
                $delay = (int) ($decision['delay'] ?? 0);

                if ($delay > self::MAX_SYNC_DELAY) {
                    // مزوّدٌ طلب أن نصبر عليه أخبرَنا أنّه لن يخدمنا الآن — فيُحسَب
                    // عليه إخفاقٌ ثانٍ فتُعجَّل تهدئتُه، ويُستأنَف القرارُ بلا انتظار
                    $decision = $this->run->fail([
                        'code' => $res['status'], 'body' => (string) $res['error'],
                    ]);

                    // وإعادةٌ ثانيةٌ تُطلَب رغم ذلك تُعامَل توقّفاً — لا حلقةَ بلا نداء
                    if ($decision['action'] === 'retry') {
                        return $this->fail($this->lastFailure);
                    }
                } elseif ($delay > 0) {
                    usleep($delay * 1_000_000);
                }
            }

            if (in_array($decision['action'], ['stop', 'done'], true)) {
                return $this->fail($this->lastFailure);
            }
        }
    }

    /** استهلاكُ آخرِ نداءٍ ناجح + عددُ النداءاتِ في الطلبِ كلِّه */
    public function usage(): array
    {
        // `array_merge` لا `+` — فلا يُسقَط عدُّ النداءاتِ صامتاً بمفتاحٍ متصادم
        return array_merge($this->lastUsage, ['calls' => $this->calls]);
    }

    /** صفُّ سجلِّ الاستهلاكِ لآخرِ محاولة — لربطِ ما بُني عليها بكلفتها */
    public function lastEventId(): ?string
    {
        return $this->lastEventId;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function lastFailure(): ?string
    {
        return $this->lastFailure;
    }

    /** أثرُ الرحلةِ للتدقيقِ — بلا سرٍّ ولا متنِ ردّ */
    public function trail(): array
    {
        return $this->run->trail();
    }

    /**
     * **قبولُ محاولةٍ واحدة** — أو رفضُها بتصنيفٍ يُقال للمستخدم.
     * وبلا سياقِ حوكمةٍ يمرّ (اختباراتُ عقدٍ بلا مستخدمٍ ولا قاعدة).
     */
    private function admit(AiModel $model, int $outCap, int $inputChars): array
    {
        if ($this->gov === []) {
            return ['ok' => true, 'code' => null, 'holds' => [], 'event' => null];
        }

        $inTokens = (int) ceil($inputChars / AskContext::CHARS_PER_TOKEN);
        $est      = AiCost::estimate((array) ($model->pricing ?? []), $inTokens, $outCap);

        return AiGovernance::admit($this->gov, $model, $est, $inTokens + $outCap, [
            'attempt'   => $this->attempts,
            'relation'  => $this->attempts === 1 ? 'initial'
                : ($this->run->depth() > 0 ? 'fallback' : 'retry'),
            'parent_id' => $this->lastEventId,
        ]);
    }

    /** سقفُ المخرَجِ بعد تضييقِ السياسة — تضييقٌ في اتّجاهٍ واحد */
    private function outputCap(): int
    {
        $lim = (int) ($this->gov['limits']['max_output_tokens'] ?? 0);

        return $lim > 0 ? max(1, min($this->maxOutput, $lim)) : $this->maxOutput;
    }

    private function fail(?string $code): array
    {
        return ['ok' => false, 'code' => $code ?? AskFailures::MODEL_FAILURE];
    }
}
