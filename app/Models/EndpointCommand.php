<?php

namespace App\Models;

use App\Support\EndpointPrivacy;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * **أمرُ نقطةٍ طرفية** (Work OS · الطور J · WP-J.2 · §43) — عنصرُ طابورٍ على
 * آلةِ حالة outbox: ادّعاءٌ ذرّيّ (UPDATE مشروط) فلا ازدواجَ إرسال، وانتقالُ
 * النتيجة مشروطٌ كذلك (`EndpointProtocolController`).
 *
 * `type` **القائمةُ المغلقة الخمسة لا غير** — allowlist هنا (لا DB enum — درسُ
 * C10 حرفياً): **لا مسارَ أمرٍ حرّ ولا shell في النظام إطلاقاً**؛ الخطيران
 * (isolate/lock — ومعهما أيُّ wipe مستقبليّ) لا يُصدَران إلا بتصعيدِ هويةٍ
 * (`hub_require_stepup`) + سببٍ إلزاميّ + قيدِ تدقيق.
 */
class EndpointCommand extends Model
{
    use HasUuid;

    protected $table = 'endpoint_commands';

    protected $guarded = ['id'];

    protected $casts = [
        'args' => 'array',
        'result' => 'array',
        'claimed_at' => 'datetime',
        'finished_at' => 'datetime',
        'next_at' => 'datetime',
    ];

    /** القائمةُ المغلقة — الخمسةُ لا غير، ولا shell (C10) */
    public const TYPES = ['refresh_inventory', 'refresh_posture', 'apply_policy', 'isolate', 'lock'];

    /** ما يتطلب تصعيدَ هويةٍ + سبباً إلزامياً + تدقيقاً — الأفعالُ الماسّة بالجهاز */
    public const STEPUP_TYPES = ['isolate', 'lock'];

    /** حالاتُ آلة الحالة — allowlist مفروضٌ في `saving` (لا DB enum · C10) */
    public const STATES = ['pending', 'claimed', 'done', 'failed', 'expired'];

    protected static function booted(): void
    {
        static::saving(function (self $c) {
            if (! in_array((string) $c->type, self::TYPES, true)) {
                throw new \InvalidArgumentException('نوعُ أمرٍ خارج القائمة المغلقة: ' . $c->type);
            }
            if (trim((string) $c->state) === '') $c->state = 'pending';
            if (! in_array((string) $c->state, self::STATES, true)) {
                throw new \InvalidArgumentException('حالةُ أمرٍ غيرُ معروفة: ' . $c->state);
            }

            // isolate/lock بلا سببٍ لا يُكتب أصلاً — الحاجزُ الأخير تحت حرس المتحكّم
            if (in_array((string) $c->type, self::STEPUP_TYPES, true) && trim((string) $c->reason) === '') {
                throw new \InvalidArgumentException('أمرُ ' . $c->type . ' بلا سببٍ إلزاميّ');
            }

            // القصُّ عند الكاتب بمحارفَ لا بايتات — SQLite تمرّر الفائضَ وMySQL يرمي
            $c->ikey = mb_substr(trim((string) $c->ikey), 0, 64);
            if ($c->ikey === '') {
                throw new \InvalidArgumentException('أمرٌ بلا مفتاح idempotency');
            }
            if ($c->reason !== null) $c->reason = EndpointPrivacy::sanitizeText((string) $c->reason, 400);
            if ($c->result_sig !== null) $c->result_sig = mb_substr((string) $c->result_sig, 0, 700);

            // النتيجةُ لا تحمل مراقبةً — حاجزٌ أخيرٌ تحت مُصادِق المتحكّم
            if (is_array($c->result) && ($v = EndpointPrivacy::violations($c->result)) !== []) {
                throw new \InvalidArgumentException('حقولُ مراقبةٍ في نتيجة أمر: ' . implode('، ', $v));
            }
        });
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(EndpointDevice::class, 'device_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'by_id');
    }
}
