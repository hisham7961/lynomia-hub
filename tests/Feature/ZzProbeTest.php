<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZzProbeTest extends TestCase
{
    /** PROBE 1: does a retryable failure persist state back to 'queued'? */
    public function test_probe_webhook_state_after_retryable_failure(): void
    {
        $this->seedCore();
        Http::fake(['*' => Http::response('boom', 500)]);

        $h = Webhook::create(['name' => 'x', 'url' => 'http://receiver.test/hook',
            'secret' => 's', 'events' => '*', 'active' => true]);
        $d = WebhookDelivery::create(['webhook_id' => $h->id, 'event' => 'clients.created',
            'event_id' => 'e1', 'payload' => '{}', 'state' => 'queued', 'created_at' => now()]);

        // simulate exactly what HubOutbox::webhooks() does
        $fresh = WebhookDelivery::with('webhook')->where('id', $d->id)->first();
        WebhookDelivery::where('id', $fresh->id)->where('state', 'queued')->update(['state' => 'sending']);
        $fresh->state = 'queued';
        \App\Support\WebhookDispatcher::send($fresh);

        $row = DB::table('webhook_deliveries')->where('id', $d->id)->first();
        fwrite(STDERR, "\nPROBE1 state=" . $row->state . " tries=" . $row->tries . " next_at=" . $row->next_at . "\n");
        $this->assertTrue(true);
    }

    /** PROBE 2: backoff indexing across all attempts */
    public function test_probe_backoff_sequence(): void
    {
        $this->seedCore();
        Http::fake(['*' => Http::response('boom', 500)]);
        $h = Webhook::create(['name' => 'x', 'url' => 'http://receiver.test/hook',
            'secret' => 's', 'events' => '*', 'active' => true]);
        $d = WebhookDelivery::create(['webhook_id' => $h->id, 'event' => 'clients.created',
            'event_id' => 'e2', 'payload' => '{}', 'state' => 'queued', 'created_at' => now()]);

        $out = [];
        for ($i = 1; $i <= 9; $i++) {
            $fresh = WebhookDelivery::with('webhook')->where('id', $d->id)->first();
            $fresh->state = 'queued';
            try {
                \App\Support\WebhookDispatcher::send($fresh);
            } catch (\Throwable $e) {
                $out[] = "attempt $i EXCEPTION: " . get_class($e) . ' ' . $e->getMessage();
                break;
            }
            $row = DB::table('webhook_deliveries')->where('id', $d->id)->first();
            $out[] = "attempt $i -> state={$row->state} tries={$row->tries} next_at={$row->next_at}";
            DB::table('webhook_deliveries')->where('id', $d->id)->update(['state' => 'queued']);
        }
        fwrite(STDERR, "\nPROBE2\n" . implode("\n", $out) . "\n");
        $this->assertTrue(true);
    }

    /** PROBE 3: wants() matching */
    public function test_probe_wants(): void
    {
        $cases = [
            ['clients.created', 'clients.created'],
            ['clients.created', 'clients.create'],
            ['clients.created', 'clients'],
            ['clients.created', '*'],
            ['clients.created', 'clients.*'],
            ['clients.created', '*.created'],
            ['clients.created', 'projects.created,clients.updated'],
            ['clients.status', 'clients.status'],
            ['clients.created', ''],
            ['clients.created', 'CLIENTS.CREATED'],
            ['clients.created', 'clients.created.x'],
            ['fin.created', 'fin'],
        ];
        $out = [];
        foreach ($cases as [$ev, $sub]) {
            $w = new Webhook(['events' => $sub]);
            $out[] = sprintf('event=%-16s sub=%-28s wants=%s', $ev, "'$sub'", $w->wants($ev) ? 'YES' : 'no');
        }
        fwrite(STDERR, "\nPROBE3\n" . implode("\n", $out) . "\n");
        $this->assertTrue(true);
    }

    /** PROBE 4: quality merge — what gets repointed, what is lost */
    public function test_probe_merge(): void
    {
        $this->seedCore();
        $keep = Client::create(['name' => 'أصلي', 'email' => '']);
        $dup  = Client::create(['name' => 'أصلي', 'email' => 'a@b.com', 'phone' => '111']);

        $q = DB::table('quotes')->insert(['id' => (string) \Illuminate\Support\Str::uuid(),
            'doc_no' => 'Q1', 'client_id' => $dup->id, 'date' => now()->toDateString(),
            'amount' => 10, 'total' => 10, 'currency' => 'د.ك', 'status' => 'مسودة',
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('comments')->insert(['id' => (string) \Illuminate\Support\Str::uuid(),
            'module' => 'clients', 'record_id' => $dup->id, 'body' => 'تعليق',
            'user_id' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inbox_documents')->insert(['id' => (string) \Illuminate\Support\Str::uuid(),
            'path' => 'p', 'orig' => 'o', 'size' => 1, 'status' => 'مصنف',
            'module' => 'clients', 'record_id' => $dup->id, 'created_at' => now()]);

        $resp = $this->actingAs($this->owner)->from('/admin/quality')
            ->post('/admin/quality/merge', ['keep' => $keep->id, 'ids' => $dup->id]);
        fwrite(STDERR, "\nPROBE4 status=" . $resp->getStatusCode() . "\n");

        $k = Client::find($keep->id);
        fwrite(STDERR, 'keep email=' . var_export($k->email, true) . ' phone=' . var_export($k->phone, true) . "\n");
        fwrite(STDERR, 'quote client_id points to keep? ' .
            (DB::table('quotes')->where('client_id', $keep->id)->exists() ? 'YES' : 'NO') . "\n");
        fwrite(STDERR, 'comment record_id points to keep? ' .
            (DB::table('comments')->where('record_id', $keep->id)->exists() ? 'YES' : 'NO') . "\n");
        fwrite(STDERR, 'inbox doc record_id points to keep? ' .
            (DB::table('inbox_documents')->where('record_id', $keep->id)->exists() ? 'YES' : 'NO') . "\n");
        fwrite(STDERR, 'session=' . json_encode(session()->all()['ok'] ?? session()->get('errors')?->all() ?? [], JSON_UNESCAPED_UNICODE) . "\n");
        $this->assertTrue(true);
    }

    /** PROBE 5: quality index grouping keys */
    public function test_probe_quality_groups(): void
    {
        $this->seedCore();
        Client::create(['name' => 'واحد', 'phone' => '99001122']);
        Client::create(['name' => 'واحد ', 'phone' => '9900-1122']);
        Client::create(['name' => 'ثاني', 'email' => '']);
        Client::create(['name' => 'ثالث', 'email' => '']);
        $r = $this->actingAs($this->owner)->get('/admin/quality');
        fwrite(STDERR, "\nPROBE5 status=" . $r->getStatusCode() . "\n");
        $groups = $r->original->getData()['groups'] ?? [];
        foreach ($groups as $ids => $g) {
            fwrite(STDERR, "group by={$g['by']} ids={$ids} n=" . $g['rows']->count() . "\n");
        }
        $this->assertTrue(true);
    }

    /** PROBE 6: audit verify on a live chain */
    public function test_probe_audit_verify(): void
    {
        $this->seedCore();
        Client::create(['name' => 'أ']);
        $c = Client::create(['name' => 'ب']);
        $c->update(['name' => 'ج']);
        $c->delete();
        $code = \Illuminate\Support\Facades\Artisan::call('hub:audit-verify');
        fwrite(STDERR, "\nPROBE6 exit={$code}\n" . \Illuminate\Support\Facades\Artisan::output() . "\n");
        fwrite(STDERR, 'audits total=' . DB::table('audits')->count()
            . ' hashed=' . DB::table('audits')->whereNotNull('hash')->count() . "\n");
        $this->assertTrue(true);
    }

    /** PROBE 7: inbox classify by name */
    public function test_probe_inbox_classify(): void
    {
        $this->seedCore();
        Client::create(['name' => 'مطاعم الذواقة']);
        Client::create(['name' => 'مطاعم الذواقة الثانية']);
        $doc = \App\Models\InboxDocument::create(['path' => 'p', 'orig' => 'o', 'size' => 1,
            'status' => 'وارد', 'created_at' => now()]);
        $r = $this->actingAs($this->owner)->from('/inboxdocs')
            ->post('/inboxdocs/' . $doc->id . '/classify', ['module' => 'clients', 'record' => 'مطاعم الذواقة']);
        fwrite(STDERR, "\nPROBE7 status=" . $r->getStatusCode() . ' errors='
            . json_encode(session('errors')?->all() ?? [], JSON_UNESCAPED_UNICODE) . "\n");
        fwrite(STDERR, 'doc record_id=' . var_export($doc->fresh()->record_id, true) . "\n");
        $this->assertTrue(true);
    }
}
