<?php

/**
 * **نقلُ أصنافٍ بين الفضاءات بلا كسر** (`docs/REORG_PLAN.md` §R2).
 *
 *     php tools/move-classes.php <mapping.json> [--dry]
 *
 * `mapping.json`: `{"App\\Support\\AiChat": "App\\Support\\Ai\\Gateway\\AiChat", ...}`
 * — **الاسمُ القصيرُ يبقى كما هو**، فأجسامُ الشيفرة لا تتغيّر؛ يتغيّر الفضاءُ وحده.
 *
 * ما يفعله، بالرموز (tokens) لا بالنصّ حيث يلزم:
 *  1. `git mv` لكلِّ ملفٍّ إلى مسارِ PSR-4 لفضائه الجديد، وتحديثُ سطر `namespace`.
 *  2. كلُّ مرجعٍ مؤهَّلٍ كاملاً (`use`، `\App\…`، نصوصُ `'App\\…'` في PHP وBlade والإعدادات)
 *     يُستبدل — بحدٍّ لفظيٍّ يمنع `AiChat` من مطابقة `AiChatX`.
 *  3. **المراجعُ غيرُ المؤهَّلة** — التي كانت تُحَلّ ضمنيّاً بالفضاء المشترك:
 *     · في الملفِّ المنقول: جارٌ بقي في الفضاء القديم (أو نُقل إلى فضاءٍ آخر) يحتاج `use`.
 *     · في ملفٍّ باقٍ: جارٌ نُقل عنه يحتاج `use`.
 *     تُكتشف بتحليل الرموز: اسمٌ بسيط (`T_STRING`) في موضعِ صنف (قبل `::`، بعد `new`
 *     و`instanceof` و`extends` و`implements` و`catch`، نوعُ وسيطٍ أو عائد أو خاصيّة،
 *     `use` سمةٍ داخل صنف) لا يُطابق استيراداً قائماً.
 *
 * والحكمُ بعده ليس للأداة: `StaticSoundnessTest` (كلُّ استيرادٍ يُحَلّ · كلُّ نداءٍ ساكنٍ
 * موجود · المسارُ يطابق الفضاء) ولقطةُ البنية والحزمةُ على المحرّكين.
 */

$args = array_values(array_filter(array_slice($argv, 1), fn ($a) => $a !== '--dry'));
$dry = in_array('--dry', $argv, true);
if (count($args) !== 1) {
    fwrite(STDERR, "usage: php tools/move-classes.php <mapping.json> [--dry]\n");
    exit(2);
}

$root = realpath(__DIR__ . '/..');
$map = json_decode((string) file_get_contents($args[0]), true);
if (! is_array($map) || $map === []) {
    fwrite(STDERR, "mapping فارغ أو غير صالح\n");
    exit(2);
}

$pathOf = fn (string $fqcn) => $root . '/app/' . str_replace('\\', '/', substr($fqcn, 4)) . '.php';
$nsOf = fn (string $fqcn) => substr($fqcn, 0, (int) strrpos($fqcn, '\\'));
$shortOf = fn (string $fqcn) => substr($fqcn, (int) strrpos($fqcn, '\\') + 1);

foreach ($map as $old => $new) {
    if (! str_starts_with($old, 'App\\') || ! str_starts_with($new, 'App\\')) {
        fwrite(STDERR, "خارج App\\: $old → $new\n");
        exit(2);
    }
    if ($shortOf($old) !== $shortOf($new)) {
        fwrite(STDERR, "الاسمُ القصيرُ يتغيّر ($old → $new) — الأداةُ تنقل الفضاءَ وحده\n");
        exit(2);
    }
    if (! is_file($pathOf($old))) {
        fwrite(STDERR, "لا ملفّ لـ $old\n");
        exit(2);
    }
    if (is_file($pathOf($new))) {
        fwrite(STDERR, "الوجهةُ موجودة: $new\n");
        exit(2);
    }
}

// ── كلُّ صنفٍ قائمٍ تحت App\ قبل النقل (PSR-4) ──
$existing = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() === 'php') {
        $rel = substr($f->getPathname(), strlen($root) + 5, -4);
        $existing[strtolower('App\\' . str_replace('/', '\\', $rel))] = 'App\\' . str_replace('/', '\\', $rel);
    }
}
$mapLower = [];
foreach ($map as $o => $n) {
    $mapLower[strtolower($o)] = $n;
}

// ── الملفّاتُ المفحوصة ──
$files = [];
foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'tests', 'resources/views'] as $dir) {
    if (! is_dir("$root/$dir")) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (str_ends_with($p, '.php') && ! str_contains($p, '/tests/Fixtures/')) {
            $files[] = $p;
        }
    }
}
sort($files);

/** تحليلُ ملفّ: الفضاء، الاستيرادات، ومواضعُ الأسماءِ البسيطةِ في موضعِ صنف */
function analyse(string $src): array
{
    $tokens = PhpToken::tokenize($src);
    $ns = '';
    $imports = [];       // alias(lower) => fqcn
    $refs = [];          // lower short => original
    $depth = 0;
    $n = count($tokens);
    $sig = function (int $i, int $dir) use ($tokens, $n) {
        for ($j = $i + $dir; $j >= 0 && $j < $n; $j += $dir) {
            if (! $tokens[$j]->isIgnorable()) return $j;
        }
        return null;
    };
    $lastUseEnd = null;
    $nsEnd = null;
    $classyStart = null;
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if ($t->text === '{' || $t->id === T_CURLY_OPEN || $t->id === T_DOLLAR_OPEN_CURLY_BRACES) $depth++;
        if ($t->text === '}') $depth--;

        if ($t->id === T_NAMESPACE && $depth === 0) {
            $j = $sig($i, 1);
            if ($j !== null && in_array($tokens[$j]->id, [T_NAME_QUALIFIED, T_STRING], true)) {
                $ns = $tokens[$j]->text;
                $k = $j;
                while ($k < $n && $tokens[$k]->text !== ';' && $tokens[$k]->text !== '{') $k++;
                $nsEnd = $tokens[$k]->pos + 1;
            }
            continue;
        }
        if ($t->id === T_USE && $depth === 0) {
            // استيرادٌ علويّ: use A\B; · use A\B as C; · use A\{B, C as D}; · use function/const (تُتخطّى)
            $j = $sig($i, 1);
            $kind = strtolower($tokens[$j]->text);
            $k = $j;
            $buf = '';
            while ($k < $n && $tokens[$k]->text !== ';') {
                $buf .= $tokens[$k]->text;
                $k++;
            }
            $lastUseEnd = $tokens[$k]->pos + 1;
            $i = $k;
            if ($kind === 'function' || $kind === 'const') continue;
            $buf = trim(preg_replace('/\s+/', ' ', $buf));
            if (preg_match('/^(.*)\\\\\{(.*)\}$/', $buf, $g)) {
                foreach (explode(',', $g[2]) as $part) {
                    $part = trim($part);
                    if ($part === '') continue;
                    [$name, $alias] = array_pad(preg_split('/\s+as\s+/i', $part), 2, null);
                    $fq = ltrim($g[1], '\\') . '\\' . trim($name);
                    $imports[strtolower($alias ?? substr($fq, strrpos($fq, '\\') + 1))] = $fq;
                }
            } else {
                foreach (explode(',', $buf) as $part) {
                    [$name, $alias] = array_pad(preg_split('/\s+as\s+/i', trim($part)), 2, null);
                    $fq = ltrim(trim($name), '\\');
                    $short = str_contains($fq, '\\') ? substr($fq, strrpos($fq, '\\') + 1) : $fq;
                    $imports[strtolower($alias ?? $short)] = $fq;
                }
            }
            continue;
        }
        if (in_array($t->id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $classyStart === null) {
            $p = $sig($i, -1);
            if ($p === null || $tokens[$p]->id !== T_DOUBLE_COLON) $classyStart = $t->pos;
        }
        if ($t->id !== T_STRING) continue;

        $prev = $sig($i, -1);
        $next = $sig($i, 1);
        $pt = $prev !== null ? $tokens[$prev] : null;
        $nt = $next !== null ? $tokens[$next] : null;
        $lower = strtolower($t->text);
        if (in_array($lower, ['self', 'static', 'parent', 'true', 'false', 'null', 'mixed', 'array', 'callable', 'iterable', 'object', 'int', 'float', 'string', 'bool', 'void', 'never'], true)) continue;
        if ($pt && in_array($pt->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_GOTO, T_NAMESPACE], true)) continue;
        if ($pt && in_array($pt->id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) continue; // تصريحُ الصنفِ نفسِه
        if ($pt && $pt->id === T_CASE) continue;                                              // حالةُ enum
        $isClassRef = false;
        if ($nt && $nt->id === T_DOUBLE_COLON) $isClassRef = true;
        elseif ($pt && in_array($pt->id, [T_NEW, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS], true)) $isClassRef = true;
        elseif ($pt && $pt->text === ',' && $nt && in_array($nt->text, ['{', ','], true)) $isClassRef = true; // implements A, B {
        elseif ($pt && $pt->id === T_USE && $depth > 0) $isClassRef = true;                  // use Trait;
        elseif ($pt && $pt->text === '|' ) $isClassRef = true;                                 // A|B في catch/أنواع
        elseif ($nt && $nt->id === T_VARIABLE) $isClassRef = true;                             // نوعُ وسيطٍ أو خاصيّة
        elseif ($nt && $nt->text === '|' ) $isClassRef = true;
        elseif ($nt && $nt->id === T_ELLIPSIS) $isClassRef = true;                             // Foo ...$xs
        elseif ($pt && ($pt->text === ':' || $pt->text === '?') && $nt && in_array($nt->text, ['{', ';', '=>', '|'], true)) $isClassRef = true; // نوعُ عائد
        elseif ($pt && $pt->text === '(' && $nt && $nt->text === '|') $isClassRef = true;
        elseif ($pt && $pt->text === '(' && $nt && $nt->text === ')' && ($pp = $sig($prev, -1)) !== null && $tokens[$pp]->id === T_CATCH) $isClassRef = true;
        elseif ($pt && $pt->text === '?' && $nt && $nt->id === T_VARIABLE) $isClassRef = true;
        if ($isClassRef) $refs[$lower] = $t->text;
    }

    return ['ns' => $ns, 'imports' => $imports, 'refs' => $refs, 'lastUseEnd' => $lastUseEnd, 'nsEnd' => $nsEnd];
}

// ── التخطيط ──
$plans = [];   // path => ['src' => ..., 'adds' => [fqcn...], 'newNs' => ?, 'moveTo' => ?]
$movedPaths = [];
foreach ($map as $old => $new) {
    $movedPaths[$pathOf($old)] = [$old, $new];
}

foreach ($files as $path) {
    $src = (string) file_get_contents($path);
    $isBlade = str_ends_with($path, '.blade.php');
    $out = $src;

    // (٢) المراجعُ المؤهَّلةُ كاملاً — نصّاً بحدٍّ لفظيّ (\ مفردة و\\ مزدوجة)
    foreach ($map as $old => $new) {
        $o1 = preg_quote($old, '/');
        $out = preg_replace('/(?<![A-Za-z0-9_\\\\])' . $o1 . '(?![A-Za-z0-9_\\\\])/', str_replace('\\', '\\\\', $new), $out);
        $out = preg_replace('/(?<![A-Za-z0-9_\\\\])\\\\' . $o1 . '(?![A-Za-z0-9_\\\\])/', str_replace('\\', '\\\\', '\\' . $new), $out);
        $o2 = preg_quote(str_replace('\\', '\\\\', $old), '/');
        $out = preg_replace('/(?<![A-Za-z0-9_\\\\])' . $o2 . '(?![A-Za-z0-9_\\\\])/', str_replace('\\', '\\\\', str_replace('\\', '\\\\', $new)), $out);
        // ورابعةٌ: `\\\\` داخل نصٍّ مزدوجٍ يولّد ملفّاً (مولِّدُ خريطةِ المزوّدين) — لقيت في R2a
        $o4 = preg_quote(str_replace('\\', '\\\\\\\\', $old), '/');
        $out = preg_replace('/(?<![A-Za-z0-9_\\\\])' . $o4 . '(?![A-Za-z0-9_\\\\])/', str_replace('\\', '\\\\', str_replace('\\', '\\\\\\\\', $new)), $out);
    }

    $adds = [];
    $newNs = null;
    if (! $isBlade) {
        $a = analyse($src);   // التحليلُ على المصدرِ قبل الاستبدال: الحلُّ بالفضاءِ القديم
        $ns0 = $a['ns'];
        $moved = $movedPaths[$path] ?? null;
        $nsNew = $moved ? $nsOf($moved[1]) : $ns0;
        if ($moved) $newNs = $nsNew;
        foreach ($a['refs'] as $lower => $orig) {
            if (isset($a['imports'][$lower])) continue;
            $oldFq = strtolower(($ns0 !== '' ? $ns0 . '\\' : '') . $orig);
            if (! isset($existing[$oldFq])) continue;               // ليس صنفاً من أصنافنا
            $target = $mapLower[$oldFq] ?? $existing[$oldFq];
            if (strtolower($nsOf($target)) === strtolower($nsNew)) continue; // يُحَلّ في الفضاءِ الجديد
            if (! $moved && ! isset($mapLower[$oldFq])) continue;  // لا شيء تغيّر لهذا المرجع
            $adds[$target] = true;
        }
    }

    if ($out !== $src || $adds || $newNs !== null) {
        $plans[$path] = ['src' => $out, 'adds' => array_keys($adds), 'newNs' => $newNs];
    }
}

// ── التنفيذ ──
$report = [];
foreach ($plans as $path => $plan) {
    $src = $plan['src'];
    if ($plan['newNs'] !== null) {
        $src = preg_replace('/^namespace\s+[^;]+;/m', 'namespace ' . $plan['newNs'] . ';', $src, 1);
    }
    if ($plan['adds']) {
        sort($plan['adds']);
        $lines = implode('', array_map(fn ($fq) => "use $fq;\n", $plan['adds']));
        $a = analyse($src);
        if ($a['lastUseEnd'] !== null) {
            $pos = $a['lastUseEnd'];
            $src = substr($src, 0, $pos) . "\n" . rtrim($lines, "\n") . substr($src, $pos);
        } elseif ($a['nsEnd'] !== null) {
            $pos = $a['nsEnd'];
            $src = substr($src, 0, $pos) . "\n\n" . rtrim($lines, "\n") . substr($src, $pos);
        } else {
            fwrite(STDERR, "✗ لا موضعَ لإدراج use في $path\n");
            exit(1);
        }
    }
    $rel = substr($path, strlen($root) + 1);
    $report[] = $rel . ($plan['adds'] ? '  +use ' . implode(', ', array_map($shortOf, $plan['adds'])) : '');
    if (! $dry) file_put_contents($path, $src);
}

if (! $dry) {
    foreach ($map as $old => $new) {
        $from = $pathOf($old);
        $to = $pathOf($new);
        if (! is_dir(dirname($to))) mkdir(dirname($to), 0755, true);
        $cmd = 'git -C ' . escapeshellarg($root) . ' mv ' . escapeshellarg($from) . ' ' . escapeshellarg($to);
        exec($cmd, $o, $code);
        if ($code !== 0) {
            fwrite(STDERR, "✗ فشل: $cmd\n");
            exit(1);
        }
    }
}

echo implode("\n", $report), "\n";
echo ($dry ? '[dry] ' : '') . count($map) . ' صنفاً نُقل · ' . count($plans) . " ملفّاً مُسّ\n";
