<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\Service;
use Tests\TestCase;

/**
 * CPQ المرحلة ب — البنودُ الاختيارية/البديلة والفرصةُ العُلويّة ومنتقي الكتالوج.
 */
class QuoteOptionalItemsTest extends TestCase
{
    private function quote(): Quote
    {
        $c = Client::create(['name' => 'عميل CPQ']);

        return Quote::create(['client_id' => $c->id, 'title' => 'عرض', 'total' => 0, 'currency' => 'د.ك']);
    }

    public function test_optional_line_is_excluded_from_committed_total_until_included(): void
    {
        $this->seedCore();
        $q = $this->quote();
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'أساسيّ', 'qty' => 1, 'unit_price' => 1000, 'line_mode' => 'required', 'included' => true]);
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'دعمٌ سنويّ', 'qty' => 1, 'unit_price' => 500, 'line_mode' => 'optional', 'included' => false]);
        $q->recalc();

        // الإجماليُّ المُلتزَم = الأساسيّ فقط؛ الاختياريُّ فرصةٌ عُلويّة
        $this->assertSame(1000.0, (float) $q->fresh()->total);
        $this->assertSame(500.0, $q->fresh()->optionalUpside());
    }

    public function test_toggle_includes_optional_line_and_recalcs(): void
    {
        $this->seedCore();
        $q = $this->quote();
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'أساسيّ', 'qty' => 1, 'unit_price' => 1000, 'line_mode' => 'required', 'included' => true]);
        $opt = QuoteLine::create(['quote_id' => $q->id, 'title' => 'إضافة', 'qty' => 1, 'unit_price' => 500, 'line_mode' => 'addon', 'included' => false]);
        $q->recalc();
        $this->assertSame(1000.0, (float) $q->fresh()->total);

        $this->actingAs($this->owner)->post("/quote/{$q->id}/line/{$opt->id}/toggle")->assertRedirect();
        $this->assertSame(1500.0, (float) $q->fresh()->total);
        $this->assertTrue($opt->fresh()->included);
    }

    public function test_including_one_alternative_excludes_its_group_siblings(): void
    {
        $this->seedCore();
        $q = $this->quote();
        $a = QuoteLine::create(['quote_id' => $q->id, 'title' => 'خطة أساسية', 'qty' => 1, 'unit_price' => 800, 'line_mode' => 'alternative', 'opt_group' => 'الخطة', 'included' => true]);
        $b = QuoteLine::create(['quote_id' => $q->id, 'title' => 'خطة متقدّمة', 'qty' => 1, 'unit_price' => 1500, 'line_mode' => 'alternative', 'opt_group' => 'الخطة', 'included' => false]);
        $q->recalc();
        $this->assertSame(800.0, (float) $q->fresh()->total);

        // إدراجُ المتقدّمة يُخرِج الأساسية (بديلٌ واحدٌ من المجموعة)
        $this->actingAs($this->owner)->post("/quote/{$q->id}/line/{$b->id}/toggle")->assertRedirect();
        $this->assertFalse($a->fresh()->included);
        $this->assertTrue($b->fresh()->included);
        $this->assertSame(1500.0, (float) $q->fresh()->total);
    }

    public function test_catalog_picker_prefills_price_from_service(): void
    {
        $this->seedCore();
        $q = $this->quote();
        $svc = Service::create(['name' => 'تطوير تطبيق', 'price' => 3000, 'cost' => 1200]);

        $this->actingAs($this->owner)->post("/quote/{$q->id}/line", [
            'service_id' => $svc->id,   // بلا عنوانٍ ولا سعر — يُملآن من الكتالوج
        ])->assertRedirect();

        $line = QuoteLine::where('quote_id', $q->id)->firstOrFail();
        $this->assertSame('تطوير تطبيق', $line->title);
        $this->assertSame(3000.0, (float) $line->unit_price);
        $this->assertSame(1200.0, (float) $line->unit_cost);
    }

    public function test_required_line_cannot_be_toggled_out(): void
    {
        $this->seedCore();
        $q = $this->quote();
        $l = QuoteLine::create(['quote_id' => $q->id, 'title' => 'أساسيّ', 'qty' => 1, 'unit_price' => 1000, 'line_mode' => 'required', 'included' => true]);

        $this->actingAs($this->owner)->post("/quote/{$q->id}/line/{$l->id}/toggle")->assertStatus(422);
    }

    public function test_quality_check_warns_on_alternative_group_without_exactly_one(): void
    {
        $this->seedCore();
        $q = $this->quote();
        // بديلان في المجموعة، كلاهما مُدرَج → تحذير
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'أ', 'qty' => 1, 'unit_price' => 100, 'line_mode' => 'alternative', 'opt_group' => 'خطة', 'included' => true]);
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'ب', 'qty' => 1, 'unit_price' => 200, 'line_mode' => 'alternative', 'opt_group' => 'خطة', 'included' => true]);
        $q->recalc();

        $issues = implode(' | ', $q->qualityCheck());
        $this->assertStringContainsString('مجموعةُ البدائل', $issues);
    }
}
