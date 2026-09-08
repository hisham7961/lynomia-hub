<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EndpointDevice;
use App\Models\EndpointRelease;
use App\Support\Es256;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\EnrollsEndpoints;
use Tests\TestCase;

/**
 * **التصحيح §5–§7 — سلسلةُ ثقةِ الإصدار وحالاتُه ورقعةُ الطرح الآمن.**
 *
 * تقسيةٌ إضافيّةٌ على سجل الإصدارات القائم (WP-L.2): حالاتُ دورةِ حياةٍ صادقة
 * (مسوَّدةٌ لا تُقدَّم، «withdrawn» = حذفٌ ناعم)، ومحورُ توثيقٍ منفصلٌ صادقٌ
 * (not-configured بلا notarytool)، ورقعةُ طرحٍ مرحليّ (canary حتميّةٌ وشركةٌ
 * بعينها) غيرُ مُلزَمةٍ افتراضاً، وبياناتٌ متوافقةٌ مع حارس الترقية في الوكيل.
 *
 * القواعدُ الصلبة: لا ادّعاءَ توثيقٍ (C15)، والطرحُ حتميٌّ لا قرعةً، والمسوَّدةُ
 * لا يبتلعها جهازٌ أبداً، والقائمُ قبل التصحيح يبقى يُقدَّم (توافقٌ رجعيّ).
 */
class EndpointReleaseTrustChainTest extends TestCase
{
    use EnrollsEndpoints;

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/agent-releases/*')) ?: [] as $f) @unlink($f);
        @rmdir(storage_path('app/agent-releases'));
        parent::tearDown();
    }

    protected function withStepup()
    {
        return $this->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);
    }

    protected function keypair(): array
    {
        $pk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($pk, $priv);
        $d = openssl_pkey_get_details($pk);

        return [$priv, $d['key']];
    }

    /** تسجيلُ جهازٍ حقيقيّ عبر المسار الكامل — يعيد [EndpointDevice, privatePem] */
    protected function device(string $os = 'windows', ?Company $c = null): array
    {
        $c ??= Company::create(['name_ar' => 'شركة ألف']);
        $this->actingAs($this->owner)->withStepup()
            ->post(route('enroll.mint'), ['assetId' => $this->eligibleAsset($c)->id])->assertSessionHas('enroll_token');
        $plain = (string) session('enroll_token');

        [$priv, $pub] = $this->keypair();
        $resp = $this->postJson('/api/v1/endpoint/enroll', [
            'token' => $plain, 'device_uuid' => (string) Str::uuid(),
            'hostname' => 'LT-TRUST', 'os' => $os, 'public_key' => $pub,
        ]);
        $resp->assertStatus(201);

        return [EndpointDevice::findOrFail((string) $resp->json('device_id')), $priv];
    }

    /** طلبٌ موقَّعٌ بعقد Es256 الحرفيّ */
    protected function signed(EndpointDevice $d, string $priv, string $method, string $uri, array $payload = [])
    {
        $body = $payload === [] ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE);
        $path = parse_url($uri, PHP_URL_PATH) ?: $uri;
        $ts = (string) time();
        $nonce = 'n-' . Str::random(24);
        openssl_sign(Es256::canonical($method, $path, $ts, $nonce, $body), $sig, $priv, OPENSSL_ALGO_SHA256);

        return $this->call($method, $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ENDPOINT_ID' => $d->id,
            'HTTP_X_ENDPOINT_TIMESTAMP' => $ts,
            'HTTP_X_ENDPOINT_NONCE' => $nonce,
            'HTTP_X_ENDPOINT_SIGNATURE' => base64_encode($sig),
        ], $body);
    }

    /** إصدارٌ مزروعٌ مباشرةً — ملفٌ حقيقيّ تحت storage/app */
    protected function seedRelease(array $over = []): array
    {
        $bytes = 'BIN-' . Str::random(40);
        Storage::disk('local')->put($p = 'agent-releases/seed-' . Str::random(10) . '.bin', $bytes);

        $rel = EndpointRelease::create(array_merge([
            'version' => '1.0.0', 'os' => 'windows', 'arch' => 'amd64',
            'path' => $p, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes),
            'published_by' => $this->owner->id,
        ], $over));

        return [$rel, $bytes];
    }

    protected function binary(string $marker): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('lynomia-agent.exe',
            "MZ\x90\x00lynomia-agent-" . $marker . '-' . Str::random(32));
    }

    protected function manifest(EndpointDevice $d, string $priv)
    {
        return $this->signed($d, $priv, 'GET', '/api/v1/endpoint/agent/manifest');
    }

    /* ───────── ① المسوَّدةُ لا يقدّمها البيان؛ ونشرُها يجعله يقدّمها (§6) ───────── */

    public function test_a_draft_release_is_never_served_by_the_manifest_until_published(): void
    {
        $this->seedCore();
        [$d, $priv] = $this->device('windows');

        // منشورٌ أقدمُ + مسوَّدةٌ أحدث: البيانُ يقدّم المنشورَ لا المسوَّدة
        [$pub] = $this->seedRelease(['version' => '1.0.0', 'created_at' => now()->subHours(2)]);
        [$draft] = $this->seedRelease(['version' => '1.1.0', 'state' => 'draft', 'created_at' => now()->subHour()]);

        $this->manifest($d, $priv)->assertOk()->assertJsonPath('version', '1.0.0');

        // والجهازُ لا ينزّل المسوَّدةَ ولو خمّن معرّفها: ٤٠٤ (دفاعُ عمقٍ فوق البيان)
        $this->signed($d, $priv, 'GET', '/api/v1/endpoint/agent/download/' . $draft->id)->assertNotFound();

        // نشرُ المسوَّدة (مالكٌ + تصعيد) ⇒ البيانُ يقدّمها الآن (الأحدث)
        $this->actingAs($this->owner)->withStepup()
            ->post(route('endpoints.releases.publish', $draft->id))->assertRedirect();
        $this->assertSame('published', $draft->fresh()->state);
        $this->manifest($d, $priv)->assertOk()->assertJsonPath('version', '1.1.0');

        $this->assertNotSame($pub->id, $draft->id);
    }

    /* ───────── ② «withdrawn» = حذفٌ ناعمٌ — لا بيانَ لمسحوب ───────── */

    public function test_a_withdrawn_release_is_soft_deleted_and_falls_back_to_the_previous_stable(): void
    {
        $this->seedCore();
        [$d, $priv] = $this->device('windows');

        [$stable] = $this->seedRelease(['version' => '1.0.0', 'created_at' => now()->subHours(2)]);
        [$newer] = $this->seedRelease(['version' => '1.1.0', 'created_at' => now()->subHour()]);

        $this->manifest($d, $priv)->assertOk()->assertJsonPath('version', '1.1.0');

        // سحبُ الأحدث (حذفٌ ناعم) ⇒ البيانُ يعود للمستقرّ السابق لا لعدمٍ
        $this->actingAs($this->owner)->withStepup()
            ->post(route('endpoints.releases.delete', $newer->id))->assertRedirect();
        $this->assertSame('withdrawn', $newer->fresh()->lifecycleState());
        $this->manifest($d, $priv)->assertOk()->assertJsonPath('version', '1.0.0');

        $this->assertNotSame($stable->id, $newer->id);
    }

    /* ───────── ③ canary مرحليّ حتميّ — والباقي على المستقرّ السابق (§7) ───────── */

    public function test_percentage_canary_targets_deterministically_and_others_stay_on_stable(): void
    {
        $this->seedCore();
        [$d, $priv] = $this->device('windows');

        [$stable] = $this->seedRelease(['version' => '1.0.0', 'created_at' => now()->subHours(2)]);

        // canary أحدثُ بنسبة 100% ⇒ الجهازُ يتلقّاه (حلقةٌ كاملة)
        [$canary] = $this->seedRelease(['version' => '1.1.0', 'rollout_scope' => 'percentage',
            'rollout_percentage' => 100, 'created_at' => now()->subHour()]);
        $this->manifest($d, $priv)->assertOk()->assertJsonPath('version', '1.1.0');

        // النسبةُ 0% ⇒ لا جهازَ في الحلقة، فيعود الجهازُ للمستقرّ السابق (لا عدمٍ)
        $canary->forceFill(['rollout_percentage' => 0])->save();
        $this->manifest($d, $priv)->assertOk()->assertJsonPath('version', '1.0.0');

        // **حتميّةٌ لا قرعة:** نفسُ الجهاز يعطي نفسَ النتيجة عبر الطلبات
        $this->manifest($d, $priv)->assertOk()->assertJsonPath('version', '1.0.0');

        // والحلقةُ ذاتُها للجهاز نفسِه: bucket ثابتٌ مهما تكرّر الحساب
        $this->assertSame($canary->fresh()->targetsDevice($d->fresh()),
            $canary->fresh()->targetsDevice($d->fresh()), 'الاستهدافُ غيرُ حتميّ — قرعة');

        $this->assertNotSame($stable->id, $canary->id);
    }

    /* ───────── ④ نطاقُ شركةٍ بعينها — جهازُها وحدَه يتلقّى (§7) ───────── */

    public function test_company_scoped_rollout_targets_only_that_companys_device(): void
    {
        $this->seedCore();
        $companyA = Company::create(['name_ar' => 'شركة أ']);
        $companyB = Company::create(['name_ar' => 'شركة ب']);
        [$da, $privA] = $this->device('windows', $companyA);
        [$db, $privB] = $this->device('windows', $companyB);

        [$stable] = $this->seedRelease(['version' => '1.0.0', 'created_at' => now()->subHours(2)]);
        [$targeted] = $this->seedRelease(['version' => '1.1.0', 'rollout_scope' => 'company',
            'rollout_company_id' => $companyA->id, 'created_at' => now()->subHour()]);

        // جهازُ شركةِ الطرح يتلقّى الأحدث؛ وجهازُ سواها يبقى على المستقرّ
        $this->manifest($da, $privA)->assertOk()->assertJsonPath('version', '1.1.0');
        $this->manifest($db, $privB)->assertOk()->assertJsonPath('version', '1.0.0');

        $this->assertNotSame($stable->id, $targeted->id);
    }

    /* ───────── ⑤ البيانُ يحمل سلسلةَ الثقة الكاملة (§5) ───────── */

    public function test_manifest_carries_the_full_trust_chain_metadata(): void
    {
        $this->seedCore();
        [$d, $priv] = $this->device('windows');

        $this->seedRelease(['version' => '1.0.0', 'build_number' => 'ci-4242',
            'min_agent_version' => '0.2.0', 'min_server_version' => '2.400.0']);

        $this->manifest($d, $priv)->assertOk()
            ->assertJsonPath('version', '1.0.0')
            ->assertJsonPath('signing_status', 'unsigned-dev')      // الصدقُ يسافر (C15)
            ->assertJsonPath('notarization_status', 'not-configured') // محورٌ منفصلٌ صادق
            ->assertJsonPath('build_number', 'ci-4242')
            ->assertJsonPath('min_agent_version', '0.2.0')          // جسرُ الترقية للوكيل
            ->assertJsonPath('min_server_version', '2.400.0');
    }

    /* ───────── ⑥ الصدق (C15): 'notarized' لا تُدَّعى ───────── */

    public function test_notarization_status_can_never_claim_notarized_without_a_verified_attestation(): void
    {
        $this->seedCore();

        // 'notarized' بلا إقرارٍ: يُرمى
        try {
            $this->seedRelease(['version' => '2.0.0', 'os' => 'macos', 'arch' => 'arm64',
                'signing_status' => 'unsigned-dev', 'notarization_status' => 'notarized']);
            $this->fail("'notarized' كُتبت بلا إقرارِ توثيقٍ متحقَّق");
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(0, EndpointRelease::count());
        }

        // قيمةٌ خارج القائمة: تُرمى (allowlist تطبيقيّ لا DB enum — C10)
        try {
            $this->seedRelease(['version' => '2.0.1', 'notarization_status' => 'apple-notarytool-v2']);
            $this->fail('حالةُ توثيقٍ خارج القائمة قُبلت');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(0, EndpointRelease::count());
        }

        // 'notarized' تستلزم 'signed' أولاً — توثيقُ غيرِ الموقَّع مرفوض
        $bytes = 'N-' . Str::random(20);
        Storage::disk('local')->put($p = 'agent-releases/nz-1.bin', $bytes);
        try {
            $r = new EndpointRelease(['version' => '2.0.2', 'os' => 'macos', 'arch' => 'arm64',
                'path' => $p, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes),
                'notarization_status' => 'notarized', 'published_by' => $this->owner->id]);
            $r->attestVerifiedNotarization()->save();   // بلا إقرارِ توقيعٍ ⇒ يُرمى
            $this->fail("'notarized' مرّت بلا 'signed'");
        } catch (\InvalidArgumentException $e) {
        }

        // توثيقُ ويندوز مرفوضٌ (notarytool أداةُ آبل حصراً) — ولو أُقِرّ التوقيعُ والتوثيق
        try {
            $r = new EndpointRelease(['version' => '2.0.3', 'os' => 'windows', 'arch' => 'amd64',
                'path' => $p, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes),
                'signing_status' => 'signed', 'notarization_status' => 'notarized', 'published_by' => $this->owner->id]);
            $r->attestVerifiedSignature()->attestVerifiedNotarization()->save();
            $this->fail('توثيقُ ويندوز قُبل');
        } catch (\InvalidArgumentException $e) {
        }

        // البابُ الشرعيّ الوحيد: ماك + موقَّعٌ متحقَّقٌ + إقرارُ توثيقٍ صريح
        $r = new EndpointRelease(['version' => '2.1.0', 'os' => 'macos', 'arch' => 'arm64',
            'path' => $p, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes),
            'signing_status' => 'signed', 'notarization_status' => 'notarized', 'published_by' => $this->owner->id]);
        $r->attestVerifiedSignature()->attestVerifiedNotarization()->save();
        $this->assertSame('notarized', $r->fresh()->notarization_status);
        $this->assertSame('signed', $r->fresh()->signing_status);
        EndpointRelease::whereKey($r->id)->forceDelete();
    }

    /* ───────── ⑦ الرفع يقبل سلسلةَ الثقة والطرح، والتوثيقُ لا يُقرأ من النموذج ───────── */

    public function test_upload_accepts_trust_chain_and_rollout_and_never_reads_notarization_from_the_form(): void
    {
        $this->seedCore();

        // رفعٌ مكتملٌ: وسمُ بناءٍ وأدنى نسخةٍ ومسوَّدةٌ بنسبةِ canary — **والنموذجُ يكذب**
        // بحالةِ توثيقٍ 'notarized' تُهمَل بالكامل (صادقٌ بالبناء)
        $this->actingAs($this->owner)->withStepup()
            ->post(route('endpoints.releases.store'), [
                'version' => '3.0.0', 'os' => 'windows', 'arch' => 'amd64', 'file' => $this->binary('tc'),
                'build_number' => 'ci-9001', 'min_agent_version' => '0.2.0',
                'state' => 'draft', 'rollout_scope' => 'percentage', 'rollout_percentage' => 25,
                'notarization_status' => 'notarized',   // ادّعاءٌ — لا يُقرأ أبداً
            ])->assertRedirect();

        $rel = EndpointRelease::firstOrFail();
        $this->assertSame('ci-9001', $rel->build_number);
        $this->assertSame('0.2.0', $rel->min_agent_version);
        $this->assertSame('draft', $rel->state);
        $this->assertSame('percentage', $rel->rollout_scope);
        $this->assertSame(25, (int) $rel->rollout_percentage);
        $this->assertSame('not-configured', $rel->notarization_status, 'نموذجُ ويب ادّعى توثيقاً فصُدِّق (C15)');
        $this->assertSame('unsigned-dev', $rel->signing_status);

        // نطاقُ 'company' بلا شركةٍ مستهدفة: يُرفَض (رسالةُ تحقّق)
        $this->actingAs($this->owner)->withStepup()
            ->post(route('endpoints.releases.store'), [
                'version' => '3.1.0', 'os' => 'windows', 'arch' => 'amd64', 'file' => $this->binary('tc2'),
                'rollout_scope' => 'company',
            ])->assertSessionHasErrors('rollout_company_id');
        $this->assertSame(1, EndpointRelease::count(), 'طرحُ شركةٍ بلا شركةٍ نُشر');
    }
}
