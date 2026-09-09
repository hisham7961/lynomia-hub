<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\ConversationMember;
use App\Models\DmMessage;
use App\Models\User;
use App\Support\MessageLink;
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
        $results = [];

        if (mb_strlen($q) >= 2) {
            // هروبُ أحرف البدل — نصٌّ يبحثه المستخدم لا نمطُ LIKE
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';

            $rows = collect();

            // ١) الخلاصة — منطَّقةٌ بشركة القارئ
            $feed = $this->feedScope(
                Comment::whereNull('deleted_at')->where('module', 'feed')->where('body', 'LIKE', $like)
            )->with('user')->orderByDesc('created_at')->limit(30)->get();

            // ٢) القنوات — التي هو عضوٌ فيها حصراً
            $memberConvIds = ConversationMember::where('user_id', $me)->pluck('conversation_id');
            $chan = Comment::whereNull('deleted_at')->where('module', 'channel')
                ->whereIn('conversation_id', $memberConvIds)->where('body', 'LIKE', $like)
                ->with('user')->orderByDesc('created_at')->limit(30)->get();

            foreach ($feed->merge($chan) as $c) {
                $rows->push([
                    'kind'    => $c->module === 'feed' ? 'خلاصة' : 'قناة',
                    'icon'    => $c->module === 'feed' ? '📣' : '#️⃣',
                    'when'    => $c->created_at,
                    'author'  => optional($c->user)->name,
                    'excerpt' => Str::limit(trim((string) $c->body), 120),
                    'link'    => MessageLink::comment($c),
                ]);
            }

            // ٣) المحادثاتُ المباشرة — خيوطُ القارئ ضمن نطاق الشركة
            $dms = DmMessage::alive()->inCompanyScope()
                ->where(fn ($w) => $w->where('from_id', $me)->orWhere('to_id', $me))
                ->where('body', 'LIKE', $like)->orderByDesc('created_at')->limit(30)->get();
            $names = User::whereIn('id', $dms->pluck('from_id')->unique())->pluck('name', 'id');
            foreach ($dms as $m) {
                $rows->push([
                    'kind'    => 'محادثة',
                    'icon'    => '💬',
                    'when'    => $m->created_at,
                    'author'  => $names[$m->from_id] ?? null,
                    'excerpt' => Str::limit(trim((string) $m->body), 120),
                    'link'    => MessageLink::dm($m, $me),
                ]);
            }

            $results = $rows->sortByDesc(fn ($x) => (string) $x['when'])->take(50)->values()->all();
        }

        return view('search.messages', ['q' => $q, 'results' => $results]);
    }

    /** نطاقُ الشركة على الخلاصة — نظيرُ `CommentController::feedCompanyFilter` حرفاً */
    private function feedScope($query)
    {
        if (! hub_has_col('comments', 'company_id')) return $query;
        if (($cids = hub_company_ids()) === null) return $query;

        return $query->where(fn ($w) => $w->whereIn('company_id', $cids)->orWhereNull('company_id'));
    }
}
