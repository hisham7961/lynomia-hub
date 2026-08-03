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

/** حركات المخزون */
class StockMove extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'stock_moves';
    public const MODULE = 'stockmv';
    public const DISPLAY = 'doc_no';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'qty' => 'decimal:3',
        'date' => 'date',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    /** يرفعه محرك الترحيل وحده — التأكيد من غير بوابته لا يحرّك رصيداً فيُمنع */
    public static bool $posting = false;

    protected static function booted(): void
    {
        static::updating(function (self $m) {
            if (static::$posting) return;
            $meta = (array) ($m->meta ?? []);
            // «مؤكدة» تعني رصيداً تحرّك — بلوغها من النموذج مباشرةً كذبة صامتة
            if ($m->isDirty('status') && $m->status === 'مؤكدة' && empty($meta['posted_at'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(
                    ['status' => 'أكّد الحركة من زر «ترحيل الحركة» في صفحتها — التأكيد اليدوي لا يحرّك المخزون']);
            }
            // الحركة المرحَّلة لا تُعدَّل كميتها ولا نوعها — تُلغى فتُعكس ثم تُنشأ غيرها
            if (! empty($meta['posted_at']) && $m->isDirty(['qty', 'kind', 'item_id', 'from_wh', 'to_wh'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(
                    ['qty' => 'الحركة المُرحَّلة مقفلة — ألغِها لعكس أثرها ثم أنشئ حركة صحيحة']);
            }
        });
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Models\StockItem::class, 'item_id');
    }

    public function by(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'by_id');
    }
}
