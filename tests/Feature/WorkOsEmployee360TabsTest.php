<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **امتدادُ Work OS (WP-F.4 · §28):** الملفُّ الشامل للموظف (٣٦٠) صار تبويباتٍ
 * **محروسةً خادميّاً** — لا مجرَّدَ إخفاءٍ في العرض.
 *
 * يمتدّ نمطَ `FieldPermissionBypassTest` بحرفه: حارسٌ (hub_can / hub_field_mode)
 * يُفرض في مسارٍ ولا يُلتَفّ عليه بمسارٍ جديد — والتبويبُ لا يكون الالتفافَ الجديد.
 *
 *  • **حجبُ الحقل عبر التبويبات:** دورُ الموارد البشرية يرى مقاعدَ الموظف وعهدتَه
 *    لكن **السيريالَ التقنيَّ محجوبٌ عنه** (hub_field_mode)، والدورُ التقنيُّ يرى
 *    الجهازَ لكن **الراتبَ محجوبٌ عنه** — كلاهما في الملفّ نفسِه، بتبويبَين.
 *  • **الحرسُ الخادميّ:** GET مباشرٌ لتبويبٍ لا يملك القارئُ وحدتَه → **٤٠٣** لا
 *    مجرَّدُ غيابٍ في الشريط؛ فلا يُغسَل عزلُ الوحدة بطلبِ `?tab=` مصنوعٍ باليد.
 *  • **لا سكّةٌ زائفة (§82):** تبويباتُ الاتصالات/الأنظمة/أمنِ النقاط (الأطوار G/J)
 *    **لا تُعرَض** حتى تصل سككُها — لا بطاقةٌ فارغةٌ ولا لوحةٌ صامتة (٤٠٤ لِـtab مجهول).
 *  • **تبويبُ المحطة (F.1) يتّصل حقّاً:** يقرأ محطاتِ الموظف بـ`current_employee_id`،
 *    منطَّقاً بالشركة كأيّ قارئ.
 */
class WorkOsEmployee360TabsTest extends TestCase
{
    /** دورٌ بمصفوفةٍ وقيودِ حقولٍ محدَّدة (نمطُ ScopeLeakAuditTest::withRole) */
    protected function role360(array $matrix, array $fieldRules = [], array $flags = []): User
    {
        $role = Role::create(['name' => 'دور ٣٦٠ ' . Str::random(5), 'scope' => 'all',
            'flags' => $flags, 'matrix' => $matrix, 'field_rules' => $fieldRules]);

        return User::create(['name' => 'قارئُ ٣٦٠', 'email' => Str::random(8) . '@int.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /** موظفٌ معروضٌ بحسابه: راتبٌ متمايز، جهازٌ بسيريالٍ سرّيّ، ومقعدٌ مُسنَدٌ إليه */
    protected function scene(): array
    {
        $this->seedCore();

        $acct = User::create(['name' => 'صاحبُ الملف', 'email' => 'target360@int.local',
            'password' => 'Secret!2026x', 'status' => 'نشط', 'password_changed_at' => now()]);

        $emp = Employee::create(['name' => 'مهندسُ الشبكات', 'status' => 'نشط',
            'user_id' => $acct->id, 'salary' => 727272]);

        // holder_id يُكتَب عادةً عبر دفتر العهدة — هنا نبذره مباشرةً بالنموذج
        $asset = Asset::create(['name' => 'لابتوبُ الميدان', 'type' => 'لابتوب',
            'serial' => 'SN-SECRET-9001', 'status' => 'قيد الاستخدام', 'holder_id' => $acct->id]);

        // current_employee_id يُكتَب عادةً عبر مسار الإسناد — هنا نبذره مباشرةً
        $station = Station::create(['facility' => 'المقرّ الرئيسيّ', 'zone' => 'أ',
            'desk' => '12', 'type' => 'مكتب', 'status' => 'مشغولة',
            'current_employee_id' => $acct->id]);

        return compact('acct', 'emp', 'asset', 'station');
    }

    /** ① دورُ الموارد البشرية لا يرى السرَّ التقنيَّ (السيريال) — hub_field_mode لا غياب */
    public function test_an_hr_role_does_not_see_the_device_technical_secret(): void
    {
        ['emp' => $emp, 'asset' => $asset] = $this->scene();

        // hr:v (يفتح الملف) + assets:v (يرى تبويبَ العهدة) لكنّ السيريالَ محجوبٌ عنه
        $hr = $this->role360(['hr' => ['v' => 1], 'assets' => ['v' => 1]],
            ['assets' => ['serial' => 'hide']]);

        // المالكُ يراه — فالحجبُ صلاحيةٌ لا حذفٌ للبيانات (التبويبُ يعرضُ فعلاً)
        $this->actingAs($this->owner)->get(route('portal.employee', $emp->id) . '?tab=assets')
            ->assertOk()->assertSee('لابتوبُ الميدان')->assertSee('SN-SECRET-9001');

        // دورُ HR: التبويبُ يظهر واسمُ الجهاز يظهر، لكنّ السيريالَ لا — لا خاماً
        $res = $this->actingAs($hr)->get(route('portal.employee', $emp->id) . '?tab=assets')->assertOk();
        $res->assertSee('لابتوبُ الميدان');
        $res->assertDontSee('SN-SECRET-9001');
    }

    /** ② الدورُ التقنيُّ لا يرى الراتب — hub_field_mode في تبويب الملف */
    public function test_a_technical_role_does_not_see_the_salary(): void
    {
        ['emp' => $emp] = $this->scene();

        // hr:v (يفتح الملف) + assets:v (تقنيّ) لكنّ الراتبَ محجوبٌ عنه
        $tech = $this->role360(['hr' => ['v' => 1], 'assets' => ['v' => 1], 'stations' => ['v' => 1]],
            ['hr' => ['salary' => 'hide']]);

        // المالكُ يرى الراتبَ في تبويب الملف — فالتوكيدُ المقابل ذو معنى
        $this->actingAs($this->owner)->get(route('portal.employee', $emp->id))
            ->assertOk()->assertSee('727,272');

        // التقنيّ: لا الراتبُ الخام ولا المنسّق
        $res = $this->actingAs($tech)->get(route('portal.employee', $emp->id))->assertOk();
        foreach (['727272', '727,272'] as $n) $res->assertDontSee($n);
    }

    /** ③ الحرسُ الخادميّ: GET مباشرٌ لتبويبٍ بلا صلاحيةِ وحدته → ٤٠٣ (لا مجرَّدُ إخفاء) */
    public function test_a_direct_get_of_a_tab_without_permission_is_forbidden(): void
    {
        ['emp' => $emp] = $this->scene();

        // hr:v وحدها — يفتح الملفَّ (التبويبُ الافتراضيّ) لكن لا وحداتِ التبويبات الأخرى
        $hrOnly = $this->role360(['hr' => ['v' => 1]]);

        // الملفُّ الافتراضيّ متاح
        $this->actingAs($hrOnly)->get(route('portal.employee', $emp->id))->assertOk();

        // وكلُّ تبويبٍ لا يملك وحدتَه: ٤٠٣ — لا صفحةٌ صامتةٌ ولا لوحةٌ مخفيّة
        foreach (['assets', 'station', 'wallet'] as $tab) {
            $this->actingAs($hrOnly)->get(route('portal.employee', $emp->id) . '?tab=' . $tab)
                ->assertForbidden();
        }

        // ودورٌ يملك المحطاتِ يفتح تبويبَها — الحرسُ صلاحيةٌ لا حجبٌ شامل
        $withSta = $this->role360(['hr' => ['v' => 1], 'stations' => ['v' => 1]]);
        $this->actingAs($withSta)->get(route('portal.employee', $emp->id) . '?tab=station')->assertOk();
    }

    /** ④ لا بطاقةٌ زائفةٌ لسكّةٍ لم تصل (§82): Telecom/Systems/EndpointSecurity غائبةٌ عن الشريط */
    public function test_no_placeholder_tab_for_an_unbuilt_rail(): void
    {
        ['emp' => $emp] = $this->scene();

        // المالكُ يملك كلَّ الوحدات — ومع ذلك لا تبويبَ لسكّةٍ لم تُبنَ بعد
        $html = $this->actingAs($this->owner)->get(route('portal.employee', $emp->id))
            ->assertOk()->getContent();

        foreach (['tab=telecom', 'tab=systems', 'tab=endpoints', 'tab=endpoint'] as $ghost) {
            $this->assertStringNotContainsString($ghost, $html,
                "شريطُ الملفّ عرض تبويباً لسكّةٍ لم تُبنَ بعد ({$ghost}) — بطاقةٌ زائفةٌ يمنعها §82");
        }

        // وطلبُ تبويبٍ مجهولٍ باليد لا يفتح لوحةً صامتة — ٤٠٤ لا ٢٠٠
        $this->actingAs($this->owner)->get(route('portal.employee', $emp->id) . '?tab=telecom')
            ->assertNotFound();
    }

    /** ⑤ تبويبُ المحطة (F.1) يتّصل حقّاً: يقرأ مقعدَ الموظف بـcurrent_employee_id */
    public function test_the_station_tab_shows_the_seat_assigned_to_the_employee(): void
    {
        ['acct' => $acct, 'emp' => $emp, 'station' => $station] = $this->scene();

        // مقعدٌ لموظفٍ آخر — يجب ألّا يظهر في تبويب هذا الموظف
        $other = Station::create(['facility' => 'فرعٌ آخر', 'desk' => '99', 'type' => 'مكتب',
            'status' => 'مشغولة', 'current_employee_id' => $this->employee->id]);

        $res = $this->actingAs($this->owner)->get(route('portal.employee', $emp->id) . '?tab=station')
            ->assertOk();
        $res->assertSee($station->code);        // مقعدُه
        $res->assertDontSee($other->code);      // لا مقعدَ غيره
    }
}
