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

/** مسيّرات الرواتب */
class PayrollRun extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'payroll_runs';
    public const MODULE = 'payroll';
    public const DISPLAY = 'name';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'total' => 'decimal:3',
        'pay_date' => 'date',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    /**
     * **مسيّرٌ واحدٌ لكلِّ شهرٍ لكلِّ شركة** (مجلس الخبراء · DB-01).
     *
     * حُفظ «رواتب أغسطس» مرّتين لشركةٍ واحدة — `2026-08` و«أغسطس 2026» — بلا
     * تحذير. والاعتمادُ يولّد قيدَ يوميّةٍ لكلِّ مسيّر، فمسيّران معتمدان
     * **صرفُ راتبٍ مرّتين ومصروفٌ مضاعفٌ في الدفتر**.
     *
     * والحارسُ هنا لا في المتحكّم: البابُ ليس واحداً (نموذجٌ واستيرادٌ وواجهةٌ
     * برمجيّةٌ وجوال)، **وحارسٌ عند بابٍ واحدٍ من أربعةٍ ليس حارساً** — وهو الدرسُ
     * الذي تكرّر في هذا المجلسِ مرّتين. والفهرسُ الفريدُ يسنده حين تسمح البيانات.
     *
     * **وما لا يُفهَم شهرُه لا يُمنع:** صيغةٌ لم يعرفها المطبِّع تُحفَظ كما هي
     * بـ`month_key = null` — الحارسُ يمنع المتطابقَ المعروف لا المجهول.
     */
    protected static function booted(): void
    {
        static::saving(function (self $m) {
            /*
             * **نصٌّ `Y-m-d` لا تاريخٌ مصبوب.** كان العمودُ مصبوباً `date`، فيكتب
             * النموذجُ «2026-08-01 00:00:00» بينما تكتب هجرةُ التعبئةِ والاستيرادُ
             * «2026-08-01». وMySQL يُسوّيهما لأنّ العمودَ `DATE`، **وSQLite لا
             * يُسوّيهما** (نوعُه مرن) — فلا الفهرسُ الفريدُ يمسك ولا الحارس.
             * سلوكٌ يختلف بالمحرّك، وهو الصنفُ الذي وُجدت بوّابةُ المحرّكين لأجله.
             */
            $m->month_key = \App\Support\PayrollMonth::key($m->month);
            if ($m->month_key === null) return;

            $clash = static::withoutTrashed()
                ->where('month_key', $m->month_key)
                ->when($m->company_id === null,
                    fn ($q) => $q->whereNull('company_id'),
                    fn ($q) => $q->where('company_id', $m->company_id))
                ->when($m->exists, fn ($q) => $q->whereKeyNot($m->getKey()))
                ->first(['id', 'name']);

            if ($clash) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'month' => 'لهذه الشركة مسيّرٌ لنفس الشهر بالفعل: «' . $clash->name
                        . '» — عدّل القائم بدل إنشاء ثانٍ، فاعتمادُ مسيّرين لشهرٍ واحد '
                        . 'يصرف الراتبَ مرّتين.',
                ]);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Company::class, 'company_id');
    }
}
