<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecordVersion extends Model
{
    protected $table = 'record_versions';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['before' => 'array', 'after' => 'array', 'snapshot' => 'array', 'value' => 'array', 'flags' => 'array'];
    
}
