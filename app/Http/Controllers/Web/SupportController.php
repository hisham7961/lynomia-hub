<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/** لوحة الدعم — طابور التذاكر بمؤقتات SLA ومتوسطات الأداء */
class SupportController extends Controller
{
    protected array $closed = ['تم الحل', 'مغلقة'];

    public function index()
    {
        abort_unless(hub_can(auth()->user(), 'tickets', 'v'), 403);

        // التذاكر المفتوحة + أول رد لكل واحدة باستعلام واحد
        $open = hub_scope(Ticket::query()->whereNull('deleted_at'), 'tickets')
            ->whereNotIn('status', $this->closed)->orderBy('created_at')->limit(60)->get();

        $firstReplies = DB::table('comments')->where('module', 'tickets')
            ->whereIn('record_id', $open->pluck('id'))->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('internal', false)->orWhereNull('internal'))
            ->select('record_id', DB::raw('MIN(created_at) as at'))->groupBy('record_id')
            ->pluck('at', 'record_id');

        $queue = $open->map(function ($t) use ($firstReplies) {
            $t->sla = hub_sla($t, $firstReplies[$t->id] ?? null);
            return $t;
        })->sortBy(fn ($t) => $t->sla['resDue'])->values();

        $kpi = [
            'open'     => $queue->count(),
            'respLate' => $queue->filter(fn ($t) => $t->sla['respPending'] && $t->sla['respLate'])->count(),
            'resLate'  => $queue->filter(fn ($t) => $t->sla['resLate'])->count(),
        ];

        // متوسطات آخر ٣٠ يوماً (المغلقة)
        $doneRows = hub_scope(Ticket::query()->whereNull('deleted_at'), 'tickets')
            ->whereIn('status', $this->closed)->where('created_at', '>=', now()->subDays(30))
            ->limit(300)->get();
        $doneReplies = DB::table('comments')->where('module', 'tickets')
            ->whereIn('record_id', $doneRows->pluck('id'))->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('internal', false)->orWhereNull('internal'))
            ->select('record_id', DB::raw('MIN(created_at) as at'))->groupBy('record_id')
            ->pluck('at', 'record_id');

        $respTimes = []; $resTimes = []; $met = 0;
        foreach ($doneRows as $t) {
            $s = hub_sla($t, $doneReplies[$t->id] ?? null);
            if ($s['respAt']) $respTimes[] = $s['respAt']->diffInMinutes($t->created_at);
            if ($s['resAt']) { $resTimes[] = $s['resAt']->diffInMinutes($t->created_at); if (! $s['resLate']) $met++; }
        }
        $kpi['avgResp'] = $respTimes ? round(abs(array_sum($respTimes) / count($respTimes)) / 60, 1) : null;
        $kpi['avgRes']  = $resTimes ? round(abs(array_sum($resTimes) / count($resTimes)) / 60, 1) : null;
        $kpi['slaPct']  = count($resTimes) ? (int) round($met * 100 / count($resTimes)) : null;
        $kpi['done30']  = $doneRows->count();

        return view('support.index', compact('queue', 'kpi'));
    }
}
