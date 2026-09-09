<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetProjectAssignment;
use App\Models\Project;
use App\Support\AssetProjectService;
use Illuminate\Http\Request;

/**
 * **API تخصيصِ الأصولِ للمشاريع** (Project 360 · §56) — REST قائمٌ فوقَ **نفسِ الخدمةِ**
 * التي يستعملها الويب (`AssetProjectService`) — لا منطقَ عملٍ مكرَّر. المصادقةُ والعزلُ
 * كسائرِ `/api/v1`: `ApiAuth` يُوثّق، و`hub_scope` يعزل (٤٠٤ خارجَ النطاق)، والعميلُ محجوب.
 * الجوّالُ الأصيلُ يبقى مؤجَّلاً — الجاهزيّةُ عبرَ هذا العقدِ المشترك (§55/§57).
 */
class AssetProjectApiController extends Controller
{
    private AssetProjectService $svc;

    public function __construct()
    {
        $this->svc = new AssetProjectService();
    }

    private function gate(): void
    {
        $u = auth()->user();
        abort_unless($u, 403);
        abort_if(hub_is_client($u), 404);
        abort_unless(hub_can($u, 'assets', 'e') && hub_can($u, 'projects', 'v'), 403);
    }

    private function scopedAsset(string $id): Asset
    {
        return hub_scope(Asset::query(), 'assets')->findOrFail($id);
    }

    private function scopedProject(string $id): Project
    {
        return hub_scope(Project::query(), 'projects')->findOrFail($id);
    }

    /** GET /api/v1/projects/{id}/assets — الأصولُ النشطةُ المخصَّصةُ للمشروع */
    public function projectAssets(Request $r, string $id)
    {
        $this->gate();
        $project = $this->scopedProject($id);

        $rows = $this->svc->activeForProject((string) $project->getKey())->map(fn ($a) => [
            'assignment_id' => (string) $a->id,
            'asset_id'      => (string) $a->asset_id,
            'asset_code'    => $a->asset?->code,
            'asset_name'    => $a->asset?->name,
            'purpose'       => $a->purpose,
            'assigned_at'   => optional($a->assigned_at)->toIso8601String(),
        ])->all();

        return response()->json(['project_id' => (string) $project->getKey(), 'assets' => $rows]);
    }

    /** GET /api/v1/assets/{id}/projects — المشاريعُ النشطةُ لهذا الأصل */
    public function assetProjects(Request $r, string $id)
    {
        $this->gate();
        $asset = $this->scopedAsset($id);

        $rows = $this->svc->activeForAsset((string) $asset->getKey())->map(fn ($a) => [
            'assignment_id' => (string) $a->id,
            'project_id'    => (string) $a->project_id,
            'project_name'  => $a->project?->name,
            'purpose'       => $a->purpose,
            'assigned_at'   => optional($a->assigned_at)->toIso8601String(),
        ])->all();

        return response()->json(['asset_id' => (string) $asset->getKey(), 'projects' => $rows]);
    }

    /** POST /api/v1/projects/{id}/assets — يخصّص أصلاً للمشروع (idempotent) */
    public function assign(Request $r, string $id)
    {
        $this->gate();
        $project = $this->scopedProject($id);
        $data = $r->validate([
            'asset_id' => ['required', 'string'],
            'purpose'  => ['nullable', 'string', 'max:120'],
            'note'     => ['nullable', 'string', 'max:500'],
        ]);
        $asset = $this->scopedAsset((string) $data['asset_id']);
        $a = $this->svc->assign($asset, $project, $r->user(),
            $data['purpose'] ?? null, $data['note'] ?? null);

        return response()->json(['assignment_id' => (string) $a->id,
            'asset_id' => (string) $a->asset_id, 'project_id' => (string) $a->project_id,
            'active' => $a->isActive()], 201);
    }

    /** POST /api/v1/asset-project/{id}/end — إنهاءٌ يحفظ التاريخ */
    public function end(Request $r, string $id)
    {
        $this->gate();
        $a = AssetProjectAssignment::findOrFail($id);
        $this->scopedAsset((string) $a->asset_id);
        $this->scopedProject((string) $a->project_id);
        $this->svc->end($a, $r->user(), hub_str($r->input('reason')));

        return response()->json(['assignment_id' => (string) $a->id, 'active' => false]);
    }
}
