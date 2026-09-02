<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class OutboxMessage extends Model
{
    use HasUuid;

    protected $table = 'outbox';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['created_at' => 'datetime', 'delivered_at' => 'datetime'];

    /** معرّفُ الطلب الذي صفّ الرسالة — يُملأ آلياً من كل موضع إنشاءٍ بلا لمسه */
    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if ($m->request_id === null && hub_has_col('outbox', 'request_id')) $m->request_id = \App\Support\Api::requestId();
        });
    }
}
