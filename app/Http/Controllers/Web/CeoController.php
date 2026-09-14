<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/** لوحة CEO — صورة الشركة كاملة في شاشة واحدة (للمالكين) */
class CeoController extends Controller
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

    public function index()
    {
        abort_unless(hub_is_owner(), 403, 'لوحة CEO للمالكين فقط');

        /*
         * **الكيانُ المختار يُصفّي هذه الأرقام فعلاً** (الجولة ٣ · V7).
         *
         * مبدّلُ الشركة يَعِد نصّاً بأن «تصفّي القوائم عليها»، وكانت هذه اللوحةُ
         * بلا أيّ إشارةٍ إلى الشركة النشطة: تحت كيانٍ فارغٍ تماماً تقول «٣١
         * موظّفاً · ٨ مشاريع · صافي السنة ١٥٩٬٧٥٨» بينما «فريقي اليوم» يقول
         * «٠ موظّفاً» من الجدول نفسِه في الدقيقة نفسِها. رقمٌ يبدو مُصفّىً وهو
         * ليس كذلك يُفسد قراراً (توظيفٌ على ساعاتِ كيانٍ آخر).
         *
         * فالمرشِّحُ هنا هو `hub_company_scope` نفسُه الذي تطبّقه قوائمُ الوحدات
         * (`ModuleController`) و«فريقي اليوم» (`WorkdayController`) — لا محرّكَ
         * تنطيقٍ ثانٍ، ولا مساسَ بالعزل الصارم (`hub_scope`) فوقه.
         *
         * **وما لا يُصفّى يُصرَّح به** في الشاشة (`partials._groupnums` ووسمُ
         * «أرقام المجموعة» على بطاقاته): صحّةُ الشركة والإيرادُ المتكرر وبطاقاتُ
         * طبقة القرار تُحسب في محرّكاتٍ عامّةٍ خارج هذا المتحكّم — والتصريحُ
         * صدقٌ، أمّا الصمتُ فادّعاءُ تصفيةٍ لم تقع.
         */
        $activeCo = \App\Support\ExecutionStats::activeCompany();

        // hub_fin_not_dead تُبقي «بلا حالة»: whereNotIn وحدها كانت تُسقط state=NULL صامتاً
        $fin = fn () => hub_fin_not_dead(
            hub_company_scope(DB::table('fin_documents')->whereNull('deleted_at'), 'fin'), $this->dead);
        $sum = fn ($kinds, $from) => (float) $fin()->whereIn('kind', $kinds)->where('date', '>=', $from)->sum('total');
        $m0 = now()->startOfMonth()->toDateString();
        $y0 = now()->startOfYear()->toDateString();

        // المؤشرات العليا
        $kpi = [
            'netM'    => $sum($this->income, $m0) - $sum($this->expense, $m0),
            'netY'    => $sum($this->income, $y0) - $sum($this->expense, $y0),
            // COALESCE: فاتورة لم يُدفع منها شيء paid=NULL — «total - NULL» تُسقطها من المجموع وهي أسوأ الحالات
            // **ما لنا وحدَه** (v2.339): كان الاستعلامُ بلا فلترِ نوعٍ إطلاقاً،
            // ففاتورةُ المشتريات غيرُ المسدَّدة تُعدّ ديناً **لنا**. صاحبُ القرار
            // يقرأ رقمَ ما يُنتظر تحصيلُه وفيه ما عليه هو أن يدفع — وهو أسوأُ
            // خطأٍ ممكنٍ في هذه البطاقة بعينها. الإيرادُ من `hub.fin.income`.
            'unpaid'  => (float) $fin()->whereIn('kind', $this->income)
                            ->whereIn('state', ['مرسلة', 'مدفوعة جزئياً', 'متأخرة'])
                            ->sum(DB::raw('total - COALESCE(paid, 0)')),
            'projects'=> hub_open_scope(hub_company_scope(DB::table('projects')->whereNull('deleted_at'), 'projects'))->count(),
            'clients' => hub_company_scope(DB::table('clients')->whereNull('deleted_at'), 'clients')->count(),
            'emps'    => hub_company_scope(DB::table('employees')->whereNull('deleted_at'), 'hr')->count(),
            'openTasks' => hub_open_scope(hub_company_scope(DB::table('tasks')->whereNull('deleted_at'), 'tasks'))->count(),
            'lateTasks' => hub_open_scope(hub_company_scope(DB::table('tasks')->whereNull('deleted_at'), 'tasks')
                ->whereNotNull('due')->where('due', '<', now()->toDateString()))->count(),
        ];

        // صحة الشركة
        $health = hub_health();

        // ٦ أشهر دخل/مصروف
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            // NoOverflow: من 31 أغسطس subMonths(2) يفيض إلى 1 يوليو فيتكرر شهر ويختفي آخر
            $a = now()->subMonthsNoOverflow($i)->startOfMonth();
            $b = $a->copy()->endOfMonth();
            $in = fn ($kinds) => (float) $fin()->whereIn('kind', $kinds)->whereBetween('date', [$a->toDateString(), $b->toDateString()])->sum('total');
            $months[] = ['l' => $a->translatedFormat('M'), 'i' => $in($this->income), 'e' => $in($this->expense)];
        }
        $max = max(1, ...array_merge(array_column($months, 'i'), array_column($months, 'e')));

        // تقدم المشاريع الجارية
        $projects = hub_open_scope(hub_company_scope(DB::table('projects')->whereNull('deleted_at'), 'projects'))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(6)->get(['id', 'name', 'status'])
            ->map(function ($p) { $p->progress = hub_progress($p->id)['pct']; return $p; });

        // فريق اليوم: إجازات معتمدة تشمل اليوم + حضور اليوم
        $today = now()->toDateString();
        // **المسنَدُ الواحد** (الخاتمة · X1): كان هنا استعلامٌ ثالثٌ لـ«من في إجازةٍ
        // اليوم» يخالف نداءَ اليومِ في ثلاثةِ مواضع — `LIKE '%معتمد%'`، و**بلا أيِّ
        // تصفيةِ نوع** (فطلبُ «سلفة» معتمدٌ يضع صاحبَه في إجازة)، ويسقط `date_to`
        // الفارغ. فصار الموظّفُ نفسُه «في إجازة» هنا و«غائباً بلا عذر» في النداءِ
        // أسفلَ الصفحةِ عينِها. القراءةُ الآن من `DailyWorkCompliance` — ومعها
        // `kind` يميّز إجازةَ الخصمِ من العذرِ المأذون، فلا يضيع أحدٌ ولا يُخلَط.
        // والتصفيةُ بشركةِ **الموظّف** صاحبِ الطلب — هي ما يقرؤه صاحبُ القرار.
        $onLeave = \App\Support\DailyWorkCompliance::onLeaveToday($today, $activeCo['id'] ?? null);
        $attToday = hub_company_scope(DB::table('attendance')->whereNull('deleted_at'), 'attend')
            ->where('date', $today)->count();

        // «نداءُ اليوم» (الجولة ١ · F9): المصدرُ نفسُه الذي تقرؤه شاشةُ «فريقي اليوم» —
        // الغائبُ بالفرق (النشطون − من ختم − من في إجازة)، فلا «0 حاضر» فوقها «مكتمل».
        // عدُّ الصفوفِ الخام كان يحسب صفَّ «غائب» المختومَ حاضراً — البطاقةُ تقرأ النداء.
        $teamRoll = \App\Support\DailyWorkCompliance::rollCall(
            hub_company_scope(\App\Models\Employee::whereNull('deleted_at')->where('status', 'نشط'), 'hr')
                ->orderBy('name')->orderBy('id')->get(['id', 'name', 'dept', 'user_id'])
        );

        // أعلى المستحقات
        $unpaidTop = $fin()->whereIn('state', ['مرسلة', 'مدفوعة جزئياً', 'متأخرة'])
            ->orderByRaw('(total - COALESCE(paid, 0)) DESC')->orderByDesc('id')
            ->limit(6)->get(['id', 'doc_no as no', 'partner', 'total', 'paid', 'due', 'state']);

        // توزيع المهام المفتوحة بالحالة (للدونات)
        $taskSlices = hub_company_scope(DB::table('tasks')->whereNull('deleted_at'), 'tasks')
            ->select('status', DB::raw('COUNT(*) c'))->groupBy('status')
            ->orderByDesc('c')->orderBy('status')->limit(6)->get()
            ->map(fn ($r) => ['label' => $r->status ?: 'بلا حالة', 'value' => (int) $r->c])->all();

        // مسار المبيعات والإيراد المتكرر — أرقام القرار التجاري في لوحة القيادة
        $pipe = hub_pipeline();
        $mrr = hub_mrr();

        $currency = setting('app.currency', 'د.ك');

        // طبقة القرار فوق طبقة الأرقام: ما ينتظرني · أين ينزف المال · أين الخطر
        $awaiting = \App\Support\CeoBoard::awaiting(auth()->user());
        $leaks = \App\Support\CeoBoard::leaks();
        $conc = \App\Support\CeoBoard::concentration();
        $risks = \App\Support\CeoBoard::risks();
        $trend = \App\Support\CeoBoard::trend($months);
        $gov = \App\Support\CeoBoard::governance();

        return view('ceo.index', compact('kpi', 'health', 'months', 'max', 'projects',
            'onLeave', 'attToday', 'teamRoll', 'unpaidTop', 'taskSlices', 'pipe', 'mrr', 'currency',
            'awaiting', 'leaks', 'conc', 'risks', 'trend', 'gov', 'activeCo'));
    }
}
