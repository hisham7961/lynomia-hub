<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * عضويّةُ محادثةٍ مطبَّعة (Work OS · الطور A · WP-A.4 · SF-3).
 *
 * صفٌّ لكلِّ (محادثة، مستخدم) يحمل دورَه في الحاوية ومصدرَ عضويّته وآخرَ قراءته —
 * بديلُ `comments.read_by` (JSON) الخام. عليها تُبنى الرقابةُ والوصولُ في الطور C.
 *
 * **RBAC واحد لا ثانٍ:** الأدوارُ هنا (owner/moderator/member/guest) عضويّةٌ داخل
 * الحاوية لا بديلٌ عن مصفوفةِ `Role` — من *يقدر* يُحكَم بـ`hub_can`، ومن *عضوٌ في
 * هذه المحادثة* يُحكَم هنا. القيمةُ allowlist في التطبيق لا DB enum (درسُ C10).
 *
 * `last_read_at` يكتبه صاحبُه حين يقرأ — ولا يمسّه القارئُ الرقابيّ (§6): الرقابةُ
 * تُدقَّق في `audits` لا بتحريكِ إيصالِ قراءةِ غيرِه.
 */
class ConversationMember extends Model
{
    use HasUuid;

    protected $table = 'conversation_members';

    public const MODULE = 'conversation_members';

    /** دورُ العضو داخل الحاوية — allowlist في التطبيق (لا DB enum · C10) */
    public const ROLES = ['owner', 'moderator', 'member', 'guest'];

    /** كيف اكتُسبت العضويّة — صريحةٌ أو موروثةٌ (من مشروع/عميل) أو من النظام */
    public const SOURCES = ['explicit', 'inherited', 'system'];

    protected $guarded = ['id'];

    /** الافتراضات على النموذج نفسه — لا تُترك للقاعدة وحدَها */
    protected $attributes = [
        'role' => 'member',
        'source' => 'explicit',
    ];

    protected $casts = [
        'last_read_at' => 'datetime',
        'muted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // حارسُ القيَم — «enum التطبيق» (C10): يرفض دوراً أو مصدراً خارج allowlist
        // قبل أيّ كتابة، فالإضافةُ المستقبليّةُ سطرٌ هنا لا ALTER على MySQL.
        static::saving(function (self $m): void {
            if ($m->role !== null && ! in_array($m->role, self::ROLES, true)) {
                throw new \InvalidArgumentException("دورُ عضويةِ محادثةٍ غيرُ صالح: {$m->role}");
            }
            if ($m->source !== null && ! in_array($m->source, self::SOURCES, true)) {
                throw new \InvalidArgumentException("مصدرُ عضويةِ محادثةٍ غيرُ صالح: {$m->source}");
            }
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** العضويّاتُ غيرُ المكتومة */
    public function scopeUnmuted($q)
    {
        return $q->whereNull('muted_at');
    }

    /* ────────── مساعِداتُ الدور (الطور C · WP-C.1) ────────── */

    /** هذا العضوُ مالكُ الحاوية */
    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /** يقدر الكتابةَ في الحاوية — الضيفُ يقرأ ولا يكتب */
    public function canPost(): bool
    {
        return Conversation::roleCanPost($this->role);
    }

    /** يقدر إدارةَ الأعضاء (إضافة/إزالة/دور) — المشرفُ فأعلى */
    public function canManage(): bool
    {
        return Conversation::roleCanManage($this->role);
    }
}
