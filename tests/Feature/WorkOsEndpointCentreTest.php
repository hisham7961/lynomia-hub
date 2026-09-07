<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EndpointDevice;
use App\Models\EndpointPolicy;
use App\Models\Role;
use App\Models\User;
use App\Support\Es256;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مركزُ النقاط الطرفية + الوضعيّةُ الصادقة + تبويبُ ٣٦٠** (Work OS · الطور J ·
 * WP-J.3 · §43/§63/§28).
 *
 * يمتدّ سوابقَه المعلنة:
 *  • `WorkOsEndpointProtocolTest` — عتادُ التسجيل والتوقيع الحقيقيّ (عقدُ
 *    docblock ‏`App\Support\Es256`) لبذر أجهزةٍ عبر المسار الكامل.
 *  • `ClientOperationsTest` — عزلُ حساب العميل: ٤٠٤ على كل سطحٍ داخليّ.
 *  • `WorkOsEmployee360TabsTest` — التبويبُ محروسٌ خادمياً وfield-mode لا إخفاءُ عرض.
 *  • `SecurityEventsTest` — التصنيفُ القانونيّ فوق ما يُكتب فعلاً (لا مخزنَ ثانياً).
 *
 * القواعدُ الصلبة (المواصفة §43 + النقد C15 — غيرُ قابلةٍ للتفاوض):
 *  ١) النقاطُ بنيةٌ داخلية: حسابُ العميل ٤٠٤ على **كل** مسارِ نقاطٍ ولو حمل
 *     المصفوفةَ كاملةً وراياتِها (الحارسُ فوق المصفوفة).
 *  ٢) الوضعيّةُ صادقة: قراءةٌ منعها النظامُ تُخزَّن **وتُعرَض** «غير مُهيّأ /
 *     تعذّرت القراءة» — أبداً لا «فعّالة».
 *  ٣) سياساتُ USB بلا MDM رصدٌ فقط — الشاشةُ تصارح «يتطلب MDM» ولا تدّعي حجباً.
 *  ٤) أكوادُ ENDPOINT_* تُطلَق **بالعتبة** لا لكل حدث — قيدٌ أمنيّ واحدٌ للنافذة.
 */
class WorkOsEndpointCentreTest extends TestCase
{
    /* ───────────────────── العتاد المشترك (نمطُ WorkOsEndpointProtocolTest حرفياً) ───────────────────── */

    protected function withStepup()
    {
        return $this->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);
    }

    /** زوجُ P-256 حقيقيّ */
    protected function keypair(): array
    {
        $pk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($pk, $priv);
        $d = openssl_pkey_get_details($pk);

        return [$priv, $d['key']];
    }

    /** تسجيلُ جهازٍ حقيقيّ عبر المسار الكامل — يعيد [EndpointDevice, privatePem] */
    protected function device(?Company $c = null): array
    {
        $c ??= Company::create(['name_ar' => 'شركة ألف']);
        $this->actingAs($this->owner)->withStepup()
            ->post(route('enroll.mint'), ['companyId' => $c->id])->assertSessionHas('enroll_token');
        $plain = (string) session('enroll_token');

        [$priv, $pub] = $this->keypair();
        $resp = $this->postJson('/api/v1/endpoint/enroll', [
            'token' => $plain, 'device_uuid' => (string) Str::uuid(),
            'hostname' => 'LT-CENTRE-01', 'os' => 'windows', 'public_key' => $pub,
        ]);
        $resp->assertStatus(201);

        return [EndpointDevice::findOrFail((string) $resp->json('device_id')), $priv];
    }

    /** طلبٌ موقَّعٌ بعقد Es256 الحرفيّ */
    protected function signed(EndpointDevice $d, string $priv, string $path, array $payload = [], array $over = [])
    {
        $body = $payload === [] ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE);
        $ts = (string) ($over['ts'] ?? time());
        $nonce = (string) ($over['nonce'] ?? 'n-' . Str::random(24));
        openssl_sign(Es256::canonical('POST', $path, $ts, $nonce, $body), $sig, $priv, OPENSSL_ALGO_SHA256);

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ENDPOINT_ID' => $d->id,
            'HTTP_X_ENDPOINT_TIMESTAMP' => $ts,
            'HTTP_X_ENDPOINT_NONCE' => $nonce,
            'HTTP_X_ENDPOINT_SIGNATURE' => $over['sig'] ?? base64_encode($sig),
        ], $body);
    }

    /** حسابُ عميلٍ صلب بمصفوفةٍ كاملة **وراياتٍ كاملة** — الحارسُ يفوقهما معاً */
    protected function clientUser(): User
    {
        $modules = array_keys(config('hub.modules'));
        $full = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
        $role = Role::create(['name' => 'دور عميل ' . Str::random(5), 'scope' => 'all',
            'flags' => ['monitor' => 1, 'secrets' => 1, 'audit' => 1], 'matrix' => $full]);

        return User::create(['name' => 'حسابُ عميل', 'email' => Str::random(8) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
    }

    /** دورٌ بمصفوفةٍ وقيودِ حقول — نمطُ WorkOsEmployee360TabsTest::role360 حرفياً */
    protected function role360(array $matrix, array $fieldRules = [], array $flags = []): User
    {
        $role = Role::create(['name' => 'دور مركز ' . Str::random(5), 'scope' => 'all',
            'flags' => $flags, 'matrix' => $matrix, 'field_rules' => $fieldRules]);

        return User::create(['name' => 'قارئُ المركز', 'email' => Str::random(8) . '@int.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /** جهازٌ مبذورٌ مباشرةً (لاختبارات العرض — التسجيلُ الكامل مغطّى في J.1/J.2) */
    protected function seededDevice(Company $c, array $over = []): EndpointDevice
    {
        return EndpointDevice::create($over + [
            'company_id' => $c->id, 'hostname' => 'LT-SEED-01', 'os' => 'windows',
            'device_uuid' => 'uuid-seed-' . Str::random(12), 'status' => 'active',
        ]);
    }

    /* ────────── ① حسابُ العميل: ٤٠٤ على كل سطحِ نقاطٍ — والداخليُّ غيرُ المراقب ٤٠٣ ────────── */

    public function test_a_client_account_is_404_on_every_endpoint_surface_even_with_a_full_matrix(): void
    {
        $this->seedCore();
        $c = Company::create(['name_ar' => 'شركة ألف']);
        $d = $this->seededDevice($c);
        $client = $this->clientUser();

        // المركزُ والصفحة: ٤٠٤ لا ٤٠٣ — لا إثباتَ وجودِ ما لا يخصّه
        $this->actingAs($client)->get('/endpoints')->assertNotFound();
        $this->actingAs($client)->get('/endpoints/' . $d->id)->assertNotFound();

        // وسطحا الكتابة (سكُّ الرمز وإصدارُ الأمر): ٤٠٤ ولو بتصعيد هويةٍ صالح
        $this->actingAs($client)->withStepup()
            ->post(route('enroll.mint'), ['companyId' => $c->id])->assertNotFound();
        $this->actingAs($client)->withStepup()
            ->postJson(route('endpoints.command', $d->id), ['type' => 'refresh_posture'])->assertNotFound();

        // والداخليُّ بلا رايةِ مراقب: ٤٠٣ — المركزُ للمالك/المراقب لا لكل داخليّ
        $this->actingAs($this->employee)->get('/endpoints')->assertForbidden();
        $this->actingAs($this->viewer)->get('/endpoints/' . $d->id)->assertForbidden();
    }

    /* ────────── ② الوضعيّةُ الصادقة: المرفوضةُ تُخزَّن وتُعرَض «غير مُهيّأ» — أبداً لا «فعّالة» ────────── */

    public function test_a_denied_posture_reading_is_stored_and_rendered_not_configured_never_active(): void
    {
        $this->seedCore();
        [$d, $priv] = $this->device();

        // الوكيلُ يبلّغ قراءةً **منعها النظام** — لا قراءةَ صالحةً واحدة على الجهاز
        $this->signed($d, $priv, '/api/v1/endpoint/heartbeat',
            ['posture' => ['firewall' => 'denied-by-os']])->assertOk();
        $this->assertSame('not-configured', $d->fresh()->posture['firewall'],
            'قراءةٌ منعها النظامُ لم تُخزَّن not-configured (C15)');

        // الشاشةُ تعرضها بصدق: «غير مُهيّأ / تعذّرت القراءة» — ولا «فعّالة» في الصفحة كلِّها
        $res = $this->actingAs($this->owner)->get(route('endpoints.show', $d->id))->assertOk();
        $res->assertSee('جدار الحماية');
        $res->assertSee('غير مُهيّأ / تعذّرت القراءة');
        $res->assertDontSee('فعّالة');
    }

    /* ────────── ③ سياسةُ USB: «يتطلب MDM / رصدٌ فقط» — لا ادّعاءَ حجب ────────── */

    public function test_the_usb_panel_declares_requires_mdm_and_never_claims_blocking(): void
    {
        $this->seedCore();
        $c = Company::create(['name_ar' => 'شركة ألف']);

        // سياسةُ «حجب تخزين» بلا فرض (الافتراضُ الصادق) مُسنَدةٌ لجهاز
        $p = EndpointPolicy::create(['name' => 'سياسة التخزين المتنقّل',
            'usb_mode' => 'block_storage', 'company_id' => $c->id]);
        $d = $this->seededDevice($c, ['policy_id' => $p->id]);

        $res = $this->actingAs($this->owner)->get(route('endpoints.show', $d->id))->assertOk();
        $res->assertSee('يتطلب MDM');
        $res->assertSee('رصدٌ فقط');
        // لا ادّعاءَ حجبٍ زائفاً — الوضعُ «حجب» بلا MDM يعني رصدَ المخالفة لا منعَها
        $res->assertDontSee('الحجبُ مُفعَّل');

        // وجهازٌ بلا سياسةٍ أصلاً: اللوحةُ تصارح بالنصّ الصادق نفسِه لا تُخفيه
        $d2 = $this->seededDevice($c, ['hostname' => 'LT-SEED-02', 'device_uuid' => 'uuid-seed-' . Str::random(12)]);
        $this->actingAs($this->owner)->get(route('endpoints.show', $d2->id))
            ->assertOk()->assertSee('يتطلب MDM');
    }

    /* ────────── ④ تبويبُ ٣٦٠: محروسٌ خادمياً وfield-mode يحجب الهويّةَ التقنية ────────── */

    public function test_the_360_endpoint_tab_is_server_guarded_and_field_mode_redacts_the_serial(): void
    {
        $this->seedCore();
        $c = Company::create(['name_ar' => 'شركة ألف']);
        $acct = User::create(['name' => 'صاحبُ الجهاز', 'email' => 'holder-ep@int.local',
            'password' => 'Secret!2026x', 'status' => 'نشط', 'password_changed_at' => now()]);
        $emp = Employee::create(['name' => 'مهندسُ الميدان', 'status' => 'نشط', 'user_id' => $acct->id]);
        $this->seededDevice($c, ['hostname' => 'LT-EMP-360', 'employee_id' => $acct->id,
            'hw' => ['serial' => 'EPSN-SECRET-77'], 'posture' => ['defender' => 'active'],
            'device_uuid' => 'uuid-ep-secret-77']);

        // hr:v وحدها: التبويبُ ٤٠٣ من الخادم — لا مجرَّدُ غيابٍ في الشريط
        $hrOnly = $this->role360(['hr' => ['v' => 1]]);
        $this->actingAs($hrOnly)->get(route('portal.employee', $emp->id) . '?tab=endpoint')
            ->assertForbidden();

        // المالك: الجهازُ وهويّتُه التقنية كاملة — فالحجبُ أدناه صلاحيةٌ لا حذفُ بيانات
        $res = $this->actingAs($this->owner)->get(route('portal.employee', $emp->id) . '?tab=endpoint')
            ->assertOk();
        $res->assertSee('LT-EMP-360');
        $res->assertSee('EPSN-SECRET-77');

        // hr+endpoints مع حجب الحقل (hw): الجهازُ يظهر والسيريالُ وهويّةُ الوكيل لا
        $redacted = $this->role360(['hr' => ['v' => 1], 'endpoints' => ['v' => 1]],
            ['endpoints' => ['hw' => 'hide']]);
        $res = $this->actingAs($redacted)->get(route('portal.employee', $emp->id) . '?tab=endpoint')
            ->assertOk();
        $res->assertSee('LT-EMP-360');
        $res->assertDontSee('EPSN-SECRET-77');
        $res->assertDontSee('uuid-ep-secret-77');
    }

    /* ────────── ⑤ أكوادُ ENDPOINT_*: قيدٌ بالعتبة لا لكل حدث — وواحدٌ للنافذة ────────── */

    public function test_endpoint_security_codes_fire_on_threshold_not_per_event(): void
    {
        $this->seedCore();
        [$d, $priv] = $this->device();
        $surge = 'تكرارُ أحداث USB على جهازٍ طرفيّ';

        // أربعةُ أحداث USB دون العتبة (٥): **لا** قيدَ أمنياً — لا ضجيجَ لكل حدث
        for ($i = 1; $i <= 4; $i++) {
            $this->signed($d, $priv, '/api/v1/endpoint/event',
                ['kind' => 'usb', 'summary' => 'قرصٌ مجهول ' . $i])->assertStatus(201);
        }
        $this->assertSame(0, DB::table('audits')->where('action', $surge)->count(),
            'قيدُ ENDPOINT_USB_SURGE كُتب قبل بلوغ العتبة — ضجيجٌ لكل حدث');

        // الخامسُ يبلغ العتبة: قيدٌ **واحد** مفتاحُه الجهاز
        $this->signed($d, $priv, '/api/v1/endpoint/event',
            ['kind' => 'usb', 'summary' => 'قرصٌ مجهول 5'])->assertStatus(201);
        $this->assertSame(1, DB::table('audits')->where('action', $surge)->count(),
            'بلوغُ العتبة لم يكتب قيدَ ENDPOINT_USB_SURGE');
        $this->assertSame((string) $d->id,
            (string) DB::table('audits')->where('action', $surge)->value('record_id'),
            'قيدُ العتبة لا يقود إلى الجهاز');

        // والسادسُ داخل النافذة نفسِها لا يكرّر القيد — تنبيهٌ واحدٌ للنافذة
        $this->signed($d, $priv, '/api/v1/endpoint/event',
            ['kind' => 'usb', 'summary' => 'قرصٌ مجهول 6'])->assertStatus(201);
        $this->assertSame(1, DB::table('audits')->where('action', $surge)->count(),
            'قيدُ العتبة تكرّر داخل النافذة — عاد الضجيج');

        // السجلُّ الأمنيُّ الموحَّد يصنّفه بالكود القانونيّ — قارئٌ واحد لا مخزنٌ ثانٍ
        $recent = \App\Support\SecurityEvents::recent(7, 60, 'ENDPOINT_USB_SURGE');
        $this->assertCount(1, $recent);
        $this->assertSame('ENDPOINT_USB_SURGE', $recent->first()['code']);

        // وعتبةُ الوضعيّة (٣ من warning/high): حدثان صامتان والثالثُ قيدٌ واحد
        $alert = 'تدهورُ وضعيّةِ جهازٍ طرفيّ';
        for ($i = 1; $i <= 2; $i++) {
            $this->signed($d, $priv, '/api/v1/endpoint/event',
                ['kind' => 'posture', 'severity' => 'high', 'summary' => 'أُطفئ فحصٌ ' . $i])->assertStatus(201);
        }
        $this->assertSame(0, DB::table('audits')->where('action', $alert)->count());
        $this->signed($d, $priv, '/api/v1/endpoint/event',
            ['kind' => 'posture', 'severity' => 'high', 'summary' => 'أُطفئ فحصٌ 3'])->assertStatus(201);
        $this->assertSame(1, DB::table('audits')->where('action', $alert)->count(),
            'عتبةُ الوضعيّة لم تكتب قيدَ ENDPOINT_POSTURE_ALERT');
    }

    /* ────────── ⑥ الأسطول: ترتيبٌ حتميّ وعزلُ شركةٍ (عبرَ شركةٍ ٤٠٤) ────────── */

    public function test_the_fleet_list_is_deterministic_and_company_scoped(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة ألف']);
        $b = Company::create(['name_ar' => 'شركة باء']);
        $this->seededDevice($a, ['hostname' => 'B-HOST', 'device_uuid' => 'uuid-fleet-b' . Str::random(6)]);
        $this->seededDevice($a, ['hostname' => 'A-HOST', 'device_uuid' => 'uuid-fleet-a' . Str::random(6)]);
        $devB = $this->seededDevice($b, ['hostname' => 'ZZ-HOST', 'device_uuid' => 'uuid-fleet-z' . Str::random(6)]);
        $devA = EndpointDevice::where('hostname', 'A-HOST')->firstOrFail();

        // المالك: الكلُّ بترتيبٍ حتميّ (hostname ثم id — لا قرعةَ إدراج)
        $html = $this->actingAs($this->owner)->get('/endpoints')->assertOk()->getContent();
        $pa = mb_strpos($html, 'A-HOST');
        $pb = mb_strpos($html, 'B-HOST');
        $pz = mb_strpos($html, 'ZZ-HOST');
        $this->assertNotFalse($pa);
        $this->assertNotFalse($pb);
        $this->assertNotFalse($pz);
        $this->assertLessThan($pb, $pa, 'ترتيبُ الأسطول ليس حتمياً بالاسم');
        $this->assertLessThan($pz, $pb);

        // مراقبُ (باء) المعزول: أجهزتُه وحدَها — وجهازُ (ألف) بالرابط المباشر ٤٠٤
        $monRole = Role::create(['name' => 'مراقب باء ' . Str::random(4), 'scope' => 'all',
            'flags' => ['monitor' => 1],
            'matrix' => collect(array_keys(config('hub.modules')))
                ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all()]);
        $mon = User::create(['name' => 'مراقبُ باء', 'email' => 'mon-b@centre.local',
            'password' => 'Secret!2026x', 'role_id' => $monRole->id, 'status' => 'نشط',
            'companies' => [$b->id], 'password_changed_at' => now()]);

        $this->actingAs($mon)->get('/endpoints')->assertOk()
            ->assertSee('ZZ-HOST')->assertDontSee('A-HOST');
        $this->actingAs($mon)->get(route('endpoints.show', $devB->id))->assertOk();
        $this->actingAs($mon)->get(route('endpoints.show', $devA->id))->assertNotFound();
    }
}
