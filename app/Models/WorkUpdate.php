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

/**
 * تحديثات العمل — بنودُ التقرير اليومي.
 *
 * البندُ يعرف يومَه ومشروعَه ومهمتَه وساعاتِه، ويغذّي المصادرَ القائمة بدل
 * أن يكون نسخةً ثانية عنها: ساعاتُه تُضاف لـ«الوقت الفعلي» على مهمته (فتعمل
 * الربحيةُ والقدراتُ القائمتان بلا إدخالٍ مزدوج)، ونسبتُه المقترحة تُحدِّث
 * المهمةَ آلياً أو تبقى اقتراحاً بانتظار المدير — بحسب إعداد المنشأة.
 */
class WorkUpdate extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'work_updates';
    public const MODULE = 'updates';
    public const DISPLAY = 'done';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'progress' => 'decimal:3',
        'hours' => 'decimal:3',
        'work_date' => 'date',
        'billable' => 'boolean',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $w) {
            // بندٌ بلا يومٍ هو بندُ اليوم — والسياقُ يُورَث من مشروعه لا يُسأل عنه
            if (! $w->work_date) $w->work_date = now()->toDateString();
            if (! $w->client_id && $w->project_id) {
                $w->client_id = Project::whereKey($w->project_id)->value('client_id');
            }
            // البندُ منسوبٌ لكاتبه دائماً — عليه يُبنى تقريرُ اليوم وشاشةُ الفريق
            // (created_by محروسٌ من التعبئة الجماعية، فيُسند هنا صراحةً)
            if (! $w->created_by && auth()->id()) $w->created_by = auth()->id();
        });

        // ساعاتُ البند تدخل «الوقت الفعلي» على مهمته **مرةً واحدة** — فمحرّكا
        // الربحية والقدرات القائمان (يقرآن tasks.act_h) يعملان بلا عدٍّ مزدوج.
        static::created(function (self $w) {
            if (! $w->task_id || ! (float) $w->hours) return;
            Task::whereKey($w->task_id)->increment('act_h', (float) $w->hours);

            // النسبةُ المقترحة: آليةً إن سمح الإعداد، وإلا اقتراحاً على meta
            // المهمة يعتمده مديرُها — لا كتابةَ تقدمٍ من طرفٍ واحد قسراً.
            if ($w->progress !== null) {
                $t = Task::find($w->task_id);
                if ($t && (float) $w->progress > (float) ($t->progress ?? 0)) {
                    if ((string) setting('work.progress_auto', '0') === '1') {
                        $t->progress = min(99, (float) $w->progress);
                        $t->save();
                    } else {
                        $t->meta = array_merge((array) $t->meta, ['suggested_progress' => [
                            'pct' => (float) $w->progress, 'by' => auth()->id(),
                            'at' => now()->toIso8601String(), 'update_id' => $w->id,
                        ]]);
                        $t->saveQuietly();
                    }
                }
            }
        });

        // تعديلُ الساعات أو المهمة يُصالَح بالفارق — لا نسختين من الحقيقة
        static::updated(function (self $w) {
            $oldTask = $w->getOriginal('task_id');
            $oldH = (float) $w->getOriginal('hours');
            if ($oldTask === $w->task_id && $oldH === (float) $w->hours) return;
            if ($oldTask && $oldH) Task::whereKey($oldTask)->decrement('act_h', $oldH);
            if ($w->task_id && (float) $w->hours) Task::whereKey($w->task_id)->increment('act_h', (float) $w->hours);
        });

        // حذفُ البند يستردّ ساعاتِه من المهمة — فلا تبقى ساعاتٌ يتيمةُ المصدر
        static::deleted(function (self $w) {
            if ($w->task_id && (float) $w->hours) {
                Task::whereKey($w->task_id)->decrement('act_h', (float) $w->hours);
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Task::class, 'task_id');
    }
}
