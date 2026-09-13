<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\MobileContextController;
use App\Models\Company;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **نطاقُ مفتاحِ API وعزلُ العميل** (Permissions 360 · م2 · 03.1 · 03.2 · 15.3).
 *
 * إثباتٌ يفشل أولاً: كلُّ حارسٍ هنا لم يكن موجوداً قبلَ الدفعة، فمفتاحٌ ضيّقُ النطاقِ كان
 * يقرأ ما يملكه مالكُه لا ما يشمله المفتاح، وحسابُ العميلِ كان يرى قائمةَ الشركاتِ الداخليّة.
 */
class Permissions360ApiScopeTest extends TestCase
{
    private function clientUser(string $email): User
    {
        $role = Role::create(['name' => 'دورُ عميلٍ ' . $email, 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        return User::create(['name' => 'عميل', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'account_type' => 'client', 'password_changed_at' => now()]);
    }

    private function callProtected(object $ctrl, string $method, array $args)
    {
        $ref = new \ReflectionMethod($ctrl, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($ctrl, $args);
    }

    /* ═══════════ 03.1 — نطاقُ المفتاحِ يُقيّد تقاريرَ الفريق ═══════════ */

    public function test_team_daily_denies_token_without_hr_scope(): void
    {
        $this->seedCore();

        // مفتاحٌ لمالكٍ كاملِ الصلاحيّة لكنّ نطاقَه «projects:v» فقط ⇒ لا يقرأ hr
        $h = ['Authorization' => 'Bearer ' . $this->apiToken($this->owner, 'projects:v')];
        $this->withHeaders($h)->getJson('/api/v1/reports/daily')->assertStatus(403);

        // مفتاحٌ نطاقُه «hr:v» ⇒ يمرّ
        $h2 = ['Authorization' => 'Bearer ' . $this->apiToken($this->owner, 'hr:v')];
        $this->withHeaders($h2)->getJson('/api/v1/reports/daily')->assertOk();
    }

    /* ═══════════ 03.2 — نطاقُ المفتاحِ يُقيّد ربطَ الأصلِ بالمشروع ═══════════ */

    public function test_project_assets_denies_token_without_assets_edit_scope(): void
    {
        $this->seedCore();

        // مفتاحٌ نطاقُه «projects:v» ينقصه «assets:e» ⇒ ٤٠٣ قبلَ بحثِ المشروع
        $h = ['Authorization' => 'Bearer ' . $this->apiToken($this->owner, 'projects:v')];
        $this->withHeaders($h)->getJson('/api/v1/projects/no-such/assets')->assertStatus(403);

        // مفتاحٌ يشمل «assets:e» و«projects:v» ⇒ يجتازُ الحارسَ فيسقطُ على مشروعٍ غيرِ موجود ٤٠٤
        $h2 = ['Authorization' => 'Bearer ' . $this->apiToken($this->owner, 'assets:e,projects:v')];
        $this->withHeaders($h2)->getJson('/api/v1/projects/no-such/assets')->assertStatus(404);
    }

    /* ═══════════ 15.3 — العميلُ لا يرى قائمةَ الشركاتِ الداخليّة ═══════════ */

    public function test_client_context_companies_dimension_is_empty(): void
    {
        $this->seedCore();
        Company::create(['name_ar' => 'شركةٌ داخليّة', 'status' => 'نشطة']);

        $ctrl = new MobileContextController();

        // حسابُ عميلٍ: بُعدُ الشركاتِ فارغٌ (لا تسريبَ لقائمةِ الشركاتِ الداخليّة)
        $client = $this->clientUser('ctxcl@test.local');
        $this->actingAs($client);
        $dim = $this->callProtected($ctrl, 'contextDimension', ['companies', null, 20]);
        $this->assertSame(0, $dim['count'], 'العميلُ لا يرى عددَ الشركات');
        $this->assertEmpty($dim['items'], 'قائمةُ الشركاتِ فارغةٌ للعميل');

        // مستخدمٌ داخليٌّ (المالك): يرى الشركةَ الداخليّة
        $this->actingAs($this->owner);
        $dimOwner = $this->callProtected($ctrl, 'contextDimension', ['companies', null, 20]);
        $this->assertGreaterThan(0, $dimOwner['count'], 'الداخليُّ يرى الشركاتِ في نطاقِه');
    }
}
