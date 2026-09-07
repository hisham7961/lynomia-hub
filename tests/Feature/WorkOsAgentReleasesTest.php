<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EndpointDevice;
use App\Models\EndpointRelease;
use App\Models\Role;
use App\Models\User;
use App\Support\Es256;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مركزُ تنزيل الوكيل + بيانُ التحديث الموقَّع** (Work OS · الطور L · WP-L.2 · §44/§62).
 *
 * يمتدّ هذا الملفُّ سوابقَه المعلنة:
 *  • `AttachmentController` (انضباطُ التقديم) — auth + قرصٌ محليّ لا public +
 *    Content-Disposition attachment + صفُّ download_log لكل تقديمٍ ناجح + sha256.
 *  • `WorkOsEndpointProtocolTest` — عتادُ الجهاز الموقَّع (تسجيلٌ بزوج P-256
 *    حقيقيّ + طلباتٌ بعقد Es256 الحرفيّ) لبيان التحديث ومساره.
 *  • `agent/internal/update` — شكلُ البيان `{url, sha256}` الذي يستهلكه
 *    `update.Apply` حرفياً (المفاتيحُ الزائدة تُهمَل في Go فلا تكسر).
 *
 * القواعدُ الصلبة (C15 — الصدقُ غيرُ قابلٍ للتفاوض):
 *  ١) **لا توقيعَ زائفاً**: لا شهادةَ Authenticode ولا Developer ID مُهيّأة —
 *     كلُّ صفٍّ يقول 'unsigned-dev' والصفحةُ تعرض «UNSIGNED DEVELOPMENT BUILD»
 *     صادقةً؛ و'signed' لا تُكتب إلا عبر إقرارِ توقيعٍ متحقَّقٍ صريح (لا نموذجَ
 *     ويب يبلغه أبداً).
 *  ٢) **التجزئةُ حقيقية**: sha256 تُحسب خادمياً من الملف المخزَّن نفسِه —
 *     تجزئةُ النموذج المزوَّرة تُهمَل بالكامل.
 *  ٣) **التقديمُ مُصادَقٌ مُسجَّل**: ضيفٌ يُحوَّل للدخول (لا بايتَ واحداً)،
 *     وحسابُ العميل ٤٠٤ على كل سطح، وكلُّ تنزيلٍ ناجحٍ صفٌّ في download_log.
 */
class WorkOsAgentReleasesTest extends TestCase
{
    protected function tearDown(): void
    {
        // ملفاتُ الاختبار على القرص الحقيقيّ (نمطُ ReaderScopeLeaksTest) — تُكنَس
        foreach (glob(storage_path('app/agent-releases/*')) ?: [] as $f) @unlink($f);
        @rmdir(storage_path('app/agent-releases'));
        parent::tearDown();
    }

    protected function withStepup()
    {
        return $this->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);
    }

    /** حسابُ عميلٍ صلب — نمطُ WorkOsEndpointProtocolTest::clientUser حرفياً */
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

    /** زوجُ P-256 حقيقيّ — نمطُ WorkOsEndpointEnrollTest::keypair حرفياً */
    protected function keypair(): array
    {
        $pk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($pk, $priv);
        $d = openssl_pkey_get_details($pk);

        return [$priv, $d['key']];
    }

    /** تسجيلُ جهازٍ حقيقيّ عبر المسار الكامل — يعيد [EndpointDevice, privatePem] */
    protected function device(string $os = 'windows'): array
    {
        $c = Company::create(['name_ar' => 'شركة ألف']);
        $this->actingAs($this->owner)->withStepup()
            ->post(route('enroll.mint'), ['companyId' => $c->id])->assertSessionHas('enroll_token');
        $plain = (string) session('enroll_token');

        [$priv, $pub] = $this->keypair();
        $resp = $this->postJson('/api/v1/endpoint/enroll', [
            'token' => $plain, 'device_uuid' => (string) Str::uuid(),
            'hostname' => 'LT-REL-01', 'os' => $os, 'public_key' => $pub,
        ]);
        $resp->assertStatus(201);

        return [EndpointDevice::findOrFail((string) $resp->json('device_id')), $priv];
    }

    /** طلبٌ موقَّعٌ بعقد Es256 الحرفيّ — GET أو POST (الاستعلامُ خارج التوقيع كالمسار في path()) */
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

    /** ثنائيّةٌ وهمية بمحتوى معلوم — التجزئةُ الحقيقية تُحسب منه في الإثباتات */
    protected function binary(string $marker): array
    {
        $bytes = "MZ\x90\x00lynomia-agent-test-" . $marker . '-' . Str::random(32);

        return [UploadedFile::fake()->createWithContent('lynomia-agent.exe', $bytes), $bytes];
    }

    /** إصدارٌ مزروعٌ مباشرةً (لاختبارات البوّابة) — ملفٌ حقيقيّ تحت storage/app لا public */
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

    /* ────────── ① البوّابة: ضيفٌ يُحوَّل ولا بايتَ يُقدَّم، وكلُّ تنزيلٍ ناجحٍ مُسجَّل ────────── */

    public function test_the_download_is_authenticated_attachment_disposition_and_every_success_is_logged(): void
    {
        $this->seedCore();
        [$rel, $bytes] = $this->seedRelease();

        // ضيفٌ بلا جلسة: تحويلٌ للدخول — **أبداً** لا محتوى الملف (لا public storage)
        $this->get(route('endpoints.releases.download', $rel->id))->assertRedirect();
        $this->assertSame(0, DB::table('download_log')->count(), 'ضيفٌ ولّد صفَّ تنزيل');

        // داخليٌّ مُصادَق: يُقدَّم attachment (لا تنفيذَ في المتصفح) والمحتوى حرفيّ
        $r = $this->actingAs($this->employee)->get(route('endpoints.releases.download', $rel->id));
        $r->assertOk();
        $this->assertStringContainsString('attachment', (string) $r->headers->get('content-disposition'),
            'التنزيلُ ليس Content-Disposition: attachment');
        $this->assertSame(hash('sha256', $bytes), hash('sha256', $r->streamedContent() ?: ''),
            'المحتوى المُقدَّم لا يطابق الملفَ المخزَّن');

        // انضباطُ download_log (سكّةُ AttachmentController نفسُها): صفٌّ لكل نجاح
        $this->actingAs($this->owner)->get(route('endpoints.releases.download', $rel->id))->assertOk();
        $this->assertSame(2, DB::table('download_log')->where('attachment_id', $rel->id)->count(),
            'تنزيلان ناجحان لم يكتبا صفّين في download_log');
        $this->assertSame(1, DB::table('download_log')->where('attachment_id', $rel->id)
            ->where('user_id', $this->employee->id)->count(), 'صفُّ التنزيل بلا هويّة المنزِّل');

        // والملفُ تحت storage/app لا تحت public/ — لا سكّةَ تقديمٍ ثانية
        $this->assertStringStartsWith('agent-releases/', (string) $rel->path);
        $this->assertFileDoesNotExist(public_path($rel->path));
    }

    /* ────────── ② حسابُ العميل: ٤٠٤ على كل سطحٍ (PortalGuard + دفاعُ العمق) ────────── */

    public function test_a_client_account_gets_404_on_every_releases_surface(): void
    {
        $this->seedCore();
        [$rel] = $this->seedRelease();
        $client = $this->clientUser();

        $this->actingAs($client)->get(route('endpoints.releases'))->assertNotFound();
        $this->actingAs($client)->get(route('endpoints.releases.download', $rel->id))->assertNotFound();
        $this->actingAs($client)->withStepup()
            ->post(route('endpoints.releases.store'), ['version' => '9.9.9', 'os' => 'windows', 'arch' => 'amd64'])
            ->assertNotFound();
        $this->actingAs($client)->withStepup()
            ->post(route('endpoints.releases.delete', $rel->id))->assertNotFound();

        $this->assertNotNull(EndpointRelease::find($rel->id), 'حسابُ عميلٍ حذف إصداراً');
        $this->assertSame(0, DB::table('download_log')->count(), 'حسابُ عميلٍ ولّد صفَّ تنزيل');
    }

    /* ────────── ③ الرفع: مالكٌ + تصعيدٌ، والتجزئةُ تُحسب خادمياً لا من النموذج ────────── */

    public function test_upload_requires_owner_and_stepup_and_the_sha256_is_computed_server_side(): void
    {
        $this->seedCore();
        [$file, $bytes] = $this->binary('up1');

        // موظفٌ داخليّ (غيرُ مالك): 403 — الإدارةُ للمالك وحدَه
        $this->actingAs($this->employee)->withStepup()
            ->post(route('endpoints.releases.store'),
                ['version' => '1.2.3', 'os' => 'windows', 'arch' => 'amd64', 'file' => $file])
            ->assertForbidden();
        $this->assertSame(0, EndpointRelease::count());

        // مالكٌ بلا تصعيد: يُحوَّل لشاشة تأكيد الهوية ولا صفَّ يُكتب
        $this->flushSession();
        $this->actingAs($this->owner)
            ->post(route('endpoints.releases.store'),
                ['version' => '1.2.3', 'os' => 'windows', 'arch' => 'amd64', 'file' => $file])
            ->assertStatus(302);
        $this->assertSame(0, EndpointRelease::count(), 'رفعٌ بلا تصعيدِ هويةٍ كتب صفاً');

        // مكتملُ الشروط — **والنموذجُ يكذب**: تجزئةٌ مزوَّرة وحجمٌ مزوَّر وحالةُ
        // 'signed' — كلُّها تُهمَل: المخزونُ تجزئةُ الملف الحقيقية وحالةُ الصدق
        [$file2, $bytes2] = $this->binary('up2');
        $this->actingAs($this->owner)->withStepup()
            ->post(route('endpoints.releases.store'), [
                'version' => '1.2.3', 'os' => 'windows', 'arch' => 'amd64', 'file' => $file2,
                'sha256' => str_repeat('a', 64),          // تجزئةٌ مزوَّرة — لا تُقرأ
                'size' => 1,                              // حجمٌ مزوَّر — لا يُقرأ
                'signing_status' => 'signed',             // ادّعاءُ توقيعٍ — لا يُقرأ أبداً
            ])->assertRedirect();

        $rel = EndpointRelease::firstOrFail();
        $this->assertSame(hash('sha256', $bytes2), $rel->sha256, 'التجزئةُ لم تُحسب خادمياً من الملف نفسِه');
        $this->assertSame(strlen($bytes2), (int) $rel->size, 'الحجمُ لم يُقرأ من الملف نفسِه');
        $this->assertSame('unsigned-dev', $rel->signing_status, 'نموذجُ ويب ادّعى توقيعاً فصُدِّق (C15)');
        $this->assertSame((string) $this->owner->id, (string) $rel->published_by);

        // والملفُ المخزَّن على القرص المحليّ تجزئتُه هي المنشورة حرفياً
        $abs = Storage::disk('local')->path($rel->path);
        $this->assertFileExists($abs);
        $this->assertSame($rel->sha256, hash_file('sha256', $abs));

        // أثرُ تدقيقٍ للنشر — من نشر أيَّ نسخةٍ لأيّ منصّة
        $this->assertGreaterThan(0, DB::table('audits')->where('module', 'endpoints')
            ->where('record_id', $rel->id)->count(), 'النشرُ بلا قيدِ تدقيق');

        // UNIQUE(version,os,arch): النسخةُ نفسُها للمنصّة نفسِها لا تُنشر مرتين
        [$file3] = $this->binary('up3');
        $this->actingAs($this->owner)->withStepup()
            ->post(route('endpoints.releases.store'),
                ['version' => '1.2.3', 'os' => 'windows', 'arch' => 'amd64', 'file' => $file3])
            ->assertSessionHasErrors();
        $this->assertSame(1, EndpointRelease::count(), 'نسخةٌ مكرَّرة (version,os,arch) نُشرت');
    }

    /* ────────── ④ الصدق (C15): 'signed' لا تُدَّعى، والصفحةُ تصارح ────────── */

    public function test_signing_status_can_never_claim_signed_without_a_verified_signature(): void
    {
        $this->seedCore();

        // إنشاءٌ مباشرٌ بحالة 'signed' بلا إقرارِ توقيعٍ متحقَّق: يُرمى لا يُكتب صامتاً
        try {
            $this->seedRelease(['version' => '2.0.0', 'signing_status' => 'signed']);
            $this->fail("حالةُ 'signed' كُتبت بلا توقيعٍ متحقَّق");
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(0, EndpointRelease::count());
        }

        // وقيمةٌ خارج القائمة: تُرمى كذلك (allowlist تطبيقيّ لا DB enum — C10)
        try {
            $this->seedRelease(['version' => '2.0.1', 'signing_status' => 'authenticode-ev']);
            $this->fail('حالةُ توقيعٍ خارج القائمة قُبلت');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(0, EndpointRelease::count());
        }

        // البابُ الشرعيّ الوحيد موجودٌ للمستقبل: إقرارُ توقيعٍ متحقَّقٍ **صريح**
        // (لا يستدعيه اليوم أيُّ مسار — لا شهادةَ مُهيّأة؛ خطُّ CI متى مُنحت
        // الأسرارَ يوقّع فعلاً ثم يقرّ) — والافتراضُ الأبديّ unsigned-dev
        $bytes = 'SIGNED-' . Str::random(20);
        Storage::disk('local')->put($p = 'agent-releases/seed-signed.bin', $bytes);
        $r = new EndpointRelease(['version' => '2.0.2', 'os' => 'macos', 'arch' => 'arm64',
            'path' => $p, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes),
            'signing_status' => 'signed', 'published_by' => $this->owner->id]);
        $r->attestVerifiedSignature()->save();
        $this->assertSame('signed', $r->fresh()->signing_status);
        // والإقرارُ لحفظةٍ واحدة — حفظٌ لاحقٌ بلا إقرارٍ جديد يُرمى لا يمرّ خلسة
        try {
            $r->fresh()->forceFill(['notes' => 'تحرير'])->save();
            $this->fail('صفُّ signed حُفظ ثانيةً بلا إقرارٍ جديد');
        } catch (\InvalidArgumentException $e) {
        }
        EndpointRelease::whereKey($r->id)->forceDelete();

        // الصفحةُ تصارح: «UNSIGNED DEVELOPMENT BUILD» بجانب كل صفٍّ غيرِ موقَّع + التجزئة
        [$rel] = $this->seedRelease(['version' => '2.1.0']);
        $this->actingAs($this->owner)->get(route('endpoints.releases'))
            ->assertOk()
            ->assertSee('UNSIGNED DEVELOPMENT BUILD')
            ->assertSee('2.1.0')
            ->assertSee($rel->sha256);

        // والموظفُ الداخليّ لا يبلغ شاشةَ الإدارة (الإدارةُ للمالك): 403
        $this->actingAs($this->employee)->get(route('endpoints.releases'))->assertForbidden();
    }

    /* ────────── ⑤ البيانُ الموقَّع: الأحدثُ لمنصّة الجهاز بعقد update.Apply حرفياً ────────── */

    public function test_the_manifest_serves_the_latest_release_for_the_devices_os_and_arch_in_the_apply_shape(): void
    {
        $this->seedCore();
        [$d, $priv] = $this->device('windows');

        // لا إصدارَ منشوراً بعد: 404 صريح لا بيانَ فارغاً يكسر Apply
        $this->signed($d, $priv, 'GET', '/api/v1/endpoint/agent/manifest')->assertNotFound();

        // أربعةُ إصدارات: قديمٌ وأحدثُ لمنصّة الجهاز، وأجنبيّان (معماريّةٌ ونظامٌ آخران)
        [$old] = $this->seedRelease(['version' => '1.0.0', 'created_at' => now()->subHours(3)]);
        [$new, $newBytes] = $this->seedRelease(['version' => '1.1.0', 'created_at' => now()->subHours(1)]);
        [$armRel] = $this->seedRelease(['version' => '1.2.0', 'arch' => 'arm64', 'created_at' => now()->subMinutes(30)]);
        $this->seedRelease(['version' => '1.3.0', 'os' => 'macos', 'arch' => 'arm64', 'created_at' => now()->subMinutes(10)]);

        // بلا تلميحِ معماريّة: amd64 افتراضاً — الأحدثُ لنظام الجهاز (لا macos أبداً)
        $m = $this->signed($d, $priv, 'GET', '/api/v1/endpoint/agent/manifest');
        $m->assertOk()
            ->assertJsonPath('version', '1.1.0')
            ->assertJsonPath('sha256', hash('sha256', $newBytes))     // التجزئةُ الحقيقية في البيان
            ->assertJsonPath('signing_status', 'unsigned-dev');       // الصدقُ يسافر مع البيان
        // **شكلُ update.Apply حرفياً**: مفتاحا url وsha256 حاضران (الزائدُ يُهمَل في Go)
        $url = (string) $m->json('url');
        $this->assertNotSame('', $url, 'البيانُ بلا url');
        $this->assertStringContainsString('/api/v1/endpoint/agent/download/' . $new->id, $url,
            'عنوانُ البيان ليس مسارَ التنزيل الموقَّع');

        // نبضةٌ موقَّعة تبلّغ المعماريّة (كما يبلّغها جردُ الوكيل runtime.GOARCH):
        // القراءةُ الموقَّعة تغلب تلميحَ الاستعلام غيرَ الموقَّع — النطاقُ نطاقُ الجهاز
        $this->signed($d, $priv, 'POST', '/api/v1/endpoint/heartbeat', ['hw' => ['arch' => 'arm64']])->assertOk();
        $this->signed($d, $priv, 'GET', '/api/v1/endpoint/agent/manifest?arch=amd64')
            ->assertOk()->assertJsonPath('version', '1.2.0');

        // مسارُ التنزيل نفسُه موقَّعٌ: بلا توقيعٍ 401 ولا بايتَ ولا صفَّ سجلّ
        $plain = parse_url($url, PHP_URL_PATH);
        $this->getJson($plain)->assertStatus(401);
        $this->assertSame(0, DB::table('download_log')->count(), 'تنزيلٌ بلا توقيعٍ سُجّل — أي أنه قُدّم');

        // وبالتوقيع: المحتوى تجزئتُه هي تجزئةُ البيان حرفياً (عقدُ Apply قبل التبديل) + سجلّ
        $dl = $this->signed($d, $priv, 'GET', $plain);
        $dl->assertOk();
        $this->assertSame(hash('sha256', $newBytes), hash('sha256', $dl->streamedContent() ?: ''),
            'بايتاتُ التنزيل لا تطابق تجزئةَ البيان — Apply سيرفض التبديل');
        $this->assertSame(1, DB::table('download_log')->where('attachment_id', $new->id)->count(),
            'تنزيلُ الجهاز الموقَّع لم يُسجَّل');

        // جهازُ نظامٍ آخر لا يبلغ إصدارَ غيرِ نظامه: ٤٠٤ لا تسريبَ وجود
        [$mac, $macPriv] = $this->device('macos');
        $this->signed($mac, $macPriv, 'GET', '/api/v1/endpoint/agent/download/' . $new->id)->assertNotFound();

        $this->assertNotSame($old->id, $new->id);
        $this->assertNotSame($armRel->id, $new->id);
    }
}
