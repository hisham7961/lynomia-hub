<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ErrorEvent;
use App\Support\ErrorLog;
use App\Support\ErrorTaxonomy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * إدارةُ الأخطاء المؤسسية والربطُ بمعرّف الطلب — إثباتاً لا ادّعاءً.
 */
class ErrorManagementAndCorrelationTest extends TestCase
{
    /* ── التصنيف ── */

    public function test_exceptions_are_classified_by_category_and_severity(): void
    {
        $this->assertSame(['VALIDATION', 'INFO'], ErrorTaxonomy::classify(
            \Illuminate\Validation\ValidationException::withMessages(['x' => 'y'])));
        $this->assertSame(['DATABASE', 'HIGH'], ErrorTaxonomy::classify(
            new \Illuminate\Database\QueryException('sqlite', 'select 1', [], new \Exception('SQLSTATE[23000]: Integrity constraint violation'))));
        $this->assertSame(['DATABASE', 'CRITICAL'], ErrorTaxonomy::classify(
            new \Illuminate\Database\QueryException('mysql', 'select 1', [], new \Exception('SQLSTATE[HY000] [2002] Connection refused'))));
        $this->assertSame(['TIMEOUT', 'WARNING'], ErrorTaxonomy::classify(
            new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out')));
        $this->assertSame(['NETWORK', 'WARNING'], ErrorTaxonomy::classify(
            new \Illuminate\Http\Client\ConnectionException('cURL error 6: Could not resolve host')));
        $this->assertSame(['STORAGE', 'HIGH'], ErrorTaxonomy::classify(new \ErrorException('file_put_contents(): No space left on device')));
        $this->assertSame(['INTEGRATION', 'WARNING'], ErrorTaxonomy::classify(new \RuntimeException('تعذّر الوصول لخادم أودو — لا استجابة')));
        $this->assertSame(['DEPENDENCY', 'HIGH'], ErrorTaxonomy::classify(new \Symfony\Component\HttpKernel\Exception\HttpException(503, 'x')));
        $this->assertSame(['APPLICATION', 'ERROR'], ErrorTaxonomy::classify(new \TypeError('boom')));
        $this->assertSame(['CONFIGURATION', 'CRITICAL'], ErrorTaxonomy::classify(new \Illuminate\Encryption\MissingAppKeyException()));
    }

    public function test_captured_exception_carries_taxonomy_release_and_route(): void
    {
        $this->seedCore();
        Route::middleware('web')->get('/_err_probe', fn () => throw new \RuntimeException('probe failure'))->name('err.probe');
        $this->actingAs($this->owner)->get('/_err_probe')->assertStatus(500);

        $e = ErrorEvent::where('message', 'like', '%probe failure%')->firstOrFail();
        $this->assertSame('APPLICATION', $e->category);
        $this->assertSame('ERROR', $e->severity);
        $this->assertSame((string) config('hub.version'), $e->release);
        $this->assertSame('err.probe', $e->route);
        $this->assertSame(1, (int) $e->users);
        $this->assertNotEmpty($e->request_id);
    }

    public function test_same_error_with_different_ids_groups_into_one_row_and_counts_users(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        ErrorLog::capture('php', 'RuntimeException: record 3f1e9c2a-1111-4c2b-9d2e-000000000001 exploded', 'a.php', 10);
        ErrorLog::capture('php', 'RuntimeException: record 3f1e9c2a-2222-4c2b-9d2e-000000000002 exploded', 'a.php', 10);
        $this->assertSame(1, ErrorEvent::where('file', 'a.php')->count(), 'معرّفان مختلفان = بصمةٌ واحدة');
        $this->assertSame(2, (int) ErrorEvent::where('file', 'a.php')->value('count'));
        $this->assertSame(1, (int) ErrorEvent::where('file', 'a.php')->value('users'));

        $this->actingAs($this->employee);
        ErrorLog::capture('php', 'RuntimeException: record 3f1e9c2a-3333-4c2b-9d2e-000000000003 exploded', 'a.php', 10);
        $row = ErrorEvent::where('file', 'a.php')->first();
        $this->assertSame(2, (int) $row->users, 'مستخدمٌ ثانٍ تأثّر');
        $this->assertCount(2, $row->meta['users']);
        // الرسالةُ المخزَّنة هي رسالةُ أول وقوع كما وقعت
        $this->assertStringContainsString('000000000001', $row->message);
    }

    public function test_error_center_filters_by_category_and_severity(): void
    {
        $this->seedCore();
        ErrorLog::capture('php', 'Illuminate\Database\QueryException: SQLSTATE[HY000] gone away', 'db.php', 1, null, ['category' => 'DATABASE', 'severity' => 'CRITICAL']);
        ErrorLog::capture('js', 'TypeError in browser', 'app.js', 3);

        $this->actingAs($this->owner)->get('/admin/errors?sev=CRITICAL')->assertOk()
            ->assertSee('gone away')->assertDontSee('TypeError in browser');
        $this->actingAs($this->owner)->get('/admin/errors?cat=APPLICATION')->assertOk()
            ->assertSee('TypeError in browser')->assertDontSee('gone away');
        // قيمةٌ من خارج القائمة تُتجاهَل لا ٥٠٠
        $this->actingAs($this->owner)->get('/admin/errors?cat=DROP&sev=x')->assertOk();
        $this->actingAs($this->owner)->get('/admin/errors/' . ErrorEvent::where('file', 'db.php')->value('id'))
            ->assertOk()->assertSee('CRITICAL');
    }

    public function test_public_error_page_shows_the_request_id(): void
    {
        $this->seedCore();
        config(['app.debug' => false]);
        Route::middleware('web')->get('/_err_page', fn () => throw new \RuntimeException('hidden'));
        $res = $this->actingAs($this->owner)->get('/_err_page');
        $res->assertStatus(500);
        $rid = $res->headers->get('X-Request-Id');
        $this->assertNotEmpty($rid);
        $res->assertSee('request_id: ' . $rid, false);
        $res->assertDontSee('hidden');
        // ويحمله صفُّ مركز الأخطاء نفسُه — الشاشةُ والسجلُّ يلتقيان بالمعرّف
        $this->assertSame($rid, ErrorEvent::where('message', 'like', '%hidden%')->value('request_id'));
    }

    /* ── الربط ── */

    public function test_request_id_flows_into_audit_outbox_webhook_and_notification(): void
    {
        $this->seedCore();
        \App\Models\Webhook::create(['name' => 'w', 'url' => 'https://example.com/hook', 'secret' => 's', 'events' => '*', 'active' => true]);
        $flow = \App\Models\Flow::create(['name' => 'f', 'module' => 'clients', 'event' => 'created', 'enabled' => true,
            'actions' => [['type' => 'tg', 'text' => 'عميل {name}'], ['type' => 'notify', 'to' => $this->employee->id, 'text' => 'x']]]);

        $res = $this->actingAs($this->owner)->post('/m/clients', ['name' => 'مرتبط']);
        $res->assertRedirect();
        $rid = $res->headers->get('X-Request-Id');
        $this->assertNotEmpty($rid);

        $c = Client::where('name', 'مرتبط')->firstOrFail();
        $this->assertSame($rid, DB::table('audits')->where('module', 'clients')->where('record_id', $c->id)->value('request_id'), 'التدقيق');
        $this->assertSame($rid, DB::table('outbox')->where('channel', 'tg')->value('request_id'), 'الصندوق الصادر');
        $this->assertSame($rid, DB::table('webhook_deliveries')->value('request_id'), 'تسليم الويبهوك');
        $this->assertSame($rid, DB::table('notifications_hub')->where('user_id', $this->employee->id)->value('request_id'), 'الإشعار');

        // والسلسلةُ التدقيقية سليمة: request_id ليس من الأعمدة المختومة فلا يمسّها
        $this->assertTrue(\App\Support\Audit::verifyTail()['ok']);
    }

    public function test_log_lines_carry_request_context(): void
    {
        $this->seedCore();
        $seen = [];
        Log::listen(function ($event) use (&$seen) { $seen[] = $event->context; });
        Route::middleware('web')->get('/_log_probe', function () { Log::warning('probe'); return 'ok'; });
        $res = $this->actingAs($this->owner)->get('/_log_probe')->assertOk();
        $ctx = collect($seen)->last();
        $this->assertSame($res->headers->get('X-Request-Id'), $ctx['request_id'] ?? null);
        $this->assertSame('/_log_probe', $ctx['path'] ?? null);
        $this->assertSame($this->owner->id, $ctx['user_id'] ?? null);
    }

    public function test_json_log_channel_is_configured(): void
    {
        $this->assertSame(\Monolog\Formatter\JsonFormatter::class, config('logging.channels.json.formatter'));
    }
}
