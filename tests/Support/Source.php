<?php

namespace Tests\Support;

use ReflectionClass;
use ReflectionFunction;
use RuntimeException;

/**
 * **مصدرُ الشيفرة بهويّتِها لا بمسارِها** (`docs/REORG_PLAN.md` §R1).
 *
 * حرّاسٌ كثيرةٌ تقرأ نصَّ صنفٍ لتتأكّد من بنيته (قفلٌ، معاملةٌ، غيابُ نداءٍ خطِر).
 * وكانت تقرؤه بمسارٍ مكتوبٍ (`app_path('Support/Odoo.php')`) — فنقلُ الصنفِ إلى
 * نطاقه (`App\Support\Integrations\Odoo`) يُسقط الحارسَ، أو — وهو الأسوأ —
 * يُفرغه: مسحٌ بنمطٍ مسطّح (`glob('Support/Ai*.php')`) يعود فارغاً بعد النقل
 * **فينجح الحارسُ وهو لا يحرس شيئاً**.
 *
 * فالقراءةُ هنا بالهويّة: اسمُ الصنف يُحَلّ بالانعكاس إلى ملفِّه أينما كان.
 * صنفٌ لم يعد موجوداً يُسقط الاختبارَ بصوتٍ عالٍ — لا يُفرغه.
 */
final class Source
{
    /** مسارُ ملفِّ صنفٍ (أو واجهةٍ أو سمة) أينما نُقل */
    public static function path(string $class): string
    {
        if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class)) {
            throw new RuntimeException("«{$class}» لا يُحمَّل — نُقل دون تحديثِ الحارس؟");
        }

        return (string) (new ReflectionClass($class))->getFileName();
    }

    public static function read(string $class): string
    {
        return (string) file_get_contents(self::path($class));
    }

    /** مسارُ ملفِّ الدوالِّ العامّة — يُحَلّ من دالّةٍ فيه لا من اسمِه */
    public static function helpersPath(): string
    {
        return (string) (new ReflectionFunction('hub_can'))->getFileName();
    }

    public static function helpers(): string
    {
        return (string) file_get_contents(self::helpersPath());
    }

    /**
     * نصُّ ملفِّ مساراتٍ **وكلِّ ما يُضمِّنه** — `routes/web.php` ثمّ `routes/web/*.php`
     * بترتيبٍ ثابت. تقسيمُ المسارات إلى ملفّات (R3) لا يُعمي حارساً يبحث في نصّها.
     */
    public static function routes(string $name = 'web'): string
    {
        $files = [base_path("routes/{$name}.php")];
        $parts = glob(base_path("routes/{$name}/*.php")) ?: [];
        sort($parts);
        $src = '';
        foreach ([...$files, ...$parts] as $file) {
            if (is_file($file)) {
                $src .= file_get_contents($file) . "\n";
            }
        }

        return $src;
    }

    /**
     * ملفّاتٌ تحت `app/{$dir}` — **بحثاً متكرّراً** — يطابق اسمُها `$pattern`
     * (`Ai*.php`). والحدُّ الأدنى إلزاميّ: مسحٌ يعود بأقلَّ منه يرمي ولا يُعيد
     * قائمةً قصيرةً ينجح عليها حارسٌ لا يرى شيئاً.
     *
     * @return list<string>
     */
    public static function files(string $dir, string $pattern, int $atLeast): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path($dir), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (fnmatch($pattern, $file->getFilename())) {
                $out[] = $file->getPathname();
            }
        }
        sort($out);
        if (count($out) < $atLeast) {
            throw new RuntimeException('مسحُ «' . $dir . '/**/' . $pattern . '» وجد ' . count($out) . " ملفّاً والحدُّ الأدنى {$atLeast} — الحارسُ يُفرَغ");
        }

        return $out;
    }
}
