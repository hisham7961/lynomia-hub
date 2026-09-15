<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkUpdate;
use App\Support\DailyWorkCompliance;
use App\Support\ReportReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * **مركزُ التقارير اليوميّة — قراءةٌ ومراجعةٌ فوق WorkUpdate القائم (§17).**
 *
 * ليس محرّكَ تقاريرَ ثانياً: يقرأ بنودَ العمل والحضورَ والمشاريعَ والمهامَّ القائمةَ
 * عبر المُحلِّلِ المركزيّ `DailyWorkCompliance` (§101) — فيُجيب المدير: من حضر، من
 * كتب تقريراً، من حضر ولم يكتب، ماذا عمل كلٌّ، على أيّ مشروع، وما راجعه المدير (§129).
 *
 * كلُّ سطحٍ منطَّقٌ بالشركة (§80) والمشروع (§33)، وحسابُ العميل يُردّ ٤٠٤ (§79).
 */
class ReportsController extends Controller
{
    /* ────────── الحرّاس ────────── */

    /** حسابُ العميلِ لا يرى أيَّ تقريرٍ داخليٍّ إطلاقاً (§79/§114) */
    protected function guardInternal(): void
    {
        abort_if(hub_is_client(auth()->user()), 404);
    }

    /** بوّابةُ رؤيةِ امتثالِ الحضورِ لكلِّ الموظّفين — HR/مالك (§34) */
    protected function guardTeam(): void
    {
        $this->guardInternal();
        abort_unless(hub_can(auth()->user(), 'hr', 'v'), 403);
    }

    /**
     * بوّابةُ الحضورِ الشهريّ (المحاسب §34): حضورٌ وانصرافٌ وأثرٌ محتسَبٌ للراتب — يكفيها
     * `attend:v` (يمنحها المالكُ للمحاسب) أو `hr:v`. لا محتوى تقاريرَ هنا (فصلُ النطاق).
     */
    protected function guardMonthly(): void
    {
        $this->guardInternal();
        abort_unless(hub_can(auth()->user(), 'attend', 'v') || hub_can(auth()->user(), 'hr', 'v'), 403);
    }

    /** موظّفو النطاق للحضور الشهريّ — عزلُ الشركة (المحاسبُ يرى شركتَه، والمالكُ الكلّ §80) */
    protected function monthlyEmployees()
    {
        $q = Employee::whereNull('deleted_at')->where('status', 'نشط');
        $cids = hub_company_ids(auth()->user());
        if ($cids !== null) $q->whereIn('company_id', $cids);
        return $q;
    }

    /* ═══════════ §17/§18 مركزُ التقارير — نظرةُ اليوم ═══════════ */

    public function index(Request $r)
    {
        $this->guardTeam();
        $date = $this->validDate($r->query('date')) ?: \App\Support\BusinessDate::today();

        $emps = hub_company_scope(hub_scope(Employee::query(), 'hr'), 'hr')
            ->whereNull('deleted_at')->where('status', 'نشط');
        if ($dept = trim((string) $r->query('dept'))) $emps->where('dept', $dept);
        if ($eq = trim((string) $r->query('q'))) $emps->where('name', 'like', '%' . $eq . '%');
        $emps = $emps->orderBy('name')->get(['id', 'name', 'dept', 'user_id']);

        $comp = DailyWorkCompliance::resolveMany($emps, $date);

        // مرشّحُ الامتثال (اختياريّ)
        $filter = (string) $r->query('compliance', '');
        $rows = collect($comp)->values();
        if ($filter !== '') {
            $rows = $rows->filter(fn ($c) => match ($filter) {
                'reported' => $c['report_submitted'],
                'missing' => $c['state'] === DailyWorkCompliance::PRESENT_WITHOUT_REPORT,
                'pending' => $c['compliance'] === 'pending',
                'absence' => $c['effective'] === 'absent_due_to_missing_report',
                'present' => $c['checked_in'],
                default => true,
            })->values();
        }

        // ملخّصُ التغطية (§35)
        $summary = [
            'expected' => $emps->count(),
            'reported' => collect($comp)->where('report_submitted', true)->count(),
            'missing'  => collect($comp)->filter(fn ($c) => $c['state'] === DailyWorkCompliance::PRESENT_WITHOUT_REPORT)->count(),
            'pending'  => collect($comp)->where('compliance', 'pending')->count(),
            'late'     => collect($comp)->where('late', true)->count(),
            'absence'  => collect($comp)->where('effective', 'absent_due_to_missing_report')->count(),
            'pending_review' => collect($comp)->sum(fn ($c) => $c['review']['pending']),
            'needs_revision' => collect($comp)->sum(fn ($c) => $c['review']['needs_revision']),
        ];
        $projLabels = hub_ref_labels('projects',
            collect($comp)->pluck('projects')->flatten(1)->filter()->unique()->values()->all());

        return view('reports.index', compact('date', 'rows', 'summary', 'projLabels')
            + ['filter' => $filter, 'q' => $r->query('q'), 'dept' => $r->query('dept')]);
    }

    /* ═══════════ §68 التفصيلُ — يومٌ لموظّف ═══════════ */

    public function day(Request $r)
    {
        $this->guardTeam();
        $emp = Employee::whereNull('deleted_at')->whereKey($r->query('emp'))->firstOrFail();
        // تنطيقُ الشركة: لا يُرى موظّفُ شركةٍ أخرى (§80/§115)
        abort_unless(hub_company_scope(hub_scope(Employee::query(), 'hr'), 'hr')
            ->whereKey($emp->id)->exists(), 404);

        $date = $this->validDate($r->query('date')) ?: \App\Support\BusinessDate::today();
        $c = DailyWorkCompliance::resolve($emp, $date);

        // بنودُ اليوم — مع المشروع والمهمّة (§68 · بلا N+1)
        $entries = $emp->user_id
            ? WorkUpdate::with(['project:id,name', 'task:id,title,progress,est_h,act_h'])
                ->whereNull('deleted_at')->where('created_by', $emp->user_id)
                ->whereDate('work_date', $date)->orderBy('submitted_at')->orderBy('id')->get()
            : collect();

        return view('reports.day', compact('emp', 'date', 'c', 'entries'));
    }

    /* ═══════════ §31 مركزُ المراجعة — طابورُ التقارير ═══════════ */

    public function review(Request $r)
    {
        $this->guardInternal();
        abort_unless(ReportReview::canReviewAny(auth()->user()), 403);

        $status = in_array($r->query('status'), ['accepted', 'needs_revision'], true)
            ? $r->query('status') : 'pending';

        /*
         * «مشاريعي فقط» (الجولة 1 · F7): مديرُ مشاريعَ بلا hr:v كان يرى طابورَ
         * كلِّ مشاريعِ نطاقِه — ضجيجُ ما لا يخصّه فوق ما ينتظر قرارَه فعلاً
         * (وكيل المحاكاة 4). لغير HR/المالك الافتراضيُّ «مشاريعي»، والرقعةُ
         * الأوسع بنقرة (?scope=all).
         */
        $uu = auth()->user();
        $isWide = ($uu->role?->is_owner ?? false) || hub_can($uu, 'hr', 'v');
        $mineOnly = $r->query('scope') === 'mine' || (! $isWide && $r->query('scope') !== 'all');

        $q = $this->reviewableUpdates($mineOnly);
        if ($status === 'pending') {
            $q->where(fn ($w) => $w->whereNull('review_status')->orWhere('review_status', ReportReview::PENDING));
        } else {
            $q->where('review_status', $status);
        }
        if ($pid = $r->query('project')) $q->where('project_id', $pid);
        if ($from = $this->validDate($r->query('from'))) $q->whereDate('work_date', '>=', $from);
        if ($to = $this->validDate($r->query('to'))) $q->whereDate('work_date', '<=', $to);

        $items = $q->with(['project:id,name', 'task:id,title'])
            ->orderByDesc('work_date')->orderByDesc('id')->paginate(30)->withQueryString();

        // كاتبو البنود (لأسماء الموظّفين) دفعةً — لا N+1
        $names = User::whereIn('id', $items->pluck('created_by')->filter()->unique())
            ->pluck('name', 'id');

        return view('reports.review', compact('items', 'names', 'status', 'mineOnly'));
    }

    /** POST — قبول / طلب تنقيح / إعادة فتح (§27/§28) */
    public function reviewAct(Request $r, string $id)
    {
        $this->guardInternal();
        $w = WorkUpdate::whereNull('deleted_at')->whereKey($id)->firstOrFail();
        abort_unless(ReportReview::canReview(auth()->user(), $w), 403);

        $action = (string) $r->input('action');
        $feedback = trim((string) $r->input('feedback', ''));

        match ($action) {
            'accept' => ReportReview::accept($w, auth()->user(), $feedback ?: null),
            'needs_revision' => $feedback !== ''
                ? ReportReview::needsRevision($w, auth()->user(), $feedback)
                : null,
            'reopen' => ReportReview::reopen($w, auth()->user()),
            default => null,
        };

        if ($action === 'needs_revision' && $feedback === '') {
            return back()->with('err', 'طلبُ التنقيح يحتاج ملاحظةً للموظف — اكتب ما المطلوب تحسينُه.');
        }

        return back()->with('ok', match ($action) {
            'accept' => 'اعتُمد التقرير — أُشعر الموظف. القبولُ لا يمسّ الساعات.',
            'needs_revision' => 'طُلب التنقيح — أُشعر الموظف بملاحظتك.',
            'reopen' => 'أُعيد فتحُ التقرير للمراجعة.',
            default => 'لا إجراء.',
        });
    }

    /** POST — ختمُ الأثرِ الفعّالِ اليدويّ (§40/§90): غياب/معذور/حاضر بعد قبول متأخر */
    public function finalize(Request $r, string $id)
    {
        $this->guardInternal();
        abort_unless(hub_can(auth()->user(), 'hr', 'e') || auth()->user()->role?->is_owner, 403);
        $row = Attendance::whereNull('deleted_at')->whereKey($id)->firstOrFail();
        // تنطيقُ الدورِ الدائم (§80): كان `hub_company_scope` (فلترُ الجلسةِ للتركيز) يخلو
        // عند غياب شركةٍ نشطةٍ فيمرّ صفُّ أيِّ شركة — فمحرّرُ HR لشركةٍ يختمُ أثرَ أخرى (IDOR).
        // `hub_scope` عزلُ الدورِ الذي لا يُطفأ — نظيرُ day()/monthlyEmployee().
        abort_unless(hub_scope(Attendance::query(), 'attend')->whereKey($row->id)->exists(), 404);

        $outcome = (string) $r->input('outcome');
        $note = trim((string) $r->input('note', ''));
        if ($outcome === 'clear') {
            ReportReview::clearComplianceLock($row, auth()->user());
            return back()->with('ok', 'رُفع ختمُ الامتثال — عاد اليومُ للاشتقاق الحيّ.');
        }
        if ($note === '') return back()->with('err', 'الختمُ يحتاج سبباً موثّقاً (§90).');
        ReportReview::finalizeCompliance($row, auth()->user(), $outcome, $note);

        return back()->with('ok', 'خُتم الأثرُ الفعّال — بمن ومتى ولماذا، وختمُ الحضور/الانصراف كما هو.');
    }

    /* ═══════════ §22/§66 تقريري اليوم — الموظّف نفسُه ═══════════ */

    public function mine(Request $r)
    {
        $this->guardInternal();
        $u = auth()->user();
        $emp = \App\Support\Workday::emp($u);
        // لا ملفَ موظّفٍ نشطٍ (كالمالك/الإدارة): لا نصفعُه بـ٤٠٣ — هذه الصفحةُ لتقريرِ
        // الموظّفِ الذاتيّ لا لحسابه. نوجّهه بلطفٍ إلى ما يخصُّه بحسب صلاحيّته (§66/§34).
        if (! $emp) {
            $msg = 'هذه الصفحةُ لتقريرِ العملِ اليوميِّ للموظّف، وحسابُك غيرُ مربوطٍ بملفِّ موظّفٍ نشط. '
                . 'لمتابعةِ تقاريرِ الفريق استخدم «مركز التقارير اليومية»، وللحضورِ الشهريِّ «الحضور الشهري».';
            if (hub_can($u, 'hr', 'v')) return redirect()->route('reports.index')->with('err', $msg);
            if (hub_can($u, 'attend', 'v')) return redirect()->route('reports.monthly')->with('err', $msg);

            return redirect()->route('dashboard')->with('err', $msg);
        }

        $date = $this->validDate($r->query('date')) ?: \App\Support\BusinessDate::today();
        $c = DailyWorkCompliance::resolve($emp, $date);
        $entries = WorkUpdate::with(['project:id,name', 'task:id,title'])
            ->whereNull('deleted_at')->where('created_by', auth()->id())
            ->whereDate('work_date', $date)->orderBy('submitted_at')->orderBy('id')->get();

        return view('reports.mine', compact('emp', 'date', 'c', 'entries'));
    }

    /* ═══════════ الحضورُ الشهريّ — سجلٌّ لكلِّ موظّفٍ + تصديرٌ للمحاسب ═══════════ */

    /** شبكةُ الحضورِ الشهريّة (كلُّ الموظّفين، شهرٌ واحد) — للمحاسب/الموارد البشرية */
    public function monthly(Request $r)
    {
        $this->guardMonthly();
        $month = \App\Support\MonthlyAttendance::normMonth($r->query('month'));
        $emps = $this->monthlyEmployees()->orderBy('name')->limit(1000)->get(['id', 'name', 'dept', 'user_id', 'company_id']);
        $summary = \App\Support\MonthlyAttendance::summary($emps, $month);

        return view('reports.monthly', [
            'month' => $month, 'rows' => $summary['rows'],
            'days' => count(\App\Support\MonthlyAttendance::daysOf($month)),
            'canExport' => true,
        ]);
    }

    /** سجلُّ موظّفٍ واحدٍ يوماً بيوم في الشهر */
    public function monthlyEmployee(Request $r)
    {
        $this->guardMonthly();
        $emp = Employee::whereNull('deleted_at')->whereKey($r->query('emp'))->firstOrFail();
        abort_unless($this->monthlyEmployees()->whereKey($emp->id)->exists(), 404);
        $month = \App\Support\MonthlyAttendance::normMonth($r->query('month'));

        return view('reports.monthly-employee', \App\Support\MonthlyAttendance::sheet($emp, $month));
    }

    /**
     * تصديرُ الحضور والانصراف الشهريّ CSV — للمحاسب (BOM + تحييدُ حقنِ الصيغ §82).
     *
     * **وضعان، والقديمُ كما هو** (الجولة ٢ · G11 · الإضافةُ لا الكسر):
     *  · الافتراضيُّ `daily` — صفٌّ لكلِّ يومٍ مسجَّل، بأعمدتِه التسعةِ نفسِها حرفاً
     *    بحرف: عقدُ ملفٍّ قائمٍ لا يُكسَر على من بنى عليه.
     *  · `?mode=payroll` — **كشفُ الرواتب**: صفٌّ لكلِّ موظّفٍ في النطاق (لا لكلِّ
     *    ختم)، فمن لم يُختم له يومٌ واحدٌ لا يسقط من الملفّ كما كان يسقط اثنان
     *    وعشرون موظّفاً من اثنين وثلاثين — ومن يبني الخصوماتِ من الملفِّ كان
     *    يخصم صفراً من الغائبين كلِّهم. بمعرّفِ الموظّفِ وأيامِه وساعاتِه
     *    وشذوذاتِه ومجاميعِه، والراتبُ خلفَ `fieldsec` كما في كلِّ سطحٍ آخر.
     */
    public function monthlyExport(Request $r)
    {
        $this->guardMonthly();

        // Permissions 360 · 10.3 — حزامُ التصديرِ نفسُه الذي يلبسه `ModuleController::exportBelt`
        // (تجميدُ الطوارئ + حظرُ الليل + تصعيدُ الحجمِ الكبير + بصمةُ التدقيق) دون تغييرِ
        // سلطةِ العرض: بوّابةُ المحاسبِ (attend:v/hr:v) قرارٌ قائمٌ، والحزامُ ضوابطُ سحبِ
        // البياناتِ فوقَه.
        abort_if((string) setting('security.freeze_exports', '0') === '1', 423,
            'التصدير مجمَّدٌ الآن بمفتاح طوارئٍ أمنيّ — يُرفع من مركز الأمان');

        /*
         * (مجلسُ الخبراء · التحقّقُ الثامن) **الحزامُ الرابع — حظرُ الليل**: كان هذا
         * البابُ يعدّ ثلاثةَ أحزمةٍ ويسكتُ عن الرابع، فيُردُّ `m/hr/export` ٤٠٣ الساعةَ
         * الثالثةَ فجراً بينما يُسلَّمُ كشفُ الرواتبِ الشهريُّ — وفيه عمودُ «الراتب
         * الأساسي» — في اللحظةِ عينها. التعريفُ الآن واحدٌ (`hub_export_night`)،
         * والاستثناءُ هو استثناءُ الوحدات نفسُه: حاملُ `exportNight` على `attend`
         * أو `hr` (بوّابةُ العرضِ تقبلُ الاثنين فكذلك الاستثناء) يمرّ ويُوسَم.
         */
        $night = hub_export_night();
        if (hub_export_blocked_now(['attend', 'hr'])) {
            abort(403, 'نقل الملفات ممنوع خارج وقت العمل — يعود متاحاً مع بداية الدوام،'
                . ' أو يُمنح دورُك مفتاحَ «تصدير خارج الدوام» (exportNight) لإقفالٍ ليليٍّ مشروع');
        }

        $month = \App\Support\MonthlyAttendance::normMonth($r->query('month'));
        $dates = \App\Support\MonthlyAttendance::daysOf($month);
        $payroll = $r->query('mode') === 'payroll';

        // Permissions 360 · 17.3 — أعمدةُ الموظفِ تستشير نمطَ الحقل (نظيرَ CSV الوحدات):
        // دورٌ يحجب حقلاً في hr (قواعدُ الحقولِ أو fieldsec) لا يستلمه في هذا الملفِّ أيضاً.
        $u = auth()->user();
        $nameHidden = hub_field_mode($u, 'hr', 'name') === 'hide';
        $deptHidden = hub_field_mode($u, 'hr', 'dept') === 'hide';
        // الراتبُ حقلٌ حسّاسٌ خلفَ `fieldsec` (hub_field_sec): المحاسبُ بـ`attend:v`
        // وحدَه يرى الأيامَ والساعاتِ ولا يرى ديناراً — ولا يُقرأ العمودُ أصلاً لمن
        // لا يملكه، فلا يمرّ في الذاكرةِ ثم يُحجب على الورق.
        $salaryHidden = hub_field_mode($u, 'hr', 'salary') === 'hide';

        $cols = ['id', 'name', 'dept', 'user_id', 'company_id'];
        if ($payroll && ! $salaryHidden) $cols[] = 'salary';

        $empQ = $this->monthlyEmployees();
        if ($eid = $r->query('emp')) $empQ->whereKey($eid);   // تصديرُ موظّفٍ واحدٍ اختياريّ
        // ترتيبٌ حتميٌّ: الاسمُ ثم المعرّف — أسماءٌ متطابقةٌ كانت قرعةً بين المحرّكين
        $emps = $empQ->orderBy('name')->orderBy('id')->limit(1000)->get($cols);

        // عدُّ الصفوفِ المُصدَّرةِ فعلاً (نفسُ شرطِ البثِّ أدناه) — لعتبةِ التصعيدِ وبصمةِ التدقيق
        $range = $payroll ? [] : \App\Support\DailyWorkCompliance::resolveRange($emps, $dates);
        $rowCount = $emps->count();
        if (! $payroll) {
            $rowCount = 0;
            foreach ($emps as $emp) {
                foreach ($dates as $d) {
                    $c = $range[$emp->id][$d] ?? null;
                    if ($c && ($c['checked_in'] || $c['on_leave'] || $c['attendance'])) $rowCount++;
                }
            }
        }
        $bigAt = (int) setting('security.export_stepup_rows', 0);
        $isBig = $bigAt > 0 && $rowCount >= $bigAt;
        if ($isBig && ($resp = hub_require_stepup())) return $resp;
        // بصمةُ التدقيق تسمّي ما خرج فعلاً: صفوفُ الملفِّ (وفيها الإجازةُ والغياب)
        // لا «يومَ حضورٍ» يعدّ الإجازةَ حضوراً
        // وسمُ «خارج الدوام» نظيرُ وسمِ `exportBelt` — فيُرصد كلُّ إقفالٍ ليليٍّ مشروع
        hub_audit(($isBig ? 'تصدير كبير' : 'تصدير') . ($night ? ' خارج الدوام (exportNight)' : ''), 'attend', null,
            $payroll ? $rowCount . ' موظفاً (كشف رواتب شهري CSV)' : $rowCount . ' صفّاً (CSV شهري يومي)');

        if ($payroll) {
            return $this->monthlyPayrollCsv($emps, $month, $nameHidden, $deptHidden, $salaryHidden);
        }

        $headers = ['الموظف', 'القسم', 'اليوم', 'الحضور', 'الانصراف', 'الساعات', 'الحالة الفعلية', 'التقرير', 'الحالة المحتسَبة'];
        $file = 'attendance-' . $month . '.csv';

        return response()->streamDownload(function () use ($emps, $dates, $range, $headers, $nameHidden, $deptHidden) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $safe = fn ($v) => (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
            fputcsv($out, $headers, ',', '"', '');
            foreach ($emps as $emp) {
                foreach ($dates as $d) {
                    $c = $range[$emp->id][$d] ?? null;
                    if (! $c || (! $c['checked_in'] && ! $c['on_leave'] && ! $c['attendance'])) continue; // تخطّي العطلِ غيرِ المسجَّلة
                    fputcsv($out, array_map($safe, [
                        $nameHidden ? '' : $emp->name, $deptHidden ? '' : ($emp->dept ?: ''), $d,
                        $c['time_in'] ?: '', $c['time_out'] ?: '',
                        $c['hours'] ? number_format((float) $c['hours'], 2) : '',
                        $c['on_leave'] ? 'إجازة' : ($c['checked_in'] ? ($c['labels']['physical'] ?? 'حاضر') : 'غائب'),
                        $c['on_leave'] ? '—' : $c['labels']['compliance'],
                        $c['labels']['effective'],
                    ]), ',', '"', '');
                }
            }
            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * **كشفُ الرواتب الشهريّ** (G11): صفٌّ لكلِّ موظّفٍ في النطاق — لا لكلِّ ختم.
     *
     * المحاسبُ يبني الخصوماتِ من هذا الملفّ، فلا بدّ أن يحمل من لا ختمَ له أصلاً
     * (وإلا خُصم صفرٌ من الغائبين كلِّهم)، ومعرّفاً يُطابَق به (لا اسماً عربيّاً
     * وحدَه)، ومقامَ القسمة (أيامُ العملِ المجدولة)، وعدّادَ شذوذاتٍ يقول إن كان
     * الرقمُ موثوقاً. المجاميعُ كلُّها من `MonthlyAttendance` — لا محرّكَ عدٍّ ثانٍ.
     */
    protected function monthlyPayrollCsv($emps, string $month, bool $nameHidden, bool $deptHidden, bool $salaryHidden)
    {
        $rows = \App\Support\MonthlyAttendance::summary($emps, $month)['rows'];

        $headers = ['الموظف', 'معرّف الموظف', 'القسم', 'أيام العمل', 'أيام الحضور',
            'غياب بلا عذر', 'غياب لعدم التقرير', 'أيام الإجازة', 'مجموع الساعات',
            'أيام التأخّر', 'انصراف مفقود', 'شذوذات', 'الراتب الأساسي'];
        $file = 'payroll-attendance-' . $month . '.csv';

        return response()->streamDownload(function () use ($emps, $rows, $headers, $nameHidden, $deptHidden, $salaryHidden) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $safe = fn ($v) => (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
            fputcsv($out, $headers, ',', '"', '');
            foreach ($emps as $emp) {
                $t = $rows[$emp->id]['totals'] ?? [];
                // الشذوذُ يُجمَع ظاهراً: انصرافٌ مفقودٌ + يومٌ مستحيلٌ (مدّةٌ سالبة)
                $anomalies = (int) ($t['missing_out'] ?? 0) + (int) ($t['invalid_span'] ?? 0);
                fputcsv($out, array_map($safe, [
                    $nameHidden ? '' : $emp->name,
                    (string) $emp->id,
                    $deptHidden ? '' : ($emp->dept ?: ''),
                    (int) ($t['scheduled'] ?? 0),
                    (int) ($t['workdays'] ?? 0),
                    (int) ($t['absent'] ?? 0) + (int) ($t['unexcused'] ?? 0),
                    (int) ($t['absence_report'] ?? 0),
                    (int) ($t['leave'] ?? 0),
                    number_format((float) ($t['attendance_hours'] ?? 0), 2, '.', ''),
                    (int) ($t['late'] ?? 0),
                    (int) ($t['missing_out'] ?? 0),
                    $anomalies,
                    $salaryHidden ? 'محجوب'
                        : ($emp->salary === null ? '' : number_format((float) $emp->salary, 3, '.', '')),
                ]), ',', '"', '');
            }
            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* ────────── مساعدات ────────── */

    /** بنودٌ قابلةٌ للمراجعة لهذا المستخدم — منطَّقةٌ شركةً ومشروعاً (§77/§80) */
    protected function reviewableUpdates(bool $mineOnly = false)
    {
        $u = auth()->user();
        $q = WorkUpdate::query()->whereNull('deleted_at');
        if ($u->role?->is_owner && ! $mineOnly) return $q;

        // «مشاريعي» = ما أُديرُه فعلاً — لا كلُّ ما يقع في نطاق رؤيتي (F7)
        $projQ = hub_scope(Project::query(), 'projects');
        if ($mineOnly) $projQ->where('manager_id', (string) $u->id);
        $pids = $projQ->pluck('id')->all();
        if ($mineOnly) {
            return $q->where(fn ($w) => $pids
                ? $w->whereIn('project_id', $pids) : $w->whereRaw('1 = 0'));
        }
        $userIds = [];
        if (hub_can($u, 'hr', 'v')) {
            $userIds = hub_company_scope(hub_scope(Employee::query(), 'hr'), 'hr')
                ->whereNotNull('user_id')->pluck('user_id')->all();
        }
        return $q->where(function ($w) use ($pids, $userIds) {
            $w->whereRaw('1 = 0');
            if ($pids) $w->orWhereIn('project_id', $pids);
            if ($userIds) $w->orWhereIn('created_by', $userIds);
        });
    }

    protected function validDate(?string $d): ?string
    {
        $d = trim((string) $d);
        if ($d === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return null;
        try { return \Illuminate\Support\Carbon::parse($d)->toDateString(); }
        catch (\Throwable $e) { return null; }
    }
}
