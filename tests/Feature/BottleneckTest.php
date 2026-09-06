<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\WorkUpdate;
use App\Support\ActionCenter;
use App\Support\ExecutionStats;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **الاختناقات (WP-7.4): قراءةٌ واحدة تجمع ما هو موجودٌ فعلاً — لا محرّكَ ثانياً.**
 *
 * ما يحرسه هذا الملف:
 *  1) مكوثُ الحالة يُحسب من تاريخِ تدقيقٍ مبذورٍ بيدنا (`audits.after.status`)
 *     — أرقامُ ساعاتٍ محسوبةٌ يدوياً لا «تبدو معقولة».
 *  2) عدّادُ إعادة الفتح `meta.reopened` يزيد حيث كان `meta.resolved_at` يُمحى
 *     بصمت — إغلاقٌ فإعادةُ فتحٍ مرتين = ٢، والقارئُ يراه.
 *  3) الراكدُ يُرصد: مهمةٌ مفتوحةٌ لم تُمَسّ منذ العتبة — والمتوقفةُ ليست راكدةً
 *     (لها بطاقةُ انتظارٍ خاصة) والمنجزةُ القديمة ليست شيئاً منهما.
 *  4) مراحلُ الانتظار من الأعمدة القائمة: اعتمادٌ `created_at→decided_at`،
 *     وتذاكرُ «بانتظار العميل»، ومهامُّ «متوقفة».
 *  5) أكثرُ المعوّقات تكراراً من `work_updates.problems` و`tasks.late_reason`.
 *  6) **الشاشتان تتّفقان:** إشاراتُ `proj.stalled`/`proj.blockers`/`sla.breach`
 *     تُقرأ من مركز الفعل نفسِه لا تُعاد حسابُها — المفاتيحُ هنا هي المفاتيحُ هناك.
 */
class BottleneckTest extends TestCase
{
    /** قيدُ تدقيقٍ مبذور — بالحدّ الأدنى من الأعمدة (كلُّ ما عداها nullable) */
    private function auditRow(string $module, string $recordId, string $status, $at, string $action = 'تعديل'): void
    {
        DB::table('audits')->insert([
            'action' => $action, 'module' => $module, 'record_id' => $recordId,
            'after' => json_encode(['status' => $status], JSON_UNESCAPED_UNICODE),
            'created_at' => $at,
        ]);
    }

    /* ────────── ١) المكوثُ من تاريخٍ مبذور ────────── */

    public function test_status_dwell_is_computed_from_seeded_audit_history(): void
    {
        $this->seedCore();
        $t = Task::create(['title' => 'مهمة المكوث', 'status' => 'متوقفة']);
        // نمسح ضجيجَ قيد الإنشاء ونبذر تاريخاً معلوماً: دخلت «قيد التنفيذ» قبل
        // ٦ أيام، ثم «متوقفة» قبل يومين — فمكوثُ الأولى ٩٦ ساعةً (فاصلٌ مغلق)
        // ومكوثُ الثانية ~٤٨ ساعةً مفتوحاً حتى الآن.
        DB::table('audits')->where('module', 'tasks')->delete();
        $this->auditRow('tasks', $t->id, 'قيد التنفيذ', now()->subDays(6), 'إضافة');
        $this->auditRow('tasks', $t->id, 'متوقفة', now()->subDays(2));

        $this->actingAs($this->owner);
        $bn = ExecutionStats::bottlenecks(hub_range());

        $rows = collect($bn['dwell']['rows']);
        $doing = $rows->first(fn ($r) => $r['module'] === 'tasks' && $r['status'] === 'قيد التنفيذ');
        $this->assertNotNull($doing, 'مكوثُ «قيد التنفيذ» غائب');
        $this->assertSame(1, $doing['n']);
        $this->assertEqualsWithDelta(96.0, $doing['avg_h'], 0.2, 'الفاصلُ المغلق ٤ أيام = ٩٦ ساعة');

        $paused = $rows->first(fn ($r) => $r['module'] === 'tasks' && $r['status'] === 'متوقفة');
        $this->assertNotNull($paused, 'مكوثُ «متوقفة» المفتوح غائب');
        $this->assertEqualsWithDelta(48.0, $paused['avg_h'], 0.2, 'المكوثُ المفتوح يُقاس حتى الآن');

        // والحالةُ **المنتهية** لا يُعدّ لها مكوثٌ مفتوح: سجلٌّ يرتاح في «منجزة»
        // ليس منتظِراً — عدُّه يجعل أقدمَ منجزةٍ «أطولَ اختناق» وهي ليست كذلك.
        $done = Task::create(['title' => 'منجزة قديمة', 'status' => 'منجزة', 'completed_at' => now()->subDays(5)]);
        DB::table('audits')->where('record_id', $done->id)->delete();
        $this->auditRow('tasks', $done->id, 'منجزة', now()->subDays(5), 'إضافة');
        $bn2 = ExecutionStats::bottlenecks(hub_range());
        $this->assertNull(collect($bn2['dwell']['rows'])
            ->first(fn ($r) => $r['status'] === 'منجزة'), 'حالةٌ منتهيةٌ عُدّ لها مكوثٌ مفتوح');
    }

    /* ────────── ٢) عدّادُ إعادة الفتح ────────── */

    public function test_reopen_counter_increments_where_resolved_at_was_silently_cleared(): void
    {
        $this->seedCore();
        $t = Ticket::create(['subject' => 'تذكرة الارتداد', 'status' => 'جديدة']);

        // إغلاقٌ يختم resolved_at
        $this->actingAs($this->owner)->post('/m/tickets/' . $t->id . '/status', ['status' => 'تم الحل']);
        $this->assertNotNull($t->fresh()->meta['resolved_at'] ?? null, 'الإغلاقُ لم يختم');

        // إعادةُ الفتح: الختمُ يُمحى (السلوكُ القائم) والعدّادُ يزيد (الجديد)
        $this->actingAs($this->owner)->post('/m/tickets/' . $t->id . '/status', ['status' => 'قيد المعالجة']);
        $m1 = $t->fresh()->meta;
        $this->assertNull($m1['resolved_at'] ?? null, 'إعادةُ الفتح لم تمحُ الختم');
        $this->assertSame(1, (int) ($m1['reopened'] ?? 0), 'أوّلُ إعادةِ فتحٍ لم تُعَدّ');

        // دورةٌ ثانية = ٢ — عدّادٌ يتراكم لا علَمٌ يُقلب
        $this->actingAs($this->owner)->post('/m/tickets/' . $t->id . '/status', ['status' => 'تم الحل']);
        $this->actingAs($this->owner)->post('/m/tickets/' . $t->id . '/status', ['status' => 'قيد المعالجة']);
        $this->assertSame(2, (int) ($t->fresh()->meta['reopened'] ?? 0));

        // والقارئُ يراه: تذكرةٌ واحدة، ومجموعُ مرات إعادة الفتح ٢
        $bn = ExecutionStats::bottlenecks(hub_range());
        $this->assertSame(1, $bn['reopened']['n']);
        $this->assertSame(2, $bn['reopened']['total']);
    }

    /* ────────── ٣) الراكدُ يُرصد ────────── */

    public function test_stalled_open_tasks_are_detected_and_paused_or_done_are_not(): void
    {
        $this->seedCore();
        $stale = Task::create(['title' => 'راكدة بلا مساس', 'status' => 'قيد التنفيذ']);
        Task::create(['title' => 'نشطة اليوم', 'status' => 'قيد التنفيذ']);
        $paused = Task::create(['title' => 'متوقفة قديمة', 'status' => 'متوقفة']);
        $done = Task::create(['title' => 'منجزة قديمة', 'status' => 'منجزة',
            'completed_at' => now()->subDays(20)]);
        // تقادمٌ عبر القاعدة مباشرة كي لا يلمس Eloquent عمودَ updated_at
        DB::table('tasks')->whereIn('id', [$stale->id, $paused->id, $done->id])
            ->update(['updated_at' => now()->subDays(10)]);

        $this->actingAs($this->owner);
        $bn = ExecutionStats::bottlenecks(hub_range());

        // الراكدة وحدَها: المتوقفةُ في بطاقة الانتظار، والمنجزةُ منتهية، والنشطةُ حديثة
        $this->assertSame(1, $bn['stalled']['n']);
        $this->assertSame('راكدة بلا مساس', $bn['stalled']['rows'][0]['title']);
        $this->assertSame(10, $bn['stalled']['rows'][0]['days']);
        $this->assertSame(1, $bn['waiting']['tasks_paused']['n'], 'المتوقفةُ تُعدّ انتظاراً لا ركوداً');
    }

    /* ────────── ٤) مراحلُ الانتظار من الأعمدة القائمة ────────── */

    public function test_waiting_stages_come_from_existing_columns(): void
    {
        $this->seedCore();
        // اعتمادٌ حُسم بعد يومين كاملين من إنشائه — ومعلّقٌ عمرُه ٥ أيام
        Approval::create(['title' => 'حُسم', 'type' => 'شراء', 'status' => 'معتمد',
            'created_at' => now()->subDays(3), 'decided_at' => now()->subDay()]);
        Approval::create(['title' => 'معلّق', 'type' => 'شراء',
            'created_at' => now()->subDays(5)]);
        Ticket::create(['subject' => 'ك١', 'status' => 'بانتظار العميل']);
        Task::create(['title' => 'م١', 'status' => 'متوقفة']);

        $this->actingAs($this->owner);
        $w = ExecutionStats::bottlenecks(hub_range())['waiting'];

        $this->assertSame(1, $w['approvals']['decided_n']);
        $this->assertEqualsWithDelta(48.0, $w['approvals']['avg_h'], 0.2, 'الانتظارُ = decided_at − created_at');
        $this->assertSame(1, $w['approvals']['pending']);
        $this->assertSame(5, $w['approvals']['oldest_days']);
        $this->assertSame(1, $w['tickets_waiting']['n']);
        $this->assertSame(1, $w['tasks_paused']['n']);
    }

    /* ────────── ٥) أكثرُ المعوّقات تكراراً ────────── */

    public function test_most_frequent_blockers_from_work_updates_and_late_reasons(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        // قبل ساعةٍ لا الآن: حدُّ النافذة الأعلى حصريٌّ (`< to`) فبندُ الثانية
        // نفسِها خارجُها — كما في الاستخدام الحقيقي حيث تُكتب التقارير قبل القراءة
        WorkUpdate::create(['done' => 'عمل', 'problems' => 'نقص مواد من المورد',
            'created_at' => now()->subHour()]);
        WorkUpdate::create(['done' => 'عمل آخر', 'problems' => '  نقص مواد من المورد ',
            'created_at' => now()->subHour()]);
        WorkUpdate::create(['done' => 'بلا مشكلة', 'created_at' => now()->subHour()]);
        $lateTask = Task::create(['title' => 'متأخرة بسبب', 'status' => 'قيد التنفيذ',
            'late_reason' => 'تأخر اعتماد العميل']);
        DB::table('tasks')->where('id', $lateTask->id)->update(['updated_at' => now()->subHour()]);

        $b = ExecutionStats::bottlenecks(hub_range())['blockers'];

        // الأكثرُ تكراراً أولاً — والمسافاتُ الزائدة لا تصنع مُعوّقاً ثانياً
        $this->assertSame('نقص مواد من المورد', $b['rows'][0]['text']);
        $this->assertSame(2, $b['rows'][0]['n']);
        $late = collect($b['rows'])->first(fn ($r) => $r['text'] === 'تأخر اعتماد العميل');
        $this->assertNotNull($late, 'سببُ تأخير المهمة غائبٌ عن المعوّقات');
        $this->assertSame(1, $late['n']);
    }

    /* ────────── ٦) الشاشتان تتّفقان مع مركز الفعل ────────── */

    public function test_the_screen_reads_action_center_signals_instead_of_recomputing(): void
    {
        $this->seedCore();

        // مشروعٌ راكد: بلا حِراكٍ (مهامَّ أو تدقيقٍ) منذ ١٠ أيام — يُمسح قيدُ
        // إنشائه لأن آخرَ الأثر يُؤخذ من التدقيق أيضاً
        $p = Project::create(['name' => 'مشروع الاختناق', 'status' => 'قيد التنفيذ']);
        DB::table('audits')->where('module', 'projects')->delete();
        DB::table('projects')->where('id', $p->id)
            ->update(['updated_at' => now()->subDays(10), 'created_at' => now()->subDays(30)]);

        // حاجبٌ مبلَّغ على المشروع نفسِه (قبل ساعةٍ — حدُّ النافذة الأعلى حصريّ)
        // + تذكرةٌ عاجلة خرقت مهلةَ حلّها (٨ ساعات)
        WorkUpdate::create(['project_id' => $p->id, 'done' => 'عمل',
            'problems' => 'انتظار المورد', 'created_at' => now()->subHour()]);
        $t = Ticket::create(['subject' => 'خرق الحل', 'priority' => 'عاجلة',
            'status' => 'جديدة', 'created_at' => now()->subDays(3)]);

        $res = $this->actingAs($this->owner)->get('/workforce/overview');
        $res->assertOk()->assertSee('مشروع الاختناق')->assertSee('انتظار المورد');
        $bn = $res->viewData('bn');

        // المفاتيحُ الظاهرة هنا هي حرفياً مفاتيحُ مركز الفعل الحيّة — لا حسابٌ ثانٍ
        $live = array_keys(ActionCenter::liveByKey());
        foreach (['proj.stalled', 'proj.blockers', 'sla.breach'] as $prefix) {
            $expected = array_values(array_filter($live, fn ($k) => str_starts_with($k, $prefix . ':')));
            $this->assertSame($expected, array_column($bn['signals'][$prefix], 'key'),
                'إشاراتُ ' . $prefix . ' لا تطابق مركزَ الفعل');
        }
        $this->assertContains('proj.stalled:' . $p->id, array_column($bn['signals']['proj.stalled'], 'key'));
        $this->assertContains('proj.blockers:' . $p->id, array_column($bn['signals']['proj.blockers'], 'key'));
        $this->assertContains('sla.breach:' . $t->id, array_column($bn['signals']['sla.breach'], 'key'));
    }
}
