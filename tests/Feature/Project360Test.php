<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetProjectAssignment;
use App\Models\Client;
use App\Models\Company;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\AssetProjectService;
use App\Support\RelationshipProjection;
use Tests\TestCase;

/**
 * **§83/§85/§86 — المشروع 360 + تخصيصُ الأصولِ عبر الويب: عزلٌ وحسمٌ خادميّ.**
 *
 * تجميعٌ لا تكرار: المشروعُ 360 يُظهر قسمَ الأصول للداخليِّ المخوَّل، ويخفيه عن العميل؛
 * والتخصيصُ من الجهتين خادميُّ الحسم (IDOR/صلاحية/عميل)؛ والحافّةُ تظهر في المستكشف.
 */
class Project360Test extends TestCase
{
    private function project(?string $companyId = null, array $extra = []): Project
    {
        return Project::create(array_merge(['name' => 'مشروع ' . \Illuminate\Support\Str::random(4),
            'company_id' => $companyId], $extra));
    }

    private function asset(?string $companyId = null, array $extra = []): Asset
    {
        return Asset::create(array_merge(['name' => 'أصل ' . \Illuminate\Support\Str::random(4),
            'type' => 'laptop', 'status' => 'نشط', 'company_id' => $companyId], $extra));
    }

    private function client(): User
    {
        $role = Role::create(['name' => 'عميل', 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        return User::create(['name' => 'عميل', 'email' => 'cl@t.local', 'account_type' => 'client',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /* ═══════════ المشروع 360: قسمُ الأصولِ للداخليِّ المخوَّل ═══════════ */

    public function test_internal_project_360_shows_assets_section_for_authorized_user(): void
    {
        $this->seedCore();
        $p = $this->project();
        $a = $this->asset();
        (new AssetProjectService)->assign($a, $p, $this->owner, 'خادمُ مشروع');

        $this->actingAs($this->owner)->get(route('m.show', ['projects', $p->id]))->assertOk()
            ->assertSee('🖥️ الأصول')                    // تبويبُ الأصول
            ->assertSee('أصولُ المشروع')
            ->assertSee($a->name);                        // الأصلُ المخصَّصُ ظاهر
    }

    /* ═══════════ عزلُ العميل: لا قسمَ أصولٍ ولا عدَّها (§12/§70/§86) ═══════════ */

    public function test_client_viewing_project_never_sees_assets_section(): void
    {
        $this->seedCore();
        $company = Company::create(['name_ar' => 'شركة']);
        $client = Client::create(['name' => 'عميلٌ', 'stage' => 'عميل حالي', 'company_id' => $company->id]);
        $clientUser = $this->client();
        \App\Models\ClientMembership::create(['client_id' => $client->id, 'user_id' => $clientUser->id, 'role' => 'owner']);
        $p = $this->project($company->id, ['client_id' => $client->id, 'audience' => 'client']);
        $a = $this->asset($company->id);
        (new AssetProjectService)->assign($a, $p, $this->owner);

        $resp = $this->actingAs($clientUser)->get(route('m.show', ['projects', $p->id]));
        if ($resp->status() === 200) {
            $resp->assertDontSee('🖥️ الأصول')->assertDontSee('أصولُ المشروع')->assertDontSee($a->name);
        } else {
            $this->assertContains($resp->status(), [403, 404]);   // البوّابةُ قد تحجبه أصلاً
        }
    }

    /* ═══════════ التخصيصُ من المشروعِ عبر الويب (§19/§95) ═══════════ */

    public function test_assign_asset_from_project_http_then_visible(): void
    {
        $this->seedCore();
        $p = $this->project();
        $a = $this->asset();

        $this->actingAs($this->owner)->from(route('m.show', ['projects', $p->id]))
            ->post(route('projects.assets.assign', $p->id), ['assets' => [(string) $a->id], 'purpose' => 'اختبار'])
            ->assertRedirect();

        $this->assertSame(1, (new AssetProjectService)->activeCountForProject($p->id));
        $this->actingAs($this->owner)->get(route('m.show', ['assets', $a->id]))->assertOk()
            ->assertSee('مشاريعُ الأصل')->assertSee($p->name);
    }

    public function test_end_assignment_http_preserves_history(): void
    {
        $this->seedCore();
        $p = $this->project();
        $a = $this->asset();
        $row = (new AssetProjectService)->assign($a, $p, $this->owner);

        $this->actingAs($this->owner)->from(route('m.show', ['projects', $p->id]))
            ->post(route('assetproject.end', $row->id))->assertRedirect();

        $this->assertNotNull($row->fresh()->ended_at);
        $this->assertSame(1, AssetProjectAssignment::where('asset_id', $a->id)->count());   // محفوظ
    }

    /* ═══════════ IDOR والصلاحية والعميل (§50/§85/§86) ═══════════ */

    public function test_cross_company_asset_assignment_is_404(): void
    {
        $this->seedCore();
        // مستخدمٌ مقيَّدٌ بشركة أ يحاول تخصيصَ أصلِ شركة ب
        $coA = Company::create(['name_ar' => 'أ']);
        $coB = Company::create(['name_ar' => 'ب']);
        $role = Role::create(['name' => 'مقيَّد', 'scope' => 'all',
            'flags' => [], 'matrix' => collect(array_keys(config('hub.modules')))
                ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all()]);
        $u = User::create(['name' => 'مقيَّد', 'email' => 'r@t.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(), 'companies' => [$coA->id]]);
        $pA = $this->project($coA->id);
        $aB = $this->asset($coB->id);

        $this->actingAs($u)->post(route('projects.assets.assign', $pA->id), ['assets' => [(string) $aB->id]])
            ->assertNotFound();   // الأصلُ خارجَ نطاقِ شركته
        $this->assertSame(0, AssetProjectAssignment::count());
    }

    public function test_viewer_without_asset_edit_cannot_assign(): void
    {
        $this->seedCore();
        $p = $this->project();
        $a = $this->asset();
        // المشاهدُ (view فقط) لا يملك assets.e
        $this->actingAs($this->viewer)->post(route('projects.assets.assign', $p->id), ['assets' => [(string) $a->id]])
            ->assertForbidden();
        $this->assertSame(0, AssetProjectAssignment::count());
    }

    public function test_client_cannot_reach_assignment_routes(): void
    {
        $this->seedCore();
        $client = $this->client();
        $p = $this->project();
        $a = $this->asset();

        $this->actingAs($client)->post(route('projects.assets.assign', $p->id), ['assets' => [(string) $a->id]])->assertNotFound();
        $this->actingAs($client)->post(route('assets.projects.assign', $a->id), ['projects' => [(string) $p->id]])->assertNotFound();
    }

    /* ═══════════ الحافّةُ في المستكشف (§40) ═══════════ */

    public function test_asset_project_edge_appears_in_relationship_projection(): void
    {
        $this->seedCore();
        $p = $this->project();
        $a = $this->asset();
        (new AssetProjectService)->assign($a, $p, $this->owner);

        $this->actingAs($this->owner);
        $graph = (new RelationshipProjection)->expand('projects', $p->id, 2);
        $this->assertNotNull($graph);
        // الأصلُ عقدةٌ في الرسم
        $assetNode = collect($graph['nodes'])->firstWhere('key', 'assets:' . $a->id);
        $this->assertNotNull($assetNode, 'عقدةُ الأصلِ غائبةٌ عن رسمِ المشروع');
        // وحافّةُ التخصيصِ موجودة
        $edge = collect($graph['edges'])->first(fn ($e) => $e['via'] === 'asset_project');
        $this->assertNotNull($edge, 'حافّةُ الأصل↔المشروع غائبةٌ عن المستكشف');
    }

    public function test_graph_edge_hidden_when_viewer_cannot_see_asset(): void
    {
        $this->seedCore();
        $p = $this->project();
        $a = $this->asset();
        (new AssetProjectService)->assign($a, $p, $this->owner);

        // مستخدمٌ يرى المشاريعَ لا الأصول — الحافّةُ لا تُكشَف (كلُّ طرفٍ يمرّ بقارئه)
        $role = Role::create(['name' => 'بلا أصول', 'scope' => 'all', 'flags' => [],
            'matrix' => ['projects' => ['v' => 1]]]);
        $u = User::create(['name' => 'بلا أصول', 'email' => 'na@t.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $graph = (new RelationshipProjection)->expand('projects', $p->id, 2);
        if ($graph !== null) {
            // يُنفَّذ كـ$u
        }
        $this->actingAs($u);
        $graph = (new RelationshipProjection)->expand('projects', $p->id, 2);
        $this->assertNotNull($graph);
        $assetNode = collect($graph['nodes'])->firstWhere('key', 'assets:' . $a->id);
        $this->assertNull($assetNode, 'عقدةُ أصلٍ تسرّبت لمن لا يراه');
    }
}
