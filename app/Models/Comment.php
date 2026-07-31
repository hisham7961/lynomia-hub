<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** تعليق على سجل أو منشور في قناة الفريق (module=feed) */
class Comment extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'comments';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'pinned'     => 'boolean',
        'internal'   => 'boolean',
        'mentions'   => 'array',
        'read_by'    => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function replies()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }
}
