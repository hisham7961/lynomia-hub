<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** مفتاح API لمستخدم */
class ApiToken extends Model
{
    use HasUuid;

    protected $table = 'api_tokens';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'expires_at'   => 'datetime',
        'last_used_at' => 'datetime',
        'created_at'   => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
