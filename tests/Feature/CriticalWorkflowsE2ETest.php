<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetCustody;
use App\Models\AuditEntry;
use App\Models\Contract;
use App\Models\HubNotification;
use App\Models\OutboxMessage;
use App\Models\SignRequest;
use App\Models\SignTemplate;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **رحلاتٌ حرجة من طرفٍ إلى طرف** (v2.399) — لا وحدةً وحدها بل السلسلةَ كلَّها كما يعيشها
 * المستخدم: كلُّ رحلةٍ تعبر الواجهةَ والـAPI والعملَ الخلفيّ وسلسلةَ التدقيق والإشعارات،
 * وتُثبت أن **الربطَ** بينها (معرّفُ الطلب، الحالة، النبضة) سليمٌ — فكسرُ حلقةٍ واحدة يسقط هنا.
 */
class CriticalWorkflowsE2ETest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    /** الرحلة ١: عقدٌ → طلبُ توقيع → توقيعٌ عامّ → العقدُ ساري + أثرٌ وإشعارٌ ورسالةٌ صادرة */
    public function test_contract_to_esign_to_active_contract(): void
    {
        $this->actingAs($this->owner)->get('/esign')->assertOk();   // بذرُ القوالب
        $tpl = SignTemplate::first();
        $this->assertNotNull($tpl);
        $c = Contract::create(['title' => 'عقد التوريد السنوي', 'type' => 'عقد مورد', 'status' => 'مسودة']);

        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'توقيع عقد التوريد', 'template_id' => $tpl->id, 'pass' => 'pass12',
            'contract_id' => $c->id, 'vars' => ['اسم_الطرف_الثاني' => 'مورد التوريد'],
        ])->assertSessionHas('sign_link');
        $this->assertSame('قيد التوقيع', $c->fresh()->status, 'إنشاءُ الطلب لم يضبط العقد على «قيد التوقيع»');
        $req = SignRequest::where('contract_id', $c->id)->firstOrFail();
        $this->assertTrue(AuditEntry::where('module', 'contracts')->where('record_id', $c->id)->exists() || AuditEntry::where('record_id', $req->id)->exists(), 'لا أثرَ تدقيقٍ لإنشاء الطلب');

        // الطرفُ الخارجي يوقّع من السطح العامّ — بلا جلسة
        auth()->logout();
        $this->post("/sign/{$req->token}/unlock", ['pass' => 'pass12']);
        $this->get("/sign/{$req->token}")->assertOk();
        $sig = 'data:image/png;base64,' . base64_encode(str_repeat('توقيع', 40));
        $this->post("/sign/{$req->token}", ['signer_name' => 'المورد', 'signature' => $sig])->assertOk();

        $this->assertSame('ساري', $c->fresh()->status, 'التوقيعُ لم يرفع العقد إلى «ساري»');
        $this->assertNotSame('pending', $req->fresh()->status);
        $this->assertTrue(HubNotification::where('user_id', $this->owner->id)->exists(), 'المالكُ لم يُخبَر بالتوقيع');
        // التوثيقُ العامّ للتوقيع يعمل بالرمز لا بالجلسة
        $verify = $req->fresh();
        $this->assertNotEmpty($verify->token);
        $this->assertGreaterThan(0, AuditEntry::count());
    }

    /** الرحلة ٢: أصلٌ يُسجَّل → عهدةٌ تُسلَّم → تُستردّ — والـAPI يقرأ الحالةَ نفسَها بالمعرّف نفسِه */
    public function test_asset_register_to_custody_handover_and_recovery(): void
    {
        $this->actingAs($this->owner)->post('/identity/register', ['name' => 'لابتوب التصميم', 'qty' => 1])->assertRedirect();
        $a = Asset::where('name', 'لابتوب التصميم')->firstOrFail();
        $this->assertNotEmpty($a->code, 'الأصلُ بلا كودٍ فريد');

        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/handover', [
            'userId' => $this->employee->id, 'at' => now()->toDateString(), 'note' => 'مع الشاحن',
        ])->assertRedirect();
        $a->refresh();
        $this->assertSame($this->employee->id, $a->holder_id);
        $this->assertSame('قيد الاستخدام', $a->status);
        $this->assertTrue(HubNotification::where('user_id', $this->employee->id)->where('module', 'assets')->exists());
        $this->assertDatabaseHas('audits', ['action' => 'تسليم عهدة', 'module' => 'assets', 'record_id' => $a->id]);

        // الموظفُ يرى عهدتَه من الـAPI بالمعرّف نفسِه وبالحالة نفسِها
        $tok = $this->apiToken($this->employee);
        $row = $this->withHeaders(['Authorization' => 'Bearer ' . $tok])->getJson('/api/v1/assets/' . $a->id)->assertOk()->json('data');
        $this->assertSame($a->id, $row['id']);
        $this->assertSame('قيد الاستخدام', $row['status']);
        $this->assertSame($this->employee->id, $row['holder_id'] ?? null);

        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/recover', ['at' => now()->toDateString()])->assertRedirect();
        $a->refresh();
        $this->assertNull($a->holder_id);
        $this->assertSame('متاح', $a->status);
        $this->assertEqualsCanonicalizing(['تسليم', 'استرداد'], AssetCustody::where('asset_id', $a->id)->pluck('action')->all());
    }

    /** الرحلة ٣: كتابةٌ من الـAPI → حدثٌ → ويبهوك صادر عبر العامل → تسليمٌ موقَّع بمعرّف الطلب نفسِه */
    public function test_api_write_to_webhook_delivery_with_correlation(): void
    {
        app()->instance('hub.dns', fn (string $h) => ['93.184.216.34']);
        Http::fake(['example.com/*' => Http::response('ok', 200)]);
        $hook = Webhook::create(['name' => 'n8n', 'url' => 'https://example.com/hook', 'secret' => 'whs_e2e', 'events' => '*', 'active' => true]);

        $tok = $this->apiToken($this->owner);
        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $tok, 'X-Request-Id' => 'e2e-req-0001'])
            ->postJson('/api/v1/clients', ['name' => 'عميل الرحلة', 'email' => 'e2e@example.com']);
        $res->assertStatus(201)->assertHeader('X-Request-Id', 'e2e-req-0001');
        $id = $res->json('data.id');

        $d = WebhookDelivery::where('webhook_id', $hook->id)->where('event', 'clients.created')->first();
        $this->assertNotNull($d, 'الحدثُ لم يُصفَّ للويبهوك');
        $this->assertSame('queued', $d->state);
        $this->assertSame('e2e-req-0001', $d->request_id, 'معرّفُ الطلب لم يصل التسليم');
        $this->assertStringContainsString($id, (string) $d->payload);

        $this->artisan('hub:outbox')->assertExitCode(0);
        $d->refresh();
        $this->assertSame('sent', $d->state, 'العاملُ لم يُسلّم الويبهوك');
        Http::assertSent(fn ($r) => $r->url() === 'https://example.com/hook'
            && $r->header('X-Hub-Event')[0] === 'clients.created'
            && $r->header('X-Hub-Signature')[0] === 'sha256=' . hash_hmac('sha256', $d->payload, 'whs_e2e'));

        // والأثرُ والصحّة: القيدُ يحمل معرّفَ الطلب، والنبضةُ حيّة
        $this->assertTrue(AuditEntry::where('request_id', 'e2e-req-0001')->exists(), 'قيدُ التدقيق بلا معرّف الطلب');
        $this->assertNotNull(setting('heartbeat.outbox'));
        $this->assertSame(0, OutboxMessage::where('state', 'sending')->count());
        $this->assertSame(0, (int) DB::table('webhook_deliveries')->where('state', 'sending')->count());
    }
}
