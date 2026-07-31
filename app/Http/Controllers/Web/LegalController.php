<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/** المركز القانوني — نظرة واحدة على العقود والرخص والالتزامات وتجديداتها */
class LegalController extends Controller
{
    public function index()
    {
        abort_unless(hub_can(auth()->user(), 'contracts', 'v'), 403, 'المركز القانوني يتطلب صلاحية عرض العقود');

        $base = fn () => hub_scope(DB::table('contracts')->whereNull('deleted_at'), 'contracts');
        $today = now()->toDateString();
        $soon = now()->addDays(60)->toDateString();
        $activeish = fn ($q) => hub_open_scope($q);

        $kpi = [
            'active'  => $activeish($base())->count(),
            'soon'    => $activeish($base())->whereNotNull('date_end')->whereBetween('date_end', [$today, $soon])->count(),
            'overdue' => $activeish($base())->whereNotNull('date_end')->where('date_end', '<', $today)->count(),
            'value'   => (float) $activeish($base())->sum('value'),
        ];

        // التوزيع بالأنواع — للدونات
        $types = $base()->select('type', DB::raw('COUNT(*) c'))->groupBy('type')->orderByDesc('c')->limit(6)->get()
            ->map(fn ($r) => ['label' => $r->type ?: 'غير مصنف', 'value' => (int) $r->c])->all();

        // تنتهي قريباً (أو تجاوزت) — بأيام متبقية
        $expiring = $activeish($base())->whereNotNull('date_end')->where('date_end', '<=', $soon)
            ->orderBy('date_end')->limit(12)
            ->get(['id', 'title', 'type', 'party', 'date_end as end', 'renewal', 'value', 'currency'])
            ->map(function ($c) {
                $c->days = (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($c->end)->startOfDay(), false);
                return $c;
            });

        // التزامات مسجلة
        $obligations = $base()->whereNotNull('obligations')->where('obligations', '!=', '')
            ->orderByDesc('created_at')->limit(8)->get(['id', 'title', 'type', 'obligations']);

        $currency = setting('app.currency', 'د.ك');

        return view('legal.index', compact('kpi', 'types', 'expiring', 'obligations', 'currency'));
    }
}
