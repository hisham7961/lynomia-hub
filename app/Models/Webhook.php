<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Webhook extends Model
{
    use HasUuid;

    protected $guarded = [];
    protected $casts = ['active' => 'bool', 'last_ok' => 'bool',
                        'paused_until' => 'datetime', 'last_at' => 'datetime',
                        'secret' => \App\Casts\EncryptedOrPlain::class];   // مشفَّرٌ في القاعدة كسائر الاعتمادات (v2.399)

    public function deliveries()
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /** هل الاشتراك جاهز للإرسال الآن؟ (مفعّل وغير موقوف مؤقتاً) */
    /**
     * هل يشمل هذا الاشتراك حدثاً مثل projects.created؟
     * الأنماط: «*» · «وحدة.حدث» · «وحدة.*» · «*.حدث» · و«وحدة» وحدها = كل أحداثها
     * (فمن يكتب اسم الوحدة مجرداً يقصدها كلها، ولا يصح أن يشترك بصمت في لا شيء).
     * المطابقة غير حساسة لحالة الأحرف.
     */
    public function wants(string $event): bool
    {
        $event = mb_strtolower(trim($event));
        [$mod, $ev] = explode('.', $event) + [1 => ''];

        foreach (preg_split('/[،,\s]+/u', mb_strtolower((string) $this->events), -1, PREG_SPLIT_NO_EMPTY) as $p) {
            if ($p === '*' || $p === $event || $p === $mod || $p === $mod . '.*' || $p === '*.' . $ev) return true;
        }

        return false;
    }
}
