<?php

namespace Tests\Feature;

use App\Support\Health;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * (WP-2.7 · spec §3.13/§3.16) لوحةُ النسخ وارتباطُ الإصدار في مركز التشغيل.
 *
 * الملفّاتُ وحدها لا تحكي فشلَ النسخ (نسخةٌ فاشلة لا تترك ملفاً) — فالتاريخ يُقرأ
 * من سلسلة ('ops','backup','run') بنجاحه وفشله. ولا استعادةَ من الويب عمداً.
 * وارتباطُ الإصدار لا يحكم بلا عيّنةٍ كافية (spec §26: لا أرقامَ مُختلَقة).
 */
class OpsBackupsReleasesTest extends TestCase
{
    /** تاريخُ التشغيل يعرض النجاح والفشل معاً من metric_points — والفشلُ لا يختفي لغياب ملفه */
    public function test_backup_run_history_shows_failures(): void
    {
        $this->seedCore();
        Health::beat('backup', 1234, 'ok', 'hub-2026.json · 10 سجلات');
        $this->travel(1)->minutes();   // نقطتان بلحظتين — المفتاح الفريد لا يدمجهما
        Health::beat('backup', null, 'fail', 'القرص ممتلئ');
        $this->travelBack();

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();

        $this->assertStringContainsString('تاريخ التشغيل', $html, 'جدول تاريخ النسخ غائب');
        $this->assertStringContainsString('القرص ممتلئ', $html, 'التشغيلة الفاشلة لا تُعرض');
        $this->assertStringContainsString('فاشلاً', $html, 'عدّاد الفشل غائب');
        // لا استعادةَ من الويب — الشاشة تقولها وتُحيل إلى كتيّب التشغيل
        $this->assertStringContainsString('لا استعادةَ من هذه الشاشة', $html);
        $this->assertStringContainsString(route('ops.runbooks'), $html);
    }

    /** بلا نشراتٍ مسجَّلة: حالةٌ فارغة صادقة لا جدولٌ موهِم */
    public function test_release_correlation_empty_state_is_honest(): void
    {
        $this->seedCore();

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();
        $this->assertStringContainsString('لا نشراتٍ مسجَّلةً بعد', $html);
    }

    /** عيّنةٌ دون الحدّ الأدنى ⇒ «لا توجد بيانات تاريخية كافية» — لا حكمَ على عيّنة هزيلة */
    public function test_release_with_thin_sample_gets_no_verdict(): void
    {
        $this->seedCore();
        DB::table('deployments')->insert(['id' => (string) Str::uuid(), 'ver' => '9.9.9',
            'env' => 'production', 'deployed_at' => now()->subHours(2),
            'created_at' => now(), 'updated_at' => now()]);

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();
        $this->assertStringContainsString('9.9.9', $html, 'النشرة لا تُعرض');
        $this->assertStringContainsString('لا توجد بيانات تاريخية كافية', $html);
    }

    /** عيّنةٌ كافية ونسبةُ أخطاءٍ ارتفعت بعد النشر ⇒ وسمُ التدهور بنسبته */
    public function test_release_regression_is_flagged_with_enough_sample(): void
    {
        $this->seedCore();
        $this->hubSetting('ops.regression_min_n', '10');
        $deployedAt = now()->subHours(2);
        DB::table('deployments')->insert(['id' => (string) Str::uuid(), 'ver' => '9.9.9',
            'env' => 'production', 'deployed_at' => $deployedAt,
            'created_at' => now(), 'updated_at' => now()]);
        // قبل النشر: ١٠٠ طلبٍ بخطأ خادمٍ واحد (1٪) — بعده: ١٠٠ طلبٍ بعشرة (10٪)
        DB::table('http_metric_buckets')->insert([
            ['bucket_at' => $deployedAt->copy()->subHours(3), 'surface' => 'web', 'method' => 'GET',
             'route' => '/x', 'count' => 100, 'err4' => 0, 'err5' => 1, 'slow' => 0,
             'sum_ms' => 1000, 'max_ms' => 50],
            ['bucket_at' => $deployedAt->copy()->addHour(), 'surface' => 'web', 'method' => 'GET',
             'route' => '/x', 'count' => 100, 'err4' => 0, 'err5' => 10, 'slow' => 0,
             'sum_ms' => 1000, 'max_ms' => 50],
        ]);

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();
        $this->assertStringContainsString('أخطاء بعد النشر', $html,
            'ارتفاعُ نسبة الأخطاء بعد النشر (1٪ ← 10٪) لم يُوسَم');
    }
}
