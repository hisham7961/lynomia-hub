<?php

namespace Tests\Feature;

use App\Support\TimeRange;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * المدى الزمنيّ الموحّد (WP-1.1): كلُّ preset يعطي حدوداً صحيحة بتوقيت المنصّة،
 * و`to` حصريٌّ دائماً، والمدى المعكوس يُقوَّم، والمدخل العدائيّ يرتدّ للافتراضي
 * بلا ٥٠٠ — و`from/to` القديمتان تعطيان **نفس** ما تحسبه شاشة التدقيق اليوم
 * (whereDate >= from و<= to) فلا ينكسر رابطٌ محفوظ.
 */
class TimeRangeTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** طلب GET بمعاملاتٍ فقط — بلا مساسٍ بطلب الحاوية */
    protected function req(array $q): Request
    {
        return Request::create('/x', 'GET', $q);
    }

    /** كل preset يعطي نافذةً منزلقة تنتهي الآن بطولها المعلَن — وبتوقيت المنصّة */
    public function test_every_preset_gives_correct_bounds_in_app_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00', config('app.timezone')));
        foreach (TimeRange::PRESETS as $key => [$minutes, $label]) {
            $tr = TimeRange::fromRequest($this->req(['range' => $key]));
            $this->assertSame($key, $tr->preset, "preset {$key}");
            $this->assertSame('2026-03-15 12:00:00', $tr->to->format('Y-m-d H:i:s'), "نهاية {$key} ليست الآن");
            $this->assertSame(now()->subMinutes($minutes)->format('Y-m-d H:i:s'),
                $tr->from->format('Y-m-d H:i:s'), "بداية {$key}");
            $this->assertSame(config('app.timezone'), $tr->from->getTimezone()->getName());
            $this->assertSame(config('app.timezone'), $tr->to->getTimezone()->getName());
            $this->assertSame($minutes, $tr->minutes(), "دقائق {$key}");
            $this->assertEqualsWithDelta($minutes / 1440, $tr->days(), 0.0001, "أيام {$key}");
            $this->assertSame($key, $tr->key());
            $this->assertSame($label, $tr->label());
        }
    }

    /** الافتراضي 7d حين لا معامل — وقابلٌ للتبديل */
    public function test_default_preset_applies_when_nothing_is_sent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00', config('app.timezone')));
        $this->assertSame('7d', TimeRange::fromRequest($this->req([]))->preset);
        $tr = TimeRange::fromRequest($this->req([]), '24h');
        $this->assertSame('24h', $tr->preset);
        $this->assertSame(1440, $tr->minutes());
    }

    /** `to` حصريّ: صفٌّ على حدّ النهاية بالضبط لا يدخل — وصفُّ حدّ البداية يدخل */
    public function test_to_is_exclusive_in_apply(): void
    {
        $in1 = DB::table('audits')->insertGetId(['action' => 'a', 'created_at' => '2026-01-05 00:00:00']);
        $in2 = DB::table('audits')->insertGetId(['action' => 'b', 'created_at' => '2026-01-05 23:59:59']);
        $out = DB::table('audits')->insertGetId(['action' => 'c', 'created_at' => '2026-01-06 00:00:00']);

        $tr = TimeRange::fromRequest($this->req(['range' => 'custom',
            'from' => '2026-01-05 00:00:00', 'to' => '2026-01-06 00:00:00']));
        $ids = $tr->apply(DB::table('audits'))->orderBy('id')->pluck('id')->all();
        $this->assertSame([$in1, $in2], $ids);
        $this->assertNotContains($out, $ids, 'صفٌّ على حدّ النهاية دخل — to ليس حصرياً');
    }

    /** مدىً معكوس يُقوَّم قبل بسط اليوم — فالمعنى «بين اليومين» أيّاً كان ترتيبهما */
    public function test_reversed_custom_range_is_corrected(): void
    {
        $tr = TimeRange::fromRequest($this->req(['from' => '2026-01-10', 'to' => '2026-01-05']));
        $this->assertSame('custom', $tr->preset);
        $this->assertSame('2026-01-05 00:00:00', $tr->from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-11 00:00:00', $tr->to->format('Y-m-d H:i:s'), 'اليوم الأبعد يدخل كاملاً');
    }

    /** مدخلٌ عدائيّ يرتدّ للافتراضي — ولا يُسقط العرض ولا يرتدّ محتواه للصفحة */
    public function test_hostile_input_falls_back_and_never_500s_when_rendered(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00', config('app.timezone')));
        $script = '<script>alert(1)</script>';
        foreach ([
            ['range' => $script],
            ['range' => 'custom', 'from' => 'garbage', 'to' => 'also-garbage'],
            ['range' => ['x'], 'from' => ['y']],
            ['from' => 'not-a-date'],
        ] as $q) {
            $tr = TimeRange::fromRequest($this->req($q));
            $this->assertSame('7d', $tr->preset, 'مدخلٌ عدائيّ لم يرتدّ للافتراضي: ' . json_encode($q));
            $this->assertTrue($tr->from->lt($tr->to));
        }

        Route::get('/_t/timerange', fn () => view('partials.timerange', ['range' => hub_range()]));
        foreach (['/_t/timerange?range=' . urlencode($script) . '&from=garbage&keep=1',
                  '/_t/timerange?range[]=x&from[]=y&to[]=z'] as $url) {
            $res = $this->get($url);
            $this->assertLessThan(500, $res->status(), "سقط العرض: {$url}");
            $this->assertStringNotContainsString('<script>alert', $res->getContent(), 'مدخلٌ عدائيّ ارتدّ خاماً');
        }
    }

    /** انحدارٌ مثبَّت: from/to وحدهما تعطيان نفسَ صفوف شاشة التدقيق اليوم (whereDate >=/<=) */
    public function test_bare_from_to_reproduces_the_audit_screen_window(): void
    {
        foreach (['2026-01-04 23:59:59', '2026-01-05 00:00:00', '2026-01-07 12:34:56',
                  '2026-01-10 23:59:59', '2026-01-11 00:00:00'] as $i => $at) {
            DB::table('audits')->insert(['action' => 'r' . $i, 'created_at' => $at]);
        }
        // ما تحسبه AuditController::index اليوم حرفياً
        $legacy = DB::table('audits')->whereDate('created_at', '>=', '2026-01-05')
            ->whereDate('created_at', '<=', '2026-01-10')->orderBy('id')->pluck('id')->all();
        $this->assertCount(3, $legacy, 'عيّنة الاختبار نفسها ناقصة');

        $tr = TimeRange::fromRequest($this->req(['from' => '2026-01-05', 'to' => '2026-01-10']));
        $this->assertSame('custom', $tr->preset, 'from/to وحدهما = custom (توافقٌ رجعيّ)');
        $mine = $tr->apply(DB::table('audits'))->orderBy('id')->pluck('id')->all();
        $this->assertSame($legacy, $mine, 'المدى الموحّد انحرف عن نافذة شاشة التدقيق');
    }

    /** توافق API: created_from/created_to بدلالة اليوم كاملاً (كما whereDate في Api::timeFilters) وupdated_since لحظة */
    public function test_api_time_filter_params_are_accepted_unrenamed(): void
    {
        $tr = TimeRange::fromRequest($this->req(['created_from' => '2026-01-05 14:00:00', 'created_to' => '2026-01-08']));
        $this->assertSame('custom', $tr->preset);
        $this->assertSame('2026-01-05 00:00:00', $tr->from->format('Y-m-d H:i:s'), 'created_from يومٌ كامل كما في Api::timeFilters');
        $this->assertSame('2026-01-09 00:00:00', $tr->to->format('Y-m-d H:i:s'));

        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00', config('app.timezone')));
        $tr = TimeRange::fromRequest($this->req(['updated_since' => '2026-01-05 08:30:00']));
        $this->assertSame('2026-01-05 08:30:00', $tr->from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-15 12:00:00', $tr->to->format('Y-m-d H:i:s'));
    }

    /** d/days العدديّان (السوشال/مقاييس API) — وقيمة d غير العددية (اتجاه فرز الوحدات) لا تُختطف */
    public function test_numeric_days_params_are_accepted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00', config('app.timezone')));
        $tr = TimeRange::fromRequest($this->req(['days' => '14']));
        $this->assertSame('custom', $tr->preset);
        $this->assertSame(now()->subDays(14)->format('Y-m-d H:i:s'), $tr->from->format('Y-m-d H:i:s'));
        $this->assertSame('30d', TimeRange::fromRequest($this->req(['d' => '30']))->preset);
        $this->assertSame('7d', TimeRange::fromRequest($this->req(['d' => 'asc']))->preset, 'd=asc اتجاهُ فرزٍ لا أيام');
    }

    /** النافذة السابقة المساوية: تنتهي حيث تبدأ الحالية وبنفس الطول — وبمفتاح خبيئةٍ مختلف */
    public function test_prev_returns_the_equal_adjacent_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00', config('app.timezone')));
        $cur = TimeRange::fromRequest($this->req(['range' => '30d']));
        $prev = $cur->prev();
        $this->assertSame($cur->from->format('Y-m-d H:i:s'), $prev->to->format('Y-m-d H:i:s'));
        $this->assertSame($cur->minutes(), $prev->minutes());
        $this->assertSame(now()->subDays(60)->format('Y-m-d H:i:s'), $prev->from->format('Y-m-d H:i:s'));
        $this->assertNotSame($cur->key(), $prev->key(), 'مفتاحا نافذتين مختلفتين تطابقا — تلوّث خبيئة');
    }

    /** toQuery يعيد بناء نفس المدى حرفياً — أساس الروابط الحافظة لباقي المعاملات */
    public function test_to_query_round_trips(): void
    {
        $this->assertSame(['range' => '6h'], TimeRange::fromRequest($this->req(['range' => '6h']))->toQuery());

        $tr = TimeRange::fromRequest($this->req(['from' => '2026-01-05', 'to' => '2026-01-10']));
        $q = $tr->toQuery();
        $this->assertSame(['range' => 'custom', 'from' => '2026-01-05', 'to' => '2026-01-10'], $q);
        $back = TimeRange::fromRequest($this->req($q));
        $this->assertSame($tr->from->format('Y-m-d H:i:s'), $back->from->format('Y-m-d H:i:s'));
        $this->assertSame($tr->to->format('Y-m-d H:i:s'), $back->to->format('Y-m-d H:i:s'));

        $tr = TimeRange::fromRequest($this->req(['range' => 'custom',
            'from' => '2026-01-05 08:30:00', 'to' => '2026-01-06 17:00:00']));
        $back = TimeRange::fromRequest($this->req($tr->toQuery()));
        $this->assertSame('2026-01-05 08:30:00', $back->from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-06 17:00:00', $back->to->format('Y-m-d H:i:s'));
    }

    /** غلاف hub_range يقرأ طلب الحاوية ويعيد نفس النوع */
    public function test_hub_range_wrapper_reads_the_container_request(): void
    {
        $this->assertInstanceOf(TimeRange::class, hub_range());
        $this->assertSame('90d', hub_range($this->req(['range' => '90d']))->preset);
    }
}
