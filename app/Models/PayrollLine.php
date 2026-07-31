<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** سطر مسيّر رواتب: راتب موظف واحد داخل المسيّر — أساسي وبدلات وخصومات وصافٍ */
class PayrollLine extends Model
{
    use HasUuid;

    protected $table = 'payroll_lines';
    protected $guarded = ['id'];

    protected $casts = [
        'base' => 'float', 'allow' => 'float', 'overtime' => 'float',
        'deduct' => 'float', 'advance' => 'float', 'net' => 'float',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'emp_id');
    }

    public function run()
    {
        return $this->belongsTo(PayrollRun::class, 'run_id');
    }
}
