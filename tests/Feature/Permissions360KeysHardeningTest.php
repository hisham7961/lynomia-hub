<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\AssetProjectController;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Route as RouteFacade;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * **تصليبٌ متفرّق** (Permissions 360 · م2 · 07.6 · 01.6 · 17.4).
 *
 * 07.6 — ربطُ الأصلِ بالمشروعِ يُفتَح لمفتاحِ `projects:assetAssign` دون رايةِ `assets:e`.
 * 01.6 — إنشاءُ دورٍ حسّاسٍ يتطلّب تصعيدَ الهويّة كتعديلِه (لا سكَّ دورٍ خطرٍ بلا تأكيد).
 * 17.4 — مسارا الاستيرادِ (map/run) خلفَ حدِّ معدّلٍ كالتصدير والجماعيّ.
 */
class Permissions360KeysHardeningTest extends TestCase
{
    private function user(string $email, array $matrix, array $flags = [], bool $owner = false): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => $flags,
            'matrix' => $matrix, 'is_owner' => $owner]);

        return User::create(['name' => 'مستخدم', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /* ═══════════ 07.6 — مفتاحُ ربطِ الأصولِ يفتحُ البوّابة ═══════════ */

    public function test_asset_assign_key_opens_gate_without_assets_edit(): void
    {
        $this->seedCore();

        // منسّقُ مشروعٍ: يملكُ projects:assetAssign فقط (لا assets:e)
        $coord = $this->user('coord@test.local', ['projects' => ['v' => 1, 'assetAssign' => 1]]);
        $this->actingAs($coord);
        $ref = new \ReflectionMethod(AssetProjectController::class, 'gate');
        $ref->setAccessible(true);
        $ref->invoke(new AssetProjectController());   // لا يرمي
        $this->assertTrue(true, 'مفتاحُ ربطِ الأصولِ يفتحُ بوّابةَ التخصيص');

        // من لا يملكُ لا assets:e ولا assetAssign ⇒ ٤٠٣
        $viewer = $this->user('pview@test.local', ['projects' => ['v' => 1]]);
        $this->actingAs($viewer);
        try {
            $ref->invoke(new AssetProjectController());
            $this->fail('قارئٌ بلا مفتاحٍ اجتازَ بوّابةَ تخصيصِ الأصول');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /* ═══════════ 01.6 — تصعيدُ الهويّة عند إنشاءِ دورٍ حسّاس ═══════════ */

    public function test_role_store_requires_stepup_for_risky_flag(): void
    {
        $this->seedCore();

        // بلا تصعيدٍ: إنشاءُ دورٍ يحمل رايةً حسّاسة (users) يُعادُ توجيهُه ولا يُحفَظ
        $this->actingAs($this->owner)
            ->post(route('roles.store'), ['name' => 'دورٌ خطرٌ بلا تصعيد', 'scope' => 'all', 'flags' => ['users' => 1]])
            ->assertRedirect();
        $this->assertFalse(Role::where('name', 'دورٌ خطرٌ بلا تصعيد')->exists(),
            'الدورُ الحسّاسُ لم يُحفَظ بلا تصعيدِ هويّة');

        // مع تصعيدٍ طازج: يُحفَظ
        $this->actingAs($this->owner)
            ->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp])
            ->post(route('roles.store'), ['name' => 'دورٌ خطرٌ بتصعيد', 'scope' => 'all', 'flags' => ['users' => 1]]);
        $this->assertTrue(Role::where('name', 'دورٌ خطرٌ بتصعيد')->exists(),
            'الدورُ الحسّاسُ يُحفَظ بعد تصعيدِ الهويّة');
    }

    public function test_role_store_plain_role_needs_no_stepup(): void
    {
        $this->seedCore();

        // دورٌ بلا رايةٍ حسّاسة ⇒ يُحفَظ دون تصعيد (لا يُثقَل العملُ اليوميّ)
        $this->actingAs($this->owner)
            ->post(route('roles.store'), ['name' => 'دورُ عرضٍ بسيط', 'scope' => 'all', 'matrix' => ['clients' => ['v' => 1]]]);
        $this->assertTrue(Role::where('name', 'دورُ عرضٍ بسيط')->exists(),
            'الدورُ البسيطُ يُحفَظ بلا تصعيد');
    }

    /* ═══════════ 17.4 — حدُّ المعدّلِ على مسارِ الاستيراد ═══════════ */

    public function test_import_routes_are_rate_limited(): void
    {
        foreach (['m.import.map', 'm.import.run'] as $name) {
            $route = RouteFacade::getRoutes()->getByName($name);
            $this->assertNotNull($route, "المسارُ {$name} موجود");
            $mw = $route->gatherMiddleware();
            $this->assertTrue(
                (bool) array_filter($mw, fn ($m) => is_string($m) && str_starts_with($m, 'throttle')),
                "المسارُ {$name} خلفَ حدِّ معدّل"
            );
        }
    }
}
