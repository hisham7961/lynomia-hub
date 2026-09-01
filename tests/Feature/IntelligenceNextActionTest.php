<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Support\NextAction;
use Tests\TestCase;

/**
 * طبقةُ الذكاء — الفعلُ الأفضلُ التالي مسطوحٌ على الكيانات (عرض/عقد/مشروع).
 * توصيةٌ بحتةٌ حسب الحالة، بروابطَ لوظائفِ النظام القائمة.
 */
class IntelligenceNextActionTest extends TestCase
{
    public function test_contract_nearing_expiry_recommends_renewal(): void
    {
        $this->seedCore();
        $c = Contract::create(['title' => 'عقدٌ يقترب', 'type' => 'خدمات', 'status' => 'ساري',
            'date_end' => now()->addDays(20)->toDateString()]);

        $steps = NextAction::for('contracts', $c);
        $this->assertNotEmpty($steps);
        $this->assertStringContainsString('ابدأ التجديد', $steps[0]['label']);
        $this->assertTrue($steps[0]['primary']);
    }

    public function test_expired_contract_recommends_late_renewal(): void
    {
        $this->seedCore();
        $c = Contract::create(['title' => 'عقدٌ منتهٍ', 'type' => 'خدمات', 'status' => 'ساري',
            'date_end' => now()->subDays(5)->toDateString()]);

        $steps = NextAction::for('contracts', $c);
        $this->assertNotEmpty($steps);
        $this->assertStringContainsString('التجديدَ المتأخر', $steps[0]['label']);
    }

    public function test_far_future_contract_has_no_renewal_nudge(): void
    {
        $this->seedCore();
        $c = Contract::create(['title' => 'بعيد', 'type' => 'خدمات', 'status' => 'ساري',
            'date_end' => now()->addDays(200)->toDateString()]);

        $this->assertSame([], NextAction::for('contracts', $c));
    }

    public function test_contract_page_shows_next_step_card(): void
    {
        $this->seedCore();
        $c = Contract::create(['title' => 'عقدٌ للعرض', 'type' => 'خدمات', 'status' => 'ساري',
            'date_end' => now()->addDays(15)->toDateString()]);

        $html = $this->actingAs($this->owner)->get('/m/contracts/' . $c->id)->assertOk()->getContent();
        $this->assertStringContainsString('الخطوة التالية', $html);
        $this->assertStringContainsString('ابدأ التجديد', $html);
    }
}
