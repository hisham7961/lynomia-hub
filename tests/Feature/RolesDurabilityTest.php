<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ثلاثة أبوابٍ تُفقد بها الصلاحيات **بلا أن يفعل أحدٌ شيئاً خاطئاً**:
 *
 *  • **التقطيع الصامت**: نموذج الأدوار كان يرسل قائمةً منسدلة لكل حقلٍ في
 *    النظام (١٢٢٠ قائمة) فيتجاوز الطلب `max_input_vars` الافتراضي (١٠٠٠) —
 *    وPHP **يقصّ** الزائد بلا خطأ. والمتحكّم يعيد بناء `field_rules` من الصفر
 *    فيمحو كل قيدٍ سقط في الذيل المقصوص: يحفظ المالك تعديلاً في اسم الدور
 *    فيُكشف الراتب لعشرات الأدوار صمتاً.
 *
 *  • **القفل الدائم**: آخر مالكٍ نشط يستطيع تجريد نفسه بتبديل دوره — لا يُوقِف
 *    نفسه ولا يحذفها (وهما محروسان) بل يختار دوراً آخر. وبعدها لا أحد يفتح
 *    الأدوار ولا الإعدادات ولا مركز الأمان، وإعادة الملكية تحتاج مالكاً.
 *
 *  • **النسخة الاحتياطية الناقصة**: لا تحفظ `field_rules` ولا عزل الشركات ولا
 *    حارسَي الحساب — فالاستعادة تُعيد النظام **بلا قيدٍ واحد**، وهي أخطر من
 *    ألا تكون هناك نسخة أصلاً لأنها تُظنّ استعادةً كاملة.
 */
class RolesDurabilityTest extends TestCase
{
    /** حجم النموذج: ما يُرسَل فعلاً يجب أن يبقى دون سقف PHP بهامشٍ واسع */
    public function test_the_roles_form_stays_under_the_php_input_limit(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/admin/roles/create')->assertOk()->getContent();

        // كل `<select>` و`<textarea>` يُرسَل دائماً مهما كانت قيمته
        $always = preg_match_all('/<(select|textarea)\s/i', $html)
                + preg_match_all('/<input[^>]+type="hidden"/i', $html)
                + preg_match_all('/<input[^>]+type="(text|number|date|email|url)"/i', $html);

        $this->assertLessThan(900, $always,
            "النموذج يرسل {$always} متغيّراً دائماً — وسقف PHP الافتراضي ١٠٠٠، فما زاد يُقصّ بصمت وتُمحى قيود الحقول");
    }

    /** والأهم من العدّ: حفظُ تعديلٍ لا يمسّ الحقول لا يمحو قيودها */
    public function test_saving_the_name_alone_does_not_erase_field_restrictions(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'محاسبة', 'scope' => 'all', 'flags' => ['exp' => 1],
            'matrix' => ['fin' => ['v' => 1, 'e' => 1]],
            'field_rules' => ['hr' => ['salary' => 'hide'], 'fin' => ['total' => 'ro']]]);

        // طلبٌ مقصوص كما تُنتجه PHP: وصل الاسم والنطاق والمصفوفة، وسقط قسم الحقول
        $this->actingAs($this->owner)->put("/admin/roles/{$role->id}", [
            'name' => 'محاسبة أولى', 'scope' => 'all', 'flags_submitted' => 1,
            'flags' => ['exp' => '1'],
            'matrix' => ['fin' => ['v' => '1', 'e' => '1']],
        ])->assertRedirect();

        $fresh = Role::find($role->id);
        $this->assertSame('hide', $fresh->field_rules['hr']['salary'] ?? null,
            'قيدٌ على الراتب اختفى بحفظِ اسمٍ — كشفٌ صامت لكل من يحمل الدور');
        $this->assertSame('محاسبة أولى', $fresh->name);
    }

    /** ومتى أُرسل القسم صراحةً فالمُرسَل هو الحقيقة — بما فيه المسح المتعمَّد */
    public function test_an_explicit_empty_field_rules_section_does_clear_them(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'دور', 'scope' => 'all', 'flags' => [],
            'matrix' => ['fin' => ['v' => 1]], 'field_rules' => ['hr' => ['salary' => 'hide']]]);

        $this->actingAs($this->owner)->put("/admin/roles/{$role->id}", [
            'name' => 'دور', 'scope' => 'all', 'flags_submitted' => 1,
            'matrix' => ['fin' => ['v' => '1']], 'fr_submitted' => 1,
        ])->assertRedirect();

        $this->assertEmpty(Role::find($role->id)->field_rules ?? []);
    }

    /** القالب يملأ ما لم يُرسَل، ولا يعلو على اختيارٍ صريح */
    public function test_a_template_never_overrides_an_explicit_choice(): void
    {
        $this->seedCore();
        $tpl = 'finance';

        // المالك اختار القالب ثم أزال كل الرايات عمداً وأبقى وحدةً واحدة
        $this->actingAs($this->owner)->post('/admin/roles', [
            'name' => 'محاسب محدود', 'scope' => 'all', 'template' => $tpl,
            'flags_submitted' => 1, 'matrix_submitted' => 1,
            'matrix' => ['fin' => ['v' => '1']],
        ])->assertRedirect();

        $role = Role::where('name', 'محاسب محدود')->firstOrFail();
        $this->assertSame([], $role->flags ?? [], 'القالب أعاد رايات أزالها المالك عمداً');
        $this->assertSame(['fin'], array_keys($role->matrix ?? []),
            'القالب أعاد وحداتٍ أزالها المالك عمداً');
    }

    // ── القفل الدائم ──

    public function test_the_last_owner_cannot_demote_himself(): void
    {
        $this->seedCore();
        $plain = Role::where('is_owner', false)->firstOrFail();

        $this->actingAs($this->owner)->put("/admin/users/{$this->owner->id}", [
            'name' => 'المالك', 'email' => 'owner@test.local',
            'role_id' => $plain->id, 'status' => 'نشط',
        ]);

        $this->assertTrue((bool) User::find($this->owner->id)->role->is_owner,
            'آخر مالكٍ جرّد نفسه — لا أحد يفتح الأدوار ولا الإعدادات ولا الأمان، وإعادة الملكية تحتاج مالكاً');
    }

    public function test_an_owner_may_step_down_once_another_owner_exists(): void
    {
        $this->seedCore();
        $ownerRole = Role::where('is_owner', true)->firstOrFail();
        User::create(['name' => 'مالكٌ ثانٍ', 'email' => 'own2@test.local', 'password' => 'Secret!2026x',
            'role_id' => $ownerRole->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $plain = Role::where('is_owner', false)->firstOrFail();
        $this->actingAs($this->owner)->put("/admin/users/{$this->owner->id}", [
            'name' => 'المالك', 'email' => 'owner@test.local',
            'role_id' => $plain->id, 'status' => 'نشط',
        ])->assertRedirect();

        $this->assertFalse((bool) User::find($this->owner->id)->role->is_owner);
    }

    /** ومن يملك راية «إدارة المستخدمين» لا يفكّ عزل شركاته عن نفسه */
    public function test_a_user_admin_cannot_lift_his_own_company_isolation(): void
    {
        $this->seedCore();
        $co = \App\Models\Company::create(['name_ar' => 'شركتي', 'status' => 'نشطة']);
        $role = Role::create(['name' => 'إداري', 'scope' => 'all', 'flags' => ['users' => 1], 'matrix' => []]);
        $u = User::create(['name' => 'إداري', 'email' => 'ua@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => [$co->id]]);

        $this->actingAs($u)->put("/admin/users/{$u->id}", [
            'name' => 'إداري', 'email' => 'ua@test.local', 'role_id' => $role->id,
            'status' => 'نشط', 'companies' => [],
        ]);

        $this->assertSame([$co->id], User::find($u->id)->companies,
            'فكّ عزل شركاته عن نفسه — يرى المنشأة كلها بضغطة');
    }

    // ── النسخة الاحتياطية ──

    public function test_a_backup_carries_every_restriction_it_promises_to_restore(): void
    {
        $this->seedCore();
        $co = \App\Models\Company::create(['name_ar' => 'شركة', 'status' => 'نشطة']);
        $role = Role::create(['name' => 'مقيّد', 'scope' => 'proj', 'flags' => ['exp' => 1],
            'matrix' => ['hr' => ['v' => 1]], 'field_rules' => ['hr' => ['salary' => 'hide']]]);
        User::create(['name' => 'معزولة', 'email' => 'iso@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => [$co->id], 'allowed_ips' => '10.0.0.0/8',
            'expires_at' => now()->addYear()->toDateString()]);

        // ‎--keep عالٍ عمداً: التشذيب الافتراضي يحذف نسخاً تعتمد عليها اختباراتٌ
        // أخرى، والملف الذي ننشئه هنا يُزال في نهاية الاختبار
        $before = glob(storage_path('app/backups/*.json')) ?: [];
        $this->artisan('hub:backup --keep=999')->assertSuccessful();

        $file = collect(array_diff(glob(storage_path('app/backups/*.json')) ?: [], $before))->first();
        $this->assertNotNull($file, 'لم يُكتب ملف نسخة');
        $data = json_decode((string) file_get_contents($file), true);
        @unlink($file);

        $r = collect($data['roles'] ?? [])->firstWhere('name', 'مقيّد');
        $this->assertSame('hide', $r['fieldRules']['hr']['salary'] ?? null,
            'النسخة لا تحمل قيود الحقول — الاستعادة تُعيد النظام مكشوفاً وتُظنّ كاملة');

        $u = collect($data['users'] ?? [])->firstWhere('email', 'iso@test.local');
        $this->assertSame([$co->id], $u['companies'] ?? null, 'عزل الشركات لا يُنسَخ');
        $this->assertSame('10.0.0.0/8', $u['allowedIps'] ?? null, 'قائمة العناوين لا تُنسَخ');
        $this->assertNotEmpty($u['expiresAt'] ?? null, 'انتهاء الحساب لا يُنسَخ');
    }

    /** وكل مسٍّ للأدوار يترك أثراً — الشاشة التي تصنع الصلاحية لا تكون خفيّة */
    public function test_role_changes_are_audited(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/admin/roles',
            ['name' => 'دورٌ جديد', 'scope' => 'all', 'flags_submitted' => 1, 'matrix_submitted' => 1]);

        $this->assertTrue(
            DB::table('audits')->where('module', 'roles')->exists(),
            'إنشاء دورٍ لا يترك قيد تدقيق — الشاشة التي تمنح الصلاحيات غير مرئيةٍ للتدقيق'
        );
    }
}
