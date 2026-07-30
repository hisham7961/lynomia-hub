<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEntry extends Model
{
    protected $table = 'audits';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['before' => 'array', 'after' => 'array', 'snapshot' => 'array', 'value' => 'array', 'flags' => 'array'];
    
}
