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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    // حالةُ المراجعةِ وزمنُ التقديم يُضبطان من النظام/خدمة المراجعة لا من التعبئة الجماعية
    protected $guarded = ['id', 'version', 'created_by',
        'submitted_at', 'review_status', 'reviewed_by', 'reviewed_at', 'review_feedback'];

    protected $casts = [
        'progress' => 'decimal:3',
        'hours' => 'decimal:3',
        'work_date' => 'date',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
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
            // زمنُ التقديم (§13): يُختم مرّةً عند أوّلِ حفظٍ — التقريرُ مُقدَّمٌ بإنشائه
            // (لا مفهومَ مسودّةٍ منفصل)، فيُقاس التأخّرُ عن المهلة منه لا من created_at
            if ($w->submitted_at === null && Schema::hasColumn('work_updates', 'submitted_at')) {
                $w->submitted_at = now();
            }
        });

        // ساعاتُ البند تدخل «الوقت الفعلي» على مهمته **مرةً واحدة** — فمحرّكا
        // الربحية والقدرات القائمان (يقرآن tasks.act_h) يعملان بلا عدٍّ مزدوج.
        static::created(function (self $w) {
            if (! $w->task_id || ! (float) $w->hours) return;
            self::shiftTaskHours($w->task_id, (float) $w->hours);

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
            if ($oldTask && $oldH) self::shiftTaskHours($oldTask, -$oldH);
            self::shiftTaskHours($w->task_id, (float) $w->hours);
        });

        // حذفُ البند يستردّ ساعاتِه من المهمة — فلا تبقى ساعاتٌ يتيمةُ المصدر
        static::deleted(function (self $w) {
            self::shiftTaskHours($w->task_id, -(float) $w->hours);
        });

        // **واستعادةُ المحذوف تُعيد ساعاتِه** (§72): الحذفُ الناعمُ خصمَها ولم يكن ثمّةَ
        // خطّافُ `restored` يُقابله — فالبندُ المُستعاد كان يترك المهمةَ ناقصةَ الساعات
        // أبداً (نظيرُ Project/Comment اللذين يهكّان restored). التماثلُ يُصان.
        static::restored(function (self $w) {
            self::shiftTaskHours($w->task_id, (float) $w->hours);
        });
    }

    /**
     * **نقلُ ساعاتٍ إلى «الوقت الفعلي» على مهمّة — بأمانٍ من العدم** (L2-08).
     *
     * `tasks.act_h` عمودٌ `decimal(16,3)` **يقبل `NULL`** وافتراضُه `NULL`،
     * و`increment()` تولّد `SET act_h = act_h + n` — و`NULL + n = NULL` على
     * المحرّكَين معاً. فكانت **أوّلُ** ساعةٍ تُبلَّغ على مهمّةٍ لم تُسجَّل ساعاتُها
     * يدويّاً من قبل **تُهدَر صامتة**: لا خطأ ولا تحذير، رقمٌ يُكتب فيُبتلع.
     * قِيس على قاعدةِ المحاكاة بعد شهرِ عملٍ كامل: ٥٨٠ بندَ تقريرٍ كلُّها بساعات،
     * و١٢١ مهمّةً `act_h` فيها `NULL` بلا استثناء. وما انبنى على الصفر: تكلفةُ
     * العمالةِ صفرٌ في ربحيّةِ كلِّ مشروع، و«الالتزامُ بالميزانية» يمنح ١٠٠
     * لمشروعٍ متجاوز، وقاعدةُ «⏳ الوقتُ الفعليّ تجاوز المقدّر» ميّتةٌ بنيويّاً.
     *
     * `COALESCE` تُصلح الجمع، و**أرضيّةُ الصفر** تمنع الخصمَ من العدمِ أن يُنتج
     * ساعاتٍ سالبة (بندٌ قديمٌ يُحذَف ومهمّتُه لم تمرّ بالإصلاح). و`CASE` بدل
     * `GREATEST`/`MAX` لأنّ كلتيهما لهجةُ محرّكٍ لا تعمّ الاثنين.
     *
     * ولا هجرةَ ولا ردمَ بيانات: `NULL` القائمةُ تعني «لم يُسجَّل شيء» وهو صادق —
     * وأوّلُ ساعةٍ تصلها تكتبها. والتحديثُ الجَمعيّ لا يوقظ أحداثَ النموذج،
     * تماماً كـ`increment()` التي حلّ محلَّها، فلا تدقيقَ مضاعفاً ولا نسخةَ إصدار.
     *
     * @param float $delta موجبٌ للإضافة، سالبٌ للاسترداد
     */
    private static function shiftTaskHours(?string $taskId, float $delta): void
    {
        if (! $taskId || ! $delta) return;

        // الفارقُ يُصاغ رقماً محكوماً بثلاثِ خاناتٍ كعمودِه (decimal(16,3))، فالتعبيرُ
        // يبقى حرفيّاً بلا سطحِ حقن — و`DB::raw` لا تقبل ربطاً في `update` أصلاً
        $d = number_format($delta, 3, '.', '');
        $sum = "COALESCE(act_h, 0) + ($d)";

        Task::whereKey($taskId)->update([
            'act_h' => DB::raw("CASE WHEN $sum < 0 THEN 0 ELSE $sum END"),
        ]);
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
