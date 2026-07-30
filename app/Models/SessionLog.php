<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class SessionLog extends Model
{
    use HasUuid;

    protected $table = 'sessions_log';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['started_at' => 'datetime', 'last_seen_at' => 'datetime', 'revoked' => 'boolean'];
}
