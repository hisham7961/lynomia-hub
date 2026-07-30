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
}
