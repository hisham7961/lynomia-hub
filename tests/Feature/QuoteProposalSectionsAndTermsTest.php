<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Support\Proposal;
use Tests\TestCase;

/**
 * CPQ — أقسامٌ ديناميكيةٌ لمستند العميل + مكتبةُ الشروط (إعادةُ استعمالٍ بلا ازدواج).
 */
class QuoteProposalSectionsAndTermsTest extends TestCase
{
    private function quote(array $attrs = []): Quote
    {
        $c = Client::create(['name_ar' => 'ع', 'name' => 'x']);

        return Quote::create(array_merge([
            'client_id' => $c->id, 'title' => 'عرض', 'total' => 0, 'currency' => 'د.ك',
            'exec_summary' => 'ملخّصٌ تنفيذيٌّ سريّ', 'scope' => 'نطاقُ العمل',
            'terms' => 'الشروطُ الأصلية',
        ], $attrs));
    }

    public function test_hidden_section_is_omitted_from_proposal(): void
    {
        $this->seedCore();
        $q = $this->quote();

        // ظاهرٌ ابتداءً
        $this->assertStringContainsString('ملخّصٌ تنفيذيٌّ سريّ', Proposal::html($q, true));

        // إخفاءُ الملخّص التنفيذي عبر الإجراء (المُرسَلُ هو المرئيّ)
        $show = array_keys(Quote::PROPOSAL_SECTIONS);
        $show = array_values(array_diff($show, ['exec_summary']));
        $this->actingAs($this->owner)->post("/quote/{$q->id}/act", ['do' => 'sections', 'show' => $show])
            ->assertRedirect();

        $q->refresh();
        $this->assertContains('exec_summary', $q->hiddenSections());
        $this->assertStringNotContainsString('ملخّصٌ تنفيذيٌّ سريّ', Proposal::html($q, true));
        // قسمٌ آخرُ لم يُخفَ يبقى
        $this->assertStringContainsString('نطاقُ العمل', Proposal::html($q, true));
    }

    public function test_pricing_and_cover_always_render_even_if_everything_toggled_off(): void
    {
        $this->seedCore();
        $q = $this->quote();
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'بندُ تسعير', 'qty' => 1, 'unit_price' => 500,
            'line_mode' => 'required', 'included' => true]);
        $q->recalc();

        // إخفاءُ كل الأقسام السرديّة (show فارغة)
        $this->actingAs($this->owner)->post("/quote/{$q->id}/act", ['do' => 'sections', 'show' => []])
            ->assertRedirect();

        $html = Proposal::html($q->fresh(), true);
        $this->assertStringContainsString('العرض التجاريّ', $html, 'التسعيرُ ثابتٌ لا يُخفى');
        $this->assertStringContainsString('بندُ تسعير', $html);
        $this->assertStringContainsString('القبول والاعتماد', $html, 'القبولُ ثابت');
    }

    public function test_terms_library_appends_template_terms(): void
    {
        $this->seedCore();
        $tpl = $this->quote(['is_template' => true, 'title' => 'قالبُ شروطٍ قياسيّ', 'terms' => 'شروطٌ قياسيةٌ جاهزة']);
        $q = $this->quote();

        $this->actingAs($this->owner)->post("/quote/{$q->id}/act",
            ['do' => 'terms', 'from' => $tpl->id, 'mode' => 'append'])->assertRedirect();

        $q->refresh();
        $this->assertStringContainsString('الشروطُ الأصلية', $q->terms, 'الشروطُ الأصليةُ محفوظة');
        $this->assertStringContainsString('شروطٌ قياسيةٌ جاهزة', $q->terms, 'شروطُ القالبِ أُلحقت');
    }

    public function test_terms_library_replace_mode(): void
    {
        $this->seedCore();
        $tpl = $this->quote(['is_template' => true, 'terms' => 'شروطٌ بديلة']);
        $q = $this->quote();

        $this->actingAs($this->owner)->post("/quote/{$q->id}/act",
            ['do' => 'terms', 'from' => $tpl->id, 'mode' => 'replace'])->assertRedirect();

        $q->refresh();
        $this->assertSame('شروطٌ بديلة', trim((string) $q->terms));
        $this->assertStringNotContainsString('الشروطُ الأصلية', $q->terms);
    }

    public function test_sections_action_requires_edit_permission(): void
    {
        $this->seedCore();
        $q = $this->quote();
        $this->actingAs($this->viewer)->post("/quote/{$q->id}/act", ['do' => 'sections', 'show' => []])
            ->assertForbidden();
    }
}
