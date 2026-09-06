<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * دلاءُ RED لطلبات HTTP (الطور ٢ · WP-2.2): صفٌّ لكل (حاوية ٥ دقائق × سطح ×
 * فعل × مسار مطبَّع) يجمع العدَّ والأخطاء والبطءَ والأزمنة — يكتبه
 * `Observability::terminate` **بعد إرسال الردّ** فلا يدفع المستخدم ثمنه،
 * ولا يرمي أبداً: القياسُ إثراءٌ لا شرطٌ للردّ.
 */
class HttpMetricsTest extends TestCase
{
    /** تثبيتُ اللحظة وسطَ حاوية — كي لا يتشظّى طلبان على جدار الدقائق الخمس */
    protected function pinClock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-06 10:02:00', config('app.timezone', 'Asia/Kuwait')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    /** طلبٌ حقيقيّ عبر نواة HTTP يترك صفَّ قياسٍ واحداً — و`terminate` هو الكاتب */
    public function test_real_request_writes_one_bucket_row(): void
    {
        $this->seedCore();
        $this->pinClock();

        $this->actingAs($this->owner)->get('/m/clients')->assertOk();

        $row = DB::table('http_metric_buckets')->orderBy('route')->orderBy('id')->first();
        $this->assertNotNull($row, 'لم يُكتب أيُّ صفِّ قياسٍ بعد طلبٍ حقيقيّ');
        $this->assertSame('m/clients', $row->route);
        $this->assertSame('web', $row->surface);
        $this->assertSame('GET', $row->method);
        $this->assertSame(1, (int) $row->count);
        $this->assertSame(hub_metric_bucket(now())->toDateTimeString(), (string) $row->bucket_at,
            'الحاويةُ ليست أرضيةَ الدقائق الخمس للحظة الطلب');
        $this->assertGreaterThanOrEqual(0, (int) $row->sum_ms);

        // المدرَّجُ اللوغاريتمي يتغذّى طلباً بطلب — مجموعُ أعداده = count
        $hist = json_decode((string) $row->hist, true);
        $this->assertIsArray($hist, 'عمود hist فارغ — لا نسبَ مئويةً بلا مدرَّج');
        $this->assertSame(1, (int) array_sum($hist));
    }

    /** عائلاتُ الحالة تُعدّ في أعمدتها: 200 ⇒ count فقط، 404 ⇒ err4، 500 ⇒ err5 */
    public function test_status_families_count_in_their_columns(): void
    {
        $this->seedCore();
        $this->pinClock();
        Route::middleware('web')->get('/_wp22/ok', fn () => 'ok');
        Route::middleware('web')->get('/_wp22/none', fn () => abort(404));
        Route::middleware('web')->get('/_wp22/boom', fn () => abort(500));
        Route::middleware('api')->get('/api/v1/_wp22/ping', fn () => response()->json(['ok' => 1]));

        $this->get('/_wp22/ok')->assertOk();
        $this->get('/_wp22/none')->assertNotFound();
        $this->get('/_wp22/boom')->assertStatus(500);
        // بلا رمزٍ تردّ مجموعةُ api بـ401 — وردُّ المنع نفسُه يُقاس (سطح api · err4)
        $this->get('/api/v1/_wp22/ping')->assertStatus(401);

        $ok = DB::table('http_metric_buckets')->where('route', '_wp22/ok')->first();
        $this->assertNotNull($ok);
        $this->assertSame([1, 0, 0], [(int) $ok->count, (int) $ok->err4, (int) $ok->err5]);

        $none = DB::table('http_metric_buckets')->where('route', '_wp22/none')->first();
        $this->assertNotNull($none);
        $this->assertSame([1, 1, 0], [(int) $none->count, (int) $none->err4, (int) $none->err5]);

        $boom = DB::table('http_metric_buckets')->where('route', '_wp22/boom')->first();
        $this->assertNotNull($boom);
        $this->assertSame([1, 0, 1], [(int) $boom->count, (int) $boom->err4, (int) $boom->err5]);

        // والسطحُ يُميَّز: مساراتُ api/* تُوسم api لا web — وردُّ 401 يُعدّ err4
        $api = DB::table('http_metric_buckets')->where('route', 'api/v1/_wp22/ping')->first();
        $this->assertNotNull($api);
        $this->assertSame('api', $api->surface);
        $this->assertSame([1, 1], [(int) $api->count, (int) $api->err4]);
    }

    /** UUID ورقمٌ في المسار يُعمَّمان ({id} و{n}) فيلتقي الطلبان في صفٍّ واحد count=2 */
    public function test_identifiers_merge_and_same_bucket_is_one_row(): void
    {
        $this->seedCore();
        $this->pinClock();
        Route::middleware('web')->get('/_wp22/rec/{id}/v/{n}', fn ($id, $n) => 'ok');

        $this->get('/_wp22/rec/2f6b3c1a-9d4e-4a7b-8c1d-0e5f6a7b8c9d/v/7')->assertOk();
        $this->get('/_wp22/rec/9a8b7c6d-5e4f-4a3b-2c1d-0f9e8d7c6b5a/v/42')->assertOk();

        $rows = DB::table('http_metric_buckets')->where('route', 'like', '_wp22/rec%')
            ->orderBy('route')->orderBy('id')->get();
        $this->assertCount(1, $rows, 'كل معرّفٍ صار صفاً مستقلاً — لا تجميعَ بلا تطبيع');
        $this->assertSame('_wp22/rec/{id}/v/{n}', $rows[0]->route);
        $this->assertSame(2, (int) $rows[0]->count);
        $this->assertSame(2, (int) array_sum((array) json_decode((string) $rows[0]->hist, true)));
        $this->assertGreaterThanOrEqual((int) $rows[0]->max_ms,
            (int) $rows[0]->sum_ms, 'المجموع لا يقلّ عن الأقصى');
    }

    /** الملفاتُ والأصول الثابتة خارج القياس — تدفّقاتٌ طويلة تلوّث توزيع الأزمنة */
    public function test_files_and_storage_paths_are_excluded(): void
    {
        $this->seedCore();
        $this->pinClock();
        Route::middleware('web')->get('/storage/_wp22probe', fn () => 's');
        Route::middleware('web')->get('/files/_wp22probe', fn () => 'f');

        $this->get('/storage/_wp22probe');
        $this->get('/files/_wp22probe');

        $this->assertSame(0, DB::table('http_metric_buckets')->count(),
            'مساراتُ الملفات الثابتة دخلت القياس');
    }

    /** عدّادُ البطء في الدلو يعمل — والتقاطُ البطء القائم في مركز الأخطاء **يبقى** */
    public function test_slow_counter_and_existing_slow_capture_coexist(): void
    {
        $this->seedCore();
        $this->pinClock();
        $this->hubSetting('ops.slow_ms', '50');
        Route::middleware('web')->get('/_wp22/slow', function () {
            usleep(80000);
            return 'ok';
        });

        $this->get('/_wp22/slow')->assertOk();

        $row = DB::table('http_metric_buckets')->where('route', '_wp22/slow')->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->slow, 'طلبٌ فوق العتبة لم يُعدَّ بطيئاً في الدلو');

        // السكّةُ القائمة (يحرسها AuditRound2Test أيضاً): صفُّ «طلب بطيء» في مركز الأخطاء
        $slow = \App\Models\ErrorEvent::where('kind', 'slow')->orderBy('id')->get();
        $this->assertCount(1, $slow, 'التقاطُ البطء القائم تغيّر — وهو خارج نطاق هذه الحزمة');
        $this->assertStringContainsString('طلب بطيء', (string) $slow[0]->message);
    }

    /** إسقاطُ الجدول لا يكسر الطلب — القياسُ إثراءٌ لا شرط (حتى تحت كاشِ مخطّطٍ دافئ) */
    public function test_dropping_the_table_breaks_no_request(): void
    {
        $this->seedCore();
        $this->pinClock();
        Route::middleware('web')->get('/_wp22/still', fn () => 'ok');

        $this->get('/_wp22/still')->assertOk();       // يُدفّئ كاشَ حارس المخطّط ويكتب صفاً
        Schema::drop('http_metric_buckets');
        $this->get('/_wp22/still')->assertOk();       // الكتابةُ ترمي تحت الكاش الدافئ — وتُبتلع
    }

    /** عرضُ عمود route (١٦٠) يُفرض عند الكاتب — MySQL الصارمة ترمي ما يفيض */
    public function test_route_is_truncated_to_declared_width(): void
    {
        $this->seedCore();
        $this->pinClock();
        $long = '_wp22/' . str_repeat('a', 200);
        Route::middleware('web')->get('/' . $long, fn () => 'ok');

        $this->get('/' . $long)->assertOk();

        $row = DB::table('http_metric_buckets')->orderBy('route')->orderBy('id')->first();
        $this->assertNotNull($row);
        $this->assertLessThanOrEqual(160, mb_strlen((string) $row->route));
    }

    /** التقليم: الدلاءُ الأقدم من مدّة الاحتفاظ تُحذف على دفعات، والحديثةُ تبقى */
    public function test_retention_prunes_old_buckets(): void
    {
        $this->seedCore();
        DB::table('http_metric_buckets')->insert([
            ['bucket_at' => now()->subDays(120)->toDateTimeString(), 'surface' => 'web', 'method' => 'GET',
             'route' => '_wp22/old', 'count' => 1, 'sum_ms' => 5, 'max_ms' => 5, 'updated_at' => now()],
            ['bucket_at' => now()->subDays(5)->toDateTimeString(), 'surface' => 'web', 'method' => 'GET',
             'route' => '_wp22/fresh', 'count' => 1, 'sum_ms' => 5, 'max_ms' => 5, 'updated_at' => now()],
        ]);

        Artisan::call('hub:automation');

        $left = DB::table('http_metric_buckets')->orderBy('route')->orderBy('id')->pluck('route');
        $this->assertTrue($left->contains('_wp22/fresh'), 'الحديثُ حُذف — التقليم أعمى');
        $this->assertFalse($left->contains('_wp22/old'), 'الأقدمُ من مدّة الاحتفاظ لم يُقلَّم');
    }
}
