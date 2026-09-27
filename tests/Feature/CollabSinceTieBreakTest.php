<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\DmMessage;
use App\Models\User;
use App\Support\Collaboration\Collaboration;
use App\Support\Collaboration\CommentService;
use App\Support\Collaboration\DmService;
use Illuminate\Support\Carbon;
use Tests\Feature\Mobile\InteractsWithMobileAuth;
use Tests\TestCase;

/**
 * **قرعةُ التعادل في مؤشّر `since`** — المعرّفاتُ UUID عشوائيّة و`created_at` بدقّة
 * الثانية، فكان شرطُ `(created_at = t AND id > cid)` يُسقط إلى الأبد رسالةً تصل لاحقاً
 * في ثانيةِ المؤشّر نفسِها بمعرّفٍ أصغر. المؤشّرُ v2 يحمل مجموعةَ ما سُلِّم في ثانيته.
 * يُثبَت على كلِّ منتِجٍ للمؤشّر: since (ويب/جوال · DM/قناة)، ذيلُ الجوال (messages/
 * comments)، ورأسُ الويب (tipSince) — ومؤشّرُ الجيلِ الأوّل ما زال يُفكّ.
 */
class CollabSinceTieBreakTest extends TestCase
{
    use InteractsWithMobileAuth;

    private const BIG = 'ffffffff-ffff-4fff-bfff-ffffffffffff';
    private const SMALL = '00000000-0000-4000-8000-000000000001';

    private Carbon $t0;

    protected function setUp(): void
    {
        parent::setUp();
        // ثانيةٌ ثابتةٌ بعد الآن (لا تسبق password_changed_at فتُبطل جلسةَ الجوال)
        $this->t0 = Carbon::now()->startOfSecond()->addSeconds(5);
        Carbon::setTestNow($this->t0);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dm(User $from, User $to, string $body, string $id): DmMessage
    {
        $m = DmService::send($from, $to, $body);
        DmMessage::whereKey($m->id)->update(['id' => $id]);

        return DmMessage::findOrFail($id);
    }

    private function channelWithMember(): Conversation
    {
        $conv = Conversation::create(['kind' => 'channel', 'title' => 'قناةُ التعادل', 'created_by' => $this->owner->id]);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->employee->id, 'role' => 'member']);

        return $conv;
    }

    private function postChannel(Conversation $conv, string $body, string $id): Comment
    {
        $c = CommentService::create($this->owner, 'channel', (string) $conv->id, $body, ['conversation_id' => (string) $conv->id]);
        Comment::whereKey($c->id)->update(['id' => $id]);

        return Comment::findOrFail($id);
    }

    /** يمرّ على since بمؤشّرٍ: يعيد [أجسام الأحداث, المؤشّر التالي] */
    private function since(string $url, array $headers, string $cursor, string $wrap): array
    {
        $res = $this->withHeaders($headers)->getJson($url . '?cursor=' . $cursor)->assertOk();

        return [array_column($res->json($wrap . 'events'), 'body'), (string) $res->json($wrap . 'cursor')];
    }

    // ═══════════════════ since نفسُه (ويب + جوال · DM + قناة) ═══════════════════

    public function test_late_same_second_smaller_id_is_delivered_by_every_since_endpoint(): void
    {
        $this->seedCore();
        $conv = $this->channelWithMember();
        $mobile = $this->bearer($this->mobileLogin($this->employee)['access_token']);

        $cases = [
            'mobile dm' => ['/api/mobile/v1/dm/threads/' . $this->owner->id . '/since', $mobile, 'data.', 'dm'],
            'mobile channel' => ['/api/mobile/v1/conversations/' . $conv->id . '/since', $mobile, 'data.', 'ch'],
            'web dm' => [route('dm.since', $this->owner->id), [], '', 'dm'],
            'web channel' => [route('conversations.since', $conv->id), [], '', 'ch'],
        ];

        $n = 0;
        foreach ($cases as $label => [$url, $headers, $wrap, $kind]) {
            $n++;
            $big = substr(self::BIG, 0, -2) . sprintf('%02d', $n);
            $small = substr(self::SMALL, 0, -2) . sprintf('%02d', $n);
            Carbon::setTestNow($this->t0->copy()->addSeconds($n * 10));
            $kind === 'dm' ? $this->dm($this->owner, $this->employee, "أولى {$n}", $big) : $this->postChannel($conv, "أولى {$n}", $big);

            if ($headers === []) $this->actingAs($this->employee);
            // المؤشّرُ حتى الآن (نمرّ على كلِّ ما سبق حتى الذيل)
            $cursor = '';
            do {
                [$bodies, $cursor2] = $this->since($url, $headers, $cursor, $wrap);
                $done = $cursor2 === $cursor;
                $cursor = $cursor2;
            } while (! $done && $bodies !== []);

            // تصل الآن — الثانيةَ نفسَها، بمعرّفٍ أصغر من معرّفِ المؤشّر
            $kind === 'dm' ? $this->dm($this->owner, $this->employee, "متأخّرة {$n}", $small) : $this->postChannel($conv, "متأخّرة {$n}", $small);

            [$bodies, $next] = $this->since($url, $headers, $cursor, $wrap);
            $this->assertSame(["متأخّرة {$n}"], $bodies, "{$label}: المتأخّرةُ ذاتُ المعرّفِ الأصغر تُسلَّم");
            [$again] = $this->since($url, $headers, $next, $wrap);
            $this->assertSame([], $again, "{$label}: ولا تتكرّر");
        }
    }

    // ═══════════════════ مؤشّراتُ الذيل/الرأس ═══════════════════

    public function test_mobile_tail_cursors_do_not_skip_a_late_smaller_id(): void
    {
        $this->seedCore();
        $conv = $this->channelWithMember();
        $H = $this->bearer($this->mobileLogin($this->employee)['access_token']);

        $this->dm($this->owner, $this->employee, 'أولى', self::BIG);
        $this->postChannel($conv, 'أولى', self::BIG);

        $dmCur = $this->withHeaders($H)->getJson('/api/mobile/v1/dm/threads/' . $this->owner->id . '/messages')->assertOk()->json('data.cursor');
        $chCur = $this->withHeaders($H)->getJson('/api/mobile/v1/comments?module=channel&record=' . $conv->id)->assertOk()->json('data.cursor');

        $this->dm($this->owner, $this->employee, 'متأخّرة', self::SMALL);
        $this->postChannel($conv, 'متأخّرة', self::SMALL);

        [$dm] = $this->since('/api/mobile/v1/dm/threads/' . $this->owner->id . '/since', $H, $dmCur, 'data.');
        [$ch] = $this->since('/api/mobile/v1/conversations/' . $conv->id . '/since', $H, $chCur, 'data.');
        $this->assertSame(['متأخّرة'], $dm);
        $this->assertSame(['متأخّرة'], $ch);
    }

    public function test_web_tip_cursor_covers_the_whole_last_second(): void
    {
        $this->seedCore();
        $this->dm($this->owner, $this->employee, 'أ', self::BIG);
        $this->dm($this->owner, $this->employee, 'ب', substr(self::BIG, 0, -1) . 'e');
        $base = DmMessage::where('thread_key', DmMessage::threadKey((string) $this->owner->id, (string) $this->employee->id));

        $tip = Collaboration::tipSince($base);
        $this->dm($this->owner, $this->employee, 'متأخّرة', self::SMALL);

        $this->actingAs($this->employee);
        [$bodies] = $this->since(route('dm.since', $this->owner->id), [], $tip, '');
        $this->assertSame(['متأخّرة'], $bodies, 'الرأسُ يحمل ثانيتَه كاملة — لا يعيد أ/ب ولا يُسقط المتأخّرة');
    }

    // ═══════════════════ التوافقُ الخلفيّ ═══════════════════

    public function test_legacy_v1_cursor_still_decodes_with_its_old_meaning(): void
    {
        $this->seedCore();
        $mid = '88888888-8888-4888-8888-888888888888';
        $this->dm($this->owner, $this->employee, 'قبل', self::SMALL);
        $this->dm($this->owner, $this->employee, 'الحدّ', $mid);
        $this->dm($this->owner, $this->employee, 'بعد', self::BIG);

        // مؤشّرُ الجيلِ الأوّل (t|id) كما أصدره الخادمُ سابقاً
        $legacy = Collaboration::encodeCursor((string) DmMessage::findOrFail($mid)->created_at, $mid);
        $this->assertSame([(string) DmMessage::findOrFail($mid)->created_at, $mid], Collaboration::decodeCursor($legacy));

        $this->actingAs($this->employee);
        [$bodies, $next] = $this->since(route('dm.since', $this->owner->id), [], $legacy, '');
        $this->assertSame(['بعد'], $bodies, 'المعنى القديم: ما بعد الحدّ في ثانيته — بلا تكرارِ ما سلّمه');
        [$again] = $this->since(route('dm.since', $this->owner->id), [], $next, '');
        $this->assertSame([], $again);

        // فاسدٌ ⇒ من البداية (كما كان)
        [$all] = $this->since(route('dm.since', $this->owner->id), [], 'not-a-cursor', '');
        $this->assertCount(3, $all);
    }
}
