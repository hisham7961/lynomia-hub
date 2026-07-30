<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InboxDocument;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** انحدارات الجولة الثانية من التدقيق المعادي (مسار التسليم والدمج والتصنيف) */
class AuditRound2Test extends TestCase
{
    public function test_failed_delivery_returns_to_queue_not_stuck_sending(): void
    {
        $this->seedCore();
        Http::fake(['*' => Http::response('boom', 500)]);

        $h = Webhook::create(['name' => 'وجهة معطوبة', 'url' => 'http://receiver.test/h',
            'secret' => 's', 'events' => '*', 'active' => true]);
        $d = WebhookDelivery::create(['webhook_id' => $h->id, 'event' => 'clients.created',
            'event_id' => 'e-1', 'payload' => '{}', 'state' => 'queued', 'created_at' => now()]);

        Artisan::call('hub:outbox');

        $row = DB::table('webhook_deliveries')->where('id', $d->id)->first();
        $this->assertSame('queued', $row->state);          // كانت تعلق في sending
        $this->assertSame(1, (int) $row->tries);
        $this->assertNotNull($row->next_at);               // وموعد الإعادة مضبوط
    }

    public function test_successful_delivery_marked_sent_through_the_worker(): void
    {
        $this->seedCore();
        Http::fake(['*' => Http::response('ok', 200)]);

        $h = Webhook::create(['name' => 'سليمة', 'url' => 'http://receiver.test/h',
            'secret' => 's', 'events' => '*', 'active' => true]);
        $d = WebhookDelivery::create(['webhook_id' => $h->id, 'event' => 'clients.created',
            'event_id' => 'e-2', 'payload' => '{}', 'state' => 'queued', 'created_at' => now()]);

        Artisan::call('hub:outbox');

        $this->assertSame('sent', DB::table('webhook_deliveries')->where('id', $d->id)->value('state'));
        $this->assertTrue((bool) $h->fresh()->last_ok);
    }

    public function test_bare_module_subscription_matches_all_its_events(): void
    {
        $h = new Webhook(['events' => 'clients']);
        $this->assertTrue($h->wants('clients.created'));
        $this->assertTrue($h->wants('clients.updated'));
        $this->assertTrue($h->wants('clients.status'));
        $this->assertFalse($h->wants('projects.created'));

        // وغير حساسة لحالة الأحرف
        $this->assertTrue((new Webhook(['events' => 'TICKETS.Created']))->wants('tickets.created'));
    }

    public function test_merge_repoints_inbox_documents(): void
    {
        $this->seedCore();
        $keep = Client::create(['name' => 'شركة النور', 'email' => 'dup@x.co']);
        $dup  = Client::create(['name' => 'شركه النور', 'email' => 'dup@x.co']);

        $doc = InboxDocument::create(['path' => 'p', 'orig' => 'فاتورة.pdf', 'size' => 1,
            'status' => 'مصنف', 'module' => 'clients', 'record_id' => $dup->id, 'created_at' => now()]);

        $this->actingAs($this->owner)->post('/admin/quality/merge', [
            'keep' => $keep->id, 'ids' => collect([$keep->id, $dup->id])->sort()->implode(','),
        ])->assertRedirect();

        $this->assertSame($keep->id, $doc->fresh()->record_id);   // كانت تبقى معلّقة بسجل محذوف
    }

    public function test_exact_name_wins_over_partial_when_classifying(): void
    {
        $this->seedCore();
        $exact = Client::create(['name' => 'مطاعم الذواقة']);
        Client::create(['name' => 'مطاعم الذواقة الثانية']);

        $doc = InboxDocument::create(['path' => 'p', 'orig' => 'o', 'size' => 1,
            'status' => 'وارد', 'created_at' => now()]);

        $this->actingAs($this->owner)->from('/inboxdocs')
            ->post('/inboxdocs/' . $doc->id . '/classify', ['module' => 'clients', 'record' => 'مطاعم الذواقة'])
            ->assertRedirect();

        $this->assertSame($exact->id, $doc->fresh()->record_id);   // كان يرفضها كاسم ملتبس
        $this->assertSame('مصنف', $doc->fresh()->status);
    }

    public function test_like_wildcards_in_the_name_are_escaped(): void
    {
        $this->seedCore();
        Client::create(['name' => 'عميل واحد فقط']);
        $doc = InboxDocument::create(['path' => 'p', 'orig' => 'o', 'size' => 1,
            'status' => 'وارد', 'created_at' => now()]);

        // «%» كان يطابق كل السجلات فيُصنَّف المستند لسجل عشوائي
        $this->actingAs($this->owner)->from('/inboxdocs')
            ->post('/inboxdocs/' . $doc->id . '/classify', ['module' => 'clients', 'record' => '%'])
            ->assertSessionHasErrors('record');
        $this->assertNull($doc->fresh()->record_id);

        // و«_» كذلك
        $this->actingAs($this->owner)->from('/inboxdocs')
            ->post('/inboxdocs/' . $doc->id . '/classify', ['module' => 'clients', 'record' => '____'])
            ->assertSessionHasErrors('record');
        $this->assertNull($doc->fresh()->record_id);
    }

    public function test_paused_hook_queues_events_for_after_the_pause_instead_of_losing_them(): void
    {
        $this->seedCore();
        $h = Webhook::create(['name' => 'موقوف مؤقتاً', 'url' => 'http://receiver.test/h',
            'secret' => 's', 'events' => '*', 'active' => true,
            'fail_streak' => 10, 'paused_until' => now()->addHour()]);

        $this->actingAs($this->owner)->post('/m/clients', ['name' => 'حدث أثناء الإيقاف'])->assertRedirect();

        $d = WebhookDelivery::where('webhook_id', $h->id)->first();
        $this->assertNotNull($d, 'الحدث كان يضيع نهائياً أثناء الإيقاف');
        $this->assertSame('queued', $d->state);
        $this->assertTrue($d->next_at->isFuture());          // ولا يُرسل قبل انتهاء الإيقاف

        // والعامل لا يلمسه ما دام الإيقاف سارياً
        Http::fake(['*' => Http::response('ok', 200)]);
        Artisan::call('hub:outbox');
        $this->assertSame('queued', $d->fresh()->state);
    }

    public function test_manually_disabled_hook_still_drops_events(): void
    {
        $this->seedCore();
        $h = Webhook::create(['name' => 'معطّل يدوياً', 'url' => 'http://receiver.test/h',
            'secret' => 's', 'events' => '*', 'active' => false]);

        $this->actingAs($this->owner)->post('/m/clients', ['name' => 'حدث'])->assertRedirect();
        $this->assertSame(0, WebhookDelivery::where('webhook_id', $h->id)->count());
    }

    public function test_repeated_slow_requests_increment_one_row_per_tier(): void
    {
        $this->seedCore();
        \App\Models\Setting::create(['key' => 'ops.slow_ms', 'value' => '50']);
        \Illuminate\Support\Facades\Cache::forget('settings:all');
        $mw = new \App\Http\Middleware\Observability;

        // نفس المسار وبطء ضمن المرتبة نفسها = صف واحد بعدّاد، لا ثلاثة صفوف
        foreach ([60, 90, 120] as $ms) {
            $req = \Illuminate\Http\Request::create('/m/clients', 'GET');
            $mw->handle($req, function () use ($ms) {
                usleep($ms * 1000);
                return response('ok');
            });
        }

        $rows = \App\Models\ErrorEvent::where('kind', 'slow')->get();
        $this->assertCount(1, $rows, 'كل طلب بطيء كان ينشئ صفاً جديداً');
        $this->assertSame(3, (int) $rows->first()->count);
        $this->assertStringContainsString('طلب بطيء', $rows->first()->message);
    }

    public function test_genuinely_ambiguous_partial_still_refuses(): void
    {
        $this->seedCore();
        Client::create(['name' => 'مطاعم الشرق']);
        Client::create(['name' => 'مطاعم الغرب']);

        $doc = InboxDocument::create(['path' => 'p', 'orig' => 'o', 'size' => 1,
            'status' => 'وارد', 'created_at' => now()]);

        $this->actingAs($this->owner)->from('/inboxdocs')
            ->post('/inboxdocs/' . $doc->id . '/classify', ['module' => 'clients', 'record' => 'مطاعم'])
            ->assertSessionHasErrors('record');
        $this->assertSame('وارد', $doc->fresh()->status);
    }
}
