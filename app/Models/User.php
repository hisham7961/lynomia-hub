<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable, HasUuid, SoftDeletes, Auditable;
    public $incrementing = false;
    protected $keyType = 'string';

    public const MODULE = 'users';

    /**
     * **حالةُ الحساب ثابتةٌ لا نصٌّ حرّ** (الجولة 1 · F31) — enum التطبيق (درس C10).
     *
     * `users.status` كان يُكتب حرّاً ويُقرأ على غير مقياس: الدخولُ يحجب «موقوف»
     * وحدها، وحارسُ الجلسة لا يقبل إلا «نشط» حرفاً بحرف — فقيمةٌ مكسورةُ الترميز
     * كُتبت يوماً جعلت الحسابَ يدخل ثم يُطرد فوراً، بينما شاشاتٌ تعدّه نشطاً
     * (`!= 'موقوف'`). القاعدة: الكتّابُ يمرّون بـ`in:` على هذه الثوابت، والقرّاءُ
     * يحكمون بـ`isActive()` وحدها — مقياسٌ واحدٌ للجميع.
     */
    public const STATUS_ACTIVE = 'نشط';
    public const STATUS_SUSPENDED = 'موقوف';
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_SUSPENDED];

    /**
     * أعمدةٌ لا تُكتب قيمتُها في سجل التدقيق أبداً — تُستبدَل ببصمة (Auditable::auditRedact).
     * كان تجزيءُ كلمة المرور ورمزُ «تذكّرني» يُختمان في `audits.after` إلى الأبد (v2.399).
     */
    public const AUDIT_SECRET = ['password', 'remember_token', 'totp_secret_cipher', 'recovery_codes'];

    protected $guarded = ['id'];
    protected $hidden = ['password', 'totp_secret_cipher', 'recovery_codes', 'remember_token'];

    protected $casts = [
        'notify_prefs' => 'array',
        'prefs' => 'array',
        'companies' => 'array',
        'clients' => 'array',      // عزل العملاء — نظير عزل الشركات
        'recovery_codes' => 'array',
        'totp_enabled' => 'boolean',
        'totp_secret_cipher' => \App\Casts\EncryptedOrPlain::class,
        'locked_until' => 'datetime',
        'password_changed_at' => 'datetime',
        'last_login_at' => 'datetime',
        'password' => 'hashed',
    ];

    /** حسابٌ جديد يجد ملفَّه الوظيفي الذي ينتظره — البريد هو الهوية */
    protected static function booted(): void
    {
        static::created(fn (self $u) => \App\Support\Workforce\Staff::linkWaitingFile($u));

        // حارسُ الثوابت — يرفض حالةً خارج allowlist قبل أي كتابة (نمطُ
        // ClientMembership نفسه). يُفحص **المتغيّرُ فقط**: صفٌّ قديمٌ بقيمةٍ
        // مكسورة يبقى قابلاً للحفظ في أعمدةٍ أخرى (عدّادُ الدخول مثلاً)،
        // لكن لا أحدَ يكتب حالةً جديدةً خارج الثوابت بعد اليوم.
        static::saving(function (self $u): void {
            if ($u->isDirty('status') && $u->status !== null
                && ! in_array((string) $u->status, self::STATUSES, true)) {
                throw new \InvalidArgumentException('حالةُ مستخدمٍ غيرُ صالحة: ' . $u->status);
            }
        });
    }

    /* ────────── الحالة: تطبيعٌ عند الكتابة وحكمٌ موحّد عند القراءة (F31) ────────── */

    /**
     * تشذيبُ الفراغات ومحارفِ الاتجاه غير المرئية (RTL/LTR marks، NBSP، ZWSP، BOM)
     * — نسخُ «نشط» من مستندٍ أو محادثةٍ يجرّ معه محرفاً خفيّاً فيفشل الحرفيّ.
     */
    public static function normalizeStatus(?string $v): ?string
    {
        if ($v === null) return null;

        $s = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}\x{00A0}]/u', '', $v);

        return trim($s);
    }

    /** التطبيعُ الدفاعيّ يقع عند المنبع — كلُّ كاتبٍ (نموذج/استيراد/سكربت) يمرّ به */
    public function setStatusAttribute(?string $v): void
    {
        $this->attributes['status'] = self::normalizeStatus($v);
    }

    /**
     * **الحكمُ الواحد**: أنشطٌ هذا الحساب؟ — به يقارن الدخولُ وحارسُ الجلسة
     * وشاشةُ الجدول وفجواتُ التدقيق، فلا يفترق قارئان بعد اليوم.
     * `null` يُقرأ نشطاً (سلوكُ حارس الجلسة التاريخي لصفوفِ ما قبل العمود)،
     * وكلُّ ما سواه يُطبَّع ثم يُقارَن على الثابت — المجهولُ يفشل مغلقاً.
     */
    public function isActive(): bool
    {
        return $this->status === null || self::normalizeStatus((string) $this->status) === self::STATUS_ACTIVE;
    }

    /** موقوفٌ أو بقيمةٍ مجهولة — عكسُ `isActive` حرفياً (المجهول يفشل مغلقاً) */
    public function isSuspended(): bool
    {
        return ! $this->isActive();
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** الملفّ الوظيفي المرتبط بهذا الحساب */
    public function employee(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Employee::class, 'user_id');
    }

    public function isOwner(): bool
    {
        return (bool) ($this->role?->is_owner);
    }

    /**
     * حسابُ عميلٍ خارجيّ — المصنِّفُ الصلب (Work OS · SF-1): يُقرأ من عمود
     * `account_type` مباشرةً، ولا يُستنتج من `users.clients` (قد تكون فارغةً
     * لعميلٍ جديدٍ أو مأهولةً لموظفٍ داخليٍّ مخصَّصٍ لعملاء). التصنيفُ بنيويٌّ لا نطاقيّ.
     */
    public function isClientAccount(): bool
    {
        return $this->account_type === 'client';
    }

    /** صلاحية على وحدة: v=عرض a=إضافة e=تعديل d=حذف */
    public function can2(string $module, string $action): bool
    {
        if ($this->isOwner()) return true;
        $m = $this->role?->matrix[$module] ?? null;
        return (bool) ($m[$action] ?? false);
    }

    public function flag(string $f): bool
    {
        return $this->isOwner() || (bool) ($this->role?->flags[$f] ?? false);
    }

    /** المشاريع التي يراها المستخدم عند نطاق "مشاريعه فقط" */
    public function visibleProjectIds(): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "user:{$this->id}:projects", 300,
            fn () => Project::query()
                ->where('manager_id', $this->id)
                ->orWhereJsonContains('members', $this->id)
                ->pluck('id')->all()
        );
    }
}