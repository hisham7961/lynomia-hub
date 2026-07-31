<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;

/** التكاليف الفعلية وربحية المشاريع — أين ذهب المال وماذا عاد */
class CostController extends Controller
{
    protected function gate(): void
    {
        abort_unless(hub_monitor(),
            403, 'لوحة التكاليف للمالكين ومن يحمل صلاحية المتابعة');
    }

    /** تحليل تكلفة الخدمات والباقات — ومعه الإيراد الشهري المتكرر الحقيقي */
    public function services()
    {
        $this->gate();
        hub_org_analytics_guard();
        $fresh = (bool) request()->query('fresh');

        return view('service_costs', [
            'd' => hub_service_costs($fresh),
            'mrr' => hub_mrr($fresh),
        ]);
    }

    public function index(Request $r)
    {
        $this->gate();

        $one = $r->query('p');
        if ($one) {
            $p = hub_scope(Project::query(), 'projects')->findOrFail($one);

            return view('costs.project', ['p' => $p, 'pl' => hub_project_pl($p->id, (bool) $r->query('fresh'))]);
        }

        $projects = hub_scope(Project::query(), 'projects')
            ->whereNull('deleted_at')->orderByDesc('created_at')->limit(60)->get(['id', 'name', 'status']);

        $rows = $projects->map(fn ($p) => ['p' => $p, 'pl' => hub_project_pl($p->id)])
            ->filter(fn ($x) => ! empty($x['pl']))->values();

        $tot = ['revenue' => 0.0, 'cost' => 0.0, 'profit' => 0.0, 'hours' => 0.0, 'delay' => 0.0];
        foreach ($rows as $x) {
            $tot['revenue'] += $x['pl']['revenue']['invoiced'];
            $tot['cost']    += $x['pl']['cost']['total'];
            $tot['profit']  += $x['pl']['profit'];
            $tot['hours']   += $x['pl']['hours']['logged'];
            $tot['delay']   += $x['pl']['delay']['cost'];
        }
        $tot['margin'] = $tot['revenue'] > 0 ? round($tot['profit'] / $tot['revenue'] * 100, 1) : null;

        return view('costs.index', [
            'rows' => $rows->sortByDesc(fn ($x) => $x['pl']['revenue']['invoiced'])->values(),
            'tot' => $tot,
            'currency' => (string) setting('app.currency', 'د.ك'),
            'rates' => hub_hourly_rates(),
        ]);
    }
}
