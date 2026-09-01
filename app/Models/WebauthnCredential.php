<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** مفتاحُ مرورٍ واحد (WebAuthn) لمستخدم — المفتاحُ العامُّ فقط يُخزَّن. */
class WebauthnCredential extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'webauthn_credentials';
    protected $guarded = ['id'];

    protected $casts = [
        'sign_count' => 'integer',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
