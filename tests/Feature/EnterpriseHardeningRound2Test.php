<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Attendance;
use App\Models\AuditEntry;
use App\Models\Client;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\ErrorEvent;
use App\Models\HubNotification;
use App\Models\OutboxMessage;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Role;
use App\Models\Setting;
use App\Models\ShareLink;
use App\Models\StockItem;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Models\WebauthnCredential;
use App\Support\Health;
use App\Support\Odoo;
use App\Support\Totp;
use App\Support\Watermark;
use App\Support\Webauthn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * جولةُ التحصين المؤسّسي الثانية (v2.399) — نتائجُ تدقيق API وسلامةِ البيانات والعمل الخلفيّ
 * والملفات وبقيّةِ المتوسّط/المنخفض من الجولة الأولى: API-01/05/09، DI-01/02/03/04، F-01/02/03/04،
 * AUTH-04/05، AUTHZ-06/07/08/09، INT-06/07، OPS-04/07/08، AUD-09/14، FS-01/02.
 * كلُّ اختبارٍ كُتب فاشلاً على الشجرة قبل الإصلاح ثم أُصلح حتى يخضرّ.
 */
class EnterpriseHardeningRound2Test extends TestCase
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
        foreach ($this->tmp as $p) @unlink($p);
        parent::tearDown();
    }

    protected function matrix(): array
    {
        return collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
    }

    protected function userWith(array $attrs, string $scope = 'all', array $flags = []): User
    {
        $role = Role::create(['name' => 'r' . Str::random(4), 'scope' => $scope, 'flags' => $flags, 'matrix' => $this->matrix()]);

        return User::create(['name' => 'u' . Str::random(4), 'email' => Str::random(8) . '@t.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()] + $attrs);
    }

    /* ═══════════════ API ═══════════════ */

    /** API-01: جولةُ GET ثم PUT بالجسم نفسِه لا تُصفّر الحقولَ التي يختلف مفتاحُها عن عمودها */
    public function test_api_get_then_put_roundtrip_keeps_every_field(): void
    {
        $tok = $this->apiToken($this->owner);
        $h = ['Authorization' => 'Bearer ' . $tok];
        $created = $this->withHeaders($h)->postJson('/api/v1/clients', [
            'name' => 'عميل الجولة', 'nextStep' => 'اتصال', 'lastTouch' => '2026-01-05', 'ownerId' => $this->employee->id,
        ]);
        $created->assertStatus(201);
        $id = $created->json('id') ?? $created->json('data.id');
        $this->assertNotEmpty($id);

        $body = $this->withHeaders($h)->getJson('/api/v1/clients/' . $id)->assertOk()->json();
        $row = $body['data'] ?? $body;
        $this->assertArrayHasKey('next_step', $row, 'القراءةُ تُخرج أسماءَ الأعمدة');

        $row['name'] = 'عميل الجولة ٢';
        $this->withHeaders($h)->putJson('/api/v1/clients/' . $id, $row)->assertOk();

        $db = DB::table('clients')->where('id', $id)->first();
        $this->assertSame('عميل الجولة ٢', $db->name);
        $this->assertSame('اتصال', $db->next_step, 'next_step صُفّر بجولة قراءة/كتابة موثّقة');
        $this->assertSame($this->employee->id, $db->owner_id, 'owner_id صُفّر');
        $this->assertSame('2026-01-05', substr((string) $db->last_touch, 0, 10), 'last_touch صُفّر');
    }

    /** API-05: حقلُ النصّ الطويل بسقف عمود TEXT — يُرفض برسالة لا يُكتب ثم يُرفض على MySQL */
    public function test_api_textarea_has_a_length_cap(): void
    {
        $tok = $this->apiToken($this->owner);
        $this->withHeaders(['Authorization' => 'Bearer ' . $tok])
            ->postJson('/api/v1/clients', ['name' => 'طويل', 'notes' => str_repeat('ن', 70000)])
            ->assertStatus(422)->assertJsonValidationErrors(['notes']);
        $this->assertNull(Client::where('name', 'طويل')->first());
    }

    /** API-09: نطاقاتُ الرمز وقائمةُ IP تُفحصان عند السكّ — لا مفتاحَ «يرفض كلَّ شيء» بصمت */
    public function test_api_token_scopes_and_ips_are_validated_at_minting(): void
    {
        $this->actingAs($this->owner)->post('/profile/token', ['tname' => 'x', 'tscopes' => 'taskz:v'])
            ->assertSessionHasErrors('tscopes');
        $this->actingAs($this->owner)->post('/profile/token', ['tname' => 'x', 'tscopes' => 'tasks:read'])
            ->assertSessionHasErrors('tscopes');
        $this->actingAs($this->owner)->post('/profile/token', ['tname' => 'x', 'tips' => '999.1.1.1'])
            ->assertSessionHasErrors('tips');
        $this->actingAs($this->owner)->post('/profile/token', ['tname' => 'x', 'tips' => '10.0.0.0/40'])
            ->assertSessionHasErrors('tips');
        $this->assertSame(0, \App\Models\ApiToken::where('user_id', $this->owner->id)->count());

        $this->actingAs($this->owner)->post('/profile/token', ['tname' => 'سليم', 'tscopes' => 'tasks:v, *:v', 'tips' => '10.0.0.0/8, 203.0.113.7'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, \App\Models\ApiToken::where('user_id', $this->owner->id)->count());
    }

    /* ═══════════════ سلامةُ البيانات ═══════════════ */

    /** DI-01: كمّيةُ المخزون لا تُسلَّب من نموذج التعديل العامّ */
    public function test_stock_quantity_cannot_go_negative_from_the_generic_form(): void
    {
        $item = StockItem::create(['name' => 'صنف', 'qty' => 3]);
        $this->actingAs($this->owner)->put('/m/stock/' . $item->id, ['name' => 'صنف', 'qty' => '-5'])
            ->assertSessionHasErrors('qty');
        $this->assertSame(3.0, (float) $item->fresh()->qty);
        $this->actingAs($this->owner)->put('/m/stock/' . $item->id, ['name' => 'صنف', 'qty' => '0'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0.0, (float) $item->fresh()->qty);
    }

    /** DI-02/03: الحضور — صفٌّ واحد لكل موظفٍ ويوم، والوقتُ بصيغة HH:MM لا نصّاً حرّاً */
    public function test_attendance_is_unique_per_employee_and_day_and_times_are_well_formed(): void
    {
        $emp = Employee::create(['name' => 'موظف']);
        $this->actingAs($this->owner)->post('/m/attend', ['empId' => $emp->id, 'date' => '2026-09-01', 'in' => '09:00'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post('/m/attend', ['empId' => $emp->id, 'date' => '2026-09-01', 'in' => '09:05'])
            ->assertSessionHasErrors('empId');
        $this->assertSame(1, Attendance::where('emp_id', $emp->id)->count(), 'صفّان لليوم نفسِه');

        $this->actingAs($this->owner)->post('/m/attend', ['empId' => $emp->id, 'date' => '2026-09-02', 'in' => 'صباحاً 9'])
            ->assertSessionHasErrors('in');
        $this->actingAs($this->owner)->post('/m/attend', ['empId' => $emp->id, 'date' => '2026-09-02', 'in' => '9:30', 'out' => '17:00'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Attendance::where('emp_id', $emp->id)->count());
    }

    /** DI-04: رقمُ العرض متفرّد — بالرسالة لا بفهرسٍ يسقط على بياناتٍ قائمة */
    public function test_quote_number_is_unique(): void
    {
        $c = Client::create(['name' => 'عميل']);
        $this->actingAs($this->owner)->post('/m/quotes', ['no' => 'QT-DUP-1', 'clientId' => $c->id])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post('/m/quotes', ['no' => 'QT-DUP-1', 'clientId' => $c->id])->assertSessionHasErrors('no');
        $this->assertSame(1, Quote::where('doc_no', 'QT-DUP-1')->count());
        // والتعديلُ على السجل نفسِه لا يصطدم برقمه هو
        $q = Quote::where('doc_no', 'QT-DUP-1')->first();
        $this->actingAs($this->owner)->put('/m/quotes/' . $q->id, ['no' => 'QT-DUP-1', 'clientId' => $c->id, 'title' => 'عنوان'])->assertSessionHasNoErrors();
    }

    /* ═══════════════ العملُ الخلفيّ ═══════════════ */

    /** F-02: رمزُ التوقيع الحسّاس زمنياً يُرسَل في الدفعة الأولى ولو وقف خلف ستّين تقريراً */
    public function test_time_sensitive_outbox_messages_go_first(): void
    {
        foreach (range(1, 60) as $i) {
            OutboxMessage::create(['kind' => 'digest', 'channel' => 'mail', 'target' => "u{$i}@x.test",
                'text' => 'تقرير', 'state' => 'queued', 'created_at' => now()->subMinutes(2)]);
        }
        $otp = OutboxMessage::create(['kind' => 'sign_otp', 'channel' => 'mail', 'target' => 'signer@x.test',
            'text' => 'رمز 123456', 'state' => 'queued', 'created_at' => now()]);
        $this->artisan('hub:outbox')->assertExitCode(0);
        $this->assertSame('sent', $otp->fresh()->state, 'رمزُ التوقيع انتظر خلف الطابور');
    }

    /** F-01: فشلٌ مؤقت يُعاد آلياً بتباعد (٥ → ٣٠ → ١٢٠ دقيقة) ثم يصير نهائياً — لا رسالةً ميتة من أول مرّة */
    public function test_outbox_retries_transient_failures_with_backoff(): void
    {
        Carbon::setTestNow('2026-09-02 09:00:00');
        $tg = OutboxMessage::create(['kind' => 'rule:x', 'channel' => 'tg', 'target' => '1',
            'text' => 'تنبيه', 'state' => 'queued', 'created_at' => now()]);   // بلا رمز بوت → يفشل

        $this->artisan('hub:outbox');
        $tg->refresh();
        $this->assertSame('queued', $tg->state, 'الفشلُ الأول صار نهائياً');
        $this->assertSame(1, (int) $tg->attempts);
        $this->assertSame(5, (int) round(now()->diffInMinutes($tg->next_at, false)));

        $this->artisan('hub:outbox');   // لم يحن موعدُها
        $this->assertSame(1, (int) $tg->fresh()->attempts);

        Carbon::setTestNow('2026-09-02 09:06:00');
        $this->artisan('hub:outbox');
        $tg->refresh();
        $this->assertSame(2, (int) $tg->attempts);
        $this->assertSame('queued', $tg->state);
        $this->assertSame(30, (int) round(now()->diffInMinutes($tg->next_at, false)));

        Carbon::setTestNow('2026-09-02 09:40:00');
        $this->artisan('hub:outbox');
        $tg->refresh();
        $this->assertSame(3, (int) $tg->attempts);
        $this->assertSame('failed', $tg->state, 'بعد ثلاث محاولات تصير نهائية');
        $this->assertNull($tg->next_at);

        // --retry يعيد العدّاد
        $this->artisan('hub:outbox', ['--retry' => true]);
        $this->assertSame(1, (int) $tg->fresh()->attempts);
        Carbon::setTestNow();
    }

    /** F-03: زرُّ الاختبار يرسل التجريبيةَ وحدَها — لا يجرف الطابورَ داخل طلب الويب */
    public function test_messaging_test_button_sends_only_the_test_message(): void
    {
        foreach (range(1, 3) as $i) {
            OutboxMessage::create(['kind' => 'sign_link', 'channel' => 'mail', 'target' => "s{$i}@x.test",
                'text' => 'رابط', 'state' => 'queued', 'created_at' => now()->subMinute()]);
        }
        $this->actingAs($this->owner)->post(route('integrations.messaging.test'), ['channel' => 'mail'])->assertRedirect();
        $this->assertSame(3, OutboxMessage::where('kind', 'sign_link')->where('state', 'queued')->count(), 'الزرُّ جرف الطابور');
        $this->assertSame('sent', OutboxMessage::where('kind', 'test')->orderByDesc('created_at')->orderByDesc('id')->value('state'));
    }

    /** F-04: مزلاجُ withoutOverlapping بمدّةٍ صريحة — لا يحبس الصادرَ يوماً كاملاً إن قُتل التشغيل */
    public function test_scheduler_mutexes_expire_quickly(): void
    {
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $find = fn (string $cmd) => $events->first(fn ($e) => str_contains((string) $e->command, $cmd));
        $this->assertSame(20, (int) $find('hub:outbox')->expiresAt);
        $this->assertSame(20, (int) $find('hub:uptime-check')->expiresAt);
        $this->assertSame(240, (int) $find('hub:backup')->expiresAt);
        $this->assertSame(240, (int) $find('hub:automation')->expiresAt);
    }

    /** OPS-08: أقفالُ المجدول في مجلدٍ لا يمسحه optimize:clear */
    public function test_cache_locks_live_outside_the_flushed_cache_directory(): void
    {
        $lock = (string) config('cache.stores.file.lock_path');
        $data = (string) config('cache.stores.file.path');
        $this->assertStringEndsWith('framework/cache/locks', $lock);
        $this->assertNotSame($data, $lock);
    }

    /** OPS-04: مفتاحُ الرجل الميّت — cron واقفٌ يُشعر الإدارةَ من طلب ويب، مرّةً في اليوم */
    public function test_watchdog_alerts_owners_when_no_scheduler_heartbeat_exists(): void
    {
        $this->hubSetting('ops.watchdog', '1');
        User::query()->update(['created_at' => now()->subHours(8)]);   // ليس تنصيباً حديثاً
        Cache::forget('health:watchdog');
        $count = fn () => HubNotification::where('user_id', $this->owner->id)->where('text', 'like', '%المجدولاتُ لا تنبض%')->count();

        $this->actingAs($this->owner)->get('/')->assertOk();
        $this->assertSame(1, $count(), 'لا إشعارَ عن cron واقف');
        $this->assertNotNull(Setting::where('key', 'heartbeat.watchdog_notified')->first());

        Cache::forget('health:watchdog');
        $this->actingAs($this->owner)->get('/')->assertOk();
        $this->assertSame(1, $count(), 'الإشعارُ تكرّر في اليوم نفسه');

        // النبضاتُ عادت — لا إشعارَ جديداً ولو مضى يوم
        foreach (array_keys(Health::JOBS) as $job) Health::beat($job);
        Setting::where('key', 'heartbeat.watchdog_notified')->delete();
        Cache::forget('settings:all');
        Cache::forget('health:watchdog');
        $this->actingAs($this->owner)->get('/')->assertOk();
        $this->assertSame(1, $count());
    }

    /** OPS-07: أفعالُ التشغيل عالية الأثر بتأكيد هوية — والإعدادُ يُطفئه */
    public function test_high_impact_ops_actions_require_step_up(): void
    {
        $this->hubSetting('security.stepup_ops', '1');
        $r = $this->actingAs($this->owner)->post('/admin/ops/maintenance');
        $r->assertRedirect();
        $this->assertStringContainsString('/stepup?next=', (string) $r->headers->get('Location'));
        $this->assertEmpty(setting('maintenance.on'), 'الصيانةُ تبدّلت بلا تأكيد هوية');

        $r = $this->actingAs($this->owner)->post('/admin/demo/reset');
        $this->assertStringContainsString('/stepup?next=', (string) $r->headers->get('Location'));
        $this->assertNull(Setting::where('key', 'demo.on')->first(), 'الوضعُ التجريبي صُفّر بلا تأكيد هوية');

        $this->actingAs($this->owner)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/admin/ops'])->assertRedirect('/admin/ops');
        $this->actingAs($this->owner)->post('/admin/ops/maintenance')->assertRedirect();
        $this->assertSame('1', (string) setting('maintenance.on'));
        $this->actingAs($this->owner)->post('/admin/ops/maintenance')->assertRedirect();
    }

    /* ═══════════════ المصادقة ═══════════════ */

    /** AUTH-04: رمزُ TOTP لا يُقبل مرّتين */
    public function test_totp_code_cannot_be_replayed(): void
    {
        $secret = Totp::secret();
        $this->owner->forceFill(['totp_enabled' => true, 'totp_secret_cipher' => $secret])->saveQuietly();
        $code = Totp::code($secret);

        $this->post('/login', ['email' => 'owner@test.local', 'password' => 'Secret!2026x'])->assertRedirect('/login/otp');
        $this->post('/login/otp', ['code' => $code])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->owner);
        $this->post('/logout');
        $this->assertGuest();

        $this->post('/login', ['email' => 'owner@test.local', 'password' => 'Secret!2026x'])->assertRedirect('/login/otp');
        $this->post('/login/otp', ['code' => $code]);   // الرمزُ نفسُه
        $this->assertGuest();
        $this->assertTrue(Totp::verify($secret, $code), 'الرمزُ نفسُه ما زال صالحاً حسابياً — لكنه مستعمَل');
    }

    /* ── مفاتيحُ المرور: مساعداتُ WebAuthn (مفتاحُ P-256 حقيقي وتوقيعٌ حقيقي) ── */
    protected function initPasskey(): void
    {
        config(['app.url' => 'http://localhost']);
        $pk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($pk, $this->priv);
        $d = openssl_pkey_get_details($pk);
        $x = str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $this->cose = [1 => 2, 3 => -7, -1 => 1, -2 => $x, -3 => $y];
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
    protected function registerFor($user): void
    {
        $opts = $this->actingAs($user)->postJson('/passkey/register/options')->assertOk()->json();
        $cd = $this->clientData('webauthn.create', $opts['challenge']);
        $att = $this->cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => $this->authData(0x41, 0, true)]);
        $this->actingAs($user)->postJson('/passkey/register/verify', [
            'clientDataJSON' => Webauthn::b64uEncode($cd),
            'attestationObject' => Webauthn::b64uEncode($att),
            'label' => 'اختبار',
        ])->assertOk()->assertJson(['ok' => true]);
    }
    protected function assertion(string $challengeB64u, int $count, int $flags = 0x05): array
    {
        $ad = $this->authData($flags, $count);
        $cd = $this->clientData('webauthn.get', $challengeB64u);
        openssl_sign($ad . hash('sha256', $cd, true), $sig, $this->priv, OPENSSL_ALGO_SHA256);
        return [
            'id' => Webauthn::b64uEncode($this->credId),
            'clientDataJSON' => Webauthn::b64uEncode($cd),
            'authenticatorData' => Webauthn::b64uEncode($ad),
            'signature' => Webauthn::b64uEncode($sig),
        ];
    }

    /** AUTH-05: مفتاحُ المرور يحترم انتهاءَ الحساب وقائمةَ IP كما يفعل بابُ كلمة المرور */
    public function test_passkey_login_honours_account_expiry_and_ip_allowlist(): void
    {
        $this->initPasskey();
        $this->registerFor($this->owner);
        $this->assertSame(1, WebauthnCredential::where('user_id', $this->owner->id)->count());
        auth()->logout();

        $this->owner->forceFill(['expires_at' => now()->subDay()->toDateString()])->saveQuietly();
        $opts = $this->postJson('/passkey/login/options')->assertOk()->json();
        $this->postJson('/passkey/login/verify', $this->assertion($opts['challenge'], 1))->assertStatus(403)->assertJson(['ok' => false]);
        $this->assertGuest();

        $this->owner->forceFill(['expires_at' => null, 'allowed_ips' => '10.9.9.9'])->saveQuietly();
        $opts = $this->postJson('/passkey/login/options')->assertOk()->json();
        $this->postJson('/passkey/login/verify', $this->assertion($opts['challenge'], 2))->assertStatus(403)->assertJson(['ok' => false]);
        $this->assertGuest();

        $this->owner->forceFill(['allowed_ips' => null])->saveQuietly();
        $opts = $this->postJson('/passkey/login/options')->assertOk()->json();
        $this->postJson('/passkey/login/verify', $this->assertion($opts['challenge'], 3))->assertOk()->assertJson(['ok' => true]);
        $this->assertAuthenticatedAs($this->owner);
    }

    /* ═══════════════ التخويل ═══════════════ */

    /** AUTHZ-06: استعادةُ نسخةٍ قديمة لا تُخرج السجلَّ من نطاق المستعيد */
    public function test_restoring_a_version_cannot_move_a_record_out_of_scope(): void
    {
        $ca = Company::create(['name_ar' => 'ألف']);
        $cb = Company::create(['name_ar' => 'باء']);
        $u = $this->userWith(['companies' => [$ca->id]]);
        $t = Ticket::create(['subject' => 'ت', 'company_id' => $cb->id]);   // النسخة ١ في باء
        $t->company_id = $ca->id; $t->save();                                  // النسخة ٢ في ألف

        $this->actingAs($u)->post("/m/tickets/{$t->id}/versions/1")->assertRedirect()->assertSessionHas('err');
        $this->assertSame($ca->id, $t->fresh()->company_id, 'السجلُّ خرج من النطاق باستعادة نسخة');
        // والمالكُ يستعيد كما كان
        $this->actingAs($this->owner)->post("/m/tickets/{$t->id}/versions/1")->assertRedirect()->assertSessionHas('ok');
        $this->assertSame($cb->id, $t->fresh()->company_id);
    }

    /** AUTHZ-07: شريحةُ الترشيح لا تُترجم معرّفَ عميلٍ أجنبيّ إلى اسمه */
    public function test_filter_chip_does_not_reveal_foreign_reference_names(): void
    {
        $a = Client::create(['name' => 'عميل ألف']);
        $b = Client::create(['name' => 'عميل باء CHIPB']);
        $u = $this->userWith(['clients' => [$a->id]]);
        $html = $this->actingAs($u)->get('/m/tickets?f[clientId]=' . $b->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('CHIPB', $html, 'الشريحةُ عرّافٌ لأسماء العملاء الأجانب');
        $html = $this->actingAs($u)->get('/m/tickets?f[clientId]=' . $a->id)->assertOk()->getContent();
        $this->assertStringContainsString('عميل ألف', $html);
    }

    /** AUTHZ-08: تسليمُ العهدة لا يقبل مشروعاً خارج نطاق المسلِّم */
    public function test_custody_handover_rejects_a_project_outside_scope(): void
    {
        $u = $this->userWith([], 'proj');
        $p1 = Project::create(['name' => 'مشروعي', 'manager_id' => $u->id]);
        $p2 = Project::create(['name' => 'أجنبي', 'manager_id' => $this->owner->id]);
        $asset = \App\Models\Asset::create(['name' => 'لابتوب', 'project_id' => $p1->id]);
        $this->actingAs($u)->post("/custody/{$asset->id}/handover", ['userId' => $this->employee->id, 'at' => now()->toDateString(), 'projectId' => $p2->id])
            ->assertSessionHasErrors('projectId');
        $this->assertNull(DB::table('asset_custody')->where('asset_id', $asset->id)->first());
        $this->actingAs($u)->post("/custody/{$asset->id}/handover", ['userId' => $this->employee->id, 'at' => now()->toDateString(), 'projectId' => $p1->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($p1->id, DB::table('asset_custody')->where('asset_id', $asset->id)->value('project_id'));
    }

    /** AUTHZ-09: تحويلُ تعليقٍ إلى مهمة يرث شركةَ السجل — فيرى المحوِّلُ المعزول مهمتَه */
    public function test_comment_to_task_inherits_the_company(): void
    {
        $ca = Company::create(['name_ar' => 'ألف']);
        $u = $this->userWith(['companies' => [$ca->id]]);
        $t = Ticket::create(['subject' => 'ت', 'company_id' => $ca->id]);
        $c = \App\Models\Comment::create(['module' => 'tickets', 'record_id' => $t->id, 'user_id' => $u->id, 'body' => 'حوّلني', 'created_at' => now()]);
        $this->actingAs($u)->post('/comments/' . $c->id . '/task')->assertRedirect();
        $task = Task::where('title', 'حوّلني')->first();
        $this->assertNotNull($task);
        $this->assertSame($ca->id, $task->company_id, 'المهمةُ يتيمةٌ بلا شركة');
        $this->actingAs($u)->get('/m/tasks/' . $task->id)->assertOk();
    }

    /** AUD-14: قيدُ التدقيق بلا جلسةِ شركة (API/console) يُنسب لشركة المعزول الوحيدة */
    public function test_manual_audit_without_session_company_falls_back_to_the_users_only_company(): void
    {
        $ca = Company::create(['name_ar' => 'ألف']);
        $u = $this->userWith(['companies' => [$ca->id]]);
        $this->actingAs($u);
        $row = hub_audit('فحص', 'tasks', null, 'x');
        $this->assertSame($ca->id, $row->company_id);
    }

    /** AUD-09: رفضُ API وطردُ الجلسة يُريان في رادار الأمان */
    public function test_api_rejections_and_session_kicks_are_recorded_in_the_radar(): void
    {
        $before = (int) DB::table('access_denials')->count();
        $this->withHeaders(['Authorization' => 'Bearer lyn_invalidinvalidinvalidinvalidinvalid'])->getJson('/api/v1/me')->assertStatus(401);
        $this->assertSame($before + 1, (int) DB::table('access_denials')->count(), 'مفتاحٌ باطل لا يُسجَّل');

        $this->employee->forceFill(['allowed_ips' => '203.0.113.0/24'])->saveQuietly();
        $this->actingAs($this->employee)->get('/')->assertRedirect('/login');
        $this->assertSame($before + 2, (int) DB::table('access_denials')->count(), 'طردُ الجلسة لا يُسجَّل');
    }

    /* ═══════════════ التكاملات ═══════════════ */

    /** INT-06: ردُّ 5xx من أودو يفتح القاطع — لا طرقَ لكل قناةٍ على خادمٍ متعثّر */
    public function test_odoo_5xx_opens_the_circuit_breaker(): void
    {
        Http::fake(['odoo.example.com/*' => Http::response('bad gateway', 502)]);
        app()->instance('hub.dns', fn (string $h) => ['93.184.216.34']);
        $c = \App\Models\OdooConnection::create(['name' => 'x', 'url' => 'https://odoo.example.com',
            'db' => 'd', 'username' => 'u', 'key_cipher' => 'k', 'active' => true]);
        $cli = Odoo::for($c);
        $msgs = [];
        foreach ([1, 2, 3] as $i) {
            try { $cli->serverVersion(); } catch (\Throwable $e) { $msgs[] = $e->getMessage(); }
        }
        Http::assertSentCount(1);
        $this->assertCount(3, $msgs);
        $this->assertStringContainsString('502', $msgs[0]);
    }

    /** INT-07: الإعادةُ اليدوية للويبهوك تحترم التعطيلَ والإيقاف وتحجز التسليم */
    public function test_manual_webhook_resend_respects_subscription_state(): void
    {
        $this->actingAs($this->owner);
        Http::fake(['example.com/*' => Http::response('ok', 200)]);
        app()->instance('hub.dns', fn (string $h) => ['93.184.216.34']);
        $mk = fn (array $over) => Webhook::create($over + ['name' => 'n8n', 'url' => 'https://example.com/hook', 'secret' => 'whs_x', 'events' => '*', 'active' => true]);
        $dl = fn (Webhook $h) => WebhookDelivery::create(['webhook_id' => $h->id, 'event' => 'projects.created', 'event_id' => (string) Str::uuid(),
            'payload' => '{"a":1}', 'state' => 'failed', 'created_at' => now()]);

        $off = $mk(['active' => false]);
        $d = $dl($off);
        $this->post(route('webhooks.resend', [$off->id, $d->id]))->assertSessionHas('err');
        $paused = $mk(['paused_until' => now()->addHour(), 'fail_streak' => 10]);
        $d2 = $dl($paused);
        $this->post(route('webhooks.resend', [$paused->id, $d2->id]))->assertSessionHas('err');
        Http::assertNothingSent();
        $this->assertSame('failed', $d->fresh()->state);

        $live = $mk([]);
        $d3 = $dl($live);
        $this->post(route('webhooks.resend', [$live->id, $d3->id]))->assertSessionHas('ok');
        $this->assertSame('sent', $d3->fresh()->state);
        // والمُرسَل لا يُعاد ثانيةً بالزرّ نفسه
        $this->post(route('webhooks.resend', [$live->id, $d3->id]))->assertSessionHas('err');
        Http::assertSentCount(1);
    }

    /* ═══════════════ الملفات ═══════════════ */

    protected const PDF_CLASSIC = 'JVBERi0xLjQKMSAwIG9iago8PCAvVHlwZSAvQ2F0YWxvZyAvUGFnZXMgMiAwIFIgPj4KZW5kb2JqCjIgMCBvYmoKPDwgL1R5cGUgL1BhZ2VzIC9LaWRzIFszIDAgUl0gL0NvdW50IDEgPj4KZW5kb2JqCjMgMCBvYmoKPDwgL1R5cGUgL1BhZ2UgL1BhcmVudCAyIDAgUiAvTWVkaWFCb3ggWzAgMCAyMDAgMjAwXSA+PgplbmRvYmoKeHJlZgowIDQKMDAwMDAwMDAwMCA2NTUzNSBmIAowMDAwMDAwMDA5IDAwMDAwIG4gCjAwMDAwMDAwNTggMDAwMDAgbiAKMDAwMDAwMDExNSAwMDAwMCBuIAp0cmFpbGVyCjw8IC9TaXplIDQgL1Jvb3QgMSAwIFIgPj4Kc3RhcnR4cmVmCjE4NgolJUVPRgo=';
    protected const PDF_XREF_STREAM = 'JVBERi0xLjUKMSAwIG9iago8PCAvVHlwZSAvQ2F0YWxvZyAvUGFnZXMgMiAwIFIgPj4KZW5kb2JqCjIgMCBvYmoKPDwgL1R5cGUgL1BhZ2VzIC9LaWRzIFszIDAgUl0gL0NvdW50IDEgPj4KZW5kb2JqCjMgMCBvYmoKPDwgL1R5cGUgL1BhZ2UgL1BhcmVudCAyIDAgUiAvTWVkaWFCb3ggWzAgMCAyMDAgMjAwXSA+PgplbmRvYmoKNCAwIG9iago8PCAvVHlwZSAvWFJlZiAvU2l6ZSA1IC9XIFsxIDIgMV0gL1Jvb3QgMSAwIFIgL0xlbmd0aCAyMCA+PgpzdHJlYW0KAAAAAAEACQABADoAAQBzAAEAugAKZW5kc3RyZWFtCmVuZG9iagpzdGFydHhyZWYKMTg2CiUlRU9GCg==';

    protected function pdfFile(string $b64): string
    {
        $dir = storage_path('app/dataroom'); is_dir($dir) || @mkdir($dir, 0755, true);
        $rel = 'dataroom/hr2_' . Str::random(10) . '.pdf';
        file_put_contents(storage_path('app/' . $rel), base64_decode($b64));
        $this->tmp[] = storage_path('app/' . $rel);

        return $rel;
    }

    /** FS-01: رابطُ «عرض فقط» على PDF بجدولٍ مضغوط يجيب بسببٍ مسمّى (415) لا ٥٠٠ — والكلاسيكيُّ يُوسم */
    public function test_view_only_share_link_explains_unsupported_pdf_instead_of_500(): void
    {
        $xref = $this->pdfFile(self::PDF_XREF_STREAM);
        $classic = $this->pdfFile(self::PDF_CLASSIC);
        $this->assertFalse(Watermark::pdfSupported(storage_path('app/' . $xref)));
        $this->assertTrue(Watermark::pdfSupported(storage_path('app/' . $classic)));

        $errs = ErrorEvent::count();
        $l = ShareLink::create(['token' => Str::random(48), 'title' => 'wm', 'path' => $xref, 'mime' => 'application/pdf',
            'no_download' => true, 'created_by' => $this->owner->id, 'created_at' => now()]);
        $res = $this->get("/s/{$l->token}/file");
        $res->assertStatus(415);
        $this->assertStringContainsString('PDF', (string) $res->getContent());
        $this->assertSame($errs, ErrorEvent::count(), 'فشلٌ متوقَّع سُجّل عطلاً');
        $this->assertStringNotContainsString('%PDF', (string) $res->getContent(), 'الأصلُ النظيف بُثّ لرابطٍ وُعد بالوسم');

        $l2 = ShareLink::create(['token' => Str::random(48), 'title' => 'wm2', 'path' => $classic, 'mime' => 'application/pdf',
            'no_download' => true, 'created_by' => $this->owner->id, 'created_at' => now()]);
        $ok = $this->get("/s/{$l2->token}/file");
        $ok->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $ok->getContent());
    }

    /** FS-02: وصولُ المصنَّف يُدوَّن في المعاينة والحزمة كما في التنزيل */
    public function test_classified_access_is_audited_for_preview_and_zip(): void
    {
        $doc = Document::create(['name' => 'سري جداً', 'secrecy' => 'سري']);
        Storage::disk('local')->put('hub/att/hr2-cls.pdf', base64_decode(self::PDF_CLASSIC));
        $this->tmp[] = storage_path('app/hub/att/hr2-cls.pdf');
        $a = Attachment::create(['module' => 'files', 'record_id' => $doc->id, 'disk' => 'local',
            'path' => 'hub/att/hr2-cls.pdf', 'original_name' => 'c.pdf', 'mime' => 'application/pdf', 'size' => 10, 'uploaded_by' => $this->owner->id]);
        $count = fn () => AuditEntry::where('action', 'وصول لبيانات مصنَّفة')->where('record_id', $doc->id)->count();

        $this->actingAs($this->owner)->get("/attachments/{$a->id}/view")->assertOk();
        $this->assertSame(1, $count(), 'المعاينةُ بلا أثر');
        if (class_exists(\ZipArchive::class)) {
            $this->actingAs($this->owner)->get("/attachments/files/{$doc->id}/zip")->assertOk();
            $this->assertSame(2, $count(), 'الحزمةُ بلا أثر');
        }
        $this->actingAs($this->owner)->get("/attachments/{$a->id}/dl")->assertOk();
        $this->assertGreaterThanOrEqual(2, $count());
    }
}
