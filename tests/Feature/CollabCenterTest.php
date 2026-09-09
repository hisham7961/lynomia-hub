<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Role;
use App\Models\User;
use App\Support\CommentService;
use App\Support\DmService;
use Tests\TestCase;

/**
 * **مركزُ التواصلِ الموحّد — الألواحُ الثلاثة** (المرحلة ٦ · §106).
 *
 * السكّةُ مرآةُ ما يراه صاحبُها لا بابٌ يلتفّ على الحارس: تُدرَج القنواتُ والغرفُ
 * والمجموعاتُ والمحادثاتُ التي هو عضوٌ فيها ضمن نطاقه، ويمرّ الاختيارُ بالحارسِ نفسِه
 * (`guardConversation`/`dmReachable`) فلا يفتح `?c=`/`?dm=` ما ليس لصاحبه. والعميلُ لا
 * يبلغ المركزَ الداخليّ أصلاً. إثباتٌ لا ادّعاء.
 */
class CollabCenterTest extends TestCase
{
    private function channel(User $owner, string $title, array $extra = []): Conversation
    {
        $conv = Conversation::create(array_merge(
            ['kind' => 'channel', 'title' => $title, 'created_by' => $owner->id], $extra
        ));
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $owner->id, 'role' => 'owner']);

        return $conv;
    }

    private function client(): User
    {
        $role = Role::create(['name' => 'عميل', 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        return User::create(['name' => 'عميلٌ خارجيّ', 'email' => 'client@ext.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
    }

    public function test_center_loads_and_lists_only_my_conversations(): void
    {
        $this->seedCore();
        $mine = $this->channel($this->owner, 'قناتي');
        ConversationMember::create(['conversation_id' => $mine->id, 'user_id' => $this->employee->id, 'role' => 'member']);

        // قناةٌ لستُ عضواً فيها — لا تظهر في سكّتي
        $foreign = $this->channel($this->viewer, 'قناةُ الغير');

        $res = $this->actingAs($this->employee)->get(route('collab.center'))->assertOk();
        $res->assertSee('مركز التواصل');
        $res->assertSee('قناتي');
        $res->assertDontSee('قناةُ الغير');
    }

    public function test_selecting_a_channel_i_am_not_a_member_of_is_refused(): void
    {
        $this->seedCore();
        $foreign = $this->channel($this->viewer, 'سرّية');

        // الاختيارُ يمرّ بالحارس — غيرُ العضو ٤٠٤ (لا كشفَ وجود)
        $this->actingAs($this->employee)->get(route('collab.center', ['c' => $foreign->id]))->assertNotFound();
    }

    public function test_selecting_my_channel_renders_timeline_and_participants(): void
    {
        $this->seedCore();
        $conv = $this->channel($this->owner, 'قناةُ العمل');
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->employee->id, 'role' => 'member']);
        CommentService::create($this->employee, 'channel', (string) $conv->id, 'رسالةٌ في الخيط', ['conversation_id' => (string) $conv->id]);

        $res = $this->actingAs($this->owner)->get(route('collab.center', ['c' => $conv->id]))->assertOk();
        $res->assertSee('رسالةٌ في الخيط');       // اللوحُ الأوسط: الخطُّ الزمنيّ
        $res->assertSee('المشاركون');              // اللوحُ الأيمن: السياق
        $res->assertSee($this->employee->name);
    }

    public function test_selecting_my_channel_marks_it_read(): void
    {
        $this->seedCore();
        $conv = $this->channel($this->owner, 'قناةٌ للقراءة');
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->employee->id, 'role' => 'member']);
        // رسالةٌ من غيري ⇒ غيرُ مقروءة قبل الفتح
        $this->travel(2)->seconds();
        CommentService::create($this->employee, 'channel', (string) $conv->id, 'جديدة', ['conversation_id' => (string) $conv->id]);

        $before = \App\Http\Controllers\Web\ConversationController::unreadCounts([$conv->id], (string) $this->owner->id);
        $this->assertSame(1, $before[$conv->id] ?? 0);

        $this->actingAs($this->owner)->get(route('collab.center', ['c' => $conv->id]))->assertOk();

        $after = \App\Http\Controllers\Web\ConversationController::unreadCounts([$conv->id], (string) $this->owner->id);
        $this->assertSame(0, $after[$conv->id] ?? 0, 'فتحُ الخيطِ لم يختمه مقروءاً');
    }

    public function test_room_shows_audience_distinction(): void
    {
        $this->seedCore();
        // غرفتان لمشروع: داخليّةٌ وغرفةُ عميل — التمييزُ لا يُخطَأ (المرحلة ٧)
        $internal = $this->channel($this->owner, 'غرفةٌ داخليّة', ['project_id' => 'p-77', 'audience' => 'internal']);
        $clientRoom = $this->channel($this->owner, 'غرفةُ العميل', ['project_id' => 'p-77', 'audience' => 'client']);

        $res = $this->actingAs($this->owner)->get(route('collab.center'))->assertOk();
        $res->assertSee('غرفُ المشاريع');       // قسمُ الغرف
        $res->assertSee('عميل');                  // شارةُ جمهورِ العميل
    }

    public function test_selecting_a_dm_renders_the_thread(): void
    {
        $this->seedCore();
        DmService::send($this->employee, $this->owner, 'أهلاً في المحادثة');

        $res = $this->actingAs($this->owner)->get(route('collab.center', ['dm' => $this->employee->id]))->assertOk();
        $res->assertSee('أهلاً في المحادثة');
        $res->assertSee($this->employee->name);
    }

    public function test_client_cannot_reach_the_center(): void
    {
        $this->seedCore();
        $client = $this->client();

        // العميلُ لا يبلغ المركزَ الداخليّ (PortalGuard + abort_if) — ٤٠٤
        $this->actingAs($client)->get(route('collab.center'))->assertNotFound();
    }
}
