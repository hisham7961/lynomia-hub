<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\DmController;
use App\Models\DmMessage;
use App\Models\SessionLog;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الرسائل كانت صفحتين تُفتحان بالتناوب: قائمةٌ ثم خيط، بلا حضورٍ ولا فواصل
 * أيامٍ ولا بحث — وفي RTL كان **الصادر يمين والوارد يسار**، عكس ما يعرفه كل
 * مستخدمٍ عربيٍّ لتطبيقات المحادثة.
 *
 * والحضور كان موجوداً ولا يُقرأ: نبضة `sessions_log.last_seen_at` حيّة منذ
 * v2.181 — فمن هو متصلٌ الآن معلومٌ للنظام ومجهولٌ للمستخدم.
 */
class MessengerTest extends TestCase
{
    public function test_the_thread_and_the_list_live_on_one_screen(): void
    {
        $this->seedCore();
        DmMessage::create(['id' => (string) Str::uuid(),
            'thread_key' => DmMessage::threadKey($this->owner->id, $this->employee->id),
            'from_id' => $this->employee->id, 'to_id' => $this->owner->id,
            'body' => 'رسالةٌ من زميلة', 'created_at' => now(), 'updated_at' => now()]);

        $html = $this->actingAs($this->owner)->get("/dm/{$this->employee->id}")->assertOk()->getContent();

        $this->assertStringContainsString('رسالةٌ من زميلة', $html);
        $this->assertStringContainsString('ابحث في المحادثات', $html,
            'الخيط يُفتح وحده بلا قائمة المحادثات — تنقّلٌ ذهاباً وإياباً');
        $this->assertStringContainsString('dmwrap', $html);
    }

    /** الصادر يسار والوارد يمين — كتطبيقات المحادثة العربية */
    public function test_outgoing_and_incoming_sit_on_opposite_sides(): void
    {
        $this->seedCore();
        $k = DmMessage::threadKey($this->owner->id, $this->employee->id);
        DmMessage::create(['id' => (string) Str::uuid(), 'thread_key' => $k,
            'from_id' => $this->owner->id, 'to_id' => $this->employee->id,
            'body' => 'صادرة مني', 'created_at' => now(), 'updated_at' => now()]);
        DmMessage::create(['id' => (string) Str::uuid(), 'thread_key' => $k,
            'from_id' => $this->employee->id, 'to_id' => $this->owner->id,
            'body' => 'واردة إليّ', 'created_at' => now(), 'updated_at' => now()]);

        $html = $this->actingAs($this->owner)->get("/dm/{$this->employee->id}")->assertOk()->getContent();

        // الخيط وحده: العنوان يظهر في معاينة القائمة أيضاً
        $flat = preg_replace('/\s+/u', ' ', \Illuminate\Support\Str::after($html, 'id="dmbox"'));
        $out = mb_strpos($flat, 'صادرة مني');
        $in  = mb_strpos($flat, 'واردة إليّ');
        $this->assertStringContainsString('align-self:flex-end',
            mb_substr($flat, max(0, $out - 420), 420), 'الصادرة ليست في جهة الصادر');
        $this->assertStringContainsString('align-self:flex-start',
            mb_substr($flat, max(0, $in - 420), 420), 'الواردة ليست في جهة الوارد');
    }

    /** الحضور يُقرأ من نبضة الجلسة — لا بنيةَ جديدة */
    public function test_presence_comes_from_the_live_session_heartbeat(): void
    {
        $this->seedCore();
        SessionLog::create(['id' => (string) Str::uuid(), 'user_id' => $this->employee->id,
            'started_at' => now()->subHour(), 'last_seen_at' => now()->subMinute()]);
        SessionLog::create(['id' => (string) Str::uuid(), 'user_id' => $this->viewer->id,
            'started_at' => now()->subDays(2), 'last_seen_at' => now()->subDays(2)]);

        $p = DmController::presence([$this->employee->id, $this->viewer->id, $this->owner->id]);

        $this->assertTrue($p[$this->employee->id]['online'], 'من ظهر قبل دقيقة ليس متصلاً؟');
        $this->assertFalse($p[$this->viewer->id]['online']);
        $this->assertArrayNotHasKey($this->owner->id, $p, 'من لا جلسة له لا حضور له');
    }

    public function test_the_screen_shows_presence_and_day_separators(): void
    {
        $this->seedCore();
        SessionLog::create(['id' => (string) Str::uuid(), 'user_id' => $this->employee->id,
            'started_at' => now(), 'last_seen_at' => now()]);
        $k = DmMessage::threadKey($this->owner->id, $this->employee->id);
        DmMessage::create(['id' => (string) Str::uuid(), 'thread_key' => $k,
            'from_id' => $this->employee->id, 'to_id' => $this->owner->id,
            'body' => 'رسالة أمس', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        DmMessage::create(['id' => (string) Str::uuid(), 'thread_key' => $k,
            'from_id' => $this->employee->id, 'to_id' => $this->owner->id,
            'body' => 'رسالة اليوم', 'created_at' => now(), 'updated_at' => now()]);

        $html = $this->actingAs($this->owner)->get("/dm/{$this->employee->id}")->assertOk()->getContent();

        $this->assertStringContainsString('متصل الآن', $html, 'الحضور معلومٌ للنظام ومجهولٌ للمستخدم');
        $this->assertStringContainsString('أمس', $html, 'لا فواصل أيام');
        $this->assertStringContainsString('اليوم', $html);
    }

    public function test_a_thread_still_belongs_to_its_two_sides_only(): void
    {
        $this->seedCore();
        DmMessage::create(['id' => (string) Str::uuid(),
            'thread_key' => DmMessage::threadKey($this->owner->id, $this->employee->id),
            'from_id' => $this->owner->id, 'to_id' => $this->employee->id,
            'body' => 'سرٌّ بين اثنين', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->viewer)->get("/dm/{$this->employee->id}")->assertOk()
            ->assertDontSee('سرٌّ بين اثنين');
    }
}
