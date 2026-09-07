<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * حركةُ العهدة المالية للموظف (Work OS · الطور E · WP-E.1 · §18–20).
 *
 * سطرٌ ثابتٌ في دفترِ العهدة — يُضاف ولا يُعدَّل ولا يُحذف. رصيدُ المحفظة مشتقٌّ
 * (`Employee::custody_balance` = SUM(sign × amount))، فلا عمودَ رصيدٍ يُحرَّر ولا
 * قيمةَ تُزوَّر — على نمطِ `StockMove`/`JournalEntry` (رصيدٌ مشتقٌّ + قيدٌ ثابتٌ
 * يُعكس لا يُعدَّل).
 *
 * **لا دفترَ محاسبيٍّ ثانٍ:** الترحيلُ المحاسبيُّ يمرّ عبر `JournalEntry` الوحيد
 * (خدمةُ الترحيل المشترَكة — WP-E.2)؛ الحركةُ تحمل `entry_id`، والمصدرُ لا يُرحَّل
 * مرّتين (UNIQUE على القاعدة).
 *
 * **allowlist في التطبيق لا DB enum (C10):** `kind`/`approval_state` نصوصٌ واسعةٌ
 * يفرض قيمَها `booted` — إضافةُ نوعٍ سطرٌ هنا لا ALTER على MySQL.
 */
class EmployeeCustodyMove extends Model
{
    use HasUuid, Auditable;

    protected $table = 'employee_custody_moves';

    public const MODULE = 'custody';
    public const DISPLAY = 'kind';

    /**
     * أنواعُ الحركة العشرة — «enum التطبيق» (لا DB enum · C10). أطولُها
     * `transfer_out` (١٢ حرفاً) يسعُه عرضُ العمود (٢٠)، يحرسه `ColumnFitsItsWriterTest`.
     *
     *  advance      سلفة                         · charge       شحنُ عهدةٍ مموَّلٌ من بنك
     *  expense      مصروفٌ باعتمادٍ وإيصال        · repayment    سدادٌ من الموظف
     *  transfer_in  تحويلٌ وارد                   · transfer_out تحويلٌ صادر
     *  deduction    خصمٌ يصالح راتباً (advance)   · settlement   تسويةٌ ختامية
     *  correction   تصحيحٌ (step-up)              · reversal     عكسٌ (step-up)
     */
    public const KINDS = [
        'advance', 'charge', 'expense', 'repayment', 'transfer_in',
        'transfer_out', 'deduction', 'settlement', 'correction', 'reversal',
    ];

    /** حالةُ الاعتماد — allowlist في التطبيق (لا DB enum · C10) */
    public const APPROVAL_STATES = ['pending', 'approved', 'rejected'];

    protected $guarded = ['id'];

    /** الافتراضاتُ على النموذج نفسِه — لا تُترك للقاعدة وحدَها */
    protected $attributes = [
        'sign' => 1,
        'amount' => 0,
        'approval_state' => 'pending',
    ];

    protected $casts = [
        'amount' => 'decimal:3',
        'sign' => 'integer',
        'at' => 'datetime',
        'posted_at' => 'datetime',
        'meta' => 'array',
    ];

    /**
     * رايةُ الترحيل الداخليّ — تُرفَع حول كتلةِ العكس وحدَها (نمطُ `StockMove::$posting`
     * و`JournalEntry::$posting`)، فيُسمح بتعليمِ الأصلِ `reversed_at` رغم قفلِ التعديل.
     * لا يرفعها مسارُ مستخدمٍ أبداً.
     */
    public static bool $posting = false;

    protected static function booted(): void
    {
        // «enum التطبيق» (C10): allowlist + قصٌّ دفاعيٌّ عند الكاتب لعرض العمود
        // (درسُ notifications_hub — لا بترَ بايتات بل mb_substr على المحارف).
        static::saving(function (self $m): void {
            if ($m->kind !== null && ! in_array($m->kind, self::KINDS, true)) {
                throw ValidationException::withMessages(
                    ['kind' => 'نوعُ حركةِ عهدةٍ غيرُ صالح: ' . $m->kind]);
            }
            if (! in_array((int) $m->sign, [1, -1], true)) {
                throw ValidationException::withMessages(
                    ['sign' => 'إشارةُ الحركة تكون +1 أو -1 لا غير']);
            }
            if ($m->amount !== null && (float) $m->amount < 0) {
                throw ValidationException::withMessages(
                    ['amount' => 'المبلغُ مقدارٌ موجب — الاتجاهُ في الإشارة لا في المبلغ']);
            }
            if ($m->approval_state !== null && ! in_array($m->approval_state, self::APPROVAL_STATES, true)) {
                throw ValidationException::withMessages(
                    ['approval_state' => 'حالةُ اعتمادٍ غيرُ صالحة: ' . $m->approval_state]);
            }
            if (is_string($m->source_module))  $m->source_module  = mb_substr($m->source_module, 0, 60);
            if (is_string($m->receipt_module)) $m->receipt_module = mb_substr($m->receipt_module, 0, 60);
        });

        // القيدُ الثابت (نمط `JournalEntry`/`StockMove`): الحركةُ المُرحَّلة مقفلةٌ —
        // لا تُعدَّل من أيّ منفذ، بل تُصحَّح بحركةِ عكس. رايةُ العكس الداخليّة وحدَها
        // تعلّم الأصلَ `reversed_at`.
        static::updating(function (self $m): void {
            if (static::$posting) return;
            if ($m->getOriginal('posted_at') !== null) {
                throw ValidationException::withMessages(['posted_at' =>
                    'الحركةُ المُرحَّلة مقفلة — تُصحَّح بحركةِ عكسٍ لا بتعديلها']);
            }
        });

        // ولا حذفَ فيزيائيٍّ لأثرٍ ماليٍّ مُرحَّل — العكسُ بديلُ الحذف (نمط `StockMove`).
        // لا استثناءَ للراية هنا: لا نحذف مُرحَّلاً حتى داخليّاً؛ العكسُ يُضيف لا يمحو.
        static::deleting(function (self $m): void {
            if ($m->getOriginal('posted_at') !== null) {
                throw ValidationException::withMessages(['posted_at' =>
                    'الحركةُ المُرحَّلة لا تُحذف — تُعكس بحركةٍ معاكسة، ولا تُمحى من الدفتر']);
            }
        });
    }

    /** المبلغُ بإشارته — لبنةُ الرصيد المشتقّ */
    public function signedAmount(): float
    {
        return round((int) $this->sign * (float) $this->amount, 3);
    }

    /**
     * عكسُ الحركة: حركةٌ معاكسةُ الإشارة تشير إلى الأصل بـ`reverses_id`، فيتصافَى
     * الزوجُ في الرصيد إلى صفرٍ بلا مسحِ الأثر. لا حذفَ ولا تعديلَ للأصل — فقط
     * تعليمُه `reversed_at`. والعكسُ المزدوجُ للحركةِ نفسِها محجوب، والنقرتان
     * المتزامنتان مُسلسَلتان بـ`lockForUpdate` (فلا عكسان لأصلٍ واحد).
     *
     * (`hub_require_stepup` يُفرض في المتحكّم — WP-E.3؛ وقيدُ العكس المحاسبيُّ
     * المعاكسُ يبنيه `CustodyPostingService::reverse` ويمرّره هنا عبر `$entryId`
     * فتُنشأ حركةُ العكس مرحَّلةً بقيدها في كتابةٍ واحدةٍ لا تصطدم بقفلِ التعديل.)
     *
     * @param  string|null  $entryId  معرّفُ قيدِ العكس المحاسبيّ إن رُحِّل (وإلا null:
     *                                سطرُ الدفتر المعاكسُ فقط — نمطُ اختبار الدفتر E.1).
     */
    public function reverse(?string $reason = null, ?string $entryId = null): self
    {
        if ($this->posted_at === null) {
            throw ValidationException::withMessages(['posted_at' => 'لا يُعكس إلا ما رُحِّل']);
        }
        if ($this->reverses_id !== null) {
            throw ValidationException::withMessages(['reverses_id' =>
                'حركةُ العكس لا تُعكس — أنشئ حركةً صحيحة بدلاً منها']);
        }

        return DB::transaction(function () use ($reason, $entryId) {
            // قفلُ الأصل وإعادةُ الفحص تحته: نقرتان متزامنتان لا تُنتجان عكسين
            $orig = static::whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            if (static::where('reverses_id', $orig->getKey())->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['reverses_id' =>
                    'الحركةُ عُكست سلفاً — العكسُ المزدوجُ للحركةِ نفسِها محجوب']);
            }

            static::$posting = true;
            try {
                $meta = (array) ($orig->meta ?? []);
                $meta['reversed_at'] = now()->toIso8601String();
                if (is_string($reason) && $reason !== '') {
                    $meta['reversed_reason'] = mb_substr($reason, 0, 300);
                }
                $orig->meta = $meta;
                $orig->save();

                $rev = new static();
                $rev->employee_id    = $orig->employee_id;
                $rev->company_id     = $orig->company_id;
                $rev->kind           = 'reversal';
                $rev->amount         = $orig->amount;
                $rev->sign           = -1 * (int) $orig->sign;
                $rev->reverses_id    = $orig->getKey();
                $rev->entry_id       = $entryId;   // قيدُ العكس المحاسبيُّ المعاكس (إن رُحِّل)
                $rev->project_id     = $orig->project_id;
                $rev->cc_id          = $orig->cc_id;
                $rev->approval_state = 'approved';
                $rev->at             = now();
                $rev->posted_at      = now();
                $rev->by_id          = auth()->id();
                $rev->meta           = ['reverses_kind' => $orig->kind, 'reverses_id' => $orig->getKey()];
                $rev->save();
            } finally {
                static::$posting = false;
            }

            return $rev;
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'employee_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(\App\Models\JournalEntry::class, 'entry_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function by(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'by_id');
    }
}
