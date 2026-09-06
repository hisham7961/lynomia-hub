<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Support\ExecutionStats;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **اتّجاهُ التنفيذ (WP-8.4 · §6.12): لقطةٌ يومية تُحدَّث ولا تكرّر.**
 *
 * رقمُ اليوم وحده لا يقول إن كنّا نتقدّم أم نتراجع — والاتّجاه فرقٌ بين نقطتين.
 * تُكتب اللقطةُ داخل الأمر القائم `hub:quality-snapshot` (لا أمرَ مجدولٌ ثانٍ
 * ولا جدولَ جديد): `('execution','org','completion_pct'|'ontime_pct'|'overdue'
 * |'open_issues')` في `metric_points` — والنقطةُ معرَّفةٌ بـ(وحدة، سجل، مقياس،
 * لحظة) فإعادةُ التشغيل في اليوم نفسِه **تُحدِّث** الصفَّ ولا تُنشئ ثانياً.
 *
 * وما لا يُقاس لا يُكتب صفراً: بلا مهامٍّ مخطَّطةٍ لا نسبةَ إنجاز — والصفرُ
 * المكتوبُ مكانَ «لا قياس» يرسم انهياراً لم يحدث.
 */
class ExecutionTrendTest extends TestCase
{
    /** بذرةٌ صغيرة: مهمّةٌ أُنجزت في موعدها وأخرى فات موعدُها */
    private function seedWork(): void
    {
        Task::create(['title' => 'أُنجزت', 'status' => 'منجزة',
            'due' => now()->subDay()->toDateString(), 'completed_at' => now()->subDay()]);
        Task::create(['title' => 'فات موعدها', 'status' => 'قيد التنفيذ',
            'due' => now()->subDays(2)->toDateString()]);
    }

    private function points(?string $metric = null)
    {
        return DB::table('metric_points')->where('module', 'execution')->where('record_id', 'org')
            ->when($metric, fn ($q) => $q->where('metric', $metric))
            ->orderBy('metric')->orderBy('at')->get();
    }

    /* ────────── ١) لقطةٌ يومية تُحدَّث ولا تكرّر ────────── */

    public function test_daily_execution_snapshot_updates_and_never_duplicates(): void
    {
        $this->seedCore();
        $this->seedWork();

        $this->artisan('hub:quality-snapshot')->assertSuccessful();

        $first = $this->points();
        $this->assertSame(['completion_pct', 'ontime_pct', 'open_issues', 'overdue'],
            $first->pluck('metric')->unique()->sort()->values()->all(),
            'مقاييسُ لقطة التنفيذ الأربعة (§6.12) غيرُ مكتوبة');
        $this->assertSame(4, $first->count());
        $this->assertSame(1.0, (float) $this->points('overdue')->first()->value);
        $this->assertSame(100.0, (float) $this->points('ontime_pct')->first()->value);

        // يتغيّر الواقعُ في اليوم نفسِه ⇒ القيمةُ تُحدَّث والصفُّ لا يتكرّر
        Task::create(['title' => 'فات موعدها أيضاً', 'status' => 'قيد التنفيذ',
            'due' => now()->subDays(3)->toDateString()]);
        $this->artisan('hub:quality-snapshot')->assertSuccessful();

        $second = $this->points();
        $this->assertSame(4, $second->count(), 'إعادةُ التشغيل في اليوم نفسِه كرّرت النقاط بدل تحديثها');
        $this->assertSame(2.0, (float) $this->points('overdue')->first()->value, 'القيمةُ لم تُحدَّث');
    }

    /* ────────── ٢) ما لا يُقاس لا يُكتب صفراً ────────── */

    public function test_unmeasured_percentages_are_skipped_not_written_as_zero(): void
    {
        $this->seedCore();

        $this->artisan('hub:quality-snapshot')->assertSuccessful();

        $this->assertSame(['open_issues', 'overdue'],
            $this->points()->pluck('metric')->unique()->sort()->values()->all(),
            'نسبةٌ بلا قياسٍ كُتبت صفراً — والصفرُ يرسم انهياراً لم يحدث');
        $this->assertSame(0.0, (float) $this->points('overdue')->first()->value,
            'والصفرُ المقيسُ يُكتب: لا متأخّرَ اليومَ حقيقةٌ لا فراغ');
    }

    /* ────────── ٣) تاريخٌ صادقٌ ولو كان فارغاً ────────── */

    public function test_empty_history_is_honest_and_two_points_make_a_delta(): void
    {
        $this->seedCore();

        $empty = ExecutionStats::history();
        $this->assertSame([], $empty['metrics']['completion_pct']['points'],
            'لا نقاطَ ⇒ قائمةٌ فارغة لا سلسلةُ أصفار');
        $this->assertNull($empty['metrics']['overdue']['delta'], 'نقطةٌ واحدةٌ أو لا شيء ⇒ لا فرق');

        // لقطةُ أمسٍ ثم لقطةُ اليوم — الاتّجاهُ فرقٌ بين نقطتين
        $this->seedWork();
        $this->artisan('hub:quality-snapshot', ['--date' => now()->subDay()->toDateString()])
            ->assertSuccessful();
        Task::create(['title' => 'فات موعدها أيضاً', 'status' => 'قيد التنفيذ',
            'due' => now()->subDays(3)->toDateString()]);
        $this->artisan('hub:quality-snapshot')->assertSuccessful();

        $h = ExecutionStats::history();
        $this->assertSame(2, count($h['metrics']['overdue']['points']), 'يومان ⇒ نقطتان');
        $this->assertSame(1.0, $h['metrics']['overdue']['delta'], 'الفرقُ من أوّل نقطةٍ إلى آخرها');
        $this->assertSame(2.0, $h['metrics']['overdue']['last']);
    }
}
