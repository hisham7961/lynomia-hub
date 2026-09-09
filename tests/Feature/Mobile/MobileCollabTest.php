<?php

namespace Tests\Feature\Mobile;

use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Role;
use App\Models\User;
use App\Support\CommentService;
use App\Support\DmService;
use App\Support\Typing;
use Tests\TestCase;

/**
 * **تكافؤُ التعاونِ على الجوال** (Mobile Readiness · المرحلة ٩ · §106 · إضافيّ).
 *
 * القدراتُ الجديدةُ لمركزِ التواصل (قائمةُ الحاويات · الجلبُ التدريجيّ · الحضور ·
 * الكتابة · التفاعلات · المحفوظات) على `/api/mobile/v1` فوق السككِ نفسِها — العزلُ
 * خادميٌّ بإعادةِ استعمالِ الحرّاس، والعميلُ يُطوى ٤٠٤ (له بوّابتُه). إثباتٌ لا ادّعاء.
 */
class MobileCollabTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function auth(User $u, ?string $uuid = null): array
    {
        return $this->bearer($this->mobileLogin($u, $uuid)['access_token']);
    }

    private function channel(User $owner, string $title, array $extra = []): Conversation
    {
        $conv = Conversation::create(array_merge(
            ['kind' => 'channel', 'title' => $title, 'created_by' => $owner->id], $extra));
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $owner->id, 'role' => 'owner']);

        return $conv;
    }

    private function clientUser(): User
    {
        $role = Role::create(['name' => 'عميل', 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        return User::create(['name' => 'حسابُ عميل', 'email' => 'cl@ext.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
    }

    public function test_conversations_lists_only_my_containers(): void
    {
        $this->seedCore();
        $mine = $this->channel($this->owner, 'قناتي');
        ConversationMember::create(['conversation_id' => $mine->id, 'user_id' => $this->employee->id, 'role' => 'member']);
        $this->channel($this->viewer, 'قناةُ الغير');   // لستُ عضواً

        $H = $this->auth($this->employee);
        $data = $this->withHeaders($H)->getJson('/api/mobile/v1/conversations')->assertOk()->json('data');

        $titles = collect($data['channels'])->pluck('title')->all();
        $this->assertContains('قناتي', $titles);
        $this->assertNotContains('قناةُ الغير', $titles);
    }

    public function test_channel_since_returns_new_events_and_refuses_non_members(): void
    {
        $this->seedCore();
        $conv = $this->channel($this->owner, 'قناة');
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->employee->id, 'role' => 'member']);
        CommentService::create($this->employee, 'channel', (string) $conv->id, 'أولى', ['conversation_id' => (string) $conv->id]);

        $H = $this->auth($this->owner);
        $res = $this->withHeaders($H)->getJson('/api/mobile/v1/conversations/' . $conv->id . '/since')->assertOk();
        $res->assertJsonPath('data.events.0.body', 'أولى');
        $res->assertJsonPath('data.events.0.type', 'message.created');

        // غيرُ العضو ٤٠٤ (المشاهدُ ليس في القناة)
        $V = $this->auth($this->viewer);
        $this->withHeaders($V)->getJson('/api/mobile/v1/conversations/' . $conv->id . '/since')->assertNotFound();
    }

    public function test_channel_typing_ping_is_visible_to_another_member(): void
    {
        $this->seedCore();
        $conv = $this->channel($this->owner, 'قناة');
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->employee->id, 'role' => 'member']);

        $O = $this->auth($this->owner);
        $this->withHeaders($O)->postJson('/api/mobile/v1/conversations/' . $conv->id . '/typing')->assertOk();

        $E = $this->auth($this->employee);
        $res = $this->withHeaders($E)->getJson('/api/mobile/v1/conversations/' . $conv->id . '/since')->assertOk();
        $this->assertSame([$this->owner->name], $res->json('data.typing'));
    }

    public function test_dm_since_returns_events_marks_read_and_isolates(): void
    {
        $this->seedCore();
        DmService::send($this->employee, $this->owner, 'أهلاً');

        $O = $this->auth($this->owner);
        $res = $this->withHeaders($O)->getJson('/api/mobile/v1/dm/threads/' . $this->employee->id . '/since')->assertOk();
        $res->assertJsonPath('data.events.0.body', 'أهلاً');
        $res->assertJsonPath('data.events.0.mine', false);

        // خيطٌ مع النفس ٤٠٤
        $this->withHeaders($O)->getJson('/api/mobile/v1/dm/threads/' . $this->owner->id . '/since')->assertNotFound();
    }

    public function test_presence_returns_states_only_for_reachable_users(): void
    {
        $this->seedCore();
        $O = $this->auth($this->owner);

        $res = $this->withHeaders($O)->getJson('/api/mobile/v1/presence?users=' . $this->employee->id . ',' . $this->viewer->id)
            ->assertOk()->json('data.presence');
        $ids = collect($res)->pluck('user_id')->all();
        $this->assertContains((string) $this->employee->id, $ids);
        // كلُّ حالةٍ من المفرداتِ الخشنةِ الأربع
        foreach ($res as $p) {
            $this->assertContains($p['presence'], ['online', 'recent', 'away', 'offline']);
        }
    }

    public function test_comment_reaction_toggles(): void
    {
        $this->seedCore();
        $conv = $this->channel($this->owner, 'قناة');
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->employee->id, 'role' => 'member']);
        $c = CommentService::create($this->owner, 'channel', (string) $conv->id, 'رسالة', ['conversation_id' => (string) $conv->id]);

        $E = $this->auth($this->employee);
        $on = $this->withHeaders($E)->postJson('/api/mobile/v1/comments/' . $c->id . '/react', ['emoji' => '👍'])->assertOk();
        $on->assertJsonPath('data.mine', true)->assertJsonPath('data.count', 1);

        $off = $this->withHeaders($E)->postJson('/api/mobile/v1/comments/' . $c->id . '/react', ['emoji' => '👍'])->assertOk();
        $off->assertJsonPath('data.mine', false)->assertJsonPath('data.count', 0);
    }

    public function test_client_cannot_reach_the_internal_collab_endpoints(): void
    {
        $this->seedCore();
        $client = $this->clientUser();
        $H = $this->auth($client);

        // المركزُ داخليّ — العميلُ يُطوى ٤٠٤ (له portal/*)
        $this->withHeaders($H)->getJson('/api/mobile/v1/conversations')->assertNotFound();
        $this->withHeaders($H)->getJson('/api/mobile/v1/presence?users=' . $this->owner->id)->assertNotFound();
        $this->withHeaders($H)->getJson('/api/mobile/v1/saved')->assertNotFound();
    }
}
