<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\TrackPoint;
use App\Models\TrackSession;
use Illuminate\Support\Facades\DB;

/**
 * عرضُ المشرف للمسار الميدانيّ — لوحةٌ وإعادةُ عرض (المرحلة ج).
 *
 * **المسارُ الدقيقُ خلف سياسةِ دور** (المواصفة): لا يراه كلُّ مدير. المشرفُ
 * (راية `hr.v` + `monitor`) يرى الملخّصَ والمسارَ المبسَّط؛ والنقاطُ الخام
 * الكاملةُ للمالك وحده. وكلُّ فتحٍ للمسار يُدوَّن — «من فتح مسار من ومتى ولماذا».
 */
class FieldController extends Controller
{
    protected function gate(): void
    {
        // مشرفٌ ميدانيّ: يرى الموارد البشرية ويملك رايةَ المراقبة — أو المالك
        abort_unless(hub_is_owner() || (hub_can(auth()->user(), 'hr', 'v') && hub_monitor()), 403,
            'عرضُ المسار الميدانيّ لمشرفي الميدان');
    }

    /**
     * لوحةُ المشرف الميدانيّ: تغطيةُ الدورات، والتزامُ الزيارات (مخطط مقابل فعلي)،
     * ونشاطُ المندوبين — كلُّها محسوبةٌ من بيانات الزيارات والدورات القائمة.
     */
    public function dashboard()
    {
        $this->gate();
        $data = hub_screen('field.dash', 120, function () {
            $vq = fn () => hub_scope(\App\Models\Visit::whereNull('deleted_at'), 'visits');
            $monthStart = now()->startOfMonth()->toDateString();

            // مخطط مقابل فعلي هذا الشهر (على تاريخ التخطيط)
            $planned = (clone $vq())->where('planned_date', '>=', $monthStart)->count();
            $done = (clone $vq())->where('status', 'تمت')->where('planned_date', '>=', $monthStart)->count();
            $missed = (clone $vq())->where('status', 'فائتة')->where('planned_date', '>=', $monthStart)->count();

            // الدورات النشطة بتغطيتها
            $cycles = hub_scope(\App\Models\Cycle::whereNull('deleted_at')->where('status', 'نشط'), 'cycles')
                ->orderByDesc('date_start')->limit(12)->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'cov' => $c->coverage()]);

            // نشاطُ المندوبين هذا الشهر
            $byRep = (clone $vq())->where('planned_date', '>=', $monthStart)
                ->selectRaw('emp_id, count(*) as planned, sum(case when status = ? then 1 else 0 end) as done', ['تمت'])
                ->groupBy('emp_id')->orderByDesc('planned')->limit(20)->get();

            return [
                'planned' => $planned, 'done' => $done, 'missed' => $missed,
                'pct' => $planned > 0 ? (int) round($done * 100 / $planned) : null,
                'cycles' => $cycles, 'byRep' => $byRep,
            ];
        }, ['visits', 'cycles']);

        $repNames = hub_ref_labels('hr', collect($data['byRep'])->pluck('emp_id')->all());

        return view('field.dashboard', compact('data', 'repNames'));
    }

    /** لوحةُ الجلسات — اليومَ والأحدث */
    public function index()
    {
        $this->gate();
        // **عزلُ الشركات على مسارات GPS الحسّاسة**: المشرفُ المحصورُ بشركاتٍ لا يرى
        // جلساتِ غيرها (كما تُنطَّق الزياراتُ في dashboard). المالك = كل الشركات.
        $cids = hub_company_ids();
        $sessions = hub_screen('field.sessions', 60, function () use ($cids) {
            $q = DB::table('track_sessions')->whereNull('deleted_at');
            if ($cids !== null) $q->whereIn('company_id', $cids);

            return $q->orderByDesc('started_at')->orderByDesc('id')->limit(50)
                ->get(['id', 'emp_id', 'field_day', 'status', 'point_count', 'distance_m', 'started_at', 'ended_at']);
        }, ['track_sessions']);
        $names = hub_ref_labels('hr', collect($sessions)->pluck('emp_id')->unique()->values()->all());

        return view('field.sessions', compact('sessions', 'names'));
    }

    /** إعادةُ عرض مسارِ جلسة على خريطة OpenStreetMap */
    public function route(string $id)
    {
        $this->gate();
        $s = TrackSession::whereKey($id)->firstOrFail();

        // العزلُ الصارم: جلسةٌ خارج شركات المشرف = 404 (كنمط findOrFail بعد النطاق)
        $cids = hub_company_ids();
        abort_if($cids !== null && ! in_array((string) $s->company_id, $cids, true), 404);

        // المسارُ المبسَّط للمشرف؛ النقاطُ الخام للمالك وحده (سياسةُ الدور)
        $line = (array) ($s->simplified ?? []);
        if (! $line && $s->active()) {
            // جلسةٌ حيّةٌ لم تُبسَّط بعد — تُعرض نقاطُها المتاحة (مبسَّطةً لحظياً)
            $pts = TrackPoint::where('session_id', $s->id)->orderBy('captured_at')
                ->get(['lat', 'lng'])->map(fn ($p) => [(float) $p->lat, (float) $p->lng])->all();
            $line = \App\Support\Tracking::simplify($pts, 0.00005);
        }
        $rawCount = hub_is_owner() ? TrackPoint::where('session_id', $s->id)->count() : null;

        hub_audit('عرض مسار ميدانيّ', 'tracks', $s->id,
            hub_ref_labels('hr', [$s->emp_id])[$s->emp_id] ?? $s->emp_id);

        return view('field.route', [
            'session' => $s, 'line' => $line, 'rawCount' => $rawCount,
            'empName' => hub_ref_labels('hr', [$s->emp_id])[$s->emp_id] ?? '—',
        ]);
    }
}
