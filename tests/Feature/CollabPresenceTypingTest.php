<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Support\DmService;
use App\Support\Presence;
use App\Support\Typing;
use Tests\TestCase;

/**
 * **مركز التواصل — الحضورُ الخشن ومؤشّرُ الكتابةِ العابر (IMPLEMENT_NOW).**
 *
 * **الحضور** حالةٌ خشنةٌ (متصل/حديثاً/بعيد/غير متصل) تُقرأ من نبضةِ الجلسةِ القائمة
 * بلا كتاباتٍ جديدة — تجربةُ تواصلٍ لا مراقبة. **الكتابة** عابرةٌ لا تُخزَّن ولا تُدقَّق،
 * تنتهي ذاتيّاً، ومُنطَّقةٌ بأعضاءِ المحادثةِ المصرَّح لهم (الحارسُ عند النبضِ والقراءة).
 * كلاهما محايدُ النقل: يُسلَّم عبر عقدِ الاستطلاع نفسِه فيستبدله websocket لاحقاً.
 * إثباتٌ لا ادّعاء.
 */
class CollabPresenceTypingTest extends TestCase
{
    /* ───────── الحضور: حالةٌ خشنةٌ من آخرِ ظهور (دالّةٌ نقيّة) ───────── */

    public function test_presence_state_falls_into_coarse_buckets(): void
    {
        $this->assertSame(Presence::OFFLINE, Presence::state(null), 'لا نبضةَ ⇒ غير متصل');
        $this->assertSame(Presence::ONLINE, Presence::state(now()->subMinutes(2)));
        $this->assertSame(Presence::RECENT, Presence::state(now()->subMinutes(10)));
        $this->assertSame(Presence::AWAY, Presence::state(now()->subMinutes(40)));
        $this->assertSame(Presence::OFFLINE, Presence::state(now()->subMinutes(120)));
    }

    /* ───────── الكتابة في قناة: يراها عضوٌ آخر، لا القارئُ نفسُه ───────── */

    public function test_typing_ping_in_a_channel_is_visible_to_another_member(): void
    {
        $this->seedCore();
        $conv = $this->channelWith($this->owner, $this->employee);

        // المالكُ يُلمِّح «أكتب الآن» — عضويّةٌ تكفي، عابرة
        $this->actingAs($this->owner)->post(route('conversations.typing', $conv->id))->assertNoContent();

        // الموظفةُ تستطلع فترى اسمَ المالكِ يكتب
        $res = $this->actingAs($this->employee)->getJson(route('conversations.since', $conv->id))->assertOk();
        $this->assertSame([$this->owner->name], $res->json('typing'));

        // والمالكُ لا يرى نفسَه يكتب (الإشارةُ للطرفِ الآخرِ لا لِمُطلِقها)
        $mine = $this->actingAs($this->owner)->getJson(route('conversations.since', $conv->id))->assertOk();
        $this->assertSame([], $mine->json('typing'));
    }

    public function test_typing_signal_expires_after_its_window(): void
    {
        $this->seedCore();
        $conv = $this->channelWith($this->owner, $this->employee);

        $this->actingAs($this->owner)->post(route('conversations.typing', $conv->id))->assertNoContent();

        // بعد انقضاءِ النافذةِ تُنتهى الإشارةُ لا تُزيَّف — فلا يبقى «يكتب» أبديّاً
        $this->travel(Typing::WINDOW + 2)->seconds();

        $res = $this->actingAs($this->employee)->getJson(route('conversations.since', $conv->id))->assertOk();
        $this->assertSame([], $res->json('typing'), 'الإشارةُ المتقادمةُ لم تُنتَهَ');
    }

    public function test_non_member_cannot_ping_typing_in_a_channel(): void
    {
        $this->seedCore();
        // قناةٌ للمالكِ وحده — المشاهدُ ليس عضواً
        $conv = Conversation::create(['kind' => 'channel', 'title' => 'مغلقة', 'created_by' => $this->owner->id]);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->owner->id, 'role' => 'owner']);

        // الحارسُ عند نقطةِ النبض: غيرُ العضوِ لا يُلمِّح (404 لا يكشف الوجود)
        $this->actingAs($this->viewer)->post(route('conversations.typing', $conv->id))->assertNotFound();

        // ولا أثرَ لإشارةٍ في النطاق
        $this->assertSame([], Typing::current((string) $conv->id, (string) $this->owner->id));
    }

    /* ───────── الكتابة في محادثةٍ مباشرة: يراها الطرفُ الآخر ───────── */

    public function test_dm_typing_ping_is_visible_to_the_other_party(): void
    {
        $this->seedCore();
        // خيطٌ قائمٌ بين الطرفين
        DmService::send($this->employee, $this->owner, 'مرحبا');

        // الموظفةُ تُلمِّح أنها تكتب للمالك
        $this->actingAs($this->employee)->post(route('dm.typing', $this->owner->id))->assertNoContent();

        // المالكُ يستطلع خيطَه مع الموظفةِ فيرى أنها تكتب
        $res = $this->actingAs($this->owner)->getJson(route('dm.since', $this->employee->id))->assertOk();
        $this->assertSame([$this->employee->name], $res->json('typing'));
    }

    public function test_dm_typing_to_self_is_refused(): void
    {
        $this->seedCore();

        // لا يُلمِّح أحدٌ لنفسه (404)
        $this->actingAs($this->owner)->post(route('dm.typing', $this->owner->id))->assertNotFound();
    }

    /* ───────── مساعد: قناةٌ بعضوين ───────── */

    private function channelWith($owner, $member): Conversation
    {
        $conv = Conversation::create(['kind' => 'channel', 'title' => 'قناة', 'created_by' => $owner->id]);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $owner->id, 'role' => 'owner']);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $member->id, 'role' => 'member']);

        return $conv;
    }
}
