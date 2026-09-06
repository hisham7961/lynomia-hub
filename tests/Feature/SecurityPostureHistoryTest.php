<?php

namespace Tests\Feature;

use App\Support\Health;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WP-4.2 — تاريخُ الوضعية الأمنية: لقطةٌ يومية في metric_points ('security','org').
 *
 * القواعد المُثبَتة:
 *  - لقطتان بيومين ⇒ سلسلةُ نقطتين؛ إعادةُ التشغيل في اليوم نفسه تُحدِّث ولا تكرّر.
 *  - بلا لقطاتٍ تُصارح الشاشةُ «سيبدأ القياس من الآن» — لا صفرٌ مُختلَق.
 *  - الأمرُ مرئيّ: مفتاح security في Health::JOBS وschedule:list — وإلا فلا يراقبه أحد.
 *  - اللقطةُ تكتب درجةَ SecurityPosture::summary نفسَها (توحيدُ الدرجة) وتُشغّل reconcile.
 */
class SecurityPostureHistoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function point(string $metric)
    {
        return DB::table('metric_points')->where('module', 'security')
            ->where('record_id', 'org')->where('metric', $metric);
    }

    /** لقطتان بيومين ⇒ نقطتان؛ والدرجةُ هي درجةُ SecurityPosture::summary الواحدة */
    public function test_two_snapshots_on_two_days_make_a_two_point_series(): void
    {
        $this->seedCore();

        Carbon::setTestNow(Carbon::parse('2026-09-06 23:40:00'));
        $this->artisan('hub:security-snapshot')->assertExitCode(0);

        Carbon::setTestNow(Carbon::parse('2026-09-07 23:40:00'));
        $this->artisan('hub:security-snapshot')->assertExitCode(0);

        $series = hub_metric_series('security', 'org', 'score', 90);
        $this->assertCount(2, $series, 'لقطتان بيومين لم تُنتجا نقطتين');

        // توحيدُ الدرجة: المكتوبُ هو درجةُ SecurityPosture::summary لا معادلةً ثانية
        $expected = (float) \App\Support\SecurityPosture::summary()['score'];
        $this->assertSame($expected, (float) end($series)['value']);

        // وكلُّ مقاييس اللقطة الثمانية كُتبت
        foreach (['score', 'bad', 'wn', 'priv_no_mfa', 'stale_secrets', 'suspicious',
                  'findings_critical', 'findings_high'] as $m) {
            $this->assertSame(2, $this->point($m)->count(), "المقياس {$m} لم يُكتب في كل لقطة");
        }

        // واللقطةُ شغّلت reconcile — نتائجُ المنظّمة كُتبت (بيئةُ الاختبار فيها فحوصٌ غيرُ سليمة حتماً)
        $this->assertGreaterThan(0, DB::table('security_findings')->where('entity_type', 'org')->count(),
            'اللقطةُ لم تُشغّل SecurityFindings::reconcile');

        // والنبضةُ تُرى في نموذج الصحّة
        $this->assertNotNull(setting('heartbeat.security'), 'نبضةُ security لم تُكتب');
    }

    /** إعادةُ التشغيل في اليوم نفسه تُحدِّث النقطةَ ولا تكرّرها */
    public function test_same_day_rerun_updates_the_point_instead_of_duplicating(): void
    {
        $this->seedCore();

        // تشغيلٌ أول والدرجةُ منخفضة (فحصُ ssrf مكسورٌ عمداً)
        $this->hubSetting('monitor.allow_private', '1');
        Carbon::setTestNow(Carbon::parse('2026-09-06 10:00:00'));
        $this->artisan('hub:security-snapshot')->assertExitCode(0);
        $before = (float) $this->point('score')->value('value');

        // أُصلح الشرطُ وأُعيد التشغيلُ في اليوم نفسه: النقطةُ تُحدَّث لا تتكرّر
        $this->hubSetting('monitor.allow_private', '0');
        Carbon::setTestNow(Carbon::parse('2026-09-06 18:30:00'));
        $this->artisan('hub:security-snapshot')->assertExitCode(0);

        $this->assertSame(1, $this->point('score')->count(), 'إعادةُ التشغيل كرّرت نقطةَ اليوم');
        $after = (float) $this->point('score')->value('value');
        $this->assertGreaterThan($before, $after, 'النقطةُ لم تُحدَّث بالقيمة الجديدة');
    }

    /** قبل أول لقطة: «سيبدأ القياس من الآن» — لا رسمُ صفرٍ كاذب */
    public function test_empty_state_before_first_snapshot_is_honest(): void
    {
        $this->seedCore();

        $this->assertNull(hub_metric_latest('security', 'org', 'score'), 'لا قياسَ بعدُ — يجب أن يكون null لا صفراً');
        $this->actingAs($this->owner)->get('/admin/security/findings')
            ->assertOk()->assertSee('سيبدأ القياس من الآن');
    }

    /** الأمرُ مرئيّ لنموذج الصحّة وللمجدول */
    public function test_command_is_in_health_jobs_and_schedule_list(): void
    {
        $this->assertArrayHasKey('security', Health::JOBS,
            'Health::JOBS بلا مفتاح security — اللقطةُ غيرُ مرئية لنموذج الصحّة');
        Artisan::call('schedule:list');
        $this->assertStringContainsString('hub:security-snapshot', Artisan::output(), 'الأمرُ غيرُ مجدول');
    }
}
