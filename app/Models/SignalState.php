<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * تصرّفُ المستخدم بحالة إشارةٍ محسوبة (إقرار/تأجيل/رفض) — لا الإشارةُ نفسها.
 * يُدمَج بمفتاح `skey` الثابت فلا يتكرّر، ويُقرأ فوق الإشارات المحسوبة حيّاً.
 */
class SignalState extends Model
{
    use HasUuid;

    protected $table = 'signal_states';

    protected $guarded = ['id'];

    protected $casts = [
        'snoozed_until' => 'datetime',
        'at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /** هل يخفي هذا التصرّفُ الإشارةَ الآن؟ (مرفوضةٌ دائماً، مؤجَّلةٌ حتى موعدها) */
    public function hidesNow(): bool
    {
        if ($this->state === 'dismissed') return true;
        if ($this->state === 'snoozed' && $this->snoozed_until && $this->snoozed_until->isFuture()) return true;

        return false;
    }
}
