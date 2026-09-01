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
}
