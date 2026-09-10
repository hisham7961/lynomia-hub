<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkUpdate;
use App\Support\DailyWorkCompliance;
use App\Support\Workday;
use Illuminate\Http\Request;

/**
 * **تقريرُ العملِ اليوميّ للجوال (§93) — نفسُ المُحلِّلِ ونفسُ العقد.**
 *
 * قراءةٌ فقط: حالُ اليوم وبنودُه للموظّفِ نفسِه. لا يُبنى تطبيقٌ أصليّ (§93/§130) —
 * جاهزيّةُ الواجهةِ فحسب. التقديمُ يُعادُ استعمالُ CRUD الجوّال للوحدة `updates`
 * (لا مسارَ مكرّر). حسابُ العميلِ محجوبٌ ببوّابة `mobile.portal` وإضافةً هنا (§79).
 */
class MobileReportsController extends Controller
{
    /** GET /api/mobile/v1/work/today — حالةُ امتثالِ اليوم + بنودُه (self) */
    public function today(Request $r)
    {
        if (hub_is_client($r->user())) abort(404);
        $emp = Workday::emp($r->user());
        if (! $emp) return response()->json(['error' => 'no_employee_profile',
            'message' => 'لا ملفَ موظّفٍ نشطاً مربوطاً بحسابك'], 422);

        $date = $this->date($r);
        $c = DailyWorkCompliance::resolve($emp, $date);
        $entries = WorkUpdate::whereNull('deleted_at')->where('created_by', $r->user()->id)
            ->whereDate('work_date', $date)->orderBy('submitted_at')->orderBy('id')
            ->get(['id', 'project_id', 'task_id', 'done', 'hours', 'progress', 'problems',
                'submitted_at', 'review_status', 'review_feedback']);

        return response()->json([
            'compliance' => DailyWorkCompliance::apiShape($c),
            'entries' => $entries->map(fn ($w) => [
                'id' => $w->id, 'project_id' => $w->project_id, 'task_id' => $w->task_id,
                'done' => $w->done, 'hours' => (float) $w->hours,
                'progress' => $w->progress !== null ? (float) $w->progress : null,
                'problems' => $w->problems,
                'submitted_at' => optional($w->submitted_at)->toIso8601String(),
                'review_status' => $w->review_status ?: 'pending_review',
                'review_feedback' => $w->review_feedback,
            ])->values(),
            'submit_hint' => ['method' => 'POST', 'path' => '/api/mobile/v1/updates',
                'note' => 'تقديمُ التقرير = إنشاءُ بندِ عملٍ للوحدة updates (لا مسارَ مكرّر)'],
        ]);
    }

    protected function date(Request $r): string
    {
        $d = trim((string) $r->query('date'));
        if ($d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            try { return \Illuminate\Support\Carbon::parse($d)->toDateString(); } catch (\Throwable $e) {}
        }
        return \App\Support\BusinessDate::today();
    }
}
