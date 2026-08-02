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

    /** «شرح طريقة ربط الإيميل من وإلى والتوسع بها» — الاتجاهان موثقان بالوصفة */
    public function test_email_guide_covers_both_directions(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/admin/integrations/messaging')->getContent();

        $this->assertStringContainsString('البريد من وإلى', $html);
        // الصادر: التسليم الحقيقي وسمعة المرسِل
        $this->assertStringContainsString('SPF/DKIM', $html, 'سمعة المرسِل غير مشروحة — البريد سيصل مزعجات');
        // الوارد: وصفة n8n → تذاكر كاملةً بمنع الازدواج
        $this->assertStringContainsString('Email Trigger (IMAP)', $html);
        $this->assertStringContainsString('/api/v1/tickets', $html);
        $this->assertStringContainsString('Idempotency-Key', $html, 'بلا منع ازدواجٍ ستتكرر التذاكر عند كل إعادة');
        $this->assertStringContainsString('لا يسحب صندوقَ بريدٍ بنفسه', $html, 'حد التصميم غير مصرَّحٍ به');
        // والتوسع: كل وحدة لها بابها
        $this->assertStringContainsString('وارد المستندات', $html);
    }

    /* ── «ليش بالخادم؟ ليش ما أضيفها بالمشروع كخانات؟» — حقول SMTP في النظام ── */

    public function test_mail_can_be_configured_from_fields_not_env(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/admin/integrations/messaging/mail', [
            'host' => 'smtp.zoho.com', 'port' => 587, 'encryption' => 'tls',
            'username' => 'no-reply@x.com', 'password' => 'app-pass-1',
            'from_address' => 'no-reply@x.com', 'from_name' => 'منشأتي',
        ])->assertRedirect()->assertSessionHas('ok');

        // تُخزَّن في النظام، وكلمة المرور مشفَّرة لا نصاً صريحاً
        $this->assertSame('smtp.zoho.com', setting('mail.host'));
        $this->assertSame('app-pass-1', setting('mail.password'));
        $this->assertStringStartsWith('enc:',
            (string) \App\Models\Setting::where('key', 'mail.password')->value('value'));

        // وتُطبَّق على البريد الحي غالبةً .env
        config(['mail.default' => 'log']);
        \App\Support\MailSettings::apply();
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.zoho.com', config('mail.mailers.smtp.host'));
        $this->assertSame('app-pass-1', config('mail.mailers.smtp.password'));
        $this->assertSame('no-reply@x.com', config('mail.from.address'));
    }

    public function test_blank_mail_password_keeps_stored_secret(): void
    {
        $this->seedCore();
        $post = fn (string $pass) => $this->actingAs($this->owner)
            ->post('/admin/integrations/messaging/mail', [
                'host' => 'smtp.zoho.com', 'port' => 587, 'encryption' => 'tls',
                'username' => 'a@x.com', 'password' => $pass,
                'from_address' => 'a@x.com',
            ]);

        $post('secret-1');
        $post('');   // فارغة = الإبقاء

        $this->assertSame('secret-1', setting('mail.password'), 'الفراغ محا كلمة المرور المخزونة');
    }

    public function test_screen_shows_the_fields_and_the_source(): void
    {
        $this->seedCore();

        // بلا حقول: المصدر ملف الخادم
        $html = $this->actingAs($this->owner)->get('/admin/integrations/messaging')->getContent();
        $this->assertStringContainsString('ضبط البريد بالحقول', $html);
        $this->assertStringContainsString('ملف .env', $html);

        // بعد ملء الحقول: المصدر حقول النظام
        $this->hubSetting('mail.host', 'smtp.zoho.com');
        $html2 = $this->actingAs($this->owner)->get('/admin/integrations/messaging')->getContent();
        $this->assertStringContainsString('حقول النظام', $html2);
    }

    /** بلا حقول لا يتغير شيء — .env يبقى سيد الموقف: إضافة لا كسر */
    public function test_env_untouched_when_fields_are_empty(): void
    {
        $this->seedCore();
        config(['mail.default' => 'log', 'mail.mailers.smtp.host' => 'env-host']);

        \App\Support\MailSettings::apply();

        $this->assertSame('log', config('mail.default'));
        $this->assertSame('env-host', config('mail.mailers.smtp.host'));
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
