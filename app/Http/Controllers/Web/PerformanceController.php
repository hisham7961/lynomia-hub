<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/**
 * لوحة الأداء: مؤشرات الشركة المحسوبة + أهداف OKR بمستوياتها + جدول KPI لكل موظف.
 * للمالكين وحاملي علم monitor — بيانات أداء الأفراد حساسة.
 */
class PerformanceController extends Controller
{
    /** تصنيف المستندات المالية — تعريف واحد في config('hub.fin') لكل التقارير */
    protected array $income;
    protected array $expense;
    protected array $dead;

    public function __construct()
    {
        $this->income  = config('hub.fin.income');
        $this->expense = config('hub.fin.expense');
        $this->dead    = config('hub.fin.dead');
    }

    protected array $doneWords = ['مكتمل', 'منجز'];

    public function index()
    {
        abort_unless(hub_monitor(), 403,
            'لوحة الأداء للمالكين وحاملي صلاحية المراقبة');

        return view('performance.index', [
            'company' => $this->companyKpis(),
            'okrs'    => $this->okrs(),
            'people'  => $this->peopleKpis(),
            'currency' => setting('app.currency', 'د.ك'),
        ]);
    }

    /* ── مؤشرات الشركة ── */
    protected function companyKpis(): array
    {
        $fin = fn () => DB::table('fin_documents')->whereNull('deleted_at')->whereNotIn('state', $this->dead);
        $sum = fn ($kinds, $a, $b) => (float) $fin()->whereIn('kind', $kinds)->whereBetween('date', [$a, $b])->sum('total');

        $m0 = now()->startOfMonth()->toDateString(); $mEnd = now()->toDateString();
        $p0 = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $p1 = now()->subMonthNoOverflow()->endOfMonth()->toDateString();

        $rev  = $sum($this->income, $m0, $mEnd);
        $exp  = $sum($this->expense, $m0, $mEnd);
        $prev = $sum($this->income, $p0, $p1);

        $projTotal = DB::table('projects')->whereNull('deleted_at')->count();
        $projDone  = DB::table('projects')->whereNull('deleted_at')
            ->where(fn ($w) => $w->where('status', 'LIKE', '%مكتمل%')->orWhere('status', 'LIKE', '%منجز%'))->count();

        $clientsTotal = DB::table('clients')->whereNull('deleted_at')->count();
        $activePartners = DB::table('fin_documents')->whereNull('deleted_at')
            ->where('date', '>=', now()->subDays(90)->toDateString())
            ->whereNotNull('partner')->where('partner', '!=', '')->distinct()->count('partner');

        return [
            'rev'      => $rev,
            'margin'   => $rev > 0 ? (int) round(($rev - $exp) * 100 / $rev) : null,
            'growth'   => $prev > 0 ? (int) round(($rev - $prev) * 100 / $prev) : null,
            'newClients' => DB::table('clients')->whereNull('deleted_at')->where('created_at', '>=', $m0)->count(),
            'clients'  => $clientsTotal,
            'activeP'  => $activePartners,
            'projPct'  => $projTotal ? (int) round($projDone * 100 / $projTotal) : null,
            'projDone' => $projDone, 'projTotal' => $projTotal,
            'crit'     => hub_open_scope(DB::table('issues')->whereNull('deleted_at')
                            ->where('severity', 'LIKE', '%حرج%'))->count(),
        ];
    }

    /* ── الأهداف بمستوياتها ── */
    protected function okrs()
    {
        return hub_open_scope(DB::table('objectives')->whereNull('deleted_at'))
            ->orderByRaw("CASE level WHEN 'الشركة' THEN 0 WHEN 'قسم' THEN 1 WHEN 'مشروع' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')->limit(20)
            ->get(['id', 'title', 'level', 'owner_id', 'period', 'due', 'progress', 'status'])
            ->groupBy(fn ($o) => $o->level ?: 'أخرى');
    }

    /* ── KPI لكل موظف (آخر ٣٠ يوماً) ── */
    protected function peopleKpis()
    {
        $since = now()->subDays(30);
        $users = DB::table('users')->whereNull('deleted_at')->where('status', '!=', 'موقوف')
            ->orderBy('name')->limit(30)->get(['id', 'name']);
        $perf = DB::table('employees')->whereNull('deleted_at')->whereNotNull('user_id')->pluck('perf', 'user_id');
        $empIds = DB::table('employees')->whereNull('deleted_at')->whereNotNull('user_id')->pluck('id', 'user_id');

        $doneLike = fn ($q) => hub_closed_scope($q);
        $openLike = fn ($q) => hub_open_scope($q);

        return $users->map(function ($u) use ($since, $perf, $empIds, $doneLike, $openLike) {
            // المهام المنجزة (وقت الإغلاق التقريبي = آخر تعديل)
            $done = $doneLike(DB::table('tasks')->whereNull('deleted_at')->where('assignee_id', $u->id))
                ->where('updated_at', '>=', $since)->get(['due', 'updated_at']);
            $withDue = $done->filter(fn ($t) => $t->due);
            $onTime = $withDue->filter(fn ($t) => substr($t->updated_at, 0, 10) <= substr($t->due, 0, 10))->count();

            $lateNow = $openLike(DB::table('tasks')->whereNull('deleted_at')->where('assignee_id', $u->id))
                ->whereNotNull('due')->where('due', '<', now()->toDateString())->count();

            // التذاكر المحلولة وزمنها
            $tix = DB::table('tickets')->whereNull('deleted_at')->where('assignee_id', $u->id)
                ->whereIn('status', ['تم الحل', 'مغلقة'])->where('updated_at', '>=', $since)
                ->get(['created_at', 'updated_at', 'meta']);
            $resHours = $tix->map(function ($t) {
                $meta = json_decode((string) $t->meta, true) ?: [];
                $end = $meta['resolved_at'] ?? $t->updated_at;
                return abs(\Illuminate\Support\Carbon::parse($end)->diffInMinutes(\Illuminate\Support\Carbon::parse($t->created_at))) / 60;
            });

            return (object) [
                'id' => $u->id, 'name' => $u->name, 'empId' => $empIds[$u->id] ?? null,
                'done' => $done->count(),
                'onTimePct' => $withDue->count() ? (int) round($onTime * 100 / $withDue->count()) : null,
                'lateNow' => $lateNow,
                'tix' => $tix->count(),
                'avgRes' => $tix->count() ? round($resHours->avg(), 1) : null,
                'rating' => $perf[$u->id] ?? null,
            ];
        })->filter(fn ($p) => $p->done || $p->tix || $p->lateNow || $p->rating)->values();
    }
}
