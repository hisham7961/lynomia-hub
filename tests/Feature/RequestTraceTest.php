<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InboundHook;
use App\Models\Role;
use App\Models\User;
use App\Support\Api;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WP-1.4 — أثرُ الطلب الواحد: `system/trace/{rid}` يجمع ما كتبه طلبٌ واحد عبر
 * الطبقات (تدقيق/خطأ/صادر/ويبهوك/إشعار/منع/حادثة) بمعرّفه، بحارسِ كلِّ مصدرٍ
 * ونطاقِه — وختمُ `tasks.completed_at` عند الإنجاز. إثباتاً لا ادّعاءً.
 */
class RequestTraceTest extends TestCase
{
    /* ── وسمُ المصدر والخارجيّ (Observability + Api) ── */

    public function test_request_source_and_external_marking(): void
    {
        $this->seedCore();
        Route::middleware('web')->get('/_wp14_src', fn () => Api::requestSource() . '|' . (Api::requestIdIsExternal() ? 'ext' : 'own'));
        $this->actingAs($this->owner)->get('/_wp14_src')->assertOk()->assertSee('web|own');
        // معرّفٌ أرسله العميل: يُحترَم ويُوسَم خارجياً — لا يُفترَض تفرّدُه
        $res = $this->actingAs($this->owner)->get('/_wp14_src', ['X-Request-Id' => 'n8n-run-00042']);
        $res->assertOk()->assertSee('web|ext');
        $this->assertSame('n8n-run-00042', $res->headers->get('X-Request-Id'));
        // مسارُ الويبهوك الوارد يُوسَم hook
        Route::middleware('web')->get('/hook/_wp14probe', fn () => Api::requestSource());
        $this->actingAs($this->owner)->get('/hook/_wp14probe')->assertOk()->assertSee('hook');
    }

    /** معرّفٌ أطولُ من عرض العمود (40) يُستبدل به UUID — كان يُكتب خاماً في audits فيُسقط الطلبَ على MySQL الصارمة */
    public function test_oversized_client_request_id_is_replaced_not_stored_raw(): void
    {
        $this->seedCore();
        $long = 'n8n-' . str_repeat('a', 60);   // 64 محرفاً — فوق varchar(40)
        $res = $this->actingAs($this->owner)->post('/m/clients', ['name' => 'معرّف-طويل'], ['X-Request-Id' => $long]);
        $res->assertSessionDoesntHaveErrors();
        $rid = $res->headers->get('X-Request-Id');
        $this->assertNotSame($long, $rid, 'المعرّف الطويل قُبل — سيُكتب خاماً في عمود 40');
        $this->assertLessThanOrEqual(40, strlen((string) $rid));
        // القيدُ كُتب فعلاً وبالمعرّف البديل — لا فشلَ إدراجٍ صامتاً ولا خاماً
        $this->assertSame($rid, DB::table('audits')->orderByDesc('id')->value('request_id'));
    }

    /* ── الكتّاب الجدد ── */

    public function test_inbound_hook_event_carries_the_request_id(): void
    {
        $this->seedCore();
        $hook = InboundHook::create(['name' => 'نقطة', 'token' => Str::random(48), 'enabled' => true,
            'created_by' => $this->owner->id]);
        $res = $this->postJson('/hook/' . $hook->token, ['x' => 1]);
        $res->assertOk();
        $rid = $res->headers->get('X-Request-Id');
        $this->assertNotEmpty($rid);
        $this->assertSame($rid, DB::table('inbound_hook_events')->orderByDesc('id')->value('request_id'));
    }

    /* ── صفحةُ الأثر ── */

    public function test_owner_sees_audit_error_outbox_webhook_and_notification_for_one_request_id(): void
    {
        $this->seedCore();
        \App\Models\Webhook::create(['name' => 'w', 'url' => 'https://example.com/hook', 'secret' => 's', 'events' => '*', 'active' => true]);
        \App\Models\Flow::create(['name' => 'f', 'module' => 'clients', 'event' => 'created', 'enabled' => true,
            'actions' => [['type' => 'tg', 'text' => 'صادر {name}'], ['type' => 'notify', 'to' => $this->employee->id, 'text' => 'إشعار-الترابط']]]);

        $res = $this->actingAs($this->owner)->post('/m/clients', ['name' => 'عميل-التتبع']);
        $res->assertRedirect();
        $rid = $res->headers->get('X-Request-Id');
        $this->assertNotEmpty($rid);
        Client::where('name', 'عميل-التتبع')->firstOrFail();

        // صفُّ خطأٍ بالمعرّف نفسِه — كما يكتبه مركزُ الأخطاء لطلبٍ تعثّر
        DB::table('error_events')->insert(['id' => (string) Str::uuid(), 'hash' => hash('sha256', 'wp14'),
            'kind' => 'php', 'message' => 'trace-err-77', 'request_id' => $rid, 'count' => 1,
            'status' => 'جديد', 'first_seen' => now(), 'last_seen' => now()]);

        $page = $this->actingAs($this->owner)->get('/system/trace/' . $rid);
        $page->assertOk();
        $page->assertSee('عميل-التتبع');                       // قيدُ التدقيق (والصادر)
        $page->assertSee('trace-err-77');                      // الخطأ
        $page->assertSee('clients.created');                   // تسليمُ الويبهوك
        $page->assertSee('إشعار-الترابط');                     // الإشعار
        $page->assertDontSee('خارجي');                         // معرّفٌ مولَّدٌ داخلياً
        $this->assertStringNotContainsString('Authorization', $page->getContent());
    }

    public function test_audit_flag_holder_sees_only_scoped_audits(): void
    {
        $this->seedCore();
        $rid = (string) Str::uuid();
        $c1 = (string) Str::uuid(); $c2 = (string) Str::uuid();
        $auditor = User::create(['name' => 'مدقق', 'email' => 'aud@test.local', 'password' => 'Secret!2026x',
            'status' => 'نشط', 'password_changed_at' => now(), 'companies' => [$c1],
            'role_id' => Role::create(['name' => 'دور التدقيق', 'scope' => 'all', 'flags' => ['audit' => 1],
                'matrix' => ['clients' => ['v' => 1]]])->id]);

        $this->actingAs($this->owner);
        hub_audit('تعديل', 'clients', (string) Str::uuid(), 'قيد-مرئي', ['company_id' => $c1, 'request_id' => $rid]);
        hub_audit('تعديل', 'clients', (string) Str::uuid(), 'قيد-شركة-ثانية', ['company_id' => $c2, 'request_id' => $rid]);
        hub_audit('تعديل', 'hr', (string) Str::uuid(), 'قيد-وحدة-محجوبة', ['company_id' => $c1, 'request_id' => $rid]);
        // مصادرُ المالك تُخفى عن حامل العلم
        DB::table('error_events')->insert(['id' => (string) Str::uuid(), 'hash' => hash('sha256', 'wp14b'),
            'kind' => 'php', 'message' => 'خطأ-محجوب-11', 'request_id' => $rid, 'count' => 1,
            'status' => 'جديد', 'first_seen' => now(), 'last_seen' => now()]);
        DB::table('outbox')->insert(['id' => (string) Str::uuid(), 'kind' => 'flow', 'channel' => 'tg',
            'text' => 'صادر-محجوب-22', 'state' => 'queued', 'created_at' => now(), 'request_id' => $rid]);

        $page = $this->actingAs($auditor)->get('/system/trace/' . $rid);
        $page->assertOk();
        $page->assertSee('قيد-مرئي');
        $page->assertDontSee('قيد-شركة-ثانية');
        $page->assertDontSee('قيد-وحدة-محجوبة');
        $page->assertDontSee('خطأ-محجوب-11');
        $page->assertDontSee('صادر-محجوب-22');
        $this->assertStringNotContainsString('Authorization', $page->getContent());
    }

    public function test_employee_without_audit_flag_gets_403(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get('/system/trace/' . Str::uuid())->assertStatus(403);
    }

    public function test_unknown_request_id_shows_an_honest_empty_state_not_500(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/system/trace/' . Str::uuid())
            ->assertOk()->assertSee('لا أثر');
    }

    /** ميزانيةُ الاستعلام (بوابة §41-5): صفحةُ الأثر مقيّدةٌ — كلُّ مصدرٍ استعلامٌ واحدٌ محدود، لا صفَّ لكل سجل */
    public function test_trace_screen_query_count_is_bounded(): void
    {
        $this->seedCore();
        $rid = (string) Str::uuid();
        foreach (range(1, 5) as $i) {
            DB::table('audits')->insert(['action' => 'حدث ' . $i, 'request_id' => $rid,
                'created_at' => now()->subMinutes(10 - $i)]);
            DB::table('error_events')->insert(['id' => (string) Str::uuid(),
                'kind' => 'php', 'message' => 'خطأ ' . $i, 'hash' => str_repeat((string) $i, 40),
                'request_id' => $rid, 'status' => 'جديد', 'count' => 1,
                'first_seen' => now()->subMinutes(9), 'last_seen' => now()->subMinutes(8 - $i)]);
            DB::table('access_denials')->insert(['kind' => 'denied', 'ip' => '10.0.0.9', 'method' => 'GET',
                'path' => '/x', 'request_id' => $rid, 'created_at' => now()->subMinutes(7 - $i)]);
        }
        // إحماءٌ أول: فحوصُ المخطط (hub_has_col/hub_col_max) تُخبَّأ ٣٠٠ ثانية —
        // فالميزانيةُ تُقاس على الحالة الدائمة لا على أول طلبٍ في عمر العملية
        $this->actingAs($this->owner)->get('/system/trace/' . $rid)->assertOk();
        $n = 0; $count = false;
        DB::listen(function () use (&$n, &$count) { if ($count) $n++; });
        $count = true;
        $this->actingAs($this->owner)->get('/system/trace/' . $rid)->assertOk();
        $count = false;
        $this->assertLessThan(40, $n, 'صفحةُ الأثر تجاوزت ميزانيةَ الاستعلام: ' . $n);
    }

    public function test_client_sent_request_id_is_labelled_external(): void
    {
        $this->seedCore();
        $res = $this->actingAs($this->owner)->post('/m/clients', ['name' => 'خارجي-المصدر'], ['X-Request-Id' => 'n8n-run-00042']);
        $res->assertRedirect();
        $this->assertSame('n8n-run-00042', $res->headers->get('X-Request-Id'));
        $this->actingAs($this->owner)->get('/system/trace/n8n-run-00042')
            ->assertOk()->assertSee('خارجي')->assertSee('خارجي-المصدر');
    }

    /* ── ختمُ إنجاز المهمة ── */

    public function test_tasks_completed_at_is_stamped_on_done_and_cleared_on_reopen(): void
    {
        $this->seedCore();
        $p = \App\Models\Project::create(['name' => 'مشروع']);
        $t = \App\Models\Task::create(['title' => 'مهمة', 'project_id' => $p->id, 'status' => 'جديدة']);
        $this->assertNull($t->fresh()->completed_at);

        $this->actingAs($this->owner)->post("/m/tasks/{$t->id}/status", ['status' => 'منجزة'])->assertOk();
        $this->assertNotNull($t->fresh()->completed_at, 'الختمُ عند الإنجاز');

        $this->actingAs($this->owner)->post("/m/tasks/{$t->id}/status", ['status' => 'قيد التنفيذ'])->assertOk();
        $this->assertNull($t->fresh()->completed_at, 'المحوُ عند مغادرة الإنجاز');

        // وعبر التعديل العام أيضاً — و«مكتملة» حالةُ إنجازٍ ثانية في خيارات السجل
        $this->actingAs($this->owner)->put("/m/tasks/{$t->id}",
            ['title' => 'مهمة', 'projectId' => $p->id, 'status' => 'مكتملة'])->assertRedirect();
        $this->assertNotNull($t->fresh()->completed_at, 'الختمُ عبر التعديل العام');
    }
}
