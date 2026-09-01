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
        // خصمٌ على مستوى العرض (اختياريّ) يُطرح من الصافي والإجمالي
        $disc = (float) ($this->discount ?: 0);
        if ($disc > 0) {
            $net = round($net - $disc, 3);
            $total = round($total - $disc, 3);
        }
        $tax = round($total - $net, 3);

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
