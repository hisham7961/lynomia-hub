<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Metrics;

/**
 * مركز السوشال ميديا — مراقبةٌ وتحليل لا دفتر جرد.
 * كانت الوحدة تحفظ رقم متابعين واحداً يُدهس مع كل تحديث، وعشرة أعمدة أرقامٍ
 * خام على المنشور بلا معدّل تفاعلٍ ولا مقارنة ولا اتجاه. هنا: النمو من
 * السلسلة الزمنية، والتفاعل محسوباً، وأداء أنواع المحتوى، وأفضل وقت نشر،
 * وحالُ التغذية الآلية لكل حساب — أي رقمٍ حيّ وأيّها ميت.
 */
class SocialController extends Controller
{
    public function index(\Illuminate\Http\Request $r)
    {
        abort_unless(hub_can(auth()->user(), 'social', 'v'), 403, 'لا تملك عرض السوشال ميديا');

        $days = min(365, max(7, (int) $r->query('d', 30)));

        return view('social.index', [
            'st' => hub_social_stats($days),
            'days' => $days,
            'canPosts' => hub_can(auth()->user(), 'posts', 'v'),
        ]);
    }

    /**
     * التقاط لقطةٍ فورية للأرقام الحالية — لمن لم يربط n8n بعد.
     * لا يخترع بيانات: يحوّل ما في الحقول الآن إلى نقطةٍ في السلسلة.
     */
    public function snapshot()
    {
        abort_unless(hub_can(auth()->user(), 'social', 'e'), 403);

        $n = 0;
        foreach (hub_scope(\App\Models\SocialAccount::query(), 'social')->whereNull('deleted_at')->get() as $a) {
            $n += Metrics::capture('social', $a, 'manual');
        }
        if (hub_can(auth()->user(), 'posts', 'e')) {
            foreach (hub_scope(\App\Models\SocialPost::query(), 'posts')->whereNull('deleted_at')->get() as $p) {
                $n += Metrics::capture('posts', $p, 'manual');
            }
        }
        hub_audit('لقطة مقاييس السوشال', 'social', null, $n . ' نقطة');

        return redirect()->route('social.index')
            ->with('ok', "سُجّلت {$n} نقطة قياس من القيم الحالية — السلسلة تبدأ من الآن ولو بلا ربطٍ خارجي");
    }
}
