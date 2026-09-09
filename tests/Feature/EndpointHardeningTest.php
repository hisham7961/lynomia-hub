<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EndpointCommand;
use App\Models\EndpointDevice;
use App\Support\EndpointPrivacy;
use App\Support\Es256;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\EnrollsEndpoints;
use Tests\TestCase;

/**
 * **التصحيح §4/§12/§13 — تقسيةُ الهويّة والأوامر والخصوصيّة (إثباتٌ لا ادّعاء).**
 *
 * §4  الهويّة: العقدُ P-256 حصراً؛ **المفتاحُ الخاصُّ لا يغادر الجهاز** — الخادمُ
 *     يرفض أيَّ مادةٍ خاصّةٍ في التسجيل ويخزّن العامَّ وبصمتَه فقط.
 * §12 الأوامر: قائمةٌ مغلقةٌ خمسيّة — **لا تنفيذَ عامّ** (shell/powershell/eval/...).
 * §13 الخصوصيّة: كلُّ حقلِ مراقبةٍ يُرَدّ خادميّاً ولا يُخزَّن منه حرف.
 */
class EndpointHardeningTest extends TestCase
{
    use EnrollsEndpoints;

    protected function withStepup()
    {
        return $this->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);
    }

    protected function p256(): array
    {
        $pk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($pk, $priv);

        return [$priv, openssl_pkey_get_details($pk)['key']];
    }

    protected function mintToken(?Company $c = null): string
    {
        $c ??= Company::create(['name_ar' => 'شركة ألف']);
        $this->actingAs($this->owner)->withStepup()
            ->post(route('enroll.mint'), ['assetId' => $this->eligibleAsset($c)->id])->assertSessionHas('enroll_token');

        return (string) session('enroll_token');
    }

    protected function enroll(array $over = [])
    {
        [, $pub] = $this->p256();

        return $this->postJson('/api/v1/endpoint/enroll', array_merge([
            'token' => $this->mintToken(), 'device_uuid' => (string) Str::uuid(),
            'hostname' => 'LT-HARD', 'os' => 'windows', 'public_key' => $pub,
        ], $over));
    }

    protected function device(): array
    {
        [$priv, $pub] = $this->p256();
        $resp = $this->postJson('/api/v1/endpoint/enroll', [
            'token' => $this->mintToken(), 'device_uuid' => (string) Str::uuid(),
            'hostname' => 'LT-HARD', 'os' => 'windows', 'public_key' => $pub,
        ]);
        $resp->assertStatus(201);

        return [EndpointDevice::findOrFail((string) $resp->json('device_id')), $priv];
    }

    protected function signed(EndpointDevice $d, string $priv, string $uri, array $payload)
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $ts = (string) time();
        $nonce = 'n-' . Str::random(24);
        openssl_sign(Es256::canonical('POST', parse_url($uri, PHP_URL_PATH), $ts, $nonce, $body), $sig, $priv, OPENSSL_ALGO_SHA256);

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ENDPOINT_ID' => $d->id, 'HTTP_X_ENDPOINT_TIMESTAMP' => $ts,
            'HTTP_X_ENDPOINT_NONCE' => $nonce, 'HTTP_X_ENDPOINT_SIGNATURE' => base64_encode($sig),
        ], $body);
    }

    /* ───────── §4 — الهويّة: P-256 حصراً، ولا مادةَ خاصّةٍ تُقبَل أو تُخزَّن ───────── */

    public function test_enroll_accepts_only_p256_public_key_and_never_stores_private_material(): void
    {
        $this->seedCore();

        // مفتاحٌ خاصٌّ في حقل public_key: يُرفَض — لا يغادر الخاصُّ الجهازَ (حارسُ «PRIVATE KEY»)
        [$priv] = $this->p256();
        $this->enroll(['public_key' => $priv])->assertStatus(422);

        // RSA عامّ (ليس P-256): يُرفَض — العقدُ P-256 حصراً
        $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->enroll(['public_key' => openssl_pkey_get_details($rsa)['key']])->assertStatus(422);
        $this->assertSame(0, EndpointDevice::count(), 'جهازٌ سُجّل بمفتاحٍ غيرِ صالح');

        // **حقنُ مادةٍ خاصّةٍ في أيّ حقل** (بصيغة PEM): يُرفَض خادميّاً — الخادمُ يمسح
        // الحمولةَ بحثاً عن «PRIVATE KEY» فيرفض وكيلاً معطوباً/عدائيّاً قبل أيّ تخزين
        [$otherPriv] = $this->p256();
        $this->enroll(['extra_field' => $otherPriv])->assertStatus(422);
        $this->assertSame(0, EndpointDevice::count(), 'مادّةٌ خاصّةٌ بلغت التخزين — المفتاحُ الخاصُّ غادر الجهاز');

        // تسجيلٌ نظيفٌ صحيح: يُقبَل — ويُخزَّن العامُّ وبصمتُه فقط
        $resp = $this->enroll();
        $resp->assertStatus(201);
        $device = EndpointDevice::findOrFail((string) $resp->json('device_id'));

        // الخادمُ يخزّن العامَّ وبصمتَه فقط — **ولا عمودَ يحمل مادّةً خاصّة**
        $this->assertNotEmpty($device->public_key);
        $this->assertNotEmpty($device->pubkey_fp);
        $row = (array) DB::table('endpoint_devices')->where('id', $device->id)->first();
        foreach ($row as $col => $val) {
            if (is_string($val)) {
                $this->assertStringNotContainsString('PRIVATE KEY', $val, "العمود {$col} يحمل مادّةً خاصّة");
                $this->assertStringNotContainsString((string) $otherPriv, $val, "العمود {$col} يحمل المفتاحَ الخاصَّ المحقون");
            }
        }
    }

    /* ───────── §12 — الأوامر: قائمةٌ مغلقةٌ خمسيّة، لا تنفيذَ عامّ ───────── */

    public function test_command_types_are_a_closed_five_and_generic_exec_is_rejected(): void
    {
        $this->seedCore();
        [$d] = $this->device();

        // القائمةُ المغلقةُ خمسةٌ حرفياً — لا سادسَ
        $this->assertSame(['refresh_inventory', 'refresh_posture', 'apply_policy', 'isolate', 'lock'],
            EndpointCommand::TYPES);

        foreach (['run_shell', 'shell', 'powershell', 'exec', 'bash', 'sh', 'applescript',
                  'eval', 'cmd', 'run', 'plugin', 'url', 'script', 'download_exec'] as $evil) {
            $this->actingAs($this->owner)->withStepup()
                ->postJson(route('endpoints.command', $d->id), ['type' => $evil, 'args' => ['cmd' => 'rm -rf /']])
                ->assertStatus(422);
        }
        $this->assertSame(0, EndpointCommand::count(), 'أمرٌ خارج القائمة المغلقة أُنشئ — ثغرةُ تنفيذٍ عامّ');
    }

    /* ───────── §13 — الخصوصيّة: كلُّ حقلِ مراقبةٍ يُرَدّ ولا يُخزَّن ───────── */

    public function test_every_forbidden_monitoring_field_is_rejected_and_never_stored(): void
    {
        $this->seedCore();
        [$d, $priv] = $this->device();

        // القائمةُ المحظورةُ غنيّةٌ (لا رمزيّة) — تغطّي الفئاتِ السبع
        $this->assertGreaterThanOrEqual(20, count(EndpointPrivacy::FORBIDDEN),
            'قائمةُ الحظر رقيقةٌ — الخصوصيّةُ غيرُ مفروضةٍ فعلاً');

        // كلُّ رمزٍ محظورٍ في نبضةٍ موقَّعةٍ: 422 مفروضٌ خادميّاً
        foreach (EndpointPrivacy::FORBIDDEN as $token) {
            $this->signed($d, $priv, '/api/v1/endpoint/heartbeat', ['hw' => [$token => 'x']])
                ->assertStatus(422);
        }

        // وحدثٌ يحمل مفتاحَ مراقبةٍ عميقاً: يُرَدّ ولا يُخزَّن حرف
        $this->signed($d, $priv, '/api/v1/endpoint/event', [
            'kind' => 'usb', 'summary' => 'ملخّص', 'meta' => ['nested' => ['screenshot' => 'BASE64...']],
        ])->assertStatus(422);
        $this->assertSame(0, \App\Models\EndpointEvent::count(), 'حدثُ مراقبةٍ خُزِّن — الخصوصيّةُ انثقبت');

        // ومعاملاتُ أمرٍ داخليّةٍ تحمل ألفاظَ مراقبة: 422 (اتساقُ البوّابة الداخليّ)
        $this->actingAs($this->owner)->withStepup()
            ->postJson(route('endpoints.command', $d->id),
                ['type' => 'apply_policy', 'args' => ['keylog' => true]])
            ->assertStatus(422);
        $this->assertSame(0, EndpointCommand::count(), 'أمرٌ بمعاملاتِ مراقبةٍ خُزِّن');
    }
}
