<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** خيطُ محادثةٍ في «اسأل Hub» — لصاحبه وحدَه (`App\Support\Ai\Ask\AskMemory`). لا `Auditable` عمداً. */
class AskThread extends Model
{
    use HasUuid;

    protected $table = 'ask_threads';

    protected $guarded = ['id'];

    protected $casts = [
        'title' => 'encrypted',
        'last_at' => 'datetime',
    ];
}
