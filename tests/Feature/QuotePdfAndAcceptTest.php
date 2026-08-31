<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * عروض المشاريع — المرحلة ب: PDF احترافي وقبولُ العميل عبر eSign.
 */
class QuotePdfAndAcceptTest extends TestCase
{
    public function test_professional_proposal_renders_without_internal_cost(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل PDF']);
        $q = Quote::create(['client_id' => $c->id, 'title' => 'مشروع احترافي', 'total' => 0,
            'currency' => 'د.ك', 'exec_summary' => 'ملخّصٌ تنفيذيّ للمشروع', 'scope' => 'نطاقُ العمل الكامل']);
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'تصميم', 'kind' => 'مرحلة',
            'qty' => 1, 'unit_price' => 3000, 'unit_cost' => 1200]);
        $q->recalc();

        // العرضُ الاحترافيّ يُبنى (HTML صريح من Proposal — مستقلٌّ عن توفّر mPDF)
        $html = \App\Support\Proposal::html($q->fresh());
        $this->assertStringContainsString('عرضُ مشروعٍ', $html);
        $this->assertStringContainsString('الملخّص التنفيذي', $html);
        $this->assertStringContainsString('العرض التجاريّ', $html);
        $this->assertStringContainsString('3,000', $html);
        // **لا تكلفةَ ولا هامشَ داخليّ في مستند العميل**
        $this->assertStringNotContainsString('1,200', $html, 'تكلفةٌ داخلية تسرّبت لمستند العميل');
        $this->assertStringNotContainsString('1200', $html, 'تكلفةٌ داخلية تسرّبت لمستند العميل');
        $this->assertStringNotContainsString('الربحية', $html);
        $this->assertStringNotContainsString('الهامش', $html);
    }

    public function test_pdf_route_returns_pdf_or_html_fallback(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $q = Quote::create(['client_id' => $c->id, 'title' => 'عرض', 'total' => 500]);

        $res = $this->actingAs($this->owner)->get('/quote/' . $q->id . '/pdf');
        $res->assertOk();
        $ctype = $res->headers->get('content-type');
        // إمّا PDF (mPDF متاح) أو HTML (تساقطٌ آمن) — لا شاشةَ بيضاء
        $this->assertTrue(str_contains($ctype, 'pdf') || str_contains($ctype, 'html'));
    }

    public function test_quote_is_a_linkable_esign_target(): void
    {
        // العرضُ صار هدفاً للتوقيع الإلكتروني — فقبولُ العميل يمرّ بمحرك eSign القائم
        $ref = new \ReflectionClass(\App\Http\Controllers\Web\EsignController::class);
        $linkable = $ref->getConstant('LINKABLE');
        $this->assertArrayHasKey('quotes', $linkable);
    }

    public function test_pdf_respects_module_view_permission(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $q = Quote::create(['client_id' => $c->id, 'total' => 100]);

        // دورٌ بلا رؤية العروض → 403
        $role = Role::create(['name' => 'بلا عروض', 'scope' => 'all', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1]]]);
        $u = User::create(['name' => 'خارجي', 'email' => 'noq@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $this->actingAs($u)->get('/quote/' . $q->id . '/pdf')->assertForbidden();
    }
}
