<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    use HasUuid;

    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['created_at' => 'datetime', 'delivered_at' => 'datetime', 'next_at' => 'datetime'];

    public function webhook()
    {
        return $this->belongsTo(Webhook::class);
    }

    /** معرّفُ الطلب الذي ولّد الحدث — به يُتتبَّع الويبهوك رجوعاً إلى فعله الأصليّ */
    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if ($m->request_id === null && hub_has_col('webhook_deliveries', 'request_id')) $m->request_id = \App\Support\Api::requestId();
        });
    }
}
