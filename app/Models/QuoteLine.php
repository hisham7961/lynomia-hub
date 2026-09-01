<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * بندُ عرضٍ مهيكل — خدمةٌ أو مرحلةٌ أو تسليمٌ بكميةٍ وسعرٍ وخصمٍ وضريبة.
 * الإجماليّ يُحسَب خادمياً في `computeTotal()` — لا يُكتَب باليد.
 */
class QuoteLine extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'quote_lines';
    protected $guarded = ['id'];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_price' => 'decimal:3',
        'discount_pct' => 'decimal:3',
        'tax_pct' => 'decimal:3',
        'line_total' => 'decimal:3',
        'unit_cost' => 'decimal:3',
        'included' => 'boolean',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $l) {
            $l->line_total = $l->computeTotal();
        });
    }

    /**
     * هل يدخل هذا البندُ **الخطَّ المُلتزَم** (الإجماليّ)؟ الأساسيّ دائماً؛ أما
     * الاختياريّ/البديل/الإضافة فبحسب `included` — فغيرُ المُدرَج فرصةٌ عُلويّة
     * لا التزام. (CPQ المرحلة ب)
     */
    public function countsToward(): bool
    {
        if (($this->line_mode ?: 'required') === 'required') return true;

        return (bool) $this->included;
    }

    /**
     * الإجماليّ: (كمية × سعر) بعد الخصم زائدَ الضريبة.
     *
     * لا bcmath في هذه البيئة، فيُتَّبع عرفُ المال في النظام نفسِه (float مع
     * `round` لثلاث منازل عند كل حدّ، كما `hub_project_pl`) — لا يدويٌّ ولا
     * تراكمَ انحرافٍ عائم يظهر في مستند.
     */
    public function computeTotal(): float
    {
        $net = $this->netBeforeTax();

        return round($net * (1 + (float) ($this->tax_pct ?: 0) / 100), 3);
    }

    /** الإجماليّ قبل الضريبة — للفصل بين الصافي والضريبة على مستوى العرض */
    public function netBeforeTax(): float
    {
        $base = (float) ($this->qty ?: 0) * (float) ($this->unit_price ?: 0);

        return round($base * (1 - (float) ($this->discount_pct ?: 0) / 100), 3);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'quote_id');
    }
}
