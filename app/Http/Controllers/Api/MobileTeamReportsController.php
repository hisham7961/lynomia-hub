<?php

namespace App\Http\Controllers\Api;

use App\Models\Employee;
use App\Models\User;
use App\Models\WorkUpdate;
use App\Support\Platform\Api;
use App\Support\Workforce\DailyWorkCompliance;
use App\Support\Workforce\ReportReview;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * **مراجعةُ التقارير اليوميّة للفريق على الجوال** (خطّة التطبيق · 4.4) — نظيرُ
 * `reports/review` + `reports/review/{id}` الويبيّين بالقاعدة نفسِها:
 *  • البوّابة `ReportReview::canReviewAny` (مالك · `hr:v` · `updates:e`)، والعميلُ ٤٠٤.
 *  • الطابور `ReportReview::reviewableQuery` — منطَّقٌ شركةً ومشروعاً، و«مشاريعي»
 *    افتراضُ غيرِ الواسع (`isWideReviewer`) كالويب (F7)، و`scope=team` يوسّعه كـ`?scope=all`.
 *  • الحكمُ `ReportReview::canReview` لكلِّ بند (لا مراجعةَ لتقريرِ النفس إلّا للمالك)،
 *    والفعلُ `ReportReview::act` (مسودةُ المدقّق لا تركب القبول).
 * حقولُ البند تمرّ بـ`hub_field_mode` على وحدة `updates` (المحجوبُ `null`).
 */
class MobileTeamReportsController extends MobileWorkflowController
{
    /** سقفُ بنودِ اليوم في الردّ الواحد */
    private const CAP = 200;

    /** حقولُ البند المعروضة: مفتاحُ الحقل في السجلّ ⇒ العمود */
    private const FIELDS = ['done' => 'done', 'hours' => 'hours', 'progress' => 'progress',
        'problems' => 'problems', 'next' => 'next'];

    /** `GET reports/daily?date=&scope=team|mine&status=` — بنودُ يومٍ قابلةٌ لمراجعتي + ملخّصُها */
    public function daily(Request $r): Response
    {
        $this->tagMobile($r);
        $u = $r->user();
        if (hub_is_client($u)) return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'غير موجود');
        if (! ReportReview::canReviewAny($u)) {
            return Api::error(Api::FORBIDDEN, 403, 'مراجعةُ التقارير للمدير أو الموارد البشرية');
        }

        $date = $this->date($r);
        $scope = (string) $r->query('scope', '');
        $wide = ReportReview::isWideReviewer($u);
        // نظيرُ الويب: غيرُ الواسع افتراضُه «مشاريعي»، و`team` (أو `all`) يوسّع الرقعة
        $mineOnly = $scope === 'mine' || (! $wide && ! in_array($scope, ['team', 'all'], true));
        $status = in_array($s = (string) $r->query('status', 'all'), ['pending', 'accepted', 'needs_revision', 'all'], true) ? $s : 'all';

        $q = ReportReview::reviewableQuery($u, $mineOnly)->whereDate('work_date', $date);
        $all = (clone $q)->get(['id', 'review_status']);
        if ($status === 'pending') {
            $q->where(fn ($w) => $w->whereNull('review_status')->orWhere('review_status', ReportReview::PENDING));
        } elseif ($status !== 'all') {
            $q->where('review_status', $status);
        }
        $items = $q->with(['project:id,name', 'task:id,title'])
            ->orderBy('submitted_at')->orderBy('id')->limit(self::CAP)->get();

        $names = User::whereIn('id', $items->pluck('created_by')->filter()->unique())->pluck('name', 'id');

        return $this->ok([
            'date' => $date,
            'scope' => $mineOnly ? 'mine' : 'team',
            'status' => $status,
            'summary' => [
                'total' => $all->count(),
                'pending' => $all->filter(fn ($w) => ! $w->review_status || $w->review_status === ReportReview::PENDING)->count(),
                'accepted' => $all->where('review_status', ReportReview::ACCEPTED)->count(),
                'needs_revision' => $all->where('review_status', ReportReview::NEEDS_REVISION)->count(),
            ],
            'entries' => $items->map(fn (WorkUpdate $w) => $this->entry($w, $u, $names))->values()->all(),
            'truncated' => $items->count() >= self::CAP,
            // امتثالُ الحضور×التقرير لكلِّ موظّف — لحامل `hr:v` وحدَه (نظيرُ reports/daily الويبيّ)
            'compliance' => hub_can($u, 'hr', 'v') ? $this->compliance($date) : null,
        ]);
    }

    /**
     * `POST reports/daily/{id}/review` — `{action: accept|needs_revision|reopen, feedback?}`.
     * خارجَ مراجعتي ٤٠٣، والتنقيحُ بلا ملاحظةٍ ٤٢٢، والفعلُ المجهولُ ٤٢٢. `Idempotency-Key`.
     */
    public function review(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        $u = $r->user();
        if (hub_is_client($u)) return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'غير موجود');

        $w = WorkUpdate::whereNull('deleted_at')->whereKey($id)->first();
        if (! $w) return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'التقرير غير موجود');
        if (! ReportReview::canReview($u, $w)) {
            return Api::error(Api::FORBIDDEN, 403, 'هذا التقرير خارجَ مراجعتك');
        }

        $data = $r->validate([
            'action' => ['required', 'string', 'in:' . implode(',', ReportReview::ACTIONS)],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ], [], ['action' => 'الإجراء', 'feedback' => 'الملاحظة']);

        return $this->idempotent($r, function () use ($w, $u, $data) {
            $outcome = ReportReview::act($w, $u, $data['action'], (string) ($data['feedback'] ?? ''));
            if ($outcome === 'feedback_required') {
                return Api::error(Api::VALIDATION_FAILED, 422,
                    'طلبُ التنقيح يحتاج ملاحظةً للموظف — اكتب ما المطلوب تحسينُه.',
                    ['feedback' => ['required']]);
            }

            $fresh = WorkUpdate::with(['project:id,name', 'task:id,title'])->whereKey($w->id)->firstOrFail();
            $names = User::whereKey($fresh->created_by)->pluck('name', 'id');

            return $this->ok(['outcome' => $outcome, 'entry' => $this->entry($fresh, $u, $names)]);
        });
    }

    /** بطاقةُ بندٍ — حقولُه عبر `hub_field_mode` (المحجوبُ null)، وقدرتي على مراجعته */
    private function entry(WorkUpdate $w, User $u, $names): array
    {
        $out = [
            'id' => (string) $w->id,
            'work_date' => $w->work_date ? substr((string) $w->work_date, 0, 10) : null,
            'author' => ['id' => (string) $w->created_by, 'name' => (string) ($names[$w->created_by] ?? '')],
            'project' => $w->project_id ? ['id' => (string) $w->project_id,
                'name' => hub_can($u, 'projects', 'v') ? optional($w->project)->name : null] : null,
            'task' => $w->task_id ? ['id' => (string) $w->task_id,
                'title' => hub_can($u, 'tasks', 'v') ? optional($w->task)->title : null] : null,
        ];
        foreach (self::FIELDS as $key => $col) {
            $v = hub_field_mode($u, 'updates', $key) === 'hide' ? null : $w->{$col};
            $out[$col] = in_array($col, ['hours', 'progress'], true) && $v !== null ? (float) $v : $v;
        }

        return $out + [
            'submitted_at' => self::iso($w->submitted_at),
            'review_status' => $w->review_status ?: ReportReview::PENDING,
            'review_feedback' => $w->review_feedback,
            'reviewed_at' => self::iso($w->reviewed_at),
            'can_review' => ReportReview::canReview($u, $w),
        ];
    }

    /** امتثالُ اليوم لموظّفي النطاق (المُحلِّلُ المركزيّ نفسُه — نظيرُ `ReportsApiController::teamDaily`) */
    private function compliance(string $date): array
    {
        $emps = hub_company_scope(hub_scope(Employee::query(), 'hr'), 'hr')
            ->whereNull('deleted_at')->where('status', 'نشط')
            ->orderBy('name')->orderBy('id')->limit(500)->get(['id', 'name', 'dept', 'user_id']);
        $comp = DailyWorkCompliance::resolveMany($emps, $date);

        return $emps->map(fn ($e) => [
            'employee_id' => (string) $e->id,
            'name' => hub_field_mode(auth()->user(), 'hr', 'name') === 'hide' ? null : $e->name,
            'dept' => hub_field_mode(auth()->user(), 'hr', 'dept') === 'hide' ? null : $e->dept,
            'compliance' => DailyWorkCompliance::apiShape($comp[$e->id]),
        ])->values()->all();
    }

    private function date(Request $r): string
    {
        $d = trim((string) $r->query('date'));
        if ($d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            try { return \Illuminate\Support\Carbon::parse($d)->toDateString(); } catch (\Throwable $e) {}
        }

        return \App\Support\Platform\BusinessDate::today();
    }
}
