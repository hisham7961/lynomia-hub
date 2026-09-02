<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use App\Traits\HasVersions;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** المحاسبة والفواتير */
class FinDocument extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'fin_documents';
    public const MODULE = 'fin';
    public const DISPLAY = 'doc_no';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'date' => 'date',
        'due' => 'date',
        'amount' => 'decimal:3',
        'tax' => 'decimal:3',
        'total' => 'decimal:3',
        'paid' => 'decimal:3',
        'tags' => 'array',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    /**
     * مستندٌ مدفوعٌ لا يُحذف — كان الحذف يترك رصيدَ بنكٍ تحرّك بالدفعة يتيماً
     * (لا مستندَ ظاهرٌ يوازيه، فالمبيعاتُ تسقط والدفترُ يبقى) وقيداً مرحّلاً
     * مقفولاً لا يُصحَّح. يُعكَس بإلغاء الدفعة/القيد لا بالحذف. الحذفُ الصلب
     * (صيانة/نسخ) يمرّ — الحارسُ على النقل للسلة فقط.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $doc) {
            if (method_exists($doc, 'isForceDeleting') && $doc->isForceDeleting()) return;
            $paid = (float) ($doc->paid ?? 0);
            $isPaid = $paid > 0 || in_array((string) $doc->state, ['مدفوعة', 'مدفوعة جزئياً'], true);
            if ($isPaid) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'doc' => 'مستندٌ مدفوعٌ لا يُحذف — اعكس الدفعة أولاً كي لا يبقى رصيدُ بنكٍ يتيمٌ خلفه',
                ]);
            }
        });
    }

    /**
     * رقمُ مستندٍ دوريٍّ فريدٌ متسلسل — بديلٌ للاحقةٍ عشوائية (`Str::random(4)`)
     * كانت تتصادم بحسبة عيد الميلاد عبر مئات المستندات. تسلسلٌ شهريٌّ مع فحصِ
     * وجودٍ يضمن عدمَ التكرار. آمنٌ للتزامن هنا: `hub:automation` غيرُ متداخلٍ
     * مع نفسه (`->withoutOverlapping()`)، فلا سباقَ بين تشغيلين.
     */
    public static function nextRecurringNo(): string
    {
        $prefix = 'REC-' . now()->format('ym') . '-';
        $last = static::withTrashed()->where('doc_no', 'like', $prefix . '%')
            ->orderByDesc('doc_no')->value('doc_no');
        $n = $last ? ((int) preg_replace('/\D/', '', substr((string) $last, strlen($prefix))) + 1) : 1;
        do {
            $candidate = $prefix . sprintf('%04d', $n);
            $n++;
        } while (static::withTrashed()->where('doc_no', $candidate)->exists());

        return $candidate;
    }

    /**
     * رقمُ مستندٍ فريدٌ مقترَح — يُستعمل حين يُترك الحقلُ فارغاً (الترقيمُ اليدويُّ
     * يبقى مسموحاً). تسلسلٌ سنويٌّ `INV-{YEAR}-{NNNN}` فوق أعلى تسلسلٍ مستعمَل.
     */
    public static function nextDocNo(): string
    {
        $year = now()->format('Y');
        $prefix = 'INV-' . $year . '-';
        $last = static::withTrashed()->where('doc_no', 'like', $prefix . '%')
            ->orderByDesc('doc_no')->value('doc_no');
        $n = $last ? ((int) preg_replace('/\D/', '', substr((string) $last, strlen($prefix))) + 1) : 1;
        do {
            $candidate = $prefix . sprintf('%04d', $n);
            $n++;
        } while (static::withTrashed()->where('doc_no', $candidate)->exists());

        return $candidate;
    }

    /**
     * حفظٌ يلتقط تصادمَ الفهرس الفريد على `doc_no` (سباقُ كتابةِ رقمين متطابقين)
     * فيولّد بديلاً بدل أن يسقط الطلبُ بـ٥٠٠ — نمطُ Quote/Contract نفسُه. لا يُطبَّق
     * إلا على الإدراج (`! $this->exists`): تحديثُ رقمٍ قائمٍ يبقى بيدِ المستخدم.
     *
     * والإحياءُ (v2.399.1) — مستندٌ ميتٌ (ملغاة/مسودة) يُرجَع إلى حالةٍ حيّة، أو
     * محذوفٌ بنعومة يُستعاد بحالةٍ حيّة — يمرّ بحارس ازدواج فوترة العرض قبل الكتابة،
     * في معاملةٍ على صفّ العرض المقفول (القفلُ خارج معاملةٍ لا أثرَ له). هنا لا في
     * المتحكّم: فأبوابُ الإحياء كثيرة (نموذجُ التعديل، سحبُ الكانبان، الإجراءُ
     * الجماعيّ، الاستعادةُ من السلّة، استعادةُ نسخة، تنفيذُ موافقة) والحارسُ الذي
     * يُطبَّق في بابٍ ويُنسى في آخر ليس حارساً. والبابُ الثالثُ إلى الثابت نفسِه:
     * **رفعُ قيمة** فاتورةِ دفعةٍ حيّةٍ فوق المتبقّي من إجماليّ العرض — يمرّ بالسقف نفسِه.
     */
    public function save(array $options = []): bool
    {
        $reviving = $this->isReviving();
        if ($reviving || $this->isRaisingLiveMilestoneTotal()) {
            return \Illuminate\Support\Facades\DB::transaction(function () use ($options, $reviving) {
                $this->guardQuoteBilling($reviving);

                return $this->saveRetryingDocNo($options);
            });
        }

        return $this->saveRetryingDocNo($options);
    }

    protected function saveRetryingDocNo(array $options): bool
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

    /** حالةٌ ميتة؟ — التعريفُ الواحد: `config('hub.fin.dead')` (ملغاة/مسودة)؛ والفراغُ حيّ */
    public static function isDeadState(mixed $state): bool
    {
        return $state !== null && $state !== ''
            && in_array((string) $state, (array) config('hub.fin.dead', []), true);
    }

    /**
     * هل هذه الكتابةُ إحياء؟ — مستندٌ قائمٌ سيكون بعدها حيّاً (لا محذوفَ ولا حالةَ
     * ميتة) وكان قبلها ميتاً أو في السلّة. الإدراجُ ليس إحياءً (حرّاسُ السكّ في
     * المتحكّم تحكمه)، والحفظُ في السلّة ليس إحياءً، والميتُ يُستعاد ميتاً بلا حارس.
     */
    protected function isReviving(): bool
    {
        if (! $this->exists || $this->{$this->getDeletedAtColumn()} !== null || self::isDeadState($this->state)) {
            return false;
        }

        return $this->getRawOriginal($this->getDeletedAtColumn()) !== null
            || self::isDeadState($this->getRawOriginal('state'));
    }

    /**
     * هل هذه الكتابةُ رفعٌ لقيمة فاتورةِ دفعةٍ حيّة؟ — حيّةٌ قبلُ وبعدُ (لا سلّةَ ولا
     * حالةَ ميتة)، موسومةٌ بمعلم، و`total` الجديدُ أكبرُ من المخزون. الخفضُ حرّ.
     */
    protected function isRaisingLiveMilestoneTotal(): bool
    {
        if (! $this->exists || ! $this->isDirty('total') || empty(((array) $this->meta)['milestone_id'])) {
            return false;
        }
        if ($this->{$this->getDeletedAtColumn()} !== null || $this->getRawOriginal($this->getDeletedAtColumn()) !== null) {
            return false;
        }
        if (self::isDeadState($this->state) || self::isDeadState($this->getRawOriginal('state'))) {
            return false;
        }

        return (float) $this->total > (float) $this->getRawOriginal('total');
    }

    /**
     * حارسُ فوترة العرض على بابِ العودة والرفع — مرآةُ حرّاس السكّ في
     * `QuoteController::msInvoice/toInvoice`:
     *
     *   · فاتورةُ دفعةٍ (`meta.milestone_id`): لا تُحيا وللمعلم فاتورةٌ حيّةٌ أخرى
     *     (الحاليّةُ أو سابقةٌ عادت)، ولا فوق فاتورةٍ كاملةٍ حيّةٍ للعرض، ولا إن رفعت —
     *     إحياءً أو رفعَ قيمةٍ — مجموعَ الدفعات الحيّة فوق إجماليّ العرض.
     *   · الفاتورةُ الكاملةُ للعرض (`quotes.meta.invoice_id` يشير إليها): لا تُحيا
     *     وللعرض فواتيرُ دفعاتٍ حيّة.
     *
     * الرفضُ `ValidationException` كحارس الحذف: نموذجُ التعديل يعرضه، والكانبان
     * يُظهره نصّاً (٤٢٢)، والإجراءُ الجماعيّ ينسبه إلى سجلّه، والاستعادةُ تُرجعه.
     *
     * الأقفالُ: العرضُ ثم المعلمُ — ترتيبُ `msInvoice` نفسُه فلا دورةَ تعطّل؛ ومعرّفُ
     * العرض يُستخرج بلا قفلٍ (من `meta.quote_id`، وإلا من صفّ المعلم — لا يتغيّر)، ثم
     * يُقفل بمفتاحه لا بمسار JSON (`FOR UPDATE` على مسارٍ بلا فهرسٍ يقفل الجدولَ كلَّه).
     * و**قراءاتُ القرار كلُّها قافلة** (`$lock = true`): على InnoDB أوّلُ قراءةٍ عاديّةٍ في
     * المعاملة تثبّت صورتَها، فسكٌّ أُودع بينما الإحياءُ ينتظر قفلَ العرض يبقى غيرَ
     * مرئيٍّ لقراءةٍ عاديّةٍ ولو جاءت بعد القفل — والقافلةُ ترى الأحدثَ المودَع.
     * والعرضُ يُقرأ `withTrashed` كالمعلم: عرضٌ في السلّة فواتيرُه مطالبةٌ يعدّها القرّاء
     * (`DB::table`) وحارسُ السكّ مغلقٌ عليه (٤٠٤)، فلا يبقى الإحياءُ بابَه الوحيد.
     * وما لا يُتحقَّق منه (معلمٌ موسومٌ لا يُعثر له على معلمٍ ولا عرض) يُرفض لا يُمرَّر بصمت.
     */
    protected function guardQuoteBilling(bool $reviving): void
    {
        $meta = (array) $this->meta;
        $msId = is_scalar($meta['milestone_id'] ?? null) ? (string) $meta['milestone_id'] : '';
        $me = (string) $this->getKey();

        if ($msId !== '') {
            $quoteId = is_scalar($meta['quote_id'] ?? null) && $meta['quote_id'] !== ''
                ? (string) $meta['quote_id']
                : (string) (QuoteMilestone::withTrashed()->whereKey($msId)->value('quote_id') ?? '');
            $quote = $quoteId !== '' ? Quote::withTrashed()->whereKey($quoteId)->lockForUpdate()->first() : null;
            $ms = QuoteMilestone::withTrashed()->whereKey($msId)->lockForUpdate()->first();
            if (! $quote && ! $ms) {
                self::refuseRevive('تعذّر التحقّق من فوترة العرض لهذه الفاتورة (لا معلمَ ولا عرضَ يُعثر عليهما) — راجع المستندَ قبل إحيائه', $reviving);
            }

            if ($reviving && $ms && ($liveId = $ms->liveInvoiceId(true)) && $liveId !== $me) {
                $liveNo = (string) (self::query()->whereKey($liveId)->value('doc_no') ?? $liveId);
                self::refuseRevive('للمعلم «' . $ms->title . '» فاتورةٌ حيّةٌ أخرى (' . $liveNo . ') — أَلغِها أولاً إن كان القصدُ إحياءَ هذه، فلا تُطالَب دفعةٌ مرّتين', $reviving);
            }
            if ($quote) {
                if ($reviving && $quote->hasLiveFullInvoice(true)) {
                    self::refuseRevive('للعرض ' . $quote->doc_no . ' فاتورةٌ كاملةٌ حيّة — لا تُحيا فاتورةُ دفعةٍ فوقها (أَلغِ الكاملةَ أولاً إن كان القصدُ الفوترةَ بالدفعات)', $reviving);
                }
                // المجموعُ الحيُّ بلا هذه الفاتورة: الميتةُ ليست فيه أصلاً، والحيّةُ التي تُرفع قيمتُها تُطرح بقيمتها المخزونة
                $billed = $quote->liveMilestoneInvoicedTotal(true) - ($reviving ? 0.0 : (float) $this->getRawOriginal('total'));
                if (round($billed + (float) $this->total, 3) > round((float) $quote->total, 3)) {
                    self::refuseRevive(($reviving ? 'إحياءُ هذه الفاتورة يرفع' : 'رفعُ قيمة هذه الفاتورة يرفع') . ' فواتيرَ الدفعات الحيّةَ على العرض ' . $quote->doc_no
                        . ' إلى ' . number_format($billed + (float) $this->total, 3) . ' فوق إجماليّ العرض (' . number_format((float) $quote->total, 3) . ') — '
                        . ($reviving ? 'أَلغِ إحداها أولاً' : 'المتبقّي ' . number_format(max(0, (float) $quote->total - $billed), 3)), $reviving);
                }
            }

            return;
        }
        if (! $reviving) {
            return;
        }

        // الفاتورةُ الكاملةُ لعرضٍ: مسارُ JSON على قيمةٍ حرفيّة يعمل على المحرّكين (`json_extract`)
        // — بلا قفلٍ لتحديد العرض، ثم قفلٌ بمفتاحه
        $quoteId = Quote::withTrashed()->where('meta->invoice_id', $me)->orderBy('id')->value('id');
        $quote = $quoteId ? Quote::withTrashed()->whereKey($quoteId)->lockForUpdate()->first() : null;
        if ($quote && $quote->hasLiveMilestoneInvoice(true)) {
            self::refuseRevive('للعرض ' . $quote->doc_no . ' فواتيرُ دفعاتٍ حيّة — لا تُحيا الفاتورةُ الكاملةُ فوقها (أَلغِها أولاً إن كان القصدُ الفوترةَ الكاملة)', $reviving);
        }
    }

    protected static function refuseRevive(string $why, bool $reviving = true): never
    {
        throw \Illuminate\Validation\ValidationException::withMessages([$reviving ? 'state' : 'total' => $why]);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Company::class, 'company_id');
    }

    public function cc(): BelongsTo
    {
        return $this->belongsTo(\App\Models\CostCenter::class, 'cc_id');
    }

    /**
     * هل هذا المستندُ (بمعرّفه) حيٌّ؟ — موجودٌ، لا محذوفٌ بنعومة، وليس في حالةٍ ميتة
     * (`config('hub.fin.dead')`: ملغاة/مسودة). تعريفٌ واحدٌ يُشارَك بين فاتورة العرض
     * الكاملة وفاتورة المعلم، فلا يختلف معنى «الحيّة» بين موضعٍ وآخر.
     */
    public static function isLive(?string $id, bool $lock = false): bool
    {
        if (! $id) return false;
        $q = fn () => self::query()->whereKey($id)->when($lock, fn ($x) => $x->lockForUpdate());
        $state = $q()->value('state');
        if ($state === null && ! $q()->exists()) return false;

        return $state === null || ! in_array((string) $state, (array) config('hub.fin.dead', []), true);
    }

    /**
     * هل بين هذه المعرّفات مستندٌ حيٌّ واحدٌ على الأقلّ؟ — التعريفُ نفسُه في
     * `isLive` لكن باستعلامٍ واحدٍ لمجموعة (فواتيرُ معالم العرض مثلاً).
     */
    public static function anyLive(array $ids, bool $lock = false): bool
    {
        $ids = array_values(array_filter(array_unique(array_map('strval', $ids))));
        if (! $ids) return false;
        $dead = (array) config('hub.fin.dead', []);

        return self::query()->whereIn('id', $ids)
            ->when($dead, fn ($q) => $q->where(fn ($w) => $w->whereNull('state')->orWhereNotIn('state', $dead)))
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->exists();
    }
}
