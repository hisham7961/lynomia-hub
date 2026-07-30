<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** رابط مشاركة في غرفة البيانات */
class ShareLink extends Model
{
    use HasUuid;

    protected $table = 'share_links';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'no_download' => 'boolean',
        'revoked'     => 'boolean',
        'expires_at'  => 'datetime',
        'created_at'  => 'datetime',
    ];

    public function isDead(): bool
    {
        return $this->revoked || ($this->expires_at && now()->gt($this->expires_at));
    }
}
