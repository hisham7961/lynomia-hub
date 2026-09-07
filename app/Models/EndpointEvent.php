<?php

namespace App\Models;

use App\Support\EndpointPrivacy;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * **حدثُ نقطةٍ طرفية** (Work OS · الطور J · WP-J.2 · §43) — لقطةٌ يبلّغها وكيلُ
 * جهازٍ عبر المسار الموقَّع (عقدُ التوقيع في docblock ‏`App\Support\Es256`).
 *
 * `kind` و`severity` قائمتا سماحٍ تُفرَضان هنا (لا DB enum — درسُ C10)؛
 * والملخّصُ **يُنقَّح ويُقصّ عند الكاتب** (محارفُ التحكّم تُنزَع، ٤٠٠ محرفاً)؛
 * و`saving` حاجزٌ أخيرٌ ضد حقول المراقبة في `meta` — المُصادِقُ في المتحكّم
 * يرفضها 422 قبل الوصول هنا، وهذا الصفُّ يرفضها ولو أخطأ كاتبٌ مستقبليّ.
 *
 * لقطةٌ لا تُحرَّر: `created_at` وحدَه (لا updated_at).
 */
class EndpointEvent extends Model
{
    use HasUuid;

    protected $table = 'endpoint_events';
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    /** أنواعُ الأحداث — allowlist مفروضٌ في `saving` (لا DB enum · C10) */
    public const KINDS = ['usb', 'posture', 'network_self', 'policy', 'agent'];

    /** سلّمُ الشدّة — سلّمُ SecurityEvents نفسُه فيصبّ في مركز الأمن بلا ترجمة */
    public const SEVERITIES = ['info', 'notice', 'warning', 'high'];

    protected static function booted(): void
    {
        static::saving(function (self $e) {
            if (! in_array((string) $e->kind, self::KINDS, true)) {
                throw new \InvalidArgumentException('نوعُ حدثٍ خارج القائمة: ' . $e->kind);
            }
            if (trim((string) $e->severity) === '') $e->severity = 'info';
            if (! in_array((string) $e->severity, self::SEVERITIES, true)) {
                throw new \InvalidArgumentException('شدّةُ حدثٍ خارج السلّم: ' . $e->severity);
            }

            // التنقيحُ والقصُّ عند الكاتب — SQLite تمرّر الفائضَ وMySQL يرمي
            $e->summary = EndpointPrivacy::sanitizeText((string) $e->summary, 400);
            $e->nonce = mb_substr(trim((string) $e->nonce), 0, 64);
            if ($e->request_id !== null) $e->request_id = mb_substr((string) $e->request_id, 0, 64);

            // الحاجزُ الأخير ضد المراقبة: meta تحمل مفتاحَ تجسّسٍ = صفٌّ لا يُكتب
            if (is_array($e->meta) && ($v = EndpointPrivacy::violations($e->meta)) !== []) {
                throw new \InvalidArgumentException('حقولُ مراقبةٍ في حدثِ نقطةٍ طرفية: ' . implode('، ', $v));
            }
        });
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(EndpointDevice::class, 'device_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
