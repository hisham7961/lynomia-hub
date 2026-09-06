<?php

namespace Tests\Feature;

use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * (WP-9.4 · spec §7.12) **استيرادُ الإعدادات بستّ خطوات** — رفعٌ ثم تحقّقُ
 * مفاتيح ثم فرقٌ ثم وسمُ خطرٍ ثم تأكيدٌ ثم تطبيق.
 *
 * ملفٌّ يُرفع فيُطبَّق مباشرةً هو أخطرُ ما في شاشة الإعدادات: مفتاحٌ مجهول
 * يُخزَّن فيبدو حيّاً وهو ميت، وقيمةٌ مخالفةٌ للصيغة تُحفظ ولا تسري (فيظنّ
 * المشغّل أن الضبط قائم)، ومفتاحٌ حسّاسٌ يُكتب نصّاً صريحاً من ملفٍّ نصّي.
 *
 * فالحدود التي يثبّتها الاختبار:
 *   · **الفرقُ قبل التطبيق**: خطوةُ الرفع لا تكتب صفّاً واحداً.
 *   · المجهولُ والحسّاسُ والحالةُ والقيمةُ المخالفة **تُرفض** بسببٍ مذكور.
 *   · التطبيقُ يمرّ بالكاتب الواحد (`Settings::put` بمصدر `import`) فيترك
 *     صفَّ تاريخٍ وقيدَ تدقيق — لا كتابةً جانبية.
 *   · و`hub:import` (الاستعادةُ الكاملة) لا يُخلَط بهذا ولا يُمَسّ.
 */
class SettingsImportTest extends TestCase
{
    protected function upload(array $payload)
    {
        $file = UploadedFile::fake()->createWithContent('settings.json',
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $this->actingAs($this->owner)->post('/admin/settings/import', ['file' => $file]);
    }

    /** حمولةٌ فيها واحدٌ من كل صنف: مقبولٌ ومجهولٌ وحسّاسٌ وحالةٌ وقيمةٌ مخالفة */
    protected function mixedPayload(): array
    {
        return [
            'exported_at' => '2026-09-01T10:00:00+00:00',
            'version'     => (string) config('hub.version'),
            'settings'    => [
                'app.name'         => 'منشأةٌ مستورَدة',       // مقبول
                'app.color'        => 'أخضر',                   // قيمةٌ مخالفةٌ للصيغة
                'zzz.unknown_key'  => 'شيء',                    // مجهول
                'odoo.key'         => 'PlainTextSecret',        // حسّاس — يُرفض دائماً
                'heartbeat.backup' => '2020-01-01 00:00:00',    // صفُّ حالة
            ],
        ];
    }

    /** الخطواتُ الأربع الأولى لا تكتب شيئاً: فرقٌ ووسمُ خطرٍ ثم وقوفٌ عند التأكيد */
    public function test_upload_computes_a_diff_without_writing_anything(): void
    {
        $this->seedCore();
        $before = DB::table('settings')->count();

        $this->upload($this->mixedPayload())->assertRedirect();

        $this->assertSame($before, DB::table('settings')->count(), 'خطوةُ الرفع كتبت صفّاً قبل التأكيد');
        $this->assertDatabaseMissing('settings', ['key' => 'app.name', 'value' => 'منشأةٌ مستورَدة']);
        $this->assertSame(0, DB::table('setting_changes')->count(), 'صفُّ تاريخٍ كُتب قبل التأكيد');

        $plan = session('hub.settings.import');
        $this->assertIsArray($plan, 'خطّةُ الاستيراد لم تُسلَّم للخطوة التالية');
        $this->assertSame(['app.name'], array_column($plan['ok'], 'key'), 'المقبولُ ليس مفتاحاً واحداً');
        $this->assertSame('', (string) ($plan['ok'][0]['before'] ?? 'x'), 'الفرقُ لا يقول القيمةَ السابقة');
        $this->assertSame('منشأةٌ مستورَدة', (string) ($plan['ok'][0]['after'] ?? ''));
    }

    /** المجهولُ والحسّاسُ والحالةُ والقيمةُ المخالفة — أربعةُ رفضٍ بسببٍ مذكور */
    public function test_unknown_sensitive_state_and_invalid_keys_are_rejected(): void
    {
        $this->seedCore();
        $this->upload($this->mixedPayload())->assertRedirect();

        $bad = collect(session('hub.settings.import')['bad'] ?? [])->pluck('why', 'key')->all();

        foreach (['zzz.unknown_key', 'odoo.key', 'heartbeat.backup', 'app.color'] as $k) {
            $this->assertArrayHasKey($k, $bad, "مفتاحٌ كان يجب رفضُه مرّ: {$k}");
            $this->assertNotSame('', trim((string) $bad[$k]), "رُفض {$k} بلا سببٍ مذكور");
        }
    }

    /** التطبيق: المقبولُ وحدَه يُكتب، والمرفوضُ لا يصل القاعدةَ أبداً */
    public function test_apply_writes_only_the_accepted_keys(): void
    {
        $this->seedCore();
        $this->upload($this->mixedPayload())->assertRedirect();
        $this->actingAs($this->owner)->post('/admin/settings/import/apply')->assertRedirect();

        $this->assertSame('منشأةٌ مستورَدة', (string) setting('app.name'));
        $this->assertDatabaseMissing('settings', ['key' => 'zzz.unknown_key']);
        $this->assertDatabaseMissing('settings', ['key' => 'app.color']);
        $this->assertNull(DB::table('settings')->where('key', 'odoo.key')->value('value'),
            'مفتاحٌ حسّاسٌ كُتب من ملفِّ استيراد');
        $this->assertNull(DB::table('settings')->where('key', 'heartbeat.backup')->value('value'),
            'صفُّ حالةٍ كُتب من ملفِّ استيراد');
    }

    /** التطبيقُ يمرّ بالكاتب الواحد: صفُّ تاريخٍ بمصدر `import` وقيدُ تدقيق */
    public function test_apply_is_audited_through_the_single_writer(): void
    {
        $this->seedCore();
        $this->upload($this->mixedPayload())->assertRedirect();
        $this->actingAs($this->owner)->post('/admin/settings/import/apply')->assertRedirect();

        $row = DB::table('setting_changes')->where('key', 'app.name')->orderBy('id')->first();
        $this->assertNotNull($row, 'لا صفَّ تاريخٍ للمفتاح المستورَد');
        $this->assertSame('import', (string) $row->source, 'بابُ الكتابة ليس `import`');
        $this->assertNotNull($row->audit_id, 'صفُّ التاريخ غيرُ موصولٍ بقيد تدقيق');
        $this->assertDatabaseHas('audits', ['action' => Settings::AUDIT_ACTION, 'module' => 'settings']);
    }

    /** التأكيدُ بلا خطّةٍ سابقة لا يفعل شيئاً — لا تطبيقَ من طلبٍ عارٍ */
    public function test_apply_without_a_prior_diff_does_nothing(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/admin/settings/import/apply')->assertRedirect();

        $this->assertSame(0, DB::table('setting_changes')->count());
    }

    /** ملفٌّ ليس JSON، أو بلا مفتاح settings — يُردّ بسببه لا يُطبَّق نصفَ تطبيق */
    public function test_a_malformed_file_is_refused(): void
    {
        $this->seedCore();
        $file = UploadedFile::fake()->createWithContent('settings.json', 'ليس JSON البتة');
        $this->actingAs($this->owner)->post('/admin/settings/import', ['file' => $file])
            ->assertRedirect()->assertSessionHasErrors();

        $this->assertNull(session('hub.settings.import'));
    }

    /** ورايةُ التشغيل لا تُستورد: ما لا تكتبه هذه الشاشة لا يكتبه ملفُّها */
    public function test_a_runtime_flag_cannot_be_raised_by_an_imported_file(): void
    {
        $this->seedCore();
        $this->upload(['settings' => ['maintenance.on' => '1']])->assertRedirect();

        $plan = session('hub.settings.import');
        $this->assertSame([], $plan['ok'] ?? ['x'], 'رايةُ تشغيلٍ قُبلت في خطّة الاستيراد');
        $this->assertContains('maintenance.on', array_column($plan['bad'] ?? [], 'key'));

        $this->actingAs($this->owner)->post('/admin/settings/import/apply')->assertRedirect();
        $this->assertFalse((bool) setting('maintenance.on', false), 'رُفعت الصيانةُ من ملفِّ استيراد');
    }

    /** الاستيرادُ للمالك وحدَه */
    public function test_import_is_owner_only(): void
    {
        $this->seedCore();
        $file = UploadedFile::fake()->createWithContent('settings.json', '{"settings":{}}');
        $this->actingAs($this->employee)->post('/admin/settings/import', ['file' => $file])->assertForbidden();
        $this->actingAs($this->employee)->post('/admin/settings/import/apply')->assertForbidden();
    }

    /** مفتاحٌ عالي الخطورة يطلب تأكيدَ الهوية قبل التطبيق — ولا يُكتب قبله */
    public function test_a_high_risk_key_requires_step_up_before_it_is_applied(): void
    {
        $this->seedCore();
        $this->upload(['settings' => ['sec.hours_start' => '09:30']])->assertRedirect();

        $r = $this->actingAs($this->owner)->post('/admin/settings/import/apply');
        $r->assertRedirect();
        $this->assertStringContainsString('/stepup', (string) $r->headers->get('Location'),
            'مفتاحٌ عالي الخطورة طُبّق بلا تأكيد هوية');
        $this->assertNotSame('09:30', (string) setting('sec.hours_start', '08:00'));

        // وبعد التأكيد يُطبَّق كما هو
        $this->actingAs($this->owner)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/']);
        $this->actingAs($this->owner)->post('/admin/settings/import/apply')->assertRedirect();
        $this->assertSame('09:30', (string) setting('sec.hours_start', '08:00'));
    }
}
