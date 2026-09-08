<?php

namespace Tests\Feature\Mobile;

use App\Models\Asset;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **F.3 · الماسحُ (QR/باركود/سيريال)** — Mobile Readiness · الطور F.
 *
 * `MobileFileController::identityResolve` يعيد استعمالَ `V1Controller::identityResolve`
 * حرفاً (لا محرّكٌ ثانٍ). لا `api_token` في الجوال ⇒ `tokenAllows` يمرّ فتُطبَّق
 * صلاحيّاتُ المستخدم كاملةً تحت `hub_can`، والنطاقُ داخلَ `Identity::resolve` —
 * فلا يُكشَف سجلٌّ خارجَ نطاق المستخدم (لا بصلاحيّةٍ ولا بعزلٍ شركاتيّ). الحمولةُ
 * معرِّفاتٌ (`{type, module, id}`) لا حقولُ سجلٍّ كاملة.
 */
class MobileScannerTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function auth(User $u, ?string $uuid = null): array
    {
        return $this->bearer($this->mobileLogin($u, $uuid)['access_token']);
    }

    public function test_owner_resolves_an_in_scope_asset_code_to_module_and_id(): void
    {
        $this->seedCore();
        $asset = Asset::create(['code' => 'LYNSCAN100', 'name' => 'لابتوب المسح', 'status' => 'متاح', 'type' => 'لابتوب']);

        $h = $this->auth($this->owner, 'inst-scan-ok-1111');
        $res = $this->withHeaders($h)->getJson('/api/mobile/v1/identity/resolve/LYNSCAN100')->assertOk();

        // معرِّفاتٌ فقط: النوعُ والوحدةُ والمعرّف = هدفُ الرابطِ العميق
        $res->assertJsonPath('type', 'asset')
            ->assertJsonPath('module', 'assets')
            ->assertJsonPath('id', $asset->id);
    }

    public function test_resolver_uppercases_so_scanning_lowercase_still_hits(): void
    {
        $this->seedCore();
        $asset = Asset::create(['code' => 'LYNSCAN200', 'name' => 'شاشة', 'status' => 'متاح', 'type' => 'شاشة']);

        $h = $this->auth($this->owner, 'inst-scan-lc-1111');
        // المحلّلُ يرفع الحالةَ (mb_strtoupper) — مسحُ الحروفِ الصغيرةِ يطابق الكودَ الكبير
        $this->withHeaders($h)->getJson('/api/mobile/v1/identity/resolve/lynscan200')
            ->assertOk()->assertJsonPath('id', $asset->id);
    }

    public function test_unknown_code_resolves_to_type_none_without_leaking(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-scan-none-11');

        $this->withHeaders($h)->getJson('/api/mobile/v1/identity/resolve/DOES-NOT-EXIST-999')
            ->assertOk()->assertJsonPath('type', 'none');
    }

    /**
     * **لا يُكشَف خارجَ العزلِ الشركاتيّ:** أصلُ شركةٍ أجنبيّة لا يُحلّه معزولٌ عنها —
     * `Identity::resolve` يمرّ بـ`hub_company_scope`، فما لا تراه شاشتُه لا يراه بالمسح.
     */
    public function test_company_isolated_user_cannot_resolve_a_foreign_company_asset(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);
        $asset = Asset::create(['code' => 'LYNSCAN300', 'name' => 'سيرفر باء',
            'status' => 'متاح', 'type' => 'سيرفر', 'company_id' => $coB->id]);

        // موظفةٌ معزولةٌ على شركةِ ألف — تملك assets:v لكنّ الأصلَ في شركةِ باء
        $this->employee->forceFill(['companies' => [$coA->id]])->saveQuietly();
        $h = $this->auth($this->employee, 'inst-scan-iso-111');

        $res = $this->withHeaders($h)->getJson('/api/mobile/v1/identity/resolve/LYNSCAN300')->assertOk();
        $res->assertJsonPath('type', 'none');
        // ولا يتسرّبُ معرّفُ الأصلِ الأجنبيِّ في أيّ موضعٍ من الحمولة
        $this->assertStringNotContainsString((string) $asset->id, $res->getContent(),
            'معرّفُ أصلِ شركةٍ أجنبيّة تسرّب عبر المسح (IDOR)');
    }

    /**
     * **لا يُكشَف بلا صلاحيّة:** مستخدمٌ بلا رؤيةٍ للأصول/المنتجات/المخزون لا يُحلّ الكودَ —
     * المحلّلُ يتخطّى كلَّ بحثٍ لوحدةٍ لا يراها (`hub_can(...,'v')` أوّلُ بوّابة).
     */
    public function test_user_without_scannable_module_view_gets_none(): void
    {
        $this->seedCore();
        $asset = Asset::create(['code' => 'LYNSCAN400', 'name' => 'أصل', 'status' => 'متاح', 'type' => 'لابتوب']);

        $modules = array_keys(config('hub.modules'));
        $matrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
        foreach (['assets', 'products', 'stock', 'phones'] as $m) $matrix[$m] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role = Role::create(['name' => 'بلا رؤيةِ مسحٍ', 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);
        $blind = User::create(['name' => 'أعمى المسح', 'email' => 'blindscan@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $h = $this->auth($blind, 'inst-scan-blind-1');
        $res = $this->withHeaders($h)->getJson('/api/mobile/v1/identity/resolve/LYNSCAN400')->assertOk();
        $res->assertJsonPath('type', 'none');
        $this->assertStringNotContainsString((string) $asset->id, $res->getContent());
    }

    public function test_scanner_requires_a_valid_mobile_access_token(): void
    {
        $this->seedCore();
        Asset::create(['code' => 'LYNSCAN500', 'name' => 'أصل', 'status' => 'متاح', 'type' => 'لابتوب']);

        $this->getJson('/api/mobile/v1/identity/resolve/LYNSCAN500')
            ->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
