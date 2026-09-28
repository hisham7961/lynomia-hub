<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * **تقريرُ أداء موظّفٍ لفترةٍ واحدة** — يكتبه `App\Support\Ai\Reports\EmployeePerformance` وحدَه.
 * الرؤيةُ لا تُقرأ من هنا: `PerformanceAccess` يقرّر (نطاقُ hr أو المديرُ المباشر، وحجبُ الحقول).
 */
class EmployeePerformanceReport extends Model
{
    use HasUuid;

    protected $table = 'employee_performance_reports';

    protected $guarded = ['id'];

    protected $casts = [
        'facts' => 'array',
        'narrative' => 'array',
        'period_from' => 'date',
        'period_to' => 'date',
        'as_of' => 'date',
        'generated_at' => 'datetime',
        'attempted_at' => 'datetime',
    ];
}
