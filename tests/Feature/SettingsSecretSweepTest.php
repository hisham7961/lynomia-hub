<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * (بوّابةُ نهاية الطور ٩ · §41/١٠) **كنسةُ السرّ الواحدة.**
 *
 * الاختباراتُ المتفرّقة تثبّت كلٌّ سطحَه: التصديرُ لا يحمل سرّاً، والمعاينةُ لا
 * ترسمه، والتاريخُ يبصمه. لكنّ كلَّ واحدٍ منها يبذر **سرّاً واحداً في مفتاحٍ
 * واحد** — فمفتاحٌ حسّاسٌ يُضاف غداً إلى الكتالوج ينجو من الأربعة معاً.
 *
 * فهذه كنسةٌ **مشتقّةٌ من الكتالوج**: تبذر سرّاً مميّزاً في **كلّ** مفتاحٍ
 * موسومٍ `sensitive`، ثم تمرّ على كل سطحٍ يخرج منه نصّ — الشاشة، والتصدير،
 * وسطرُ «آخرُ تعديل»، وحمولةُ المعاينة، وجدولا التاريخ والتدقيق — وتشترط
 * ثلاثةً معاً: لا نصَّ سرٍّ صريحاً، ولا كتلةَ `enc:`، ولا قيمةَ بيئة.
 */
class SettingsSecretSweepTest extends TestCase
{
    /** نصٌّ مميّزٌ لكل مفتاحٍ حسّاس: مفتاح ⇐ سرُّه الصريح */
    protected function seedEverySecret(): array
    {
        $this->seedCore();

        $plain = [];
        $i = 0;
        foreach (Settings::secrets() as $key) {
            $plain[$key] = 'PLAINTEXT-SECRET-' . (++$i) . '-' . str_replace('.', '-', $key);
            Settings::put($key, $plain[$key], 'screen');
        }
        $this->assertNotSame([], $plain, 'لا مفتاحَ حسّاسٌ في الكتالوج — الكنسةُ بلا موضوع');

        // ولا يُقبَل «نظيفٌ» لأن التشفير لم يقع أصلاً: كلُّ سرٍّ مخزَّنٌ enc:
        foreach ($plain as $key => $value) {
            $raw = (string) DB::table('settings')->where('key', $key)->value('value');
            $this->assertStringContainsString('enc:', $raw, "المفتاحُ الحسّاس {$key} خُزِّن نصّاً صريحاً");
            $this->assertStringNotContainsString($value, $raw);
        }

        return $plain;
    }

    /**
     * يشترط على نصٍّ واحد: لا نصَّ سرٍّ صريحاً ولا **كتلةَ تشفيرٍ**.
     *
     * والبحثُ عن كتلةٍ لا عن الكلمة: نصُّ الكتالوج نفسُه يشرح للمشغّل أنّ التوكن
     * «يُخزَّن مشفَّراً (enc:)» — شرحٌ لا تسريب. فالمطلوبُ ألّا تخرج **الحمولة**:
     * ‏`enc:` متبوعةً بـbase64 (وطليعتُها في Laravel دائماً `eyJpdiI6`).
     */
    protected function assertClean(string $body, array $plain, string $where): void
    {
        foreach ($plain as $key => $value) {
            $this->assertStringNotContainsString($value, $body, "نصُّ سرِّ {$key} الصريح ظهر في {$where}");
        }
        $this->assertDoesNotMatchRegularExpression('~enc:\\\\?/?[A-Za-z0-9+/=]{16,}~', $body,
            "كتلةُ تشفيرٍ (‏enc:…) ظهرت في {$where}");
        $this->assertStringNotContainsString('eyJpdiI6', $body, "حمولةُ Crypt الصريحة ظهرت في {$where}");
    }

    /** الشاشةُ والتصديرُ والمعاينةُ والتاريخُ والتدقيق — سطحاً سطحاً بكلّ الأسرار */
    public function test_no_sensitive_key_leaks_on_any_surface(): void
    {
        $plain = $this->seedEverySecret();

        // ١) الشاشة
        $screen = $this->actingAs($this->owner)->get('/admin/settings')->assertOk()->getContent();
        $this->assertClean($screen, $plain, 'شاشة الإعدادات');

        // ٢) التصدير
        $export = $this->actingAs($this->owner)->get('/admin/settings/export')->assertOk()->getContent();
        $this->assertClean($export, $plain, 'ملفّ التصدير');
        foreach (array_keys($plain) as $key) {
            $this->assertArrayNotHasKey($key, (array) (json_decode($export, true)['settings'] ?? []),
                "المفتاحُ الحسّاس {$key} خرج في التصدير");
        }

        // ٣) المعاينة — سرٌّ **جديد** يُكتب في النموذج ثم تُقرأ الحمولةُ والشاشة
        $fresh = [];
        $form = [];
        foreach (array_keys($plain) as $key) {
            $fresh[$key] = 'FRESH-PREVIEW-SECRET-' . str_replace('.', '-', $key);
            $form[str_replace('.', '_', $key)] = $fresh[$key];
        }
        $this->actingAs($this->owner)->post('/admin/settings/preview', $form)->assertRedirect();
        $held = (string) json_encode(session(\App\Http\Controllers\Web\SettingController::PREVIEW_SESSION),
            JSON_UNESCAPED_UNICODE);
        $this->assertClean($held, $fresh, 'حمولةُ المعاينة في الجلسة');
        $this->assertClean($this->actingAs($this->owner)->get('/admin/settings')->getContent(), $fresh, 'شاشةُ المعاينة');

        // ٤) جدولُ التاريخ وجدولُ التدقيق — الطرفان اللذان يعيشان بعد الطلب
        $history = (string) json_encode(DB::table('setting_changes')->orderBy('id')->get(), JSON_UNESCAPED_UNICODE);
        $this->assertClean($history, $plain + $fresh, 'جدول setting_changes');
        $audits = (string) json_encode(DB::table('audits')->orderBy('id')->get(['name', 'before', 'after']), JSON_UNESCAPED_UNICODE);
        $this->assertClean($audits, $plain + $fresh, 'جدول audits');

        // ٥) وسطرُ «آخرُ تعديل» يقول إنّ المفتاحَ تغيّر — والبصمةُ وحدَها دليلُه
        foreach (array_keys($plain) as $key) {
            $this->assertArrayHasKey($key, Settings::lastChanges([$key]), "لا سطرَ «آخرُ تعديل» للمفتاح {$key}");
            $eff = Settings::effective($key);
            $this->assertTrue($eff['stored'] === null || str_starts_with((string) $eff['stored'], 'sha256:'),
                "‏effective({$key}) أعادت قيمةً لا بصمة");
        }

        // ٦) وردودُ فاحصَي الاتصال: مفتاحُ أودو ومفتاحُ n8n مضبوطان الآن، والرسالةُ
        //    تخرج إلى الشاشة والجلسة — فلا تحمل المفتاحَ ولا كتلتَه
        $this->hubSetting('odoo.url', 'https://odoo.example.com');
        $this->hubSetting('odoo.db', 'prod');
        $this->hubSetting('odoo.user', 'bot@example.com');
        $this->hubSetting('n8n.url', 'https://n8n.example.com');
        foreach (['/admin/settings/odoo-test', '/admin/integrations/n8n/test'] as $url) {
            $r = $this->actingAs($this->owner)->post($url);
            $this->assertClean((string) json_encode($r->getSession()->all(), JSON_UNESCAPED_UNICODE),
                $plain, 'ردُّ الفاحص ' . $url);
        }
    }

    /** قيمةُ البيئة لا تُعرض ولا تُصدَّر — يُقال «مضبوطة» ولا يُقال ماذا */
    public function test_no_environment_value_reaches_any_surface(): void
    {
        $this->seedCore();

        $marks = [];
        foreach (Settings::exposedKeys() as $key) {
            $env = (string) (Settings::entry($key)['env_key'] ?? '');
            if ($env === '') continue;
            $marks[$key] = 'ENVVALUE-' . str_replace('_', '-', $env);
            putenv($env . '=' . $marks[$key]);
            $_ENV[$env] = $marks[$key];
            $_SERVER[$env] = $marks[$key];
        }
        $this->assertNotSame([], $marks, 'لا مفتاحَ يُعلن env_key — الاختبارُ بلا موضوع');

        $screen = $this->actingAs($this->owner)->get('/admin/settings')->assertOk()->getContent();
        $export = $this->actingAs($this->owner)->get('/admin/settings/export')->assertOk()->getContent();

        foreach ($marks as $key => $value) {
            $this->assertStringNotContainsString($value, $screen, "قيمةُ بيئةِ {$key} ظهرت في الشاشة");
            $this->assertStringNotContainsString($value, $export, "قيمةُ بيئةِ {$key} خرجت في التصدير");
            $this->assertNull(Settings::effective($key)['effective'],
                "‏effective({$key}) أعادت قيمةَ البيئة بدل أن تكتفي بـ«مضبوط»");
        }

        foreach ($marks as $key => $value) {
            $env = (string) Settings::entry($key)['env_key'];
            putenv($env);
            unset($_ENV[$env], $_SERVER[$env]);
        }
    }

    /**
     * (critic #7) استعادةُ مفتاحٍ لا تقلب مفتاحاً آخر: حارسٌ افتراضيُّه «مُشغَّل»
     * أطفأه المالكُ **يبقى مطفأً** مهما استُعيد حولَه، والاستعادةُ نفسُها تكتب
     * الافتراضيَّ صريحاً في صفٍّ قائمٍ لا تحذفه.
     */
    public function test_restoring_one_key_never_flips_another(): void
    {
        $this->seedCore();

        // كلُّ مفاتيح الكتالوج التي افتراضيُّها «مُشغَّل» — لا واحدٌ مختار
        $onByDefault = [];
        foreach (Settings::exposedKeys() as $key) {
            $meta = Settings::entry($key);
            if (($meta['type'] ?? '') === 'onoff' && (string) ($meta['default'] ?? '') === '1') $onByDefault[] = $key;
        }
        $this->assertNotSame([], $onByDefault, 'لا مفتاحَ ثنائيٌّ افتراضيُّه مُشغَّل — الاختبارُ بلا موضوع');

        foreach ($onByDefault as $key) $this->hubSetting($key, '0');      // أطفأها المالكُ عمداً
        $this->hubSetting('app.company', 'شركةٌ حقيقية');

        $this->actingAs($this->owner)->post('/admin/settings/restore', ['key' => 'app.company'])->assertRedirect();

        foreach ($onByDefault as $key) {
            $this->assertSame('0', (string) setting($key, ''), "استعادةُ app.company أعادت إشعالَ {$key}");
            $this->assertDatabaseHas('settings', ['key' => $key]);
        }

        // واستعادةُ **كلِّ** حارسٍ منها تكتب الافتراضيَّ صريحاً — الصفُّ باقٍ بقيمته
        // لا محذوف. وعاليُ الخطورة يمرّ بتأكيد الهوية أوّلاً (§18) فيُؤكَّد ثم يُستعاد.
        $this->actingAs($this->owner)->post('/stepup',
            ['answer' => 'Secret!2026x', 'next' => '/admin/settings'])->assertRedirect('/admin/settings');

        foreach ($onByDefault as $guard) {
            $this->actingAs($this->owner)->post('/admin/settings/restore', ['key' => $guard])->assertRedirect();
            $this->assertDatabaseHas('settings', ['key' => $guard]);
            $this->assertNotNull(Setting::where('key', $guard)->first(), "صفُّ {$guard} حُذف بدل أن يُكتب");
            $this->assertSame('1', (string) setting($guard, ''), "استعادةُ {$guard} لم تكتب الافتراضيَّ المُعلَن");
        }
    }

    /**
     * ميزانيةُ الشاشة **لا تنمو بعدد المفاتيح**: صفٌّ لكل مفتاحٍ في الجدول لا
     * يزيد استعلاماً واحداً — لا استعلامَ لكل مفتاح.
     */
    public function test_the_screen_query_count_does_not_grow_with_the_number_of_rows(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/admin/settings')->assertOk();     // تسخين

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->owner)->get('/admin/settings')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $empty = $count();

        $writable = array_values(array_filter(Settings::exposedKeys(), fn ($k) => ! Settings::isSecret($k)
            && ! Settings::isReadonly($k) && (string) (Settings::entry($k)['type'] ?? '') !== 'img'));

        // عشرةُ صفوفٍ بتاريخها…
        $write = function (array $keys): void {
            foreach ($keys as $k) {
                try { Settings::put($k, (string) (Settings::flat(Settings::defaultOf($k)) ?? ''), 'screen'); }
                catch (\InvalidArgumentException $e) { /* قيمةٌ لا تمرّ بقاعدتها — لا يعني ذلك شيئاً للميزانية */ }
            }
        };
        $write(array_slice($writable, 0, 10));
        $ten = $count();

        // …ثم كلُّ المفاتيح المعروضة
        $write(array_slice($writable, 10));
        $all = $count();

        // **الميزانيةُ ثابتةٌ لا تنمو بالعدد**: من عشرةِ صفوفٍ إلى خمسةٍ وتسعين
        // لا يزيد استعلامٌ واحد. (الفرقُ بين «لا صفَّ» و«صفوف» ثابتٌ صغير:
        // استعلاما `setting_changes` واسمُ المستخدم — لا استعلامَ لكل مفتاح.)
        $this->assertSame($ten, $all,
            "ميزانيةُ الشاشة نمت مع عدد الصفوف: {$ten} (عشرة) ⇐ {$all} (كلُّها) — استعلامٌ لكل مفتاح");
        $this->assertLessThanOrEqual($empty + 3, $all,
            "كلفةُ التاريخ فوق ثلاثة استعلاماتٍ ثابتة: {$empty} ⇐ {$all}");
        $this->assertLessThan(40, $all, "شاشةُ الإعدادات صارت {$all} استعلاماً");
    }
}
