<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use App\Traits\HasVersions;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** أرقام الهواتف — أصلُ الاتصالات (SIM/eSIM/الخط) على السكّة القائمة (Work OS · الطور G) */
class PhoneNumber extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'phone_numbers';
    public const MODULE = 'phones';
    public const DISPLAY = 'number';

    protected $guarded = ['id', 'version', 'created_by'];

    /**
     * أعمدةُ الأسرار لا تُكتب قيمتُها في سجل التدقيق — بصمةٌ فقط
     * (انظر `Auditable::auditRedact`؛ نظيرُ `VaultSecret::AUDIT_SECRET`).
     * PIN/PUK مراجعُ خزنةٍ لا قيَم — لا في شاشة، ولا تصدير، ولا أثر.
     */
    public const AUDIT_SECRET = ['pin', 'puk'];

    protected $casts = [
        'sms' => 'boolean',
        'wa' => 'boolean',
        'roaming' => 'boolean',
        'expiry' => 'date',
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'cost' => 'decimal:3',
        // PIN/PUK مشفّران عند الحفظ ويُفكّان عند القراءة (نمطُ vault_secrets.secret_cipher)
        'pin' => \App\Casts\EncryptedOrPlain::class,
        'puk' => \App\Casts\EncryptedOrPlain::class,
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    protected static function booted(): void
    {
        // هويّةُ الخط (ICCID/MSISDN) تدخل سجلَّ الهوية الموحّد تلقائياً — فيحلّها
        // `Identity::resolve` بالمسح، وتعديلُها من أيّ مسار (CRUD/API/استيراد) يلحق
        // وحده. **لا لوكَبٌ ثانٍ** — `Identity::attach` هو المحرّكُ الوحيد للهوية.
        static::saved(function (self $p) {
            if ($p->iccid) {
                \App\Support\Identity::attach('phones', $p->id, 'iccid', $p->iccid,
                    ['is_primary' => true, 'verified' => true]);
            }
            if ($p->msisdn) {
                \App\Support\Identity::attach('phones', $p->id, 'msisdn', $p->msisdn);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Company::class, 'company_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_id');
    }

    // ── روابطُ الأصل (Work OS · الطور G · WP-G.2 · §22/§23/§28) ──
    // مراجعُ تُضيء علاقاتِ hub_children/hub_related تلقائياً — والعزلُ يبقى في hub_scope.

    /** المزوّدُ الذي أصدر الخطّ */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Carrier::class, 'carrier_id');
    }

    /** الموظفُ المُخصَّصُ له الخطّ — يُضيء تبويبَ الاتصالات في الموظف 360 */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'employee_id');
    }

    /** الجهازُ (الأصل) الذي تعيش فيه الشريحة */
    public function device(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Asset::class, 'device_id');
    }

    /** المحطةُ (المقعد) التي يخدمها الخطّ — من الطور F */
    public function station(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Station::class, 'station_id');
    }
}
