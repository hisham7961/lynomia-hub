<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** نقطة قياس زمنية: (وحدة، سجل، مقياس) ← قيمة عند لحظة */
class MetricPoint extends Model
{
    use HasUuid;

    protected $table = 'metric_points';
    protected $guarded = ['id'];

    protected $casts = ['at' => 'datetime', 'value' => 'float', 'meta' => 'array'];
}
