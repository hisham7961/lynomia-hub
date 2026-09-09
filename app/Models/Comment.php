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

    /**
     * §19 **عدّادُ الخيطِ يُصان عند الرد لا بالاستعلام** — نموذجيّاً في مكانٍ واحدٍ
     * يغطّي كلَّ سطحٍ يُنشئ/يحذف رداً (الويب/الجوال/الخدمة): إنشاءُ ردٍّ يرفع
     * `reply_count` ويختم `last_reply_at`، وحذفُه/استعادتُه يُعيد الحساب من الردود
     * الحيّة. تحديثٌ جماعيٌّ على الأبِ (لا يُطلق أحداثاً) فلا تكرارَ ولا لمسَ updated_at.
     */
    protected static function booted(): void
    {
        static::created(function (self $c): void {
            if ($c->parent_id) self::bumpThread((string) $c->parent_id, $c->created_at);
        });
        static::deleted(function (self $c): void {
            if ($c->parent_id) self::recountThread((string) $c->parent_id);
        });
        static::restored(function (self $c): void {
            if ($c->parent_id) self::recountThread((string) $c->parent_id);
        });
    }

    /** رفعُ عدّادِ الخيطِ وختمُ آخرِ ردٍّ — عمودٌ حديث: قبل الهجرة لا يُكتب شيء */
    protected static function bumpThread(string $parentId, $at): void
    {
        if (! hub_has_col('comments', 'reply_count')) return;

        self::whereKey($parentId)->update([
            'reply_count'   => \Illuminate\Support\Facades\DB::raw('reply_count + 1'),
            'last_reply_at' => $at,
        ]);
    }

    /** إعادةُ حساب الخيطِ من الردود الحيّة (بعد حذفٍ/استعادة) — الصدقُ فوق التقريب */
    protected static function recountThread(string $parentId): void
    {
        if (! hub_has_col('comments', 'reply_count')) return;

        $count = self::where('parent_id', $parentId)->count();
        $last  = self::where('parent_id', $parentId)->max('created_at');
        self::whereKey($parentId)->update(['reply_count' => $count, 'last_reply_at' => $last]);
    }

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
