<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\RecurringDoc;
use App\Support\Platform\DayRange;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * **المدى يُعيد ما تُعيده الدالّةُ — حرفاً، على المحرّكَين** (#27 · #33).
 *
 * ── **لماذا اختبارُ تكافؤٍ لا اختبارُ سرعة** ──
 *
 * المكسبُ قِيس بـ`EXPLAIN` على MariaDB: `DATE(date_end)` تُعطي
 * `type: ALL · key: NULL` (مسحُ جدولٍ كامل) بينما المدى يُعطي
 * `type: range · key: contracts_date_end_index`. **والسرعةُ لا تُقاس هنا**:
 * لا بياناتِ إنتاجٍ في هذه البيئة، وقياسُ زمنٍ على جدولٍ فارغٍ ادّعاءٌ لا دليل.
 *
 * فالمُختبَرُ هو **ما يُمكن إثباتُه**: أنّ التحويلَ لم يغيّر صفّاً واحداً.
 * وهذا هو الخطرُ الحقيقيُّ للتحويل — لا بُطؤه.
 *
 * ── **والكسرةُ التي يحرسها هذا الملفّ** ──
 *
 * عمودٌ مُكاستٌ `'date'` يُكتَب `Y-m-d H:i:s` مهما كان الكاست. فعلى SQLite
 * — ولا إلزامَ نوعيَّ فيها — يبقى `'2026-09-22 00:00:00'` نصّاً، ومساواةٌ
 * بـ`'2026-09-22'` **تُخطئ الصفَّ صامتةً**. والحدودُ هنا تُبنى نصّاً بمدىً
 * نصفِ مفتوح، وهذه الاختباراتُ تُشغَّل على المحرّكَين معاً.
 */
class DayRangeEquivalenceTest extends TestCase
{
    /** **`upto`: يشمل اليومَ نفسَه بكلِّ لحظاتِه** */
    public function test_حتّى_يومٍ_تشمل_اليومَ_ولا_تتجاوزه(): void
    {
        $this->seedCore();

        $before = Contract::create(['title' => 'أمس',   'type' => 'عقد عميل', 'status' => 'ساري', 'date_end' => '2026-09-21']);
        $same   = Contract::create(['title' => 'اليوم', 'type' => 'عقد عميل', 'status' => 'ساري', 'date_end' => '2026-09-22']);
        $after  = Contract::create(['title' => 'غداً',  'type' => 'عقد عميل', 'status' => 'ساري', 'date_end' => '2026-09-23']);

        $ids = DayRange::upto(Contract::query(), 'date_end', '2026-09-22')
            ->orderBy('date_end')->pluck('id')->all();

        $this->assertContains($before->id, $ids, 'ما قبل اليومِ سقط من «حتّى»');
        $this->assertContains($same->id, $ids,
            'اليومُ نفسُه سقط — وهذه هي الكسرةُ التي يُنتجها `<= "Y-m-d"` نصّاً');
        $this->assertNotContains($after->id, $ids, 'ما بعد اليومِ دخل في «حتّى»');
    }

    /** **`before`: يستثني اليومَ نفسَه بكلِّ لحظاتِه** */
    public function test_قبل_يومٍ_تستثني_اليومَ_كلَّه(): void
    {
        $this->seedCore();

        $before = Contract::create(['title' => 'أمس',   'type' => 'عقد عميل', 'status' => 'ساري', 'date_end' => '2026-09-21']);
        $same   = Contract::create(['title' => 'اليوم', 'type' => 'عقد عميل', 'status' => 'ساري', 'date_end' => '2026-09-22']);

        $ids = DayRange::before(Contract::query(), 'date_end', '2026-09-22')->pluck('id')->all();

        $this->assertContains($before->id, $ids);
        $this->assertNotContains($same->id, $ids, 'اليومُ نفسُه دخل في «قبل»');
    }

    /** **`on`: اليومُ وحدَه، وطرفاه مقطوعان** */
    public function test_يومٌ_بعينِه_لا_يزيد_ولا_ينقص(): void
    {
        $this->seedCore();

        $y = RecurringDoc::create(['name' => 'أمس',   'kind' => 'مصروف', 'amount' => 1,
            'cycle' => 'شهري', 'status' => 'مفعّل', 'next' => '2026-09-21']);
        $t = RecurringDoc::create(['name' => 'اليوم', 'kind' => 'مصروف', 'amount' => 1,
            'cycle' => 'شهري', 'status' => 'مفعّل', 'next' => '2026-09-22']);
        $m = RecurringDoc::create(['name' => 'غداً',  'kind' => 'مصروف', 'amount' => 1,
            'cycle' => 'شهري', 'status' => 'مفعّل', 'next' => '2026-09-23']);

        $ids = DayRange::on(RecurringDoc::query(), 'next', '2026-09-22')->pluck('id')->all();

        $this->assertSame([$t->id], $ids, 'اليومُ بعينِه التقط جارَه — الطرفانِ غيرُ مقطوعَين');
        $this->assertNotContains($y->id, $ids);
        $this->assertNotContains($m->id, $ids);
    }

    /**
     * **والتكافؤُ يُثبَت بالمقارنةِ لا بالدعوى** — نفسُ المجموعةِ من البابَين.
     *
     * وهذا هو البرهانُ الذي يُعاد تشغيلُه: لو انحرف `DayRange` عن دلالةِ
     * `whereDate` يوماً، سقط هنا قبل أن يسقط في تقريرٍ ماليّ.
     */
    public function test_المدى_يطابق_whereDate_صفّاً_بصفّ(): void
    {
        $this->seedCore();

        foreach (['2026-09-20', '2026-09-21', '2026-09-22', '2026-09-23'] as $i => $d) {
            Contract::create(['title' => "عقد {$i}", 'type' => 'عقد عميل', 'status' => 'ساري', 'date_end' => $d]);
        }

        $day = Carbon::parse('2026-09-22');

        $viaFunction = Contract::whereDate('date_end', '<=', $day)->orderBy('date_end')->pluck('id')->all();
        $viaRange    = DayRange::upto(Contract::query(), 'date_end', $day)->orderBy('date_end')->pluck('id')->all();

        $this->assertSame($viaFunction, $viaRange,
            '`DayRange::upto` انحرف عن دلالةِ `whereDate(<=)` — والتحويلُ الذي يغيّر صفّاً ليس تحسيناً');

        $viaFunction = Contract::whereDate('date_end', '<', $day)->orderBy('date_end')->pluck('id')->all();
        $viaRange    = DayRange::before(Contract::query(), 'date_end', $day)->orderBy('date_end')->pluck('id')->all();

        $this->assertSame($viaFunction, $viaRange, '`DayRange::before` انحرف عن دلالةِ `whereDate(<)`');

        $viaFunction = Contract::whereDate('date_end', $day)->orderBy('date_end')->pluck('id')->all();
        $viaRange    = DayRange::on(Contract::query(), 'date_end', $day)->orderBy('date_end')->pluck('id')->all();

        $this->assertSame($viaFunction, $viaRange, '`DayRange::on` انحرف عن دلالةِ `whereDate(=)`');
    }
}
