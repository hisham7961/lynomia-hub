<?php

namespace Tests\Feature;

use App\Models\OutboxMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * **مركز المراسلة** — كل طرق التواصل الخارجة في شاشة واحدة.
 *
 * أخبثُ ما يكشفه: `MAIL_MAILER=log` الافتراضي — بريدٌ «ينجح» إلى ملف
 * السجل ولا يصل أحداً. وأثمنُ ما يصلحه: حلقةُ `notify_prefs` الميتة —
 * عاملُ التسليم يقرؤها منذ بنائه ولا واجهةَ كانت تكتبها.
 */
class MessagingCenterTest extends TestCase
{
    public function test_center_is_owner_only_and_renders_without_config_or_http(): void
    {
        $this->seedCore();
        Http::fake();

        $this->actingAs($this->employee)->get('/admin/integrations/messaging')->assertForbidden();

        $html = $this->actingAs($this->owner)->get('/admin/integrations/messaging')
            ->assertOk()->getContent();
        $this->assertStringContainsString('مركز المراسلة', $html);
        $this->assertStringContainsString('تلجرام', $html);
        $this->assertCount(0, Http::recorded(), 'شاشة العرض نادت الشبكة');
    }

    /** بريدُ «السجل» نجاحُه كاذب — التحذير الأحمر واجب */
    public function test_log_mailer_shows_a_loud_warning(): void
    {
        $this->seedCore();
        config(['mail.default' => 'log']);

        $html = $this->actingAs($this->owner)->get('/admin/integrations/messaging')->getContent();

        $this->assertStringContainsString('وضع السجل', $html);
        $this->assertStringContainsString('لا يغادر الخادم', $html);

        config(['mail.default' => 'smtp']);
        $html2 = $this->actingAs($this->owner)->get('/admin/integrations/messaging')->getContent();
        $this->assertStringContainsString('يرسل فعلاً', $html2);
    }

    public function test_telegram_test_send_walks_the_real_delivery_path(): void
    {
        $this->seedCore();
        $this->hubSetting('notify.tg_token', 'tok123');
        $this->hubSetting('notify.tg_chat', '-100555');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->actingAs($this->owner)
            ->post('/admin/integrations/messaging/test', ['channel' => 'tg'])
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame(1, OutboxMessage::where('kind', 'test')->where('state', 'sent')->count(),
            'التجريبية لم تسلك مسار التسليم الحقيقي');
        Http::assertSent(fn ($req) => str_contains($req->url(), 'bottok123/sendMessage')
            && $req['chat_id'] === '-100555');
    }

    public function test_failed_test_send_reports_the_error_not_a_fake_success(): void
    {
        $this->seedCore();
        $this->hubSetting('notify.tg_token', 'tok123');
        // لا وجهة: لا chat ولا تفضيل — الفشل الحقيقي يُروى بسببه
        Http::fake();

        $this->actingAs($this->owner)
            ->post('/admin/integrations/messaging/test', ['channel' => 'tg'])
            ->assertSessionHas('err', fn ($m) => str_contains((string) $m, 'وجهة'));

        $this->assertSame(1, OutboxMessage::where('kind', 'test')->where('state', 'failed')->count());
    }

    public function test_retry_button_requeues_and_delivers(): void
    {
        $this->seedCore();
        $this->hubSetting('notify.tg_token', 'tok123');
        $this->hubSetting('notify.tg_chat', '-100555');
        OutboxMessage::create(['kind' => 'flow', 'channel' => 'tg', 'text' => 'فشلت سابقاً',
            'state' => 'failed', 'error' => 'قديم', 'created_at' => now()]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->actingAs($this->owner)->post('/admin/integrations/messaging/retry')
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame(0, OutboxMessage::where('state', 'failed')->count());
        $this->assertSame(1, OutboxMessage::where('state', 'sent')->count());
    }

    /* ── الحلقة الميتة: تفضيلات الوجهة صار لها بابٌ يكتبها ── */

    public function test_notify_prefs_can_finally_be_written_from_the_profile(): void
    {
        $this->seedCore();

        $this->actingAs($this->employee)->put('/profile/notify', [
            'tg' => '123456789', 'email' => 'alt@test.local',
        ])->assertRedirect()->assertSessionHas('ok');

        $prefs = $this->employee->fresh()->notify_prefs;
        $this->assertSame('123456789', $prefs['tg']);
        $this->assertSame('alt@test.local', $prefs['email']);

        // والمحو يمحو — لا قيم شبحية تسرّب التنبيهات لوجهة قديمة
        $this->actingAs($this->employee)->put('/profile/notify', ['tg' => '', 'email' => '']);
        $this->assertSame([], (array) $this->employee->fresh()->notify_prefs);

        // ومعرفٌ غير رقمي يُرفض — سيُمرَّر حرفياً لتلجرام فيضيع التنبيه صامتاً
        $this->actingAs($this->employee)->put('/profile/notify', ['tg' => '@myname'])
            ->assertSessionHasErrors('tg');
    }

    /** الإثبات الوظيفي: التفضيل المكتوب من الواجهة تسلكه الرسالة فعلاً */
    public function test_delivery_actually_uses_the_written_preference(): void
    {
        $this->seedCore();
        $this->hubSetting('notify.tg_token', 'tok123');
        $this->hubSetting('notify.tg_chat', '-100999');   // العامة — يجب ألا تُستعمل

        $this->actingAs($this->employee)->put('/profile/notify', ['tg' => '777001']);

        OutboxMessage::create(['user_id' => $this->employee->id, 'kind' => 'digest',
            'channel' => 'tg', 'text' => 'ملخصك', 'state' => 'queued', 'created_at' => now()]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        \Illuminate\Support\Facades\Artisan::call('hub:outbox');

        Http::assertSent(fn ($req) => $req['chat_id'] === '777001');
        $this->assertSame('sent', OutboxMessage::where('kind', 'digest')->value('state'));
    }

    public function test_profile_shows_the_prefs_form(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->employee)->get('/profile')->assertOk()->getContent();

        $this->assertStringContainsString('وجهات التنبيه', $html);
        $this->assertStringContainsString('name="tg"', $html);
    }

    public function test_center_gateway_and_guide_cover_messaging(): void
    {
        $this->seedCore();

        $center = $this->actingAs($this->owner)->get('/admin/integrations')->getContent();
        $this->assertStringContainsString('مركز المراسلة', $center);

        $msg = $this->actingAs($this->owner)->get('/admin/integrations/messaging')->getContent();
        foreach (['BotFather', 'getUpdates', 'MAIL_MAILER=smtp', 'سلسلة الوجهات', '--retry'] as $needle) {
            $this->assertStringContainsString($needle, $msg, "الدليل ناقص: {$needle}");
        }
    }

    public function test_mail_test_uses_laravel_mailer(): void
    {
        $this->seedCore();
        Mail::fake();

        $this->actingAs($this->owner)
            ->post('/admin/integrations/messaging/test', ['channel' => 'mail'])
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame('sent', OutboxMessage::where('kind', 'test')->value('state'));
    }
}
