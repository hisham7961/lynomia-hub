<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * حدث في سجل أدلة العقود — append-only: لا تعديل ولا حذف بعد الكتابة،
 * فمنه تُبنى شهادة الإتمام والخط الزمني القانوني.
 */
class ContractEvent extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    protected $table = 'contract_events';
    protected $guarded = ['id'];
    protected $casts = ['created_at' => 'datetime'];

    public static function booted(): void
    {
        static::updating(fn () => false);   // سجل أدلة لا يُمس
        static::deleting(fn () => false);
    }

    /** كتابة حدث — الملتقط الواحد لكل مواضع التسجيل */
    public static function log(string $event, ?SignRequest $req = null, array $extra = []): self
    {
        return self::create($extra + [
            'event' => $event,
            'request_id' => $req?->id,
            'contract_id' => $req?->contract_id,
            'actor_id' => auth()->id(),
            'ip' => request()?->ip(),
            'agent' => substr((string) request()?->userAgent(), 0, 250),
            'created_at' => now(),
        ]);
    }
}
