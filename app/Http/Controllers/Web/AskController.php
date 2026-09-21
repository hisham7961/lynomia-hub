<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\AskFailures;
use App\Support\AskPipeline;
use App\Support\AskPolicy;
use App\Support\AskTools;
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
 * ── **ولا محادثةً محفوظةً في هذه المرحلة — وهذا قرارٌ لا نقص** ──
 *
 * حفظُ الأسئلةِ والأجوبةِ يُنشئ **مخزناً حسّاساً جديداً**: سؤالٌ مثل «كم راتبُ
 * فلان؟» يصير صفّاً يُقرأ لاحقاً بصلاحيّاتٍ غيرِ صلاحيّةِ سائله — وهو بعينِه
 * التسريبُ الذي أغلقه المسارُ كلُّه، ويعيده من البابِ الخلفيّ. فالمرحلةُ
 * **سؤالٌ وجوابٌ بلا أثرٍ مخزَّن**: ما يبقى هو أثرُ التدقيقِ وحدَه، وفيه
 * **من سأل ومتى وبأيِّ أدوات — لا ماذا سأل ولا ماذا أُجيب**.
 *
 * وإضافةُ محفوظاتٍ لاحقاً تحتاج مرحلتَها: مِلكيّةٌ ونطاقٌ وتحقّقٌ عند كلِّ
 * قراءةٍ وسياسةُ احتفاظٍ وحذف. ولا تُبنى بنصفِها.
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

    public function index()
    {
        $this->gate();

        return view('ask.index', $this->screen());
    }

    /** يسأل ويعرض — بلا حفظٍ ولا محادثةٍ مستمرّة */
    public function run(Request $r)
    {
        $this->gate();

        $question = (string) $r->input('q', '');
        $result   = AskPipeline::ask($question, $r->user());

        // **`array_merge` لا `+`**: عاملُ الجمعِ يُبقي مفاتيحَ الطرفِ الأيسر،
        // و`screen()` يحمل `result => null` — فكان الجوابُ يُبنى ثمّ يُطمَس
        // بفراغٍ قبل العرض، والصفحةُ تعود كأن شيئاً لم يكن.
        return view('ask.index', array_merge($this->screen(), [
            'result' => $result,
            // **السؤالُ يُعاد عرضُه للمستخدمِ نفسِه** — لا يُخزَّن ولا يُدقَّق
            'asked'  => AskPolicy::sanitizeQuestion($question),
        ]));
    }

    /**
     * حالةُ الشاشةِ المشتركة — **ولا سرَّ فيها ولا تفصيلَ بنيةٍ داخليّة**.
     *
     * @return array<string,mixed>
     */
    private function screen(): array
    {
        $ready   = AskPolicy::ready();
        $profile = AskPolicy::profile();
        $canFix  = \App\Support\AiAccess::canManage();

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
                ? \App\Support\AskModelAdvisory::read($profile) : null,
            'failures' => AskFailures::MESSAGES,
        ];
    }
}
