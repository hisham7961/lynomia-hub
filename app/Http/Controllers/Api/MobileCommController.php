<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Web\CommentController;
use App\Http\Controllers\Web\ConversationController;
use App\Http\Controllers\Web\DmController;
use App\Models\Comment;
use App\Models\HubNotification;
use App\Models\User;
use App\Support\Api;
use App\Support\CommentService;
use App\Support\DmService;
use App\Support\NotificationLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * **اتصالُ الجوال (إشعارات · تعليقات · DM)** — Mobile Readiness · الطور E ·
 * E.1/E.2/E.3/E.4. مبنيٌّ بالكامل على سككٍ مشتركة — لا محرّكٌ ثانٍ:
 *
 *  • **الإشعارات (E.1/E.2):** `HubNotification` منطَّقاً بـ`user_id = auth()->id()`
 *    **وحدَه** (هويّةٌ خاصّةٌ صارمة — لا طابور غيري)، ترقيمٌ **بمؤشّرٍ على
 *    `created_at,id`** (لا `updated_at` — `$timestamps=false`, حذفٌ صلبٌ · INVENTORY
 *    §9)، وقراءةٌ/قراءةُ الكلّ تعيدان منطقَ `NotificationController` (go/readAll).
 *    الوجهةُ (E.2) من المحلِّلِ الموحّد `NotificationLink::target($n)` ⇒
 *    `{module,id,action}` قانونيّةً لا رابطَ ويب.
 *  • **التعليقات (E.3):** `CommentService::guardTarget($u,$module,$id)` (نقطةُ
 *    التخويلِ الوحيدة · F2) للقراءة، وكتابةُ القناةِ عبر `guardConversation('post')`
 *    (نظيرُ الويبِ حرفاً)، ثم `CommentService::create(...)` (سكّةٌ تعيد الموديل) —
 *    نطاق/رد/منشن بلا تغيير. Idempotency على النشر (مالكُ الجوال · F1).
 *  • **DM (E.4):** خصوصيّةُ الطرفَين (Critic F8 · «لا A تسأل عن B↔C»): المسارُ يأخذ
 *    **معرّفَ الطرفِ الآخر** لا خيطاً، ويُعاد بناءُ `thread_key` خادميّاً من
 *    `auth()->id()`+الطرف (`DmService::thread/markThreadRead/threadRows`
 *    و`DmMessage::threadKey`، مع بوّابةِ `DmService::reachable` وحارسِ
 *    `in_array(auth,[from,to])` الضِّمنيّ في المفتاح). لا يُقبَل `thread_key`/
 *    conversation من العميلِ قط. الإرسالُ عبر `DmService::send` + Idempotency (F1).
 *
 * **يرث `V1Controller`** (نظيرُ `MobileWorkController`/`MobileResourceController`)
 * لِيَرِثَ آلةَ الـIdempotency على **مالكِ الجوال** (`Idempotency::owner` ⇒
 * `mobile_session->id` · F1) — يستعملها نشرُ التعليقِ وإرسالُ الرسالة. لا مسارَ
 * يوجّه لطرائق CRUD الموروثة (الأسماءُ الحرفيّةُ وحدَها مُوجَّهة).
 *
 * كلُّ ردٍّ بغلاف `Api::*`؛ الهويّةُ من الجلسة وحدَها (`auth()->user()` الذي أرسته
 * `MobileSessionAuth`) — لا يُوثَق بأيِّ هويّةٍ يرسلها العميل. `mobile.context`
 * يضيّق العرضَ (لا يوسّع)، ولا يُطبَّق تضييقُه على قراءاتٍ منطَّقةٍ بالهويّة/العضويّة.
 */
class MobileCommController extends V1Controller
{
    // ═══════════════════ E.1/E.2 · الإشعارات (هويّةٌ خاصّةٌ فقط) ═══════════════════

    /**
     * `GET notifications` — قائمةُ إشعاراتي (مؤشّرٌ على `created_at,id`) · E.1.
     *
     * **هويّةٌ خاصّةٌ صارمة:** `user_id = auth()->id()` وحدَه — لا يبلغ المُنادي إشعارَ
     * أحدٍ سواه أبداً. `?unread=1` يقصر على غير المقروء. الترقيمُ بمؤشّرٍ حتميٍّ على
     * `(created_at DESC, id DESC)` (لا `updated_at` — النموذجُ بلا طوابعَ وحذفُه صلبٌ).
     * كلُّ عنصرٍ يحمل وجهتَه القانونيّة `{module,id,action}` (E.2) — لا رابطَ ويبٍ.
     */
    public function notifications(Request $r): Response
    {
        $this->tagMobile($r);
        $uid = (string) auth()->id();
        $per = min(50, max(1, (int) $r->query('per', 25)));

        $q = HubNotification::where('user_id', $uid);
        if ($r->boolean('unread')) $q->where('read', false);
        $q->orderByDesc('created_at')->orderByDesc('id');

        // مؤشّرُ keyset على (created_at,id): «أقدمُ من المؤشّر» — يمنع تكرارَ صفٍّ
        // وإسقاطَ آخر حين تتساوى الطوابعُ (كُتّابٌ دفعيّون في الثانية نفسها).
        if ($cur = $this->decodeCursor((string) $r->query('cursor', ''))) {
            [$c, $i] = $cur;
            $q->where(fn ($w) => $w->where('created_at', '<', $c)
                ->orWhere(fn ($w2) => $w2->where('created_at', $c)->where('id', '<', $i)));
        }

        $rows = $q->limit($per + 1)->get();          // +١ لكشفِ «هل ثمّة مزيد»
        $more = $rows->count() > $per;
        $rows = $rows->take($per);

        $next = null;
        if ($more && ($last = $rows->last())) {
            $next = $this->encodeCursor(
                optional($last->created_at)->toDateTimeString() ?? '', (string) $last->id);
        }

        return $this->ok([
            'notifications' => $rows->map(fn ($n) => $this->notificationShape($n))->values()->all(),
            'unread'        => $this->unread($uid),
            'cursor'        => ['next' => $next, 'has_more' => $more, 'per' => $per],
        ]);
    }

    /** `GET notifications/unread-count` — عدُّ غيرِ المقروءِ لي وحدي · E.1 */
    public function unreadCount(Request $r): Response
    {
        $this->tagMobile($r);

        return $this->ok(['unread' => $this->unread((string) auth()->id())]);
    }

    /**
     * `POST notifications/{id}/read` — تعليمُ إشعاري مقروءاً (منطقُ `NotificationController::go`
     * بلا إعادةِ توجيه) · E.1. **إشعاري وحدَه** (`user_id = auth`) — لا يمسّ أحدٌ إشعارَ غيره.
     */
    public function markRead(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        $uid = (string) auth()->id();

        $n = HubNotification::where('user_id', $uid)->find($id);
        if (! $n) return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'الإشعار غير موجود أو ليس لك');
        if (! $n->read) $n->forceFill(['read' => true])->save();

        return $this->ok([
            'id'     => (string) $n->id,
            'read'   => true,
            'unread' => $this->unread($uid),
            'target' => NotificationLink::target($n),
        ]);
    }

    /** `POST notifications/read-all` — تعليمُ كلِّ إشعاراتي مقروءةً (منطقُ `readAll`) · E.1 */
    public function markAllRead(Request $r): Response
    {
        $this->tagMobile($r);
        $uid = (string) auth()->id();

        $marked = HubNotification::where('user_id', $uid)->where('read', false)->update(['read' => true]);

        return $this->ok(['marked' => (int) $marked, 'unread' => 0]);
    }

    /**
     * `GET notifications/{id}/target` — الوجهةُ القانونيّة `{module,id,action}` (E.2)
     * لِإشعاري وحدَه (لا IDOR). قراءةٌ خالصةٌ **لا تختم القراءة** (لذلك مسارٌ آخر).
     */
    public function notificationTarget(Request $r, string $id): Response
    {
        $this->tagMobile($r);

        $n = HubNotification::where('user_id', (string) auth()->id())->find($id);
        if (! $n) return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'الإشعار غير موجود أو ليس لك');

        return $this->ok([
            'id'        => (string) $n->id,
            'target'    => NotificationLink::target($n),   // {module,id,action} أو null (يعود للقائمة)
            'module'    => $n->module !== null ? (string) $n->module : null,
            'record_id' => $n->record_id !== null ? (string) $n->record_id : null,
        ]);
    }

    // ═══════════════════ E.3 · التعليقات (reuse guardTarget · F2) ═══════════════════

    /**
     * `GET comments?module=&record=` — خيطُ تعليقاتِ سجلٍّ يراه المستخدم · E.3.
     *
     * التخويلُ عبر `CommentService::guardTarget` (نقطةُ التخويلِ الوحيدة، دون تغيير ·
     * F2): `channel` ⇒ عضويّة، وحدةٌ مسجَّلةٌ ⇒ `hub_can(v)` + `hub_scope(...)->findOrFail`
     * (سجلٌّ خارج النطاق = ٤٠٤). القناةُ العامّة (`feed`) تُنطَّق بشركة القارئ بالرِّكازِ
     * نفسِه الذي يطبّقه الويبُ (`hub_company_ids` · WP-A.5) — لا يبلغ منشورَ شركةٍ أخرى.
     * ترتيبٌ حتميّ (المثبَّتُ أولاً ثم `created_at,id`). قراءةٌ خالصةٌ لا تكتب سجلَّ قراءة.
     */
    public function comments(Request $r): Response
    {
        $this->tagMobile($r);
        $u = auth()->user();

        $module = hub_str($r->query('module'));
        $record = hub_str($r->query('record'));
        if ($record === '') $record = hub_str($r->query('record_id'));   // مرادفٌ متساهل
        if ($module === '') return Api::error(Api::VALIDATION_FAILED, 422, 'المعامل module مطلوب');

        // نقطةُ التخويلِ الوحيدة — تُلقي 403/404 التي يترجمها Api::render آليّاً
        [$module, $recordId] = CommentService::guardTarget($u, $module, $record !== '' ? $record : null);

        $q = Comment::where('module', $module)->whereNull('parent_id')->with('user', 'replies.user');
        if ($module === 'feed') {
            // عزلُ الشركةِ نفسُه الذي يطبّقه الويبُ على القناة — بالرِّكازِ نفسِه لا محرّكاً ثانياً
            if (hub_has_col('comments', 'company_id') && ($cids = hub_company_ids($u)) !== null) {
                $q->where(fn ($w) => $w->whereIn('company_id', $cids)->orWhereNull('company_id'));
            }
            $q->orderByDesc('pinned')->orderByDesc('created_at')->orderByDesc('id');
        } else {
            $q->where('record_id', $recordId)->orderByDesc('pinned')->orderBy('created_at')->orderBy('id');
        }
        $items = $q->get();

        // تفاعلاتُ الخيطِ كلِّه باستعلامٍ واحد (نظيرُ عرضِ الويب) — قراءةٌ لا كتابة
        $reactions = CommentController::reactionsFor($items);

        return $this->ok([
            'module'   => $module,
            'record'   => $recordId !== null ? (string) $recordId : null,
            'comments' => $items->map(fn ($c) => $this->commentShape($c, $reactions, $u))->values()->all(),
        ]);
    }

    /**
     * `POST comments` — نشرُ تعليقٍ (guardTarget/guardConversation + assertReplyIntegrity
     * + `CommentService::create` + Idempotency) · E.3.
     *
     * التخويلُ يطابق كتابةَ الويب حرفاً: القناةُ تتطلّب `guardConversation('post')`
     * (الضيفُ يقرأ ولا يكتب)، والوحدةُ المسجَّلةُ تكفيها رؤيتُها (`guardTarget` · «يرى =
     * يعلّق»). الردُّ يلتصق بخيطه (`assertReplyIntegrity`) **قبل** رفعِ أيّ مرفق. المرفقُ
     * يمرّ من رِكازِ التعليقِ نفسِه (`store('hub','local')` · لا base64). النشرُ جانبٌ
     * قابلٌ لإعادة المحاولة ⇒ `Idempotency-Key` (مالكُ الجوال · F1).
     */
    public function postComment(Request $r): Response
    {
        $this->tagMobile($r);
        $u = auth()->user();

        $data = $r->validate([
            'module'    => ['required', 'string', 'max:60'],
            'record'    => ['nullable', 'string'],
            'record_id' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'exists:comments,id'],
            'body'      => ['required', 'string', 'max:4000'],
            'att'       => ['nullable', 'file', 'max:' . hub_upload_cap()['kb']],
            'mention'   => ['nullable', 'array'],
            'internal'  => ['nullable', 'boolean'],
        ], [], ['body' => 'نص التعليق', 'att' => 'المرفق']);

        $record = ($data['record'] ?? '') !== '' ? (string) $data['record'] : (string) ($data['record_id'] ?? '');
        $reqConv = trim(hub_str($r->input('conversation_id')));

        // تخويلُ الكتابة — يطابق فرعَي `CommentController::store` (F2)
        $conversationId = null;
        if (($data['module'] ?? '') === 'channel' || $reqConv !== '') {
            $convId = $reqConv !== '' ? $reqConv : $record;
            [$conv] = ConversationController::guardConversation($convId, 'post');   // الضيفُ لا يكتب (403)
            [$module, $recordId] = ['channel', (string) $conv->id];
            $conversationId = (string) $conv->id;
        } else {
            [$module, $recordId] = CommentService::guardTarget($u, $data['module'], $record !== '' ? $record : null);
        }

        // الردُّ يلتصق بخيطه — قبل أيّ أثرٍ جانبيّ (رفعُ مرفق)، بالحارسِ المشترك
        CommentService::assertReplyIntegrity($data['parent_id'] ?? null, $module, $recordId);

        // Idempotency (مالكُ الجوال · F1): يُحجَز قبل رفعِ المرفقِ والإنشاء، فإعادةُ المحاولة
        // بالمفتاح نفسِه لا تُنشئ تعليقَين ولا تُعيد رفعَ المرفق.
        $gate = $this->idempotentBegin($r);
        if ($gate instanceof Response) return $gate;

        try {
            $attPath = $r->hasFile('att') ? $r->file('att')->store('hub', 'local') : null;
            $c = CommentService::create($u, $module, $recordId, $data['body'], [
                'parent_id'       => $data['parent_id'] ?? null,
                'att'             => $attPath,
                'internal'        => $r->boolean('internal'),
                'mention'         => (array) $r->input('mention', []),
                'conversation_id' => $conversationId,
            ]);
            $resp = $this->ok(['comment' => $this->commentShape($c, [], $u)]);
            $this->idempotentFinish($r, $resp);

            return $resp;
        } catch (\Throwable $e) {
            if ($gate === true) $this->idempotentRelease($r);
            throw $e;
        }
    }

    // ═══════════════════ E.4 · DM (خصوصيّةُ الطرفَين · F8) ═══════════════════

    /**
     * `GET dm/threads` — خيوطي أنا (طرفاً فيها) لا سواها · E.4.
     *
     * `DmService::threadRows(auth id)` (السكّةُ المشتركةُ نفسُها التي يبنيها صندوقُ
     * الويب · F2): أحدثُ الخيوطِ **وكلُّ غيرِ مقروء**، منطَّقةً بشركتي (WP-A.5). لا
     * يبلغ المُنادي إلا خيوطاً هو طرفٌ فيها — لا خيطَ ثالثَين.
     */
    public function dmThreads(Request $r): Response
    {
        $this->tagMobile($r);
        $me = (string) auth()->id();

        $rows = DmService::threadRows($me);
        $names = User::whereIn('id', $rows->pluck('other')->filter()->unique()->values()->all())
            ->pluck('name', 'id');

        $threads = $rows->map(function ($t) use ($me, $names) {
            $last = $t['last'];

            return [
                'user'   => ['id' => (string) $t['other'], 'name' => (string) ($names[$t['other']] ?? '')],
                'unread' => (int) $t['unread'],
                'last'   => [
                    'id'         => (string) $last->id,
                    'mine'       => (string) $last->from_id === $me,
                    'excerpt'    => Str::limit(trim((string) $last->body), 80),
                    'read'       => $last->read_at !== null,
                    'created_at' => optional($last->created_at)->toIso8601String(),
                ],
            ];
        })->values()->all();

        return $this->ok(['threads' => $threads, 'unread_total' => DmController::unreadCount()]);
    }

    /**
     * `GET dm/threads/{user}/messages` — خيطي مع الطرفِ الآخر · E.4 · **F8**.
     *
     * `{user}` = معرّفُ الطرفِ الآخر لا خيطاً. المفتاحُ يُبنى خادميّاً من
     * `auth()->id()`+`{user}` داخل `DmService::thread` — فلا يبلغ المُنادي خيطَ ثالثَين
     * ولو خمّن معرّفَهما. بوّابةُ `DmService::reachable` (نطاقُ الشركات) تُرجِع ٤٠٤ لا
     * كشفَ وجودٍ خارجَ العزل. قراءةٌ خالصةٌ لا تختم القراءة (لذلك مسارُ `read` مستقلّ).
     */
    public function dmMessages(Request $r, string $user): Response
    {
        $this->tagMobile($r);
        $me = auth()->user();

        if ($resp = $this->resolveOther($me, $user, $other)) return $resp;

        // F8 — المفتاحُ من auth+الطرف خادميّاً (DmService)، لا thread_key من العميل قط
        $msgs = DmService::thread((string) $me->id, (string) $other->id);

        return $this->ok([
            'user'     => ['id' => (string) $other->id, 'name' => (string) $other->name],
            'messages' => $msgs->map(fn ($m) => $this->dmMessageShape($m, (string) $me->id))->all(),
        ]);
    }

    /**
     * `POST dm/threads/{user}/send` — إرسالٌ للطرفِ الآخر (DmService::send + Idempotency)
     * · E.4 · **F8**.
     *
     * يطابق حرّاسَ `DmController::send`: الطرفُ موجودٌ وبالغٌ ونشط وليس النفس (خارجُ
     * النطاق يُطوى في «لا حساب» — لا كشفَ وجود). المفتاحُ من `auth()->id()`+`{user}`
     * خادميّاً (`DmService::send`) لا من العميل. الإرسالُ قابلٌ لإعادة المحاولة ⇒
     * `Idempotency-Key` (مالكُ الجوال · F1): إعادةٌ بالمفتاح نفسِه لا تُرسل رسالتَين.
     */
    public function dmSend(Request $r, string $user): Response
    {
        $this->tagMobile($r);
        $me = auth()->user();

        $other = User::whereNull('deleted_at')->find($user);
        // خارجُ النطاق/غيرُ موجودٍ ⇒ ٤٠٤ بنفس الرسالة (لا تمييزَ عن «لا حساب» — لا تعداد)
        if (! $other || ! DmService::reachable($me, $other)) {
            return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'لا حساب بهذا المعرّف — اختر زميلاً من القائمة');
        }
        if ((string) $other->id === (string) $me->id) {
            return Api::error(Api::VALIDATION_FAILED, 422, 'لا محادثة مع النفس');
        }
        if (($other->status ?? '') !== 'نشط') {
            return Api::error(Api::BUSINESS_RULE_VIOLATION, 422,
                'حساب «' . $other->name . '» موقوف — رسالتُك لن يفتحها أحد');
        }

        $r->merge(['body' => trim(hub_str($r->input('body')))]);   // مسافاتٌ بيضٌ ليست رسالة
        $data = $r->validate([
            'body' => ['required', 'string', 'max:4000'],
            'att'  => ['nullable', 'file', 'max:' . hub_upload_cap()['kb']],
        ], [], ['body' => 'نص الرسالة', 'att' => 'المرفق']);

        $gate = $this->idempotentBegin($r);
        if ($gate instanceof Response) return $gate;

        try {
            $attPath = $r->hasFile('att') ? $r->file('att')->store('hub', 'local') : null;
            $msg = DmService::send($me, $other, $data['body'], $attPath);   // F8 · المفتاحُ خادميّ
            $resp = $this->ok(['message' => $this->dmMessageShape($msg, (string) $me->id)]);
            $this->idempotentFinish($r, $resp);

            return $resp;
        } catch (\Throwable $e) {
            if ($gate === true) $this->idempotentRelease($r);
            throw $e;
        }
    }

    /**
     * `POST dm/threads/{user}/read` — ختمُ قراءةِ خيطي مع الطرفِ الآخر · E.4 · **F8**.
     * المفتاحُ من `auth()->id()`+`{user}` خادميّاً (`DmService::markThreadRead`). يختم
     * الواردَ غيرَ المقروءِ إليّ وحدَه — لا يمسّ رسائلَ غيري.
     */
    public function dmMarkRead(Request $r, string $user): Response
    {
        $this->tagMobile($r);
        $me = auth()->user();

        if ($resp = $this->resolveOther($me, $user, $other)) return $resp;

        $marked = DmService::markThreadRead((string) $me->id, (string) $other->id);   // F8

        return $this->ok([
            'user'         => ['id' => (string) $other->id],
            'marked'       => (int) $marked,
            'unread_total' => DmController::unreadCount(),
        ]);
    }

    // ═══════════════════════════ مساعِداتٌ داخلية ═══════════════════════════

    /**
     * يحلّ الطرفَ الآخر من معرّفِ المسار ويطبّق حرّاسَ الخصوصيّة (F8): موجودٌ + بالغٌ
     * (نطاقُ الشركات) + ليس النفس — وإلا يعيد ردَّ خطأٍ مُغلَّفاً (٤٠٤/٤٠٤). يضع الطرفَ
     * في `$other` بالإحالة. **لا مفتاحَ خيطٍ يُقبَل من العميل** — المُنادي يمرّر معرّفَ
     * الطرفِ فقط، والخيطُ يُبنى خادميّاً لاحقاً من `auth()->id()`.
     */
    private function resolveOther(User $me, string $user, ?User &$other): ?Response
    {
        $other = User::whereNull('deleted_at')->find($user);
        if (! $other || ! DmService::reachable($me, $other) || (string) $other->id === (string) $me->id) {
            // ٤٠٤ لا كشفَ وجودٍ خارجَ العزل (نظيرُ `abort_unless(dmReachable, 404)` في الويب)
            return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'لا محادثة بهذا المعرّف');
        }

        return null;
    }

    /** عدُّ غيرِ المقروءِ لهويّةٍ بعينها — هي وحدَها (لا نطاقَ سواها) */
    private function unread(string $uid): int
    {
        return (int) HubNotification::where('user_id', $uid)->where('read', false)->count();
    }

    /** بطاقةُ إشعارٍ لصاحبه: النصُّ والنوعُ والوجهةُ القانونيّة (E.2) — لا نطاقَ غيري */
    private function notificationShape(HubNotification $n): array
    {
        return [
            'id'         => (string) $n->id,
            'kind'       => (string) $n->kind,
            'text'       => $n->text !== null ? (string) $n->text : null,
            'read'       => (bool) $n->read,
            'module'     => $n->module !== null ? (string) $n->module : null,
            'record_id'  => $n->record_id !== null ? (string) $n->record_id : null,
            'target'     => NotificationLink::target($n),   // {module,id,action} أو null
            'created_at' => optional($n->created_at)->toIso8601String(),
        ];
    }

    /**
     * بطاقةُ تعليقٍ آمنة (حقولٌ صريحةٌ — لا تسريبَ عمود): الكاتبُ والنصُّ والمنشنُ والتثبيتُ
     * والحلُّ وحضورُ مرفقٍ + التفاعلاتُ (عدداً) + ردودُه المشكَّلة. المرفقُ يُكشَف حضوراً
     * لا مساراً (تنزيلُه من رِكازِ الملفّاتِ في الطور F).
     *
     * @param array $reactions مخرَجُ `CommentController::reactionsFor` أو []
     */
    private function commentShape(Comment $c, array $reactions, ?User $me): array
    {
        $shape = [
            'id'          => (string) $c->id,
            'parent_id'   => $c->parent_id !== null ? (string) $c->parent_id : null,
            'user'        => $c->user
                ? ['id' => (string) $c->user->id, 'name' => (string) $c->user->name]
                : ['id' => (string) $c->user_id, 'name' => ''],
            'body'        => (string) $c->body,
            'mentions'    => array_values(array_map('strval', (array) $c->mentions)),
            'internal'    => (bool) $c->internal,
            'pinned'      => (bool) $c->pinned,
            'resolved'    => $c->resolved_at !== null,
            'has_attachment' => ! empty($c->att),
            'reactions'   => $this->reactionSummary($reactions[$c->id] ?? [], $me),
            'created_at'  => optional($c->created_at)->toIso8601String(),
        ];

        if ($c->relationLoaded('replies')) {
            $shape['replies'] = $c->replies->map(fn ($rep) => $this->commentShape($rep, $reactions, $me))->values()->all();
        }

        return $shape;
    }

    /** ملخّصُ تفاعلاتِ تعليقٍ: [emoji => {count, mine(bool)}] من مخرَجِ reactionsFor */
    private function reactionSummary(array $byEmoji, ?User $me): array
    {
        $mine = $me ? (string) $me->id : null;
        $out = [];
        foreach ($byEmoji as $emoji => $people) {
            $ids = array_map(fn ($p) => (string) ($p['id'] ?? ''), (array) $people);
            $out[] = [
                'emoji' => (string) $emoji,
                'count' => count($ids),
                'mine'  => $mine !== null && in_array($mine, $ids, true),
            ];
        }

        return $out;
    }

    /**
     * بطاقةُ رسالةِ DM لطرفٍ فيها: الجسمُ يُعرَض للطرفِ (محادثتُه هو — لا تسريب)، والمحذوفةُ
     * تُخفي جسمَها وتُعلَّم `deleted` (نظيرُ «حُذفت رسالة» في الويب). المرفقُ حضوراً لا مساراً.
     */
    private function dmMessageShape($m, string $me): array
    {
        $deleted = isset($m->deleted_at) && $m->deleted_at !== null;

        return [
            'id'             => (string) $m->id,
            'from_id'        => (string) $m->from_id,
            'to_id'          => (string) $m->to_id,
            'mine'           => (string) $m->from_id === $me,
            'body'           => $deleted ? null : (string) $m->body,
            'deleted'        => $deleted,
            'has_attachment' => ! $deleted && ! empty($m->att),
            'read'           => $m->read_at !== null,
            'created_at'     => optional($m->created_at)->toIso8601String(),
        ];
    }

    /**
     * مؤشّرُ keyset مُعمّى (base64 من `created_at|id`) — يبني عليه الفهرسُ شرطَ «أقدمُ
     * من المؤشّر». غيرُ مُوقَّعٍ لأنّه لا يحمل تخويلاً (الاستعلامُ منطَّقٌ بـ`user_id`
     * أصلاً): مؤشّرٌ مُختلَقٌ يزيح النافذةَ داخلَ إشعاراتي وحدَها لا غير.
     */
    private function encodeCursor(string $createdAt, string $id): string
    {
        return rtrim(strtr(base64_encode($createdAt . '|' . $id), '+/', '-_'), '=');
    }

    /** فكُّ المؤشّر ⇒ [created_at, id] أو null (فارغٌ/مشوَّه ⇒ من البداية) */
    private function decodeCursor(string $cursor): ?array
    {
        if ($cursor === '') return null;
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false || strpos($raw, '|') === false) return null;
        [$c, $i] = explode('|', $raw, 2);
        if ($c === '' || $i === '') return null;

        return [$c, $i];
    }

    /** غلافُ نجاحٍ موحَّد: `data` + `request_id` (X-API-Version من `MobileSessionAuth`) */
    private function ok(array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'request_id' => Api::requestId()], 200);
    }

    /** وسمُ مصدرِ الطلب `mobile` — يقرؤه `hub_audit` عبر `Api::requestSource` (وسمٌ لا تخويل) */
    private function tagMobile(Request $r): void
    {
        $r->attributes->set('request_source', 'mobile');
    }
}
