<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\WorkUpdate;
use App\Support\DailyWorkCompliance;
use App\Support\Workday;
use Illuminate\Http\Request;

/**
 * **واجهةُ التقارير اليوميّة (REST v1 · §93/§94).**
 *
 * نفسُ المُحلِّلِ المركزيّ الذي يستهلكه الويب (`DailyWorkCompliance`) — لا سلوكٌ ثانٍ.
 * الداخليُّ حصراً: حسابُ العميلِ يُردّ ٤٠٤ (§79/§114). تقديمُ التقريرِ نفسُه يُعادُ
 * استعمالُ CRUD القائم (`POST /api/v1/updates`) — لا مسارَ تقديمٍ مكرّر.
 */
class ReportsApiController extends Controller
{
    /** تقريري اليوم + حالتُه (self) — GET /api/v1/reports/my-daily */
    public function myDaily(Request $r)
    {
        if (hub_is_client($r->user())) abort(404);
        $emp = Workday::emp($r->user());
        if (! $emp) return response()->json(['error' => 'no_employee_profile',
            'message' => 'لا ملفَ موظّفٍ نشطاً مربوطاً بحسابك'], 422);

        $date = $this->date($r);
        $c = DailyWorkCompliance::resolve($emp, $date);
        $entries = WorkUpdate::whereNull('deleted_at')->where('created_by', $r->user()->id)
            ->whereDate('work_date', $date)->orderBy('submitted_at')->orderBy('id')
            ->get(['id', 'project_id', 'task_id', 'done', 'hours', 'progress', 'problems', 'next',
                'submitted_at', 'review_status', 'review_feedback']);

        return response()->json([
            'compliance' => DailyWorkCompliance::apiShape($c),
            'entries' => $entries->map(fn ($w) => [
                'id' => $w->id, 'project_id' => $w->project_id, 'task_id' => $w->task_id,
                'done' => $w->done, 'hours' => (float) $w->hours,
                'progress' => $w->progress !== null ? (float) $w->progress : null,
                'problems' => $w->problems, 'next' => $w->next,
                'submitted_at' => optional($w->submitted_at)->toIso8601String(),
                'review_status' => $w->review_status ?: 'pending_review',
                'review_feedback' => $w->review_feedback,
            ])->values(),
        ]);
    }

    /** حالةُ امتثالِ اليوم فقط (self) — GET /api/v1/reports/today-compliance */
    public function todayCompliance(Request $r)
    {
        if (hub_is_client($r->user())) abort(404);
        $emp = Workday::emp($r->user());
        if (! $emp) return response()->json(['error' => 'no_employee_profile'], 422);

        return response()->json(['compliance' =>
            DailyWorkCompliance::apiShape(DailyWorkCompliance::resolve($emp, $this->date($r)))]);
    }

    /** نظرةُ اليوم للفريق (مدير/HR) — GET /api/v1/reports/daily?date= */
    public function teamDaily(Request $r)
    {
        if (hub_is_client($r->user())) abort(404);
        abort_unless(hub_can($r->user(), 'hr', 'v'), 403);

        $date = $this->date($r);
        $emps = hub_company_scope(hub_scope(Employee::query(), 'hr'), 'hr')
            ->whereNull('deleted_at')->where('status', 'نشط')
            ->orderBy('name')->limit(500)->get(['id', 'name', 'dept', 'user_id']);
        $comp = DailyWorkCompliance::resolveMany($emps, $date);

        return response()->json([
            'date' => $date,
            'employees' => $emps->map(fn ($e) => [
                'employee_id' => $e->id, 'name' => $e->name, 'dept' => $e->dept,
                'compliance' => DailyWorkCompliance::apiShape($comp[$e->id]),
            ])->values(),
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
