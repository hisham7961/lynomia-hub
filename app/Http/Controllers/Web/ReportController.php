<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/** التقارير المالية v1 — مبنية على وحدة المالية (fin) مباشرة */
class ReportController extends Controller
{
    protected array $income  = ['فاتورة مبيعات', 'دفعة واردة'];
    protected array $expense = ['مصروف', 'فاتورة مشتريات', 'دفعة صادرة'];
    protected array $dead    = ['ملغاة', 'مسودة'];

    public function finance()
    {
        abort_unless(hub_can(auth()->user(), 'fin', 'v'), 403);
        $t = hub_mod('fin')['table'];
        $base = fn () => DB::table($t)->whereNull('deleted_at')->whereNotIn('state', $this->dead);

        $mStart = now()->startOfMonth()->toDateString();
        $sum = fn ($kinds, $from = null) => (float) $base()->whereIn('kind', $kinds)
            ->when($from, fn ($q) => $q->where('date', '>=', $from))->sum('total');

        $cards = [
            'inc'  => $sum($this->income, $mStart),
            'exp'  => $sum($this->expense, $mStart),
            'incY' => $sum($this->income, now()->startOfYear()->toDateString()),
            'expY' => $sum($this->expense, now()->startOfYear()->toDateString()),
        ];

        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $m0 = now()->subMonths($i)->startOfMonth();
            $m1 = $m0->copy()->endOfMonth();
            $inRange = fn ($kinds) => (float) $base()->whereIn('kind', $kinds)
                ->whereBetween('date', [$m0->toDateString(), $m1->toDateString()])->sum('total');
            $months[] = ['l' => $m0->translatedFormat('M'), 'i' => $inRange($this->income), 'e' => $inRange($this->expense)];
        }
        $max = max(1, ...array_merge(array_column($months, 'i'), array_column($months, 'e')));

        $unpaid = $base()->whereIn('state', ['مرسلة', 'مدفوعة جزئياً', 'متأخرة'])
            ->orderBy('due')->limit(12)->get(['id', 'doc_no as no', 'partner', 'total', 'paid', 'due', 'state']);

        $byState = $base()->select('state', DB::raw('COUNT(*) c'), DB::raw('SUM(total) s'))
            ->groupBy('state')->orderByDesc('s')->get();

        $topPartners = $base()->whereIn('kind', $this->income)->whereNotNull('partner')->where('partner', '!=', '')
            ->select('partner', DB::raw('SUM(total) s'))->groupBy('partner')->orderByDesc('s')->limit(7)->get();

        $currency = setting('app.currency', 'د.ك');

        return view('reports.finance', compact('cards', 'months', 'max', 'unpaid', 'byState', 'topPartners', 'currency'));
    }
}
