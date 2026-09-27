<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Collaboration\MessageLink;
use App\Support\Collaboration\MessageSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * **بحثُ الرسائل عبر السطوح** (§25) — سكّةٌ واحدةٌ تبحث في **نصّ** الرسائل عبر
 * المحرّك الواحد (خلاصة/قنوات/محادثات مباشرة)، خلافاً للبحث الشامل الذي يجد الوحداتِ
 * والقنواتِ **بالاسم** لا بمحتواها. كلُّ نتيجةٍ برابطٍ دائمٍ (`MessageLink`).
 *
 * **العزلُ خادميّ لا واجهيّ:** لا تظهر رسالةٌ لا يراها الباحثُ أصلاً —
 *  • الخلاصةُ منطَّقةٌ بشركة القارئ (نظيرُ `feedCompanyFilter`).
 *  • القنواتُ: عضويّتُه الفعّالة شرطُ الظهور (لا يبحث في قناةٍ ليس عضواً فيها).
 *  • المحادثاتُ المباشرة: خيوطُه وحدَها (طرفٌ فيها) ضمن نطاق الشركة.
 * فالبحثُ يجد ويوصل ما يملك رؤيتَه فقط — لا بابَ IDOR يفتحه.
 */
class MessageSearchController extends Controller
{
    public function index(Request $r)
    {
        $me = (string) auth()->id();
        $q = trim(hub_str($r->query('q')));

        // القاعدةُ والعزلُ والعدُّ في `MessageSearch::run` (يشترك فيها الجوال) — هنا العرضُ وحده
        $found = MessageSearch::run(auth()->user(), $q);

        $results = $found['rows']->map(fn (array $x) => $x['type'] === 'dm'
            ? [
                'kind'    => 'محادثة',
                'icon'    => '💬',
                'when'    => $x['at'],
                'author'  => $x['author'],
                'excerpt' => Str::limit(trim((string) $x['model']->body), 120),
                'link'    => MessageLink::dm($x['model'], $me),
            ]
            : [
                'kind'    => $x['type'] === 'feed' ? 'خلاصة' : 'قناة',
                'icon'    => $x['type'] === 'feed' ? '📣' : '#️⃣',
                'when'    => $x['at'],
                'author'  => $x['author'],
                'excerpt' => Str::limit(trim((string) $x['model']->body), 120),
                'link'    => MessageLink::comment($x['model']),
            ])->all();

        return view('search.messages', ['q' => $q, 'results' => $results,
            'resultsN' => $found['total']]);
    }
}
