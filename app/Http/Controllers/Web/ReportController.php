<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/** التقارير المالية v1 — مبنية على وحدة المالية (fin) مباشرة */
class ReportController extends Controller
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

    public function finance()
    {
        abort_unless(hub_can(auth()->user(), 'fin', 'v'), 403);
        $t = hub_mod('fin')['table'];
        // النطاق والعزل يسريان على التقرير كما يسريان على القوائم — المعزول يرى شركاته فقط،
        // والشركة النشطة من المحوّل تركّز الأرقام عليها
        $base = fn () => hub_company_scope(
            hub_scope(hub_fin_not_dead(DB::table($t)->whereNull('deleted_at'), $this->dead), 'fin'), 'fin');

        $mStart = now()->startOfMonth()->toDateString();
        $sum = fn ($kinds, $from = null) => (float) $base()->whereIn('kind', $kinds)
            ->when($from, fn ($q) => $q->where('date', '>=', $from))->sum('total');

        $cards = [
            'inc'  => $sum($this->income, $mStart),
            'exp'  => $sum($this->expense, $mStart),
            'incY' => $sum($this->income, now()->startOfYear()->toDateString()),
            'expY' => $sum($this->expense, now()->startOfYear()->toDateString()),
        ];

        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            // NoOverflow: من 31 أغسطس subMonths(2) يفيض إلى 1 يوليو فيتكرر شهر ويختفي آخر
            $m0 = now()->subMonthsNoOverflow($i)->startOfMonth();
            $m1 = $m0->copy()->endOfMonth();
            $inRange = fn ($kinds) => (float) $base()->whereIn('kind', $kinds)
                ->whereBetween('date', [$m0->toDateString(), $m1->toDateString()])->sum('total');
            $months[] = ['l' => $m0->translatedFormat('M'), 'i' => $inRange($this->income), 'e' => $inRange($this->expense)];
        }
        $max = max(1, ...array_merge(array_column($months, 'i'), array_column($months, 'e')));

        $unpaid = $base()->whereIn('state', ['مرسلة', 'مدفوعة جزئياً', 'متأخرة'])
            ->orderBy('due')->orderBy('id')->limit(12)   // فاصلُ تعادلٍ: due يتساوى فيُقرَع بين المحرّكين بلا id
            ->get(['id', 'doc_no as no', 'partner', 'total', 'paid', 'due', 'state']);

        $byState = $base()->select('state', DB::raw('COUNT(*) c'), DB::raw('SUM(total) s'))
            ->groupBy('state')->orderByDesc('s')->get();

        $topPartners = $base()->whereIn('kind', $this->income)->whereNotNull('partner')->where('partner', '!=', '')
            ->select('partner', DB::raw('SUM(total) s'))->groupBy('partner')->orderByDesc('s')->limit(7)->get();

        // المصروف حسب مركز التكلفة (سنة جارية): cc_id كان يُملأ ولا يُقرأ في أي تقرير
        $byCC = $base()->whereIn('kind', $this->expense)
            ->where('date', '>=', now()->startOfYear()->toDateString())
            ->select('cc_id', DB::raw('COUNT(*) c'), DB::raw('SUM(total) s'))
            ->groupBy('cc_id')->orderByDesc('s')->limit(12)->get();
        $ccNames = DB::table('cost_centers')->whereIn('id', $byCC->pluck('cc_id')->filter())->pluck('name', 'id');

        // صدقُ العملة: التقرير يجمع `total` بلا تحويل (لا محرّك في النظام). إن حملت
        // المستنداتُ أكثرَ من عملةٍ فالأرقامُ المجمّعة تُخلط تحت لصيقةٍ واحدة — يُرفع
        // علمٌ يُقرأ به الرقمُ مؤشّراً لا رقماً دقيقاً بدل إيهامِ عملةٍ واحدة.
        //
        // وكان المرشِّح يُسقط العملاتِ الفارغة من مجموعة التمييز، والفراغُ يُنتَج
        // آليّاً (نسخُ عرضٍ، مشترياتٌ، أتمتة) — فـ«فارغ + دولار» يبدو متجانساً
        // وهو مخلوط. واللصيقةُ كانت عملةَ النظام دائماً ولو كانت المستنداتُ كلُّها
        // بالدولار. المساعدُ الموحَّد يعالج الاثنين.
        $label = hub_cur_label($base()->distinct()->pluck('currency'));
        $currency = $label['cur'];
        $mixed = $label['mixed'];

        // **قفلُ الحقل يسري على التقرير كما يسري على القائمة** (v2.321): مبلغٌ
        // محجوبٌ عن قارئٍ في شاشة المالية كان يُطبع هنا كاملاً — فالتقريرُ
        // نافذةٌ خلفية على ما حُجب. العدُّ والحالاتُ والتواريخ تبقى.
        $seesTotals = hub_field_mode(auth()->user(), 'fin', 'total') !== 'hide';

        return view('reports.finance', compact('cards', 'months', 'max', 'unpaid', 'byState', 'topPartners', 'byCC', 'ccNames', 'currency', 'mixed', 'seesTotals'));
    }
}
