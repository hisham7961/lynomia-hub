<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\DmMessage;
use App\Models\HubNotification;
use App\Models\SavedMessage;
use App\Support\CommentService;
use App\Support\DmService;
use Tests\TestCase;

/**
 * **مركز التواصل (المرحلة ٣·ب · §22/§27) — التحرير والمحفوظات.**
 *
 * تحريرُ الرسالة يختم `edited_at` صادقاً ويبقى لصاحبها وحده (تعليقاً أو DM)،
 * والمحفوظاتُ الشخصيّة مرجعٌ لا نسخُ محتوى — تُخوَّل عند الحفظ وعند الفتح. إثباتٌ لا ادّعاء.
 */
class CollabEditSavedTest extends TestCase
{
    /* ───────── §22 تحريرُ التعليق ───────── */

    public function test_comment_edit_is_owner_only_and_stamps_edited_at(): void
    {
        $this->seedCore();
        $c = CommentService::create($this->owner, 'feed', null, 'الأصل');
        $this->assertNull($c->edited_at);

        // غيرُ صاحبه لا يحرّره
        $this->actingAs($this->employee)->post('/comments/' . $c->id . '/edit', ['body' => 'اقتحام'])
            ->assertForbidden();

        // صاحبُه يحرّره — يُختم edited_at ويُحدَّث النص
        $this->actingAs($this->owner)->post('/comments/' . $c->id . '/edit', ['body' => 'النص المعدّل'])
            ->assertRedirect();
        $c->refresh();
        $this->assertSame('النص المعدّل', $c->body);
        $this->assertNotNull($c->edited_at, 'التحريرُ لم يختم edited_at');
    }

    public function test_comment_edit_notifies_only_newly_added_mentions(): void
    {
        $this->seedCore();
        $c = CommentService::create($this->owner, 'feed', null, 'بلا ذكرٍ بعد');
        $this->assertSame(0, HubNotification::where('user_id', $this->employee->id)->where('kind', 'mention')->count());

        // تحريرٌ يُضيف ذكرَ موظفة — تُشعَر مرّةً واحدة
        $this->actingAs($this->owner)->post('/comments/' . $c->id . '/edit', ['body' => 'انظري @موظفة']);
        $this->assertSame(1, HubNotification::where('user_id', $this->employee->id)->where('kind', 'mention')->count());

        // تحريرٌ ثانٍ بالذكر نفسِه لا يُعيد الإشعار (لا سبامَ تحرير)
        $this->actingAs($this->owner)->post('/comments/' . $c->id . '/edit', ['body' => 'انظري @موظفة رجاءً']);
        $this->assertSame(1, HubNotification::where('user_id', $this->employee->id)->where('kind', 'mention')->count());
    }

    /* ───────── §22 تحريرُ الرسالة المباشرة ───────── */

    public function test_dm_edit_is_sender_only_not_deleted_and_stamps_edited_at(): void
    {
        $this->seedCore();
        $m = DmService::send($this->owner, $this->employee, 'رسالةٌ فيها خطأ');

        // المستلمُ لا يحرّر رسالةَ غيره
        $this->actingAs($this->employee)->post('/dm/msg/' . $m->id . '/edit', ['body' => 'تلاعب'])
            ->assertForbidden();

        // المُرسِلُ يحرّرها — يُختم edited_at
        $this->actingAs($this->owner)->post('/dm/msg/' . $m->id . '/edit', ['body' => 'رسالةٌ مصحّحة'])
            ->assertRedirect();
        $m->refresh();
        $this->assertSame('رسالةٌ مصحّحة', $m->body);
        $this->assertNotNull($m->edited_at);

        // المحذوفةُ لا تُحرَّر
        $m->forceFill(['deleted_at' => now()])->save();
        $this->actingAs($this->owner)->post('/dm/msg/' . $m->id . '/edit', ['body' => 'بعد الحذف'])
            ->assertStatus(422);
    }

    /* ───────── §27 المحفوظات ───────── */

    public function test_saved_toggle_adds_then_removes_a_feed_comment(): void
    {
        $this->seedCore();
        $c = CommentService::create($this->owner, 'feed', null, 'يستحقّ الحفظ');

        // حفظ
        $this->actingAs($this->employee)->post('/saved', ['target_type' => 'comment', 'target_id' => $c->id])
            ->assertRedirect();
        $this->assertSame(1, SavedMessage::where('user_id', $this->employee->id)->count());

        // تبديلٌ يُزيل
        $this->actingAs($this->employee)->post('/saved', ['target_type' => 'comment', 'target_id' => $c->id]);
        $this->assertSame(0, SavedMessage::where('user_id', $this->employee->id)->count());
    }

    public function test_saving_a_channel_message_you_cannot_see_is_refused(): void
    {
        $this->seedCore();
        $conv = Conversation::create(['kind' => 'channel', 'title' => 'مغلقة', 'created_by' => $this->owner->id]);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        $msg = CommentService::create($this->owner, 'channel', (string) $conv->id, 'سرّ', ['conversation_id' => (string) $conv->id]);

        // المشاهدُ ليس عضواً — لا يحفظ ما لا يرى (٤٠٤ نظيرُ guardConversation)
        $this->actingAs($this->viewer)->post('/saved', ['target_type' => 'comment', 'target_id' => $msg->id])
            ->assertNotFound();
        $this->assertSame(0, SavedMessage::where('user_id', $this->viewer->id)->count());
    }

    public function test_saved_index_lists_visible_and_owner_can_remove(): void
    {
        $this->seedCore();
        $c = CommentService::create($this->owner, 'feed', null, 'محفوظٌ ظاهر');
        $s = SavedMessage::create(['user_id' => $this->owner->id, 'target_type' => 'comment', 'target_id' => $c->id]);

        $this->actingAs($this->owner)->get('/saved')->assertOk()->assertSee('محفوظٌ ظاهر');

        // إزالةُ محفوظةٍ بمعرّفها — لصاحبها
        $this->actingAs($this->owner)->delete('/saved/' . $s->id)->assertRedirect();
        $this->assertSame(0, SavedMessage::where('user_id', $this->owner->id)->count());
    }
}
