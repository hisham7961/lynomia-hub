<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ودجة داخل لوحة: مفتاحها من WidgetRegistry، وموضعها وحجمها بوحدات الشبكة */
class DashboardWidget extends Model
{
    use HasUuid;

    protected $guarded = ['id'];

    protected $casts = ['config' => 'array'];

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }
}
