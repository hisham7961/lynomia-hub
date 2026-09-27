<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\Workforce\Staff;
use App\Support\Workforce\TeamDirectory;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * محاكاة الاستخدام البشري — الجولة 2 · سكّةُ الهوية (G13/G15/G16/G19).
 *
 * أربعُ عثراتٍ وقعت في رحلتَي التعيين والحادثة، كلُّها على خطٍّ واحد: **من هو
 * صاحبُ هذا الملف، ومن يفتح له باباً، وبأيِّ مفتاح**.
 *
 *   · G13 — ملفٌّ ثانٍ بالبريد نفسِه يُحفظ بصمت فيتنازع ملفّان حساباً واحداً،
 *     بينما كلُّ سكّةِ الربط قائمةٌ على «البريد هو الهوية» (Staff::makeAccountResult).
 *   · G15 — فتحُ حسابِ الموظّف محصورٌ برايةِ إدارةِ المستخدمين، فالتعيينُ يحتاج
 *     أربعةَ أشخاص: HR تُنشئ الملفَّ ثم تنتظر المالكَ ليفتح الحساب.
 *   · G16 — الواجهةُ تَعِد «سيُطلب منه تبديلُها عند أوّل دخول» والوعدُ غيرُ مُنفَّذ:
 *     `password_changed_at` كان وسماً يُقرأ في التقارير ولا يحجب أحداً.
 *   · G19 — بطاقةُ «أوّل أسبوع» تَعِد بأربعِ خطواتٍ وتعرض ثلاثاً، وخطوةُ «تعرّف
 *     على فريقك» تفتح دليلاً فارغاً لمن يحمل `hr:v` بنطاقٍ حاسر.
 *
 * **لماذا حارسُ البريد وإلزامُ كلمةِ المرور ليسا تضييقاً تحسينيّاً؟** كلاهما
 * عيبٌ مثبَتٌ في محاكاةٍ حيّة: الأوّل يُنتج ملفَّين يتنازعان حساباً واحداً (وهو
 * ما تمنعه سكّةُ الربط نفسُها عند الطرف الآخر)، والثاني وعدٌ مكتوبٌ في الواجهة
 * لم يكن يُنفَّذ. ومع ذلك لزم في كليهما نمطُ «من كان يقدر يبقى يقدر»:
 *   · حارسُ البريد يعمل على بابِ الإنسان (`auth()->check()`) كنظيرِه فوقه في
 *     النموذج — فالبذّارُ والسكربتاتُ وما بُني من بياناتٍ قبل اليوم لا يُمسّ.
 *   · وإلزامُ التبديل يقع على **علمٍ صريح** (`must_change_password`) يُوسم به
 *     الحسابُ المفتوحُ بكلمةٍ مؤقّتة وحدَه — لا على تخمينِ «بلا ختمِ تجديد»،
 *     فحساباتُ العملاء ومَن سبق لا يُحبَسون.
 */
class DogfoodR2IdentityTest extends TestCase
{
    /* ────────── عُدّة المشهد ────────── */

    /** دورُ موارد بشريّةٍ كامل الملفّات، بلا رايةِ إدارةِ المستخدمين */
    protected function hrUser(array $extraHr = [], array $extraMods = [], string $email = 'hr2@test.local'): User
    {
        $role = Role::create(['name' => 'موارد بشرية ' . substr($email, 0, 4), 'scope' => 'all', 'flags' => [],
            'matrix' => ['hr' => ['v' => 1, 'a' => 1, 'e' => 1] + $extraHr] + $extraMods]);

        return User::create(['name' => 'مريم الكندري', 'email' => $email,
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /* ═══════════ G13 — بريدٌ مكرّرٌ يُقبل بصمت ═══════════ */

    public function test_g13_a_second_file_with_the_same_email_is_refused_and_names_its_owner(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        Employee::create(['name' => 'سالم المطيري', 'email' => 'salem@test.local', 'status' => 'نشط']);

        // البريدُ نفسُه بحالةِ أحرفٍ أخرى وفراغاتٍ حولَه — الهويةُ واحدة
        $res = $this->post('/m/hr', ['name' => 'سالمٌ آخر', 'email' => '  SALEM@Test.Local ', 'status' => 'نشط']);
        $res->assertSessionHasErrors('email');

        $this->assertSame(1, Employee::whereNull('deleted_at')->count(),
            'حُفظ ملفٌّ ثانٍ ببريدِ ملفٍّ قائم — ملفّان يتنازعان حساباً واحداً');
        $this->assertStringContainsString('سالم المطيري',
            (string) session('errors')?->first('email'),
            'رُفض البريدُ بلا أن يُسمّى صاحبُ الملفّ الذي يملكه — فلا يعرف المستخدم أين يبحث');
    }

    /** وتحريرُ الملفِّ نفسِه ببريده لا يُمنع — الحارسُ على المنازع لا على صاحب البريد */
    public function test_g13_editing_the_same_file_keeps_its_own_email(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $emp = Employee::create(['name' => 'سالم', 'email' => 'salem@test.local', 'status' => 'نشط']);

        $this->put('/m/hr/' . $emp->id, ['name' => 'سالم المطيري', 'email' => 'SALEM@test.local',
            'status' => 'نشط'])->assertSessionHasNoErrors();

        $this->assertSame('سالم المطيري', $emp->fresh()->name, 'رُفض تحريرُ الملفّ ببريده هو');
    }

    /** والمحذوفُ ناعماً لا يحجز بريداً — الملفُّ المُزال ليس منازعاً */
    public function test_g13_soft_deleted_file_does_not_reserve_the_email(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $old = Employee::create(['name' => 'مغادر', 'email' => 'gone@test.local', 'status' => 'منتهية خدمته']);
        $old->delete();

        $this->post('/m/hr', ['name' => 'قادمٌ جديد', 'email' => 'gone@test.local', 'status' => 'نشط'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Employee::whereNull('deleted_at')->where('email', 'gone@test.local')->exists(),
            'ملفٌّ محذوفٌ ناعماً حجز بريداً على من بعده');
    }

    /* ═══════════ G15 — التعيين يحتاج أربعة أشخاص ═══════════ */

    public function test_g15_the_key_is_declared_in_the_fine_permissions_catalog(): void
    {
        $cat = (array) config('hub_permissions');
        $this->assertArrayHasKey('staffAccounts', $cat,
            'مفتاح staffAccounts غير معلَن في كتالوج hub_permissions — فلا موضعَ لمنحه في محرّر الأدوار');
        $this->assertContains('hr', (array) $cat['staffAccounts']['modules']);
        // **حُذف هنا تأكيدٌ فارغ** (v2.558): كان
        // `assertStringNotContainsString('.', 'staffAccounts')` — طرفاه حرفيّان،
        // فهو صادقٌ أبداً مهما كان الكتالوج، ولا يفشل على أيِّ انحدار. ولم
        // يُستبدَل بحارسِ «مفتاحٌ بلا نقطة» لأنّ الدعوى لم تثبت: المفتاحُ يدخل
        // اسمَ الحقلِ بين قوسين (`matrix[{$mk}][{$fk}]` في roles/form.blade.php)
        // فالنقطةُ فيه لا تكسر شيئاً. والتأكيدانِ أعلاه يقومان بالعملِ الحقيقيّ:
        // المفتاحُ مُعلَنٌ، وعلى وحدةِ `hr`. **وما لم يُقَس لا يُحرَس.**
    }

    public function test_g15_hr_holding_the_key_opens_an_employee_account_without_the_users_flag(): void
    {
        $this->seedCore();
        $hr = $this->hrUser(['staffAccounts' => 1]);
        $role = Role::create(['name' => 'عضو فريق', 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        $emp = Employee::create(['name' => 'لطيفة السالم', 'email' => 'latifa@test.local', 'status' => 'نشط']);

        $this->actingAs($hr)->post('/staff/' . $emp->id . '/account', ['role_id' => $role->id])
            ->assertRedirect();

        $u = User::where('email', 'latifa@test.local')->first();
        $this->assertNotNull($u, 'حاملُ مفتاح «فتح حسابات الموظفين» لم يستطع فتحَ حساب — التعيينُ ما زال ينتظر المالك');
        $this->assertSame($u->id, $emp->fresh()->user_id);
    }

    /** والشاشةُ تعرض له الزرَّ فعلاً — لا بابٌ مفتوحٌ بلا مقبض */
    public function test_g15_staff_screen_offers_the_button_to_the_key_holder(): void
    {
        $this->seedCore();
        $hr = $this->hrUser(['staffAccounts' => 1]);
        Employee::create(['name' => 'بلا حساب', 'email' => 'noacc@test.local', 'status' => 'نشط']);

        $this->actingAs($hr)->get('/staff')->assertOk()->assertSee('افتح حساباً');
    }

    /** من كان يقدر يبقى يقدر: المالكُ ورايةُ إدارةِ المستخدمين على حالهما */
    public function test_g15_owner_and_users_flag_keep_opening_accounts(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'عضو فريق', 'scope' => 'all', 'flags' => [], 'matrix' => []]);
        $emp = Employee::create(['name' => 'بدر', 'email' => 'badr@test.local', 'status' => 'نشط']);

        $this->actingAs($this->owner)->post('/staff/' . $emp->id . '/account', ['role_id' => $role->id]);
        $this->assertNotNull(User::where('email', 'badr@test.local')->first(),
            'المالكُ فقد قدرةً كان يملكها');

        $admin = Role::create(['name' => 'إداري مستخدمين', 'scope' => 'all', 'flags' => ['users' => 1],
            'matrix' => ['hr' => ['v' => 1, 'a' => 1, 'e' => 1]]]);
        $au = User::create(['name' => 'إداري', 'email' => 'adm2@test.local', 'password' => 'Secret!2026x',
            'role_id' => $admin->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $emp2 = Employee::create(['name' => 'هند', 'email' => 'hind@test.local', 'status' => 'نشط']);

        $this->actingAs($au)->post('/staff/' . $emp2->id . '/account', ['role_id' => $role->id]);
        $this->assertNotNull(User::where('email', 'hind@test.local')->first(),
            'حاملُ راية إدارة المستخدمين فقد قدرةً كان يملكها');
    }

    /** ومن لا يحمل المفتاحَ ولا الرايةَ يبقى مردوداً — الفتحُ منحٌ لا أثرٌ جانبيّ لـhr:e */
    public function test_g15_hr_without_the_key_is_still_refused(): void
    {
        $this->seedCore();
        $hr = $this->hrUser([], [], 'hr3@test.local');
        $role = Role::create(['name' => 'عضو فريق', 'scope' => 'all', 'flags' => [], 'matrix' => []]);
        $emp = Employee::create(['name' => 'راشد', 'email' => 'rashed@test.local', 'status' => 'نشط']);

        $this->actingAs($hr)->post('/staff/' . $emp->id . '/account', ['role_id' => $role->id])
            ->assertForbidden();
        $this->assertNull(User::where('email', 'rashed@test.local')->first());
    }

    /**
     * **البابُ الجديد ليس سُلّماً**: حاملُ المفتاح وحدَه لا يمنح دوراً يملك
     * إدارةَ المستخدمين — وإلا صار «فتحُ حساباتِ الموظفين» طريقاً إلى النظام كلّه.
     */
    public function test_g15_key_holder_cannot_hand_out_a_user_admin_role(): void
    {
        $this->seedCore();
        $hr = $this->hrUser(['staffAccounts' => 1], [], 'hr4@test.local');
        $admin = Role::create(['name' => 'إداري مستخدمين', 'scope' => 'all', 'flags' => ['users' => 1], 'matrix' => []]);
        $emp = Employee::create(['name' => 'متسلّق', 'email' => 'climb@test.local', 'status' => 'نشط']);

        $this->actingAs($hr)->post('/staff/' . $emp->id . '/account', ['role_id' => $admin->id])
            ->assertForbidden();
        $this->assertNull(User::where('email', 'climb@test.local')->first(),
            'مفتاحُ فتحِ الحسابات منح إدارةَ المستخدمين — تصعيدٌ من بابٍ جانبيّ');
    }

    /* ═══════════ G16 — كلمةُ المرور المؤقّتة غيرُ مُلزِمة ═══════════ */

    /** الحسابُ المفتوحُ بكلمةٍ مؤقّتة يُوسَم صراحةً — لا تخمينَ من «بلا ختمِ تجديد» */
    public function test_g16_new_account_is_explicitly_flagged_for_password_change(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'عضو فريق', 'scope' => 'all', 'flags' => [], 'matrix' => []]);
        $emp = Employee::create(['name' => 'جديد', 'email' => 'fresh@test.local', 'status' => 'نشط']);

        $this->actingAs($this->owner)->post('/staff/' . $emp->id . '/account', ['role_id' => $role->id]);

        $u = User::where('email', 'fresh@test.local')->firstOrFail();
        $this->assertTrue((bool) $u->must_change_password, 'الحسابُ المؤقّت بلا علمٍ صريح يُلزِم بالتبديل');
        $this->assertNull($u->password_changed_at);
        $this->assertTrue(Staff::mustChangePassword($u));
    }

    /** والوعدُ يُنفَّذ: لا عملَ قبل التبديل، ثم يُفتح كلُّ شيء */
    public function test_g16_temp_password_account_is_held_at_the_door_until_it_changes(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'عضو فريق', 'scope' => 'all', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1]]]);
        $emp = Employee::create(['name' => 'جديد', 'email' => 'fresh@test.local', 'status' => 'نشط']);
        $this->actingAs($this->owner)->post('/staff/' . $emp->id . '/account', ['role_id' => $role->id]);
        // تُعرض مرةً واحدة في الجلسة — تُلتقط قبل أيِّ طلبٍ آخر يستهلك الوميض
        $temp = (string) session('temp_password');
        $this->assertNotEmpty($temp, 'لم تُعرض كلمةُ المرور المؤقتة');

        $u = User::where('email', 'fresh@test.local')->firstOrFail();

        // أيُّ شاشةٍ أخرى تردُّه إلى ملفّه ليبدّل — لا عملَ بكلمةٍ سلّمها غيرُه بيده
        $this->actingAs($u)->get('/')->assertRedirect(route('profile.edit'));
        // وبابُ التبديل نفسُه مفتوحٌ وإلا حُبس بلا مخرج
        $this->actingAs($u)->get('/profile')->assertOk();

        // بدّلها فانفتح الباب
        $this->actingAs($u)->put('/profile/password', [
            'current' => $temp, 'password' => 'Newpass!2026x', 'password_confirmation' => 'Newpass!2026x',
        ])->assertSessionHasNoErrors();

        $this->assertFalse(Staff::mustChangePassword($u->fresh()));
        $this->actingAs($u->fresh())->get('/')->assertOk();
    }

    /** ولا يُمسّ القائم: حسابٌ بلا علمٍ يدخل كما كان ولو كان ختمُه فارغاً */
    public function test_g16_existing_accounts_are_not_disturbed(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get('/')->assertOk();

        // حسابٌ قديمٌ بلا ختمِ تجديد (عضوُ بوّابةِ عميلٍ لم يُفعّل، أو صفٌّ تاريخيّ)
        $this->employee->forceFill(['password_changed_at' => null])->saveQuietly();
        $this->assertFalse(Staff::mustChangePassword($this->employee->fresh()));
        $this->actingAs($this->employee->fresh())->get('/')->assertOk();
    }

    /* ═══════════ G19 — بطاقةُ «أوّل أسبوع»: عدٌّ كاذبٌ ورابطٌ إلى فراغ ═══════════ */

    public function test_g19_the_card_counts_exactly_what_it_shows(): void
    {
        $this->seedCore();
        Employee::create(['name' => 'زميل', 'title' => 'مطوّر', 'dept' => 'التقنية', 'status' => 'نشط']);
        Employee::create(['name' => $this->employee->name, 'status' => 'نشط',
            'user_id' => $this->employee->id, 'dept' => 'التقنية', 'hired' => now()->toDateString()]);

        // ثلاثُ خطواتٍ بلا وثيقةِ دليل — والبطاقةُ كانت تَعِد بأربع
        $this->actingAs($this->employee)->get('/me')->assertOk()
            ->assertSee('ثلاثُ خطواتٍ', false)
            ->assertDontSee('أربعُ خطواتٍ', false);

        \App\Models\Document::create(['name' => 'دليل الموظف الجديد — نسخة 2026']);
        $this->actingAs($this->employee)->get('/me')->assertOk()
            ->assertSee('أربعُ خطواتٍ', false);
    }

    /**
     * **ولا خطوةَ تقود إلى فراغ — والجذرُ أُصلح فلم يعد ثمّة فراغ.**
     *
     * كُتب هذا الاختبارُ أوّلاً على المشهد المعطوب: حاملُ `hr:v` بنطاقِ مشاريعَ
     * حاسرٍ يرى دليلاً **فارغاً** (`hub_scope` تحسر على `employees.project_id`
     * وهو NULL) فتُسقَط خطوةُ «تعرّف على فريقك» لئلّا تقود إلى عدم. ثم أُصلح
     * السببُ نفسُه في `TeamDirectory::cards` (سقوطٌ إلى الدليلِ الأدنى حين يُفرغ
     * النطاقُ النتيجة) — فصار الزميلُ يرى زملاءه، والخطوةُ حيّةً لا ميّتة.
     * الضمانةُ المحروسةُ هي هي: **ما يُعرض يقود إلى شيء**؛ تغيّر الوجهُ لا الحكم.
     */
    public function test_g19_team_step_is_alive_because_the_directory_is_no_longer_empty(): void
    {
        $this->seedCore();
        Employee::create(['name' => 'زميل', 'title' => 'مطوّر', 'dept' => 'التقنية', 'status' => 'نشط']);

        $role = Role::create(['name' => 'منفّذ بملفات', 'scope' => 'proj', 'flags' => [],
            'matrix' => ['hr' => ['v' => 1], 'tasks' => ['v' => 1]]]);
        $u = User::create(['name' => 'موظف جديد', 'email' => 'newhire@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
        $emp = Employee::create(['name' => 'موظف جديد', 'email' => 'newhire@test.local',
            'user_id' => $u->id, 'status' => 'نشط', 'dept' => 'التقنية', 'hired' => now()->toDateString()]);

        Cache::flush();
        $this->actingAs($u);
        $cards = collect(TeamDirectory::cards($u))->flatten(1);
        $this->assertGreaterThan(0, $cards->count(),
            'حسرُ النطاقِ يُنقص ما يُعرَض لا أن يُلغيَ حقَّ الزميلِ في دليله الأدنى');

        // والدليلُ الأدنى يبقى أدنى: **لا قيمةَ حسّاسةً** تتسرّب بالسقوط إليه —
        // لا راتبَ ولا انتهاءَ إقامةٍ أو جوازٍ ولا تنبيهاتٍ عليها. (المفتاحُ
        // الغائبُ أشدُّ خصوصيّةً من الخاوي، فكلاهما يُقبل — القيمةُ هي الحُكم.)
        foreach ($cards as $card) {
            $card = (array) $card;
            $this->assertNull($card['salary'] ?? null, 'راتبٌ في الدليل الأدنى');
            $this->assertNull($card['idDays'] ?? null, 'انتهاءُ إقامةٍ في الدليل الأدنى');
            $this->assertNull($card['passDays'] ?? null, 'انتهاءُ جوازٍ في الدليل الأدنى');
            $this->assertNotTrue($card['alert'] ?? false, 'تنبيهُ وثائقَ في الدليل الأدنى');
        }

        $fw = Staff::firstWeek($u, $emp);
        $labels = collect($fw['items'])->pluck('label')->implode(' | ');
        $this->assertStringContainsString('تعرّف على فريقك', $labels,
            'الدليلُ صار عامراً فالخطوةُ تقود إلى شيء — تُعرض');
    }

    /** وتبقى الخطوةُ لمن يرى زملاءه فعلاً — لا تضييقَ على المشهد السليم */
    public function test_g19_team_step_remains_for_an_ordinary_colleague(): void
    {
        $this->seedCore();
        Employee::create(['name' => 'زميل', 'title' => 'مطوّر', 'dept' => 'التقنية', 'status' => 'نشط']);

        $role = Role::create(['name' => 'منفّذ', 'scope' => 'proj', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1]]]);
        $u = User::create(['name' => 'زميلة', 'email' => 'mate@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
        $emp = Employee::create(['name' => 'زميلة', 'email' => 'mate@test.local', 'user_id' => $u->id,
            'status' => 'نشط', 'dept' => 'التقنية', 'hired' => now()->toDateString()]);

        Cache::flush();
        $this->actingAs($u);
        $fw = Staff::firstWeek($u, $emp);
        $team = collect($fw['items'])->firstWhere('label', 'تعرّف على فريقك: من في قسمك ومن مديرك المباشر');
        $this->assertNotNull($team, 'سقطت خطوةُ الفريق عمّن يرى زملاءه فعلاً');
        $this->assertSame(route('team'), $team['url']);
    }

    /* ═══════════ البذّار — هل يؤدّي كلُّ دورٍ مهمّتَه المعلنة؟ ═══════════ */

    public function test_demo_seeder_roles_can_do_their_declared_jobs(): void
    {
        $this->seed(\Database\Seeders\CoreSeeder::class);
        $this->seed(\Database\Seeders\DemoCompanySeeder::class);

        $it = User::where('email', 'abdullah@lynomia-demo.test')->firstOrFail();
        foreach (['tickets', 'issues', 'servers', 'incidents'] as $m) {
            $this->assertTrue(hub_can($it, $m, 'e'),
                "مسؤولُ تقنيةِ المعلومات عاجزٌ عن «{$m}» — لا يستطيع معالجةَ حادثةٍ تقنيّة");
        }

        $hr = User::where('email', 'maryam@lynomia-demo.test')->firstOrFail();
        $this->assertTrue(hub_can($hr, 'hr', 'staffAccounts'),
            'الموارد البشرية بلا مفتاح فتح الحسابات — التعيينُ ما زال ينتظر المالك');
        $this->assertTrue(Staff::mayOpenAccounts($hr));
        $this->assertTrue(hub_can($hr, 'assets', 'v'), 'الموارد البشرية لا ترى الأصول فلا تُسلّم عهدة');
        $this->assertTrue(hub_can($hr, 'assets', 'custodyAssign'), 'الموارد البشرية لا تُسنِد عهدةً للموظّف الجديد');
        $this->assertTrue(hub_can($hr, 'recruit', 'e'), 'الموارد البشرية لا تدير مسارَ التوظيف');

        $acc = User::where('email', 'yousef@lynomia-demo.test')->firstOrFail();
        $this->assertTrue(hub_can($acc, 'payroll', 'e'), 'المحاسبُ لا يستطيع تسيير الرواتب');
    }
}
