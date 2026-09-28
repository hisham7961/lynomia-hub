<?php

namespace App\Support\Ai\Reports;

use App\Models\Employee;
use App\Models\User;
use App\Support\Platform\Redactor;
use App\Support\Ai\Ask\AskContext;
use App\Support\Ai\Auditor\Text;
use App\Support\Workforce\DailyWorkCompliance;
use App\Support\Workforce\LeaveDecision;
use App\Support\Workforce\MonthlyAttendance;
use App\Support\Workforce\Workday;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **حقائقُ أداء الموظّف — حتميّةٌ في الخادم قبل أيِّ ذكاء.**
 *
 * لكلِّ فترةٍ (حتى `as_of`: آخرُها إن اكتملت، وإلّا أمس) تُحسب من المصادر القائمة لا من محرّكٍ ثانٍ:
 *  · **التقارير اليوميّة والحضور:** `DailyWorkCompliance::resolveRange` يوماً بيوم — مقدَّم/ناقص/متأخّر/معذور،
 *    وحاضر/غائب/متأخّر/إجازة/ميدانيّ وساعاتُ الحضور؛ وعطلةُ الأسبوع من `MonthlyAttendance::isWeekend`.
 *  · **الساعاتُ المُبلَّغة والمشاريع:** البنودُ الصالحة (`isValidReportRow`) — مجموعُها وتوزيعُها على المشاريع.
 *  · **مراجعةُ التقارير:** مقبول/يحتاج تنقيحاً/بانتظار (`ReportReview`).
 *  · **المهامّ:** أُسندت · أُنجزت (`completed_at`) · في الموعد · فات موعدُها في الفترة.
 *  · **الإجازات** (المعتمدةُ أيّاماً داخل الفترة، والمعلّقة، والمرفوضة) · **العهدة** (أصولٌ بيده وحركاتُها)
 *  · **التذاكر والمشكلات** المُسندة إليه والمحلولة · **نتائجُ المدقّق** عن عمله (عدداً بالشدّة لا نصّاً).
 *
 * **ولا يُقرأ عمودٌ حسّاس:** لا راتبَ ولا بدلاتٍ ولا هويّةَ ولا حساباً بنكيّاً ولا رصيدَ عهدةٍ ماليّة —
 * الأعمدةُ مُسمّاةٌ صراحةً في كلِّ استعلام، والهويّةُ (`PerformanceIdentity`) بلا `fieldsec`.
 */
final class PerformanceFacts
{
    /** أقصى تقاريرَ من نصوص الموظّف تُرسَل عيّنةً */
    public const SAMPLE = 15;

    /** حروفُ الحقل الواحد في العيّنة */
    public const CLIP = 300;

    /** سقفُ حروف العيّنة كلِّها */
    public const SAMPLE_CHARS = 8000;

    /** أقصى مشاريعَ تُذكر في التوزيع */
    public const MAX_PROJECTS = 8;

    /** سقفُ المهامّ المقروءة للفترة */
    public const TASKS_CAP = 1000;

    /**
     * **الحقائقُ للتخزين والعرض** — أو `null` إن لم يمضِ من الفترة يومٌ كامل.
     * المشاريعُ تُحفظ بمعرّفها ورمزها (`PR1`) — والمعرّفُ لا يغادر الخادم (`forModel`).
     */
    public static function compute(Employee $emp, array $p, User $id): ?array
    {
        $asOf = PerformancePeriod::asOf($p);
        if ($asOf === null) return null;

        $dates = [];
        for ($d = Carbon::parse($p['from']); $d->toDateString() <= $asOf; $d->addDay()) $dates[] = $d->toDateString();

        return [
            'period' => ['kind' => $p['kind'], 'key' => $p['key'], 'from' => $p['from'], 'to' => $p['to'],
                'complete' => PerformancePeriod::complete($p), 'as_of' => $asOf, 'days' => count($dates)],
            ...self::daily($emp, $dates),
            'work' => self::work($emp, $p['from'], $asOf, $id),
            'tasks' => self::tasks($emp, $p['from'], $asOf, $id),
            'leaves' => self::leaves($emp, $p['from'], $asOf, $id),
            'custody' => self::custody($emp, $p['from'], $asOf, $id),
            'tickets' => self::tickets($emp, $p['from'], $asOf, $id),
            'findings' => self::findings($emp, $p['from'], $asOf),
        ];
    }

    /** التقاريرُ اليوميّةُ والحضورُ يوماً بيوم — من المُحلِّل المركزيّ */
    private static function daily(Employee $emp, array $dates): array
    {
        // يومٌ زائدٌ في طرف المدى ثمّ يُهمَل: `resolveRange` يرشّح بـ`whereBetween` على أعمدة تاريخ، وSQLite تخزّنها
        // «Y-m-d 00:00:00» فتُسقط آخرَ يومٍ من مدىً ينتهي بـ«Y-m-d» (مقارنةٌ نصّيّة) — وMySQL لا تُسقطه.
        $pad = Carbon::parse((string) end($dates))->addDay()->toDateString();
        $cells = DailyWorkCompliance::resolveRange(collect([$emp]), [...$dates, $pad])[$emp->id] ?? [];
        $required = (string) setting('work.report_required', '1') === '1';
        $r = ['workdays' => 0, 'submitted' => 0, 'missing' => 0, 'late' => 0, 'excused' => 0, 'compliance_pct' => null,
            'items' => 0, 'review' => ['accepted' => 0, 'needs_revision' => 0, 'pending' => 0]];
        $a = ['present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0, 'excused' => 0, 'field' => 0, 'hours' => 0.0];

        foreach ($dates as $d) {
            $c = $cells[$d] ?? null;
            if ($c === null) continue;
            $workday = ! MonthlyAttendance::isWeekend($d);
            if ($workday) $r['workdays']++;

            // الحضور — الفيزيائيّ لا يُطمَس
            if ($c['checked_in']) {
                $a['present']++;
                $a['hours'] += (float) $c['hours'];
                if ($c['late_arrival'] ?? false) $a['late']++;
                if (in_array($c['physical'] ?? '', [Workday::FIELD, Workday::REMOTE], true)) $a['field']++;
            } elseif ($c['on_leave']) {
                $a['leave']++;
            } elseif ($workday && $c['excused']) {
                $a['excused']++;
            } elseif ($workday || $c['attendance'] !== null) {
                $a['absent']++;
            }

            // التقرير — الامتثالُ من الوجود لا من قبول المدير
            if ($c['report_submitted']) {
                $r['submitted']++;
                $r['items'] += (int) $c['report_count'];
                if ($c['late']) $r['late']++;
                foreach (['accepted', 'needs_revision', 'pending'] as $k) $r['review'][$k] += (int) ($c['review'][$k] ?? 0);
            } elseif ($workday && ($c['on_leave'] || $c['excused'])) {
                $r['excused']++;
            } elseif ($workday && $required) {
                $r['missing']++;
            }
        }
        $den = $r['submitted'] + $r['missing'];
        $r['compliance_pct'] = $den > 0 ? (int) round($r['submitted'] * 100 / $den) : null;
        $a['hours'] = round($a['hours'], 1);

        return ['reports' => $r, 'attendance' => $a];
    }

    /** البنودُ الصالحة في الفترة — بعين الهويّة (`hub_scope('updates')`) */
    public static function reportRows(Employee $emp, string $from, string $to, User $id, int $limit = 2000)
    {
        if (! $emp->user_id) return collect();

        return hub_scope(DB::table('work_updates')->whereNull('deleted_at'), 'updates', $id)
            ->where('created_by', (string) $emp->user_id)
            ->where('work_date', '>=', $from)->where('work_date', '<=', $to . ' 23:59:59')
            ->orderBy('work_date')->orderBy('created_at')->orderBy('id')->limit($limit)
            ->get(['id', 'work_date', 'project_id', 'hours', 'progress', 'review_status', 'done', 'doing', 'problems', 'needs', 'next', 'created_at'])
            ->filter(fn ($r) => DailyWorkCompliance::isValidReportRow($r))->values();
    }

    /** الساعاتُ المُبلَّغة وتوزيعُها على المشاريع — المشروعُ رمزٌ ثابتٌ بترتيب الساعات */
    private static function work(Employee $emp, string $from, string $to, User $id): array
    {
        $rows = self::reportRows($emp, $from, $to, $id);
        $by = [];
        $internal = 0.0;
        foreach ($rows as $r) {
            $h = (float) ($r->hours ?? 0);
            if (blank($r->project_id)) { $internal += $h; continue; }
            $k = (string) $r->project_id;
            $by[$k] ??= ['id' => $k, 'reports' => 0, 'hours' => 0.0];
            $by[$k]['reports']++;
            $by[$k]['hours'] += $h;
        }
        $list = array_values($by);
        usort($list, fn ($x, $y) => [$y['hours'], $y['reports'], $x['id']] <=> [$x['hours'], $x['reports'], $y['id']]);
        $list = array_slice($list, 0, self::MAX_PROJECTS);
        foreach ($list as $i => &$pr) {
            $pr['code'] = 'PR' . ($i + 1);
            $pr['hours'] = round($pr['hours'], 1);
        }
        unset($pr);

        return ['reported_hours' => round((float) $rows->sum(fn ($r) => (float) ($r->hours ?? 0)), 1),
            'items' => $rows->count(), 'projects_count' => count($by), 'projects' => $list,
            'internal_hours' => round($internal, 1)];
    }

    /** المهامّ المُسندة إليه في الفترة — عيّنةٌ مسقوفةٌ بترتيبٍ حتميّ ثم حسابٌ في PHP (لا لهجةَ تاريخٍ خاصّة) */
    private static function tasks(Employee $emp, string $from, string $to, User $id): ?array
    {
        if (! $emp->user_id) return null;
        $end = $to . ' 23:59:59';
        $rows = hub_scope(DB::table('tasks')->whereNull('deleted_at'), 'tasks', $id)
            ->where('assignee_id', (string) $emp->user_id)
            ->where(fn ($w) => $w->whereBetween('created_at', [$from, $end])
                ->orWhereBetween('completed_at', [$from, $end])
                ->orWhereBetween('due', [$from, $to]))
            ->orderBy('created_at')->orderBy('id')->limit(self::TASKS_CAP)
            ->get(['id', 'status', 'due', 'completed_at', 'created_at']);

        $t = ['assigned' => 0, 'completed' => 0, 'with_due' => 0, 'on_time' => 0, 'on_time_pct' => null, 'overdue' => 0,
            'capped' => $rows->count() >= self::TASKS_CAP];
        foreach ($rows as $x) {
            $created = substr((string) $x->created_at, 0, 10);
            $done = $x->completed_at ? substr((string) $x->completed_at, 0, 10) : null;
            $due = $x->due ? substr((string) $x->due, 0, 10) : null;
            if ($created >= $from && $created <= $to) $t['assigned']++;
            if ($done !== null && $done >= $from && $done <= $to) {
                $t['completed']++;
                if ($due !== null) {
                    $t['with_due']++;
                    if ($done <= $due) $t['on_time']++;
                }
            }
            // فات موعدُه داخل الفترة: الموعدُ فيها ولم يُنجَز حتى الموعد
            if ($due !== null && $due >= $from && $due <= $to && ($done === null || $done > $due)) $t['overdue']++;
        }
        $t['on_time_pct'] = $t['with_due'] > 0 ? (int) round($t['on_time'] * 100 / $t['with_due']) : null;

        return $t;
    }

    /** الإجازاتُ المتقاطعةُ مع الفترة — أيّامُ المعتمَد داخلها بالنوع، والمعلّقُ والمرفوض عدداً */
    private static function leaves(Employee $emp, string $from, string $to, User $id): ?array
    {
        if (! Schema::hasTable('leave_requests')) return null;
        $rows = hub_scope(DB::table('leave_requests')->whereNull('deleted_at'), 'leaves', $id)
            ->where('emp_id', (string) $emp->id)
            ->where('date_from', '<=', $to . ' 23:59:59')
            ->where(fn ($w) => $w->where('date_to', '>=', $from)->orWhereNull('date_to'))
            ->orderBy('date_from')->orderBy('id')->limit(200)
            ->get(['type', 'status', 'date_from', 'date_to']);

        $out = ['approved_days' => 0, 'by_type' => [], 'pending' => 0, 'rejected' => 0];
        foreach ($rows as $l) {
            $status = (string) $l->status;
            if ($status === 'معتمد') {
                $f = max($from, substr((string) $l->date_from, 0, 10));
                $t = min($to, substr((string) ($l->date_to ?: $l->date_from), 0, 10));
                $n = $t >= $f ? Carbon::parse($f)->diffInDays(Carbon::parse($t)) + 1 : 0;
                $type = (string) ($l->type ?: 'أخرى');
                $out['by_type'][$type] = ($out['by_type'][$type] ?? 0) + (int) $n;
                $out['approved_days'] += (int) $n;
            } elseif (in_array($status, LeaveDecision::PENDING, true)) {
                $out['pending']++;
            } elseif ($status === 'مرفوض') {
                $out['rejected']++;
            }
        }
        ksort($out['by_type']);

        return $out;
    }

    /** العهدة: أصولٌ بيده الآن، وحركاتُ عهدته في الفترة — بلا قيمةٍ ماليّة */
    private static function custody(Employee $emp, string $from, string $to, User $id): ?array
    {
        if (! $emp->user_id) return null;
        $uid = (string) $emp->user_id;
        $held = (int) hub_scope(DB::table('assets')->whereNull('deleted_at'), 'assets', $id)->where('holder_id', $uid)->count();
        $moves = Schema::hasTable('asset_custody')
            ? (int) hub_scope(DB::table('asset_custody')->whereNull('deleted_at'), 'assets', $id)->where('user_id', $uid)
                ->whereBetween('at', [$from, $to . ' 23:59:59'])->count()
            : 0;

        return ['assets_held' => $held, 'moves' => $moves];
    }

    /** التذاكرُ والمشكلاتُ المُسندةُ إليه — المحلولُ في الفترة والمفتوحُ منها */
    private static function tickets(Employee $emp, string $from, string $to, User $id): ?array
    {
        if (! $emp->user_id) return null;
        $uid = (string) $emp->user_id;
        $end = $to . ' 23:59:59';
        $tk = fn () => hub_scope(DB::table('tickets')->whereNull('deleted_at'), 'tickets', $id)->where('assignee_id', $uid);
        $is = fn () => hub_scope(DB::table('issues')->whereNull('deleted_at'), 'issues', $id)->where('assignee_id', $uid);

        return [
            'tickets_assigned' => (int) $tk()->whereBetween('created_at', [$from, $end])->count(),
            'tickets_resolved' => (int) hub_closed_scope($tk())->whereBetween('updated_at', [$from, $end])->count(),
            'issues_assigned' => (int) $is()->whereBetween('created_at', [$from, $end])->count(),
            'issues_resolved' => (int) hub_closed_scope($is())->whereBetween('closed', [$from, $to])->count(),
        ];
    }

    /** نتائجُ المدقّق عن عمله في الفترة — أعدادٌ بالشدّة لا نصوص (النصُّ حكمٌ خامٌ يُعاد تنطيقُه لكلِّ مشاهد) */
    private static function findings(Employee $emp, string $from, string $to): ?array
    {
        if (! $emp->user_id || ! Schema::hasTable('ai_findings')) return null;
        $rows = DB::table('ai_findings')->where('subject_user_id', (string) $emp->user_id)
            ->whereBetween('detected_at', [$from, $to . ' 23:59:59'])
            ->selectRaw('severity, status, COUNT(*) n')->groupBy('severity', 'status')->get();
        $out = ['total' => 0, 'high' => 0, 'medium' => 0, 'info' => 0, 'open' => 0];
        foreach ($rows as $r) {
            $n = (int) $r->n;
            $out['total'] += $n;
            $sev = (string) $r->severity;
            if (isset($out[$sev]) && $sev !== 'total' && $sev !== 'open') $out[$sev] += $n;
            if ((string) $r->status === 'open') $out['open'] += $n;
        }

        return $out;
    }

    // ══════════════════ ما يغادر الخادم ══════════════════

    /** الحقائقُ كما يراها النموذج — بلا معرّفاتٍ (المشروعُ رمزُه وحدَه) */
    public static function forModel(array $facts): array
    {
        $f = $facts;
        $f['work']['projects'] = array_map(fn ($p) => ['project' => '[' . $p['code'] . ']', 'reports' => $p['reports'],
            'hours' => $p['hours']], (array) ($facts['work']['projects'] ?? []));

        return $f;
    }

    /**
     * **عيّنةُ نصوص الموظّف نفسِه** — أحدثُ التقارير الصالحة في الفترة (حتى `SAMPLE`) بترتيبٍ زمنيّ،
     * **منقّحةً قبل القصّ** ومحيّدةً، واسمُه فيها يصير `[E]`، والمشروعُ رمزُه، وبسقفِ حروفٍ كلّيّ.
     *
     * @return list<array>
     */
    public static function sample(Employee $emp, array $facts, User $id): array
    {
        $rows = self::reportRows($emp, (string) $facts['period']['from'], (string) $facts['period']['as_of'], $id)
            ->sortByDesc(fn ($r) => substr((string) $r->work_date, 0, 10) . '|' . $r->created_at . '|' . $r->id)
            ->take(self::SAMPLE)
            ->sortBy(fn ($r) => substr((string) $r->work_date, 0, 10) . '|' . $r->created_at . '|' . $r->id)->values();
        $codes = [];
        foreach ((array) ($facts['work']['projects'] ?? []) as $p) $codes[(string) $p['id']] = (string) $p['code'];

        $names = array_values(array_filter([trim((string) $emp->name)], fn ($n) => mb_strlen($n) >= 2));
        $clean = function ($v) use ($names) {
            $s = Redactor::text((string) $v);
            foreach ($names as $n) $s = str_replace($n, '[E]', $s);

            return AskContext::neutralize(Text::clip($s, self::CLIP));
        };

        $out = [];
        $chars = 0;
        foreach ($rows as $r) {
            $item = ['date' => substr((string) $r->work_date, 0, 10)];
            if (! blank($r->project_id)) $item['project'] = isset($codes[(string) $r->project_id]) ? '[' . $codes[(string) $r->project_id] . ']' : 'مشروعٌ آخر';
            if ($r->hours !== null) $item['hours'] = round((float) $r->hours, 2);
            $item['review'] = (string) ($r->review_status ?: 'pending_review');
            foreach (ProjectReportDigest::TEXT_FIELDS as $f) {
                $v = trim((string) ($r->{$f} ?? ''));
                if ($v !== '') $item[$f] = $clean($v);
            }
            $len = mb_strlen((string) json_encode($item, JSON_UNESCAPED_UNICODE));
            if ($out !== [] && $chars + $len > self::SAMPLE_CHARS) break;
            $out[] = $item;
            $chars += $len;
        }

        return $out;
    }
}
