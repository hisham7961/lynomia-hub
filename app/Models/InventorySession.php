<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * **جلسةُ جرد** — رأسُ عمليّةِ جردٍ مُصادَقة (Work OS · الطور F · WP-F.3 · §32).
 *
 * حاويةٌ يقودها `InventoryController` (freeze/scan/reconcile/close)، **ليست وحدةَ
 * `hub.modules`** (لا CRUD عامٌّ يلتفّ على القفل). داخليّةٌ فقط، معزولةٌ بـ`company_id`.
 *
 * `status` **مقفلٌ** بقيمتين (allowlist في `saving`): تُفتَح عند التجميد، وتُغلَق عبر
 * مسار الإغلاق المُصعَّد وحدَه (`hub_require_stepup`) — لا إغلاقَ بجلسةٍ مسروقة.
 */
class InventorySession extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'inventory_sessions';

    protected $guarded = ['id'];

    protected $casts = [
        'meta' => 'array',
        'closed_at' => 'datetime',
    ];

    public const OPEN = 'مفتوحة';
    public const CLOSED = 'مغلقة';

    /** حالتا الجلسة — allowlist مفروضٌ في `saving` (لا DB enum · C10) */
    public const STATUSES = [self::OPEN, self::CLOSED];

    protected static function booted(): void
    {
        static::saving(function (self $s) {
            // قيمةٌ خارج القائمة لا تُكتَب صامتةً فتفسد تصنيفَ الجلسات
            if (! in_array((string) $s->status, self::STATUSES, true)) {
                throw new \InvalidArgumentException('حالةُ جلسةِ جردٍ غيرُ معروفة: ' . $s->status);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class, 'session_id');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(InventoryScan::class, 'session_id');
    }
}
