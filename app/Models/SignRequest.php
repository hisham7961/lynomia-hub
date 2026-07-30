<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** طلب توقيع إلكتروني — رابط خاص بكلمة سر، وأثرٌ كامل: من ومتى ومن أين */
class SignRequest extends Model
{
    use HasUuid;

    protected $table = 'sign_requests';
    protected $guarded = ['id'];
    protected $hidden = ['pass'];
    protected $casts = ['opened_at' => 'datetime', 'signed_at' => 'datetime'];

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
                  'suppliers' => '🚚', 'recruit' => '🎯', 'projects' => '🚀'];

        return ($icons[$this->link_module] ?? '🔗') . ' ' . ($row->{hub_display_col($this->link_module)} ?? $this->link_id);
    }

    /** رابط صفحة الجهة المرتبطة */
    public function linkUrl(): ?string
    {
        return ($this->link_module && $this->link_id)
            ? route('m.show', [$this->link_module, $this->link_id]) : null;
    }
}
