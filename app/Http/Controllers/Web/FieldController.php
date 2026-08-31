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

    /** لوحةُ الجلسات — اليومَ والأحدث */
    public function index()
    {
        $this->gate();
        $sessions = hub_screen('field.sessions', 60, function () {
            return DB::table('track_sessions')->whereNull('deleted_at')
                ->orderByDesc('started_at')->orderByDesc('id')->limit(50)
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
