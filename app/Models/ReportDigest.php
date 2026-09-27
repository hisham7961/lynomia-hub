<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * **ملخّصُ تقارير مشروعٍ واحد** — صفٌّ لكلِّ مشروع يكتبه `App\Support\Ai\Reports\ProjectReportDigest` وحدَه.
 * الرؤيةُ لا تُقرأ من هنا: `DigestAccess` يقرّر (نطاقُ المشروع + `updates:v` + حجبُ الحقول).
 */
class ReportDigest extends Model
{
    use HasUuid;

    protected $table = 'project_report_digests';

    protected $guarded = ['id'];

    protected $casts = [
        'sections' => 'array',
        'prev_sections' => 'array',
        'members' => 'array',
        'reports_count' => 'integer',
        'covered_until' => 'datetime',
        'generated_at' => 'datetime',
        'attempted_at' => 'datetime',
    ];
}
