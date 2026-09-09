<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Web\CommentController;
use App\Http\Controllers\Web\ConversationController;
use App\Http\Controllers\Web\DmController;
use App\Models\Comment;
use App\Models\DmMessage;
use App\Models\SavedMessage;
use App\Models\User;
use App\Support\Api;
use App\Support\Collaboration;
use App\Support\CollaborationRail;
use App\Support\CommentService;
use App\Support\DmService;
use App\Support\Presence;
use App\Support\Typing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * **تكافؤُ التعاونِ على الجوال** (Mobile Readiness · المرحلة ٩ · §109 · إضافيّ) —
 * يكشف على `/api/mobile/v1` القدراتِ الجديدةَ لمركزِ التواصل (قائمةُ الحاويات، الجلبُ
 * التدريجيّ، الحضور، الكتابة، التفاعلات، المحفوظات) **فوق السككِ نفسِها** التي يستعملها
 * الويبُ — لا محرّكٌ ثانٍ ولا عقدُ تخويلٍ ثانٍ:
 *
 *  • **العزلُ خادميٌّ بإعادةِ استعمالِ الحرّاس:** القناةُ عبر `guardConversation`
 *    (غيرُ العضو ٤٠٤)، والمحادثةُ عبر `DmService::reachable` + مفتاحٍ من `auth()`
 *    (F8 — لا thread_key من العميل)، والقائمةُ عبر `CollaborationRail` (مرآةُ عضويّاتِ
 *    صاحبها ضمن نطاقه). المركزُ داخليٌّ فالعميلُ يُطوى ٤٠٤ (له بوّابتُه).
 *  • **الحضورُ خشنٌ للتواصلِ لا للمراقبة**، **والكتابةُ عابرةٌ لا تُخزَّن ولا تُدقَّق** —
 *    نفسُ عقدِ الويب (`Presence`/`Typing`)، محايدُ النقل.
 *  • كلُّ ردٍّ بغلاف `data`+`request_id`؛ الهويّةُ من الجلسة (`auth()->user()`) لا من العميل.
 */
class MobileCollabController extends V1Controller
{
    /** المركزُ داخليٌّ — العميلُ يُطوى ٤٠٤ (له `portal/*`)؛ يعيد ردَّ خطأٍ أو null */
    private function denyClient(): ?Response
    {
        return hub_is_client(auth()->user())
            ? Api::error(Api::RESOURCE_NOT_FOUND, 404, 'غير موجود')
            : null;
    }

    /**
     * `GET conversations` — سكّتي: قنواتٌ/غرفٌ/مجموعاتٌ/محادثاتٌ مع غيرِ المقروءِ والحضور.
     * مرآةُ عضويّاتي ضمن نطاقي (`CollaborationRail`) — لا حاويةً لا أبلغها.
     */
    public function conversations(Request $r): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;

        $rail = CollaborationRail::forUser(auth()->user());

        $shape = fn (array $i) => [
            'id'       => (string) ($i['id'] ?? ''),
            'kind'     => (string) $i['kind'],
            'title'    => (string) $i['title'],
            'audience' => (string) ($i['audience'] ?? ''),
            'unread'   => (int) ($i['unread'] ?? 0),
            'favorite' => (bool) ($i['fav'] ?? false),
        ];
        $dmShape = fn (array $d) => [
            'user_id'  => (string) $d['other_id'],
            'title'    => (string) $d['title'],
            'unread'   => (int) $d['unread'],
            'presence' => (string) $d['presence'],
        ];

        return $this->ok([
            'channels'     => array_map($shape, $rail['channels']),
            'rooms'        => array_map($shape, $rail['rooms']),
            'groups'       => array_map($shape, $rail['groups']),
            'dms'          => array_map($dmShape, $rail['dms']),
            'unread_total' => (int) $rail['unreadTotal'],
        ]);
    }

    /**
     * `GET conversations/{id}/since?cursor=` — الجديدُ في قناةٍ/مجموعةٍ منذ مؤشّر + كتابة.
     * `guardConversation('v')` (غيرُ العضو ٤٠٤). عقدُ الأحداثِ نفسُه الذي يبثّه websocket لاحقاً.
     */
    public function channelSince(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;

        [$conv] = ConversationController::guardConversation($id, 'v');

        $cursor = Collaboration::decodeCursor((string) $r->query('cursor', ''));
        $q = Comment::where('conversation_id', $conv->id)->whereNull('deleted_at')->with('user:id,name');
        if ($cursor !== null) {
            [$t, $cid] = $cursor;
            $q->where(fn ($w) => $w->where('created_at', '>', $t)
                ->orWhere(fn ($x) => $x->where('created_at', $t)->where('id', '>', $cid)));
        }
        $rows = $q->orderBy('created_at')->orderBy('id')->limit(50)->get();

        $events = $rows->map(fn (Comment $c) => [
            'type'       => Collaboration::EV_MESSAGE_CREATED,
            'id'         => (string) $c->id,
            'parent_id'  => $c->parent_id ? (string) $c->parent_id : null,
            'user_id'    => (string) $c->user_id,
            'author'     => optional($c->user)->name,
            'body'       => (string) $c->body,
            'edited'     => $c->edited_at !== null,
            'created_at' => optional($c->created_at)->toIso8601String(),
        ])->all();

        $last = $rows->last();
        $next = $last ? Collaboration::encodeCursor((string) $last->created_at, (string) $last->id)
            : (string) $r->query('cursor', '');

        $typing = User::whereIn('id', Typing::current((string) $conv->id, (string) auth()->id()))->pluck('name')->all();

        return $this->ok(['events' => $events, 'cursor' => $next, 'typing' => array_values($typing)]);
    }

    /** `POST conversations/{id}/typing` — نبضةُ «أكتب الآن» (عضويّةٌ تكفي · عابرة) */
    public function channelTyping(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;

        [$conv] = ConversationController::guardConversation($id, 'v');
        Typing::ping((string) $conv->id, (string) auth()->id());

        return $this->ok(['ok' => true]);
    }

    /**
     * `GET dm/threads/{user}/since?cursor=` — الجديدُ في محادثةٍ منذ مؤشّر + كتابة · **F8**.
     * المفتاحُ من `auth()`+الطرف؛ الواردُ الجديدُ يُختَم مقروءاً؛ المحذوفةُ تُرسَل أثراً.
     */
    public function dmSince(Request $r, string $user): Response
    {
        $this->tagMobile($r);
        $me = auth()->user();

        $other = User::whereNull('deleted_at')->find($user);
        if (! $other || ! DmService::reachable($me, $other) || (string) $other->id === (string) $me->id) {
            return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'لا محادثة بهذا المعرّف');
        }

        $key = DmMessage::threadKey((string) $me->id, (string) $other->id);
        $cursor = Collaboration::decodeCursor((string) $r->query('cursor', ''));
        $q = DmMessage::where('thread_key', $key)->inCompanyScope();
        if ($cursor !== null) {
            [$t, $cid] = $cursor;
            $q->where(fn ($w) => $w->where('created_at', '>', $t)
                ->orWhere(fn ($x) => $x->where('created_at', $t)->where('id', '>', $cid)));
        }
        $rows = $q->orderBy('created_at')->orderBy('id')->limit(50)->get();

        // الواردُ الجديدُ إليّ يُختَم مقروءاً — المستخدمُ يقرأ الآن (نظيرُ dm.since في الويب)
        $incoming = $rows->where('to_id', (string) $me->id)->whereNull('read_at')->pluck('id');
        if ($incoming->isNotEmpty()) {
            DmMessage::whereIn('id', $incoming)->update(['read_at' => now()]);
        }

        $events = $rows->map(fn (DmMessage $m) => [
            'type'       => $m->deleted_at !== null ? Collaboration::EV_MESSAGE_DELETED : Collaboration::EV_MESSAGE_CREATED,
            'id'         => (string) $m->id,
            'mine'       => (string) $m->from_id === (string) $me->id,
            'body'       => $m->deleted_at !== null ? null : (string) $m->body,
            'deleted'    => $m->deleted_at !== null,
            'edited'     => $m->edited_at !== null,
            'created_at' => optional($m->created_at)->toIso8601String(),
        ])->all();

        $last = $rows->last();
        $next = $last ? Collaboration::encodeCursor((string) $last->created_at, (string) $last->id)
            : (string) $r->query('cursor', '');

        // الطرفُ الآخرُ يكتب الآن؟ (عابرٌ) — الاسمُ إن كان في النافذة
        $typing = in_array((string) $other->id, Typing::current($key, (string) $me->id), true)
            ? [(string) $other->name] : [];

        return $this->ok(['events' => $events, 'cursor' => $next, 'typing' => $typing]);
    }

    /** `POST dm/threads/{user}/typing` — نبضةُ الكتابةِ في خيطٍ · المفتاحُ من auth (F8) */
    public function dmTyping(Request $r, string $user): Response
    {
        $this->tagMobile($r);
        $me = auth()->user();

        $other = User::whereNull('deleted_at')->find($user);
        if (! $other || ! DmService::reachable($me, $other) || (string) $other->id === (string) $me->id) {
            return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'لا محادثة بهذا المعرّف');
        }
        Typing::ping(DmMessage::threadKey((string) $me->id, (string) $other->id), (string) $me->id);

        return $this->ok(['ok' => true]);
    }

    /**
     * `GET presence?users=a,b,c` — الحضورُ الخشنُ لمن أبلغهم (نطاقاً) — لا استقصاءَ حضورٍ
     * لمن ليس في متناولي. تواصلٌ لا مراقبة.
     */
    public function presence(Request $r): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;
        $me = auth()->user();

        $ids = collect(explode(',', (string) $r->query('users', '')))
            ->map(fn ($x) => trim($x))->filter()->unique()->take(100)->values();

        // لا أستقصي حضورَ من لا أبلغه — أرشّحُ بالوصولِ (dmReachable) أولاً
        $reachable = $ids->isEmpty() ? collect() : User::whereNull('deleted_at')->whereIn('id', $ids)->get()
            ->filter(fn ($u) => (string) $u->id !== (string) $me->id && DmController::dmReachable($u, $me))
            ->pluck('id')->map('strval')->values();

        $states = Presence::for($reachable->all());
        $out = [];
        foreach ($reachable as $uid) {
            $out[] = ['user_id' => (string) $uid, 'presence' => $states[$uid] ?? Presence::OFFLINE];
        }

        return $this->ok(['presence' => $out]);
    }

    /**
     * `POST comments/{id}/react` — بدّلُ تفاعلي على تعليقٍ أراه (`guardTarget` · «يرى=يتفاعل»).
     * يعيد الحالةَ الجديدةَ للرمز (مُفعّل؟ والعدد).
     */
    public function commentReact(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        $c = Comment::find($id);
        if (! $c) return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'غير موجود');
        CommentService::guardTarget(auth()->user(), (string) $c->module, $c->record_id);

        $emoji = hub_str($r->input('emoji'));
        if (! in_array($emoji, CommentController::REACTIONS, true)) {
            return Api::error(Api::VALIDATION_FAILED, 422, 'تفاعل غير معروف');
        }

        $on = $this->toggleReaction(['comment_id' => $c->id], $emoji);
        if ($on && $c->user_id !== auth()->id()) {
            hub_notify($c->user_id, 'react',
                $emoji . ' تفاعل ' . auth()->user()->name . ' مع ' . ($c->module === 'feed' ? 'منشورك' : 'تعليقك') . ': ' . Str::limit(trim((string) $c->body), 50),
                $c->module, $c->record_id);
        }

        return $this->ok(['comment_id' => (string) $c->id, 'emoji' => $emoji, 'mine' => $on,
            'count' => (int) DB::table('reactions')->where('comment_id', $c->id)->where('emoji', $emoji)->count()]);
    }

    /**
     * `POST dm/messages/{id}/react` — بدّلُ تفاعلي على رسالتي (طرفاً فيها · لا محذوفة).
     */
    public function dmReact(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        $m = DmMessage::find($id);
        if (! $m) return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'غير موجود');
        if (! in_array((string) auth()->id(), [(string) $m->from_id, (string) $m->to_id], true)) {
            return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'لا شأن لك بهذه المحادثة');
        }
        if ($m->deleted_at !== null) return Api::error(Api::BUSINESS_RULE_VIOLATION, 422, 'لا تفاعلَ على رسالةٍ محذوفة');
        if (! hub_has_col('reactions', 'dm_message_id')) {
            return Api::error(Api::INTEGRATION_UNAVAILABLE, 422, 'تفاعلاتُ الرسائلِ تحتاج ترحيلَ قاعدةِ البيانات');
        }

        $emoji = hub_str($r->input('emoji'));
        if (! in_array($emoji, CommentController::REACTIONS, true)) {
            return Api::error(Api::VALIDATION_FAILED, 422, 'تفاعل غير معروف');
        }

        $on = $this->toggleReaction(['dm_message_id' => $m->id, 'comment_id' => null], $emoji);
        if ($on && (string) $m->from_id !== (string) auth()->id()) {
            hub_notify($m->from_id, 'react',
                $emoji . ' تفاعل ' . auth()->user()->name . ' مع رسالتك: ' . Str::limit(trim((string) $m->body), 50),
                null, $m->id);
        }

        return $this->ok(['dm_message_id' => (string) $m->id, 'emoji' => $emoji, 'mine' => $on,
            'count' => (int) DB::table('reactions')->where('dm_message_id', $m->id)->where('emoji', $emoji)->count()]);
    }

    /**
     * `GET saved` — محفوظاتي، **تُعادُ تخويلاً عند كلِّ فتح** (`guardTarget` لكلِّ صفٍّ):
     * ما لم يعد يُرى يُعلَّم `available=false` ولا يُكشَف جسمُه — لا تسريبَ عبر محفوظةٍ قديمة.
     */
    public function saved(Request $r): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;
        $me = auth()->user();

        $rows = SavedMessage::where('user_id', $me->id)->orderByDesc('created_at')->limit(200)->get()
            ->map(fn (SavedMessage $s) => $this->savedShape($me, $s))->values()->all();

        return $this->ok(['saved' => $rows]);
    }

    /* ────────── مساعِداتٌ داخلية ────────── */

    /** بدّلُ تفاعلٍ (toggle) بمفتاحِ الصفِّ + الرمزِ — يعيد true إن أصبح مُفعّلاً */
    private function toggleReaction(array $key, string $emoji): bool
    {
        $q = DB::table('reactions')->where($key)->where('user_id', auth()->id())->where('emoji', $emoji);
        if ($q->exists()) {
            $q->delete();

            return false;
        }
        try {
            DB::table('reactions')->insert($key + [
                'id' => (string) Str::uuid(), 'user_id' => auth()->id(), 'emoji' => $emoji, 'created_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // ضغطتان متزامنتان — القيدُ الفريد حسمها
        }

        return true;
    }

    /** بطاقةُ محفوظةٍ مُعادةُ التخويل — لا تكشف جسمَ ما لم يعد يُرى */
    private function savedShape(User $me, SavedMessage $s): array
    {
        $base = ['id' => (string) $s->id, 'type' => (string) $s->target_type,
            'available' => false, 'title' => null, 'author' => null,
            'note' => $s->note !== null ? (string) $s->note : null,
            'saved_at' => optional($s->created_at)->toIso8601String()];

        try {
            if ($s->target_type === 'comment') {
                $c = Comment::find($s->target_id);
                if (! $c) return $base;
                CommentService::guardTarget($me, (string) $c->module, $c->record_id);   // يُجهض إن خفي

                return array_merge($base, ['available' => true,
                    'title' => Str::limit(trim((string) $c->body), 90), 'author' => optional($c->user)->name]);
            }
            $m = DmMessage::find($s->target_id);
            if (! $m || ! in_array((string) $me->id, [(string) $m->from_id, (string) $m->to_id], true)) return $base;

            return array_merge($base, ['available' => $m->deleted_at === null,
                'title' => $m->deleted_at === null ? Str::limit(trim((string) $m->body), 90) : 'حُذفت رسالة',
                'author' => optional(User::find($m->from_id))->name]);
        } catch (\Throwable $e) {
            return $base;   // لم يعد يُرى — يبقى الصفُّ كي يُزيله صاحبُه، بلا كشفٍ
        }
    }

    /** غلافُ نجاحٍ موحَّد: `data` + `request_id` */
    private function ok(array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'request_id' => Api::requestId()], 200);
    }

    /** وسمُ مصدرِ الطلب `mobile` (وسمٌ لا تخويل) */
    private function tagMobile(Request $r): void
    {
        $r->attributes->set('request_source', 'mobile');
    }
}
