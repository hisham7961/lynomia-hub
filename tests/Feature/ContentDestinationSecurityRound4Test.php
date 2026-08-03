<?php

namespace Tests\Feature;

use App\Models\MediaItem;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\WebhookDispatcher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * الموجة الرابعة — أمان المحتوى والوجهة الخارجية:
 * (أ) كتالوج مركز الإعلام يطبع «رابط المادة» خاماً في href بلا hub_safe_url —
 *     فقيمة javascript: يخزّنها محرّرُ media تُنفَّذ في جلسة أي مُشاهد (XSS مخزّن).
 * (ب) تسليم الويبهوك يتّبع إعادة التوجيه، فوجهةٌ عامّة تردّ 302 نحو عنوانٍ داخلي
 *     (169.254.169.254) يُتّبع — التفافٌ على بوابة SSRF التي تفحص الوجهة الأولى فقط.
 */
class ContentDestinationSecurityRound4Test extends TestCase
{
    /** (أ) رابط javascript: لا يخرج خاماً في href بكتالوج مركز الإعلام */
    public function test_media_center_neutralises_javascript_url(): void
    {
        $this->seedCore();
        MediaItem::create(['title' => 'ظهور إعلامي', 'url' => 'javascript:alert(document.cookie)',
            'date' => now()->toDateString(), 'status' => 'منشور']);

        $this->actingAs($this->owner)->get('/media-center')
            ->assertOk()
            ->assertDontSee('href="javascript:', false);
    }

    /** (ب) تسليم الويبهوك لا يتّبع 302 نحو عنوانٍ داخلي */
    public function test_webhook_delivery_does_not_follow_redirect_to_internal(): void
    {
        $this->seedCore();
        Http::fake([
            'attacker.example/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            '169.254.169.254/*'  => Http::response('SECRET-CLOUD-METADATA', 200),
        ]);

        $wh = Webhook::create(['name' => 'ssrf', 'url' => 'https://attacker.example/redir',
            'secret' => 'whs_x', 'events' => '*', 'active' => true]);
        $d = WebhookDelivery::create(['webhook_id' => $wh->id, 'event' => 'test.event',
            'event_id' => 'e1', 'payload' => '{}', 'state' => 'queued', 'tries' => 0, 'created_at' => now()]);

        WebhookDispatcher::send($d);

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '169.254.169.254'));
    }
}
