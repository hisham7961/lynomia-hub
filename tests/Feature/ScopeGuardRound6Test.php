<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FinDocument;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * جولة تدقيق ٦ — سدّ تسريبات النطاق المكتشفة في التدقيق الشامل:
 *  1) التقارير المالية /reports/finance كانت بلا hub_scope — المعزول يرى أرقام كل الشركات.
 *  2) بطاقات الموجز الصباحي (موافقات/طلبات/حوادث/مستحقات) كانت بلا نطاق.
 *  3) تبويب «المالية» في مساحة عمل العقد كان بلا صلاحية fin.v وبلا نطاق.
 *  4) محرك قواعد التنبيه كان يُشعر المستلم بسجلات خارج نطاقه.
 */
class ScopeGuardRound6Test extends TestCase
{
    protected Company $coA;
    protected Company $coB;

    protected function seedTwoCompanies(): void
    {
        $this->seedCore();
        $this->coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $this->coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $this->employee->update(['companies' => [$this->coA->id]]);
    }

    protected function finDoc(string $partner, string $companyId, array $extra = []): FinDocument
    {
        return FinDocument::create(array_merge([
            'doc_no' => 'T-' . strtoupper(substr(uniqid(), -6)),
            'kind' => 'فاتورة مبيعات', 'partner' => $partner,
            'date' => now()->toDateString(), 'total' => 100, 'paid' => 0,
            'state' => 'مرسلة', 'company_id' => $companyId,
        ], $extra));
    }

    public function test_finance_report_is_scoped_for_isolated_user(): void
    {
        $this->seedTwoCompanies();
        $this->finDoc('شريك ألف المالي', $this->coA->id);
        $this->finDoc('شريك باء المالي', $this->coB->id);

        $this->actingAs($this->employee)->get('/reports/finance')
            ->assertOk()->assertSee('شريك ألف المالي')->assertDontSee('شريك باء المالي');

        // المالك غير المقيد يرى الكل
        $this->actingAs($this->owner)->get('/reports/finance')
            ->assertOk()->assertSee('شريك ألف المالي')->assertSee('شريك باء المالي');
    }

    public function test_morning_cards_are_scoped_for_isolated_user(): void
    {
        $this->seedTwoCompanies();

        DB::table('approvals')->insert([
            ['id' => (string) \Illuminate\Support\Str::uuid(), 'title' => 'موافقة ألف الصباحية', 'type' => 'تعديل',
             'status' => 'معلّق', 'company_id' => $this->coA->id, 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) \Illuminate\Support\Str::uuid(), 'title' => 'موافقة باء الصباحية', 'type' => 'تعديل',
             'status' => 'معلّق', 'company_id' => $this->coB->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->finDoc('مستحق ألف الصباحي', $this->coA->id, ['due' => now()->toDateString()]);
        $this->finDoc('مستحق باء الصباحي', $this->coB->id, ['due' => now()->toDateString()]);

        $r = $this->actingAs($this->employee)->get('/morning')->assertOk();
        $r->assertSee('موافقة ألف الصباحية')->assertDontSee('موافقة باء الصباحية');
        $r->assertSee('مستحق ألف الصباحي')->assertDontSee('مستحق باء الصباحي');
    }

    public function test_contract_workspace_fin_tab_requires_fin_permission(): void
    {
        $this->seedTwoCompanies();
        // التبويب يعرض رقم المستند لا اسم الشريك — الإثبات على doc_no
        $this->finDoc('شريك تبويب العقد', $this->coA->id, ['doc_no' => 'CTFIN-777']);
        $contract = \App\Models\Contract::create([
            'doc_no' => 'CT-100', 'title' => 'عقد التبويب', 'status' => 'ساري',
            'type' => 'عقد خدمة', 'company_id' => $this->coA->id,
        ]);

        // دور يرى العقود ولا يرى المالية — أرقام المستندات لا تظهر له
        $modules = array_keys(config('hub.modules'));
        $matrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();
        $matrix['fin'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $legalRole = Role::create(['name' => 'قانوني بلا مالية', 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);
        $legal = User::create(['name' => 'قانوني', 'email' => 'legal@test.local',
            'password' => 'Secret!2026x', 'role_id' => $legalRole->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->actingAs($legal)->get('/m/contracts/' . $contract->id)
            ->assertOk()->assertDontSee('CTFIN-777');

        // ومن يملك fin.v يراها
        $this->actingAs($this->owner)->get('/m/contracts/' . $contract->id)
            ->assertOk()->assertSee('CTFIN-777');
    }

    public function test_alert_rule_directed_to_isolated_user_respects_scope(): void
    {
        $this->seedTwoCompanies();
        \App\Models\Client::create(['name' => 'عميل ألف للقاعدة', 'company_id' => $this->coA->id, 'stage' => 'عميل حالي']);
        \App\Models\Client::create(['name' => 'عميل باء للقاعدة', 'company_id' => $this->coB->id, 'stage' => 'عميل حالي']);

        \App\Models\AlertRule::create([
            'name' => 'قاعدة نطاق', 'mod' => 'clients', 'field' => 'stage',
            'op' => 'يساوي', 'val' => 'عميل حالي', 'chan' => 'نظام', 'every' => 7,
            'status' => 'مفعّلة', 'to_id' => $this->employee->id,
        ]);

        $this->artisan('hub:automation')->assertExitCode(0);

        $texts = DB::table('notifications_hub')->where('user_id', $this->employee->id)
            ->pluck('text')->implode(' | ');
        $this->assertStringContainsString('عميل ألف للقاعدة', $texts, 'القاعدة يجب أن تُشعر بسجل النطاق المسموح');
        $this->assertStringNotContainsString('عميل باء للقاعدة', $texts, 'القاعدة سرّبت سجلاً خارج نطاق المستلم');
    }
}
