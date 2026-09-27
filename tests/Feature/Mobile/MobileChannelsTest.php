<?php

namespace Tests\Feature\Mobile;

use App\Models\Comment;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\DmMessage;
use App\Models\HubNotification;
use App\Models\Role;
use App\Models\User;
use App\Support\Collaboration\CommentService;
use App\Support\Collaboration\DmService;
use Tests\TestCase;

/**
 * **إدارةُ القنوات والمجموعات والرسائل على الجوال** (خطّة التطبيق 4.2) — فوق
 * `ChannelService`/`GroupService`/`DmService`/`MessageSearch` التي يستدعيها الويبُ حرفاً.
 * كلُّ فعلٍ مع رفضه: غيرُ العضو ٤٠٤، الدورُ غيرُ الكافي ٤٠٣، لا قناةَ بلا مالك ٤٢٢،
 * المجموعةُ لا تُوسَّع في مكانها، الرسالةُ لصاحبها، والبحثُ لا يعبر عضويّةً ولا خيطاً.
 */
class MobileChannelsTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function h(User $u): array
    {
        return $this->bearer($this->mobileLogin($u)['access_token']);
    }

    private function channel(User $owner, string $title, array $extra = []): Conversation
    {
        $conv = Conversation::create(array_merge(['kind' => 'channel', 'title' => $title,
            'audience' => 'internal', 'visibility' => 'members', 'created_by' => $owner->id], $extra));
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $owner->id, 'role' => 'owner']);

        return $conv;
    }

    private function member(Conversation $c, User $u, string $role = 'member'): void
    {
        ConversationMember::create(['conversation_id' => $c->id, 'user_id' => $u->id, 'role' => $role]);
    }

    private function clientUser(): User
    {
        $role = Role::create(['name' => 'عميل', 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        return User::create(['name' => 'حسابُ عميل', 'email' => 'cl@ext.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'account_type' => 'client', 'password_changed_at' => now()]);
    }

    public function test_directory_and_join_follow_visibility_and_company_scope(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);
        $this->employee->update(['companies' => [$coA->id]]);
        $open = $this->channel($this->owner, 'قناةٌ عامّة', ['visibility' => 'public']);
        $private = $this->channel($this->owner, 'قناةٌ خاصّة', ['visibility' => 'private']);
        $foreign = $this->channel($this->owner, 'قناةُ شركةِ باء', ['visibility' => 'company', 'company_id' => $coB->id]);

        $H = $this->h($this->employee);
        $titles = array_column($this->withHeaders($H)->getJson('/api/mobile/v1/conversations/directory')
            ->assertOk()->json('data.channels'), 'title');
        $this->assertSame(['قناةٌ عامّة'], $titles, 'الخاصّةُ وقناةُ الشركة الأخرى لا تُكتشفان');

        $this->withHeaders($H)->postJson('/api/mobile/v1/conversations/' . $open->id . '/join')->assertOk()
            ->assertJsonPath('data.joined', true)->assertJsonPath('data.conversation.my_role', 'member');
        $this->withHeaders($H)->postJson('/api/mobile/v1/conversations/' . $open->id . '/join')->assertOk()
            ->assertJsonPath('data.joined', false);
        $this->withHeaders($H)->postJson('/api/mobile/v1/conversations/' . $private->id . '/join')->assertNotFound();
        $this->withHeaders($H)->postJson('/api/mobile/v1/conversations/' . $foreign->id . '/join')->assertNotFound();
        $this->assertFalse(ConversationMember::where('conversation_id', $private->id)->where('user_id', $this->employee->id)->exists());
        $this->assertFalse(ConversationMember::where('conversation_id', $foreign->id)->where('user_id', $this->employee->id)->exists());
    }

    public function test_create_channel_makes_me_owner_and_is_idempotent(): void
    {
        $this->seedCore();
        $H = $this->h($this->employee) + ['Idempotency-Key' => 'ch-1'];

        $a = $this->withHeaders($H)->postJson('/api/mobile/v1/conversations',
            ['title' => 'قناةُ التسليم', 'visibility' => 'public', 'body' => 'أهلاً بالفريق'])->assertStatus(201);
        $a->assertJsonPath('data.conversation.my_role', 'owner')->assertJsonPath('data.conversation.kind', 'channel');
        $b = $this->withHeaders($H)->postJson('/api/mobile/v1/conversations',
            ['title' => 'قناةُ التسليم', 'visibility' => 'public', 'body' => 'أهلاً بالفريق'])->assertStatus(201);
        $this->assertSame($a->json('data.conversation.id'), $b->json('data.conversation.id'));
        $this->assertSame(1, Conversation::where('title', 'قناةُ التسليم')->count());

        $id = $a->json('data.conversation.id');
        $this->assertSame('owner', Conversation::roleOf($id, (string) $this->employee->id));
        $this->assertTrue(Comment::where('conversation_id', $id)->where('body', 'أهلاً بالفريق')->exists());

        $this->withHeaders($this->h($this->viewer))->postJson('/api/mobile/v1/conversations', ['audience' => 'x'])
            ->assertStatus(422);
    }

    public function test_member_management_enforces_roles_and_the_last_owner(): void
    {
        $this->seedCore();
        $mod = User::create(['name' => 'مشرف', 'email' => 'mod@test.local', 'password' => 'Secret!2026x',
            'role_id' => $this->employee->role_id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $conv = $this->channel($this->owner, 'قناة');
        $this->member($conv, $mod, 'moderator');
        $this->member($conv, $this->employee);
        $url = '/api/mobile/v1/conversations/' . $conv->id . '/members';

        // غيرُ العضو ٤٠٤ حتى للقراءة
        $this->withHeaders($this->h($this->viewer))->getJson($url)->assertNotFound();

        $E = $this->h($this->employee);
        $this->withHeaders($E)->getJson($url)->assertOk()->assertJsonPath('data.my_role', 'member')
            ->assertJsonPath('data.can_manage', false)->assertJsonPath('data.members.0.role', 'owner');
        // العضوُ العاديّ لا يدير
        $this->withHeaders($E)->postJson($url, ['user_id' => (string) $this->viewer->id])->assertForbidden();

        $M = $this->h($mod);
        // المشرفُ يضيف عضواً — ولا يصعّد إلى مشرف
        $this->withHeaders($M)->postJson($url, ['user_id' => (string) $this->viewer->id, 'role' => 'moderator'])->assertForbidden();
        $this->withHeaders($M)->postJson($url, ['user_id' => (string) $this->viewer->id])->assertStatus(201)
            ->assertJsonPath('data.member.role', 'member');
        $this->withHeaders($M)->postJson($url, ['user_id' => (string) $this->viewer->id])->assertStatus(422);
        // ولا يمسّ المالك
        $this->withHeaders($M)->putJson($url . '/' . $this->owner->id, ['role' => 'member'])->assertForbidden();
        $this->withHeaders($M)->deleteJson($url . '/' . $this->owner->id)->assertForbidden();

        $O = $this->h($this->owner);
        // لا قناةَ بلا مالك
        $this->withHeaders($O)->putJson($url . '/' . $this->owner->id, ['role' => 'member'])->assertStatus(422);
        $this->withHeaders($O)->deleteJson($url . '/' . $this->owner->id)->assertStatus(422);
        $this->withHeaders($O)->putJson($url . '/' . $this->employee->id, ['role' => 'moderator'])->assertOk()
            ->assertJsonPath('data.member.role', 'moderator');
        $this->withHeaders($O)->deleteJson($url . '/' . $this->viewer->id)->assertOk()->assertJsonPath('data.removed', true);
        $this->assertSame('owner', Conversation::roleOf((string) $conv->id, (string) $this->owner->id));
        $this->assertNull(Conversation::roleOf((string) $conv->id, (string) $this->viewer->id));
    }

    public function test_favorite_notify_are_per_member_and_archive_is_owner_only(): void
    {
        $this->seedCore();
        $conv = $this->channel($this->owner, 'قناة');
        $this->member($conv, $this->employee);
        $base = '/api/mobile/v1/conversations/' . $conv->id;
        $E = $this->h($this->employee);

        $this->withHeaders($E)->postJson($base . '/favorite')->assertOk()->assertJsonPath('data.favorite', true);
        $this->withHeaders($E)->postJson($base . '/favorite')->assertOk()->assertJsonPath('data.favorite', false);
        $this->withHeaders($E)->putJson($base . '/notify', ['pref' => 'muted'])->assertOk()->assertJsonPath('data.pref', 'muted');
        $this->withHeaders($E)->putJson($base . '/notify', ['pref' => 'loud'])->assertStatus(422);
        $this->withHeaders($E)->postJson($base . '/archive')->assertForbidden();

        $V = $this->h($this->viewer);
        $this->withHeaders($V)->postJson($base . '/favorite')->assertNotFound();
        $this->withHeaders($V)->putJson($base . '/notify', ['pref' => 'muted'])->assertNotFound();

        $O = $this->h($this->owner);
        $this->withHeaders($O)->postJson($base . '/archive')->assertOk()->assertJsonPath('data.archived', true);
        $this->assertNotNull($conv->fresh()->archived_at);
        $this->withHeaders($O)->postJson($base . '/archive')->assertOk()->assertJsonPath('data.archived', false);
    }

    public function test_group_create_fork_and_leave_keep_historic_audience(): void
    {
        $this->seedCore();
        $client = $this->clientUser();
        $E = $this->h($this->employee);

        // عميلٌ لا يُضاف لمجموعةٍ داخليّة
        $this->withHeaders($E)->postJson('/api/mobile/v1/groups', ['participants' => [(string) $client->id]])
            ->assertStatus(422);

        $g = $this->withHeaders($E)->postJson('/api/mobile/v1/groups',
            ['participants' => [(string) $this->owner->id], 'title' => 'ثنائيّ', 'body' => 'سرُّ الثنائيّ'])->assertStatus(201);
        $g->assertJsonPath('data.conversation.kind', 'group')->assertJsonPath('data.my_role', 'owner');
        $gid = $g->json('data.conversation.id');
        $this->assertEqualsCanonicalizing([(string) $this->employee->id, (string) $this->owner->id],
            array_column(array_column($g->json('data.members'), 'user'), 'id'));

        // إضافةُ الموظّف الثالث تُنشئ مجموعةً جديدة — القديمةُ لا يبلغها
        $f = $this->withHeaders($E)->postJson('/api/mobile/v1/groups/' . $gid . '/participants',
            ['participants' => [(string) $this->viewer->id]])->assertStatus(201);
        $newId = $f->json('data.conversation.id');
        $this->assertNotSame($gid, $newId);
        $f->assertJsonPath('data.forked_from', $gid);
        $this->assertNull(Conversation::roleOf($gid, (string) $this->viewer->id), 'الجديدُ لا يرى تاريخَ القديمة');
        $this->assertSame(0, Comment::where('conversation_id', $newId)->count());

        // غيرُ العضو لا يوسّع ولا يغادر ما ليس فيه
        $V = $this->h($this->viewer);
        $this->withHeaders($V)->postJson('/api/mobile/v1/groups/' . $gid . '/participants',
            ['participants' => [(string) $this->viewer->id]])->assertNotFound();
        $this->withHeaders($V)->postJson('/api/mobile/v1/groups/' . $gid . '/leave')->assertNotFound();

        $this->withHeaders($E)->postJson('/api/mobile/v1/groups/' . $gid . '/leave')->assertOk()->assertJsonPath('data.left', true);
        $this->assertNull(Conversation::roleOf($gid, (string) $this->employee->id));
        $this->assertSame('member', Conversation::roleOf($gid, (string) $this->owner->id), 'مغادرتي لا تمسّ غيري');

        // المجموعةُ لا تُضاف إليها عضويّةٌ مباشرة ولو من مالكها (أمنُ الجمهور التاريخيّ — 422)،
        // وعضوُها غيرُ المالك لا يدير أصلاً (403)
        $this->withHeaders($E)->postJson('/api/mobile/v1/conversations/' . $newId . '/members',
            ['user_id' => (string) $client->id])->assertStatus(422);
        $this->withHeaders($this->h($this->owner))->postJson('/api/mobile/v1/conversations/' . $newId . '/members',
            ['user_id' => (string) $client->id])->assertStatus(403);
        $this->assertNull(Conversation::roleOf($newId, (string) $client->id));
    }

    public function test_dm_edit_and_retract_are_owner_only_and_hidden_from_strangers(): void
    {
        $this->seedCore();
        $m = DmService::send($this->employee, $this->owner, 'رسالةٌ أولى');
        $this->assertTrue(HubNotification::where('kind', 'dm')->where('record_id', $m->id)->exists());
        $url = '/api/mobile/v1/dm/messages/' . $m->id;

        $this->withHeaders($this->h($this->viewer))->patchJson($url, ['body' => 'تطفّل'])->assertNotFound();
        $O = $this->h($this->owner);
        $this->withHeaders($O)->patchJson($url, ['body' => 'تحريفُ كلام غيري'])->assertForbidden();
        $this->withHeaders($O)->deleteJson($url)->assertForbidden();

        $E = $this->h($this->employee);
        $this->withHeaders($E)->patchJson($url, ['body' => '   '])->assertStatus(422);
        $this->withHeaders($E)->patchJson($url, ['body' => 'رسالةٌ مصحَّحة'])->assertOk()
            ->assertJsonPath('data.message.body', 'رسالةٌ مصحَّحة')->assertJsonPath('data.message.edited', true);

        $this->withHeaders($E)->deleteJson($url)->assertOk()->assertJsonPath('data.message.deleted', true)
            ->assertJsonPath('data.message.body', null);
        $this->assertNotNull(DmMessage::find($m->id)->deleted_at, 'حذفٌ ناعمٌ يبقى أثرُه');
        $this->assertFalse(HubNotification::where('kind', 'dm')->where('record_id', $m->id)->exists(), 'يُسحب الإشعارُ معها');
        $this->withHeaders($E)->patchJson($url, ['body' => 'بعد السحب'])->assertStatus(422);
        $this->withHeaders($E)->deleteJson($url)->assertStatus(422);
    }

    public function test_message_search_never_crosses_membership_or_threads(): void
    {
        $this->seedCore();
        $mine = $this->channel($this->owner, 'قناتي');
        $this->member($mine, $this->employee);
        $theirs = $this->channel($this->owner, 'قناةُ الغير');
        CommentService::create($this->owner, 'channel', (string) $mine->id, 'كلمةُ-سرّ-زرقاء في قناتي', ['conversation_id' => (string) $mine->id]);
        CommentService::create($this->owner, 'channel', (string) $theirs->id, 'كلمةُ-سرّ-زرقاء في قناة الغير', ['conversation_id' => (string) $theirs->id]);
        DmService::send($this->owner, $this->employee, 'كلمةُ-سرّ-زرقاء في خيطي');
        DmService::send($this->owner, $this->viewer, 'كلمةُ-سرّ-زرقاء في خيطِ ثالثَين');

        $H = $this->h($this->employee);
        $res = $this->withHeaders($H)->getJson('/api/mobile/v1/search/messages?q=' . urlencode('كلمةُ-سرّ-زرقاء'))->assertOk();
        $excerpts = array_column($res->json('data.results'), 'excerpt');
        sort($excerpts);
        $this->assertSame(['كلمةُ-سرّ-زرقاء في خيطي', 'كلمةُ-سرّ-زرقاء في قناتي'], $excerpts);
        $res->assertJsonPath('data.total', 2);
        foreach ($res->json('data.results') as $hit) {
            $this->assertContains($hit['type'], ['channel', 'dm']);
            if ($hit['type'] === 'dm') $this->assertSame((string) $this->owner->id, $hit['target']['user_id']);
            if ($hit['type'] === 'channel') $this->assertSame((string) $mine->id, $hit['target']['record_id']);
        }

        // أقلُّ من حرفين لا بحث
        $this->withHeaders($H)->getJson('/api/mobile/v1/search/messages?q=a')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_client_account_is_folded_404_on_every_internal_channel_surface(): void
    {
        $this->seedCore();
        $client = $this->clientUser();
        $conv = $this->channel($this->owner, 'قناة', ['visibility' => 'public']);
        $m = DmService::send($this->owner, $this->employee, 'رسالة');
        $H = $this->h($client);

        $this->withHeaders($H)->getJson('/api/mobile/v1/conversations/directory')->assertNotFound();
        $this->withHeaders($H)->postJson('/api/mobile/v1/conversations', ['title' => 'x'])->assertNotFound();
        $this->withHeaders($H)->postJson('/api/mobile/v1/conversations/' . $conv->id . '/join')->assertNotFound();
        $this->withHeaders($H)->getJson('/api/mobile/v1/conversations/' . $conv->id . '/members')->assertNotFound();
        $this->withHeaders($H)->postJson('/api/mobile/v1/groups', ['participants' => [(string) $this->owner->id]])->assertNotFound();
        $this->withHeaders($H)->patchJson('/api/mobile/v1/dm/messages/' . $m->id, ['body' => 'x'])->assertNotFound();
        $this->withHeaders($H)->getJson('/api/mobile/v1/search/messages?q=' . urlencode('رسالة'))->assertNotFound();
        $this->assertNull(Conversation::roleOf((string) $conv->id, (string) $client->id));
    }
}
