<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * قيدُ إسنادٍ على محطة: إسنادٌ (assign) أو إخلاء (vacate)، بتاريخه ومن نفّذه
 * (Work OS · الطور F · WP-F.1 · §26).
 *
 * `stations.current_employee_id` يقول من يجلس **الآن** وحسب؛ فإذا انتقل المقعدُ
 * ضاع من جلس قبله ومتى أُخلي. هذا الجدولُ الأثر: صفٌّ لكلِّ حركة، لا يُعاد كتابتُه،
 * ويبقى بعد مغادرة الموظف — نظيرُ `AssetCustody` للأصول.
 */
class StationAssignment extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'station_assignments';

    protected $guarded = ['id'];

    protected $casts = [
        'at' => 'datetime',
    ];

    /** حركتا الإسناد — allowlist مفروضٌ في `saving` (لا DB enum · C10) */
    public const ACTIONS = ['assign', 'vacate'];

    protected static function booted(): void
    {
        static::saving(function (self $r) {
            // قيمةٌ خارج القائمة لا تُكتَب صامتةً فتفسد تصنيفَ التاريخ
            if (! in_array((string) $r->action, self::ACTIONS, true)) {
                throw new \InvalidArgumentException('حركةُ إسنادٍ غيرُ معروفة: ' . $r->action);
            }
        });
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'station_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
