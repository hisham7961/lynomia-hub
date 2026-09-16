<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * **حارسُ «العدّادُ يعدّ الجدولَ لا الواقع»** (W-5 · الطور ١٦٧).
 *
 * الشكلُ الخطر في سطرَين متباعدَين:
 *
 * ```php
 * $rows = …->limit(12)->get();          // متحكّمٌ يقصّ للعرض
 * ```
 * ```blade
 * <h3>يستحق التجديد <span class="bdg">{{ $rows->count() }}</span></h3>
 * ```
 *
 * **فالشارةُ تقول كم يسع الجدولُ لا كم وقع.** والخطأُ في اتّجاهِ التهوينِ
 * **دائماً**: كلّما ازداد الواقعُ ثبت الرقمُ على سقفِه، **فيعمى المؤشّرُ كلّما
 * ازدادت الحاجةُ إليه**. ورقمٌ يكذب أخطرُ من فجوةٍ ظاهرة — الفجوةُ تدفع إلى
 * الفحص، والرقمُ الكاذبُ يمنع منه لأنّ قارئَه يظنّ أنّه يعرف.
 *
 * **ولماذا حارسٌ لا إصلاحٌ موضعيّ؟** لأنّ الصنفَ ثبت أنّه يتكرّر: W-1 في
 * `/morning`، ثمّ W-4 في `/w/{key}`، ثمّ **ثمانيةَ عشرَ موضعاً** كشفها هذا
 * المسحُ نفسُه. وشاهدُ أنّه سهوٌ لا اصطلاحٌ مقصود أنّ `AppCenterController`
 * يطبّق الصوابَ في سطر (`$issuesN` عدٌّ منفصلٌ عن العرض) ويُغفله في الذي يليه.
 *
 * **وحدُّه معلنٌ كحدِّ أخيه `JsonKeyOrderGuardTest`:** المسحُ اللفظيُّ
 * **لا يُثبت غياباً** — يُمسك الشكلَ المعروفَ الذي كلّفنا فعلاً، وذاك ما
 * يُدّعى له لا أكثر. فقصٌّ في خدمةٍ وسيطةٍ أو عدٌّ في مكوّنٍ مُضمَّن يفلت منه.
 *
 * **والعلاجُ في كلِّ موضع:** العدُّ قبل القصّ ويُمرَّر رقماً مستقلّاً
 * (`$xN = (clone $q)->count()`)، والشارةُ تقول الواقعَ، والمسرودُ يبقى
 * مقصوصاً وفوقَه رابطٌ إلى الكلّ حين يقع القصّ — **صدقٌ في العدّاد لا إغراقٌ
 * للصفحة**.
 */
class TruncatedCountGuardTest extends TestCase
{
    /**
     * مواضعُ مستثناةٌ بتبريرٍ مكتوب — تُضاف هنا **بسببٍ يُقرأ** لا لإسكاتِ
     * الحارس. المفتاح: `ملفُّ المتحكّم:المتغيّر`.
     *
     * @var array<string,string>
     */
    private const ALLOWED = [];

    /** مسارُ قالبٍ من اسمِه في `view('a.b')` */
    private function bladeOf(string $name): string
    {
        return base_path('resources/views/' . str_replace('.', '/', $name) . '.blade.php');
    }

    /** مواضعُ الطباعة `{{ … }}` و`{!! … !!}` في قالب */
    private function printedSpans(string $t): array
    {
        preg_match_all('/\{\{.*?\}\}|\{!!.*?!!\}/su', $t, $m, PREG_OFFSET_CAPTURE);

        return array_map(fn ($x) => [$x[1], $x[1] + strlen($x[0])], $m[0]);
    }

    /**
     * أجسامُ الدوالّ في ملفٍّ — كلٌّ مع رقمِ سطرِ بدايتها، فيُبلَّغ بموضعٍ حقيقيّ.
     *
     * @return array<int,array{0:string,1:int}>
     */
    private function methods(string $src): array
    {
        $parts = preg_split('/^(?=\s*(?:public|protected|private)\s+(?:static\s+)?function\s)/m', $src);
        $out = []; $line = 1;
        foreach ($parts as $p) {
            $out[] = [$p, $line];
            $line += substr_count($p, "\n");
        }

        return $out;
    }

    public function test_no_screen_prints_a_truncated_list_length_as_a_total(): void
    {
        $bad = [];

        foreach (File::allFiles(app_path('Http/Controllers')) as $f) {
            if ($f->getExtension() !== 'php') continue;
            $whole = file_get_contents($f->getPathname());

            /*
             * **المطابقةُ داخلَ الدالّةِ الواحدة لا داخلَ الملفّ.** أوّلُ صياغةٍ
             * قارنت كلَّ إسنادٍ في الملفّ بكلِّ قالبٍ يذكره الملفّ، فبلّغت عن
             * `ModuleController::board()` و`export()` لأنّ `index()` في الملفّ
             * نفسِه تعرض `modules.index` — وهي تمرّر **مُصفِّحاً** (`paginate`)
             * لا قائمةً مقصوصة. فبلاغان كاذبان، والحارسُ الذي يُبلّغ كاذباً
             * يُسكَت فيُفقَد. فالنطاقُ صار جسمَ الدالّة.
             */
            foreach ($this->methods($whole) as [$src, $base]) {

            // القوالبُ التي تعرضها هذه الدالّةُ بعينِها — فلا يُخلَط متغيّرٌ باسمِ آخر
            preg_match_all("/view\(\s*'([a-zA-Z0-9_.\-]+)'/", $src, $vm);
            $blades = [];
            foreach (array_unique($vm[1]) as $v) {
                $p = $this->bladeOf($v);
                if (is_file($p)) $blades[$v] = file_get_contents($p);
            }
            if (! $blades) continue;

            // إسنادٌ ينتهي تعبيرُه بقصٍّ ثابت
            preg_match_all('/\$(\w+)\s*=\s*(.{0,900}?);/su', $src, $am, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($am as $a) {
                [$whole, $off] = $a[0];
                $name = $a[1][0];
                $expr = $a[2][0];
                if (! preg_match('/->\s*(take|limit)\(\s*\d+\s*\)/', $expr)) continue;
                if (isset(self::ALLOWED[$f->getFilename() . ':' . $name])) continue;

                $cLine = $base + substr_count(substr($src, 0, $off), "\n");

                foreach ($blades as $v => $bt) {
                    $spans = $this->printedSpans($bt);
                    $re = '/(?:\$' . preg_quote($name, '/') . '\s*->\s*count\(\)|count\(\s*\$'
                        . preg_quote($name, '/') . '\s*\))/';
                    if (! preg_match_all($re, $bt, $cm, PREG_OFFSET_CAPTURE)) continue;

                    foreach ($cm[0] as $hit) {
                        $at = $hit[1];
                        $printed = false;
                        foreach ($spans as [$s, $e]) {
                            if ($at >= $s && $at < $e) { $printed = true; break; }
                        }
                        if (! $printed) continue;   // `@if ($x->count())` فحصُ خواءٍ لا رقمٌ معروض

                        $bad[] = $f->getFilename() . ':' . $cLine . ' ($' . $name . ') ⇒ '
                               . $v . '.blade:' . (substr_count(substr($bt, 0, $at), "\n") + 1);
                    }
                }
            }
            }
        }

        $bad = array_values(array_unique($bad));
        sort($bad);

        $this->assertSame([], $bad,
            "طولُ قائمةٍ **مقصوصةٍ للعرض** يُطبع على الشاشةِ كأنّه مجموع — فكلّما\n"
            . "ازداد الواقعُ ثبت الرقمُ على سقفِه، وعمى المؤشّرُ حين تشتدّ الحاجةُ إليه.\n"
            . "عُدَّ **قبل** القصّ ومرِّر الرقمَ مستقلّاً، وأبقِ المسرودَ مقصوصاً:\n"
            . implode("\n", $bad));
    }

    /**
     * **وأنّ الحارسَ يحرس فعلاً** — يُركَّب الشكلُ الخطرُ نفسُه في متحكّمٍ
     * وقالبٍ مؤقّتَين ويُتحقَّق أنّ المنطقَ يُمسكه، ثمّ يُزالان. فالحارسُ الذي
     * لا يُمسك شيئاً أبداً يخضرّ كذلك، وخضرتُه بلا معنى.
     */
    public function test_the_guard_itself_catches_the_shape_it_claims_to_catch(): void
    {
        $ctl   = app_path('Http/Controllers/__TruncProbeController.php');
        $vdir  = base_path('resources/views/__truncprobe');
        $blade = $vdir . '/probe.blade.php';

        @mkdir($vdir, 0777, true);
        file_put_contents($ctl, "<?php\n// probe\n\$rows = Model::query()->limit(7)->get();\nreturn view('__truncprobe.probe', compact('rows'));\n");
        file_put_contents($blade, "<h3>عنوان <span class=\"bdg\">{{ \$rows->count() }}</span></h3>\n");

        try {
            $caught = false;
            try {
                $this->test_no_screen_prints_a_truncated_list_length_as_a_total();
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                $caught = str_contains($e->getMessage(), '__TruncProbeController.php');
            }
            $this->assertTrue($caught, 'الحارسُ لم يُمسك الشكلَ الذي يدّعي إمساكَه — فخضرتُه بلا معنى');
        } finally {
            @unlink($ctl);
            @unlink($blade);
            @rmdir($vdir);
        }
    }
}
