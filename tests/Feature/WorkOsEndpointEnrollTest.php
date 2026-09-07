<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EndpointDevice;
use App\Models\EnrollmentToken;
use App\Models\Role;
use App\Models\User;
use App\Support\Es256;
use App\Support\HubEvents;
use App\Support\Webauthn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **سجلُّ الأجهزة + التسجيلُ اللاتماثليّ** (Work OS · الطور J · WP-J.1 · §43).
 *
 * يمتدّ هذا الملفُّ ثلاثَ سوابقَ معلنة:
 *  • `WebauthnCoreTest` — «مُصادِقٌ افتراضيّ» حقيقيّ: زوجُ P-256 يُولَّد بـopenssl
 *    ويُوقَّع به كما يفعل الجهاز — فيُثبَت أن بدائيّةَ ES256 المستخرَجة (`Es256`)
 *    تقبل الصحيحَ وترفض المُلاعَب، وأن Webauthn بعد التفويض يعمل كما كان (C5).
 *  • `InboundHookRound5Test` — انضباطُ replay نفسُه: طابعُ وقتٍ ±300ث + nonce
 *    فريدٌ لكل جهاز — الإعادةُ 409 والقديمُ 401 والمزوَّر 401.
 *  • `ClientOperationsTest`/`WorkOsIpDefenseTest` — دلالةُ ٤٠٤ للعميل على كل
 *    سطحٍ داخليّ، وعزلُ الشركات الصارم (عبرَ شركةٍ = ٤٠٤ لا تسريب).
 *
 * القواعدُ الصلبة (المواصفة §43 — غيرُ قابلةٍ للتفاوض):
 *  ١) الخادمُ يخزّن **العامَّ فقط** — الخاصُّ لا يُرسَل ولا يُخزَّن ولا يُدوَّن قط.
 *  ٢) رمزُ التسجيل sha256 (لا نصَّ صريحاً في القاعدة)، لمرّةٍ واحدة (الثانيةُ 409)،
 *     قصيرُ المهلة (endpoint.enroll_ttl_min)، مُسنَدٌ لشركةٍ/موظفٍ لحظةَ السكّ.
 *  ٣) السكُّ step-up + مالك/مراقب؛ والعميلُ ٤٠٤ على كل سطح نقاط.
 *  ٤) عقدُ التوقيع (docblock `Es256`): توقيتٌ قديم/nonce معاد/توقيعٌ مزوَّر —
 *     كلُّها تُرَدّ قبل أيّ منطقِ معالج.
 */
class WorkOsEndpointEnrollTest extends TestCase
{
    /** جلسةُ تصعيدٍ سارية — نمطُ WorkOsInventoryTest حرفياً */
    protected function withStepup()
    {
        return $this->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);
    }

    /** زوجُ مفاتيح P-256 حقيقيّ — [privatePem, publicPem] (نمطُ WebauthnCoreTest::keypair) */
    protected function keypair(): array
    {
        $pk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($pk, $priv);
        $d = openssl_pkey_get_details($pk);

        return [$priv, $d['key']];
    }

    protected function company(string $name = 'شركة ألف'): Company
    {
        return Company::create(['name_ar' => $name]);
    }

    /** سكُّ رمزٍ عبر المسار الحقيقيّ (مالك + step-up) — يُعيد النصَّ الصريح المعروضَ مرةً */
    protected function mintFor(Company $c, ?string $employeeId = null): string
    {
        $resp = $this->actingAs($this->owner)->withStepup()
            ->post(route('enroll.mint'), array_filter(['companyId' => $c->id, 'employeeId' => $employeeId]));
        $resp->assertSessionHas('enroll_token');

        return (string) session('enroll_token');
    }

    /** حمولةُ تسجيلٍ صالحة — تُعيد [response, privatePem, publicPem] */
    protected function enroll(string $token, array $over = []): array
    {
        [$priv, $pub] = $this->keypair();
        $resp = $this->postJson('/api/v1/endpoint/enroll', array_merge([
            'token'         => $token,
            'device_uuid'   => (string) Str::uuid(),
            'hostname'      => 'LT-TEST-001',
            'os'            => 'windows',
            'agent_version' => '1.0.0',
            'public_key'    => $pub,
        ], $over));

        return [$resp, $priv, $pub];
    }

    /** حسابُ عميلٍ صلب — نمطُ WorkOsIpDefenseTest::clientUser حرفياً */
    protected function clientUser(): User
    {
        $modules = array_keys(config('hub.modules'));
        $full = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
        $role = Role::create(['name' => 'دور عميل ' . Str::random(5), 'scope' => 'all',
            'flags' => [], 'matrix' => $full]);

        return User::create(['name' => 'حسابُ عميل', 'email' => Str::random(8) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
    }

    /* ────────── ① البدائيّةُ المستخرَجة Es256 — عقدُ التوقيع نفسُه (C5) ────────── */

    public function test_the_extracted_es256_primitive_verifies_and_rejects(): void
    {
        [$priv, $pub] = $this->keypair();

        // العقدُ حرفياً: METHOD\nPATH\nTIMESTAMP\nNONCE\nsha256hex(BODY)
        $canon = Es256::canonical('POST', '/api/v1/endpoint/heartbeat', '1700000000', 'n-0001', '{"x":1}');
        $this->assertSame(
            "POST\n/api/v1/endpoint/heartbeat\n1700000000\nn-0001\n" . hash('sha256', '{"x":1}'),
            $canon, 'السلسلةُ القانونية انحرفت عن العقد المعلن — وكيلُ الطور K يبني عليها حرفياً');

        openssl_sign($canon, $sig, $priv, OPENSSL_ALGO_SHA256);
        $this->assertTrue(Es256::verify($canon, $sig, $pub), 'توقيعٌ صحيحٌ رُفض');
        $this->assertFalse(Es256::verify($canon . 'x', $sig, $pub), 'بياناتٌ مُلاعَبة قُبلت');

        $sig2 = $sig;
        $sig2[8] = $sig2[8] === 'A' ? 'B' : 'A';
        $this->assertFalse(Es256::verify($canon, $sig2, $pub), 'توقيعٌ مُلاعَب قُبل');

        // مفتاحٌ غيرُ صالح يرمي صراحةً (سلوكُ Webauthn المحفوظ) لا يمرّ صامتاً
        $this->expectException(\RuntimeException::class);
        Es256::verify($canon, $sig, 'garbage-not-a-pem');
    }

    public function test_webauthn_delegates_to_the_same_primitive_unchanged(): void
    {
        // COSE→PEM: المسارُ الواحد — Webauthn يفوّض ولا يزدوج (C5)
        $pk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $d = openssl_pkey_get_details($pk);
        $x = str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $cose = [1 => 2, 3 => -7, -1 => 1, -2 => $x, -3 => $y];

        $this->assertSame(Es256::coseToPem($cose), Webauthn::coseEs256ToPem($cose),
            'مساران للتحويل — العقدُ بدائيّةٌ واحدة لا اثنتان');
        $this->assertTrue(Es256::isP256PublicKey($d['key']), 'مفتاحُ P-256 عامٌّ سليم رُفض');
        $this->assertFalse(Es256::isP256PublicKey('not a key'), 'نصٌّ مهمل قُبل مفتاحاً');
    }

    /* ────────── ② السكّ: مالك/مراقب + step-up + عميل ٤٠٤ ────────── */

    public function test_minting_requires_stepup_and_owner_or_monitor(): void
    {
        $this->seedCore();
        $c = $this->company();

        // موظفٌ بلا رايةِ مراقب: ممنوع
        $this->actingAs($this->employee)->withStepup()
            ->post(route('enroll.mint'), ['companyId' => $c->id])->assertForbidden();

        // مالكٌ بلا step-up: يُحال للتأكيد ولا يُسَكّ شيء (الجلسةُ تُفرَغ من ختم
        // التصعيد الذي زرعه الطلبُ السابق — فالاختبارُ يختبر الغيابَ حقاً)
        $this->flushSession();
        $this->actingAs($this->owner)->post(route('enroll.mint'), ['companyId' => $c->id])
            ->assertRedirect();
        $this->assertSame(0, EnrollmentToken::count(), 'رمزٌ سُكّ دون تصعيد هوية');

        // حسابُ عميل: ٤٠٤ فوق كل شيء (PortalGuard قائمةٌ بيضاء)
        $this->actingAs($this->clientUser())->withStepup()
            ->post(route('enroll.mint'), ['companyId' => $c->id])->assertNotFound();

        // مالكٌ مُصعَّد: يُسَكّ، ويُخزَّن sha256 لا النصُّ الصريح، وبمهلةٍ من الإعداد
        $this->hubSetting('endpoint.enroll_ttl_min', '30');
        $plain = $this->mintFor($c);
        $t = EnrollmentToken::firstOrFail();
        $this->assertSame(hash('sha256', $plain), $t->token_hash, 'الرمزُ لم يُخزَّن sha256');
        $this->assertNull($t->consumed_at);
        $this->assertSame($c->id, (string) $t->company_id, 'الرمزُ لم يُسنَد لشركته لحظةَ السكّ');
        $this->assertTrue($t->expires_at->between(now()->addMinutes(28), now()->addMinutes(32)),
            'المهلةُ لا تُقرأ من endpoint.enroll_ttl_min');
        $this->assertStringNotContainsString($plain, json_encode(DB::table('audits')->get()),
            'النصُّ الصريح للرمز تسرّب إلى سجل التدقيق');
        $this->assertGreaterThan(0, DB::table('audits')->where('module', 'endpoints')->count(),
            'السكُّ بلا قيدِ تدقيق');
    }

    /* ────────── ③ رمزٌ لمرّةٍ واحدة — الثانيةُ 409 ────────── */

    public function test_a_minted_token_enrolls_once_and_the_second_use_conflicts(): void
    {
        $this->seedCore();
        $c = $this->company();
        $plain = $this->mintFor($c);

        $fired = [];
        HubEvents::listen(function ($e) use (&$fired) { $fired[] = $e; });
        try {
            [$resp] = $this->enroll($plain);
            $resp->assertStatus(201);

            $d = EndpointDevice::firstOrFail();
            $this->assertSame($c->id, (string) $d->company_id, 'الشركةُ لا تُسنَد من الرمز');
            $this->assertSame('active', $d->status);
            $this->assertStringContainsString('BEGIN PUBLIC KEY', (string) $d->public_key);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $d->pubkey_fp,
                'بصمةُ المفتاح ليست sha256 hex');
            $this->assertNotNull(EnrollmentToken::first()->consumed_at, 'الرمزُ لم يُستهلَك');
            $this->assertContains('endpoint.enrolled', $fired, 'حدثُ endpoint.enrolled لم يُبَثّ');
        } finally {
            HubEvents::forgetListeners();
        }

        // الاستعمالُ الثاني — ولو بجهازٍ مختلف — 409 ولا جهازَ ثانيَ يُنشأ
        [$resp2] = $this->enroll($plain);
        $resp2->assertStatus(409);
        $this->assertSame(1, EndpointDevice::count(), 'رمزٌ واحد أنشأ جهازين');
    }

    /* ────────── ④ الخادمُ يخزّن العامَّ فقط — الخاصُّ يُرَدّ ولا يُخزَّن قط ────────── */

    public function test_the_server_stores_only_the_public_key(): void
    {
        $this->seedCore();
        $c = $this->company();

        // (أ) حمولةٌ تحمل مفتاحاً خاصاً بأيّ حقلٍ كان: تُرفض 422 ولا يُخزَّن شيء
        [$priv] = $this->keypair();
        $plain = $this->mintFor($c);
        [$resp] = $this->enroll($plain, ['private_key' => $priv]);
        $resp->assertStatus(422);
        $this->assertSame(0, EndpointDevice::count(), 'جهازٌ خُلق من حمولةٍ تحمل مفتاحاً خاصاً');

        // (ب) مفتاحٌ خاصٌّ مكانَ العامّ: يُرفض 422
        [$resp2] = $this->enroll($plain, ['public_key' => $priv]);
        $resp2->assertStatus(422);
        $this->assertSame(0, EndpointDevice::count());

        // (ج) تسجيلٌ سليم: الصفُّ كلُّه بلا أيّ أثرِ مادةٍ خاصة — والتدقيقُ كذلك
        [$resp3, , $pub] = $this->enroll($plain);
        $resp3->assertStatus(201);
        $row = (array) DB::table('endpoint_devices')->first();
        $this->assertStringNotContainsString('PRIVATE', json_encode($row),
            'مادةُ مفتاحٍ خاص في صفّ الجهاز');
        $this->assertSame(Es256::fingerprint($pub), $row['pubkey_fp'],
            'البصمةُ لا تُشتقّ من المفتاح المخزَّن نفسِه');
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode(DB::table('audits')->get()),
            'مادةُ مفتاحٍ خاص تسرّبت إلى التدقيق');
    }

    /* ────────── ⑤ المهلةُ تُفرَض — المنتهي يُرَدّ ────────── */

    public function test_an_expired_token_is_rejected(): void
    {
        $this->seedCore();
        $c = $this->company();
        $plain = $this->mintFor($c);
        EnrollmentToken::query()->update(['expires_at' => now()->subMinute()]);

        [$resp] = $this->enroll($plain);
        $resp->assertStatus(401);
        $this->assertSame(0, EndpointDevice::count(), 'رمزٌ منتهٍ سجّل جهازاً');

        // ورمزٌ مجهول: الردُّ نفسُه — لا تمييزَ بين مجهولٍ ومنتهٍ للطارق
        [$resp2] = $this->enroll('enr_' . Str::random(40));
        $resp2->assertStatus(401);
    }

    /* ────────── ⑥ عبرَ شركةٍ: ٤٠٤ — سكّاً وقراءةً ────────── */

    public function test_cross_company_isolation_on_mint_and_read(): void
    {
        $this->seedCore();
        $a = $this->company('شركة ألف');
        $b = $this->company('شركة باء');

        // مراقبٌ معزولٌ على شركة باء لا يسكّ رمزاً لشركة ألف — ٤٠٤ لا تسريبَ وجود
        $monRole = Role::create(['name' => 'مراقب باء', 'scope' => 'all',
            'flags' => ['monitor' => 1],
            'matrix' => collect(array_keys(config('hub.modules')))
                ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all()]);
        $bUser = User::create(['name' => 'مراقبُ باء', 'email' => 'mon-b@test.local',
            'password' => 'Secret!2026x', 'role_id' => $monRole->id, 'status' => 'نشط',
            'companies' => [$b->id], 'password_changed_at' => now()]);

        $this->actingAs($bUser)->withStepup()
            ->post(route('enroll.mint'), ['companyId' => $a->id])->assertNotFound();
        $this->assertSame(0, EnrollmentToken::count());

        // ولشركته: يمرّ
        $this->actingAs($bUser)->withStepup()
            ->post(route('enroll.mint'), ['companyId' => $b->id])->assertSessionHas('enroll_token');

        // جهازُ ألف لا يُقرأ من معزولِ باء (findScoped→404) — والمالكُ يراه
        $plainA = $this->mintFor($a);
        [$respA] = $this->enroll($plainA);
        $respA->assertStatus(201);
        $device = EndpointDevice::where('company_id', $a->id)->firstOrFail();

        $this->actingAs($bUser)->get('/m/endpoints/' . $device->id)->assertNotFound();
        $this->actingAs($this->owner)->get('/m/endpoints/' . $device->id)->assertOk();
    }

    /* ────────── ⑦ مفتاحٌ مهملٌ أو غيرُ P-256: 422 ────────── */

    public function test_garbage_or_non_p256_public_keys_are_rejected(): void
    {
        $this->seedCore();
        $c = $this->company();

        // نصٌّ مهمل
        [$r1] = $this->enroll($this->mintFor($c), ['public_key' => 'garbage-not-a-pem']);
        $r1->assertStatus(422);

        // منحنى P-384 — ليس عقدَنا
        $pk384 = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);
        $pub384 = openssl_pkey_get_details($pk384)['key'];
        [$r2] = $this->enroll($this->mintFor($c), ['public_key' => $pub384]);
        $r2->assertStatus(422);

        // RSA — خوارزميةٌ أخرى كلياً
        $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $pubRsa = openssl_pkey_get_details($rsa)['key'];
        [$r3] = $this->enroll($this->mintFor($c), ['public_key' => $pubRsa]);
        $r3->assertStatus(422);

        $this->assertSame(0, EndpointDevice::count(), 'مفتاحٌ خارج العقد سجّل جهازاً');
    }

    /* ────────── ⑧ العميلُ ٤٠٤ على كل سطح نقاط ────────── */

    public function test_a_client_account_never_reaches_endpoint_surfaces(): void
    {
        $this->seedCore();
        $client = $this->clientUser();

        $this->actingAs($client)->get('/m/endpoints')->assertNotFound();
        $this->actingAs($client)->withStepup()
            ->post(route('enroll.mint'), ['companyId' => (string) Str::uuid()])->assertNotFound();
    }

    /* ────────── ⑨ وسيطُ التوقيع: العقدُ يُفرَض قبل أيّ منطق ────────── */

    /** طلبٌ موقَّعٌ بالعقد الحرفيّ نحو مسارِ فحصٍ خلف الوسيط */
    protected function signedProbe(string $deviceId, string $priv, string $body, array $over = [])
    {
        $ts = (string) ($over['ts'] ?? time());
        $nonce = (string) ($over['nonce'] ?? 'n-' . Str::random(20));
        $canon = Es256::canonical('POST', '/probe-endpoint', $ts, $nonce, $body);
        openssl_sign($canon, $sig, $priv, OPENSSL_ALGO_SHA256);

        return $this->call('POST', '/probe-endpoint', [], [], [], [
            'CONTENT_TYPE'              => 'application/json',
            'HTTP_X_ENDPOINT_ID'        => $deviceId,
            'HTTP_X_ENDPOINT_TIMESTAMP' => $ts,
            'HTTP_X_ENDPOINT_NONCE'     => $nonce,
            'HTTP_X_ENDPOINT_SIGNATURE' => $over['sig'] ?? base64_encode($sig),
        ], $body);
    }

    public function test_the_signature_middleware_enforces_the_contract(): void
    {
        $this->seedCore();
        $c = $this->company();
        $plain = $this->mintFor($c);
        [$resp, $priv] = $this->enroll($plain);
        $resp->assertStatus(201);
        $device = EndpointDevice::firstOrFail();

        Route::post('/probe-endpoint', fn () => response()->json(['ok' => true]))
            ->middleware(\App\Http\Middleware\EndpointSignature::class);

        $body = '{"probe":1}';

        // الصحيحُ يمرّ
        $this->signedProbe($device->id, $priv, $body, ['nonce' => 'n-valid-1'])
            ->assertOk()->assertJson(['ok' => true]);

        // الإعادةُ بحذافيرها (nonce معاد): 409 — قبل أيّ منطقِ معالج
        $this->signedProbe($device->id, $priv, $body, ['nonce' => 'n-valid-1'])->assertStatus(409);

        // طابعٌ قديم (> ±300ث): 401
        $this->signedProbe($device->id, $priv, $body, ['ts' => time() - 400])->assertStatus(401);

        // توقيعٌ بمفتاحٍ آخر (مزوَّر): 401
        [$otherPriv] = $this->keypair();
        $this->signedProbe($device->id, $otherPriv, $body)->assertStatus(401);

        // جهازٌ مجهول: 401 لا تسريبَ وجود
        $this->signedProbe((string) Str::uuid(), $priv, $body)->assertStatus(401);

        // جهازٌ موقوف: يُرَدّ ولو صحَّ توقيعُه
        $device->forceFill(['status' => 'suspended'])->saveQuietly();
        $this->signedProbe($device->id, $priv, $body)->assertStatus(403);
    }
}
