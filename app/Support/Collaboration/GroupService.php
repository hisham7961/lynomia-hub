<?php

namespace App\Support\Collaboration;

use App\Http\Controllers\Web\ConversationController;
use App\Http\Controllers\Web\DmController;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\User;

/**
 * **مجموعاتُ الرسائل — القاعدةُ الواحدة للسطحَين** (§35 · خطّة التطبيق 4.2).
 * مُستخرَجةٌ حرفاً من `GroupController` كي يستدعيها الويبُ والجوال: داخليّةٌ خاصّةٌ
 * (`kind=group`, `visibility=private`, `audience=internal`)، المشاركون زملاءُ داخليّون
 * ضمن النطاق (سكّةُ DM نفسُها)، **وإضافةُ مشاركٍ = مجموعةٌ جديدة** (أمنُ الجمهور
 * التاريخيّ — لا يرى الجديدُ ما مضى)، والمغادرةُ تزيل عضويّتي وحدها.
 */
final class GroupService
{
    /** أقصى عددِ أعضاءِ مجموعةٍ (شاملاً المُنشئ) — مجموعةٌ صغيرةٌ لا قناة */
    public const MAX_MEMBERS = 20;

    /** قواعدُ الإنشاء */
    public static function createRules(): array
    {
        return [
            'participants'   => ['required', 'array', 'min:1', 'max:' . (self::MAX_MEMBERS - 1)],
            'participants.*' => ['string'],
            'title'          => ['nullable', 'string', 'max:200'],
            'body'           => ['nullable', 'string', 'max:4000'],
        ];
    }

    /** قواعدُ التوسيع (fork) */
    public static function forkRules(): array
    {
        return ['participants' => ['required', 'array', 'min:1'], 'participants.*' => ['string']];
    }

    /** مجموعاتي — التي أنا عضوٌ فيها، بترتيبٍ حتميّ (الأحدثُ نشاطاً ثمّ id) */
    public static function mine(User $me)
    {
        $memberIds = ConversationMember::where('user_id', $me->getKey())->pluck('conversation_id');

        return Conversation::groups()->active()->whereNull('deleted_at')
            ->whereIn('id', $memberIds)
            ->withCount('members')
            ->orderByDesc('updated_at')->orderBy('id')
            ->get(['id', 'title', 'updated_at', 'created_by']);
    }

    /** **إنشاءُ مجموعة** بمشاركين صريحين + رسالةُ افتتاحٍ اختياريّة. العميلُ ٤٠٣. */
    public static function create(User $me, array $participants, ?string $title, string $body = ''): Conversation
    {
        abort_if(hub_is_client($me), 403, 'مجموعاتُ الرسائل للفريق الداخليّ');

        $members = self::validateParticipants($me, $participants);
        $conv = self::createGroup($me, $members, $title);

        $body = trim($body);
        if ($body !== '') {
            CommentService::create($me, 'channel', (string) $conv->id, $body, ['conversation_id' => (string) $conv->id]);
        }

        return $conv;
    }

    /**
     * **إضافةُ مشاركٍ = مجموعةٌ جديدة** — أعضاءُ الحاليّة (عداي) + الجدد بتاريخٍ فارغ،
     * وتبقى القديمةُ لجمهورها. غيرُ العضو ٤٠٤ (`guardConversation`)، وغيرُ المجموعة ٤٠٤.
     */
    public static function fork(User $me, string $id, array $participants): Conversation
    {
        [$conv] = ConversationController::guardConversation($id, 'v');
        abort_unless($conv->kind === 'group', 404);

        $current = ConversationMember::where('conversation_id', $conv->id)
            ->where('user_id', '!=', $me->getKey())->pluck('user_id')->all();
        $added = self::validateParticipants($me, $participants);
        $union = array_values(array_unique(array_merge($current, $added)));
        abort_if(count($union) + 1 > self::MAX_MEMBERS, 422, 'المجموعةُ أكبرُ من الحدّ');

        return self::createGroup($me, $union, $conv->title);
    }

    /** **مغادرةُ مجموعة** — تُزيل عضويّتي فقط؛ آخرُ عضوٍ يطويها (أرشفة) */
    public static function leave(User $me, string $id): Conversation
    {
        [$conv] = ConversationController::guardConversation($id, 'v');
        abort_unless($conv->kind === 'group', 404);

        ConversationMember::where('conversation_id', $conv->id)->where('user_id', $me->getKey())->delete();

        if (ConversationMember::where('conversation_id', $conv->id)->count() === 0) {
            $conv->forceFill(['archived_at' => now()])->save();   // مجموعةٌ بلا أعضاءٍ تُطوى
        }

        return $conv;
    }

    /** يتحقّق من المشاركين: داخليّون، غيرُ عملاء، ضمن النطاق، غيرُ النفس — يعيد معرّفاتهم */
    public static function validateParticipants(User $me, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        $out = [];
        foreach ($ids as $uid) {
            if ($uid === (string) $me->getKey()) continue;
            $u = User::whereNull('deleted_at')->where('status', 'نشط')->find($uid);
            // داخليٌّ ضمن نطاقِ الشركة (نفسُ سكّة DM) وليس عميلاً — وإلّا يُرفَض بلا كشفِ وجود
            abort_unless($u && ! hub_is_client($u) && DmController::dmReachable($u, $me), 422,
                'مشاركٌ غيرُ صالح — اختر زملاءَ من فريقك الداخليّ');
            $out[] = (string) $u->id;
        }
        abort_if($out === [], 422, 'اختر مشاركاً واحداً على الأقل');

        return $out;
    }

    /** يُنشئ حاويةَ مجموعةٍ + عضويّاتِها (المُنشئ owner، البقيّة member) */
    public static function createGroup(User $me, array $memberIds, ?string $title): Conversation
    {
        // نطاقُ الشركة: تُوسَم بشركة مُنشئها المقيَّد (كالقناة) فينعزل عنها الغريب
        $companyId = (($cids = hub_company_ids($me)) !== null && $cids) ? $cids[0] : null;

        $conv = Conversation::create([
            'kind'       => 'group',
            'title'      => $title ? mb_substr($title, 0, 200) : null,
            'audience'   => 'internal',
            'visibility' => 'private',
            'company_id' => $companyId,
            'created_by' => $me->getKey(),
        ]);

        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $me->getKey(),
            'role' => 'owner', 'source' => 'explicit', 'last_read_at' => now()]);
        foreach ($memberIds as $uid) {
            ConversationMember::firstOrCreate(
                ['conversation_id' => $conv->id, 'user_id' => $uid],
                ['role' => 'member', 'source' => 'explicit']
            );
        }

        hub_audit('group.created', 'conversations', (string) $conv->id, $conv->title ?: 'مجموعة');

        return $conv;
    }

    /** أسماءُ أعضاءِ كلِّ مجموعةٍ (عدا القارئ) لعرضِ عنوانٍ بلا عنوانٍ صريح — بترتيبٍ حتميّ بالاسم ثمّ المعرّف */
    public static function memberNames(array $convIds, string $me): array
    {
        if (! $convIds) return [];

        $rows = ConversationMember::whereIn('conversation_id', $convIds)
            ->where('user_id', '!=', $me)
            ->join('users', 'users.id', '=', 'conversation_members.user_id')
            ->orderBy('users.name')->orderBy('conversation_members.id')
            ->get(['conversation_members.conversation_id as cid', 'users.name']);

        $out = [];
        foreach ($rows as $row) $out[$row->cid][] = $row->name;

        return $out;
    }
}
