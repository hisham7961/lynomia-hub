<?php

namespace App\Support\Platform;

use Closure;
use Illuminate\Routing\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * **لقطةُ البنية — شبكةُ الأمانِ التي تسبق كلَّ نقل** (`docs/REORG_PLAN.md` §R0).
 *
 * إعادةُ التنظيم وعدُها «نقلٌ لا تغييرُ سلوك». والوعدُ لا يُحرَس بالذاكرة بل بلقطةٍ
 * مجمَّدةٍ تُسقط الحزمةَ إن تغيّر ما يراه العالمُ الخارجيّ من النظام:
 *
 *  · **المسارات** — الفعلُ والعنوانُ والاسمُ والمتحكّمُ والوسائط، لكلِّ مسار.
 *    تقسيمُ `routes/web.php` إلى ملفّات (R3) يجب أن يُنتج **القائمةَ نفسَها حرفيّاً**.
 *  · **سجلُّ الوحدات** — بصمةٌ لكلِّ وحدةٍ **وترتيبُها** (الترتيبُ دلاليّ: OpenAPI
 *    والتنقّلُ يقرآنه بترتيب الإعلان). تقسيمُ `config/hub.php` (R4) يجب ألّا يمسّ شيئاً.
 *  · **الأصناف** — كلُّ صنفٍ تحت `app/`. نقلُ النطاقات (R2) يغيّرها **عمداً**، فتُكتب
 *    اللقطةُ من جديد ويظهر كلُّ نقلٍ في الفرق للمراجعة — ولا يضيع صنفٌ بلا أثر.
 *  · **الدوالُّ العامّة** — كلُّ دالّةٍ معرَّفةٍ في `app/`. نقلُ محرّكات helpers إلى
 *    أصناف (R5) يُبقي الدالّةَ غلافاً، فالقائمةُ لا تتغيّر.
 *
 * وما يأتي من البيئة (النسخة، مفاتيحُ `env()`) خارجَ اللقطة — وإلّا صارت قرعةً بين
 * تنصيبٍ وآخر لا حارساً للبنية.
 */
class StructureSnapshot
{
    /** مجلّدُ اللقطات — نسبيّاً لجذر المشروع */
    public const DIR = 'tests/Fixtures/structure';

    public const PARTS = ['routes', 'registry', 'classes', 'functions'];

    /** مفاتيحُ `config('hub')` المشتقّةُ من البيئة — ليست بنية */
    private const ENV_KEYS = ['version', 'allow_destructive', 'outbound', 'bootstrap'];

    /** @return array<string, mixed> */
    public static function build(string $part): array
    {
        return match ($part) {
            'routes' => self::routes(),
            'registry' => self::registry(),
            'classes' => self::classes(),
            'functions' => self::functions(),
        };
    }

    public static function path(string $part): string
    {
        return base_path(self::DIR . '/' . $part . '.json');
    }

    public static function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    }

    /** @return array<string, mixed>|null */
    public static function stored(string $part): ?array
    {
        $file = self::path($part);

        return is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    }

    /** @return list<array{methods: string, uri: string, name: ?string, action: string, middleware: list<string>}> */
    private static function routes(): array
    {
        $out = [];
        /** @var Route $route */
        foreach (app('router')->getRoutes() as $route) {
            $uses = $route->getAction('uses');
            $out[] = [
                'methods' => implode('|', $route->methods()),
                'uri' => $route->uri(),
                'name' => $route->getName(),
                'action' => $uses instanceof Closure ? 'Closure' : (string) $uses,
                'middleware' => array_values(array_map(
                    fn ($m) => $m instanceof Closure ? 'Closure' : (string) $m,
                    $route->gatherMiddleware(),
                )),
            ];
        }
        // ترتيبُ التسجيل يحكم المطابقة — فيُحفظ كما هو، لا مرتّباً أبجديّاً
        return $out;
    }

    /** @return array{order: list<string>, modules: array<string, string>, sections: array<string, string>} */
    private static function registry(): array
    {
        $hub = config('hub');
        $modules = [];
        foreach ($hub['modules'] as $key => $def) {
            $modules[$key] = hash('sha256', self::canonical($def));
        }
        $sections = [];
        foreach ($hub as $key => $value) {
            if ($key === 'modules' || in_array($key, self::ENV_KEYS, true)) {
                continue;
            }
            $sections[$key] = hash('sha256', self::canonical($value));
        }

        return ['order' => array_keys($hub['modules']), 'modules' => $modules, 'sections' => $sections];
    }

    /** @return list<string> */
    private static function classes(): array
    {
        $out = [];
        foreach (self::appFiles() as $rel) {
            if ($rel === 'Support/helpers.php') {
                continue;
            }
            $out[] = 'App\\' . str_replace(['/', '.php'], ['\\', ''], $rel);
        }
        sort($out);

        return $out;
    }

    /** @return list<string> */
    private static function functions(): array
    {
        // دوالُّ ذاتُ فضاءٍ تُعرَّف داخل ملفّاتِ أصناف (`App\Support\in_array_route_show`)
        // فلا توجد إلّا بعد تحميلِ صنفِها — فتُحمَّل الأصنافُ كلُّها أوّلاً، وإلّا صارت
        // القائمةُ قرعةً على ما صادف أن حُمِّل قبلها.
        foreach (self::classes() as $class) {
            class_exists($class) || interface_exists($class) || trait_exists($class);
        }
        $app = realpath(app_path()) . DIRECTORY_SEPARATOR;
        $out = [];
        foreach (get_defined_functions()['user'] as $fn) {
            $file = (string) (new \ReflectionFunction($fn))->getFileName();
            if (str_starts_with($file, $app)) {
                $out[] = $fn;
            }
        }
        sort($out);

        return $out;
    }

    /** @return list<string> مساراتٌ نسبيّةٌ لـ`app/` */
    private static function appFiles(): array
    {
        $root = app_path();
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            }
        }

        return $files;
    }

    /** ترميزٌ ثابت: المفاتيحُ الترابطيّةُ تُرتَّب، والقوائمُ المرقّمةُ تبقى بترتيبها */
    private static function canonical(mixed $value): string
    {
        return json_encode(self::sortAssoc($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function sortAssoc(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value instanceof Closure ? 'Closure' : $value;
        }
        $value = array_map(fn ($v) => self::sortAssoc($v), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
