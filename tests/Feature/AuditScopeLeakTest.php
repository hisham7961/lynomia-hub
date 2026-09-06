<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WP-5.1 — تسريب النطاق في سجلّ التدقيق (spec §17 · §45 · §23.1).
 *
 * كانت شاشةُ التدقيق تقرأ الجدولَ بلا مرشِّح الوحدات المرئية: من يحمل رايةَ
 * `audit` يقرأ **أسماء** سجلاتٍ من وحداتٍ لا يملك عرضَها — والاسمُ وحده تسريب
 * («مسيّر رواتب أبريل» يقول ما يكفي) — ويُربَط له بشاشة السجل نفسِه، وتُعرَض له
 * الوحدةُ المحجوبة في القائمة المنسدلة. وعزلُ العملاء (`hub_client_ids`) لم
 * يكن يُطبَّق مطلقاً رغم أنّ ١٦ وحدةً تحمل عمودَ عميل.
 *
 * القاعدة: **قارئ الأثر لا يرى أثرَ ما لا يراه** — وكلُّ قارئٍ للجدول يبدأ من
 * `Audit::scopedQuery($user)` لا من `DB::table('audits')` الخام.
 */
class AuditScopeLeakTest extends TestCase
{
    /** دورٌ يحمل رايةَ سجلّ التدقيق ومصفوفةً محدودة — كما يُمنَح مدقّقٌ مقيَّد في الواقع */
    protected function auditor(array $matrix, array $clients = []): User
    {
        $role = Role::create(['name' => 'مدقّق محدود ' . Str::random(5), 'scope' => 'all',
            'flags' => ['audit' => 1], 'matrix' => $matrix]);

        return User::create(['name' => 'مدقّق مقيَّد', 'email' => Str::random(10) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'clients' => $clients ?: null, 'password_changed_at' => now()]);
    }

    /** قارئٌ بلا `hr:v` لا يرى اسمَ سجلٍّ من hr ولا رابطَه ولا الوحدةَ في المنسدلة ولا نبضَها */
    public function test_reader_without_hr_view_sees_no_hr_name_link_or_dropdown_entry(): void
    {
        $this->seedCore();
        $rid = (string) Str::uuid();

        $this->actingAs($this->owner);
        hub_audit('تعديل', 'hr', $rid, 'ملف راتب المدير التنفيذي', ['ip' => '203.0.113.77']);
        hub_audit('تعديل', 'tasks', null, 'مهمة مرئية للمدقق');

        $u = $this->auditor(['tasks' => ['v' => 1]]);
        $this->actingAs($u)->get('/admin/audit')->assertOk()
            ->assertSee('مهمة مرئية للمدقق')
            // الاسمُ نفسه تسريب — لا يُطبع لمن لا يملك hr:v
            ->assertDontSee('ملف راتب المدير التنفيذي')
            // ولا يُربَط له بشاشة السجل m.show
            ->assertDontSee('/m/hr/' . $rid)
            // ولا تُعرَض الوحدةُ المحجوبة في القائمة المنسدلة
            ->assertDontSee('ملفات الموظفين')
            // ولا يتسرّب عنوانُ القيد المحجوب عبر نبض «عناوين جديدة»
            ->assertDontSee('203.0.113.77');
    }

    /** المحصورُ بعميلٍ لا يرى أثرَ عملاءَ غيرِه — في الوحدات ذات عمود العميل وفي بطاقات العملاء */
    public function test_client_restricted_reader_sees_no_other_clients_activity(): void
    {
        $this->seedCore();
        $mine  = (string) Str::uuid();
        $other = (string) Str::uuid();
        DB::table('clients')->insert([
            ['id' => $mine,  'name' => 'عميلي المسموح'],
            ['id' => $other, 'name' => 'العميل الآخر'],
        ]);
        $cMine  = (string) Str::uuid();
        $cOther = (string) Str::uuid();
        DB::table('contracts')->insert([
            ['id' => $cMine,  'title' => 'عقد أ', 'type' => 'خدمات', 'client_id' => $mine],
            ['id' => $cOther, 'title' => 'عقد ب', 'type' => 'خدمات', 'client_id' => $other],
        ]);

        $this->actingAs($this->owner);
        hub_audit('تعديل', 'contracts', $cMine, 'عقد عميلي المسموح تجديد');
        hub_audit('تعديل', 'contracts', $cOther, 'عقد العميل المحجوب تسعير');
        hub_audit('تعديل', 'clients', $other, 'بطاقة العميل المحجوب');

        $u = $this->auditor(['contracts' => ['v' => 1], 'clients' => ['v' => 1]], [$mine]);
        $this->actingAs($u)->get('/admin/audit')->assertOk()
            ->assertSee('عقد عميلي المسموح تجديد')
            ->assertDontSee('عقد العميل المحجوب تسعير')
            ->assertDontSee('بطاقة العميل المحجوب');
    }

    /** المرشِّحات تضيّق ولا توسّع: طلبُ `module=hr` صراحةً لا يفتح المحجوب (نمط SecurityCenterTest) */
    public function test_filters_cannot_widen_the_scope(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        hub_audit('تصدير', 'hr', null, 'مسيّر رواتب الربع الأول');

        $u = $this->auditor(['tasks' => ['v' => 1]]);
        $this->actingAs($u)->get('/admin/audit?module=hr')->assertOk()
            ->assertDontSee('مسيّر رواتب الربع الأول');
        $this->actingAs($u)->get('/admin/audit?module=hr&q=' . urlencode('مسيّر'))->assertOk()
            ->assertDontSee('مسيّر رواتب الربع الأول');
    }

    /**
     * عدائيّ (فحص المرحلة ٥): تسميةُ ما وراء النطاق **قيمةَ مرشِّح** لا تفتحه —
     * عميلُ غيرِه في `client=`، وشركةُ غيرِه في `company=`، ومعرّفُ طلبِ قيدٍ
     * محجوبٍ في `request_id=` — كلُّها تقاطُعٌ مع النطاق فتُرجِع لا شيء.
     */
    public function test_naming_foreign_ids_as_filter_values_opens_nothing(): void
    {
        $this->seedCore();
        $mine = (string) Str::uuid();
        $other = (string) Str::uuid();
        DB::table('clients')->insert([
            ['id' => $mine,  'name' => 'عميلي المسموح'],
            ['id' => $other, 'name' => 'العميل الآخر'],
        ]);
        $cOther = (string) Str::uuid();
        DB::table('contracts')->insert([
            ['id' => $cOther, 'title' => 'عقد ب', 'type' => 'خدمات', 'client_id' => $other],
        ]);
        $foreignCo = (string) Str::uuid();
        $foreignRid = 'rid-foreign-' . Str::random(8);

        $this->actingAs($this->owner);
        hub_audit('تعديل', 'contracts', $cOther, 'عقد العميل المحجوب سرّي');
        hub_audit('تصدير', 'tasks', null, 'تصدير شركة أخرى محجوب',
            ['company_id' => $foreignCo, 'request_id' => $foreignRid]);

        // محصورٌ بعميلٍ واحد يسمّي عميلَ غيره قيمةً للمرشِّح
        $u = $this->auditor(['contracts' => ['v' => 1], 'clients' => ['v' => 1], 'tasks' => ['v' => 1]], [$mine]);
        $this->actingAs($u)->get('/admin/audit?client=' . $other)->assertOk()
            ->assertDontSee('عقد العميل المحجوب سرّي');

        // ومعزولٌ بشركةٍ يسمّي شركةَ غيره ومعرّفَ طلبِ قيدها قيمتَين للمرشِّحين
        $role = Role::create(['name' => 'معزول شركة ' . Str::random(5), 'scope' => 'all',
            'flags' => ['audit' => 1], 'matrix' => ['tasks' => ['v' => 1]]]);
        $iso = User::create(['name' => 'معزول', 'email' => Str::random(10) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'companies' => [(string) Str::uuid()], 'password_changed_at' => now()]);
        $this->actingAs($iso)->get('/admin/audit?company=' . $foreignCo)->assertOk()
            ->assertDontSee('تصدير شركة أخرى محجوب');
        $this->actingAs($iso)->get('/admin/audit?request_id=' . $foreignRid)->assertOk()
            ->assertDontSee('تصدير شركة أخرى محجوب');
    }

    /** انحدار: المالك يرى كلَّ شيء — الوحدات، والوحدات الزائفة (settings)، والقيود النظامية بلا وحدة */
    public function test_the_owner_still_sees_everything(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        hub_audit('تعديل', 'hr', (string) Str::uuid(), 'ملف موظف يراه المالك');
        hub_audit('تعديل إعدادات النظام', 'settings', null, 'مفتاح إعدادات عام');
        hub_audit('دخول', null, null, 'قيد نظامي بلا وحدة');

        $this->actingAs($this->owner)->get('/admin/audit')->assertOk()
            ->assertSee('ملف موظف يراه المالك')
            ->assertSee('مفتاح إعدادات عام')
            ->assertSee('قيد نظامي بلا وحدة')
            // والقائمةُ المنسدلة تعرض له كلَّ الوحدات
            ->assertSee('ملفات الموظفين');
    }
}
