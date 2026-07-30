<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Webhook extends Model
{
    use HasUuid;

    protected $guarded = [];
    protected $casts = ['active' => 'bool', 'last_ok' => 'bool',
                        'paused_until' => 'datetime', 'last_at' => 'datetime'];

    public function deliveries()
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /** هل الاشتراك جاهز للإرسال الآن؟ (مفعّل وغير موقوف مؤقتاً) */
    public function deliverable(): bool
    {
        return $this->active && (! $this->paused_until || $this->paused_until->isPast());
    }

    /** هل يشمل هذا الاشتراك حدثاً مثل projects.created؟ */
    public function wants(string $event): bool
    {
        [$mod, $ev] = explode('.', $event) + [1 => ''];
        foreach (preg_split('/[،,\s]+/u', (string) $this->events, -1, PREG_SPLIT_NO_EMPTY) as $p) {
            if ($p === '*' || $p === $event || $p === $mod . '.*' || $p === '*.' . $ev) return true;
        }

        return false;
    }
}
