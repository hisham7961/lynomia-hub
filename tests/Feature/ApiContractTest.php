<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * **عقدُ API الواحد** — اختبارُ عقدٍ لا اختبارُ سلوك: ما يعتمد عليه المستهلكُ
 * الخارجيّ (n8n، جوال، سكربت) يُثبَّت هنا كي لا يتغيّر بصمت.
 *
 * ثلاثة ضمانات: (١) الشكلُ القديم محفوظٌ حرفياً (`error`، `message`+`errors`،
 * `data/total/page/last_page`)، (٢) كلُّ خطأٍ يحمل كوداً آلياً ثابتاً و`request_id`
 * يطابق ترويسة `X-Request-Id`، (٣) لا تسريبَ لأسماء أصنافٍ داخلية أو مواضع ملفات.
 */
class ApiContractTest extends TestCase
{
    protected function h($user = null, ?string $scopes = null): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiToken($user ?? $this->owner, $scopes)];
    }

    /* ── ١) الأكواد والغلاف ── */

    public function test_every_error_class_carries_a_machine_code_and_request_id(): void
    {
        $this->seedCore();
        config(['app.debug' => false]);

        $cases = [
            'UNAUTHENTICATED' => [$this->getJson('/api/v1/me'), 401],
            'RESOURCE_NOT_FOUND' => [$this->withHeaders($this->h())->getJson('/api/v1/no-such-module'), 404],
            'VALIDATION_FAILED' => [$this->withHeaders($this->h())->postJson('/api/v1/clients', []), 422],
            'FORBIDDEN' => [$this->withHeaders($this->h($this->viewer))->postJson('/api/v1/clients', ['name' => 'x']), 403],
            'INSUFFICIENT_SCOPE' => [$this->withHeaders($this->h($this->owner, 'tasks:v'))->getJson('/api/v1/clients'), 403],
            'METHOD_NOT_ALLOWED' => [$this->withHeaders($this->h())->putJson('/api/v1/me', []), 405],
        ];
        foreach ($cases as $code => [$res, $status]) {
            $res->assertStatus($status);
            $res->assertJsonPath('code', $code);
            $res->assertHeader('X-Error-Code', $code);
            $body = $res->json();
            $this->assertArrayHasKey('error', $body, "$code: مفتاح التوافق error");
            $this->assertArrayHasKey('message', $body, "$code: message");
            $this->assertSame($res->headers->get('X-Request-Id'), $body['request_id'], "$code: request_id يطابق الترويسة");
            $this->assertArrayNotHasKey('exception', $body, "$code: لا اسم صنفٍ داخليّ");
            $this->assertArrayNotHasKey('trace', $body, "$code: لا أثر تنفيذ");
        }

        // التحقق يحتفظ بشكل Laravel القديم كاملاً + التفاصيل المهيكلة
        $v = $cases['VALIDATION_FAILED'][0]->json();
        $this->assertArrayHasKey('errors', $v);
        $this->assertArrayHasKey('name', $v['errors']);
        $this->assertSame($v['errors'], $v['details']);
    }

    public function test_missing_record_404_does_not_leak_the_model_class_name(): void
    {
        $this->seedCore();
        config(['app.debug' => false]);
        $res = $this->withHeaders($this->h())->getJson('/api/v1/clients/00000000-0000-0000-0000-000000000000');
        $res->assertStatus(404)->assertJsonPath('code', 'RESOURCE_NOT_FOUND')->assertJsonPath('details.kind', 'record');
        $this->assertStringNotContainsString('App\\', $res->getContent(), 'اسمُ صنف النموذج لا يخرج للعميل');
        $this->assertStringNotContainsString('No query results', $res->getContent());
    }

    public function test_internal_error_is_safe_in_production_and_verbose_only_in_debug(): void
    {
        $this->seedCore();
        Route::middleware('api')->get('/api/v1/a/b/c/boom', function () {
            throw new \RuntimeException('secret detail /var/www/app.php');
        });

        config(['app.debug' => false]);
        $res = $this->withHeaders($this->h())->getJson('/api/v1/a/b/c/boom');
        $res->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR');
        $this->assertStringNotContainsString('secret detail', $res->getContent());
        $this->assertStringNotContainsString('RuntimeException', $res->getContent());
        $this->assertNotEmpty($res->json('request_id'));

        config(['app.debug' => true]);
        $res = $this->withHeaders($this->h())->getJson('/api/v1/a/b/c/boom');
        $res->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR')->assertJsonPath('debug.exception', \RuntimeException::class);
    }

    public function test_rate_limit_answers_with_code_and_retry_after(): void
    {
        $this->seedCore();
        $h = $this->h();
        $last = null;
        for ($i = 0; $i < 125; $i++) {
            $last = $this->withHeaders($h)->getJson('/api/v1/me');
            if ($last->getStatusCode() === 429) break;
        }
        $last->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
        $this->assertNotNull($last->headers->get('Retry-After'));
        $this->assertSame('0', $last->headers->get('X-RateLimit-Remaining'));
    }

    public function test_maintenance_and_lockdown_carry_codes(): void
    {
        $this->seedCore();
        $h = $this->h($this->employee);

        $this->hubSetting('maintenance.on', '1');
        $this->withHeaders($h)->getJson('/api/v1/me')->assertOk();   // القراءة تمرّ في الصيانة
        $this->withHeaders($h)->postJson('/api/v1/clients', ['name' => 'x'])
            ->assertStatus(503)->assertJsonPath('code', 'MAINTENANCE')->assertHeader('Retry-After');
        $this->hubSetting('maintenance.on', '0');

        $this->hubSetting('security.lockdown', '1');
        $this->getJson('/api/v1/clients')->assertStatus(503)->assertJsonPath('code', 'LOCKDOWN');
    }

    public function test_successful_responses_keep_legacy_keys_and_add_meta(): void
    {
        $this->seedCore();
        Client::create(['name' => 'أ']);
        $res = $this->withHeaders($this->h())->getJson('/api/v1/clients?per=10')->assertOk();
        $res->assertJsonStructure(['data', 'total', 'page', 'last_page', 'meta' => ['page', 'per', 'total', 'last_page', 'has_more', 'sort', 'dir'], 'request_id']);
        $this->assertSame(10, $res->json('meta.per'));
        $res->assertHeader('X-API-Version', '1');
        $res->assertHeader('X-Request-Id');

        $id = $res->json('data.0.id');
        $one = $this->withHeaders($this->h())->getJson('/api/v1/clients/' . $id)->assertOk();
        $one->assertJsonStructure(['data' => ['id'], 'request_id']);
        $this->assertSame('"1"', $one->headers->get('ETag'), 'النسخة الأولى في ETag');
    }

    /* ── ٢) الفرز والترشيح ── */

    public function test_sorting_is_whitelisted_and_hidden_fields_are_ignored(): void
    {
        $this->seedCore();
        foreach (['ج', 'أ', 'ب'] as $n) Client::create(['name' => $n, 'email' => $n . '@x.io']);

        $asc = $this->withHeaders($this->h())->getJson('/api/v1/clients?sort=name&dir=asc')->assertOk();
        $this->assertSame(['أ', 'ب', 'ج'], array_column($asc->json('data'), 'name'));
        $this->assertSame('name', $asc->json('meta.sort'));
        $this->assertSame('asc', $asc->json('meta.dir'));

        $desc = $this->withHeaders($this->h())->getJson('/api/v1/clients?sort=-name')->assertOk();
        $this->assertSame(['ج', 'ب', 'أ'], array_column($desc->json('data'), 'name'));

        // عمودٌ من خارج الوحدة أو عمودُ قاعدةٍ خام: يُتجاهَل بصمت لا ٥٠٠ ولا حقن
        $this->withHeaders($this->h())->getJson('/api/v1/clients?sort=password;drop')->assertOk()->assertJsonPath('meta.sort', 'created_at');

        // حقلٌ محجوب عن الدور: الفرزُ به إفشاءٌ بلا عرض — يُتجاهَل
        $this->employee->role->forceFill(['field_rules' => ['clients' => ['email' => 'hide']]])->save();
        $this->employee->unsetRelation('role');
        $r = $this->withHeaders($this->h($this->employee))->getJson('/api/v1/clients?sort=email')->assertOk();
        $this->assertSame('created_at', $r->json('meta.sort'));
        $this->assertArrayNotHasKey('email', $r->json('data.0'));
    }

    public function test_time_filters_and_field_filters_narrow_the_list(): void
    {
        $this->seedCore();
        $old = Client::create(['name' => 'قديم']);
        Client::whereKey($old->id)->update(['created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30)]);
        Client::create(['name' => 'جديد']);

        $h = $this->h();
        $this->assertSame(['جديد'], array_column($this->withHeaders($h)->getJson('/api/v1/clients?created_from=' . now()->subDay()->toDateString())->json('data'), 'name'));
        $this->assertSame(['قديم'], array_column($this->withHeaders($h)->getJson('/api/v1/clients?created_to=' . now()->subDays(2)->toDateString())->json('data'), 'name'));
        $this->assertSame(['جديد'], array_column($this->withHeaders($h)->getJson('/api/v1/clients?updated_since=' . rawurlencode(now()->subHour()->toIso8601String()))->json('data'), 'name'));
        $this->withHeaders($h)->getJson('/api/v1/clients?created_from=not-a-date')->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');

        // مرشِّحُ المراجع كما في الويب: f[key]=uuid
        $c = Client::create(['name' => 'صاحب المشروع']);
        \App\Models\Project::create(['name' => 'مشروعه', 'client_id' => $c->id, 'status' => 'نشط']);
        \App\Models\Project::create(['name' => 'مشروع آخر', 'status' => 'نشط']);
        $r = $this->withHeaders($h)->getJson('/api/v1/projects?f[clientId]=' . $c->id)->assertOk();
        $this->assertSame(['مشروعه'], array_column($r->json('data'), 'name'));
    }

    /* ── ٣) التعديل الجزئيّ والقفل التفاؤليّ ── */

    public function test_patch_writes_only_sent_fields_while_put_replaces(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل', 'email' => 'a@b.c', 'phone' => '111']);
        $h = $this->h();

        $this->withHeaders($h)->patchJson('/api/v1/clients/' . $c->id, ['phone' => '222'])->assertOk()
            ->assertJsonPath('data.phone', '222')->assertJsonPath('data.email', 'a@b.c');

        $this->withHeaders($h)->patchJson('/api/v1/clients/' . $c->id, ['unknown_field' => 'x'])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');

        // PUT يبقى استبدالاً كاملاً — العقدُ الموثَّق لا يتغيّر
        $this->withHeaders($h)->putJson('/api/v1/clients/' . $c->id, ['name' => 'عميل'])->assertOk()
            ->assertJsonPath('data.email', null);
    }

    public function test_stale_version_is_rejected_with_version_conflict(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $h = $this->h();
        $etag = $this->withHeaders($h)->getJson('/api/v1/clients/' . $c->id)->headers->get('ETag');
        $this->assertSame('"1"', $etag);

        $this->withHeaders($h + ['If-Match' => '"7"'])->patchJson('/api/v1/clients/' . $c->id, ['name' => 'س'])
            ->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT')->assertJsonPath('details.current_version', 1);
        $this->assertSame('عميل', $c->fresh()->name, 'لا كتابة عند التعارض');

        $this->flushHeaders();
        $ok = $this->withHeaders($h + ['If-Match' => $etag])->patchJson('/api/v1/clients/' . $c->id, ['name' => 'س'])->assertOk();
        $this->assertSame('"2"', $ok->headers->get('ETag'));

        // _version في الجسم يعمل أيضاً (نظير نموذج الويب) — بلا ترويسة If-Match باقيةٍ من الطلب السابق
        $this->flushHeaders();
        $this->withHeaders($h)->putJson('/api/v1/clients/' . $c->id, ['name' => 'ص', '_version' => 1])
            ->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');
        $this->withHeaders($h)->putJson('/api/v1/clients/' . $c->id, ['name' => 'ص', '_version' => 'abc'])->assertStatus(422);
    }

    public function test_idempotency_codes(): void
    {
        $this->seedCore();
        $h = $this->h() + ['Idempotency-Key' => 'k-1'];
        $this->withHeaders($h)->postJson('/api/v1/clients', ['name' => 'أول'])->assertCreated()->assertJsonStructure(['data', 'request_id']);
        $this->withHeaders($h)->postJson('/api/v1/clients', ['name' => 'ثانٍ'])
            ->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    }

    /* ── ٤) OpenAPI ── */

    public function test_openapi_is_generated_from_the_registry_and_scoped_to_the_token(): void
    {
        $this->seedCore();
        $spec = $this->withHeaders($this->h())->getJson('/api/v1/openapi.json')->assertOk()->json();
        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertSame(config('hub.version'), $spec['info']['version']);
        foreach (['/api/v1/me', '/api/v1/clients', '/api/v1/clients/{id}', '/api/v1/metrics'] as $p) {
            $this->assertArrayHasKey($p, $spec['paths'], $p);
        }
        $this->assertArrayHasKey('patch', $spec['paths']['/api/v1/clients/{id}']);
        $this->assertArrayHasKey('Clients', $spec['components']['schemas']);
        $this->assertArrayHasKey('Error', $spec['components']['schemas']);
        $this->assertSame(array_keys(\App\Support\Api::CODES), array_keys($spec['x-error-codes']));
        // المفتاحُ المقيَّد لا يُوثَّق له ما لا يستطيع
        $scoped = $this->withHeaders($this->h($this->owner, 'tasks:v'))->getJson('/api/v1/openapi.json')->assertOk()->json();
        $this->assertArrayNotHasKey('/api/v1/clients', $scoped['paths']);
        $this->assertArrayHasKey('/api/v1/tasks', $scoped['paths']);
        // الحقلُ المحجوب لا يظهر في مخطّط الدور
        $this->employee->role->forceFill(['field_rules' => ['clients' => ['email' => 'hide']]])->save();
        $this->employee->unsetRelation('role');
        $emp = $this->withHeaders($this->h($this->employee))->getJson('/api/v1/openapi.json')->assertOk()->json();
        $this->assertArrayNotHasKey('email', $emp['components']['schemas']['Clients']['properties']);
    }

    public function test_openapi_command_writes_a_full_valid_spec(): void
    {
        $out = sys_get_temp_dir() . '/lyn-openapi-' . uniqid() . '.json';
        $this->artisan('hub:openapi', ['--out' => $out])->assertExitCode(0);
        $spec = json_decode((string) file_get_contents($out), true);
        @unlink($out);
        $this->assertIsArray($spec);
        $this->assertGreaterThan(80, count($spec['paths']), 'كل الوحدات موثَّقة');
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);
    }

    /* ── ٥) تصعيد المصادقة في JSON ── */

    public function test_step_up_json_keeps_legacy_keys_and_adds_code(): void
    {
        $this->seedCore();
        Route::middleware('web')->get('/_contract_stepup', fn () => hub_require_stepup() ?? response()->json(['ok' => 1]));
        $res = $this->actingAs($this->owner)->withHeaders(['Accept' => 'application/json'])->get('/_contract_stepup');
        $res->assertStatus(428)->assertJsonPath('code', 'STEP_UP_REQUIRED')->assertJsonPath('stepup', true);
        $this->assertArrayHasKey('url', $res->json());
        $this->assertArrayHasKey('error', $res->json());
    }
}
