<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Employee;
use App\Models\EndpointDevice;
use App\Models\Flow;
use App\Models\HubNotification;
use App\Models\IpRule;
use App\Models\Station;
use App\Support\AlertEngine;
use App\Support\Api;
use App\Support\HubEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **إثباتُ السكّة الواحدة** (Work OS · الطور M · WP-M.2 · §63–73).
 *
 * أعلن Work OS عبر الأطوار A–L ستةَ عشرَ حدثاً دلاليّاً — وهذا الملفُّ يُثبت
 * (اختباراً لا ادّعاءً) أنّ كلَّ حدثٍ منها:
 *  ١) يُبثُّ عبر ناقلِ `FlowRunner::fire → HubEvents` **الواحد** — لا مُوزِّعَ
 *     أحداثٍ ثانياً في الشيفرة (مسحٌ على نمط حارس
 *     `WorkOsCustodyPostingTest::test_all_three_rails_post_through_the_one_shared_service`
 *     وحارس `HubEventsTest::test_every_mapped_status_is_actually_declared`).
 *  ٢) يحمل معرّفَ الارتباط **الواحد** `Api::requestId` (X-Request-Id) في أثره
 *     التدقيقيّ — نهايةً إلى نهاية: فعلٌ عبر HTTP ← بثٌّ واحدٌ ← قيدُ تدقيقٍ
 *     بمعرّف الطلب نفسِه (يمتدّ `RequestTraceTest`؛ عيّنةٌ من كل عائلة طور:
 *     قناة C، عضويّة B، عهدة E، محطة F، حظرٌ آليّ I، تسجيلُ نقطة J).
 *  ٣) إشعارُه يركب محرّكَ `hub_notify`/`HubNotification` **الواحد** بانضباطِ
 *     الكتم القائم (`MUTEABLE`) وبمعرّف الطلب نفسِه — لا محرّكَ إشعاراتٍ ثانياً
 *     (يمتدّ `NotificationMuteTest` و`HubEventsTest::test_editing_a_record_over_http...`).
 *
 * لا يبني هذا الطورُ سكّةً جديدة: يستهلك الإطلاقاتِ التي أرستها الأطوارُ نفسُها
 * ويحرسها من الانحراف — حذفُ `FlowRunner::fire` من أيّ مُطلِقٍ يُسقط المسحَ هنا.
 */
class WorkOsEventRailTest extends TestCase
{
    /**
     * الأحداثُ الدلاليّةُ المُعلَنة في Work OS (الأطوار A–L) حرفيّاً:
     * `الاسمُ الدلاليّ => [وحدةُ الخريطة في config('hub.events'), الحدثُ الخامُّ المُشتَقُّ منه]`.
     */
    private const DECLARED = [
        'conversation.created'      => ['conversations',      'created'],           // الطور A/C
        'client.account_activated'  => ['users',              'account_activated'], // الطور B
        'client_membership_granted' => ['client_memberships', 'granted'],           // الطور B
        'client_membership_revoked' => ['client_memberships', 'revoked'],           // الطور B
        'client_workspace_created'  => ['clients',            'workspace_created'], // الطور B
        'project.provisioned'       => ['projects',           'provisioned'],       // الطور D
        'custody.charged'           => ['custody',            'charged'],           // الطور E
        'custody.approved'          => ['custody',            'approved'],          // الطور E
        'custody.reversed'          => ['custody',            'reversed'],          // الطور E
        'station.assigned'          => ['stations',           'assigned'],          // الطور F
        'station.vacated'           => ['stations',           'vacated'],           // الطور F
        'inventory.session_closed'  => ['inventory',          'session_closed'],    // الطور F
        'ip_auto_blocked'           => ['ip_rules',           'auto_blocked'],      // الطور I
        'endpoint.enrolled'         => ['endpoints',          'enrolled'],          // الطور J
        'endpoint.usb_event'        => ['endpoints',          'usb_event'],         // الطور J
        'endpoint.posture_alert'    => ['endpoints',          'posture_alert'],     // الطور J
    ];

    protected function tearDown(): void
    {
        // المشتركون الإضافيون static — لا يتسرّبون لاختبارٍ تالٍ (نمطُ HubEventsTest)
        HubEvents::forgetListeners();
        parent::tearDown();
    }

    /** عدّادُ بثِّ اسمٍ دلاليٍّ بعينه — نمطُ HubEventsTest::record لكن عدّاً لا رصداً */
    private function countEmissions(string $name, int &$count): void
    {
        HubEvents::forgetListeners();
        HubEvents::listen(function (string $e) use ($name, &$count) {
            if ($e === $name) $count++;
        });
    }

    /**
     * معرّفُ الطلب في قيدِ تدقيقٍ واحدٍ بعينه — ويُؤكَّد أنّه قيدٌ **واحد**
     * (لا `first()` على قرعة ترتيبٍ، ولا بثٌّ مكرَّرٌ يكتب قيدين).
     */
    private function auditRequestId(string $action, string $recordId): ?string
    {
        $rows = DB::table('audits')->where('action', $action)
            ->where('record_id', $recordId)->orderBy('id')->get();
        $this->assertCount(1, $rows, "قيدُ التدقيق «{$action}» غائبٌ أو مكرَّر");

        return $rows->first()->request_id;
    }

    /** مصادرُ `app/` كلُّها — مسحٌ واحدٌ يخدم حارسَي السكّة أدناه */
    private function appSources(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php') {
                $out[(string) $f->getPathname()] = (string) file_get_contents((string) $f->getPathname());
            }
        }
        $this->assertNotEmpty($out, 'مسحُ app/ لم يجد ملفات — الحارسُ أعمى');

        return $out;
    }

    /* ────────── ① الإعلان: كلُّ حدثٍ مُعلَنٌ على خريطة الأحداث الواحدة ────────── */

    public function test_all_declared_work_os_events_are_on_the_one_semantic_map(): void
    {
        $names = HubEvents::semanticNames();
        foreach (self::DECLARED as $emit => [$module, $on]) {
            $this->assertContains($emit, $names,
                "«{$emit}» غائبٌ عن config('hub.events') — حدثٌ مُعلَنٌ في المواصفة بلا خريطة");

            $rule = collect((array) config("hub.events.$module", []))
                ->first(fn ($r) => ($r['emit'] ?? null) === $emit);
            $this->assertNotNull($rule, "«{$emit}» ليس تحت وحدة «{$module}» في الخريطة");
            $this->assertSame($on, $rule['on'] ?? null,
                "الخامُّ المُشتَقُّ منه «{$emit}» انحرف عن «{$on}» — المُطلِقون القائمون يعتمدونه حرفيّاً");
        }
    }

    /* ────────── ② المسح: لكلِّ حدثٍ مُطلِقٌ حقيقيٌّ على الناقل الواحد ────────── */

    public function test_every_declared_work_os_event_has_a_real_emitter_on_the_one_rail(): void
    {
        // مرشَّحو الإطلاق: ملفاتُ app/ التي تنادي FlowRunner::fire فعلاً
        $sources = array_filter($this->appSources(),
            fn (string $src) => str_contains($src, 'FlowRunner::fire'));
        $this->assertNotEmpty($sources, 'لا مُطلِقَ واحداً في الشيفرة — الناقلُ ميّت');

        foreach (self::DECLARED as $emit => [$module, $on]) {
            // وحدةُ العهدة تُنادى عبر ثابت النموذج لا النصّ الحرفيّ (CustodyPostingService)
            $moduleTokens = $module === 'custody'
                ? ["'custody'", 'EmployeeCustodyMove::MODULE'] : ["'{$module}'"];

            $hit = false;
            foreach ($sources as $src) {
                if (! str_contains($src, "'{$on}'")) continue;
                foreach ($moduleTokens as $tok) {
                    if (str_contains($src, $tok)) { $hit = true; break 2; }
                }
            }
            $this->assertTrue($hit,
                "«{$emit}» مُعلَنٌ ولا مُطلِقَ له: لا ملفَّ في app/ ينادي FlowRunner::fire "
                . "ويحمل «'{$on}'» على وحدة «{$module}» — حدثٌ لا يقع أبداً");
        }
    }

    /** نقطةُ دخول الناقل واحدة، ولا كاتبَ إشعاراتٍ يلتفّ على المحرّك الواحد */
    public function test_the_bus_has_one_entry_point_and_no_second_notification_write_path(): void
    {
        $dispatchers = [];
        $directInserts = [];
        foreach ($this->appSources() as $path => $src) {
            $base = basename($path);
            // HubEvents::dispatch يناديه FlowRunner::fire وحده — «النقطة الوحيدة» المُعلنة
            if (str_contains($src, 'HubEvents::dispatch')
                && ! in_array($base, ['FlowRunner.php', 'HubEvents.php'], true)) {
                $dispatchers[] = $base;
            }
            // إدراجٌ خامٌّ في notifications_hub يقفز فوق كتمِ MUTEABLE وقصِّ العرض
            // وختمِ request_id في طبقة HubNotification — محرّكٌ ثانٍ متنكّر
            if (preg_match("/table\\(\\s*'notifications_hub'\\s*\\)\\s*->\\s*insert/u", $src)) {
                $directInserts[] = $base;
            }
        }
        $this->assertSame([], $dispatchers,
            'مُوزِّعٌ ثانٍ ينادي HubEvents::dispatch مباشرةً — الناقلُ يُنادى عبر FlowRunner::fire وحدَه');
        $this->assertSame([], $directInserts,
            'كاتبٌ يُدرج في notifications_hub خامّاً — كلُّ إشعارٍ عبر HubNotification/hub_notify');
    }

    /* ────────── ③ نهايةً إلى نهاية: بثٌّ واحد + معرّفُ الطلب الواحد في الأثر ──────────
     * عيّنةٌ ممثِّلة، واحدةٌ من كل عائلة طور — البقيّةُ محروسةٌ بالمسح أعلاه
     * وباختبارات أطوارها الموسومة. */

    /** الطور C — إنشاءُ قناة: بثٌّ واحدٌ لـconversation.created وقيدُ تدقيقٍ بمعرّف الطلب */
    public function test_channel_creation_rides_the_rail_with_the_one_request_id(): void
    {
        $this->seedCore();
        $n = 0;
        $this->countEmissions('conversation.created', $n);

        $res = $this->actingAs($this->owner)->post('/conversations', ['title' => 'قناةُ إثبات السكّة']);
        $res->assertSessionDoesntHaveErrors();
        $rid = (string) $res->headers->get('X-Request-Id');
        $this->assertNotSame('', $rid, 'لا X-Request-Id على الردّ — وسيطُ Observability غائب');

        $conv = Conversation::where('kind', 'channel')->where('title', 'قناةُ إثبات السكّة')->first();
        $this->assertNotNull($conv, 'القناةُ لم تُنشأ');
        $this->assertSame(1, $n, 'conversation.created لم يُبثّ مرّةً واحدةً بالضبط');
        $this->assertSame($rid, $this->auditRequestId('channel.created', (string) $conv->id),
            'قيدُ تدقيق القناة لا يحمل معرّفَ الطلب الواحد');
    }

    /** الطور B — منحُ عضويّةِ عميل: client_membership_granted مرّةً + الأثرُ بمعرّف الطلب */
    public function test_membership_grant_rides_the_rail_with_the_one_request_id(): void
    {
        $this->seedCore();
        $client = Client::create(['name' => 'عميلُ السكّة', 'stage' => 'عميل حالي']);
        $n = 0;
        $this->countEmissions('client_membership_granted', $n);

        $res = $this->actingAs($this->owner)->post(route('clients.members.invite', $client->id),
            ['email' => 'rail-member@client.local', 'role' => 'viewer']);
        $res->assertSessionDoesntHaveErrors();
        $rid = (string) $res->headers->get('X-Request-Id');
        $this->assertNotSame('', $rid);

        $this->assertSame(1, $n, 'client_membership_granted لم يُبثّ مرّةً واحدةً بالضبط');
        $this->assertSame($rid, $this->auditRequestId('منح عضويّة عميل', (string) $client->id),
            'قيدُ تدقيق المنح لا يحمل معرّفَ الطلب الواحد');
    }

    /** الطور E — شحنُ عهدة: custody.charged مرّةً + الأثرُ بمعرّف الطلب */
    public function test_custody_charge_rides_the_rail_with_the_one_request_id(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركةُ السكّة']);
        $emp = Employee::create(['name' => 'موظفُ السكّة', 'status' => 'نشط', 'company_id' => $co->id]);
        $bank = BankAccount::create(['name' => 'بنكُ السكّة', 'balance' => 1000, 'status' => 'نشط']);
        $n = 0;
        $this->countEmissions('custody.charged', $n);

        $res = $this->actingAs($this->owner)->post(route('custody.wallet.charge'),
            ['employee_id' => $emp->id, 'amount' => 250, 'bank_id' => $bank->id]);
        $res->assertSessionDoesntHaveErrors();
        $res->assertRedirect();
        $rid = (string) $res->headers->get('X-Request-Id');
        $this->assertNotSame('', $rid);

        $this->assertSame(1, $n, 'custody.charged لم يُبثّ مرّةً واحدةً بالضبط');
        $this->assertSame($rid, $this->auditRequestId('شحن عهدة', (string) $emp->id),
            'قيدُ تدقيق الشحن لا يحمل معرّفَ الطلب الواحد');
    }

    /** الطور F — إسنادُ محطة: station.assigned مرّةً + الأثرُ بمعرّف الطلب */
    public function test_station_assign_rides_the_rail_with_the_one_request_id(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركةُ المحطات']);
        $st = Station::create(['facility' => 'مبنى السكّة', 'type' => 'مكتب', 'company_id' => $co->id]);
        $n = 0;
        $this->countEmissions('station.assigned', $n);

        $res = $this->actingAs($this->owner)->post(route('stations.assign', $st->id),
            ['user_id' => $this->employee->id]);
        $res->assertSessionDoesntHaveErrors();
        $res->assertRedirect();
        $rid = (string) $res->headers->get('X-Request-Id');
        $this->assertNotSame('', $rid);

        $this->assertSame(1, $n, 'station.assigned لم يُبثّ مرّةً واحدةً بالضبط');
        $this->assertSame($rid, $this->auditRequestId('إسناد محطة', (string) $st->id),
            'قيدُ تدقيق الإسناد لا يحمل معرّفَ الطلب الواحد');
    }

    /**
     * الطور I — الحظرُ الآليّ: ip_auto_blocked مرّةً، وقاعدةُ الحظر نفسُها تحمل
     * `Api::requestId` **الواحد** (لا معرّفَ ثانياً يُولَّد في محرّك التنبيه) —
     * التغذيةُ والتقييمُ على نمط WorkOsIpAutoBlockTest حرفيّاً.
     */
    public function test_auto_block_rides_the_rail_and_stamps_the_one_request_id(): void
    {
        $this->seedCore();
        $this->hubSetting('security.autoblock_enabled', '1');
        $this->hubSetting('security.autoblock_threshold', '3');
        AlertRule::create(['name' => 'رفضٌ متكرّر', 'mod' => '', 'field' => 'x', 'op' => 'يساوي',
            'val' => '3', 'status' => 'مفعّلة', 'source' => 'security.denials', 'window_min' => 60]);
        for ($i = 0; $i < 5; $i++) {
            DB::table('access_denials')->insert(['kind' => 'وصول مرفوض', 'ip' => '203.0.113.77',
                'method' => 'GET', 'path' => '/admin/rail' . $i, 'created_at' => now()->subMinute()]);
        }
        $n = 0;
        $this->countEmissions('ip_auto_blocked', $n);

        (new AlertEngine())->evaluate(['components' => []]);

        $this->assertSame(1, $n, 'ip_auto_blocked لم يُبثّ مرّةً واحدةً بالضبط');
        $rows = IpRule::query()->get();
        $this->assertCount(1, $rows, 'قاعدةُ حظرٍ واحدةٌ لرشقٍ واحد');
        $this->assertNotEmpty($rows->first()->request_id, 'قاعدةُ الحظر بلا request_id — الأثرُ مقطوع');
        $this->assertSame(Api::requestId(), $rows->first()->request_id,
            'قاعدةُ الحظر لا تحمل معرّفَ Api::requestId الواحد — معرّفُ ارتباطٍ ثانٍ؟');
    }

    /** الطور J — تسجيلُ جهازٍ طرفيّ: endpoint.enrolled مرّةً + الأثرُ بمعرّف الطلب */
    public function test_endpoint_enroll_rides_the_rail_with_the_one_request_id(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركةُ النقاط']);

        // السكُّ عبر المسار الحقيقيّ (مالك + step-up) — نمطُ WorkOsEndpointEnrollTest::mintFor
        $this->actingAs($this->owner)
            ->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp])
            ->post(route('enroll.mint'), ['companyId' => $co->id])->assertSessionHas('enroll_token');
        $token = (string) session('enroll_token');

        // زوجُ P-256 حقيقيّ — العامُّ وحده يُرسَل (نمطُ WorkOsEndpointEnrollTest::keypair)
        $pk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pub = openssl_pkey_get_details($pk)['key'];

        $n = 0;
        $this->countEmissions('endpoint.enrolled', $n);

        $res = $this->postJson('/api/v1/endpoint/enroll', [
            'token' => $token, 'device_uuid' => (string) Str::uuid(), 'hostname' => 'LT-RAIL-001',
            'os' => 'windows', 'agent_version' => '1.0.0', 'public_key' => $pub,
        ]);
        $res->assertStatus(201);
        $rid = (string) $res->headers->get('X-Request-Id');
        $this->assertNotSame('', $rid);

        $device = EndpointDevice::query()->first();
        $this->assertNotNull($device, 'الجهازُ لم يُسجَّل');
        $this->assertSame(1, $n, 'endpoint.enrolled لم يُبثّ مرّةً واحدةً بالضبط');
        $this->assertSame($rid, $this->auditRequestId('تسجيلُ جهازٍ طرفيّ', (string) $device->id),
            'قيدُ تدقيق التسجيل لا يحمل معرّفَ الطلب الواحد');
    }

    /* ────────── ④ الإشعار: يركب المحرّكَ الواحد بمعرّفه وكتمِه القائم ────────── */

    /** مسارٌ مربوطٌ بحدثٍ دلاليٍّ من Work OS يُشعر عبر HubNotification بمعرّف الطلب الواحد */
    public function test_a_work_os_event_notification_rides_the_one_engine_with_the_one_id(): void
    {
        $this->seedCore();
        HubEvents::forgetListeners();

        $flow = Flow::create(['name' => 'إشعارُ إسناد محطة', 'module' => 'stations',
            'event' => 'station.assigned', 'enabled' => true, 'cond_op' => 'eq', 'runs' => 0,
            'actions' => [['type' => 'notify', 'to' => $this->employee->id, 'text' => 'أُسنِدت محطةُ السكّة']]]);

        $co = Company::create(['name_ar' => 'شركةُ الإشعار']);
        $st = Station::create(['facility' => 'مبنى الإشعار', 'type' => 'مكتب', 'company_id' => $co->id]);

        $res = $this->actingAs($this->owner)->post(route('stations.assign', $st->id),
            ['user_id' => $this->employee->id]);
        $res->assertSessionDoesntHaveErrors();
        $rid = (string) $res->headers->get('X-Request-Id');

        $this->assertSame(1, (int) $flow->fresh()->runs, 'المسارُ المربوطُ بالحدث الدلاليّ لم يعمل');
        $ns = HubNotification::where('user_id', $this->employee->id)->where('kind', 'flow')->get();
        $this->assertCount(1, $ns, 'إشعارٌ واحدٌ لبثٍّ واحد — لا صفر ولا مطر');
        $this->assertSame('أُسنِدت محطةُ السكّة', (string) $ns->first()->text);
        $this->assertSame($rid, (string) $ns->first()->request_id,
            'الإشعارُ لا يحمل معرّفَ الطلب الواحد — سلسلةُ الترابط مقطوعةٌ عند الإشعار');
    }

    /** الكتمُ القائم (MUTEABLE) يسري على إشعارات أحداث Work OS — دليلُ «لا محرّكَ ثانياً» */
    public function test_a_muted_recipient_gets_no_notification_from_a_work_os_event(): void
    {
        $this->seedCore();
        HubEvents::forgetListeners();

        // «flow» نوعٌ قابلٌ للكتم في المحرّك الواحد — لو ركب الإشعارُ محرّكاً ثانياً لتجاوز الكتم
        $this->assertArrayHasKey('flow', HubNotification::MUTEABLE);
        $this->viewer->update(['prefs' => ['mute' => ['flow']]]);

        $flow = Flow::create(['name' => 'إشعارٌ مكتوم', 'module' => 'stations',
            'event' => 'station.assigned', 'enabled' => true, 'cond_op' => 'eq', 'runs' => 0,
            'actions' => [['type' => 'notify', 'to' => $this->viewer->id, 'text' => 'لن يصل']]]);

        $co = Company::create(['name_ar' => 'شركةُ الكتم']);
        $st = Station::create(['facility' => 'مبنى الكتم', 'type' => 'مكتب', 'company_id' => $co->id]);

        $this->actingAs($this->owner)->post(route('stations.assign', $st->id),
            ['user_id' => $this->employee->id])->assertSessionDoesntHaveErrors();

        $this->assertSame(1, (int) $flow->fresh()->runs, 'الكتمُ في طبقة الإشعار لا في الناقل — المسارُ يعمل');
        $this->assertSame(0, HubNotification::where('user_id', $this->viewer->id)->count(),
            'إشعارٌ وصل مستلماً كاتماً — كاتبٌ يلتفّ على كتم المحرّك الواحد');
    }
}
