<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AiBudget;
use App\Models\AiModel;
use App\Models\AiPolicyRule;
use App\Models\AiProvider;
use App\Models\AiUsageEvent;
use App\Support\Ai\Center\AiAccess;
use App\Support\Ai\Governance\AiBudgets;
use App\Support\Ai\Governance\AiCost;
use App\Support\Ai\Governance\AiPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * **شاشاتُ الحوكمة** (المرحلة ٤ · P4-W7) — السياساتُ والميزانيّاتُ والاستهلاك.
 *
 * ── **والواجهةُ ليست طبقةَ الفرض** ──
 *
 * كلُّ منعٍ يقع في `AiPolicy`/`AiBudgets` في الخادم، **قبل أن تُبنى صفحةٌ
 * واحدة**. وما هنا عرضٌ وتحريرٌ لقواعدِ ذلك المنع. فمن عطّل جافاسكربت أو نادى
 * المسارَ مباشرةً يصطدم بالحارسِ نفسِه — **لأنّ الحارسَ ليس هنا أصلاً**.
 *
 * ── **ولا رقمَ عالميٌّ لمن لا يملك إلّا شركتَه** ──
 *
 * قراءةُ الاستهلاكِ **مُنطَّقةٌ بـ`hub_company_ids`**: من له عزلُ شركاتٍ يرى
 * صفوفَ شركاتِه وحدَها، ولا يرى مجموعاً عالميّاً يكشف حجمَ غيرِه. ومن لا عزلَ
 * عليه يرى الكلَّ كما هو حقُّه.
 */
class AiGovernanceController extends Controller
{
    /**
     * **الكتابةُ خلف الإدارةِ والتصعيدِ معاً** — كما كلُّ كتابةٍ في المركز.
     *
     * ── **ولمَ يُعاد الردُّ ولا يُبتَلَع؟** ──
     *
     * `hub_require_stepup()` **تُعيد** ردَّ التحويلِ (أو ٤٢٨ للـAPI) ولا
     * تُلقي ولا تُجهِض. فنداؤها عارياً كان يُسقط ردَّها في الفراغ **وتمضي
     * الكتابةُ بجلسةٍ باردة** — فيُنشأ صفُّ «اسمح» أو تُطفأ ميزانيّةٌ مفروضةٌ
     * بلا هويّةٍ طازجة، وهو بعينِه التهديدُ المُسمّى في نموذجِ تهديدِ المرحلة ٤
     * («رفعُ سقفٍ ليلاً وإعادتُه صباحاً») والمُعلَنُ أنّ هذا الحارسَ يمنعه.
     *
     * **والمنعُ يُعاد إلى المُستدعي ليُعيده** — فلا حارسَ يُفرَض بنيّةِ مُستدعٍ.
     */
    protected function gateWrite(): ?\Symfony\Component\HttpFoundation\Response
    {
        AiAccess::gateManage();

        return hub_require_stepup();
    }

    // ═══ السياسات ═══

    public function policies()
    {
        AiAccess::gateView();

        return view('ai.policies', [
            'sections' => AiAccess::sections(),
            'section'  => 'policies',
            'rules'    => AiPolicyRule::query()
                ->orderBy('priority')->orderBy('key')->orderBy('id')->get(),
            'models'   => AiModel::query()->orderBy('litellm_model_name')->orderBy('id')->get(),
            'providers' => AiProvider::query()->orderBy('label')->orderBy('id')->get(),
            'profiles' => \App\Models\AiProfile::query()->orderBy('key')->orderBy('id')->get(),
            'canManage' => AiAccess::canManage(),
        ]);
    }

    public function storePolicy(Request $r)
    {
        if ($resp = $this->gateWrite()) return $resp;

        $data = $r->validate([
            'key'                   => ['required', 'string', 'max:80', 'regex:/^[a-z0-9._-]+$/',
                                        'unique:ai_policies,key'],
            'label'                 => ['required', 'string', 'max:191'],
            'effect'                => ['required', 'in:' . implode(',', AiPolicy::EFFECTS)],
            'scope_type'            => ['required', 'in:' . implode(',', AiPolicy::SCOPES)],
            'scope_id'              => ['nullable', 'string', 'max:80'],
            'purpose'               => ['nullable', 'string', 'max:80'],
            'provider_id'           => ['nullable', 'uuid', 'exists:ai_providers,id'],
            'model_id'              => ['nullable', 'uuid', 'exists:ai_models,id'],
            'allow_generation'      => ['nullable', 'boolean'],
            'allow_tools'           => ['nullable', 'boolean'],
            'max_output_tokens'     => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_calls_per_request' => ['nullable', 'integer', 'min:1', 'max:100'],
            'priority'              => ['nullable', 'integer', 'min:0', 'max:9999'],
            'notes'                 => ['nullable', 'string', 'max:1000'],
        ]);

        /*
         * **نطاقٌ يحتاج معرّفاً ولا معرّفَ له سياسةٌ لا تطابق أحداً** — فتبدو
         * مفروضةً وهي معطَّلةٌ فعليّاً. والصمتُ هنا أسوأُ من الرفض.
         */
        if ($data['scope_type'] !== 'global' && trim((string) ($data['scope_id'] ?? '')) === '') {
            return back()->withInput()->withErrors([
                'scope_id' => 'نطاقٌ غيرُ عامٍّ بلا معرّفٍ لا يطابق أحداً — فالسياسةُ تبدو مفروضةً وهي معطَّلة']);
        }

        $rule = AiPolicyRule::create($data + ['enabled' => true]);

        return redirect()->route('ai.policies.index')
            ->with('ok', 'أُضيفت السياسة «' . $rule->label . '».');
    }

    public function togglePolicy(AiPolicyRule $policy)
    {
        if ($resp = $this->gateWrite()) return $resp;
        $policy->update(['enabled' => ! $policy->enabled]);

        return back()->with('ok', $policy->enabled ? 'فُعِّلت السياسة.' : 'عُطِّلت السياسة.');
    }

    public function destroyPolicy(AiPolicyRule $policy)
    {
        if ($resp = $this->gateWrite()) return $resp;
        $label = (string) $policy->label;
        $policy->delete();

        return redirect()->route('ai.policies.index')->with('ok', 'أُزيلت السياسة «' . $label . '».');
    }

    // ═══ الميزانيّات ═══

    public function budgets()
    {
        AiAccess::gateCost();

        $rows = AiBudget::query()->orderBy('scope_type')->orderBy('key')->orderBy('id')->get();

        return view('ai.budgets', [
            'sections'  => AiAccess::sections(),
            'section'   => 'budgets',
            'budgets'   => $rows,
            // **الحالةُ تُقرأ مرّةً لكلِّ ميزانيّةٍ هنا** — لا داخلَ حلقةِ القالب
            'status'    => $rows->mapWithKeys(fn ($b) => [(string) $b->id => AiBudgets::status($b)])->all(),
            'canManage' => AiAccess::canManage(),
        ]);
    }

    public function storeBudget(Request $r)
    {
        if ($resp = $this->gateWrite()) return $resp;

        $data = $r->validate([
            'key'            => ['required', 'string', 'max:80', 'regex:/^[a-z0-9._-]+$/',
                                 'unique:ai_budgets,key'],
            'label'          => ['required', 'string', 'max:191'],
            'scope_type'     => ['required', 'in:' . implode(',', AiBudgets::SCOPES)],
            'scope_id'       => ['nullable', 'string', 'max:80'],
            'period'         => ['required', 'in:' . implode(',', AiBudgets::PERIODS)],
            'limit_amount'   => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'limit_requests' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'limit_tokens'   => ['nullable', 'integer', 'min:1'],
            'enforce'        => ['nullable', 'boolean'],
            'notes'          => ['nullable', 'string', 'max:1000'],
        ]);

        if ($data['scope_type'] !== 'global' && trim((string) ($data['scope_id'] ?? '')) === '') {
            return back()->withInput()->withErrors([
                'scope_id' => 'نطاقٌ غيرُ عامٍّ بلا معرّفٍ لا يحكم شيئاً']);
        }

        // **ميزانيّةٌ بلا سقفٍ واحدٍ لا تحكم شيئاً** — وتبدو حارساً وهي ليست كذلك
        if (($data['limit_amount'] ?? null) === null && ($data['limit_requests'] ?? null) === null
            && ($data['limit_tokens'] ?? null) === null) {
            return back()->withInput()->withErrors([
                'limit_amount' => 'ميزانيّةٌ بلا سقفِ مالٍ ولا طلباتٍ ولا رموزٍ لا تمنع شيئاً']);
        }

        $b = AiBudget::create([
            'key'            => $data['key'],
            'label'          => $data['label'],
            'scope_type'     => $data['scope_type'],
            'scope_id'       => $data['scope_id'] ?? null,
            'period'         => $data['period'],
            // **المالُ يُحوَّل إلى عددٍ صحيحٍ عند الحدّ** — ولا يدخل كسرٌ عائمٌ إلى عدّادٍ متسابق
            'limit_micro'    => AiCost::toMicro($data['limit_amount'] === null
                ? null : (float) $data['limit_amount']),
            'limit_requests' => $data['limit_requests'] ?? null,
            'limit_tokens'   => $data['limit_tokens'] ?? null,
            'currency'       => AiCost::CURRENCY,
            'enforce'        => (bool) ($data['enforce'] ?? true),
            'enabled'        => true,
            'notes'          => $data['notes'] ?? null,
        ]);

        return redirect()->route('ai.budgets.index')
            ->with('ok', 'أُضيفت الميزانيّة «' . $b->label . '».');
    }

    public function toggleBudget(AiBudget $budget)
    {
        if ($resp = $this->gateWrite()) return $resp;
        $budget->update(['enabled' => ! $budget->enabled]);

        return back()->with('ok', $budget->enabled ? 'فُعِّلت الميزانيّة.' : 'عُطِّلت الميزانيّة.');
    }

    public function destroyBudget(AiBudget $budget)
    {
        if ($resp = $this->gateWrite()) return $resp;
        $label = (string) $budget->label;
        $budget->delete();

        return redirect()->route('ai.budgets.index')->with('ok', 'أُزيلت الميزانيّة «' . $label . '».');
    }

    // ═══ الاستهلاك من سجلِّ Hub ═══

    /**
     * **ملخَّصُ الاستهلاكِ من السجلِّ المحلّيّ** — بلا نداءِ بوّابةٍ ولا انتظار.
     *
     * **وكلُّ رقمٍ يُقال من أين جاء**: مُبلَّغٌ أم محسوبٌ أم مقدَّرٌ أم مجهول.
     * والمجهولُ **عددٌ مستقلٌّ لا صفرٌ مضمومٌ إلى المعروف**.
     *
     * @return array{events:int, ok:int, failed:int, tokens:int, cost_micro:?int,
     *               unknown:int, by_source:array, by_model:list<array>, by_failure:list<array>}
     */
    public static function usageSummary(int $days = 30): array
    {
        $since = now()->subDays(max(1, $days));
        $base  = static function () use ($since) {
            $q = AiUsageEvent::query()->where('created_at', '>=', $since);

            /*
             * **عزلُ الشركاتِ يسري على المال كما يسري على السجلّات.**
             *
             * `hub_company_ids` تعود `null` لمن لا عزلَ عليه، وقائمةً لمن عليه.
             * ومن له عزلٌ **لا يرى صفوفاً بلا شركة**: تلك عمليّاتُ غيرِه أو
             * عمليّاتٌ عامّةٌ، وكشفُ مجموعِها يكشف حجمَ نشاطٍ ليس له.
             */
            $ids = hub_company_ids();
            if (is_array($ids)) $q->whereIn('company_id', $ids);

            return $q;
        };

        $agg = (clone $base())
            ->selectRaw('COUNT(*) AS n')
            ->selectRaw("SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) AS ok_n")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS bad_n")
            ->selectRaw('SUM(COALESCE(total_tokens, 0)) AS tok')
            ->selectRaw('SUM(COALESCE(cost_micro, 0)) AS cost')
            ->selectRaw('SUM(CASE WHEN cost_micro IS NULL AND status IN (\'ok\',\'failed\') THEN 1 ELSE 0 END) AS unk')
            ->first();

        $bySource = (clone $base())
            ->selectRaw('cost_source, COUNT(*) AS n, SUM(COALESCE(cost_micro, 0)) AS cost')
            ->groupBy('cost_source')->orderBy('cost_source')->get()
            ->mapWithKeys(fn ($r) => [(string) $r->cost_source =>
                ['n' => (int) $r->n, 'cost' => (int) $r->cost]])->all();

        // **الترتيبُ ينتهي بعمودٍ فريد** — فمجموعان متساويان ليسا قرعة
        $byModel = (clone $base())
            ->selectRaw('model_name, COUNT(*) AS n, SUM(COALESCE(cost_micro, 0)) AS cost')
            ->selectRaw('SUM(COALESCE(total_tokens, 0)) AS tok')
            ->selectRaw('SUM(CASE WHEN cost_micro IS NULL THEN 1 ELSE 0 END) AS unk')
            ->groupBy('model_name')
            ->orderByDesc('cost')->orderBy('model_name')->limit(50)->get()
            ->map(fn ($r) => ['model' => (string) ($r->model_name ?? '—'), 'n' => (int) $r->n,
                              'cost' => (int) $r->cost, 'tokens' => (int) $r->tok,
                              'unknown' => (int) $r->unk])->all();

        $byFailure = (clone $base())
            ->whereNotNull('failure')
            ->selectRaw('failure, COUNT(*) AS n')
            ->groupBy('failure')->orderByDesc('n')->orderBy('failure')->limit(20)->get()
            ->map(fn ($r) => ['failure' => (string) $r->failure, 'n' => (int) $r->n])->all();

        $events = (int) ($agg->n ?? 0);

        return [
            'events'     => $events,
            'ok'         => (int) ($agg->ok_n ?? 0),
            'failed'     => (int) ($agg->bad_n ?? 0),
            'tokens'     => (int) ($agg->tok ?? 0),
            // **مجموعٌ صفرٌ بلا حدثٍ واحدٍ ليس «صفرَ إنفاق»** بل «لا قياسَ بعد»
            'cost_micro' => $events === 0 ? null : (int) ($agg->cost ?? 0),
            'unknown'    => (int) ($agg->unk ?? 0),
            'by_source'  => $bySource,
            'by_model'   => $byModel,
            'by_failure' => $byFailure,
            /*
             * ── **آخرُ الدوراتِ مقروءةً** (قبولُ الإنتاج `92dbd557`) ──
             *
             * ثلاثُ محاولاتٍ مدفوعةٍ مضت خُمِّن سببُها بدل أن يُقرأ، لأنّ ما
             * يلزم التشخيصَ لم يكن مُسجَّلاً ولا معروضاً. **والشاشةُ هي
             * الطريقُ الوحيدُ لصاحبِ الخادمِ إلى صفِّه** — بلا طرفيّةٍ ولا
             * استعلام.
             *
             * **ولا محتوى فيها**: سببُ انتهاءٍ وأرقامٌ واسمُ أداةٍ من مفرداتٍ
             * مغلقة، وكلُّها منطَّقةٌ بـ`hub_company_ids` كبقيّةِ الصفوف.
             */
            /*
             * **والترتيبُ يُطلَب صراحةً حتّى في جدولِ عرض** (المرحلة ٥ · W5).
             *
             * `created_at` بدقّةِ الثانية، و`id` **معرّفٌ عشوائيٌّ لا تزايديّ**
             * — فمحاولاتُ الطلبِ الواحدِ تقع في الثانيةِ نفسِها ويُرتّبها
             * المحرّكُ كما اتّفق. فيقرأ المشخّصُ `fallback` فوق `initial`
             * **ويستنتج أنّ الاحتياطَ سبق الأساسيّ**.
             *
             * فالتقسيمُ بـ`request_id` يجمع محاولاتِ الطلبِ الواحدِ متجاورةً،
             * و`attempt` يرتّبها داخلَه بترتيبِ وقوعِها.
             */
            'turns'      => (clone $base())
                ->orderByDesc('created_at')->orderBy('request_id')->orderBy('attempt')
                ->orderBy('id')->limit(25)
                ->get(['correlation', 'attempt', 'relation', 'status', 'failure',
                       'finish_reason', 'model_name', 'input_tokens', 'output_tokens',
                       'reasoning_tokens', 'max_output_tokens', 'tool_requested',
                       'latency_ms', 'created_at'])
                ->map(fn ($r) => [
                    'correlation' => mb_substr((string) $r->correlation, 0, 8),
                    'attempt'     => (int) $r->attempt,
                    'relation'    => (string) $r->relation,
                    'status'      => (string) $r->status,
                    'failure'     => $r->failure === null ? null : (string) $r->failure,
                    'finish'      => $r->finish_reason === null ? null : (string) $r->finish_reason,
                    'model'       => (string) ($r->model_name ?? '—'),
                    'in'          => $r->input_tokens === null ? null : (int) $r->input_tokens,
                    'out'         => $r->output_tokens === null ? null : (int) $r->output_tokens,
                    'reasoning'   => $r->reasoning_tokens === null ? null : (int) $r->reasoning_tokens,
                    'cap'         => $r->max_output_tokens === null ? null : (int) $r->max_output_tokens,
                    'tool'        => $r->tool_requested === null ? null : (string) $r->tool_requested,
                    'ms'          => (int) $r->latency_ms,
                    'at'          => $r->created_at,
                ])->all(),
        ];
    }
}
