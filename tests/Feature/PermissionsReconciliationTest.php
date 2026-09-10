<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\RoleController;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionInspector as PI;
use Tests\TestCase;

/**
 * **مطابقةُ الصلاحيّات والرؤية (Permissions Reconciliation).** يُثبّت أنّ الرؤيةَ اشتقاقٌ من
 * التوثيق: كلُّ وحدةٍ مُنِح عرضُها تُكتشَف، وما لم يُمنَح يختفي، وحدُّ حساب العميل يعلو، والمُفسِّر
 * يشرح كلَّ قرار. يحرس العيبَ المُبلَّغ (apps مقابل projects) بنيويّاً.
 */
class PermissionsReconciliationTest extends TestCase
{
    private function employeeWith(array $matrix, array $flags = []): User
    {
        $role = Role::create(['name' => 'دور اختبار ' . \Illuminate\Support\Str::random(4),
            'scope' => 'all', 'flags' => $flags, 'matrix' => $matrix]);

        return User::create(['name' => 'موظف', 'email' => \Illuminate\Support\Str::random(9) . '@t.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    private function clientWith(array $matrix): User
    {
        $role = Role::create(['name' => 'دور عميل ' . \Illuminate\Support\Str::random(4),
            'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => 'عميل', 'email' => \Illuminate\Support\Str::random(9) . '@c.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
    }

    /* ═══════════ المُفسِّر (§12/§59) ═══════════ */

    /** موظفٌ داخليّ: العرضُ مسموحٌ بالمنح، والكتابةُ ممنوعةٌ بلا منحِها، وusers محكومةٌ بعلَم */
    public function test_inspector_explains_internal_employee(): void
    {
        $this->seedCore();
        $u = $this->employeeWith(['apps' => ['v' => 1], 'projects' => ['v' => 1, 'e' => 1]]);

        $this->assertTrue(PI::explain($u, 'apps', 'v')['allowed']);
        $this->assertSame('ALLOWED', PI::explain($u, 'apps', 'v')['state']);
        $this->assertFalse(PI::explain($u, 'apps', 'a')['allowed']);
        $this->assertSame('DENIED_ROLE', PI::explain($u, 'apps', 'a')['state']);
        $this->assertTrue(PI::explain($u, 'projects', 'e')['allowed']);
        $this->assertFalse(PI::explain($u, 'fin', 'v')['allowed']);
        $this->assertSame('FLAG_GOVERNED', PI::explain($u, 'users', 'v')['state']);
    }

    /** المالكُ يتجاوز المصفوفةَ كلَّها */
    public function test_inspector_owner_allows_everything(): void
    {
        $this->seedCore();
        $r = PI::explain($this->owner, 'apps', 'd');
        $this->assertTrue($r['allowed']);
        $this->assertSame('OWNER', $r['state']);
    }

    /** حدُّ حساب العميل يعلو على مصفوفةٍ ملوّثة (§37/§73/§109) — العيبُ المُبلَّظ مُفسَّراً */
    public function test_inspector_client_boundary_overrides_polluted_matrix(): void
    {
        $this->seedCore();
        // عميلٌ مُنِح داخليّاتٍ خطأً في المصفوفة
        $c = $this->clientWith(['apps' => ['v' => 1], 'servers' => ['v' => 1], 'projects' => ['v' => 1]]);

        $this->assertTrue(PI::explain($c, 'projects', 'v')['allowed'], 'projects ضمن قائمة البوّابة');
        $this->assertFalse(PI::explain($c, 'apps', 'v')['allowed'], 'apps داخليٌّ — يُمنع للعميل');
        $this->assertSame('DENIED_CLIENT', PI::explain($c, 'apps', 'v')['state']);
        $this->assertFalse(PI::explain($c, 'servers', 'v')['allowed']);
        $this->assertSame('DENIED_CLIENT', PI::explain($c, 'servers', 'v')['state']);
    }

    /* ═══════════ العيبُ المُبلَّغ: apps مقابل projects (§97/§98) ═══════════ */

    /** التطبيقات تتبع العرضَ تماماً كالمشاريع: مُنِحت ⇒ مرئيّة، لا كتابة؛ نُزِعت ⇒ مخفيّة */
    public function test_applications_visibility_follows_view_permission(): void
    {
        $this->seedCore();
        // View فقط
        $u = $this->employeeWith(['apps' => ['v' => 1]]);
        $nav = PI::navigation($u);
        $this->assertContains('apps', array_column($nav['visible'], 'key'), 'apps مخفيٌّ رغم منح العرض');
        $this->assertTrue(PI::allows($u, 'apps', 'v'));
        $this->assertFalse(PI::allows($u, 'apps', 'a'));
        $this->assertFalse(PI::allows($u, 'apps', 'e'));
        $this->assertFalse(PI::allows($u, 'apps', 'd'));

        // نُزِع العرض ⇒ يختفي
        $u2 = $this->employeeWith(['projects' => ['v' => 1]]);   // apps غيرُ ممنوح
        $nav2 = PI::navigation($u2);
        $this->assertNotContains('apps', array_column($nav2['visible'], 'key'));
        $this->assertContains('apps', array_column($nav2['hidden'], 'key'));
    }

    /** تكافؤُ المشاريع والتطبيقات (§98): ثلاثةُ أدوارٍ — كلاهما/مشاريعٌ فقط/تطبيقاتٌ فقط */
    public function test_projects_and_applications_parity(): void
    {
        $this->seedCore();

        $both = PI::navigation($this->employeeWith(['projects' => ['v' => 1], 'apps' => ['v' => 1]]));
        $bk = array_column($both['visible'], 'key');
        $this->assertContains('projects', $bk);
        $this->assertContains('apps', $bk);

        $projOnly = PI::navigation($this->employeeWith(['projects' => ['v' => 1]]));
        $pk = array_column($projOnly['visible'], 'key');
        $this->assertContains('projects', $pk);
        $this->assertNotContains('apps', $pk);

        $appsOnly = PI::navigation($this->employeeWith(['apps' => ['v' => 1]]));
        $ak = array_column($appsOnly['visible'], 'key');
        $this->assertContains('apps', $ak);
        $this->assertNotContains('projects', $ak);
    }

    /* ═══════════ مطابقةٌ لكلِّ الوحدات (§31/§79/§103) ═══════════ */

    /** مُنِح عرضُ **كلِّ** وحدة ⇒ كلُّها مرئيّةٌ (عدا users المحكومةِ بعلَم) — لا وحدةٌ تُمنَح فتُخفى */
    public function test_every_module_is_discoverable_when_view_granted(): void
    {
        $this->seedCore();
        $all = collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => 1]])->all();
        $u = $this->employeeWith($all);

        $visible = array_column(PI::navigation($u)['visible'], 'key');
        $expected = array_values(array_diff(array_keys(config('hub.modules')), ['users']));

        sort($visible); sort($expected);
        $this->assertSame($expected, $visible, 'وحدةٌ مُنِح عرضُها لم تُكتشَف (granted-view-but-hidden)');
    }

    /** لا منحَ ⇒ لا وحدةٌ مرئيّة (denied-view-but-visible = 0) */
    public function test_no_module_visible_without_grant(): void
    {
        $this->seedCore();
        $u = $this->employeeWith([]);   // مصفوفةٌ فارغة
        $this->assertSame([], array_column(PI::navigation($u)['visible'], 'key'));
    }

    /* ═══════════ محرّرُ الأدوار: كلُّ وحدةٍ إنسانيّةٍ حاضرة (§16) ═══════════ */

    public function test_role_editor_covers_every_module_except_users(): void
    {
        $inEditor = [];
        foreach (RoleController::groupedModules() as $g) {
            foreach (array_keys($g['items']) as $k) $inEditor[$k] = 1;
        }
        $expected = array_values(array_diff(array_keys(config('hub.modules')), ['users']));
        $got = array_keys($inEditor);
        sort($expected); sort($got);
        $this->assertSame($expected, $got, 'وحدةٌ إنسانيّةٌ غائبةٌ عن محرّر الأدوار');
    }

    /* ═══════════ سلامةُ مفاتيح الصلاحيّة (§28) ═══════════ */

    public function test_no_unknown_module_keys_in_editor(): void
    {
        foreach (RoleController::groupedModules() as $g) {
            foreach (array_keys($g['items']) as $k) {
                $this->assertNotNull(hub_mod($k), "مفتاحُ وحدةٍ غيرُ معروفٍ في المحرّر: {$k}");
            }
        }
    }

    /* ═══════════ شاشةُ تشخيصِ الوصول (§59/§86) ═══════════ */

    public function test_access_diagnostics_is_owner_only(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get(route('access.index'))->assertOk();
        // موظفٌ داخليٌّ غيرُ مالك ⇒ ٤٠٣ (حارسُ المالك)
        $this->actingAs($this->employee)->get(route('access.index'))->assertForbidden();

        // حسابُ عميلٍ ⇒ ٤٠٤ من PortalGuard (لا كشفَ وجودٍ) قبلَ بلوغِ حارسِ المتحكّم
        $client = $this->clientWith(['projects' => ['v' => 1]]);
        $this->actingAs($client)->get(route('access.index'))->assertNotFound();
    }

    public function test_access_diagnostics_renders_effective_matrix_and_probe(): void
    {
        $this->seedCore();
        $u = $this->employeeWith(['apps' => ['v' => 1]]);

        $html = $this->actingAs($this->owner)
            ->get(route('access.index', ['user' => $u->id, 'module' => 'fin', 'op' => 'v']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('الصلاحيّة الفعّالة', $html);
        $this->assertStringContainsString('ممنوع', $html, 'فحصُ fin.v لم يُظهر المنعَ وسببَه');
    }

    /* ═══════════ سلامةُ المصفوفة: الكتابةُ تستلزم العرض (§57/§58) ═══════════ */

    /** حفظُ دورٍ بعمليّةِ كتابةٍ بلا عرضٍ يُفعّل العرضَ تلقائيّاً (لا حالةٌ متناقضةٌ تُحفَظ) */
    public function test_saving_mutation_without_view_auto_enables_view(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp])
            ->post(route('roles.store'), [
                'name' => 'دور كتابةٍ بلا عرض', 'scope' => 'all', 'matrix_submitted' => 1,
                'matrix' => ['apps' => ['e' => 1]],   // تعديلٌ بلا عرض
            ])->assertRedirect();

        $role = Role::where('name', 'دور كتابةٍ بلا عرض')->firstOrFail();
        $this->assertSame(1, (int) ($role->matrix['apps']['v'] ?? 0), 'العرضُ لم يُفعَّل تلقائيّاً مع الكتابة');
        $this->assertSame(1, (int) ($role->matrix['apps']['e'] ?? 0));
    }

    /** قوالبُ الأدوار سليمةٌ: لا مفتاحَ وحدةٍ معدوم، ولا كتابةٌ بلا عرضٍ بعد التطبيق (§28/§58/§82) */
    public function test_role_templates_are_integrity_clean(): void
    {
        $mods = array_keys(config('hub.modules'));
        foreach ((array) config('hub_roles.templates', []) as $tk => $t) {
            foreach ((array) ($t['mods'] ?? []) as $m => $letters) {
                $this->assertContains($m, $mods, "قالب {$tk}: مفتاحُ وحدةٍ معدوم {$m}");
            }
            $applied = RoleController::fromTemplate($tk)['matrix'] ?? [];
            foreach ($applied as $m => $row) {
                if (array_intersect(['a', 'e', 'd'], array_keys(array_filter($row)))) {
                    $this->assertNotEmpty($row['v'] ?? 0, "قالب {$tk}: كتابةٌ بلا عرضٍ على {$m}");
                }
            }
        }
    }

    public function test_access_role_preview_is_computed_without_creating_a_user(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'دور معاينة', 'scope' => 'all', 'flags' => [],
            'matrix' => ['apps' => ['v' => 1]]]);
        $before = User::count();

        $this->actingAs($this->owner)->get(route('access.role', $role))->assertOk()
            ->assertSee('apps');

        $this->assertSame($before, User::count(), 'معاينةُ الدورِ أنشأت مستخدماً — يجب أن تكون حساباً لا انتحالاً');
    }
}
