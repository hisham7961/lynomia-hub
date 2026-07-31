<?php

namespace Tests\Feature;

use App\Models\Quote;
use App\Models\StockItem;
use App\Support\Items;
use Tests\TestCase;

/**
 * تعبئة المنتجات بالكراتين (v2.135): كل صنف يعرف «وحدات كرتونته»، فتُحسب الكراتين
 * على مستندات البيع والشراء وحركات المخزون — والكمية الأقل من الكرتونة لا تعيق شيئاً.
 */
class CartonPackingTest extends TestCase
{
    /** المحلّل يلتقط العمود الرابع الاختياري «وحدات الكرتونة» ولا يكسر السطور الثلاثية */
    public function test_parse_captures_optional_carton_column(): void
    {
        $rows = Items::parse("منتج أ | 30 | 5 | 12\nمنتج ب | 4 | 9");
        $this->assertSame(12, $rows[0]['per']);
        $this->assertSame(30.0, $rows[0]['qty']);
        $this->assertNull($rows[1]['per']);          // سطر ثلاثي قديم يبقى صالحاً
        $this->assertSame(9.0, $rows[1]['price']);
    }

    /** الكراتين تُحسب من التجاوز الصريح، والكمية الأقل من كرتونة تظهر ككسر لا كخطأ */
    public function test_cartons_from_explicit_per_allows_partial(): void
    {
        $rows = Items::cartons(Items::parse("منتج | 30 | 5 | 12"));
        $this->assertSame(2, $rows[0]['cartons']);       // 30 / 12 = 2 كاملة
        $this->assertEqualsWithDelta(6.0, $rows[0]['loose'], 0.001);   // + 6 سائبة
        $this->assertStringContainsString('كرتونة', $rows[0]['cartonText']);
        $this->assertStringContainsString('وحدة', $rows[0]['cartonText']);

        // كمية أقل من الكرتونة: صفر كراتين + الكل سائب — لا استثناء ولا حجب
        $less = Items::cartons(Items::parse("منتج | 5 | 3 | 12"));
        $this->assertSame(0, $less[0]['cartons']);
        $this->assertEqualsWithDelta(5.0, $less[0]['loose'], 0.001);
    }

    /** وحدات الكرتونة تُشتق من تعبئة المنتج في المخزون بمطابقة الاسم (بلا عمود رابع) */
    public function test_cartons_resolved_from_stock_product_by_name(): void
    {
        $this->seedCore();
        StockItem::create(['name' => 'علبة عصير', 'qty' => 0, 'carton_qty' => 24]);

        $rows = Items::cartons(Items::parse("علبة عصير | 50 | 1"));
        $this->assertSame(24, $rows[0]['per']);           // من المنتج لا من السطر
        $this->assertSame(2, $rows[0]['cartons']);        // 50 / 24 = 2 كاملة + 2 سائبة
        $this->assertEqualsWithDelta(2.0, $rows[0]['loose'], 0.001);

        // منتج بلا تعبئة معرّفة: لا كراتين ولا تعطيل
        $rows2 = Items::cartons(Items::parse("صنف مجهول | 9 | 1"));
        $this->assertNull($rows2[0]['per']);
        $this->assertSame('', $rows2[0]['cartonText']);
        $this->assertFalse(Items::anyCartons($rows2));
    }

    /** تفكيك كمية مفردة (حركة مخزون): كراتين + سائب */
    public function test_pack_single_quantity(): void
    {
        $p = Items::pack(27.0, 12);
        $this->assertSame(2, $p['cartons']);
        $this->assertEqualsWithDelta(3.0, $p['loose'], 0.001);
        $this->assertNull(Items::pack(27.0, 0));          // تعبئة غير صالحة → null
        $this->assertNull(Items::pack(null, 12));
    }

    /** مستند عرض السعر يُظهر عمود الكراتين حين لبندٍ تعبئة، وإجمالي الكراتين */
    public function test_quote_doc_renders_carton_column(): void
    {
        $this->seedCore();
        StockItem::create(['name' => 'كيس أسمنت', 'qty' => 0, 'carton_qty' => 10]);
        $q = Quote::create(['doc_no' => 'Q-CTN-1', 'title' => 'عرض', 'items' => "كيس أسمنت | 35 | 2", 'total' => 70]);

        $html = $this->actingAs($this->owner)->get("/quote/{$q->id}/doc")->assertOk()->getContent();
        $this->assertStringContainsString('الكراتين', $html);           // رأس العمود
        $this->assertStringContainsString('كرتونة', $html);             // 35/10 = 3 كراتين
        $this->assertStringContainsString('إجمالي الكراتين الكاملة', $html);
    }

    /** مستندٌ بلا أي منتج ذي تعبئة لا يعرض عمود الكراتين — لا ضجيج على الوثائق القديمة */
    public function test_quote_doc_without_cartons_is_unchanged(): void
    {
        $this->seedCore();
        $q = Quote::create(['doc_no' => 'Q-CTN-2', 'title' => 'خدمات', 'items' => "استشارة | 10 | 100", 'total' => 1000]);

        $html = $this->actingAs($this->owner)->get("/quote/{$q->id}/doc")->assertOk()->getContent();
        $this->assertStringNotContainsString('<th style="width:130px">الكراتين</th>', $html);
    }

    /** حقل تعبئة المنتج قابل للحفظ ويظهر في صفحة المنتج */
    public function test_carton_qty_is_saveable_on_product(): void
    {
        $this->seedCore();
        $item = StockItem::create(['name' => 'صندوق', 'qty' => 0, 'carton_qty' => 6]);
        $this->assertSame(6, (int) $item->fresh()->carton_qty);

        $html = $this->actingAs($this->owner)->get("/m/stock/{$item->id}")->assertOk()->getContent();
        $this->assertStringContainsString('وحدات الكرتونة', $html);
    }
}
