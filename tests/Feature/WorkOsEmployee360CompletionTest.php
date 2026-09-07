<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PhoneNumber;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **اكتمالُ تبويبات الملفّ ٣٦٠** (Work OS · الطور M · WP-M.3 · §28/§74–81) —
 * يمتدّ `WorkOsEmployee360TabsTest` (الحرسُ الخادميّ للتبويبات) إلى آخرِ سكّتين:
 *
 *  · **الاتصالات (سكّةُ G.2):** خطوطُ الموظف بـ`phone_numbers.employee_id` —
 *    الأسرارُ (PIN/PUK) **لا تُنتقى أصلاً** من القارئ فلا تبلغ HTML بحالٍ
 *    (كشفُها مسارُ `revealSecret` وحدَه)، وICCID هويّةٌ تقنيّةٌ يحجبها
 *    `hub_field_mode` كسيريال العهدة — عرضٌ حرٌّ للمالك، محجوبٌ بقاعدةِ حقل.
 *  · **الأنظمة (سكّةُ H.1):** السيرفراتُ المربوطةُ بـ`servers.hr_id` — حافّةُ
 *    بنيةٍ (`edge`) طرفاها محروسان بالبناء: الصفحةُ تتطلب `hr:v` والتبويبُ
 *    `servers:v`، فقاعدةُ «الحافّةُ لمن يملك طرفَيها» (WorkOsInfraEdgesTest)
 *    مستوفاةٌ قبل أن يُحلَّ اسمُ سيرفرٍ واحد. وIP خلف `hub_field_mode`.
 *  · **صفرُ بطاقاتٍ زائفة (§82):** بوصول systems اكتمل السجلُّ —
 *    profile/assets/station/telecom/systems/endpoint/wallet كلُّها سككٌ حقيقيّة،
 *    وتبويبٌ مجهولٌ بعدُ ٤٠٤ لا لوحةٌ صامتة.
 */
class WorkOsEmployee360CompletionTest extends TestCase
{
    /** دورٌ بمصفوفةٍ وقيودِ حقول (نمطُ WorkOsEmployee360TabsTest::role360 بحرفه) */
    protected function role360(array $matrix, array $fieldRules = []): User
    {
        $role = Role::create(['name' => 'دور اكتمال ٣٦٠ ' . Str::random(5), 'scope' => 'all',
            'flags' => [], 'matrix' => $matrix, 'field_rules' => $fieldRules]);

        return User::create(['name' => 'قارئُ الاكتمال', 'email' => Str::random(8) . '@int.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /** موظفٌ له خطُّ SIM بأسراره وسيرفرٌ مسؤولٌ عنه — وضجيجُ زميلٍ لإثبات الترشيح */
    protected function scene(): array
    {
        $this->seedCore();

        $acct = User::create(['name' => 'صاحبُ الملف', 'email' => 'complete360@int.local',
            'password' => 'Secret!2026x', 'status' => 'نشط', 'password_changed_at' => now()]);
        $emp = Employee::create(['name' => 'مهندسُ البنية', 'status' => 'نشط', 'user_id' => $acct->id]);
        $peer = Employee::create(['name' => 'زميلٌ آخر', 'status' => 'نشط']);

        // خطُّه: أسرارُ الشريحة تُبذر لتُثبت غيابَها عن HTML لا غيابَ البيانات
        $sim = PhoneNumber::create(['number' => '+96550001111', 'line_type' => 'SIM',
            'carrier' => 'زين', 'iccid' => 'ICCID-8996500042420001', 'msisdn' => '96550001111',
            'pin' => 'PIN-SECRET-7777', 'puk' => 'PUK-SECRET-88888888',
            'status' => 'نشط', 'employee_id' => $emp->id]);
        // وخطُّ الزميل — يجب ألّا يظهر في تبويب هذا الموظف
        $peerSim = PhoneNumber::create(['number' => '+96550009999', 'line_type' => 'SIM',
            'status' => 'نشط', 'employee_id' => $peer->id]);

        // سيرفرُه (حافّةُ H.1: servers.hr_id) — وسيرفرُ الزميل ضجيجاً
        $srv = Server::create(['name' => 'أوريون الإنتاجيّ', 'provider' => 'Hetzner',
            'ip' => '198.51.100.42', 'os' => 'Ubuntu 24.04', 'status' => 'نشط', 'hr_id' => $emp->id]);
        $peerSrv = Server::create(['name' => 'سيرفرُ الزميل', 'status' => 'نشط', 'hr_id' => $peer->id]);

        return compact('acct', 'emp', 'peer', 'sim', 'peerSim', 'srv', 'peerSrv');
    }

    /** ① تبويبُ الاتصالات يعرض خطَّ الموظف وحدَه — وأسرارُ الشريحة لا تبلغ HTML بحال */
    public function test_the_telecom_tab_shows_the_sim_and_never_its_secrets(): void
    {
        ['emp' => $emp] = $this->scene();

        $res = $this->actingAs($this->owner)
            ->get(route('portal.employee', $emp->id) . '?tab=telecom')->assertOk();

        $res->assertSee('+96550001111');                    // خطُّه يظهر
        $res->assertDontSee('+96550009999');                // لا خطَّ زميله (ترشيحُ employee_id)
        $res->assertSee('ICCID-8996500042420001');          // هويّةُ الشريحة حرّةٌ للمالك
        // الأسرار: لا تُنتقى من القارئ أصلاً — فلا خامَ ولا مشفَّراً في الصفحة
        $res->assertDontSee('PIN-SECRET-7777');
        $res->assertDontSee('PUK-SECRET-88888888');
    }

    /** ② ICCID هويّةٌ تقنيّةٌ يحجبها hub_field_mode — نظيرُ سيريال العهدة بحرفه */
    public function test_the_iccid_is_maskable_by_a_field_rule(): void
    {
        ['emp' => $emp] = $this->scene();

        $masked = $this->role360(['hr' => ['v' => 1], 'phones' => ['v' => 1]],
            ['phones' => ['iccid' => 'hide']]);

        $res = $this->actingAs($masked)
            ->get(route('portal.employee', $emp->id) . '?tab=telecom')->assertOk();
        $res->assertSee('+96550001111');                    // الخطُّ يظهر — الحجبُ حقلٌ لا تبويب
        $res->assertDontSee('ICCID-8996500042420001');      // والهويّةُ التقنيّة محجوبة
    }

    /** ③ تبويبُ الأنظمة يعرض سيرفرَ الموظف وحدَه — حافّةُ hr_id بانضباط H.1 */
    public function test_the_systems_tab_shows_the_server_linked_by_hr_id(): void
    {
        ['emp' => $emp] = $this->scene();

        $res = $this->actingAs($this->owner)
            ->get(route('portal.employee', $emp->id) . '?tab=systems')->assertOk();

        $res->assertSee('أوريون الإنتاجيّ');                // سيرفرُه (hr_id) يظهر
        $res->assertDontSee('سيرفرُ الزميل');               // لا سيرفرَ زميله
        $res->assertSee('198.51.100.42');                   // IP حرٌّ للمالك
    }

    /** ④ IP السيرفر يحجبه hub_field_mode — لا خاماً لدورٍ قُيّد حقلُه */
    public function test_the_server_ip_is_maskable_by_a_field_rule(): void
    {
        ['emp' => $emp] = $this->scene();

        $masked = $this->role360(['hr' => ['v' => 1], 'servers' => ['v' => 1]],
            ['servers' => ['ip' => 'hide']]);

        $res = $this->actingAs($masked)
            ->get(route('portal.employee', $emp->id) . '?tab=systems')->assertOk();
        $res->assertSee('أوريون الإنتاجيّ');                // السيرفرُ يظهر — الحجبُ حقلٌ لا تبويب
        $res->assertDontSee('198.51.100.42');               // وعنوانُه محجوب
    }

    /** ⑤ الحرسُ الخادميّ: كلا التبويبَين ٤٠٣ بلا وحدته — لا مجرَّدُ غيابٍ في الشريط */
    public function test_both_tabs_are_forbidden_without_their_module_permission(): void
    {
        ['emp' => $emp] = $this->scene();

        $hrOnly = $this->role360(['hr' => ['v' => 1]]);

        $this->actingAs($hrOnly)->get(route('portal.employee', $emp->id) . '?tab=telecom')
            ->assertForbidden();
        $this->actingAs($hrOnly)->get(route('portal.employee', $emp->id) . '?tab=systems')
            ->assertForbidden();
    }

    /** ⑥ السجلُّ اكتمل بصفرِ بطاقاتٍ زائفة — سبعُ سككٍ حقيقيّة وتبويبٌ مجهولٌ ٤٠٤ */
    public function test_the_catalog_is_complete_with_zero_placeholders(): void
    {
        ['emp' => $emp] = $this->scene();

        $html = $this->actingAs($this->owner)->get(route('portal.employee', $emp->id))
            ->assertOk()->getContent();

        foreach (['profile', 'assets', 'station', 'telecom', 'systems', 'endpoint', 'wallet'] as $key) {
            $this->assertStringContainsString('tab=' . $key, $html,
                "تبويبُ {$key} غائبٌ عن شريط الملفّ الشامل — السجلُّ لم يكتمل (WP-M.3)");
        }

        // وما لا سكّةَ له بعدُ يبقى ٤٠٤ — لا لوحةٌ صامتةٌ لتبويبٍ مُختلَق
        $this->actingAs($this->owner)->get(route('portal.employee', $emp->id) . '?tab=ghost')
            ->assertNotFound();
    }
}
