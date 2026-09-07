<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * تنصيبُ تطبيقِ جوالٍ أصيل (iOS/Android) — Mobile Readiness · الطور B.
 *
 * هويّتُه `installation_uuid` يولّده التطبيق (لا معرّفَ جهازٍ نظاميّ · spec §Models)،
 * و`platform` قائمةُ سماحٍ في التطبيق (`ios|android`) لا DB enum. ليست
 * `UserDevice` (ثقةُ متصفّحٍ بكوكي) — قرارُ الفصل B (INVENTORY §3c).
 */
class MobileInstallation extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $table = 'mobile_installations';
    protected $guarded = ['id'];

    protected $casts = [
        'push_capable'  => 'boolean',
        'last_seen_at'  => 'datetime',
        'registered_at' => 'datetime',
        'revoked_at'    => 'datetime',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    /** المنصّاتُ المسموحة — قائمةُ سماحٍ في التطبيق لا DB enum (C10) */
    public const PLATFORMS = ['ios', 'android'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sessions()
    {
        return $this->hasMany(MobileSession::class, 'installation_id');
    }
}
