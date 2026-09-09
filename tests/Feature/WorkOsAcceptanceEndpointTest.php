<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EndpointCommand;
use App\Models\EndpointDevice;
use App\Models\EndpointEvent;
use App\Models\EndpointPolicy;
use App\Models\EndpointRelease;
use App\Models\EnrollmentToken;
use App\Support\Es256;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **سيناريو القبول §100 — رحلةُ النقطة الطرفية من السكّ إلى التحديث الموقَّع**
 * (Work OS · الطور M · WP-M.4).
 *
 * يمتدّ سوابقَه الموسومة ولا يستنسخها (`WorkOsEndpointEnrollTest` السكُّ والتسجيل،
 * `WorkOsEndpointProtocolTest` البروتوكولُ الموقَّع والخصوصيّة والأوامر،
 * `WorkOsEndpointCentreTest` الوضعيّةُ الصادقة، `WorkOsAgentReleasesTest` البيانُ
 * والتنزيل) — وهذا الملفُّ يثبت **الرحلةَ الواحدة** لجهازٍ واحدٍ بمفتاحٍ واحدٍ
 * عبر أسطح HTTP الحقيقية:
 *
 *   سكٌّ (تصعيدُ هويةٍ إجباريّ) ← تسجيلٌ برمزٍ يعمل مرّةً (الثانيةُ 409) ← نبضةٌ
 *   موقَّعةٌ بوضعيّةٍ صادقة (denied-by-os → «غير مُهيّأ» لا «فعّالة») ← حدثُ USB
 *   بلا محتوى (والتجسّسيُّ 422 لا يُخزَّن حرفاً) ← سياسةٌ رصديّةٌ تصارح «يتطلب
 *   MDM» ← دورةُ أمرٍ كاملة (isolate: تصعيدٌ+سبب ← claim ← نتيجةٌ موقَّعة ← done
 *   والثانيةُ 409) ← بيانُ تحديثٍ ببصمةِ sha256 حقيقية وتنزيلٌ موقَّعٌ تُطابق
 *   بايتاتُه بصمةَ البيان حرفياً.
 */
class WorkOsAcceptanceEndpointTest extends TestCase
{
    use \Tests\Concerns\EnrollsEndpoints;

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/agent-releases/*')) ?: [] as $f) @unlink($f);
        @rmdir(storage_path('app/agent-releases'));
        parent::tearDown();
    }

    private function withStepup()
    {
        return $this->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);
    }

    /** زوجُ P-256 حقيقيّ — نمطُ WorkOsEndpointEnrollTest::keypair حرفياً */
    private function keypair(): array
    {
        $pk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($pk, $priv);
        $d = openssl_pkey_get_details($pk);

        return [$priv, $d['key']];
    }

    /** طلبٌ موقَّعٌ بعقد Es256 الحرفيّ — GET أو POST (نمطُ WorkOsAgentReleasesTest::signed) */
    private function signed(EndpointDevice $d, string $priv, string $method, string $uri, array $payload = [])
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

    public function test_the_full_endpoint_journey_from_mint_to_verified_update(): void
    {
        $this->seedCore();
        $company = Company::create(['name_ar' => 'شركةُ القبول']);

        /* ── (١) السكّ: بلا تصعيدٍ يُحال ولا يُسكّ — وبالتصعيد يُسكّ sha256 ── */

        $asset = $this->eligibleAsset($company);   // §2 — أصلٌ مملوكٌ للشركة مؤهّل

        $this->actingAs($this->owner)->post(route('enroll.mint'), ['assetId' => $asset->id])
            ->assertRedirect();
        $this->assertSame(0, EnrollmentToken::count(), 'رمزٌ سُكّ دون تصعيد هوية');

        $this->actingAs($this->owner)->withStepup()
            ->post(route('enroll.mint'), ['assetId' => $asset->id])
            ->assertSessionHas('enroll_token');
        $plain = (string) session('enroll_token');
        $this->assertSame(hash('sha256', $plain), EnrollmentToken::firstOrFail()->token_hash,
            'الرمزُ لم يُخزَّن sha256');

        /* ── (٢) التسجيل: زوجُ مفاتيحَ يولّده الجهاز، والرمزُ يعمل مرّةً واحدة ── */

        [$priv, $pub] = $this->keypair();
        $enroll = fn (string $token) => $this->postJson('/api/v1/endpoint/enroll', [
            'token' => $token, 'device_uuid' => (string) Str::uuid(),
            'hostname' => 'LT-ACCEPT-100', 'os' => 'windows',
            'agent_version' => '1.0.0', 'public_key' => $pub,
        ]);

        $resp = $enroll($plain);
        $resp->assertStatus(201);
        $device = EndpointDevice::findOrFail((string) $resp->json('device_id'));
        $this->assertSame($company->id, (string) $device->company_id, 'الجهازُ لم يُسنَد لشركة رمزه');
        $this->assertStringNotContainsString('PRIVATE',
            json_encode((array) DB::table('endpoint_devices')->first()),
            'مادةُ مفتاحٍ خاصّ بلغت صفَّ الجهاز');

        $enroll($plain)->assertStatus(409);   // الرمزُ لمرّةٍ واحدة
        $this->assertSame(1, EndpointDevice::count(), 'رمزٌ واحد سجّل جهازين');

        /* ── (٣) نبضةٌ موقَّعة بوضعيّةٍ صادقة: المرفوضُ «غير مُهيّأ» أبداً لا «فعّالة» ── */

        $this->signed($device, $priv, 'POST', '/api/v1/endpoint/heartbeat', [
            'agent_version' => '1.0.0',
            'posture' => ['defender' => 'active', 'firewall' => 'denied-by-os'],
        ])->assertOk();
        $posture = $device->fresh()->posture;
        $this->assertSame('active', $posture['defender']);
        $this->assertSame('not-configured', $posture['firewall'],
            'قراءةٌ منعها النظامُ لم تُخزَّن not-configured (C15)');

        // توقيعٌ بمفتاحٍ آخر على المسار نفسِه: 401 — الهويّةُ لا تُنتحل
        [$forgedPriv] = $this->keypair();
        $this->signed($device, $forgedPriv, 'POST', '/api/v1/endpoint/heartbeat', [])
            ->assertStatus(401);

        /* ── (٤) حدثُ USB بلا محتوى — والتجسّسيُّ يُرَدّ 422 ولا يُخزَّن حرفاً ── */

        $this->signed($device, $priv, 'POST', '/api/v1/endpoint/event', [
            'kind' => 'usb', 'severity' => 'notice',
            'summary' => 'وُصل قرصُ تخزينٍ مجهول', 'meta' => ['vendor_id' => '0xACC1'],
        ])->assertStatus(201);
        $this->assertSame(1, EndpointEvent::count());

        $this->signed($device, $priv, 'POST', '/api/v1/endpoint/event', [
            'kind' => 'usb', 'summary' => 'قرصٌ بمحتواه',
            'meta' => ['files' => [['file_content' => 'PDF-bytes...']]],
        ])->assertStatus(422);
        $this->assertSame(1, EndpointEvent::count(), 'حمولةُ محتوىً بلغت المخزن — الخصوصيّةُ انثقبت');

        /* ── (٥) سياسةٌ رصديّة: enforce=false بالولادة والشاشةُ تصارح «يتطلب MDM» ── */

        $policy = EndpointPolicy::create(['name' => 'سياسةُ قبول USB',
            'usb_mode' => 'block_storage', 'company_id' => $company->id]);
        $this->assertFalse((bool) $policy->enforce, 'سياسةٌ وُلدت فارضةً — الافتراضُ رصدٌ فقط');
        $device->forceFill(['policy_id' => $policy->id])->saveQuietly();

        $show = $this->actingAs($this->owner)->get(route('endpoints.show', $device->id))->assertOk();
        $show->assertSee('يتطلب MDM');
        $show->assertSee('رصدٌ فقط');
        $show->assertSee('غيرُ مُهيّأ');   // §11 — الوضعيّةُ الصادقة على الشاشة (not-configured)
        $show->assertDontSee('الحجبُ مُفعَّل');

        /* ── (٦) دورةُ الأمر كاملة: isolate = تصعيدٌ + سبب ← claim ← نتيجةٌ موقَّعة ── */

        $this->flushSession();
        $this->actingAs($this->owner)
            ->postJson(route('endpoints.command', $device->id), ['type' => 'isolate', 'reason' => 'جهازٌ مشبوه'])
            ->assertStatus(428);   // بلا تصعيدٍ لا أمرَ عزل
        $this->actingAs($this->owner)->withStepup()
            ->postJson(route('endpoints.command', $device->id), ['type' => 'isolate'])
            ->assertStatus(422);   // بلا سببٍ لا أمرَ عزل
        $this->assertSame(0, EndpointCommand::count());

        $this->actingAs($this->owner)->withStepup()
            ->postJson(route('endpoints.command', $device->id), ['type' => 'isolate', 'reason' => 'جهازٌ مشبوه'])
            ->assertStatus(201);
        $cmd = EndpointCommand::firstOrFail();
        $this->assertSame('pending', $cmd->state);
        $this->assertGreaterThan(0, DB::table('audits')->where('module', 'endpoints')
            ->where('record_id', $cmd->id)->count(), 'أمرُ العزل بلا قيدِ تدقيق');

        // الجهازُ يسحب فيدّعي، والسحبُ الثاني لا يكرّر الإرسال
        $pull1 = $this->signed($device, $priv, 'POST', '/api/v1/endpoint/commands/pull');
        $pull1->assertOk();
        $this->assertSame([(string) $cmd->id], collect($pull1->json('commands'))->pluck('id')->all());
        $pull2 = $this->signed($device, $priv, 'POST', '/api/v1/endpoint/commands/pull');
        $this->assertSame([], $pull2->json('commands'), 'السحبُ الثاني ادّعى أمراً مُدَّعى');

        // نتيجةٌ موقَّعةٌ بعقدها (ikey\nstate\nsha256hex) — تُقفل الأمرَ «done» مرّةً
        $resultRaw = '{"isolated":true}';
        openssl_sign($cmd->ikey . "\ndone\n" . hash('sha256', $resultRaw), $rs, $priv, OPENSSL_ALGO_SHA256);
        $this->signed($device, $priv, 'POST', '/api/v1/endpoint/commands/result', [
            'command_id' => $cmd->id, 'state' => 'done',
            'result' => $resultRaw, 'result_sig' => base64_encode($rs),
        ])->assertOk();
        $this->assertSame('done', $cmd->fresh()->state);
        $this->signed($device, $priv, 'POST', '/api/v1/endpoint/commands/result', [
            'command_id' => $cmd->id, 'state' => 'failed',
        ])->assertStatus(409);
        $this->assertSame('done', $cmd->fresh()->state, 'حالةُ أمرٍ منتهٍ دُهست');

        /* ── (٧) التحديث: بيانٌ ببصمةٍ حقيقية، وتنزيلٌ موقَّعٌ تُطابق بايتاتُه البصمة ── */

        $bytes = "MZ\x90\x00lynomia-agent-accept-" . Str::random(24);
        Storage::disk('local')->put($path = 'agent-releases/accept-' . Str::random(8) . '.bin', $bytes);
        $release = EndpointRelease::create([
            'version' => '1.1.0', 'os' => 'windows', 'arch' => 'amd64',
            'path' => $path, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes),
            'published_by' => $this->owner->id,
        ]);
        $this->assertSame('unsigned-dev', $release->signing_status,
            'إصدارٌ بلا شهادةٍ ادُّعي توقيعُه — الصدقُ C15');

        $manifest = $this->signed($device, $priv, 'GET', '/api/v1/endpoint/agent/manifest');
        $manifest->assertOk()
            ->assertJsonPath('version', '1.1.0')
            ->assertJsonPath('sha256', hash('sha256', $bytes))
            ->assertJsonPath('signing_status', 'unsigned-dev');

        $dlPath = parse_url((string) $manifest->json('url'), PHP_URL_PATH);
        $this->getJson($dlPath)->assertStatus(401);   // بلا توقيعٍ لا بايتَ واحداً

        $dl = $this->signed($device, $priv, 'GET', $dlPath);
        $dl->assertOk();
        $this->assertSame((string) $manifest->json('sha256'),
            hash('sha256', $dl->streamedContent() ?: ''),
            'بايتاتُ التنزيل لا تطابق بصمةَ البيان — update.Apply سيرفض التبديل');
        $this->assertSame(1, DB::table('download_log')->where('attachment_id', $release->id)->count(),
            'تنزيلُ الجهاز الموقَّع لم يُسجَّل');
    }
}
