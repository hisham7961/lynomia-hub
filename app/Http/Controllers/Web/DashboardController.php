<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $user  = auth()->user();
        $cards = [];

        foreach (['projects', 'clients', 'tasks', 'tickets', 'fin', 'contracts'] as $key) {
            $def = hub_mod($key);
            if (! $def || ! hub_can($user, $key, 'v')) continue;
            $cards[] = [
                'key'   => $key,
                'label' => $def['label'],
                'count' => hub_scope(DB::table($def['table'])->whereNull('deleted_at'), $key)->count(),
            ];
        }

        // رادار الانتهاءات — أهم ٥
        $expiry = collect(hub_expiry())->filter(fn ($i) => hub_can($user, $i['module'], 'v'))->take(5)->values();

        // مهام تقترب مواعيدها
        $due = collect(); $dueCol = $stCol = $disp = null;
        $tdef = hub_mod('tasks');
        if ($tdef && hub_can($user, 'tasks', 'v')) {
            $dueF   = collect($tdef['fields'])->firstWhere('key', 'due');
            $stCol  = $tdef['status'] ?? null;
            $disp   = hub_display_col('tasks');
            if ($dueF) {
                $dueCol = $dueF['col'];
                $due = hub_scope(DB::table($tdef['table'])->whereNull('deleted_at'), 'tasks')
                    ->whereNotNull($dueCol)->whereDate($dueCol, '>=', now()->toDateString())
                    ->orderBy($dueCol)->limit(6)
                    ->get(array_values(array_unique(array_filter(['id', $disp, $dueCol, $stCol]))));
            }
        }

        // تقدم التطبيقات — نسبة الإنجاز الحية من محرك النسبة
        $apps = collect();
        if (hub_can($user, 'apps', 'v')) {
            $apps = hub_scope(DB::table('applications')->whereNull('deleted_at'), 'apps')
                ->whereNotNull('project_id')
                ->where(fn ($w) => $w->whereNull('status')->orWhere('status', 'NOT LIKE', '%موقوف%'))
                ->orderByDesc('created_at')->limit(6)
                ->get(['id', 'name', 'ver', 'status', 'project_id'])
                ->map(function ($a) {
                    $a->progress = hub_progress($a->project_id)['pct'];
                    return $a;
                });
        }

        // توزيع المهام بالحالة — للدونات
        $taskSlices = [];
        if (hub_can($user, 'tasks', 'v')) {
            $taskSlices = hub_scope(DB::table('tasks')->whereNull('deleted_at'), 'tasks')
                ->select('status', DB::raw('COUNT(*) c'))->groupBy('status')->orderByDesc('c')->limit(6)->get()
                ->map(fn ($r) => ['label' => $r->status ?: 'بلا حالة', 'value' => (int) $r->c])->all();
        }

        $aq = DB::table('audits')
            ->leftJoin('users', 'users.id', '=', 'audits.user_id')
            ->select('audits.*', 'users.name as user_name');
        if (hub_scoped($user)) {
            $ids = $user->visibleProjectIds();
            $aq->where(fn ($w) => $w->where('audits.user_id', $user->id)
                                    ->orWhereIn('audits.project_id', $ids));
        }
        $audits = $aq->orderByDesc('audits.created_at')->limit(10)->get();

        return view('dashboard', compact('cards', 'audits', 'due', 'dueCol', 'stCol', 'disp', 'expiry', 'apps', 'taskSlices'));
    }
}
