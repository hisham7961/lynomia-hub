<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** العزل الصارم لتعدد الشركات: قوائم، وصول مباشر، كتابة، API، محوّل */
class CompanyIsolationTest extends TestCase
{
    protected Company $coA;
    protected Company $coB;
    protected Client $clientA;
    protected Client $clientB;

    protected function seedCompanies(): void
    {
        $this->seedCore();
        $this->coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $this->coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $this->clientA = Client::create(['name' => 'عميل ألف', 'company_id' => $this->coA->id]);
        $this->clientB = Client::create(['name' => 'عميل باء', 'company_id' => $this->coB->id]);
        // الموظفة معزولة على شركة ألف فقط
        $this->employee->update(['companies' => [$this->coA->id]]);
    }

    public function test_restricted_user_lists_only_allowed_company_records(): void
    {
        $this->seedCompanies();
        $resp = $this->actingAs($this->employee)->get('/m/clients');
        $resp->assertOk()->assertSee('عميل ألف')->assertDontSee('عميل باء');
    }

    public function test_direct_url_to_foreign_record_is_404(): void
    {
        $this->seedCompanies();
        $this->actingAs($this->employee)->get('/m/clients/' . $this->clientB->id)->assertNotFound();
        $this->actingAs($this->employee)->get('/m/clients/' . $this->clientA->id)->assertOk();
        // قائمة الشركات نفسها تنعزل أيضاً
        $this->actingAs($this->employee)->get('/m/companies/' . $this->coB->id)->assertNotFound();
    }

    public function test_write_with_foreign_company_is_rejected(): void
    {
        $this->seedCompanies();
        $resp = $this->actingAs($this->employee)->from('/m/clients/create')
            ->post('/m/clients', ['name' => 'تسلل', 'companyId' => $this->coB->id]);
        $resp->assertRedirect('/m/clients/create')->assertSessionHasErrors('companyId');
        $this->assertSame(0, Client::where('name', 'تسلل')->count());

        // وبالشركة المسموحة يمرّ
        $this->actingAs($this->employee)
            ->post('/m/clients', ['name' => 'مشروع ألف', 'companyId' => $this->coA->id]);
        $this->assertSame(1, Client::where('name', 'مشروع ألف')->count());
    }

    public function test_api_is_isolated_too(): void
    {
        $this->seedCompanies();
        $tok = $this->apiToken($this->employee);

        $list = $this->getJson('/api/v1/clients', ['Authorization' => "Bearer $tok"])->assertOk()->json('data');
        $this->assertCount(1, $list);

        $this->getJson('/api/v1/clients/' . $this->clientB->id, ['Authorization' => "Bearer $tok"])->assertNotFound();

        $this->postJson('/api/v1/clients', ['name' => 'تسلل API', 'companyId' => $this->coB->id],
            ['Authorization' => "Bearer $tok"])->assertStatus(422);
        $this->assertSame(0, Client::where('name', 'تسلل API')->count());
    }

    public function test_switcher_rejects_foreign_company(): void
    {
        $this->seedCompanies();
        $this->actingAs($this->employee)->post('/company-switch', ['company' => $this->coB->id])->assertForbidden();
        $this->actingAs($this->employee)->post('/company-switch', ['company' => $this->coA->id])->assertRedirect();
    }

    public function test_owner_and_unrestricted_user_see_everything(): void
    {
        $this->seedCompanies();
        // المالك دائماً غير مقيد حتى لو حُدّدت له قائمة
        $this->owner->update(['companies' => [$this->coA->id]]);
        $this->actingAs($this->owner)->get('/m/clients')->assertSee('عميل ألف')->assertSee('عميل باء');
        // مستخدم بلا قائمة = غير مقيد
        $this->actingAs($this->viewer)->get('/m/clients')->assertSee('عميل ألف')->assertSee('عميل باء');
    }

    public function test_org_analytics_pages_blocked_for_isolated_user(): void
    {
        $this->seedCompanies();
        // تقييم الموردين وتكلفة الخدمات والتوصيات لوحات منشأة كاملة — 403 للمعزول
        $this->actingAs($this->employee)->get('/supplier-scores')->assertForbidden();
        $this->actingAs($this->employee)->get('/service-costs')->assertForbidden();
        $this->actingAs($this->employee)->get('/recommendations')->assertForbidden();
        // وغير المعزول (المالك) يصلها
        $this->actingAs($this->owner)->get('/supplier-scores')->assertOk();
    }

    public function test_refoptions_hide_foreign_company_reference_targets(): void
    {
        $this->seedCompanies();
        // نموذج الاجتماعات يرجع للعملاء — المعزول لا يرى عميل الشركة الأجنبية في القائمة
        $resp = $this->actingAs($this->employee)->get('/m/meetings/create');
        $resp->assertOk()->assertSee('عميل ألف')->assertDontSee('عميل باء');
    }

    public function test_soft_deleted_company_rejected_in_user_form(): void
    {
        $this->seedCompanies();
        $this->coB->delete();   // حذف ناعم
        $resp = $this->actingAs($this->owner)->from('/admin/users/' . $this->employee->id . '/edit')
            ->put('/admin/users/' . $this->employee->id, [
                'name' => 'موظفة', 'email' => 'emp@test.local',
                'role_id' => $this->employee->role_id, 'status' => 'نشط',
                'companies' => [$this->coB->id],
            ]);
        $resp->assertSessionHasErrors('companies.0');
    }
}
