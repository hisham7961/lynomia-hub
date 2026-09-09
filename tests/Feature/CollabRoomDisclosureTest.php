<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationMember;
use Tests\TestCase;

/**
 * **تمييزُ الجمهور — غرفُ العميل والداخليّة** (المرحلة ٧ · §9).
 *
 * العزلُ فيزيائيٌّ صلبٌ ومُثبَتٌ في `WorkOsProjectRoomsTest` (صفّان منفصلان لا علامةُ
 * رسالة). هذا الملفُّ يحرس **التمييزَ العرضيَّ فوقه**: لا يُخطئ أحدٌ فيكتب سرّاً داخليّاً
 * في محادثةٍ يبلغها العميل — تحذيرٌ صريحٌ على الناشرِ في الشاشةِ الكاملةِ والمركز.
 */
class CollabRoomDisclosureTest extends TestCase
{
    private function channel(string $title, string $audience): Conversation
    {
        $conv = Conversation::create(['kind' => 'channel', 'title' => $title,
            'audience' => $audience, 'created_by' => $this->owner->id]);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->owner->id, 'role' => 'owner']);

        return $conv;
    }

    public function test_client_audience_channel_shows_a_disclosure_warning_on_the_full_page(): void
    {
        $this->seedCore();
        $clientCh = $this->channel('غرفةُ العميل', 'client');

        $this->actingAs($this->owner)->get(route('conversations.show', $clientCh->id))
            ->assertOk()->assertSee('يبلغها العميل');
    }

    public function test_internal_channel_shows_no_client_disclosure_warning(): void
    {
        $this->seedCore();
        $internal = $this->channel('قناةٌ داخليّة', 'internal');

        $this->actingAs($this->owner)->get(route('conversations.show', $internal->id))
            ->assertOk()->assertDontSee('يبلغها العميل عبر بوابته');
    }

    public function test_center_client_room_shows_disclosure_when_selected(): void
    {
        $this->seedCore();
        $clientRoom = Conversation::create(['kind' => 'channel', 'title' => 'غرفةُ مشروعِ العميل',
            'audience' => 'client', 'project_id' => 'p-9', 'created_by' => $this->owner->id]);
        ConversationMember::create(['conversation_id' => $clientRoom->id, 'user_id' => $this->owner->id, 'role' => 'owner']);

        $this->actingAs($this->owner)->get(route('collab.center', ['c' => $clientRoom->id]))
            ->assertOk()
            ->assertSee('غرفةُ عميل')          // شارةُ الترويسة
            ->assertSee('يبلغها العميل');       // تحذيرُ الناشر
    }
}
