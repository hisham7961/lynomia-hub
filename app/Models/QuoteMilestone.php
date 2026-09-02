<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * دفعةٌ في جدول مدفوعات العرض — نسبةٌ أو مبلغٌ عند محفّزٍ (قبول/مرحلة/تسليم).
 *
 * منذ v2.399 تحمل حالةَ **بلوغ** (`reached_at`/`reached_by`) ورابطَ **فاتورة**
 * (`invoice_id` → `fin_documents`): فمعلمٌ بُلغ ولم تُسكّ فاتورتُه بعدُ فجوةُ
 * تحصيلٍ حقيقيّةٌ تُقاس لا تُختلَق.
 */
class QuoteMilestone extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'quote_milestones';
    protected $guarded = ['id'];

    protected $casts = [
        'pct' => 'decimal:3',
        'amount' => 'decimal:3',
        'meta' => 'array',
        'due_date' => 'date',
        'reached_at' => 'datetime',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'quote_id');
    }

    /** فاتورةُ المعلم (إن سُكّت) — المحذوفةُ بنعومة تُرى عبر withTrashed عند الحاجة */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinDocument::class, 'invoice_id');
    }

    /**
     * قيمةُ الدفعة: المبلغُ الصريحُ إن وُجد، وإلا النسبةُ من إجماليّ العرض —
     * القاعدةُ نفسُها التي تطبع بها الشاشةُ ومستندُ العميل (Proposal).
     */
    public function amountDue(?Quote $q = null): float
    {
        $q = $q ?? $this->quote;
        if ((float) $this->amount > 0) return round((float) $this->amount, 3);
        if ((float) $this->pct > 0 && $q) return round((float) $q->total * (float) $this->pct / 100, 3);

        return 0.0;
    }

    /**
     * هل للمعلم فاتورةٌ حيّة؟ — موجودةٌ (لا محذوفةٌ بنعومة) وليست في حالةٍ ميتة
     * (ملغاة/مسودة من `config('hub.fin.dead')`). الفاتورةُ الحيّةُ تُطفئ إشارةَ
     * «بُلغ ولم يُفوتَر» تلقائياً؛ وحذفُها أو إلغاؤها يُعيدها.
     */
    public function hasLiveInvoice(): bool
    {
        return FinDocument::isLive($this->invoice_id);
    }
}
