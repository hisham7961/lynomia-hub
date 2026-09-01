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
        $lines = $this->lines()->get();
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
        $this->forceFill([
            'amount' => $net, 'tax' => $tax, 'total' => $total, 'cost' => $cost,
        ])->saveQuietly();
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
