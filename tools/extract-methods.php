<?php

/**
 * **نقلُ طرائقَ من متحكّمٍ إلى خدماتٍ بمفوِّضاتٍ من سطر** (`docs/REORG_PLAN.md` §R6).
 *
 *     php tools/extract-methods.php <plan.json>
 *
 * ```json
 * {"class": "App\\Http\\Controllers\\Web\\ModuleController",
 *  "targets": {"App\\Support\\Platform\\Modules\\ModuleQuery": ["buildQuery", "chipLabel"], ...},
 *  "consts":  {"FL_OPS": "App\\Support\\Platform\\Modules\\ModuleQuery"}}
 * ```
 *
 * **شرطُ الانغلاق:** كلُّ `$this->x(` في طريقةٍ منقولة يجب أن يكون `x` منقولاً أيضاً — وإلّا توقّفت
 * الأداةُ ولم تكتب شيئاً. فالطرائقُ المنقولةُ لا تعتمد على حالةِ المتحكّم، وتصير ساكنةً بلا تغيير سلوك.
 * **والتعدّدُ الشكليّ محفوظٌ آليّاً:** يُمسح كلُّ ورثةِ المتحكّم تحت `app/` — فإن أعاد وارثٌ تعريفَ طريقةٍ
 * تناديها طريقةٌ منقولةٌ أخرى (`$this->x()` صار `\Service::x()` فيتجاوز إعادةَ التعريف) توقّفت الأداة.
 * (وإعادةُ تعريفِ طريقةٍ منقولةٍ لا يناديها منقولٌ آخر سليمة: المفوِّضُ باقٍ و`parent::` يصله.)
 * **ولا حالةَ صنفٍ ضمنيّة:** `self::class` · `static::class` · `new self/static` · `self::$prop` ·
 * `instanceof self` في النصّ المنقول تُوقف الأداة (معناها يتغيّر في الخدمة)؛ وثابتٌ غيرُ منقولٍ تقرؤه
 * طريقةٌ منقولة يجب أن يكون **عامّاً** (الخدمةُ تقرؤه من الخارج) و`static::CONST` لا يعيد وارثٌ تعريفَه.
 *
 * لكلِّ طريقة: نصُّها وتوثيقُها حرفيّاً إلى الخدمة (`public static`)، و`$this->x(` ⇒ `\Service::x(`،
 * و`self::CONST` المنقول ⇒ `\Service::CONST`؛ وفي المتحكّم مفوِّضٌ بالتوقيعِ والظهورِ نفسَيهما.
 * والثوابتُ المنقولةُ تبقى في المتحكّم أسماءً مستعارة (`const X = \Service::X;`).
 */

$root = realpath(__DIR__ . '/..');
require $root . '/vendor/autoload.php';

$plan = json_decode((string) file_get_contents($argv[1] ?? ''), true);
$cls = $plan['class'] ?? null;
if (! $cls || empty($plan['targets'])) {
    fwrite(STDERR, "usage: php tools/extract-methods.php <plan.json>\n");
    exit(2);
}

$rc = new ReflectionClass($cls);
$path = $rc->getFileName();
$lines = file($path);
$src = implode('', $lines);

// ورثةُ المتحكّم تحت app/ — لا تُفترض قائمتُهم يدويّاً
$heirs = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS)) as $f) {
    if (! str_ends_with((string) $f, '.php')) continue;
    $code = (string) file_get_contents((string) $f);
    if (! preg_match('/^namespace\s+([^;]+);/m', $code, $nsm) || ! preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $code, $cm)) continue;
    $fq = $nsm[1] . '\\' . $cm[1];
    if (class_exists($fq) && is_subclass_of($fq, $cls)) $heirs[] = $fq;
}
sort($heirs);

/**
 * `self::K`/`static::K` في نصٍّ منقول ⇒ `\Service::K` إن انتقل الثابت، وإلّا `\Controller::K` بشرط أن
 * يكون عامّاً ولا يعيد وارثٌ تعريفَه تحت `static::`. و`parent::` أيّاً كان يوقف الأداة (لا أبَ للخدمة).
 */
function rewriteSelf(string $text, string $who, array $constOwner, string $cls, array $heirs): string
{
    if (preg_match('/\bparent::|\b(?:self|static)::(?:class\b|\$)|\bnew\s+(?:self|static)\b|\binstanceof\s+(?:self|static)\b/', $text, $bad)) {
        fwrite(STDERR, "✗ {$who} تستعمل «{$bad[0]}» — معناها يتغيّر في الخدمة\n"); exit(1);
    }

    return preg_replace_callback('/\b(self|static)::([A-Za-z_]\w*)\b(?!\s*\()/', function ($x) use ($constOwner, $cls, $heirs, $who) {
        [$all, $kw, $k] = $x;
        if (isset($constOwner[$k])) return '\\' . $constOwner[$k] . '::' . $k;
        if (! (new ReflectionClass($cls))->hasConstant($k)) { fwrite(STDERR, "✗ {$who}: «{$all}» ليس ثابتاً في المتحكّم\n"); exit(1); }
        if (! (new ReflectionClassConstant($cls, $k))->isPublic()) {
            fwrite(STDERR, "✗ {$who} تقرأ «{$all}» غيرَ العامّ — انقله مع الطريقة (consts) أو أبقِها\n"); exit(1);
        }
        if ($kw === 'static') {
            foreach ($heirs as $h) {
                if ((new ReflectionClassConstant($h, $k))->class === $h) { fwrite(STDERR, "✗ {$who}: {$h} يعيد تعريفَ {$k} و«static::» يربط متأخّراً\n"); exit(1); }
            }
        }

        return '\\' . $cls . '::' . $k;
    }, $text);
}

/** وسائطُ التوقيع بأسمائها — والمتغيّرُ العدد (`T_ELLIPSIS` لا نصُّ «...» الذي قد يكون قيمةً افتراضيّة) مبسوط */
function argList(string $params): string
{
    $args = [];
    $variadic = false;
    foreach (PhpToken::tokenize('<?php function x(' . $params . '){}') as $t) {
        if ($t->id === T_VARIABLE) $args[] = $t->text;
        if ($t->id === T_ELLIPSIS) $variadic = true;
    }
    $list = implode(', ', $args);

    return $variadic ? (string) preg_replace('/(\$\w+)$/', '...$1', $list) : $list;
}

// كلُّ خدمةٍ هدف: اسمُ صنفٍ صحيحٌ تحت App\ وملفُّها غيرُ موجود — يُفحص **قبل أيّ كتابة** فلا نصفَ نقل
foreach (array_unique([...array_keys($plan['targets']), ...array_values($plan['consts'] ?? [])]) as $svc) {
    if (! preg_match('/^App(\\\\[A-Z]\w*)+$/', $svc)) { fwrite(STDERR, "✗ اسمُ خدمةٍ غيرُ صالح: {$svc}\n"); exit(1); }
    if (is_file($root . '/app/' . str_replace('\\', '/', substr($svc, 4)) . '.php')) { fwrite(STDERR, "✗ موجود: {$svc}\n"); exit(1); }
}

$owner = [];   // method => service fqcn
foreach ($plan['targets'] as $svc => $methods) {
    foreach ($methods as $m) $owner[$m] = $svc;
}
$constOwner = $plan['consts'] ?? [];

// استيراداتُ ملفِّ المتحكّم
$imports = [];
foreach ($lines as $l) {
    if (preg_match('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+(\w+))?\s*;/', $l, $mm)) {
        $imports[$mm[2] ?? substr($mm[1], strrpos($mm[1], '\\') + 1)] = $mm[1];
    }
}

$services = [];
$edits = [];
foreach ($owner as $name => $svc) {
    $m = $rc->getMethod($name);
    if ($m->class !== $cls) { fwrite(STDERR, "✗ {$name} ليست معرَّفةً في {$cls}\n"); exit(1); }
    if ($m->isStatic()) { fwrite(STDERR, "✗ {$name} ساكنةٌ أصلاً\n"); exit(1); }
    $start = $m->getStartLine() - 1;
    $end = $m->getEndLine() - 1;
    $docStart = $start;
    if ($m->getDocComment() !== false) {
        $k = $start - 1;
        while ($k >= 0 && trim($lines[$k]) === '') $k--;
        if ($k >= 0 && str_ends_with(trim($lines[$k]), '*/')) {
            while ($k >= 0 && ! str_contains($lines[$k], '/**')) $k--;
            $docStart = $k;
        }
    }
    $text = implode('', array_slice($lines, $start, $end - $start + 1));
    $doc = $docStart < $start ? implode('', array_slice($lines, $docStart, $start - $docStart)) : '';

    // الانغلاق
    preg_match_all('/\$this->(\w+)\s*\(/', $text, $calls);
    foreach (array_unique($calls[1]) as $c) {
        if (! isset($owner[$c])) { fwrite(STDERR, "✗ {$name} تنادي \$this->{$c} غيرَ المنقولة — العنقودُ غيرُ منغلق\n"); exit(1); }
    }
    if (preg_match('/\$this(?!->\w+\s*\()/', $text)) { fwrite(STDERR, "✗ {$name} تستعمل \$this لغير نداء طريقة\n"); exit(1); }
    // وارثٌ يعيد تعريفَ طريقةٍ تناديها هذه الطريقة ⇒ النداءُ الساكنُ يتجاوزه
    foreach (array_unique($calls[1]) as $c) {
        if ($c === $name) continue;
        foreach ($heirs as $h) {
            if ((new ReflectionMethod($h, $c))->class === $h) {
                fwrite(STDERR, "✗ {$name} تنادي {$c} و{$h} يعيد تعريفَها — النقلُ يتجاوز إعادةَ التعريف\n"); exit(1);
            }
        }
    }

    $body = preg_replace_callback('/\$this->(\w+)\s*\(/', fn ($x) => '\\' . $owner[$x[1]] . '::' . $x[1] . '(', $text);
    $body = rewriteSelf($body, $name, $constOwner, $cls, $heirs);
    if (preg_match('/\b(self|static|parent)::\w+\s*\(/', $body)) { fwrite(STDERR, "✗ {$name} تنادي self/static/parent::طريقة\n"); exit(1); }
    $body = preg_replace('/^(\s*)(?:public|protected|private)\s+function\s+/m', '$1public static function ', $body, 1);
    $services[$svc]['methods'][] = $doc . $body;

    // الاستيراداتُ التي يحتاجها النصّ المنقول
    foreach ($imports as $alias => $fq) {
        if (preg_match('/(?<![\\\\\w$])' . preg_quote($alias, '/') . '(?!\w)/', $text)) $services[$svc]['uses'][$fq] = true;
    }

    // المفوِّض
    if (! preg_match('/function\s+' . preg_quote($name, '/') . '\s*\((.*?)\)\s*(:\s*[^{]+)?\{/s', $text, $sig)) {
        fwrite(STDERR, "✗ لا توقيعَ لـ {$name}\n"); exit(1);
    }
    $params = $sig[1];
    $ret = isset($sig[2]) ? rtrim($sig[2]) : '';
    $argList = argList($params);
    $vis = $m->isPublic() ? 'public' : ($m->isProtected() ? 'protected' : 'private');
    $void = trim(ltrim($ret, ':')) === 'void';
    $short = substr($svc, strrpos($svc, '\\') + 1);
    $delegate = "    /** مفوِّضٌ — المنطقُ في `{$short}::{$name}` (docs/REORG_PLAN.md §R6) */\n"
        . "    {$vis} function {$name}({$params}){$ret}\n    {\n        "
        . ($void ? '' : 'return ') . "\\{$svc}::{$name}({$argList});\n    }\n";
    $edits[] = [$docStart, $end, $delegate];
}

// الثوابتُ المنقولة: نصُّها إلى الخدمة، ومكانَها اسمٌ مستعار
foreach ($constOwner as $const => $svc) {
    $rcc = new ReflectionClassConstant($cls, $const);
    $vis = $rcc->isPublic() ? 'public' : ($rcc->isProtected() ? 'protected' : 'private');
    if (! preg_match('/^(\s*)(?:(?:public|protected|private)\s+)?const\s+' . $const . '\s*=/m', $src, $cm, PREG_OFFSET_CAPTURE)) {
        fwrite(STDERR, "✗ لا ثابتَ {$const}\n"); exit(1);
    }
    $startLine = substr_count(substr($src, 0, $cm[0][1]), "\n");
    $k = $startLine;
    while (! preg_match('/;\s*(\/\/.*)?$/', rtrim($lines[$k]))) $k++;
    $constText = implode('', array_slice($lines, $startLine, $k - $startLine + 1));
    // نصُّ الثابت يمرّ بما تمرّ به الطرائق: self:: يُعاد حلُّه، والأسماءُ المستوردةُ تُستورد في الخدمة
    $constText = rewriteSelf($constText, $const, $constOwner, $cls, $heirs);
    foreach ($imports as $alias => $fq) {
        if (preg_match('/(?<![\\\\\w$])' . preg_quote($alias, '/') . '(?!\w)/', $constText)) $services[$svc]['uses'][$fq] = true;
    }
    $services[$svc]['consts'][] = preg_replace('/^(\s*)(?:(?:public|protected|private)\s+)?const/', '$1public const', $constText, 1);
    $edits[] = [$startLine, $k, "    {$vis} const {$const} = \\{$svc}::{$const};   // انتقل (docs/REORG_PLAN.md §R6)\n"];
}

foreach ($services as $svc => $s) {
    $ns = substr($svc, 0, strrpos($svc, '\\'));
    $short = substr($svc, strrpos($svc, '\\') + 1);
    $file = $root . '/app/' . str_replace('\\', '/', substr($svc, 4)) . '.php';
    if (is_file($file)) { fwrite(STDERR, "✗ موجود: {$svc}\n"); exit(1); }
    $uses = array_keys($s['uses'] ?? []);
    sort($uses);
    $code = "<?php\n\nnamespace {$ns};\n\n" . ($uses ? implode('', array_map(fn ($u) => "use {$u};\n", $uses)) . "\n" : '')
        . "/**\n * طرائقُ نُقلت من `" . substr($cls, strrpos($cls, '\\') + 1) . "` بلا تغيير (docs/REORG_PLAN.md §R6) — والمتحكّمُ يفوّض\n"
        . " * إليها بالتوقيعِ والظهورِ نفسَيهما، فالورثةُ لا يتغيّرون.\n */\nfinal class {$short}\n{\n"
        . (isset($s['consts']) ? implode("\n", $s['consts']) . "\n" : '')
        . implode("\n", $s['methods']) . "}\n";
    if (! is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
    file_put_contents($file, $code);
    echo "✓ {$svc} (" . count($s['methods']) . ")\n";
}

usort($edits, fn ($a, $b) => $b[0] <=> $a[0]);
foreach ($edits as [$s, $e, $w]) array_splice($lines, $s, $e - $s + 1, [$w]);
file_put_contents($path, implode('', $lines));
echo count($edits) . " تعديلاً على المتحكّم\n";
