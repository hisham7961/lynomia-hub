<?php

namespace Tests\Feature\UltimateReview;

use Tests\TestCase;

/**
 * **خريطةُ المشغّلِ لا تتعفّن** (المراجعةُ الشاملة · الطبقة ٣ · L3-03).
 *
 * كلُّ مفتاحٍ في كتالوج الإعدادات يحمل `where` — اقتباساً يسمّي **أين يسري
 * المفتاحُ في الشيفرة**. وهذا ليس تعليقاً داخليّاً: `settings/form.blade.php:256`
 * **يطبعه للمشغّل** تحت كلِّ حقلٍ في شاشة الإعدادات، وعنوانُه الحرفيُّ «أين يسري
 * في الشيفرة».
 *
 * فهي **خريطةٌ يقرؤها إنسانٌ ويمشي عليها**: مالكٌ يسأل «أين يؤثّر هذا المفتاح؟»،
 * ومهندسٌ يفتح الملفّ المسمّى. ولا شيءَ يفحصها. فإعادةُ تسميةِ متحكّمٍ أو حذفُ
 * قالبٍ تترك الخريطةَ تشير إلى لا شيء — **وتبقى مطبوعةً بثقةٍ على الشاشة**.
 *
 * **مئةٌ واثنان وستّون اقتباساً** في مئةٍ وأربعةَ عشرَ مفتاحاً. هذا الحارسُ يمرّ
 * عليها جميعاً ويطالب كلَّ اقتباسٍ **يشبه مرجعاً برمجيّاً** بأن يحلّ إلى ملفٍّ
 * قائم.
 *
 * **وما لا يُطالَب به:** رقمُ السطر. الاقتباسُ يسمّي **حيث يظهر الأثر** لا حيث
 * يُقرأ المفتاحُ حرفيّاً — و`CostController:61` تشير إلى `hub_project_pl(...)`
 * التي تقرأ `app.currency` في `helpers.php`، فلا يوجد نصُّ `app.currency` في
 * المتحكّم أصلاً. والأسطرُ تنزاح مع كلِّ تحرير. فالعقدُ المُتاحُ آليّاً هو
 * **وجودُ الملفّ**، وما عداه يقرؤه قارئٌ لا مُطابِقُ نصوص.
 *
 * **واقتباسٌ نثريٌّ عربيٌّ خالص** («شاشةُ أسعار الصرف») أسلوبٌ مشروعٌ يخاطب
 * المشغّلَ لا المهندس — فيُتخطّى صراحةً لا يُعدّ عطلاً.
 */
class SettingsCitationsPointAtRealCodeTest extends TestCase
{
    /** فهرسُ الشيفرة مرّةً واحدة: أصناف، ودوالّ، وتوابع، وقوالب */
    private static array $idx = [];

    private function index(): array
    {
        if (self::$idx) return self::$idx;

        $root = base_path();
        $classes = $blades = $funcs = $methods = [];

        foreach ([$root . '/app', $root . '/database/seeders'] as $dir) {
            if (! is_dir($dir)) continue;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $f) {
                if (! $f->isFile() || $f->getExtension() !== 'php') continue;
                $classes[$f->getBasename('.php')] ??= $f->getPathname();
                preg_match_all('/function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/',
                    (string) @file_get_contents($f->getPathname()), $m);
                foreach ($m[1] as $fn) { $funcs[$fn] ??= $f->getPathname(); $methods[$fn] ??= $f->getPathname(); }
            }
        }

        $vroot = $root . '/resources/views/';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($vroot)) as $f) {
            if (! $f->isFile()) continue;
            $rel = str_replace($vroot, '', $f->getPathname());
            $blades[$rel] = $f->getPathname();
            // واقتباسٌ بلا لاحقة: `alerts/index` ⇒ `alerts/index.blade.php`
            if (str_ends_with($rel, '.blade.php')) $blades[substr($rel, 0, -10)] = $f->getPathname();
        }

        return self::$idx = compact('classes', 'blades', 'funcs', 'methods');
    }

    private function resolve(string $tok): ?string
    {
        ['classes' => $c, 'blades' => $b, 'funcs' => $f, 'methods' => $me] = $this->index();
        $tok = trim(rtrim($tok, '.'));
        if ($tok === '') return null;
        if (isset($b[$tok])) return $b[$tok];
        if (str_ends_with($tok, '.php')) return $c[basename($tok, '.php')] ?? null;

        $cls = basename(str_replace('\\', '/', $tok));
        return $c[$cls] ?? $f[$tok] ?? $me[$tok] ?? $c[$cls] ?? null;
    }

    public function test_every_code_citation_in_the_settings_catalogue_resolves_to_a_real_file(): void
    {
        $dead = []; $cites = 0; $prose = 0; $resolved = 0;

        foreach (config('hub_settings.groups') as $grp) {
            foreach ($grp as $key => $meta) {
                if (! is_array($meta)) continue;
                $where = trim((string) ($meta['where'] ?? ''));
                if ($where === '') continue;

                foreach (preg_split('/\s*·\s*/u', $where) as $cite) {
                    $cite = trim($cite);
                    if ($cite === '') continue;
                    $cites++;

                    preg_match_all('#[A-Za-z_][A-Za-z0-9_\\\\/\.\-]{2,}#', $cite, $m);
                    if (! $m[0]) { $prose++; continue; }   // نثرٌ عربيٌّ للمشغّل — أسلوبٌ لا عطل

                    $hit = false;
                    foreach ($m[0] as $tok) {
                        if ($this->resolve($tok) || $this->resolve(explode('::', $tok)[0])) { $hit = true; break; }
                    }
                    if ($hit) $resolved++;
                    else $dead[] = "{$key} → «{$cite}»";
                }
            }
        }

        $this->assertGreaterThan(100, $cites, 'الكتالوجُ فقد اقتباساتِه — الحارسُ يحرس فراغاً');
        $this->assertSame([], $dead,
            'اقتباسٌ في شاشةِ الإعدادات يشير إلى ملفٍّ لا وجودَ له — والمشغّلُ يقرؤه مطبوعاً بثقة');
        $this->assertGreaterThan(0.9, $resolved / max(1, $cites - $prose),
            'أكثرُ من عُشرِ الاقتباساتِ البرمجيّةِ لم تحلّ — الحارسُ صار يقيس نفسَه لا الكتالوج');
    }

    /** وكلُّ مفتاحٍ يحمل اقتباساً أصلاً — لا حقلَ بلا خريطة */
    public function test_every_catalogue_key_carries_a_where_citation(): void
    {
        $naked = [];
        foreach (config('hub_settings.groups') as $grp) {
            foreach ($grp as $key => $meta) {
                if (! is_array($meta)) continue;
                if (trim((string) ($meta['where'] ?? '')) === '') $naked[] = $key;
            }
        }

        $this->assertSame([], $naked, 'مفتاحٌ في الكتالوج بلا «أين يسري» — حقلٌ يُقلَب على العمياء');
    }

    /** والحارسُ يميّز: اقتباسٌ ميّتٌ يُمسَك فعلاً لا يُتخطّى */
    public function test_the_guard_would_catch_a_dead_citation(): void
    {
        $this->assertNull($this->resolve('LaWujudaLahuController'),
            'المحلِّلُ يحلّ صنفاً لا وجودَ له — فحارسُه يخضرّ على خريطةٍ ميّتة');
        $this->assertNotNull($this->resolve('SettingController'), 'المحلِّلُ عجز عن صنفٍ قائم');
        $this->assertNotNull($this->resolve('partials/sidebar.blade.php'), 'المحلِّلُ عجز عن قالبٍ قائم');
        $this->assertNotNull($this->resolve('hub_brand_css'), 'المحلِّلُ عجز عن دالّةٍ قائمةٍ في helpers');
    }
}
