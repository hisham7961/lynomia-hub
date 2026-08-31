<?php

namespace Tests\Feature;

use App\Models\IdentityLookup;
use App\Models\Product;
use App\Support\Discovery\Engine;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **الاستكشاف الخارجي: يسأل ويجمع ويسمّي مصادره — ولا يخترع ولا يعتمد عليه أحد.**
 *
 * باركود عالميّ مجهول يُسأل عنه المزوّدون المفعَّلون، وتُجمَع الإجابات باقتراحٍ
 * واحد: قيمةُ كل حقلٍ بالأغلبية، وثقتُه من الاتفاق، ومصدرُه محفوظ. والفشلُ
 * معزول: مزوّدٌ ساقطٌ لا يُسقط البقية، وكلُّهم ساقطين لا يُسقطون شاشةَ عهدة.
 *
 * ما يحرسه هذا الملف:
 *  1) التجميع: اتفاقُ مزوّدين يرفع الثقة، وخلافُهما يخفضها، والغائب يبقى غائباً.
 *  2) عزل الفشل والمهلة، وترتيبُ الأولوية من الإعداد، وإطفاءُ مزوّد.
 *  3) الكاش: السؤال الثاني لا يطرق الشبكة، والعدّاد يشهد.
 *  4) حارس SSRF: مضيفٌ يتحلّل إلى عنوانٍ داخلي لا يُطرَق أصلاً.
 *  5) الداخليُّ أولاً: منتجٌ مسجَّل لا يُسأل عنه مزوّد.
 */
class DiscoveryTest extends TestCase
{
    protected const GTIN = '6291041500213';

    protected function setUp(): void
    {
        parent::setUp();
        // بلا DNS حيّ في الاختبارات: المضيفات الخارجية تتحلّل لعنوانٍ عام ثابت
        app()->instance('hub.dns', fn (string $h) => ['93.184.216.34']);
    }

    protected function fakeProviders(array $upc = null, array $off = null): void
    {
        Http::fake([
            'api.upcitemdb.com/*' => $upc === null
                ? Http::response('', 500)
                : Http::response($upc),
            'world.openfoodfacts.org/*' => $off === null
                ? Http::response('', 500)
                : Http::response($off),
            'openlibrary.org/*' => Http::response('', 404),
        ]);
    }

    /* ────────── ١) التجميع والثقة ────────── */

    public function test_agreement_raises_confidence_and_conflict_lowers_it(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $this->fakeProviders(
            ['items' => [['title' => 'Dell Latitude 5550', 'brand' => 'Dell', 'model' => 'Latitude 5550',
                'category' => 'Electronics > Laptop Computers']]],
            ['status' => 1, 'product' => ['product_name' => 'Dell Latitude 5550', 'brands' => 'DELL COMPUTERS']],
        );

        $res = Engine::lookup(self::GTIN);

        $this->assertSame('found', $res['status']);
        $s = $res['suggestion'];
        $this->assertSame('Dell Latitude 5550', $s['name'], 'الاسمُ المتفق عليه');
        $this->assertSame('لابتوب', $s['type'], 'التصنيف الخارجي تُرجم لمفردات النظام');
        // اتفاقُ اثنين على الاسم أوثق من انفراد واحدٍ بالعلامة (تعارضت الصياغتان)
        $this->assertGreaterThan($s['confidence']['brand'], $s['confidence']['name']);
        $this->assertSame(['upcitemdb', 'openfoodfacts'], $s['sources']['name'], 'المصدر مُسمّى');
        // ولا اختلاق: لا origin لأن أحداً لم يقله
        $this->assertArrayNotHasKey('origin', $s);
    }

    /* ────────── ٢) عزل الفشل والإعداد ────────── */

    public function test_a_dead_provider_does_not_kill_the_rest(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $this->fakeProviders(null,   // upcitemdb يرد 500
            ['status' => 1, 'product' => ['product_name' => 'حليب المراعي', 'brands' => 'المراعي']]);

        $res = Engine::lookup(self::GTIN);

        $this->assertSame('found', $res['status'], 'سقوطُ مزوّدٍ لا يُسقط الجواب');
        $this->assertSame('حليب المراعي', $res['suggestion']['name']);
        $log = collect($res['providers']);
        $this->assertFalse($log->firstWhere('key', 'upcitemdb')['ok']);
        $this->assertTrue($log->firstWhere('key', 'openfoodfacts')['ok']);
    }

    public function test_disabling_a_provider_in_settings_skips_it(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $this->hubSetting('identity.providers', 'openfoodfacts');
        $this->fakeProviders(
            ['items' => [['title' => 'يجب ألا يُسأل']]],
            ['status' => 1, 'product' => ['product_name' => 'الوحيد المفعَّل']]);

        $res = Engine::lookup(self::GTIN);

        $this->assertSame(['openfoodfacts'], collect($res['providers'])->pluck('key')->all());
        $this->assertSame('الوحيد المفعَّل', $res['suggestion']['name']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'upcitemdb'));
    }

    public function test_openlibrary_is_only_asked_about_isbn_barcodes(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        Http::fake([
            'api.upcitemdb.com/*' => Http::response('', 404),
            'world.openfoodfacts.org/*' => Http::response('', 404),
            'openlibrary.org/*' => Http::response(['title' => 'كتاب الاختبار', 'publishers' => ['دار النشر']]),
        ]);

        // ISBN (978…) يصل مزوّدَ الكتب
        $book = Engine::lookup('9789933101473');
        $this->assertSame('found', $book['status']);
        $this->assertSame('كتاب الاختبار', $book['suggestion']['name']);

        // وباركود عاديّ لا يطرقه أصلاً
        Engine::lookup(self::GTIN);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'openlibrary') && str_contains($req->url(), self::GTIN));
    }

    /* ────────── ٣) الكاش ────────── */

    public function test_the_cache_answers_the_second_scan_without_the_network(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $this->fakeProviders(['items' => [['title' => 'منتج مكرر السؤال']]],
            ['status' => 1, 'product' => ['product_name' => 'منتج مكرر السؤال']]);

        $first = Engine::lookup(self::GTIN);
        $this->assertFalse($first['cached']);

        Http::fake(['*' => Http::response('', 500)]);        // الشبكة «سقطت» — والكاش يجيب
        $second = Engine::lookup(self::GTIN);
        $this->assertTrue($second['cached']);
        $this->assertSame('منتج مكرر السؤال', $second['suggestion']['name']);
        $this->assertSame(1, IdentityLookup::where('norm', self::GTIN)->value('hits'));

        // وبعد انقضاء الصلاحية يُسأل من جديد
        IdentityLookup::where('norm', self::GTIN)->update(['checked_at' => now()->subDays(90)]);
        $third = Engine::lookup(self::GTIN);
        $this->assertFalse($third['cached']);

        // والبائتُ جداً يُكنَس في الأتمتة
        IdentityLookup::where('norm', self::GTIN)->update(['checked_at' => now()->subDays(365)]);
        $this->assertSame(1, Engine::prune());
    }

    /* ────────── ٤) حارس SSRF ────────── */

    public function test_a_provider_resolving_to_a_private_address_is_never_contacted(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        app()->instance('hub.dns', fn (string $h) => ['169.254.169.254']);   // بوابة سحابة داخلية
        Http::fake(['*' => Http::response(['items' => [['title' => 'يجب ألا يصل']]])]);

        $res = Engine::lookup(self::GTIN);

        $this->assertSame('notfound', $res['status']);
        Http::assertNothingSent();
        $this->assertNotEmpty($res['providers'], 'الرفضُ مسجَّلٌ مسمّى السبب');
    }

    /* ────────── ٥) الداخلي أولاً — والمسار الكامل عبر النقطة ────────── */

    public function test_discover_endpoint_returns_internal_hits_without_asking_providers(): void
    {
        $this->seedCore();
        Product::create(['name' => 'منتج داخلي', 'barcode' => self::GTIN]);
        Http::fake(['*' => Http::response('', 500)]);

        $this->actingAs($this->owner)->postJson('/identity/discover', ['q' => self::GTIN])
            ->assertOk()->assertJsonPath('type', 'product')
            ->assertJsonPath('product.name', 'منتج داخلي');

        Http::assertNothingSent();
    }

    public function test_discover_requires_the_product_add_permission(): void
    {
        $this->seedCore();
        $role = \App\Models\Role::create(['name' => 'قارئ فقط', 'scope' => 'all', 'flags' => [],
            'matrix' => ['assets' => ['v' => 1], 'products' => ['v' => 1]]]);
        $u = \App\Models\User::create(['name' => 'قارئ', 'email' => 'ro@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->actingAs($u)->postJson('/identity/discover', ['q' => self::GTIN])->assertForbidden();
    }

    public function test_a_suggestion_flags_existing_duplicates(): void
    {
        $this->seedCore();
        Product::create(['name' => 'Dell Latitude 5550', 'brand' => 'Dell', 'model' => 'Latitude 5550']);
        $this->fakeProviders(
            ['items' => [['title' => 'Dell Latitude 5550', 'brand' => 'Dell', 'model' => 'Latitude 5550']]],
            null);

        $res = $this->actingAs($this->owner)
            ->postJson('/identity/discover', ['q' => '4006381333931'])
            ->assertOk()->assertJsonPath('type', 'discovery')->json();

        $this->assertNotEmpty($res['dupes'], 'المشابهُ القائم يُسمّى قبل أن يُنشأ مكرر');
        $this->assertSame('نفس العلامة والطراز', $res['dupes'][0]['why']);
    }
}
