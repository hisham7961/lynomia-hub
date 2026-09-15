<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مجلسُ الخبراء — انقسامُ السلطة على حقيقةٍ واحدة (A-1 · A-3).**
 *
 * كشف الخبيران ٠١ و٠٢ أنّ عيبَ «المصدرَين» الذي أُغلق في v2.499.0 لم يكن حادثةً
 * منفردةً بل **صنفاً**: تُكتب السلطةُ المركزيّةُ صحيحةً، ثمّ يكتب قارئٌ تعريفَه
 * الخاصَّ **حين كان ذلك التعريفُ صحيحاً**، فينزلق المعنى تحته ويبقى القارئُ
 * على حاله. وتحقّقتُ من الحالتين أدناه في الشيفرةِ بنفسي قبل كتابةِ الاختبار.
 *
 * **A-1 — «هل المهمّةُ مفتوحة؟»**
 * السلطةُ `hub_open_scope` تُعالج الفراغَ صراحةً: `whereNull($col)->orWhereNotIn(…)`
 * — فمهمّةٌ بلا حالةٍ **مفتوحة**. وصحّةُ المشروع (`helpers.php:2597`) تستعمل
 * `whereNotIn` عارياً، و**`NULL NOT IN (…)` لا يصدُق أبداً في SQL** — فالمهمّةُ
 * **تختفي** من عدّادِ المتأخّر. و`tasks.status` عمودٌ يقبل الفراغَ والنموذجُ
 * العامُّ يعرض خياراً فارغاً، فالحالةُ واقعةٌ لا نظريّة: تقول الصحّةُ «٠ متأخرة
 * من ١٢» ومهامُّ المشروعِ كلُّها فائتةُ الموعد.
 *
 * **A-3 — «من في إجازة؟» — وهو العيبُ الذي أُغلق ونجا هنا**
 * `hub_capacity` (`helpers.php:3709`) يقرأ `status = 'معتمد'` **بلا أيِّ تصفيةِ
 * نوعٍ إطلاقاً** — فطلبُ «سلفة» أو «شهادة راتب» معتمدٌ **يخصم أيّامَ عملٍ من
 * طاقةِ الموظّف**. وهذا حرفيّاً ما أُصلح في لوحةِ المالكِ قبل دفعةٍ واحدة، فأغلقتُ
 * المثالَ ولم أُغلق الصنف. ويزيد عليه أنّ `whereDate('date_to','>=',…)` **يُسقط
 * `NULL`**، فإجازةٌ مفتوحةُ النهايةِ لا تُخصم من الطاقةِ البتّة.
 *
 * والأثرُ ليس عرضاً: `/capacity` و`ExecutionStats::loadBalance` وبطاقةُ «فوق
 * طاقته — أجّل أو وزّع أو وظّف» كلُّها تقرأ هذا الرقم، فيُوزَّع العملُ على أساسه.
 */
class CouncilAuthoritySplitTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    protected function linkedEmployee(string $name): array
    {
        $role = Role::firstOrCreate(['name' => 'موظّفٌ منفّذ'],
            ['scope' => 'all', 'flags' => [],
             'matrix' => ['updates' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0], 'tasks' => ['v' => 1]]]);
        $u = User::create(['name' => $name, 'email' => Str::random(10) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $e = Employee::create(['name' => $name, 'status' => 'نشط', 'user_id' => $u->id]);

        return [$u, $e];
    }

    /* ═══════════ A-1 — المهمّةُ بلا حالةٍ لا تختفي من عدّادِ المتأخّر ═══════════ */

    public function test_a_status_less_overdue_task_is_counted_late_by_project_health(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00', config('app.timezone')));

        $p = Project::create(['name' => 'مشروعُ اختبارِ الانقسام', 'status' => 'جاري']);

        // مهمّتان فائتتا الموعد: واحدةٌ بحالةٍ مصرَّحٍ بها وأخرى **بلا حالة**
        Task::create(['project_id' => $p->id, 'title' => 'مهمّةٌ لها حالة',
            'status' => 'قيد التنفيذ', 'due' => '2026-09-01']);
        Task::create(['project_id' => $p->id, 'title' => 'مهمّةٌ بلا حالة',
            'status' => null, 'due' => '2026-09-01']);

        // السلطةُ المركزيّةُ تعدّهما مفتوحتين معاً
        $open = hub_open_scope(Task::query()->where('project_id', $p->id))->count();
        $this->assertSame(2, $open, 'hub_open_scope يعدّ المهمّةَ بلا حالةٍ مفتوحةً — وهو التعريفُ المعتمَد');

        $h = hub_project_health($p->id, true);
        $note = collect($h['factors'] ?? [])->firstWhere('k', 'انضباط المهام')['note'] ?? '';

        $this->assertStringContainsString('2 متأخرة', $note,
            'صحّةُ المشروعِ أسقطت المهمّةَ بلا حالةٍ من عدّادِ المتأخّر — '
            . '«NULL NOT IN (…)» لا يصدُق في SQL. فالمشروعُ يبدو أصحَّ ممّا هو، '
            . 'والرقمُ الكاذبُ أخطرُ من الفجوةِ الظاهرة.');
    }

    /* ═══════════ A-3 — الطاقةُ لا تخصم طلباً إداريّاً وتخصم إجازةً مفتوحة ═══════════ */

    public function test_capacity_does_not_deduct_an_administrative_request_that_is_not_a_leave(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00', config('app.timezone')));

        [, $loanEmp] = $this->linkedEmployee('من طلب سلفةً لا إجازة');

        // «سلفة» معتمدة — طلبٌ إداريٌّ لا يُغيّب صاحبَه عن العمل يوماً واحداً
        LeaveRequest::create(['emp_id' => $loanEmp->id, 'type' => 'سلفة',
            'date_from' => '2026-09-14', 'date_to' => '2026-09-18', 'days' => 5, 'status' => 'معتمد']);

        $cap = hub_capacity('2026-09-14', '2026-09-18');
        $row = collect($cap['rows'] ?? [])->firstWhere('id', $loanEmp->id);

        $this->assertNotNull($row, 'الموظّفُ غائبٌ عن جدولِ الطاقة');
        $this->assertSame(0.0, (float) ($row['leaveDays'] ?? 0),
            '«سلفة» معتمدةٌ خصمت أيّامَ عملٍ من الطاقة — والطلبُ الإداريُّ لا يُغيّب أحداً. '
            . 'وهذا عينُ عيبِ لوحةِ المالكِ الذي أُغلق في v2.499.0 وقد نجا هنا.');
    }

    public function test_capacity_deducts_an_open_ended_approved_leave(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00', config('app.timezone')));

        [, $emp] = $this->linkedEmployee('من له إجازةٌ مفتوحةُ النهاية');

        // **يُدرَج مباشرةً عمداً** — والسببُ يستحقّ التصريح: خطّافُ `saving` في
        // `LeaveRequest` يملأ `date_to` من `date_from` حين يكون فارغاً، فالحالةُ
        // **لا تنشأ عبر النموذج**. لكنّ النموذجَ نفسَه يعترف في حارسِ التداخل
        // بأنّ «صفّاً قائماً بـ`date_to` فارغ» واردٌ (صفوفٌ قديمةٌ أو مستورَدة)
        // ويدافع عنه بـ`COALESCE`. فكانت الطاقةُ **القارئَ الوحيدَ الذي لا
        // يدافع** — وهذا الاختبارُ يحرس اتّساقَ الدفاعِ لا حالةً نظريّة.
        \Illuminate\Support\Facades\DB::table('leave_requests')->insert([
            'id' => (string) Str::uuid(), 'emp_id' => $emp->id, 'type' => 'إجازة مرضية',
            'date_from' => '2026-09-14', 'date_to' => null, 'days' => 5, 'status' => 'معتمد',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $cap = hub_capacity('2026-09-14', '2026-09-18');
        $row = collect($cap['rows'] ?? [])->firstWhere('id', $emp->id);

        $this->assertNotNull($row, 'الموظّفُ غائبٌ عن جدولِ الطاقة');
        $this->assertGreaterThan(0.0, (float) ($row['leaveDays'] ?? 0),
            'إجازةٌ مفتوحةُ النهايةِ لم تُخصَم من الطاقة — `whereDate(date_to,>=)` يُسقط NULL. '
            . 'فالنظامُ يحسب طاقةَ موظّفٍ في إجازةٍ مرضيّةٍ مفتوحةٍ كأنّه حاضرٌ كلَّ الأسبوع.');
    }
}
