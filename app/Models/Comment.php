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
        'pinned'        => 'boolean',
        'internal'      => 'boolean',
        'mentions'      => 'array',
        'read_by'       => 'array',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
        'edited_at'     => 'datetime',   // §22 ختمُ التحرير الصادق
        'last_reply_at' => 'datetime',   // §19 آخرُ ردٍّ في الخيط
        'pinned_at'     => 'datetime',   // §28 بيانُ التثبيت
        'reply_count'   => 'integer',    // §19 عدّادُ الخيط
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** ردودُ الخيط — مستوىً واحدٌ تحت الجذر (§19)، ترتيبٌ حتميّ (زمن ثم id) */
    public function replies()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at')->orderBy('id');
    }

    /** §19 هل هذا التعليقُ جذرُ خيطٍ له ردود؟ */
    public function isThreadRoot(): bool
    {
        return $this->parent_id === null && (int) $this->reply_count > 0;
    }
}
