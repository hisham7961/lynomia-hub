<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Competitor;
use Tests\TestCase;

/**
 * المرحلة ٥ — المسار البيعي رقماً: كانت `value` و`prob` تُملآن ولا تُجمعان
 * في أي مكان (الكانبان يعدّ البطاقات فقط)، ومرحلة «خسارة» بلا سبب ولا
 * «لصالح مَن» — ووحدة المنافسين كاملةً لا تُقرأ من أي شاشة إطلاقاً.
 */
class PipelineRound6Test extends TestCase
{
    protected function client(string $name, string $stage, float $value = 0, float $prob = 0, array $extra = []): Client
    {
        return Client::create(array_merge(['name' => $name, 'stage' => $stage,
            'value' => $value, 'prob' => $prob], $extra));
    }

    public function test_pipeline_sums_open_stages_and_weights_by_probability(): void
    {
        $this->seedCore();
        $this->client('محتمل', 'عميل محتمل', 1000, 20);
        $this->client('تفاوض', 'تفاوض', 2000, 75);
        $this->client('فائز', 'فوز', 5000, 100);          // محسوم لا مفتوح
        $this->client('خاسر', 'خسارة', 3000, 0);

        $p = hub_pipeline($this->owner);
        $this->assertEquals(3000.0, $p['pipeline'], 'المفتوح: المحتمل + التفاوض');
        $this->assertEquals(1700.0, $p['weighted'], '١٠٠٠×٢٠٪ + ٢٠٠٠×٧٥٪');
        $this->assertEquals(5000.0, $p['won']);
        $this->assertSame(63, $p['winRate'], '٥٠٠٠ من ٨٠٠٠ محسوم');
    }

    public function test_loss_analysis_groups_by_reason_and_competitor(): void
    {
        $this->seedCore();
        $rival = Competitor::create(['name' => 'المنافس الأول']);
        $this->client('خسارة سعر ١', 'خسارة', 1000, 0, ['lost_reason' => 'السعر', 'competitor_id' => $rival->id]);
        $this->client('خسارة سعر ٢', 'خسارة', 500, 0, ['lost_reason' => 'السعر']);
        $this->client('خسارة توقيت', 'خسارة', 200, 0, ['lost_reason' => 'التوقيت']);
        $this->client('خسارة مجهولة', 'خسارة', 100, 0);

        $p = hub_pipeline($this->owner);
        $this->assertSame('السعر', $p['lostReasons'][0]['reason'], 'الأعلى قيمةً أولاً');
        $this->assertEquals(1500.0, $p['lostReasons'][0]['value']);
        $this->assertContains('غير مسجَّل', array_column($p['lostReasons'], 'reason'),
            'الخسارة بلا سبب تُصنَّف صراحةً لا تُسقَط من التقرير');

        $this->assertSame('المنافس الأول', $p['lostToCompetitors'][0]['name'],
            'وحدة المنافسين تُقرأ أخيراً — «لمن نخسر» صار سؤالاً له جواب');
        $this->assertEquals(1000.0, $p['lostToCompetitors'][0]['value']);
    }

    public function test_board_shows_stage_totals_and_ceo_shows_pipeline(): void
    {
        $this->seedCore();
        $this->client('عميل الكانبان', 'تفاوض', 4500, 50);

        $this->actingAs($this->owner)->get('/m/clients/board')
            ->assertOk()->assertSee('4,500')->assertSee('مرجّح');

        $this->actingAs($this->owner)->get('/ceo')
            ->assertOk()->assertSee('مسار المبيعات المفتوح')->assertSee('MRR');
    }

    public function test_lost_client_page_shows_loss_autopsy(): void
    {
        $this->seedCore();
        $rival = Competitor::create(['name' => 'شركة منافسة', 'threat' => 'عالٍ']);
        $c = $this->client('عميل ضائع', 'خسارة', 800, 0,
            ['lost_reason' => 'المزايا', 'competitor_id' => $rival->id]);

        $this->actingAs($this->owner)->get('/m/clients/' . $c->id)
            ->assertOk()->assertSee('تشريح الخسارة')->assertSee('المزايا')->assertSee('شركة منافسة');
    }
}
