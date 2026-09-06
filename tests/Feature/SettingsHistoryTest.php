<?php

namespace Tests\Feature;

use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **كاتبٌ واحد للإعدادات وتاريخٌ لكل مفتاح** (WP-9.2 · spec §7.5 · §7.6 · §31).
 *
 * كان لجدول `settings` **عشرةُ كتّابٍ متوازين**: شاشةُ الإعدادات، ومركزُ
 * المراسلة (بريدٌ وتلجرام)، ولوحةُ n8n، واتصالُ أودو الافتراضي، ومفاتيحُ
 * الطوارئ في مركز الأمن، وتبديلُ الصيانة، و`hub:set`، و`hub:import` — كلٌّ
 * منهم يكتب بيده ويتذكّر (أو ينسى) `Cache::forget`، وثلاثةٌ منهم بلا أثرِ
 * تدقيقٍ إطلاقاً. فسؤالُ §48 «من غيّر هذا المفتاح آخرَ مرة؟» كان بلا جواب:
 * لا لأنّ الجوابَ ضائعٌ، بل لأنّ **أحداً لم يكتبه**.
 *
 * وهذا الحارس يثبّت خمسةَ أشياء:
 *   ١) كلُّ مسارٍ من العشرة يمرّ بـ`Settings::put` فيُنتج **صفَّ تاريخٍ**
 *      في `setting_changes` و**قيدَ تدقيقٍ واحداً** للدفعة (لا لكل مفتاح).
 *   ٢) الأسرارُ مبصومةٌ في الاثنين — لا نصَّ سرٍّ في تاريخٍ ولا في تدقيق.
 *   ٣) `hub:set` و`hub:import` صارا مُدقَّقَين لأول مرة، والثاني مُتحقَّقاً منه.
 *   ٤) `Health::beat` و`Integrations::pulse` **يبقيان خارجَه**: حالةٌ تشغيلية
 *      لا إعداد — نبضةٌ كلَّ خمس دقائق ليست «تعديلَ إعدادات».
 *   ٥) «آخرُ تعديل (من/متى)» يُعرض لكل مفتاح، ويقرأ التاريخَ الجديد مع
 *      **ارتدادٍ إلى `audits`** للصفوف السابقة للجدول.
 */
class SettingsHistoryTest extends TestCase
{
    /* ═══════════════ ١) شاشةُ الإعدادات: صفٌّ لكل مفتاح وقيدٌ واحد ═══════════════ */

    public function test_the_settings_screen_records_history_and_exactly_one_audit(): void
    {
        $this->seedCore();
        $this->hubSetting('ops.slow_ms', '1000');

        $this->actingAs($this->owner)->post(route('settings.update'), [
            'app_name' => 'ليونوميا', 'ops_slow_ms' => '2500',
        ])->assertRedirect();

        $rows = DB::table('setting_changes')->orderBy('key')->orderBy('id')->get();
        $this->assertSame(['app.name', 'ops.slow_ms'], $rows->pluck('key')->all(),
            'صفوفُ التاريخ لا تُغطّي كلَّ مفتاحٍ تغيّر');

        foreach ($rows as $r) {
            $this->assertSame('screen', $r->source);
            $this->assertSame((string) $this->owner->id, (string) $r->user_id, 'التاريخُ بلا صاحبٍ');
            $this->assertNotNull($r->audit_id, 'صفُّ التاريخ بلا رابطٍ لقيد التدقيق');
            $this->assertNotEmpty($r->created_at);
        }

        $slow = $rows->firstWhere('key', 'ops.slow_ms');
        $this->assertSame('1000', json_decode((string) $slow->before, true), 'القيمةُ القديمة غائبة');
        $this->assertSame('2500', json_decode((string) $slow->after, true), 'القيمةُ الجديدة غائبة');

        // قيدُ تدقيقٍ **واحد** للدفعة كلِّها — لا قيدٌ لكل مفتاح (وهو ما يحرسه
        // EnterpriseHardeningRound1Test::test_settings_change_audit_carries_before_and_after)
        $audits = DB::table('audits')->where('action', 'تعديل إعدادات النظام')->pluck('id');
        $this->assertCount(1, $audits, 'الدفعةُ الواحدة كتبت أكثرَ من قيدِ تدقيق');
        $this->assertSame([(int) $audits[0]], $rows->pluck('audit_id')->map(fn ($v) => (int) $v)->unique()->values()->all());
    }

    /** ولا صفَّ تاريخٍ لما لم يتغيّر — الحفظُ بالقيمة نفسِها ليس تعديلاً */
    public function test_an_unchanged_value_writes_no_history_row(): void
    {
        $this->seedCore();
        $this->hubSetting('ops.slow_ms', '1000');

        $this->actingAs($this->owner)->post(route('settings.update'), ['ops_slow_ms' => '1000'])->assertRedirect();

        $this->assertSame(0, DB::table('setting_changes')->count(), 'قيمةٌ لم تتغيّر كُتب لها تاريخ');
        $this->assertSame(0, DB::table('audits')->where('action', 'تعديل إعدادات النظام')->count());
    }

    /* ═══════════════ ٢) كلُّ كاتبٍ من العشرة ═══════════════ */

    public function test_the_messaging_center_mail_form_writes_history(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post(route('integrations.messaging.mail'), [
            'host' => 'smtp.example.com', 'port' => '587', 'encryption' => 'tls',
            'username' => 'bot@example.com', 'password' => 'MAIL-PLAIN-9f2b',
            'from_address' => 'no-reply@example.com', 'from_name' => 'ليونوميا',
        ])->assertRedirect();

        $keys = DB::table('setting_changes')->orderBy('key')->orderBy('id')->pluck('key')->all();
        $this->assertContains('mail.host', $keys);
        $this->assertContains('mail.password', $keys);
        $this->assertSame(['messaging'], DB::table('setting_changes')->distinct()->pluck('source')->all());
        $this->assertSame(1, DB::table('audits')->where('action', 'تعديل إعدادات النظام')->count(),
            'نموذجُ البريد كتب أكثرَ من قيدِ تدقيقٍ واحد');
        $this->assertNoPlaintextAnywhere('MAIL-PLAIN-9f2b');
    }

    public function test_the_messaging_center_telegram_form_writes_history(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post(route('integrations.messaging.telegram'), [
            'tg_token' => 'TG-PLAIN-77aa', 'tg_chat' => '@lynomia',
        ])->assertRedirect();

        $this->assertHistoryFor('notify.tg_token', 'messaging');
        $this->assertHistoryFor('notify.tg_chat', 'messaging');
        $this->assertNoPlaintextAnywhere('TG-PLAIN-77aa');
    }

    public function test_the_n8n_panel_writes_history(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post(route('integrations.n8n.save'), [
            'url' => 'https://n8n.example.com', 'key' => 'N8N-PLAIN-4c1d',
        ])->assertRedirect();

        $this->assertHistoryFor('n8n.url', 'n8n');
        $this->assertHistoryFor('n8n.key', 'n8n');
        $this->assertNoPlaintextAnywhere('N8N-PLAIN-4c1d');
    }

    public function test_the_odoo_defaults_form_writes_history(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post(route('integrations.odoo.defaults'), [
            'url' => 'https://odoo.example.com', 'db' => 'prod', 'username' => 'reader',
            'key' => 'ODOO-PLAIN-31fe',
        ])->assertRedirect();

        $this->assertHistoryFor('odoo.url', 'odoo');
        $this->assertHistoryFor('odoo.key', 'odoo');
        $this->assertNoPlaintextAnywhere('ODOO-PLAIN-31fe');
    }

    public function test_the_security_freeze_switch_writes_history(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post(route('security.freeze', 'exports'))->assertRedirect();

        $on = $this->assertHistoryFor('security.freeze_exports', 'security');
        $this->assertNull(json_decode((string) $on->before, true), 'لا صفَّ قبلها — فـ«قبل» فراغ');
        $this->assertSame('1', json_decode((string) $on->after, true));
        // والقيدُ هو حدثُ الأمن نفسُه لا قيدَ إعداداتٍ ثانٍ بجانبه
        $this->assertSame(1, DB::table('audits')->whereIn('id', [$on->audit_id])->count());
        $this->assertSame('تجميد تصدير البيانات (طوارئ)',
            (string) DB::table('audits')->where('id', $on->audit_id)->value('action'));
    }

    public function test_the_lockdown_switch_writes_history(): void
    {
        $this->seedCore();

        // قفلُ الطوارئ فعلٌ حرجٌ في الاتجاهين — تأكيدُ الهوية أولاً
        $this->actingAs($this->owner)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/admin/security']);
        $this->actingAs($this->owner)->post(route('security.lockdown'))->assertRedirect();

        $row = $this->assertHistoryFor('security.lockdown', 'security');
        $this->assertSame('1', json_decode((string) $row->after, true));
    }

    public function test_the_maintenance_toggle_writes_history(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post(route('ops.maintenance'))->assertRedirect();

        $row = $this->assertHistoryFor('maintenance.on', 'ops');
        $this->assertSame('1', json_decode((string) $row->after, true));
    }

    /** `hub:set` — كان يكتب بلا أثرٍ إطلاقاً، فمفتاحٌ أمنيٌّ يُطفأ من الطرفية بلا شاهد */
    public function test_hub_set_is_now_audited_and_recorded(): void
    {
        $this->artisan('hub:set', ['key' => 'security.stepup_minutes', 'value' => '99'])->assertSuccessful();

        $row = $this->assertHistoryFor('security.stepup_minutes', 'cli');
        $this->assertNull($row->user_id, 'أمرُ طرفيةٍ بلا مستخدم — لا يُنسب لأحد');
        $this->assertSame('99', json_decode((string) $row->after, true));

        $audit = DB::table('audits')->where('id', $row->audit_id)->first();
        $this->assertNotNull($audit, 'hub:set ما يزال بلا أثرِ تدقيق');
        $this->assertSame('تعديل إعدادات النظام', (string) $audit->action);
        $this->assertStringContainsString('security.stepup_minutes', (string) $audit->name);
    }

    /** و`hub:import` — كان يكتب الإعدادات بلا تحقّقٍ ولا أثر */
    public function test_hub_import_records_and_validates_restored_settings(): void
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) mkdir($dir, 0700, true);
        $file = $dir . '/hub-history-test.json';
        file_put_contents($file, json_encode([
            'settings' => ['app.currency' => 'ر.س', 'app.color' => 'not-a-color'],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('hub:import', ['file' => $file])->assertExitCode(0);
        @unlink($file);

        $row = $this->assertHistoryFor('app.currency', 'import');
        $this->assertSame('ر.س', json_decode((string) $row->after, true));
        $this->assertNotNull($row->audit_id, 'استعادةُ الإعدادات بلا أثرِ تدقيق');

        // والقيمةُ المخالفةُ لقاعدة الكتالوج تُرفض ولا تُخزَّن بصمت
        $this->assertSame(0, DB::table('setting_changes')->where('key', 'app.color')->count(),
            'قيمةٌ لا تسري خُزّنت من الاستعادة بلا تحقّق');
        $this->assertNotSame('not-a-color', (string) setting('app.color', ''));
    }

    /* ═══════════════ ٣) ما يبقى خارجَ الكاتب ═══════════════ */

    /**
     * النبضةُ وحالةُ التكامل **حالةٌ تشغيلية لا إعداد**: أربعُ مجدولاتٍ كلَّ خمس
     * دقائق تعني ألفَ صفِّ «تعديل إعدادات» في اليوم يغرق التاريخَ الحقيقيّ.
     */
    public function test_heartbeats_and_integration_pulses_write_no_history(): void
    {
        \App\Support\Health::beat('backup', 120);
        \App\Support\Integrations::pulse('odoo', true, null, 35);
        \App\Support\Integrations::pulse('n8n', false, 'تعذّر الاتصال', 900);

        $this->assertSame(0, DB::table('setting_changes')->count(),
            'نبضةٌ تشغيلية كُتبت في تاريخ الإعدادات');
        $this->assertSame(0, DB::table('audits')->where('action', 'تعديل إعدادات النظام')->count());
    }

    /* ═══════════════ ٤) «آخرُ تعديل» على الشاشة ═══════════════ */

    public function test_last_modified_renders_for_each_key(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post(route('settings.update'), ['app_name' => 'ليونوميا'])->assertRedirect();

        $html = $this->actingAs($this->owner)->get('/admin/settings')->assertOk()->getContent();
        $this->assertStringContainsString('آخر تعديل', $html, 'الشاشةُ لا تقول من غيّر المفتاح آخرَ مرة');
        $this->assertStringContainsString($this->owner->name, $html, 'اسمُ من غيّر غائب');
    }

    /** وللصفوف السابقة للجدول: ارتدادٌ إلى `audits` — التاريخُ لا يبدأ من الصفر */
    public function test_last_modified_falls_back_to_audits_for_historical_rows(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        hub_audit('تعديل إعدادات النظام', 'settings', null, 'app.currency',
            ['before' => ['app.currency' => 'د.ك'],
             'after'  => ['app.currency' => 'ر.س', '_keys' => ['app.currency']]]);

        $this->assertSame(0, DB::table('setting_changes')->count(), 'قيدٌ تاريخيٌّ لا صفَّ تاريخٍ له — هذا موضعُ الارتداد');

        $last = Settings::lastChanges(['app.currency']);
        $this->assertArrayHasKey('app.currency', $last, 'الارتدادُ إلى audits لا يعمل');
        $this->assertSame($this->owner->name, $last['app.currency']['user']);
        $this->assertSame('audit', $last['app.currency']['from']);
    }

    /* ═══════════════ أدوات ═══════════════ */

    /** صفُّ تاريخٍ واحدٌ للمفتاح من المصدر المعلَن — ويُعاد للفحص التفصيلي */
    protected function assertHistoryFor(string $key, string $source)
    {
        $rows = DB::table('setting_changes')->where('key', $key)->orderBy('id')->get();
        $this->assertCount(1, $rows, "لا صفَّ تاريخٍ (أو أكثرُ من صفّ) للمفتاح $key");
        $this->assertSame($source, (string) $rows[0]->source, "مصدرُ الكتابة الخطأ للمفتاح $key");
        $this->assertNotNull($rows[0]->audit_id, "صفُّ تاريخ $key بلا رابطٍ لقيد التدقيق");

        return $rows[0];
    }

    /** لا نصَّ سرٍّ في تاريخٍ ولا في تدقيق — بصمةٌ لا غير */
    protected function assertNoPlaintextAnywhere(string $plain): void
    {
        foreach (DB::table('setting_changes')->orderBy('id')->get() as $r) {
            $this->assertStringNotContainsString($plain, (string) $r->before . '|' . (string) $r->after,
                "نصُّ سرٍّ صريحٌ في تاريخ المفتاح {$r->key}");
        }
        foreach (DB::table('audits')->orderBy('id')->get() as $a) {
            $this->assertStringNotContainsString($plain, (string) $a->before . '|' . (string) $a->after . '|' . (string) $a->name,
                'نصُّ سرٍّ صريحٌ في قيد التدقيق');
        }

        // والبصمةُ موجودةٌ فعلاً — لا «لا شيء» يمرّ بوصفه طمساً
        $blob = DB::table('setting_changes')->orderBy('id')->get()
            ->map(fn ($r) => (string) $r->before . (string) $r->after)->implode('');
        $this->assertStringContainsString('sha256:', $blob, 'السرُّ لم يُبصَم في التاريخ');
    }
}
