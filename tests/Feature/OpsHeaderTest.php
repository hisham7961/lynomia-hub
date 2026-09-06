<?php

namespace Tests\Feature;

use App\Support\Health;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * (WP-2.7 · spec §3.1) ترويسةُ الصحّة الواحدة في مركز التشغيل: الحالةُ العامة
 * **ومنذ متى** (من سلسلة `ops/health/rank` التي تكتبها لقطةُ hub:ops-snapshot)،
 * والمكوّناتُ الساقطة، والنسخةُ والبيئة (يعيدهما نموذجُ الصحّة سلفاً وكانا لا يُعرضان).
 *
 * «منذ متى» بلا تاريخٍ كذبٌ — فحين لا سلسلةَ بعدُ تقول الترويسةُ
 * «سيبدأ القياس من الآن» بدل اختراع مدّة.
 */
class OpsHeaderTest extends TestCase
{
    /** يقتطع كتلةَ الترويسة وحدها — كي لا تنجح المزاعمُ بنصٍّ صدفةً من الشريط الجانبي */
    protected function headerBlock(string $html): string
    {
        $start = strpos($html, 'id="ops-hdr"');
        $end = strpos($html, 'ops-hdr-end');
        $this->assertNotFalse($start, 'كتلة الترويسة id="ops-hdr" غائبة عن الشاشة');
        $this->assertNotFalse($end, 'علامة نهاية الترويسة ops-hdr-end غائبة');

        return substr($html, $start, $end - $start);
    }

    /** «منذ متى»: أولُ نقطةٍ بالرتبة الحالية بعد آخر نقطةٍ برتبةٍ مغايرة — من السلسلة لا من التخمين */
    public function test_header_shows_since_when_from_rank_series(): void
    {
        $this->seedCore();

        // رتبةُ الحالة الحيّة كما ستُحسب وقتَ الطلب — والسلسلةُ تُبذر حولها:
        // نقطةٌ مغايرة قبل ٣ ساعات ثم نقطتان بالرتبة الحالية (−٢ س، −١ س)
        $rank = (float) Health::rank(Health::check()['status']);
        $other = $rank === 0.0 ? 4.0 : 0.0;
        $sinceAt = now()->subHours(2);
        hub_metric_put('ops', 'health', 'rank', $other, now()->subHours(3), 'auto');
        hub_metric_put('ops', 'health', 'rank', $rank, $sinceAt, 'auto');
        hub_metric_put('ops', 'health', 'rank', $rank, now()->subHour(), 'auto');

        $hdr = $this->headerBlock($this->actingAs($this->owner)
            ->get('/admin/ops')->assertOk()->getContent());

        $this->assertStringContainsString($sinceAt->diffForHumans(), $hdr,
            'الترويسة لا تعرض «منذ متى» من سلسلة ops/health/rank');
    }

    /** بلا سلسلةٍ بعد: لا مدّةَ مُختلَقة — «سيبدأ القياس من الآن» */
    public function test_header_without_series_is_honest(): void
    {
        $this->seedCore();
        DB::table('metric_points')->delete();

        $hdr = $this->headerBlock($this->actingAs($this->owner)
            ->get('/admin/ops')->assertOk()->getContent());

        $this->assertStringContainsString('سيبدأ القياس من الآن', $hdr,
            'بلا سلسلةِ رتبٍ يجب أن تصدُق الترويسة لا أن تخترع مدّة');
    }

    /** النسخةُ والبيئة — يعيدهما Health::check سلفاً، والترويسة تعرضهما أخيراً */
    public function test_header_shows_version_and_environment(): void
    {
        $this->seedCore();

        $hdr = $this->headerBlock($this->actingAs($this->owner)
            ->get('/admin/ops')->assertOk()->getContent());

        $this->assertStringContainsString(Health::version(), $hdr, 'النسخة غائبة عن الترويسة');
        $this->assertStringContainsString((string) config('app.env'), $hdr, 'البيئة غائبة عن الترويسة');
    }

    /** المكوّناتُ الساقطة تُسمّى في الترويسة — وضعُ الصيانة يجعل مكوّن «الإعداد» غيرَ سليم */
    public function test_header_lists_failing_components(): void
    {
        $this->seedCore();
        $this->hubSetting('maintenance.on', '1');

        $hdr = $this->headerBlock($this->actingAs($this->owner)
            ->get('/admin/ops')->assertOk()->getContent());

        $this->assertStringContainsString('الإعداد', $hdr,
            'مكوّنٌ غيرُ سليم (الإعداد في وضع الصيانة) لا يُسمّى في الترويسة');
    }

    /** ميزانيةُ الاستعلامات: الفتحةُ الدافئة (الخبايا مملوءة) لا تتجاوز ٨٠ استعلاماً */
    public function test_ops_page_query_budget_warm(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/admin/ops')->assertOk();   // باردة — تملأ الخبايا

        $n = 0;
        $count = false;
        DB::listen(function () use (&$n, &$count) { if ($count) $n++; });
        $count = true;
        $this->actingAs($this->owner)->get('/admin/ops')->assertOk();
        $count = false;

        $this->assertLessThanOrEqual(80, $n,
            "الفتحة الدافئة لمركز التشغيل كلّفت {$n} استعلاماً والميزانية ٨٠");
    }
}
