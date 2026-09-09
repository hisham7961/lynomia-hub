<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\HubNotification;
use App\Support\CommentService;
use Tests\TestCase;

/**
 * **مركز التواصل (المرحلة ٣·ج · §16) — تفضيلُ إشعارِ القناة.**
 *
 * كلُّ عضوٍ يضبط تفضيلَه لكلِّ قناة: «كل الإشعارات» أو «الإشارات فقط» أو «مكتومة».
 * والتفضيلُ **يُحترَم في التفريع** فعلاً لا على الورق: المكتومةُ لا تُشعِر بشيء،
 * و«الإشارات فقط» تمرّر الإشارةَ وتكتم إشعارَ الرد. إثباتٌ لا ادّعاء.
 */
class CollabNotifyPrefTest extends TestCase
{
    /** قناةٌ عضواها المالكُ والموظفة */
    private function channel(): Conversation
    {
        $conv = Conversation::create(['kind' => 'channel', 'title' => 'فريق', 'created_by' => $this->owner->id]);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->employee->id, 'role' => 'member']);

        return $conv;
    }

    private function member(Conversation $conv, string $userId): ConversationMember
    {
        return ConversationMember::where('conversation_id', $conv->id)->where('user_id', $userId)->first();
    }

    private function mentionCount(): int
    {
        return HubNotification::where('user_id', $this->employee->id)->where('kind', 'mention')->count();
    }

    private function replyCount(): int
    {
        return HubNotification::where('user_id', $this->employee->id)->where('kind', 'reply')->count();
    }

    /* ───────── الضبط: العضوُ يضبط تفضيلَه، وغيرُ العضوِ ٤٠٤ ───────── */

    public function test_member_sets_own_pref_and_muted_syncs_the_single_mute(): void
    {
        $this->seedCore();
        $conv = $this->channel();

        $this->actingAs($this->employee)->post(route('conversations.notify', $conv->id), ['pref' => 'muted'])
            ->assertRedirect();
        $m = $this->member($conv, $this->employee->id);
        $this->assertSame('muted', $m->notify_pref);
        $this->assertNotNull($m->muted_at, 'تفضيلُ muted لم يزامن muted_at (الكتمُ الواحد)');

        // غيرُ العضو (مشاهد) لا يبلغ الضبطَ — ٤٠٤ نظيرُ الحارس
        $this->actingAs($this->viewer)->post(route('conversations.notify', $conv->id), ['pref' => 'muted'])
            ->assertNotFound();
    }

    /* ───────── التفريع يحترم التفضيل ───────── */

    public function test_muted_channel_suppresses_even_a_mention(): void
    {
        $this->seedCore();
        $conv = $this->channel();
        $this->member($conv, $this->employee->id)->forceFill(['notify_pref' => 'muted'])->save();

        CommentService::create($this->owner, 'channel', (string) $conv->id,
            'خطّة @موظفة راجعيها', ['conversation_id' => (string) $conv->id]);

        $this->assertSame(0, $this->mentionCount(), 'القناةُ المكتومةُ أشعرت بإشارة');
    }

    public function test_mentions_only_passes_the_mention_but_mutes_the_reply(): void
    {
        $this->seedCore();
        $conv = $this->channel();
        $this->member($conv, $this->employee->id)->forceFill(['notify_pref' => 'mentions'])->save();

        // إشارةٌ صريحة — تمرّ
        CommentService::create($this->owner, 'channel', (string) $conv->id,
            'انتبهي @موظفة', ['conversation_id' => (string) $conv->id]);
        $this->assertSame(1, $this->mentionCount(), '«الإشارات فقط» كتمت إشارةً');

        // ردٌّ على رسالةِ الموظفة (بلا إشارة) — يُكتم إشعارُ الرد
        $root = CommentService::create($this->employee, 'channel', (string) $conv->id,
            'اقتراحي', ['conversation_id' => (string) $conv->id]);
        CommentService::create($this->owner, 'channel', (string) $conv->id,
            'ردٌّ عليه', ['parent_id' => $root->id, 'conversation_id' => (string) $conv->id]);
        $this->assertSame(0, $this->replyCount(), '«الإشارات فقط» لم تكتم إشعارَ الرد');
    }

    public function test_default_all_notifies_both_mention_and_reply(): void
    {
        $this->seedCore();
        $conv = $this->channel();   // بلا ضبطٍ = all

        CommentService::create($this->owner, 'channel', (string) $conv->id,
            'مرحبا @موظفة', ['conversation_id' => (string) $conv->id]);
        $this->assertSame(1, $this->mentionCount());

        $root = CommentService::create($this->employee, 'channel', (string) $conv->id,
            'فكرة', ['conversation_id' => (string) $conv->id]);
        CommentService::create($this->owner, 'channel', (string) $conv->id,
            'ردّي', ['parent_id' => $root->id, 'conversation_id' => (string) $conv->id]);
        $this->assertSame(1, $this->replyCount());
    }
}
