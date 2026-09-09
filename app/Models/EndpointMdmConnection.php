<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * **وصلةُ تكامل MDM** (Intune/Jamf) — مسارُ التصحيح §9.
 *
 * تحمل **الإعداد** لا الأسرار: المستأجرُ ظاهرٌ، والسرُّ إشارةٌ إلى `vault_secrets`
 * (المشفَّر هناك) لا قيمةً هنا. `provider` قائمةُ سماحٍ في النموذج (C10). و`enabled`
 * لا يعني «يفرض»: الفرضُ الفعليُّ يقرّره `MdmService` من قدرةِ المزوّد (canEnforce)
 * — وكلُّ المزوّدات المشحونة رصدٌ فقط (الجسرُ الحيُّ مؤجَّل). لا حجبَ زائفاً (C15).
 */
class EndpointMdmConnection extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'endpoint_mdm_connections';

    protected $guarded = ['id'];

    /** المزوّدان المدعومان — allowlist مفروضٌ في `saving` (لا DB enum · C10) */
    public const PROVIDERS = ['intune', 'jamf'];

    protected $casts = [
        'usb_policy_map' => 'array',
        'enabled' => 'boolean',
        'last_health_at' => 'datetime',
        'last_sync_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $c) {
            if (! in_array((string) $c->provider, self::PROVIDERS, true)) {
                throw new \InvalidArgumentException('مزوّدُ MDM خارج القائمة: ' . $c->provider);
            }
            // القصُّ عند الكاتب (MySQL صارم) — المستأجرُ ليس سرّاً فيُعرَض، لكنّه يُقصّ
            $c->external_tenant = filled($c->external_tenant)
                ? mb_substr(trim((string) $c->external_tenant), 0, 190) : null;
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** السرُّ في الخزنة — المرجعُ فقط (القيمةُ المشفَّرة تبقى في vault_secrets) */
    public function secret(): BelongsTo
    {
        return $this->belongsTo(VaultSecret::class, 'secret_id');
    }
}
