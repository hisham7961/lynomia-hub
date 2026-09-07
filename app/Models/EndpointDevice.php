<?php

namespace App\Models;

use App\Support\Es256;
use App\Traits\Auditable;
use App\Traits\HasUuid;
use App\Traits\HasVersions;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * **جهازُ نقطةٍ طرفية** (Work OS · الطور J · WP-J.1 · §43) — جهازُ شركةٍ سجّل
 * نفسَه بالتسجيل اللاتماثليّ (عقدُ التوقيع في docblock ‏`App\Support\Es256`).
 *
 * **الخادمُ يخزّن العامَّ فقط** — `saving` أدناه يرفض أيَّ مادةِ مفتاحٍ خاصّ
 * ويشتقّ البصمةَ من المفتاح المخزَّن نفسِه، فلا تفترق بصمةٌ عن مفتاحها أبداً.
 *
 * `status` قائمةُ سماحٍ تُفرَض هنا (لا DB enum — درسُ C10)؛ والحقولُ الآليّة
 * (hostname/os/pubkey_fp/status/آخرُ نبضة) **مقفولةٌ** في سجل الوحدة فلا يكتبها
 * CRUD العامّ — يكتبها مسارُ التسجيل (WP-J.1) وheartbeat/الأوامر (WP-J.2) وحدَها.
 *
 * **ليس `UserDevice`**: ذاك بصمةُ جلسةِ متصفّح — وهذا هويّةُ أسطولٍ بمفتاحٍ عامّ.
 */
class EndpointDevice extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'endpoint_devices';
    public const MODULE = 'endpoints';
    public const DISPLAY = 'hostname';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'hw' => 'array',
        'posture' => 'array',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
        'last_heartbeat_at' => 'datetime',
    ];

    /** حالاتُ الجهاز — allowlist مفروضٌ في `saving` (لا DB enum · C10) */
    public const STATUSES = ['active', 'suspended', 'locked', 'retired'];

    /** أنظمةُ التشغيل المقبولة عند التسجيل — allowlist في التطبيق (C10) */
    public const OSES = ['windows', 'macos', 'linux'];

    protected static function booted(): void
    {
        static::saving(function (self $d) {
            // حالةٌ فارغة (إنشاءُ CRUD عامّ) تسقط للافتراضيّ؛ وقيمةٌ خارج القائمة
            // لا تُكتَب صامتةً فتُفسد دلالةَ الفرض (نمطُ IpRule::saving)
            if (trim((string) $d->status) === '') $d->status = 'active';
            if (! in_array((string) $d->status, self::STATUSES, true)) {
                throw new \InvalidArgumentException('حالةُ جهازٍ غيرُ معروفة: ' . $d->status);
            }

            // **العامُّ فقط**: أيُّ مادةِ مفتاحٍ خاصّ تُرفَض قبل أن تلمس القاعدة —
            // ولو أخطأ متحكّمٌ مستقبليّ فمرّرها، فهذا الحاجزُ الأخير لا يُلتفّ عليه
            if (filled($d->public_key)) {
                if (stripos((string) $d->public_key, 'PRIVATE') !== false) {
                    throw new \InvalidArgumentException('مفتاحٌ خاصٌّ في سجلّ جهاز — الخادمُ يخزّن العامَّ فقط');
                }
                if (! Es256::isP256PublicKey((string) $d->public_key)) {
                    throw new \InvalidArgumentException('مفتاحُ الجهاز ليس مفتاحاً عامّاً P-256 صالحاً');
                }
                // البصمةُ تُشتقّ من المفتاح نفسِه — لا تُدَّعى من حمولةٍ فتفترقا
                $d->pubkey_fp = Es256::fingerprint((string) $d->public_key);
            } else {
                $d->pubkey_fp = null;
            }

            // القصُّ عند الكاتب بمحارفَ لا بايتات — SQLite تمرّر الفائضَ وMySQL يرمي
            $d->hostname = mb_substr(trim((string) $d->hostname), 0, 120);
            $d->os = mb_substr(trim((string) $d->os), 0, 20);
            if ($d->agent_version !== null) $d->agent_version = mb_substr((string) $d->agent_version, 0, 30);
            if ($d->device_uuid !== null) $d->device_uuid = mb_substr((string) $d->device_uuid, 0, 64);
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** حسابُ الحامل (نمطُ stations.current_employee_id — مرجعُ users) */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
