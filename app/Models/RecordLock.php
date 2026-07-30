<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecordLock extends Model
{
    protected $table = 'record_locks';
    protected $guarded = [];
    public $timestamps = true;
    protected $casts = ['before' => 'array', 'after' => 'array', 'snapshot' => 'array', 'value' => 'array', 'flags' => 'array'];
    
}
