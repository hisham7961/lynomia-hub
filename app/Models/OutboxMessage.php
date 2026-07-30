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
}
