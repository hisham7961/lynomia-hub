<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetCustody;
use App\Models\IdentityLookup;
use App\Models\Product;
use App\Models\RecordIdentifier;
use App\Models\Role;
use App\Models\User;
use App\Support\Identity;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مسح ← تعرّف ← تسجيل ← إسناد: معاملةٌ واحدة من شاشةٍ واحدة.**
 *
 * كان تسجيلُ عهدةٍ من باركود مجهولٍ رحلةً بين ثلاث شاشات: أنشئ المنتج يدوياً،
 * ثم الأصل، ثم افتح العهدة وسلّم — ومن تعثّر في الثانية ترك منتجاً يتيماً.
 *
 * ما يحرسه هذا الملف:
 *  1) الحالات الأربع من نقطةٍ واحدة (identity/register): منتجٌ قائم، بياناتُ
 *     اقتراح، تسجيلٌ يدويّ، ودفعةُ قطعٍ بسيريالاتها.
 *  2) الذرّية: فشلُ أي خطوةٍ (عهدة/قطعة) يُرجع كلَّ شيء — لا منتجَ بلا أصلٍ
 *     قُصد، ولا أصلَ بلا عهدةٍ طُلبت.
 *  3) التزامن: مستخدمان يسجّلان الباركود المجهول نفسَه ⇒ منتجٌ واحد.
 *  4) الصلاحيات على كل باب، والملصقات مفردةً ودفعةً.
 */
class ScanFlowTest extends TestCase
{
    protected function worker(array $matrix): User
    {
        $role = Role::create(['name' => 'عامل ' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => 'عامل مستودع', 'email' => Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /* ────────── ١) الحالات ────────── */

    public function test_case2_known_product_registers_a_unit_and_assigns_custody_in_one_post(): void
    {
        $this->seedCore();
        $p = Product::create(['name' => 'Dell Latitude 5550', 'type' => 'لابتوب',
            'barcode' => '6291041500213']);
        $emp = $this->worker(['assets' => ['v' => 1]]);

        $res = $this->actingAs($this->owner)->post('/identity/register', [
            'product_id' => $p->id, 'serials' => ['CZJ-1001'],
            'holder_id' => $emp->id, 'loc' => 'الدور الثاني', 'note' => 'تسليم أول يوم',
        ]);

        $a = Asset::where('product_id', $p->id)->firstOrFail();
        $res->assertRedirect(route('m.show', ['assets', $a->id]));

        $this->assertSame('لابتوب', $a->type, 'النوع وُرث من الطراز');
        $this->assertSame('CZJ-1001', $a->serial);
        $this->assertSame($emp->id, $a->holder_id, 'العهدة أُسندت في الطلب نفسه');
        $this->assertSame('قيد الاستخدام', $a->status);
        $this->assertMatchesRegularExpression('/^LYN-LT-\d{4}-\d{4}$/', $a->code, 'كودُ عهدةٍ من صنفه');

        // الحيازةُ **سجلٌّ** لا عمودٌ يُدهس: صفُّ تسليمٍ باسم من نفّذ
        $log = AssetCustody::where('asset_id', $a->id)->get();
        $this->assertCount(1, $log);
        $this->assertSame(['تسليم', $emp->id, $this->owner->id],
            [$log[0]->action, $log[0]->user_id, $log[0]->by_id]);

        // والسيريال دخل سجل الهوية: مسحُه يفتح القطعة بعينها
        $this->assertSame('asset', Identity::resolve('CZJ-1001')['type']);
    }

    public function test_case3_unknown_barcode_confirmation_creates_product_asset_and_custody_together(): void
    {
        $this->seedCore();
        $emp = $this->worker(['assets' => ['v' => 1]]);

        // ما خزّنه الاستكشافُ في كاش الخادم يُختم مصدراً على المنتج — لا ثقة من المتصفح
        IdentityLookup::create(['norm' => '4006381333931', 'status' => 'found',
            'result' => ['name' => 'Stabilo Boss', 'score' => 91,
                'confidence' => ['name' => 91], 'sources' => ['name' => ['upcitemdb']]],
            'providers' => [['key' => 'upcitemdb', 'label' => 'UPCitemdb', 'ok' => true, 'why' => '']],
            'checked_at' => now(), 'created_at' => now(), 'updated_at' => now(), 'hits' => 0,
            'id' => (string) Str::uuid()]);

        $this->actingAs($this->owner)->post('/identity/register', [
            'name' => 'Stabilo Boss', 'brand' => 'Stabilo', 'type' => 'أخرى',
            'barcode' => '4006381333931', 'qty' => 1, 'holder_id' => $emp->id,
        ])->assertRedirect();

        $p = Product::where('barcode', '4006381333931')->firstOrFail();
        $this->assertSame('LYN-PRD-00000001', $p->code);
        $this->assertSame('بحاجة مراجعة', $p->status, 'ما جاء من الاستكشاف يُراجَع قبل التوثيق');
        $this->assertSame(91, data_get($p->meta, 'discovery.score'), 'المصدرُ والثقةُ خُتما من كاش الخادم');

        $a = Asset::where('product_id', $p->id)->firstOrFail();
        $this->assertSame($emp->id, $a->holder_id);

        // ومسحُ الباركود بعدها يجد الطراز داخلياً — النظامُ صار مصدرَ نفسه
        $this->assertSame('product', Identity::resolve('4006381333931')['type']);
    }

    public function test_case4_manual_registration_when_nobody_knows_the_barcode(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/identity/register', [
            'name' => 'جهاز حضور بصمة', 'type' => 'أخرى', 'barcode' => '', 'qty' => 1,
        ])->assertRedirect();

        $p = Product::where('name', 'جهاز حضور بصمة')->firstOrFail();
        $this->assertSame('غير موثّق', $p->status, 'اليدويُّ الصرف غير موثّق — لا ادّعاء مصدر');
        $this->assertNull(data_get($p->meta, 'discovery'));
        $this->assertSame(1, Asset::where('product_id', $p->id)->count());
    }

    public function test_bulk_registration_binds_each_serial_to_its_own_asset(): void
    {
        $this->seedCore();
        $p = Product::create(['name' => 'شاشة Dell P2422H', 'type' => 'شاشة']);

        $this->actingAs($this->owner)->post('/identity/register', [
            'product_id' => $p->id, 'qty' => 3,
            'serials' => ['MON-A1', 'MON-A2', 'MON-A3'],
        ])->assertRedirect();

        $assets = Asset::where('product_id', $p->id)->orderBy('code')->get();
        $this->assertCount(3, $assets);
        // كلُّ قطعةٍ بكودٍ فريدٍ وسيريالها الملتصق بترتيبه — على **كل** الصفوف
        $this->assertSame(['MON-A1', 'MON-A2', 'MON-A3'], $assets->pluck('serial')->sort()->values()->all());
        $this->assertSame(3, $assets->pluck('code')->unique()->count(), 'لا كودَ تكرر في الدفعة');
        foreach ($assets as $a) {
            $this->assertSame('asset', Identity::resolve($a->serial)['type']);
        }

        // والدفعة فوق السقف تُرفض تحققاً لا صمتاً
        $this->actingAs($this->owner)->post('/identity/register', [
            'product_id' => $p->id, 'qty' => Identity::BULK_MAX + 1,
        ])->assertSessionHasErrors('qty');
    }

    /* ────────── ٢) الذرّية ────────── */

    public function test_a_failing_custody_step_rolls_back_the_product_and_the_assets(): void
    {
        $this->seedCore();
        $before = [Product::count(), Asset::count(), AssetCustody::count(), RecordIdentifier::count()];

        // حائزٌ محذوف بين التحقق والتنفيذ — أقرب محاكاةٍ لفشل الخطوة الأخيرة:
        // نمرّر حائزاً ثم نجعله يفشل بحذف المستخدم بعد التحقق عبر hook الحفظ.
        $emp = $this->worker(['assets' => ['v' => 1]]);
        \Illuminate\Support\Facades\Event::listen('eloquent.created: ' . AssetCustody::class,
            fn () => throw new \RuntimeException('فشل مفتعل بعد إنشاء القيد'));

        try {
            $this->actingAs($this->owner)->withoutExceptionHandling()->post('/identity/register', [
                'name' => 'منتج سيفشل', 'type' => 'أخرى', 'qty' => 1, 'holder_id' => $emp->id,
            ]);
            $this->fail('كان يجب أن يفشل');
        } catch (\RuntimeException) {
        }

        $this->assertSame($before, [Product::count(), Asset::count(),
            AssetCustody::count(), RecordIdentifier::count()],
            'فشلُ الخطوة الأخيرة أرجع المنتجَ والقطعةَ والمعرفاتِ كلَّها — لا يتيم');
    }

    /* ────────── ٣) التزامن على المجهول نفسه ────────── */

    public function test_two_workers_registering_the_same_new_gtin_end_with_one_product(): void
    {
        $this->seedCore();
        $w = $this->worker(['assets' => ['v' => 1, 'a' => 1, 'e' => 1], 'products' => ['v' => 1, 'a' => 1]]);

        $payload = ['name' => 'HP LaserJet Pro', 'type' => 'طابعة', 'barcode' => '6291041500213', 'qty' => 1];
        $this->actingAs($this->owner)->post('/identity/register', $payload)->assertRedirect();
        // «متزامنان»: الثاني وصل والمنتجُ قد أُنشئ للتو — الفهرسُ الفريد هو الحكم
        $this->actingAs($w)->post('/identity/register', $payload)->assertRedirect();

        $this->assertSame(1, Product::where('barcode', '6291041500213')->count(), 'منتجٌ واحدٌ لا اثنان');
        $p = Product::where('barcode', '6291041500213')->first();
        $this->assertSame(2, Asset::where('product_id', $p->id)->count(), 'وقطعتان عليه');
        $this->assertSame(2, Asset::where('product_id', $p->id)->pluck('code')->unique()->count());
    }

    /* ────────── ٤) الصلاحيات والملصقات ────────── */

    public function test_every_door_checks_its_own_permission(): void
    {
        $this->seedCore();
        $p = Product::create(['name' => 'منتج محمي', 'type' => 'أخرى']);
        $viewer = $this->worker(['assets' => ['v' => 1], 'products' => ['v' => 1]]);

        // بلا assets.a: لا تسجيل — وبلا products.a: لا إنشاء منتجٍ من التسجيل
        $this->actingAs($viewer)->post('/identity/register', ['product_id' => $p->id])->assertForbidden();
        $creator = $this->worker(['assets' => ['v' => 1, 'a' => 1], 'products' => ['v' => 1]]);
        $this->actingAs($creator)->post('/identity/register', ['name' => 'جديد', 'type' => 'أخرى'])
            ->assertForbidden();

        // وبلا assets.e: التسجيل يمرّ والإسنادُ يُرفض
        $emp = $this->worker(['assets' => ['v' => 1]]);
        $this->actingAs($creator)->post('/identity/register',
            ['product_id' => $p->id, 'holder_id' => $emp->id])->assertForbidden();
        $this->actingAs($creator)->post('/identity/register', ['product_id' => $p->id])->assertRedirect();

        // المركز يفتح لقارئ الأصول، ويُحجب عمّن لا يرى شيئاً
        $this->actingAs($viewer)->get('/identity')->assertOk();
        $none = $this->worker(['tasks' => ['v' => 1]]);
        $this->actingAs($none)->get('/identity')->assertForbidden();
        $this->actingAs($none)->post('/identity/discover', ['q' => '6291041500213'])->assertForbidden();
    }

    public function test_labels_print_singly_and_in_bulk_with_code128(): void
    {
        $this->seedCore();
        $p = Product::create(['name' => 'HPE ProLiant DL380', 'brand' => 'HPE', 'type' => 'سيرفر']);
        $this->actingAs($this->owner)->post('/identity/register',
            ['product_id' => $p->id, 'qty' => 2, 'serials' => ['CZ-1', 'CZ-2']]);
        $assets = Asset::where('product_id', $p->id)->get();

        // ملصق المنتج: كودُه برمزيه — QR وCode128
        $this->actingAs($this->owner)->get('/identity/product/' . $p->id . '/label')
            ->assertOk()->assertSee($p->code)->assertSee('viewBox', false)
            ->assertSee('shape-rendering="crispEdges"', false);

        // الدفعة: كل الأكواد في ورقةٍ واحدة
        $sheet = $this->actingAs($this->owner)
            ->get('/identity/labels?ids=' . $assets->pluck('id')->implode(','))
            ->assertOk();
        foreach ($assets as $a) {
            $sheet->assertSee($a->code);
        }

        // صفحة المنتج تعرض بطاقة الهوية والقطعتين
        $this->actingAs($this->owner)->get('/m/products/' . $p->id)
            ->assertOk()->assertSee('بطاقة الهوية')->assertSee('القطع المملوكة')
            ->assertSee($assets[0]->code)->assertSee($assets[1]->code);

        // وكتالوج العهد يحمل شاشة المسح
        $this->actingAs($this->owner)->get('/custody')
            ->assertOk()->assertSee('امسح أو أدخل أي معرّف');
    }

    public function test_scanning_a_product_label_link_opens_the_product(): void
    {
        $this->seedCore();
        $p = Product::create(['name' => 'منتج ملصق', 'type' => 'أخرى']);

        $this->actingAs($this->owner)->get('/p/' . $p->code)
            ->assertRedirect(route('m.show', ['products', $p->id]));
        $this->actingAs($this->owner)->get('/p/UNKNOWN-CODE')->assertNotFound();
    }
}
