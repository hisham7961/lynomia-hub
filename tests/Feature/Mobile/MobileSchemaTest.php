<?php

namespace Tests\Feature\Mobile;

use Tests\TestCase;

/**
 * **C.4 · GET schema · schema/modules** (ETag/304) — Mobile Readiness · الطور C · §109.
 *
 * المخطّطُ المُنطَّقُ الأسلمُ: يعكس `hub_visible_fields`/`hub_can` (فلا يظهر ما لا
 * يُسمح)، ويحمل تصنيفَ مزامنةٍ لكلِّ وحدة، وتحقّقاً مشتقّاً — **دون** اسمِ الجدول/
 * العمود الفيزيائيّ (تسريبُ `V1Controller.php:33` لا يُكرَّر). ويثبت أن
 * `/api/v1/modules` القديم يبقى كما هو (بـ`table`) للتوافق (لا كسر · CLAUDE.md).
 */
class MobileSchemaTest extends TestCase
{
    use InteractsWithMobileAuth;
    use AssertsMobilePayload;

    /** وحدةٌ من قائمة المخطّط بمفتاحها (أو null) */
    private function moduleByKey(array $modules, string $key): ?array
    {
        foreach ($modules as $m) {
            if (($m['key'] ?? null) === $key) return $m;
        }

        return null;
    }

    // ════════════════════════════ لا تسريبَ بنيةٍ فيزيائية ════════════════════════════

    public function test_schema_modules_never_leaks_physical_table_or_col_keys(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->owner, 'inst-schema-notab-1');

        $res = $this->withHeaders($this->bearer($data['access_token']))
            ->getJson('/api/mobile/v1/schema/modules');
        $res->assertOk();

        // لا مفتاحَ `table` ولا `col` في أيّ عمقٍ من الحمولة (INVENTORY §7c)
        $this->assertNoKeysDeep($this->forbiddenSchemaKeys(), $res->json('data'),
            'مخطّطُ الجوال يُسقط البنيةَ الفيزيائيّة');

        // ولا مفاتيحَ أسرار (نظيرُ باقي نقاط القراءة)
        $this->assertNoKeysDeep($this->forbiddenSecretKeys(), $res->json('data'), 'المخطّطُ بلا أسرار');
    }

    public function test_full_schema_also_drops_table_and_carries_mobile_api_version(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->owner, 'inst-schema-full-11');

        $res = $this->withHeaders($this->bearer($data['access_token']))
            ->getJson('/api/mobile/v1/schema');
        $res->assertOk();

        $this->assertNoKeysDeep($this->forbiddenSchemaKeys(), $res->json('data'), 'المخطّطُ الكامل يُسقط البنيةَ الفيزيائيّة');
        $this->assertSame('1', $res->json('data.mobile_api_version'));
        $this->assertNotEmpty($res->json('data.schema_version'));
        $this->assertNotEmpty($res->json('data.modules'));
    }

    // ════════════════════════════ تصنيفُ المزامنة + التحقّقُ المشتقّ ════════════════════════════

    public function test_every_module_carries_a_sync_class_from_the_allowed_set(): void
    {
        $this->seedCore();
        $data    = $this->mobileLogin($this->owner, 'inst-schema-sync-11');
        $allowed = (array) config('hub.mobile_sync.classes');

        $modules = $this->withHeaders($this->bearer($data['access_token']))
            ->getJson('/api/mobile/v1/schema/modules')->json('data.modules');

        $this->assertNotEmpty($modules);
        foreach ($modules as $m) {
            $this->assertArrayHasKey('sync_class', $m, "الوحدةُ «{$m['key']}» بلا تصنيفِ مزامنة");
            $this->assertContains($m['sync_class'], $allowed,
                "تصنيفُ «{$m['key']}» = «{$m['sync_class']}» خارجَ الأصناف المعلَنة");
        }

        // عيّنةٌ مقصودة: العملاءُ قابلون للمزامنة التزايديّة، والخزنةُ حسّاسةٌ لا تُخبَّأ
        $clients = $this->moduleByKey($modules, 'clients');
        $this->assertSame('CACHEABLE_INCREMENTAL', $clients['sync_class']);
        if ($vault = $this->moduleByKey($modules, 'vault')) {
            $this->assertSame('SENSITIVE_NO_PERSIST', $vault['sync_class'], 'الخزنةُ لا تُكتَب على الجهاز');
        }
    }

    public function test_module_fields_carry_derived_validation_metadata(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->owner, 'inst-schema-valid-1');

        $modules = $this->withHeaders($this->bearer($data['access_token']))
            ->getJson('/api/mobile/v1/schema/modules')->json('data.modules');

        $clients = $this->moduleByKey($modules, 'clients');
        $this->assertNotNull($clients);
        $this->assertArrayHasKey('can', $clients);
        $this->assertTrue($clients['can']['v'], 'المالكُ يرى العملاء');

        // حقلُ الاسم: مطلوبٌ (تحقّقٌ مشتقّ) — كلُّ حقلٍ يحمل مفاتيحَ التحقّق
        $byKey = collect($clients['fields'])->keyBy('key');
        $this->assertTrue((bool) $byKey['name']['required'], 'اسمُ العميل مطلوب');
        foreach ($clients['fields'] as $f) {
            foreach (['key', 'label', 'type', 'required', 'readonly'] as $meta) {
                $this->assertArrayHasKey($meta, $f, "حقلٌ بلا «{$meta}»");
            }
            $this->assertIsBool($f['required']);
            $this->assertIsBool($f['readonly']);
        }

        // حقلُ الدولة (sel) يحمل خياراتِه — تحقّقٌ مشتقّ من options
        $this->assertNotEmpty($byKey['country']['options'] ?? null, 'حقلُ sel يحمل خياراته');
    }

    // ════════════════════════════ الوعيُ بالصلاحيات ════════════════════════════

    public function test_schema_is_permission_aware_hidden_field_is_absent_for_restricted_user(): void
    {
        $this->seedCore();
        // دورُ الموظفة يُخفي حقلَ بريدِ العميل (field_rules[clients][email]=hide)
        $this->employee->role->forceFill(['field_rules' => ['clients' => ['email' => 'hide']]])->save();

        $empData   = $this->mobileLogin($this->employee, 'inst-schema-hide-e1');
        $ownerData = $this->mobileLogin($this->owner, 'inst-schema-hide-o1');

        $empModules = $this->withHeaders($this->bearer($empData['access_token']))
            ->getJson('/api/mobile/v1/schema/modules')->json('data.modules');
        $ownerModules = $this->withHeaders($this->bearer($ownerData['access_token']))
            ->getJson('/api/mobile/v1/schema/modules')->json('data.modules');

        $empFieldKeys   = collect($this->moduleByKey($empModules, 'clients')['fields'])->pluck('key')->all();
        $ownerFieldKeys = collect($this->moduleByKey($ownerModules, 'clients')['fields'])->pluck('key')->all();

        $this->assertContains('email', $ownerFieldKeys, 'المالكُ يرى حقلَ البريد (ضبطٌ مرجعيّ)');
        $this->assertNotContains('email', $empFieldKeys, 'الحقلُ المخفيُّ (hide) غائبٌ عن مخطّطِ المقيَّد');
        $this->assertContains('name', $empFieldKeys, 'الحقولُ المرئيّةُ الأخرى باقية');
    }

    public function test_schema_omits_modules_the_user_cannot_view(): void
    {
        $this->seedCore();
        // دورٌ بلا صلاحيةِ عرضٍ على «clients» فقط
        $role   = $this->employee->role;
        $matrix = $role->matrix;
        $matrix['clients'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role->forceFill(['matrix' => $matrix])->save();

        $data    = $this->mobileLogin($this->employee, 'inst-schema-omit-11');
        $modules = $this->withHeaders($this->bearer($data['access_token']))
            ->getJson('/api/mobile/v1/schema/modules')->json('data.modules');

        $keys = collect($modules)->pluck('key')->all();
        $this->assertNotContains('clients', $keys, 'وحدةٌ بلا عرضٍ تُحذف من المخطّط');
        $this->assertContains('companies', $keys, 'الوحداتُ المرئيّةُ الأخرى باقية');
    }

    // ════════════════════════════ ETag/304 ════════════════════════════

    public function test_schema_modules_etag_returns_304_on_repeat(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->owner, 'inst-schema-etag-11');
        $h    = $this->bearer($data['access_token']);

        $first = $this->withHeaders($h)->getJson('/api/mobile/v1/schema/modules');
        $first->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $second = $this->withHeaders($h + ['If-None-Match' => $etag])
            ->getJson('/api/mobile/v1/schema/modules');
        $second->assertStatus(304);
        $this->assertSame('', $second->getContent(), 'جسمُ 304 فارغ');
    }

    public function test_full_schema_etag_returns_304_on_repeat(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->owner, 'inst-schema-etag-22');
        $h    = $this->bearer($data['access_token']);

        $etag = $this->withHeaders($h)->getJson('/api/mobile/v1/schema')->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->withHeaders($h + ['If-None-Match' => $etag])
            ->getJson('/api/mobile/v1/schema')->assertStatus(304);
    }

    public function test_schema_requires_authentication(): void
    {
        $this->seedCore();
        $this->getJson('/api/mobile/v1/schema')->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->getJson('/api/mobile/v1/schema/modules')->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    // ════════════════════════════ توافقٌ رجعيّ: /api/v1/modules يبقى كما هو ════════════════════════════

    public function test_legacy_v1_modules_still_emits_physical_table_for_integrations(): void
    {
        $this->seedCore();
        $tok = $this->apiToken($this->owner);

        $res = $this->getJson('/api/v1/modules', ['Authorization' => "Bearer $tok"]);
        $res->assertOk();

        $modules = $res->json('modules');
        $this->assertNotEmpty($modules, 'الشكلُ القديم يعيد modules في الطبقة العليا (لا data)');

        // كلُّ وحدةٍ قديمةٍ تحمل `table` الفيزيائيّ — عقدُ التكامل ثابت (لا كسر)
        $companies = collect($modules)->firstWhere('key', 'companies');
        $this->assertNotNull($companies);
        $this->assertArrayHasKey('table', $companies, '/api/v1/modules يُبقي table للتوافق');
        $this->assertSame('companies', $companies['table']);

        // وترويسةُ إصدارِ العقد كما هي على السطح القديم
        $res->assertHeader('X-API-Version', '1');
    }
}
