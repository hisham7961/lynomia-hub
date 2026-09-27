<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\ErrorEvent;
use App\Support\Platform\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * **ما أثبتته المراجعةُ العدائيّةُ لـv2.616.0** — كلُّ اختبارٍ هنا يفشل على الشيفرة السابقة.
 */
class Review616FixesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        RateLimiter::clear('pwreset-ip:127.0.0.1');
    }

    private function outboxText(string $kind): string
    {
        return (string) DB::table('outbox')->where('kind', $kind)->orderByDesc('id')->value('text');
    }

    // ═══ ١ · رابطُ الاستعادة من العنوان المضبوط لا من ترويسة Host ═══

    public function test_reset_link_ignores_a_forged_host_header(): void
    {
        config(['app.url' => 'https://hub.lynomia.test']);
        $this->post('http://attacker.example/password/forgot', ['email' => 'emp@test.local']);

        $text = $this->outboxText('password_reset');
        $this->assertStringContainsString('https://hub.lynomia.test/password/reset/', $text);
        $this->assertStringNotContainsString('attacker.example', $text, 'تسميمُ رابط الاستعادة');
    }

    // ═══ ٢ · الرمزُ لا يُخزَّن في جداول القياس ولا في السجلّ ═══

    public function test_opening_a_reset_link_does_not_store_the_token_in_metrics(): void
    {
        $tok = str_repeat('ab12', 16);
        $this->get('/password/reset/' . $tok . '?email=emp%40test.local');

        $routes = DB::table('http_metric_buckets')->orderBy('id')->pluck('route')->implode(' | ');
        $this->assertStringNotContainsString($tok, $routes);
        $this->assertStringContainsString('password/reset/{tok}', $routes);
        $this->assertStringNotContainsString($tok, \App\Support\Platform\Redactor::text('فُتح /password/reset/' . $tok));
    }

    // ═══ ٣ · مستقبِلُ تقارير CSP ═══

    private function report(string $doc, string $ip = '203.0.113.7', ?string $host = null)
    {
        $body = json_encode(['csp-report' => ['document-uri' => $doc, 'effective-directive' => 'script-src-elem',
            'blocked-uri' => 'inline', 'source-file' => $doc, 'disposition' => 'report']]);
        $server = ['CONTENT_TYPE' => 'application/csp-report', 'REMOTE_ADDR' => $ip];
        if ($host) $server['HTTP_HOST'] = $host;

        return $this->call('POST', $host ? 'http://' . $host . '/csp-report' : '/csp-report', [], [], [], $server, $body);
    }

    public function test_csp_reports_do_not_store_path_tokens(): void
    {
        $tok = str_repeat('cd34', 16);
        $this->report(url('/sign/' . $tok));
        $this->report(url('/password/reset/' . $tok));

        $rows = ErrorEvent::where('kind', 'js')->orderBy('id')->get();
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $this->assertStringNotContainsString($tok, (string) $r->message);
            $this->assertStringNotContainsString($tok, (string) $r->file);
        }
    }

    public function test_one_address_cannot_exhaust_the_daily_report_quota(): void
    {
        $cap = \App\Support\Security\ContentSecurity::REPORT_IP_DAILY_CAP;
        for ($i = 0; $i < $cap + 10; $i++) $this->report(url('/m/clients/x' . $i));
        $this->report(url('/m/projects/other'), '198.51.100.9');

        $this->assertSame($cap + 1, ErrorEvent::where('kind', 'js')->count() ?: ErrorEvent::where('kind', 'js')->sum('count'),
            'عنوانٌ واحدٌ يبلغ سقفَه، وغيرُه يمرّ');
    }

    public function test_a_forged_host_cannot_make_foreign_pages_ours(): void
    {
        config(['app.url' => 'https://hub.lynomia.test']);
        $this->report('https://evil.example/x', '203.0.113.7', 'evil.example');
        $this->assertSame(0, ErrorEvent::where('kind', 'js')->count());
    }

    // ═══ ٤ · ختمٌ ضاع بعد الالتزام يُستدرك — والقريبُ «قيدَ الختم» لا «عبث» ═══

    private function unsealedRow(\DateTimeInterface $at): int
    {
        $row = new AuditEntry(['action' => 'تجربة ختم', 'module' => 'tasks', 'name' => 'قيد']);
        $row->created_at = $at;
        $id = DB::table('audits')->insertGetId(array_merge($row->getAttributes(), ['hash' => null, 'prev_hash' => null,
            'created_at' => $at]));

        return (int) $id;
    }

    public function test_a_lost_seal_is_recovered_and_the_chain_verifies(): void
    {
        hub_audit('قيدٌ سابق', 'tasks', 'x', 'قبل');
        DB::table('audit_chain')->where('id', 1)->update(['started_at' => now()->subDays(3)]);
        $lost = $this->unsealedRow(now()->subMinutes(10));
        $old = $this->unsealedRow(now()->subDays(2));

        $this->assertSame('bad', Audit::verifyTail()['state'], 'قبل الاستدراك: قيدٌ بلا بصمة');
        $this->assertSame(1, AuditEntry::sealPending());
        $this->assertNotNull(DB::table('audits')->where('id', $lost)->value('hash'), 'القريبُ يُختم');
        $this->assertNull(DB::table('audits')->where('id', $old)->value('hash'), 'الأقدمُ من ٢٤ ساعة يبقى إنذاراً');
    }

    public function test_a_just_committed_row_awaiting_its_seal_is_not_tamper(): void
    {
        hub_audit('قيدٌ سابق', 'tasks', 'x', 'قبل');
        DB::table('audit_chain')->where('id', 1)->update(['started_at' => now()->subDay()]);
        $this->unsealedRow(now()->subSeconds(5));

        $this->assertNotSame('bad', Audit::verifyTail()['state'], 'قيدٌ قيدَ الختم ليس عبثاً');
        $this->assertSame(0, AuditEntry::sealPending(), 'ولا يُسابَق ختمٌ جارٍ');
    }

    // ═══ ٥ · صفٌّ مختومٌ مزوَّرٌ خارج السلسلة يُكشف ═══

    public function test_a_forged_sealed_row_outside_the_chain_is_detected(): void
    {
        for ($i = 0; $i < 3; $i++) hub_audit('قيدٌ حقيقيّ', 'tasks', 'r' . $i, 'n' . $i);
        $this->assertSame('ok', Audit::verifyTail()['state']);
        $real = AuditEntry::query()->orderByDesc('id')->first();
        DB::table('audits')->insert(['action' => 'forged', 'module' => 'tasks', 'name' => 'مزوَّر',
            'hash' => str_repeat('a', 64), 'prev_hash' => str_repeat('b', 64), 'created_at' => $real->created_at]);

        $this->assertSame('bad', Audit::verifyTail()['state']);
    }
}
