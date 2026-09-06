<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\OutboxMessage;
use App\Support\Integrations;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * (WP-2.6) عملياتُ الصندوق الصادر في مركز التشغيل — وإغلاقُ تسريبٍ حيّ:
 * شاشةُ المراسلة كانت تطبع نصَّ الرسالة الفاشلة كاملاً، ومنها رسائلُ رموز
 * التحقّق (sign_otp) — فرمزُ توقيعٍ ساري المفعول معروضٌ لكل من فتح الشاشة.
 */
class OutboxOpsTest extends TestCase
{
    protected function failedOtp(): OutboxMessage
    {
        return OutboxMessage::create([
            'kind' => 'sign_otp', 'channel' => 'mail', 'target' => 'signer@example.com',
            'text' => 'رمز التحقق للتوقيع: 987654', 'state' => 'failed',
            'error' => 'SMTP connect failed', 'created_at' => now()->subHour(),
        ]);
    }

    /** التسريبُ الحيّ: شاشةُ المراسلة لا تطبع نصَّ رسالة OTP الفاشلة بعد اليوم */
    public function test_messaging_screen_hides_otp_text_of_failed_messages(): void
    {
        $this->seedCore();
        $this->failedOtp();

        $html = $this->actingAs($this->owner)->get('/admin/integrations/messaging')
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('987654', $html,
            'رمز OTP لرسالةٍ فاشلة مطبوعٌ في شاشة المراسلة — التسريب قائم');
    }

    /** معاينةُ الفشل في مركز التشغيل: النصُّ محجوبٌ لأنواع OTP والوجهةُ مقنَّعة */
    public function test_ops_failure_preview_hides_otp_and_masks_destination(): void
    {
        $this->seedCore();
        $this->failedOtp();

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();

        $this->assertStringContainsString('s…@e….com', $html, 'الوجهة المقنَّعة غائبة — المعاينة لا تُعرض');
        $this->assertStringNotContainsString('987654', $html, 'رمز OTP مطبوع في مركز التشغيل');
        $this->assertStringNotContainsString('signer@example.com', $html, 'الوجهة الكاملة مكشوفة');
    }

    /** قواعدُ المقنِّع الواحد — بريدٌ ومعرّفُ محادثة، ونصٌّ محجوبٌ لأنواع OTP وحدها */
    public function test_masker_rules(): void
    {
        $this->assertSame('s…@e….com', Integrations::maskDestination('signer@example.com'));
        $this->assertSame('…555', Integrations::maskDestination('-100555'));
        $this->assertSame('—', Integrations::maskDestination(''));

        foreach (['sign_otp', 'otp'] as $kind) {
            $this->assertStringNotContainsString('987654',
                Integrations::outboxPreview($kind, 'رمزك 987654'));
        }
        $this->assertStringContainsString('مرحبا', Integrations::outboxPreview('alert', 'مرحبا'));
    }

    /** الإعادة للمالك وحده وبتأكيد هوية، وتُدقَّق، وتسلك مسارَ العامل الحقيقي */
    public function test_retry_is_owner_only_with_stepup_and_audited(): void
    {
        $this->seedCore();
        $this->hubSetting('notify.tg_token', 'tok123');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $m = OutboxMessage::create(['kind' => 'alert', 'channel' => 'tg', 'target' => '-100555',
            'text' => 'تنبيه', 'state' => 'failed', 'error' => 'timeout',
            'created_at' => now()->subHour()]);

        $this->actingAs($this->employee)->post("/admin/ops/outbox/{$m->id}/retry")->assertForbidden();

        $this->hubSetting('security.stepup_ops', '1');
        $r = $this->actingAs($this->owner)->post("/admin/ops/outbox/{$m->id}/retry");
        $this->assertStringContainsString('/stepup?next=', (string) $r->headers->get('Location'));
        $this->assertSame('failed', $m->fresh()->state, 'أُعيدت الرسالة بلا تأكيد هوية');

        $this->actingAs($this->owner)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/admin/ops']);
        $this->actingAs($this->owner)->post("/admin/ops/outbox/{$m->id}/retry")->assertRedirect();

        $this->assertSame('sent', $m->fresh()->state, 'الرسالة لم تُسلَّم بعد الإعادة');
        $this->assertSame(1, AuditEntry::where('action', 'إعادة إرسال رسالة صادرة')->count(),
            'الإعادة بلا قيد تدقيق');
    }

    /** معرّفٌ مجهول ⇒ ٤٠٤ — ولا يُعاد إلا الفاشل (المُرسَل لا يُرسَل مرتين) */
    public function test_retry_guards_state_and_unknown_id(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)
            ->post('/admin/ops/outbox/' . Str::uuid() . '/retry')->assertNotFound();

        $sent = OutboxMessage::create(['kind' => 'alert', 'channel' => 'tg', 'target' => '-1',
            'text' => 'x', 'state' => 'sent', 'created_at' => now()->subHour(),
            'delivered_at' => now()->subHour()]);
        $this->actingAs($this->owner)->post("/admin/ops/outbox/{$sent->id}/retry")
            ->assertRedirect()->assertSessionHas('err');
        $this->assertSame('sent', $sent->fresh()->state, 'رسالةٌ مُرسَلة أُعيد صفُّها');
    }

    /** أرقامُ الطابور: أقدمُ منتظرة وعمرُها، والإنتاجية من delivered_at */
    public function test_ops_shows_pending_age_and_throughput(): void
    {
        $this->seedCore();
        OutboxMessage::create(['kind' => 'alert', 'channel' => 'tg', 'target' => '-1',
            'text' => 'a', 'state' => 'queued', 'created_at' => now()->subHours(3)]);
        OutboxMessage::create(['kind' => 'alert', 'channel' => 'mail', 'target' => 'a@b.c',
            'text' => 'b', 'state' => 'sent', 'created_at' => now()->subHours(2),
            'delivered_at' => now()->subMinutes(30)]);

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();

        $this->assertStringContainsString('أقدمُ منتظرة', $html);
        $this->assertStringContainsString('المُسلَّم (٢٤ ساعة)', $html);
        $this->assertStringContainsString('ob-stats', $html, 'لوحة أرقام الصادر غائبة');
    }
}
