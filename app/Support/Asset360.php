<?php

namespace App\Support;

use App\Models\Asset;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **الأصل 360** (الكيان 360 · §28–39) — نموذجُ قراءةٍ يُجمّع سياقَ الأصلِ من مصادرِه: **لا
 * محرّكَ أصولٍ/عهدةٍ/جردٍ ثانٍ**. الكتابةُ عبرَ `App\Support\Custody` و`AssetProjectService`
 * فقط؛ هذا صنفُ قراءةٍ يفصل صراحةً **الحائز ≠ المحطة ≠ تخصيصُ المشروع** (§30).
 *
 * المصادر: الحائزُ `assets.holder_id` + تاريخُه `asset_custody`؛ المحطةُ `assets.station_id`
 * + **تاريخُها** `asset_custody.station_id`؛ المشاريعُ `asset_project_assignments`؛ النقطةُ
 * `endpoint_devices.asset_id`؛ الجردُ `inventory_scans/items.asset_id`.
 */
class Asset360
{
    /** نظرةٌ خاطفة: الحائزُ/المحطةُ/المشاريعُ/النقطةُ/الجردُ — كلٌّ خلف صلاحيّته وحقلِ عرضه */
    public function overview(Asset $asset, $u): array
    {
        $holder = ($asset->holder_id && hub_can($u, 'hr', 'v'))
            ? (hub_ref_labels('users', [$asset->holder_id])[$asset->holder_id] ?? null) : null;
        $station = ($asset->station_id && hub_can($u, 'stations', 'v'))
            ? (hub_ref_labels('stations', [$asset->station_id])[$asset->station_id] ?? null) : null;

        $projects = null;
        if (hub_can($u, 'projects', 'v') && hub_has_col('asset_project_assignments', 'asset_id')) {
            $projects = (int) \App\Models\AssetProjectAssignment::active()
                ->where('asset_id', (string) $asset->id)->count();
        }

        $endpoint = null;
        if (hub_can($u, 'endpoints', 'v') && Schema::hasTable('endpoint_devices')) {
            $endpoint = $asset->activeEndpoint();
        }

        return [
            'holder'    => $holder,       // العهدة — «مَن بيده الآن» (≠ محطة ≠ مشروع)
            'station'   => $station,      // المقعدُ الفيزيائيّ
            'projects'  => $projects,     // تخصيصاتٌ نشطة (علاقةٌ لا عهدة)
            'endpoint'  => $endpoint ? ['id' => $endpoint->id, 'hostname' => $endpoint->hostname,
                'os' => $endpoint->os, 'status' => $endpoint->status] : null,
            'inventory' => $this->inventoryStatus($asset, $u),
        ];
    }

    /**
     * **تاريخُ محطةِ الأصل** (§33) — من `asset_custody.station_id` (حركاتُ إسناد/إخلاء المحطة).
     * صادقٌ: إن غاب العمودُ فلا تاريخَ محطةٍ يُدَّعى (§33 «وثّق الحدَّ لا تختلقه»).
     */
    public function stationHistory(Asset $asset, $u, int $limit = 30): Collection
    {
        if (! hub_can($u, 'stations', 'v') || ! hub_has_col('asset_custody', 'station_id')) return collect();

        $rows = DB::table('asset_custody')->whereNull('deleted_at')
            ->where('asset_id', (string) $asset->id)->whereNotNull('station_id')
            ->orderByDesc('at')->orderByDesc('id')->limit($limit)
            ->get(['id', 'station_id', 'action', 'at', 'by_id', 'note']);

        $stationNames = hub_ref_labels('stations', $rows->pluck('station_id')->filter()->unique()->all());
        $actorNames = hub_ref_labels('users', $rows->pluck('by_id')->filter()->unique()->all());

        return $rows->map(function ($r) use ($stationNames, $actorNames) {
            $r->station_code = $stationNames[$r->station_id] ?? '—';
            $r->actor_name = $actorNames[$r->by_id] ?? null;

            return $r;
        });
    }

    /** آخرُ فحصِ جردٍ لهذا الأصل (§36) — مسحٌ حاسمٌ (معروف/غير متوقّع)، أو null */
    public function inventoryStatus(Asset $asset, $u): ?array
    {
        if (! hub_can($u, 'assets', 'v') || ! Schema::hasTable('inventory_scans')) return null;

        // `inventory_scans`/`inventory_items` بلا حذفٍ ناعم (لا `deleted_at`) — MySQL صارم
        $last = DB::table('inventory_scans')
            ->where('asset_id', (string) $asset->id)->whereNotNull('at')
            ->orderByDesc('at')->orderByDesc('id')->first(['at', 'result']);

        $verdict = null;
        if (Schema::hasTable('inventory_items')) {
            $verdict = DB::table('inventory_items')
                ->where('asset_id', (string) $asset->id)
                ->orderByDesc('id')->value('verdict');
        }

        return ($last || $verdict) ? ['last_at' => $last->at ?? null,
            'result' => $last->result ?? null, 'verdict' => $verdict] : null;
    }

    /**
     * الدورةُ الحياتيّة (§38) — من حقولِ الأصلِ القائمة (شراء/ضمان/صيانة/عمر/إخراج) +
     * إجماليُّ الصيانة. لا آلةَ حالةٍ ثانية؛ حسابٌ عرضيٌّ فوقَ الأعمدةِ الموجودة.
     */
    public function lifecycle(Asset $asset, $u): array
    {
        $maint = 0.0;
        if (Schema::hasTable('asset_maintenance')) {
            $maint = (float) DB::table('asset_maintenance')->whereNull('deleted_at')
                ->where('asset_id', (string) $asset->id)->sum('cost');
        }
        $seesPrice = hub_field_mode($u, 'assets', 'price') !== 'hide';

        return [
            'buy_date' => $asset->buy_date, 'warranty' => $asset->warranty,
            'maint' => $asset->maint, 'disposal' => $asset->disposal,
            'life' => $asset->life, 'price' => $seesPrice ? $asset->price : null,
            'maint_total' => $seesPrice ? $maint : null,
        ];
    }
}
