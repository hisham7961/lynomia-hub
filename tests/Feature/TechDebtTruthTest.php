<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **سجلُّ الدَّين يُحرَس كما تُحرَس الشيفرة** — لأنّه كذب مرّتين.
 *
 * ── **العيبُ الذي بُني هذا الحارسُ لأجله** ──
 *
 * `docs/TECH_DEBT.md` يحوي **دعاوى قابلةً للقياس**: «`esign.pass_min` غيرُ
 * موجود (٠ موضع)»، و«ما زال مفتوحاً: ١٩ بنداً» يليها قائمةُ أسماء. وهذه
 * الدعاوى **تصدق يومَ تُكتَب وتكذب بالجولةِ التالية** — وقد وقع الاثنان:
 *
 * - v2.596.0 سكّ `esign.pass_min` **ونسي الصفَّ الذي يقول إنّه غيرُ موجود**.
 * - v2.597.0 سكّ مفتاحَي #14 و#20 **ونسي صفّيهما**.
 * - وصفُّ «ما زال مفتوحاً» كان يقول **١٩** وقائمتُه تعُدّ **٢٠** — عددٌ
 *   خُفِّض دون حذفِ الاسم.
 *
 * **ووثيقةٌ تكذب أسوأُ من وثيقةٍ ناقصة**: الناقصةُ تُرسل قارئَها إلى الشيفرة،
 * والكاذبةُ تُقنعه أنّه لا يحتاج. فمُنِعَ الصنفُ كلُّه هنا بالمسح لا بالذاكرة.
 */
class TechDebtTruthTest extends TestCase
{
    /** دعوى «`مفتاح.ما` غيرُ موجود» — تُقاس على الشيفرة لا تُصدَّق */
    private const ABSENCE = '/`([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)`\s*\*\*غيرُ موجودٍ?\*\*/u';

    /** صفُّ ملخّصٍ ثالثُ خاناته قائمةُ `#` محضة — يُعَدُّ ويُقارَن بعدده المُعلَن */
    private const ROW = '/^\|\s*(.+?)\s*\|\s*([٠-٩]+)\s*\|\s*((?:#[0-9]+[أب]?(?:‑QE10)?)(?:\s*·\s*#[0-9]+[أب]?(?:‑QE10)?)*)\s*\|$/u';

    private function register(): string
    {
        return (string) file_get_contents(base_path('docs/TECH_DEBT.md'));
    }

    /**
     * **دعوى الغياب تُقاس** — فمفتاحٌ سُكَّ وبقي صفُّه يقول «غيرُ موجود»
     * يُحوّل السجلَّ من خريطةٍ إلى مصيدة.
     */
    public function test_كل_دعوى_غياب_مفتاحٍ_صادقةٌ_على_الشيفرة(): void
    {
        // **أوّلاً: إثباتُ أنّ الحارسَ ليس أعمى.** الأرضيّةُ المعتادةُ («وُجدت
        // دعوىً واحدةٌ على الأقلّ») **خاطئةٌ هنا**: صفرُ دعاوى حالٌ مشروعةٌ
        // تماماً — بل هي الحالُ المرجوّة. فيُختبَر النمطُ على عيّنةٍ صناعيّة.
        $this->assertSame(1, preg_match(self::ABSENCE, '| `demo.key` **غيرُ موجود** (٠ موضع) |'),
            'نمطُ دعوى الغياب لم يَعُد يُطابق صيغةَ السجلّ — الحارسُ أعمى');

        preg_match_all(self::ABSENCE, $this->register(), $m);
        $claims = array_unique($m[1]);

        $haystack = '';
        foreach (['config', 'app'] as $dir) {
            foreach ($this->phpFiles(base_path($dir)) as $f) $haystack .= file_get_contents($f);
        }

        foreach ($claims as $key) {
            $this->assertStringNotContainsString("'{$key}'", $haystack,
                "سجلُّ الدَّين يقول إنّ «{$key}» غيرُ موجود — وهو مسكوكٌ في الشيفرة. "
                . 'الدعوى صدقت يومَ كُتبت وكذبت بعدها: حدّث الصفَّ أو احذف الدعوى.');
        }
    }

    /**
     * **وعددُ الصفِّ يُطابق قائمتَه** — فعددٌ يُخفَّض دون حذفِ الاسم
     * (أو اسمٌ يُحذَف دون خفضِ العدد) يمرّ صامتاً إلى الأبد.
     */
    public function test_أعدادُ_جدولِ_الملخّص_تطابق_قوائمَها(): void
    {
        $checked = 0;

        foreach (explode("\n", $this->register()) as $n => $line) {
            if (! preg_match(self::ROW, trim($line), $m)) continue;

            $declared = (int) strtr($m[2], array_combine(
                ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'], range('0', '9')));
            $actual = count(preg_split('/\s*·\s*/u', trim($m[3])));
            $checked++;

            $this->assertSame($declared, $actual,
                "سطر " . ($n + 1) . " — «{$m[1]}» يُعلن {$declared} والقائمةُ تعُدّ {$actual}: {$m[3]}");
        }

        // أرضيّةٌ كنمطِ سقوفِ الدَّين: حارسٌ لا يفحص شيئاً أخضرُ ولا يُثبت شيئاً
        $this->assertGreaterThanOrEqual(2, $checked,
            'لم يُفحَص صفُّ ملخّصٍ واحد — تغيّر شكلُ الجدول والحارسُ صار أخضرَ بلا عمل');
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        if (! is_dir($dir)) return [];
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') $out[] = $f->getPathname();
        }
        sort($out);   // ترتيبٌ حتميّ — نظامُ الملفّات لا يَعِد بشيء

        return $out;
    }
}
