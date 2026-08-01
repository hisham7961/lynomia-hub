<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * بقيّة ما أخرجه التدقيق — عيوبٌ أهدأ صوتاً وأثرُها باقٍ:
 *
 *  • **نموذج الأدوار يمحو عملك عند أول خطأ**: اسمٌ فارغ يردّ الصفحة، وتُبنى من
 *    **قاعدة البيانات** لا من `old()` — فتضيع مئة نقرةٍ على المصفوفة والرايات
 *    والقيود. عقوبةُ خطأٍ مطبعيّ أن تبدأ من جديد.
 *  • **استعادة نسخةٍ تتخطّى بوابة الموافقات**: التعديل المباشر يُصفّ طلباً،
 *    والاستعادة تكتب فوراً — فهي بابٌ حول الحماية نفسها.
 *  • **نشاط مساحة العمل يتجاوز عزل الشركات**: يُرشَّح بالشركة **النشطة** إن
 *    اختِيرت، فمن لم يختر شيئاً يقرأ أسماء سجلات الشركات كلها.
 *  • **حفظُ مستخدمٍ يفكّ عزله** حين تُحذف شركاته ناعماً: لا تُعرض في النموذج
 *    فلا تُرسَل فتُقرأ «بلا قيد» — فينقلب المعزول مطلقَ الوصول.
 *  • **`--truncate` معلَنٌ ولا يُقرأ**: من يستعيد نسخةً ظنّاً أنها تستبدل القاعدة
 *    يحصل على دمجٍ صامت — سجلاتٌ قديمة تبقى مختلطةً بالمستعادة.
 */
class AuditRemainderTest extends TestCase
{
    public function test_a_validation_error_keeps_the_matrix_you_built(): void
    {
        $this->seedCore();

        $res = $this->actingAs($this->owner)->from('/admin/roles/create')->post('/admin/roles', [
            'name' => '',                                   // خطأ مطبعيّ يردّ الصفحة
            'scope' => 'all', 'flags_submitted' => 1, 'matrix_submitted' => 1,
            'flags' => ['exp' => '1'],
            'matrix' => ['fin' => ['v' => '1', 'e' => '1'], 'hr' => ['v' => '1']],
            'fr_submitted' => 1, 'fr' => ['hr' => ['salary' => 'hide']],
        ]);
        $res->assertRedirect('/admin/roles/create');

        $html = $this->actingAs($this->owner)->get('/admin/roles/create')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="matrix\[fin\]\[e\]"[^>]*checked/', $html,
            'المصفوفة عادت فارغةً بعد خطأٍ في الاسم — مئة نقرةٍ تضيع بحرفٍ ناقص');
        $this->assertMatchesRegularExpression('/name="flags\[exp\]"[^>]*checked/', $html, 'الرايات ضاعت');
        $this->assertStringContainsString('fr[hr][salary]', $html, 'قيود الحقول ضاعت');
    }

    /** الاستعادة تكتب فوراً بينما التعديل المباشر يمرّ بالموافقات */
    public function test_restoring_a_version_respects_the_approval_gate(): void
    {
        $this->seedCore();
        $this->hubSetting('approval.rules', 'hr:e');

        $role = Role::create(['name' => 'كاتب', 'scope' => 'all', 'flags' => [],
            'matrix' => ['hr' => ['v' => 1, 'a' => 1, 'e' => 1]]]);
        $clerk = User::create(['name' => 'كاتب', 'email' => 'clerk@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($this->owner);
        $e = Employee::create(['name' => 'الاسم الأول', 'status' => 'نشط']);
        $e->update(['name' => 'الاسم الثاني']);
        $v = DB::table('record_versions')->where('record_id', $e->id)->orderBy('version')->first();
        $this->assertNotNull($v);

        $this->actingAs($clerk)->post("/m/hr/{$e->id}/restore/{$v->version}");

        $this->assertSame('الاسم الثاني', Employee::find($e->id)->name,
            'الاستعادة كتبت فوراً بينما التعديل المباشر يمرّ بالموافقات — بابٌ حول الحماية');
    }

    public function test_workspace_activity_respects_company_isolation(): void
    {
        $this->seedCore();
        $mine  = Company::create(['name_ar' => 'شركتي', 'status' => 'نشطة']);
        $other = Company::create(['name_ar' => 'شركة أخرى', 'status' => 'نشطة']);

        $this->actingAs($this->owner);
        hub_audit('تعديل', 'clients', null, 'عميل شركتي', ['company_id' => $mine->id]);
        hub_audit('تعديل', 'clients', null, 'عميل الشركة الأخرى', ['company_id' => $other->id]);

        $role = Role::create(['name' => 'معزول', 'scope' => 'all', 'flags' => [],
            'matrix' => collect(array_keys(config('hub.modules')))
                ->mapWithKeys(fn ($m) => [$m => ['v' => 1]])->all()]);
        $u = User::create(['name' => 'معزولة', 'email' => 'iso@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => [$mine->id]]);

        // بلا اختيار شركةٍ نشطة — وهو الوضع الافتراضي
        $this->actingAs($u)->get('/w/entities')->assertOk()
            ->assertDontSee('عميل الشركة الأخرى');
    }

    /** شركةٌ حُذفت ناعماً لا تُعرض في النموذج، فغيابها لا يعني «أطلِق هذا الحساب» */
    public function test_saving_a_user_does_not_lift_isolation_when_a_company_is_soft_deleted(): void
    {
        $this->seedCore();
        $alive = Company::create(['name_ar' => 'قائمة', 'status' => 'نشطة']);
        $gone  = Company::create(['name_ar' => 'محذوفة', 'status' => 'نشطة']);

        $u = User::create(['name' => 'معزولة', 'email' => 'iso2@test.local', 'password' => 'Secret!2026x',
            'role_id' => $this->employee->role_id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => [$alive->id, $gone->id]]);
        $gone->delete();

        // النموذج لا يعرض المحذوفة، فالحفظ يرسل القائمة وحدها
        $this->actingAs($this->owner)->put("/admin/users/{$u->id}", [
            'name' => 'معزولة', 'email' => 'iso2@test.local', 'role_id' => $u->role_id,
            'status' => 'نشط', 'companies' => [$alive->id],
        ])->assertRedirect();

        $this->assertSame([$alive->id], User::find($u->id)->companies,
            'العزل بقي على الشركة القائمة — لا انقلب إلى وصولٍ مطلق');
    }

    public function test_the_isolation_is_never_silently_emptied(): void
    {
        $this->seedCore();
        $gone = Company::create(['name_ar' => 'محذوفة', 'status' => 'نشطة']);
        $u = User::create(['name' => 'معزولة', 'email' => 'iso3@test.local', 'password' => 'Secret!2026x',
            'role_id' => $this->employee->role_id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => [$gone->id]]);
        $gone->delete();

        // كل شركاته محذوفة: النموذج يرسل قائمةً فارغة — و«فارغ» تعني «كل الشركات»
        $this->actingAs($this->owner)->put("/admin/users/{$u->id}", [
            'name' => 'معزولة', 'email' => 'iso3@test.local', 'role_id' => $u->role_id,
            'status' => 'نشط',
        ])->assertRedirect();

        $this->assertNotSame([], User::find($u->id)->companies ?? [],
            'حسابٌ معزولٌ صار مطلق الوصول لأن شركته حُذفت ناعماً — بلا قرارٍ من أحد');
    }

    public function test_import_truncate_actually_replaces(): void
    {
        $this->seedCore();
        \App\Models\Client::create(['name' => 'عميلٌ قديم']);

        $file = storage_path('app/test-import-' . \Illuminate\Support\Str::random(6) . '.json');
        file_put_contents($file, json_encode([
            '_meta' => ['app' => 'اختبار'],
            'clients' => [['id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'عميلٌ مستعاد']],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan("hub:import {$file} --truncate")->assertSuccessful();
        @unlink($file);

        $names = \App\Models\Client::pluck('name')->all();
        $this->assertContains('عميلٌ مستعاد', $names);
        $this->assertNotContains('عميلٌ قديم', $names,
            '‎--truncate معلَنٌ ولا يُقرأ: الاستعادة تدمج صمتاً بدل أن تستبدل');
    }
}
