<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InboxDocument extends Model
{
    use HasUuid, SoftDeletes;

    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['created_at' => 'datetime', 'classified_at' => 'datetime',
                        'doc_date' => 'date', 'expiry' => 'date'];
}
