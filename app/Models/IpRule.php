<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * **قاعدةُ IP (سماح/حظر)** — Work OS · الطور I · WP-I.1 · §41.
 *
 * صفٌّ لكلّ قاعدة: عنوانٌ دقيق أو CIDR رابعة/سادسة، يدويّةٌ أو آليّة، مؤقّتةٌ
 * (`expires_at`) أو دائمة (null). Eloquent لا `DB::table` — لأنّ
 * `FlowRunner::fire` (Model-typed) سيستقبلها في WP-I.2 (`ip_auto_blocked`)،
 * و`Auditable` يسجّل كلَّ إنشاءٍ/تعديلٍ/إلغاءٍ في سلسلة التدقيق.
 *
 * **لا مُحلِّلَ ثانٍ:** المطابقةُ كلُّها تفويضٌ للمُطابِق الواحد `ip_allowed()`
 * (helpers:27 — دقيق/بدل صريح/CIDR ببايتات inet_pton للعائلتين). أيُّ افتراقٍ
 * بين ما تفرضه بوّابةُ الحساب وما يفرضه الدفاعُ التكيّفيّ ثغرةٌ — فالمصدرُ واحد.
 *
 * **دلالةُ الحياة (تبدأ هنا ويبني عليها IpDefense في WP-I.3):** القاعدةُ حيّةٌ
 * ما لم تُلغَ (`revoked_at`) وما لم تنتهِ (`expires_at` ماضٍ) — والمنتهيةُ تتوقّف
 * عن المطابقة **فوراً** في `matches` نفسِها لا في التقليم وحدَه (حظرٌ شبح = انقطاع).
 *
 * `mode`/`origin` قائمتا سماحٍ تُفرَضان في `saving` (لا DB enum — درسُ C10)؛
 * و`reason` يقصّه الكاتبُ بـ`mb_substr(400)` (درسُ `notifications_hub.kind`).
 *
 * **تنبيهُ تسمية:** لا صلةَ بـ`IpAsset` (الملكيّة الفكريّة) — ذاك سجلُّ أصولٍ قانونيّ.
 */
class IpRule extends Model
{
    use HasFactory, HasUuid, Auditable;

    protected $table = 'ip_rules';

    /** ليس وحدةَ سجلٍّ — الثابتان لسجل التدقيق (Auditable) لا لـhub_modules (نمطُ ClientMembership) */
    public const MODULE = 'ip_rules';
    public const DISPLAY = 'ip';

    protected $guarded = ['id'];

    protected $casts = [
        'is_cidr' => 'boolean',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'escalation_level' => 'integer',
        'hits' => 'integer',
    ];

    /** نمطا القاعدة — allowlist مفروضٌ في `saving` (لا DB enum · C10) */
    public const MODES = ['block', 'allow'];

    /** مصدرا القاعدة — يدويّةٌ من شاشات الأمن، آليّةٌ من سلّم WP-I.2 */
    public const ORIGINS = ['manual', 'auto'];

    protected static function booted(): void
    {
        static::saving(function (self $r) {
            // قيمةٌ خارج القائمة لا تُكتَب صامتةً فتُفسد دلالةَ الفرض (نمطُ StationAssignment)
            if (! in_array((string) $r->mode, self::MODES, true)) {
                throw new \InvalidArgumentException('نمطُ قاعدةِ IP غيرُ معروف: ' . $r->mode);
            }
            if (! in_array((string) $r->origin, self::ORIGINS, true)) {
                throw new \InvalidArgumentException('مصدرُ قاعدةِ IP غيرُ معروف: ' . $r->origin);
            }

            // is_cidr يُشتقّ من شكل القاعدة نفسِها — لا يُترَك لادّعاء النموذج فيفترقا
            $r->is_cidr = str_contains((string) $r->ip, '/');

            // القصُّ عند الكاتب بمحارفَ لا بايتات — SQLite تمرّر الفائضَ وMySQL يرمي
            if ($r->reason !== null) $r->reason = mb_substr((string) $r->reason, 0, 400);
            if ($r->severity !== null) $r->severity = mb_substr((string) $r->severity, 0, 12);
        });

        // (WP-I.3) كلُّ كتابةِ قاعدةٍ تنسف خبيئةَ الفرض القصيرة — فالتغييرُ يسري
        // لحظياً لا بعد TTL: إلغاءٌ يرفع الصدَّ فوراً، وحظرٌ جديد يصدّ فوراً.
        static::saved(fn () => \App\Http\Middleware\IpDefense::bust());
        static::deleted(fn () => \App\Http\Middleware\IpDefense::bust());
    }

    /**
     * القاعدةُ الحيّة: غيرُ ملغاةٍ وغيرُ منتهية — القراءةُ التي سيبني عليها
     * `IpDefense` (WP-I.3) عبر فهرس `(mode, expires_at)`. الترتيبُ عند الاستهلاك
     * حتميٌّ دائماً (`orderBy(col)->orderBy('id')` — درسُ CLAUDE.md).
     */
    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('revoked_at')
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * هل تنطبق هذه القاعدةُ على العنوان؟ — تفويضٌ كاملٌ للمُطابِق الواحد
     * `ip_allowed()`: دقيق/CIDR/IPv4/IPv6، واختلافُ العائلة لا يرمي.
     *
     * الحياةُ تُفحَص هنا أيضاً (لا في `scopeActive` وحدَه): قاعدةٌ منتهيةٌ أو
     * ملغاةٌ وصلت المُستدعي بأيّ طريقٍ يجب أن تتوقّف عن المطابقة **فوراً** —
     * دفاعٌ في العمق ضدّ حظرٍ شبحٍ من كاشٍ متأخّرٍ أو استعلامٍ بلا نطاق.
     */
    public function matches(string $ip): bool
    {
        if ($this->revoked_at !== null) return false;
        if ($this->expires_at !== null && ! $this->expires_at->isFuture()) return false;

        return ip_allowed($ip, (string) $this->ip);
    }

    /** مَن أنشأ القاعدة (null للآليّة قبل إسنادِ فاعل) */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'by_id');
    }

    /** مَن ألغاها */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
