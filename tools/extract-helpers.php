<?php

/**
 * **نقلُ دوالِّ `helpers.php` إلى أصنافٍ بغلافٍ من سطرٍ واحد** (`docs/REORG_PLAN.md` §R5).
 *
 *     php tools/extract-helpers.php <plan.json>
 *
 * `plan.json`: `{"hub_recommendations": "App\\Support\\Insights\\Recommendations::build", ...}`
 *
 * لكلِّ دالّة:
 *  1. يُقتطع نصُّها (ومعه تعليقُها التوثيقيّ) **حرفيّاً** بحدودِ الانعكاس (السطرُ الأوّلُ والأخير).
 *  2. يصير جسمُها طريقةً ساكنةً بالتوقيعِ نفسِه في الصنفِ الهدف — والصنفُ يُنشأ أو يُلحَق به.
 *  3. **أسماءُ الأصنافِ غيرُ المؤهَّلة** في الجسم كانت تُحَلّ في الفضاءِ العامّ (helpers.php بلا
 *     فضاء)؛ وفي صنفٍ ذي فضاءٍ تُحَلّ نسبيّاً — فتُؤهَّل: ما استورده helpers.php يُستورد في الصنف،
 *     وما سواه يُسبَق بـ`\`. (الدوالُّ والثوابتُ غيرُ المؤهَّلة تسقط إلى العامّ تلقائيّاً فلا تُمسّ.)
 *  4. تبقى الدالّةُ العامّةُ في موضعها **غلافاً يمرّر وسائطَه كما هي** — فلا Blade ولا متحكّمَ يتغيّر.
 *
 * والحكمُ بعدها للحزمة: لقطةُ الدوالّ (لا تتغيّر قائمتُها) و`StaticSoundnessTest` والمحرّكان.
 */

$root = realpath(__DIR__ . '/..');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$plan = json_decode((string) file_get_contents($argv[1] ?? ''), true);
if (! is_array($plan) || $plan === []) {
    fwrite(STDERR, "usage: php tools/extract-helpers.php <plan.json>\n");
    exit(2);
}

$helpersPath = (new ReflectionFunction('hub_can'))->getFileName();
$lines = file($helpersPath);

// استيراداتُ helpers.php — الاسمُ القصيرُ ⇒ المؤهَّل
$imports = [];
foreach ($lines as $l) {   // أسطرُ `use` العلويّة — الملفُّ بلا فضاء
    if (preg_match('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+(\w+))?\s*;/', $l, $m)) {
        $imports[strtolower($m[2] ?? substr($m[1], strrpos($m[1], '\\') + 1))] = $m[1];
    }
}

/** يؤهّل الأسماءَ غيرَ المؤهَّلة في موضعِ صنف — ويُعيد النصَّ والاستيراداتِ اللازمة */
function qualify(string $code, array $imports): array
{
    $tokens = PhpToken::tokenize("<?php\n" . $code);
    $n = count($tokens);
    $sig = function (int $i, int $dir) use ($tokens, $n) {
        for ($j = $i + $dir; $j >= 0 && $j < $n; $j += $dir) {
            if (! $tokens[$j]->isIgnorable()) return $j;
        }
        return null;
    };
    $need = [];
    $out = '';
    foreach ($tokens as $i => $t) {
        if ($i === 0) continue;   // <?php المضاف
        if ($t->id !== T_STRING) { $out .= $t->text; continue; }
        $p = $sig($i, -1); $q = $sig($i, 1);
        $pt = $p !== null ? $tokens[$p] : null; $nt = $q !== null ? $tokens[$q] : null;
        $lower = strtolower($t->text);
        $skip = in_array($lower, ['self', 'static', 'parent', 'true', 'false', 'null', 'mixed', 'array', 'callable',
            'iterable', 'object', 'int', 'float', 'string', 'bool', 'void', 'never'], true)
            || ($pt && in_array($pt->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true));
        $classRef = ! $skip && (
            ($nt && $nt->id === T_DOUBLE_COLON)
            || ($pt && in_array($pt->id, [T_NEW, T_INSTANCEOF, T_CATCH], true))
            || ($pt && $pt->text === '(' && ($pp = $sig($p, -1)) !== null && $tokens[$pp]->id === T_CATCH)
            || ($nt && $nt->id === T_VARIABLE && $pt && in_array($pt->text, ['(', ',', '?', '|'], true))
            || ($pt && $pt->text === '|' ) || ($nt && $nt->text === '|' && $pt && in_array($pt->text, ['(', ','], true))
            || ($pt && ($pt->text === ':' || $pt->text === '?') && $nt && in_array($nt->text, ['{', ';', '=>'], true))
        );
        if (! $classRef) { $out .= $t->text; continue; }
        if (isset($imports[$lower])) { $need[$imports[$lower]] = true; $out .= $t->text; continue; }
        $out .= '\\' . $t->text;   // كان يُحَلّ عامّاً — فيُؤهَّل عامّاً
    }

    return [$out, array_keys($need)];
}

$edits = [];   // [start, end, replacement] على أسطر helpers.php (0-based)
$classes = []; // FQCN => ['methods' => [...], 'uses' => []]

foreach ($plan as $fn => $target) {
    [$fqcn, $methodName] = explode('::', $target);
    $rf = new ReflectionFunction($fn);
    $start = $rf->getStartLine() - 1;
    $end = $rf->getEndLine() - 1;
    // التعليقُ التوثيقيّ فوقها (إن وُجد) ينتقل معها
    $docStart = $start;
    $doc = $rf->getDocComment();
    if ($doc !== false) {
        $k = $start - 1;
        while ($k >= 0 && trim($lines[$k]) === '') $k--;
        if ($k >= 0 && str_ends_with(trim($lines[$k]), '*/')) {
            while ($k >= 0 && ! str_contains($lines[$k], '/**')) $k--;
            $docStart = $k;
        }
    }
    $src = implode('', array_slice($lines, $start, $end - $start + 1));
    $docText = $docStart < $start ? implode('', array_slice($lines, $docStart, $start - $docStart)) : '';

    // التوقيع: من «function name(» إلى أوّل «{» بعد القوس المغلِق
    if (! preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\((.*?)\)\s*(:\s*[^{]+)?\{/s', $src, $m, PREG_OFFSET_CAPTURE)) {
        fwrite(STDERR, "✗ لا توقيعَ لـ {$fn}\n");
        exit(1);
    }
    $params = $m[1][0];
    $ret = isset($m[2]) ? rtrim($m[2][0]) : '';
    $bodyStart = $m[0][1] + strlen($m[0][0]);
    $body = substr($src, $bodyStart, strrpos($src, '}') - $bodyStart);

    [$body, $uses] = qualify($body, $imports);
    [$paramsQ, $uses2] = qualify($params, $imports);
    [$retQ, $uses3] = qualify($ret, $imports);

    // الوسائطُ بأسمائها وبترتيبها — والمتغيّرةُ العدد تُمرَّر مبسوطة
    $args = [];
    foreach (PhpToken::tokenize('<?php function x(' . $params . '){}') as $i => $t) {
        if ($t->id === T_VARIABLE) $args[] = $t->text;
    }
    $variadic = str_contains($params, '...');
    $argList = implode(', ', $args);
    if ($variadic) $argList = preg_replace('/(\$\w+)$/', '...$1', $argList);

    // الإزاحةُ كما هي: الدالّةُ داخل `if (! function_exists)` بإزاحةِ أربعٍ وجسمُها بثمانٍ —
    // وهي إزاحةُ طريقةٍ داخل صنفٍ حرفيّاً
    $code = $docText . "    public static function {$methodName}({$paramsQ}){$retQ}\n    {" . $body . "}\n";
    $classes[$fqcn]['methods'][] = $code;
    foreach ([...$uses, ...$uses2, ...$uses3] as $u) $classes[$fqcn]['uses'][$u] = true;

    $void = trim(ltrim($ret, ':')) === 'void';
    $short = substr($fqcn, strrpos($fqcn, '\\') + 1);
    $wrapper = "    /** غلافٌ — المنطقُ في `{$short}::{$methodName}` (docs/REORG_PLAN.md §R5) */\n"
        . "    function {$fn}({$params}){$ret}\n    {\n        "
        . ($void ? '' : 'return ') . "\\{$fqcn}::{$methodName}({$argList});\n    }\n";
    $edits[] = [$docStart, $end, $wrapper];
}

// الأصناف
foreach ($classes as $fqcn => $c) {
    $ns = substr($fqcn, 0, strrpos($fqcn, '\\'));
    $short = substr($fqcn, strrpos($fqcn, '\\') + 1);
    $path = $root . '/app/' . str_replace('\\', '/', substr($fqcn, 4)) . '.php';
    if (is_file($path)) {
        fwrite(STDERR, "✗ الصنفُ موجود: {$fqcn}\n");
        exit(1);
    }
    $uses = array_keys($c['uses'] ?? []);
    sort($uses);
    $code = "<?php\n\nnamespace {$ns};\n\n" . ($uses ? implode('', array_map(fn ($u) => "use {$u};\n", $uses)) . "\n" : '')
        . "/**\n * محرّكاتٌ نُقلت من `helpers.php` بلا تغيير (docs/REORG_PLAN.md §R5) — والدوالُّ العامّةُ بأسمائها\n"
        . " * باقيةٌ هناك أغلفةً من سطرٍ واحد، فلا Blade ولا متحكّمَ يتغيّر.\n */\nfinal class {$short}\n{\n"
        . implode("\n", $c['methods']) . "}\n";
    if (! is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
    file_put_contents($path, $code);
    echo "✓ {$fqcn} (" . count($c['methods']) . ")\n";
}

// helpers.php — من الأسفل إلى الأعلى كي لا تنزاح الأسطر
usort($edits, fn ($a, $b) => $b[0] <=> $a[0]);
foreach ($edits as [$s, $e, $w]) {
    array_splice($lines, $s, $e - $s + 1, [$w]);
}
file_put_contents($helpersPath, implode('', $lines));
echo count($edits) . " دالّةً صارت غلافاً\n";
