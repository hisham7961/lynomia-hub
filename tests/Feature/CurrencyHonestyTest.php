<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * صدق العملات والنطاق في المال — الموجة الثالثة:
 * (١) hub_mrr كان يجمع عقوداً بعملاتٍ شتّى في رقمٍ واحد تحت لصيقةٍ واحدة —
 *     كذبةٌ رقمية؛ الآن يفصل بالعملة ويرفع علم mixed؛
 * (٢) رواتب generate() يقرأ الموظفين بلا hub_scope — تشغيلةٌ بلا شركةٍ
 *     تجمع كل الشركات، ومعزولُ شركةٍ يسحب موظفي شركاتٍ لا يراها.
 */
class CurrencyHonestyTest extends TestCase
{
    /** (١) عقدان بعملتين لا يُجمعان في MRR واحد بلا تمييز */
    public function test_mrr_segregates_by_currency(): void
    {
        $this->seedCore();
        Contract::create(['title' => 'عقد دينار', 'type' => 'عقد عميل', 'status' => 'ساري',
            'value' => 1000, 'currency' => 'د.ك']);
        Contract::create(['title' => 'عقد دولار', 'type' => 'عقد عميل', 'status' => 'ساري',
            'value' => 1000, 'currency' => 'دولار']);

        $mrr = hub_mrr(true);

        $this->assertArrayHasKey('byCurrency', $mrr, 'لا تفصيل بالعملة — الرقم الواحد يُخفي الخلط');
        $this->assertCount(2, $mrr['byCurrency'], 'عملتان دُمجتا في مجموعٍ واحد');
        $this->assertTrue($mrr['mixed'], 'الخلط لا يُعلَن — لا علم mixed');
        // كل عملة برقمها الصادق (سنويّ ÷ ١٢)
        $byCur = collect($mrr['byCurrency'])->keyBy('currency');
        $this->assertEqualsWithDelta(1000 / 12, $byCur['د.ك']['mrr'], 0.01);
        $this->assertEqualsWithDelta(1000 / 12, $byCur['دولار']['mrr'], 0.01);
    }

    /** عملةٌ واحدة: لا خلط، والرقم صادقٌ كما كان */
    public function test_mrr_single_currency_is_not_flagged_mixed(): void
    {
        $this->seedCore();
        Contract::create(['title' => 'عقد', 'type' => 'عقد عميل', 'status' => 'ساري',
            'value' => 1200, 'currency' => 'د.ك']);

        $mrr = hub_mrr(true);
        $this->assertFalse($mrr['mixed']);
        $this->assertEqualsWithDelta(100.0, $mrr['mrr'], 0.01);   // 1200 سنويّ ÷ 12
    }

    /**
     * (٢) استعلام موظفي الرواتب يمرّ بـhub_scope — كان خاماً، فتشغيلةٌ بلا
     * company_id تسحب موظفي كل الشركات وتخلط عملاتهم في مجموعٍ وقيدٍ واحد.
     * حارس مصدر (النطاق دفاعٌ عميق: تشغيلةُ null-company لا يبلغها المعزول عبر
     * حارس التشغيلة أصلاً، لكن hub_scope يضمن ألّا يسحب generate ما وراء نطاقه).
     */
    public function test_payroll_employee_query_is_scoped(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Web/PayrollController.php'));
        $this->assertMatchesRegularExpression("/hub_scope\(\s*Employee::query\(\).*?,\s*'hr'/su", $src,
            'استعلام موظفي الرواتب خام بلا hub_scope — تشغيلةٌ بلا شركة تسحب كل الشركات');

        // ولا يزال يولّد فعلاً لموظفي التشغيلة (لم يُكسر السلوك السليم)
        $this->seedCore();
        Employee::create(['name' => 'موظف', 'salary' => 1000, 'status' => 'على رأس العمل']);
        $run = PayrollRun::create(['name' => 'مسيّر', 'month' => '2026-07', 'status' => 'مسودة']);
        $this->actingAs($this->owner)->post("/payroll/{$run->id}/act", ['do' => 'generate']);
        $this->assertSame(1, PayrollLine::where('run_id', $run->id)->count());
    }
}
