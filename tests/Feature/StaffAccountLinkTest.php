<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **الموظف والمستخدم شخصٌ واحد.**
 *
 * كان الطرفان جدولين لا يعرف أحدهما الآخر: `employees.user_id` حقلٌ مرجعيّ
 * يُملأ يدوياً أو لا يُملأ. فتنشأ ثلاث حالاتٍ كلُّها تقع بصمت:
 *
 *   ١) **موظفٌ بلا حساب**: يُضاف ملفُّه ولا يستطيع الدخول — ولا شيء يُنبّه.
 *   ٢) **حسابٌ بلا ملف**: يدخل النظام ولا راتب له ولا عهدة ولا إجازات.
 *   ٣) **الأخطر — خدمةٌ انتهت وحسابٌ حيّ**: يُضبط الملف «منتهية خدمته»
 *      ويبقى الحساب نشطاً يقرأ ويكتب. الملفُّ يقول ذهب، والنظام يقول موجود.
 *
 * القاعدة: **البريد هو الهوية**. من يُضاف بأحد الطرفين يُربط بالآخر إن وُجد،
 * ويُنشأ الطرف الغائب بقرارٍ صريح — والانتهاءُ يُغلق الباب فوراً.
 */
class StaffAccountLinkTest extends TestCase
{
    /* ── الربط التلقائي: البريد هو الهوية ── */

    public function test_adding_an_employee_links_the_account_that_already_exists(): void
    {
        $this->seedCore();
        $u = User::create(['name' => 'سالم', 'email' => 'salem@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($this->owner)->post('/m/hr',
            ['name' => 'سالم', 'email' => 'salem@test.local', 'status' => 'نشط'])->assertRedirect();

        $emp = Employee::where('email', 'salem@test.local')->firstOrFail();
        $this->assertSame($u->id, $emp->user_id,
            'أُضيف موظفٌ لبريدٍ له حسابٌ قائم وبقي الطرفان لا يعرف أحدهما الآخر');
    }

    public function test_adding_a_user_links_the_employee_file_that_is_waiting(): void
    {
        $this->seedCore();
        $emp = Employee::create(['name' => 'نورة', 'email' => 'noura@test.local', 'status' => 'نشط']);

        $this->actingAs($this->owner)->post('/admin/users', [
            'name' => 'نورة', 'email' => 'noura@test.local', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password' => 'Secret!2026x',
        ])->assertRedirect();

        $u = User::where('email', 'noura@test.local')->firstOrFail();
        $this->assertSame($u->id, $emp->fresh()->user_id,
            'أُنشئ حسابٌ لبريدٍ ينتظره ملفٌّ وظيفي وبقي الملف يتيماً');
    }

    /** والربط لا يسرق ملفاً مربوطاً بغيره — الحساب لصاحبه */
    public function test_a_second_employee_never_steals_a_linked_account(): void
    {
        $this->seedCore();
        $u = User::create(['name' => 'سالم', 'email' => 'salem@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $first = Employee::create(['name' => 'سالم', 'email' => 'salem@test.local',
            'status' => 'نشط', 'user_id' => $u->id]);

        $second = Employee::create(['name' => 'سالمٌ آخر', 'email' => 'salem@test.local', 'status' => 'نشط']);

        $this->assertNull($second->fresh()->user_id, 'ملفّان يتقاسمان حساباً واحداً');
        $this->assertSame($u->id, $first->fresh()->user_id);
    }

    /* ── إنشاء الطرف الغائب بقرارٍ صريح ── */

    public function test_an_employee_can_be_given_an_account_at_creation(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'موظف', 'scope' => 'own', 'flags' => [], 'matrix' => []]);

        $this->actingAs($this->owner)->post('/m/hr', [
            'name' => 'بدر', 'email' => 'badr@test.local', 'status' => 'نشط',
            '_make_account' => '1', '_account_role' => $role->id,
        ])->assertRedirect();

        $u = User::where('email', 'badr@test.local')->first();
        $this->assertNotNull($u, 'طُلب حسابٌ للموظف الجديد ولم يُنشأ');
        $this->assertSame($role->id, $u->role_id);
        $this->assertNull($u->password_changed_at, 'حسابٌ جديد بلا إلزام بتبديل كلمة المرور المؤقتة');
        $this->assertSame($u->id, Employee::where('email', 'badr@test.local')->value('user_id'));
    }

    public function test_a_user_can_be_given_an_employee_file_at_creation(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/admin/users', [
            'name' => 'هند', 'email' => 'hind@test.local', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password' => 'Secret!2026x', '_make_employee' => '1',
        ])->assertRedirect();

        $emp = Employee::where('email', 'hind@test.local')->first();
        $this->assertNotNull($emp, 'طُلب ملفٌّ وظيفي للحساب الجديد ولم يُنشأ');
        $this->assertSame(User::where('email', 'hind@test.local')->value('id'), $emp->user_id);
    }

    /** ولا يُفتح حسابٌ لمن لا يملك إدارة المستخدمين */
    public function test_creating_an_account_needs_the_users_flag(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'موظف', 'scope' => 'own', 'flags' => [], 'matrix' => []]);
        $hr = Role::create(['name' => 'موارد بشرية', 'scope' => 'all', 'flags' => [],
            'matrix' => ['hr' => ['v' => 1, 'a' => 1, 'e' => 1]]]);
        $u = User::create(['name' => 'مسؤول ملفات', 'email' => 'hrx@test.local',
            'password' => 'Secret!2026x', 'role_id' => $hr->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->actingAs($u)->post('/m/hr', [
            'name' => 'راشد', 'email' => 'rashed@test.local', 'status' => 'نشط',
            '_make_account' => '1', '_account_role' => $role->id,
        ]);

        $this->assertNull(User::where('email', 'rashed@test.local')->first(),
            'من لا يملك إدارة المستخدمين فتح حساباً من باب ملفات الموظفين');
        $this->assertNotNull(Employee::where('email', 'rashed@test.local')->first(),
            'ولم يُحفظ الملف الوظيفي أصلاً — رُفض ما كان مسموحاً');
    }

    /** ولا تُمنح الملكية من بابٍ خلفي */
    public function test_owner_role_is_not_handed_out_through_the_employee_form(): void
    {
        $this->seedCore();
        $ownerRole = Role::where('is_owner', true)->firstOrFail();
        $admin = Role::create(['name' => 'إداري', 'scope' => 'all', 'flags' => ['users' => 1],
            'matrix' => ['hr' => ['v' => 1, 'a' => 1, 'e' => 1]]]);
        $u = User::create(['name' => 'إداري', 'email' => 'adm@test.local',
            'password' => 'Secret!2026x', 'role_id' => $admin->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->actingAs($u)->post('/m/hr', [
            'name' => 'متسلّق', 'email' => 'climb@test.local', 'status' => 'نشط',
            '_make_account' => '1', '_account_role' => $ownerRole->id,
        ])->assertForbidden();

        $this->assertNull(User::where('email', 'climb@test.local')->first());
    }

    /**
     * **العرضُ ليس المنح**: شرطُ التعيين كان
     * `$role && hub_can(users,'v') || $role && hub_flag(users)` — وأسبقيةُ
     * `&&` على `||` تجعل **عرضَ** المستخدمين وحده كافياً لفتح حساب.
     */
    public function test_viewing_users_does_not_let_you_open_an_account_while_hiring(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'موظف', 'scope' => 'own', 'flags' => [], 'matrix' => []]);
        $peeker = Role::create(['name' => 'مطّلع', 'scope' => 'all', 'flags' => [],
            'matrix' => ['users' => ['v' => 1], 'recruit' => ['v' => 1, 'e' => 1],
                         'hr' => ['v' => 1, 'a' => 1]]]);
        $u = User::create(['name' => 'مطّلع', 'email' => 'peek@test.local',
            'password' => 'Secret!2026x', 'role_id' => $peeker->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $c = \App\Models\Candidate::create(['name' => 'مرشح', 'job' => 'مطوّر',
            'email' => 'cand@test.local', 'stage' => 'مقابلة']);

        $this->actingAs($u)->post("/candidates/{$c->id}/hire", ['role_id' => $role->id]);

        $this->assertNull(User::where('email', 'cand@test.local')->first(),
            'من يملك «عرض المستخدمين» وحده فتح حساباً — والعرضُ ليس المنح');
        // **ولا يُمنَع التعيينُ نفسه**: الرافد يُسقط ما لا يُملَك ويمضي بما يُملَك.
        // بالشرط القديم كان الحارس يرمي ٤٠٣ فيسقط التعيينُ كلُّه ولا يُعيَّن أحد.
        $this->assertNotNull(Employee::where('email', 'cand@test.local')->first(),
            'رُفض التعيينُ كلُّه بدل أن يُسقَط فتحُ الحساب وحده');
        $this->assertSame('تم التعيين', $c->fresh()->stage);
    }

    /* ── انتهاء الخدمة يُغلق الباب ── */

    public function test_ending_service_suspends_the_linked_account(): void
    {
        $this->seedCore();
        $u = User::create(['name' => 'مغادر', 'email' => 'leaver@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $emp = Employee::create(['name' => 'مغادر', 'email' => 'leaver@test.local',
            'status' => 'نشط', 'user_id' => $u->id]);

        $emp->update(['status' => 'منتهية خدمته']);

        $this->assertSame('موقوف', $u->fresh()->status,
            'انتهت خدمتُه وبقي حسابُه يقرأ ويكتب — الملفُّ يقول ذهب والنظام يقول موجود');
    }

    /** والإيقاف كذلك — لا فرق بين الغياب المؤقت والدائم في الوصول */
    public function test_suspending_an_employee_suspends_the_account(): void
    {
        $this->seedCore();
        $u = User::create(['name' => 'موقوف', 'email' => 'sus@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $emp = Employee::create(['name' => 'موقوف', 'email' => 'sus@test.local',
            'status' => 'نشط', 'user_id' => $u->id]);

        $emp->update(['status' => 'موقوف']);

        $this->assertSame('موقوف', $u->fresh()->status);
    }

    /** وحذف الملف يُغلق الحساب — لا حسابٌ حيٌّ بلا ملفّ يحكمه */
    public function test_deleting_an_employee_file_closes_the_account(): void
    {
        $this->seedCore();
        $u = User::create(['name' => 'محذوف', 'email' => 'del@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $emp = Employee::create(['name' => 'محذوف', 'email' => 'del@test.local',
            'status' => 'نشط', 'user_id' => $u->id]);

        $emp->delete();

        $this->assertSame('موقوف', $u->fresh()->status);
    }

    /**
     * **والعودةُ لا تُعيد الوصول تلقائياً**: إعادةُ التنشيط قرارٌ يُتَّخذ لا أثرٌ
     * جانبيّ. من عاد يُنبَّه من يدير المستخدمين ليفتح حسابه بعد مراجعة صلاحياته.
     */
    public function test_reactivating_does_not_silently_restore_access(): void
    {
        $this->seedCore();
        $u = User::create(['name' => 'عائد', 'email' => 'back@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'موقوف', 'password_changed_at' => now()]);
        $emp = Employee::create(['name' => 'عائد', 'email' => 'back@test.local',
            'status' => 'موقوف', 'user_id' => $u->id]);

        $emp->update(['status' => 'نشط']);

        $this->assertSame('موقوف', $u->fresh()->status,
            'عاد الملفُّ فانفتح الحساب تلقائياً — إعادةُ الوصول قرارٌ لا أثرٌ جانبي');
        $this->assertGreaterThan(0, \App\Models\HubNotification::where('user_id', $this->owner->id)
            ->where('text', 'LIKE', '%عاد%')->count(), 'ولا أحد أُبلغ ليفتح له الحساب');
    }

    /* ── شاشة الفجوات ── */

    public function test_the_staff_screen_lists_who_has_no_account_and_who_has_no_file(): void
    {
        $this->seedCore();
        Employee::create(['name' => 'موظفٌ بلا حساب', 'email' => 'gap1@test.local', 'status' => 'نشط']);
        User::create(['name' => 'حسابٌ بلا ملف', 'email' => 'gap2@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($this->owner)->get('/staff')->assertOk()
            ->assertSee('موظفٌ بلا حساب')->assertSee('حسابٌ بلا ملف');
    }

    public function test_the_staff_screen_needs_permission(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'بلا شيء', 'scope' => 'all', 'flags' => [], 'matrix' => []]);
        $u = User::create(['name' => 'بلا صلاحيات', 'email' => 'nil@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->actingAs($u)->get('/staff')->assertForbidden();
    }

    /** والربط بنقرةٍ من الشاشة نفسها */
    public function test_linking_from_the_screen_joins_the_two_sides(): void
    {
        $this->seedCore();
        $emp = Employee::create(['name' => 'فيصل', 'email' => 'f@test.local', 'status' => 'نشط']);
        $u = User::create(['name' => 'فيصل', 'email' => 'other@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($this->owner)->post("/staff/{$emp->id}/link", ['user_id' => $u->id])
            ->assertRedirect();

        $this->assertSame($u->id, $emp->fresh()->user_id);
    }

    /** وفتحُ حسابٍ من الشاشة يُظهر كلمةً مؤقتة مرةً واحدة */
    public function test_opening_an_account_from_the_screen_shows_a_one_time_password(): void
    {
        $this->seedCore();
        $emp = Employee::create(['name' => 'جديد', 'email' => 'new@test.local', 'status' => 'نشط']);
        $role = Role::create(['name' => 'موظف', 'scope' => 'own', 'flags' => [], 'matrix' => []]);

        $this->actingAs($this->owner)->post("/staff/{$emp->id}/account", ['role_id' => $role->id])
            ->assertRedirect()->assertSessionHas('temp_password');

        $u = User::where('email', 'new@test.local')->firstOrFail();
        $this->assertSame($u->id, $emp->fresh()->user_id);
        $this->assertNull($u->password_changed_at);
    }

    /** وملفٌّ بلا بريدٍ لا يُفتح له حساب — البريد هو الهوية */
    public function test_an_account_needs_an_email_in_the_file(): void
    {
        $this->seedCore();
        $emp = Employee::create(['name' => 'بلا بريد', 'status' => 'نشط']);
        $role = Role::create(['name' => 'موظف', 'scope' => 'own', 'flags' => [], 'matrix' => []]);

        $this->actingAs($this->owner)->post("/staff/{$emp->id}/account", ['role_id' => $role->id])
            ->assertSessionHasErrors();
    }

    /** والتوحيد ينقل اسم الملف إلى الحساب */
    public function test_aligning_copies_the_file_name_onto_the_account(): void
    {
        $this->seedCore();
        $u = User::create(['name' => 'الاسم القديم', 'email' => 'al@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $emp = Employee::create(['name' => 'الاسم الصحيح', 'email' => 'al@test.local',
            'status' => 'نشط', 'user_id' => $u->id]);

        $this->actingAs($this->owner)->post("/staff/{$emp->id}/align")->assertRedirect();

        $this->assertSame('الاسم الصحيح', $u->fresh()->name);
    }

    /** ولا يكسر التوحيدُ دخولَ أحد ببريدٍ مأخوذ */
    public function test_aligning_never_steals_an_email_another_account_logs_in_with(): void
    {
        $this->seedCore();
        $other = User::create(['name' => 'صاحب البريد', 'email' => 'shared@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $u = User::create(['name' => 'حساب', 'email' => 'mine@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $emp = Employee::create(['name' => 'حساب', 'email' => 'shared@test.local',
            'status' => 'نشط', 'user_id' => $u->id]);

        $this->actingAs($this->owner)->post("/staff/{$emp->id}/align");

        $this->assertSame('mine@test.local', $u->fresh()->email, 'انتُزع بريدٌ يُسجّل به آخرُ دخولَه');
        $this->assertSame('shared@test.local', $other->fresh()->email);
    }

    /** وفكُّ الربط يُصحّح خطأً بلا حذف طرف */
    public function test_unlinking_keeps_both_sides_alive(): void
    {
        $this->seedCore();
        $u = User::create(['name' => 'مربوط', 'email' => 'lnk@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $emp = Employee::create(['name' => 'مربوط', 'email' => 'lnk@test.local',
            'status' => 'نشط', 'user_id' => $u->id]);

        $this->actingAs($this->owner)->post("/staff/{$emp->id}/unlink")->assertRedirect();

        $this->assertNull($emp->fresh()->user_id);
        $this->assertNotNull($emp->fresh());
        $this->assertSame('نشط', $u->fresh()->status, 'فكُّ الربط أوقف حساباً لم يُطلب إيقافه');
    }

    /** والفجوةُ الخطرة تُعرض: خدمةٌ انتهت وحسابٌ حيّ (ممّا تراكم قبل السكّة) */
    public function test_the_screen_surfaces_a_live_account_behind_an_ended_file(): void
    {
        $this->seedCore();
        $u = User::create(['name' => 'شبح', 'email' => 'ghost@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        // حالةٌ تاريخية: كُتبت في القاعدة بلا مرورٍ بالسكّة
        $emp = Employee::create(['name' => 'شبح', 'email' => 'ghost@test.local',
            'status' => 'نشط', 'user_id' => $u->id]);
        \Illuminate\Support\Facades\DB::table('employees')->where('id', $emp->id)
            ->update(['status' => 'منتهية خدمته']);

        $this->actingAs($this->owner)->get('/staff')->assertOk()
            ->assertSee('خدمةٌ انتهت وحسابٌ حيّ');
    }

    /** ولا يُربط حسابٌ مأخوذ */
    public function test_linking_refuses_an_account_already_taken(): void
    {
        $this->seedCore();
        $u = User::create(['name' => 'مأخوذ', 'email' => 'taken@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        Employee::create(['name' => 'الأول', 'email' => 'a1@test.local', 'status' => 'نشط', 'user_id' => $u->id]);
        $second = Employee::create(['name' => 'الثاني', 'email' => 'a2@test.local', 'status' => 'نشط']);

        $this->actingAs($this->owner)->post("/staff/{$second->id}/link", ['user_id' => $u->id])
            ->assertSessionHasErrors();

        $this->assertNull($second->fresh()->user_id);
    }
}
