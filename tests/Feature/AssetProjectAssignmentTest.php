<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetCustody;
use App\Models\AssetProjectAssignment;
use App\Models\Project;
use App\Models\Station;
use App\Models\User;
use App\Support\AssetProjectService;
use Tests\TestCase;

/**
 * **§84/§85 — تخصيصُ الأصلِ للمشروع: علاقةٌ زمنيّةٌ لا عهدة، مصونةُ العزل.**
 *
 * إثباتٌ لا ادّعاء: التخصيصُ الأساسيّ، واستقلالُه عن العهدة/الحائز/المحطّة/الحالة، والمشاركةُ
 * (أصلٌ لعدّة مشاريع)، ومنعُ التكرارِ النشط، والإنهاءُ الحافظُ للتاريخ، وإعادةُ التخصيص —
 * ثمّ عزلُ الشركات (IDOR) والعميل والصلاحية.
 */
class AssetProjectAssignmentTest extends TestCase
{
    private function asset(?string $companyId = null, array $extra = []): Asset
    {
        return Asset::create(array_merge([
            'name' => 'أصل ' . \Illuminate\Support\Str::random(4),
            'type' => 'laptop', 'status' => 'نشط', 'company_id' => $companyId,
        ], $extra));
    }

    private function project(?string $companyId = null, array $extra = []): Project
    {
        return Project::create(array_merge([
            'name' => 'مشروع ' . \Illuminate\Support\Str::random(4), 'company_id' => $companyId,
        ], $extra));
    }

    private function svc(): AssetProjectService
    {
        return new AssetProjectService();
    }

    /* ═══════════ الأساسيّات (§84) ═══════════ */

    public function test_assign_one_asset_appears_on_both_sides_with_history(): void
    {
        $this->seedCore();
        $a = $this->asset();
        $p = $this->project();

        $row = $this->svc()->assign($a, $p, $this->owner, 'خادمُ مشروع');
        $this->assertTrue($row->isActive());

        // المشروعُ يُظهر الأصل، والأصلُ يُظهر المشروع
        $this->assertCount(1, $this->svc()->activeForProject($p->id));
        $this->assertCount(1, $this->svc()->activeForAsset($a->id));
        // التاريخُ موجود
        $this->assertSame(1, AssetProjectAssignment::where('asset_id', $a->id)->count());
        $this->assertSame('خادمُ مشروع', $row->purpose);
        $this->assertSame((string) $this->owner->id, (string) $row->assigned_by);
    }

    public function test_assign_multiple_assets_to_a_project(): void
    {
        $this->seedCore();
        $p = $this->project();
        $a1 = $this->asset(); $a2 = $this->asset(); $a3 = $this->asset();
        foreach ([$a1, $a2, $a3] as $a) $this->svc()->assign($a, $p, $this->owner);

        $this->assertSame(3, $this->svc()->activeCountForProject($p->id));
    }

    /* ═══════════ الاستقلالُ عن العهدة (§18/§84) ═══════════ */

    public function test_assignment_does_not_change_custody_holder_station_or_status(): void
    {
        $this->seedCore();
        $station = Station::create(['name' => 'محطة', 'company_id' => null]);
        $a = $this->asset(null, ['holder_id' => $this->employee->id, 'station_id' => $station->id, 'status' => 'نشط']);
        $custodyBefore = AssetCustody::where('asset_id', $a->id)->count();
        $endpointBefore = \App\Models\EndpointDevice::where('asset_id', $a->id)->count();
        $p = $this->project();

        $this->svc()->assign($a, $p, $this->owner);

        $fresh = $a->fresh();
        $this->assertSame((string) $this->employee->id, (string) $fresh->holder_id, 'الحائزُ تغيّر بالتخصيص');
        $this->assertSame((string) $station->id, (string) $fresh->station_id, 'المحطّةُ تغيّرت بالتخصيص');
        $this->assertSame('نشط', $fresh->status, 'حالةُ الأصلِ تغيّرت بالتخصيص');
        $this->assertSame($custodyBefore, AssetCustody::where('asset_id', $a->id)->count(), 'سجلُّ العهدةِ تغيّر');
        $this->assertSame($endpointBefore, \App\Models\EndpointDevice::where('asset_id', $a->id)->count(), 'النقطةُ الطرفيّةُ تغيّرت');
    }

    public function test_ending_assignment_does_not_unassign_holder_or_station(): void
    {
        $this->seedCore();
        $station = Station::create(['name' => 'محطة', 'company_id' => null]);
        $a = $this->asset(null, ['holder_id' => $this->employee->id, 'station_id' => $station->id]);
        $p = $this->project();
        $row = $this->svc()->assign($a, $p, $this->owner);

        $this->svc()->end($row, $this->owner, 'انتهى الاستخدام');

        $fresh = $a->fresh();
        $this->assertSame((string) $this->employee->id, (string) $fresh->holder_id);
        $this->assertSame((string) $station->id, (string) $fresh->station_id);
    }

    /* ═══════════ المشاركة: أصلٌ لعدّة مشاريعَ نشطة (§65/§84) ═══════════ */

    public function test_one_asset_can_be_active_in_two_projects(): void
    {
        $this->seedCore();
        $a = $this->asset();
        $pA = $this->project(); $pB = $this->project();

        $this->svc()->assign($a, $pA, $this->owner);
        $this->svc()->assign($a, $pB, $this->owner);

        $this->assertCount(2, $this->svc()->activeForAsset($a->id));
        $this->assertSame(1, $this->svc()->activeCountForProject($pA->id));
        $this->assertSame(1, $this->svc()->activeCountForProject($pB->id));
    }

    /* ═══════════ منعُ التكرارِ النشط (idempotent · §46/§84) ═══════════ */

    public function test_duplicate_active_pair_is_idempotent(): void
    {
        $this->seedCore();
        $a = $this->asset(); $p = $this->project();

        $r1 = $this->svc()->assign($a, $p, $this->owner);
        $r2 = $this->svc()->assign($a, $p, $this->owner);   // نفسُ الزوجِ النشط

        $this->assertSame((string) $r1->id, (string) $r2->id, 'أُنشئ زوجٌ نشطٌ مكرَّر');
        $this->assertSame(1, AssetProjectAssignment::where('asset_id', $a->id)->where('project_id', $p->id)->whereNull('ended_at')->count());
    }

    /* ═══════════ الإنهاءُ يحفظ التاريخ (§21/§84) ═══════════ */

    public function test_ending_preserves_history_row(): void
    {
        $this->seedCore();
        $a = $this->asset(); $p = $this->project();
        $row = $this->svc()->assign($a, $p, $this->owner);

        $this->svc()->end($row, $this->owner, 'انتهى');

        $fresh = $row->fresh();
        $this->assertNotNull($fresh->ended_at, 'الإنهاءُ لم يُسجَّل');
        $this->assertNull($fresh->active_flag, 'الرايةُ لم تُرفَع بعد الإنهاء');
        $this->assertSame((string) $this->owner->id, (string) $fresh->ended_by);
        // الصفُّ باقٍ (لا حذف)
        $this->assertSame(1, AssetProjectAssignment::where('asset_id', $a->id)->count());
        $this->assertSame(0, $this->svc()->activeCountForProject($p->id));
    }

    /* ═══════════ إعادةُ التخصيصِ بعد الإنهاء = صفٌّ جديد (§84) ═══════════ */

    public function test_reassign_after_end_creates_new_history_row(): void
    {
        $this->seedCore();
        $a = $this->asset(); $p = $this->project();
        $r1 = $this->svc()->assign($a, $p, $this->owner);
        $this->svc()->end($r1, $this->owner);

        $r2 = $this->svc()->assign($a, $p, $this->owner);   // يُقبَل بعد الإنهاء

        $this->assertNotSame((string) $r1->id, (string) $r2->id, 'إعادةُ التخصيصِ لم تُنشئ صفّاً جديداً');
        $this->assertTrue($r2->isActive());
        $this->assertSame(2, AssetProjectAssignment::where('asset_id', $a->id)->count(), 'التاريخُ لا يحمل الصفّين');
    }

    /* ═══════════ حالةٌ نهائيّةٌ تُرفَض (§17) ═══════════ */

    public function test_terminal_status_asset_is_rejected(): void
    {
        $this->seedCore();
        $a = $this->asset(null, ['status' => 'مباع']);
        $p = $this->project();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->svc()->assign($a, $p, $this->owner);
    }

    /* ═══════════ العميلُ لا يُخصِّص (§17/§85) ═══════════ */

    public function test_client_cannot_assign(): void
    {
        $this->seedCore();
        $role = \App\Models\Role::create(['name' => 'عميل', 'scope' => 'all', 'flags' => [], 'matrix' => []]);
        $client = User::create(['name' => 'عميل', 'email' => 'cl@t.local', 'account_type' => 'client',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $a = $this->asset(); $p = $this->project();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->svc()->assign($a, $p, $client);
    }

    /* ═══════════ عزلُ الشركاتِ في الخدمة (§17/§85) ═══════════ */

    public function test_cross_company_asset_and_project_is_rejected(): void
    {
        $this->seedCore();
        $coA = \App\Models\Company::create(['name_ar' => 'شركة أ']);
        $coB = \App\Models\Company::create(['name_ar' => 'شركة ب']);
        $a = $this->asset($coA->id);
        $p = $this->project($coB->id);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->svc()->assign($a, $p, $this->owner);
    }
}
