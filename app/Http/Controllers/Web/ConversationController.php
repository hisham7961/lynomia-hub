<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\User;
use App\Support\ChatCommands;
use App\Support\FlowRunner;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * **القنواتُ والفضاءات فوق Comment** (Work OS · الطور C · WP-C.1 · §3–5, §7).
 *
 * القناةُ حاويةٌ مطبَّعة (`conversations.kind=channel`)، ورسائلُها **تعليقاتٌ** تحمل
 * `conversation_id` — لا محرّكَ رسائلَ ثانٍ، ولا جدولَ تفاعلاتٍ ثانٍ: mentions/
 * reactions/read/pin/resolve/attachment/task-link تُعاد كما هي عبر `CommentController`
 * حين تكتسب الرسالةُ حاويتَها.
 *
 * **الحرسُ `guardConversation` على نمط `guardTarget` (CommentController:309):**
 * تُرى القناةُ وتُكتَب **فقط** بـ`عضويّةٍ فعّالة` (`conversation_members`) +
 * `نطاقِ الشركة/العميل` (دفاعٌ في العمق فوق العضويّة) + `صلاحيةِ الوحدةِ الهدف`
 * لخيوطِ السجلات. غيرُ العضو ٤٠٤ (لا نُثبت وجودَ ما لا يخصّه، نظيرُ
 * `findScoped()->findOrFail`). والعميلُ لا يبلغ هذه المساراتِ الداخليّةَ أصلاً —
 * `PortalGuard` فوقها كلِّها؛ قناةُ جمهورِه (`audience=client`) تصله عبر البوابة
 * (`ClientPortalController`، الطور B).
 *
 * **الأدوارُ عضويّةٌ لا RBAC ثانٍ:** owner/moderator/member/guest داخلَ الحاوية —
 * من *يقدر* يحسمه `hub_can`، ومن *عضوٌ هنا وبأيّ دور* يحسمه هذا الجدول.
 */
class ConversationController extends Controller
{
    /**
     * **الحارسُ القاطع** — نقطةُ الحسم الوحيدة لرؤية/كتابة/إدارةِ حاوية.
     *
     * ثابتٌ ليُستدعى من `CommentController@store` أيضاً (ردٌّ لا يُحقَن في قناةٍ لا
     * يراها القارئ) دون تكرارِ منطق. يُرجع `[Conversation, string $role]` أو يُجهض.
     *
     * @param string $op  v=رؤية/تفاعل · post=كتابة · manage=إدارةُ الأعضاء
     * @return array{0: Conversation, 1: string}
     */
    public static function guardConversation(string $id, string $op = 'v'): array
    {
        $user = auth()->user();
        abort_unless($user, 403);

        // ١) الحاويةُ موجودةٌ وحيّة (غيرُ مؤرشفةٍ ولا محذوفةٍ ناعماً)
        $conv = Conversation::whereNull('archived_at')->whereNull('deleted_at')->find($id);

        // ٢) عضويّةٌ فعّالة — غيرُ العضو لا يُثبت له وجودُها (٤٠٤ لا ٤٠٣)
        $role = $conv ? Conversation::roleOf((string) $conv->getKey(), (string) $user->getKey()) : null;
        abort_if($conv === null || $role === null, 404);

        // ٣) نطاقُ الشركة/العميل — دفاعٌ في العمق فوق العضويّة: عضويّةٌ خاطئةٌ لحاويةِ
        //    شركةٍ/عميلٍ خارجَ نطاق القارئ لا تُسرّب صفّاً (نظيرُ عزل hub_scope).
        if (($cids = hub_company_ids($user)) !== null && $conv->company_id !== null
            && ! in_array((string) $conv->company_id, $cids, true)) {
            abort(404);
        }
        if (($kids = hub_client_ids($user)) !== null && $conv->client_id !== null
            && ! in_array((string) $conv->client_id, $kids, true)) {
            abort(404);
        }

        // ٤) خيطُ سجلٍّ (context على وحدةٍ حقيقية): صلاحيةُ عرضِ تلك الوحدةِ على القارئ
        //    — يمتدّ شرطُ `guardTarget` نفسَه. القناةُ الحرّةُ (بلا module) لا تمرّ به.
        if ($conv->module && $conv->record_id && hub_mod($conv->module)) {
            abort_unless(hub_can($user, $conv->module, 'v'), 403);
        }

        // ٥) الكتابةُ/الإدارةُ تتطلبان دوراً كافياً — عضويّةُ الرؤيةِ لا تكفيهما
        if ($op === 'post') {
            abort_unless(Conversation::roleCanPost($role), 403, 'الضيفُ يقرأ القناةَ ولا يكتب فيها');
        } elseif ($op === 'manage') {
            abort_unless(Conversation::roleCanManage($role), 403, 'إدارةُ الأعضاء لأصحاب القناة ومشرفيها');
        }

        return [$conv, $role];
    }

    /* ────────── الفهرسُ والعرض ────────── */

    /** قنواتُ المستخدم — التي هو عضوٌ فيها، بترتيبٍ حتميّ */
    public function index()
    {
        $user = auth()->user();

        // معرّفاتُ حاوياتِه (العضويّةُ أساسُ العزل) — بترتيبٍ حتميّ
        $memberIds = ConversationMember::where('user_id', $user->getKey())
            ->orderBy('conversation_id')->pluck('conversation_id');

        $channels = Conversation::channels()->active()->whereNull('deleted_at')
            ->whereIn('id', $memberIds)
            // دفاعُ نطاقٍ فوق العضويّة (كالحارس): المقيَّدُ لا يرى قناةَ شركةٍ خارجَ نطاقه
            ->when(($cids = hub_company_ids($user)) !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereIn('company_id', $cids)->orWhereNull('company_id')))
            ->when(($kids = hub_client_ids($user)) !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereIn('client_id', $kids)->orWhereNull('client_id')))
            ->orderBy('title')->orderBy('id')
            ->get(['id', 'kind', 'title', 'audience', 'visibility', 'client_id', 'updated_at']);

        return view('conversations.index', ['channels' => $channels]);
    }

    /** عرضُ قناةٍ: رسائلُها (من comments عبر الحاوية) وأعضاؤها */
    public function show(string $id)
    {
        [$conv, $role] = self::guardConversation($id);

        // الرسائلُ من المحرّكِ الوحيد (comments) عبر علاقةِ الحاوية — ترتيبٌ حتميّ
        $messages = $conv->messages()->whereNull('parent_id')
            ->with('user', 'replies.user')->get();

        // إيصالُ قراءةِ صاحبِه: يمرّ عبر السكّة نفسها (read_by) لا read_at غيره
        (new CommentController)->markReadPublic($messages);

        $members = $conv->members()->with('user:id,name')
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'moderator' THEN 1 WHEN 'member' THEN 2 ELSE 3 END")
            ->orderBy('id')->get();

        return view('conversations.show', [
            'conv'      => $conv,
            'role'      => $role,
            'canPost'   => Conversation::roleCanPost($role),
            'canManage' => Conversation::roleCanManage($role),
            'messages'  => $messages,
            'members'   => $members,
            'users'     => CommentController::userNames(),
        ]);
    }

    /* ────────── إنشاءُ قناة ────────── */

    /** إنشاءُ قناةٍ + عضويّةِ مالكها، وإطلاقُ conversation.created */
    public function store(Request $r)
    {
        $user = auth()->user();
        abort_unless($user, 403);

        $data = $r->validate([
            'title'      => ['required', 'string', 'max:200'],
            'audience'   => ['nullable', 'string', Rule::in(Conversation::AUDIENCES)],
            'visibility' => ['nullable', 'string', Rule::in(Conversation::VISIBILITIES)],
            'client_id'  => ['nullable', 'string'],
            'project_id' => ['nullable', 'string'],
            // رسالةُ افتتاحٍ اختياريّة — نصٌّ أو أمرُ محادثة (WP-C.3)
            'body'       => ['nullable', 'string', 'max:4000'],
        ], [], ['title' => 'اسمُ القناة']);

        $audience = $data['audience'] ?? 'internal';

        // نطاقُ الشركة: تُوسَم القناةُ بشركةِ مُنشئها المقيَّد فينعزل عنها الغريب
        // (كتوسيمِ منشورِ القناةِ العامّة WP-A.5)؛ المُنشئُ غيرُ المقيَّد يتركها عامّة.
        $companyId = (($cids = hub_company_ids($user)) !== null && $cids) ? $cids[0] : null;

        // عميلُ القناة يُقيَّد بنطاق مُنشئه المقيَّد (لا يُوسَم بعميلٍ خارج نطاقه)
        $clientId = null;
        if (in_array($audience, ['client', 'both'], true)) {
            $clientId = ($v = trim(hub_str($r->input('client_id')))) !== '' ? $v : null;
            if ($clientId !== null && ($kids = hub_client_ids($user)) !== null
                && ! in_array($clientId, $kids, true)) {
                abort(403, 'عميلٌ خارجَ نطاقك');
            }
        }

        $projectId = ($v = trim(hub_str($r->input('project_id')))) !== '' ? $v : null;

        $conv = Conversation::create([
            'kind'       => 'channel',
            'title'      => mb_substr(trim($data['title']), 0, 200),
            'audience'   => $audience,
            'visibility' => $data['visibility'] ?? 'private',
            'company_id' => $companyId,
            'client_id'  => $clientId,
            'project_id' => $projectId,
            'created_by' => $user->getKey(),
        ]);

        // مُنشئُ القناةِ مالكُها — العضويّةُ المطبَّعة (لا read_by JSON)
        ConversationMember::create([
            'conversation_id' => $conv->id, 'user_id' => $user->getKey(),
            'role' => 'owner', 'source' => 'explicit', 'last_read_at' => now(),
        ]);

        // الحدثُ الدلاليّ المُعلَنُ سلفاً في config('hub.events') (الطور A) — يُطلَق هنا
        FlowRunner::fire('created', 'conversations', $conv);

        // أثرُ التدقيق — سلسلةُ الوصول هي سلسلةُ التدقيق (لا سجلَّ ثانٍ)
        hub_audit('channel.created', 'conversations', (string) $conv->id, $conv->title);

        // (WP-C.3 · §8) رسالةُ افتتاحٍ اختياريّة عند الإنشاء — أمرُ محادثة يُنفَّذ خدمةً
        // حقيقيّة، أو نصٌّ يُنشَر كأولِ رسالةٍ في القناة (المحرّكُ الوحيد: comments).
        // المُنشئُ مالكٌ فله الكتابةُ والأمرُ معاً — والحرسُ نفسُه: hub_can على الوحدةِ الهدف.
        $body = trim(hub_str($r->input('body')));
        if ($body !== '') {
            $ctx = ['module' => 'channel', 'record_id' => (string) $conv->id, 'conversation_id' => (string) $conv->id];
            if (($parsed = ChatCommands::parse($body)) !== null) {
                ChatCommands::dispatch($parsed, $user, $ctx);
            } else {
                ChatCommands::postMessage($user, $body, $ctx);
            }
        }

        return redirect()->route('conversations.show', $conv->id)->with('ok', 'أُنشئت القناة');
    }

    /* ────────── إدارةُ الأعضاء (owner/moderator) ────────── */

    /** إضافةُ عضوٍ — المشرفُ فأعلى؛ وتنصيبُ مالكٍ/مشرفٍ للمالك وحده */
    public function addMember(Request $r, string $id)
    {
        [$conv, $actorRole] = self::guardConversation($id, 'manage');

        $data = $r->validate([
            'user_id' => ['required', 'string'],
            'role'    => ['nullable', 'string', Rule::in(ConversationMember::ROLES)],
        ], [], ['user_id' => 'المستخدم', 'role' => 'الدور']);

        $target = User::whereNull('deleted_at')->find($data['user_id']);
        abort_unless($target, 422, 'لا مستخدمَ بهذا المعرّف');

        $role = $data['role'] ?? 'member';

        // تصعيدُ الهويّة — تنصيبُ مالكٍ أو مشرفٍ — للمالك وحده (لا المشرف)
        if (Conversation::roleRank($role) >= Conversation::roleRank('moderator')) {
            abort_unless($actorRole === 'owner', 403, 'تنصيبُ المشرفين والملّاك للمالك وحده');
        }

        // عضويّةٌ واحدةٌ لكلِّ ثنائيّ — إن كان عضواً فالتغييرُ عبر تعديل الدور لا الإضافة
        abort_if(
            ConversationMember::where('conversation_id', $conv->id)->where('user_id', $target->id)->exists(),
            422, 'المستخدمُ عضوٌ أصلاً — عدّل دورَه'
        );

        ConversationMember::create([
            'conversation_id' => $conv->id, 'user_id' => $target->id,
            'role' => $role, 'source' => 'explicit',
        ]);

        hub_audit('channel.member_added', 'conversations', (string) $conv->id,
            $target->name, ['after' => $role]);

        return back()->with('ok', 'أُضيف العضو');
    }

    /** تعديلُ دورِ عضوٍ — المشرفُ فأعلى؛ ولا يمسّ مَن يفوقه، والتصعيدُ للمالك وحده */
    public function setRole(Request $r, string $id)
    {
        [$conv, $actorRole] = self::guardConversation($id, 'manage');

        $data = $r->validate([
            'user_id' => ['required', 'string'],
            'role'    => ['required', 'string', Rule::in(ConversationMember::ROLES)],
        ], [], ['user_id' => 'المستخدم', 'role' => 'الدور']);

        $m = ConversationMember::where('conversation_id', $conv->id)
            ->where('user_id', $data['user_id'])->first();
        abort_unless($m, 404, 'العضوُ غيرُ موجودٍ في القناة');

        $newRole = $data['role'];
        $actorRank = Conversation::roleRank($actorRole);

        // المشرفُ لا يمسّ مَن يفوقه أو يساويه رتبةً (مالكٌ/مشرفٌ آخر)، ولا يصعّد إلى
        // رتبةِ إدارة — التصعيدُ/المساسُ بالإدارة للمالك وحده.
        if ($actorRole !== 'owner') {
            abort_unless(Conversation::roleRank($m->role) < $actorRank, 403, 'لا تمسّ مَن يفوقك أو يساويك');
            abort_unless(Conversation::roleRank($newRole) < Conversation::roleRank('moderator'), 403,
                'التصعيدُ إلى إدارةٍ للمالك وحده');
        }

        // لا تُخلى القناةُ من مالكها الأخير بخفضِ دورِه
        if ($m->role === 'owner' && $newRole !== 'owner' && $this->ownerCount($conv->id) <= 1) {
            abort(422, 'لا تُخلى القناةُ من مالكها الوحيد — نصّب مالكاً آخرَ أولاً');
        }

        $before = $m->role;
        $m->update(['role' => $newRole]);

        hub_audit('channel.member_role', 'conversations', (string) $conv->id,
            optional($m->user)->name, ['before' => $before, 'after' => $newRole]);

        return back()->with('ok', 'عُدّل الدور');
    }

    /** إزالةُ عضوٍ — المشرفُ فأعلى؛ لا يزيل مَن يفوقه، ولا يُخلي القناةَ من مالك */
    public function removeMember(Request $r, string $id)
    {
        [$conv, $actorRole] = self::guardConversation($id, 'manage');

        $data = $r->validate(['user_id' => ['required', 'string']], [], ['user_id' => 'المستخدم']);

        $m = ConversationMember::where('conversation_id', $conv->id)
            ->where('user_id', $data['user_id'])->first();
        abort_unless($m, 404, 'العضوُ غيرُ موجودٍ في القناة');

        // المشرفُ لا يزيل مالكاً/مشرفاً آخر — الإزالةُ لمن دونه رتبةً (المالكُ يزيل الكلّ)
        if ($actorRole !== 'owner') {
            abort_unless(Conversation::roleRank($m->role) < Conversation::roleRank($actorRole),
                403, 'لا تزيل مَن يفوقك أو يساويك');
        }

        // لا تُخلى القناةُ من مالكها الأخير
        if ($m->role === 'owner' && $this->ownerCount($conv->id) <= 1) {
            abort(422, 'لا تُخلى القناةُ من مالكها الوحيد — نصّب مالكاً آخرَ أولاً');
        }

        $name = optional($m->user)->name;
        $before = $m->role;
        $m->delete();

        hub_audit('channel.member_removed', 'conversations', (string) $conv->id, $name, ['before' => $before]);

        return back()->with('ok', 'أُزيل العضو');
    }

    /** عددُ مالكي الحاوية — حاجزُ «لا قناةَ بلا مالك» */
    private function ownerCount(string $conversationId): int
    {
        return ConversationMember::where('conversation_id', $conversationId)
            ->where('role', 'owner')->count();
    }
}
