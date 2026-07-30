<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class HubNotification extends Model
{
    use HasUuid;

    protected $table = 'notifications_hub';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['read' => 'boolean', 'created_at' => 'datetime'];

    /** الأنواع القابلة للكتم (المهمة كالموافقات والإسناد والرسائل تبقى دائماً) */
    public const MUTEABLE = [
        'react' => 'التفاعلات على منشوراتي',
        'reply' => 'الردود على تعليقاتي',
        'mention' => 'الإشارات إليّ (@)',
        'flow' => 'تنبيهات مسارات العمل',
    ];

    /**
     * كتمٌ شامل عند المصدر: أي إشعار من نوع كتمه مستلمه لا يُنشأ أصلاً —
     * يغطي كل مواضع الإنشاء (تعليقات، تفاعلات، مسارات) بلا لمسها.
     */
    protected static function booted(): void
    {
        static::creating(function (self $n) {
            $kind = (string) $n->kind;
            if (! isset(self::MUTEABLE[$kind]) || ! $n->user_id) return;

            static $muted = [];   // ذاكرة الطلب: تفضيلات كل مستلم تُقرأ مرة
            if (! array_key_exists($n->user_id, $muted)) {
                $prefs = \App\Models\User::whereKey($n->user_id)->value('prefs');
                $arr = is_array($prefs) ? $prefs : (json_decode((string) $prefs, true) ?: []);
                $muted[$n->user_id] = (array) ($arr['mute'] ?? []);
            }

            if (in_array($kind, $muted[$n->user_id], true)) return false;   // كتم — لا يُنشأ
        });
    }
}
