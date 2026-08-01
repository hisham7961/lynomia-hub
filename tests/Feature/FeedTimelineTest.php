<?php

namespace Tests\Feature;

use App\Models\Comment;
use Tests\TestCase;

/**
 * قناة الفريق كانت **قائمةً واحدة** تنزل بلا فرز: من ذَكَرك في منشورٍ قبل أسبوع
 * صار تحت عشرين إعلاناً، والمثبّت لا يُميَّز إلا بشارة، ولا أحد يعرف أحيّةٌ القناة
 * أم هجرها الفريق.
 *
 * الآن: تبويبات (الكل · ما ذكرني · المثبّت)، ونبضٌ أسبوعيّ معلن، وحضورٌ حيّ
 * بجانب اسم الناشر — وأيُّ تبويبٍ لا يُسرّب منشوراً لا يخصّه.
 */
class FeedTimelineTest extends TestCase
{
    protected function fpost(string $body, array $attrs = []): Comment
    {
        return Comment::create(array_merge([
            'module' => 'feed', 'record_id' => null, 'user_id' => $this->owner->id,
            'body' => $body, 'read_by' => [], 'created_at' => now(),
        ], $attrs));
    }

    public function test_the_mentions_tab_shows_only_posts_that_named_me(): void
    {
        $this->seedCore();
        $this->fpost('إعلانٌ عام لا يخصّ أحداً');
        $this->fpost('نتيجة الربع — للجميع', ['mentions' => [$this->viewer->id]]);
        $this->fpost('يا فلان راجع العقد', ['mentions' => [$this->employee->id]]);

        $res = $this->actingAs($this->employee)->get('/feed?t=me');
        $res->assertOk();
        $res->assertSee('يا فلان راجع العقد');
        $res->assertDontSee('إعلانٌ عام لا يخصّ أحداً');
        $res->assertDontSee('نتيجة الربع — للجميع');
    }

    public function test_the_pinned_tab_shows_only_pinned_posts(): void
    {
        $this->seedCore();
        $this->fpost('منشورٌ عادي');
        $this->fpost('سياسة الدوام الجديدة', ['pinned' => true]);

        $res = $this->actingAs($this->owner)->get('/feed?t=pin');
        $res->assertOk();
        $res->assertSee('سياسة الدوام الجديدة');
        $res->assertDontSee('منشورٌ عادي');
    }

    /** تبويبٌ مجهول لا يُسقط الصفحة ولا يُفرغها — يعود إلى «الكل» */
    public function test_an_unknown_tab_falls_back_to_all(): void
    {
        $this->seedCore();
        $this->fpost('منشورٌ عادي');

        $this->actingAs($this->owner)->get('/feed?t=' . urlencode("' or 1=1"))
            ->assertOk()->assertSee('منشورٌ عادي');
    }

    /** النبض معلن: كم منشوراً هذا الأسبوع وكم مشاركاً ومثبّتاً */
    public function test_the_channel_pulse_is_announced(): void
    {
        $this->seedCore();
        $this->fpost('أ', ['user_id' => $this->owner->id]);
        $this->fpost('ب', ['user_id' => $this->employee->id]);
        $this->fpost('ج', ['user_id' => $this->employee->id, 'pinned' => true]);
        $this->fpost('قديم', ['user_id' => $this->viewer->id, 'created_at' => now()->subDays(30)]);

        $html = $this->actingAs($this->owner)->get('/feed')->assertOk()->getContent();
        $html = preg_replace('/\s+/u', ' ', $html);

        $this->assertStringContainsString('<b>3</b> منشوراً', $html, 'نبض الأسبوع لم يُحسب أو لم يُعرض');
        $this->assertStringContainsString('<b>2</b> مشاركاً', $html);
        $this->assertStringContainsString('<b>1</b> مثبّت', $html);
    }

    /** حضورٌ حيّ: من له جلسةٌ نابضة الآن تظهر نقطته الخضراء بجانب اسمه */
    public function test_a_live_author_gets_an_online_dot(): void
    {
        $this->seedCore();
        $this->fpost('منشورٌ من زميلٍ متصل', ['user_id' => $this->employee->id]);

        $this->actingAs($this->owner)->get('/feed')->assertOk()->assertDontSee('onlinedot');

        \Illuminate\Support\Facades\DB::table('sessions_log')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $this->employee->id,
            'ip' => '10.0.0.9', 'user_agent' => 'test', 'started_at' => now()->subMinutes(20),
            'last_seen_at' => now()->subMinute(), 'revoked' => false,
        ]);

        $this->actingAs($this->owner)->get('/feed')->assertOk()->assertSee('onlinedot');
    }

    /** التبويب لا يبتلع الترقيم: الروابط تحمل `t` كي لا تعود الصفحة الثانية للكل */
    public function test_paging_keeps_the_active_tab(): void
    {
        $this->seedCore();
        for ($i = 0; $i < 17; $i++) $this->fpost("مثبّت رقم {$i}", ['pinned' => true]);

        $this->actingAs($this->owner)->get('/feed?t=pin')->assertOk()->assertSee('t=pin', false);
    }
}
