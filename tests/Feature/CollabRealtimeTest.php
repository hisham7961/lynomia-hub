<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\DmMessage;
use App\Support\CommentService;
use App\Support\DmService;
use Tests\TestCase;

/**
 * **مركز التواصل (المرحلة ٥ · §38/§39) — الزمنُ الحقيقيّ: الجلبُ التدريجيّ.**
 *
 * استطلاعٌ تدريجيٌّ بمؤشّر `since` (keyset): كلُّ نبضةٍ تجلب **الجديدَ فقط** لا الخيطَ
 * كلَّه. عقدُ أحداثٍ مستقرّ (message.created/deleted) جاهزٌ لبثٍّ لاحق. والعزلُ خادميّ:
 * لا يستطلع غيرُ العضوِ قناةً. إثباتٌ لا ادّعاء.
 */
class CollabRealtimeTest extends TestCase
{
    /* ───────── §39 قناة: الجديدُ فقط بعد المؤشّر ───────── */

    public function test_channel_since_returns_only_messages_after_the_cursor(): void
    {
        $this->seedCore();
        $conv = Conversation::create(['kind' => 'channel', 'title' => 'ح', 'created_by' => $this->owner->id]);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->employee->id, 'role' => 'member']);

        CommentService::create($this->employee, 'channel', (string) $conv->id, 'أولى', ['conversation_id' => (string) $conv->id]);
        CommentService::create($this->employee, 'channel', (string) $conv->id, 'ثانية', ['conversation_id' => (string) $conv->id]);

        // بلا مؤشّر — من الذيل (كلاهما) + مؤشّرٌ جديد
        $res = $this->actingAs($this->owner)->getJson(route('conversations.since', $conv->id))->assertOk();
        $res->assertJsonCount(2, 'events');
        $cursor = $res->json('cursor');
        $this->assertNotEmpty($cursor);

        // رسالةٌ جديدةٌ بعد المؤشّر (نُقدّم الزمنَ تفادياً لتعادُلِ الثانية)
        $this->travel(2)->seconds();
        CommentService::create($this->employee, 'channel', (string) $conv->id, 'ثالثة', ['conversation_id' => (string) $conv->id]);

        $res2 = $this->actingAs($this->owner)
            ->getJson(route('conversations.since', $conv->id) . '?cursor=' . urlencode($cursor))->assertOk();
        $res2->assertJsonCount(1, 'events');
        $res2->assertJsonPath('events.0.body', 'ثالثة');
        $res2->assertJsonPath('events.0.type', 'message.created');
    }

    public function test_channel_since_is_refused_to_non_members(): void
    {
        $this->seedCore();
        $conv = Conversation::create(['kind' => 'channel', 'title' => 'مغلقة', 'created_by' => $this->owner->id]);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->owner->id, 'role' => 'owner']);

        $this->actingAs($this->viewer)->getJson(route('conversations.since', $conv->id))->assertNotFound();
    }

    /* ───────── §39 محادثة مباشرة: الجديدُ فقط + ختمُ القراءة + أثرُ الحذف ───────── */

    public function test_dm_since_returns_new_messages_marks_read_and_signals_deletes(): void
    {
        $this->seedCore();
        $m1 = DmService::send($this->employee, $this->owner, 'مرحبا');

        // المالكُ يستطلع — يرى الرسالةَ ويُختَم واردُه مقروءاً
        $res = $this->actingAs($this->owner)->getJson(route('dm.since', $this->employee->id))->assertOk();
        $res->assertJsonCount(1, 'events');
        $res->assertJsonPath('events.0.body', 'مرحبا');
        $this->assertNotNull($m1->fresh()->read_at, 'الاستطلاعُ لم يختم الواردَ مقروءاً');
        $cursor = $res->json('cursor');

        // حذفُ رسالةٍ يظهر أثراً (message.deleted) لا اختفاءً صامتاً
        $this->travel(2)->seconds();
        $m2 = DmService::send($this->employee, $this->owner, 'خطأ');
        $m2->forceFill(['deleted_at' => now()])->save();

        $res2 = $this->actingAs($this->owner)
            ->getJson(route('dm.since', $this->employee->id) . '?cursor=' . urlencode($cursor))->assertOk();
        $res2->assertJsonPath('events.0.type', 'message.deleted');
        $res2->assertJsonPath('events.0.deleted', true);
    }
}
