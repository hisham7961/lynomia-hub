<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **كلفةُ صفحةِ الصباحِ لا تنمو بعددِ التذاكر** (قياسُ الحِمل · N+1).
 *
 * `MorningController` يمرّ على تذاكرَ مفتوحةٍ (حتى **ثمانين**) وينادي
 * `hub_sla($t)` لكلٍّ منها. و`hub_sla` — حين لا يُمرَّر لها أوّلُ ردٍّ —
 * تسأل القاعدةَ بنفسِها:
 *
 * ```sql
 * select `created_at` from `comments`
 *  where `module` = ? and `record_id` = ? ... order by `created_at` limit 1
 * ```
 *
 * **استعلامٌ لكلِّ تذكرة.** قِيس على عالَمِ الشهر: ١٦ تذكرةً ⇐ ١٦ استعلاماً،
 * وهي أربعون بالمئة من استعلاماتِ الصفحةِ كلِّها. وكلفتُه اليومَ مليمتراتٌ
 * (١٦ms) — **لكنّه ينمو خطّيّاً**، وصفحةُ الصباحِ أوّلُ ما يفتحه كلُّ موظّفٍ
 * كلَّ صباح. فهي أسوأُ موضعٍ لنمطٍ يكبر.
 *
 * **والمخرجُ موجودٌ في الدالّةِ نفسِها منذ البداية:** `hub_sla($t, $firstReply)`
 * تقبل أوّلَ ردٍّ مُمرَّراً، و`ExecutionStats::163` يستعمله فعلاً بتحميلٍ دفعيّ
 * (`MIN(created_at) … groupBy(record_id)`). **صفحةُ الصباحِ وحدَها لم تستعمله.**
 *
 * والحكمُ هنا ليس رقماً سحريّاً بل أنّ **ثلاثَ تذاكرَ وثلاثين تكلّفان العددَ
 * نفسَه** من الاستعلامات.
 */
class MorningSlaCostIsFlatTest extends TestCase
{
    private function tickets(int $n, int $from = 0): void
    {
        for ($i = $from; $i < $from + $n; $i++) {
            $t = Ticket::create(['subject' => "تذكرة {$i}", 'status' => 'مفتوحة',
                'priority' => 'عالية', 'created_at' => now()->subDays(9)]);
            // ردٌّ غيرُ داخليٍّ واحد — فالمسارُ الذي يسأل القاعدةَ هو المقيس
            Comment::create(['module' => 'tickets', 'record_id' => (string) $t->id,
                'body' => "ردٌّ على {$i}", 'user_id' => $this->owner->id, 'internal' => false,
                'created_at' => now()->subDays(8)]);
        }
    }

    private function counted(\Closure $fn): array
    {
        $n = 0; $on = false;
        DB::listen(function () use (&$n, &$on) { if ($on) $n++; });
        $on = true; $out = $fn(); $on = false;

        return [$out, $n];
    }

    public function test_the_morning_page_does_not_query_once_per_ticket(): void
    {
        $this->seedCore();
        $this->hubSetting('ops.watchdog', '0');

        $this->tickets(3);

        // **تسخينٌ أوّلاً:** أوّلُ طلبٍ في العمليّةِ يدفع ثمنَ ما يُخزَّن مرّةً
        // (الإعدادات، فحوصُ الأعمدة، سجلُّ الوحدات) — فقياسُه يُغرق الفرقَ المقيس.
        $this->actingAs($this->owner)->get(route('morning'))->assertOk();

        [, $qFew] = $this->counted(fn () => $this->actingAs($this->owner)->get(route('morning'))->assertOk());

        $this->tickets(27, 3);
        $this->actingAs($this->owner)->get(route('morning'))->assertOk();   // تسخينٌ بعد الإضافةِ أيضاً

        [, $qMany] = $this->counted(fn () => $this->actingAs($this->owner)->get(route('morning'))->assertOk());

        // **حارسُ «ألا يقيس القياسُ شيئاً»:** صفرُ استعلاماتٍ متساوٍ مع صفرٍ أيضاً
        $this->assertGreaterThan(10, $qFew, 'لم تُقَس الصفحةُ أصلاً — عدّادُ الاستعلاماتِ صامت');

        $this->assertLessThanOrEqual($qFew + 2, $qMany,
            "٣ تذاكرَ كلّفت {$qFew} استعلاماً و٣٠ كلّفت {$qMany} — الكلفةُ تنمو بعددِ التذاكر، "
            . 'وهذه الصفحةُ يفتحها كلُّ موظّفٍ كلَّ صباح');
    }

    /** ولا يتغيّر ما تعرضه الصفحة: التذكرةُ المتجاوِزةُ تبقى مذكورةً بعد التحميلِ الدفعيّ */
    public function test_a_breaching_ticket_is_still_listed(): void
    {
        $this->seedCore();
        $this->hubSetting('ops.watchdog', '0');

        // تذكرةٌ قديمةٌ بلا ردٍّ إطلاقاً ⇒ تجاوزت أوّلَ ردٍّ يقيناً
        Ticket::create(['subject' => 'تذكرةٌ متجاوِزةٌ بلا ردّ', 'status' => 'مفتوحة',
            'priority' => 'عالية', 'created_at' => now()->subDays(30)]);

        $this->actingAs($this->owner)->get(route('morning'))
            ->assertOk()->assertSee('تذكرةٌ متجاوِزةٌ بلا ردّ', false);
    }
}
