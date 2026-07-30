<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** مسار عمل بلا كود */
class Flow extends Model
{
    use HasUuid;

    protected $table = 'flows';
    protected $guarded = ['id'];

    protected $casts = [
        'actions' => 'array',
        'enabled' => 'boolean',
        'last_run_at' => 'datetime',
    ];
}
