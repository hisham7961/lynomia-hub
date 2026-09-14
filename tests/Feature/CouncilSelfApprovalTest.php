<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **مجلسُ الخبراء — الموظّفُ يعتمد إجازةَ نفسِه (A · خطورةٌ عالية).**
 *
 * أثبته الخبيرُ ١٤ بالمتصفّح، وتحقّقتُ من **السجلِّ المخزَّن** بنفسي قبل الإصلاح:
 *
 * ```
 * id: 6e61e674-…  ·  emp: أحمد قاسم  ·  type: إجازة سنوية
 * status: معتمد   ·  mgr_id: NULL
 * ```
 *
 * موظّفٌ بلا أيِّ رايةٍ فتح «＋ طلب إجازة» من ملفّه، فوصل إلى نموذجِ وحدةِ
 * `leaves` العامّ، **واختار «معتمد» من قائمةِ الحالة**، فحُفظ. **إجازةٌ معتمدةٌ
 * بلا معتمِد.** وزاد: أنشأ طلباً **باسمِ زميلتِه** بالحالةِ نفسِها.
 *
 * **والأثرُ ليس عرضاً:** الحالةُ «معتمد» تُشغّل خصمَ الرصيد (`syncBalance`)
 * وتجعل صاحبَها معذوراً في نداءِ اليوم. فالموظّفُ يمنح نفسَه أيّامَ إجازةٍ
 * ويُعفي نفسَه من الحضور — بنقرتين، وبلا أثرٍ يُنبّه أحداً.
 *
 * **والاختبارُ يمرّ من حيث مرّ الخبير:** `POST m/leaves` — نموذجُ الوحدةِ العامّ.
 * فحارسٌ لا يُختبَر من بابِه لا يُثبَت. والبابُ الثاني — الاستيراد — يُختبَر هنا
 * أيضاً: يكتب على النموذجِ مباشرةً بلا `fill()`، وخمسةُ أدوارٍ كاملةِ النطاقِ
 * تملك `leaves:a` بلا `hr` ولا رايةِ اعتماد.
 *
 * **وما لا يتغيّر:** الحقلُ باقٍ، والشاشةُ باقية، ومسارُ القرارِ `leaves.decide`
 * باقٍ بتسلسلِه. من يملك البتَّ يكتب الحالةَ كما كان — وذاك ما تحرسه الاختباراتُ
 * الثلاثةُ الأخيرةُ هنا: معتمِدٌ براية، وموارد بشريّة بلا راية، وبذرٌ بلا مستخدم.
 */
class CouncilSelfApprovalTest extends TestCase
{
    /** موظّفٌ عاديٌّ بلا رايات — يملك `leaves` ليطلب لنفسه */
    protected function plainEmployee(string $name): array
    {
        $role = Role::firstOrCreate(['name' => 'عضو فريق تشغيلي · مجلس'],
            ['scope' => 'all', 'flags' => [],
             'matrix' => ['leaves' => ['v' => 1, 'a' => 1, 'e' => 1], 'tasks' => ['v' => 1]]]);

        return $this->userWithRole($role, $name);
    }

    /** يبني مستخدماً وموظّفاً مرتبطين بدورٍ مُعطى */
    protected function userWithRole(Role $role, string $name, float $bal = 30): array
    {
        $u = User::create(['name' => $name, 'email' => \Illuminate\Support\Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $e = Employee::create(['name' => $name, 'status' => 'نشط', 'user_id' => $u->id, 'leave_bal' => $bal]);

        return [$u, $e];
    }

    /** حمولةُ نموذجِ الوحدةِ العامّ — الحقولُ بمفاتيحِها في `config/hub.php` */
    protected function payload(Employee $emp, string $status): array
    {
        return ['empId' => $emp->id, 'type' => 'إجازة سنوية',
            'from' => '2026-10-01', 'to' => '2026-10-05', 'days' => 5, 'status' => $status];
    }

    /** آخرُ طلبٍ لهذا الموظّف — بترتيبِ `id` لا بالقرعة */
    protected function latestFor(Employee $emp): ?LeaveRequest
    {
        return LeaveRequest::where('emp_id', $emp->id)->orderByDesc('id')->first();
    }

    public function test_an_employee_cannot_approve_their_own_leave_request(): void
    {
        $this->seedCore();
        [$user, $emp] = $this->plainEmployee('أحمد الطالبُ لنفسه');

        $this->actingAs($user)
            ->post(route('m.store', 'leaves'), $this->payload($emp, 'معتمد'))
            ->assertSessionHasNoErrors();

        $req = $this->latestFor($emp);
        $this->assertNotNull($req, 'لم يُحفَظ الطلبُ أصلاً — الحارسُ منع التقديمَ لا الاعتمادَ، وذاك نزعُ قدرة');
        $this->assertNotSame('معتمد', (string) $req->status,
            'موظّفٌ بلا رايةٍ اعتمد إجازةَ نفسِه — إجازةٌ معتمدةٌ بلا معتمِد، '
            . 'وخصمُ رصيدٍ وإعفاءٌ من الحضورِ بنقرتين.');
        $this->assertSame('مقدّم', (string) $req->status,
            'والطلبُ لا يُرفَض بل يُردّ إلى «مقدّم» — فالموظّفُ يطلب ولا يبتّ');
    }

    public function test_an_employee_cannot_approve_a_colleagues_request_either(): void
    {
        $this->seedCore();
        [$user] = $this->plainEmployee('من اعتمد لزميلتِه');
        [, $peer] = $this->plainEmployee('الزميلةُ التي اعتُمد عنها');

        $this->actingAs($user)
            ->post(route('m.store', 'leaves'), $this->payload($peer, 'معتمد'))
            ->assertSessionHasNoErrors();

        $this->assertSame('مقدّم', (string) $this->latestFor($peer)?->status,
            'زميلٌ بلا سلطةٍ اعتمد إجازةَ زميلتِه — والنصفُ الثاني من بلاغِ الخبير ١٤');
    }

    public function test_the_balance_is_not_deducted_by_a_self_approval(): void
    {
        $this->seedCore();
        [$user, $emp] = $this->plainEmployee('من حاول خصمَ رصيدِه');

        $this->actingAs($user)->post(route('m.store', 'leaves'), $this->payload($emp, 'معتمد'));

        $this->assertSame(30.0, (float) Employee::whereKey($emp->id)->value('leave_bal'),
            'الاعتمادُ الذاتيُّ خصم الرصيدَ فعلاً — فالضررُ ماليٌّ لا عرضيّ');
    }

    public function test_the_import_door_is_guarded_the_same_way(): void
    {
        $this->seedCore();
        // دورٌ كاملُ النطاقِ يملك `leaves:a` بلا `hr` ولا راية — كـ«محاسب» و«موظّف
        // مبيعات» و«مدير مشاريع» في الأدوارِ الحيّة. فهو يبلغ شاشةَ الاستيراد.
        $role = Role::create(['name' => 'مستورِدٌ بلا سلطةِ بتّ' . \Illuminate\Support\Str::random(4),
            'scope' => 'all', 'flags' => [], 'matrix' => ['leaves' => ['v' => 1, 'a' => 1, 'e' => 1]]]);
        [$user, $emp] = $this->userWithRole($role, 'المستورِد');

        $csv = "الموظف,النوع,من,إلى,الأيام,الحالة\n"
            . $emp->id . ",إجازة سنوية,2026-10-01,2026-10-05,5,معتمد\n";
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('leaves.csv', $csv);

        $this->actingAs($user);
        $map = $this->post(route('m.import.map', 'leaves'), ['file' => $file]);
        $map->assertSessionHasNoErrors();

        $this->post(route('m.import.run', 'leaves'), ['map' => [
            0 => 'empId', 1 => 'type', 2 => 'from', 3 => 'to', 4 => 'days', 5 => 'status',
        ]]);

        $req = $this->latestFor($emp);
        $this->assertNotNull($req, 'لم يُستورَد الصفُّ أصلاً — الحارسُ عطّل الاستيرادَ بدل أن يحرسَ حقلَ القرار');
        $this->assertSame('مقدّم', (string) $req->status,
            'ملفُّ CSV اعتمد إجازةً بلا معتمِد — بابُ الاستيرادِ يلتفّ حول الحارس');
        $this->assertSame(30.0, (float) Employee::whereKey($emp->id)->value('leave_bal'),
            'والخصمُ وقع من الاستيراد — الضررُ الماليُّ نفسُه من بابٍ آخر');
    }

    public function test_a_flag_bearing_approver_can_still_approve(): void
    {
        $this->seedCore();
        [, $emp] = $this->plainEmployee('موظّفٌ ينتظر البتّ');
        $role = Role::create(['name' => 'معتمِد' . \Illuminate\Support\Str::random(4), 'scope' => 'all',
            'flags' => ['approve' => 1], 'matrix' => ['leaves' => ['v' => 1, 'a' => 1, 'e' => 1]]]);
        [$approver] = $this->userWithRole($role, 'مريم المعتمِدة');

        $this->actingAs($approver)
            ->post(route('m.store', 'leaves'), $this->payload($emp, 'معتمد'))
            ->assertSessionHasNoErrors();

        $this->assertSame('معتمد', (string) $this->latestFor($emp)?->status,
            'حاملُ رايةِ الاعتمادِ مُنع من الاعتماد — الحارسُ أوسعُ ممّا يجب وقد كسر العمل');
        $this->assertSame(25.0, (float) Employee::whereKey($emp->id)->value('leave_bal'),
            'والخصمُ يقع كما كان حين يبتّ صاحبُ الصلاحيّة');
    }

    public function test_human_resources_can_still_approve_without_any_flag(): void
    {
        $this->seedCore();
        [, $emp] = $this->plainEmployee('موظّفٌ تبتّ فيه الموارد البشريّة');
        // **الأدوارُ الحيّةُ قِيست قبل اختيارِ المعيار:** «موظّفة موارد بشريّة» بلا
        // أيِّ رايةٍ إطلاقاً — فلو كان المعيارُ رايةَ الاعتمادِ وحدَها لَكُسر عملُها.
        $role = Role::create(['name' => 'موارد بشريّة' . \Illuminate\Support\Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => ['leaves' => ['v' => 1, 'a' => 1, 'e' => 1],
                'hr' => ['v' => 1, 'a' => 1, 'e' => 1]]]);
        [$hr] = $this->userWithRole($role, 'نورة الموارد البشريّة');

        $this->actingAs($hr)
            ->post(route('m.store', 'leaves'), $this->payload($emp, 'معتمد'))
            ->assertSessionHasNoErrors();

        $this->assertSame('معتمد', (string) $this->latestFor($emp)?->status,
            'الموارد البشريّةُ مُنعت من الاعتماد — الحارسُ نزع قدرةً قائمةً بدل أن يحرسَها');
    }

    public function test_seeding_and_console_writes_are_not_broken_by_the_guard(): void
    {
        $this->seedCore();
        [, $emp] = $this->plainEmployee('موظّفُ البذر');

        // بلا مستخدمٍ مصادَق — بذرٌ أو أمرُ طرفيّةٍ أو استيراد
        $req = LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة سنوية',
            'date_from' => '2026-10-01', 'date_to' => '2026-10-05', 'days' => 5, 'status' => 'معتمد']);

        $this->assertSame('معتمد', (string) $req->fresh()->status,
            'الحارسُ خنق البذرَ والاستيراد — ولا فاعلَ بشريّاً هناك ليُحرَس منه');
    }

    /** يُنشئ طلباً «مقدّم» لهذا الموظّف دون المرور بأيّ متحكّم */
    protected function pendingFor(Employee $emp): LeaveRequest
    {
        return LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة سنوية',
            'date_from' => '2026-10-01', 'date_to' => '2026-10-05', 'days' => 5, 'status' => 'مقدّم']);
    }

    public function test_the_kanban_drag_door_is_guarded_too(): void
    {
        $this->seedCore();
        [$user, $emp] = $this->plainEmployee('من سحب بطاقتَه إلى «معتمد»');
        $req = $this->pendingFor($emp);

        $this->actingAs($user)->post(route('m.status', ['leaves', $req->id]), ['status' => 'معتمد']);

        $this->assertSame('مقدّم', (string) $req->fresh()->status,
            'سحبُ البطاقةِ في لوحةِ كانبان اعتمد الإجازةَ — بابٌ ثالثٌ يلتفّ حول الحارس');
        $this->assertSame(30.0, (float) Employee::whereKey($emp->id)->value('leave_bal'),
            'وخُصم الرصيدُ بسحبِ إصبع');
    }

    public function test_the_bulk_door_is_guarded_too(): void
    {
        $this->seedCore();
        [$user, $emp] = $this->plainEmployee('من اعتمد بالجملة');
        $req = $this->pendingFor($emp);

        $this->actingAs($user)->post(route('m.bulk', 'leaves'),
            ['do' => 'status', 'ids' => [$req->id], 'status' => 'معتمد']);

        $this->assertSame('مقدّم', (string) $req->fresh()->status,
            'الإجراءُ الجماعيُّ اعتمد الإجازةَ — والالتفافُ بالجملة أوسعُ أثراً من الفرد');
    }

    public function test_the_requester_may_still_cancel_their_own_pending_request(): void
    {
        $this->seedCore();
        [$user, $emp] = $this->plainEmployee('من ألغى طلبَه');
        $req = $this->pendingFor($emp);

        // **الحارسُ يمنع قيمةَ القرارِ لا كلَّ تغيير.** إلغاءُ المرءِ طلبَه حقُّه،
        // ولو مُنع لَكان الإصلاحُ نزعَ قدرةٍ — وهو المحظورُ الأوّلُ في هذا المجلس.
        $this->actingAs($user)->post(route('m.status', ['leaves', $req->id]), ['status' => 'ملغى']);

        $this->assertSame('ملغى', (string) $req->fresh()->status,
            'مُنع صاحبُ الطلبِ من إلغاءِ طلبِه — الحارسُ أوسعُ ممّا يجب وقد نزع قدرة');
    }

    public function test_an_approver_may_still_decide_from_the_status_door(): void
    {
        $this->seedCore();
        [, $emp] = $this->plainEmployee('موظّفٌ يبتّ فيه من اللوحة');
        $req = $this->pendingFor($emp);
        $role = Role::create(['name' => 'معتمِدُ اللوحة' . \Illuminate\Support\Str::random(4), 'scope' => 'all',
            'flags' => ['approve' => 1], 'matrix' => ['leaves' => ['v' => 1, 'a' => 1, 'e' => 1]]]);
        [$approver] = $this->userWithRole($role, 'معتمِدٌ يسحب البطاقة');

        $this->actingAs($approver)->post(route('m.status', ['leaves', $req->id]), ['status' => 'معتمد']);

        $this->assertSame('معتمد', (string) $req->fresh()->status,
            'حاملُ الرايةِ مُنع من البتِّ بسحبِ البطاقة — قدرةٌ قائمةٌ نُزعت');
    }
}
