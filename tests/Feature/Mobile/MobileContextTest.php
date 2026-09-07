<?php

namespace Tests\Feature\Mobile;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Tests\TestCase;

/**
 * **C.1 · GET context + سياقُ العرض (SF-4)** — Mobile Readiness · الطور C · §109.
 *
 * يفحص أن قائمةَ الشركات/العملاء تُحلّ عبر `hub_scope` (محرّكُ العزل) وحدَه:
 * المقيَّدُ يرى مجموعتَه بالضبط، والعابرُ للحدّ **لا يتسرّب** (لا IDOR)، والترتيبُ
 * حتميّ. وترويستا `X-Lynomia-Company/-Client` تضيّقان العرضَ (`active`) من داخل
 * المسموح ولا توسّعانه أبداً — قيمةٌ خارجَ المسموح تُتجاهَل، والفاسدةُ/الغائبةُ لا
 * تكسر الطلب (spec §Headers · Critic S.4).
 */
class MobileContextTest extends TestCase
{
    use InteractsWithMobileAuth;
    use AssertsMobilePayload;

    private Company $coA;
    private Company $coB;
    private Company $coC;
    private Client $clA;
    private Client $clB;

    /** ثلاثُ شركاتٍ وعميلان (أ لشركةِ أ، ب لشركةِ ب) — أرضُ العزل */
    private function seedTenants(): void
    {
        $this->seedCore();
        $this->coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $this->coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $this->coC = Company::create(['name_ar' => 'شركة جيم', 'status' => 'نشطة']);
        $this->clA = Client::create(['name' => 'عميل ألف', 'company_id' => $this->coA->id]);
        $this->clB = Client::create(['name' => 'عميل باء', 'company_id' => $this->coB->id]);
    }

    // ════════════════════════════ C.1 · العزلُ في القائمة ════════════════════════════

    public function test_owner_context_lists_all_companies_and_clients_unrestricted(): void
    {
        $this->seedTenants();
        $data = $this->mobileLogin($this->owner, 'inst-owner-11111111');

        $res = $this->withHeaders($this->bearer($data['access_token']))
            ->getJson('/api/mobile/v1/context');
        $res->assertOk();

        $this->assertTrue($res->json('data.user.is_owner'));
        $this->assertFalse($res->json('data.companies.restricted'), 'المالكُ غيرُ مقيَّد');
        $this->assertFalse($res->json('data.clients.restricted'));

        $companyIds = collect($res->json('data.companies.items'))->pluck('id')->all();
        $this->assertContains($this->coA->id, $companyIds);
        $this->assertContains($this->coB->id, $companyIds);
        $this->assertContains($this->coC->id, $companyIds);

        $clientIds = collect($res->json('data.clients.items'))->pluck('id')->all();
        $this->assertContains($this->clA->id, $clientIds);
        $this->assertContains($this->clB->id, $clientIds);
    }

    public function test_company_restricted_user_sees_only_its_company_and_that_companys_clients(): void
    {
        $this->seedTenants();
        $this->employee->forceFill(['companies' => [$this->coA->id]])->saveQuietly();
        $data = $this->mobileLogin($this->employee, 'inst-emp-a-11111111');

        $res = $this->withHeaders($this->bearer($data['access_token']))
            ->getJson('/api/mobile/v1/context');
        $res->assertOk();

        // مقيَّدةٌ على شركةِ ألف — فرايةُ التقييد مرفوعة
        $this->assertTrue($res->json('data.companies.restricted'));

        $companyIds = collect($res->json('data.companies.items'))->pluck('id')->all();
        $this->assertSame([$this->coA->id], $companyIds, 'شركةُ ألف وحدَها');
        $this->assertNotContains($this->coB->id, $companyIds, 'شركةُ باء لا تتسرّب (لا IDOR)');
        $this->assertNotContains($this->coC->id, $companyIds);

        // عملاءُ العرض ينعزلون بشركتِها أيضاً — عميلُ باء غائب
        $clientIds = collect($res->json('data.clients.items'))->pluck('id')->all();
        $this->assertSame([$this->clA->id], $clientIds, 'عميلُ ألف وحدَه');
        $this->assertNotContains($this->clB->id, $clientIds, 'عميلُ شركةٍ أجنبيّة لا يتسرّب');

        // اسمُ العرض يُحلّ من عمود العرض (name_ar للشركات، name للعملاء)
        $this->assertSame('شركة ألف', $res->json('data.companies.items.0.name'));
        $this->assertSame('عميل ألف', $res->json('data.clients.items.0.name'));
    }

    public function test_client_restricted_user_sees_only_its_permitted_clients(): void
    {
        $this->seedTenants();
        // معزولٌ على العملاء عبر users.clients (توافقٌ رجعيّ — لا صفَّ عضويّةٍ له)
        $clientUser = User::create(['name' => 'حسابُ عميل', 'email' => 'clientuser@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id, 'status' => 'نشط',
            'clients' => [$this->clA->id], 'password_changed_at' => now()]);

        $data = $this->mobileLogin($clientUser, 'inst-client-11111111');
        $res = $this->withHeaders($this->bearer($data['access_token']))
            ->getJson('/api/mobile/v1/context');
        $res->assertOk();

        $this->assertTrue($res->json('data.clients.restricted'));
        $clientIds = collect($res->json('data.clients.items'))->pluck('id')->all();
        $this->assertSame([$this->clA->id], $clientIds, 'العميلُ المسموحُ وحدَه');
        $this->assertNotContains($this->clB->id, $clientIds, 'عميلٌ غيرُ مسموحٍ لا يتسرّب (لا IDOR)');
    }

    public function test_context_company_order_is_deterministic_across_calls(): void
    {
        $this->seedTenants();
        $data = $this->mobileLogin($this->owner, 'inst-owner-22222222');
        $h    = $this->bearer($data['access_token']);

        $first  = collect($this->withHeaders($h)->getJson('/api/mobile/v1/context')->json('data.companies.items'))->pluck('id')->all();
        $second = collect($this->withHeaders($h)->getJson('/api/mobile/v1/context')->json('data.companies.items'))->pluck('id')->all();
        $this->assertSame($first, $second, 'ترتيبٌ حتميّ (display,id) عبر النداءين');
    }

    // ════════════════════════════ SF-4 · ترويساتُ السياق ════════════════════════════

    public function test_valid_company_header_the_user_can_see_narrows_active_view(): void
    {
        $this->seedTenants();
        $this->employee->forceFill(['companies' => [$this->coA->id, $this->coB->id]])->saveQuietly();
        $data = $this->mobileLogin($this->employee, 'inst-emp-narrow-1111');

        $res = $this->withHeaders($this->bearer($data['access_token']) + ['X-Lynomia-Company' => $this->coA->id])
            ->getJson('/api/mobile/v1/context');
        $res->assertOk();

        // الترويسةُ المسموحةُ تضيّق `active` إليها — دون توسيعِ القائمة
        $this->assertSame($this->coA->id, $res->json('data.companies.active'), 'التضييقُ النشطُ = شركةُ ألف');
        $ids = collect($res->json('data.companies.items'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$this->coA->id, $this->coB->id], $ids, 'القائمةُ تبقى مجموعتَه كاملةً — التضييقُ على active لا على items');
    }

    public function test_company_header_the_user_cannot_see_is_ignored_and_never_widens_scope(): void
    {
        $this->seedTenants();
        // مقيَّدةٌ على [ألف، باء] — «جيم» خارجُ مجموعتِها
        $this->employee->forceFill(['companies' => [$this->coA->id, $this->coB->id]])->saveQuietly();
        $data = $this->mobileLogin($this->employee, 'inst-emp-forbid-111');

        $res = $this->withHeaders($this->bearer($data['access_token']) + ['X-Lynomia-Company' => $this->coC->id])
            ->getJson('/api/mobile/v1/context');
        $res->assertOk();

        // شركةٌ لا يراها ⇒ تُتجاهَل: active=null، ولا تدخل القائمة (لا توسيع)
        $this->assertNull($res->json('data.companies.active'), 'ترويسةٌ لشركةٍ ممنوعةٍ تُتجاهَل (لا توسيع)');
        $ids = collect($res->json('data.companies.items'))->pluck('id')->all();
        $this->assertNotContains($this->coC->id, $ids, 'الشركةُ الممنوعةُ لا تظهر — الترويسةُ لا توسّع النطاق');
        $this->assertEqualsCanonicalizing([$this->coA->id, $this->coB->id], $ids, 'النطاقُ لم يتجاوز hub_scope');
    }

    public function test_forbidden_client_header_is_ignored_and_never_leaks_that_client(): void
    {
        $this->seedTenants();
        // مقيَّدٌ على العملاء (clients=[ألف]) — فعميلُ باء خارجُ مجموعتِه المسموحة
        $clientUser = User::create(['name' => 'حسابُ عميل', 'email' => 'clhdr@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id, 'status' => 'نشط',
            'clients' => [$this->clA->id], 'password_changed_at' => now()]);
        $data = $this->mobileLogin($clientUser, 'inst-clhdr-1111111');

        // (أ) ترويسةٌ لعميلٍ ممنوعٍ (باء) ⇒ تُتجاهَل: active=null، ولا يدخل القائمة
        $forbidden = $this->withHeaders($this->bearer($data['access_token']) + ['X-Lynomia-Client' => $this->clB->id])
            ->getJson('/api/mobile/v1/context');
        $forbidden->assertOk();
        $this->assertNull($forbidden->json('data.clients.active'), 'عميلٌ ممنوعٌ في الترويسة يُتجاهَل (لا توسيع)');
        $ids = collect($forbidden->json('data.clients.items'))->pluck('id')->all();
        $this->assertSame([$this->clA->id], $ids, 'القائمةُ لا تتجاوز hub_scope');
        $this->assertNotContains($this->clB->id, $ids, 'العميلُ الممنوعُ لا يتسرّب عبر الترويسة');

        // (ب) ترويسةٌ لعميلٍ مسموحٍ (ألف) ⇒ تضيّق active إليه
        $allowed = $this->withHeaders($this->bearer($data['access_token']) + ['X-Lynomia-Client' => $this->clA->id])
            ->getJson('/api/mobile/v1/context');
        $allowed->assertOk();
        $this->assertSame($this->clA->id, $allowed->json('data.clients.active'), 'العميلُ المسموحُ يضيّق active');
    }

    public function test_unrestricted_dimension_accepts_header_as_narrowing_without_widening(): void
    {
        $this->seedTenants();
        // المالكُ غيرُ مقيَّدٍ على أيّ بُعد — الترويسةُ تضيّق عرضَه لا توسّع صلاحيتَه
        // (يرى الكلَّ أصلاً، فتضييقُه لشركةٍ بعينِها آمنٌ بنيويّاً — التطبيقُ AND فوق hub_scope)
        $data = $this->mobileLogin($this->owner, 'inst-owner-narrow-1');

        $res = $this->withHeaders($this->bearer($data['access_token']) + ['X-Lynomia-Company' => $this->coB->id])
            ->getJson('/api/mobile/v1/context');
        $res->assertOk();
        $this->assertSame($this->coB->id, $res->json('data.companies.active'), 'غيرُ المقيَّد يقبل التضييقَ لنفسه');
        // ولم تُنقَص القائمةُ الكاملةُ (التضييقُ على active — التطبيقُ الفعليُّ في الطور D)
        $ids = collect($res->json('data.companies.items'))->pluck('id')->all();
        $this->assertContains($this->coA->id, $ids);
        $this->assertContains($this->coB->id, $ids);
    }

    public function test_missing_and_malformed_context_headers_are_ignored_gracefully(): void
    {
        $this->seedTenants();
        // مقيَّدةٌ على البُعدين معاً (شركةُ ألف + عميلُ ألف) كي تُحقَّق كلتا الترويستين المشوَّهتين
        $this->employee->forceFill(['companies' => [$this->coA->id], 'clients' => [$this->clA->id]])->saveQuietly();
        $data = $this->mobileLogin($this->employee, 'inst-emp-bad-11111');

        // (أ) بلا ترويسةٍ أصلاً — active=null، لا ٥٠٠
        $none = $this->withHeaders($this->bearer($data['access_token']))
            ->getJson('/api/mobile/v1/context');
        $none->assertOk();
        $this->assertNull($none->json('data.companies.active'));
        $this->assertNull($none->json('data.clients.active'));

        // (ب) ترويستان مشوَّهتان (طويلةٌ جداً + محاولةُ حقنٍ/مسار) — تُتجاهَلان بلا كسر
        $bad = $this->withHeaders($this->bearer($data['access_token']) + [
            'X-Lynomia-Company' => str_repeat('x', 200) . " ' OR 1=1 --",
            'X-Lynomia-Client'  => '../../etc/passwd',
        ])->getJson('/api/mobile/v1/context');
        $bad->assertOk();
        $this->assertNull($bad->json('data.companies.active'), 'ترويسةٌ مشوَّهة تُتجاهَل');
        $this->assertNull($bad->json('data.clients.active'), 'ترويسةُ عميلٍ مشوَّهةٌ خارجَ المسموح تُتجاهَل');
        // ولم يتسرّب شيءٌ أجنبيٌّ رغم المحاولة
        $this->assertSame([$this->coA->id], collect($bad->json('data.companies.items'))->pluck('id')->all());
        $this->assertSame([$this->clA->id], collect($bad->json('data.clients.items'))->pluck('id')->all());
    }

    // ════════════════════════════ المصادقة ════════════════════════════

    public function test_context_requires_a_valid_mobile_access_token(): void
    {
        $this->seedTenants();
        $this->getJson('/api/mobile/v1/context')
            ->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
