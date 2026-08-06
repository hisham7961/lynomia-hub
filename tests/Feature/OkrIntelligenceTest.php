<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\KeyResult;
use App\Models\Objective;
use Tests\TestCase;

/**
 * OKR كان **كتابةً لا قياساً**: تكتب «نسبة الإنجاز ٪» بيدك في الهدف — وهذا
 * ينقض فكرة OKR من أصلها؛ والنتيجة الرئيسية تطلب `kpi_id` **نصاً** فيُتوقّع
 * منك لصقُ معرّفٍ لا تعرفه. ولا مقارنةَ بين ما أُنجز وما **يُفترض** أن يكون
 * أُنجز في هذا الوقت من الفترة — وهي روح OKR كله.
 */
class OkrIntelligenceTest extends TestCase
{
    protected function obj(array $x = []): Objective
    {
        return Objective::create(array_merge([
            'title' => 'مضاعفة قاعدة العملاء', 'level' => 'الشركة', 'status' => 'قيد التنفيذ',
            'date_start' => now()->subDays(45)->toDateString(),
            'due' => now()->addDays(45)->toDateString(),
        ], $x));
    }

    public function test_objective_progress_is_computed_from_its_key_results(): void
    {
        $this->seedCore();
        $o = $this->obj();
        KeyResult::create(['title' => 'عملاء جدد', 'objective_id' => $o->id,
                           'start_value' => 0, 'target_value' => 100, 'current_value' => 40, 'weight' => 1]);
        KeyResult::create(['title' => 'إيراد', 'objective_id' => $o->id,
                           'start_value' => 0, 'target_value' => 100, 'current_value' => 80, 'weight' => 3]);

        $p = hub_okr_progress($o->id, false, false, persist: true);
        $this->assertSame(70, $p['pct'], 'المتوسط الموزون: (40×1 + 80×3) ÷ 4');
        $this->assertSame(70, (int) $o->fresh()->progress,
            'النسبة تُكتب على الهدف تلقائياً — لا تُملأ باليد');
    }

    public function test_pace_says_ahead_or_behind_not_just_a_number(): void
    {
        $this->seedCore();
        $o = $this->obj();     // مضى نصف الفترة تقريباً
        KeyResult::create(['title' => 'نتيجة', 'objective_id' => $o->id,
                           'start_value' => 0, 'target_value' => 100, 'current_value' => 20]);

        $p = hub_okr_progress($o->id, false, true);
        $this->assertEqualsWithDelta(50, $p['expected'], 3, 'نصف الفترة مضى فالمتوقَّع نحو ٥٠٪');
        $this->assertSame('متأخر', $p['pace'], '٢٠٪ مقابل ٥٠٪ متوقَّعة = متأخر');
        $this->assertSame('bad', $p['tone']);
    }

    public function test_key_result_reads_its_value_from_a_record_count(): void
    {
        $this->seedCore();
        $o = $this->obj();
        Client::create(['name' => 'عميل أ']);
        Client::create(['name' => 'عميل ب']);
        Client::create(['name' => 'عميل ج']);

        $kr = KeyResult::create(['title' => 'عدد العملاء', 'objective_id' => $o->id,
            'source' => 'count', 'src_module' => 'clients',
            'start_value' => 0, 'target_value' => 6]);

        $this->assertSame(3.0, hub_kr_read($kr), 'القيمة تُقرأ من عدّ السجلات لا من الكتابة');

        hub_okr_progress($o->id, true, false, persist: true);
        $this->assertSame(3.0, (float) $kr->fresh()->current_value);
        $this->assertSame(50, $kr->fresh()->pct());
    }

    public function test_key_result_reads_a_sum_and_a_time_series(): void
    {
        $this->seedCore();
        $o = $this->obj();
        $s = \App\Models\Service::create(['name' => 'خدمة', 'price' => 250, 'status' => 'نشطة']);
        \App\Models\Service::create(['name' => 'خدمة٢', 'price' => 150, 'status' => 'نشطة']);

        $sum = KeyResult::create(['title' => 'إجمالي الأسعار', 'objective_id' => $o->id,
            'source' => 'sum', 'src_module' => 'services', 'src_col' => 'price',
            'start_value' => 0, 'target_value' => 800]);
        $this->assertSame(400.0, hub_kr_read($sum));

        hub_metric_put('services', $s->id, 'nps', 62, now());
        $met = KeyResult::create(['title' => 'مؤشر الرضا', 'objective_id' => $o->id,
            'source' => 'metric', 'src_module' => 'services', 'src_record' => $s->id,
            'src_metric' => 'nps', 'start_value' => 0, 'target_value' => 80]);
        $this->assertSame(62.0, hub_kr_read($met), 'يقرأ آخر نقطة في السلسلة الزمنية');
    }

    public function test_automatic_key_results_never_take_a_typed_value(): void
    {
        $this->seedCore();
        $o = $this->obj();
        $kr = KeyResult::create(['title' => 'عدد العملاء', 'objective_id' => $o->id,
            'source' => 'count', 'src_module' => 'clients', 'start_value' => 0, 'target_value' => 10]);
        Client::create(['name' => 'عميل']);

        // محاولة كتابة قيمةٍ يدوية على نتيجةٍ آلية
        $this->actingAs($this->owner)->put('/m/krs/' . $kr->id, [
            'title' => 'عدد العملاء', 'objectiveId' => $o->id, 'source' => 'count',
            'srcModule' => 'clients', 'startValue' => 0, 'targetValue' => 10, 'currentValue' => 999,
        ])->assertRedirect();

        hub_okr_progress($o->id, true, false, persist: true);
        $this->assertSame(1.0, (float) $kr->fresh()->current_value,
            'المصدر الآلي يغلب الكتابة اليدوية — وإلا فما فائدة الأتمتة');
    }

    public function test_the_okr_center_draws_the_picture(): void
    {
        $this->seedCore();
        $o = $this->obj();
        KeyResult::create(['title' => 'عملاء جدد', 'objective_id' => $o->id,
                           'start_value' => 0, 'target_value' => 100, 'current_value' => 30, 'unit' => 'عميل']);

        $html = $this->actingAs($this->owner)->get('/okrs')->assertOk()->getContent();
        $this->assertStringContainsString('مضاعفة قاعدة العملاء', $html);
        $this->assertStringContainsString('عملاء جدد', $html);
        $this->assertStringContainsString('المتوقَّع', $html, 'المقارنة بالإيقاع معروضة');
        $this->assertStringContainsString('متأخر', $html);
    }

    public function test_objective_without_key_results_is_told_why_it_has_no_number(): void
    {
        $this->seedCore();
        $this->obj(['title' => 'هدف بلا نتائج']);

        $html = $this->actingAs($this->owner)->get('/okrs')->assertOk()->getContent();
        $this->assertStringContainsString('بلا نتائج رئيسية', $html,
            'هدفٌ بلا KR لا يُقاس — يُقال ذلك بدل عرض صفرٍ كاذب');
    }

    public function test_center_respects_permission(): void
    {
        $this->seedCore();
        $role = $this->viewer->role;
        $m = $role->matrix;
        $m['okrs'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role->forceFill(['matrix' => $m])->save();

        $this->actingAs($this->viewer->fresh())->get('/okrs')->assertForbidden();
    }
}
