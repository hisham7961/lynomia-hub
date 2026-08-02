<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * الأدوار والمستخدمون — بابان مفتوحان وتجربةٌ مُنهِكة.
 *
 *  • **تصعيدُ صلاحيات**: شاشة المستخدمين محروسةٌ براية `users` وحدها لا
 *    بالملكية، و`role_id` يُتحقَّق منه بـ`exists:roles,id` فقط — فمن يملك
 *    «إدارة المستخدمين» يمنح نفسه **دور المالك** بنقرة، أو يفتح حساب مالكٍ
 *    قائم ويبدّل كلمة مروره فيستولي عليه. رايةٌ تُمنح لموظف إدارية تصير
 *    مفتاح النظام كله.
 *
 *  • **إسقاط المالك**: `RoleController::data()` يكتب `is_owner => false` في
 *    كل حفظ بلا استثناء، و`update()` بلا حارس — فطلبُ PUT واحدٌ على دور
 *    المالك (والمسار مفتوح ولو أخفت الشاشة زرّه) يجرّده من الملكية. وإن كان
 *    الدور المالك الوحيد أُقفلت الأدوار والإعدادات ومركز الأمان **إلى الأبد**:
 *    لا أحد يستطيع إعادة الملكية لأن إعادتها تحتاج مالكاً.
 *
 *  • **تجربة مُنهِكة**: النموذج ٢٩٢ مربع اختيار و~١٢٢٠ قائمة منسدلة في صفحةٍ
 *    واحدة مسطّحة: بلا بحثٍ ولا تجميعٍ ولا قالبٍ جاهز ولا استنساخ.
 *
 *  • وحقلان يفرضهما الدخول ولا سبيل لضبطهما: انتهاء الحساب وعناوين الشبكة.
 */
class RolesAndUsersTest extends TestCase
{
    /** حسابٌ يملك راية «إدارة المستخدمين» وليس مالكاً */
    protected function userAdmin(): User
    {
        $role = Role::create(['name' => 'إداري مستخدمين', 'scope' => 'all',
            'flags' => ['users' => 1], 'matrix' => []]);

        return User::create(['name' => 'إداري', 'email' => 'ua@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    protected function ownerRole(): Role
    {
        return Role::where('is_owner', true)->firstOrFail();
    }

    // ── تصعيد الصلاحيات ──

    public function test_a_user_admin_cannot_hand_out_the_owner_role(): void
    {
        $this->seedCore();
        $admin = $this->userAdmin();

        $this->actingAs($admin)->post('/admin/users', [
            'name' => 'حسابٌ مصنوع', 'email' => 'new@test.local', 'password' => 'Secret!2026x',
            'role_id' => $this->ownerRole()->id, 'status' => 'نشط',
        ])->assertForbidden();

        $this->assertNull(User::where('email', 'new@test.local')->first(),
            'غير المالك منح دور المالك — النظام كله بنقرة');
    }

    public function test_a_user_admin_cannot_promote_himself(): void
    {
        $this->seedCore();
        $admin = $this->userAdmin();

        $this->actingAs($admin)->put("/admin/users/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email,
            'role_id' => $this->ownerRole()->id, 'status' => 'نشط',
        ])->assertForbidden();

        $this->assertNotSame($this->ownerRole()->id, User::find($admin->id)->role_id);
    }

    public function test_a_user_admin_cannot_touch_an_owner_account(): void
    {
        $this->seedCore();
        $admin = $this->userAdmin();
        $before = $this->owner->password;

        // الاستيلاء: تبديل كلمة مرور المالك من شاشة المستخدمين
        $this->actingAs($admin)->put("/admin/users/{$this->owner->id}", [
            'name' => 'المالك', 'email' => 'owner@test.local', 'role_id' => $this->owner->role_id,
            'status' => 'نشط', 'password' => 'Hacked!2026x',
        ])->assertForbidden();
        $this->assertSame($before, User::find($this->owner->id)->password);

        // ولا إيقافه ولا حذفه
        $this->actingAs($admin)->delete("/admin/users/{$this->owner->id}")->assertForbidden();
        $this->assertNotNull(User::find($this->owner->id));
    }

    public function test_the_owner_still_can_do_all_of_that(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/admin/users', [
            'name' => 'مالكٌ ثانٍ', 'email' => 'own2@test.local', 'password' => 'Secret!2026x',
            'role_id' => $this->ownerRole()->id, 'status' => 'نشط',
        ])->assertRedirect();

        $this->assertNotNull(User::where('email', 'own2@test.local')->first());
    }

    /** كلمةٌ جديدة تعني تاريخاً جديداً — وإلا كذب فحصُ «لم تُجدَّد منذ سنة» */
    public function test_setting_a_password_stamps_its_date(): void
    {
        $this->seedCore();
        $u = User::create(['name' => 'قديمة', 'email' => 'old@test.local', 'password' => 'Secret!2026x',
            'role_id' => $this->employee->role_id, 'status' => 'نشط',
            'password_changed_at' => now()->subYears(2)]);

        $this->actingAs($this->owner)->put("/admin/users/{$u->id}", [
            'name' => 'قديمة', 'email' => 'old@test.local', 'role_id' => $u->role_id,
            'status' => 'نشط', 'password' => 'Secret!2026y',
        ])->assertRedirect();

        $this->assertTrue(User::find($u->id)->password_changed_at->gt(now()->subMinute()));
    }

    /** حقلان يفرضهما الدخول فعلاً ولا مدخل لهما — ضبطٌ مستحيل */
    public function test_login_guards_can_finally_be_set(): void
    {
        $this->seedCore();
        $u = $this->employee;

        $this->actingAs($this->owner)->put("/admin/users/{$u->id}", [
            'name' => $u->name, 'email' => $u->email, 'role_id' => $u->role_id, 'status' => 'نشط',
            'expires_at' => now()->addDays(30)->toDateString(),
            'allowed_ips' => '10.0.0.0/8, 203.0.113.7',
        ])->assertRedirect();

        $fresh = User::find($u->id);
        $this->assertNotNull($fresh->expires_at, 'انتهاء الحساب يُفحص عند الدخول ولا يمكن ضبطه');
        $this->assertStringContainsString('10.0.0.0/8', (string) $fresh->allowed_ips);
    }

    // ── دور المالك ──

    public function test_the_owner_role_cannot_be_stripped_of_ownership(): void
    {
        $this->seedCore();
        $ownerRole = $this->ownerRole();

        $this->actingAs($this->owner)->put("/admin/roles/{$ownerRole->id}", [
            'name' => 'مالك', 'scope' => 'all', 'flags_submitted' => 1,
        ])->assertStatus(422);

        $this->assertTrue((bool) Role::find($ownerRole->id)->is_owner,
            'جُرّد دور المالك من الملكية — لا أحد يستطيع إعادتها لأن إعادتها تحتاج مالكاً');
    }

    // ── تجربة الأدوار ──

    public function test_a_role_can_be_built_from_a_template(): void
    {
        $this->seedCore();
        $tpl = array_key_first(config('hub_roles.templates'));

        $this->actingAs($this->owner)->post('/admin/roles', [
            'name' => 'محاسبة', 'scope' => 'all', 'template' => $tpl, 'flags_submitted' => 1,
        ])->assertRedirect();

        $role = Role::where('name', 'محاسبة')->firstOrFail();
        $this->assertNotEmpty($role->matrix, 'القالب لم يملأ شيئاً — فالبناء ما زال ٢٩٢ نقرة');
    }

    public function test_a_role_can_be_cloned(): void
    {
        $this->seedCore();
        $src = Role::create(['name' => 'أصل', 'scope' => 'proj', 'flags' => ['exp' => 1],
            'matrix' => ['tasks' => ['v' => 1, 'e' => 1]], 'field_rules' => ['hr' => ['salary' => 'hide']]]);

        $this->actingAs($this->owner)->post("/admin/roles/{$src->id}/clone")->assertRedirect();

        $copy = Role::where('name', 'LIKE', '%أصل%')->where('id', '!=', $src->id)->firstOrFail();
        // ترتيبُ مفاتيح JSON ليس عقداً (انظر FlowEditTest) — المقياسُ المحتوى
        $this->assertEquals(['v' => 1, 'e' => 1], $copy->matrix['tasks'] ?? null);
        $this->assertEquals(['exp' => 1], $copy->flags);
        $this->assertSame('hide', $copy->field_rules['hr']['salary'] ?? null);
        $this->assertFalse((bool) $copy->is_owner, 'النسخة وُلدت مالكة');
    }

    /** إضافةٌ أو تعديلٌ أو حذفٌ بلا عرض حالةٌ لا معنى لها — تُصحَّح لا تُحفَظ */
    public function test_write_permission_implies_read(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/admin/roles', [
            'name' => 'كاتبٌ أعمى', 'scope' => 'all', 'flags_submitted' => 1,
            'matrix' => ['tasks' => ['e' => '1', 'd' => '1']],
        ])->assertRedirect();

        $mx = Role::where('name', 'كاتبٌ أعمى')->firstOrFail()->matrix;
        $this->assertSame(1, $mx['tasks']['v'] ?? null,
            'دورٌ يعدّل ويحذف ولا يرى — الشاشة تعرض حالةً لا يمكن استعمالها');
    }

    public function test_the_roles_form_is_navigable_not_a_wall(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/admin/roles/create')->assertOk()->getContent();

        $this->assertStringContainsString('ابحث في الوحدات', $html, 'بحثٌ داخل المصفوفة');
        $this->assertStringContainsString('قالب جاهز', $html, 'قوالب تُغني عن ٢٩٢ نقرة');
        // مجموعات التنقّل السبع تُستعمل تجميعاً للمصفوفة
        foreach (['الكيانات', 'المالية والمشتريات', 'الموارد البشرية'] as $g) {
            $this->assertStringContainsString($g, $html, "المصفوفة غير مجمَّعة: $g");
        }
    }

    public function test_the_roles_screen_shows_what_each_role_actually_reaches(): void
    {
        $this->seedCore();
        Role::create(['name' => 'مشاهد محدود', 'scope' => 'proj', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1], 'fin' => ['v' => 1, 'e' => 1]]]);

        $html = $this->actingAs($this->owner)->get('/admin/roles')->assertOk()->getContent();
        $this->assertStringContainsString('يعدّل', $html, 'الجدول لا يقول ماذا يبلغ كل دور');
    }

    // ── المستخدمون ──

    public function test_the_users_list_answers_the_security_questions(): void
    {
        $this->seedCore();
        User::whereKey($this->employee->id)->update(['totp_enabled' => 1]);

        $html = $this->actingAs($this->owner)->get('/admin/users')->assertOk()->getContent();
        $this->assertStringContainsString('بلا تحقّق', $html, 'من بلا تحقّقٍ بخطوتين لا يظهر');

        // ومرشِّحاتٌ تجيب «من يملك ماذا»
        $this->actingAs($this->owner)->get('/admin/users?twofa=off')->assertOk()
            ->assertSee('المالك')->assertDontSee('موظفة');
    }

    public function test_a_user_page_explains_the_reach_of_his_role(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->get("/admin/users/{$this->employee->id}/edit")
            ->assertOk()->assertSee('ماذا يبلغ هذا الحساب');
    }
}
