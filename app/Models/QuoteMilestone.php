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
     * كلُّ فاتورةٍ رُبطت بالمعلم يوماً: الحاليّةُ (`invoice_id`) ثم السوابقُ التي ماتت
     * فسُكّ بدلُها (`meta.prev_invoices`، v2.399.1). الميتةُ قد تعود إلى الحياة من
     * الماليّة (حالةٌ تُرجَع، استعادةٌ من السلّة) — فكلُّ قارئٍ لـ«فاتورة المعلم»
     * يقرأ هذه القائمةَ كلَّها لا العمودَ وحدَه، وإلا عُدّت فاتورةٌ حيّةٌ لا شيء.
     *
     * @return array<int, string>
     */
    public function invoiceIds(): array
    {
        return static::invoiceIdsOf($this->invoice_id, $this->meta);
    }

    /**
     * القاعدةُ نفسُها لصفٍّ خامٍ (استعلامُ الإشارة ١٥ يقرأ `DB::table` لا النموذج):
     * `meta` نصٌّ JSON أو مصفوفةٌ مفكوكة.
     *
     * @return array<int, string>
     */
    public static function invoiceIdsOf(?string $invoiceId, mixed $meta): array
    {
        $meta = is_string($meta) ? (json_decode($meta, true) ?: []) : (array) $meta;
        $ids = array_merge([$invoiceId], (array) ($meta['prev_invoices'] ?? []));

        return array_values(array_unique(array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $ids))));
    }

    /**
     * هل للمعلم فاتورةٌ حيّة؟ — موجودةٌ (لا محذوفةٌ بنعومة) وليست في حالةٍ ميتة
     * (ملغاة/مسودة من `config('hub.fin.dead')`). الفاتورةُ الحيّةُ تُطفئ إشارةَ
     * «بُلغ ولم يُفوتَر» تلقائياً؛ وحذفُها أو إلغاؤها يُعيدها. تشمل السوابقَ المُستعادة.
     */
    public function hasLiveInvoice(bool $lock = false): bool
    {
        return $this->liveInvoiceId($lock) !== null;
    }

    /**
     * معرّفُ الفاتورة الحيّة للمعلم — الحاليّةُ إن كانت حيّة، وإلا أوّلُ سابقةٍ
     * عادت إلى الحياة؛ ولا شيءَ إن ماتت كلُّها. إليها يُردّ من يطلب سكّاً مكرّراً.
     * `$lock`: قراءةٌ قافلةٌ لقرار حارسٍ داخل معاملة (انظر `Quote::liveMilestoneInvoicedTotal`).
     */
    public function liveInvoiceId(bool $lock = false): ?string
    {
        $ids = $this->invoiceIds();
        if (! $ids) return null;
        $dead = (array) config('hub.fin.dead', []);
        $live = FinDocument::query()->whereIn('id', $ids)
            ->when($dead, fn ($q) => $q->where(fn ($w) => $w->whereNull('state')->orWhereNotIn('state', $dead)))
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->pluck('id')->flip()->all();
        foreach ($ids as $id) {
            if (isset($live[$id])) return $id;
        }

        return null;
    }
}
