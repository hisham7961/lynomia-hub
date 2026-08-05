<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\SettingController;
use Tests\TestCase;

/**
 * شاشة الإعدادات كانت نموذجاً مسطّحاً بلا بحثٍ ولا شرحٍ لأثر أي مفتاح ولا
 * ذكرٍ لافتراضيّه — و**١٩ مفتاحاً حيّاً في الكود بلا مدخلٍ فيها إطلاقاً**،
 * منها ما يضبط ساعات العمل التي تُحسب عليها القدرات، وما يُبطل حارس SSRF.
 *
 * والحارس هنا يمنع تكرار الانحراف: كل مفتاح يقرؤه `setting()` في الشيفرة
 * إمّا له مدخلٌ في الشاشة، أو **يُعلَن داخلياً صراحةً** بسببه. لا ثالث.
 */
class SettingsCenterTest extends TestCase
{
    /** كل مفتاحٍ حيّ إمّا معروضٌ أو معلَنٌ داخلياً — لا مفتاح يتيم */
    public function test_no_live_setting_key_is_left_without_a_home(): void
    {
        $known = array_merge(SettingController::exposedKeys(), array_keys(SettingController::internal()));
        $orphans = array_values(array_diff($this->liveKeys(), $known));

        $this->assertSame([], $orphans,
            "مفاتيح يقرؤها الكود ولا مدخل لها ولا إعلان (تُضبط بتحرير القاعدة يدوياً):\n"
            . implode("\n", $orphans));
    }

    /** ولا مدخلٌ لمفتاحٍ لا يقرؤه أحد — الشاشة لا تعِد بما لا أثر له */
    public function test_no_exposed_key_is_dead(): void
    {
        $live = $this->liveKeys();
        $dead = array_values(array_filter(SettingController::exposedKeys(),
            fn ($k) => ! in_array($k, $live, true)));

        $this->assertSame([], $dead,
            "مدخلاتٌ في الشاشة بلا قارئ في الكود — تعدُ بأثرٍ لا يقع:\n" . implode("\n", $dead));
    }

    /** كل مدخل يقول ماذا يتغيّر وما افتراضيّه — لا تسميةٌ عارية */
    public function test_every_setting_explains_itself(): void
    {
        $bare = [];
        foreach (SettingController::catalog() as $group => $items) {
            foreach ($items as $key => $meta) {
                if (trim((string) ($meta['effect'] ?? '')) === '') $bare[] = "$key — بلا شرحٍ لأثره";
                if (! isset($meta['type'])) $bare[] = "$key — بلا نوع مدخل";
            }
        }
        $this->assertSame([], $bare, implode("\n", $bare));
    }

    public function test_the_screen_shows_effects_defaults_and_search(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/admin/settings')->assertOk()->getContent();

        $this->assertStringContainsString('ابحث في الإعدادات', $html, 'بحثٌ داخل الشاشة');
        $this->assertStringContainsString('الافتراضي', $html, 'الافتراضي معروض');
        // v2.307: النموذج بلغة الحقول المملوءة (lx-form) والمجموعات مصفوفة (setgrp)
        $this->assertStringContainsString('class="lx-form"', $html, 'شاشة الإعدادات بلا لغة النموذج الموحّدة');
        $this->assertStringContainsString('setgrp', $html, 'مجموعات الإعدادات غائبة');
        // مفاتيح كانت غائبة تماماً
        foreach (['cost_work_hours', 'ops_slow_ms', 'monitor_timeout', 'esign_remind_days'] as $input) {
            $this->assertStringContainsString('name="' . $input . '"', $html, "المفتاح $input ما زال بلا مدخل");
        }
    }

    /** ما يُبطل حارساً أمنياً يُحاط بتحذيرٍ صريح لا يُدسّ بين البقية */
    public function test_dangerous_settings_are_flagged(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/admin/settings')->assertOk()->getContent();

        $this->assertStringContainsString('monitor_allow_private', $html);
        $this->assertStringContainsString('⚠️', $html, 'المفاتيح الخطرة موسومة');

        $risky = collect(SettingController::catalog())->flatMap(fn ($g) => $g)
            ->filter(fn ($m) => ($m['risk'] ?? '') !== '');
        $this->assertGreaterThanOrEqual(4, $risky->count(), 'مفاتيح الخطر موثّقةٌ بمخاطرها');
    }

    public function test_saving_takes_effect_immediately(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/admin/settings', [
            'app_name' => 'ليونوميا',
            'cost_work_hours' => '7',
            'ops_slow_ms' => '2500',
        ])->assertRedirect();

        $this->assertSame('ليونوميا', setting('app.name'));
        $this->assertSame('7', (string) setting('cost.work_hours'), 'قيمةٌ تسري فور الحفظ بلا مسح كاش يدوي');
        $this->assertSame('2500', (string) setting('ops.slow_ms'));
    }

    /**
     * مفتاحان افتراضيهما «مفعَّل» يُقرآن بمقارنةٍ صارمة على النص «1»، والإطفاء
     * كان يخزّن نصّاً فارغاً — و`setting()` تردّ الافتراضي عند الفراغ. فزرّ
     * الإطفاء يعود مطفأً في الشاشة بينما الحارس يعمل: ضبطٌ يكذب على صاحبه.
     */
    public function test_a_switch_that_defaults_on_can_actually_be_turned_off(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/admin/settings', [
            'sec_hours_on__sent' => '1', 'sec_strict_files__sent' => '1',   // مُرسَلان ومطفآن
        ])->assertRedirect();

        $this->assertNotSame('1', (string) setting('sec.hours_on', '1'),
            'إطفاء «ساعات العمل» لم يُطفئه — القيمة الفارغة ارتدّت للافتراضي');
        $this->assertNotSame('1', (string) setting('sec.strict_files', '1'));

        // ولا ينقلب المطفأ افتراضاً مشتعلاً: القارئ يفحص النص لا وجود الصفّ
        $this->assertFalse((bool) setting('maintenance.on', false), 'مفتاحٌ افتراضيه مطفأ اشتعل بالحفظ');
    }

    public function test_settings_are_owner_only(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get('/admin/settings')->assertForbidden();
        $this->actingAs($this->employee)->post('/admin/settings', ['app_name' => 'اختراق'])->assertForbidden();
    }

    /** المفاتيح التي يقرؤها الكود فعلاً، من المصدر لا من الذاكرة */
    protected function liveKeys(): array
    {
        $out = [];
        foreach (['app', 'resources', 'routes'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (! $f->isFile() || ! preg_match('/\.php$/', $f->getFilename())) continue;
                if (preg_match_all("/setting\(\s*'([a-z0-9_.]+)'/", (string) file_get_contents($f->getPathname()), $m)) {
                    foreach ($m[1] as $k) if (str_contains($k, '.') && ! str_ends_with($k, '.')) $out[$k] = 1;
                }
            }
        }

        return array_values(array_diff(array_keys($out), ['heartbeat.']));
    }
}
