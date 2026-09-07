<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

/**
 * **صنفُ الجرد المجمَّد** — أصلٌ في لقطةِ جلسة (Work OS · الطور F · WP-F.3 · §32).
 *
 * `snapshot` صورةٌ **ثابتةٌ** لموضعِ الأصلِ وهويّته لحظةَ التجميد — لا تتبدّل بتغيّرِ
 * الأصلِ بعدها؛ و`verdict` نتيجةُ المصالحة (معلّق أولاً ثم أحدُ الأربعة).
 */
class InventoryItem extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'inventory_items';

    protected $guarded = ['id'];

    protected $casts = [
        'snapshot' => 'array',
    ];

    /* دورةُ حكمِ الصنف: معلّقٌ قبل المصالحة، ثم أحدُ الأربعة */
    public const PENDING    = 'معلّق';      // مجمَّدٌ لم يُصالَح بعد
    public const PRESENT    = 'موجود';      // مُسِح في موضعه المجمَّد
    public const MISSING    = 'مفقود';      // لم يُمسح
    public const MOVED      = 'انتقل';      // مُسِح لكن موضعَه الحاليَّ يخالف المجمَّد
    public const UNEXPECTED = 'غير متوقع';  // مُسِح وليس في اللقطة (أُنشئ بعد التجميد)

    /** أحكامُ الصنف — allowlist مفروضٌ في `saving` (لا DB enum · C10) */
    public const VERDICTS = [self::PENDING, self::PRESENT, self::MISSING, self::MOVED, self::UNEXPECTED];

    protected static function booted(): void
    {
        static::saving(function (self $r) {
            if (! in_array((string) $r->verdict, self::VERDICTS, true)) {
                throw new \InvalidArgumentException('حكمُ جردٍ غيرُ معروف: ' . $r->verdict);
            }
        });
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(InventorySession::class, 'session_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }
}
