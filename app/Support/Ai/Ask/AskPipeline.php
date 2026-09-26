<?php

namespace App\Support\Ai\Ask;

use App\Contracts\AskGenerator;
use Illuminate\Support\Str;
use App\Support\Ai\Governance\AiGovernance;
use App\Support\Ai\Governance\AiPolicy;
use App\Support\Platform\Redactor;

/**
 * **منسّقُ «اسأل Hub»** — من السؤالِ إلى الجوابِ، بحارسٍ عند كلِّ خطوة. (P3-W5)
 *
 * المسارُ كاملاً:
 *
 * ```
 * المستخدِم → الباب → كتالوجُ أدواتِه → السياق → التوجيه → طلبُ قراءة
 *   → إعادةُ تحقّقٍ في الخادم → تنفيذ → مظروفٌ غيرُ موثوق → توليد
 *   → جوابٌ مُصادَق → تدقيق
 * ```
 *
 * ── **الثابتُ الحاكم: النموذجُ لا يملك سلطةَ تنفيذ** ──
 *
 * ما يعود من المولِّدِ **طلبٌ غيرُ موثوقٍ بالكامل**، ولو كان المولِّدُ قد
 * أطاع حقناً إطاعةً تامّة. ولذلك **حارسان مستقلّان لا واحد**:
 *
 *  ① **تصفيةُ الكتالوج** — النموذجُ لا يرى إلّا ما يملكه صاحبُ الجلسة، فلا
 *     يعرف أنّ ما لا يملكه موجودٌ أصلاً. وهذا يقتل «حقنَ الأداة» عند منبعِه.
 *
 *  ② **تصريحُ التنفيذ** — وهو الحارسُ الذي يُعوَّل عليه. **يُعاد بناءُ
 *     الكتالوجِ عند كلِّ تنفيذٍ من الصفر**، ويُعاد التحقّقُ من المستخدمِ
 *     والنطاقِ والوحدةِ والعمليّةِ والوسائطِ والمعرّفاتِ والحدودِ والحقول.
 *
 * **ولماذا حارسانِ والأوّلُ كافٍ ظاهراً؟** لأنّ الأوّلَ يُبنى **مرّةً** في
 * أوّلِ الطلب، وقد تُسحَب صلاحيّةُ المستخدمِ بعد ذلك بلحظة. فكتالوجٌ بُني
 * قبل السحبِ يبقى في ذاكرةِ الطلبِ يَعِد بما لم يعد مملوكاً — وهذا
 * ‏TOCTOU حرفيّاً. والحارسُ الثاني يقرأ الصلاحيّةَ **وقتَ التنفيذِ** فيسقط
 * الطلبُ ولو مرّ بالأوّل.
 *
 * ── **حدُّ القراءةِ فقط** ──
 *
 * هذه المرحلةُ **تقرأ ولا تكتب**. لا إنشاءَ ولا تعديلَ ولا حذفَ ولا اعتمادَ
 * ولا إرسالَ ولا تشغيلَ مسارٍ ولا تغييرَ إعدادٍ أو صلاحيّةٍ أو تكامل. والحدُّ
 * مفروضٌ **بالبنيةِ لا بالنيّة**: الأدواتُ الخمسُ قارئةٌ كلُّها، وأيُّ اسمٍ
 * خارجَها يُرَدّ قبل أن يُنظَر في وسائطِه.
 *
 * ── **ولا تفكيرَ يُخزَّن** ──
 *
 * لا يُحفَظ سؤالٌ ولا جوابٌ ولا سلسلةُ تفكيرٍ في التدقيق: سجلُّ التدقيقِ
 * يُقرأ بصلاحيّاتٍ **غيرِ صلاحيّةِ السائل**، فتخزينُ «كم راتبُ فلان؟» فيه
 * يفتح التسريبَ الذي أغلقه المسارُ كلُّه.
 */
final class AskPipeline
{
    /** شكلُ الجوابِ الموحَّد — لا شكلَ يُخترَع في متحكّمٍ ولا قالب */
    public const SHAPE = ['ok', 'answer', 'sources', 'failure', 'message',
                          'partial', 'budget', 'meta'];

    /** **الأدواتُ الكاتبةُ: لا شيء.** ثابتٌ يُقرأ ويُختبَر لا تعليقٌ يُنسى */
    public const WRITE_TOOLS = [];

    /**
     * **يُجيب عن سؤالٍ واحد** — أو يقول لماذا لا، بتصنيفٍ لا برسالةٍ عامّة.
     *
     * @return array{ok:bool, answer:?string, sources:list<array>, failure:?string,
     *               message:?string, partial:bool, budget:array, meta:array}
     */
    public static function ask(string $question, mixed $user = null, ?AskGenerator $generator = null): array
    {
        $started     = microtime(true);
        $correlation = (string) Str::uuid();
        $u           = $user ?? auth()->user();
        $gen         = $generator ?? app(AskGenerator::class);

        // ── البابُ: صلاحيّةٌ لا توافر ──
        if ($u === null || ! AskPolicy::canAsk($u)) {
            AskAudit::denied(AskFailures::UNAUTHORIZED, ['correlation' => $correlation,
                'failure' => AskFailures::UNAUTHORIZED, 'generator' => $gen->label()]);

            return self::fail(AskFailures::UNAUTHORIZED, $correlation, $started, $gen);
        }

        $q = AskPolicy::sanitizeQuestion($question);
        if ($q === null) {
            return self::fail(AskFailures::MALFORMED_QUESTION, $correlation, $started, $gen);
        }

        // ── التوافرُ: مفصولٌ عن الصلاحيّةِ عمداً (العيبُ الذي أُغلق سابقاً) ──
        if (! AskPolicy::ready($u)) {
            return self::fail(AskFailures::UNAVAILABLE, $correlation, $started, $gen);
        }

        $profile = AskPolicy::profile();
        if ($profile === null) {
            return self::fail(AskFailures::UNAVAILABLE, $correlation, $started, $gen);
        }

        /*
         * ── **الحوكمة: السياقُ يُبنى هنا و`hub_allowed` تُرفَع هنا** (المرحلة ٤) ──
         *
         * **والرفعُ بعد `canAsk` مباشرةً لا قبلَها** — فالرايةُ اسمُها ما تعنيه:
         * «مرّ تخويلُ Hub». ورفعُها في أوّلِ الدالّةِ بلا فحصٍ كان سيجعل
         * `AiPolicy` تثق بما لم يُتحقَّق منه، **فتصير طبقةُ التضييقِ بابَ دخول**.
         *
         * والسياسةُ تُقرأ **للغرضِ قبل النموذج**: منعٌ على مستوى الغرضِ يوفّر
         * بناءَ الكتالوجِ والسياقِ كلِّه.
         */
        $gov = AiGovernance::context($u, (string) $profile->key, 'ask', $correlation);
        $gov['hub_allowed'] = true;

        $verdict = AiPolicy::evaluate($gov);
        if (! $verdict['allowed']) {
            return self::fail((string) ($verdict['code'] ?? AskFailures::POLICY_DENIED),
                $correlation, $started, $gen, ['profile' => (string) $profile->key]);
        }

        // **والسقوفُ تُحمَل في السياقِ لا تُعاد قراءتُها** — فقراءةٌ ثانيةٌ في
        // المولِّدِ قد تقع بعد تعديلِ السياسةِ بلحظة، فيُولَّد بسقفٍ غيرِ الذي
        // أُذن به. والسياقُ لقطةٌ واحدةٌ للطلبِ كلِّه.
        $gov['limits'] = (array) $verdict['limits'];
        $gov['tools']  = (bool) $verdict['tools'];

        // **والمولِّدُ يُسلَّم السياقَ قبل أوّلِ خطوة** — فلا نداءَ خارجَ الحوكمة
        $gen->govern($gov);

        /*
         * **ويُسلَّم السؤالَ نفسَه** (قبولُ الإنتاج · `21b7633f`).
         *
         * وكان `$q` يُطهَّر في أوّلِ الدالّةِ ثمّ **لا يُستعمَل مرّةً أخرى** —
         * مرجعانِ اثنان في الملفّ كلِّه: إسنادٌ وفحصُ فراغ. فيصل النموذجَ
         * مظروفُ بياناتٍ بلا سؤال، فيجيب بالحقيقة: «لم يرد سؤالٌ محدّد».
         */
        $gen->asking($q);

        // ── الكتالوج: الحارسُ الأوّل — النموذجُ لا يرى ما لا يملكه صاحبُ الجلسة ──
        //
        // **والسياسةُ تضيّق فوقَه ولا توسّع**: `allow_tools = false` تُفرغ
        // الكتالوجَ فلا يرى النموذجُ أداةً أصلاً — وهذا أقوى من رفضِ التنفيذِ
        // لاحقاً لأنّه **لا يُغري بطلبِ ما لا يُنفَّذ** فتُهدَر خطوةُ توليد.
        $catalog = $verdict['tools'] ? AskTools::catalog($u) : [];

        $ctx = AskContext::open();
        $ctx->trust('limits', $ctx->budget());
        $ctx->trust('modules_offered', array_keys($catalog));
        $ctx->trust('tools_offered', AskTools::TOOLS);
        $ctx->trust('read_only', true);
        $ctx->trust('scope', 'كلُّ قراءةٍ مُنطَّقةٌ بصلاحيّةِ صاحبِ الجلسةِ وشركتِه');

        $history   = [];
        $requested = [];
        $denied    = [];
        $answer    = null;
        $cited     = [];
        $usage     = [];
        $executed  = 0;
        $seen      = [];
        $truncatedAnswer = false;

        /*
         * ── **سقفانِ مستقلّان: خطواتُ النموذجِ وتنفيذاتُ الأدوات** ──
         *
         * خطوةٌ رفضها الحارسُ تستهلك **نداءَ توليدٍ** ولا تستهلك **قراءةَ
         * قاعدة**. وعدُّهما عدّاً واحداً يجعل نموذجاً يطلب ستَّ وحداتٍ ممنوعةٍ
         * يستنفد ميزانيّةَ القراءةِ **بلا قراءةٍ واحدة** — فيُحرَم السائلُ
         * جوابَه بعقوبةِ خطأٍ لم يرتكبه.
         */
        $maxSteps = AskPolicy::maxModelSteps();
        $maxTools = AskPolicy::maxToolCalls();

        for ($step = 0; $step < $maxSteps; $step++) {
            $envelope = $ctx->render();

            // **فشلٌ مُغلَقٌ لا مفتوح**: مظروفٌ لم يجتَز فحصَه لا يُرسَل
            if (! $ctx->verify($envelope)) {
                /*
                 * **فشلُ السياجِ حالةُ أمنٍ لا حالةُ سعة** — ورقمٌ سرّيٌّ ظهر
                 * ثالثةً يعني أنّ بياناتٍ حملته إلى داخلِ الحمولة. وتسميتُها
                 * «ضيقَ سياق» كانت تُخفي محاولةَ حقنٍ خلف رسالةِ سعة.
                 */
                return self::fail(AskFailures::CONTEXT_INTEGRITY, $correlation, $started, $gen,
                    ['requested' => $requested, 'denied' => $denied, 'ctx' => $ctx,
                     'profile' => (string) $profile->key]);
            }

            $out = $gen->step($envelope, $catalog, $history);
            $usage = is_array($out['usage'] ?? null) ? $out['usage'] : $usage;
            $kind  = (string) ($out['kind'] ?? 'error');

            if ($kind === 'error') {
                $code = (string) ($out['code'] ?? AskFailures::MODEL_FAILURE);

                /*
                 * **ودليلُ السياقِ يُسجَّل مع الإخفاقِ لا مع النجاحِ وحدَه.**
                 *
                 * كان إخفاقُ النموذجِ يُسجَّل بـ`chars = 0` و`rows = 0` مهما
                 * قُرئ قبلَه — **فالصفُّ الذي يدّعي ضيقَ السياقِ لا يحمل عنه
                 * رقماً واحداً**، ولا يُشخَّص بعدَه شيء.
                 */
                return self::fail(AskFailures::known($code) ? $code : AskFailures::MODEL_FAILURE,
                    $correlation, $started, $gen,
                    ['requested' => $requested, 'denied' => $denied, 'usage' => $usage,
                     'profile' => (string) $profile->key,
                     'executed' => $executed, 'ctx' => $ctx]);
            }

            if ($kind === 'answer') {
                $answer = is_string($out['answer'] ?? null) ? $out['answer'] : null;
                $cited  = array_values(array_filter((array) ($out['sources'] ?? []), 'is_int'));
                // **جوابٌ بلغ سقفَ رموزِ المخرَجِ مبتورٌ** — ويُعلَن جزئيّاً كما
                // يُعلَن قصُّ السياق، فلا يُقرأ الناقصُ تامّاً
                $truncatedAnswer = (bool) ($out['partial'] ?? false);
                break;
            }

            if ($kind !== 'tool') {
                return self::fail(AskFailures::MODEL_FAILURE, $correlation, $started, $gen,
                    ['requested' => $requested, 'denied' => $denied, 'usage' => $usage,
                     'profile' => (string) $profile->key,
                     'executed' => $executed, 'ctx' => $ctx]);
            }

            // ── الحارسُ الثاني: التصريحُ عند التنفيذِ، مستقلٌّ عن الكتالوج ──
            $tool = is_string($out['tool'] ?? null) ? $out['tool'] : '';
            $args = is_array($out['args'] ?? null) ? $out['args'] : [];
            $requested[] = $tool;

            $why = self::authorize($tool, $args, $u);

            /*
             * **وطلبٌ مكرَّرٌ حرفيّاً لا يُنفَّذ مرّتين.**
             *
             * نموذجٌ عالقٌ في حلقةٍ يُعيد الطلبَ نفسَه حتّى تنفد الميزانيّة،
             * **وكلُّ إعادةٍ استعلامُ قاعدةٍ كامل** يعيد الصفوفَ التي في
             * المظروفِ أصلاً. فالرفضُ هنا يوفّر القراءةَ ويقول للنموذجِ
             * صراحةً إنّ النتيجةَ عنده — وهو أنفعُ من تكرارٍ صامت.
             */
            $print = $tool . '|' . json_encode($args, JSON_UNESCAPED_UNICODE);
            if ($why === null && isset($seen[$print])) {
                $why = 'طلبٌ مكرَّرٌ حرفيّاً — نتيجتُه في المظروفِ سلفاً';
            }

            if ($why === null && $executed >= $maxTools) {
                $why = 'بلغ الطلبُ سقفَ قراءاتِ القاعدةِ المسموحة';
            }

            if ($why !== null) {
                $denied[] = $tool;
                $ctx->trust('rejected_step_' . $step, ['tool' => $tool, 'why' => $why]);
                $history[] = ['tool' => $tool, 'ok' => false, 'why' => $why];
                continue;
            }

            $seen[$print] = true;
            $executed++;
            $result = AskTools::run($tool, $args, $u);
            $added  = $ctx->addResult($result);

            $history[] = ['tool' => $tool, 'ok' => (bool) ($result['ok'] ?? false),
                          'rows' => $added['rows'], 'source' => $added['source']];
        }

        if ($answer === null) {
            return self::fail(AskFailures::TOOL_BUDGET, $correlation, $started, $gen,
                ['requested' => $requested, 'denied' => $denied, 'usage' => $usage,
                     'profile' => (string) $profile->key,
                 'executed' => $executed, 'ctx' => $ctx]);
        }

        $answer = trim(Redactor::text($answer));
        if ($answer === '') {
            return self::fail(AskFailures::MODEL_FAILURE, $correlation, $started, $gen,
                ['requested' => $requested, 'denied' => $denied, 'usage' => $usage,
                     'profile' => (string) $profile->key, 'ctx' => $ctx]);
        }

        // ── المراجعُ تُصادَق على ما قرأه الخادمُ لا على ما قاله النموذج ──
        foreach ($cited as $n) {
            if (! $ctx->isKnownSource((int) $n)) {
                return self::fail(AskFailures::FORGED_SOURCE, $correlation, $started, $gen,
                    ['requested' => $requested, 'denied' => $denied, 'usage' => $usage,
                     'profile' => (string) $profile->key, 'ctx' => $ctx]);
            }
        }

        /*
         * ── **رقمٌ بلا قراءةٍ تخمينٌ — ولو بدا واثقاً** (قبولُ إنتاجٍ · «كم مشروعاً») ──
         *
         * **وموضعُه بعد مصادقةِ المراجعِ مقصود:** جوابٌ يخترع مرجعاً `[#9]`
         * ويحمل رقماً يستوفي الشرطين معاً، **واختلاقُ المرجعِ أدقُّ تشخيصاً**
         * — فهو يقول ما فعله النموذجُ بالضبط. وتقديمُ هذا الحارسِ كان يبتلع
         * `FORGED_SOURCE` ويُخفي أخطرَ السلوكين.
         *
         * السؤالُ الأوّلُ الحقيقيُّ كان «كم مشروع لدينا؟»، وهو **لا يُجاب من
         * معرفةِ نموذج**: العددُ حقيقةٌ في قاعدةِ Hub، مُنطَّقةٌ بشركةِ السائلِ
         * وصلاحيّتِه. فنموذجٌ يجيب برقمٍ ولم يُنفِّذ قراءةً واحدةً **اخترعه** —
         * ومصادقةُ المراجعِ وحدَها لا تمسكه، لأنّها تفحص ما استُشهد به لا ما
         * لم يُستشهد بشيءٍ أصلاً.
         *
         * **والحارسُ ضيّقٌ عمداً:** يُطبَّق على الأرقامِ وحدَها وحين **لا قراءةَ
         * البتّة**. فجوابٌ نصّيٌّ يمرّ كما كان، وجوابٌ برقمٍ بعد قراءةٍ حقيقيّةٍ
         * تحكمه مصادقةُ المراجعِ كما كانت. ولا يُوسَّع إلى «كلُّ جوابٍ يلزمه
         * مصدر» — فذاك يمنع «لا أجد ما يجيب في نطاقِك» وهي إجابةٌ صحيحة.
         */
        if ($executed === 0 && preg_match('/[0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', $answer)) {
            return self::fail(AskFailures::UNSOURCED_NUMBER, $correlation, $started, $gen,
                ['requested' => $requested, 'denied' => $denied, 'usage' => $usage,
                     'profile' => (string) $profile->key,
                 'executed' => $executed, 'ctx' => $ctx]);
        }

        /*
         * ── **ولا جوابَ نهائيٌّ بلا قراءةٍ نفّذها الخادم** (`21b7633f`) ──
         *
         * دفاعٌ في العمقِ **فوق** إصلاحِ وصولِ السؤالِ لا بدلاً منه. فحتّى مع
         * سؤالٍ يصل، قد يُنتج نموذجٌ جواباً من معرفتِه أو يدّعي «لا توجد
         * بيانات» — **وكلاهما ادّعاءٌ عن شركةِ السائلِ لا يملكه**.
         *
         * **و«لم أقرأ شيئاً» ليست «لا توجد بيانات».** الثانيةُ حقيقةٌ لا تُقال
         * إلّا بقراءةٍ وقعت وعادت فارغة: عدٌّ صفرٌ، أو استعلامٌ بلا صفوف. أمّا
         * قراءةٌ لم تقع — أو مُنعت صلاحيّةً، أو أخفقت — فلا تُنتج علماً بعدمٍ
         * البتّة. **وخلطُهما يجعل منعَ صلاحيّةٍ يُقرأ «شركتُك فارغة»**.
         *
         * **والحارسُ لا يُخفي السببَ الأصليَّ**: إن لم يصل السؤالُ فالنموذجُ
         * لن يطلب أداةً، فيقع هنا بتصنيفٍ يُسمّي ما جرى — لا بجوابٍ يُصدَّق.
         */
        if ($executed === 0) {
            return self::fail(AskFailures::NO_SERVER_READ, $correlation, $started, $gen,
                ['requested' => $requested, 'denied' => $denied, 'usage' => $usage,
                 'profile' => (string) $profile->key,
                 'executed' => $executed, 'ctx' => $ctx]);
        }


        $budget  = $ctx->budget();
        $partial = (bool) $budget['truncated'] || $truncatedAnswer;

        AskAudit::asked([
            'correlation' => $correlation,
            'tools'       => $requested,
            'requested'   => $requested,
            'denied'      => $denied,
            'offered'     => count($catalog),
            'modules'     => array_values(array_filter(array_map(
                static fn (array $s) => $s['module'], $ctx->sources()))),
            'sources'     => count($ctx->sources()),
            'rows'        => (int) $budget['rows'],
            'chars'       => (int) $budget['chars'],
            'truncated'   => $partial,
            'executed'    => $executed,
            'calls'       => isset($usage['calls']) ? (int) $usage['calls'] : null,
            'profile'     => (string) $profile->key,
            'model'       => isset($usage['model']) ? (string) $usage['model'] : null,
            'generator'   => $gen->label(),
            'tokens'      => isset($usage['tokens']) ? (int) $usage['tokens'] : null,
            'cost'        => isset($usage['cost']) ? (float) $usage['cost'] : null,
            'reasoning_tokens' => isset($usage['reasoning']) ? (int) $usage['reasoning'] : null,
            'depth'       => count($requested),
            'ms'          => (int) round((microtime(true) - $started) * 1000),
            'outcome'     => $partial ? 'partial' : 'ok',
        ]);

        return [
            'ok'      => true,
            'answer'  => $answer,
            'sources' => $ctx->sources(),
            'failure' => $partial ? AskFailures::PARTIAL_RESULT : null,
            'message' => $partial ? AskFailures::message(AskFailures::PARTIAL_RESULT) : null,
            'partial' => $partial,
            'budget'  => $budget,
            'meta'    => self::requestMeta($correlation, $started, $gen, (string) $profile->key, $usage),
        ];
    }

    /**
     * **تصريحُ التنفيذ** — الحارسُ الثاني، ويُعاد بناؤه من الصفرِ كلَّ مرّة.
     *
     * يعود `null` إن جاز التنفيذُ، وإلّا **سببٌ لا يكشف ما لا يملكه الطالب**:
     * «غيرُ متاحة» لا «موجودةٌ وممنوعة». فالتفريقُ بينهما يرسم للمهاجمِ خريطةَ
     * ما عند غيرِه.
     */
    public static function authorize(string $tool, array $args, mixed $user): ?string
    {
        if ($user === null || ! AskPolicy::canAsk($user)) return 'لا صلاحيّةَ لاستعمالِ المساعد';
        if (! in_array($tool, AskTools::TOOLS, true)) return 'أداةٌ غيرُ معروفة';
        if (in_array($tool, self::WRITE_TOOLS, true)) return 'الكتابةُ خارجَ نطاقِ هذه المرحلة';

        // **الكتالوجُ يُبنى الآن لا من ذاكرةِ أوّلِ الطلب** — وهذا ما يُغلق TOCTOU
        $catalog = AskTools::catalog($user);

        $module = $args['module'] ?? null;
        if ($module !== null) {
            if (! is_string($module) || ! isset($catalog[$module])) return 'وحدةٌ غيرُ متاحةٍ لك';
        }

        // **الشكلُ يُفحَص قبل القيمة.** وسيطٌ يصل مصفوفةً حيث يُنتظَر عددٌ كان
        // يُحوَّل إلى نصٍّ فيرفع تحذيراً — أي أنّ **حمولةً فاسدةً تُسقط الحارسَ
        // نفسَه** بدل أن يردَّها. فيُفحَص النوعُ أوّلاً ويُرَدُّ ما ليس قياسيّاً.
        foreach (['id', 'limit', 'page'] as $numeric) {
            if (! array_key_exists($numeric, $args)) continue;

            $v = $args[$numeric];
            if (is_int($v)) continue;
            if (! is_string($v) || ! ctype_digit($v)) return 'وسيطٌ عدديٌّ غيرُ صالح';
        }

        if (isset($args['filters']) && ! is_array($args['filters'])) return 'شكلُ تصفيةٍ غيرُ صالح';

        return null;
    }

    // ── الداخل ────────────────────────────────────────────────────────

    private static function fail(string $code, string $correlation, float $started,
                                 AskGenerator $gen, array $extra = []): array
    {
        $ctx    = $extra['ctx'] ?? null;
        $budget = $ctx instanceof AskContext ? $ctx->budget() : [];

        AskAudit::asked([
            'correlation' => $correlation,
            'tools'       => (array) ($extra['requested'] ?? []),
            'requested'   => (array) ($extra['requested'] ?? []),
            'denied'      => (array) ($extra['denied'] ?? []),
            'rows'        => (int) ($budget['rows'] ?? 0),
            'chars'       => (int) ($budget['chars'] ?? 0),
            'truncated'   => (bool) ($budget['truncated'] ?? false),
            'executed'    => (int) ($extra['executed'] ?? 0),
            'calls'       => isset($extra['usage']['calls']) ? (int) $extra['usage']['calls'] : null,
            /*
             * ── **والصفُّ الذي يُحقَّق فيه كان أفقرَ من صفِّ النجاح** ──
             * (قبولُ الإنتاج · `92dbd557`)
             *
             * أثرُ النجاحِ يحمل الغرضَ والنموذجَ والرموزَ والكلفة، **وأثرُ
             * الإخفاقِ لا يحمل منها شيئاً** — وهو وحدَه ما يُفتَح للتحقيق.
             * فمعرّفُ الطلبِ يُعطي «أخفق» ولا يقول **بأيِّ نموذجٍ ولا كم
             * أنفق**، فيُخمَّن ما كان يُقرأ.
             */
            'profile'     => isset($extra['profile']) ? (string) $extra['profile'] : null,
            'model'       => isset($extra['usage']['model']) ? (string) $extra['usage']['model'] : null,
            'tokens'      => isset($extra['usage']['tokens']) ? (int) $extra['usage']['tokens'] : null,
            'cost'        => isset($extra['usage']['cost']) ? (float) $extra['usage']['cost'] : null,
            'reasoning_tokens' => isset($extra['usage']['reasoning'])
                ? (int) $extra['usage']['reasoning'] : null,
            'generator'   => $gen->label(),
            'failure'     => $code,
            'ms'          => (int) round((microtime(true) - $started) * 1000),
            'outcome'     => 'failed',
            'why'         => $code,
        ]);

        return [
            'ok'      => false,
            'answer'  => null,
            'sources' => $ctx instanceof AskContext ? $ctx->sources() : [],
            'failure' => $code,
            'message' => AskFailures::message($code),
            'partial' => false,
            'budget'  => $budget,
            'meta'    => self::requestMeta($correlation, $started, $gen, null,
                (array) ($extra['usage'] ?? [])),
        ];
    }

    private static function requestMeta(string $correlation, float $started, AskGenerator $gen,
                                 ?string $profile, array $usage): array
    {
        return [
            'correlation' => $correlation,
            'ms'          => (int) round((microtime(true) - $started) * 1000),
            'generator'   => $gen->label(),
            'live'        => $gen->isLive(),
            'profile'     => $profile,
            // **لا تفكيرَ ولا سياقَ خامٌّ هنا** — ما يُعرَض للمستخدمِ لا يحمل إلّا ما يخصّه
            'usage'       => array_intersect_key($usage,
                array_flip(['model', 'tokens', 'cost', 'reasoning'])),
            /*
             * **وعددُ النداءاتِ المدفوعةِ يُعرَض ولو أخفق الطلب.**
             *
             * كان يُحسَب في المولِّدِ ويُسجَّل في الأثر، **ثمّ يُصفّى هنا**
             * فلا يراه من يُشخّص من الشاشة: طلبٌ أنفق نداءين وأخفق يبدو
             * كأنّه لم يُنفق شيئاً. وهو **عددٌ لا يحمل سرّاً**.
             */
            'calls'       => (int) ($usage['calls'] ?? 0),
        ];
    }
}
