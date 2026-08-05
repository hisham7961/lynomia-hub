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
