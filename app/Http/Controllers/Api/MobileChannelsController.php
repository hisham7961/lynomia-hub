<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Web\ConversationController;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\DmMessage;
use App\Support\Collaboration\ChannelService;
use App\Support\Collaboration\Collaboration;
use App\Support\Collaboration\DmService;
use App\Support\Collaboration\GroupService;
use App\Support\Collaboration\MessageSearch;
use App\Support\Platform\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * **إدارةُ القنوات والمجموعات والرسائل على الجوال** (خطّة التطبيق · 4.2) — فوق
 * القواعدِ الواحدة التي يستدعيها الويبُ حرفاً:
 *  • القنوات: `ChannelService` (دليل · انضمام · إنشاء · أعضاء · مفضّلة · أرشفة ·
 *    تفضيلُ إشعار) خلف الحارسِ القاطع `ConversationController::guardConversation`.
 *  • مجموعاتُ الرسائل: `GroupService` (إنشاء · توسيعٌ = مجموعةٌ جديدة · مغادرة).
 *  • تحريرُ/سحبُ رسالةٍ مباشرة: `DmService::editOwn/retract` (لصاحبها وحده).
 *  • بحثُ الرسائل: `MessageSearch::run` (الخلاصةُ بشركتي، القنواتُ بعضويّتي، خيوطي).
 *
 * كلُّها داخليّة: `MobilePortalGuard` يطوي حسابَ العميل ٤٠٤ قبل المتحكّم (الأسماءُ
 * خارجَ قائمته البيضاء)، والمتحكّمُ يعيد الطيَّ دفاعاً في العمق.
 */
class MobileChannelsController extends MobileWorkflowController
{
    // ══════════════════════════ القنوات ══════════════════════════

    /** `GET conversations/directory` — قنواتٌ أقدر على الانضمام إليها ولستُ عضواً فيها */
    public function directory(Request $r): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;

        return $this->ok(['channels' => ChannelService::directory($r->user())->map(fn (Conversation $c) => [
            'id' => (string) $c->id,
            'title' => (string) $c->title,
            'visibility' => (string) $c->visibility,
            'members' => (int) ($c->members_count ?? 0),
            'updated_at' => self::iso($c->updated_at),
        ])->values()->all()]);
    }

    /** `POST conversations` — إنشاءُ قناةٍ (أنا مالكُها) + رسالةُ افتتاحٍ اختياريّة (`Idempotency-Key`) */
    public function channelStore(Request $r): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;
        $data = $r->validate(ChannelService::createRules(), [], ['title' => 'اسمُ القناة']);

        return $this->idempotent($r, function () use ($r, $data) {
            $conv = ChannelService::create($r->user(), $data);

            return $this->ok(['conversation' => $this->convCard($conv->fresh() ?? $conv, 'owner')], 201);
        });
    }

    /** `POST conversations/{id}/join` — انضمامٌ ذاتيّ لقناةٍ قابلةٍ للاكتشاف (الخاصّةُ ٤٠٤) */
    public function join(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;

        $res = ChannelService::join($r->user(), $id);

        return $this->ok(['conversation' => $this->convCard($res['conversation'],
            (string) Conversation::roleOf((string) $res['conversation']->id, (string) $r->user()->id)),
            'joined' => (bool) $res['joined']]);
    }

    /** `GET conversations/{id}/members` — أعضاءُ حاويتي بأدوارهم + دوري وما أقدر عليه */
    public function members(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;
        [$conv, $role] = ConversationController::guardConversation($id, 'v');

        return $this->ok($this->membersPayload($conv, $role));
    }

    /** `POST conversations/{id}/members` — إضافةُ عضو (مشرفٌ فأعلى؛ الإدارةُ للمالك؛ المجموعةُ ٤٢٢) */
    public function addMember(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;
        [$conv] = ConversationController::guardConversation($id, 'manage');
        abort_if($conv->kind === 'group', 422,
            'مجموعةُ الرسائل: أضِف المشاركَ عبر «مجموعةٌ جديدة» حفظاً لخصوصيّة ما مضى');
        $data = $r->validate(ChannelService::addMemberRules(), [], ['user_id' => 'المستخدم', 'role' => 'الدور']);

        $m = ChannelService::addMember($id, $data);

        return $this->ok(['member' => $this->memberShape($m->fresh(['user']) ?? $m)], 201);
    }

    /** `PUT conversations/{id}/members/{user}` — تعديلُ دورِ عضو (لا يمسّ مَن يفوقه؛ لا قناةَ بلا مالك) */
    public function setRole(Request $r, string $id, string $user): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;
        ConversationController::guardConversation($id, 'manage');
        $data = $r->validate(['role' => ChannelService::setRoleRules()['role']], [], ['role' => 'الدور']);

        $m = ChannelService::setRole($id, ['user_id' => $user, 'role' => $data['role']]);

        return $this->ok(['member' => $this->memberShape($m->fresh(['user']) ?? $m)]);
    }

    /** `DELETE conversations/{id}/members/{user}` — إزالةُ عضو (لا يزيل مَن يفوقه؛ لا قناةَ بلا مالك) */
    public function removeMember(Request $r, string $id, string $user): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;

        ChannelService::removeMember($id, $user);

        return $this->ok(['user_id' => $user, 'removed' => true]);
    }

    /** `POST conversations/{id}/favorite` — تبديلُ نجمةِ المفضّلة لعضويّتي */
    public function favorite(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;

        $m = ChannelService::toggleFavorite($id);
        if ($m === null) return $this->needsMigration('المفضّلة');

        return $this->ok(['conversation_id' => $id, 'favorite' => $m->favorite_at !== null]);
    }

    /** `POST conversations/{id}/archive` — أرشفةُ القناةِ وإعادتُها (لمالكها وحده) */
    public function archive(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;

        $conv = ChannelService::toggleArchive($id);

        return $this->ok(['conversation_id' => (string) $conv->id, 'archived' => $conv->archived_at !== null]);
    }

    /** `PUT conversations/{id}/notify` — تفضيلُ إشعاري (all/mentions/muted) */
    public function notifyPref(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;
        ConversationController::guardConversation($id, 'v');   // غيرُ العضو ٤٠٤ قبل التحقّق
        $data = $r->validate(['pref' => ['required', 'string', \Illuminate\Validation\Rule::in(Collaboration::NOTIFY_PREFS)]],
            [], ['pref' => 'تفضيل الإشعار']);

        $m = ChannelService::setNotifyPref($id, $data['pref']);
        if ($m === null) return $this->needsMigration('تفضيلُ الإشعار');

        return $this->ok(['conversation_id' => $id, 'pref' => (string) $m->notify_pref]);
    }

    // ══════════════════════════ مجموعاتُ الرسائل ══════════════════════════

    /** `POST groups` — مجموعةٌ بمشاركين داخليّين ضمن نطاقي (`Idempotency-Key`) */
    public function groupStore(Request $r): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;
        $data = $r->validate(GroupService::createRules(), [], ['participants' => 'المشاركون']);

        return $this->idempotent($r, function () use ($r, $data) {
            $conv = GroupService::create($r->user(), (array) $data['participants'],
                trim(hub_str($data['title'] ?? null)) ?: null, hub_str($data['body'] ?? null));

            return $this->ok(['conversation' => $this->convCard($conv, 'owner')]
                + $this->membersPayload($conv, 'owner'), 201);
        });
    }

    /** `POST groups/{id}/participants` — إضافةُ مشاركين = مجموعةٌ جديدة (القديمةُ لجمهورها) */
    public function groupFork(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;
        [$conv] = ConversationController::guardConversation($id, 'v');
        abort_unless($conv->kind === 'group', 404);
        $data = $r->validate(GroupService::forkRules(), [], ['participants' => 'المشاركون']);

        return $this->idempotent($r, function () use ($r, $id, $data) {
            $new = GroupService::fork($r->user(), $id, (array) $data['participants']);

            return $this->ok(['conversation' => $this->convCard($new, 'owner'), 'forked_from' => $id]
                + $this->membersPayload($new, 'owner'), 201);
        });
    }

    /** `POST groups/{id}/leave` — مغادرةُ مجموعةٍ (عضويّتي وحدها؛ آخرُ عضوٍ يطويها) */
    public function groupLeave(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;

        $conv = GroupService::leave($r->user(), $id);

        return $this->ok(['conversation_id' => (string) $conv->id, 'left' => true]);
    }

    // ══════════════════════════ تحريرُ/سحبُ رسالةٍ مباشرة ══════════════════════════

    /** `PATCH dm/messages/{id}` — تحريرُ رسالتي (لصاحبها؛ المحذوفةُ ٤٢٢؛ غيرُ الطرف ٤٠٤) */
    public function dmEdit(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        $m = $this->dmParty($id);
        if ($m instanceof Response) return $m;
        DmService::guardOwn($r->user(), $m, 'edit');

        $r->merge(['body' => trim(hub_str($r->input('body')))]);
        $data = $r->validate(['body' => ['required', 'string', 'max:4000']], [], ['body' => 'نص الرسالة']);
        $m = DmService::editOwn($r->user(), $m, $data['body']);

        return $this->ok(['message' => $this->dmShape($m)]);
    }

    /** `DELETE dm/messages/{id}` — سحبُ رسالتي (حذفٌ ناعمٌ يبقى أثرُه؛ يُسحب إشعارُ المستلم) */
    public function dmDestroy(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        $m = $this->dmParty($id);
        if ($m instanceof Response) return $m;

        if (! DmService::retract($r->user(), $m)) return $this->needsMigration('سحبُ الرسائل');

        return $this->ok(['message' => $this->dmShape($m->fresh() ?? $m)]);
    }

    // ══════════════════════════ بحثُ الرسائل ══════════════════════════

    /** `GET search/messages?q=` — نصُّ الرسائل التي أراها (خلاصةُ شركتي · قنواتي · خيوطي) */
    public function searchMessages(Request $r): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;
        $q = trim(hub_str($r->query('q')));
        $me = (string) $r->user()->id;
        $found = MessageSearch::run($r->user(), $q);

        return $this->ok([
            'q' => $q,
            'min_chars' => MessageSearch::MIN_CHARS,
            'total' => (int) $found['total'],
            'results' => $found['rows']->map(function (array $x) use ($me) {
                $m = $x['model'];
                $target = $x['type'] === 'dm'
                    ? ['kind' => 'dm', 'user_id' => (string) $m->from_id === $me ? (string) $m->to_id : (string) $m->from_id,
                        'message_id' => (string) $m->id]
                    : ['kind' => 'comment', 'module' => (string) $m->module,
                        'record_id' => $m->module !== 'feed' && $m->record_id !== null ? (string) $m->record_id : null,
                        'comment_id' => (string) $m->id,
                        'parent_id' => $m->parent_id !== null ? (string) $m->parent_id : null];

                return [
                    'type' => $x['type'],
                    'id' => (string) $m->id,
                    'author' => $x['author'],
                    'excerpt' => Str::limit(trim((string) $m->body), 120),
                    'created_at' => self::iso($x['at']),
                    'target' => $target,
                ];
            })->values()->all(),
        ]);
    }

    // ══════════════════════════ مساعِدات ══════════════════════════

    /** رسالةٌ أنا طرفٌ فيها أو ٤٠٤ — لا نكشف وجودَ رسالةِ غيري (نظيرُ dmReact) */
    private function dmParty(string $id): DmMessage|Response
    {
        $m = DmMessage::find($id);
        $me = (string) auth()->id();
        if (! $m || ! in_array($me, [(string) $m->from_id, (string) $m->to_id], true)) {
            return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'لا رسالةَ بهذا المعرّف');
        }

        return $m;
    }

    private function needsMigration(string $feature): Response
    {
        return Api::error(Api::INTEGRATION_UNAVAILABLE, 422,
            $feature . ' ميزةٌ جديدة تحتاج تحديث قاعدة البيانات — شغّل الترحيلات ثم أعد المحاولة',
            ['reason' => 'migration_required']);
    }

    private function convCard(Conversation $c, string $role): array
    {
        return [
            'id' => (string) $c->id,
            'kind' => (string) $c->kind,
            'title' => $c->title !== null ? (string) $c->title : null,
            'audience' => (string) $c->audience,
            'visibility' => (string) $c->visibility,
            'archived' => $c->archived_at !== null,
            'my_role' => $role,
        ];
    }

    private function membersPayload(Conversation $conv, string $role): array
    {
        $members = ConversationMember::where('conversation_id', $conv->id)->with('user:id,name')
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'moderator' THEN 1 WHEN 'member' THEN 2 ELSE 3 END")
            ->orderBy('id')->get();

        return [
            'conversation_id' => (string) $conv->id,
            'my_role' => $role,
            'can_post' => Conversation::roleCanPost($role),
            'can_manage' => Conversation::roleCanManage($role),
            'members' => $members->map(fn (ConversationMember $m) => $this->memberShape($m))->values()->all(),
        ];
    }

    private function memberShape(ConversationMember $m): array
    {
        return [
            'user' => ['id' => (string) $m->user_id, 'name' => (string) ($m->user->name ?? '')],
            'role' => (string) $m->role,
        ];
    }

    private function dmShape(DmMessage $m): array
    {
        $deleted = $m->deleted_at !== null;

        return [
            'id' => (string) $m->id,
            'from_id' => (string) $m->from_id,
            'to_id' => (string) $m->to_id,
            'mine' => (string) $m->from_id === (string) auth()->id(),
            'body' => $deleted ? null : (string) $m->body,
            'deleted' => $deleted,
            'edited' => $m->edited_at !== null,
            'created_at' => self::iso($m->created_at),
        ];
    }
}
