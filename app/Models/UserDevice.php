<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * جهازٌ معروفٌ لمستخدم — هوية ثقةٍ ثابتة عبر كوكي طويل العمر.
 * لا Auditable: التغييرات الأمنية عليه تُدوَّن صراحةً من المتحكّم بأثرٍ واضح.
 */
class UserDevice extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'user_devices';
    protected $guarded = ['id'];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    protected $hidden = ['cookie_hash'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
