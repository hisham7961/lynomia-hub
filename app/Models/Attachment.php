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

    public function isClean(): bool
    {
        return $this->av_status === 'clean';
    }
}
