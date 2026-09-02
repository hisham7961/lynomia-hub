<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use App\Traits\HasVersions;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** عروض الأسعار — والعروضُ المهنيّة للمشاريع (بنودٌ مهيكلة ومراحل ومدفوعات). */
class Quote extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'quotes';
    public const MODULE = 'quotes';
    public const DISPLAY = 'doc_no';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'date' => 'date',
        'valid' => 'date',
        'amount' => 'decimal:3',
        'tax' => 'decimal:3',
        'total' => 'decimal:3',
        'discount' => 'decimal:3',
        'cost' => 'decimal:3',
        'mrr' => 'decimal:3',
        'arr' => 'decimal:3',
        'tcv' => 'decimal:3',
        'accepted_at' => 'datetime',
        'sent_at' => 'datetime',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
        'is_template' => 'boolean',
    ];

    protected static function booted(): void
    {
        // ترقيمٌ تلقائيّ للعرض إن تُرك doc_no فارغاً — نمطُ العقد حرفياً
        static::creating(function (self $q) {
            if (! $q->doc_no) $q->doc_no = self::nextDocNo();
        });
        // المجموعُ عمودٌ إلزاميّ بلا افتراضي: نموذجٌ بلا بنود كان يسقط بـ500 (v2.399) — يُحسب من المبلغ والضريبة
        static::saving(function (self $q) {
            if ($q->total === null || $q->total === '') $q->total = round((float) ($q->amount ?? 0) + (float) ($q->tax ?? 0), 3);
            // عرضٌ ببنودٍ مهيكلة: المبلغُ والضريبةُ والإجماليُّ تُحسَب من البنود (recalc) — كتابةٌ من
            // النموذج العامّ أو الـAPI كانت تدوسها بلا إعادة حساب (ARCH-02, v2.399). recalc نفسُه يكتب
            // بـsaveQuietly فلا يمرّ بهذا الحارس.
            if ($q->exists && $q->isDirty(['amount', 'tax', 'total']) && $q->lines()->exists()) {
                foreach (['amount', 'tax', 'total'] as $col) {
                    if ($q->isDirty($col)) $q->{$col} = $q->getOriginal($col);
                }
            }
        });
    }

    /**
     * إعادةُ المحاولة عند تصادم الرقم الفريد — نمطُ `Contract::save()`:
     * كاتبان متوازيان يقرآن التسلسل نفسه، فيُلتقط خرقُ القيد ويُعاد برقمٍ جديد
     * بدل السقوط بـ500.
     */
    public function save(array $options = []): bool
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return parent::save($options);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($this->exists || $attempt >= 5 || ! self::isDupDocNo($e)) throw $e;
                $this->doc_no = self::nextDocNo();
            }
        }
    }

    protected static function isDupDocNo(\Illuminate\Database\QueryException $e): bool
    {
        return (string) $e->getCode() === '23000'
            && str_contains(mb_strtolower($e->getMessage()), 'doc_no');
    }

    /** الرقم التالي: صيغةٌ من الإعداد (QT-{YEAR}-{SEQ})، مسحُ سنةٍ بأقصى تسلسل */
    public static function nextDocNo(): string
    {
        $fmt = (string) setting('quotes.doc_no_format', 'QT-{YEAR}-{SEQ}');
        $year = now()->format('Y');
        $prefix = str_replace(['{YEAR}', '{SEQ}'], [$year, ''], $fmt);
        $prefix = rtrim($prefix, '-');
        $last = static::withTrashed()->where('doc_no', 'like', $prefix . '%')
            ->orderByDesc('doc_no')->value('doc_no');
        $n = $last ? ((int) preg_replace('/\D/', '', substr((string) $last, strlen($prefix))) + 1) : 1;
        do {
            $candidate = str_replace(['{YEAR}', '{SEQ}'], [$year, sprintf('%04d', $n)], $fmt);
            $n++;
        } while (static::withTrashed()->where('doc_no', $candidate)->exists());

        return $candidate;
    }

    /**
     * إعادةُ حساب إجماليات العرض من بنوده المهيكلة — **خادمياً لا يدوياً**.
     * يكتب amount (صافٍ قبل الضريبة) وtax وtotal وcost (داخلية)، بحساب decimal.
     */
    public function recalc(): void
    {
        // يُحسَب الإجماليُّ من البنود **المُلتزَمة** فقط (الأساسيّ + الاختياريّ
        // المُدرَج) — فالاختياريُّ غير المُدرَج فرصةٌ عُلويّة لا يُلزِم العميل.
        $lines = $this->lines()->get()->filter(fn ($l) => $l->countsToward());
        $net = 0.0; $total = 0.0; $cost = 0.0;
        foreach ($lines as $l) {
            $net = round($net + $l->netBeforeTax(), 3);
            $total = round($total + $l->computeTotal(), 3);
            if ($l->unit_cost !== null) {
                $cost = round($cost + (float) ($l->qty ?: 0) * (float) $l->unit_cost, 3);
            }
        }
        $tax = round($total - $net, 3);   // ضريبةُ البنود قبل الخصم

        // خصمٌ على مستوى العرض (اختياريّ): يُنقص **الوعاءَ الخاضع للضريبة**، فتُعاد
        // الضريبةُ نسبةً للأساس بعد الخصم — لا تبقى ضريبةَ ما قبل الخصم. كان الخصمُ
        // يُطرح بالتساوي من الصافي والإجماليّ، فتُحسَب الضريبةُ على وعاءٍ أكبرَ مما
        // يُدفَع، والمستندُ لا يطابق «صافٍ − خصم + ضريبة = إجماليّ». ولا صافيَ سالبٌ
        // حين يفوق الخصمُ البنودَ (كلُّها اختياريّةٌ غيرُ مُدرَجةٍ مثلاً).
        $disc = (float) ($this->discount ?: 0);
        if ($disc > 0) {
            $base = $net;
            $net = round(max(0.0, $net - $disc), 3);
            $tax = $base > 0 ? round($tax * ($net / $base), 3) : 0.0;
        }
        $total = round($net + $tax, 3);

        // **الملخّصُ التجاريّ** (CPQ): تصنيفُ الإيراد بجانب الإجماليّ لا بدلاً منه.
        // MRR من البنود الدوريّة (السنويّ ÷ ١٢)، ARR = MRR×١٢، وTCV = الإجماليُّ
        // لمرّةٍ واحدة + الإيرادُ السنويّ المتكرّر. الاستخدامُ والتكلفةُ الممرَّرة
        // لا يُدخَلان في MRR (لا يُتنبّأ بهما شهرياً).
        $mrr = 0.0; $oneTime = 0.0;
        foreach ($lines as $l) {
            $lineNet = $l->netBeforeTax();
            $rt = (string) ($l->rev_type ?: 'one_time');
            if ($rt === 'recurring') {
                $monthly = hub_ar_norm((string) $l->rev_period) === hub_ar_norm('سنوي')
                    ? round($lineNet / 12, 3) : $lineNet;
                $mrr = round($mrr + $monthly, 3);
            } elseif ($rt === 'one_time' || $rt === 'pass_through') {
                $oneTime = round($oneTime + $lineNet, 3);
            }
        }
        $arr = round($mrr * 12, 3);
        $tcv = round($oneTime + $arr, 3);

        $this->forceFill([
            'amount' => $net, 'tax' => $tax, 'total' => $total, 'cost' => $cost,
            'mrr' => $mrr, 'arr' => $arr, 'tcv' => $tcv,
        ])->saveQuietly();
    }

    /**
     * الملخّصُ التجاريّ الداخليّ: إيرادٌ لمرّة، شهريّ (MRR)، سنويّ (ARR)، قيمةُ
     * العقد الكلّية (TCV)، والتكلفةُ والهامشُ — **داخليٌّ بحتٌ لا يُعرَض للعميل**.
     */
    public function commercialSummary(): array
    {
        return [
            // خُزِّن TCV = إيرادُ المرّة + ARR، فإيرادُ المرّة = TCV − ARR (لا total
            // الذي يضمّ القيمَ الاسميّة للبنود الدوريّة فيُفسِد الطرح)
            'one_time' => round((float) $this->tcv - (float) $this->arr, 3),
            'mrr' => (float) $this->mrr,
            'arr' => (float) $this->arr,
            'tcv' => (float) $this->tcv,
            'cost' => (float) $this->cost,
            'margin' => $this->margin(),
            'upside' => $this->optionalUpside(),
        ];
    }

    /**
     * **الفرصةُ العُلويّة**: مجموعُ صافي البنود الاختيارية/البديلة/الإضافية غير
     * المُدرَجة في الخطّ المُلتزَم — ما قد يُضيفه العميلُ لو قَبِل الاختياريّ.
     */
    public function optionalUpside(): float
    {
        return round((float) $this->lines()->get()
            ->reject(fn ($l) => $l->countsToward())
            ->sum(fn ($l) => $l->netBeforeTax()), 3);
    }

    /**
     * **أقسامُ العرض القابلة للإظهار/الإخفاء** (CPQ — أقسامٌ ديناميكية): عنوانُ كلٍّ
     * بمفتاحه. الغلافُ والتسعيرُ والقبولُ ثابتةٌ لا تُخفى — أما السرديّةُ والجداولُ
     * فاختياريّةُ العرض. الافتراض: كلُّها ظاهرة (سلوكٌ قائمٌ لا يتغيّر).
     */
    public const PROPOSAL_SECTIONS = [
        'exec_summary' => 'الملخّص التنفيذي',
        'objective'    => 'هدف المشروع',
        'scope'        => 'نطاق العمل',
        'phases'       => 'المراحل والتسليمات',
        'optional'     => 'البنود الاختيارية',
        'payments'     => 'جدول المدفوعات',
        'assumptions'  => 'الافتراضات',
        'exclusions'   => 'خارج النطاق',
        'terms'        => 'الشروط والأحكام',
    ];

    /** مفاتيحُ الأقسام المخفيّة في هذا العرض (من meta) — الافتراضُ لا شيء */
    public function hiddenSections(): array
    {
        $h = (array) (($this->meta['proposal_hidden'] ?? []));

        return array_values(array_intersect($h, array_keys(self::PROPOSAL_SECTIONS)));
    }

    /** هل يُعرَض هذا القسمُ في مستند العميل؟ (غيرُ المخفيِّ يُعرَض) */
    public function showsSection(string $key): bool
    {
        return ! in_array($key, $this->hiddenSections(), true);
    }

    /**
     * فحصُ الجودة التجاريّ قبل الإرسال — تحذيراتٌ لا حجبٌ صامت: عميلٌ وعملة
     * وبنودٌ وجدولُ دفعٍ يجمع ١٠٠٪ (حين تُستعمل النِّسب) وبنودٌ مسعَّرة. يُرجع
     * قائمةَ مشكلاتٍ مفسَّرة (فارغةٌ = سليم).
     */
    public function qualityCheck(): array
    {
        $issues = [];
        if (! $this->client_id) $issues[] = 'لا عميلَ محدَّدٌ للعرض.';
        if (! $this->currency) $issues[] = 'العملةُ غير محدَّدة.';

        $lines = $this->lines()->get();
        if ($lines->isEmpty()) $issues[] = 'العرضُ بلا بنود.';
        foreach ($lines as $l) {
            $optional = ($l->line_mode ?: 'required') !== 'required';
            if (! $optional && (float) $l->unit_price <= 0) {
                $issues[] = 'بندٌ بلا سعر: «' . $l->title . '».';
            }
        }

        // كلُّ مجموعةِ بدائل يجب أن يكون فيها بديلٌ واحدٌ مُدرَجٌ لا أكثر
        foreach ($lines->where('line_mode', 'alternative')->groupBy('opt_group') as $grp => $alts) {
            $on = $alts->where('included', true)->count();
            if ($grp && $on !== 1) {
                $issues[] = 'مجموعةُ البدائل «' . $grp . '» فيها ' . $on . ' بديلاً مُدرَجاً (المطلوب واحد).';
            }
        }

        // جدولُ الدفع بالنِّسب يجب أن يجمع ١٠٠٪ (± ٠٫١)
        $ms = $this->milestones()->get();
        $pctSum = round($ms->sum(fn ($m) => (float) $m->pct), 3);
        if ($ms->isNotEmpty() && $pctSum > 0 && abs($pctSum - 100) > 0.1) {
            $issues[] = 'جدولُ الدفع بالنِّسب يجمع ' . rtrim(rtrim(number_format($pctSum, 2), '0'), '.') . '٪ لا ١٠٠٪.';
        }

        // هامشٌ دون الحدّ (إن ضُبط) — تحذيرٌ ظاهر (والحجبُ للاعتماد في send)
        $floor = (float) setting('quotes.margin_floor', 0);
        $m = $this->margin();
        if ($floor > 0 && $m !== null && $m < $floor) {
            $issues[] = 'الهامشُ ' . $m . '٪ دون الحدّ (' . $floor . '٪) — يتطلّب اعتماداً عند الإرسال.';
        }

        return $issues;
    }

    /** الهامش المتوقّع % (داخليّ) — لا يظهر للعميل */
    public function margin(): ?float
    {
        $total = (float) $this->total;
        if ($total <= 0) return null;

        return round(($total - (float) $this->cost) / $total * 100, 1);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class, 'quote_id')->orderBy('sort')->orderBy('id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(QuoteMilestone::class, 'quote_id')->orderBy('sort')->orderBy('id');
    }

    /**
     * هل للعرض فاتورةٌ كاملةٌ حيّة (من `do=invoice`, المؤشَّرُ إليها بـ`meta.invoice_id`)؟
     * إن كانت، فإيرادُ العرض مُطالَبٌ به كلُّه — فلا تُسكّ فاتورةُ دفعةٍ فوقه ولا تُعدّ
     * معالمُه «بلا فاتورة» (v2.399: منعُ ازدواج الفوترة).
     */
    public function hasLiveFullInvoice(bool $lock = false): bool
    {
        $id = ((array) $this->meta)['invoice_id'] ?? null;

        return is_string($id) && $id !== '' && FinDocument::isLive($id, $lock);
    }

    /**
     * هل لأيّ معلمٍ من معالم العرض فاتورةُ دفعةٍ حيّة؟ — الوجهُ المقابلُ لـ
     * `hasLiveFullInvoice`: إن سُكّت فاتورةُ دفعةٍ حيّةٌ فلا تُسكّ الفاتورةُ الكاملةُ
     * فوقها (v2.399.1: منعُ ازدواج الفوترة في الاتجاهين).
     */
    public function hasLiveMilestoneInvoice(bool $lock = false): bool
    {
        return FinDocument::anyLive(array_keys(static::milestoneInvoiceOwners([$this->getKey()], $lock)), $lock);
    }

    /**
     * مجموعُ فواتير الدفعات الحيّة على هذا العرض — سقفُ ما يُسكّ بعدها هو
     * `total − هذا المجموع` (v2.399.1: لا يتجاوز مجموعُ الدفعات إجماليَّ العرض).
     * يشمل معالمَ حُذفت بنعومة كما `hasLiveMilestoneInvoice`: فاتورتُها ما زالت مطالبة.
     *
     * `$lock`: قراءةٌ قافلة (`FOR UPDATE`) لقرارات الحرّاس داخل معاملة — على InnoDB
     * القراءةُ العاديّةُ تُجيب من صورةٍ ثبّتتها أوّلُ قراءةٍ في المعاملة، فلا ترى سكّاً
     * أُودع بعدها ولو بعد قفلِ العرض؛ والقافلةُ ترى الأحدثَ المودَع دائماً.
     */
    public function liveMilestoneInvoicedTotal(bool $lock = false): float
    {
        return static::liveMilestoneInvoicedTotals([$this->getKey()], $lock)[$this->getKey()] ?? 0.0;
    }

    /**
     * الصورةُ الجماعيّةُ لما سبق: `[quote_id => مجموع فواتير الدفعات الحيّة]` لعدّة عروضٍ
     * باستعلامين — للإشارة ١٥ (صفحة معالم) بدل استعلامٍ لكلّ معلم.
     *
     * @param  array<int, string>  $quoteIds
     * @return array<string, float>
     */
    public static function liveMilestoneInvoicedTotals(array $quoteIds, bool $lock = false): array
    {
        $owners = static::milestoneInvoiceOwners($quoteIds, $lock);
        if (! $owners) {
            return [];
        }
        $dead = (array) config('hub.fin.dead', []);
        $out = [];
        // مجموعُ الحيّة في PHP على خريطة «فاتورة ← عرض» — لا ربطَ في SQL على `invoice_id`
        // وحده (كان يُسقط السابقةَ المُستعادة) ولا مقارنةَ مسار JSON بعمود (تباينُ المحرّكين)
        \Illuminate\Support\Facades\DB::table('fin_documents')
            ->whereIn('id', array_keys($owners))->whereNull('deleted_at')
            ->when($dead, fn ($q) => $q->where(fn ($w) => $w->whereNull('state')->orWhereNotIn('state', $dead)))
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get(['id', 'total'])
            ->each(function ($fd) use (&$out, $owners) {
                $qid = $owners[(string) $fd->id];
                $out[$qid] = round(($out[$qid] ?? 0.0) + (float) $fd->total, 3);
            });

        return $out;
    }

    /**
     * خريطةُ «معرّف فاتورة ← معرّف عرض» لكلّ فاتورةٍ رُبطت يوماً بمعلمٍ من معالم هذه
     * العروض — الحاليّةُ والسوابقُ (`QuoteMilestone::invoiceIds`)، حتى في معالمَ حُذفت
     * بنعومة (فاتورتُها الحيّةُ ما زالت مطالبةً للعميل). المصدرُ الواحدُ لقرّاء
     * «فواتير دفعات العرض»: الحارسُ والسقفُ والشاشةُ والإشارة ١٥.
     *
     * @param  array<int, string>  $quoteIds
     * @return array<string, string>
     */
    public static function milestoneInvoiceOwners(array $quoteIds, bool $lock = false): array
    {
        $quoteIds = array_values(array_filter(array_unique(array_map('strval', $quoteIds))));
        if (! $quoteIds) {
            return [];
        }
        $owners = [];
        \Illuminate\Support\Facades\DB::table('quote_milestones')
            ->whereIn('quote_id', $quoteIds)
            ->where(fn ($w) => $w->whereNotNull('invoice_id')->orWhereNotNull('meta'))
            ->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get(['quote_id', 'invoice_id', 'meta'])
            ->each(function ($m) use (&$owners) {
                foreach (QuoteMilestone::invoiceIdsOf($m->invoice_id, $m->meta) as $id) {
                    $owners[$id] ??= (string) $m->quote_id;
                }
            });

        return $owners;
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Engagement::class, 'engagement_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Client::class, 'client_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Company::class, 'company_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_id');
    }
}
