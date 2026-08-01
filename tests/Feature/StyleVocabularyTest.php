<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **لغةٌ تكتبها القوالب ولا تعرفها الورقة.**
 *
 * القوالب تكتب `class="cardtitle"` في **اثنين وأربعين ملفاً**، والورقة لا
 * تعرف هذا الصنف — فيُعرَض عنوانُ البطاقة نصّاً عارياً. ومثلُه `kpi` و`val`
 * (بطاقةُ مؤشرٍ بلا شكل) و`grid2` (عمودان يصيران عموداً) و`palitem` (صفُّ
 * إشعارٍ يكتب `justify-content` وهو ليس flex أصلاً، فالنقطةُ والنصّ لا يصطفّان).
 *
 * ولا شيء يكشف هذا: **الحزمة خضراء، والصفحة تُفتح، والصنف المفقود لا يُخطئ** —
 * يُتجاهَل بصمت. فالحارسُ هنا يقرأ المصدر: كل صنفٍ تكتبه القوالب يجب أن تعرفه
 * الورقةُ أو كتلةُ نمطٍ في قالبٍ ما، وكل متغيّرٍ يُشار إليه يجب أن يكون مُعرَّفاً.
 *
 * ومعها عيبٌ أمنيّ في محرّك التأكيد: نصُّ `data-confirm` يُحقن بـ`innerHTML`،
 * وأربعةَ عشرَ موضعاً يُدخلون فيه اسم سجلٍّ يكتبه المستخدم.
 */
class StyleVocabularyTest extends TestCase
{
    /**
     * أصنافٌ بلا نمطٍ **عن قصد**: مقابضُ جافاسكربت أو حاملاتُ بنيةٍ تُنسَّق
     * بأنماطٍ سطرية أو بمحدِّدٍ أبويّ. كلُّ ما عداها عيبُ عرضٍ صامت.
     */
    private const HOOKS = [
        'advfl', 'advrows', 'brow',          // مقابض شريط الفلترة والتحديد الجماعي
        'mxrow', 'mxbox', 'mxgrp',           // مصفوفة الصلاحيات — بحثٌ وتبديل بالجافاسكربت
        'setrow', 'setgrp',                  // بحث الإعدادات
        'cwpane',                            // ألواح مساحة عمل العقد — تُبدَّل بـhidden
        'dmrow', 'dmthread',                 // بحث المحادثات، وابنُ شبكةٍ يُنسَّق من أبيه
        'frrow', 'arow', 'onlinedot',        // صفوفٌ ونقاطٌ بأنماطٍ سطرية كاملة
        'tracecols', 'statv2',               // شبكاتٌ بأنماطٍ سطرية كاملة
    ];

    /** كل ما يُعرَّف: الورقة + كل كتل <style> في القوالب */
    private function pool(): string
    {
        $pool = file_get_contents(public_path('css/app.css'));
        foreach ($this->views() as $v) {
            preg_match_all('/<style[^>]*>(.*?)<\/style>/s', $this->src($v), $m);
            $pool .= "\n" . implode("\n", $m[1] ?? []);
        }

        return $pool;
    }

    /**
     * مصدرُ القالب بلا تعليقات: حارسٌ يسقط على **توثيقِ نفسه** حارسٌ رديء —
     * تعليقٌ يقول «أصلحنا `var(--brand)`» ليس استعمالاً لمتغيّر.
     */
    private function src(string $f): string
    {
        return (string) preg_replace(['/\{\{--.*?--\}\}/s', '/\/\*.*?\*\//s', '/<!--.*?-->/s'],
            ' ', file_get_contents($f));
    }

    /** @return string[] */
    private function views(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) $out[] = $f->getPathname();
        }
        sort($out);

        return $out;
    }

    public function test_every_class_written_by_a_template_is_styled_somewhere(): void
    {
        preg_match_all('/\.([a-zA-Z][\w-]*)/', $this->pool(), $m);
        $known = array_flip($m[1] ?? []);

        $orphans = [];
        foreach ($this->views() as $v) {
            // السمات الحرفية وحدها: ما فيه Blade يُبنى وقت العرض فلا يُقرأ من المصدر
            preg_match_all('/class="([^"{@]*)"/', $this->src($v), $mm);
            foreach ($mm[1] ?? [] as $attr) {
                foreach (preg_split('/\s+/', trim($attr), -1, PREG_SPLIT_NO_EMPTY) as $c) {
                    if (isset($known[$c]) || in_array($c, self::HOOKS, true)) continue;
                    $orphans[$c][] = basename($v);
                }
            }
        }

        $report = collect($orphans)->map(fn ($f, $c) => $c . ' (' . count(array_unique($f)) . ' ملف)')->implode(' · ');
        $this->assertSame([], array_keys($orphans),
            'أصنافٌ تكتبها القوالب ولا تعرفها الورقة — تُعرَض عاريةً بلا خطأ يُنبّه: ' . $report);
    }

    public function test_every_css_variable_referenced_is_defined(): void
    {
        $pool = $this->pool();
        preg_match_all('/(--[a-zA-Z0-9_-]+)\s*:/', $pool, $m);
        $known = array_flip($m[1] ?? []);

        $orphans = [];
        foreach (array_merge([public_path('css/app.css')], $this->views()) as $f) {
            // بلا بديل وحدها: `var(--x, 10px)` مُعرَّفٌ ببديله فلا يسقط شيء
            preg_match_all('/var\(\s*(--[a-zA-Z0-9_-]+)\s*\)/', $this->src($f), $mm);
            foreach ($mm[1] ?? [] as $var) {
                if (! isset($known[$var])) $orphans[$var][] = basename($f);
            }
        }

        $this->assertSame([], array_keys($orphans),
            'متغيّرٌ يُشار إليه ولا يُعرَّف — الخاصيةُ تسقط صامتةً فيرث النصُّ لونَ أبيه: '
            . collect($orphans)->map(fn ($f, $v) => $v . ' في ' . implode('، ', array_unique($f)))->implode(' · '));
    }

    /**
     * **نصُّ التأكيد لا يُحقن HTML**: `innerHTML = '⚠️ ' + msg` وسمةُ
     * `data-confirm` تحمل في أربعةَ عشرَ موضعاً اسمَ سجلٍّ يكتبه المستخدم —
     * فسجلٌّ اسمُه `<img src=x onerror=…>` ينفّذ عند أول ضغطةِ حذف.
     */
    public function test_the_confirm_prompt_never_injects_the_message_as_html(): void
    {
        $js = $this->src(public_path('js/app.js'));

        $this->assertDoesNotMatchRegularExpression('/innerHTML\s*=\s*[^;]*\bmsg\b/', $js,
            'نصُّ data-confirm يُحقن HTML — واسمُ السجل فيه من كتابة المستخدم');
    }

    /** ولا يتشابه زرّا «اعتماد» و«رفض» في بوابة الموظف — الضغطةُ الخاطئة تُنفَّذ */
    public function test_the_approve_button_is_visually_distinct(): void
    {
        $this->assertMatchesRegularExpression('/\.btn\.ok\b/', file_get_contents(public_path('css/app.css')),
            'زرُّ «اعتماد» بلا شكلٍ خاص — يُشبه زرَّ «رفض» تماماً بجانبه');
    }
}
