<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;

/**
 * مركز السياسات والإقرارات: **من قرأ ومن لم يقرأ** بالأسماء لا بالعدد.
 * كانت السياسة نصّاً يُحفظ ثم يُنسى — لا إبلاغ ولا إقرار ولا أثر لتحديث نسخة.
 */
class PolicyController extends Controller
{
    public function index()
    {
        abort_unless(hub_can(auth()->user(), 'policies', 'v'), 403, 'لا تملك عرض السياسات');

        return view('policies.index', ['board' => hub_ack_board()]);
    }

    /** إعلان السجل على من يعنيهم — إشعارٌ وإقرارٌ معلّق لكل مُخاطَب */
    public function announce(string $module, string $id)
    {
        abort_unless(isset(hub_ack_modules()[$module]), 404);
        abort_unless(hub_can(auth()->user(), $module, 'e'), 403);

        $n = hub_ack_announce($module, $id);
        hub_audit('إعلان للإقرار', $module, $id, $n . ' مخاطَباً');

        return back()->with('ok', "أُبلغ {$n} مستخدماً، وفُتح لكلٍّ إقرارٌ معلّق باسمه");
    }

    /** إقرار المستخدم الحالي — بدليله: وقتٌ وعنوانٌ وجهاز */
    public function ack(string $module, string $id)
    {
        abort_unless(isset(hub_ack_modules()[$module]), 404);
        abort_unless(hub_can(auth()->user(), $module, 'v'), 403);

        $ack = hub_ack_do($module, $id);
        abort_unless($ack, 404, 'السجل غير موجود');

        return back()->with('ok', 'سُجّل إقرارك بالنسخة ' . $ack->ver . ' — ووقتُه وجهازك محفوظان دليلاً');
    }
}
