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
            'value'   => (float) $activeish($base())->sum('value'),
        ];

        // التوزيع بالأنواع — للدونات
        $types = $base()->select('type', DB::raw('COUNT(*) c'))->groupBy('type')->orderByDesc('c')->limit(6)->get()
            ->map(fn ($r) => ['label' => $r->type ?: 'غير مصنف', 'value' => (int) $r->c])->all();

        // تنتهي قريباً (أو تجاوزت) — بأيام متبقية
        $expiring = $activeish($base())->whereNotNull('date_end')->where('date_end', '<=', $soon)
            ->orderBy('date_end')->limit(12)
            ->get(['id', 'title', 'type', 'party', 'date_end as end', 'renewal', 'value', 'currency'])
            ->map(function ($c) {
                $c->days = (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($c->end)->startOfDay(), false);
                return $c;
            });

        // v2.124: التزامات متتبعة تستحق (وحدة م7) بدل قصاصات النص القديمة
        $obligations = \Illuminate\Support\Facades\Schema::hasTable('contract_obligations')
            ? $viaContract(hub_scope(\App\Models\ContractObligation::query(), 'obligations'))
                ->whereNotIn('status', ['مكتمل', 'ملغي'])->whereNotNull('due')
                ->whereDate('due', '<=', now()->addDays(31))->orderBy('due')->limit(10)->get()
            : collect();

        // v2.124: قيمة الساري بكل عملة على حدة — لا جمع عملات مختلفة في رقمٍ واحد
        $values = $activeish($base())->whereNotNull('value')->where('value', '>', 0)
            ->select('currency', DB::raw('SUM(value) s'), DB::raw('COUNT(*) c'))
            ->groupBy('currency')->orderByDesc('s')->limit(5)->get();

        // v2.124: عالقة بلا توقيع >٧ أيام من الإرسال — بزر تذكيرٍ مباشر
        $stuck = app(EsignController::class)->filterVisible(
            $viaContract(\App\Models\SignRequest::where('status', 'بانتظار التوقيع'))->whereNull('cancelled_at')
                ->whereNotNull('sent_at')->where('sent_at', '<=', now()->subDays(7))
                ->orderBy('sent_at')->limit(50)->get()
        )->take(8);

        // v2.124: موافقات معلقة + خط التجديد (قيد التجديد ومسودات التجديد)
        $pendingSteps = \Illuminate\Support\Facades\Schema::hasTable('contract_approval_steps')
            ? \App\Models\ContractApprovalStep::where('status', 'بانتظار')
                // لا عمود مشروع على المرحلة — الطريق عبر طلب التوقيع أو عقده
                ->when($pid, fn ($q) => $q->whereIn('request_id',
                    DB::table('sign_requests')->select('id')->where('project_id', $pid)
                        ->orWhereIn('contract_id', DB::table('contracts')->select('id')->where('project_id', $pid))))
                ->orderBy('created_at')->orderBy('stage')->limit(20)->get()->unique('request_id')->take(8)
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
        $renewals = $base()
            ->where(fn ($q) => $q->where('status', 'قيد التجديد')
                ->orWhere(fn ($w) => $w->where('kind', 'تجديد')->where('status', 'مسودة')))
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
        $currency = setting('app.currency', 'د.ك');

        return view('legal.index', compact('kpi', 'types', 'expiring', 'obligations', 'currency',
            'values', 'stuck', 'pendingSteps', 'renewals', 'byOwner', 'dormantRules', 'obUsers', 'lens'));
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
