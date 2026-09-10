<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetProjectAssignment;
use App\Models\Project;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use App\Support\AssetProjectService;
use App\Support\Custody;
use App\Support\RelationshipProjection;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **الرسمُ المعمَّق + الفصلُ الدلاليّ** (الكيان 360 · §45–63/§92/§96–100) — محرّكٌ واحد:
 * بيانةُ الحافّة (مباشر/مشتقّ + زمنيّ)، وضعُ التاريخ، منعُ IDOR في الرسم، وصفرُ تصادمٍ دلاليّ.
 */
class Entity360GraphTest extends TestCase
{
    private function asset(?string $companyId = null, array $extra = []): Asset
    {
        return Asset::create(array_merge(['name' => 'أصل ' . Str::random(4),
            'type' => 'laptop', 'status' => 'نشط', 'company_id' => $companyId], $extra));
    }

    /* ═══════════ بيانةُ الحافّة: مباشرٌ/دلاليّ (§47/§48) ═══════════ */

    public function test_ref_edges_carry_direct_kind_and_semantic_rel(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $a = $this->asset();
        Custody::move($a, 'تسليم', $this->employee->id, now()->toDateString());   // holder_id

        $g = RelationshipProjection::expand('assets', $a->id, 2, true);
        $holder = collect($g['edges'])->first(fn ($e) => ($e['via'] ?? '') === 'assets.holder_id');
        $this->assertNotNull($holder, 'حافّةُ الحائز غائبة');
        $this->assertSame('direct', $holder['kind']);
        $this->assertSame('holds', $holder['rel']);
    }

    public function test_asset_project_edge_is_temporal_and_active_by_default(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $a = $this->asset();
        $p = Project::create(['name' => 'مشروع']);
        (new AssetProjectService)->assign($a, $p, $this->owner, 'خادم');

        $g = RelationshipProjection::expand('assets', $a->id, 2, true);
        $edge = collect($g['edges'])->first(fn ($e) => ($e['via'] ?? '') === 'asset_project');
        $this->assertNotNull($edge);
        $this->assertSame('direct', $edge['kind']);
        $this->assertTrue($edge['active'], 'التخصيصُ النشطُ يجب أن يكون active=true');
        $this->assertNotNull($edge['since']);
    }

    /* ═══════════ وضعُ التاريخ (§54/§100) ═══════════ */

    public function test_history_mode_reveals_ended_assignment_marked_inactive(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $a = $this->asset();
        $p = Project::create(['name' => 'منتهٍ']);
        $svc = new AssetProjectService;
        $row = $svc->assign($a, $p, $this->owner);
        $svc->end($row, $this->owner);

        // الوضعُ الافتراضيّ (نشطٌ فقط): لا حافّةَ تخصيص
        $now = RelationshipProjection::expand('assets', $a->id, 2, true, false);
        $this->assertNull(collect($now['edges'])->first(fn ($e) => ($e['via'] ?? '') === 'asset_project'),
            'المُنهاةُ ظهرت في الوضع الحاليّ');

        // وضعُ التاريخ: تظهر موسومةً active=false
        $hist = RelationshipProjection::expand('assets', $a->id, 2, true, true);
        $edge = collect($hist['edges'])->first(fn ($e) => ($e['via'] ?? '') === 'asset_project');
        $this->assertNotNull($edge, 'وضعُ التاريخ لم يُظهر المُنهاة');
        $this->assertFalse($edge['active']);
        $this->assertNotNull($edge['ended']);
        $this->assertTrue($hist['history']);
    }

    /* ═══════════ الحافّةُ المشتقّة (§27/§47) ═══════════ */

    public function test_derived_station_project_edge_is_tagged(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $s = Station::create(['status' => 'متاحة']);
        $a = $this->asset();
        Custody::assignStation($a, $s->id, now()->toDateString());
        $p = Project::create(['name' => 'مشروعٌ عبر أصل']);
        (new AssetProjectService)->assign($a->fresh(), $p, $this->owner);

        $g = RelationshipProjection::expand('stations', $s->id, 2, true);
        $edge = collect($g['edges'])->first(fn ($e) => ($e['via'] ?? '') === 'station_project_derived');
        $this->assertNotNull($edge, 'الحافّةُ المشتقّةُ المحطة→المشروع غائبة');
        $this->assertSame('derived', $edge['kind']);
        $this->assertSame('project_via_asset', $edge['rel']);
    }

    /* ═══════════ منعُ IDOR في الرسم (§52/§99) ═══════════ */

    public function test_graph_hides_node_and_edge_for_unauthorized_endpoint(): void
    {
        $this->seedCore();
        $s = Station::create(['status' => 'متاحة']);
        $a = $this->asset(null, ['station_id' => $s->id]);

        // قارئٌ يرى الأصولَ لا المحطات — عقدةُ المحطة وحافّتُها لا تُكشَفان
        $role = Role::create(['name' => 'بلا محطات', 'scope' => 'all', 'flags' => [],
            'matrix' => ['assets' => ['v' => 1]]]);
        $u = User::create(['name' => 'بلا محطات', 'email' => 'na@t.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $this->actingAs($u);

        $g = RelationshipProjection::expand('assets', $a->id, 2, true);
        $this->assertNull(collect($g['nodes'])->firstWhere('key', 'stations:' . $s->id), 'عقدةُ محطةٍ تسرّبت لمن لا يراها');
        $this->assertNull(collect($g['edges'])->first(fn ($e) => ($e['via'] ?? '') === 'assets.station_id'),
            'حافّةُ محطةٍ تسرّبت لمن لا يراها');
    }

    /* ═══════════ الفصلُ الدلاليّ (§96) — تصادماتٌ = 0 ═══════════ */

    public function test_assigning_project_does_not_change_holder_station_status(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $s = Station::create(['status' => 'متاحة']);
        $a = $this->asset();
        Custody::move($a, 'تسليم', $this->employee->id, now()->toDateString());
        Custody::assignStation($a->fresh(), $s->id, now()->toDateString());
        $a = $a->fresh();
        [$h0, $st0, $status0] = [$a->holder_id, $a->station_id, $a->status];

        (new AssetProjectService)->assign($a, Project::create(['name' => 'م']), $this->owner);
        $a = $a->fresh();
        $this->assertSame($h0, $a->holder_id, 'تخصيصُ المشروع غيّر الحائز!');
        $this->assertSame($st0, $a->station_id, 'تخصيصُ المشروع غيّر المحطة!');
        $this->assertSame($status0, $a->status, 'تخصيصُ المشروع غيّر الحالة!');
    }

    public function test_changing_holder_or_station_does_not_change_project_assignment(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $a = $this->asset();
        $p = Project::create(['name' => 'ثابت']);
        (new AssetProjectService)->assign($a, $p, $this->owner);
        $activeId = AssetProjectAssignment::active()->where('asset_id', $a->id)->value('id');

        // تغييرُ الحائز
        Custody::move($a->fresh(), 'تسليم', $this->employee->id, now()->toDateString());
        // تغييرُ المحطة
        $s = Station::create(['status' => 'متاحة']);
        Custody::assignStation($a->fresh(), $s->id, now()->toDateString());

        // التخصيصُ نفسُه لم يُمسّ (لا نهاية، نفسُ الصفّ نشط)
        $still = AssetProjectAssignment::active()->where('asset_id', $a->id)->value('id');
        $this->assertSame($activeId, $still, 'تغييرُ الحائز/المحطة مسّ تخصيصَ المشروع!');
        $this->assertSame(1, AssetProjectAssignment::where('asset_id', $a->id)->whereNull('ended_at')->count());
    }
}
