<?php

namespace App\Support;

use App\Models\Employee;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **الموظف 360** (الكيان 360 · §6–18) — نموذجُ قراءةٍ يُكمِّل الملفَّ الشاملَ القائم
 * (`PortalController`) بشريطِ نظرةٍ وتاريخِ محطةٍ وتاريخِ عهدةٍ ونشاطٍ منطَّق. **لا محرّكَ
 * موظفٍ ثانٍ، ولا مسحٌ شامل** (§18): مجالاتُ الأعمالِ المصرَّحةُ وحدَها.
 *
 * جسرُ الهويّتَين: `phones/custody/servers` بـ`employees.id`، و`assets/stations/endpoints`
 * بـ`employees.user_id` — كما في `PortalController`. كلُّ حقلٍ خلف `hub_can`+`hub_scope`+field-mode.
 */
class Employee360
{
    /** نظرةٌ خاطفة (§6): عدّاداتٌ ورؤوسٌ منطَّقةٌ — كلُّ حقلٍ خلف صلاحيّته، وإلا null (غائبٌ لا صفر) */
    public function overview(Employee $emp, $u): array
    {
        $uid = $emp->user_id ? (string) $emp->user_id : null;

        $station = null;
        if ($uid && hub_can($u, 'stations', 'v')) {
            $station = hub_scope(DB::table('stations')->whereNull('deleted_at'), 'stations')
                ->where('current_employee_id', $uid)->orderBy('code')->value('code');
        }

        $assets = ($uid && hub_can($u, 'assets', 'v'))
            ? (int) hub_scope(DB::table('assets')->whereNull('deleted_at'), 'assets')->where('holder_id', $uid)->count()
            : null;

        $projects = null;
        if ($uid && hub_can($u, 'projects', 'v')) {
            $projects = (int) hub_scope(DB::table('projects')->whereNull('deleted_at'), 'projects')
                ->where(fn ($w) => $w->where('manager_id', $uid)->orWhere('members', 'LIKE', '%"' . $uid . '"%'))
                ->count();
        }

        $tasks = null;
        if ($uid && hub_can($u, 'tasks', 'v')) {
            $tasks = (int) hub_open_scope(hub_scope(DB::table('tasks')->whereNull('deleted_at'), 'tasks')
                ->where('assignee_id', $uid))->count();
        }

        $sim = hub_can($u, 'phones', 'v')
            ? (int) hub_scope(DB::table('phone_numbers')->whereNull('deleted_at'), 'phones')->where('employee_id', (string) $emp->id)->count()
            : null;

        $endpoints = ($uid && hub_can($u, 'endpoints', 'v') && \Illuminate\Support\Facades\Schema::hasTable('endpoint_devices'))
            ? (int) hub_scope(DB::table('endpoint_devices')->whereNull('deleted_at'), 'endpoints')->where('employee_id', $uid)->count()
            : null;

        // الرصيدُ الماليُّ خلف صلاحيّةِ العهدة **وحقلِ المبلغ** — لا يظهر لمجرّد رؤية الملفّ (§14/§70)
        $balance = null;
        if (hub_can($u, 'custody', 'v') && hub_field_mode($u, 'custody', 'amount') !== 'hide') {
            $balance = (float) $emp->custody_balance;
        }

        return compact('station', 'assets', 'projects', 'tasks', 'sim', 'endpoints', 'balance');
    }

    /** تاريخُ المحطات (§9) — من `station_assignments` (assign/vacate) بحساب الموظف */
    public function stationHistory(Employee $emp, $u, int $limit = 20): Collection
    {
        if (! $emp->user_id || ! hub_can($u, 'stations', 'v')) return collect();

        $rows = hub_scope(DB::table('station_assignments')->whereNull('deleted_at'), 'stations')
            ->where('user_id', (string) $emp->user_id)
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

    /** تاريخُ العهدة (§11) — من `asset_custody` بحساب الموظف (استلام/إعادة/نقل) */
    public function custodyHistory(Employee $emp, $u, int $limit = 20): Collection
    {
        if (! $emp->user_id || ! hub_can($u, 'assets', 'v')) return collect();

        $rows = hub_scope(DB::table('asset_custody')->whereNull('deleted_at'), 'assets')
            ->where('user_id', (string) $emp->user_id)
            ->orderByDesc('at')->orderByDesc('id')->limit($limit)
            ->get(['id', 'asset_id', 'action', 'at', 'by_id', 'note']);

        $assetNames = hub_ref_labels('assets', $rows->pluck('asset_id')->filter()->unique()->all());
        $actorNames = hub_ref_labels('users', $rows->pluck('by_id')->filter()->unique()->all());

        return $rows->map(function ($r) use ($assetNames, $actorNames) {
            $r->asset_name = $assetNames[$r->asset_id] ?? '—';
            $r->actor_name = $actorNames[$r->by_id] ?? null;

            return $r;
        });
    }

    /**
     * النشاطُ المنطَّق (§17) — أحداثٌ تشغيليّةٌ **مصرَّحةٌ لكلِّ نوع** (محطة/عهدة)، مدموجةٌ
     * ومرتّبةٌ زمنيّاً ومقيَّدة. **ليس مسحاً شاملاً** (§461): لا زياراتِ صفحاتٍ ولا تخابر.
     */
    public function activity(Employee $emp, $u, int $limit = 15): Collection
    {
        $events = collect();

        foreach ($this->stationHistory($emp, $u, $limit) as $s) {
            $events->push(['at' => $s->at, 'icon' => '🪑', 'kind' => 'محطة',
                'text' => ($s->action === 'assign' ? 'أُسنِد لمحطة ' : 'أُخلي من محطة ') . $s->station_code,
                'actor' => $s->actor_name]);
        }
        foreach ($this->custodyHistory($emp, $u, $limit) as $c) {
            $events->push(['at' => $c->at, 'icon' => '💻', 'kind' => 'عهدة',
                'text' => $c->action . ' — ' . $c->asset_name, 'actor' => $c->actor_name]);
        }

        return $events->sortByDesc('at')->values()->take($limit);
    }
}
