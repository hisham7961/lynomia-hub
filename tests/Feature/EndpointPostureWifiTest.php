<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EndpointDevice;
use App\Models\EndpointPolicy;
use App\Support\Es256;
use App\Support\PostureContract;
use Illuminate\Support\Str;
use Tests\Concerns\EnrollsEndpoints;
use Tests\TestCase;

/**
 * **التصحيح §10/§11 — وضعيّةُ Wi-Fi الشركة + عقدُ الوضعيّة الموسَّع.**
 *
 * §11: العقدُ ستّيٌّ (active/inactive/permission-denied/unavailable/unsupported/
 * not-configured) — و«مُنع القراءة» **ليس امتثالاً** (لا يُطمَس not-configured
 * ولا يُحسَب فعّالاً). §10: وضعيّةُ Wi-Fi من اتصال الجهاز نفسِه فقط (لا مسح)،
 * تُقيَّم على قائمة SSID المعتمدة، واسمُ الشبكة يُخزَّن **حين تكون معتمدة فقط**
 * (احترازُ خصوصيّة §13).
 */
class EndpointPostureWifiTest extends TestCase
{
    use EnrollsEndpoints;

    protected function keypair(): array
    {
        $pk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($pk, $priv);

        return [$priv, openssl_pkey_get_details($pk)['key']];
    }

    protected function device(?Company $c = null): array
    {
        $c ??= Company::create(['name_ar' => 'شركة ألف']);
        $this->actingAs($this->owner)->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp])
            ->post(route('enroll.mint'), ['assetId' => $this->eligibleAsset($c)->id])->assertSessionHas('enroll_token');
        $plain = (string) session('enroll_token');

        [$priv, $pub] = $this->keypair();
        $resp = $this->postJson('/api/v1/endpoint/enroll', [
            'token' => $plain, 'device_uuid' => (string) Str::uuid(),
            'hostname' => 'LT-WIFI', 'os' => 'windows', 'public_key' => $pub,
        ]);
        $resp->assertStatus(201);

        return [EndpointDevice::findOrFail((string) $resp->json('device_id')), $priv];
    }

    protected function signed(EndpointDevice $d, string $priv, string $uri, array $payload = [])
    {
        $body = $payload === [] ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE);
        $ts = (string) time();
        $nonce = 'n-' . Str::random(24);
        openssl_sign(Es256::canonical('POST', parse_url($uri, PHP_URL_PATH), $ts, $nonce, $body), $sig, $priv, OPENSSL_ALGO_SHA256);

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ENDPOINT_ID' => $d->id, 'HTTP_X_ENDPOINT_TIMESTAMP' => $ts,
            'HTTP_X_ENDPOINT_NONCE' => $nonce, 'HTTP_X_ENDPOINT_SIGNATURE' => base64_encode($sig),
        ], $body);
    }

    /* ───────── ① العقد: التطبيع والامتثال الصارم ───────── */

    public function test_the_contract_normalizes_and_only_active_is_compliant(): void
    {
        foreach (['active', 'inactive', 'permission-denied', 'unavailable', 'unsupported', 'not-configured'] as $s) {
            $this->assertSame($s, PostureContract::normalize($s));
        }
        // خارجُ القائمة ⇒ not-configured
        $this->assertSame('not-configured', PostureContract::normalize('enabled'));
        $this->assertSame('not-configured', PostureContract::normalize('ACTIVE!!'));

        // الامتثالُ لـactive وحدَها — permission-denied **ليس** امتثالاً (§10/§11)
        $this->assertTrue(PostureContract::isCompliant('active'));
        $this->assertFalse(PostureContract::isCompliant('permission-denied'));
        $this->assertFalse(PostureContract::isCompliant('inactive'));
        $this->assertFalse(PostureContract::isCompliant('unavailable'));
    }

    /* ───────── ② تقييمُ Wi-Fi: معتمدة/غيرُ معتمدة/مُنع/غيرُ مدعوم ───────── */

    public function test_wifi_evaluation_is_honest_and_permission_denied_is_not_compliant(): void
    {
        $approved = ['CorpWiFi', 'Corp-Guest'];

        $this->assertSame('active', PostureContract::evaluateWifi('CorpWiFi', 'readable', $approved));
        $this->assertSame('inactive', PostureContract::evaluateWifi('HomeNet', 'readable', $approved));
        // مُنع القراءة ⇒ permission-denied (**ليس** active — §10 صراحةً)
        $this->assertSame('permission-denied', PostureContract::evaluateWifi(null, 'permission-denied', $approved));
        $this->assertSame('unsupported', PostureContract::evaluateWifi(null, 'unsupported', $approved));
        $this->assertSame('unavailable', PostureContract::evaluateWifi(null, 'unavailable', $approved));
        $this->assertSame('unavailable', PostureContract::evaluateWifi('', 'readable', $approved));
        // بلا قائمةٍ معتمدة ⇒ لا حكم (not-configured)
        $this->assertSame('not-configured', PostureContract::evaluateWifi('CorpWiFi', 'readable', []));
    }

    /* ───────── ③ النبضة: الحالاتُ الموسَّعة تُحفَظ ولا تُطمَس ───────── */

    public function test_heartbeat_preserves_expanded_posture_states(): void
    {
        $this->seedCore();
        [$d, $priv] = $this->device();

        $this->signed($d, $priv, '/api/v1/endpoint/heartbeat', [
            'posture' => [
                'defender' => 'active',
                'firewall' => 'permission-denied',   // مُنع القراءة — يُحفَظ لا يُطمَس
                'updates' => 'unsupported',
                'bitlocker' => 'enabled',            // خارج القائمة ⇒ not-configured
            ],
        ])->assertOk();

        $p = $d->fresh()->posture;
        $this->assertSame('active', $p['defender']);
        $this->assertSame('permission-denied', $p['firewall'], 'مُنعُ القراءة طُمس (كان ينبغي حفظُه)');
        $this->assertSame('unsupported', $p['updates']);
        $this->assertSame('not-configured', $p['bitlocker'], 'قيمةٌ خارج القائمة قُبلت');

        // posture_ok يعدّ 'active' وحدَها — permission-denied/unsupported لا تُحسَب
        $this->assertSame(1.0, (float) \App\Models\MetricPoint::where('module', 'endpoints')
            ->where('record_id', $d->id)->where('metric', 'posture_ok')
            ->orderByDesc('at')->orderByDesc('id')->value('value'),
            'permission-denied حُسبت امتثالاً');
    }

    /* ───────── ④ Wi-Fi: التقييم على القائمة المعتمدة + احترازُ الخصوصيّة ───────── */

    public function test_heartbeat_wifi_is_evaluated_against_policy_and_ssid_stored_only_when_approved(): void
    {
        $this->seedCore();
        $company = Company::create(['name_ar' => 'ش']);
        [$d, $priv] = $this->device($company);

        // سياسةٌ بقائمة SSID معتمدة، تُسنَد للجهاز
        $policy = EndpointPolicy::create(['name' => 'سياسةٌ', 'usb_mode' => 'audit',
            'company_id' => $company->id, 'approved_ssids' => ['CorpWiFi', 'Corp-Guest']]);
        $d->forceFill(['policy_id' => $policy->id])->save();

        // على شبكةٍ معتمدة ⇒ wifi=active، والاسمُ يُخزَّن (معتمدٌ فيُعرَض)
        $this->signed($d, $priv, '/api/v1/endpoint/heartbeat', [
            'wifi' => ['ssid' => 'CorpWiFi', 'status' => 'readable'],
        ])->assertOk();
        $d->refresh();
        $this->assertSame('active', $d->posture['wifi']);
        $this->assertSame('CorpWiFi', $d->wifi_ssid, 'اسمُ الشبكة المعتمدة لم يُخزَّن للعرض');

        // على شبكةٍ **غيرِ** معتمدة ⇒ wifi=inactive، والاسمُ **لا يُخزَّن** (خصوصيّة §13)
        $this->signed($d, $priv, '/api/v1/endpoint/heartbeat', [
            'wifi' => ['ssid' => 'HomeNetwork', 'status' => 'readable'],
        ])->assertOk();
        $d->refresh();
        $this->assertSame('inactive', $d->posture['wifi']);
        $this->assertNull($d->wifi_ssid, 'اسمُ شبكةٍ شخصيّةٍ (غيرِ معتمدة) خُزِّن — خرقُ خصوصيّة');

        // مُنعُ قراءة SSID ⇒ permission-denied (ليس امتثالاً) والاسمُ null
        $this->signed($d, $priv, '/api/v1/endpoint/heartbeat', [
            'wifi' => ['status' => 'permission-denied'],
        ])->assertOk();
        $d->refresh();
        $this->assertSame('permission-denied', $d->posture['wifi']);
        $this->assertNull($d->wifi_ssid);
    }
}
