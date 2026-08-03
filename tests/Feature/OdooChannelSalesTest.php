<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Support\Odoo;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesOdoo;
use Tests\TestCase;

/**
 * **أرقام قنوات البيع من أودو** — النطاقات والاحتياطي والكاش والعرض.
 *
 * الفرقُ الدلاليّ مثبَّت: team/tag/website تُحصي أوامر البيع المؤكدة،
 * وjournal يُحصي فواتير العملاء المرحّلة — دفترُ المحاسبة يخصّ الفواتير.
 */
class OdooChannelSalesTest extends TestCase
{
    use FakesOdoo;

    protected function scene(array $channels): Project
    {
        $this->seedCore();
        foreach (['odoo.url' => 'https://main.example.com', 'odoo.db' => 'db',
                  'odoo.user' => 'ro@x.c', 'odoo.key' => 'k'] as $k => $v) {
            $this->hubSetting($k, $v);
        }
        $p = Project::create(['name' => 'مشروع القنوات', 'status' => 'نشط']);
        $meta = (array) $p->meta;
        $meta['odoo']['channels'] = $channels;
        $p->meta = $meta;
        $p->save();

        return $p;
    }

    protected function ch(string $key, string $mode, int $rid, string $label = 'قناة'): array
    {
        return ['key' => $key, 'label' => $label, 'icon' => '🛒',
                'mode' => $mode, 'ref_id' => $rid, 'ref_name' => 'R' . $rid];
    }

    public function test_team_tag_website_modes_query_sale_orders_with_right_domain(): void
    {
        $p = $this->scene([]);
        $captured = [];
        $this->fakeOdoo('https://main.example.com', [
            'common.authenticate' => 7,
            'sale.order.search_count' => function ($args) use (&$captured) {
                $captured[] = $args[0]; return 4;
            },
            'sale.order.read_group' => [['amount_total' => 500.0]],
        ]);

        $cli = Odoo::for(null);
        foreach ([['team', 'team_id'], ['tag', 'tag_ids'], ['website', 'website_id']] as [$mode, $col]) {
            $s = $cli->channelStats($p->id, $this->ch('c_' . $mode . '01', $mode, 5));
            $this->assertSame(4, $s['count']);
            $this->assertSame('orders', $s['src'], "المميِّز {$mode} لا يُحصي الأوامر");
        }

        // النطاقات: العمود الصحيح + قيد الحالة sale/done في كل نداء
        $this->assertCount(3, $captured);
        foreach ([['team_id', 0], ['tag_ids', 1], ['website_id', 2]] as [$col, $i]) {
            $flat = json_encode($captured[$i]);
            $this->assertStringContainsString($col, $flat, "نطاق {$col} غائب");
            $this->assertStringContainsString('"sale"', $flat, 'قيد الحالة غائب — المسودات ستُحصى مبيعات');
        }
    }

    /** الدفترُ محاسبيّ: يقرأ فواتير العملاء المرحّلة لا أوامر البيع */
    public function test_journal_mode_reads_posted_customer_invoices_not_orders(): void
    {
        $p = $this->scene([]);
        $domain = null;
        $this->fakeOdoo('https://main.example.com', [
            'common.authenticate' => 7,
            'account.move.search_count' => function ($args) use (&$domain) {
                $domain = $args[0]; return 9;
            },
            'account.move.read_group' => [['amount_total' => 1200.0]],
        ]);

        $s = Odoo::for(null)->channelStats($p->id, $this->ch('c_jrn001', 'journal', 9));

        $this->assertSame(9, $s['count']);
        $this->assertSame('invoices', $s['src']);
        $flat = json_encode($domain);
        $this->assertStringContainsString('journal_id', $flat);
        $this->assertStringContainsString('out_invoice', $flat, 'بلا قيد النوع ستدخل فواتير الموردين');
        $this->assertStringContainsString('posted', $flat, 'بلا قيد الترحيل ستدخل المسودات');
    }

    /** أودو 17/18 بدأ يهجر read_group — الاحتياطي يعمل وعلامة approx تصدُق */
    public function test_total_falls_back_to_search_read_sum_when_read_group_fails(): void
    {
        $p = $this->scene([]);
        $this->fakeOdoo('https://main.example.com', [
            'common.authenticate' => 7,
            'sale.order.search_count' => 3,
            'sale.order.read_group' => ['#error' => 'read_group deprecated'],
            'sale.order.search_read' => [
                ['amount_total' => 100.5], ['amount_total' => 200.5], ['amount_total' => 99.0],
            ],
        ]);

        $s = Odoo::for(null)->channelStats($p->id, $this->ch('c_fb0001', 'team', 5));

        $this->assertSame(400.0, $s['total'], 'الاحتياطي لم يجمع صحيحاً');
        $this->assertFalse($s['approx'], 'ثلاثة صفوف دون الحد — لا وجه لوسم التقريب');
    }

    public function test_project_card_renders_tiles_totals_and_partial_failures(): void
    {
        $p = $this->scene([
            $this->ch('c_ok0001', 'team', 5, 'ترنديول'),
            $this->ch('c_bad001', 'journal', 9, 'أمازون'),
        ]);
        $this->fakeOdoo('https://main.example.com', [
            'common.authenticate' => 7,
            'sale.order.search_count' => 6,
            'sale.order.read_group' => [['amount_total' => 750.0]],
            'account.move.search_count' => ['#error' => 'Access Denied'],
        ]);

        $html = $this->actingAs($this->owner)->get("/m/projects/{$p->id}")->assertOk()->getContent();

        $this->assertStringContainsString('مبيعات القنوات', $html);
        $this->assertStringContainsString('ترنديول', $html);
        $this->assertStringContainsString('750', $html);
        $this->assertStringContainsString('6 أمر', $html);
        // القناة الفاشلة تعرض تعذُّرها ولا تقتل الصفحة ولا جارتها
        $this->assertStringContainsString('تعذّر', $html);
        $this->assertStringContainsString('أمازون', $html);
    }

    public function test_stats_cached_and_refresh_busts(): void
    {
        $p = $this->scene([$this->ch('c_cache1', 'team', 5, 'نون')]);
        $this->fakeOdoo('https://main.example.com', [
            'common.authenticate' => 7,
            'sale.order.search_count' => 2,
            'sale.order.read_group' => [['amount_total' => 300.0]],
        ]);

        $this->actingAs($this->owner)->get("/m/projects/{$p->id}")->assertOk();
        $n1 = count(Http::recorded());

        // عرضٌ ثانٍ: لا نداء جديداً — الكاش يخدم
        $this->actingAs($this->owner)->get("/m/projects/{$p->id}")->assertOk();
        $this->assertSame($n1, count(Http::recorded()), 'العرض الثاني نادى أودو رغم الكاش');

        // والتحديث يكسره فيُنادى من جديد
        $this->actingAs($this->owner)->post("/odoo/projects/{$p->id}/channels/refresh");
        $this->actingAs($this->owner)->get("/m/projects/{$p->id}")->assertOk();
        $this->assertGreaterThan($n1, count(Http::recorded()), 'التحديث لم يكسر الكاش');
    }

    /** بلا تهيئةٍ أو بلا قنوات: إرشادٌ بلا أي HTTP — حماية شبكة الشاشات */
    public function test_card_degrades_without_config_and_makes_no_http(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'بلا أودو', 'status' => 'نشط']);
        Http::fake();   // أي نداء سيُسجَّل

        $html = $this->actingAs($this->owner)->get("/m/projects/{$p->id}")->assertOk()->getContent();

        $this->assertStringContainsString('مبيعات القنوات', $html);
        $this->assertStringContainsString('لم يُهيأ اتصال أودو', $html);
        $this->assertCount(0, Http::recorded(), 'شاشةٌ بلا تهيئة نادت الشبكة');
    }
}
