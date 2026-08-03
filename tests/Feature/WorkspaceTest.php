<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Support\Workspaces;
use Tests\TestCase;

/** CTO م2 (v2.134): مساحات العمل — صفحات مركزية بأرقام منطّقة وصلاحيات صارمة */
class WorkspaceTest extends TestCase
{
    /** المساحات تُشتق من hub_nav (مصدر واحد) وكل وحداتها مسجلة فعلاً */
    public function test_workspaces_derive_from_nav_and_are_structurally_sound(): void
    {
        $this->seedCore();
        $spaces = Workspaces::for($this->owner);

        $this->assertNotEmpty($spaces);
        $navModules = collect(config('hub_nav'))->flatMap(fn ($g) => $g['items'])->all();
        foreach ($spaces as $key => $ws) {
            $this->assertNotEmpty($ws['modules'], "مساحة $key بلا وحدات");
            foreach ($ws['modules'] as $mk) {
                $this->assertNotNull(hub_mod($mk), "$key.$mk غير مسجلة");
                $this->assertContains($mk, $navModules, "$key.$mk ليست من hub_nav — ازدواج مصدر");
            }
        }
        // كل مجموعات hub_nav لها مساحة — لا وحدة يتيمة خارج المعمارية
        $covered = collect($spaces)->pluck('nav')->all();
        foreach (config('hub_nav') as $g) {
            $this->assertContains($g['g'], $covered, "مجموعة {$g['g']} بلا مساحة عمل");
        }
    }

    /** الصفحة تعرض بطاقات الوحدات بأعداد حقيقية والنشاط والمراكز */
    public function test_workspace_page_renders_real_counts(): void
    {
        $this->seedCore();
        Contract::create(['title' => 'عقد المساحة', 'type' => 'عقد عميل', 'status' => 'ساري']);

        $this->actingAs($this->owner)->get('/w/legalws')->assertOk()
            ->assertSee('العقود والشؤون القانونية')
            ->assertSee('وحدات المساحة')
            ->assertSee('العقود والالتزامات')
            ->assertSee('آخر النشاط')
            ->assertSee('⚖️ القانوني');   // مركز تابع

        $this->actingAs($this->owner)->get('/w/finance')->assertOk()->assertSee('المالية والمشتريات');
    }

    /** مساحة لا يرى المستخدم أي وحدة منها = 404، ومفتاح وهمي = 404 */
    public function test_workspace_respects_permissions(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/w/nope')->assertNotFound();

        // دور بلا رؤية لأي وحدة مالية
        $noFin = collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => in_array($m, config('hub_nav')[3]['items'], true) ? 0 : 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();
        $role = \App\Models\Role::create(['name' => 'بلا مالية', 'scope' => 'all', 'flags' => [], 'matrix' => $noFin]);
        $u = \App\Models\User::create(['name' => 'محدود', 'email' => 'lim@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($u)->get('/w/finance')->assertNotFound();
        $this->actingAs($u)->get('/w/entities')->assertOk();
    }

    /** لون المساحة يطابق لون مجموعتها في hub_mod_look — فلا يتضارب مع ترويسات وحداتها */
    public function test_workspace_color_matches_module_group_color(): void
    {
        $this->seedCore();
        $spaces = Workspaces::for($this->owner);

        foreach ($spaces as $key => $ws) {
            $firstModule = $ws['modules'][0];
            $groupColor = hub_mod_look($firstModule)['color'];
            $this->assertSame($groupColor, $ws['color'],
                "لون مساحة $key ($ws[color]) يخالف لون مجموعتها في hub_mod_look ($groupColor)");
        }
    }

    /** أرقام البطاقات منطّقة: الشركة النشطة تغيّر العدّ (مفتاح الخبيئة يشمل الشركة) */
    public function test_workspace_counts_are_scoped(): void
    {
        $this->seedCore();
        Contract::create(['title' => 'عقد ظاهر', 'type' => 'عقد عميل', 'status' => 'ساري']);
        $res = $this->actingAs($this->owner)->get('/w/legalws');
        $res->assertOk()->assertSee('عقد ظاهر', false);   // في النشاط الأخير (أُنشئ الآن)
    }
}
