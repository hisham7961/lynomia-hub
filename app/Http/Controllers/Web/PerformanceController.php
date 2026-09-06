<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\ExecutionStats;
use Illuminate\Support\Facades\DB;

/**
 * لوحة الأداء: مؤشرات الشركة المحسوبة + أهداف OKR بمستوياتها + جدول KPI لكل موظف.
 * للمالكين وحاملي علم monitor — بيانات أداء الأفراد حساسة.
 */
class PerformanceController extends Controller
{
    /** تصنيف المستندات المالية — تعريف واحد في config('hub.fin') لكل التقارير */
    protected array $income;
    protected array $expense;
    protected array $dead;

    public function __construct()
    {
        $this->income  = config('hub.fin.income');
        $this->expense = config('hub.fin.expense');
        $this->dead    = config('hub.fin.dead');
    }

    protected array $doneWords = ['مكتمل', 'منجز'];

    /** سقفُ صفوف لوح الموظفين — عرضٌ لا حساب (الأرقامُ مُجمَّعةٌ لهؤلاء وحدهم) */
    protected const PEOPLE_CAP = 30;

    public function index()
    {
        abort_unless(hub_monitor(), 403,
            'لوحة الأداء للمالكين وحاملي صلاحية المراقبة');
        // تجمع المنشأة كلها من الجداول الخام — كأخواتها الثلاث (القدرات
        // والتوصيات والأثر) — وكانت وحدها بلا حارس العزل، فالمحصورُ بشركةٍ
        // يقرأ إيراد المنشأة كلها ورواتبها
        hub_org_analytics_guard();

        return view('performance.index', [
            'company' => $this->companyKpis(),
            'okrs'    => $this->okrs(),
            'people'  => $this->peopleKpis(),
            'currency' => setting('app.currency', 'د.ك'),
        ]);
    }

    /* ── مؤشرات الشركة ── */
    protected function companyKpis(): array
    {
        // hub_fin_not_dead تُبقي «بلا حالة»: whereNotIn وحدها كانت تُسقط state=NULL صامتاً
        $fin = fn () => hub_fin_not_dead(DB::table('fin_documents')->whereNull('deleted_at'), $this->dead);
        $sum = fn ($kinds, $a, $b) => (float) $fin()->whereIn('kind', $kinds)->whereBetween('date', [$a, $b])->sum('total');

        $m0 = now()->startOfMonth()->toDateString(); $mEnd = now()->toDateString();
        $p0 = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $p1 = now()->subMonthNoOverflow()->endOfMonth()->toDateString();

        $rev  = $sum($this->income, $m0, $mEnd);
        $exp  = $sum($this->expense, $m0, $mEnd);
        $prev = $sum($this->income, $p0, $p1);

        $projTotal = DB::table('projects')->whereNull('deleted_at')->count();
        $projDone  = DB::table('projects')->whereNull('deleted_at')
            ->where(fn ($w) => $w->where('status', 'LIKE', '%مكتمل%')->orWhere('status', 'LIKE', '%منجز%'))->count();

        $clientsTotal = DB::table('clients')->whereNull('deleted_at')->count();
        $activePartners = DB::table('fin_documents')->whereNull('deleted_at')
            ->where('date', '>=', now()->subDays(90)->toDateString())
            ->whereNotNull('partner')->where('partner', '!=', '')->distinct()->count('partner');

        return [
            'rev'      => $rev,
            'margin'   => $rev > 0 ? (int) round(($rev - $exp) * 100 / $rev) : null,
            'growth'   => $prev > 0 ? (int) round(($rev - $prev) * 100 / $prev) : null,
            'newClients' => DB::table('clients')->whereNull('deleted_at')->where('created_at', '>=', $m0)->count(),
            'clients'  => $clientsTotal,
            'activeP'  => $activePartners,
            'projPct'  => $projTotal ? (int) round($projDone * 100 / $projTotal) : null,
            'projDone' => $projDone, 'projTotal' => $projTotal,
            'crit'     => hub_open_scope(DB::table('issues')->whereNull('deleted_at')
                            ->where('severity', 'LIKE', '%حرج%'))->count(),
        ];
    }

    /**
     * الأهداف بمستوياتها — **من المصدر الواحد** (WP-8.5 · §6.10).
     *
     * كانت هذه الدالّة تقرأ عمودَ `objectives.progress` المخزَّن بينما `/okrs`
     * تحسب النسبةَ من نتائجها (`hub_okr_progress`): رقمان لهدفٍ واحدٍ على
     * شاشتين، وأحدُهما كاذبٌ حتماً — فالعمودُ لا يُكتب إلا بتثبيتٍ مأذونٍ من
     * قارئٍ غيرِ مقيَّد، وقد يتخلّف أسابيع. الآن كلتاهما تقرأ `OkrCentre::board`
     * (وهو `hub_okr_board` مُغنّى)، والعمودُ يبقى أثرَ آخر تثبيتٍ لا مصدرَ عرض.
     */
    protected function okrs(): array
    {
        return \App\Support\OkrCentre::board(auth()->user());
    }

    /**
     * KPI لكل موظف (آخر ٣٠ يوماً) — **قراءةٌ لا حساب** (WP-7.3):
     *
     * الأرقامُ كلُّها من `ExecutionStats::people` — القارئُ الواحد الذي تقرأ منه
     * نظرةُ القوى العاملة وبطاقةُ الموظف كذلك؛ كانت هنا نسخةٌ ثالثةٌ متداخلة
     * تحسب «أُنجز» من **آخر تعديل** فتُفسد تاريخَ الإنجاز عند أيّ لمسةٍ لاحقة،
     * وتعدّ الملغى إنجازاً. والقراءةُ **دفعةً واحدة**: كانت ثلاثةَ استعلاماتٍ
     * لكلٍّ من ثلاثين (تسعون في فتحةٍ واحدة) فصارت ثمانيةً مُجمَّعة.
     *
     * وعيبان يُصلَحان معها: سحبُ جدول الموظفين كاملاً لقراءة عمودين، و**قراءةُ
     * `employees.perf` بلا `hub_field_mode`** — «تقييمُ المدير» حقلُ ملفٍّ
     * وظيفيّ تحكمه صلاحيةُ الحقل كأيّ حقلٍ آخر، فكان دورٌ محجوبٌ عنه في شاشة
     * الموظف يقرؤه هنا كاملاً.
     */
    protected function peopleKpis()
    {
        // نافذةٌ ثابتةٌ كما تقول ترويسةُ اللوحة «آخر ٣٠ يوماً» — لا معاملَ رابطٍ جديد
        $range = hub_range(new \Illuminate\Http\Request(), '30d');

        // فاصلُ id بعد الاسم: أسماءٌ متساويةٌ ترتيبُها قرعةٌ تختلف بين المحرّكين
        $users = DB::table('users')->whereNull('deleted_at')->where('status', '!=', 'موقوف')
            ->orderBy('name')->orderBy('id')->limit(self::PEOPLE_CAP)->get(['id', 'name']);
        $ids = $users->pluck('id')->all();
        if (! $ids) return collect();

        // ملفّاتُ هؤلاء وحدهم — لا سحبَ للجدول كلِّه، وترتيبٌ حتميّ عند ربطٍ مزدوج
        $emp = DB::table('employees')->whereNull('deleted_at')->whereIn('user_id', $ids)
            ->orderBy('id')->get(['id', 'user_id', 'perf'])->keyBy('user_id');

        // صلاحيةُ الحقل تسري على الشاشة كما على ملفّ الموظف — لا حقلَ محجوبٌ يُقرأ
        $showPerf = hub_field_mode(auth()->user(), 'hr', 'perf') !== 'hide';

        // إسقاطُ الأشخاص من القارئ الواحد؛ بلا قارئٍ لأن اللوحةَ خلف
        // hub_org_analytics_guard() فأرقامُها أرقامُ المنشأة كاملةً
        $stats = ExecutionStats::people($ids, $range);

        return $users->map(function ($u) use ($emp, $stats, $showPerf) {
            $x = $stats[$u->id] ?? [];
            $e = $emp[$u->id] ?? null;

            return (object) [
                'id' => $u->id, 'name' => $u->name, 'empId' => $e->id ?? null,
                'done' => $x['completed'] ?? 0,
                'onTimePct' => $x['on_time']['pct'] ?? null,
                'lateNow' => $x['overdue'] ?? 0,
                'tix' => $x['tickets_resolved'] ?? 0,
                'avgRes' => $x['resolution']['avg_h'] ?? null,
                'rating' => $showPerf ? ($e->perf ?? null) : null,
            ];
        })->filter(fn ($p) => $p->done || $p->tix || $p->lateNow || $p->rating)->values();
    }
}
