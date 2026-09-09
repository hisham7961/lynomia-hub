<?php

namespace App\Models;

use App\Support\Collaboration;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * **المحفوظات الشخصيّة** (مركز التواصل · §27) — «حفظٌ لوقتٍ لاحق».
 *
 * مؤشّرٌ خاصٌّ بمستخدمٍ لرسالةٍ أُتيح له الاطّلاعُ عليها (comment أو dm) + ملاحظةٌ
 * وتذكيرٌ اختياريّان. **مرجعٌ لا نسخُ محتوى** (§27): إن صار المصدرُ غيرَ متاحٍ لاحقاً
 * فالمحفوظةُ لا تلتفّ على التخويل — القارئُ يُعاد تخويلُه عند الفتح كأيّ رسالة.
 * **يختلف عن التثبيت (Pin §28):** المحفوظةُ شخصيّةٌ، والتثبيتُ عامٌّ للمحادثة.
 */
class SavedMessage extends Model
{
    use HasUuid;

    protected $table = 'saved_messages';

    protected $guarded = ['id'];

    protected $casts = [
        'remind_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $m): void {
            if (! in_array((string) $m->target_type, Collaboration::SAVED_TYPES, true)) {
                throw new \InvalidArgumentException("نوعُ رسالةٍ محفوظةٍ غيرُ صالح: {$m->target_type}");
            }
            $m->note = filled($m->note) ? mb_substr(trim((string) $m->note), 0, 500) : null;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
