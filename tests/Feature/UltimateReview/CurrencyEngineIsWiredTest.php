<?php

namespace Tests\Feature\UltimateReview;

use App\Models\Client;
use App\Models\FinDocument;
use App\Models\Project;
use App\Models\Quote;
use App\Support\Insights\CeoBoard;
use App\Support\Finance\Currency;
use App\Support\Finance\SalesBoard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **محرّكُ الصرفِ موصولٌ بالشاشاتِ التي تجمع المال** (المراجعةُ الشاملة · F-15).
 *
 * كان في النظامِ محرّكُ صرفٍ كاملٌ — `App\Support\Finance\Currency` بسعرٍ مؤرَّخٍ ومقلوبٍ
 * ومجموعٍ محافظٍ — **وشاشةُ إدارةٍ تُدخِل الأسعار**، ثمّ لا شيء: `Currency::sum`
 * لم تُنادَ من سطرٍ واحدٍ في `app/`، ونداؤها الوحيدُ كان من هذه الحزمة. فستُّ
 * شاشاتٍ تجمع المالَ كانت تنادي `hub_cur_label` فترفع علمَ «مخلوط»، والمالكُ
 * يفتح شاشةَ الأسعارِ ويسجّل السعرَ ويعود — **فلا يتغيّر رقمٌ واحد**.
 *
 * ميزةٌ مبنيّةٌ ومختبَرةٌ ومحروسةٌ ومقطوعةُ السلك. وهذه الحزمةُ هي السلك.
 *
 * **وحدُّها المعلَن — وهو نصفُ قيمتِها:** بلا سعرٍ مسجَّلٍ **لا يتغيّر حرف**.
 * كلُّ اختبارٍ هنا له وجهان: وجهٌ يثبت التحويلَ حين يوجد السعر، ووجهٌ يثبت أنّ
 * الشاشةَ بلا سعرٍ تطبع ما كانت تطبعه. فالوصلُ إضافةٌ لا كسر.
 */
class CurrencyEngineIsWiredTest extends TestCase
{
    /** سعرٌ مسجَّلٌ كما تسجّله شاشةُ الإدارة */
    private function rate(string $from, string $to, string $rate, string $asOf): void
    {
        DB::table('currency_rates')->insert([
            'id' => (string) Str::uuid(),
            'from_cur' => $from, 'to_cur' => $to, 'rate' => $rate, 'as_of' => $asOf,
            'source' => 'اختبار', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Currency::flush();
    }

    protected function tearDown(): void
    {
        Currency::flush();
        parent::tearDown();
    }

    // ═══════════ ١ · لوحةُ المبيعات — قيمةُ المسؤولِ الواحد ═══════════

    /**
     * بائعٌ واحدٌ له صفقتان بعملتين: ١٠٠٠ دولارٍ و٣٠٠ ديناراً. بسعرِ ٠٫٣
     * مجموعُه ٦٠٠ ديناراً **رقماً واحداً**، لا «١٣٠٠ مخلوط» — والـ١٣٠٠ ليست
     * كبيرةً فحسب، هي **لا تُمثّل شيئاً**: جمعُ تفّاحٍ ببرتقال.
     */
    public function test_sales_owner_value_converts_when_a_rate_exists(): void
    {
        $this->seedCore();
        $base = Currency::base();
        $this->quotesForOwner($base);
        $this->rate('USD', $base, '0.300000', '2026-01-01');

        $row = $this->ownerRow();

        $this->assertTrue($row['converted'] ?? false, 'قيمةُ البائعِ لم تُحوَّل مع وجودِ سعرٍ مسجَّل');
        $this->assertFalse($row['mixed'], 'وُسِمت «مخلوطة» وهي محوَّلةٌ بالكامل');
        $this->assertSame($base, $row['cur'], 'المحوَّلُ يُعنوَن بعملةِ الأساس');
        $this->assertEqualsWithDelta(600.0, $row['value'], 0.001, '١٠٠٠ دولارٍ × ٠٫٣ + ٣٠٠ = ٦٠٠');
    }

    /** والوجهُ الآخر: بلا سعرٍ تبقى الشاشةُ حرفاً بحرفٍ كما كانت */
    public function test_sales_owner_value_is_untouched_without_a_rate(): void
    {
        $this->seedCore();
        $base = Currency::base();
        $this->quotesForOwner($base);

        $row = $this->ownerRow();

        $this->assertFalse($row['converted'] ?? false, 'حُوِّل بلا سعرٍ مسجَّل');
        $this->assertTrue($row['mixed'], 'عملتان بلا سعرٍ ⇒ مخلوط');
        $this->assertEqualsWithDelta(1300.0, $row['value'], 0.001, 'المجموعُ الخامُ كما كان');
    }

    /** وسعرٌ ناقصٌ لزوجٍ واحدٍ يُبقي المجموعَ كلَّه مخلوطاً — لا يُحوَّل بعضُه */
    public function test_one_missing_pair_keeps_the_whole_sum_mixed(): void
    {
        $this->seedCore();
        $base = Currency::base();
        $this->quotesForOwner($base);
        $q = Quote::create(['client_id' => $this->client()->id, 'total' => 500, 'currency' => 'EUR',
            'status' => 'مقبول', 'owner_id' => $this->owner->id, 'accepted_at' => '2026-03-01']);
        $this->assertNotNull($q->id);
        $this->rate('USD', $base, '0.300000', '2026-01-01');   // ولا سعرَ لليورو

        $row = $this->ownerRow();

        $this->assertFalse($row['converted'] ?? false, 'حُوِّل وزوجٌ بلا سعر');
        $this->assertTrue($row['mixed']);
        $this->assertEqualsWithDelta(1800.0, $row['value'], 0.001, 'يبقى الخامُ كلُّه');
    }

    // ═══════════ ٢ · تركّزُ العملاء — النسبةُ على مقامٍ موحَّد ═══════════

    /**
     * **أخطرُ الستّة.** «أكبرُ عميلٍ ٤٠٪ من إيرادك» نسبةٌ مقسومةٌ على مجموعٍ
     * مخلوط، وفوقها حكمٌ قاطعٌ («أزمةُ سيولة»). عميلٌ بـ١٠٠٠ دولارٍ وآخرُ
     * بـ٤٠٠ ديناراً: خاماً يبدو الأوّلُ ٧١٪ **وهو في الحقيقةِ ٤٣٪**.
     */
    public function test_client_concentration_percentages_use_a_converted_denominator(): void
    {
        $this->seedCore();
        $base = Currency::base();
        $a = Client::create(['name' => 'عميلُ الدولار']);
        $b = Client::create(['name' => 'عميلُ الدينار']);
        FinDocument::create(['kind' => 'فاتورة مبيعات', 'client_id' => $a->id,
            'total' => 1000, 'currency' => 'USD', 'date' => now()->subMonth()->toDateString()]);
        FinDocument::create(['kind' => 'فاتورة مبيعات', 'client_id' => $b->id,
            'total' => 400, 'currency' => $base, 'date' => now()->subMonth()->toDateString()]);
        $this->rate('USD', $base, '0.300000', now()->subYear()->toDateString());

        $this->actingAs($this->owner);
        $c = CeoBoard::concentration();

        $this->assertNotEmpty($c, 'التركّزُ فارغٌ — تهيئةٌ خاطئة');
        $this->assertTrue($c['converted'] ?? false, 'التركّزُ لم يُحوَّل مع وجودِ سعر');
        $this->assertFalse($c['mixed'], 'وُسِم مخلوطاً وهو محوَّل');
        $this->assertEqualsWithDelta(700.0, $c['total'], 0.001, '٣٠٠ + ٤٠٠ بعملةِ الأساس');
        $this->assertSame(57, $c['firstPct'], 'أكبرُ عميلٍ ٤٠٠÷٧٠٠ ≈ ٥٧٪ — لا ٧١٪ من مقامٍ مخلوط');
        $this->assertStringNotContainsString('مؤشّراً لا رقماً', (string) $c['verdict'],
            'الحكمُ ما زال يعتذر بالاختلاط وقد زال الاختلاط');
    }

    /** وبلا سعرٍ: النسبةُ الخامُ والحكمُ المعتذِرُ كما كانا */
    public function test_client_concentration_is_untouched_without_a_rate(): void
    {
        $this->seedCore();
        $base = Currency::base();
        $a = Client::create(['name' => 'عميلُ الدولار']);
        $b = Client::create(['name' => 'عميلُ الدينار']);
        FinDocument::create(['kind' => 'فاتورة مبيعات', 'client_id' => $a->id,
            'total' => 1000, 'currency' => 'USD', 'date' => now()->subMonth()->toDateString()]);
        FinDocument::create(['kind' => 'فاتورة مبيعات', 'client_id' => $b->id,
            'total' => 400, 'currency' => $base, 'date' => now()->subMonth()->toDateString()]);

        $this->actingAs($this->owner);
        $c = CeoBoard::concentration();

        $this->assertNotEmpty($c);
        $this->assertFalse($c['converted'] ?? false);
        $this->assertTrue($c['mixed'], 'عملتان بلا سعرٍ ⇒ مخلوط');
        $this->assertSame(71, $c['firstPct'], 'الخامُ كما كان: ١٠٠٠ ÷ ١٤٠٠');
        $this->assertStringContainsString('مؤشّراً لا رقماً', (string) $c['verdict']);
    }

    // ═══════════ ٣ · ربحيّةُ المشروع — الطرفان أو لا طرف ═══════════

    /**
     * **الربحُ فرقُ رقمين، فلا يصحُّ أن يُحوَّل أحدُهما دون الآخر.**
     *
     * إيرادٌ بالدولارِ وتكلفةٌ بالدينار: تحويلُ الإيرادِ وحدَه يطبع ربحاً
     * أسوأَ من الحقيقةِ بثلاثِ مرّات — وهو رقمٌ **أسوأُ من «مخلوط»** لأنّه
     * يبدو دقيقاً. فإمّا يُحوَّل الطرفان وإمّا لا يُحوَّل شيء.
     */
    public function test_project_pl_converts_both_sides_together(): void
    {
        $this->seedCore();
        $base = Currency::base();
        [$p] = $this->projectWithMixedPl($base);
        $this->rate('USD', $base, '0.300000', '2026-01-01');

        $pl = hub_project_pl($p->id, true);

        $this->assertTrue($pl['converted'] ?? false, 'ربحيّةُ المشروعِ لم تُحوَّل مع وجودِ سعر');
        $this->assertFalse($pl['mixed']);
        $this->assertEqualsWithDelta(300.0, $pl['revenue']['invoiced'], 0.001, '١٠٠٠ دولارٍ × ٠٫٣');
        $this->assertEqualsWithDelta(100.0, $pl['cost']['total'], 0.001, 'شراءٌ بـ١٠٠ ديناراً');
        $this->assertEqualsWithDelta(200.0, $pl['profit'], 0.001, '٣٠٠ − ١٠٠');
    }

    /**
     * وسعرٌ ناقصٌ على **طرفِ التكلفةِ وحدَه** يمنع تحويلَ الإيرادِ كذلك —
     * وهذا هو الحارسُ الذي يمنع الربحَ المُخترَع.
     */
    public function test_project_pl_refuses_to_convert_one_side_only(): void
    {
        $this->seedCore();
        $base = Currency::base();
        [$p] = $this->projectWithMixedPl($base, costCurrency: 'EUR');
        $this->rate('USD', $base, '0.300000', '2026-01-01');   // الإيرادُ قابلٌ للتحويل

        $pl = hub_project_pl($p->id, true);

        $this->assertFalse($pl['converted'] ?? false, 'حُوِّل الإيرادُ وتكلفتُه بلا سعر');
        $this->assertTrue($pl['mixed']);
        $this->assertEqualsWithDelta(1000.0, $pl['revenue']['invoiced'], 0.001, 'الإيرادُ خامٌ كما كان');
        $this->assertEqualsWithDelta(100.0, $pl['cost']['total'], 0.001, 'والتكلفةُ خامٌ كما كانت');
    }

    /**
     * **الصفرُ لا يُخالط.** مشروعٌ عملتُه المُعلَنةُ درهمٌ ولا تكلفةَ مسجَّلةً
     * فيه ولا فاتورة: لا مبلغَ فيه أصلاً، فلا اختلاطَ يُعلَن.
     *
     * وُجِد حيّاً على قاعدةِ المحاكاة بعد الوصل: مكوّنٌ **بلا بيانٍ** كان يُفبرَك
     * له صفٌّ بمبلغِ صفرٍ يحمل عملةَ المشروع، فيُخالط بلا مال. وعلمُ اختلاطٍ
     * كاذبٌ ليس زينةً: الشاشةُ تطبع تحذيراً أحمرَ يُعلِّم القارئَ تجاهلَ التحذيرات.
     */
    public function test_a_project_with_no_money_at_all_is_not_declared_mixed(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروعٌ بلا مال', 'status' => 'قيد التنفيذ',
            'currency' => 'AED', 'cost' => null, 'client_id' => $this->client()->id]);

        $pl = hub_project_pl($p->id, true);

        $this->assertFalse($pl['mixed'] ?? true,
            'مشروعٌ لا مبلغَ فيه أصلاً وُسِم «مخلوطاً» — والصفرُ لا يُخالط');
        $this->assertEqualsWithDelta(0.0, $pl['cost']['total'], 0.001);
        $this->assertEqualsWithDelta(0.0, $pl['revenue']['invoiced'], 0.001);
    }

    // ═══════════ ٤ · التقريرُ الماليّ — بسعرِ شهرِ كلِّ مستند ═══════════

    /**
     * **سعرُ يناير لفاتورةِ يناير.** فاتورتان بألفِ دولارٍ كلٌّ منهما، واحدةٌ
     * في يناير بسعرِ ٠٫٣ وأخرى في يونيو بسعرِ ٠٫٤ ⇒ ٧٠٠. وتحويلُهما بسعرِ
     * اليومِ الواحدِ يعطي ٨٠٠ — ويجعل تقريرَ الربعِ الماضي يتغيّر كلَّ صباح.
     */
    public function test_finance_report_converts_each_month_at_its_own_rate(): void
    {
        $this->seedCore();
        $base = Currency::base();
        $y = now()->year;
        FinDocument::create(['kind' => 'فاتورة مبيعات', 'total' => 1000, 'currency' => 'USD',
            'date' => $y . '-01-15']);
        FinDocument::create(['kind' => 'فاتورة مبيعات', 'total' => 1000, 'currency' => 'USD',
            'date' => $y . '-06-15']);
        // وصفٌّ بعملةِ الأساسِ يجعل المجموعَ مخلوطاً فعلاً — ومجموعُ عملةٍ
        // واحدةٍ لا يُوسَم محوَّلاً بحكمِ عقدِ المحرّك (ألفُ دولارٍ هو ألفُ دولار)
        FinDocument::create(['kind' => 'فاتورة مبيعات', 'total' => 100, 'currency' => $base,
            'date' => $y . '-06-20']);
        $this->rate('USD', $base, '0.300000', $y . '-01-01');
        $this->rate('USD', $base, '0.400000', $y . '-06-01');

        $q = fn () => DB::table('fin_documents')->whereNull('deleted_at')
            ->where('kind', 'فاتورة مبيعات')->where('date', '>=', $y . '-01-01');
        $sum = hub_money_sum_q($q(), 'total', 'currency', 'date');

        $this->assertTrue($sum['converted'], 'لم يُحوَّل رغمَ وجودِ سعرين');
        $this->assertEqualsWithDelta(800.0, $sum['total'], 0.001,
            'يناير بـ٠٫٣ (٣٠٠) ويونيو بـ٠٫٤ (٤٠٠) + ١٠٠ أساس — وبسعرٍ واحدٍ لكان ٧٠٠ أو ٩٠٠');
    }

    /** والتقريرُ بلا سعرٍ لا يُغيّر استعلامَه ولا رقمَه */
    public function test_finance_report_query_is_untouched_without_a_rate(): void
    {
        $this->seedCore();
        $y = now()->year;
        FinDocument::create(['kind' => 'فاتورة مبيعات', 'total' => 1000, 'currency' => 'USD',
            'date' => $y . '-01-15']);
        FinDocument::create(['kind' => 'فاتورة مبيعات', 'total' => 400, 'currency' => Currency::base(),
            'date' => $y . '-06-15']);

        $q = fn () => DB::table('fin_documents')->whereNull('deleted_at')
            ->where('kind', 'فاتورة مبيعات')->where('date', '>=', $y . '-01-01');

        $sql = [];
        DB::listen(function ($e) use (&$sql) { $sql[] = $e->sql; });
        $sum = hub_money_sum_q($q(), 'total', 'currency', 'date');

        $this->assertFalse($sum['converted']);
        $this->assertTrue($sum['mixed']);
        $this->assertEqualsWithDelta(1400.0, $sum['total'], 0.001, 'الخامُ كما كان');
        $grouped = array_values(array_filter($sql, fn ($q) => str_contains(strtolower($q), 'group by')
            && str_contains(strtolower($q), 'fin_documents')));
        $this->assertSame([], $grouped,
            'المسارُ الخالي من الأسعارِ حوّل المجموعَ القياسيَّ إلى تجميعٍ بالعملةِ والشهر — '
            . 'ومن لم يُفعّل الميزةَ لا يدفع ثمنَها: ' . implode(' | ', $grouped));
    }

    // ═══════════ ٥ · قيمةُ العقودِ السارية ═══════════

    public function test_contract_values_convert_on_the_legal_screen(): void
    {
        $this->seedCore();
        $base = Currency::base();
        if (! \Illuminate\Support\Facades\Schema::hasTable('contracts')) {
            $this->markTestSkipped('لا جدولَ عقودٍ في هذه التهيئة');
        }
        $this->contract(1000, 'USD');
        $this->contract(300, $base);
        $this->rate('USD', $base, '0.300000', now()->subYear()->toDateString());

        $html = $this->actingAs($this->owner)->get(route('legal'))->assertOk()->getContent();

        $this->assertStringContainsString('محوَّل', $html,
            'الشاشةُ لا تُعلن أنّ الرقمَ محوَّلٌ — والمحوَّلُ يُعلَن لا يُقدَّم أصليّاً');
    }

    // ═══════════ ٦ · التقريرُ الماليُّ والإيرادُ المتكرّرُ ولوحةُ التكاليف ═══════════

    /** الشاشةُ تُعلن التحويلَ ولا تُقدّم المحوَّلَ أصليّاً */
    public function test_the_finance_report_screen_declares_the_conversion(): void
    {
        $this->seedCore();
        $base = Currency::base();
        $y = now()->year;
        FinDocument::create(['kind' => 'فاتورة مبيعات', 'total' => 1000, 'currency' => 'USD',
            'date' => $y . '-01-15']);
        FinDocument::create(['kind' => 'فاتورة مبيعات', 'total' => 100, 'currency' => $base,
            'date' => $y . '-01-20']);
        $this->rate('USD', $base, '0.300000', $y . '-01-01');

        $html = $this->actingAs($this->owner)->get(route('reports.finance'))->assertOk()->getContent();

        $this->assertStringContainsString('محوَّل', $html, 'التقريرُ لا يُعلن أنّ أرقامَه محوَّلة');
        $this->assertStringNotContainsString('قد تختلط العملات', $html,
            'يعتذر بالاختلاط وقد حُوِّل');
    }

    /** الإيرادُ المتكرّرُ يصير رقماً واحداً — والتفصيلُ بالعملةِ يبقى (لا حذف) */
    public function test_recurring_revenue_becomes_one_number_without_losing_the_breakdown(): void
    {
        $this->seedCore();
        $base = Currency::base();
        if (! \Illuminate\Support\Facades\Schema::hasTable('contracts')) {
            $this->markTestSkipped('لا جدولَ عقودٍ في هذه التهيئة');
        }
        $this->clientContract(1200, 'USD');
        $this->clientContract(120, $base);
        $this->rate('USD', $base, '0.300000', now()->subYear()->toDateString());

        $this->actingAs($this->owner);
        \Illuminate\Support\Facades\Cache::flush();
        $m = hub_mrr(true);

        $this->assertTrue($m['converted'], 'الإيرادُ المتكرّرُ لم يُحوَّل مع وجودِ سعر');
        $this->assertFalse($m['mixed']);
        // سنويّان ÷ ١٢: (١٢٠٠ × ٠٫٣ + ١٢٠) ÷ ١٢ = ٤٠
        $this->assertEqualsWithDelta(40.0, $m['mrr'], 0.01);
        $this->assertEqualsWithDelta(480.0, $m['arr'], 0.01, 'ARR تتبع MRR المحوَّلة');
        $this->assertCount(2, $m['byCurrency'], 'التفصيلُ بالعملةِ حُذف — والوصلُ إضافةٌ لا كسر');
    }

    /**
     * لوحةُ التكاليف تجمع ربحيّةَ مشاريعَ — فتُعلن التحويلَ حين يُحوَّل **كلُّ**
     * مشروعٍ فيها، لا حين يُحوَّل أحدُها: مجموعُ محوَّلٍ وخامٍ رقمٌ لا يُمثّل شيئاً.
     */
    public function test_the_costs_board_declares_conversion_only_when_every_project_converts(): void
    {
        $this->seedCore();
        $base = Currency::base();
        $this->projectWithMixedPl($base);
        $this->rate('USD', $base, '0.300000', '2026-01-01');

        \Illuminate\Support\Facades\Cache::flush();
        $html = $this->actingAs($this->owner)->get(route('costs.index'))->assertOk()->getContent();
        $this->assertStringContainsString('بأسعارِ الصرف المسجَّلة', $html,
            'اللوحةُ لا تُعلن التحويلَ وكلُّ مشاريعِها محوَّلة');

        // مشروعٌ ثانٍ بزوجٍ بلا سعرٍ يُعيد اللوحةَ كلَّها إلى الخام
        $p2 = Project::create(['name' => 'مشروعُ اليورو', 'status' => 'قيد التنفيذ',
            'client_id' => $this->client()->id]);
        FinDocument::create(['kind' => 'فاتورة مبيعات', 'project_id' => $p2->id,
            'total' => 500, 'currency' => 'EUR', 'date' => '2026-03-01']);
        DB::table('purchases')->insert([
            'id' => (string) Str::uuid(), 'doc_no' => 'ش-' . Str::random(6),
            'project_id' => $p2->id, 'amount' => 50, 'date' => '2026-03-01',
            'currency' => $base, 'status' => 'مستلم',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\Cache::flush();

        $html = $this->get(route('costs.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('بأسعارِ الصرف المسجَّلة', $html,
            'أعلنت التحويلَ ومشروعٌ فيها بزوجٍ بلا سعر — ومجموعُ محوَّلٍ وخامٍ لا يُمثّل شيئاً');
    }

    // ═══════════ ٧ · الحدُّ المعلَن: بلا سعرٍ لا تتغيّر شاشةٌ واحدة ═══════════

    /**
     * **الحارسُ الجامع.** ستُّ شاشاتٍ تجمع المال، وبلا سعرٍ مسجَّلٍ لا واحدةٌ
     * منها تُعلن تحويلاً ولا تنكسر. وهذا ما يجعل الوصلَ إضافةً: من لم يُفعّل
     * الميزةَ لا يرى أثراً لها البتّة.
     */
    public function test_no_screen_claims_conversion_without_a_registered_rate(): void
    {
        $this->seedCore();
        $base = Currency::base();
        $c = Client::create(['name' => 'عميلٌ']);
        FinDocument::create(['kind' => 'فاتورة مبيعات', 'client_id' => $c->id, 'total' => 1000,
            'currency' => 'USD', 'date' => now()->subMonth()->toDateString()]);
        FinDocument::create(['kind' => 'فاتورة مبيعات', 'client_id' => $c->id, 'total' => 400,
            'currency' => $base, 'date' => now()->subMonth()->toDateString()]);
        $this->quotesForOwner($base);

        $this->actingAs($this->owner);
        foreach (['reports.finance', 'legal', 'costs.index', 'ceo'] as $r) {
            $html = $this->get(route($r))->assertOk()->getContent();
            $this->assertStringNotContainsString('بأسعارِ الصرف المسجَّلة', $html,
                "شاشةُ «{$r}» ادّعت تحويلاً ولا سعرَ مسجَّلٌ في النظام");
        }
    }

    // ═══════════ أدواتٌ ═══════════

    private function client(): Client
    {
        return $this->clientMemo ??= Client::create(['name' => 'عميلُ الاختبار']);
    }

    private ?Client $clientMemo = null;

    /** صفقتان لبائعٍ واحدٍ بعملتين: ١٠٠٠ دولارٍ و٣٠٠ بعملةِ الأساس */
    private function quotesForOwner(string $base): void
    {
        Quote::create(['client_id' => $this->client()->id, 'total' => 1000, 'currency' => 'USD',
            'status' => 'مقبول', 'owner_id' => $this->owner->id, 'accepted_at' => '2026-03-01']);
        Quote::create(['client_id' => $this->client()->id, 'total' => 300, 'currency' => $base,
            'status' => 'مقبول', 'owner_id' => $this->owner->id, 'accepted_at' => '2026-03-01']);
    }

    /** صفُّ البائعِ من لوحةِ المبيعات */
    private function ownerRow(): array
    {
        $this->actingAs($this->owner);
        \Illuminate\Support\Facades\Cache::flush();
        $rows = SalesBoard::data()['byOwner'] ?? [];
        $this->assertNotEmpty($rows, 'لا صفَّ بائعٍ — تهيئةٌ خاطئة');

        return (array) $rows[0];
    }

    /** مشروعٌ إيرادُه بالدولارِ وتكلفتُه بعملةٍ تُملى */
    private function projectWithMixedPl(string $base, string $costCurrency = 'د.ك'): array
    {
        $costCurrency = $costCurrency === 'د.ك' ? $base : $costCurrency;
        $p = Project::create(['name' => 'مشروعُ العملتين', 'status' => 'قيد التنفيذ',
            'client_id' => $this->client()->id]);
        FinDocument::create(['kind' => 'فاتورة مبيعات', 'project_id' => $p->id,
            'total' => 1000, 'currency' => 'USD', 'date' => '2026-03-01']);
        DB::table('purchases')->insert([
            'id' => (string) Str::uuid(), 'doc_no' => 'ش-' . Str::random(6),
            'project_id' => $p->id, 'amount' => 100, 'date' => '2026-03-01',
            'currency' => $costCurrency, 'status' => 'مستلم',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$p];
    }

    /** عقدُ عميلٍ سارٍ سنويٌّ — مصدرُ الإيرادِ المتكرّر */
    private function clientContract(float $value, string $cur): void
    {
        DB::table('contracts')->insert([
            'id' => (string) Str::uuid(), 'title' => 'عقدُ ' . $cur, 'status' => 'ساري',
            'type' => 'عقد عميل', 'value' => $value, 'currency' => $cur,
            'client_id' => $this->client()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function contract(float $value, string $cur): void
    {
        DB::table('contracts')->insert([
            'id' => (string) Str::uuid(), 'title' => 'عقدٌ ' . $cur, 'status' => 'ساري',
            'type' => 'خدمات', 'value' => $value, 'currency' => $cur,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
