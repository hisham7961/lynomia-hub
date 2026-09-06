<?php

namespace Tests\Feature;

use App\Support\Series;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WP-2.4 — جدولُ أداء المسارات على شاشة التشغيل + كشفُ الانحدار (أداءً وأخطاءً).
 *
 * العمودُ الفقريّ (critic #27): التجميعُ **في القاعدة** (GROUP BY route, method
 * داخل TimeRange) لا في PHP، و`hist` يُقرأ لصفوف الصفحة المعروضة وحدها —
 * فميزانيةُ الاستعلام ثابتةٌ لا تنمو مع عدد المسارات المسجَّلة.
 */
class RoutePerformanceTest extends TestCase
{
    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow(null);
        parent::tearDown();
    }

    /** بذرُ حاويةِ RED واحدة بالشكل الذي يكتبه Observability::terminate حرفياً */
    protected function bucket(string $route, $at, int $count, int $sumMs, array $o = []): void
    {
        $avg = $count > 0 ? $sumMs / $count : 0;
        DB::table('http_metric_buckets')->insert(array_merge([
            'bucket_at' => hub_metric_bucket($at)->toDateTimeString(),
            'surface'   => 'web', 'method' => 'GET', 'route' => $route,
            'count'     => $count, 'err4' => 0, 'err5' => 0, 'slow' => 0,
            'sum_ms'    => $sumMs, 'max_ms' => (int) ceil($avg),
            'hist'      => json_encode(Series::hist(array_fill(0, max(1, $count), $avg))),
            'updated_at' => now()->toDateTimeString(),
        ], $o));
    }

    /** صفُّ خطأٍ مجمَّع في مركز الأخطاء — لدليل الانحدار حسب النوع */
    protected function errorRow(string $kind, int $count, $seen): void
    {
        DB::table('error_events')->insert([
            'id' => (string) Str::uuid(), 'hash' => sha1($kind . $count . microtime()),
            'kind' => $kind, 'message' => 'خطأ اختباري', 'count' => $count,
            'status' => 'جديد', 'first_seen' => $seen, 'last_seen' => $seen,
        ]);
    }

    /** حاوياتُ المسار الواحد تُجمَع صفاً واحداً في القاعدة، والنسبُ موسومةٌ تقريبية */
    public function test_buckets_aggregate_per_route_and_percentiles_are_labelled_approximate(): void
    {
        $this->seedCore();
        $this->bucket('zz-perf/clients', now()->subHours(2), 10, 1000);
        $this->bucket('zz-perf/clients', now()->subHour(), 20, 4000);
        $this->bucket('zz-perf/quotes', now()->subHour(), 5, 250);

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();

        $this->assertStringContainsString('أداء المسارات', $html, 'القسم غائب عن شاشة التشغيل');
        $this->assertSame(1, substr_count($html, 'zz-perf/clients'), 'صفٌّ واحد للمسار مهما تعددت حاوياته');
        $this->assertStringContainsString('167', $html, 'المتوسط من SUM(sum_ms)/SUM(count) = 5000/30');
        $this->assertStringContainsString('p95', $html);
        $this->assertStringContainsString('تقريبية', $html, 'النسبُ المئوية من مدرَّجٍ — التقريب مُعلَن');
    }

    /** مساران بمعرّفين UUID مختلفين يلتقيان في صفٍّ مطبَّعٍ واحد ({id}) عبر الكاتب الحقيقي */
    public function test_uuid_paths_land_in_one_normalized_row(): void
    {
        $this->seedCore();
        // تثبيتُ الساعة على لحظةٍ **داخل** حاوية الـ٥ دقائق لا على حدّها: الكتابةُ
        // (Observability::terminate) والقراءةُ (نافذةُ TimeRange المنتهيةُ «الآن»)
        // تتشاركان اللحظةَ نفسَها، فحاويةُ الطلبين (أرضيةُ الدقائق الخمس) أقلُّ من
        // «الآن» حتماً. بلا تثبيتٍ كان العدّاءُ الأبطأ يعبر الحدَّ فيغيب الصف (MySQL CI).
        \Illuminate\Support\Carbon::setTestNow(
            \Illuminate\Support\Carbon::parse('2026-09-06 10:02:00', config('app.timezone', 'Asia/Kuwait')));
        $this->actingAs($this->owner)->get('/admin/errors/' . Str::uuid())->assertNotFound();
        $this->actingAs($this->owner)->get('/admin/errors/' . Str::uuid())->assertNotFound();

        $rows = DB::table('http_metric_buckets')->where('route', 'LIKE', '%{id}%')
            ->orderBy('bucket_at')->orderBy('id')->get();
        $this->assertCount(1, $rows, 'المطبِّع الواحد يجمع كل المعرّفات في صفٍّ واحد');
        $this->assertSame(2, (int) $rows[0]->count);

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'admin/errors/{id}'), 'الشاشة تعرض المسار المطبَّع صفاً واحداً');
    }

    /** الفرزُ قائمةٌ بيضاء: المجهول يرتدّ للافتراضي بلا سقوط، والمعلوم يقلب الترتيب فعلاً */
    public function test_sort_whitelist_rejects_unknown_and_sorts_by_err5(): void
    {
        $this->seedCore();
        $this->bucket('zz-few-errors', now()->subHour(), 50, 5000);
        $this->bucket('zz-many-errors', now()->subHours(2), 10, 1000, ['err5' => 9]);

        // عمودٌ خارج القائمة لا يصل SQL — الشاشة تعمل بالافتراضي (الأكثر طلبات أولاً)
        $html = $this->actingAs($this->owner)->get('/admin/ops?sort=hist%3BDROP')->assertOk()->getContent();
        $this->assertTrue(strpos($html, 'zz-few-errors') < strpos($html, 'zz-many-errors'),
            'الفرز الافتراضي (SUM(count) تنازلياً) بعد رفض عمودٍ مجهول');

        $html = $this->actingAs($this->owner)->get('/admin/ops?sort=err5')->assertOk()->getContent();
        $this->assertTrue(strpos($html, 'zz-many-errors') < strpos($html, 'zz-few-errors'),
            'الفرز بعمودٍ من القائمة يسري على المجاميع في القاعدة');
    }

    /** عيّنةٌ دون الحدّ الأدنى لا تُوسَم انحداراً مهما صرخ الفارق — بل «لا بيانات كافية» */
    public function test_small_sample_is_never_flagged_as_regression(): void
    {
        $this->seedCore();
        // السابقة ٥٠ طلباً بمتوسط 100ms والحالية ٥٠ بمتوسط 1000ms — الفارق ×١٠ لكن العيّنة < ١٠٠
        $this->bucket('zz-api', now()->subDays(8), 50, 5000);
        $this->bucket('zz-api', now()->subHour(), 50, 50000);

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();
        $this->assertStringContainsString('لا توجد بيانات تاريخية كافية', $html);
        $this->assertStringNotContainsString('تراجع الأداء', $html, 'لا وسمَ انحدارٍ من عيّنةٍ هزيلة');
        $this->assertStringNotContainsString('ارتفعت الأخطاء', $html);
    }

    /** تجاوزُ العتبة مع عيّنةٍ كافية يُوسَم — ومعه الدليل: القيمتان وحجم العيّنة */
    public function test_regression_over_threshold_with_enough_sample_is_flagged_with_evidence(): void
    {
        $this->seedCore();
        $this->bucket('zz-api', now()->subDays(8), 200, 20000);   // متوسط 100ms — عيّنة كافية
        $this->bucket('zz-api', now()->subHour(), 200, 40000);    // متوسط 200ms → ‎+100٪ > 30٪

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();
        $this->assertStringContainsString('تراجع الأداء', $html);
        $this->assertStringContainsString('200ms', $html, 'الدليل: القيمة الحالية');
        $this->assertStringContainsString('100ms', $html, 'الدليل: القيمة السابقة');
        $this->assertStringContainsString('العيّنة', $html, 'الدليل: حجم العيّنة معروض');
    }

    /** انحدارُ الأخطاء: النسبة من err4/err5 للحاويات + SUM(count) حسب النوع دليلاً */
    public function test_error_rate_regression_shows_kind_evidence(): void
    {
        $this->seedCore();
        $this->bucket('zz-api', now()->subDays(8), 200, 20000);                  // بلا أخطاء
        $this->bucket('zz-api', now()->subHour(), 200, 20000, ['err5' => 40]);   // ‏20٪ أخطاء
        $this->errorRow('php', 37, now()->subHour()->toDateTimeString());        // النافذة الحالية
        $this->errorRow('api', 5, now()->subDays(8)->toDateTimeString());        // النافذة السابقة

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();
        $this->assertStringContainsString('ارتفعت الأخطاء', $html);
        $this->assertStringContainsString('37', $html, 'دليل النوع: SUM(count) في النافذة الحالية');
    }

    /** بلا أي بيانات: حالةٌ فارغة صادقة لا أصفارٌ مُختلَقة — والشاشة للمالك وحده */
    public function test_no_data_shows_honest_empty_state(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get('/admin/ops')->assertForbidden();

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();
        $this->assertStringContainsString('لا توجد بيانات تاريخية كافية', $html);
    }

    /**
     * ميزانيةُ الاستعلام (critic #27): التجميعُ في القاعدة، وعددُ الاستعلامات ثابتٌ
     * لا ينمو مع عدد المسارات، وقراءةُ hist استعلامٌ واحدٌ مقيّدٌ بصفوف الصفحة وحدها.
     */
    public function test_query_budget_hist_read_only_for_page_rows(): void
    {
        $this->seedCore();
        for ($i = 0; $i < 20; $i++) {   // ٢٠ مساراً > حجم الصفحة (١٥)
            $this->bucket('zz-route-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                now()->subHour(), 100 - $i, (100 - $i) * 100);
        }

        DB::enableQueryLog();
        $this->actingAs($this->owner)->get('/admin/ops')->assertOk();
        // استعلاماتُ البيانات وحدها — فحوصُ المخطط (hub_has_col) كلفةٌ ثابتة تُخبّأ
        $selects = array_values(array_filter(DB::getQueryLog(), fn ($q) =>
            str_contains($q['query'], 'http_metric_buckets')
            && str_starts_with(ltrim(strtolower($q['query'])), 'select')
            && ! preg_match('/sqlite_master|pragma_table|information_schema/i', $q['query'])));
        DB::disableQueryLog();

        // عدُّ الصفحة + صفوفُها + مدرّجاتُها + نافذتا الانحدار + قراءةُ نبض الدلاء
        // (WP-2.7: النبضُ صار يقرأ الدلاءَ المجمَّعة بدل صفوف page_visits) —
        // كلفةٌ ثابتة، ولا استعلامَ لكل مسار
        $this->assertLessThanOrEqual(6, count($selects),
            'استعلامات القسم تتضخم مع عدد المسارات: ' . count($selects));

        $hist = array_values(array_filter($selects, fn ($q) => str_contains($q['query'], 'hist')));
        $this->assertCount(1, $hist, 'قراءةُ hist استعلامٌ واحدٌ لا حلقة');
        // حدّا النافذة + (route, method) لكل صفٍّ من صفوف الصفحة الـ١٥ حصراً — لا الـ٢٠ كلِّها
        $this->assertSame(2 + 15 * 2, count($hist[0]['bindings']),
            'hist يُقرأ لصفوف الصفحة المعروضة وحدها');
    }
}
