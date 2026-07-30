<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/** لوحة CEO — صورة الشركة كاملة في شاشة واحدة (للمالكين) */
class CeoController extends Controller
{
    protected array $income  = ['فاتورة مبيعات', 'دفعة واردة'];
    protected array $expense = ['مصروف', 'فاتورة مشتريات', 'دفعة صادرة'];
    protected array $dead    = ['ملغاة', 'مسودة'];

    public function index()
    {
        abort_unless(auth()->user()->role?->is_owner, 403, 'لوحة CEO للمالكين فقط');

        $fin = fn () => DB::table('fin_documents')->whereNull('deleted_at')->whereNotIn('state', $this->dead);
        $sum = fn ($kinds, $from) => (float) $fin()->whereIn('kind', $kinds)->where('date', '>=', $from)->sum('total');
        $m0 = now()->startOfMonth()->toDateString();
        $y0 = now()->startOfYear()->toDateString();

        // المؤشرات العليا
        $kpi = [
            'netM'    => $sum($this->income, $m0) - $sum($this->expense, $m0),
            'netY'    => $sum($this->income, $y0) - $sum($this->expense, $y0),
            'unpaid'  => (float) $fin()->whereIn('state', ['مرسلة', 'مدفوعة جزئياً', 'متأخرة'])->sum(DB::raw('total - paid')),
            'projects'=> DB::table('projects')->whereNull('deleted_at')->where(fn ($w) => $w->whereNull('status')->orWhere(fn ($x) => $x->where('status', 'NOT LIKE', '%مكتمل%')->where('status', 'NOT LIKE', '%ملغ%')))->count(),
            'clients' => DB::table('clients')->whereNull('deleted_at')->count(),
            'emps'    => DB::table('employees')->whereNull('deleted_at')->count(),
            'openTasks' => DB::table('tasks')->whereNull('deleted_at')->where(fn ($w) => $w->whereNull('status')->orWhere(fn ($x) => $x->where('status', 'NOT LIKE', '%مكتمل%')->where('status', 'NOT LIKE', '%منجز%')->where('status', 'NOT LIKE', '%ملغ%')))->count(),
            'lateTasks' => DB::table('tasks')->whereNull('deleted_at')->whereNotNull('due')->where('due', '<', now()->toDateString())->where(fn ($w) => $w->whereNull('status')->orWhere(fn ($x) => $x->where('status', 'NOT LIKE', '%مكتمل%')->where('status', 'NOT LIKE', '%منجز%')->where('status', 'NOT LIKE', '%ملغ%')))->count(),
        ];

        // صحة الشركة
        $health = hub_health();

        // ٦ أشهر دخل/مصروف
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $a = now()->subMonths($i)->startOfMonth();
            $b = $a->copy()->endOfMonth();
            $in = fn ($kinds) => (float) $fin()->whereIn('kind', $kinds)->whereBetween('date', [$a->toDateString(), $b->toDateString()])->sum('total');
            $months[] = ['l' => $a->translatedFormat('M'), 'i' => $in($this->income), 'e' => $in($this->expense)];
        }
        $max = max(1, ...array_merge(array_column($months, 'i'), array_column($months, 'e')));

        // تقدم المشاريع الجارية
        $projects = DB::table('projects')->whereNull('deleted_at')
            ->where(fn ($w) => $w->whereNull('status')->orWhere(fn ($x) => $x->where('status', 'NOT LIKE', '%مكتمل%')->where('status', 'NOT LIKE', '%ملغ%')))
            ->orderByDesc('created_at')->limit(6)->get(['id', 'name', 'status'])
            ->map(function ($p) { $p->progress = hub_progress($p->id)['pct']; return $p; });

        // فريق اليوم: إجازات معتمدة تشمل اليوم + حضور اليوم
        $today = now()->toDateString();
        $onLeave = DB::table('leave_requests')->whereNull('leave_requests.deleted_at')
            ->where('leave_requests.status', 'LIKE', '%معتمد%')
            ->where('leave_requests.date_from', '<=', $today)->where('leave_requests.date_to', '>=', $today)
            ->join('employees', 'employees.id', '=', 'leave_requests.emp_id')
            ->get(['employees.name as name', 'leave_requests.type as type', 'leave_requests.date_to as to']);
        $attToday = DB::table('attendance')->whereNull('deleted_at')->where('date', $today)->count();

        // أعلى المستحقات
        $unpaidTop = $fin()->whereIn('state', ['مرسلة', 'مدفوعة جزئياً', 'متأخرة'])
            ->orderByRaw('(total - paid) DESC')->limit(6)->get(['id', 'doc_no as no', 'partner', 'total', 'paid', 'due', 'state']);

        // توزيع المهام المفتوحة بالحالة (للدونات)
        $taskSlices = DB::table('tasks')->whereNull('deleted_at')
            ->select('status', DB::raw('COUNT(*) c'))->groupBy('status')->orderByDesc('c')->limit(6)->get()
            ->map(fn ($r) => ['label' => $r->status ?: 'بلا حالة', 'value' => (int) $r->c])->all();

        $currency = setting('app.currency', 'د.ك');

        return view('ceo.index', compact('kpi', 'health', 'months', 'max', 'projects',
            'onLeave', 'attToday', 'unpaidTop', 'taskSlices', 'currency'));
    }
}
