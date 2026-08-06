<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** طلب توقيع إلكتروني — رابط خاص بكلمة سر، وأثرٌ كامل: من ومتى ومن أين */
class SignRequest extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'sign_requests';
    protected $guarded = ['id'];
    protected $hidden = ['pass'];
    // v2.117: كانت opts تُقرأ بـ json_decode يدوي وexpires_at تُقارن نصاً خاماً
    protected $casts = ['opened_at' => 'datetime', 'signed_at' => 'datetime',
                        'expires_at' => 'datetime', 'opts' => 'array',
                        'sent_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function signers()
    {
        return $this->hasMany(ContractSigner::class, 'request_id')->orderBy('order');
    }

    public function events()
    {
        // الترتيبُ نفسُه الذي تحسب به السلسلة — وإلا طبع الخطُّ الزمنيّ ترتيباً
        // والبصمةُ تُحسب بآخر
        return ContractEvent::ordered($this->hasMany(ContractEvent::class, 'request_id'));
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    /** اسم الجهة المرتبطة معروضاً — «👤 مطاعم الذواقة» أو null */
    public function linkLabel(): ?string
    {
        if (! $this->link_module || ! $this->link_id) return null;
        $def = hub_mod($this->link_module);
        if (! $def) return null;
        $class = '\\App\\Models\\' . $def['model'];
        if (! class_exists($class)) return null;
        $row = $class::find($this->link_id);
        if (! $row) return null;
        $icons = ['clients' => '👤', 'companies' => '🏢', 'hr' => '🧑‍💼',
                  'suppliers' => '🚚', 'recruit' => '🎯', 'projects' => '🚀',
                  'approvals' => '✅', 'decisions' => '⚖️', 'policies' => '📜', 'policyacks' => '🖊️'];

        return ($icons[$this->link_module] ?? '🔗') . ' ' . ($row->{hub_display_col($this->link_module)} ?? $this->link_id);
    }

    /** رابط صفحة الجهة المرتبطة */
    public function linkUrl(): ?string
    {
        return ($this->link_module && $this->link_id)
            ? route('m.show', [$this->link_module, $this->link_id]) : null;
    }
}
