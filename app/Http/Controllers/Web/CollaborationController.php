<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\User;
use App\Support\Collaboration;
use App\Support\CollaborationRail;
use App\Support\DmService;
use Illuminate\Http\Request;

/**
 * **مركزُ التواصلِ الموحّد — الألواحُ الثلاثة** (المرحلة ٦ · §106).
 *
 * لوحٌ يسارٌ (السكّة: قنوات/غرف/مجموعات/محادثات + مفضّلة + غيرُ مقروء + حضور)، ولوحٌ
 * أوسطُ (الخيطُ المختار: ترويسة/خطٌّ زمنيّ/فاصلُ غيرِ المقروء/ردود/تفاعلات/ناشر/مرفقات/
 * مراجعُ سجلّ)، ولوحٌ يمينٌ (سياق: مشاركون/مثبّتات/ملفّات/سياقُ السجلّ). **كلُّه فوق
 * المحرّكِ الواحد** — لا صفحةٌ ثانيةٌ ولا محرّكٌ ثانٍ: القنواتُ عبر `guardConversation`
 * و`comments`، والمحادثاتُ عبر `DmService`، والسكّةُ عبر `CollaborationRail`.
 *
 * **العزلُ خادميٌّ لا يُلتَفُّ عليه:** الاختيارُ يمرّ بالحارسِ نفسِه (`guardConversation`
 * للقناة، `dmReachable` للمحادثة) فلا يفتح `?c=`/`?dm=` ما ليس لصاحبه — ٤٠٤ لا كشفَ
 * وجود. والعميلُ لا يبلغ المركزَ أصلاً (`PortalGuard` + الحارس أدناه).
 */
class CollaborationController extends Controller
{
    public function center(Request $r)
    {
        $user = auth()->user();
        // العميلُ لا يبلغ المركزَ الداخليَّ — دفاعٌ فوق PortalGuard (٤٠٤ لا كشفَ وجود)
        abort_if(hub_is_client($user), 404);

        $rail = CollaborationRail::forUser($user);

        $selected = null;    // ['type' => 'channel'|'group'|'dm', ...]
        $center = [];

        $c = hub_str($r->query('c'));
        $dm = hub_str($r->query('dm'));

        if ($c !== '') {
            $center = $this->openConversation($c);
            $selected = ['type' => $center['conv']->kind === 'group' ? 'group' : 'channel',
                'id' => (string) $center['conv']->id];
        } elseif ($dm !== '') {
            $center = $this->openDm($user, $dm);
            $selected = ['type' => 'dm', 'id' => (string) $center['other']->id];
        }

        return view('collaboration.center', array_merge([
            'rail'     => $rail,
            'selected' => $selected,
            'users'    => CommentController::userNames(),
        ], $center));
    }

    /** يفتح قناةً/مجموعةً في اللوح الأوسط — الحارسُ نفسُه (غيرُ العضو ٤٠٤) */
    private function openConversation(string $id): array
    {
        [$conv, $role] = ConversationController::guardConversation($id);

        $messages = $conv->messages()->whereNull('parent_id')->with('user', 'replies.user')->get();
        (new CommentController)->markReadPublic($messages);

        if (hub_has_col('conversation_members', 'last_read_at')) {
            ConversationMember::where('conversation_id', $conv->id)
                ->where('user_id', auth()->id())->update(['last_read_at' => now()]);
        }

        $members = $conv->members()->with('user:id,name')
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'moderator' THEN 1 WHEN 'member' THEN 2 ELSE 3 END")
            ->orderBy('id')->get();

        // §28 المثبّتاتُ للوحِ السياق — من نفسِ الحاوية (لا استعلامٌ عبر حاويةٍ أخرى)
        $pins = Comment::where('conversation_id', $conv->id)->whereNull('deleted_at')
            ->where('pinned', true)->with('user:id,name')
            ->orderByDesc('pinned_at')->orderByDesc('id')->limit(50)->get();

        // الملفّاتُ للوحِ السياق — رسائلُ الحاويةِ ذاتُ المرفق (بترتيبٍ حتميّ)
        $files = Comment::where('conversation_id', $conv->id)->whereNull('deleted_at')
            ->whereNotNull('att')->with('user:id,name')
            ->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get();

        // §8 سياقُ السجلّ — الخيطُ على وحدةٍ حقيقيّة يعرض بطاقةَ ربطٍ (صلاحيةُ الوحدةِ حُسمت في الحارس)
        $record = null;
        if ($conv->module && $conv->record_id && hub_mod($conv->module)) {
            $record = [
                'module' => $conv->module,
                'id'     => (string) $conv->record_id,
                'label'  => hub_mod($conv->module)['label'] ?? $conv->module,
                'url'    => route('m.show', [$conv->module, $conv->record_id]),
            ];
        }

        $tip = Comment::where('conversation_id', $conv->id)->whereNull('deleted_at')
            ->orderByDesc('created_at')->orderByDesc('id')->first(['id', 'created_at']);

        return [
            'conv'        => $conv,
            'role'        => $role,
            'canPost'     => Conversation::roleCanPost($role),
            'canManage'   => Conversation::roleCanManage($role),
            'isGroup'     => $conv->kind === 'group',
            'messages'    => $messages,
            'members'     => $members,
            'pins'        => $pins,
            'files'       => $files,
            'record'      => $record,
            'sinceCursor' => $tip ? Collaboration::encodeCursor((string) $tip->created_at, (string) $tip->id) : '',
        ];
    }

    /** يفتح محادثةً مباشرةً في اللوح الأوسط — `dmReachable` (خارجُ النطاق ٤٠٤) */
    private function openDm(User $me, string $userId): array
    {
        $other = User::findOrFail($userId);
        abort_if($other->id === $me->id, 404, 'لا محادثة مع النفس');
        abort_unless(DmController::dmReachable($other), 404);

        DmService::markThreadRead((string) $me->id, (string) $other->id);
        $msgs = DmService::thread((string) $me->id, (string) $other->id);

        $tip = \App\Models\DmMessage::where('thread_key', \App\Models\DmMessage::threadKey((string) $me->id, (string) $other->id))
            ->orderByDesc('created_at')->orderByDesc('id')->first(['id', 'created_at']);

        return [
            'other'       => $other,
            'msgs'        => $msgs,
            'dmReactions' => DmController::dmReactionsFor($msgs->pluck('id')->all()),
            'dmPresence'  => DmController::presence([(string) $other->id])[(string) $other->id] ?? null,
            'sinceCursor' => $tip ? Collaboration::encodeCursor((string) $tip->created_at, (string) $tip->id) : '',
        ];
    }
}
