<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/** المركز القانوني — نظرة واحدة على العقود والرخص والالتزامات وتجديداتها */
class LegalController extends Controller
{
    public function index()
    {
        abort_unless(hub_can(auth()->user(), 'contracts', 'v'), 403, 'المركز القانوني يتطلب صلاحية عرض العقود');

        // عدسة المشروع: لفّة واحدة على المُغلَّف تُرشِّح ست كتلٍ من تسع
        $lens = hub_lens();
        $pid  = $lens['id'];
        $base = fn () => hub_lens_apply(
            hub_scope(DB::table('contracts')->whereNull('deleted_at'), 'contracts'), 'contracts', $pid);

        // الالتزامات وطلبات التوقيع: عمود المشروع فيها شبه فارغ (يُملأ وقت
        // الترحيل فقط) — فالطريق الأمتن: العمود **أو** عقدُها في المشروع.
        $viaContract = fn ($q) => $pid ? $q->where(fn ($w) => $w->where('project_id', $pid)
            ->orWhereIn('contract_id', DB::table('contracts')->select('id')->where('project_id', $pid))) : $q;
        $today = now()->toDateString();
        $soon = now()->addDays(60)->toDateString();
        $activeish = fn ($q) => hub_open_scope($q);

        $kpi = [
            'active'  => $activeish($base())->count(),
            'soon'    => $activeish($base())->whereNotNull('date_end')->whereBetween('date_end', [$today, $soon])->count(),
            'overdue' => $activeish($base())->whereNotNull('date_end')->where('date_end', '<', $today)->count(),
            // يُملأ أدناه من محرّكِ الصرف — محوَّلاً حين يمكن وخاماً حين لا
            'value'   => 0.0,
        ];

        // التوزيع بالأنواع — للدونات
        $types = $base()->select('type', DB::raw('COUNT(*) c'))->groupBy('type')->orderByDesc('c')->limit(6)->get()
            ->map(fn ($r) => ['label' => $r->type ?: 'غير مصنف', 'value' => (int) $r->c])->all();

        /*
         * **العدُّ قبل القصّ** (W-5 · الطور ١٦٧). كانت الشاشةُ تطبع
         * `$expiring->count()` في شارةِ «يستحق التجديد» — وهو طولُ ما بقي **بعد**
         * `limit(12)`. فمنشأةٌ لها أربعون عقداً على وشك الانتهاء تقرأ «١٢»،
         * وتبني عليه قرارَ تجديدٍ من ثُلثِ الصورة. والخطأُ في اتّجاهِ التهوينِ
         * دائماً: كلّما ازداد الواقعُ ثبت الرقمُ على سقفِه.
         * فالعدُّ استعلامٌ مستقلٌّ الآن، والمسرودُ يبقى مقصوصاً كما هو.
         */
        $expiringQ = $activeish($base())->whereNotNull('date_end')->where('date_end', '<=', $soon);
        $expiringN = (clone $expiringQ)->count();

        // تنتهي قريباً (أو تجاوزت) — بأيام متبقية
        $expiring = $expiringQ
            ->orderBy('date_end')->limit(12)
            ->get(['id', 'title', 'type', 'party', 'date_end as end', 'renewal', 'value', 'currency'])
            ->map(function ($c) {
                $c->days = (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($c->end)->startOfDay(), false);
                return $c;
            });

        // v2.124: التزامات متتبعة تستحق (وحدة م7) بدل قصاصات النص القديمة
        // والعدُّ قبل القصّ هنا كذلك (W-5)
        $obligationsQ = \Illuminate\Support\Facades\Schema::hasTable('contract_obligations')
            ? $viaContract(hub_scope(\App\Models\ContractObligation::query(), 'obligations'))
                ->whereNotIn('status', ['مكتمل', 'ملغي'])->whereNotNull('due')
                ->whereDate('due', '<=', now()->addDays(31))
            : null;
        $obligationsN = $obligationsQ ? (clone $obligationsQ)->count() : 0;
        $obligations = $obligationsQ ? $obligationsQ->orderBy('due')->limit(10)->get() : collect();

        // v2.124: قيمة الساري بكل عملة على حدة — لا جمع عملات مختلفة في رقمٍ واحد
        // **المجموعةُ كاملةً للحساب، والمقصوصةُ للعرض** — الخمسُ الأُوَل تُعرض
        // في الجدول، والبطاقةُ تُحسَب من **كلِّ** العملات وإلّا كانت لصيقةُ
        // التحويل تصف مجموعةً والرقمُ من أخرى (v2.542)
        $valuesAll = $activeish($base())->whereNotNull('value')->where('value', '>', 0)
            ->select('currency', DB::raw('SUM(value) s'), DB::raw('COUNT(*) c'))
            ->groupBy('currency')->orderByDesc('s')->get();
        $values = $valuesAll->take(5);

        // v2.124: عالقة بلا توقيع >٧ أيام من الإرسال — بزر تذكيرٍ مباشر
        /*
         * **حدٌّ متبقٍّ مكتوب** (W-5): الرؤيةُ هنا تُرشَّح **بعد** الجلب
         * (`filterVisible` تقرأ عقدَ كلِّ طلب)، فلا يُعَدُّ الصادقُ باستعلامِ
         * `COUNT` قبلَها. فالعدُّ طولُ المرشَّحِ كلِّه — ومصدرُه خمسون طلباً
         * أقدمَ إرسالاً، وهو سقفٌ يُقال ولا يُدَّعى غيرُه.
         */
        $stuckAll = app(EsignController::class)->filterVisible(
            $viaContract(\App\Models\SignRequest::where('status', 'بانتظار التوقيع'))->whereNull('cancelled_at')
                ->whereNotNull('sent_at')->where('sent_at', '<=', now()->subDays(7))
                ->orderBy('sent_at')->limit(50)->get()
        );
        $stuckN = $stuckAll->count();
        $stuck = $stuckAll->take(8);

        // v2.124: موافقات معلقة + خط التجديد (قيد التجديد ومسودات التجديد)
        $pendingSteps = \Illuminate\Support\Facades\Schema::hasTable('contract_approval_steps')
            ? \App\Models\ContractApprovalStep::where('status', 'بانتظار')
                // لا عمود مشروع على المرحلة — الطريق عبر طلب التوقيع أو عقده
                ->when($pid, fn ($q) => $q->whereIn('request_id',
                    DB::table('sign_requests')->select('id')->where('project_id', $pid)
                        ->orWhereIn('contract_id', DB::table('contracts')->select('id')->where('project_id', $pid))))
                // **القصُّ للعرضِ أُخِّر إلى ما بعد الترشيح** (W-5): كان `take(8)`
                // هنا قبل `filterVisible`، فالرقمُ المطبوعُ طولُ المعروضِ لا
                // عددُ الموافقاتِ المعلّقةِ فعلاً. صار القصُّ آخرَ خطوة، والعدُّ
                // يسبقه. (وحدٌّ متبقٍّ مكتوب: مصدرُه عشرون مرحلةً أقدمَ إنشاءً.)
                ->orderBy('created_at')->orderBy('stage')->limit(20)->get()->unique('request_id')
                ->map(function ($s) {
                    $s->req = \App\Models\SignRequest::find($s->request_id);
                    return $s;
                })
                // filterVisible كما في بطاقة «العالقة» المجاورة: بلا عدسةٍ كانت
                // البطاقة تجمع مراحل المنشأة كلها ثم تعرض عناوين طلبات شركاتٍ
                // خارج عزل المستخدم — وعنوان طلب التوقيع كثيراً ما يكون هو السر
                ->pipe(fn ($steps) => $steps->filter(fn ($s) => $s->req)
                    ->pipe(function ($ss) {
                        $visible = app(EsignController::class)->filterVisible($ss->pluck('req'))
                            ->pluck('id')->flip();
                        return $ss->filter(fn ($s) => isset($visible[$s->req->id]));
                    }))
            : collect();
        $pendingStepsN = $pendingSteps->count();
        $pendingSteps = $pendingSteps->take(8);

        // وخطُّ التجديدِ مثلُها: العدُّ استعلامٌ مستقلٌّ والمسرودُ ثمانية (W-5)
        $renewalsQ = $base()
            ->where(fn ($q) => $q->where('status', 'قيد التجديد')
                ->orWhere(fn ($w) => $w->where('kind', 'تجديد')->where('status', 'مسودة')));
        $renewalsN = (clone $renewalsQ)->count();
        $renewals = $renewalsQ
            ->orderByDesc('created_at')->limit(8)->get(['id', 'title', 'doc_no', 'status', 'kind', 'date_end']);

        // v2.124: التوزيع بالمسؤول (الساري فقط) — أسماء حقيقية لا معرفات
        $byOwnerRaw = $activeish($base())->whereNotNull('owner_id')
            ->select('owner_id', DB::raw('COUNT(*) c'))->groupBy('owner_id')->orderByDesc('c')->limit(6)->get();
        $ownerNames = \App\Models\User::whereIn('id', $byOwnerRaw->pluck('owner_id'))->pluck('name', 'id');
        $byOwner = $byOwnerRaw->map(fn ($r) => ['label' => $ownerNames[$r->owner_id] ?? 'غير معروف', 'value' => (int) $r->c])->all();

        // v2.124: قاعدتا تنبيه العقود المبذورتان معطلتين — زر تفعيلٍ بنقرة (لا نفعّل عن المستخدم)
        $dormantRules = \App\Models\AlertRule::whereNull('deleted_at')->where('mod', 'contracts')
            ->where('status', '!=', 'مفعّلة')->orderBy('name')->limit(4)->get(['id', 'name']);

        $obUsers = \App\Models\User::whereIn('id', $obligations->pluck('owner_id')->filter())->pluck('name', 'id');

        // بطاقةُ «قيمة الساري» كانت تجمع العملات وتلصق عملةَ المنشأة، وتحتها في
        // الشاشة نفسِها جدولُ `$values` يفصلها بتعليقٍ صريح — تناقضٌ داخل شاشةٍ
        // واحدة. اللصيقةُ الآن حقيقيةٌ عند التوحّد، وموسومةٌ عند الاختلاط.
        //
        // **ومحرّكُ الصرفِ موصولٌ هنا** (v2.542): قيمةُ العقدِ الساري مبلغٌ قائمٌ
        // لا حدثٌ مؤرَّخ، فيُحوَّل بسعرِ اليوم لا بسعرِ شهرٍ مضى. وبلا سعرٍ
        // مسجَّلٍ تبقى اللصيقةُ والرقمُ كما كانا حرفاً بحرف.
        $curLabel = hub_money_sum($valuesAll, 's', 'currency');
        $currency = $curLabel['cur'];
        $mixed = $curLabel['mixed'];
        $converted = $curLabel['converted'];
        $curMissing = $curLabel['missing'];
        // **الرقمُ من المصدرِ الذي وُصف**: كان `SUM(value)` خاماً مستقلّاً عن
        // اللصيقة، فبطاقةٌ تقول «محوَّل» فوق مجموعٍ لم يُحوَّل تناقضٌ في شاشة
        $kpi['value'] = $curLabel['total'];

        return view('legal.index', compact('kpi', 'types', 'expiring', 'obligations', 'currency', 'mixed',
            'converted', 'curMissing',
            'values', 'stuck', 'pendingSteps', 'renewals', 'byOwner', 'dormantRules', 'obUsers', 'lens',
            'expiringN', 'obligationsN', 'stuckN', 'pendingStepsN', 'renewalsN'));
    }

    /** تفعيل قاعدة تنبيه عقود مبذورة معطلة — بنقرة صريحة من المستخدم لا آلياً */
    public function enableRule(string $id)
    {
        abort_unless(hub_can(auth()->user(), 'contracts', 'e'), 403);
        $rule = \App\Models\AlertRule::whereNull('deleted_at')->where('mod', 'contracts')->findOrFail($id);
        $rule->forceFill(['status' => 'مفعّلة'])->save();
        hub_audit('تفعيل قاعدة تنبيه عقود', 'contracts', null, $rule->name);

        return back()->with('ok', 'فُعّلت «' . $rule->name . '» — تعمل مع أتمتة الصباح اليومية');
    }
}
