<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attachment extends Model
{
    use HasUuid, SoftDeletes;

    protected $guarded = ['id'];
    protected $casts = ['scanned_at' => 'datetime', 'size' => 'integer', 'version' => 'integer'];

    /** لا تُسمِّها isClean — تتصادم مع Model::isClean($attributes) وتُفجّر التحميل */
    public function avClean(): bool
    {
        return $this->av_status === 'clean';
    }
}
