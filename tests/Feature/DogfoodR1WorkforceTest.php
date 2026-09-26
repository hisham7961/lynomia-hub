<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditEntry;
use App\Models\Document;
use App\Models\Employee;
use App\Models\HubNotification;
use App\Models\Role;
use App\Models\User;
use App\Support\Workforce\TeamDirectory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * محاكاة الاستخدام البشري — الجولة 1 · سكّة القوى العاملة (F28/F29/F30/F31/F4).
 *
 * خمسُ عثراتٍ وقع فيها موظفٌ وموظفةٌ جديدةٌ وHR فعلاً:
 *   · F28 — زرُّ «أضف بند عمل» يظهر لمن لا يملك `updates:a` ثم يقوده إلى 403.
 *   · F29 — موظفةُ اليوم الأول ترى «يوم هادئ» وستةَ أصفار ولا دليلَ يوجّهها.
 *   · F30 — HR أعادت تفعيل ملفٍّ موقوفٍ فبقي حسابُ الدخول موقوفاً بلا تحذير.
 *   · F31 — `users.status` نصٌّ حرّ: قيمةٌ مكسورةُ الترميز رفضها فحصُ الجلسة
 *     بينما شاشاتٌ أخرى تحسبها نشطة — القرّاء والكتّاب على غير ثوابت واحدة.
 *   · F4  — موظفٌ بنطاق مشاريع وبلا `hr:v` يرى دليلَ فريقٍ فارغاً (تنطيقُ
 *     `hr` يحسر على `employees.project_id` وهو NULL دائماً)، والمديرُ يظهر
 *     UUID خاماً حيث لا يُحلّ اسمُه.
 */
class DogfoodR1WorkforceTest extends TestCase
{
    /* ────────── عُدّة المشهد ────────── */

    /** دورُ «عرضٍ فقط» على بنود العمل — يرى ولا يضيف */
    protected function viewOnlyUser(): User
    {
        $role = Role::create(['name' => 'قارئ بنود', 'scope' => 'all', 'flags' => [],
            'matrix' => ['updates' => ['v' => 1], 'tasks' => ['v' => 1], 'files' => ['v' => 1]]]);

        return User::create(['name' => 'أحمد القارئ', 'email' => 'ahmad@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /** ملفٌّ وظيفيٌّ نشطٌ مربوطٌ بحساب */
    protected function fileFor(User $u, array $extra = []): Employee
    {
        return Employee::create($extra + ['name' => $u->name, 'status' => 'نشط',
            'user_id' => $u->id, 'dept' => 'التقنية']);
    }

    /* ═══════════ F28 — الزرُّ الكاذب: يظهر ثم يصفع 403 ═══════════ */

    public function test_f28_view_only_role_sees_honest_message_not_add_button(): void
    {
        $this->seedCore();
        $u = $this->viewOnlyUser();
        $this->fileFor($u);

        $res = $this->actingAs($u)->get('/my/report')->assertOk();
        // لا زرَّ يقود إلى 403 — لا في الترويسة ولا في الحالة الفارغة
        $res->assertDontSee('أضف بندَ عمل');
        $res->assertDontSee('أضِف أول بند');
        // ومكانَه صدقٌ يشرح ويدلّ على المخرج
        $res->assertSee('دورك للعرض فقط');
    }

    public function test_f28_add_button_still_shows_for_those_allowed(): void
    {
        $this->seedCore();
        $this->fileFor($this->employee);

        $this->actingAs($this->employee)->get('/my/report')->assertOk()
            ->assertSee('أضف بندَ عمل')
            ->assertDontSee('دورك للعرض فقط');
    }

    /** بطاقةُ «يومي» في اللوحة — الموضعُ الثاني لنفس الزرّ */
    public function test_f28_checkin_widget_hides_add_button_from_view_only(): void
    {
        $this->seedCore();
        $u = $this->viewOnlyUser();
        $emp = $this->fileFor($u);
        Attendance::create(['emp_id' => $emp->id, 'date' => now()->toDateString(), 'time_in' => '08:00']);

        $this->actingAs($u);
        $html = view('partials.widgets.checkin', ['data' => \App\Support\Workforce\Workday::mine($u)])->render();
        $this->assertStringNotContainsString('＋ بند عمل', $html,
            'زرُّ إضافة بندٍ ظهر في بطاقة «يومي» لمن لا يملك updates:a');

        // ولمن يملكها يبقى الزرُّ كما كان
        $emp2 = $this->fileFor($this->employee);
        Attendance::create(['emp_id' => $emp2->id, 'date' => now()->toDateString(), 'time_in' => '08:00']);
        $this->actingAs($this->employee);
        $html2 = view('partials.widgets.checkin', ['data' => \App\Support\Workforce\Workday::mine($this->employee)])->render();
        $this->assertStringContainsString('＋ بند عمل', $html2);
    }

    /* ═══════════ F29 — أوّلُ أسبوعٍ بلا دليلٍ ولا بوصلة ═══════════ */

    public function test_f29_first_week_card_greets_new_employee(): void
    {
        $this->seedCore();
        $this->fileFor($this->employee, ['hired' => now()->toDateString()]);

        $this->actingAs($this->employee)->get('/me')->assertOk()
            ->assertSee('أوّل أسبوع')
            ->assertSee('سجّل حضورك')
            ->assertSee('بند عمل')
            ->assertSee('تعرّف على فريقك');
    }

    public function test_f29_no_card_for_veterans(): void
    {
        $this->seedCore();
        $this->fileFor($this->employee, ['hired' => now()->subDays(60)->toDateString()]);
        // حسابٌ قديمٌ كذلك — كلا البابين (التعيين وإنشاء الحساب) خارج النافذة
        $this->employee->forceFill(['created_at' => now()->subDays(60)])->saveQuietly();

        $this->actingAs($this->employee)->get('/me')->assertOk()
            ->assertDontSee('أوّل أسبوع');
    }

    /** رابطُ «دليل الموظف الجديد» يظهر إن وُجدت الوثيقة — ولا رابطَ ميتاً إن غابت */
    public function test_f29_guide_link_only_when_document_exists(): void
    {
        $this->seedCore();
        $this->fileFor($this->employee, ['hired' => now()->toDateString()]);

        $this->actingAs($this->employee)->get('/me')->assertOk()
            ->assertDontSee('دليل الموظف الجديد');

        Document::create(['name' => 'دليل الموظف الجديد — نسخة 2026']);

        $this->actingAs($this->employee)->get('/me')->assertOk()
            ->assertSee('دليل الموظف الجديد');
    }

    /* ═══════════ F30 — فكُّ الإيقاف لا يعيد الحساب ═══════════ */

    public function test_f30_reactivating_employee_reopens_linked_account(): void
    {
        $this->seedCore();
        $emp = $this->fileFor($this->employee);

        // الإيقاف يغلق الحساب آلياً (السلوك القائم — لا يُكسر)
        $this->actingAs($this->owner);
        $emp->update(['status' => 'موقوف']);
        $this->assertSame('موقوف', $this->employee->fresh()->status,
            'إغلاقُ الملف لم يوقف الحساب — انكسر السلوك القائم');

        // والعودةُ تفتحه فعلاً — لا إشعاراً يضيع بين إشعارات الإدارة
        $emp->refresh()->update(['status' => 'نشط']);
        $this->assertSame('نشط', $this->employee->fresh()->status,
            'أُعيد تفعيل الملف وبقي حسابُ الدخول موقوفاً — الموظفة معلّقة خارج النظام');

        // قيدُ تدقيقٍ باسم الفعل، وإشعارٌ للموظف نفسه أن بابه فُتح
        $this->assertTrue(AuditEntry::where('module', 'users')
            ->where('record_id', $this->employee->id)
            ->where('action', 'LIKE', '%إعادة تفعيل حساب%')->exists(),
            'لا أثرَ تدقيقٍ لإعادة التفعيل');
        $this->assertTrue(HubNotification::where('user_id', $this->employee->id)
            ->where('text', 'LIKE', '%أُعيد تفعيل حسابك%')->exists(),
            'الموظفُ لم يُبلَّغ أن حسابه عاد');
    }

    /** حسابٌ ذو امتياز لا يُفتح آلياً بفاعلٍ أدنى — يُبلَّغ من يملك القرار بصوتٍ عال */
    public function test_f30_privileged_account_stays_closed_with_loud_warning(): void
    {
        $this->seedCore();

        $adminRole = Role::create(['name' => 'إداري مستخدمين', 'scope' => 'all',
            'flags' => ['users' => 1], 'matrix' => []]);
        $priv = User::create(['name' => 'إدارية', 'email' => 'admin2@test.local',
            'password' => 'Secret!2026x', 'role_id' => $adminRole->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->actingAs($this->owner);
        $emp = $this->fileFor($priv);
        // مشهدُ الإيقاف اليدويّ ثم عودةُ الملف — بيد HR بلا راية users
        $priv->forceFill(['status' => 'موقوف'])->save();
        $emp->forceFill(['status' => 'موقوف'])->saveQuietly();

        $hrRole = Role::create(['name' => 'موارد بشرية', 'scope' => 'all', 'flags' => [],
            'matrix' => ['hr' => ['v' => 1, 'a' => 1, 'e' => 1]]]);
        $hr = User::create(['name' => 'مريم', 'email' => 'hr@test.local',
            'password' => 'Secret!2026x', 'role_id' => $hrRole->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->actingAs($hr);
        $emp->refresh()->update(['status' => 'نشط']);

        $this->assertSame('موقوف', $priv->fresh()->status,
            'حسابٌ ذو امتياز فُتح آلياً بفاعلٍ لا يملك المساسَ به — تصعيد');
        $this->assertTrue(HubNotification::where('record_id', $priv->id)
            ->where('text', 'LIKE', '%ما زال موقوفاً%')->exists(),
            'بقي الحسابُ موقوفاً ولم يُحذَّر أحدٌ ممن يملك القرار');
    }

    /* ═══════════ F31 — حالةُ المستخدم نصٌّ حرّ ═══════════ */

    public function test_f31_statuses_constant_is_the_single_source(): void
    {
        $this->assertSame(['نشط', 'موقوف'], User::STATUSES);
        $this->assertSame('نشط', User::STATUS_ACTIVE);
        $this->assertSame('موقوف', User::STATUS_SUSPENDED);
    }

    public function test_f31_admin_form_rejects_free_text_status(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/admin/users', [
            'name' => 'مكسور', 'email' => 'broken@test.local',
            'role_id' => $this->employee->role_id,
            'status' => 'Ù†Ø´Ø·',            // «نشط» بترميزٍ مكسور — كما وقع فعلاً
            'password' => 'Secret!2026x',
        ])->assertSessionHasErrors('status');
        $this->assertDatabaseMissing('users', ['email' => 'broken@test.local']);
    }

    /** التطبيعُ الدفاعيّ: محارفُ الاتجاه والفراغاتُ الخفيّة تُشذَّب عند الكتابة */
    public function test_f31_mutator_normalizes_invisible_characters(): void
    {
        $this->seedCore();

        $this->employee->forceFill(['status' => " نشط\u{200F} "])->save();
        $this->assertSame('نشط', $this->employee->fresh()->status,
            'حالةٌ بمحرف اتجاهٍ خفيّ خُزّنت خاماً — فحصُ الجلسة سيطرد صاحبها');

        // وقيمةٌ خارج الثوابت تُرفض عند المنبع — enum التطبيق (درس C10)
        $this->expectException(\InvalidArgumentException::class);
        $this->employee->forceFill(['status' => 'معطّل'])->save();
    }

    /** القرّاء على ثوابت واحدة: الدخولُ يرفض المكسورَ كما يرفضه حارسُ الجلسة */
    public function test_f31_broken_status_fails_closed_at_login_and_session_alike(): void
    {
        $this->seedCore();
        // قيمةٌ مكسورةُ الترميز زُرعت مباشرةً في القاعدة (تجاوزاً للتطبيع)
        DB::table('users')->where('id', $this->employee->id)->update(['status' => 'Ù†Ø´Ø·']);

        // كان الدخولُ يمرّ (يقارن على «موقوف» وحدها) ثم يطرده حارسُ الجلسة
        // (يقارن على «نشط») — رحلةُ تناقضٍ. الآن: بابٌ واحد مغلقٌ بثوابتَ واحدة.
        $this->post('/login', ['email' => 'emp@test.local', 'password' => 'Secret!2026x']);
        $this->assertGuest();

        // وحارسُ الجلسة على الحكم نفسه — جلسةٌ قائمةٌ تُطرد
        $this->actingAs($this->employee->fresh())->get('/me')->assertRedirect('/login');
    }

    /* ═══════════ F4 — دليلُ الفريق فارغٌ والمديرُ UUID خام ═══════════ */

    /** موظفٌ داخليٌّ بنطاق مشاريع وبلا hr:v: يرى زملاءه — الاسم والمسمّى والقسم والمدير بالاسم */
    public function test_f4_ordinary_employee_gets_minimal_directory_with_manager_name(): void
    {
        $this->seedCore();

        $manager = User::create(['name' => 'مديرة المشاريع', 'email' => 'mgr@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $role = Role::create(['name' => 'منفّذ مشاريع', 'scope' => 'proj', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1, 'a' => 1], 'updates' => ['v' => 1, 'a' => 1]]]);
        $latifa = User::create(['name' => 'لطيفة', 'email' => 'latifa@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
        $this->fileFor($latifa, ['manager_id' => $manager->id]);

        $colleague = Employee::create(['name' => 'زميلة أولى', 'title' => 'مهندسة',
            'dept' => 'التقنية', 'status' => 'نشط', 'salary' => 1500,
            'manager_id' => $manager->id]);

        Cache::flush();
        $res = $this->actingAs($latifa)->get('/team')->assertOk();
        $res->assertSee('زميلة أولى');                    // كان: «لا موظفين في نطاقك»
        $res->assertSee('مديرة المشاريع');                // المديرُ اسماً…
        $res->assertDontSee($manager->id);                // …لا UUID خاماً
        $res->assertDontSee('1,500');                      // الراتبُ يبقى خلف صلاحيته
        $res->assertDontSee('/m/hr/' . $colleague->id);    // لا رابطَ يقود لملفّ HR ثم 403
    }

    /** الحدُّ الأدنى بلا حقولٍ حسّاسة — والبطاقةُ تحمل الشركةَ بالاسم */
    public function test_f4_minimal_cards_carry_company_but_no_sensitive_fields(): void
    {
        $this->seedCore();
        $co = \App\Models\Company::create(['name_ar' => 'لينوميا القابضة']);

        $role = Role::create(['name' => 'منفّذ', 'scope' => 'proj', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1]]]);
        $u = User::create(['name' => 'موظف عادي', 'email' => 'plain@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
        $this->fileFor($u);

        Employee::create(['name' => 'زميل محاسب', 'title' => 'محاسب', 'dept' => 'المالية',
            'status' => 'نشط', 'salary' => 900, 'phone' => '99887766',
            'company_id' => $co->id]);

        $this->actingAs($u);
        Cache::flush();
        $cards = collect(TeamDirectory::cards($u))->flatten(1);
        $mate = $cards->firstWhere('name', 'زميل محاسب');

        $this->assertNotNull($mate, 'الدليلُ الأدنى لا يرى الزملاء');
        $this->assertSame('محاسب', $mate['title']);
        $this->assertSame('لينوميا القابضة', $mate['company'] ?? null);
        $this->assertNull($mate['salary'], 'راتبٌ تسرّب في الدليل الأدنى');
        $this->assertNull($mate['idDays'], 'انتهاءُ الإقامة تسرّب في الدليل الأدنى');
        $this->assertArrayNotHasKey('phone', $mate, 'هاتفٌ تسرّب في بطاقة الدليل');
    }

    /** البابُ يبقى مغلقاً على من ليس زميلاً: لا ملفَّ له ولا hr:v — وحسابُ العميل كذلك */
    public function test_f4_no_file_no_hr_still_forbidden_and_clients_blocked(): void
    {
        $this->seedCore();

        $role = Role::create(['name' => 'بلا موظفين', 'scope' => 'all', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1]]]);
        $stranger = User::create(['name' => 'غريب', 'email' => 'stranger@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
        $this->actingAs($stranger)->get('/team')->assertForbidden();

        $client = User::create(['name' => 'عميل', 'email' => 'client@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
        // حسابُ العميل مردودٌ عن السطح الداخلي أصلاً: PortalGuard يحوّله لبوّابته
        // (302 — قرار F22) قبل أن يبلغ المتحكّم، وحارسُ mode() طبقةٌ ثانية لو
        // تبدّل الوسيطُ يوماً. المهم: لا دليلَ موظفين يصل عميلاً بأي طريق.
        $res = $this->actingAs($client)->get('/team');
        $this->assertContains($res->getStatusCode(), [302, 403, 404], 'حسابُ عميلٍ فتح دليلَ الموظفين');
        if ($res->getStatusCode() === 302) {
            $this->assertStringContainsString('/portal', (string) $res->headers->get('Location'),
                'تحويلةُ العميل لا تقوده إلى بوّابته');
        }
    }
}
