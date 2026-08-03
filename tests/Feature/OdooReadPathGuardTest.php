<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **حرسُ مصدرِ مسارات أودو** — بالنمط المنزلي: ما لا يُقرأ من المصدر لا يُحرَس.
 *
 * أربعُ قواعد بعد تعدد الاتصالات:
 *   ١) مفاتيح `odoo.*` تُقرأ في العميل وحده — قارئٌ خارجه يتجاوز حلَّ الاتصال
 *      فيقرأ الافتراضيَّ لسجلٍّ اختار خادماً آخر: أرقامُ شركةٍ لأخرى.
 *   ٢) نداءات JSON-RPC في العميل وحده — لنفس السبب، وليتوحد المهلةُ والخطأ.
 *   ٣) بطاقاتُ أودو تحلّ بـ`forRow` — لا انحدارَ صامتاً للاتصال العالمي.
 *   ٤) العميل لا يستدعي دوالَّ الكتابة أبداً — «قراءةٌ فقط» مفروضةٌ لا موعودة.
 */
class OdooReadPathGuardTest extends TestCase
{
    /** @return string[] ملفات php تحت جذور معينة */
    protected function files(array $roots, string $ext = '.php'): array
    {
        $out = [];
        foreach ($roots as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($it as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), $ext)) $out[] = $f->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    /** مصدرٌ بلا تعليقات — حارسٌ يسقط على توثيق نفسه حارسٌ رديء */
    protected function src(string $f): string
    {
        return (string) preg_replace(
            ['/\{\{--.*?--\}\}/s', '/\/\*.*?\*\//s', '/\/\/[^\n]*/', '/<!--.*?-->/s'],
            ' ', (string) file_get_contents($f));
    }

    public function test_every_odoo_setting_read_lives_in_the_connection_factory(): void
    {
        $allowed = [app_path('Support/Odoo.php')];
        $bad = [];
        foreach ($this->files([app_path(), resource_path('views')]) as $f) {
            if (in_array($f, $allowed, true)) continue;
            if (str_contains($this->src($f), "setting('odoo.")) {
                $bad[] = str_replace(base_path() . '/', '', $f);
            }
        }

        $this->assertSame([], $bad,
            'قارئُ odoo.* خارج العميل يتجاوز حلَّ الاتصال — أرقامُ الخادم الافتراضي لسجلٍّ اختار غيرَه: '
            . implode(' · ', $bad));
    }

    public function test_jsonrpc_calls_exist_only_in_the_client(): void
    {
        $allowed = [app_path('Support/Odoo.php')];
        $bad = [];
        foreach ($this->files([app_path()]) as $f) {
            if (in_array($f, $allowed, true)) continue;
            if (str_contains($this->src($f), 'jsonrpc')) {
                $bad[] = str_replace(base_path() . '/', '', $f);
            }
        }

        $this->assertSame([], $bad,
            'نداءُ JSON-RPC خارج العميل — بلا مهلته وخطئه العربي وحلِّ اتصاله: ' . implode(' · ', $bad));
    }

    public function test_odoo_blades_resolve_via_forRow(): void
    {
        foreach (['partials/odoo_card.blade.php', 'partials/odoo_channels_card.blade.php'] as $v) {
            $this->assertStringContainsString('forRow',
                (string) file_get_contents(resource_path('views/' . $v)),
                "{$v} لا تحلّ بـforRow — ستقرأ الافتراضيَّ لسجلٍّ اختار خادماً آخر");
        }
    }

    /**
     * «قراءة فقط» تُفرض من المصدر: execute_kw لا يُمرَّر له فعلُ كتابة.
     * تُفحص السلاسلُ الحرفية الممرَّرة كوسيط method في call() داخل العميل.
     */
    public function test_odoo_client_never_calls_write_methods(): void
    {
        $src = $this->src(app_path('Support/Odoo.php'));

        preg_match_all("/->call\(\s*'[^']+'\s*,\s*'([^']+)'/", $src, $m);
        $this->assertNotEmpty($m[1], 'لا نداءات في العميل — تغيّر شكلُه فحدّث الحارس');

        $reads = ['search_read', 'search_count', 'read_group', 'read'];
        foreach (array_unique($m[1]) as $method) {
            $this->assertContains($method, $reads,
                "العميل يستدعي «{$method}» وليست قراءة — ضمانُ «قراءة فقط» انكسر");
        }

        // والكتابات الثلاث لا تظهر كسلاسلَ في الملف أصلاً — بأي صياغة كانت
        foreach (["'create'", "'write'", "'unlink'"] as $w) {
            $this->assertStringNotContainsString($w, $src,
                "فعلُ الكتابة {$w} حاضرٌ في مصدر العميل");
        }
    }
}
