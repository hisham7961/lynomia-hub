<?php

namespace Tests\Feature;

use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **تحقّقُ التبعية كمجموعات** (WP-9.3 · spec §7.9).
 *
 * شاشةُ الإعدادات تحفظ المفاتيحَ **فرادى**، ومركزُ المراسلة ومركزُ التكامل
 * يحفظانها **مجموعةً بقاعدةٍ واحدة**. فالقاعدةُ نفسُها موجودةٌ في موضعٍ ومفقودةٌ
 * في آخر، والنتيجةُ عيبٌ حيّ:
 *
 *  · `MailSettings::apply` تُشعَل بـ`mail.host` وحدَه، ثم **تغلب `.env` كلَّه**:
 *    مستخدمٌ فارغٌ وكلمةٌ فارغة تُكتبان فوق ما في الملف. فمن كتب الخادمَ من شاشة
 *    الإعدادات وحدَه عطّل بريدَ النظام كلَّه — رسائلِ التوقيع ورموزِ التحقق —
 *    بلا رسالةٍ واحدة. ومركزُ المراسلة يشترط الخمسةَ معاً (`required`).
 *  · وروابطُ أودو تمرّ في مركز التكامل بحارس SSRF (`hub_outbound_ok`)، وتمرّ
 *    من شاشة الإعدادات **بلا حارس** — عنوانٌ داخليٌّ يجعل الخادمَ مِجَسّاً.
 *
 * وقاعدةُ الامتناع صريحةٌ كقاعدةِ الفرض: **لا تُخترَع قاعدةٌ لا يقابلها كود**.
 * `notify.quiet` مبذورٌ في `CoreSeeder` بلا أيّ قارئ ⇒ لا تحقّقَ له.
 */
class SettingsDependencyTest extends TestCase
{
    /* ═══════════════ ١) البريد: الخادمُ يستدعي بقيّةَ المجموعة ═══════════════ */

    public function test_an_incomplete_mail_group_is_rejected_before_anything_is_written(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post(route('settings.update'), [
            'mail_host' => 'smtp.example.com',            // وحدَه — بلا مستخدمٍ ولا عنوان مُرسِل
        ])->assertRedirect()->assertSessionHasErrors();

        $this->assertNull(setting('mail.host'),
            'خادمُ SMTP حُفظ وحدَه — مُرسِلٌ حيٌّ نصفَ مضبوطٍ يغلب .env بمستخدمٍ فارغ');
        $this->assertSame(0, DB::table('setting_changes')->count(), 'كُتب تاريخٌ لدفعةٍ مرفوضة');
        $this->assertSame(0, DB::table('audits')->where('action', Settings::AUDIT_ACTION)->count());
    }

    public function test_a_complete_mail_group_is_accepted(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post(route('settings.update'), [
            'mail_host' => 'smtp.example.com', 'mail_port' => '587', 'mail_encryption' => 'tls',
            'mail_username' => 'bot@example.com', 'mail_from_address' => 'no-reply@example.com',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('smtp.example.com', (string) setting('mail.host'));
        $this->assertSame('bot@example.com', (string) setting('mail.username'));
    }

    /** والقاعدةُ نفسُها التي يفرضها مركزُ المراسلة: عنوانُ مُرسِلٍ بريدٌ صحيح */
    public function test_a_malformed_sender_address_is_rejected_as_the_messaging_centre_rejects_it(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post(route('settings.update'), [
            'mail_host' => 'smtp.example.com', 'mail_username' => 'bot@example.com',
            'mail_from_address' => 'ليس بريداً',
        ])->assertRedirect()->assertSessionHasErrors();

        $this->assertNull(setting('mail.host'));
    }

    /* ═══════════════ ٢) أودو: الأربعةُ معاً وحارسُ الوجهة ═══════════════ */

    public function test_odoo_is_refused_an_internal_address_from_the_settings_screen_too(): void
    {
        $this->seedCore();
        $this->app->instance('hub.dns', fn (string $h) => ['10.0.0.7']);   // مضيفٌ يحلّ لعنوانٍ داخلي

        $this->actingAs($this->owner)->post(route('settings.update'), [
            'odoo_url' => 'https://odoo.internal-host.test/', 'odoo_db' => 'prod',
            'odoo_user' => 'reader@example.com', 'odoo_key' => 'K-1',
        ])->assertRedirect()->assertSessionHasErrors();

        $this->assertNull(setting('odoo.url'),
            'رابطُ أودو مرّ من شاشة الإعدادات بلا حارس SSRF الذي يطبّقه مركزُ التكامل');
    }

    public function test_an_incomplete_odoo_group_is_rejected(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post(route('settings.update'), [
            'odoo_url' => 'https://odoo.example.com/',    // بلا قاعدةٍ ولا مستخدمٍ ولا مفتاح
        ])->assertRedirect()->assertSessionHasErrors();

        $this->assertNull(setting('odoo.url'));
    }

    public function test_a_complete_public_odoo_group_is_accepted(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post(route('settings.update'), [
            'odoo_url' => 'https://odoo.example.com/', 'odoo_db' => 'prod',
            'odoo_user' => 'reader@example.com', 'odoo_key' => 'K-1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('https://odoo.example.com/', (string) setting('odoo.url'));
        $this->assertSame('K-1', (string) setting('odoo.key'), 'مفتاحُ أودو لم يُحفَظ مفكوكاً كما يُقرأ');
    }

    /* ═══════════════ ٣) تلجرام وساعاتُ العمل ونطاقاتُ الخطر ═══════════════ */

    /** قناةٌ بلا توكن = كلُّ رسالةِ تلجرام تفشل في `HubOutbox` — قاعدةٌ لها قارئ */
    public function test_a_telegram_channel_without_a_token_is_rejected(): void
    {
        $this->seedCore();

        $this->assertNotSame([], Settings::dependencyErrors(['notify.tg_chat' => '@lynomia']),
            'قناةُ تلجرام قُبلت بلا توكن — كلُّ رسالةٍ سترتدّ من HubOutbox');

        $this->hubSetting('notify.tg_token', 'bot:token');
        $this->assertSame([], Settings::dependencyErrors(['notify.tg_chat' => '@lynomia']));
    }

    public function test_work_hours_must_start_before_the_strict_window(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post(route('settings.update'), [
            'sec_hours_start' => '18:00',                 // بعد بداية الوضع الصارم (17:00)
        ])->assertRedirect()->assertSessionHasErrors();
        $this->assertNull(setting('sec.hours_start'));

        // والصيغةُ أربعُ خاناتٍ: «8:00» تقلب المقارنة النصّية صمتاً
        $this->actingAs($this->owner)->post(route('settings.update'), ['sec_hours_start' => '8:00'])
            ->assertRedirect()->assertSessionHasErrors();
        $this->assertNull(setting('sec.hours_start'));

        $this->actingAs($this->owner)->post(route('settings.update'), ['sec_hours_start' => '07:00'])
            ->assertRedirect();
        $this->assertSame('07:00', (string) setting('sec.hours_start'), 'قيمةٌ صحيحةٌ رُدّت مع الخاطئتين');
    }

    public function test_risk_bands_must_ascend(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post(route('settings.update'), [
            'risk_band_medium' => '70',                   // فوق «عالٍ» (60)
        ])->assertRedirect()->assertSessionHasErrors();
        $this->assertNull(setting('risk.band_medium'));

        $this->actingAs($this->owner)->post(route('settings.update'), [
            'risk_band_medium' => '25', 'risk_band_high' => '50', 'risk_band_critical' => '75',
        ])->assertRedirect();
        $this->assertSame('25', (string) setting('risk.band_medium'), 'نطاقاتٌ صاعدةٌ رُدّت');
        $this->assertSame('75', (string) setting('risk.band_critical'));
    }

    /* ═══════════════ ٤) لا قاعدةَ بلا قارئ ═══════════════ */

    public function test_notify_quiet_gets_no_rule_because_no_code_reads_it(): void
    {
        $this->seedCore();

        $this->assertSame([], Settings::dependencyErrors(['notify.quiet' => '{"on":true,"from":22,"to":7}']),
            'اختُرعت قاعدةٌ لـnotify.quiet وهو مبذورٌ بلا أيّ قارئ');

        $watched = [];
        foreach (Settings::DEPENDS as $group => $rule) foreach ($rule['keys'] as $k) $watched[] = $k;
        $this->assertNotContains('notify.quiet', $watched, 'مفتاحٌ بلا قارئ دخل جدولَ التبعية');

        // ولا مفتاحَ مخترَعٍ في الجدول: كلُّ ما يُسلّح قاعدةً له بيتٌ في الكتالوج
        // (معروضاً أو مُعلَناً داخلياً) — وإلا فقاعدةٌ ميّتةٌ لا تُسلَّح أبداً
        $known = array_merge(Settings::exposedKeys(), array_keys(Settings::internal()));
        $this->assertSame([], array_values(array_diff($watched, $known)),
            "مفاتيحُ تبعيةٍ لا مدخلَ لها:\n" . implode("\n", array_diff($watched, $known)));

        // والدعوى مُثبَتةٌ من المصدر لا من الذاكرة: لا قارئَ له في app/
        $this->assertSame([], $this->readersOf('notify.quiet'),
            'صار لـnotify.quiet قارئٌ — فراجِع قرارَ «لا قاعدةَ له»');
    }

    /* ═══════════════ ٥) لا تُتحقَّق مجموعةٌ لم تُمَسّ ═══════════════ */

    public function test_an_untouched_group_does_not_block_an_unrelated_save(): void
    {
        $this->seedCore();
        $this->hubSetting('mail.host', 'smtp.legacy.test');    // حالةٌ ناقصةٌ سابقةٌ في القاعدة

        $this->actingAs($this->owner)->post(route('settings.update'), ['app_name' => 'ليونوميا'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('ليونوميا', (string) setting('app.name'),
            'حالةٌ ناقصةٌ في مجموعةٍ أخرى منعت حفظَ مفتاحٍ لا صلةَ له بها');
    }

    /** مواضعُ قراءة مفتاحٍ في `app/` — من المصدر لا من الذاكرة */
    protected function readersOf(string $key): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (! $f->isFile() || ! str_ends_with($f->getFilename(), '.php')) continue;
            $src = (string) file_get_contents($f->getPathname());
            if (str_contains($src, "setting('" . $key . "'")) $out[] = $f->getFilename();
        }

        return $out;
    }
}
