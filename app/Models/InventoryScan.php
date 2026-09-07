<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

/**
 * **مسحةُ جرد** — رمزٌ مُسِح في الجلسة، **مختومٌ بالماسِح** وزمنِه (Work OS · الطور F ·
 * WP-F.3 · §32). أثرٌ لا يُمحى (نمطُ `asset_custody`/`station_assignments`).
 *
 * الرمزُ يُحلّ عبر `Identity::resolve` (المحلِّل الموحّد، بنطاقِ الماسِح): `معروف` (ضمن
 * اللقطة)، `غير متوقع` (في النطاق خارجَ اللقطة)، `غير معروف` (لم يُحَلّ — `asset_id` null،
 * لا تسريبَ هويّةِ أصلٍ أجنبيّ).
 */
class InventoryScan extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'inventory_scans';

    protected $guarded = ['id'];

    protected $casts = [
        'at' => 'datetime',
        'meta' => 'array',
    ];

    public const KNOWN      = 'معروف';      // حُلّ إلى أصلٍ ضمنَ لقطةِ الجلسة
    public const UNEXPECTED  = 'غير متوقع'; // حُلّ إلى أصلٍ في النطاق خارجَ اللقطة
    public const UNKNOWN     = 'غير معروف'; // لم يُحَلّ (خارجَ الشركة/غيرُ موجود) — asset_id null

    /** نتائجُ المسح — allowlist مفروضٌ في `saving` (لا DB enum · C10) */
    public const RESULTS = [self::KNOWN, self::UNEXPECTED, self::UNKNOWN];

    protected static function booted(): void
    {
        static::saving(function (self $r) {
            if (! in_array((string) $r->result, self::RESULTS, true)) {
                throw new \InvalidArgumentException('نتيجةُ مسحٍ غيرُ معروفة: ' . $r->result);
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
