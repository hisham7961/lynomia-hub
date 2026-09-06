<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\WorkUpdate;
use App\Support\ExecutionStats;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **تحليلاتُ التنفيذ (WP-8.4): أرقامٌ تطابق بذورَها، لا أرقامٌ «تبدو معقولة».**
 *
 * ما يحرسه هذا الملف:
 *  ١) §6.5 — مخطّطٌ/منجَزٌ/مفتوحٌ/متأخّرٌ/متوقّفٌ/الإنجاز٪/الالتزام٪/الإنتاجية،
 *     كلُّ رقمٍ محسوبٌ بيدنا من بذرةٍ معلومة؛ و«المتأخّر» **رقمٌ واحد** يراه
 *     إسقاطُ المنشأة (`org`) ولوحُ التنفيذ معاً — لا عدّادان يتباعدان.
 *  ٢) **تعديلٌ بعد الإنجاز لا يقلب «في الموعد»**: الالتزامُ من `completed_at`
 *     لا من `updated_at` — وإلا فأيُّ لمسةٍ لاحقةٍ تُفسد تاريخَ الإنجاز.
 *  ٣) §6.6 — جدولُ المشاريع **بالجملة**: ميزانيةُ استعلاماته لا تنمو مع عدد
 *     المشاريع (`hub_project_health` لكل صفٍّ = سبعةُ استعلاماتٍ لكلّ مشروع).
 *  ٤) §6.7 — تدفّقُ المهامّ: أُنشئت/أُنجزت/أُعيد فتحُها/متأخّرة/زمنُ الدورة
 *     (وسيطٌ بجانب المتوسّط ووسمُ عيّنة، بشكل `Delivery::leadTime`)/الإنتاجية.
 *  ٥) §6.8 — جودةُ التذاكر، **وتصحيحُ عيبٍ حيّ**: `/support` كانت تعدّ «المفتوحة»
 *     من صفحةٍ محدودةٍ بستّين صفّاً، فمنشأةٌ عليها ٦٥ تذكرةً مفتوحة تقرأ ٦٠.
 *  ٦) لا بيانات ⇒ **حالةٌ فارغةٌ صادقة**: `null` لا صفرٌ ولا ١٠٠٪.
 */
class ExecutionAnalyticsTest extends TestCase
{
    /** بذرةُ §6.5: خمسُ مهامّ بمواعيدَ وأختامِ إنجازٍ معلومة */
    private function seedTasks(): void
    {
        // أ) مستحقّةٌ اليوم وأُنجزت أمسَ ساعةً — داخل النافذة وفي الموعد
        Task::create(['title' => 'أ — أُنجزت في الموعد', 'status' => 'منجزة',
            'due' => now()->toDateString(), 'completed_at' => now()->subHour()]);
        // ب) كان موعدُها قبل يومين وأُنجزت أمس — داخل النافذة ومتأخّرة
        Task::create(['title' => 'ب — أُنجزت متأخرة', 'status' => 'منجزة',
            'due' => now()->subDays(2)->toDateString(), 'completed_at' => now()->subDay()]);
        // ج) فات موعدُها ولم تُنجَز — مفتوحةٌ ومتأخّرةٌ الآن
        Task::create(['title' => 'ج — فات موعدها', 'status' => 'قيد التنفيذ',
            'due' => now()->subDay()->toDateString()]);
        // د) متوقّفةٌ بلا موعد — مفتوحةٌ ومتوقّفة، وليست متأخّرة
        Task::create(['title' => 'د — متوقفة', 'status' => 'متوقفة']);
        // هـ) موعدُها بعد عشرة أيام — مفتوحةٌ خارج خطّة النافذة
        Task::create(['title' => 'هـ — لاحقة', 'status' => 'جديدة',
            'due' => now()->addDays(10)->toDateString()]);
    }

    /* ────────── ١) §6.5 عدّاداتُ التنفيذ ────────── */

    public function test_execution_summary_matches_the_seeded_tasks(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $this->seedTasks();

        $r = hub_range();                       // ٧ أيام تنتهي الآن
        $x = ExecutionStats::executionSummary($r);

        // المخطَّط = ما موعدُه داخل أيام النافذة (أ، ب، ج) — و«هـ» بعدها و«د» بلا موعد
        $this->assertSame(3, $x['planned']['n'], 'المخطَّط = المهامُّ المستحقّة داخل النافذة');
        $this->assertSame(2, $x['planned']['done'], 'أُنجز من المخطَّط: أ وب');
        $this->assertSame(67, $x['planned']['pct'], 'الإنجاز٪ = ٢ ÷ ٣');

        $this->assertSame(2, $x['completed'], 'المنجَزُ في النافذة من ختم completed_at');
        $this->assertSame(3, $x['open'], 'المفتوحُ الآن: ج ود وهـ');
        $this->assertSame(1, $x['overdue'], 'المتأخّرُ الآن: ج وحدَها');
        $this->assertSame(1, $x['blocked'], 'المتوقّفُ الآن: د وحدَها');

        // الالتزامُ من المنجَز في النافذة: أ في الموعد، ب متأخّرة
        $this->assertSame(2, $x['on_time']['with_due']);
        $this->assertSame(1, $x['on_time']['on_time']);
        $this->assertSame(50, $x['on_time']['pct']);

        // الإنتاجيةُ = المنجَزُ ÷ أيام النافذة — لا رقمٌ مخترع
        $this->assertEqualsWithDelta(2 / 7, $x['throughput']['per_day'], 0.01);
        $this->assertSame(2, $x['throughput']['n']);

        // **رقمٌ واحد لا رقمان:** «المتأخّر» هنا هو نفسُه في إسقاط المنشأة
        $this->assertSame(ExecutionStats::org($r, $this->owner)['overdue'], $x['overdue'],
            'عدّادان للمتأخّر يتباعدان — التعريفُ واحد');
    }

    /* ────────── ٢) التعديلُ بعد الإنجاز لا يقلب الالتزام ────────── */

    public function test_editing_a_task_after_completion_does_not_flip_on_time(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        // مهمّةٌ موعدُها قبل ثلاثة أيام وأُنجزت في يومها — التزامٌ ١٠٠٪
        $t = Task::create(['title' => 'أُنجزت في موعدها', 'status' => 'منجزة',
            'due' => now()->subDays(3)->toDateString(), 'completed_at' => now()->subDays(3)]);

        $r = hub_range();
        $this->assertSame(100, ExecutionStats::executionSummary($r)['on_time']['pct']);

        // تعديلٌ لاحقٌ اليوم (عنوانٌ فقط) — يُحرّك updated_at ولا يمسّ completed_at
        $t->update(['title' => 'أُنجزت في موعدها — بعنوانٍ محرَّر']);
        $this->assertTrue($t->fresh()->updated_at->isToday(), 'التعديلُ لم يُحرّك updated_at أصلاً');

        $after = ExecutionStats::executionSummary($r);
        $this->assertSame(100, $after['on_time']['pct'],
            'التعديلُ بعد الإنجاز قلب «في الموعد» — الالتزامُ يُقاس من completed_at لا من updated_at');
        $this->assertSame(1, $after['completed'], 'والتعديلُ لم يُخرج المهمّة من نافذة إنجازها');
    }

    /* ────────── ٣) §6.6 جدولُ المشاريع بالجملة ────────── */

    public function test_project_table_is_bulk_and_its_query_budget_does_not_grow(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $seed = function (int $n, string $tag) {
            for ($i = 1; $i <= $n; $i++) {
                $p = Project::create(['name' => "$tag$i", 'status' => 'قيد التنفيذ',
                    'manager_id' => $this->employee->id, 'progress' => 40]);
                Task::create(['title' => "متأخرة $tag$i", 'status' => 'قيد التنفيذ',
                    'project_id' => $p->id, 'due' => now()->subDays(2)->toDateString()]);
                Task::create(['title' => "منجزة $tag$i", 'status' => 'منجزة',
                    'project_id' => $p->id, 'due' => now()->subDay()->toDateString(),
                    'completed_at' => now()->subDay()]);
                WorkUpdate::create(['project_id' => $p->id, 'done' => 'عمل',
                    'problems' => 'انتظار المورد', 'created_at' => now()->subHour()]);
            }
        };

        $r = hub_range();
        $seed(3, 'مشروع');
        ExecutionStats::projectExecution($r);                    // تسخينُ الإعدادات والخبيئة

        DB::flushQueryLog();
        DB::enableQueryLog();
        $small = ExecutionStats::projectExecution($r);
        $qSmall = count(DB::getQueryLog());
        DB::disableQueryLog();

        $seed(27, 'إضافي');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $big = ExecutionStats::projectExecution($r);
        $qBig = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($qSmall, $qBig,
            "ميزانيةُ الاستعلامات نمت مع المشاريع ($qSmall ⟵ $qBig) — الجدولُ يُجمَّع بالجملة لا صفّاً صفّاً");
        $this->assertLessThanOrEqual(8, $qBig, 'جدولُ المشاريع تجميعاتٌ معدودة لا استعلامٌ لكل مشروع');
        $this->assertSame(3, count($small['rows']));
        $this->assertSame(30, count($big['rows']));

        // والصفُّ يقول ما تقوله استعلاماتُه المرجعية
        $row = collect($big['rows'])->firstWhere('name', 'مشروع1');
        $this->assertNotNull($row, 'صفُّ المشروع غائب');
        $this->assertSame('موظفة', $row['owner'], 'المالكُ من manager_id');
        $this->assertSame('قيد التنفيذ', $row['status']);
        $this->assertSame(2, $row['tasks']);
        $this->assertSame(1, $row['completed'], 'المنجَزُ في النافذة');
        $this->assertSame(1, $row['overdue'], 'المتأخّرُ الآن');
        $this->assertSame(1, $row['blockers'], 'المعوّقاتُ من work_updates.problems');
        // الخطرُ أعلامٌ معدودةٌ بقاعدةٍ موصوفة — لا درجةٌ مركّبةٌ مخترعة (§6.1)
        $this->assertNotSame('', (string) $big['risk_rule'], 'قاعدةُ الخطر غيرُ موصوفة');
        $this->assertSame(2, $row['risk']['n'], 'علَمان: مهامُّ متأخّرة ومعوّقاتٌ مبلَّغة');
        $this->assertSame('high', $row['risk']['sev']);
    }

    /* ────────── ٤) §6.7 تدفّقُ المهامّ ────────── */

    public function test_task_flow_counts_reopened_and_reports_median_beside_mean(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        // ثلاثُ مهامّ أُنجزت في النافذة بأزمنةِ دورةٍ ٢ و٤ و١٢ يوماً
        foreach ([2, 4, 12] as $i => $d) {
            $t = Task::create(['title' => 'دورة ' . $d, 'status' => 'منجزة',
                'due' => now()->toDateString(), 'completed_at' => now()->subHours($i + 1)]);
            DB::table('tasks')->where('id', $t->id)
                ->update(['created_at' => now()->subHours($i + 1)->subDays($d)]);
        }
        // قبل ساعةٍ لا الآن: حدُّ النافذة الأعلى حصريٌّ (`< to`) فبندُ الثانية
        // نفسِها خارجُها — كما في الاستخدام الحقيقي حيث يسبق الإدخالُ القراءةَ
        Task::create(['title' => 'ما زالت مفتوحة', 'status' => 'قيد التنفيذ',
            'due' => now()->subDay()->toDateString(), 'created_at' => now()->subHour()]);

        // إعادةُ فتحٍ حقيقية: تدقيقٌ ينقل الحالةَ من منتهيةٍ إلى مفتوحة
        $re = Task::create(['title' => 'أُعيد فتحها', 'status' => 'قيد التنفيذ',
            'created_at' => now()->subHour()]);
        DB::table('audits')->insert([
            'action' => 'تعديل', 'module' => 'tasks', 'record_id' => $re->id,
            'before' => json_encode(['status' => 'منجزة'], JSON_UNESCAPED_UNICODE),
            'after' => json_encode(['status' => 'قيد التنفيذ'], JSON_UNESCAPED_UNICODE),
            'created_at' => now()->subHour(),
        ]);
        // وتغييرُ حالةٍ عاديّ (مفتوحة ⟵ مفتوحة) ليس إعادةَ فتح
        DB::table('audits')->insert([
            'action' => 'تعديل', 'module' => 'tasks', 'record_id' => $re->id,
            'before' => json_encode(['status' => 'جديدة'], JSON_UNESCAPED_UNICODE),
            'after' => json_encode(['status' => 'قيد التنفيذ'], JSON_UNESCAPED_UNICODE),
            'created_at' => now()->subHours(2),
        ]);

        $f = ExecutionStats::taskFlow(hub_range());

        // أُنشئت في النافذة: أربعٌ — وذاتُ الدورة الاثني عشر يوماً أُنشئت قبلها
        $this->assertSame(4, $f['created'], 'أُنشئت في النافذة');
        $this->assertSame(3, $f['completed']);
        $this->assertSame(1, $f['reopened']['n'], 'إعادةُ الفتح: من حالةٍ منتهيةٍ إلى مفتوحة وحدَها');
        $this->assertSame(1, $f['overdue'], 'المتأخّرُ الآن: المفتوحةُ الفائتةُ موعدَها وحدَها');

        // الوسيطُ بجانب المتوسّط — بشكل Delivery::leadTime: ٢/٤/١٢ ⇒ وسيط ٤ ومتوسط ٦
        $this->assertSame(3, $f['cycle']['n']);
        $this->assertEqualsWithDelta(4.0, $f['cycle']['median'], 0.05, 'الوسيطُ يقاوم الذيلَ الطويل');
        $this->assertEqualsWithDelta(6.0, $f['cycle']['avg'], 0.05);
        $this->assertEqualsWithDelta(2.0, $f['cycle']['best'], 0.05);
        $this->assertEqualsWithDelta(12.0, $f['cycle']['worst'], 0.05);
        $this->assertFalse($f['cycle']['capped'], 'عيّنةٌ دون السقف لا تُوسَم مسقوفة');
        $this->assertGreaterThan(0, $f['cycle']['cap'], 'سقفُ العيّنة يُعلَن كي يُقرأ الرقمُ عيّنةً');
        $this->assertEqualsWithDelta(3 / 7, $f['throughput']['per_day'], 0.01);
    }

    /* ────────── ٥) §6.8 جودةُ التذاكر + تصحيحُ عدّاد لوحة الدعم ────────── */

    public function test_ticket_quality_matches_seeds(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        // تذكرةٌ عاجلة (١س استجابة/٨س حل): رُدّ عليها بعد ساعتين وحُلّت بعد ٤ ساعات
        $late = Ticket::create(['subject' => 'خرقت الاستجابة', 'priority' => 'عاجلة',
            'status' => 'تم الحل', 'created_at' => now()->subHours(6)]);
        Comment::create(['module' => 'tickets', 'record_id' => $late->id,
            'user_id' => $this->owner->id, 'body' => 'ردّ', 'created_at' => now()->subHours(4)]);
        // أُغلقت قبل ساعتين — والختمُ وآخرُ تعديلٍ متّسقان (حدُّ النافذة حصريّ)
        $late->forceFill(['meta' => ['resolved_at' => now()->subHours(2)->toIso8601String()],
            'updated_at' => now()->subHours(2)])->saveQuietly();

        // تذكرةٌ متوسطة (٨س/٧٢س): رُدّ عليها بعد ساعةٍ وحُلّت بعد ٣ — داخل المهلتين
        $ok = Ticket::create(['subject' => 'ملتزمة', 'priority' => 'متوسطة',
            'status' => 'مغلقة', 'created_at' => now()->subHours(5)]);
        Comment::create(['module' => 'tickets', 'record_id' => $ok->id,
            'user_id' => $this->owner->id, 'body' => 'ردّ', 'created_at' => now()->subHours(4)]);
        $ok->forceFill(['meta' => ['resolved_at' => now()->subHours(2)->toIso8601String()],
            'updated_at' => now()->subHours(2)])->saveQuietly();

        // وثالثةٌ ما زالت مفتوحة — تُعَدّ «فُتحت» ولا تُقاس زمنَ حلّ
        Ticket::create(['subject' => 'ما زالت مفتوحة', 'status' => 'جديدة',
            'created_at' => now()->subHours(3)]);

        $q = ExecutionStats::ticketQuality(hub_range());

        $this->assertSame(3, $q['opened'], 'فُتحت في النافذة');
        $this->assertSame(2, $q['resolved'], 'حُلّت في النافذة');
        // العاجلةُ حُلّت في ٤ ساعاتٍ (مهلتُها ٨) فهي ملتزمةٌ بالحلّ ومتأخّرةٌ
        // بالاستجابة وحدَها — ونسبةُ SLA تُقاس بمهلة الحلّ كما تقيسها لوحةُ الدعم
        $this->assertSame(2, $q['sla']['of'], 'كلتاهما لها لحظةُ حلّ تُقاس');
        $this->assertSame(100, $q['sla']['pct'], 'كلتاهما داخل مهلة الحل');
        $this->assertEqualsWithDelta(1.5, $q['response']['avg_h'], 0.05, 'متوسّطُ أول ردّ: (٢+١)÷٢');
        $this->assertEqualsWithDelta(3.5, $q['resolution']['avg_h'], 0.05, 'متوسّطُ الحل: (٤+٣)÷٢');
        $this->assertSame(2, $q['response']['n']);
        $this->assertSame(0, $q['reopened']['total'], 'لا ارتدادَ مسجّلاً');
    }

    public function test_support_board_open_count_is_not_a_sixty_row_page(): void
    {
        $this->seedCore();

        // ٦٥ تذكرةً مفتوحة — أكثرُ من صفحة الطابور (٦٠)
        $rows = [];
        for ($i = 1; $i <= 65; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'subject' => 'تذكرة ' . $i,
                'status' => 'جديدة', 'version' => 1, 'archived' => 0,
                'created_at' => now()->subHours($i), 'updated_at' => now()->subHours($i)];
        }
        DB::table('tickets')->insert($rows);

        $kpi = $this->actingAs($this->owner)->get('/support')->assertOk()->viewData('kpi');

        $this->assertSame(65, $kpi['open'],
            'عدّادُ «تذاكر مفتوحة» يُقرأ من صفحةٍ محدودةٍ بستّين صفّاً — والرقمُ الناقصُ الذي يبدو كاملاً كذبة');
        $this->assertSame(60, $kpi['shown'], 'والمعروضُ في الطابور يُعلَن على حِدة');
        $this->assertTrue($kpi['capped'], 'وتُقال حقيقةُ أنّ الطابور مقصوص');
    }

    /* ────────── ٦) الحالةُ الفارغةُ الصادقة ────────── */

    public function test_no_data_yields_honest_nulls_not_invented_percentages(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $r = hub_range();
        $x = ExecutionStats::executionSummary($r);
        $this->assertSame(0, $x['planned']['n']);
        $this->assertNull($x['planned']['pct'], 'بلا مخطَّطٍ لا نسبةَ إنجاز — لا ٠٪ ولا ١٠٠٪');
        $this->assertNull($x['on_time']['pct'], 'بلا مهامَّ ذاتِ موعدٍ لا نسبةَ التزام');

        $f = ExecutionStats::taskFlow($r);
        $this->assertSame(0, $f['cycle']['n']);
        $this->assertNull($f['cycle']['median'], 'بلا إنجازٍ لا زمنَ دورة');

        $q = ExecutionStats::ticketQuality($r);
        $this->assertNull($q['sla']['pct'], 'بلا تذاكرَ محلولةٍ لا نسبةَ التزامٍ بـSLA');
        $this->assertNull($q['response']['avg_h']);

        $p = ExecutionStats::projectExecution($r);
        $this->assertSame([], $p['rows']);
        $this->assertFalse($p['capped']);
    }
}
