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

        // (WP-8.5) اللوحةُ نفسُها مُغنّاةً بأعلامها وعدّاداتها ومالكيها — والرقمُ
        // فيها من `hub_okr_progress` كما كان، فالمصدرُ واحدٌ للشاشتين
        return view('okrs.index', ['b' => \App\Support\OkrCentre::board()]);
    }

    /** تحديث كل القيم الآلية الآن — بدل انتظار الدورة اليومية */
    public function refresh()
    {
        abort_unless(hub_can(auth()->user(), 'okrs', 'e'), 403);

        $n = 0;
        foreach (hub_scope(\App\Models\Objective::query(), 'okrs')->whereNull('deleted_at')->get() as $o) {
            // التثبيتُ من فعلٍ صريح لا من فتح شاشة — والحارسُ داخل الدالة يمنعه
            // عن قارئٍ مقيَّد النطاق كي لا يُثبّت رقمَه الجزئيّ على عمودٍ مشترك
            if (hub_okr_progress($o->id, true, false, true)) $n++;
        }
        hub_audit('تحديث الأهداف', 'okrs', null, $n . ' هدفاً');

        return back()->with('ok', "حُدّثت قيم {$n} هدفاً من مصادرها");
    }
}
