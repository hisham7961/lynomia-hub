<?php

namespace Tests\Feature;

use App\Console\Commands\HubBackup;
use App\Models\InboundHook;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Models\WebauthnCredential;
use App\Support\ErrorLog;
use App\Support\Health;
use App\Support\SecurityPosture;
use App\Support\StepUp;
use App\Support\Webauthn;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * جولةُ التحصين الثالثة (v2.399) — الإعداداتُ والأسرار والواجهة والتكاملات الصغيرة:
 * CFG-02/05/06/07/08/09/12، FE-01/02/04، AUTH-06/10، INT-03/05، F-10/11.
 */
class EnterpriseHardeningRound3Test extends TestCase
{
    protected array $tmp = [];
    protected string $priv = '';
    protected array $cose = [];
    protected string $credId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $p) { @unlink($p); @rmdir($p); }
        parent::tearDown();
    }

    /* ═══════════════ الواجهة ═══════════════ */

    /** FE-01: عاملُ الخدمة لا يخبّئ صفحاتِ الرموز العامّة */
    public function test_service_worker_never_caches_public_token_pages(): void
    {
        $sw = (string) file_get_contents(public_path('sw.js'));
        preg_match('/var NEVER = \[([^\]]*)\]/', $sw, $m);
        $this->assertNotEmpty($m[1] ?? '');
        foreach (['/s/', '/sign/', '/verify', '/w/'] as $p) {
            $this->assertStringContainsString("'$p'", $m[1], "$p يُخبَّأ في المتصفح");
        }
    }

    /** FE-02: Leaflet من الأصل نفسه — لا سكربتَ طرفٍ ثالث بلا SRI */
    public function test_leaflet_is_served_from_the_same_origin(): void
    {
        $view = (string) file_get_contents(resource_path('views/field/route.blade.php'));
        $this->assertStringNotContainsString('cdnjs.cloudflare.com', $view);
        $this->assertStringContainsString("asset('vendor/leaflet/1.9.4/leaflet.min.js')", $view);
        $this->assertFileExists(public_path('vendor/leaflet/1.9.4/leaflet.min.js'));
        $this->assertFileExists(public_path('vendor/leaflet/1.9.4/leaflet.min.css'));
        $this->assertFileExists(public_path('vendor/leaflet/1.9.4/images/marker-icon.png'));
        $this->assertStringStartsWith('!function', (string) file_get_contents(public_path('vendor/leaflet/1.9.4/leaflet.min.js')));
        // ولا سكربتَ خارجياً في أي شاشة
        $ext = [];
        foreach (\Illuminate\Support\Facades\File::allFiles(resource_path('views')) as $f) {
            if (preg_match_all('/<script[^>]+src="https?:\/\/[^"]+"/', (string) file_get_contents($f->getPathname()), $mm)) {
                foreach ($mm[0] as $s) $ext[] = $f->getRelativePathname() . ': ' . $s;
            }
        }
        $this->assertSame([], $ext, 'سكربتاتٌ خارجية: ' . implode(' | ', $ext));
    }

    /** FE-04: الحقولُ المخصّصة موسومةٌ برمجياً، وأزرارُ الأيقونات لها اسمٌ مقروء */
    public function test_custom_fields_are_labelled_and_icon_buttons_are_named(): void
    {
        $this->hubSetting('custom.fields', json_encode(['tasks' => [['key' => 'cli', 'label' => 'عميل مخصص', 'type' => 'text']]], JSON_UNESCAPED_UNICODE));
        $html = $this->actingAs($this->owner)->get('/m/tasks/create')->assertOk()->getContent();
        $this->assertStringContainsString('for="cf-cli"', $html);
        $this->assertStringContainsString('id="cf-cli"', $html);

        $bare = [];
        foreach (\Illuminate\Support\Facades\File::allFiles(resource_path('views')) as $f) {
            $src = (string) file_get_contents($f->getPathname());
            if (preg_match_all('/<button(?![^>]*(?:aria-label|title)=)[^>]*>\s*[🗑⚙️✕✖]+\s*<\/button>/u', $src, $mm)) {
                foreach ($mm[0] as $b) $bare[] = $f->getRelativePathname();
            }
        }
        $this->assertSame([], array_values(array_unique($bare)), 'أزرارُ أيقوناتٍ بلا اسم: ' . implode('، ', array_unique($bare)));
    }

    /* ═══════════════ الأسرار والإعدادات ═══════════════ */

    /** CFG-06/ERR-08: رموزُ المشاركة ومساحاتِ العمل تُطمَس من سجل الأخطاء */
    public function test_public_tokens_are_redacted_from_error_log(): void
    {
        $tok = str_repeat('Ab3', 16);
        $this->assertStringNotContainsString($tok, ErrorLog::redact("https://hub.example/s/{$tok}/file?x=1"));
        $this->assertStringNotContainsString($tok, ErrorLog::redact("/w/{$tok}"));
        $this->assertStringContainsString('{رمز}', ErrorLog::redact("/s/{$tok}/file"));
        $this->assertSame('/m/tasks', ErrorLog::redact('/m/tasks'));
    }

    /** CFG-08: hub:set يشفّر مفاتيحَ الأسرار كما تفعل الشاشة */
    public function test_hub_set_encrypts_secret_keys(): void
    {
        $this->artisan('hub:set', ['key' => 'notify.tg_token', 'value' => '123456:ABCDEF-secret'])->assertExitCode(0);
        $raw = (string) DB::table('settings')->where('key', 'notify.tg_token')->value('value');
        $this->assertStringStartsWith('"enc:', trim($raw), 'التوكن خُزّن نصّاً صريحاً');
        $this->assertStringNotContainsString('ABCDEF-secret', $raw);
        $this->assertSame('123456:ABCDEF-secret', setting('notify.tg_token'));
        $this->artisan('hub:set', ['key' => 'app.name', 'value' => 'ليونوميا'])->assertExitCode(0);
        $this->assertSame('ليونوميا', setting('app.name'));
    }

    /** CFG-09: نشرُ cPanel يأخذ نسخةً قبل الترحيل ويفحص المخطّط بعده */
    public function test_cpanel_deploy_backs_up_before_migrating(): void
    {
        $y = (string) file_get_contents(base_path('.cpanel.yml'));
        $this->assertLessThan(strpos($y, 'php artisan migrate --force'), strpos($y, 'php artisan hub:backup'));
        $this->assertStringContainsString('hub:schema-check', $y);
    }

    /** CFG-05/12: وضعيةُ الأمان ترى كلمةَ المرور الافتراضية وAPP_DEBUG وحداثةَ النسخة */
    public function test_posture_flags_default_password_debug_mode_and_stale_backup(): void
    {
        $find = fn (string $k) => collect(SecurityPosture::checks())->firstWhere('key', $k);

        $this->assertSame('ok', $find('default_pw')['tone']);
        $ownerRole = Role::where('is_owner', true)->first();
        User::create(['name' => 'غيث', 'email' => 'owner2@lynomia.com', 'password' => 'ChangeMe!2026',
            'role_id' => $ownerRole->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $c = $find('default_pw');
        $this->assertSame('bad', $c['tone'], 'كلمةُ المرور المنشورة حيّةٌ ولا تُرى');
        $this->assertSame(1, $c['n']);

        config(['app.debug' => true, 'app.env' => 'production']);
        $this->assertSame('bad', $find('debug_mode')['tone']);
        config(['app.debug' => false, 'app.env' => 'production']);
        $this->assertSame('ok', $find('debug_mode')['tone']);
        config(['app.debug' => false, 'app.env' => 'testing']);
        $this->assertSame('wn', $find('debug_mode')['tone']);

        $this->assertSame('wn', $find('backup_fresh')['tone'], 'لا نسخةَ قطّ ولا تحذير');
        Health::beat('backup');
        $this->assertSame('ok', $find('backup_fresh')['tone']);
        Setting::updateOrCreate(['key' => 'heartbeat.backup'], ['value' => now()->subDays(4)->toIso8601String()]);
        \Illuminate\Support\Facades\Cache::forget('settings:all');
        $this->assertSame('bad', $find('backup_fresh')['tone']);
    }

    /** CFG-07: HUB_OUTBOUND=off يُطفئ كلَّ نداءٍ خارجيّ */
    public function test_outbound_kill_switch(): void
    {
        app()->instance('hub.dns', fn (string $h) => ['93.184.216.34']);
        $this->assertTrue(hub_outbound_ok('https://example.com/x')['ok']);
        config(['hub.outbound' => 'off']);
        $g = hub_outbound_ok('https://example.com/x');
        $this->assertFalse($g['ok']);
        $this->assertStringContainsString('HUB_OUTBOUND', $g['why']);
        Http::fake();
        $h = Webhook::create(['name' => 'n8n', 'url' => 'https://example.com/hook', 'secret' => 'whs_x', 'events' => '*', 'active' => true]);
        $d = WebhookDelivery::create(['webhook_id' => $h->id, 'event' => 'x', 'event_id' => (string) Str::uuid(), 'payload' => '{}', 'state' => 'queued', 'created_at' => now()]);
        \App\Support\WebhookDispatcher::send($d);
        Http::assertNothingSent();
    }

    /** CFG-02: النسخةُ تُشفَّر اختيارياً وتُتحقَّق بإعادة القراءة وتُستعاد بالمفتاح نفسِه */
    public function test_backup_can_be_encrypted_verified_and_restored(): void
    {
        $dir = storage_path('app/backups');
        is_dir($dir) || @mkdir($dir, 0700, true);
        foreach (array_merge(glob($dir . '/hub-*.json') ?: [], glob($dir . '/hub-*.json.enc') ?: []) as $f) @unlink($f);
        \App\Models\Task::create(['title' => 'مهمةٌ للنسخة', 'status' => 'جديدة']);

        $this->hubSetting('backup.encrypt', '1');
        $this->artisan('hub:backup', ['--verify' => true, '--keep' => 3])->assertExitCode(0);
        $files = glob($dir . '/hub-*.json.enc');
        $this->assertCount(1, $files, 'لا ملفَ مشفَّراً');
        $this->tmp[] = $files[0];
        $raw = (string) file_get_contents($files[0]);
        $this->assertStringNotContainsString('مهمةٌ للنسخة', $raw, 'النسخةُ «المشفَّرة» تحمل النصَّ صريحاً');
        $this->assertStringStartsNotWith('{', $raw);
        $this->assertSame([], glob($dir . '/hub-*.json') ?: []);

        $db = HubBackup::readFile($files[0]);
        $this->assertIsArray($db);
        $n = 0;
        foreach ($db as $k => $v) {
            if (in_array($k, ['_meta', 'roles', 'users', 'settings'], true)) continue;
            if ($k === '_tables') { foreach ((array) $v as $rows) $n += count((array) $rows); continue; }
            $n += count((array) $v);
        }
        $this->assertGreaterThan(0, $n);
        $this->assertNull(HubBackup::verifyFile($files[0], $n));
        $this->assertNotNull(HubBackup::verifyFile($files[0], $n + 1), 'التحقّق لا يرى عدّاً مخالفاً');

        \App\Models\Task::where('title', 'مهمةٌ للنسخة')->delete();
        $this->artisan('hub:import', ['file' => $files[0], '--truncate' => true])->assertExitCode(0);
        $this->assertTrue(\App\Models\Task::where('title', 'مهمةٌ للنسخة')->exists(), 'الاستعادةُ من نسخةٍ مشفَّرة لم تُعد السجل');

        // ملفٌ بمفتاحٍ آخر = رسالةٌ لا «0 سجل»
        file_put_contents($dir . '/hub-9999-01-01-0000.json.enc', 'garbage');
        $this->tmp[] = $dir . '/hub-9999-01-01-0000.json.enc';
        $this->artisan('hub:import', ['file' => $dir . '/hub-9999-01-01-0000.json.enc'])->assertExitCode(1);
    }

    /** F-10: زرُّ النسخة يقول «فشل» حين يفشل الأمر */
    public function test_backup_button_reports_failure_honestly(): void
    {
        $dir = storage_path('app/backups');
        is_dir($dir) || @mkdir($dir, 0700, true);
        $block = $dir . '/hub-' . now()->format('Y-m-d-Hi') . '.json.tmp';
        @mkdir($block, 0500);   // يحتلّ اسمَ الملف المؤقّت فتفشل الكتابة
        $this->tmp[] = $block;
        $res = $this->actingAs($this->owner)->post('/admin/ops/backup');
        @rmdir($block);
        $res->assertRedirect()->assertSessionHas('err');
        $this->assertStringContainsString('فشل', (string) session('err'));
    }

    /** F-11: الملخّصُ لا يُرسَل مرّتين في اليوم نفسه — و--force يتجاوز */
    public function test_digest_is_not_sent_twice_the_same_day(): void
    {
        Health::beat('digest');
        $this->artisan('hub:digest')->expectsOutputToContain('لا تكرار')->assertExitCode(0);
        $before = \App\Models\OutboxMessage::count();
        $this->artisan('hub:digest', ['--force' => true])->assertExitCode(0);
        $this->assertGreaterThanOrEqual($before, \App\Models\OutboxMessage::count());
    }

    /* ═══════════════ التكاملات ═══════════════ */

    /** INT-03: أسرارُ الويبهوك مشفَّرةٌ في القاعدة — والقديمُ الصريح يُقرأ، والتوقيعُ بالسرّ الحقيقي */
    public function test_webhook_secrets_are_encrypted_at_rest(): void
    {
        $h = Webhook::create(['name' => 'n8n', 'url' => 'https://example.com/hook', 'secret' => 'whs_plain_secret', 'events' => '*', 'active' => true]);
        $raw = (string) DB::table('webhooks')->where('id', $h->id)->value('secret');
        $this->assertNotSame('whs_plain_secret', $raw, 'السرُّ نصٌّ صريح في القاعدة');
        $this->assertSame('whs_plain_secret', $h->fresh()->secret);

        DB::table('webhooks')->where('id', $h->id)->update(['secret' => 'whs_legacy']);   // صفٌّ قديم صريح
        $this->assertSame('whs_legacy', Webhook::find($h->id)->secret);

        $i = InboundHook::create(['name' => 'استقبال', 'token' => Str::random(48), 'module' => 'clients', 'secret' => 'in_secret_value', 'enabled' => true, 'created_by' => $this->owner->id]);
        $this->assertNotSame('in_secret_value', (string) DB::table('inbound_hooks')->where('id', $i->id)->value('secret'));
        $this->assertSame('in_secret_value', $i->fresh()->secret);

        // التوقيعُ الصادر بالسرّ الحقيقي لا بالمشفَّر
        app()->instance('hub.dns', fn (string $x) => ['93.184.216.34']);
        Http::fake(['example.com/*' => Http::response('ok', 200)]);
        $d = WebhookDelivery::create(['webhook_id' => $h->id, 'event' => 'x', 'event_id' => (string) Str::uuid(), 'payload' => '{"a":1}', 'state' => 'queued', 'created_at' => now()]);
        \App\Support\WebhookDispatcher::send($d);
        Http::assertSent(fn ($req) => $req->header('X-Hub-Signature')[0] === 'sha256=' . hash_hmac('sha256', '{"a":1}', 'whs_legacy'));
    }

    /** INT-05: توقيعُ الوارد يُربط بالزمن حين يُرسَل X-Hub-Timestamp — والقديمُ بلا ترويسة يعمل */
    public function test_inbound_signature_can_be_bound_to_a_timestamp(): void
    {
        $hook = InboundHook::create(['name' => 'استقبال', 'token' => Str::random(48), 'module' => 'clients', 'secret' => 's3cret', 'enabled' => true, 'created_by' => $this->owner->id]);
        $body = '{"event":"x","n":1}';
        $sign = fn (string $data) => 'sha256=' . hash_hmac('sha256', $data, 's3cret');

        $this->call('POST', '/hook/' . $hook->token, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE' => $sign($body)], $body)->assertOk();

        $ts = (string) time();
        $this->call('POST', '/hook/' . $hook->token, [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_TIMESTAMP' => $ts, 'HTTP_X_HUB_SIGNATURE' => $sign($ts . '.' . $body)], $body)->assertOk();

        $old = (string) (time() - 900);
        $this->call('POST', '/hook/' . $hook->token, [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_TIMESTAMP' => $old, 'HTTP_X_HUB_SIGNATURE' => $sign($old . '.' . $body)], $body)->assertStatus(401);

        // طابعٌ حديث بتوقيع الجسم وحده (ملتقَطٌ أُلحق به طابع) = مرفوض
        $this->call('POST', '/hook/' . $hook->token, [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_TIMESTAMP' => $ts, 'HTTP_X_HUB_SIGNATURE' => $sign($body)], $body)->assertStatus(401);
    }

    /* ═══════════════ المصادقة والأسطح العامّة ═══════════════ */

    /** AUTH-10: كلمةُ سرّ رابط المشاركة ثمانيةُ أحرف فأكثر */
    public function test_share_link_password_has_a_minimum_length(): void
    {
        $this->actingAs($this->owner)->post('/dataroom', ['title' => 'ملف', 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), 'password' => '1234'])
            ->assertSessionHasErrors('password');
        $this->actingAs($this->owner)->post('/dataroom', ['title' => 'ملف', 'file' => UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'), 'password' => 'Strong-Pass-12'])
            ->assertSessionHasNoErrors();
    }

    /* ── WebAuthn helpers ── */
    protected function initPasskey(): void
    {
        config(['app.url' => 'http://localhost']);
        $pk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($pk, $this->priv);
        $d = openssl_pkey_get_details($pk);
        $this->cose = [1 => 2, 3 => -7, -1 => 1, -2 => str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT), -3 => str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT)];
        $this->credId = random_bytes(20);
    }
    protected function cbor($v): string
    {
        if (is_int($v)) { [$m, $n] = $v >= 0 ? [0, $v] : [1, -1 - $v]; return $this->hd($m, $n); }
        if (is_string($v)) return $this->hd(2, strlen($v)) . $v;
        if (is_array($v) && array_is_list($v)) { $o = $this->hd(4, count($v)); foreach ($v as $e) $o .= $this->cbor($e); return $o; }
        $o = $this->hd(5, count($v));
        foreach ($v as $k => $e) { $o .= is_int($k) ? $this->cbor($k) : ($this->hd(3, strlen($k)) . $k); $o .= $this->cbor($e); }
        return $o;
    }
    protected function hd(int $m, int $n): string
    {
        $b = $m << 5;
        if ($n < 24) return chr($b | $n);
        if ($n < 256) return chr($b | 24) . chr($n);
        return chr($b | 25) . pack('n', $n);
    }
    protected function authData(int $flags, int $count, bool $withCred = false): string
    {
        $d = hash('sha256', Webauthn::rpId(), true) . chr($flags) . pack('N', $count);
        if ($withCred) $d .= str_repeat("\0", 16) . pack('n', strlen($this->credId)) . $this->credId . $this->cbor($this->cose);
        return $d;
    }
    protected function clientData(string $type, string $challengeB64u): string
    {
        return json_encode(['type' => $type, 'challenge' => $challengeB64u, 'origin' => Webauthn::origin()], JSON_UNESCAPED_SLASHES);
    }
    protected function assertion(string $challengeB64u, int $count, int $flags = 0x05): array
    {
        $ad = $this->authData($flags, $count);
        $cd = $this->clientData('webauthn.get', $challengeB64u);
        openssl_sign($ad . hash('sha256', $cd, true), $sig, $this->priv, OPENSSL_ALGO_SHA256);
        return ['id' => Webauthn::b64uEncode($this->credId), 'clientDataJSON' => Webauthn::b64uEncode($cd),
            'authenticatorData' => Webauthn::b64uEncode($ad), 'signature' => Webauthn::b64uEncode($sig)];
    }

    /** AUTH-06: تصعيدُ المصادقة بمفتاح مرور يرفض إثباتَ الحضور وحده (UP بلا UV) */
    public function test_passkey_step_up_requires_user_verification(): void
    {
        $this->initPasskey();
        $opts = $this->actingAs($this->owner)->postJson('/passkey/register/options')->assertOk()->json();
        $cd = $this->clientData('webauthn.create', $opts['challenge']);
        $att = $this->cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => $this->authData(0x41, 0, true)]);
        $this->actingAs($this->owner)->postJson('/passkey/register/verify', ['clientDataJSON' => Webauthn::b64uEncode($cd),
            'attestationObject' => Webauthn::b64uEncode($att), 'label' => 'اختبار'])->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(1, WebauthnCredential::where('user_id', $this->owner->id)->count());

        $opts = $this->actingAs($this->owner)->postJson('/passkey/stepup/options')->assertOk()->json();
        $this->actingAs($this->owner)->postJson('/passkey/stepup/verify', $this->assertion($opts['challenge'], 5, 0x01))->assertStatus(422);
        $this->assertFalse(StepUp::fresh(), 'تصعيدٌ بلا تحقّقٍ من المستخدم');

        $opts = $this->actingAs($this->owner)->postJson('/passkey/stepup/options')->assertOk()->json();
        $this->actingAs($this->owner)->postJson('/passkey/stepup/verify', $this->assertion($opts['challenge'], 6, 0x05))->assertOk()->assertJson(['ok' => true]);
        $this->assertTrue(StepUp::fresh());
    }
}
