<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * مِنحةُ تصعيدِ مصادقةٍ للجوال — Mobile Readiness · الطور B.
 *
 * بديلُ نافذةِ الجلسة في `StepUp` (التي تعيش في `session()`): مِنحةٌ عديمةُ الحالة
 * مربوطةٌ بـ**المستخدم + جلسة الجوال + الغرض + الانتهاء** (Critic F11 · INVENTORY
 * §2c). تُختم بعد نجاح `StepUp::verify` (فحصُ الاعتماد وحدَه يُعاد استعمالُه — لا
 * `session()`). بلا timestamps: الزمنُ في granted_at/expires_at/consumed_at.
 */
class MobileStepupGrant extends Model
{
    use HasUuid;

    protected $table = 'mobile_stepup_grants';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'granted_at'  => 'datetime',
        'expires_at'  => 'datetime',
        'consumed_at' => 'datetime',
    ];

    /** طرائقُ التصعيد المسموحة — قائمةُ سماحٍ في التطبيق لا DB enum (C10) */
    public const METHODS = ['totp', 'password'];

    public function session()
    {
        return $this->belongsTo(MobileSession::class, 'mobile_session_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** مِنحةٌ سارية: لم تُستهلَك ولم تنتهِ بعد */
    public function isFresh(): bool
    {
        return ! $this->consumed_at
            && $this->expires_at
            && now()->lte($this->expires_at);
    }
}
