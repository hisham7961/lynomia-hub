<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Setting;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ApprovalWebhookTest extends TestCase
{
    public function test_guarded_edit_queues_approval_instead_of_applying(): void
    {
        $this->seedCore();
        Setting::create(['key' => 'approval.rules', 'value' => 'clients:ed']);
        Cache::forget('settings:all');

        $c = Client::create(['name' => 'محمي بالموافقات']);
        $this->actingAs($this->employee)
            ->put('/m/clients/' . $c->id, ['name' => 'تعديل يحتاج موافقة'])
            ->assertRedirect();

        $this->assertSame('محمي بالموافقات', $c->fresh()->name);   // لم يتغير
        $this->assertDatabaseHas('approvals', ['mod' => 'clients', 'record_id' => $c->id, 'status' => 'معلّق']);
    }

    public function test_owner_bypasses_approval_guard(): void
    {
        $this->seedCore();
        Setting::create(['key' => 'approval.rules', 'value' => 'clients:ed']);
        Cache::forget('settings:all');

        $c = Client::create(['name' => 'قبل']);
        $this->actingAs($this->owner)->put('/m/clients/' . $c->id, ['name' => 'بعد'])->assertRedirect();
        $this->assertSame('بعد', $c->fresh()->name);
    }

    public function test_guarded_api_write_gets_409(): void
    {
        $this->seedCore();
        Setting::create(['key' => 'approval.rules', 'value' => 'clients:ed']);
        Cache::forget('settings:all');

        $c = Client::create(['name' => 'ثابت']);
        $tok = $this->apiToken($this->employee);
        $this->withHeader('Authorization', 'Bearer ' . $tok)
            ->putJson('/api/v1/clients/' . $c->id, ['name' => 'تغيير'])->assertStatus(409);
        $this->assertSame('ثابت', $c->fresh()->name);
    }

    public function test_webhook_queued_on_matching_event_only(): void
    {
        $this->seedCore();
        Webhook::create(['name' => 'الكل', 'url' => 'http://receiver.test/hook',
            'secret' => 'whs_test1', 'events' => '*', 'active' => true]);
        Webhook::create(['name' => 'مشاريع فقط', 'url' => 'http://receiver.test/hook2',
            'secret' => 'whs_test2', 'events' => 'projects.*', 'active' => true]);
        Webhook::create(['name' => 'معطل', 'url' => 'http://receiver.test/hook3',
            'secret' => 'whs_test3', 'events' => '*', 'active' => false]);

        $this->actingAs($this->owner)->post('/m/clients', ['name' => 'حدث ويبهوك'])->assertRedirect();

        $events = WebhookDelivery::pluck('event');
        $this->assertSame(1, $events->count());                       // «الكل» فقط — لا المعطل ولا المفلتر
        $this->assertSame('clients.created', $events->first());
        $this->assertSame('queued', WebhookDelivery::first()->state);
    }

    public function test_webhook_payload_excludes_secret_fields_and_wants_patterns(): void
    {
        $this->seedCore();
        $h = new Webhook(['events' => 'tickets.created, projects.*, *.status']);
        $this->assertTrue($h->wants('tickets.created'));
        $this->assertTrue($h->wants('projects.updated'));
        $this->assertTrue($h->wants('fin.status'));
        $this->assertFalse($h->wants('tickets.updated'));
        $this->assertFalse($h->wants('clients.created'));

        Webhook::create(['name' => 'س', 'url' => 'http://r.test/h', 'secret' => 's', 'events' => '*', 'active' => true]);
        $this->actingAs($this->owner)->post('/m/vault', [
            'name' => 'سر الخادم', 'kind' => 'خادم', 'user' => 'root', 'passCipher' => 'SuperSecret!',
        ]);
        $d = WebhookDelivery::where('event', 'vault.created')->first();
        if ($d) $this->assertStringNotContainsString('SuperSecret!', $d->payload);
    }
}
