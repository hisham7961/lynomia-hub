<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Engagement;
use App\Models\PlanItem;
use App\Models\Project;
use App\Models\Quote;
use App\Models\QuoteLine;
use Tests\TestCase;

/**
 * عروض المشاريع — المرحلة ج: التحويل عرض ← ارتباط ← مشروع خارجي.
 * معاملاتيٌّ آمن، بلا ازدواج بيانات، بمنع تحويلٍ مكرّر وخطِّ أساسٍ تجاريّ.
 */
class QuoteConversionTest extends TestCase
{
    protected function acceptedQuote(): Quote
    {
        $c = Client::create(['name' => 'عميل التحويل']);
        $q = Quote::create(['client_id' => $c->id, 'title' => 'تطوير متجر', 'total' => 9000,
            'cost' => 5000, 'currency' => 'د.ك', 'billing' => 'دفعات مراحل',
            'scope' => 'نطاق العمل الكامل', 'status' => 'مقبول', 'accepted_at' => now()]);
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'اكتشاف', 'kind' => 'مرحلة', 'qty' => 1, 'unit_price' => 3000]);
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'تطوير', 'kind' => 'مرحلة', 'qty' => 1, 'unit_price' => 6000]);

        return $q;
    }

    public function test_accepted_quote_converts_to_engagement_and_project(): void
    {
        $this->seedCore();
        $q = $this->acceptedQuote();

        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/act', ['do' => 'project'])
            ->assertRedirect();

        $q->refresh();
        $meta = (array) $q->meta;
        $this->assertSame('محوّل', $q->status);
        $this->assertNotEmpty($meta['engagement_id']);
        $this->assertNotEmpty($meta['project_id']);

        // الارتباط أُنشئ من العرض بلا ازدواج للعميل
        $eng = Engagement::find($meta['engagement_id']);
        $this->assertSame($q->client_id, $eng->client_id);
        $this->assertSame('9000.000', (string) $eng->revenue);
        $this->assertSame('دفعات مراحل', $eng->billing);

        // المشروع موصولٌ بالعميل والارتباط — تُضيء الربحيةُ القائمة
        $project = Project::find($meta['project_id']);
        $this->assertSame($q->client_id, $project->client_id);
        $this->assertSame($eng->id, $project->engagement_id);

        // نُقلت المراحل إلى خطة العمل (plan_items) بتتبّعٍ للعرض
        $phases = PlanItem::where('project_id', $project->id)->get();
        $this->assertCount(2, $phases);
        $this->assertSame('مرحلة', $phases->first()->type);
        $this->assertSame($q->id, $phases->first()->meta['from_quote']);

        // خطُّ الأساس التجاريّ محفوظٌ على المشروع (لقطةُ العرض المقبول)
        $this->assertSame($q->doc_no, $project->meta['baseline']['quote_no']);
        $this->assertSame('9000.000', (string) $project->meta['baseline']['amount']);
    }

    public function test_conversion_is_idempotent_no_duplicate_project(): void
    {
        $this->seedCore();
        $q = $this->acceptedQuote();

        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/act', ['do' => 'project']);
        $firstProject = (array) $q->fresh()->meta;

        // نقرةٌ ثانية لا تُنشئ مشروعاً ثانياً — تُفتح الأول
        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/act', ['do' => 'project'])->assertRedirect();
        $this->assertSame(1, Project::where('client_id', $q->client_id)->count(), 'أُنشئ مشروعٌ مكرّر');
        $this->assertSame(1, Engagement::where('client_id', $q->client_id)->count(), 'أُنشئ ارتباطٌ مكرّر');
        $this->assertSame($firstProject['project_id'], $q->fresh()->meta['project_id']);
    }

    public function test_conversion_requires_accepted_status(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'ع']);
        $q = Quote::create(['client_id' => $c->id, 'total' => 100, 'status' => 'مسودة']);

        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/act', ['do' => 'project'])
            ->assertStatus(422);
    }

    public function test_conversion_fires_the_converted_event(): void
    {
        $this->seedCore();
        // كتلةُ الأحداث تعرّف quote.converted فتعمل حِزمُ onboarding
        $emits = collect(config('hub.events.quotes'))->pluck('emit')->all();
        $this->assertContains('quote.converted', $emits);
        $this->assertContains('quote.sent', $emits);
    }
}
