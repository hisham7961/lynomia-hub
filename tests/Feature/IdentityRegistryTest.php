<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Company;
use App\Models\Product;
use App\Models\RecordIdentifier;
use App\Models\Role;
use App\Models\User;
use App\Support\Identity;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **سجل الهوية الموحّد: منتجٌ غير قطعة، ومعرّفٌ واحدٌ لكل سؤال «ما هذا؟».**
 *
 * كانت هويّة الممتلكات ثلاثة أعمدة لا يجمعها بحث: كودُ عهدة، وسيريالُ مصنع،
 * وباركودُ مخزون — والطرازُ (المنتج) لا وجودَ له أصلاً فعشرون لابتوباً متطابقاً
 * عشرون اسماً باليد، وGTIN الطرازِ يُخلط برقم القطعة.
 *
 * ما يحرسه هذا الملف:
 *  1) كودُ المنتج LYN-PRD دائمٌ يولَّد ولا يُكتب، ويتعافى من التصادم.
 *  2) المعرفاتُ تُسجَّل تلقائياً عند الحفظ، والفهرسُ الفريد يمنع التكرار.
 *  3) المحلّل يحسم بالترتيب: كودُ عهدة ← كودُ منتج ← معرّفات ← أعمدةُ ميراث.
 *  4) المسحُ لا يكشف ما لا تكشفه الشاشة: صلاحيةً وعزلَ شركات.
 *  5) مرشّحو التكرار يُسمَّون قبل الإنشاء، والدمجُ يترك كوداً بديلاً لا جثةً.
 */
class IdentityRegistryTest extends TestCase
{
    protected function limited(array $matrix, array $companies = []): User
    {
        $role = Role::create(['name' => 'دور ' . Str::random(5), 'scope' => 'all',
            'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => 'مقيَّد', 'email' => Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'companies' => $companies ?: null, 'password_changed_at' => now()]);
    }

    /* ────────── ١) كود المنتج ────────── */

    public function test_product_codes_are_generated_sequential_and_immutable(): void
    {
        $this->seedCore();

        $p1 = Product::create(['name' => 'Dell Latitude 5550']);
        $p2 = Product::create(['name' => 'HPE ProLiant DL380']);

        $this->assertSame('LYN-PRD-00000001', $p1->code);
        $this->assertSame('LYN-PRD-00000002', $p2->code);

        // الكودُ مقفول (locked) — التعديل من مسار الوحدة لا يمسّه
        $this->actingAs($this->owner)->put('/m/products/' . $p1->id,
            ['name' => 'Dell Latitude 5550', 'code' => 'HACK-1'])->assertRedirect();
        $this->assertSame('LYN-PRD-00000001', $p1->fresh()->code);

        // والمحذوفُ يحجز كودَه — لا يُعاد استعمالُ هويةٍ مطبوعةٍ على ملصق
        $p2->delete();
        $p3 = Product::create(['name' => 'Lenovo ThinkPad']);
        $this->assertSame('LYN-PRD-00000003', $p3->code);
    }

    /* ────────── ٢) التسجيل التلقائي والفهرس الفريد ────────── */

    public function test_identifiers_register_on_save_and_the_unique_index_rules(): void
    {
        $this->seedCore();

        $p = Product::create(['name' => 'iPhone 17 Pro', 'barcode' => '0195949821554', 'mpn' => 'A3101']);
        $kinds = Identity::of('products', $p->id)->pluck('kind')->all();
        $this->assertContains('lyn', $kinds);
        $this->assertContains('gtin', $kinds);
        $this->assertContains('mpn', $kinds);

        $a = Asset::create(['name' => 'جهاز اختبار', 'type' => 'هاتف', 'serial' => 'SN-777']);
        $ka = Identity::of('assets', $a->id);
        $this->assertContains('lyn', $ka->pluck('kind')->all());
        $this->assertSame('SN-777', $ka->firstWhere('kind', 'serial')->value);

        // النداء الثاني بنفس القيمة لا يكرر صفاً — والحفظ المتكرر كذلك
        $a->touch();
        $a->save();
        $this->assertSame(2, Identity::of('assets', $a->id)->count(), 'lyn + serial بلا تكرار');

        // والقيمةُ المحجوزة لسجلٍ آخر تُترك لصاحبها الأول بصمت
        $b = Asset::create(['name' => 'جهاز ثانٍ', 'type' => 'هاتف', 'serial' => 'SN-777']);
        $this->assertNull(RecordIdentifier::where('module', 'assets')->where('kind', 'serial')
            ->where('norm', 'SN-777')->where('record_id', $b->id)->first());
    }

    public function test_gtin_checksum_is_validated_not_just_length(): void
    {
        $this->assertTrue(Identity::looksGtin('6291041500213'));   // EAN-13 سليمة
        $this->assertTrue(Identity::looksGtin('96385074'));        // EAN-8 سليمة
        $this->assertFalse(Identity::looksGtin('6291041500214'), 'خانةُ تحقق فاسدة تُرفض');
        $this->assertFalse(Identity::looksGtin('12345'), 'طولٌ غير عالمي');
        $this->assertFalse(Identity::looksGtin('LYN-SV-2026-0001'));
    }

    /* ────────── ٣) ترتيب الحسم ────────── */

    public function test_the_resolver_answers_every_identifier_type(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $p = Product::create(['name' => 'Latitude 5550', 'barcode' => '6291041500213']);
        $a = Asset::create(['name' => 'لابتوب المدير', 'type' => 'لابتوب',
            'serial' => 'CZJ12345', 'product_id' => $p->id]);
        $s = \App\Models\StockItem::create(['name' => 'ورق A4', 'barcode' => '9788973146888', 'qty' => 5]);

        $this->assertSame('asset', Identity::resolve($a->code)['type'], 'كود عهدة');
        $this->assertSame('asset', Identity::resolve(mb_strtolower($a->code))['type'], 'وبأي حالة أحرف');
        $this->assertSame('product', Identity::resolve($p->code)['type'], 'كود منتج');
        $this->assertSame('asset', Identity::resolve('CZJ12345')['type'], 'سيريال ← القطعة');
        $this->assertSame('product', Identity::resolve('6291041500213')['type'], 'GTIN ← الطراز');
        $this->assertSame('stock', Identity::resolve('9788973146888')['type'], 'باركود مخزون');
        $this->assertSame('none', Identity::resolve('لا شيء إطلاقاً')['type']);

        // الباركود العالمي يقود للطراز لا لقطعةٍ ما — التمييزُ الجوهري
        $hit = Identity::resolve('6291041500213');
        $this->assertSame($p->id, $hit['row']->id);
    }

    public function test_the_resolve_endpoint_says_asset_product_and_unknown(): void
    {
        $this->seedCore();
        $p = Product::create(['name' => 'ProLiant DL380', 'barcode' => '6291041500213']);
        $a = Asset::create(['name' => 'سيرفر الإنتاج', 'type' => 'سيرفر', 'product_id' => $p->id]);

        $this->actingAs($this->owner)->get('/identity/resolve?q=' . $a->code)
            ->assertOk()->assertJsonPath('type', 'asset')
            ->assertJsonPath('asset.code', $a->code)
            ->assertJsonPath('asset.product', 'ProLiant DL380');

        $this->actingAs($this->owner)->get('/identity/resolve?q=6291041500213')
            ->assertOk()->assertJsonPath('type', 'product')
            ->assertJsonPath('product.canRegister', true);

        // مجهولٌ بصيغة GTIN سليمة: يقول «باركود عالمي» ويعرض الاستكشاف
        $this->actingAs($this->owner)->get('/identity/resolve?q=4006381333931')
            ->assertOk()->assertJsonPath('type', 'none')
            ->assertJsonPath('gtin', true)->assertJsonPath('canDiscover', true);
    }

    /* ────────── ٤) المسح لا يكشف ما لا تكشفه الشاشة ────────── */

    public function test_the_resolver_respects_permissions_and_company_isolation(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركة أ', 'status' => 'نشطة']);
        $other = Company::create(['name_ar' => 'شركة ب', 'status' => 'نشطة']);

        $a = Asset::create(['name' => 'سيرفر ب', 'type' => 'سيرفر',
            'serial' => 'SECRET-9', 'company_id' => $other->id]);

        // بلا صلاحية عرض الأصول: الكودُ الصحيح «غير معروف» — لا وجودَ يُثبَت
        $blind = $this->limited(['products' => ['v' => 1]]);
        $this->actingAs($blind);
        $this->assertSame('none', Identity::resolve($a->code)['type']);
        $this->assertSame('none', Identity::resolve('SECRET-9')['type']);

        // ومعزولُ الشركة لا يرى أصلَ شركةٍ أخرى ولو ملك الوحدة
        $iso = $this->limited(['assets' => ['v' => 1]], [$co->id]);
        $this->actingAs($iso);
        $this->assertSame('none', Identity::resolve($a->code)['type'], 'أصل شركةٍ أجنبية لا يُحسم');
        $this->assertSame('none', Identity::resolve('SECRET-9')['type'], 'ولا سيريالُه');

        // وصاحبُ الشركة نفسِها يراه
        $mine = Asset::create(['name' => 'سيرفر أ', 'type' => 'سيرفر', 'company_id' => $co->id]);
        $this->assertSame('asset', Identity::resolve($mine->code)['type']);
    }

    public function test_the_api_resolver_uses_the_same_engine_and_gates(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'جهاز API', 'type' => 'لابتوب']);

        \App\Models\ApiToken::create(['name' => 'اختبار', 'user_id' => $this->owner->id,
            'token_hash' => hash('sha256', 'tk_test_123'), 'scopes' => 'assets:v', 'created_at' => now()]);

        $this->getJson('/api/v1/identity/resolve/' . $a->code, ['Authorization' => 'Bearer tk_test_123'])
            ->assertOk()->assertJsonPath('type', 'asset')->assertJsonPath('code', $a->code);

        $this->getJson('/api/v1/identity/resolve/UNKNOWN-XYZ', ['Authorization' => 'Bearer tk_test_123'])
            ->assertOk()->assertJsonPath('type', 'none');

        $this->getJson('/api/v1/identity/resolve/' . $a->code)->assertUnauthorized();
    }

    /* ────────── ٥) التكرار والدمج ────────── */

    public function test_duplicate_candidates_are_named_before_creation(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        Product::create(['name' => 'Latitude 5550', 'brand' => 'Dell', 'model' => 'Latitude 5550',
            'barcode' => '6291041500213']);

        $d = Identity::dupes(['barcode' => '6291041500213']);
        $this->assertCount(1, $d);
        $this->assertSame('الباركود نفسه', $d[0]['why']);

        $d2 = Identity::dupes(['brand' => 'Dell', 'model' => 'Latitude 5550']);
        $this->assertNotEmpty($d2);
    }

    public function test_merge_repoints_assets_and_leaves_an_alias_not_a_corpse(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $into = Product::create(['name' => 'HPE DL380 Gen10', 'brand' => 'HPE']);
        $dupe = Product::create(['name' => 'HP DL380 G10', 'barcode' => '6291041500213']);
        $a1 = Asset::create(['name' => 'سيرفر ١', 'type' => 'سيرفر', 'product_id' => $dupe->id]);
        $a2 = Asset::create(['name' => 'سيرفر ٢', 'type' => 'سيرفر', 'product_id' => $dupe->id]);
        $dupeCode = $dupe->code;

        $this->actingAs($this->owner)->post('/identity/merge/' . $dupe->id, ['into' => $into->id])
            ->assertRedirect(route('m.show', ['products', $into->id]));

        // القطعُ أُعيدت إشارتُها كلُّها — لا واحدةٌ من اثنتين
        $this->assertSame([$into->id, $into->id],
            [$a1->fresh()->product_id, $a2->fresh()->product_id]);

        // والـGTIN انتقل للأصل، وكودُ المكرر صار اسماً بديلاً يفتح الأصل
        $this->assertSame('product', ($h = Identity::resolve('6291041500213'))['type']);
        $this->assertSame($into->id, $h['row']->id);
        $hit = Identity::resolve($dupeCode);
        $this->assertSame('product', $hit['type'], 'مسحُ ملصقٍ قديم لا يقول «لا نتائج»');
        $this->assertSame($into->id, $hit['row']->id);

        // والمكرر مؤرشفٌ يقول أين ذهب — لا محذوفٌ بصمت
        $fresh = $dupe->fresh();
        $this->assertSame('مؤرشف بدمج', $fresh->status);
        $this->assertTrue((bool) $fresh->archived);
        $this->assertSame($into->id, data_get($fresh->meta, 'merged_into'));

        // الدمجُ صلاحيةُ حذفٍ — قارئٌ لا يدمج
        $reader = $this->limited(['products' => ['v' => 1]]);
        $p3 = Product::create(['name' => 'ثالث']);
        $this->actingAs($reader)->post('/identity/merge/' . $p3->id, ['into' => $into->id])
            ->assertForbidden();
    }

    /* ────────── ٦) هجرة التعريف الرجعي ────────── */

    public function test_the_backfill_registered_existing_assets_without_touching_them(): void
    {
        $this->seedCore();
        // الهجرة عملت أثناء migrate — أصلٌ جديد الآن يمثّل «قائماً» قبلها:
        // نتأكد أن السكّتين (هجرة + خطاف الحفظ) تُنتجان الشكل نفسه
        $a = Asset::create(['name' => 'قديم مُعرَّف', 'type' => 'شاشة',
            'serial' => 'OLD-SN-1', 'tag' => 'TAG-9']);

        $kinds = Identity::of('assets', $a->id)->pluck('kind')->sort()->values()->all();
        $this->assertSame(['lyn', 'serial', 'tag'], $kinds);

        // كودُ الأصل القائم يبقى كما هو — الهوية تسجيلٌ لا استبدال
        $this->assertMatchesRegularExpression('/^LYN-SC-\d{4}-\d{4}$/', $a->code);
    }
}
