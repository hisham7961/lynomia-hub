<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Ai\Ask\AskFailures;
use App\Support\Ai\Ask\AskMemory;
use App\Support\Ai\Ask\AskPipeline;
use App\Support\Ai\Ask\AskPolicy;
use App\Support\Ai\Ask\AskTools;
use Illuminate\Http\Request;

/**
 * **شاشةُ «اسأل Hub»** (المرحلة ٣ · P3-W6).
 *
 * ── **البابُ صلاحيّةٌ والتوافرُ رسالةٌ داخلَ الصفحة** ──
 *
 * `canAsk` وحدَها تفتح الصفحة. أمّا جاهزيّةُ البوّابةِ والغرضِ فتُقال **في
 * الصفحة**، لا بإخفائها: لو أُخفيت عند انقطاعِ خدمةٍ لظنَّ صاحبُ الصلاحيّةِ
 * أنّه فقدها، وهو العيبُ نفسُه الذي يفصله `AskFailures`.
 *
 * ── **والمحادثةُ المحفوظة: مرحلتُها جاءت بشروطها كاملة** (المرحلة ٢ · v2.605.0) ──
 *
 * كانت المرحلةُ ٣ ترفض الحفظ: سؤالٌ مثل «كم راتبُ فلان؟» يصير صفّاً يُقرأ لاحقاً
 * بصلاحيّاتٍ غيرِ صلاحيّةِ سائله. وقالت إنّ المحفوظاتِ تحتاج «مِلكيّةً ونطاقاً وتحقّقاً
 * عند كلِّ قراءةٍ وسياسةَ احتفاظٍ وحذف — ولا تُبنى بنصفها». وهي الآن في `AskMemory`
 * بالشروط تلك حرفاً: خيطٌ لصاحبه وحدَه، وجوابٌ لا يُعرَض ما لم تجتز مصادرُه الحارسَ
 * **من جديد**، وأسئلةٌ سابقةٌ فقط (لا أجوبة) تصل النموذج، ونصوصٌ مشفَّرةٌ خارجَ التدقيق،
 * ومقصُّ عمرٍ ومحوٌ لصاحبه. وأثرُ التدقيقِ ما زال يقول مَن سأل ومتى — لا ماذا.
 *
 * ── **وما لا يُعرَض أبداً** ──
 *
 * لا حمولةَ أداةٍ خام، ولا مظروفَ سياق، ولا سلسلةَ تفكير، ولا سرّ. المعروضُ
 * **جوابٌ مُصادَقٌ ومصادرُه التي قرأها الخادمُ** لا التي ادّعاها النموذج.
 */
class AskController extends Controller
{
    /** حارسُ الصفحة — **هو نفسُه شرطُ ظهورِ الرابطِ** في الشريطِ ولوحةِ الأوامر */
    protected function gate(): void
    {
        AskPolicy::gate();
    }

    public function index(Request $r)
    {
        $this->gate();

        // خيطٌ محفوظٌ لصاحبه وحدَه — وخيطُ غيرِه ٤٠٤ كغيرِ الموجود (لا «ممنوع» تُفشي الوجود)
        $thread = null;
        if ($r->filled('thread')) {
            $thread = AskMemory::open($r->user(), (string) $r->input('thread'));
            abort_if($thread === null, 404);
        }

        return view('ask.index', $this->screen($thread));
    }

    /** يسأل ويعرض — ويحفظ في خيطِ صاحبه إن كانت الذاكرةُ مفعَّلة (`AskMemory`) */
    public function run(Request $r)
    {
        $this->gate();

        return view('ask.index', $this->answer($r));
    }

    /**
     * **السؤالُ نفسُه مع تقدّم القراءة** (المرحلة ٢ · «تدفّقُ الجواب» بصيغته الصادقة): أحداثُ SSE بكلِّ
     * خطوةِ تفكيرٍ وكلِّ قراءةٍ مُنطَّقةٍ تمّت (وحدتُها وعددُ صفوفها) — **ولا حرفَ من الجواب قبل مصادقة
     * مراجعه**: الحدثُ الأخيرُ (`done`) يحمل الصفحةَ نفسَها التي يعيدها `run`. والمسارُ ذاتُ الحرّاس
     * (`answer()` واحدٌ للمسارين)، وبلا JavaScript يبقى النموذجُ العاديّ.
     */
    public function stream(Request $r)
    {
        $this->gate();
        // خيطُ غيرِه ٤٠٤ **قبل** فتح البثّ — فلا يُرسَل رأسُ 200 لطلبٍ مرفوض
        if ($r->filled('thread')) abort_if(AskMemory::open($r->user(), (string) $r->input('thread')) === null, 404);

        return response()->stream(function () use ($r) {
            $send = function (string $event, array $data): void {
                echo 'event: ' . $event . "\n" . 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level() > 0) @ob_flush();
                flush();
            };
            $data = $this->answer($r, function (array $p) use ($send) {
                $send('progress', ['text' => match ($p['stage']) {
                    'think' => 'يفكّر… (الخطوة ' . (int) ($p['step'] ?? 1) . ')',
                    'read' => 'قرأ ' . ($p['label'] ? '«' . $p['label'] . '»' : 'بياناتٍ مُنطَّقة') . ' — ' . (int) ($p['rows'] ?? 0) . ' صفّاً',
                    default => 'يعمل…',
                }]);
            });
            $send('done', ['html' => view('ask.index', $data)->render()]);
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',   // nginx: لا يُخزَّن البثُّ مؤقّتاً
        ]);
    }

    /** بابٌ واحدٌ للسؤال — للعرض العاديّ وللبثّ: الخيط (لصاحبه)، والمتابعة، والحفظ @return array<string,mixed> */
    private function answer(Request $r, ?\Closure $progress = null): array
    {
        $thread = null;
        if ($r->filled('thread')) {
            $thread = AskMemory::open($r->user(), (string) $r->input('thread'));
            abort_if($thread === null, 404);
            if (AskMemory::full($thread)) $thread = null;   // بلغ سقفَه ⇒ خيطٌ جديد
        }

        $question = (string) $r->input('q', '');
        $result   = AskPipeline::ask($question, $r->user(), null, AskMemory::earlierQuestions($thread), $progress);
        // يُحفظ دورٌ فقط إن كانت الذاكرةُ مفعَّلةً والسؤالُ غيرَ فارغ — وهو وحدَه ما يُسقَط من التاريخ المعروض
        $saved    = AskMemory::enabled() && AskPolicy::sanitizeQuestion($question) !== null;
        $thread   = AskMemory::record($r->user(), $thread, $question, $result) ?? $thread;

        // **`array_merge` لا `+`**: عاملُ الجمعِ يُبقي مفاتيحَ الطرفِ الأيسر،
        // و`screen()` يحمل `result => null` — فكان الجوابُ يُبنى ثمّ يُطمَس
        // بفراغٍ قبل العرض، والصفحةُ تعود كأن شيئاً لم يكن.
        return array_merge($this->screen($thread, $saved), [
            'result' => $result,
            // **السؤالُ يُعاد عرضُه للمستخدمِ نفسِه** — لا يُخزَّن ولا يُدقَّق
            'asked'  => AskPolicy::sanitizeQuestion($question),
        ]);
    }

    /** محوُ خيطٍ واحدٍ أو كلِّ خيوط صاحب الجلسة — لا يمسّ خيطَ غيره أبداً */
    public function forget(Request $r)
    {
        $this->gate();
        $one = $r->filled('thread') ? (string) $r->input('thread') : null;
        if ($one !== null) abort_if(AskMemory::open($r->user(), $one) === null, 404);
        $n = AskMemory::forget($r->user(), $one);

        return redirect()->route('ask.index')->with('ok', $one ? 'مُحيت المحادثة' : "مُحيت محادثاتُك ({$n})");
    }

    /**
     * حالةُ الشاشةِ المشتركة — **ولا سرَّ فيها ولا تفصيلَ بنيةٍ داخليّة**.
     *
     * @param  bool  $justAsked  الدورُ الأخيرُ معروضٌ جواباً حيّاً فلا يُكرَّر في التاريخ
     * @return array<string,mixed>
     */
    private function screen(?\App\Models\AskThread $thread = null, bool $justAsked = false): array
    {
        $u = auth()->user();
        $turns = $thread ? AskMemory::turns($u, $thread) : [];
        if ($justAsked) array_pop($turns);

        $ready   = AskPolicy::ready();
        $profile = AskPolicy::profile();
        $canFix  = \App\Support\Ai\Center\AiAccess::canManage();

        return [
            'ready'    => $ready,
            'whyNot'   => $ready ? null : AskPolicy::whyNot(),
            'profile'  => $profile?->label,
            // عددُ الوحداتِ المتاحةِ له — **بلا أسمائها**: الأسماءُ خريطةٌ لما
            // يملكه، وهي تُعرَض في الصفحةِ نفسِها لصاحبِها، فلا بأس. لكنّ
            // العددَ وحدَه يكفي للطمأنة قبل أوّلِ سؤال.
            'modules'  => count(AskTools::catalog()),
            'tools'    => AskTools::TOOLS,
            // **الحدودُ كما تُفرَض الآن لا كما كُتبت يوماً** — فالمضبوطُ من
            // الإعداداتِ يُغيّر السلوكَ، وشاشةٌ تعرض الثابتَ تَعِد بما لا يقع
            'limits'   => [
                'question' => AskPolicy::MAX_QUESTION_CHARS,
                'steps'    => AskPolicy::maxToolCalls(),
                'rows'     => AskPolicy::MAX_ROWS_PER_TOOL,
                // **والسقفُ الذي يُنتج `OUTPUT_LIMIT` يُعرَض** — فرسالةُ الإخفاقِ
                // تُحيل إليه، وقارئُها كان لا يرى كم هو الآن
                'output'   => AskPolicy::maxOutputTokens(),
            ],
            'result'   => null,
            'asked'    => null,
            // **من يملك الإصلاحَ يُعطى الطريقَ إليه** — ولا يُعرَض لغيرِه بابٌ مغلق
            'canFix'   => $canFix,
            /*
             * **أيصلح النموذجُ لسؤالٍ قصير؟** — من التهيئةِ لا من نداء.
             *
             * ولمن يملك التبديلَ وحدَه: اسمُ النموذجِ وقدراتُه تفصيلُ بنيةٍ لا
             * يخصّ السائل، **وعرضُه للجميعِ يوسّع سطحَ المعرفةِ بلا فائدةٍ له**.
             */
            'advisory' => ($canFix && $profile !== null)
                ? \App\Support\Ai\Ask\AskModelAdvisory::read($profile) : null,
            'failures' => AskFailures::MESSAGES,
            // الذاكرة (المرحلة ٢): خيوطُه وحدَه، والأدوارُ بأجوبةٍ مُعادةِ التحقّق
            'memory'   => AskMemory::enabled(),
            'memoryDays' => AskMemory::days(),
            'threads'  => AskMemory::threads($u),
            'thread'   => $thread,
            'turns'    => $turns,
        ];
    }
}
