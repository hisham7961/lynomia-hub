<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Models\FinDocument;
use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * مسار عرض السعر: مستند طباعة أنيق ← إرسال ← قبول/رفض ← تحويل بنقرة إلى عقد وفاتورة.
 * التحويلات تُسجَّل في meta العرض فلا تتكرر، وكل خطوة موثقة في التدقيق.
 */
class QuoteController extends Controller
{
    /** المستند القابل للطباعة (A4) */
    public function doc(string $id)
    {
        abort_unless(hub_can(auth()->user(), 'quotes', 'v'), 403);
        $q = hub_scope(Quote::query(), 'quotes')->findOrFail($id);
        $client = $q->client_id ? Client::find($q->client_id) : null;

        // إثراء البنود بحساب الكراتين (تعبئة المنتجات) بعزل شركة المستند
        $items = \App\Support\Items::cartons(
            \App\Support\Items::parse((string) $q->items), $q->company_id);

        // **قفلُ الحقل يسري على الورقة كما على الشاشة** (v2.323): ما حُجب في
        // القائمة كان يُطبع هنا كاملاً — والمستندُ يُرسَل للعميل ويُطبَع ويُؤرشَف.
        $u = auth()->user();
        $hide = fn (string $f) => hub_field_mode($u, 'quotes', $f) === 'hide';

        return view('quotes.doc', [
            'q' => $q, 'client' => $client,
            'items' => $items,
            'hideTotal' => $hide('total'),
            'hideItems' => $hide('items'),
            'showCartons' => \App\Support\Items::anyCartons($items),
            'totalCartons' => \App\Support\Items::totalCartons($items),
            'logo' => setting('app.logo'),
        ]);
    }

    /** إجراءات المسار */
    public function act(Request $r, string $id)
    {
        abort_unless(hub_can(auth()->user(), 'quotes', 'e'), 403, 'إجراءات العرض تتطلب صلاحية تعديل');
        if ($why = hub_block_if_queued('quotes')) return back()->with('err', $why);
        $q = hub_scope(Quote::query(), 'quotes')->findOrFail($id);
        $action = hub_str($r->input('do'));

        return match ($action) {
            'send'     => $this->send($q),
            'accept'   => $this->setStatus($q, 'مقبول', '🎉 قُبل العرض — حوّله لعقد أو فاتورة من الأزرار'),
            'reject'   => $this->setStatus($q, 'مرفوض', 'حُدّد العرض كمرفوض'),
            'contract' => $this->toContract($q),
            'invoice'  => $this->toInvoice($q),
            'project'  => $this->toProject($q),
            default    => abort(422),
        };
    }

    /**
     * **تحويلُ عرضٍ مقبولٍ إلى ارتباطٍ ومشروعٍ خارجيّ** — جوهرةُ المواصفة.
     *
     * معاملاتيٌّ آمن كنمط `toContract`: قفلُ صفٍّ + فحصُ meta لمنع تحويلٍ مكرّر
     * (عرضٌ واحدٌ لا يُنشئ مشروعين). يُنشئ ارتباطاً (إن لم يُختَر) ثم مشروعاً
     * خارجياً موصولاً به وبالعميل — فتُضيء الربحيةُ والتقدّمُ القائمان تلقائياً.
     * ينقل البنودَ ذاتَ النوع «مرحلة» إلى خطة العمل (plan_items) على المشروع،
     * ويحفظ **خطَّ الأساس التجاريّ** (لقطةَ العرض المقبول) في meta المشروع —
     * فالتغييرُ اللاحق تغييرٌ يُدار لا تعديلٌ للعرض. **بلا ازدواج بيانات**:
     * المشروع يشير للعميل والارتباط والعرض، لا ينسخها.
     */
    protected function toProject(Quote $q)
    {
        abort_unless(hub_can(auth()->user(), 'projects', 'a'), 403, 'التحويل لمشروع يتطلب صلاحية إنشاء المشاريع');
        abort_unless(hub_can(auth()->user(), 'engagements', 'a'), 403, 'التحويل يتطلب صلاحية إنشاء الارتباطات');
        // مشروعٌ قائمٌ من هذا العرض يُفتَح مباشرةً — حتى بعد أن صار «محوّل»،
        // فالحارسُ التالي (مقبول) لا يمنع إعادةَ الفتح المتكرّرة (idempotent).
        $done = (array) $q->meta;
        if (! empty($done['project_id']) && \App\Models\Project::withTrashed()->find($done['project_id'])) {
            return redirect()->route('m.show', ['projects', $done['project_id']])->with('ok', 'حُوّل من قبل — هذا مشروعه');
        }
        abort_unless($q->status === 'مقبول', 422, 'حوّل العرض بعد قبوله أولاً');
        abort_unless($q->client_id, 422, 'العرضُ بلا عميلٍ — لا يُحوَّل لمشروع عميل');

        return DB::transaction(function () use ($q) {
            $q = Quote::whereKey($q->getKey())->lockForUpdate()->firstOrFail();
            $meta = (array) $q->meta;
            // منعُ التحويل المكرّر: مشروعٌ قائمٌ من هذا العرض يُفتَح لا يُكرَّر
            if (! empty($meta['project_id']) && \App\Models\Project::withTrashed()->find($meta['project_id'])) {
                return redirect()->route('m.show', ['projects', $meta['project_id']])->with('ok', 'حُوّل من قبل — هذا مشروعه');
            }

            $name = $q->title ?: ('مشروع بموجب العرض ' . $q->doc_no);

            // (١) ارتباطٌ: يُختار القائمُ إن مُرِّر، وإلا يُنشأ من العرض
            $engagementId = $meta['engagement_id'] ?? $q->engagement_id;
            if (! $engagementId || ! \App\Models\Engagement::find($engagementId)) {
                $eng = \App\Models\Engagement::create([
                    'name' => mb_substr($name, 0, 290),
                    'client_id' => $q->client_id,
                    'type' => 'تنفيذ مشروع',
                    'status' => 'نشط',
                    'contract_id' => $meta['contract_id'] ?? null,
                    'am_id' => $q->am_id,
                    'pm_id' => $q->pm_id,
                    'billing' => $q->billing,
                    'revenue' => $q->total,
                    'currency' => $q->currency,
                    'scope' => $q->scope,
                    'company_id' => $q->company_id,
                    'notes' => 'أُنشئ تلقائياً من العرض ' . $q->doc_no,
                ]);
                $engagementId = $eng->id;
            }

            // (٢) مشروعٌ خارجيّ موصولٌ بالعميل والارتباط — تُضيء الربحيةُ والتقدّم
            $project = \App\Models\Project::create([
                'name' => mb_substr($name, 0, 290),
                'client_id' => $q->client_id,
                'engagement_id' => $engagementId,
                'company_id' => $q->company_id,
                'manager_id' => $q->pm_id,
                'type' => 'خدمة',
                'status' => 'تخطيط',
                'budget' => $q->cost,          // التكلفة التقديرية ميزانيةً مبدئية
                'rev_exp' => $q->total,        // الإيراد المتوقّع = إجمالي العرض
                'currency' => $q->currency,
                'start_date' => now()->toDateString(),
                'description' => $q->scope ?: $q->exec_summary,
                // **خطُّ الأساس التجاريّ**: لقطةٌ للعرض المقبول لا تتغيّر بالتعديل اللاحق
                'meta' => ['baseline' => [
                    'quote_id' => $q->id, 'quote_no' => $q->doc_no,
                    'amount' => (string) $q->total, 'currency' => $q->currency,
                    'accepted_at' => optional($q->accepted_at)->toIso8601String(),
                    'lines' => $q->lines()->get(['title', 'kind', 'phase', 'qty', 'unit_price', 'line_total'])->toArray(),
                ]],
            ]);

            // (٣) نقلُ البنود ذات النوع «مرحلة» إلى خطة العمل (plan_items) بتتبّعٍ للعرض
            foreach ($q->lines()->get() as $l) {
                if ($l->kind !== 'مرحلة' && $l->phase === null) continue;
                \App\Models\PlanItem::create([
                    'title' => mb_substr($l->title, 0, 290),
                    'type' => 'مرحلة',
                    'project_id' => $project->id,
                    'status' => 'مخططة',
                    'weight' => 1,
                    'description' => $l->description,
                    'meta' => ['from_quote' => $q->id, 'from_line' => $l->id],
                ]);
            }

            // (٤) الربطُ والحالة: العرض يشير لمشروعه وارتباطه، ويصير «محوّل»
            $q->meta = $meta + ['engagement_id' => $engagementId, 'project_id' => $project->id,
                'converted_at' => now()->toIso8601String(), 'converted_by' => auth()->id()];
            $q->engagement_id = $engagementId;
            $q->status = 'محوّل';
            $q->save();
            \App\Support\FlowRunner::fire('status', 'quotes', $q, 'محوّل');
            hub_audit('تحويل عرض إلى مشروع', 'quotes', $q->id, $q->doc_no . ' → ' . $name);

            return redirect()->route('m.show', ['projects', $project->id])
                ->with('ok', '🚀 أُنشئ المشروع والارتباط من العرض — نُقل النطاق وحُفظ خطُّ الأساس التجاريّ');
        });
    }

    /**
     * إرسالٌ للعميل بعتبةِ اعتماد: عرضٌ يتجاوز مبلغَ العتبة أو نسبةَ خصمها يتطلب
     * اعتماداً داخلياً أولاً (على محرك الموافقات القائم عبر approval.rules) — أو
     * يُرسَل مباشرةً إن كانت العتبتان مطفأتين. لا محرك موافقاتٍ ثانٍ.
     */
    protected function send(Quote $q)
    {
        $amountAt = (float) setting('quotes.approve_amount', 0);
        $discAt = (float) setting('quotes.approve_discount', 0);
        $discPct = ((float) $q->total + (float) $q->discount) > 0
            ? (float) $q->discount / ((float) $q->total + (float) $q->discount) * 100 : 0;
        $needs = ($amountAt > 0 && (float) $q->total >= $amountAt)
            || ($discAt > 0 && $discPct >= $discAt);

        if ($needs && ! hub_flag(auth()->user(), 'approve') && ! hub_is_owner()) {
            // يُبلَّغ المعتمدون بطلبِ إرسالٍ يستحق نظرَهم — دون قلبِ الحالة
            foreach (array_unique(hub_approvers()) as $oid) {
                if ($oid && $oid !== auth()->id()) {
                    hub_notify($oid, 'approval', 'عرضٌ ينتظر اعتمادَ الإرسال: ' . ($q->title ?: $q->doc_no)
                        . ' — ' . number_format((float) $q->total, 3) . ' ' . $q->currency, 'quotes', $q->id);
                }
            }
            $q->status = 'مراجعة داخلية';
            $q->save();

            return back()->with('warn', 'العرضُ يتجاوز عتبةَ الاعتماد — أُحيل «للمراجعة الداخلية» وأُبلغ المعتمدون.');
        }

        if (! $q->sent_at) $q->sent_at = now();

        return $this->setStatus($q, 'مُرسل', '📨 حُدّد العرض كمُرسل للعميل');
    }

    /* ────────── داخلي ────────── */

    protected function setStatus(Quote $q, string $status, string $msg)
    {
        // قفلُ الحقل يسري على أزرار المسار كما على النموذج: دورٌ حقلُ حالته «قراءة
        // فقط» لا يقلبها من الأزرار (hub_field_mode توثّق setStatus كأحد مستهلكيها).
        abort_if(hub_field_mode(auth()->user(), 'quotes', 'status') !== '', 403,
            'حقل الحالة مقفولٌ لدورك (قراءة فقط) — لا يُغيَّر من أزرار المسار');
        $q->status = $status;
        if ($status === 'مقبول' && ! $q->accepted_at) {
            $q->accepted_at = now();
            $q->accepted_by = auth()->user()?->name;
        }
        $q->save();

        // **إطلاقُ الأحداث الدلالية**: كان setStatus يتجاوز FlowRunner فلا تُطلَق
        // quote.accepted/rejected المعلَنة — الآن تُطلق فتعمل حِزمُ الاستجابة والتنبيهات.
        \App\Support\FlowRunner::fire('status', 'quotes', $q, $status);

        return back()->with('ok', $msg);
    }

    protected function toContract(Quote $q)
    {
        // صلاحيةُ الوحدة الهدف تُفرض هنا كما في المسار الرسمي (ContractActionsController::find):
        // إجراءُ العرض (quotes:e) لا يسكّ عقداً لمن لا يملك إنشاء العقود.
        abort_unless(hub_can(auth()->user(), 'contracts', 'a'), 403,
            'تحويل العرض لعقد يتطلب صلاحية إنشاء العقود');
        abort_unless($q->status === 'مقبول', 422, 'حوّل العرض بعد قبوله أولاً');

        // الفحص+الإنشاء+حفظ meta داخل معاملةٍ على صفٍّ مقفول: نقرتان متزامنتان لا
        // تُنشئان عقدين. وwithTrashed: عقدٌ حُذف بنعومة يظل تحويلاً واقعاً فلا يُنشأ
        // ثانٍ بالرقم نفسه (Contract::find كان يُقصي المحذوف فيسقط الحارس).
        return DB::transaction(function () use ($q) {
            $q = Quote::whereKey($q->getKey())->lockForUpdate()->firstOrFail();
            $meta = (array) $q->meta;
            if (! empty($meta['contract_id']) && Contract::withTrashed()->find($meta['contract_id'])) {
                return redirect()->route('m.show', ['contracts', $meta['contract_id']])->with('ok', 'حُوّل من قبل — هذا عقده');
            }

            $c = Contract::create([
                // عنوانٌ يُقصّ إلى عرض العمود (300) — doc_no قد يبلغ الحدّ فيفيض على MySQL
                'title'      => mb_substr('عقد بموجب عرض السعر ' . $q->doc_no, 0, 300),
                'type'       => 'عقد عميل',
                'client_id'  => $q->client_id,
                'service_id' => $q->service_id,   // الخدمة تتبع البيع فيُقاس MRR من مصدره
                'company_id' => $q->company_id,
                'project_id' => $q->project_id,
                'party'      => $q->client_id ? Client::find($q->client_id)?->name : null,
                'value'      => $q->total,
                'currency'   => $q->currency,
                // v2.117: كان يكتب عمود start الوهمي (الصحيح date_start → انفجار 500 على
                // MySQL) وحالة «نشط» الخارجة عن خيارات الوحدة فلا كانبان ولا حدث يلتقطها
                'date_start' => now()->toDateString(),
                'owner_id'   => $q->owner_id ?: auth()->id(),
                'status'     => 'ساري',
                'notes'      => 'أُنشئ تلقائياً من عرض السعر ' . $q->doc_no
                              . ($q->terms ? "\n\nالشروط المتفق عليها:\n" . Str::limit($q->terms, 1500) : ''),
            ]);
            $q->meta = $meta + ['contract_id' => $c->id];
            $q->save();

            return redirect()->route('m.show', ['contracts', $c->id])
                ->with('ok', '📜 أُنشئ العقد من العرض — راجع بنوده وتاريخ نهايته');
        });
    }

    protected function toInvoice(Quote $q)
    {
        // صلاحيةُ المالية تُفرض هنا كما في store لوحدة fin: الفاتورة تدخل MRR
        // والتقارير، فلا تُسكّ لمن لا يملك إنشاء المستندات المالية.
        abort_unless(hub_can(auth()->user(), 'fin', 'a'), 403,
            'تحويل العرض لفاتورة يتطلب صلاحية إنشاء المستندات المالية');
        abort_unless($q->status === 'مقبول', 422, 'حوّل العرض بعد قبوله أولاً');

        // نفس التصليب: معاملةٌ على صفٍّ مقفول + withTrashed — لا فاتورتان بالرقم نفسه
        // (من نقرٍ مزدوج أو من حذف الأولى بنعومة ثم إعادة التحويل).
        return DB::transaction(function () use ($q) {
            $q = Quote::whereKey($q->getKey())->lockForUpdate()->firstOrFail();
            $meta = (array) $q->meta;
            if (! empty($meta['invoice_id']) && FinDocument::withTrashed()->find($meta['invoice_id'])) {
                return redirect()->route('m.show', ['fin', $meta['invoice_id']])->with('ok', 'حُوّل من قبل — هذه فاتورته');
            }

            $inv = FinDocument::create([
                'doc_no'      => mb_substr('INV-' . $q->doc_no, 0, 300),   // يُقصّ إلى عرض العمود
                'kind'        => 'فاتورة مبيعات',
                'client_id'   => $q->client_id,   // المرجع الصريح — الاسم النصي يضيع بالتكرار وتغيير الاسم
                'service_id'  => $q->service_id,
                'partner'     => $q->client_id ? (Client::find($q->client_id)?->name ?? '') : '',
                'date'        => now()->toDateString(),
                'due'         => now()->addDays(14)->toDateString(),
                'amount'      => $q->amount ?? $q->total,
                'tax'         => $q->tax ?? 0,
                'total'       => $q->total,
                'paid'        => 0,
                'currency'    => $q->currency,
                'state'       => 'مرسلة',
                'project_id'  => $q->project_id,
                'company_id'  => $q->company_id,
                'description' => 'فاتورة بموجب عرض السعر ' . $q->doc_no,
            ]);
            $q->meta = $meta + ['invoice_id' => $inv->id];
            $q->save();

            return redirect()->route('m.show', ['fin', $inv->id])
                ->with('ok', '🧾 أُنشئت الفاتورة من العرض — استحقاقها بعد ١٤ يوماً');
        });
    }

}
