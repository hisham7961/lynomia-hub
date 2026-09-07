<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Carrier;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PhoneNumber;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **سجلُّ المزوّدين + روابطُ الموظف/الجهاز/المحطة على أصل الاتصالات**
 * (Work OS · الطور G · WP-G.2 · §22/§23/§28).
 *
 * المزوّدُ كيانٌ داخليٌّ مُدارٌ بالبيانات فوق `ModuleController` (وحدةُ `carriers`
 * في `config/hub.php`) — **لا `CarrierController` خاص**؛ وروابطُ الخطّ
 * (carrier/employee/device/station) مراجعُ `ref` تُضيء علاقاتِ
 * `hub_children`/`hub_related` تلقائياً. يمتدّ هذا الملفُّ نمطَ
 * `FieldPermissionBypassTest` و`CompanyIsolationTest`: حارسٌ يُفرض في مسارٍ ولا
 * يُلتَفّ عليه، وعزلٌ مُنطَّقٌ يُثبَت على **كلّ** الصفوف لا على واحدٍ بالقرعة.
 *
 *  • خياراتُ `carrier_id` مُنطَّقةٌ بالشركة — مديرُ شركةٍ يرى مزوّديها لا مزوّدي غيرها.
 *  • تبويبُ الاتصالات في الموظف 360 يُضيء عبر `hub_related` حين يُربَط خطٌّ بالموظف،
 *    بترتيبٍ حتميّ، ونطاقٌ لكلّ ابن (خطُّ شركةٍ أجنبيةٍ لا يبلغ مديراً معزولاً).
 *  • `portal_password` سرٌّ (`sec`/VaultSecret) — لا يُطبَع (يُقنَّع ••••)، ويُكشف عبر
 *    `revealSecret` وحدَه بأثرِ «عرض حساس».
 *  • المزوّدون بنيةٌ داخليّة — حسابُ العميل يُردّ ٤٠٤ على الوحدة (فوق المصفوفة).
 */
class WorkOsCarriersTest extends TestCase
{
    protected Company $coA;
    protected Company $coB;

    /** شركتان، ومزوّدٌ لكلٍّ منهما باسمٍ فريدٍ يميّزه في مصدر الصفحة */
    protected function seedCarriers(): void
    {
        $this->seedCore();
        $this->coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $this->coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
    }

    /** دورٌ داخليٌّ معزولٌ على شركةٍ واحدة، بصلاحياتِ الوحداتِ الممرَّرة */
    protected function scopedManager(array $companyIds, array $matrix): User
    {
        $role = Role::create(['name' => 'مديرٌ معزول ' . Str::random(5), 'scope' => 'all',
            'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => 'مديرٌ معزول', 'email' => Str::random(8) . '@int.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'companies' => $companyIds, 'password_changed_at' => now()]);
    }

    /** ① خياراتُ carrier_id في نموذج الخطّ مُنطَّقةٌ بشركة القارئ */
    public function test_carrier_id_options_are_company_scoped(): void
    {
        $this->seedCarriers();
        Carrier::create(['name' => 'مزوّدُ ألف الفريد', 'company_id' => $this->coA->id]);
        Carrier::create(['name' => 'مزوّدُ باء الفريد', 'company_id' => $this->coB->id]);

        // المالكُ يرى الاثنين في نموذج إنشاء الخطّ (لا حجبٌ شامل يزوّر العزل)
        $this->actingAs($this->owner)->get('/m/phones/create')->assertOk()
            ->assertSee('مزوّدُ ألف الفريد')->assertSee('مزوّدُ باء الفريد');

        // مديرُ شركة ألف: يرى مزوّدَها فقط — مزوّدُ باء خارجَ خياراته
        $mgr = $this->scopedManager([$this->coA->id],
            ['phones' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0], 'carriers' => ['v' => 1]]);
        $res = $this->actingAs($mgr)->get('/m/phones/create')->assertOk();
        $res->assertSee('مزوّدُ ألف الفريد');
        $res->assertDontSee('مزوّدُ باء الفريد');
    }

    /** ② تبويبُ الاتصالات في الموظف 360 يُضيء عبر hub_related — كلُّ الصفوف، وحتميّاً */
    public function test_the_telecom_tab_lights_up_via_related_on_the_employee_record(): void
    {
        $this->seedCarriers();

        // موظفٌ في شركة ألف — يفتحه مديرُ ألف بحقّ
        $emp = Employee::create(['name' => 'موظفُ الاتصالات', 'status' => 'نشط',
            'company_id' => $this->coA->id]);

        // خطّان مربوطان بالموظف — الأقدمُ أولاً كي يُثبَت الترتيبُ الحتميّ (الأحدثُ أعلى)
        $older = PhoneNumber::create(['number' => 'خطُّ-الموظف-الأقدم', 'status' => 'نشط',
            'company_id' => $this->coA->id, 'employee_id' => $emp->id,
            'created_at' => now()->subDay()]);
        $newer = PhoneNumber::create(['number' => 'خطُّ-الموظف-الأحدث', 'status' => 'نشط',
            'company_id' => $this->coA->id, 'employee_id' => $emp->id,
            'created_at' => now()]);

        // خطٌّ لشركةٍ أجنبيةٍ مربوطٌ بالموظف نفسِه — يظهر للمالك، ويُحجَب عن مديرِ ألف (نطاقٌ لكلّ ابن)
        $foreign = PhoneNumber::create(['number' => 'خطُّ-شركةٍ-أجنبية', 'status' => 'نشط',
            'company_id' => $this->coB->id, 'employee_id' => $emp->id]);

        // المالكُ يرى كلَّ الخطوطِ الثلاثة على سجلّ الموظف (تبويبُ الاتصالات مُضاء بكلّ الصفوف)
        $html = $this->actingAs($this->owner)->get('/m/hr/' . $emp->id)->assertOk()->getContent();
        foreach (['خطُّ-الموظف-الأقدم', 'خطُّ-الموظف-الأحدث', 'خطُّ-شركةٍ-أجنبية'] as $n) {
            $this->assertStringContainsString($n, $html, "تبويبُ الاتصالات لم يُضئ الخطَّ {$n} عبر hub_related");
        }
        // وترتيبٌ حتميّ: الأحدثُ قبل الأقدم (orderByDesc created_at ثم id) — لا قرعة
        $this->assertLessThan(
            mb_strpos($html, 'خطُّ-الموظف-الأقدم'),
            mb_strpos($html, 'خطُّ-الموظف-الأحدث'),
            'ترتيبُ الأبناء غيرُ حتميّ — الأحدثُ لم يسبق الأقدم'
        );

        // مديرُ ألف يفتح الموظفَ ويرى خطَّي شركتِه، لكنّ خطَّ الشركة الأجنبية محجوبٌ (نطاقٌ لكلّ ابن)
        $mgr = $this->scopedManager([$this->coA->id],
            ['hr' => ['v' => 1], 'phones' => ['v' => 1]]);
        $res = $this->actingAs($mgr)->get('/m/hr/' . $emp->id)->assertOk();
        $res->assertSee('خطُّ-الموظف-الأقدم');
        $res->assertSee('خطُّ-الموظف-الأحدث');
        $res->assertDontSee('خطُّ-شركةٍ-أجنبية');
    }

    /**
     * ②ب تبويبُ الاتصالات في الملفّ الشامل (الموظف 360) — سكّةُ الطور G التي بشّر
     * بها متحكّمُ البوابة (تُضاف عند وصولِ سكّتها). يعرض خطوطَ الموظف منطَّقةً بالشركة
     * وبترتيبٍ حتميّ، وتبويبٌ بلا `phones:v` يُردّ ٤٠٣ من المتحكّم (الحرسُ فوق الشريط).
     */
    public function test_the_360_telecom_tab_lists_the_employee_lines_scoped_and_ordered(): void
    {
        $this->seedCarriers();
        $emp = Employee::create(['name' => 'موظفُ الاتصالات 360', 'status' => 'نشط',
            'company_id' => $this->coA->id]);

        $older = PhoneNumber::create(['number' => 'خطُّ-360-الأقدم', 'status' => 'نشط',
            'company_id' => $this->coA->id, 'employee_id' => $emp->id, 'created_at' => now()->subDay()]);
        $newer = PhoneNumber::create(['number' => 'خطُّ-360-الأحدث', 'status' => 'نشط',
            'company_id' => $this->coA->id, 'employee_id' => $emp->id, 'created_at' => now()]);
        $foreign = PhoneNumber::create(['number' => 'خطُّ-360-أجنبي', 'status' => 'نشط',
            'company_id' => $this->coB->id, 'employee_id' => $emp->id]);

        // المالكُ يفتح تبويبَ الاتصالات في الـ360 فيرى خطَّي الموظف بترتيبٍ حتميّ (الأحدثُ أعلى)
        $html = $this->actingAs($this->owner)
            ->get(route('portal.employee', ['id' => $emp->id, 'tab' => 'telecom']))
            ->assertOk()->getContent();
        foreach (['خطُّ-360-الأقدم', 'خطُّ-360-الأحدث'] as $n) {
            $this->assertStringContainsString($n, $html, "تبويبُ الاتصالات في الـ360 لم يعرض {$n}");
        }
        $this->assertLessThan(
            mb_strpos($html, 'خطُّ-360-الأقدم'),
            mb_strpos($html, 'خطُّ-360-الأحدث'),
            'ترتيبُ خطوطِ الـ360 غيرُ حتميّ — الأحدثُ لم يسبق الأقدم'
        );

        // مديرُ ألف يرى خطوطَ شركتِه لا خطَّ الشركة الأجنبية (نطاقٌ لكلّ ابن)
        $mgr = $this->scopedManager([$this->coA->id],
            ['hr' => ['v' => 1], 'phones' => ['v' => 1]]);
        $res = $this->actingAs($mgr)
            ->get(route('portal.employee', ['id' => $emp->id, 'tab' => 'telecom']))->assertOk();
        $res->assertSee('خطُّ-360-الأقدم');
        $res->assertDontSee('خطُّ-360-أجنبي');

        // تبويبٌ معروفٌ لكن بلا صلاحيةِ الاتصالات (phones) → ٤٠٣ (فوق الشريط، لا مجرَّدَ إخفاء)
        $noPhones = $this->scopedManager([$this->coA->id], ['hr' => ['v' => 1]]);
        $this->actingAs($noPhones)
            ->get(route('portal.employee', ['id' => $emp->id, 'tab' => 'telecom']))
            ->assertForbidden();
    }

    /** ③ كلمةُ مرور بوّابة المزوّد سرٌّ — لا تُطبَع، وتُكشف بأثرِ «عرض حساس» */
    public function test_the_carrier_portal_password_is_a_masked_audited_secret(): void
    {
        $this->seedCarriers();
        $carrier = Carrier::create(['name' => 'مزوّدٌ بكلمةِ بوّابة', 'company_id' => $this->coA->id,
            'portal_username' => 'admin', 'portal_password' => 'PORTALxSEKRETx9001']);

        // مصدرُ الصفحة لا يحمل القيمةَ — تُجلب عند الكشف فقط، والحقلُ مقنَّعٌ فعلاً
        $res = $this->actingAs($this->owner)->get('/m/carriers/' . $carrier->id)->assertOk();
        $res->assertDontSee('PORTALxSEKRETx9001');
        $res->assertSee('••••••');

        // الكشفُ عبر المسار الوحيد يعيد القيمةَ ويكتب أثرَ «عرض حساس» على وحدة carriers
        $this->actingAs($this->owner)->postJson('/m/carriers/' . $carrier->id . '/secret/portalPassword')
            ->assertOk()->assertJson(['v' => 'PORTALxSEKRETx9001']);

        $this->assertTrue(
            AuditEntry::where('action', 'عرض حساس')->where('module', 'carriers')
                ->where('record_id', $carrier->id)->exists(),
            'كشفُ كلمةِ بوّابة المزوّد لم يكتب أثرَ «عرض حساس»'
        );
    }

    /** ④ المزوّدون بنيةٌ داخليّة — حسابُ العميل يُردّ ٤٠٤ على القائمةِ والسجلّ (فوق المصفوفة) */
    public function test_carriers_are_internal_a_client_account_gets_404(): void
    {
        $this->seedCarriers();
        $carrier = Carrier::create(['name' => 'مزوّدٌ داخليّ', 'company_id' => $this->coA->id]);

        $client = User::create(['name' => 'حسابُ عميل', 'email' => Str::random(6) . '@client.test',
            'password' => 'Secret!2026x', 'status' => 'نشط', 'account_type' => 'client',
            'password_changed_at' => now()]);

        $this->actingAs($client)->get('/m/carriers')->assertNotFound();
        $this->actingAs($client)->get('/m/carriers/' . $carrier->id)->assertNotFound();
    }
}
