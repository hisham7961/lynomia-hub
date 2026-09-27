<?php

namespace App\Support\Collaboration;

use App\Http\Controllers\Web\ConversationController;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\User;
use App\Support\Platform\FlowRunner;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * **إدارةُ القنوات — القاعدةُ الواحدة للسطحَين** (Work OS · WP-C.1 · §13–16 · خطّة
 * التطبيق 4.2). مُستخرَجةٌ حرفاً من `ConversationController` (الدليل · الانضمام ·
 * الإنشاء · تفضيلُ الإشعار · المفضّلة · الأرشفة · الأعضاء) كي يستدعيها الويبُ
 * والجوالُ معاً — الحارسُ القاطعُ يبقى `ConversationController::guardConversation`
 * (عضويّةٌ فعّالة + نطاقُ الشركة/العميل + صلاحيةُ الوحدةِ الهدف + دورٌ كافٍ).
 *
 * كلُّ رفضٍ `abort` بالرمز نفسِه الذي كان الويبُ يردّه، فيترجمه كلُّ سطحٍ بطريقته
 * (صفحةٌ أو غلافُ `Api::error`). وأعمدةٌ حديثةٌ غائبةٌ (ما قبل الهجرة) تُعاد `null`
 * بدل خمسمئة — والسطحُ يقول إنّ الميزةَ تحتاج ترحيلاً.
 */
final class ChannelService
{
    /** قواعدُ إنشاءِ قناة */
    public static function createRules(): array
    {
        return [
            'title'      => ['required', 'string', 'max:200'],
            'audience'   => ['nullable', 'string', Rule::in(Conversation::AUDIENCES)],
            'visibility' => ['nullable', 'string', Rule::in(Conversation::VISIBILITIES)],
            'client_id'  => ['nullable', 'string'],
            'project_id' => ['nullable', 'string'],
            // رسالةُ افتتاحٍ اختياريّة — نصٌّ أو أمرُ محادثة (WP-C.3)
            'body'       => ['nullable', 'string', 'max:4000'],
        ];
    }

    /**
     * **دليلُ القنوات** (§13) — ما يقدر المستخدمُ اكتشافَه والانضمامَ إليه ذاتيّاً وليس
     * عضواً فيه: قنواتٌ نشطةٌ داخليّةُ الجمهور، ظهورُها company/public، ضمن نطاقه.
     * العميلُ ٤٠٤ (لا يبلغ الدليل).
     */
    public static function directory(User $user): Collection
    {
        abort_if(hub_is_client($user), 404);

        $mineIds = ConversationMember::where('user_id', $user->getKey())->pluck('conversation_id');

        return Conversation::channels()->active()->whereNull('deleted_at')
            ->where('audience', 'internal')
            ->whereIn('visibility', ConversationController::DISCOVERABLE)
            ->whereNotIn('id', $mineIds)
            ->when(($cids = hub_company_ids($user)) !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereIn('company_id', $cids)->orWhereNull('company_id')))
            ->when(($kids = hub_client_ids($user)) !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereIn('client_id', $kids)->orWhereNull('client_id')))
            ->withCount('members')
            ->orderBy('title')->orderBy('id')
            ->get(['id', 'title', 'visibility', 'updated_at']);
    }

    /**
     * **الانضمامُ الذاتيّ** (§13) — يعيد فحصَ الظهورِ والنطاقِ خادميّاً (لا يثق بالدليل):
     * غيرُ الموجود/الخاصّ/«الأعضاء» ٤٠٤. عضوٌ أصلاً ⇒ `joined=false` بلا تكرار.
     *
     * @return array{conversation: Conversation, joined: bool}
     */
    public static function join(User $user, string $id): array
    {
        abort_if(hub_is_client($user), 404);

        $conv = Conversation::channels()->active()->whereNull('deleted_at')->find($id);
        abort_if($conv === null || $conv->audience !== 'internal'
            || ! in_array((string) $conv->visibility, ConversationController::DISCOVERABLE, true), 404);

        if (($cids = hub_company_ids($user)) !== null && $conv->company_id !== null
            && ! in_array((string) $conv->company_id, $cids, true)) abort(404);
        if (($kids = hub_client_ids($user)) !== null && $conv->client_id !== null
            && ! in_array((string) $conv->client_id, $kids, true)) abort(404);

        if (ConversationMember::where('conversation_id', $conv->id)->where('user_id', $user->getKey())->exists()) {
            return ['conversation' => $conv, 'joined' => false];
        }

        ConversationMember::create([
            'conversation_id' => $conv->id, 'user_id' => $user->getKey(),
            'role' => 'member', 'source' => 'explicit', 'last_read_at' => now(),
        ]);

        hub_audit('channel.joined', 'conversations', (string) $conv->id, $conv->title);

        return ['conversation' => $conv, 'joined' => true];
    }

    /**
     * **إنشاءُ قناة** + عضويّةِ مالكها، وإطلاقُ `conversation.created` وأثرِ التدقيق،
     * ثمّ رسالةُ افتتاحٍ اختياريّة (أمرُ محادثةٍ يُنفَّذ خدمةً، أو نصٌّ يُنشَر).
     *
     * @param array<string,mixed> $data المُتحقَّقُ من `createRules()`
     */
    public static function create(User $user, array $data): Conversation
    {
        $audience = $data['audience'] ?? 'internal';

        // نطاقُ الشركة: تُوسَم القناةُ بشركةِ مُنشئها المقيَّد فينعزل عنها الغريب
        $companyId = (($cids = hub_company_ids($user)) !== null && $cids) ? $cids[0] : null;

        // عميلُ القناة يُقيَّد بنطاق مُنشئه المقيَّد (لا يُوسَم بعميلٍ خارج نطاقه)
        $clientId = null;
        if (in_array($audience, ['client', 'both'], true)) {
            $clientId = ($v = trim(hub_str($data['client_id'] ?? null))) !== '' ? $v : null;
            if ($clientId !== null && ($kids = hub_client_ids($user)) !== null
                && ! in_array($clientId, $kids, true)) {
                abort(403, 'عميلٌ خارجَ نطاقك');
            }
        }

        $projectId = ($v = trim(hub_str($data['project_id'] ?? null))) !== '' ? $v : null;

        $conv = Conversation::create([
            'kind'       => 'channel',
            'title'      => mb_substr(trim((string) $data['title']), 0, 200),
            'audience'   => $audience,
            'visibility' => $data['visibility'] ?? 'private',
            'company_id' => $companyId,
            'client_id'  => $clientId,
            'project_id' => $projectId,
            'created_by' => $user->getKey(),
        ]);

        ConversationMember::create([
            'conversation_id' => $conv->id, 'user_id' => $user->getKey(),
            'role' => 'owner', 'source' => 'explicit', 'last_read_at' => now(),
        ]);

        FlowRunner::fire('created', 'conversations', $conv);
        hub_audit('channel.created', 'conversations', (string) $conv->id, $conv->title);

        $body = trim(hub_str($data['body'] ?? null));
        if ($body !== '') {
            $ctx = ['module' => 'channel', 'record_id' => (string) $conv->id, 'conversation_id' => (string) $conv->id];
            if (($parsed = ChatCommands::parse($body)) !== null) {
                ChatCommands::dispatch($parsed, $user, $ctx);
            } else {
                ChatCommands::postMessage($user, $body, $ctx);
            }
        }

        return $conv;
    }

    /**
     * **تفضيلُ إشعارِ القناةِ للعضو** (§16) — عضويّةُ الرؤية تكفي. `null` قبل الهجرة.
     */
    public static function setNotifyPref(string $id, string $pref): ?ConversationMember
    {
        [$conv] = ConversationController::guardConversation($id, 'v');
        abort_unless(in_array($pref, Collaboration::NOTIFY_PREFS, true), 422, 'تفضيلُ إشعارٍ غيرُ معروف');

        if (! hub_has_col('conversation_members', 'notify_pref')) return null;

        $m = ConversationMember::where('conversation_id', $conv->id)
            ->where('user_id', auth()->id())->firstOrFail();
        $m->forceFill(['notify_pref' => $pref])->save();   // الخطّافُ يزامن muted_at

        return $m;
    }

    /** **نجمةُ المفضّلة الشخصيّة** (§15) — تبديلٌ لعضويّتي. `null` قبل الهجرة. */
    public static function toggleFavorite(string $id): ?ConversationMember
    {
        [$conv] = ConversationController::guardConversation($id, 'v');

        if (! hub_has_col('conversation_members', 'favorite_at')) return null;

        $m = ConversationMember::where('conversation_id', $conv->id)
            ->where('user_id', auth()->id())->firstOrFail();
        $m->forceFill(['favorite_at' => $m->favorite_at ? null : now()])->save();

        return $m;
    }

    /** **أرشفةُ القناةِ وإعادتُها** (§14) — لمالكها وحده، بأثرِ تدقيقٍ على الطرفين */
    public static function toggleArchive(string $id): Conversation
    {
        [$conv, $role] = ConversationController::guardConversation($id, 'v', true);
        abort_unless($role === 'owner', 403, 'أرشفةُ القناةِ لمالكها وحده');

        $now = $conv->archived_at === null;
        $conv->forceFill(['archived_at' => $now ? now() : null])->save();

        hub_audit($now ? 'channel.archived' : 'channel.unarchived', 'conversations',
            (string) $conv->id, $conv->title);

        return $conv;
    }

    /** قواعدُ إضافةِ عضو */
    public static function addMemberRules(): array
    {
        return [
            'user_id' => ['required', 'string'],
            'role'    => ['nullable', 'string', Rule::in(ConversationMember::ROLES)],
        ];
    }

    /** قواعدُ تعديلِ الدور */
    public static function setRoleRules(): array
    {
        return [
            'user_id' => ['required', 'string'],
            'role'    => ['required', 'string', Rule::in(ConversationMember::ROLES)],
        ];
    }

    /** **إضافةُ عضو** — المشرفُ فأعلى؛ تنصيبُ مالكٍ/مشرفٍ للمالك وحده؛ المجموعةُ ٤٢٢ (fork) */
    public static function addMember(string $id, array $data): ConversationMember
    {
        [$conv, $actorRole] = ConversationController::guardConversation($id, 'manage');

        abort_if($conv->kind === 'group', 422,
            'مجموعةُ الرسائل: أضِف المشاركَ عبر «مجموعةٌ جديدة» حفظاً لخصوصيّة ما مضى');

        $target = User::whereNull('deleted_at')->find($data['user_id']);
        abort_unless($target, 422, 'لا مستخدمَ بهذا المعرّف');

        $role = $data['role'] ?? 'member';

        if (Conversation::roleRank($role) >= Conversation::roleRank('moderator')) {
            abort_unless($actorRole === 'owner', 403, 'تنصيبُ المشرفين والملّاك للمالك وحده');
        }

        abort_if(
            ConversationMember::where('conversation_id', $conv->id)->where('user_id', $target->id)->exists(),
            422, 'المستخدمُ عضوٌ أصلاً — عدّل دورَه'
        );

        $m = ConversationMember::create([
            'conversation_id' => $conv->id, 'user_id' => $target->id,
            'role' => $role, 'source' => 'explicit',
        ]);

        hub_audit('channel.member_added', 'conversations', (string) $conv->id,
            $target->name, ['after' => $role]);

        return $m;
    }

    /** **تعديلُ دورِ عضو** — لا يمسّ مَن يفوقه، والتصعيدُ للمالك، ولا قناةَ بلا مالك */
    public static function setRole(string $id, array $data): ConversationMember
    {
        [$conv, $actorRole] = ConversationController::guardConversation($id, 'manage');

        $m = ConversationMember::where('conversation_id', $conv->id)
            ->where('user_id', $data['user_id'])->orderBy('id')->first();
        abort_unless($m, 404, 'العضوُ غيرُ موجودٍ في القناة');

        $newRole = $data['role'];
        $actorRank = Conversation::roleRank($actorRole);

        if ($actorRole !== 'owner') {
            abort_unless(Conversation::roleRank($m->role) < $actorRank, 403, 'لا تمسّ مَن يفوقك أو يساويك');
            abort_unless(Conversation::roleRank($newRole) < Conversation::roleRank('moderator'), 403,
                'التصعيدُ إلى إدارةٍ للمالك وحده');
        }

        if ($m->role === 'owner' && $newRole !== 'owner' && self::ownerCount($conv->id) <= 1) {
            abort(422, 'لا تُخلى القناةُ من مالكها الوحيد — نصّب مالكاً آخرَ أولاً');
        }

        $before = $m->role;
        $m->update(['role' => $newRole]);

        hub_audit('channel.member_role', 'conversations', (string) $conv->id,
            optional($m->user)->name, ['before' => $before, 'after' => $newRole]);

        return $m;
    }

    /** **إزالةُ عضو** — لا يزيل مَن يفوقه، ولا يُخلي القناةَ من مالك */
    public static function removeMember(string $id, string $userId): ConversationMember
    {
        [$conv, $actorRole] = ConversationController::guardConversation($id, 'manage');

        $m = ConversationMember::where('conversation_id', $conv->id)
            ->where('user_id', $userId)->orderBy('id')->first();
        abort_unless($m, 404, 'العضوُ غيرُ موجودٍ في القناة');

        if ($actorRole !== 'owner') {
            abort_unless(Conversation::roleRank($m->role) < Conversation::roleRank($actorRole),
                403, 'لا تزيل مَن يفوقك أو يساويك');
        }

        if ($m->role === 'owner' && self::ownerCount($conv->id) <= 1) {
            abort(422, 'لا تُخلى القناةُ من مالكها الوحيد — نصّب مالكاً آخرَ أولاً');
        }

        $name = optional($m->user)->name;
        $before = $m->role;
        $m->delete();

        hub_audit('channel.member_removed', 'conversations', (string) $conv->id, $name, ['before' => $before]);

        return $m;
    }

    /** عددُ مالكي الحاوية — حاجزُ «لا قناةَ بلا مالك» */
    public static function ownerCount(string $conversationId): int
    {
        return ConversationMember::where('conversation_id', $conversationId)
            ->where('role', 'owner')->count();
    }
}
