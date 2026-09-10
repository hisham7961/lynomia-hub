<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Company;
use App\Models\Project;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use App\Support\AssetProjectService;
use App\Support\Custody;
use App\Support\Station360;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **المحطة 360** (الكيان 360 · §19–27/§94) — تجميعٌ لا محرّكٌ ثانٍ: النظرةُ والأصولُ
 * وتاريخُها والمشروعُ (مباشرٌ ومشتقٌّ) — عزلٌ خادميٌّ (IDOR/عميل) وأمانةُ العدّ.
 */
class Station360Test extends TestCase
{
    private function station(?string $companyId = null, array $extra = []): Station
    {
        return Station::create(array_merge(['company_id' => $companyId, 'status' => 'متاحة'], $extra));
    }

    private function asset(?string $companyId = null, array $extra = []): Asset
    {
        return Asset::create(array_merge(['name' => 'أصل ' . Str::random(4),
            'type' => 'laptop', 'status' => 'نشط', 'company_id' => $companyId], $extra));
    }

    private function client(): User
    {
        $role = Role::create(['name' => 'عميل', 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        return User::create(['name' => 'عميل', 'email' => 'cl@t.local', 'account_type' => 'client',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    public function test_station_360_shows_assets_and_history(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $s = $this->station();
        $a = $this->asset();
        Custody::assignStation($a, $s->id, now()->toDateString());   // يضبط station_id + صفَّ تاريخ

        $this->get(route('m.show', ['stations', $s->id]))->assertOk()
            ->assertSee('💻 الأصول')                 // تبويبُ الأصول
            ->assertSee('أصولُ المحطة الحاليّة')
            ->assertSee($a->name);
    }

    public function test_current_assets_are_scoped(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $s = $this->station();
        $a = $this->asset();
        Custody::assignStation($a, $s->id, now()->toDateString());

        $rows = (new Station360)->currentAssets($s, $this->owner);
        $this->assertTrue($rows->pluck('id')->contains($a->id));
    }

    public function test_asset_history_comes_from_asset_custody_station_col(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $s = $this->station();
        $a = $this->asset();
        Custody::assignStation($a, $s->id, now()->toDateString());
        Custody::assignStation($a, null, now()->toDateString());     // إخلاء — يبقى في التاريخ

        $hist = (new Station360)->assetHistory($s, $this->owner);
        $this->assertTrue($hist->contains(fn ($r) => (string) $r->asset_id === (string) $a->id),
            'تاريخُ وضعِ الأصلِ عند المحطة غائبٌ عن `asset_custody.station_id`');
    }

    /* ═══════════ المشروع: مباشرٌ + مشتقٌّ موسوم (§27) ═══════════ */

    public function test_project_context_direct_and_derived(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $direct = Project::create(['name' => 'مشروعٌ مباشر']);
        $s = $this->station(null, ['project_id' => $direct->id]);
        $derived = Project::create(['name' => 'مشروعٌ مشتق']);
        $a = $this->asset();
        Custody::assignStation($a, $s->id, now()->toDateString());
        (new AssetProjectService)->assign($a, $derived, $this->owner);   // أصلٌ بالمحطة → مشروع

        $ctx = (new Station360)->projectContext($s, $this->owner);
        $this->assertSame((string) $direct->id, (string) $ctx['direct']['id']);
        $this->assertTrue($ctx['derived']->pluck('id')->contains($derived->id), 'المشروعُ المشتقُّ غائب');
        // المشتقُّ لا يكرّر المباشر
        $this->assertFalse($ctx['derived']->pluck('id')->contains($direct->id));
    }

    /* ═══════════ العزل (IDOR/عميل §73/§76) ═══════════ */

    public function test_cross_company_station_is_404_for_restricted_reader(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'أ']);
        $coB = Company::create(['name_ar' => 'ب']);
        $role = Role::create(['name' => 'مقيَّد', 'scope' => 'all', 'flags' => [],
            'matrix' => collect(array_keys(config('hub.modules')))->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all()]);
        $u = User::create(['name' => 'مقيَّد', 'email' => 'r@t.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(), 'companies' => [$coA->id]]);
        $sB = $this->station($coB->id);

        $this->actingAs($u)->get(route('m.show', ['stations', $sB->id]))->assertNotFound();
    }

    public function test_client_cannot_reach_station_360(): void
    {
        $this->seedCore();
        $s = $this->station();
        // البوّابةُ لا تُدرج stations في قائمة العميل → ٤٠٤ (لا كشفَ وجود)
        $this->actingAs($this->client())->get(route('m.show', ['stations', $s->id]))->assertNotFound();
    }

    public function test_count_safety_unauthorized_assets_not_counted(): void
    {
        // مستخدمٌ بلا صلاحيّة أصول: عدُّ الأصولِ null لا رقمٌ خام (§74)
        $this->seedCore();
        $role = Role::create(['name' => 'بلا أصول', 'scope' => 'all', 'flags' => [],
            'matrix' => ['stations' => ['v' => 1]]]);
        $u = User::create(['name' => 'بلا أصول', 'email' => 'na@t.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $s = $this->station();
        $a = $this->asset();
        $this->actingAs($this->owner);
        Custody::assignStation($a, $s->id, now()->toDateString());

        $ov = (new Station360)->overview($s, $u);
        $this->assertNull($ov['assets'], 'عدُّ أصولٍ ظهر لمن لا يملك عرضَ الأصول');
    }
}
