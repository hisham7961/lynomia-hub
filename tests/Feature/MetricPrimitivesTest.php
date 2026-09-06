<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Support\CeoBoard;
use App\Support\Health;
use App\Support\Series;
use App\Support\WidgetRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * أوّليّاتُ القياس (WP-1.6): حاويةُ الزمن، ونافذتا المقارنة، و«لا نسبةَ
 * مخترَعة» في مقارِنٍ واحدٍ بدل نسختين متباعدتين (WidgetRegistry/CeoBoard)،
 * والنسبُ المئوية من مدرَّجٍ لوغاريتميّ بحدّ خطأٍ مُعلَن (القرار ق٥).
 */
class MetricPrimitivesTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ────────── hub_metric_bucket ────────── */

    public function test_the_bucket_floors_down_at_five_minute_precision_across_hour_boundaries(): void
    {
        $f = fn ($at, $m = 5) => hub_metric_bucket($at, $m)->format('Y-m-d H:i:s');

        // داخل الساعة
        $this->assertSame('2026-09-06 10:00:00', $f('2026-09-06 10:02:59'));
        $this->assertSame('2026-09-06 10:35:00', $f('2026-09-06 10:37:12'));
        // عبورُ حدّ الساعة: 9:59 تنتمي لحاوية 9:55 لا 10:00
        $this->assertSame('2026-09-06 09:55:00', $f('2026-09-06 09:59:59'));
        // وحدُّ منتصف الليل
        $this->assertSame('2026-09-07 00:00:00', $f('2026-09-07 00:04:59'));
        $this->assertSame('2026-09-06 23:55:00', $f('2026-09-06 23:59:59'));
        // بدايةُ الحاوية تبقى نفسَها (تقريبٌ لأسفل لا لأقرب)
        $this->assertSame('2026-09-06 10:00:00', $f('2026-09-06 10:00:00'));
        // دقّةٌ أخرى: حاويةُ ساعة
        $this->assertSame('2026-09-06 10:00:00', $f('2026-09-06 10:47:33', 60));
        // ويقبل Carbon كما يقبل النصّ، ويصفّر الثواني
        $this->assertSame('2026-09-06 12:20:00', $f(Carbon::parse('2026-09-06 12:24:45')));
    }

    public function test_the_bucket_normalizes_the_timezone_like_hub_metric_put(): void
    {
        // «22:00Z» و«01:00+03:00» لحظةٌ واحدة — الحاويةُ تُحسب بعد التطبيع
        // لتوقيت النظام فلا تتشظّى النقطةُ الواحدة بجدار ساعة مصدرها
        $b = hub_metric_bucket('2026-09-06T07:02:30Z');
        $this->assertSame(Carbon::parse('2026-09-06T07:00:00Z')->timestamp, $b->timestamp);
        $this->assertSame(config('app.timezone'), $b->timezone->getName());
    }

    /* ────────── hub_window_pair ────────── */

    public function test_the_window_pair_is_two_equal_adjacent_windows(): void
    {
        Carbon::setTestNow('2026-09-06 12:00:00');
        $p = hub_window_pair(24);

        [$cf, $ct] = $p['cur'];
        [$pf, $pt] = $p['prev'];
        $this->assertTrue($ct->equalTo(Carbon::parse('2026-09-06 12:00:00')));
        $this->assertTrue($cf->equalTo(Carbon::parse('2026-09-05 12:00:00')));
        // السابقةُ تنتهي حيث تبدأ الحالية — لا فجوةَ ولا تداخل
        $this->assertTrue($pt->equalTo($cf));
        $this->assertTrue($pf->equalTo(Carbon::parse('2026-09-04 12:00:00')));
        // والنافذتان متساويتان — قوامُ المقارنة العادلة (spec §36)
        $this->assertSame($cf->diffInSeconds($ct), $pf->diffInSeconds($pt));
    }

    /* ────────── hub_compare ────────── */

    public function test_pct_is_null_when_the_base_is_zero_or_absent(): void
    {
        $c = hub_compare(5.0, 0.0);
        $this->assertNull($c['pct'], '«١٠٠٪» المختلقة حين الأساس صفر تكذب');
        $this->assertFalse($c['n_ok']);
        $this->assertSame(5.0, $c['cur']);
        $this->assertSame(5.0, $c['delta']);

        $c = hub_compare(5.0, null);
        $this->assertNull($c['pct']);
        $this->assertNull($c['delta'], 'لا فرقَ بلا أساس — «لا قياس» ليس «صفراً»');
        $this->assertFalse($c['n_ok']);
    }

    public function test_compare_reproduces_both_old_formulas(): void
    {
        // دلالةُ WidgetRegistry: (int) round((this-prev)*100/prev)
        $c = hub_compare(3.0, 2.0);
        $this->assertSame(50, $c['pct']);
        $this->assertSame(1.0, $c['delta']);
        $this->assertTrue($c['n_ok']);

        // دلالةُ CeoBoard: القسمة على |prev| كي يصحّ الاتجاه مع صافٍ سالب
        $c = hub_compare(100.0, -50.0);
        $this->assertSame(300, $c['pct']);
        $this->assertSame(150.0, $c['delta']);

        // minN: أساسٌ أصغرُ من العتبة لا تُعرَض له نسبة (spec §26)
        $c = hub_compare(30.0, 2.0, 5);
        $this->assertNull($c['pct']);
        $this->assertFalse($c['n_ok']);
    }

    /* ────────── Series: percentiles من مدرَّجٍ لوغاريتميّ ────────── */

    /** توزيعٌ اصطناعيّ حتميّ على نمط أزمنة HTTP: لوغاريتميّ من ~1 إلى ~10000ms */
    protected function syntheticValues(): array
    {
        $values = [];
        for ($i = 0; $i < 1000; $i++) $values[] = pow(10, $i / 250.0);
        return $values;
    }

    /** النسبةُ الدقيقة بترتيب الرتبة الأقرب (nearest-rank) — مرجعُ المقارنة */
    protected function exactPercentile(array $values, int $p): float
    {
        sort($values);
        return $values[max(1, (int) ceil(count($values) * $p / 100)) - 1];
    }

    public function test_percentiles_are_within_the_declared_bucket_error_bound(): void
    {
        $values = $this->syntheticValues();
        $got = Series::percentiles(Series::hist($values));

        foreach ([50, 95, 99] as $p) {
            $exact = $this->exactPercentile($values, $p);
            $rel = abs($got["p{$p}"] - $exact) / $exact;
            $this->assertLessThanOrEqual(Series::MAX_REL_ERROR, $rel,
                "p{$p}: الخطأ النسبيّ {$rel} تجاوز الحدَّ المُعلَن — التقريبُ لم يعد صادقاً");
        }

        // مدرَّجٌ فارغ ⇒ null لا صفرٌ مُختلَق (spec: no fake percentiles)
        $this->assertSame(['p50' => null, 'p95' => null, 'p99' => null], Series::percentiles([]));
    }

    public function test_merge_hist_equals_the_hist_of_the_union(): void
    {
        $all = $this->syntheticValues();
        $a = array_slice($all, 0, 400);
        $b = array_slice($all, 400);

        $this->assertSame(Series::hist($all), Series::mergeHist(Series::hist($a), Series::hist($b)));

        // مفاتيحُ JSON تصل نصوصاً من عمود hist — تُطبَّع أعداداً قبل الجمع
        $this->assertSame([3 => 3, 7 => 1], Series::mergeHist(['3' => '2', '7' => 1], [3 => 1]));

        // والنسبُ من المدموج ضمن الحدّ نفسِه — فدمجُ نوافذ الزمن لا يُفسد التقدير
        $merged = Series::percentiles(Series::mergeHist(Series::hist($a), Series::hist($b)));
        $exact = $this->exactPercentile($all, 95);
        $this->assertLessThanOrEqual(Series::MAX_REL_ERROR, abs($merged['p95'] - $exact) / $exact);
    }

    /* ────────── الانحدار: التوحيد لا يغيّر رقماً ────────── */

    public function test_widget_counts_trend_matches_the_old_formula_after_unification(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        // ٣ عملاء هذا الأسبوع مقابل ٢ في الذي قبله — بعيداً عن حدَي النافذتين
        foreach ([2, 3, 4] as $d) {
            $c = Client::create(['name' => "هذا الأسبوع {$d}"]);
            DB::table('clients')->where('id', $c->id)->update(['created_at' => now()->subDays($d)]);
        }
        foreach ([9, 10] as $d) {
            $c = Client::create(['name' => "الأسبوع السابق {$d}"]);
            DB::table('clients')->where('id', $c->id)->update(['created_at' => now()->subDays($d)]);
        }

        $rows = collect(WidgetRegistry::resolve('counts', $this->owner))->keyBy('key');
        $clients = $rows['clients'];

        // الصيغةُ القديمة حرفياً (WidgetRegistry قبل التوحيد): round((3-2)*100/2)
        $this->assertSame(5, $clients['count']);
        $this->assertSame((int) round((3 - 2) * 100 / 2), $clients['trend']);
        $this->assertNull($clients['fresh'], 'أساسُ مقارنةٍ موجود — لا وسمَ «جديد»');
    }

    public function test_widget_counts_fresh_matches_the_old_formula_when_base_is_zero(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        // عميلان هذا الأسبوع ولا شيءَ قبلهما ⇒ trend=null وfresh=2 (الصيغة القديمة)
        foreach ([1, 3] as $d) {
            $c = Client::create(['name' => "جديد {$d}"]);
            DB::table('clients')->where('id', $c->id)->update(['created_at' => now()->subDays($d)]);
        }

        $rows = collect(WidgetRegistry::resolve('counts', $this->owner))->keyBy('key');
        $this->assertNull($rows['clients']['trend'], 'نسبةٌ بلا أساس نسبةٌ مخترَعة');
        $this->assertSame(2, $rows['clients']['fresh']);
    }

    public function test_ceo_trend_matches_the_old_formula_after_unification(): void
    {
        // الحالةُ نفسُها التي يحرسها CeoDecisionLayerTest — أرقامُها لا تتزحزح
        $t = CeoBoard::trend([
            ['l' => 'يناير', 'i' => 1000, 'e' => 900],
            ['l' => 'فبراير', 'i' => 1000, 'e' => 800],
            ['l' => 'مارس', 'i' => 1000, 'e' => 500],
        ]);
        $this->assertSame(300.0, $t['delta']);
        $this->assertSame(150, $t['pct']);
        $this->assertSame('up', $t['dir']);
        $this->assertSame(350.0, $t['vsBase']);
        $this->assertSame('الشهر أفضل من الذي قبله بـ150٪', $t['verdict']);

        // أساسٌ صفر ⇒ pct=null والحكمُ بلا نسبةٍ ملحقة
        $t = CeoBoard::trend([['l' => 'أ', 'i' => 500, 'e' => 500], ['l' => 'ب', 'i' => 300, 'e' => 0]]);
        $this->assertNull($t['pct']);
        $this->assertSame('الشهر أفضل من الذي قبله', $t['verdict']);

        // أساسٌ سالب: القسمةُ على |prev| كما كانت — round(150/50*100)=300
        $t = CeoBoard::trend([['l' => 'أ', 'i' => 0, 'e' => 50], ['l' => 'ب', 'i' => 100, 'e' => 0]]);
        $this->assertSame(300, $t['pct']);
        $this->assertSame('up', $t['dir']);
    }

    /* ────────── critic #31: المعرّفاتُ تسع أعمدةَ metric_points ────────── */

    public function test_every_planned_identifier_fits_metric_points_column_widths(): void
    {
        // record_id عمودُ uuid ⇒ char(36) على MySQL الصارمة (تقبله SQLite صامتةً):
        // كلُّ مفتاح مجدولةٍ سيُكتب record_id في نبضات WP-2.3
        foreach (array_keys(Health::JOBS) as $key) {
            $this->assertLessThanOrEqual(36, strlen($key),
                "مفتاح المجدولة «{$key}» أطول من metric_points.record_id (char(36))");
        }

        // ومفاتيحُ الوحدات تُكتب module (string(40)) وrecord_id معاً (لوحة 8.2)
        foreach (hub_modules() as $key => $def) {
            $this->assertLessThanOrEqual(36, strlen($key),
                "مفتاح الوحدة «{$key}» أطول من metric_points.record_id (char(36))");
            $this->assertLessThanOrEqual(40, strlen($key),
                "مفتاح الوحدة «{$key}» أطول من metric_points.module (string(40))");
            // وصيغةُ WP-2.3 «rows:<table>» تُكتب metric (string(40))
            $metric = 'rows:' . ($def['table'] ?? '');
            $this->assertLessThanOrEqual(40, strlen($metric),
                "المقياس «{$metric}» أطول من metric_points.metric (string(40))");
        }
    }
}
