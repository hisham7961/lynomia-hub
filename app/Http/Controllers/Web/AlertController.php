<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** رادار «ينتهي قريباً»: كل تواريخ الانتهاء والتجديد والاستحقاق عبر الوحدات كلها */
class AlertController extends Controller
{
    public function index(Request $r)
    {
        /*
         * **الترشيحُ عند السلطةِ الواحدةِ لا هنا** (مجلس الخبراء · PROD-05).
         *
         * كان هنا ترشيحٌ ثانٍ: `->filter(fn ($i) => hub_can($u, $i['module'], 'v'))`.
         * ويومَ كُتب كان **زائداً بلا أثر** — فكلُّ صفٍّ يرجع من `hub_expiry()` قد
         * مرّ بالشرطِ نفسِه أصلاً (`hub_can` داخل حلقةِ المسح، ومثلُه في
         * `hub_doc_expiry`)، فوقَه قناعُ الحقلِ والنطاق.
         *
         * ثمّ تغيّرت السلطةُ تحتَه: صارت تُرجع صفَّ **صاحبِ الشأن** قصداً بلا
         * `hub_can` — فمن تنتهي إقامتُه يُنذَر بإقامتِه هو وإن لم يملك `hr:v`.
         * فأسقطه الترشيحُ الثاني وهو لا يدري، فصارت الشارةُ تقول «١» والصفحةُ
         * التي تفتحها الشارةُ تقول «لا شيء ينتهي». **وتناقضُ شاشتين أسوأُ من
         * صمتِهما: يتعلّم المستخدمُ ألّا يصدّقَ الشارة.**
         *
         * فالعلاجُ حذفُ التعريفِ الثاني لا ترقيعُه — تعريفٌ واحدٌ لسؤالٍ واحد.
         */
        $items = collect(hub_expiry($r->boolean('fresh')))->values();

        $late = $items->filter(fn ($i) => $i['days'] < 0);
        $week = $items->filter(fn ($i) => $i['days'] >= 0 && $i['days'] <= 7);
        $month = $items->filter(fn ($i) => $i['days'] > 7);

        return view('alerts.index', compact('late', 'week', 'month'));
    }
}
