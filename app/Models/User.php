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

    protected $guarded = ['id'];
    protected $hidden = ['password', 'totp_secret_cipher', 'recovery_codes', 'remember_token'];

    protected $casts = [
        'notify_prefs' => 'array',
        'prefs' => 'array',
        'companies' => 'array',
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
        static::created(fn (self $u) => \App\Support\Staff::linkWaitingFile($u));
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