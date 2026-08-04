<?php

namespace Tests\Feature;

use App\Models\InboundHook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * بند ٥ (الويبهوك الوارد): نقاطُ استقبالٍ أصلية — نظامٌ خارجيّ (n8n/نموذج) يستدعي
 * رابطاً موقّعاً فيُخزَّن ما أرسله حدثاً. مُصادَقٌ بالرمز في الرابط + توقيع HMAC.
 */
class InboundHookRound5Test extends TestCase
{
    protected function hook(bool $signed = true, bool $enabled = true): InboundHook
    {
        return InboundHook::create([
            'name'       => 'استقبال n8n',
            'token'      => Str::random(48),
            'secret'     => $signed ? Str::random(64) : null,
            'event'      => 'lead.created',
            'enabled'    => $enabled,
            'created_by' => $this->owner->id ?? (string) Str::uuid(),
        ]);
    }

    protected function send(InboundHook $h, string $body, ?string $sig = null)
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($sig !== null) $server['HTTP_X_HUB_SIGNATURE'] = $sig;

        return $this->call('POST', route('hook.receive', $h->token), [], [], [], $server, $body);
    }

    public function test_signed_hook_receives_and_stores_the_event(): void
    {
        $this->seedCore();
        $h = $this->hook(signed: true);
        $body = json_encode(['name' => 'عميل', 'value' => 100], JSON_UNESCAPED_UNICODE);
        $sig = 'sha256=' . hash_hmac('sha256', $body, $h->secret);

        $this->send($h, $body, $sig)->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, DB::table('inbound_hook_events')->where('hook_id', $h->id)->count(),
            'الحمولة الواردة لم تُخزَّن حدثاً');
        $this->assertSame(1, (int) $h->fresh()->hits, 'عدّاد الاستقبال لم يُزَد');
        $this->assertStringContainsString('عميل',
            (string) DB::table('inbound_hook_events')->where('hook_id', $h->id)->value('payload'));
    }

    public function test_wrong_signature_is_rejected_and_stores_nothing(): void
    {
        $this->seedCore();
        $h = $this->hook(signed: true);
        $body = json_encode(['x' => 1]);

        $this->send($h, $body, 'sha256=deadbeef')->assertStatus(401);
        $this->assertSame(0, DB::table('inbound_hook_events')->where('hook_id', $h->id)->count(),
            'حمولةٌ بتوقيعٍ خاطئ خُزّنت — التوقيع لا يحرس');
    }

    public function test_unknown_or_disabled_token_is_404(): void
    {
        $this->seedCore();
        $this->call('POST', route('hook.receive', 'nope-'.Str::random(20)), [], [], [],
            ['CONTENT_TYPE' => 'application/json'], '{}')->assertNotFound();

        $off = $this->hook(signed: false, enabled: false);
        $this->send($off, '{}')->assertNotFound();
    }

    public function test_unsigned_hook_accepts_without_a_signature(): void
    {
        $this->seedCore();
        $h = $this->hook(signed: false);
        $this->send($h, json_encode(['a' => 1]))->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(1, (int) $h->fresh()->hits);
    }

    public function test_owner_manages_hooks_and_others_cannot(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post(route('hooks.store'), ['name' => 'نقطة', 'signed' => '1'])
            ->assertRedirect();
        $this->assertSame(1, InboundHook::count());
        $this->assertNotNull(InboundHook::first()->secret, 'المُوقّعة أُنشئت بلا سرّ');

        $this->actingAs($this->owner)->get(route('hooks.index'))->assertOk()->assertSee('نقطة');

        // غيرُ المالك ممنوع من الإدارة
        $this->actingAs($this->employee)->get(route('hooks.index'))->assertForbidden();
        $this->actingAs($this->employee)->post(route('hooks.store'), ['name' => 'x'])->assertForbidden();
    }
}
