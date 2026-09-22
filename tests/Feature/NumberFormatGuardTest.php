<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **كلُّ مولِّدِ ترقيمٍ يقرأ قالبَه من الإعدادات يحرس `{SEQ}`** (#٣٥أ · §٤ط).
 *
 * ── **لماذا حارسٌ لا إصلاحٌ ثالث** ──
 *
 * في v2.592.0 أُصلحت **ثلاثةُ** مولِّدات (العهدةُ والمحطاتُ وتصاريحُ الخروج)
 * بعد أن قِيس أنّ قالباً بلا `{SEQ}` يُعلّق الحفظَ أبداً. ثمّ كشف مسحٌ لاحق
 * أنّ العائلةَ **خمسة** لا ثلاثة: العروضُ والعقودُ تقرآن قالبيهما من
 * `quotes.doc_no_format` و`contracts.doc_no_format` وتدوران الحلقةَ نفسَها.
 *
 * **فالإصلاحُ الذي يُعدّ أعضاءَ عائلتِه بيدِه يُخطئ العدّ.** وهذا الحارسُ
 * يعدّهم بالمسح: كلُّ ملفٍّ فيه حلقةُ «مرشَّحٌ حتى يَفرُد» **ويقرأ قالبَه من
 * `setting()`** يجب أن يحمل حارسَ `{SEQ}`. فمن أضاف مولِّداً سادساً غداً
 * سقط هنا قبل أن يُعلّق شاشةً عند عميل.
 *
 * ── **وما لا يحرسه** ──
 *
 * مولِّداتٌ بقالبٍ **ثابتٍ في الشيفرة** (`Product` · `FinDocument`) لا تقع
 * تحت الخطر: تسلسلُها `sprintf` يتزايد دوماً فالحلقةُ تخرج. ورمزُ التحقّقِ
 * في `EsignController` عشوائيٌّ لا قالبيّ. **فالحارسُ يستثنيها بالقياس لا
 * بالثقة**: شرطُه أن يقرأ الملفُّ قالباً من `setting()` أصلاً.
 */
class NumberFormatGuardTest extends TestCase
{
    /** حلقةُ «مرشَّحٌ حتى يَفرُد» — النمطُ الذي يدور أبداً بقالبٍ ثابت */
    private const LOOP = '/while\s*\(.*->exists\(\)\)/';

    /** قالبٌ يُقرأ من الإعدادات: `setting('…format…')` */
    private const FORMAT = "/setting\(\s*'[a-z_.]*format[a-z_.]*'/i";

    /** الحارسُ المطلوب: إلحاقُ التسلسلِ بقالبٍ ينقصه */
    private const GUARD = "/str_contains\(\s*\\\$\w+\s*,\s*'\{SEQ\}'\s*\)/";

    public function test_كلُّ_مولِّدٍ_بقالبٍ_من_الإعدادات_يحرس_التسلسل(): void
    {
        $offenders = [];
        $guarded = 0;

        foreach ($this->phpFiles() as $path => $src) {
            if (! preg_match(self::LOOP, $src)) continue;      // ليس مولِّدَ ترقيم
            if (! preg_match(self::FORMAT, $src)) continue;     // قالبُه ثابتٌ في الشيفرة
            if (preg_match(self::GUARD, $src)) { $guarded++; continue; }
            $offenders[] = $this->rel($path);
        }

        $this->assertSame([], $offenders,
            "مولِّدُ ترقيمٍ يقرأ قالبَه من الإعدادات بلا حارسِ `{SEQ}` — وقالبٌ ينقصه\n"
            . "يُبقي المرشَّحَ ثابتاً فتدور الحلقةُ أبداً باستعلامِ قاعدةٍ في كلِّ لفّة:\n  "
            . implode("\n  ", $offenders));

        // **وأرضيّةٌ تحرس الحارس**: مسحٌ انكسر يُعيد صفراً فيخضرّ كاذباً
        $this->assertGreaterThanOrEqual(5, $guarded,
            'المسحُ وجد أقلَّ من خمسةِ مولِّداتٍ محروسة — والمعروفُ خمسةٌ، فالمسحُ انكسر');
    }

    /** ملفّاتُ `app/` كلُّها، مفهرسةً بمسارها */
    private function phpFiles(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') $out[$f->getPathname()] = file_get_contents($f->getPathname());
        }
        ksort($out);   // ترتيبٌ حتميٌّ — لا قرعةَ نظامِ ملفّات

        return $out;
    }

    private function rel(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), '/');
    }
}
