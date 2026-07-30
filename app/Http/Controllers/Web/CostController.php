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
        abort_unless(auth()->user()?->role?->is_owner || hub_flag(auth()->user(), 'monitor'),
            403, 'لوحة التكاليف للمالكين ومن يحمل صلاحية المتابعة');
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
            'currency' => (string) setting('fin.currency', 'د.ك'),
            'rates' => hub_hourly_rates(),
        ]);
    }
}
