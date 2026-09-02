<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * سجل التدقيق — تحقيقٌ لا سردٌ زمني.
 *
 * كان مرشِّحان اثنان (وحدة + بحثٌ في الاسم) فوق جدولٍ ينمو أسرع من كل شيء:
 * لا «من فعل»، ولا «أي إجراء»، ولا «متى»، ولا «من أي عنوان» — فسؤالٌ بسيط
 * مثل «من صدّر بيانات الرواتب الشهر الماضي» يعني تصفّح مئات الصفحات يدوياً.
 */
class AuditController extends Controller
{
    public function index(Request $r)
    {
        abort_unless(hub_flag(auth()->user(), 'audit'), 403);

        $q = DB::table('audits')
            ->leftJoin('users', 'users.id', '=', 'audits.user_id')
            ->select('audits.*', 'users.name as user_name')
            // النطاق أولاً ودائماً — المرشِّحات تضيّق ما بعده ولا تفتح ما قبله
            ->when(hub_scoped(auth()->user()), function ($w) {
                $u = auth()->user();
                $w->where(fn ($x) => $x->where('audits.user_id', $u->id)
                                       ->orWhereIn('audits.project_id', $u->visibleProjectIds()));
            })
            // عزل الشركات يسري على التدقيق كما على البيانات: القيد يحمل شركته
            // منذ سمة Auditable، ومع ذلك كان يُقرأ بلا ترشيح — فمن يرى شركةً
            // واحدة يقرأ أثر عمليات بقية الشركات. والقيود بلا شركة (سجلات لا
            // شركة لها) تبقى لصاحبها وحده.
            ->when(hub_company_ids() !== null, function ($w) {
                $ids = hub_company_ids();
                $uid = auth()->id();
                $w->where(fn ($x) => $x->whereIn('audits.company_id', $ids)
                    ->orWhere(fn ($y) => $y->whereNull('audits.company_id')->where('audits.user_id', $uid)));
            })
            ->when($r->input('module'), fn ($w, $m) => $w->where('audits.module', $m))
            ->when($r->input('user'), fn ($w, $u) => $w->where('audits.user_id', $u))
            ->when($r->input('action'), fn ($w, $a) => $w->where('audits.action', $a))
            ->when($r->input('ip'), fn ($w, $i) => $w->where('audits.ip', 'LIKE', "%$i%"))
            ->when($r->input('from'), fn ($w, $d) => $w->whereDate('audits.created_at', '>=', $d))
            ->when($r->input('to'), fn ($w, $d) => $w->whereDate('audits.created_at', '<=', $d))
            ->when($r->input('q'), fn ($w, $t) => $w->where(fn ($x) => $x
                ->where('audits.name', 'LIKE', "%$t%")->orWhere('audits.reason', 'LIKE', "%$t%")))
            // فاصلُ id بعد الطابع: created_at بدقّة الثانية تتساوى قيمُه،
            // والترتيبُ على المتساوي قرعةٌ تكرّر صفوفاً وتُسقط أخرى عبر الصفحات
            ->orderByDesc('audits.created_at')->orderByDesc('audits.id')
            // بلا COUNT(*) إجمالي — أسرع الجداول نمواً والعدّ مع join يثقل كل صفحة
            ->simplePaginate(40)->withQueryString();

        return view('audit.index', [
            'rows'    => $q,
            'users'   => DB::table('users')->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id'),
            // DISTINCT على أسرع الجداول نموّاً في كل فتحة (PERF-02): خمسُ دقائقَ خبيئة — والفهرسُ (action) يسنده (v2.399)
            'actions' => \Illuminate\Support\Facades\Cache::remember('audit:actions', 300, fn () => DB::table('audits')->distinct()->orderBy('action')->limit(40)->pluck('action')),
            'chain'   => Audit::verifyTail(),
            'pulse'   => $this->pulse(),
        ]);
    }

    /**
     * نبضُ اليوم: أربعة أعدادٍ تُقرأ في ثانية — لا «كم قيداً» بل **ما يستحق نظرة**.
     * الحذف والتصدير أثرهما لا يُستردّ، والعمل خارج الدوام والعناوين الجديدة
     * إشارتان لا تُدركان من سردٍ زمني مهما طال.
     */
    protected function pulse(): array
    {
        $day = now()->startOfDay();
        // **بنفس نطاق القائمة** (v2.321): كان النبضُ يقرأ الجدولَ كلَّه، فيُطبع
        // عددُ الحذف والتصدير و«العناوين الجديدة» من شركاتٍ خارج نطاق القارئ —
        // شاشةٌ واحدة، حارسان مختلفان.
        $base = function () use ($day) {
            $q = DB::table('audits')->where('created_at', '>=', $day);
            if (hub_scoped(auth()->user())) {
                $u = auth()->user();
                $q->where(fn ($x) => $x->where('audits.user_id', $u->id)
                                       ->orWhereIn('audits.project_id', $u->visibleProjectIds()));
            }
            if (($ids = hub_company_ids()) !== null) {
                $uid = auth()->id();
                $q->where(fn ($x) => $x->whereIn('audits.company_id', $ids)
                    ->orWhere(fn ($y) => $y->whereNull('audits.company_id')->where('audits.user_id', $uid)));
            }

            return $q;
        };

        $hoursStart = (string) setting('sec.hours_start', '08:00');
        $hoursEnd   = (string) setting('sec.hours_end', '16:00');

        $today = $base()->get(['action', 'ip', 'created_at', 'user_id']);
        // بحدّ تسعين يوماً: كان DISTINCT على كامل أسرع الجداول نمواً مع كل
        // فتحة شاشة — وعنوانٌ غاب تسعين يوماً عودتُه «جديدة» عملياً بحق
        // ومرجعُ «العناوين المعروفة» منطَّقٌ كذلك: بغيره يبدو عنوانُ شركةٍ أخرى
        // «معروفاً» فيُخفى، أو يُقارَن به عنوانُ القارئ فيُوسَم جديداً بلا سبب
        $seenQ = DB::table('audits')->where('created_at', '<', $day)
            ->where('created_at', '>=', $day->copy()->subDays(90))
            ->whereNotNull('ip');
        if (hub_scoped(auth()->user())) {
            $u = auth()->user();
            $seenQ->where(fn ($x) => $x->where('audits.user_id', $u->id)
                                       ->orWhereIn('audits.project_id', $u->visibleProjectIds()));
        }
        if (($ids = hub_company_ids()) !== null) {
            $uid = auth()->id();
            $seenQ->where(fn ($x) => $x->whereIn('audits.company_id', $ids)
                ->orWhere(fn ($y) => $y->whereNull('audits.company_id')->where('audits.user_id', $uid)));
        }
        $seen = $seenQ->distinct()->pluck('ip')->all();

        return [
            'deletes' => $today->where('action', 'حذف')->count(),
            'exports' => $today->where('action', 'تصدير')->count(),
            'offhours' => $today->filter(function ($a) use ($hoursStart, $hoursEnd) {
                $t = \Illuminate\Support\Carbon::parse($a->created_at)->format('H:i');

                return $t < $hoursStart || $t >= $hoursEnd;
            })->count(),
            'newIps' => $today->pluck('ip')->filter()->unique()
                ->reject(fn ($ip) => in_array($ip, $seen, true))->values()->all(),
        ];
    }
}
