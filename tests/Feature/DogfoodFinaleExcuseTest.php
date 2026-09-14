<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\DailyWorkCompliance;
use App\Support\Workday;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **الخاتمة — العذرُ المعتمَدُ يُدين صاحبَه: انقلابُ الاعتماد.**
 *
 * بلّغ المالكُ في يومِ الخاتمة أنّ إجازةً اعتمدها الساعةَ 07:59 ظهرت «إجازة» في
 * مكانٍ و«**غائبٌ بلا عذر**» في آخر. تتبّعتُ الشرطَ فكان أدقَّ وأسوأَ ممّا وُصف:
 * ليست شاشتين تختلفان على سجلٍّ واحد، بل **ثلاثةُ تعاريفَ لـ«في إجازةٍ اليوم»**
 * في ثلاثةِ مواضع، وبينها انقلابٌ في المعنى.
 *
 *  1. `Workday::onLeave` و`resolveMany`: `status = 'معتمد'` **بالضبط** + النوعُ من
 *     `hub.leave.deduct_types` — **ثلاثةُ أنواعٍ فقط** (سنوية · مرضية · طارئة).
 *  2. `DailyWorkCompliance::excuseTypes()`: الثلاثةُ **زائدَ** «إذن خروج» و«عمل عن
 *     بعد» — ووثيقتُها تقول صراحةً إنّها الأنواعُ التي **تفسّر غيابَ اليوم**.
 *     ولا تُستشار إلّا للطلبِ **قيدَ القرار**.
 *  3. `CeoController`: `status LIKE '%معتمد%'` و**بلا أيِّ تصفيةِ نوع** — فطلبُ
 *     «سلفة» أو «شهادة راتب» معتمدٌ يجعل صاحبَه «في إجازةٍ اليوم» على لوحةِ المالك.
 *
 * **والانقلاب:** «إذن خروج» **قيدَ القرار** ⇒ «بانتظار قرار» (أغلقته الجولة 2 · G12،
 * ووثّقته بأنّ مراسلةَ المعذورِ كغائبٍ «ظلمٌ وبيانٌ كاذب»). وهو نفسُه **بعد
 * الاعتماد** ⇒ «غائبٌ بلا عذر». فالمنتجُ يعاقب الموظّفَ على أنّ مديرَه **وافق**:
 * ما دام الطلبُ معلّقاً فهو معذور، فإذا اعتُمد صار مُداناً. والأسوأُ أنّ كنسَ
 * نهايةِ اليوم (`Workday::close`) يقرأ `onLeave` نفسَها، فيختم غيابَه في السجلِّ
 * الدائم — والإدانةُ تصير صفّاً لا عرضاً.
 *
 * **وحدُّ العلاج:** «نوعُ الخصم» سؤالُ **رصيدٍ ورواتب** لا سؤالُ حضور، فلا يُوسَّع.
 * والعلاجُ فئةٌ صريحةٌ ثالثة — **«مأذون»** — لمن له عذرٌ معتمَدٌ ليس إجازةَ خصم:
 * فلا هو غائبٌ بلا عذر، ولا هو في إجازةٍ تُسقط عنه تقريرَ اليوم. و«عمل عن بعد»
 * خصوصاً **يبقى التقريرُ مطلوباً منه** — من يعمل من بيته يعمل.
 */
class DogfoodFinaleExcuseTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    /** موظفٌ نشطٌ مربوطٌ بمستخدم (نمطُ DogfoodR2AttendanceTest) */
    protected function linkedEmployee(string $name, array $extra = []): array
    {
        $role = Role::firstOrCreate(['name' => 'موظّفٌ منفّذ'],
            ['scope' => 'all', 'flags' => [],
             'matrix' => ['updates' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0], 'tasks' => ['v' => 1]]]);
        $u = User::create(['name' => $name, 'email' => Str::random(10) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $e = Employee::create(array_merge(['name' => $name, 'status' => 'نشط', 'user_id' => $u->id], $extra));

        return [$u, $e];
    }

    protected function request(Employee $e, string $type, string $status, string $from, ?string $to = null): LeaveRequest
    {
        return LeaveRequest::create(['emp_id' => $e->id, 'type' => $type,
            'date_from' => $from, 'date_to' => $to ?? $from, 'days' => 1, 'status' => $status]);
    }

    protected function roll(): array
    {
        return DailyWorkCompliance::rollCall(
            Employee::whereNull('deleted_at')->where('status', 'نشط')
                ->orderBy('id')->get(['id', 'name', 'dept', 'user_id'])
        );
    }

    /** أسماءُ فئةٍ من النداء */
    protected function names(array $roll, string $bucket): array
    {
        return array_column($roll['buckets'][$bucket] ?? [], 'name');
    }

    /* ═══════════ 1) الانقلاب: الاعتمادُ لا يجوز أن يسوء بصاحبه ═══════════ */

    public function test_approving_an_exit_permit_must_not_turn_it_into_an_unexcused_absence(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_start', '08:00');
        Carbon::setTestNow(Carbon::parse('2026-09-14 09:30:00', config('app.timezone')));

        [, $huda] = $this->linkedEmployee('هدى صاحبةُ الإذنِ المعتمَد');
        [, $plain] = $this->linkedEmployee('غائبٌ بلا طلبٍ البتّة');

        // الحالةُ الأولى: الطلبُ ما زال قيدَ القرار — أغلقتها G12 وتبقى خضراء
        $req = $this->request($huda, 'إذن خروج', 'موافقة المدير', '2026-09-14');
        $before = $this->roll();
        $this->assertContains($huda->name, $this->names($before, 'pending'),
            'الطلبُ قيدَ القرارِ «بانتظار قرار» — حارسُ G12 قائم');
        $this->assertNotContains($huda->name, $this->names($before, 'absent'));

        // ثمّ اعتمدته الموارد البشرية — ولا شيءَ آخرَ تغيّر في الدنيا
        $req->update(['status' => 'معتمد']);
        $after = $this->roll();

        $this->assertNotContains($huda->name, $this->names($after, 'absent'),
            'الاعتمادُ جعلها «غائبةً بلا عذر» — فالموافقةُ عقوبة. وهذا عينُ الانقلاب.');
        $this->assertContains($huda->name, $this->names($after, 'excused'),
            'المعذورُ بعذرٍ معتمَدٍ له فئتُه الصريحة: «مأذون»');
        $this->assertContains($plain->name, $this->names($after, 'absent'),
            'ومن لا طلبَ له يبقى غائباً — العلاجُ لا يُعمي النداءَ عن الغياب الحقيقيّ');
        $this->assertSame(1, $after['n']['absent'], 'عدّادُ «غائب بلا عذر» يُحصي الغائبَ وحدَه');
    }

    /* ═══════════ 2) «مأذون» ليست «في إجازة»: الرصيدُ والتقريرُ لا يُمسّان ═══════════ */

    public function test_an_excused_employee_is_not_on_leave_and_still_owes_a_report(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_start', '08:00');
        $this->hubSetting('work.report_required', '1');
        Carbon::setTestNow(Carbon::parse('2026-09-14 09:30:00', config('app.timezone')));

        [, $remote] = $this->linkedEmployee('كريمٌ يعمل عن بعد');
        [, $annual] = $this->linkedEmployee('ريمٌ في إجازةٍ سنوية');

        $this->request($remote, 'عمل عن بعد', 'معتمد', '2026-09-14');
        Employee::whereKey($annual->id)->update(['leave_bal' => 30]);   // حارسُ الرصيدِ قائمٌ ومحترَم
        $this->request($annual, 'إجازة سنوية', 'معتمد', '2026-09-14');

        $cRemote = DailyWorkCompliance::resolve($remote);
        $cAnnual = DailyWorkCompliance::resolve($annual);

        // «نوعُ الخصم» سؤالُ رصيدٍ لا سؤالُ حضور — فلا يُوسَّع بحجّةِ العذر
        $this->assertFalse($cRemote['on_leave'],
            '«عمل عن بعد» ليس إجازةَ خصم — توسيعُ deduct_types يُفسد الرصيدَ والرواتب');
        $this->assertTrue($cAnnual['on_leave'], 'والإجازةُ السنويّةُ تبقى إجازةً كما كانت');

        $this->assertTrue($cRemote['excused'], 'وهو مع ذلك **معذور** — الحقيقتان متعامدتان');
        $this->assertSame('عمل عن بعد', $cRemote['excuse_type']);

        // ومن يعمل من بيته يعمل: التقريرُ يبقى مستحقّاً عليه
        $this->assertTrue($cRemote['report_required'],
            'العذرُ عن الحضورِ الفيزيائيّ ليس إعفاءً من تقريرِ اليوم');
        $this->assertFalse($cAnnual['report_required'], 'وصاحبُ الإجازةِ وحدَه يُعفى');

        // ولا تُخلَط الفئتان في النداء
        $roll = $this->roll();
        $this->assertContains($remote->name, $this->names($roll, 'excused'));
        $this->assertContains($annual->name, $this->names($roll, 'leave'));
        $this->assertNotContains($remote->name, $this->names($roll, 'leave'));
    }

    /* ═══════════ 3) كنسُ نهايةِ اليوم لا يختم المعذورَ غائباً ═══════════ */

    public function test_the_nightly_sweep_does_not_stamp_an_excused_employee_absent(): void
    {
        $this->seedCore();
        $this->hubSetting('cost.weekend', '5,6');

        [, $huda] = $this->linkedEmployee('هدى صاحبةُ الإذنِ المعتمَد');
        [, $plain] = $this->linkedEmployee('غائبٌ بلا طلبٍ البتّة');

        // 2026-09-14 اثنينٌ: يومُ عملٍ لا عطلةٌ أسبوعية
        $this->assertNotContains((int) date('N', strtotime('2026-09-14')), [5, 6]);
        $this->request($huda, 'إذن خروج', 'معتمد', '2026-09-14');

        Workday::close('2026-09-14');

        $this->assertSame(0, Attendance::where('emp_id', $huda->id)->where('status', Workday::ABSENT)->count(),
            'الكنسُ ختم المعذورَ غائباً في السجلِّ الدائم — والإدانةُ صارت صفّاً لا عرضاً');
        $this->assertSame(1, Attendance::where('emp_id', $plain->id)->where('status', Workday::ABSENT)->count(),
            'ومن لا عذرَ له يُختم — الكنسُ يبقى يعمل');
    }

    /* ═══════════ 4) الشاشتان تقولان الشيءَ نفسَه عن السجلِّ نفسِه ═══════════ */

    public function test_the_owner_board_and_the_roll_call_never_contradict_each_other(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_start', '08:00');
        Carbon::setTestNow(Carbon::parse('2026-09-14 09:30:00', config('app.timezone')));

        [, $huda] = $this->linkedEmployee('هدى صاحبةُ الإذنِ المعتمَد');
        [, $loan] = $this->linkedEmployee('من طلب سلفةً لا إجازة');

        $this->request($huda, 'إذن خروج', 'معتمد', '2026-09-14');
        $this->request($loan, 'سلفة', 'معتمد', '2026-09-14');

        $onLeaveNames = DailyWorkCompliance::onLeaveToday('2026-09-14')->pluck('name')->all();
        $roll = $this->roll();

        // طلبُ السلفةِ ليس غياباً ولا إجازة — ولا يجوز أن يظهر صاحبُه «في إجازة»
        $this->assertNotContains($loan->name, $onLeaveNames,
            '«سلفة» معتمدةٌ كانت تجعل صاحبَها «في إجازةِ اليوم» على لوحةِ المالك');
        $this->assertContains($loan->name, $this->names($roll, 'absent'),
            'ولا عذرَ له عن الحضور — فهو غائبٌ في النداء، والشاشتان متّفقتان');

        // ولا سجلٌّ واحدٌ يُقرأ «إجازة» هنا و«غائبٌ بلا عذر» هناك
        foreach ($this->names($roll, 'absent') as $absent) {
            $this->assertNotContains($absent, $onLeaveNames,
                "«{$absent}» غائبٌ بلا عذرٍ في النداءِ و«في إجازة» على اللوحة — مصدران لحقيقةٍ واحدة");
        }
    }

    /* ═══════════ 5) عقدُ «أين يسري هذا الإعداد؟» لا يُسقِط مجالاً ═══════════ */

    /**
     * **مفتاحٌ واحدٌ يخدم مجالين، وموثَّقٌ لمجالٍ واحد** (الخاتمة · X2).
     *
     * `sec.hours_start` موصوفٌ في سجلِّ الإعدادات بوصفِه ضابطاً **أمنيّاً** بحتاً —
     * نافذةُ الوضعِ الصارمِ ووسمُ الدخولِ الباكر — ويسمّي قارئَين اثنين. وهو في
     * الحقيقةِ **ساعةُ دوامِ الشركة**: يقرؤه `Workday::checkIn` ليُقرّر من تأخّر،
     * و`DailyWorkCompliance::rollCall` ليُقرّر متى يبدأ إعلانُ الغياب.
     *
     * فمن يُرخي النافذةَ الأمنيّةَ إلى «00:00» — وهو فعلٌ يصفه السجلُّ ويُبرّره —
     * يُصيّر **كلَّ** ختمِ حضورٍ «متأخراً» بلا رسالةِ خطأٍ واحدة، فيُتلف سجلَّ
     * الانضباطِ كلَّه وهو يظنّ أنّه مسّ الأمنَ وحدَه.
     *
     * وحقلُ `where` في هذا السجلّ **عقدٌ** لا تعليق: بُني ليعرف المسؤولُ ما الذي
     * يمسّه قبل أن يمسّه. وإسقاطُه مجالاً كاملاً يجعل العقدَ يكذب — وهذا الاختبارُ
     * يحرسه من أن يعود صامتاً.
     */
    public function test_the_work_start_setting_declares_its_attendance_readers_not_only_its_security_ones(): void
    {
        $def = $this->findSettingDef('sec.hours_start');
        $this->assertNotNull($def, 'مفتاحُ بدايةِ الدوامِ مفقودٌ من سجلِّ الإعدادات');

        $where = (string) ($def['where'] ?? '');
        $effect = (string) ($def['effect'] ?? '');

        foreach (['Workday' => 'وسمُ التأخّر في ختمِ الحضور',
                  'DailyWorkCompliance' => 'نداءُ اليوم وحدُّ «لم يبدأ الدوام»'] as $reader => $why) {
            $this->assertStringContainsString($reader, $where,
                "«أين يسري» لا يذكر {$reader} — و{$why} يقرأ هذا المفتاح. "
                . 'فمن يضبطه أمنيّاً يُصيب الحضورَ وهو لا يعلم.');
        }

        // والأثرُ يُشرح بلغةِ مَن يقرأ: «متأخّر» و«حضور» لا «نافذةُ طلبات» وحدَها
        $this->assertTrue(str_contains($effect, 'متأخر') || str_contains($effect, 'التأخّر'),
            'شرحُ الأثرِ لا يذكر وسمَ التأخّر إطلاقاً — وهو أوسعُ أثرَيه وأخفاهما');
    }

    /** يجد تعريفَ مفتاحٍ في سجلِّ الإعدادات أيّاً كانت مجموعتُه */
    protected function findSettingDef(string $key): ?array
    {
        foreach ((array) config('hub_settings.groups', []) as $keys) {
            if (isset($keys[$key]) && is_array($keys[$key])) return $keys[$key];
        }

        return null;
    }
}
