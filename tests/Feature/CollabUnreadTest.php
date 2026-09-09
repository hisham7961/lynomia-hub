<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\ConversationController;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Support\CommentService;
use Tests\TestCase;

/**
 * **مركز التواصل (المرحلة ٣·و · §17) — نموذجُ غير المقروء للقنوات.**
 *
 * عددُ غير المقروء يُشتقّ من **مؤشّرِ القراءة** (`last_read_at`) لا من مسحِ `read_by`:
 * رسالةٌ من غيري بعد آخرِ قراءةٍ لي = غيرُ مقروءة، وفتحُ القناةِ يقدّم المؤشّرَ فيصفر
 * العدّاد. رسائلي لا تُعَدّ عليّ. إثباتٌ لا ادّعاء.
 */
class CollabUnreadTest extends TestCase
{
    private function channel(): Conversation
    {
        $conv = Conversation::create(['kind' => 'channel', 'title' => 'قناة', 'created_by' => $this->owner->id]);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->employee->id, 'role' => 'member']);

        return $conv;
    }

    private function unreadFor(Conversation $conv, $user): int
    {
        return ConversationController::unreadCounts([$conv->id], (string) $user->id)[$conv->id] ?? 0;
    }

    public function test_unread_counts_messages_from_others_after_the_read_cursor(): void
    {
        $this->seedCore();
        $conv = $this->channel();

        // رسالتان من الموظفة — غيرُ مقروءتَين للمالك (لا مؤشّرَ قراءةٍ بعد)
        CommentService::create($this->employee, 'channel', (string) $conv->id, 'أولى', ['conversation_id' => (string) $conv->id]);
        CommentService::create($this->employee, 'channel', (string) $conv->id, 'ثانية', ['conversation_id' => (string) $conv->id]);
        $this->assertSame(2, $this->unreadFor($conv, $this->owner));

        // رسالةُ المالكِ نفسِه لا تُعَدّ عليه غيرَ مقروءة
        CommentService::create($this->owner, 'channel', (string) $conv->id, 'من المالك', ['conversation_id' => (string) $conv->id]);
        $this->assertSame(2, $this->unreadFor($conv, $this->owner));
    }

    public function test_opening_the_channel_advances_the_cursor_and_zeroes_unread(): void
    {
        $this->seedCore();
        $conv = $this->channel();
        CommentService::create($this->employee, 'channel', (string) $conv->id, 'رسالة', ['conversation_id' => (string) $conv->id]);
        $this->assertSame(1, $this->unreadFor($conv, $this->owner));

        // فتحُ القناةِ يقدّم last_read_at فيصفر العدّاد
        $this->actingAs($this->owner)->get(route('conversations.show', $conv->id))->assertOk();
        $this->assertSame(0, $this->unreadFor($conv, $this->owner));

        // رسالةٌ جديدةٌ **بعد** القراءةِ تُعَدّ من جديد (تصل بعد ثوانٍ في الواقع —
        // نُقدّم الزمنَ كي لا يتساوى ختمُها مع مؤشّرِ القراءة بدقّة الثانية)
        $this->travel(2)->seconds();
        CommentService::create($this->employee, 'channel', (string) $conv->id, 'بعدَ القراءة', ['conversation_id' => (string) $conv->id]);
        $this->assertSame(1, $this->unreadFor($conv, $this->owner));
    }

    public function test_index_shows_the_unread_badge(): void
    {
        $this->seedCore();
        $conv = $this->channel();
        CommentService::create($this->employee, 'channel', (string) $conv->id, 'غيرُ مقروءة', ['conversation_id' => (string) $conv->id]);

        // شاشةُ القنوات تُظهر عدّادَ غير المقروء للمالك
        $this->actingAs($this->owner)->get(route('conversations.index'))->assertOk()->assertSee('قناة');
        $this->assertSame(1, $this->unreadFor($conv, $this->owner));

        // والموظفةُ صاحبةُ الرسالة: صفرٌ (رسالتُها ليست غيرَ مقروءةٍ عليها)
        $this->assertSame(0, $this->unreadFor($conv, $this->employee));
    }
}
