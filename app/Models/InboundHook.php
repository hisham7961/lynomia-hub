<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** نقطةُ استقبال ويبهوك واردة — رابطٌ موقّع يُدخِل بياناتٍ للنظام */
class InboundHook extends Model
{
    use HasUuid;

    protected $table = 'inbound_hooks';
    protected $guarded = ['id'];

    protected $casts = [
        'enabled'     => 'boolean',
        'last_hit_at' => 'datetime',
    ];

    /** رابطُ الاستقبال العام الكامل */
    public function url(): string
    {
        return route('hook.receive', $this->token);
    }
}
