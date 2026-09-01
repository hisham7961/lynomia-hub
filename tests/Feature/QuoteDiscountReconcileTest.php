<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteLine;
use Tests\TestCase;

/**
 * تدقيقٌ عميق — الخصمُ على مستوى العرض يُعيد وعاءَ الضريبة.
 *
 * كان الخصمُ يُطرح بالتساوي من الصافي والإجماليّ، فتبقى الضريبةُ = إجماليّ − صافٍ
 * ضريبةَ **ما قبل الخصم** (وعاءٌ أكبرُ مما يُدفَع)، والمستندُ لا يطابق
 * «صافٍ − خصم + ضريبة = إجماليّ». الإصلاح: الخصمُ يُنقص الوعاءَ الخاضع، وتُعاد
 * الضريبةُ نسبةً للأساس بعد الخصم، فيتصالح المستند.
 */
class QuoteDiscountReconcileTest extends TestCase
{
    private function quote(): Quote
    {
        $this->seedCore();
        $c = Client::create(['name_ar' => 'ع', 'name' => 'x']);

        return Quote::create(['client_id' => $c->id, 'title' => 'عرض', 'total' => 0, 'currency' => 'د.ك']);
    }

    public function test_quote_discount_recomputes_tax_on_discounted_base(): void
    {
        $q = $this->quote();
        // بندٌ: صافٍ ١٠٠٠، ضريبة ١٥٪ → إجماليُّ البند ١١٥٠
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'أساسيّ', 'qty' => 1, 'unit_price' => 1000,
            'tax_pct' => 15, 'line_mode' => 'required', 'included' => true]);
        $q->discount = 200;
        $q->recalc();
        $q->refresh();

        // بعد خصم ٢٠٠: الوعاء ٨٠٠، الضريبة ١٥٪×٨٠٠ = ١٢٠، الإجمالي ٩٢٠
        $this->assertSame(800.0, (float) $q->amount, 'الصافي بعد الخصم خاطئ');
        $this->assertSame(120.0, (float) $q->tax, 'الضريبة لم تُعَد على الوعاء بعد الخصم');
        $this->assertSame(920.0, (float) $q->total, 'الإجمالي خاطئ');

        // يتصالح المستند: صافٍ + ضريبة = إجماليّ
        $this->assertSame(round((float) $q->amount + (float) $q->tax, 3), (float) $q->total);
    }

    public function test_proposal_shows_pre_discount_subtotal_so_it_reconciles(): void
    {
        $q = $this->quote();
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'أساسيّ', 'qty' => 1, 'unit_price' => 1000,
            'tax_pct' => 15, 'line_mode' => 'required', 'included' => true]);
        $q->discount = 200;
        $q->recalc();
        $q->refresh();

        $html = \App\Support\Proposal::html($q, true);
        // الصافيُّ المعروضُ قبل الخصم = ١٠٠٠ (لا ٨٠٠ المخزَّن) كي يتصالح المستند
        $this->assertStringContainsString(number_format(1000, 3), $html);
        $this->assertStringContainsString(number_format(200, 3), $html);   // الخصم
        $this->assertStringContainsString(number_format(920, 3), $html);   // الإجمالي
    }

    public function test_quote_doc_shows_discount_line_and_reconciles(): void
    {
        $this->seedCore();
        $c = Client::create(['name_ar' => 'ع', 'name' => 'x']);
        $q = Quote::create(['client_id' => $c->id, 'title' => 'عرض', 'total' => 0, 'currency' => 'د.ك']);
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'أساسيّ', 'qty' => 1, 'unit_price' => 1000,
            'tax_pct' => 15, 'line_mode' => 'required', 'included' => true]);
        $q->discount = 200;
        $q->recalc();

        $html = $this->actingAs($this->owner)->get("/quote/{$q->id}/doc")->assertOk()->getContent();
        // الصافيُّ قبل الخصم ١٠٠٠، سطرُ خصمٍ ظاهر، الضريبةُ ١٢٠، المستحقُّ ٩٢٠ — يتصالح
        $this->assertStringContainsString(number_format(1000, 3), $html);
        $this->assertStringContainsString('الخصم', $html);
        $this->assertStringContainsString(number_format(920, 3), $html);
    }

    public function test_discount_exceeding_lines_never_goes_negative(): void
    {
        $q = $this->quote();
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'أساسيّ', 'qty' => 1, 'unit_price' => 100,
            'tax_pct' => 15, 'line_mode' => 'required', 'included' => true]);
        $q->discount = 500;   // خصمٌ يفوق البنود
        $q->recalc();
        $q->refresh();

        $this->assertSame(0.0, (float) $q->amount, 'الصافي سالبٌ عند خصمٍ يفوق البنود');
        $this->assertSame(0.0, (float) $q->tax);
        $this->assertSame(0.0, (float) $q->total, 'الإجمالي سالبٌ — لا يجوز');
    }

    public function test_no_discount_totals_unchanged(): void
    {
        $q = $this->quote();
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'أساسيّ', 'qty' => 2, 'unit_price' => 500,
            'tax_pct' => 15, 'line_mode' => 'required', 'included' => true]);
        $q->recalc();
        $q->refresh();

        $this->assertSame(1000.0, (float) $q->amount);
        $this->assertSame(150.0, (float) $q->tax);
        $this->assertSame(1150.0, (float) $q->total);
    }
}
