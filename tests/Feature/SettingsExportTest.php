<?php

namespace Tests\Feature;

use App\Support\Settings;
use Tests\TestCase;

/**
 * (WP-9.4 · spec §7.11 · §35) **تصديرُ الإعدادات الآمن.**
 *
 * نقلُ إعداداتِ تنصيبٍ إلى آخر كان يعني فتحَ جدول `settings` ونسخَه — فيُنقل
 * معه ما لا يُنقل: أسرارٌ مشفَّرةٌ بمفتاحِ تطبيقٍ آخر (فتصل نصّاً لا يُفكّ)،
 * وصفوفُ **حالةٍ** لا إعداد (نبضاتُ المجدولات، وآخرُ نجاحِ تكامل، ورايةُ الوضع
 * التجريبي) فتُكذب على المراقبة في التنصيب الجديد من أول دقيقة.
 *
 * فهنا ثلاثةُ حدودٍ يثبّتها الاختبار:
 *   · لا سرَّ ولا قيمةَ `enc:` تخرج البتّة — ولو مرّةً واحدة.
 *   · لا صفَّ حالةٍ يخرج: تُصنَّف **ببادئةٍ صريحة** لأنّ أكثرها لا مدخلَ له في
 *     الكتالوج أصلاً (‏`heartbeat.<job>` و`integration.<key>.last_ok` عائلاتٌ
 *     ديناميّة لا مفاتيحُ ثابتة).
 *   · التصديرُ سحبُ بياناتٍ جماعيّ: يحترم مفتاحَ الطوارئ (٤٢٣) ويترك أثراً.
 */
class SettingsExportTest extends TestCase
{
    /** يهيّئ تنصيباً فيه إعدادٌ حقيقي وسرٌّ وصفوفُ حالةٍ من كل عائلة */
    protected function seedInstall(): void
    {
        $this->seedCore();
        $this->hubSetting('app.name', 'منشأةُ الاختبار');
        $this->hubSetting('app.color', '#123456');

        // سرٌّ مخزَّنٌ كما تخزّنه الشاشة: مشفَّراً ببادئة enc:
        Settings::put('odoo.key', 'MySuperSecretOdooKey', 'screen');

        // صفوفُ حالةٍ من كل عائلة مستثناة — بعضُها بلا مدخلٍ في الكتالوج إطلاقاً
        $this->hubSetting('heartbeat.backup', '2026-09-05 03:00:00');
        $this->hubSetting('integration.odoo.last_ok', '2026-09-05 03:05:00');
        $this->hubSetting('demo.on', '1');
        $this->hubSetting('custom.fields_seq', '7');
        $this->hubSetting('esign.tpl_seeded', '["عقد عمل"]');
    }

    protected function exportJson(): array
    {
        $r = $this->actingAs($this->owner)->get('/admin/settings/export')->assertOk();
        $body = $r->getContent();
        $data = json_decode($body, true);
        $this->assertIsArray($data, 'التصديرُ ليس JSON صالحاً');

        return [$data, $body];
    }

    /** لا سرَّ ولا `enc:` في التصدير — ولا نصُّ السرّ الصريح في أيّ موضعٍ من الملف */
    public function test_export_carries_no_secret_value(): void
    {
        $this->seedInstall();
        [$data, $body] = $this->exportJson();

        $this->assertStringNotContainsString('MySuperSecretOdooKey', $body, 'نصُّ السرّ الصريح خرج في التصدير');
        $this->assertStringNotContainsString('enc:', $body, 'قيمةٌ مشفَّرة خرجت في التصدير');

        foreach (Settings::secrets() as $k) {
            $this->assertArrayNotHasKey($k, $data['settings'] ?? [], "مفتاحٌ حسّاس ({$k}) في حمولة التصدير");
        }
    }

    /** لا صفَّ حالةٍ في التصدير — تُصنَّف ببادئةٍ صريحة لا بوجود مدخلٍ في الكتالوج */
    public function test_export_carries_no_state_rows(): void
    {
        $this->seedInstall();
        [$data] = $this->exportJson();

        foreach (array_keys($data['settings'] ?? []) as $k) {
            foreach (Settings::STATE_PREFIXES as $p) {
                $this->assertFalse(str_starts_with($k, $p), "صفُّ حالةٍ ({$k}) خرج في التصدير");
            }
        }
        $this->assertArrayNotHasKey('heartbeat.backup', $data['settings'] ?? []);
        $this->assertArrayNotHasKey('demo.on', $data['settings'] ?? []);
        $this->assertArrayNotHasKey('custom.fields_seq', $data['settings'] ?? []);
        $this->assertArrayNotHasKey('esign.tpl_seeded', $data['settings'] ?? []);
    }

    /**
     * ولا رايةَ تشغيلٍ تُنقل: `maintenance.on` معروضٌ في الكتالوج لكنّ شاشتَه
     * ليست هذه (‏`readonly`) — فتصديرُه ثم استيرادُه في تنصيبٍ آخر يرفع صيانتَه
     * من ملفٍّ نصّي، بلا تأكيدِ الهوية والرسالةِ والأثر التي في مركز التشغيل.
     */
    public function test_export_carries_no_runtime_flag(): void
    {
        $this->seedInstall();
        $this->hubSetting('maintenance.on', '1');
        [$data] = $this->exportJson();

        $this->assertArrayNotHasKey('maintenance.on', $data['settings'] ?? [],
            'رايةُ تشغيلٍ تملكها شاشةٌ أخرى خرجت في التصدير');
        foreach (array_keys(Settings::RUNTIME_FLAGS) as $k) {
            $this->assertArrayNotHasKey($k, $data['settings'] ?? []);
        }
    }

    /**
     * **ولا مسارَ ملفٍّ على قرصِ هذا التنصيب.**
     *
     * قيمةُ مفتاحٍ من نوع `img` ليست إعداداً بل **مساراً** لملفٍّ رُفع هنا
     * (‏`hub/branding/xxxx.png` على القرص العام). نقلُه إلى تنصيبٍ آخر يكتب مساراً
     * لا ملفَّ خلفه: شعارٌ مكسورٌ في الشريط الجانبي وفي بطاقة الدخول وفي ترويسة
     * كلِّ عرضِ سعرٍ وأمرِ شراءٍ يُطبع للعملاء — وهو **العيبُ نفسُه** الذي تُستبعد
     * لأجله صفوفُ الحالة: قيمةٌ صادقةٌ هنا تكذب هناك. والشعارُ يُرفع من شاشته.
     */
    public function test_export_carries_no_local_file_path(): void
    {
        $this->seedInstall();
        $this->hubSetting('app.logo', 'hub/branding/nAq7Local.png');
        [$data, $body] = $this->exportJson();

        $this->assertArrayNotHasKey('app.logo', $data['settings'] ?? [],
            'مسارُ ملفٍّ على قرص هذا التنصيب خرج في التصدير');
        $this->assertStringNotContainsString('nAq7Local.png', $body);

        // ولا يُستورد كذلك — الرفضُ عند القراءة لا عند الكتابة وحدَها
        $plan = Settings::importPlan(['app.logo' => 'hub/branding/other.png']);
        $this->assertSame([], $plan['ok'], 'مسارُ صورةٍ قُبل في خطّة الاستيراد');
        $this->assertSame('app.logo', $plan['bad'][0]['key'] ?? null);
    }

    /** الحمولةُ تحمل ختمَ الزمن والنسخة — وإلا فلا يُعرف على أي نظامٍ يُطبَّق */
    public function test_export_is_stamped_with_time_and_version(): void
    {
        $this->seedInstall();
        [$data] = $this->exportJson();

        $this->assertNotEmpty($data['exported_at'] ?? '', 'التصديرُ بلا ختمِ زمن');
        $this->assertSame((string) config('hub.version'), (string) ($data['version'] ?? ''),
            'نسخةُ التصدير لا تطابق نسخةَ النظام الحيّة');
        $this->assertSame('منشأةُ الاختبار', $data['settings']['app.name'] ?? null,
            'مفتاحٌ معروضٌ غيرُ حسّاس لم يخرج في التصدير');
    }

    /** تجميدُ التصدير مفتاحُ طوارئٍ يشمل هذا المسار أيضاً — ٤٢٣ ولا بايتَ ولا أثر */
    public function test_export_is_blocked_by_the_export_freeze(): void
    {
        $this->seedInstall();
        $this->hubSetting('security.freeze_exports', '1');

        $r = $this->actingAs($this->owner)->get('/admin/settings/export');
        $r->assertStatus(423);
        $r->assertHeaderMissing('Content-Disposition');
        $this->assertDatabaseMissing('audits', ['action' => 'تصدير', 'module' => 'settings']);
    }

    /** التصديرُ حدثٌ أمنيّ مصنَّف (DATA_EXPORT) — يُكتب في التدقيق */
    public function test_export_is_audited(): void
    {
        $this->seedInstall();
        $this->exportJson();

        $this->assertDatabaseHas('audits', ['action' => 'تصدير', 'module' => 'settings']);
        $this->assertSame('DATA_EXPORT',
            \App\Support\SecurityEvents::codeFor('تصدير', 'settings'),
            'فعلُ التصدير لا يُصنَّف DATA_EXPORT');
    }

    /** الإعداداتُ للمالك وحدَه — والتصديرُ منها */
    public function test_export_is_owner_only(): void
    {
        $this->seedInstall();
        $this->actingAs($this->employee)->get('/admin/settings/export')->assertForbidden();
    }
}
