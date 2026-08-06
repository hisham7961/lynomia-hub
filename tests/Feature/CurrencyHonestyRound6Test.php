<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\FinDocument;
use App\Models\Project;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ١٥: **صدقُ اللصيقة في آخر متر.**
 *
 * لا محرّكَ صرفٍ في هذا النظام — `app.currency` **تسميةٌ لا تحويل**. فجمعُ
 * دينارٍ ودولارٍ في رقمٍ واحد ثم لصقُ عملةِ النظام عليه **كذبةٌ رقمية**، لا
 * تقريبٌ ولا تبسيط. والمعيارُ مُرسَّخٌ في البيت منذ v2.252: `CeoBoard::curLabel`
 * و`hub_mrr` يحسبان الاختلاط ويرفعان علمَه.
 *
 * وهذه الدفعة تُغلق المواضعَ التي **يُحسَب فيها الصدقُ ولا يُعرَض**، والتي لم
 * يبلغها المعيارُ أصلاً:
 *
 *  ١) `ReportController:71,76` — علمُ الاختلاط يُرشِّح العملاتِ الفارغة فيسقط
 *     مع `NULL`+دولار (والفراغُ يُنتَج آليّاً من نسخ العروض والمشتريات)، واللصيقةُ
 *     عملةُ النظام دائماً ولو كانت المستنداتُ كلُّها بالدولار.
 *  ٢) `helpers.php:1470` — العودةُ المبكرة لـ`hub_mrr` بلا `byCurrency`/`mixed`:
 *     فخُّ `Undefined array key` ينتظر أوّلَ عرضٍ يقرأ الصدق.
 *  ٣) `ceo/index.blade.php:129` + `service_costs.blade.php` — `hub_mrr` يرفع
 *     `mixed` والعرضان يتجاهلانه: الحسابُ صادقٌ والعرضُ يُسقط صدقَه.
 *  ٤) `hub_project_pl` + `CostController` — الإيرادُ يُجمع بلا نظرٍ للعملة،
 *     و`/costs` يلصق عملةَ النظام على مجموعٍ غير متجانس ويبني عليه **ربحاً
 *     وهامشاً**.
 *  ٥) `LegalController:33` — «قيمة الساري» تجمع العملات، وتحتها في الشاشة نفسِها
 *     جدولٌ يفصلها بتعليقٍ صريح «لا جمع عملات مختلفة في رقمٍ واحد».
 *  ٦) `CeoBoard::concentrationCalc` — نسبةُ تركّزٍ من مقامٍ مخلوط، وحكمٌ قاطع
 *     («أزمة سيولة») مبنيٌّ عليها.
 *  ٧) `Pricing::marketBand` — نطاقُ السوق يخلط عملاتِ المنافسين، ثم تقارن
 *     `insights()` باقتَنا بالدينار بأدنى منافسٍ بالدولار وتُفتي.
 */
class CurrencyHonestyRound6Test extends TestCase
{
    private const WARN = 'قد تختلط العملات';

    /* ── ١) تقرير المالية: الفارغُ عملةٌ أيضاً، واللصيقةُ حقيقية ── */

    public function test_the_finance_report_flags_a_null_currency_against_a_named_one(): void
    {
        $this->seedCore();

        // الفراغُ يُنتَج آليّاً: نسخُ عرضٍ أو أتمتةٍ بلا عملة
        FinDocument::create(['doc_no' => 'N-NULL', 'kind' => 'فاتورة مبيعات', 'total' => 1000,
            'state' => 'مرسلة', 'date' => now()->toDateString(), 'currency' => null]);
        FinDocument::create(['doc_no' => 'N-USD', 'kind' => 'فاتورة مبيعات', 'total' => 1000,
            'state' => 'مرسلة', 'date' => now()->toDateString(), 'currency' => 'دولار']);

        $html = $this->actingAs($this->owner)->get('/reports/finance')->assertOk()->getContent();
        $this->assertStringContainsString(self::WARN, $html,
            'مستندٌ بلا عملة ومستندٌ بالدولار جُمعا بلا وسمِ اختلاط — المرشِّح يُسقط الفارغَ '
            . 'من مجموعة التمييز فيبدو الرقمُ متجانساً');
    }

    public function test_the_finance_report_labels_with_the_real_currency(): void
    {
        $this->seedCore();

        FinDocument::create(['doc_no' => 'U-1', 'kind' => 'فاتورة مبيعات', 'total' => 700,
            'state' => 'مرسلة', 'date' => now()->toDateString(), 'currency' => 'دولار']);
        FinDocument::create(['doc_no' => 'U-2', 'kind' => 'مصروف', 'total' => 200,
            'state' => 'مرسلة', 'date' => now()->toDateString(), 'currency' => 'دولار']);

        $html = $this->actingAs($this->owner)->get('/reports/finance')->assertOk()->getContent();
        $this->assertStringNotContainsString(self::WARN, $html, 'عملةٌ واحدة وُسمت اختلاطاً زوراً');
        $this->assertStringContainsString('دخل هذا الشهر (دولار)', $html,
            'كلُّ المستندات بالدولار والتقريرُ يُعنونها بعملة النظام — لصيقةٌ كاذبة');
    }

    /* ── ٢) العودةُ المبكرة لـhub_mrr تحمل مفاتيح الصدق ── */

    public function test_the_mrr_early_return_carries_the_currency_keys(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $m = hub_mrr(true);
        $this->assertArrayHasKey('byCurrency', $m,
            'العودةُ المبكرة بلا byCurrency — فخُّ Undefined array key ينتظر أوّلَ عرضٍ يقرأ الصدق');
        $this->assertArrayHasKey('mixed', $m);
        $this->assertFalse($m['mixed']);
    }

    /* ── ٣) لوحةُ CEO وتكلفةُ الخدمات تقرآن علمَ الاختلاط ── */

    private function twoCurrencyContracts(): void
    {
        $c = Client::create(['name' => 'عميلٌ مختلط']);
        Contract::create(['title' => 'عقدٌ بالدينار', 'type' => 'عقد عميل', 'status' => 'ساري',
            'value' => 1200, 'currency' => 'د.ك', 'client_id' => $c->id]);
        Contract::create(['title' => 'عقدٌ بالدولار', 'type' => 'عقد عميل', 'status' => 'ساري',
            'value' => 2400, 'currency' => 'دولار', 'client_id' => $c->id]);
    }

    public function test_the_ceo_board_shows_the_mixed_flag_on_mrr(): void
    {
        $this->seedCore();
        $this->twoCurrencyContracts();

        $html = $this->actingAs($this->owner)->get('/ceo')->assertOk()->getContent();
        $this->assertStringContainsString(self::WARN, $html,
            'hub_mrr يرفع mixed ولوحةُ CEO تتجاهله — الحسابُ صادقٌ والعرضُ يُسقط صدقه');
    }

    public function test_the_service_costs_screen_shows_the_mixed_flag_on_mrr(): void
    {
        $this->seedCore();
        $this->twoCurrencyContracts();

        $html = $this->actingAs($this->owner)->get('/service-costs')->assertOk()->getContent();
        $this->assertStringContainsString(self::WARN, $html,
            'شاشةُ تكلفة الخدمات تعرض MRR رقماً واحداً من عملتين بلا وسم');
    }

    /* ── ٤) لوحةُ التكاليف: الربحُ والهامشُ من مجموعٍ متجانس ── */

    public function test_the_cost_board_flags_mixed_currency(): void
    {
        $this->seedCore();

        $p = Project::create(['name' => 'مشروعُ العملتين', 'status' => 'تنفيذ']);
        FinDocument::create(['doc_no' => 'P-KWD', 'kind' => 'فاتورة مبيعات', 'total' => 1000,
            'state' => 'مرسلة', 'date' => now()->toDateString(), 'currency' => 'د.ك',
            'project_id' => $p->id]);
        FinDocument::create(['doc_no' => 'P-USD', 'kind' => 'فاتورة مبيعات', 'total' => 1000,
            'state' => 'مرسلة', 'date' => now()->toDateString(), 'currency' => 'دولار',
            'project_id' => $p->id]);

        $pl = hub_project_pl($p->id, true);
        $this->assertTrue($pl['mixed'] ?? false,
            'ربحُ المشروع وهامشُه مبنيّان على إيرادٍ يخلط عملتين بلا وسم — رقمُ قرارٍ كاذب');

        $html = $this->actingAs($this->owner)->get('/costs')->assertOk()->getContent();
        $this->assertStringContainsString(self::WARN, $html,
            'لوحةُ التكاليف تلصق عملةَ النظام على مجموعٍ غير متجانس');
    }

    /* ── ٥) «قيمة الساري» في المركز القانوني ── */

    public function test_the_legal_value_card_is_honestly_labelled(): void
    {
        $this->seedCore();

        Contract::create(['title' => 'سارٍ بالدينار', 'type' => 'عقد عميل', 'status' => 'ساري',
            'value' => 5000, 'currency' => 'د.ك']);
        Contract::create(['title' => 'سارٍ بالدولار', 'type' => 'عقد عميل', 'status' => 'ساري',
            'value' => 5000, 'currency' => 'دولار']);

        $html = $this->actingAs($this->owner)->get('/legal')->assertOk()->getContent();
        $this->assertStringContainsString(self::WARN, $html,
            '«قيمة الساري» تجمع العملات، وتحتها في الشاشة نفسِها جدولٌ يفصلها بتعليقٍ صريح');
    }

    /* ── ٦) تركّزُ الإيراد لا يُفتي من مقامٍ مخلوط ── */

    public function test_revenue_concentration_flags_mixed_currency(): void
    {
        $this->seedCore();

        $a = Client::create(['name' => 'عميلُ الدينار']);
        $b = Client::create(['name' => 'عميلُ الدولار']);
        FinDocument::create(['doc_no' => 'C-1', 'kind' => 'فاتورة مبيعات', 'total' => 9000,
            'state' => 'مرسلة', 'date' => now()->toDateString(), 'currency' => 'د.ك', 'client_id' => $a->id]);
        FinDocument::create(['doc_no' => 'C-2', 'kind' => 'فاتورة مبيعات', 'total' => 1000,
            'state' => 'مرسلة', 'date' => now()->toDateString(), 'currency' => 'دولار', 'client_id' => $b->id]);

        $this->actingAs($this->owner);
        $conc = \App\Support\CeoBoard::concentration();

        $this->assertNotEmpty($conc, 'التركّزُ لم يُحسب أصلاً — الاختبارُ يمرّ فراغاً');
        $this->assertTrue($conc['mixed'] ?? false,
            'نسبةُ تركّزٍ من مقامٍ يخلط عملتين، وحكمٌ قاطع مبنيٌّ عليها بلا وسم');
    }

    /* ── ٧) نطاقُ السوق لا يقارن ديناراً بدولار ── */

    public function test_the_market_band_does_not_compare_across_currencies(): void
    {
        $this->seedCore();

        $svc = \App\Models\Service::create(['name' => 'خدمةُ المقارنة', 'status' => 'نشطة']);
        \App\Models\PricingPlan::create(['name' => 'باقتنا', 'service_id' => $svc->id,
            'price' => 100, 'currency' => 'د.ك', 'cycle' => 'شهري', 'status' => 'نشطة']);
        \App\Models\Competitor::create(['name' => 'منافسٌ أمريكي', 'service_id' => $svc->id,
            'price_from' => 500, 'price_to' => 900, 'currency' => 'دولار']);

        $this->actingAs($this->owner);
        $titles = collect(\App\Support\Pricing::insights())->pluck('title')->all();

        $this->assertNotContains('أرخص من السوق كلّه', $titles,
            'قُورنت باقةٌ بالدينار بمنافسٍ بالدولار وأُفتيَ بـ«مالٌ متروك على الطاولة» — '
            . 'لا محرّك تحويل في النظام، فالمقارنةُ الصامتة أسوأ من غيابها');
    }

    /** والمقارنةُ داخل العملة الواحدة تبقى تعمل — الحارسُ لم يُطفئ الميزة */
    public function test_the_market_band_still_compares_within_one_currency(): void
    {
        $this->seedCore();

        $svc = \App\Models\Service::create(['name' => 'خدمةٌ بعملةٍ واحدة', 'status' => 'نشطة']);
        \App\Models\PricingPlan::create(['name' => 'باقتنا الرخيصة', 'service_id' => $svc->id,
            'price' => 100, 'currency' => 'د.ك', 'cycle' => 'شهري', 'status' => 'نشطة']);
        \App\Models\Competitor::create(['name' => 'منافسٌ محلي', 'service_id' => $svc->id,
            'price_from' => 500, 'price_to' => 900, 'currency' => 'د.ك']);

        $this->actingAs($this->owner);
        $titles = collect(\App\Support\Pricing::insights())->pluck('title')->all();

        $this->assertContains('أرخص من السوق كلّه', $titles,
            'الحارسُ أطفأ المقارنةَ المشروعة داخل العملة الواحدة');
    }
}
