<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class KpiDef extends Model
{
    use HasUuid;

    protected $table = 'kpi_defs';
    protected $guarded = ['id'];
    protected $casts = ['formula' => 'array', 'target' => 'float',
        // نسبُ الهدف (v2.539): متى قِيس خطُّ الأساس — تاريخٌ لا نصّ، فيُقرأ عمرُه
        'baseline_at' => 'datetime'];
}
