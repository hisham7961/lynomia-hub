<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** كاشُ الاستكشاف الخارجي: باركود سُئل عنه المزوّدون مرةً لا يُسألون عنه كل مسحة */
class IdentityLookup extends Model
{
    use HasUuid;

    protected $table = 'identity_lookups';
    protected $guarded = ['id'];
    protected $casts = ['result' => 'array', 'providers' => 'array', 'checked_at' => 'datetime'];
}
