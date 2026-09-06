<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * حاويةُ المحادثة (Work OS · الطور A · WP-A.4 · SF-3).
 *
 * الطبقةُ المطبَّعة التي يجتمع تحتها الحديثُ كلُّه — خلاصةُ الفريق وخيوطُ الرسائل
 * المباشرة والقنواتُ وخيطُ سجلٍّ بعينه — كلٌّ منها محادثةٌ لها جمهورٌ ونطاقٌ وأعضاء.
 *
 * **لا محرّكَ رسائلَ ثانٍ:** النصوصُ تبقى في `comments` (و`dm_messages`) وتُنسَب
 * إلى الحاوية بـ`conversation_id`؛ `messages()` هنا تقرأ من `comments` لا من جدولٍ
 * جديد. الحاويةُ تحمل *مَن يرى ومَن عضو* لا نصَّ الرسالة.
 *
 * **allowlist في التطبيق لا DB enum (C10):** `kind`/`audience`/`visibility`
 * نصوصٌ واسعةٌ يفرضها `booted` — إضافةُ قيمةٍ جديدةٍ سطرٌ هنا لا ALTER على MySQL.
 * والجمهورُ `internal` افتراضاً (SF-4): داخليٌّ حتى يُشرَّع صراحةً.
 */
class Conversation extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'conversations';

    public const MODULE = 'conversations';

    /** نوعُ الحاوية — المسموحُ يُفرَض في `saving` (لا DB enum · درسُ C10) */
    public const KINDS = ['feed', 'dm', 'channel', 'record'];

    /** الجمهور (SF-4) — داخليٌّ افتراضاً؛ العميلُ لا يرى إلا `client`/`both` */
    public const AUDIENCES = ['internal', 'client', 'both'];

    /** مدى الظهور — allowlist في التطبيق (لا DB enum · C10) */
    public const VISIBILITIES = ['private', 'members', 'company', 'public'];

    protected $guarded = ['id'];

    /** الافتراضات على النموذج نفسه — لا تُترك للقاعدة وحدَها */
    protected $attributes = [
        'audience' => 'internal',
        'visibility' => 'private',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // حارسُ القيَم — «enum التطبيق» البديلُ عن DB enum (C10): يرفض أيَّ قيمةٍ
        // خارج allowlist قبل أيّ كتابة، فتبقى الإضافةُ المستقبليّةُ سطراً لا ALTER.
        static::saving(function (self $m): void {
            if ($m->kind !== null && ! in_array($m->kind, self::KINDS, true)) {
                throw new \InvalidArgumentException("نوعُ محادثةٍ غيرُ صالح: {$m->kind}");
            }
            if ($m->audience !== null && ! in_array($m->audience, self::AUDIENCES, true)) {
                throw new \InvalidArgumentException("جمهورُ محادثةٍ غيرُ صالح: {$m->audience}");
            }
            if ($m->visibility !== null && ! in_array($m->visibility, self::VISIBILITIES, true)) {
                throw new \InvalidArgumentException("مدى ظهورِ محادثةٍ غيرُ صالح: {$m->visibility}");
            }
        });
    }

    /**
     * رسائلُ الحاوية — من `comments` عبر `conversation_id`، لا جدولَ رسائلَ ثانٍ.
     * ترتيبٌ حتميّ (زمنٌ ثم id) — `created_at` بدقّة الثانية قد يتساوى فيُكسَر بـid
     * (درس CLAUDE.md: الترتيبُ ليس مضموناً إلا بما يُطلَب صراحةً).
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Comment::class, 'conversation_id')
            ->orderBy('created_at')->orderBy('id');
    }

    /** أعضاءُ الحاوية المطبَّعون — دورٌ ومصدرٌ وآخرُ قراءة */
    public function members(): HasMany
    {
        return $this->hasMany(ConversationMember::class, 'conversation_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** غيرُ المؤرشفة — الحاوياتُ الحيّة */
    public function scopeActive($q)
    {
        return $q->whereNull('archived_at');
    }
}
