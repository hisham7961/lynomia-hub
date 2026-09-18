<?php

namespace Tests\Feature\UltimateReview;

use App\Models\Project;
use App\Models\Task;
use App\Models\WorkUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * **ساعاتُ التقريرِ اليوميّ: أتصل مهمّتَها؟** (المراجعةُ الشاملة · الطبقة ٢ · L2-08).
 *
 * جولةُ الساعات: الحزمةُ خضراءُ على الربحيّة، والشاشةُ تعرض تكلفةَ مشروعٍ برقمٍ
 * واثق. فسُئل السؤالُ الذي لا تسأله الحزمة: **من أين جاءت ساعاتُ العمالة؟**
 *
 * قِيس على قاعدةِ المحاكاة بعد شهرِ عملٍ كامل: **٥٨٠ بندَ تقريرٍ يوميّ، كلُّها
 * تحمل ساعاتٍ موجبة** — و**١٢١ مهمّةً `act_h` فيها `NULL` بلا استثناء**. لا صفرٌ
 * واحد، لا موجبٌ واحد: `NULL` في كلِّ صفّ.
 *
 * والسببُ سطرٌ واحد. `tasks.act_h` عمودٌ `decimal(16,3) NULL` افتراضُه `NULL`،
 * و`WorkUpdate::created` يستدعي `increment('act_h', $h)` — و`increment` تولّد
 * `SET act_h = act_h + h`، و**`NULL + 7.5 = NULL`** على المحرّكَين معاً. فكلُّ
 * ساعةٍ أبلغَ عنها موظّفٌ على مهمّةٍ لم تُسجَّل ساعاتُها يدويّاً من قبل **تُهدَر
 * صامتةً**: لا خطأ، لا تحذير، لا صفٌّ ناقص — رقمٌ يُكتب فيُبتلع.
 *
 * وما الذي انبنى على الصفر:
 *
 * - **تكلفةُ العمالةِ في ربحيّةِ كلِّ مشروع = صفر.** وهي في شركةِ خدماتٍ أكبرُ
 *   بنودِ التكلفة. (`hub_project_pl` يقرأ `SUM(act_h)`.)
 * - **«الالتزامُ بالميزانية» يمنح ١٠٠** لمشروعٍ متجاوزٍ متأخّر — ٠٪ مستهلك —
 *   فيُصبَغ «سليم». (وثّق الأثرَ تعليقُ `hub_project_pl` نفسُه دون بلوغِ سببه.)
 * - **قاعدةُ التنبيه «⏳ الوقتُ الفعليّ تجاوز المقدّر»** (`act_h > est_h` في
 *   حزمةِ البداية) **ميّتةٌ بنيويّاً** — شرطُها لا يتحقّق أبداً.
 *
 * ومسحُ الصنف أثبت أنّ الموضعَ الحيَّ واحد: كلُّ أعمدةِ `increment` الأخرى في
 * التطبيق (`opens`, `attempts`, `views`, `hits`, `runs`, `downloads`,
 * `point_count`, `failed_attempts`) **`NOT NULL DEFAULT 0`** — فالعيبُ محصورٌ
 * في `act_h` وحدَه، تصيبه خمسةُ نداءاتٍ في `WorkUpdate`.
 *
 * والعلاجُ إضافةٌ لا كسر: `COALESCE(act_h, 0)` قبل الجمع، وأرضيّةُ صفرٍ عند
 * الطرح كي لا يورّث الخصمُ من `NULL` رقماً سالباً. ولا هجرةَ ولا ردمَ بيانات:
 * `NULL` القائمةُ تعني «لم يُسجَّل شيء» وهو صادق — وأوّلُ ساعةٍ تصلها تكتبها.
 */
class HoursReachTheirTaskTest extends TestCase
{
    private function task(array $attr = []): Task
    {
        $p = Project::create(['name' => 'مشروعُ قياسِ الساعات', 'status' => 'قيد التنفيذ']);

        return Task::create(array_merge([
            'title' => 'مهمّةٌ لم تُسجَّل ساعاتُها بعد',
            'project_id' => $p->id,
            'status' => 'قيد التنفيذ',
        ], $attr));
    }

    /** العيبُ الأصل: أوّلُ ساعةٍ على مهمّةٍ `act_h` فيها NULL كانت تُبتلَع. */
    public function test_first_reported_hours_land_on_a_task_whose_actual_is_null(): void
    {
        $t = $this->task();
        $this->assertNull(DB::table('tasks')->where('id', $t->id)->value('act_h'),
            'شرطُ الاختبار: المهمّةُ تبدأ بـact_h = NULL كما تُنشئها الهجرة');

        WorkUpdate::create(['project_id' => $t->project_id, 'task_id' => $t->id,
            'done' => 'أنجزتُ الجزءَ الأول', 'hours' => 7.5]);

        $this->assertSame(7.5, (float) DB::table('tasks')->where('id', $t->id)->value('act_h'),
            'ساعاتُ البندِ لم تصل المهمّةَ — increment على عمودٍ NULL يُعيد NULL');
    }

    /** والثانيةُ تُضاف للأولى لا تحلّ محلّها. */
    public function test_a_second_report_adds_to_the_first(): void
    {
        $t = $this->task();
        WorkUpdate::create(['project_id' => $t->project_id, 'task_id' => $t->id,
            'done' => 'اليومُ الأول', 'hours' => 3]);
        WorkUpdate::create(['project_id' => $t->project_id, 'task_id' => $t->id,
            'done' => 'اليومُ الثاني', 'hours' => 4.25]);

        $this->assertSame(7.25, (float) DB::table('tasks')->where('id', $t->id)->value('act_h'),
            'البندُ الثاني لم يُضَف إلى الأول');
    }

    /** والمهمّةُ التي سُجّلت ساعاتُها يدويّاً تُبنى عليها لا تُصفَّر. */
    public function test_manually_recorded_hours_are_added_to_not_replaced(): void
    {
        $t = $this->task(['act_h' => 10]);

        WorkUpdate::create(['project_id' => $t->project_id, 'task_id' => $t->id,
            'done' => 'بندٌ فوقَ المسجَّلِ يدويّاً', 'hours' => 2.5]);

        $this->assertSame(12.5, (float) DB::table('tasks')->where('id', $t->id)->value('act_h'),
            'الساعاتُ المسجَّلةُ يدويّاً ضاعت أو حلّ البندُ محلَّها');
    }

    /** وحذفُ البندِ يستردّ ساعاتِه — لا تبقى ساعاتٌ يتيمةُ المصدر. */
    public function test_deleting_a_report_returns_its_hours(): void
    {
        $t = $this->task();
        $w = WorkUpdate::create(['project_id' => $t->project_id, 'task_id' => $t->id,
            'done' => 'بندٌ سيُحذَف', 'hours' => 5]);

        $this->assertSame(5.0, (float) DB::table('tasks')->where('id', $t->id)->value('act_h'));
        $w->delete();

        $this->assertSame(0.0, (float) DB::table('tasks')->where('id', $t->id)->value('act_h'),
            'حذفُ البندِ لم يستردّ ساعاتِه');
    }

    /** والخصمُ لا يهبط تحت الصفر ولو كان الأصلُ NULL. */
    public function test_a_decrement_from_null_never_goes_negative(): void
    {
        $t = $this->task();
        $w = WorkUpdate::create(['project_id' => $t->project_id, 'task_id' => $t->id,
            'done' => 'بندٌ سيُحذَف مرّتين منطقيّاً', 'hours' => 4]);

        // تصفيرُ العمودِ يدويّاً إلى NULL يحاكي صفّاً قديماً لم يمرّ بالإصلاح
        DB::table('tasks')->where('id', $t->id)->update(['act_h' => null]);
        $w->delete();

        $v = (float) DB::table('tasks')->where('id', $t->id)->value('act_h');
        $this->assertGreaterThanOrEqual(0.0, $v,
            'الخصمُ من NULL أنتج ساعاتٍ سالبة — وهي لا معنى لها');
    }

    /** وتعديلُ ساعاتِ بندٍ يُصالَح بالفارق على مهمّةٍ بدأت NULL. */
    public function test_editing_hours_reconciles_by_the_difference(): void
    {
        $t = $this->task();
        $w = WorkUpdate::create(['project_id' => $t->project_id, 'task_id' => $t->id,
            'done' => 'بندٌ سيُعدَّل', 'hours' => 3]);

        $w->hours = 8;
        $w->save();

        $this->assertSame(8.0, (float) DB::table('tasks')->where('id', $t->id)->value('act_h'),
            'تعديلُ الساعاتِ لم يُصالَح بالفارق');
    }

    /** ونقلُ البندِ إلى مهمّةٍ أخرى ينقل ساعاتِه معه. */
    public function test_moving_a_report_to_another_task_moves_its_hours(): void
    {
        $a = $this->task();
        $b = $this->task(['title' => 'المهمّةُ المنقولُ إليها']);

        $w = WorkUpdate::create(['project_id' => $a->project_id, 'task_id' => $a->id,
            'done' => 'بندٌ سيُنقل', 'hours' => 6]);
        $w->task_id = $b->id;
        $w->save();

        $this->assertSame(0.0, (float) DB::table('tasks')->where('id', $a->id)->value('act_h'),
            'المهمّةُ الأولى احتفظت بساعاتٍ لم تعد لها');
        $this->assertSame(6.0, (float) DB::table('tasks')->where('id', $b->id)->value('act_h'),
            'المهمّةُ الثانية لم تستلم الساعات');
    }

    /** وبندٌ بلا مهمّةٍ لا يلمس شيئاً — الحارسُ القائم يبقى. */
    public function test_a_report_without_a_task_touches_nothing(): void
    {
        $t = $this->task();
        WorkUpdate::create(['project_id' => $t->project_id,
            'done' => 'بندٌ بلا مهمّة', 'hours' => 9]);

        $this->assertNull(DB::table('tasks')->where('id', $t->id)->value('act_h'),
            'بندٌ بلا مهمّةٍ مسّ مهمّةً');
    }

    /** وصفرُ ساعاتٍ لا يُنشئ رقماً من عدم. */
    public function test_zero_hours_leave_the_task_untouched(): void
    {
        $t = $this->task();
        WorkUpdate::create(['project_id' => $t->project_id, 'task_id' => $t->id,
            'done' => 'بندٌ بلا ساعات', 'hours' => 0]);

        $this->assertNull(DB::table('tasks')->where('id', $t->id)->value('act_h'),
            'بندٌ بصفرِ ساعاتٍ كتب رقماً في المهمّة');
    }

    /**
     * **وحارسُ الصنف.** العيبُ ليس في `act_h` بل في الجمعِ على عمودٍ يقبل العدم،
     * و`act_h` مجرّدُ موضعِه الحيّ اليوم. فيُثبَّت الشرطُ نفسُه على **كلِّ** عمودٍ
     * يزيده التطبيقُ بـ`increment`: إمّا `NOT NULL` فالجمعُ عليه سليمٌ بطبعه،
     * وإمّا معالَجٌ بـ`COALESCE` كما عولج `act_h`. فإن أضيف غداً عمودٌ ثالثٌ
     * قابلٌ للعدم يُزاد عليه، سقط هذا الحارسُ **قبل** أن تُهدَر ساعاتُ الإنتاج.
     */
    public function test_every_incremented_column_is_safe_to_add_to(): void
    {
        // مواضعُ increment/decrement في app/ — تُراجَع مع كلّ موضعٍ جديد
        $sites = [
            ['tasks', 'act_h'],                     // WorkUpdate (معالَجٌ بـCOALESCE)
            ['sign_requests', 'opens'],             // EsignController
            ['account_activations', 'attempts'],    // ActivationController · MobileActivationController
            ['users', 'failed_attempts'],           // AccountLockout
            ['share_links', 'views'],               // DataRoomController
            ['track_sessions', 'point_count'],      // Tracking
            ['attachments', 'downloads'],           // AttachmentController · AttachmentService
            ['flows', 'runs'],                      // FlowRunner
            ['identity_lookups', 'hits'],           // Discovery\Engine
        ];
        $handled = ['tasks.act_h'];                 // ما يعالجه الكودُ صراحةً

        $checked = 0;
        foreach ($sites as [$table, $col]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $col)) continue;
            $meta = collect(Schema::getColumns($table))->firstWhere('name', $col);
            $this->assertNotNull($meta, "العمود {$table}.{$col} غيرُ مقروء");
            $checked++;
            if (in_array("{$table}.{$col}", $handled, true)) continue;

            $this->assertFalse((bool) ($meta['nullable'] ?? false),
                "العمود {$table}.{$col} يُزاد عليه بـincrement وهو يقبل NULL — "
                . 'فأوّلُ زيادةٍ على صفٍّ فارغٍ تُهدَر صامتةً (NULL + n = NULL). '
                . 'اجعله NOT NULL DEFAULT 0 أو عالِجه بـCOALESCE كما في WorkUpdate.');
        }

        $this->assertGreaterThanOrEqual(7, $checked,
            'لم تُفحَص مواضعُ الزيادةِ المتوقَّعة — راجع قائمةَ المواضع');
    }
}
