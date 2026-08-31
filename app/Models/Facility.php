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

/**
 * المنشأة الصحية — مستشفى أو عيادة أو صيدلية بموقعها الجغرافي.
 *
 * أولُ سجلٍّ في المشروع يحمل إحداثياتٍ حقيقية: نقطةُ المركز ونطاقُ
 * «الوصول» بالمتر — فزيارةُ الميدان تُنسب لمكانٍ لا لعنوانٍ نصيّ.
 */
class Facility extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'facilities';
    public const MODULE = 'facilities';
    public const DISPLAY = 'name';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'tags' => 'array',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'territory_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * هل النقطة داخل نطاق المنشأة؟ Haversine على نصف قطر الأرض بالمتر.
     * منشأةٌ بلا إحداثيات أو بلا نطاق لا تحكم على أحد — `null` لا «خارج».
     */
    public function within(float $lat, float $lng): ?bool
    {
        if ($this->lat === null || $this->lng === null || ! $this->radius_m) return null;

        return self::distanceM((float) $this->lat, (float) $this->lng, $lat, $lng) <= (float) $this->radius_m;
    }

    /** المسافة بين نقطتين بالمتر — أول دالة مسافةٍ في المشروع، فلتكن هنا وحدها */
    public static function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
