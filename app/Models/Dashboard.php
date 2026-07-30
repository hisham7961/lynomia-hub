<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** لوحة قابلة للبناء: تخصّ مالكها أو تُنشَر لدور — والودجات صفوفٌ مستقلة */
class Dashboard extends Model
{
    use HasUuid, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'is_default' => 'boolean',
        'shared'     => 'boolean',
    ];

    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class)->orderBy('y')->orderBy('x');
    }

    /** من يملك تعديلها: صاحبها، أو المالك على لوحات الأدوار */
    public function editableBy($user): bool
    {
        if (! $user) return false;
        if ($this->owner_id) return $this->owner_id === $user->id || hub_is_owner($user);

        return hub_is_owner($user);          // لوحات الأدوار للمالك وحده
    }

    /** من يراها: صاحبها، أو حاملو دورها، أو الجميع إن نُشرت */
    public function visibleTo($user): bool
    {
        if (! $user) return false;
        if ($this->owner_id === $user->id) return true;
        if ($this->role_id && $this->role_id === $user->role_id) return true;

        return (bool) $this->shared;
    }
}
