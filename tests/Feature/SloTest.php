<?php

namespace Tests\Feature;

use App\Support\Series;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WP-2.5 — أهدافُ مستوى الخدمة (SLO) وميزانيةُ الخطأ على شاشة التشغيل (spec §3.10/§26).
 *
 * ثلاثةُ عقودٍ يحرسها هذا الملف:
 *   ١) **مطفأةٌ كلّياً ما لم تُضبط المفاتيح** — بلا `slo.window_days` وهدفٍ واحدٍ
 *      على الأقل لا تظهر البطاقةُ ولا رقمٌ واحد، ولا يُطبع «99.9» مُختلَقاً أبداً.
 *   ٢) بضبطٍ وبياناتٍ تغطّي النافذة: SLI محسوبةٌ من المصدر الحقيقيّ
 *      (metric_points للتوافر، http_metric_buckets للزمن والأخطاء)، والامتثالُ
 *      بالاتجاه الصحيح، وميزانيةُ الخطأ بالمسموح/المستهلَك/المتبقّي عدّاً فعلياً.
 *   ٣) تاريخٌ لا يغطّي النافذةَ ⇒ «لا توجد بيانات تاريخية كافية» بلا أي SLI —
 *      امتثالُ ثلاثين يوماً من بيانات ساعةٍ رقمٌ زائف (spec §26).
 */
class SloTest extends TestCase
{
    /** بذرُ حاويةِ RED بالشكل الذي يكتبه Observability::terminate حرفياً */
    protected function bucket($at, int $count, array $vals, array $o = []): void
    {
        DB::table('http_metric_buckets')->insert(array_merge([
            'bucket_at' => hub_metric_bucket($at)->toDateTimeString(),
            'surface'   => 'web', 'method' => 'GET',
            'route'     => 'zz-slo/' . Str::random(6),
            'count'     => $count, 'err4' => 0, 'err5' => 0, 'slow' => 0,
            'sum_ms'    => (int) array_sum($vals), 'max_ms' => (int) max($vals ?: [0]),
            'hist'      => json_encode(Series::hist($vals)),
            'updated_at' => now()->toDateTimeString(),
        ], $o));
    }

    /** بذرُ فحوص توافر (metric_points · metric=up) دفعةً — الناجحُ أولاً ثم الفاشل */
    protected function upChecks($start, int $good, int $bad): void
    {
        $rid = (string) Str::uuid();
        $rows = [];
        $i = 0;
        foreach ([1 => $good, 0 => $bad] as $val => $n) {
            for ($k = 0; $k < $n; $k++, $i++) {
                $rows[] = [
                    'id' => (string) Str::uuid(), 'module' => 'servers', 'record_id' => $rid,
                    'metric' => 'up', 'value' => $val, 'source' => 'monitor',
                    'at' => Carbon::parse($start)->addMinutes($i)->toDateTimeString(),
                    'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
                ];
            }
        }
        foreach (array_chunk($rows, 100) as $chunk) DB::table('metric_points')->insert($chunk);
    }

    /** بلا مفاتيحَ لا بطاقةَ ولا رقم — ومفتاحُ هدفٍ بلا نافذةٍ لا يُشعل شيئاً ولا يطبع 99.9 */
    public function test_without_keys_the_card_is_entirely_off(): void
    {
        $this->seedCore();

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();
        $this->assertStringNotContainsString('أهداف مستوى الخدمة', $html, 'البطاقة مطفأة كلياً بلا مفاتيح');

        // هدفُ توافرٍ وحدَه بلا نافذة = غيرُ مفعَّل — والرقمُ المضبوط لا يتسرّب للشاشة
        $this->hubSetting('slo.availability_pct', '99.9');
        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();
        $this->assertStringNotContainsString('أهداف مستوى الخدمة', $html, 'هدفٌ بلا نافذةٍ لا يُشعل البطاقة');
        $this->assertStringNotContainsString('99.9', $html, 'لا يُطبع 99.9 مُختلَقاً أبداً');
    }

    /** بضبطٍ وبياناتٍ كافية: SLI والامتثال والميزانية المستهلكة والمتبقية — من العدّ الفعلي */
    public function test_with_keys_and_enough_data_sli_compliance_and_budget_are_computed(): void
    {
        $this->seedCore();
        $this->hubSetting('slo.window_days', '7');
        $this->hubSetting('slo.availability_pct', '99');
        $this->hubSetting('slo.latency_ms', '500');
        $this->hubSetting('slo.latency_pct', '95');
        $this->hubSetting('slo.error_rate_pct', '1');

        // التوافر: ٢٠٠ فحصاً منذ بداية النافذة، ١٩٩ ناجحاً ⇒ SLI = 99.5٪ ≥ 99
        $this->upChecks(now()->subDays(7)->addMinutes(10), 199, 1);
        // الحاويات: ١٠٠ طلبٍ سريع (100ms) أولَ النافذة، ثم ٩٥ سريعاً و٥ بطيئاً (2000ms)
        // مع ٤ أخطاءِ خادم ⇒ الزمن 195/200 = 97.5٪ ≥ 95، والأخطاء 4/200 = 2٪ > 1
        $this->bucket(now()->subDays(7)->addMinutes(10), 100, array_fill(0, 100, 100.0));
        $this->bucket(now()->subHour(), 100,
            array_merge(array_fill(0, 95, 100.0), array_fill(0, 5, 2000.0)), ['err5' => 4]);

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();

        $this->assertStringContainsString('أهداف مستوى الخدمة', $html, 'البطاقة تظهر حين تُضبط المفاتيح');
        // SLI محسوبةٌ من المصدر لا مُختلَقة
        $this->assertStringContainsString('SLI الفعلي <b>99.5٪</b>', $html, 'التوافر 199/200');
        $this->assertStringContainsString('SLI الفعلي <b>97.5٪</b>', $html, 'الزمن 195/200 تحت العتبة (من المدرَّج)');
        $this->assertStringContainsString('SLI الفعلي <b>2٪</b>', $html, 'أخطاء الخادم 4/200');
        // الامتثال بالاتجاه الصحيح: هدفان ملتزمان وهدفُ الأخطاء متجاوز
        $this->assertSame(2, substr_count($html, '✓ ملتزم'), 'التوافر والزمن ملتزمان');
        $this->assertSame(1, substr_count($html, '⚠️ متجاوز'), 'الأخطاء فوق السقف');
        // ميزانية الخطأ عدّاً فعلياً: المسموح ⇒ المستهلك (٪) ⇒ المتبقي
        $this->assertStringContainsString('المستهلك 1 (50٪)', $html, 'توافر: فحصٌ فاشل من ميزانية فحصين');
        $this->assertStringContainsString('المستهلك 5 (50٪)', $html, 'زمن: ٥ بطيئات من ميزانية ١٠');
        $this->assertStringContainsString('المستهلك 4 (200٪)', $html, 'أخطاء: ٤ من ميزانية ٢ — فوق الميزانية');
        $this->assertStringContainsString('تقريبية', $html, 'الزمنُ من مدرَّجٍ لوغاريتمي — التقريب مُعلَن');
    }

    /** تاريخٌ لا يغطّي النافذة ⇒ الرسالة الصادقة بلا أي SLI ولا حكم امتثال */
    public function test_incomplete_history_prints_the_honest_message_not_numbers(): void
    {
        $this->seedCore();
        $this->hubSetting('slo.window_days', '30');
        $this->hubSetting('slo.availability_pct', '99.9');
        $this->hubSetting('slo.latency_ms', '500');
        $this->hubSetting('slo.latency_pct', '95');
        $this->hubSetting('slo.error_rate_pct', '1');

        // قياسٌ حديثٌ فقط: ساعةٌ من البيانات لا تصلح امتثالَ ثلاثين يوماً
        $this->upChecks(now()->subMinutes(30), 20, 0);
        $this->bucket(now()->subHour(), 200, array_fill(0, 200, 100.0));

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();

        $this->assertStringContainsString('أهداف مستوى الخدمة', $html, 'البطاقة تظهر بالمفاتيح');
        $this->assertStringContainsString('لا توجد بيانات تاريخية كافية — القياس المتاح', $html,
            'الرسالة الصادقة مع سبب النقص');
        $this->assertStringNotContainsString('SLI الفعلي', $html, 'لا رقمَ SLI من تاريخٍ ناقص');
        $this->assertStringNotContainsString('✓ ملتزم', $html, 'لا حكمَ امتثالٍ من تاريخٍ ناقص');
        $this->assertStringNotContainsString('⚠️ متجاوز', $html);
        $this->assertStringNotContainsString('ميزانية الخطأ: المسموح', $html, 'لا ميزانيةَ من تاريخٍ ناقص');
    }

    /** ولا بياناتَ إطلاقاً مع المفاتيح مضبوطةً: الرسالة نفسُها لا أصفارٌ ولا 100٪ زائفة */
    public function test_no_data_at_all_with_keys_set_is_honest_too(): void
    {
        $this->seedCore();
        $this->hubSetting('slo.window_days', '7');
        $this->hubSetting('slo.availability_pct', '99.9');
        $this->hubSetting('slo.error_rate_pct', '1');

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();

        $this->assertStringContainsString('أهداف مستوى الخدمة', $html);
        $this->assertStringContainsString('لا توجد بيانات تاريخية كافية', $html);
        $this->assertStringNotContainsString('SLI الفعلي', $html, 'لا SLI بلا قياس — «لا قياس» ليس «100٪»');
        $this->assertStringNotContainsString('✓ ملتزم', $html);
    }
}
