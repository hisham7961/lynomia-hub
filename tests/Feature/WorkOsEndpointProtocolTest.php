<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EndpointCommand;
use App\Models\EndpointDevice;
use App\Models\EndpointEvent;
use App\Models\EndpointPolicy;
use App\Models\Role;
use App\Models\User;
use App\Support\Es256;
use App\Support\HubEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **بروتوكول النقاط الطرفية الموقَّع** (Work OS · الطور J · WP-J.2 · §43):
 * heartbeat + أحداثٌ (خصوصيّةٌ مفروضةٌ خادمياً) + أوامرُ registry صارمة +
 * سياساتُ USB رصديّة.
 *
 * يمتدّ هذا الملفُّ سوابقَه المعلنة:
 *  • `WorkOsEndpointEnrollTest` — عتادُ التسجيل الحقيقيّ (mint + enroll بزوج
 *    P-256 يولَّد بـopenssl) وعقدُ التوقيع (docblock ‏`App\Support\Es256`).
 *  • `InboundHookRound5Test` — انضباطُ replay الواحد: طابعٌ ±300ث + nonce فريد.
 *  • `HubOutbox` (آلةُ الحالة) — ادّعاءُ الأمر UPDATE مشروطاً فلا ازدواجَ إرسال.
 *  • `ColumnFitsItsWriterTest` — قيَمُ allowlist تسع أعمدتَها على المحرّكين.
 *
 * القواعدُ الصلبة (المواصفة §43 + النقد C10/C15 — غيرُ قابلةٍ للتفاوض):
 *  ١) الإعادةُ مرفوضة: nonce معاد 409، طابعٌ قديم 401، توقيعٌ مزوَّر 401 —
 *     **قبل** أيّ منطقِ معالج.
 *  ٢) الخصوصيّةُ تُفرَض خادمياً: حمولةٌ تحمل حقولَ مراقبة (keystrokes/screenshot/
 *     clipboard/browsing/file_content…) تُرَدّ 422 مسجَّلةً ولا يُخزَّن منها حرف.
 *  ٣) الأوامرُ قائمةُ سماحٍ مغلقة (٥ أنواع — لا shell)؛ isolate/lock تصعيدُ
 *     هويةٍ + سببٌ إلزاميّ + أثرُ تدقيق؛ ولا ازدواجَ إرسالٍ تحت claim متزامن.
 *  ٤) سياساتُ USB تُولد enforce=false («رصدٌ فقط») — والفرضُ الحقيقيّ يتطلب
 *     إقرارَ MDM صريحاً لا قلبَ راية صامتاً (C15).
 */
class WorkOsEndpointProtocolTest extends TestCase
{
    /* ───────────────────── العتاد المشترك ───────────────────── */

    protected function withStepup()
    {
        return $this->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);
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
    protected function device(?Company $c = null): array
    {
        $c ??= Company::create(['name_ar' => 'شركة ألف']);
        $this->actingAs($this->owner)->withStepup()
            ->post(route('enroll.mint'), ['companyId' => $c->id])->assertSessionHas('enroll_token');
        $plain = (string) session('enroll_token');

        [$priv, $pub] = $this->keypair();
        $resp = $this->postJson('/api/v1/endpoint/enroll', [
            'token' => $plain, 'device_uuid' => (string) Str::uuid(),
            'hostname' => 'LT-PROTO-01', 'os' => 'windows', 'public_key' => $pub,
        ]);
        $resp->assertStatus(201);

        // بالمعرّف من الردّ لا بترتيب created_at — جهازان في الثانية نفسِها قرعةٌ (درسُ CLAUDE.md)
        return [EndpointDevice::findOrFail((string) $resp->json('device_id')), $priv];
    }

    /** طلبٌ موقَّعٌ بعقد Es256 الحرفيّ نحو مسارٍ حقيقيّ خلف الوسيط */
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

    /** حسابُ عميلٍ صلب — نمطُ WorkOsEndpointEnrollTest::clientUser حرفياً */
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

    /* ────────── ① replay على المسارات الحقيقية: معادٌ 409 · قديمٌ 401 · مزوَّرٌ 401 ────────── */

    public function test_replay_stale_and_forged_requests_are_rejected_before_any_handler(): void
    {
        $this->seedCore();
        [$d, $priv] = $this->device();

        // الصحيحُ يمرّ ويحدّث النبضة
        $this->signed($d, $priv, '/api/v1/endpoint/heartbeat', ['agent_version' => '1.1.0'], ['nonce' => 'n-hb-000001'])
            ->assertOk();
        $this->assertNotNull($d->fresh()->last_heartbeat_at, 'النبضةُ لم تُسجَّل');

        // الإعادةُ بحذافيرها (nonce معاد): 409 — ولا نبضةَ ثانية
        $before = (string) $d->fresh()->last_heartbeat_at;
        $this->signed($d, $priv, '/api/v1/endpoint/heartbeat', ['agent_version' => '1.1.0'], ['nonce' => 'n-hb-000001'])
            ->assertStatus(409);

        // طابعٌ قديم (> ±300ث): 401
        $this->signed($d, $priv, '/api/v1/endpoint/heartbeat', [], ['ts' => time() - 301])->assertStatus(401);

        // توقيعٌ بمفتاحٍ آخر: 401 — ولا حدثَ يُبتلع
        [$otherPriv] = $this->keypair();
        $this->signed($d, $otherPriv, '/api/v1/endpoint/event',
            ['kind' => 'usb', 'summary' => 'قرصٌ مجهول'])->assertStatus(401);
        $this->assertSame(0, EndpointEvent::count(), 'حدثٌ من توقيعٍ مزوَّرٍ خُزّن');
    }

    /* ────────── ② الخصوصيّةُ تُفرَض خادمياً: 422 ولا يُخزَّن حرف ────────── */

    public function test_the_privacy_validator_rejects_surveillance_payloads_and_nothing_lands(): void
    {
        $this->seedCore();
        [$d, $priv] = $this->device();

        // حقولُ المراقبة — بالمفتاح المباشر وبالمفتاح المتشعّب — كلُّها 422
        $spy = [
            ['kind' => 'agent', 'summary' => 'x', 'meta' => ['keystrokes' => 'abc']],
            ['kind' => 'agent', 'summary' => 'x', 'meta' => ['nested' => ['screenshot' => 'data:image']]],
            ['kind' => 'agent', 'summary' => 'x', 'meta' => ['clipboard' => 'secret']],
            ['kind' => 'agent', 'summary' => 'x', 'meta' => ['browsing_history' => ['a.com']]],
            ['kind' => 'agent', 'summary' => 'x', 'meta' => ['files' => [['file_content' => 'PDF...']]]],
            ['kind' => 'agent', 'summary' => 'x', 'meta' => ['keylog_buffer' => '...']],
            ['kind' => 'agent', 'summary' => 'x', 'meta' => ['microphone' => 'on']],
        ];
        foreach ($spy as $payload) {
            $this->signed($d, $priv, '/api/v1/endpoint/event', $payload)->assertStatus(422);
        }
        $this->assertSame(0, EndpointEvent::count(), 'حمولةُ مراقبةٍ بلغت endpoint_events');
        $this->assertSame(0, DB::table('endpoint_events')->count());

        // وheartbeat كذلك: جردُ عتادٍ يحمل مفتاحَ مراقبة يُرَدّ ولا يلمس صفَّ الجهاز
        $this->signed($d, $priv, '/api/v1/endpoint/heartbeat',
            ['hw' => ['cpu' => 'i7', 'camera_capture' => 'jpeg...']])->assertStatus(422);
        $this->assertNull($d->fresh()->hw, 'عتادٌ بحقل مراقبةٍ خُزّن رغم الرفض');

        // والحمولةُ النظيفة تمرّ بعد كل ذلك — الرفضُ انتقائيٌّ لا شامل
        $this->signed($d, $priv, '/api/v1/endpoint/event',
            ['kind' => 'usb', 'summary' => 'وُصل قرصُ تخزينٍ مجهول', 'meta' => ['vendor_id' => '0x1234']])
            ->assertStatus(201);
        $this->assertSame(1, EndpointEvent::count());
    }

    /* ────────── ③ الابتلاع: kind قائمةُ سماح + ملخّصٌ منقّحٌ مقصوص + أحداثُ flow ────────── */

    public function test_event_ingest_enforces_the_kind_allowlist_and_sanitizes_summaries(): void
    {
        $this->seedCore();
        [$d, $priv] = $this->device();

        // نوعٌ غيرُ مُدرَج: 422 ولا صفّ (allowlist تطبيقيّ لا DB enum — C10)
        $this->signed($d, $priv, '/api/v1/endpoint/event',
            ['kind' => 'spyware', 'summary' => 'x'])->assertStatus(422);
        $this->assertSame(0, EndpointEvent::count());

        $fired = [];
        HubEvents::listen(function ($e) use (&$fired) { $fired[] = $e; });
        try {
            // ملخّصٌ بمحارفِ تحكّمٍ وطولٍ فائض: يُنقّى ويُقصّ إلى ٤٠٠ محرفاً
            $dirty = "قرصُ USB\x00\x1B مجهول " . str_repeat('م', 500);
            $this->signed($d, $priv, '/api/v1/endpoint/event',
                ['kind' => 'usb', 'severity' => 'high', 'summary' => $dirty])->assertStatus(201);

            $e = EndpointEvent::firstOrFail();
            $this->assertLessThanOrEqual(400, mb_strlen((string) $e->summary), 'الملخّصُ لم يُقصّ إلى عرض عموده');
            $this->assertStringNotContainsString("\x00", (string) $e->summary, 'محرفُ تحكّمٍ نجا من التنقيح');
            $this->assertSame($d->company_id, $e->company_id, 'الحدثُ لم يُسنَد لشركة جهازه');
            $this->assertNotSame('', (string) $e->nonce, 'الحدثُ بلا nonce طلبِه الموقَّع');
            $this->assertContains('endpoint.usb_event', $fired, 'حدثُ endpoint.usb_event لم يُبَثّ');

            // وحدثُ وضعيّةٍ عالي الشدّة يبثّ endpoint.posture_alert
            $this->signed($d, $priv, '/api/v1/endpoint/event',
                ['kind' => 'posture', 'severity' => 'high', 'summary' => 'أُطفئ جدارُ الحماية'])->assertStatus(201);
            $this->assertContains('endpoint.posture_alert', $fired, 'حدثُ endpoint.posture_alert لم يُبَثّ');
        } finally {
            HubEvents::forgetListeners();
        }
    }

    /* ────────── ④ heartbeat: وضعيّةٌ صادقة + مقاييسُ عبر المخزن الواحد + قارئُ الإعداد ────────── */

    public function test_heartbeat_stores_honest_posture_and_writes_metrics_via_the_single_store(): void
    {
        $this->seedCore();
        $this->hubSetting('endpoint.heartbeat_interval_min', '7');
        [$d, $priv] = $this->device();

        $resp = $this->signed($d, $priv, '/api/v1/endpoint/heartbeat', [
            'agent_version' => '1.2.0',
            'posture' => [
                'defender' => 'active',
                'firewall' => 'denied-by-os',    // قراءةٌ منعها النظام
                'bitlocker' => 'ACTIVE!!',        // ادّعاءٌ خارج القائمة
                'filevault' => 'inactive',
            ],
        ]);
        $resp->assertOk()->assertJsonPath('interval_min', 7);   // القارئُ الحقيقيّ للإعداد

        $p = $d->fresh()->posture;
        $this->assertSame('active', $p['defender']);
        $this->assertSame('inactive', $p['filevault']);
        // C15: المرفوضُ والمجهول 'not-configured' — **أبداً** لا 'active'
        $this->assertSame('not-configured', $p['firewall'], 'قراءةٌ منعها النظامُ لم تُخزَّن not-configured');
        $this->assertSame('not-configured', $p['bitlocker'], 'ادّعاءٌ خارج القائمة قُبل');

        // المقاييسُ في metric_points عبر hub_metric_put — لا مخزنَ ثانياً
        $this->assertGreaterThan(0, \App\Models\MetricPoint::where('module', 'endpoints')
            ->where('record_id', $d->id)->where('metric', 'heartbeat')->count(),
            'نبضةُ الأسطول لم تُكتب في المخزن الواحد metric_points');
        $this->assertSame(1.0, (float) \App\Models\MetricPoint::where('module', 'endpoints')
            ->where('record_id', $d->id)->where('metric', 'posture_ok')->orderByDesc('at')->orderByDesc('id')
            ->value('value'), 'عدّادُ الوضعيّة الفعّالة لا يعكس القراءاتِ الصادقة (defender وحدَه active — ١ من ٤)');
    }

    /* ────────── ⑤ الأوامر: قائمةٌ مغلقة، isolate = تصعيدٌ + سبب + أثر ────────── */

    public function test_command_types_are_a_closed_allowlist_and_isolate_needs_stepup_reason_and_audit(): void
    {
        $this->seedCore();
        [$d] = $this->device();

        // نوعٌ غيرُ مُدرَج (لا مسارَ shell إطلاقاً): 422 ولا صفّ
        $this->actingAs($this->owner)->withStepup()
            ->postJson(route('endpoints.command', $d->id), ['type' => 'run_shell', 'args' => ['cmd' => 'rm -rf /']])
            ->assertStatus(422);
        $this->assertSame(0, EndpointCommand::count(), 'أمرٌ خارج القائمة أُنشئ');

        // isolate بتصعيدٍ لكن بلا سبب: 422
        $this->actingAs($this->owner)->withStepup()
            ->postJson(route('endpoints.command', $d->id), ['type' => 'isolate'])
            ->assertStatus(422);

        // isolate بسببٍ لكن بلا تصعيد: 428 ولا صفّ (الجلسةُ تُفرَغ من ختم التصعيد)
        $this->flushSession();
        $this->actingAs($this->owner)
            ->postJson(route('endpoints.command', $d->id), ['type' => 'isolate', 'reason' => 'جهازٌ مصاب'])
            ->assertStatus(428);
        $this->assertSame(0, EndpointCommand::count(), 'أمرُ عزلٍ أُنشئ دون تصعيد هوية');

        // مكتملُ الشروط: يُنشأ pending ويُدقَّق بسببه
        $this->actingAs($this->owner)->withStepup()
            ->postJson(route('endpoints.command', $d->id), ['type' => 'isolate', 'reason' => 'جهازٌ مصاب'])
            ->assertStatus(201);
        $cmd = EndpointCommand::firstOrFail();
        $this->assertSame('pending', $cmd->state);
        $this->assertSame('جهازٌ مصاب', $cmd->reason);
        $this->assertSame((string) $this->owner->id, (string) $cmd->by_id);
        $this->assertGreaterThan(0, DB::table('audits')->where('module', 'endpoints')
            ->where('record_id', $cmd->id)->count(), 'أمرُ العزل بلا قيدِ تدقيق');

        // refresh_inventory أمرٌ قرائيّ: بلا تصعيدٍ يمرّ — القائمةُ تفرّق الخطرَ من البريء
        $this->flushSession();
        $this->actingAs($this->owner)
            ->postJson(route('endpoints.command', $d->id), ['type' => 'refresh_inventory'])
            ->assertStatus(201);

        // موظفٌ بلا رايةِ مراقب: 403 · وحسابُ عميل: 404 فوق كل شيء
        $this->actingAs($this->employee)->withStepup()
            ->postJson(route('endpoints.command', $d->id), ['type' => 'refresh_posture'])->assertStatus(403);
        $this->actingAs($this->clientUser())->withStepup()
            ->postJson(route('endpoints.command', $d->id), ['type' => 'refresh_posture'])->assertStatus(404);
    }

    /* ────────── ⑥ عبرَ شركةٍ: ٤٠٤ على إصدار الأوامر ────────── */

    public function test_cross_company_command_issuing_is_a_404(): void
    {
        $this->seedCore();
        [$d] = $this->device();   // شركة ألف

        $b = Company::create(['name_ar' => 'شركة باء']);
        $monRole = Role::create(['name' => 'مراقب باء', 'scope' => 'all',
            'flags' => ['monitor' => 1],
            'matrix' => collect(array_keys(config('hub.modules')))
                ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all()]);
        $bUser = User::create(['name' => 'مراقبُ باء', 'email' => 'mon-b@proto.local',
            'password' => 'Secret!2026x', 'role_id' => $monRole->id, 'status' => 'نشط',
            'companies' => [$b->id], 'password_changed_at' => now()]);

        $this->actingAs($bUser)->withStepup()
            ->postJson(route('endpoints.command', $d->id), ['type' => 'refresh_inventory'])
            ->assertNotFound();
        $this->assertSame(0, EndpointCommand::count(), 'أمرٌ صدر عبرَ شركةٍ');
    }

    /* ────────── ⑦ لا ازدواجَ إرسال: claim ذرّيّ + ikey idempotent ────────── */

    public function test_concurrent_pulls_never_double_claim_and_the_ikey_is_idempotent(): void
    {
        $this->seedCore();
        [$d, $priv] = $this->device();

        // إصدارٌ بمفتاح idempotency صريح — والإصدارُ الثاني بالمفتاح نفسِه لا يكرّر
        $this->actingAs($this->owner)
            ->postJson(route('endpoints.command', $d->id), ['type' => 'refresh_inventory', 'ikey' => 'ik-repeat-1'])
            ->assertStatus(201);
        $this->actingAs($this->owner)
            ->postJson(route('endpoints.command', $d->id), ['type' => 'refresh_inventory', 'ikey' => 'ik-repeat-1'])
            ->assertOk();
        $this->assertSame(1, EndpointCommand::count(), 'مفتاحُ idempotency واحد أنشأ أمرين');
        $cmdId = (string) EndpointCommand::first()->id;

        // سحبان متتابعان (nonces مختلفة): الأمرُ يظهر في أحدهما فقط — لا ازدواجَ إرسال
        $r1 = $this->signed($d, $priv, '/api/v1/endpoint/commands/pull');
        $r2 = $this->signed($d, $priv, '/api/v1/endpoint/commands/pull');
        $r1->assertOk();
        $r2->assertOk();
        $ids1 = collect($r1->json('commands'))->pluck('id')->all();
        $ids2 = collect($r2->json('commands'))->pluck('id')->all();
        $this->assertSame([$cmdId], $ids1, 'السحبُ الأول لم يدّعِ الأمر');
        $this->assertSame([], $ids2, 'السحبُ الثاني ادّعى أمراً مُدَّعى — ازدواجُ إرسال');
        $this->assertSame('claimed', EndpointCommand::first()->state);

        // والادّعاءُ المشروط نفسُه تحت سباقٍ مباشر: محاولتا ادّعاءٍ على الصفّ = واحدةٌ تفوز
        EndpointCommand::whereKey($cmdId)->update(['state' => 'pending', 'claimed_at' => null]);
        $wins = 0;
        for ($i = 0; $i < 2; $i++) {
            $wins += EndpointCommand::whereKey($cmdId)->where('state', 'pending')
                ->update(['state' => 'claimed', 'claimed_at' => now()]);
        }
        $this->assertSame(1, $wins, 'الادّعاءُ المشروط سمح بفوزين');
    }

    /* ────────── ⑧ النتيجة: انتقالٌ مشروط + توقيعُ نتيجةٍ يُتحقَّق + عبرَ جهازٍ ٤٠٤ ────────── */

    public function test_command_results_transition_once_and_a_forged_result_sig_is_rejected(): void
    {
        $this->seedCore();
        [$d, $priv] = $this->device();
        $this->actingAs($this->owner)
            ->postJson(route('endpoints.command', $d->id), ['type' => 'refresh_posture'])->assertStatus(201);
        $cmd = EndpointCommand::firstOrFail();

        // نتيجةٌ لأمرٍ غير مُدَّعى: 409 — الانتقالُ pending→done ممنوعٌ بلا claim
        $this->signed($d, $priv, '/api/v1/endpoint/commands/result',
            ['command_id' => $cmd->id, 'state' => 'done'])->assertStatus(409);

        // يُدَّعى ثم تُقدَّم نتيجةٌ موقَّعة: العقدُ ikey\nstate\nsha256hex(resultRaw)
        $this->signed($d, $priv, '/api/v1/endpoint/commands/pull')->assertOk();
        $resultRaw = '{"checks":4,"ok":true}';
        openssl_sign($cmd->ikey . "\n" . 'done' . "\n" . hash('sha256', $resultRaw), $rs, $priv, OPENSSL_ALGO_SHA256);
        $this->signed($d, $priv, '/api/v1/endpoint/commands/result', [
            'command_id' => $cmd->id, 'state' => 'done',
            'result' => $resultRaw, 'result_sig' => base64_encode($rs),
        ])->assertOk();

        $done = $cmd->fresh();
        $this->assertSame('done', $done->state);
        $this->assertNotNull($done->finished_at);
        $this->assertSame(4, (int) ($done->result['checks'] ?? 0));
        $this->assertNotSame('', (string) $done->result_sig);

        // انتقالٌ ثانٍ على أمرٍ منتهٍ: 409 — الحالةُ لا تُدهس
        $this->signed($d, $priv, '/api/v1/endpoint/commands/result',
            ['command_id' => $cmd->id, 'state' => 'failed'])->assertStatus(409);
        $this->assertSame('done', $cmd->fresh()->state);

        // توقيعُ نتيجةٍ مزوَّر (مفتاحٌ آخر): 422 ولا انتقال
        $this->actingAs($this->owner)
            ->postJson(route('endpoints.command', $d->id), ['type' => 'refresh_inventory'])->assertStatus(201);
        $cmd2 = EndpointCommand::where('type', 'refresh_inventory')->firstOrFail();
        $this->signed($d, $priv, '/api/v1/endpoint/commands/pull')->assertOk();
        [$otherPriv] = $this->keypair();
        openssl_sign($cmd2->ikey . "\ndone\n" . hash('sha256', '{}'), $badSig, $otherPriv, OPENSSL_ALGO_SHA256);
        $this->signed($d, $priv, '/api/v1/endpoint/commands/result', [
            'command_id' => $cmd2->id, 'state' => 'done', 'result' => '{}',
            'result_sig' => base64_encode($badSig),
        ])->assertStatus(422);
        $this->assertSame('claimed', $cmd2->fresh()->state, 'توقيعُ نتيجةٍ مزوَّرٌ نقل الحالة');

        // ونتيجةٌ تحمل حقلَ مراقبة: 422 قبل أي انتقال (الخصوصيّةُ على كل مدخل)
        $this->signed($d, $priv, '/api/v1/endpoint/commands/result', [
            'command_id' => $cmd2->id, 'state' => 'done', 'result' => '{"screenshot":"..."}',
        ])->assertStatus(422);
        $this->assertSame('claimed', $cmd2->fresh()->state);

        // جهازُ شركةٍ أخرى لا يرى أمرَ غيره: ٤٠٤ لا تسريبَ وجود
        [$d2, $priv2] = $this->device(Company::create(['name_ar' => 'شركة باء']));
        $this->signed($d2, $priv2, '/api/v1/endpoint/commands/result',
            ['command_id' => $cmd2->id, 'state' => 'done'])->assertStatus(404);
        // وسحبُه لا يلتقط أوامرَ غيرِه
        $r = $this->signed($d2, $priv2, '/api/v1/endpoint/commands/pull');
        $this->assertSame([], $r->json('commands'), 'جهازٌ سحب أوامرَ جهازٍ آخر');
    }

    /* ────────── ⑨ سياساتُ USB: enforce=false بالولادة، والفرضُ بإقرار MDM صريح (C15) ────────── */

    public function test_usb_policies_default_to_audit_only_and_enforce_requires_the_mdm_acknowledgment(): void
    {
        // خمسةُ أوضاعٍ لا غير — allowlist تطبيقيّ (C10) لا DB enum
        $this->assertCount(5, EndpointPolicy::USB_MODES);

        $p = EndpointPolicy::create(['name' => 'سياسةُ التخزين المتنقّل', 'usb_mode' => 'audit']);
        $this->assertFalse((bool) $p->enforce, 'سياسةٌ وُلدت فارضةً — الافتراضُ رصدٌ فقط');

        // وضعٌ خارج القائمة: يُرمى لا يُكتب صامتاً
        try {
            EndpointPolicy::create(['name' => 'خارج القائمة', 'usb_mode' => 'nuke_everything']);
            $this->fail('وضعُ USB خارج القائمة قُبل');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(1, EndpointPolicy::count());
        }

        // قلبُ enforce=true صامتاً: يُرمى — الفرضُ الحقيقيّ يتطلب MDM (لا حجبَ زائفاً)
        try {
            $p->enforce = true;
            $p->save();
            $this->fail('enforce=true مرّ بلا إقرار MDM');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('MDM', $e->getMessage(), 'رسالةُ الرفض لا تُصارح بمتطلب MDM');
        }
        $this->assertFalse((bool) $p->fresh()->enforce);

        // وبالإقرار الصريح (سطحُ الويب يعرضه «يتطلب MDM / رصدٌ فقط»): يُقبل
        $p = $p->fresh();
        $p->enforce = true;
        $p->acknowledgeMdmRequirement()->save();
        $this->assertTrue((bool) $p->fresh()->enforce);
        $this->assertStringContainsString('MDM', EndpointPolicy::ENFORCE_NOTICE);
    }
}
