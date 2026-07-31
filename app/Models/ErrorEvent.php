<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** حدث خطأ مجمّع */
class ErrorEvent extends Model
{
    use HasUuid;

    protected $table = 'error_events';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = ['first_seen' => 'datetime', 'last_seen' => 'datetime', 'meta' => 'array'];
}
