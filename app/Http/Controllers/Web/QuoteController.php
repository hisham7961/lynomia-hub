<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Models\FinDocument;
use App\Models\Quote;
use App\Models\QuoteMilestone;
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

    /**
     * عرضُ المشروع الاحترافيّ PDF (mPDF، RTL) — عرضٌ تجاريٌّ لا فاتورة.
     * يتساقط لعرض HTML إن غابت المكتبةُ أو فشل التوليد، فلا شاشةَ بيضاء.
     */
    public function pdf(string $id)
    {
        abort_unless(hub_can(auth()->user(), 'quotes', 'v'), 403);
        $q = hub_scope(Quote::query(), 'quotes')->findOrFail($id);

        $html = \App\Support\Proposal::html($q);
        $bin = \App\Support\DocRenderer::pdf($html, 'عرض ' . $q->doc_no);
        if ($bin === null) {
            // بلا mPDF: تُقدَّم نسخةٌ HTML قابلةٌ للطباعة من المتصفح
            return response($html)->header('Content-Type', 'text/html; charset=utf-8');
        }
        hub_audit('توليد عرض PDF', 'quotes', $q->id, $q->doc_no);

        return response($bin)->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="proposal-' . $q->doc_no . '.pdf"');
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
            'clone'    => $this->cloneQuote($q),
            'sections' => $this->setSections($r, $q),
            'terms'    => $this->applyTerms($r, $q),
            // معالمُ الدفع بعد القبول (v2.399): بلوغٌ/تراجعٌ/سكُّ فاتورةِ دفعة
            'ms.reach'   => $this->msReach($r, $q),
            'ms.unreach' => $this->msUnreach($r, $q),
            'ms.invoice' => $this->msInvoice($r, $q),
            default    => abort(422),
        };
    }

    /* ────────── معالمُ الدفع (v2.399) ────────── */

    /**
     * معلمٌ من جدول مدفوعات هذا العرض تحديداً — معرّفٌ من عرضٍ آخر يسقط 404
     * (المعلمُ يُقرأ عبر العرض المنطَّق، لا بمعرّفه المجرّد).
     */
    protected function milestone(Request $r, Quote $q): QuoteMilestone
    {
        return QuoteMilestone::query()->where('quote_id', $q->id)
            ->findOrFail(hub_str($r->input('ms')));
    }

    /**
     * إعلانُ بلوغ المعلم — فعلٌ بشريٌّ مسجَّلٌ (من ومتى). لا يُبلَغ معلمٌ في عرضٍ
     * لم يُقبَل: جدولُ السداد يصير التزاماً بالقبول لا قبله. متكرّرٌ بلا أثر.
     */
    protected function msReach(Request $r, Quote $q)
    {
        abort_unless(in_array($q->status, ['مقبول', 'محوّل'], true), 422, 'المعالمُ تُبلَغ بعد قبول العرض');
        $ms = $this->milestone($r, $q);
        if ($ms->reached_at) return back()->with('ok', 'المعلمُ مُعلَنٌ بلوغُه من قبل');

        $ms->reached_at = now();
        $ms->reached_by = auth()->id();
        $ms->save();
        hub_audit('بلوغ معلم دفع', 'quotes', $q->id, $q->doc_no . ' — ' . $ms->title);

        return back()->with('ok', '🏁 أُعلن بلوغُ المعلم «' . $ms->title . '» — سُكّ فاتورتَه حين تحين');
    }

    /**
     * التراجعُ عن البلوغ — ممنوعٌ متى سُكّت فاتورةٌ حيّةٌ للمعلم (الفاتورةُ تسبق:
     * تُلغى أو تُحذف أولاً من الماليّة، ثم يُتراجَع). يُبقي `invoice_id` كما هو.
     */
    protected function msUnreach(Request $r, Quote $q)
    {
        $ms = $this->milestone($r, $q);
        if (! $ms->reached_at) return back()->with('ok', 'المعلمُ غيرُ مُعلَنٍ أصلاً');
        abort_if($ms->hasLiveInvoice(), 422, 'للمعلم فاتورةٌ حيّة — أَلغِها أو احذفها من الماليّة قبل التراجع');

        $ms->reached_at = null;
        $ms->reached_by = null;
        $ms->save();
        hub_audit('تراجع عن بلوغ معلم دفع', 'quotes', $q->id, $q->doc_no . ' — ' . $ms->title);

        return back()->with('ok', 'أُلغي إعلانُ بلوغ المعلم «' . $ms->title . '»');
    }

    /**
     * سكُّ فاتورةِ دفعةٍ لمعلمٍ — تصليبُ `toInvoice` نفسُه: صلاحيةُ إنشاء المستندات
     * الماليّة، معاملةٌ على صفٍّ مقفول، وwithTrashed فلا فاتورتان للمعلم الواحد
     * (من نقرٍ مزدوج أو من حذف الأولى بنعومة ثم إعادة السكّ). يُعلِن البلوغَ إن لم
     * يكن مُعلَناً. القيمةُ بقاعدة الشاشة والمستند (مبلغٌ صريحٌ وإلا نسبةٌ من
     * الإجماليّ)، والضريبةُ بنسبتها من العرض.
     */
    protected function msInvoice(Request $r, Quote $q)
    {
        abort_unless(hub_can(auth()->user(), 'fin', 'a'), 403,
            'سكُّ فاتورة المعلم يتطلب صلاحية إنشاء المستندات المالية');
        abort_unless(in_array($q->status, ['مقبول', 'محوّل'], true), 422, 'فواتيرُ المعالم تُسكّ بعد قبول العرض');
        $msId = $this->milestone($r, $q)->id;

        return DB::transaction(function () use ($q, $msId) {
            $ms = QuoteMilestone::whereKey($msId)->lockForUpdate()->firstOrFail();
            if ($ms->invoice_id && FinDocument::withTrashed()->find($ms->invoice_id)) {
                return redirect()->route('m.show', ['fin', $ms->invoice_id])->with('ok', 'سُكّت من قبل — هذه فاتورةُ المعلم');
            }
            // لا ازدواجَ فوترة: العرضُ المفوتَرُ كاملاً (do=invoice) إيرادُه مُطالَبٌ به كلُّه.
            // تُقرأ الصورةُ الطازجةُ للعرض داخل المعاملة لا نسخةَ المتحكّم.
            $fresh = Quote::query()->whereKey($q->getKey())->first(['id', 'meta']);
            abort_if($fresh && $fresh->hasLiveFullInvoice(), 422,
                'للعرض فاتورةٌ كاملةٌ حيّة — لا تُسكّ فاتورةُ دفعةٍ فوقها (أَلغِ الكاملةَ أولاً إن كان القصدُ الفوترةَ بالدفعات)');

            $total = $ms->amountDue($q);
            abort_if($total <= 0, 422, 'المعلمُ بلا قيمة (لا مبلغَ ولا نسبة) — لا تُسكّ فاتورةٌ صفريّة');
            // الضريبةُ بنسبتها من العرض: (ضريبة/إجماليّ) × قيمةِ الدفعة
            $ratio = (float) $q->total > 0 ? (float) ($q->tax ?? 0) / (float) $q->total : 0.0;
            $tax = round($total * $ratio, 3);
            $amount = round($total - $tax, 3);

            // رقمٌ مميَّز: INV-<العرض>-M<ترتيب المعلم>؛ وإن اصطدم (معلمٌ حُذف وأُعيد
            // بالترتيب نفسه) يُلحَق -2، -3… — الفريدُ على doc_no يبقى مصانا.
            $base = mb_substr('INV-' . $q->doc_no . '-M' . max(1, (int) $ms->sort), 0, 290);
            $docNo = $base;
            for ($i = 2; FinDocument::withTrashed()->where('doc_no', $docNo)->exists() && $i < 50; $i++) {
                $docNo = $base . '-' . $i;
            }

            // مشروعُ الفاتورة: مشروعُ العرض، وإلا المشروعُ الذي حُوِّل إليه (إن كان حيّاً)
            $meta = (array) $q->meta;
            $projectId = $q->project_id ?: null;
            if (! $projectId && ! empty($meta['project_id'])
                && \App\Models\Project::query()->whereKey($meta['project_id'])->exists()) {
                $projectId = $meta['project_id'];
            }

            $inv = FinDocument::create([
                'doc_no'      => mb_substr($docNo, 0, 300),
                'kind'        => 'فاتورة مبيعات',
                'client_id'   => $q->client_id,
                'service_id'  => $q->service_id,
                'partner'     => $q->client_id ? (Client::find($q->client_id)?->name ?? '') : '',
                'date'        => now()->toDateString(),
                'due'         => now()->addDays(14)->toDateString(),
                'amount'      => $amount,
                'tax'         => $tax,
                'total'       => $total,
                'paid'        => 0,
                'currency'    => $q->currency,
                'state'       => 'مرسلة',
                'project_id'  => $projectId,
                'company_id'  => $q->company_id,
                'description' => mb_substr('فاتورة دفعة «' . $ms->title . '» بموجب عرض السعر ' . $q->doc_no, 0, 1000),
                'meta'        => ['quote_id' => $q->id, 'milestone_id' => $ms->id],
            ]);

            $ms->invoice_id = $inv->id;
            if (! $ms->reached_at) {
                $ms->reached_at = now();
                $ms->reached_by = auth()->id();
            }
            $ms->save();
            hub_audit('سكّ فاتورة معلم', 'quotes', $q->id, $q->doc_no . ' — ' . $ms->title . ' → ' . $inv->doc_no);

            return redirect()->route('m.show', ['fin', $inv->id])
                ->with('ok', '🧾 سُكّت فاتورةُ الدفعة «' . $ms->title . '» — استحقاقها بعد ١٤ يوماً');
        });
    }

    /**
     * **أقسامُ العرض الديناميكية**: يضبط أيّ الأقسامِ السرديّة تُخفى في مستند
     * العميل (`meta['proposal_hidden']`). المُرسَلُ هو المرئيّ، فالمخفيّ = ما لم
     * يُختَر من الأقسام القابلة للإخفاء. لا يمسّ التسعيرَ ولا الغلافَ ولا القبول.
     */
    protected function setSections(Request $r, Quote $q)
    {
        $keys = array_keys(Quote::PROPOSAL_SECTIONS);
        $show = array_values(array_intersect((array) $r->input('show', []), $keys));
        $hidden = array_values(array_diff($keys, $show));

        $meta = (array) $q->meta;
        $meta['proposal_hidden'] = $hidden;
        $q->meta = $meta;
        $q->save();
        hub_audit('ضبط أقسام العرض', 'quotes', $q->id, $q->doc_no);

        return back()->with('ok', 'حُدِّثت أقسامُ العرض الظاهرةُ في مستند العميل');
    }

    /**
     * **مكتبةُ الشروط**: يُدرِج شروطَ عرضٍ قالبٍ (`is_template`) في هذا العرض — نصٌّ
     * جاهزٌ يُعاد استعماله بلا إعادة كتابة. يُلحَق بالشروط القائمة أو يستبدلها
     * (`mode=replace`). يُعاد استعمالُ آليّة القوالب القائمة لا جدولٌ جديد.
     */
    protected function applyTerms(Request $r, Quote $q)
    {
        $tplId = hub_str($r->input('from'));
        // القالبُ يُنطَّق كأيّ عرض — لا يُقرأ شرطُ عرضٍ خارج نطاق المستخدم
        $tpl = hub_scope(Quote::query(), 'quotes')->where('is_template', true)->find($tplId);
        abort_unless($tpl, 422, 'قالبُ الشروط غير موجودٍ أو خارج نطاقك');

        $snippet = trim((string) $tpl->terms);
        if ($snippet === '') return back()->with('err', 'القالبُ المختارُ بلا شروط');

        $mode = hub_str($r->input('mode'));
        $current = trim((string) $q->terms);
        $q->terms = ($mode === 'replace' || $current === '')
            ? $snippet
            : $current . "\n\n" . $snippet;
        $q->save();
        hub_audit('إدراج شروطٍ من قالب', 'quotes', $q->id, $q->doc_no . ' ← ' . $tpl->doc_no);

        return back()->with('ok', 'أُدرجت الشروطُ من القالب — راجعها قبل الإرسال');
    }

    /**
     * **استنساخُ العرض عرضاً جديداً** — أساسُ القوالب القابلة لإعادة الاستخدام.
     *
     * يُنشئ مسودةً جديدةً تنسخ السرديّة (ملخّص/هدف/نطاق/افتراضات/شروط) وكلَّ
     * البنود والمراحل — بلا إعادة إدخال. يُصفَّر ما يخصّ النسخة الأصل: الرقمُ
     * يُولَّد جديداً، والحالةُ «مسودة»، والقبولُ والإرسالُ وربطُ التحويل تُمحى،
     * والقالبيّةُ لا تُورَّث (النسخةُ عرضٌ حيّ لا قالب). صلاحيةُ التعديل تكفي
     * (كإجراءات المسار) — النسخُ لا يسكّ عقداً ولا فاتورة.
     */
    protected function cloneQuote(Quote $q)
    {
        return DB::transaction(function () use ($q) {
            $src = Quote::whereKey($q->getKey())->lockForUpdate()->firstOrFail();

            $copy = ['company_id', 'client_id', 'project_id', 'service_id', 'owner_id',
                'am_id', 'pm_id', 'title', 'currency', 'billing', 'discount',
                'exec_summary', 'objective', 'scope', 'assumptions', 'exclusions',
                'terms', 'items'];
            $data = [];
            foreach ($copy as $c) $data[$c] = $src->{$c};
            $data['title'] = mb_substr('نسخة من ' . ($src->title ?: $src->doc_no), 0, 300);
            $data['status'] = 'مسودة';
            $data['is_template'] = false;   // النسخةُ عرضٌ حيّ لا قالب
            // إجمالياتٌ صفريّةٌ ابتداءً (العمودُ غيرُ فارغ) — recalc يُعيد حسابها من البنود
            $data['amount'] = $data['tax'] = $data['total'] = $data['cost'] = 0;
            // doc_no/accepted/sent/meta/engagement تُترك فارغةً → ترقيمٌ جديد وسجلٌّ نظيف
            $new = Quote::create($data);

            // البنود ثم المراحل — بترتيبها، ويُعاد حساب الإجماليات خادمياً
            foreach ($src->lines()->get() as $l) {
                $ld = $l->only(['kind', 'service_id', 'product_id', 'phase', 'title',
                    'description', 'qty', 'unit', 'unit_price', 'discount_pct',
                    'tax_pct', 'unit_cost', 'sort', 'meta']);
                $new->lines()->create($ld);
            }
            foreach ($src->milestones()->get() as $m) {
                $md = $m->only(['title', 'pct', 'amount', 'trigger', 'phase',
                    'due_note', 'sort', 'meta']);
                $new->milestones()->create($md);
            }
            $new->recalc();

            hub_audit('استنساخ عرض', 'quotes', $new->id, $new->doc_no . ' ← ' . $src->doc_no);

            return redirect()->route('m.show', ['quotes', $new->id])
                ->with('ok', '📋 أُنشئت مسودةٌ جديدةٌ من العرض — راجع العميلَ والتواريخ ثم أرسِل');
        });
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
        // **حاجزُ الهامش** (CPQ): هامشٌ دون الحدّ المضبوط يستوجب اعتماداً كالمبلغ
        // والخصم — لا مجرّد تلوينٍ أحمر يُتجاوَز. (٠ = مطفأ.)
        $floorAt = (float) setting('quotes.margin_floor', 0);
        $margin = $q->margin();
        $needs = ($amountAt > 0 && (float) $q->total >= $amountAt)
            || ($discAt > 0 && $discPct >= $discAt)
            || ($floorAt > 0 && $margin !== null && $margin < $floorAt);

        if ($needs && ! hub_flag(auth()->user(), 'approve') && ! hub_is_owner()) {
            // يُبلَّغ المعتمدون بطلبِ إرسالٍ يستحق نظرَهم — دون قلبِ الحالة.
            // **نطاقٌ لكلّ مستلم**: لا يُسرَّب عنوانُ العرض ومبلغُه لمعتمِدٍ معزولٍ
            // عن شركة/عميل العرض (كنمط notifyMonitors في المسار الآليّ).
            foreach (array_unique(hub_approvers_for('quotes', $q->id)) as $oid) {
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

        // **أرشفةُ العرض المُصدَر** (CPQ هـ): لقطةٌ ثابتةٌ من العرض لحظةَ الإرسال —
        // فالتاريخيُّ لا يُعاد توليدُه من بياناتٍ متغيّرة لاحقاً (سلامةٌ تاريخية).
        $this->archiveProposal($q, 'إرسال');

        return $this->setStatus($q, 'مُرسل', '📨 حُدّد العرض كمُرسل للعميل');
    }

    /**
     * أرشفةُ العرض الاحترافيّ كمرفقٍ ثابتٍ على السجل (نمط `archiveSignedCopy`):
     * PDF إن توفّرت المكتبة وإلا HTML — فيبقى أثرٌ للمُصدَر دوماً. لا يُفشل الفعلَ.
     */
    protected function archiveProposal(Quote $q, string $tag): void
    {
        try {
            $html = \App\Support\Proposal::html($q->fresh());
            $pdf = \App\Support\DocRenderer::pdf($html, 'عرض ' . $q->doc_no);
            [$blob, $mime, $ext] = $pdf
                ? [$pdf, 'application/pdf', 'pdf']
                : [$html, 'text/html', 'html'];
            $path = 'hub/att/quote-' . $q->doc_no . '-v' . (int) $q->version . '-' . uniqid() . '.' . $ext;
            \Illuminate\Support\Facades\Storage::disk('local')->put($path, $blob);
            \App\Models\Attachment::create([
                'module' => 'quotes', 'record_id' => $q->id,
                'field' => 'عرض مؤرشَف — ' . $tag . ' (نسخة ' . (int) $q->version . ')',
                'disk' => 'local', 'path' => $path,
                'original_name' => 'proposal-' . $q->doc_no . '-v' . (int) $q->version . '.' . $ext,
                'mime' => $mime, 'size' => strlen($blob),
                'checksum' => hash('sha256', $blob),
                'uploaded_by' => auth()->id(),
            ]);
        } catch (\Throwable $e) {
            report($e);   // الأرشفةُ إضافةٌ — فشلُها لا يُفشل الإرسال
        }
    }

    /**
     * **مقارنةُ النسختين** (CPQ هـ): ما تغيّر بين نسخةٍ سابقةٍ والحاليّة —
     * سعرٌ ونطاقٌ وحالةٌ وصلاحية. من `record_versions` (لقطاتُ الحفظ)، فالتفاوضُ
     * يُقرأ بوضوح. لا يُعدَّل شيءٌ — عرضٌ فقط.
     */
    public function diff(Request $r, string $id)
    {
        abort_unless(hub_can(auth()->user(), 'quotes', 'v'), 403);
        $q = hub_scope(Quote::query(), 'quotes')->findOrFail($id);
        $versions = $q->versions()->get(['version', 'snapshot', 'created_at']);

        // النسخةُ المرجعُ: من ?v= أو الأسبق مباشرةً قبل الحاليّة
        $cur = (array) $q->getAttributes();
        $want = (int) $r->query('v', 0);
        $base = $want > 0
            ? $versions->firstWhere('version', $want)
            : $versions->where('version', '<', (int) $q->version)->sortByDesc('version')->first();
        // اللقطةُ قد تُقرأ نصاً (DB خام) أو مصفوفةً (cast على النموذج) — الحالتان
        $baseSnap = $base
            ? (is_array($base->snapshot) ? $base->snapshot : (array) json_decode((string) $base->snapshot, true))
            : [];

        $watch = [
            'total' => 'الإجمالي', 'amount' => 'الصافي', 'discount' => 'الخصم', 'tax' => 'الضريبة',
            'cost' => 'التكلفة (داخليّ)', 'mrr' => 'MRR', 'arr' => 'ARR',
            'status' => 'الحالة', 'title' => 'العنوان', 'scope' => 'النطاق',
            'exec_summary' => 'الملخّص التنفيذي', 'valid' => 'صالح حتى', 'currency' => 'العملة',
        ];
        $hideCost = hub_field_mode(auth()->user(), 'quotes', 'cost') === 'hide';
        $changes = [];
        foreach ($watch as $col => $label) {
            if ($hideCost && in_array($col, ['cost', 'mrr', 'arr'], true)) continue;
            $old = (string) ($baseSnap[$col] ?? '');
            $new = (string) ($cur[$col] ?? '');
            if ($old !== $new) $changes[] = ['label' => $label, 'old' => $old, 'new' => $new];
        }

        return view('quotes.diff', [
            'q' => $q, 'versions' => $versions,
            'baseVersion' => $base->version ?? null, 'changes' => $changes,
        ]);
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

        // أرشفةُ النسخة المقبولة — الوثيقةُ التي وافق عليها العميلُ تُجمَّد كما هي
        if ($status === 'مقبول') $this->archiveProposal($q, 'مقبول');

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
