<?php

namespace Tests\Feature;

use App\Support\SecurityEvents;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **استعادةُ الافتراضي — كتابةٌ لا حذف** (WP-9.3 · spec §7.8 · §18 · critic #7).
 *
 * كان المقترحُ أن تكون الاستعادةُ **حذفَ الصفّ** (كما تفعل شاشةُ الأمن لرايات
 * الطوارئ) — وهي لمفاتيحِ الطوارئ صحيحة لأنّ افتراضيَّها «مطفأ». لكنّ في
 * الكتالوج مفتاحَين افتراضيُّهما **مُشغَّل**: `sec.hours_on` و`sec.strict_files`.
 * و`setting()` تردّ الافتراضيَّ عند غياب الصفّ — فحذفُ صفِّ حارسٍ أطفأه المالكُ
 * عمداً **يُعيد إشعالَه**. وهذا انقلابُ حالةٍ لا استعادة، وهو نفسُ العيب الذي
 * أغلقه `SettingController` بتخزين «0» صراحةً بدل الفراغ
 * (`SettingsCenterTest::test_a_switch_that_defaults_on_can_actually_be_turned_off`).
 *
 * فالاستعادةُ هنا **تكتب `default` المُعلَن في الكتالوج** (WP-9.1). والفارقُ
 * لا يُقاس بالقيمة السارية — فهي واحدةٌ في الحالتين — بل **بوجود الصفّ**:
 * صفٌّ مكتوبٌ يقول «هذه إرادةُ المالك»، وغيابُه يقول «ما تسقط إليه الشيفرة».
 * ولا يُحذف الصفُّ إلا لنوعَي `img`/`pass` — لا افتراضيَّ يُكتب لصورةٍ أو لسرّ.
 */
class SettingsRestoreTest extends TestCase
{
    /* ═══════════════ ١) تكتب الافتراضيَّ ولا تحذف الصفّ ═══════════════ */

    public function test_restoring_a_key_writes_the_declared_default_and_keeps_the_row(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.strict_minutes', '999');
        $this->passStepUp();                              // مفتاحٌ أمنيّ — التصعيدُ مُختبَرٌ وحدَه أدناه

        $this->actingAs($this->owner)->post(route('settings.restore'), ['key' => 'sec.strict_minutes'])
            ->assertRedirect();

        $this->assertTrue(DB::table('settings')->where('key', 'sec.strict_minutes')->exists(),
            'الاستعادةُ حذفت الصفَّ بدل أن تكتب الافتراضيَّ المُعلَن');
        $this->assertSame('10', (string) setting('sec.strict_minutes'),
            'القيمةُ السارية بعد الاستعادة ليست افتراضيَّ الكتالوج');
        $this->assertSame(Settings::flat(Settings::defaultOf('sec.strict_minutes')),
            Settings::flat(\App\Models\Setting::query()->where('key', 'sec.strict_minutes')->value('value')),
            'المكتوبُ في الصفّ ليس افتراضيَّ الكتالوج — الاستعادةُ كتبت شيئاً آخر');
    }

    /**
     * **critic #7 بحرفه:** حارسٌ افتراضيُّه «مُشغَّل» أطفأه المالك — استعادتُه
     * تكتب «1» في صفّه (إرادةٌ صريحة)، ولا تحذف الصفَّ (انقلابٌ صامت).
     */
    public function test_a_default_on_guard_is_restored_by_writing_not_by_deleting(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_on', '0');
        $this->passStepUp();

        $this->actingAs($this->owner)->post(route('settings.restore'), ['key' => 'sec.hours_on'])
            ->assertRedirect();

        $this->assertTrue(DB::table('settings')->where('key', 'sec.hours_on')->exists(),
            'صفُّ حارسٍ افتراضيُّه مُشغَّل حُذف — الاستعادةُ صارت اعتماداً على سقوطِ القارئ لا كتابةً');
        $this->assertSame('1', (string) setting('sec.hours_on', '0'));
    }

    /** واستعادةُ **مفتاحٍ آخر** لا تمسّ حارساً أطفأه المالك — ولو دفعةً */
    public function test_a_switch_the_owner_turned_off_stays_off_when_another_key_is_restored(): void
    {
        $this->seedCore();

        // إطفاءٌ من الشاشة نفسِها كما يفعل المالك (مربّعان مُرسَلان ومطفآن)
        $this->actingAs($this->owner)->post(route('settings.update'), [
            'sec_hours_on__sent' => '1', 'sec_strict_files__sent' => '1',
        ])->assertRedirect();
        $this->assertNotSame('1', (string) setting('sec.hours_on', '1'));

        $this->hubSetting('sec.strict_minutes', '999');
        $this->passStepUp();
        $this->actingAs($this->owner)->post(route('settings.restore'), ['key' => 'sec.strict_minutes'])
            ->assertRedirect();

        $this->assertSame('10', (string) setting('sec.strict_minutes'), 'الاستعادةُ لم تقع أصلاً — الاختبارُ لا يقيس شيئاً');
        $this->assertNotSame('1', (string) setting('sec.hours_on', '1'),
            'استعادةُ مفتاحٍ أعادت إشعالَ حارسٍ لم يُطلَب — أثرٌ جانبيٌّ يقلب الأمن');
        $this->assertNotSame('1', (string) setting('sec.strict_files', '1'));
        $this->assertTrue(DB::table('settings')->where('key', 'sec.hours_on')->exists());
    }

    /* ═══════════════ ٢) الصورةُ والسرُّ يُفرَّغان لا يُكتبان ═══════════════ */

    public function test_a_secret_row_is_cleared_because_no_default_is_ever_written_for_it(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post(route('settings.update'), ['quoteflow_pass' => 'MY-OWN-PASS-1'])
            ->assertRedirect();
        $this->assertTrue(DB::table('settings')->where('key', 'quoteflow.pass')->exists());

        $this->actingAs($this->owner)->post(route('settings.restore'), ['key' => 'quoteflow.pass'])
            ->assertRedirect();

        $this->assertFalse(DB::table('settings')->where('key', 'quoteflow.pass')->exists(),
            'صفُّ سرٍّ بقي بعد الاستعادة — لا يُكتب افتراضيُّ سرٍّ في القاعدة');
        $this->assertSame('1998', (string) setting('quoteflow.pass', '1998'));
    }

    /* ═══════════════ ٣) الخطرُ يطلب تصعيدَ هوية ═══════════════ */

    public function test_restoring_a_high_risk_key_demands_step_up(): void
    {
        $this->seedCore();
        $this->hubSetting('auth.pw_min', '6');

        $r = $this->actingAs($this->owner)->post(route('settings.restore'), ['key' => 'auth.pw_min']);
        $r->assertRedirect();
        $this->assertStringContainsString('/stepup?next=', (string) $r->headers->get('Location'),
            'استعادةُ مفتاحٍ عالي الخطورة مرّت بلا تأكيدِ هوية');
        $this->assertSame('6', (string) setting('auth.pw_min'), 'استُعيد قبل التأكيد');

        $this->actingAs($this->owner)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/admin/settings'])
            ->assertRedirect('/admin/settings');
        $this->actingAs($this->owner)->post(route('settings.restore'), ['key' => 'auth.pw_min'])->assertRedirect();
        $this->assertSame('10', (string) setting('auth.pw_min'), 'لم يُستعَد بعد تأكيد الهوية');
    }

    /** ومفتاحٌ لا خطرَ فيه يُستعاد بلا تصعيد — الحارسُ ليس ضريبةً على كل نقرة */
    public function test_restoring_a_plain_key_needs_no_step_up(): void
    {
        $this->seedCore();
        $this->hubSetting('ops.slow_ms', '9999');

        $r = $this->actingAs($this->owner)->post(route('settings.restore'), ['key' => 'ops.slow_ms']);
        $r->assertRedirect();
        $this->assertStringNotContainsString('/stepup', (string) $r->headers->get('Location'));
        $this->assertSame('1000', (string) setting('ops.slow_ms'));
    }

    /* ═══════════════ ٤) الأثرُ والتاريخ ═══════════════ */

    public function test_restore_is_audited_under_its_own_registered_code(): void
    {
        $this->seedCore();
        $this->hubSetting('ops.slow_ms', '9999');

        $this->actingAs($this->owner)->post(route('settings.restore'), ['key' => 'ops.slow_ms'])->assertRedirect();

        $audit = DB::table('audits')->where('action', Settings::RESTORE_ACTION)->orderBy('id')->first();
        $this->assertNotNull($audit, 'استعادةُ الافتراضي بلا أثرٍ في التدقيق');
        $this->assertSame('SETTINGS_RESTORED', SecurityEvents::codeFor(Settings::RESTORE_ACTION, 'settings', null, 'ops.slow_ms'),
            'فعلُ الاستعادة غيرُ مسجَّلٍ في SecurityEvents::CODES');

        $row = DB::table('setting_changes')->where('key', 'ops.slow_ms')->orderBy('id')->first();
        $this->assertNotNull($row, 'الاستعادةُ بلا صفِّ تاريخ');
        $this->assertSame('restore', (string) $row->source, 'بابُ الكتابة ليس «restore»');
        $this->assertSame((int) $audit->id, (int) $row->audit_id);
        $this->assertSame('9999', json_decode((string) $row->before, true));
        $this->assertSame('1000', json_decode((string) $row->after, true));
    }

    /** واستعادةُ مفتاحٍ أمنيٍّ حدثٌ أمنيّ لا مجرّدُ «تعديل إعدادات» */
    public function test_restoring_a_security_key_classifies_as_a_policy_change(): void
    {
        $this->assertSame('SECURITY_POLICY_CHANGED',
            SecurityEvents::codeFor(Settings::RESTORE_ACTION, 'settings', null, 'auth.pw_min'));
    }

    /* ═══════════════ ٥) المجموعةُ دفعةً واحدة ═══════════════ */

    public function test_a_whole_group_restores_in_one_batch_with_one_audit_entry(): void
    {
        $this->seedCore();
        $this->hubSetting('app.name', 'اسمٌ مضبوط');
        $this->hubSetting('app.currency', 'ريال');

        // مجموعةٌ بلا مفتاحٍ عالي الخطورة — التصعيدُ مُختبَرٌ وحدَه أعلاه
        $group = '🏷️ الهوية والعرض';
        $this->assertArrayHasKey($group, Settings::catalog(), 'اسمُ المجموعة تبدّل — الاختبارُ يقيس اسماً لا سلوكاً');

        $this->actingAs($this->owner)->post(route('settings.restore'), ['group' => $group])->assertRedirect();

        $this->assertNull(setting('app.name'), 'اسمُ النظام لم يعد إلى افتراضيه (فارغٌ ⇐ يسقط إلى APP_NAME)');
        $this->assertSame('د.ك', (string) setting('app.currency'));
        $this->assertCount(1, DB::table('audits')->where('action', Settings::RESTORE_ACTION)->pluck('id'),
            'استعادةُ المجموعة كتبت قيدَ تدقيقٍ لكل مفتاح بدل قيدٍ واحدٍ للدفعة');
        $this->assertSame(2, DB::table('setting_changes')->where('source', 'restore')->count(),
            'صفوفُ تاريخ الاستعادة لا تُغطّي كلَّ ما تغيّر في المجموعة');
    }

    public function test_restore_is_owner_only(): void
    {
        $this->seedCore();
        $this->hubSetting('ops.slow_ms', '9999');

        $this->actingAs($this->employee)->post(route('settings.restore'), ['key' => 'ops.slow_ms'])->assertForbidden();
        $this->actingAs($this->viewer)->post(route('settings.restore'), ['key' => 'ops.slow_ms'])->assertForbidden();
        $this->assertSame('9999', (string) setting('ops.slow_ms'));
    }

    /** ومفتاحٌ خارجَ الكتالوج لا يُستعاد — لا افتراضيَّ مُعلَنَ له أصلاً */
    public function test_an_unknown_key_is_refused(): void
    {
        $this->seedCore();
        $this->hubSetting('heartbeat.backup', '2026-01-01');

        $this->actingAs($this->owner)->post(route('settings.restore'), ['key' => 'heartbeat.backup'])
            ->assertRedirect();
        $this->assertSame('2026-01-01', (string) setting('heartbeat.backup'),
            'مفتاحٌ داخليٌّ بلا افتراضيٍّ مُعلَن أُعيد ضبطُه من شاشة الإعدادات');
    }

    /** والزرُّ في الشاشة على سكّة التأكيد القائمة لا نقرةً عمياء */
    public function test_the_screen_offers_restore_behind_a_confirmation(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get(route('settings.edit'))->assertOk()->getContent();

        $this->assertStringContainsString(route('settings.restore'), $html, 'لا زرَّ استعادةٍ في الشاشة');
        $this->assertStringContainsString('data-confirm', $html, 'الاستعادةُ بلا تأكيد');
    }

    /** تصعيدُ هويةٍ ناجح — لاختباراتٍ تقيس الاستعادةَ لا حارسَها */
    protected function passStepUp(): void
    {
        $this->actingAs($this->owner)
            ->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/admin/settings'])
            ->assertRedirect('/admin/settings');
    }
}
