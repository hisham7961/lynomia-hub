<?php

namespace App\Support;

use App\Models\AssetProjectAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **المحطة 360** (الكيان 360 · §19–27) — نموذجُ قراءةٍ يُجمّع سياقَ المحطةِ المصرَّحَ من
 * مصادرِه القائمة: **لا محرّكَ محطاتٍ ثانٍ**. كلُّ استعلامٍ مقيَّدٌ (`limit`) ومنطَّقٌ
 * (`hub_scope`+`hub_can`)، وعدُّ ما يقرؤه القارئُ لا عدٌّ خام (§74). الكتابةُ (إسناد/إخلاء)
 * تبقى في `StationController` المقفل — هذا صنفُ قراءةٍ فقط.
 *
 * المصادرُ المرجعيّة (§4): الشاغلُ `stations.current_employee_id`؛ الأصولُ `assets.station_id`؛
 * **تاريخُ الأصول** `asset_custody.station_id`؛ النقاطُ `endpoint_devices.station_id`؛ الجردُ
 * عبرَ أصولِ المحطة؛ المشروعُ المباشرُ `stations.project_id` والمشتقُّ عبرَ الأصول (موسوم).
 */
class Station360
{
    /** نظرةٌ خاطفة: عدّاداتٌ ورؤوسُ علاقاتٍ منطَّقةٌ — كلُّ حقلٍ خلف صلاحيّته */
    public function overview(object $station, $u): array
    {
        $sid = (string) $station->id;

        $assets = hub_can($u, 'assets', 'v')
            ? (int) hub_scope(DB::table('assets')->whereNull('deleted_at'), 'assets')->where('station_id', $sid)->count()
            : null;

        $endpoints = (hub_can($u, 'endpoints', 'v') && hub_has_col('endpoint_devices', 'station_id'))
            ? (int) hub_scope(DB::table('endpoint_devices')->whereNull('deleted_at'), 'endpoints')->where('station_id', $sid)->count()
            : null;

        $occupant = null;
        if ($station->current_employee_id && hub_can($u, 'hr', 'v')) {
            $occupant = hub_ref_labels('users', [$station->current_employee_id])[$station->current_employee_id] ?? null;
        }

        return [
            'occupant'       => $occupant,
            'assets'         => $assets,
            'endpoints'      => $endpoints,
            'project'        => $station->project_id && hub_can($u, 'projects', 'v')
                ? (hub_ref_labels('projects', [$station->project_id])[$station->project_id] ?? null) : null,
            'inventory'      => $this->inventoryStatus($station, $u),
        ];
    }

    /** الأصولُ الحاليّةُ عند المحطة — منطَّقةٌ ومقيَّدة، بترتيبٍ حتميّ */
    public function currentAssets(object $station, $u, int $limit = 60): Collection
    {
        if (! hub_can($u, 'assets', 'v')) return collect();

        return hub_scope(DB::table('assets')->whereNull('deleted_at'), 'assets')
            ->where('station_id', (string) $station->id)
            ->orderBy('name')->orderBy('id')->limit($limit)
            ->get(['id', 'code', 'name', 'type', 'tag', 'holder_id', 'status']);
    }

    /**
     * **تاريخُ وضعِ الأصولِ عند المحطة** (§23) — من `asset_custody.station_id` (يُختَم
     * في كلِّ حركةِ إسناد/إخلاء محطة). صادقٌ: إن غاب العمودُ فلا تاريخ (لا اختلاق).
     */
    public function assetHistory(object $station, $u, int $limit = 40): Collection
    {
        if (! hub_can($u, 'assets', 'v') || ! hub_has_col('asset_custody', 'station_id')) return collect();

        $rows = hub_scope(DB::table('asset_custody')->whereNull('deleted_at'), 'assets')
            ->where('station_id', (string) $station->id)
            ->orderByDesc('at')->orderByDesc('id')->limit($limit)
            ->get(['id', 'asset_id', 'action', 'at', 'by_id', 'note']);

        // أسماءٌ دفعةً واحدة — لا N+1
        $assetNames = hub_ref_labels('assets', $rows->pluck('asset_id')->filter()->unique()->all());
        $actorNames = hub_ref_labels('users', $rows->pluck('by_id')->filter()->unique()->all());

        return $rows->map(function ($r) use ($assetNames, $actorNames) {
            $r->asset_name = $assetNames[$r->asset_id] ?? '—';
            $r->actor_name = $actorNames[$r->by_id] ?? null;

            return $r;
        });
    }

    /**
     * حالةُ الجرد للمحطة (§25) — لا `station_id` على جداول الجرد؛ يُشتقّ عبرَ أصولِ المحطة.
     * يعيد آخرَ مسحٍ وعددَ أصولِ المحطة الممسوحة — أو null إن لا صلاحيّةَ/جرد.
     */
    public function inventoryStatus(object $station, $u): ?array
    {
        if (! hub_can($u, 'assets', 'v')
            || ! \Illuminate\Support\Facades\Schema::hasTable('inventory_scans')) return null;

        $assetIds = hub_scope(DB::table('assets')->whereNull('deleted_at'), 'assets')
            ->where('station_id', (string) $station->id)->limit(500)->pluck('id')->all();
        if (! $assetIds) return null;

        // `inventory_scans` بلا حذفٍ ناعم (لا عمودَ `deleted_at`) — MySQL يرفض عمداً ما تتساهله SQLite
        $last = DB::table('inventory_scans')
            ->whereIn('asset_id', $assetIds)->whereNotNull('at')
            ->orderByDesc('at')->orderByDesc('id')->first(['at', 'result']);
        $scanned = (int) DB::table('inventory_scans')
            ->whereIn('asset_id', $assetIds)->distinct()->count('asset_id');

        return $last ? ['last_at' => $last->at, 'result' => $last->result,
            'scanned' => $scanned, 'total' => count($assetIds)] : null;
    }

    /**
     * سياقُ المشروع (§27): **مباشرٌ** (`stations.project_id`) + **مشتقٌّ** (أصلٌ عند المحطة
     * مخصَّصٌ لمشروعٍ نشط) — الأخيرُ **موسومٌ صراحةً** كغيرِ مباشرٍ فلا يُخلَط بإسناد.
     */
    public function projectContext(object $station, $u): array
    {
        if (! hub_can($u, 'projects', 'v')) return ['direct' => null, 'derived' => collect()];

        $direct = $station->project_id
            ? ['id' => $station->project_id,
               'name' => hub_ref_labels('projects', [$station->project_id])[$station->project_id] ?? '—'] : null;

        $derived = collect();
        if (hub_can($u, 'assets', 'v') && hub_has_col('asset_project_assignments', 'asset_id')) {
            $assetIds = hub_scope(DB::table('assets')->whereNull('deleted_at'), 'assets')
                ->where('station_id', (string) $station->id)->limit(200)->pluck('id')->all();
            if ($assetIds) {
                $projIds = AssetProjectAssignment::active()->whereIn('asset_id', $assetIds)
                    ->limit(200)->pluck('project_id')->unique()
                    ->reject(fn ($p) => $direct && $p === $direct['id'])->values()->all();
                if ($projIds) {
                    // المشاريعُ عبرَ قارئها (لا تسريبَ عبر وسيط)
                    $visible = hub_scope(DB::table('projects')->whereNull('deleted_at'), 'projects')
                        ->whereIn('id', $projIds)->limit(50)->get(['id', 'name']);
                    $derived = $visible;
                }
            }
        }

        return ['direct' => $direct, 'derived' => $derived];
    }
}
