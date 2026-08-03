<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\User;
use App\Support\AssetLife;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * حقول الأصل الأغلى ثمناً كانت **ديكوراً**: الثمن والضمان والعمر الافتراضي
 * وموعد الصيانة وتاريخ الاستبعاد تُملأ ولا يقرؤها أحد. فالنتيجة: جهازٌ انتهى
 * ضمانه أمس ويُدفع إصلاحه من الجيب، وجهازٌ صُرف على صيانته أكثر من ثمنه ولا
 * أحد جمع فواتيره، وموظفٌ خرج وعهدتُه باسمه، وأصلٌ «تالف» يُحسَب في الجرد.
 */
class AssetLifeTest extends TestCase
{
    protected function asset(array $a = []): Asset
    {
        return Asset::create(array_merge([
            'name' => 'لابتوب', 'type' => 'لابتوب', 'status' => 'قيد الاستخدام',
        ], $a));
    }

    protected function maint(string $assetId, array $a = []): void
    {
        DB::table('asset_maintenance')->insert(array_merge([
            'id' => (string) Str::uuid(), 'asset_id' => $assetId, 'title' => 'إصلاح',
            'kind' => 'إصلاح عطل', 'date' => now()->toDateString(), 'status' => 'مكتملة',
            'created_at' => now(), 'updated_at' => now(),
        ], $a));
    }

    /** الفواتير متفرّقة فلا تُرى إلا مجموعة */
    public function test_maintenance_spend_is_summed_against_the_price(): void
    {
        $this->seedCore();
        $a = $this->asset(['name' => 'جهاز مكلف', 'price' => 1000]);
        foreach ([300, 400, 200] as $c) $this->maint($a->id, ['cost' => $c]);

        $b = $this->asset(['name' => 'جهاز سليم', 'price' => 1000]);
        $this->maint($b->id, ['cost' => 50]);

        $this->actingAs($this->owner);
        $r = collect(AssetLife::replace());

        $this->assertSame(1, $r->count(), 'جهازٌ صيانته ٥٪ عُرض كأنه يستحق الإحلال');
        $this->assertSame('جهاز مكلف', $r->first()['name']);
        $this->assertSame(900.0, $r->first()['spent']);
        $this->assertSame(90, $r->first()['pct']);
        $this->assertSame(3, $r->first()['times']);
    }

    /** وإذا تجاوزت الصيانة الثمن كله فالحكم أصرح */
    public function test_spending_more_than_the_price_is_named_plainly(): void
    {
        $this->seedCore();
        $a = $this->asset(['price' => 500]);
        $this->maint($a->id, ['cost' => 700]);

        $this->actingAs($this->owner);
        $this->assertStringContainsString('أكثر من ثمنه', AssetLife::replace()[0]['verdict']);
    }

    /** عهدةٌ باسم من لم يعد يعمل */
    public function test_custody_held_by_an_inactive_account_is_flagged(): void
    {
        $this->seedCore();
        $gone = User::create(['name' => 'موظف سابق', 'email' => 'ex@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'موقوف', 'password_changed_at' => now()]);

        $this->asset(['name' => 'لابتوب المغادر', 'holder_id' => $gone->id, 'price' => 800]);
        $this->asset(['name' => 'لابتوب نشط', 'holder_id' => $this->employee->id, 'price' => 500]);

        $this->actingAs($this->owner);
        $c = collect(AssetLife::custody());

        $first = $c->first();
        $this->assertTrue($first['gone'], 'عهدةُ من لم يعد نشطاً لم تُرفع إلى الأعلى');
        $this->assertSame('موظف سابق', $first['name']);
        $this->assertFalse($c->firstWhere('name', 'موظفة')['gone']);
    }

    /** الضمان: ما انتهى وما يوشك */
    public function test_warranty_expiry_is_surfaced_with_its_consequence(): void
    {
        $this->seedCore();
        $this->asset(['name' => 'جهاز بلا ضمان', 'warranty' => now()->subDays(10)->toDateString()]);
        $this->asset(['name' => 'جهاز يوشك', 'warranty' => now()->addDays(20)->toDateString()]);
        $this->asset(['name' => 'جهاز مضمون', 'warranty' => now()->addYear()->toDateString()]);

        $this->actingAs($this->owner);
        $f = collect(AssetLife::flags());

        $this->assertSame(1, $f->where('kind', 'ضمانٌ منتهٍ')->count());
        $this->assertSame(1, $f->where('kind', 'ضمانٌ ينتهي قريباً')->count());
        $this->assertStringContainsString('على حساب الشركة',
            $f->firstWhere('kind', 'ضمانٌ منتهٍ')['why']);
    }

    /** صيانةٌ مجدولة فات موعدها */
    public function test_an_overdue_scheduled_maintenance_is_flagged(): void
    {
        $this->seedCore();
        $a = $this->asset(['name' => 'سيرفر']);
        $this->maint($a->id, ['title' => 'فحص دوري', 'status' => 'مجدولة',
            'next' => now()->subDays(12)->toDateString()]);

        $this->actingAs($this->owner);
        $hit = collect(AssetLife::flags())->firstWhere('kind', 'صيانة فات موعدها');

        $this->assertNotNull($hit);
        $this->assertStringContainsString('سيرفر', $hit['title']);
        $this->assertStringContainsString('12', $hit['why']);
    }

    /** أصلٌ تجاوز عمره الافتراضي */
    public function test_an_asset_past_its_useful_life_is_flagged(): void
    {
        $this->seedCore();
        $this->asset(['name' => 'جهاز قديم', 'life' => 3, 'buy_date' => now()->subYears(5)->toDateString()]);
        $this->asset(['name' => 'جهاز حديث', 'life' => 5, 'buy_date' => now()->subYear()->toDateString()]);

        $this->actingAs($this->owner);
        $f = collect(AssetLife::flags())->where('kind', 'تجاوز عمره الافتراضي');

        $this->assertSame(1, $f->count());
        $this->assertSame('جهاز قديم', $f->first()['title']);
    }

    /** «تالف» بلا تاريخ استبعاد: أصلٌ ميتٌ يُحسَب حيّاً */
    public function test_a_dead_asset_without_a_disposal_date_is_bad(): void
    {
        $this->seedCore();
        $this->asset(['name' => 'شاشة محترقة', 'status' => 'تالف', 'price' => 300]);
        $this->asset(['name' => 'شاشة مستبعدة', 'status' => 'مستبعد',
            'disposal' => now()->subMonth()->toDateString()]);

        $this->actingAs($this->owner);
        $f = collect(AssetLife::flags())->where('kind', 'خارج الخدمة بلا استبعاد');

        $this->assertSame(1, $f->count());
        $this->assertSame('bad', $f->first()['tone']);
        $this->assertStringContainsString('300', $f->first()['why']);
    }

    /** قيد الاستخدام بلا حائز: مسؤوليةٌ بلا صاحب */
    public function test_an_asset_in_use_without_a_holder_is_flagged(): void
    {
        $this->seedCore();
        $this->asset(['name' => 'طابعة يتيمة']);

        $this->actingAs($this->owner);
        $this->assertNotNull(collect(AssetLife::flags())->firstWhere('kind', 'قيد الاستخدام بلا حائز'));
    }

    /** الشاشة خلف صلاحية الأصول */
    public function test_the_screen_is_behind_the_assets_permission(): void
    {
        $this->seedCore();
        $this->asset(['name' => 'شاشة محترقة', 'status' => 'تالف']);

        $this->actingAs($this->owner)->get('/assets-life')->assertOk()
            ->assertSee('العهدة ودورة الحياة')->assertSee('شاشة محترقة');

        $role = $this->employee->role;
        $m = $role->matrix;
        $m['assets'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role->update(['matrix' => $m]);

        $this->actingAs($this->employee->fresh())->get('/assets-life')->assertForbidden();
    }
}
