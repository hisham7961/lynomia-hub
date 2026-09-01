<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\QuoteMilestone;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * CPQ المرحلة أ: تصنيفُ الإيراد والملخّصُ التجاريّ (MRR/ARR/TCV) وحاجزُ الهامش
 * وفحصُ الجودة التجاريّ.
 */
class CpqCommercialPhaseATest extends TestCase
{
    /** MRR/ARR/TCV تُحسَب من تصنيف الإيراد — والدوريُّ لا يُخلَط بالمرّة */
    public function test_recurring_rollup_mrr_arr_tcv(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $q = Quote::create(['client_id' => $c->id, 'total' => 0, 'currency' => 'د.ك']);
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'تنفيذ', 'qty' => 1, 'unit_price' => 1000, 'rev_type' => 'one_time']);
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'استضافة', 'qty' => 1, 'unit_price' => 500, 'rev_type' => 'recurring', 'rev_period' => 'شهري']);
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'دعم', 'qty' => 1, 'unit_price' => 1200, 'rev_type' => 'recurring', 'rev_period' => 'سنوي']);
        $q->recalc();
        $q->refresh();

        // MRR = 500 (شهري) + 1200/12=100 (سنوي) = 600 · ARR = 7200 · TCV = 1000 + 7200 = 8200
        $this->assertSame(600.0, (float) $q->mrr);
        $this->assertSame(7200.0, (float) $q->arr);
        $this->assertSame(8200.0, (float) $q->tcv);

        $cs = $q->commercialSummary();
        $this->assertSame(1000.0, $cs['one_time'], 'إيرادُ المرّة = TCV − ARR');
        $this->assertSame(600.0, $cs['mrr']);
    }

    /** بلا بنودٍ دوريّة: MRR/ARR صفر، وTCV = إيرادُ المرّة */
    public function test_one_time_only_has_zero_recurring(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $q = Quote::create(['client_id' => $c->id, 'total' => 0, 'currency' => 'د.ك']);
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'تصميم', 'qty' => 2, 'unit_price' => 750, 'rev_type' => 'one_time']);
        $q->recalc();
        $q->refresh();

        $this->assertSame(0.0, (float) $q->mrr);
        $this->assertSame(0.0, (float) $q->arr);
        $this->assertSame(1500.0, (float) $q->tcv);
    }

    /** حاجزُ الهامش: عرضٌ دون الحدّ يُحال «للمراجعة الداخلية» لا يُرسَل مباشرة */
    public function test_margin_floor_routes_low_margin_to_review(): void
    {
        $this->seedCore();
        $this->hubSetting('quotes.margin_floor', '40');   // الحدّ ٤٠٪

        $c = Client::create(['name' => 'عميل']);
        $q = Quote::create(['client_id' => $c->id, 'total' => 0, 'currency' => 'د.ك', 'status' => 'مسودة']);
        // إيراد 1000، تكلفة 800 → هامش 20٪ < 40٪
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'عمل', 'qty' => 1, 'unit_price' => 1000, 'unit_cost' => 800]);
        $q->recalc();
        $this->assertSame(20.0, $q->fresh()->margin());

        // معدٌّ بلا راية اعتماد → يُحال للمراجعة الداخلية (لا يُرسَل)
        $role = Role::create(['name' => 'مندوب مبيعات', 'scope' => 'all', 'flags' => [],
            'matrix' => ['quotes' => ['v' => 1, 'e' => 1]]]);
        $u = User::create(['name' => 'مندوب', 'email' => 'rep@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($u)->post('/quote/' . $q->id . '/act', ['do' => 'send'])->assertRedirect();
        $this->assertSame('مراجعة داخلية', $q->fresh()->status, 'الهامشُ دون الحدّ → مراجعةٌ لا إرسال');
    }

    /** الهامشُ الصحّي يُرسَل مباشرةً (لا يُعطّل العملَ العاديّ) */
    public function test_healthy_margin_sends_directly(): void
    {
        $this->seedCore();
        $this->hubSetting('quotes.margin_floor', '40');

        $c = Client::create(['name' => 'عميل']);
        $q = Quote::create(['client_id' => $c->id, 'total' => 0, 'currency' => 'د.ك', 'status' => 'مسودة']);
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'عمل', 'qty' => 1, 'unit_price' => 1000, 'unit_cost' => 300]);   // هامش ٧٠٪
        $q->recalc();

        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/act', ['do' => 'send'])->assertRedirect();
        $this->assertSame('مُرسل', $q->fresh()->status);
    }

    /** فحصُ الجودة يرصد جدولَ دفعٍ لا يجمع ١٠٠٪ وبنداً بلا سعر */
    public function test_quality_check_flags_payment_and_priceless_line(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $q = Quote::create(['client_id' => $c->id, 'total' => 0, 'currency' => 'د.ك']);
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'بندٌ مسعَّر', 'qty' => 1, 'unit_price' => 1000]);
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'بندٌ بلا سعر', 'qty' => 1, 'unit_price' => 0]);
        QuoteMilestone::create(['quote_id' => $q->id, 'title' => 'دفعة', 'pct' => 60]);   // ٦٠٪ فقط
        $q->recalc();

        $issues = implode(' | ', $q->qualityCheck());
        $this->assertStringContainsString('بلا سعر', $issues);
        $this->assertStringContainsString('١٠٠', $issues);
    }
}
