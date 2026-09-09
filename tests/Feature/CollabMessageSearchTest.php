<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\DmMessage;
use App\Support\CommentService;
use App\Support\DmService;
use App\Support\MessageLink;
use Tests\TestCase;

/**
 * **مركز التواصل (المرحلة ٣·هـ · §25/§26) — بحثُ الرسائل والروابطُ الدائمة.**
 *
 * يبحث في **نصّ** الرسائل عبر السطوح (خلاصة/قنوات/محادثات) — **ما يراه القارئ فقط**:
 * لا رسالةَ قناةٍ ليس عضواً فيها، ولا محادثةٌ ليس طرفاً فيها. وكلُّ نتيجةٍ برابطٍ
 * دائمٍ إلى الرسالةِ بعينها. إثباتٌ لا ادّعاء.
 */
class CollabMessageSearchTest extends TestCase
{
    public function test_search_finds_only_messages_the_reader_can_see(): void
    {
        $this->seedCore();

        // ما يراه المالك: خلاصةٌ + قناةٌ هو عضوها + محادثتُه
        CommentService::create($this->owner, 'feed', null, 'علامةُ الخلاصة زئبق');

        $mine = Conversation::create(['kind' => 'channel', 'title' => 'قناتي', 'created_by' => $this->owner->id]);
        ConversationMember::create(['conversation_id' => $mine->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        ConversationMember::create(['conversation_id' => $mine->id, 'user_id' => $this->employee->id, 'role' => 'member']);
        CommentService::create($this->employee, 'channel', (string) $mine->id, 'علامةُ القناة زئبق', ['conversation_id' => (string) $mine->id]);

        DmService::send($this->owner, $this->employee, 'علامةُ المحادثة زئبق');

        // ما لا يراه المالك: قناةٌ ليس عضواً فيها + محادثةٌ بين طرفَين آخرَين
        $other = Conversation::create(['kind' => 'channel', 'title' => 'قناةٌ أخرى', 'created_by' => $this->employee->id]);
        ConversationMember::create(['conversation_id' => $other->id, 'user_id' => $this->employee->id, 'role' => 'owner']);
        ConversationMember::create(['conversation_id' => $other->id, 'user_id' => $this->viewer->id, 'role' => 'member']);
        CommentService::create($this->employee, 'channel', (string) $other->id, 'سرُّ القناة الأخرى زئبق', ['conversation_id' => (string) $other->id]);

        DmService::send($this->employee, $this->viewer, 'سرُّهما زئبق');

        $res = $this->actingAs($this->owner)->get('/search/messages?q=' . urlencode('زئبق'))->assertOk();

        // يرى الثلاثةَ التي يملك رؤيتَها …
        $res->assertSee('علامةُ الخلاصة زئبق');
        $res->assertSee('علامةُ القناة زئبق');
        $res->assertSee('علامةُ المحادثة زئبق');

        // … ولا يرى ما ليس طرفاً/عضواً فيه (العزلُ خادميّ لا واجهيّ)
        $res->assertDontSee('سرُّ القناة الأخرى زئبق');
        $res->assertDontSee('سرُّهما زئبق');
    }

    public function test_short_query_returns_no_results(): void
    {
        $this->seedCore();
        CommentService::create($this->owner, 'feed', null, 'منشورٌ ما');

        $this->actingAs($this->owner)->get('/search/messages?q=' . urlencode('ا'))
            ->assertOk()->assertSee('حرفين على الأقل');
    }

    /* ───────── §26 الروابطُ الدائمة ───────── */

    public function test_permalinks_point_to_the_host_with_a_message_anchor(): void
    {
        $this->seedCore();

        $feed = CommentService::create($this->owner, 'feed', null, 'منشور');
        $this->assertSame(route('feed') . '#c-' . $feed->id, MessageLink::comment($feed));

        $conv = Conversation::create(['kind' => 'channel', 'title' => 'ق', 'created_by' => $this->owner->id]);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        $chan = CommentService::create($this->owner, 'channel', (string) $conv->id, 'رسالة', ['conversation_id' => (string) $conv->id]);
        $this->assertSame(route('conversations.show', $conv->id) . '#c-' . $chan->id, MessageLink::comment($chan));

        $dm = DmService::send($this->owner, $this->employee, 'مباشرة');
        // من منظور المالك: خيطُ الطرفِ الآخر (الموظفة) مع مرساةِ الرسالة
        $this->assertSame(route('dm.thread', $this->employee->id) . '#dm-' . $dm->id,
            MessageLink::dm($dm, (string) $this->owner->id));
    }
}
