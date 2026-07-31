<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;

/**
 * لوحة الأهداف والنتائج — الصورة لا الجدول.
 * كانت «نسبة الإنجاز» رقماً يُكتب باليد على الهدف، والنتيجة الرئيسية تطلب
 * معرّف مؤشرٍ نصاً. هنا: النسبة **محسوبةٌ** من نتائجها، ومقارَنةٌ بالإيقاع
 * المتوقَّع من الفترة — فيُقال «متأخر» و«متقدّم» لا رقمٌ أصمّ.
 */
class OkrController extends Controller
{
    public function index()
    {
        abort_unless(hub_can(auth()->user(), 'okrs', 'v'), 403, 'لا تملك عرض الأهداف');

        return view('okrs.index', ['b' => hub_okr_board()]);
    }

    /** تحديث كل القيم الآلية الآن — بدل انتظار الدورة اليومية */
    public function refresh()
    {
        abort_unless(hub_can(auth()->user(), 'okrs', 'e'), 403);

        $n = 0;
        foreach (hub_scope(\App\Models\Objective::query(), 'okrs')->whereNull('deleted_at')->get() as $o) {
            if (hub_okr_progress($o->id, true)) $n++;
        }
        hub_audit('تحديث الأهداف', 'okrs', null, $n . ' هدفاً');

        return back()->with('ok', "حُدّثت قيم {$n} هدفاً من مصادرها");
    }
}
