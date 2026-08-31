<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * نقطةُ مسارٍ خام — إحداثيةٌ ودقّةٌ ولحظةُ التقاط. لا Auditable ولا SoftDeletes:
 * حجمُها كبير وتُقلَّم بسياسة الاحتفاظ، والتدقيقُ على الجلسة لا على كل نقطة.
 */
class TrackPoint extends Model
{
    use HasUuid;

    protected $table = 'track_points';
    public $timestamps = false;
    protected $guarded = ['id'];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'captured_at' => 'datetime',
        'created_at' => 'datetime',
        'meta' => 'array',
    ];
}
