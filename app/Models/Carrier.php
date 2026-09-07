<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use App\Traits\HasVersions;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * **مزوّدُ الاتصالات (Carrier)** — Work OS · الطور G · WP-G.2 · §22/§23.
 *
 * كيانٌ داخليٌّ مُدارٌ بالبيانات فوق `ModuleController` (وحدةُ `carriers` في
 * `config/hub.php`) — لا متحكّمَ خاص. مصدرُ الخطوطِ والشرائح، تُربَط به الخطوطُ عبر
 * `phone_numbers.carrier_id`. **داخليٌّ فقط** — بلا `client_id`، ممنوعٌ على حساب
 * العميل عبر `PortalGuard`.
 *
 * **السرُّ = مرجعُ خزنة (VaultSecret):** `portal_password` مشفّرٌ عند الحفظ ويُفكّ
 * عند القراءة عبر `EncryptedOrPlain` (نظيرُ `PhoneNumber::pin/puk` و
 * `vault_secrets.secret_cipher`)، ولا تُكتب قيمتُه في سجل التدقيق — بصمةٌ فقط
 * (`Auditable::auditRedact` يقرأ `AUDIT_SECRET`). لا يُطبَع في شاشةٍ ولا تصدير،
 * ويُكشف عبر مسار `revealSecret` وحدَه.
 */
class Carrier extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'carriers';
    public const MODULE = 'carriers';
    public const DISPLAY = 'name';

    protected $guarded = ['id', 'version', 'created_by'];

    /**
     * عمودُ السرِّ لا تُكتب قيمتُه في سجل التدقيق — بصمةٌ فقط
     * (نظيرُ `PhoneNumber::AUDIT_SECRET` و`VaultSecret::AUDIT_SECRET`).
     */
    public const AUDIT_SECRET = ['portal_password'];

    protected $casts = [
        // كلمةُ مرور البوّابة مشفّرةٌ عند الحفظ وتُفكّ عند القراءة (نمطُ vault_secrets.secret_cipher)
        'portal_password' => \App\Casts\EncryptedOrPlain::class,
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** خطوطُ هذا المزوّد — عكسُ `phone_numbers.carrier_id` */
    public function phones(): HasMany
    {
        return $this->hasMany(PhoneNumber::class, 'carrier_id');
    }
}
