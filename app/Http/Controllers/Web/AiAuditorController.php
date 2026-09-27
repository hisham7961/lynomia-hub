<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Ai\Auditor\Auditor;
use App\Support\Ai\Auditor\AuditorAccuracy;
use App\Support\Ai\Auditor\AuditorAi;
use App\Support\Ai\Center\AiAccess;
use Illuminate\Http\Request;

/**
 * **المدقّق في مركز الذكاء — دقّتُه وحالتُه** (§٣.٥ · A4).
 *
 * **أعدادٌ لا محتوى:** كم رُصد وكم زال وكم أقرّ المديرون وكم رفضوا لكلِّ كاشف — لا ملخّصَ
 * نتيجةٍ ولا اسمَ موظّف. فالقراءةُ لمن يرى مركزَ الذكاء (`aiView`)، والإطفاءُ والإعادةُ لمن
 * يديره (`aiAdmin`). والنتائجُ نفسُها تُقرأ حيث وُلدت: في مركز الفعل، مُعادةَ التنطيق لكلِّ مشاهد.
 */
class AiAuditorController extends Controller
{
    public function index()
    {
        AiAccess::gateView();

        return view('ai.auditor', [
            'sections' => AiAccess::sections(),
            'section'  => 'auditor',
            'manage'   => AiAccess::canManage(),
            'enabled'  => Auditor::enabled(),
            'aiWhyNot' => AuditorAi::whyNot(),
            'profile'  => AuditorAi::profileKey(),
            'maxCalls' => AuditorAi::maxCalls(),
            // بعين المشاهد: من له قائمةُ شركاتٍ يرى أعدادَ شركاته وحدَها
            'rows'     => AuditorAccuracy::stats(auth()->user()),
            'window'   => AuditorAccuracy::WINDOW_DAYS,
            'minN'     => AuditorAccuracy::MIN_DISPOSITIONS,
            'maxRate'  => AuditorAccuracy::MAX_DISMISS_RATE,
        ]);
    }

    /** إطفاءُ كاشفٍ أو إعادتُه — نتائجُه لا تُمسح، تُخفى معه وتعود بعودته */
    public function toggle(Request $r, string $key)
    {
        AiAccess::gateManage();
        abort_if(Auditor::detector($key) === null, 404);

        $off = ! AuditorAccuracy::isDisabled($key);
        AuditorAccuracy::setDisabled($key, $off, 'web', $off ? 'إطفاءٌ يدويّ من شاشة المدقّق' : 'إعادةُ تشغيلٍ من شاشة المدقّق');

        return redirect()->route('ai.auditor')
            ->with('ok', $off ? 'أُطفئ الكاشف — نتائجُه مخفيّةٌ ولم تُمسح.' : 'أُعيد تشغيلُ الكاشف.');
    }
}
