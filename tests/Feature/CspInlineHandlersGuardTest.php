<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * **حارسةُ السياسة الصارمة في القوالب** (بند الدَّين #12 · FE-03).
 *
 * السياسةُ المفروضة `script-src 'self' 'nonce-…'; script-src-attr 'none'` تحجب في المتصفّح:
 *  · كلَّ معالجِ حدثٍ في السمات (`onclick=` · `onchange=` · `onsubmit=` …)،
 *  · وكلَّ رابط `javascript:`،
 *  · و`hx-on` (تُقيِّمه htmx بـ`Function` — أي eval)،
 *  · وكلَّ `<script>` مضمَّنٍ تنفيذيٍّ لا يحمل `@cspNonce`.
 *
 * وحجبُها **صامت**: زرٌّ لا يستجيب ونموذجٌ لا يتفاعل ولا خطأ في الحزمة. فهذه الحارسةُ تمسح
 * `resources/views` كلَّها وتُسقط الحزمةَ عند أوّل عودةٍ لأيٍّ منها، باسم الملفّ والسطر.
 * البديل: سماتُ data-* ومستمِعاتُها المفوَّضة في `public/js/actions.js`، أو مستمِعٌ يُربط
 * داخل سكربت الصفحة الموسوم بالـnonce.
 *
 * (كتلُ البيانات `<script type="application/json">` ليست تنفيذيّة — لا تحتاج شيئاً.)
 */
class CspInlineHandlersGuardTest extends TestCase
{
    /** مخالفاتُ مجلّدِ قوالب — مفصولةٌ ليُثبَت أنّ الماسحَ يرى ما يجب أن يرى */
    public static function violations(string $dir): array
    {
        $out = [];
        foreach (File::allFiles($dir) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) continue;
            $rel = str_replace($dir . '/', '', $file->getPathname());
            $src = (string) file_get_contents($file->getPathname());

            // تعليقاتُ Blade وHTML لا تُنفَّذ — تُمحى مع إبقاء أسطرها كي يصدق رقمُ السطر
            $blank = fn ($m) => preg_replace('/[^\n]/', ' ', $m[0]);
            $src = preg_replace_callback('/\{\{--.*?--\}\}/s', $blank, $src);
            $src = preg_replace_callback('/<!--.*?-->/s', $blank, $src);

            // وسومُ السكربت: التنفيذيُّ المضمَّن يحمل الـnonce؛ والجسمُ يُمحى (شيفرةٌ لا سمات)
            $src = preg_replace_callback('/<script\b([^>]*)>(.*?)<\/script>/is', function ($m) use (&$out, $rel, $src) {
                $attrs = $m[1];
                $isData = preg_match('/\btype\s*=\s*["\']application\/(ld\+)?json["\']/i', $attrs);
                $hasSrc = preg_match('/\bsrc\s*=/i', $attrs);
                $hasNonce = str_contains($attrs, '@cspNonce') || preg_match('/\bnonce\s*=/i', $attrs);
                if (! $isData && ! $hasSrc && ! $hasNonce) {
                    $out[] = $rel . ': <script' . $attrs . '> بلا @cspNonce';
                }

                return '<script' . $attrs . '>' . preg_replace('/[^\n]/', ' ', $m[2]) . '</script>';
            }, $src);
            $src = preg_replace_callback('/<style\b[^>]*>.*?<\/style>/is', $blank, $src);

            $rules = [
                'معالجُ حدثٍ في السمة' => '/(?<=\s)on[a-z]{3,}\s*=\s*["\']/i',
                'رابطُ javascript:'   => '/javascript\s*:/i',
                'hx-on (eval)'        => '/(?<=\s)(data-)?hx-on[:\-]?[a-z:\-]*\s*=/i',
            ];
            foreach ($rules as $what => $re) {
                if (! preg_match_all($re, $src, $mm, PREG_OFFSET_CAPTURE)) continue;
                foreach ($mm[0] as [$hit, $off]) {
                    $line = substr_count(substr($src, 0, $off), "\n") + 1;
                    $out[] = $rel . ':' . $line . ' — ' . $what . ' «' . trim($hit) . '»';
                }
            }
        }
        sort($out);

        return $out;
    }

    public function test_no_view_carries_inline_handlers_javascript_urls_hx_on_or_unnonced_scripts(): void
    {
        $bad = self::violations(resource_path('views'));

        $this->assertSame([], $bad, "قوالبُ تكسرها السياسةُ الصارمة (script-src-attr 'none'):\n  "
            . implode("\n  ", $bad)
            . "\nالبديل: سمةُ data-* من public/js/actions.js، أو مستمِعٌ في سكربتٍ يحمل @cspNonce.");
    }

    /** الماسحُ نفسُه يرى كلَّ صنف — حارسةٌ لا تبصر شيئاً تخضرّ دائماً */
    public function test_the_scanner_catches_every_class(): void
    {
        $dir = sys_get_temp_dir() . '/cspguard_' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir . '/x');
        file_put_contents($dir . '/x/bad.blade.php', implode("\n", [
            '<button onclick="go()">a</button>',
            '<select class="inp"',
            '        onchange="this.form.submit()"></select>',
            '<a href="javascript:history.back()">b</a>',
            '<form hx-on::after-request="location.reload()"></form>',
            '<script>alert(1)</script>',
        ]));
        file_put_contents($dir . '/x/good.blade.php', implode("\n", [
            '{{-- كان onclick="x()" و javascript:void — تعليقٌ لا يُنفَّذ --}}',
            '<script @cspNonce>var only = 1; var s = "<a onclick=\'x\'>"; location.href = "javascript:";</script>',
            '<script type="application/json" id="d">{"onclick":"x"}</script>',
            '<script src="/js/app.js"></script>',
            '<button data-print data-confirm-native="حذف؟">c</button>',
            '<p>السعر ثابتٌ ولا يحمل one = two</p>',
        ]));

        try {
            $v = self::violations($dir);
        } finally {
            File::deleteDirectory($dir);
        }

        $this->assertCount(5, $v, implode("\n", $v));
        $joined = implode("\n", $v);
        $this->assertStringContainsString('x/bad.blade.php:1 — معالجُ حدثٍ في السمة', $joined);
        $this->assertStringContainsString('x/bad.blade.php:3 — معالجُ حدثٍ في السمة', $joined);
        $this->assertStringContainsString('x/bad.blade.php:4 — رابطُ javascript:', $joined);
        $this->assertStringContainsString('x/bad.blade.php:5 — hx-on (eval)', $joined);
        $this->assertStringContainsString('x/bad.blade.php: <script> بلا @cspNonce', $joined);
        $this->assertStringNotContainsString('good.blade.php', $joined);
    }

    /** وملفُّ الأفعال الذي يحلّ محلّها محمَّلٌ في كلّ قشرة — وإلا صارت السماتُ زينةً ميتة */
    public function test_actions_script_is_loaded_by_every_shell(): void
    {
        $this->assertFileExists(public_path('js/actions.js'));
        foreach (['layouts/app', 'layouts/portal', 'partials/standalone_head'] as $v) {
            $this->assertStringContainsString("asset('js/actions.js')",
                (string) file_get_contents(resource_path("views/$v.blade.php")), "$v لا تحمّل actions.js");
        }

        // وكلُّ صفحةٍ مستقلّة (بلا تخطيطٍ ولا رأسٍ مشترك) تستعمل سمةَ فعلٍ تحمّله بنفسها
        $attr = '/\sdata-(print|copy|copy-from|go-back|no-contextmenu|submit-on-change|confirm-native)\b/';
        foreach (File::allFiles(resource_path('views')) as $f) {
            $src = (string) file_get_contents($f->getPathname());
            if (! str_contains($src, '<html') || str_contains($src, '@extends')
                || str_contains($src, "partials.standalone_head") || ! preg_match($attr, $src)) continue;
            $this->assertStringContainsString("asset('js/actions.js')", $src,
                $f->getRelativePathname() . ' تستعمل سماتِ أفعالٍ ولا تحمّل actions.js');
        }
    }

    /**
     * **لا يُسجَّل المستمِعُ مرّتين**: البوّابةُ تحمّل الملفَّ في رأسها المشترك وفي ذيلها — فكان كلُّ فعلٍ
     * يُنفَّذ مرّتين (زرُّ السمة يقلب ويعيد، والتأكيدُ يُسأل مرّتين، والكشفُ يُدقَّق مرّتين).
     * فالملفُّ يحرس نفسَه من التحميل الثاني، وصفحةٌ حقيقيّةٌ للبوّابة تُفحص.
     */
    public function test_actions_script_guards_against_double_load(): void
    {
        $js = file_get_contents(public_path('js/actions.js'));
        $this->assertMatchesRegularExpression('/if\s*\(window\.__hubActions\)\s*return;/', $js, 'actions.js بلا حارسِ تحميلٍ مزدوج');
    }
}
