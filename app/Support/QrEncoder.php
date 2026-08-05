<?php

namespace App\Support;

/**
 * مُرمِّز QR **صرفُ PHP بلا أيّ مكتبةٍ خارجية** — نموذج 2، وضعُ البايت، تصحيحُ
 * الأخطاء مستوى M، النسخ 1..10 (تكفي روابط التحقّق وأطول). سببُ وجوده: مكتبةُ
 * bacon-qr-code تُثبَّت عبر composer و`vendor/` مستبعدٌ من المستودع — فعلى استضافةٍ
 * تُرفَع بنسخ الملفات (بلا composer install) تغيب المكتبة فيختفي الرمز. هذا المُرمِّز
 * يُشحَن مع الكود دائماً فالرمزُ يظهر على أي استضافة.
 *
 * المخرجُ مصفوفةٌ ثنائية 0/1 (مظلم=1). مُتحقَّقٌ منه بفكّ jsQR (يُمسَح فعلاً).
 */
class QrEncoder
{
    /** سعةُ بيانات كل نسخة (M) وبنيةُ كتلها: [ecPerBlock, [[count, dataPerBlock], ...]] */
    private const ECC_M = [
        1 => [10, [[1, 16]]],
        2 => [16, [[1, 28]]],
        3 => [26, [[1, 44]]],
        4 => [18, [[2, 32]]],
        5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]],
        7 => [18, [[4, 31]]],
        8 => [22, [[2, 38], [2, 39]]],
        9 => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];

    /** مراكزُ أنماط المحاذاة لكل نسخة */
    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    private static array $exp = [];
    private static array $log = [];

    /** يبني جداول GF(256) مرةً واحدة (متعددة الحدود البدائية 0x11d) */
    private static function initGf(): void
    {
        if (self::$exp) return;
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) $x ^= 0x11d;
        }
        for ($i = 255; $i < 512; $i++) self::$exp[$i] = self::$exp[$i - 255];
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) return 0;

        return self::$exp[self::$log[$a] + self::$log[$b]];
    }

    /** متعددةُ حدود المولّد لدرجةٍ degree — **الأعلى درجةً أولاً** (gen[0]=1) كما تفترض القسمة */
    private static function rsGenerator(int $degree): array
    {
        $poly = [1];   // يُبنى أدنى-درجةً-أولاً ثم يُقلب
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $j => $coef) {
                $next[$j] ^= self::gfMul($coef, self::$exp[$i]);
                $next[$j + 1] ^= $coef;
            }
            $poly = $next;
        }

        return array_reverse($poly);
    }

    /** بايتاتُ تصحيح الأخطاء لكتلة بيانات */
    private static function rsEncode(array $data, int $ec): array
    {
        $gen = self::rsGenerator($ec);
        $res = array_merge($data, array_fill(0, $ec, 0));
        for ($i = 0; $i < count($data); $i++) {
            $factor = $res[$i];
            if ($factor === 0) continue;
            $lf = self::$log[$factor];
            foreach ($gen as $j => $g) {
                $res[$i + $j] ^= self::$exp[self::$log[$g] + $lf];
            }
        }

        return array_slice($res, count($data));
    }

    /** يختار أصغر نسخةٍ تسع البايتات */
    private static function chooseVersion(int $bytes): ?int
    {
        for ($v = 1; $v <= 10; $v++) {
            $totalData = 0;
            foreach (self::ECC_M[$v][1] as [$cnt, $per]) $totalData += $cnt * $per;
            $ccBits = $v <= 9 ? 8 : 16;
            $need = (int) ceil((4 + $ccBits + $bytes * 8) / 8);   // مؤشّر + عدّاد + البيانات
            if ($need <= $totalData) return $v;
        }

        return null;   // أطولُ من طاقة النسخة 10
    }

    /** يبني بِتّات البيانات (قبل ECC) مبعثرةً على الكتل مع تصحيحها ثم يُشابكها */
    private static function buildCodewords(string $text, int $version): array
    {
        [$ecPer, $blocks] = self::ECC_M[$version];
        $totalData = 0;
        foreach ($blocks as [$cnt, $per]) $totalData += $cnt * $per;

        // مجرى البِتّات: وضعُ البايت (0100) + عدّاد + البيانات + نهاية + حشو
        $bits = '0100';
        $len = strlen($text);
        $bits .= str_pad(decbin($len), $version <= 9 ? 8 : 16, '0', STR_PAD_LEFT);
        for ($i = 0; $i < $len; $i++) $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        // نهايةٌ حتى ٤ أصفار، ثم محاذاةُ بايت، ثم حشوٌ متناوب
        $bits .= substr('0000', 0, min(4, $totalData * 8 - strlen($bits)));
        while (strlen($bits) % 8 !== 0) $bits .= '0';
        $pads = ['11101100', '00010001']; $p = 0;
        while (strlen($bits) < $totalData * 8) { $bits .= $pads[$p]; $p ^= 1; }

        // بايتاتُ البيانات ثم تقسيمُها على الكتل وتصحيحُ كلٍّ
        $allData = [];
        for ($i = 0; $i < strlen($bits); $i += 8) $allData[] = bindec(substr($bits, $i, 8));

        $dataBlocks = []; $ecBlocks = []; $pos = 0;
        foreach ($blocks as [$cnt, $per]) {
            for ($b = 0; $b < $cnt; $b++) {
                $blk = array_slice($allData, $pos, $per); $pos += $per;
                $dataBlocks[] = $blk;
                $ecBlocks[] = self::rsEncode($blk, $ecPer);
            }
        }

        // مشابكةُ البيانات ثم ECC (عمود عمود عبر الكتل)
        $out = [];
        $maxData = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($dataBlocks as $blk) if (isset($blk[$i])) $out[] = $blk[$i];
        }
        for ($i = 0; $i < $ecPer; $i++) {
            foreach ($ecBlocks as $blk) $out[] = $blk[$i];
        }

        return $out;
    }

    /**
     * يُرجِع مصفوفة الوحدات (0/1) للنصّ، أو null إن تعذّر (أطول من النسخة 10).
     * @return array<int,array<int,int>>|null
     */
    public static function matrix(string $text, ?int $forceMask = null): ?array
    {
        self::initGf();
        $version = self::chooseVersion(strlen($text));
        if ($version === null) return null;

        $size = 17 + $version * 4;
        $m = [];     // القيمة، null=غير مضبوط
        $fn = [];    // وظيفيّ؟ (لا يُقنَّع)
        for ($r = 0; $r < $size; $r++) { $m[$r] = array_fill(0, $size, null); $fn[$r] = array_fill(0, $size, false); }

        $set = function (int $r, int $c, int $v, bool $isFn = true) use (&$m, &$fn) { $m[$r][$c] = $v; if ($isFn) $fn[$r][$c] = true; };

        // أنماطُ الكاشف الثلاثة + فواصلها
        $finder = function (int $r0, int $c0) use ($set, $size) {
            for ($r = -1; $r <= 7; $r++) for ($c = -1; $c <= 7; $c++) {
                $rr = $r0 + $r; $cc = $c0 + $c;
                if ($rr < 0 || $cc < 0 || $rr >= $size || $cc >= $size) continue;
                $dark = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
                    || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6))
                    || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                $set($rr, $cc, $dark ? 1 : 0);
            }
        };
        $finder(0, 0); $finder(0, $size - 7); $finder($size - 7, 0);

        // أنماطُ التوقيت
        for ($i = 8; $i < $size - 8; $i++) {
            if ($m[6][$i] === null) $set(6, $i, $i % 2 === 0 ? 1 : 0);
            if ($m[$i][6] === null) $set($i, 6, $i % 2 === 0 ? 1 : 0);
        }

        // أنماطُ المحاذاة
        $centers = self::ALIGN[$version];
        foreach ($centers as $ar) foreach ($centers as $ac) {
            // تُتخطّى إن تداخلت مع أنماط الكاشف
            if (($ar <= 8 && $ac <= 8) || ($ar <= 8 && $ac >= $size - 9) || ($ar >= $size - 9 && $ac <= 8)) continue;
            for ($r = -2; $r <= 2; $r++) for ($c = -2; $c <= 2; $c++) {
                $dark = max(abs($r), abs($c)) !== 1;
                $set($ar + $r, $ac + $c, $dark ? 1 : 0);
            }
        }

        // حجزُ مناطق معلومات الصيغة (تُملأ لاحقاً في placeFormat)
        for ($i = 0; $i < 9; $i++) { if ($m[8][$i] === null) $set(8, $i, 0); if ($m[$i][8] === null) $set($i, 8, 0); }
        for ($i = 0; $i < 8; $i++) { $set(8, $size - 1 - $i, 0); $set($size - 1 - $i, 8, 0); }

        // الوحدة المظلمة الإلزامية (4v+9, 8) — بعد الحجز كي لا يدهسها صفرُه
        $set($size - 8, 8, 1);

        // معلوماتُ النسخة (7+)
        if ($version >= 7) {
            $vbits = self::versionBits($version);
            for ($i = 0; $i < 18; $i++) {
                $bit = ($vbits >> $i) & 1;
                $r = intdiv($i, 3); $c = $i % 3;
                $set($size - 11 + $c, $r, $bit); $set($r, $size - 11 + $c, $bit);
            }
        }

        // وضعُ بايتات البيانات+ECC بالزجزاج
        $data = self::buildCodewords($text, $version);
        $bitstr = '';
        foreach ($data as $byte) $bitstr .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        $idx = 0; $up = true;
        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) $col--;   // نتجاوز عمود التوقيت
            for ($i = 0; $i < $size; $i++) {
                $row = $up ? $size - 1 - $i : $i;
                for ($k = 0; $k < 2; $k++) {
                    $c = $col - $k;
                    if ($m[$row][$c] !== null) continue;
                    $bit = $idx < strlen($bitstr) ? (int) $bitstr[$idx] : 0;
                    $idx++;
                    $m[$row][$c] = $bit;   // بياناتٌ (ليست وظيفية) — تُقنَّع
                }
            }
            $up = ! $up;
        }

        // اختيارُ أفضل قناع بأقلّ عقوبة ($forceMask للاختبار المرجعيّ فقط)
        $best = null; $bestPen = PHP_INT_MAX;
        foreach ($forceMask !== null ? [$forceMask] : range(0, 7) as $mask) {
            $cand = self::applyMask($m, $fn, $size, $mask);
            self::placeFormat($cand, $size, $mask);
            $pen = self::penalty($cand, $size);
            if ($pen < $bestPen) { $bestPen = $pen; $best = $cand; }
        }

        return $best;
    }

    /** ينسخ المصفوفة ويقنّع وحدات البيانات وفق النمط */
    private static function applyMask(array $m, array $fn, int $size, int $mask): array
    {
        for ($r = 0; $r < $size; $r++) for ($c = 0; $c < $size; $c++) {
            if ($fn[$r][$c]) continue;
            $inv = match ($mask) {
                0 => ($r + $c) % 2 === 0,
                1 => $r % 2 === 0,
                2 => $c % 3 === 0,
                3 => ($r + $c) % 3 === 0,
                4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
                5 => (($r * $c) % 2) + (($r * $c) % 3) === 0,
                6 => ((($r * $c) % 2) + (($r * $c) % 3)) % 2 === 0,
                7 => ((($r + $c) % 2) + (($r * $c) % 3)) % 2 === 0,
            };
            if ($inv) $m[$r][$c] ^= 1;
        }

        return $m;
    }

    /**
     * يضع بِتّات معلومات الصيغة (مستوى M + القناع) في موضعيها — **الأثقل أولاً**:
     * البِتّ 14 عند (8,0) نزولاً إلى البِتّ 0 عند (0,8)؛ وعكسُ الترتيب (الأخفّ أولاً)
     * كان عينَ العطل: قارئُ الكاميرا يفكّ صيغةً خاطئة فيرفض الرمز كلّه.
     */
    private static function placeFormat(array &$m, int $size, int $mask): void
    {
        $fmt = self::formatBits($mask);
        $bit = fn (int $i) => ($fmt >> (14 - $i)) & 1;   // i=0 ← الأثقل (بِتّ 14)

        // النسخة الأولى حول الكاشف العلويّ الأيسر
        for ($i = 0; $i <= 5; $i++) $m[8][$i] = $bit($i);          // (8,0..5) ← البِتّات 14..9
        $m[8][7] = $bit(6);
        $m[8][8] = $bit(7);
        $m[7][8] = $bit(8);
        for ($i = 9; $i <= 14; $i++) $m[14 - $i][8] = $bit($i);    // (5..0,8) ← البِتّات 5..0

        // النسخة الثانية: 14..8 نازلةً في العمود 8 من الأسفل، و7..0 في الصفّ 8 يميناً
        for ($i = 0; $i <= 6; $i++) $m[$size - 1 - $i][8] = $bit($i);
        for ($i = 7; $i <= 14; $i++) $m[8][$size - 15 + $i] = $bit($i);
    }

    /** BCH(15,5) لمعلومات الصيغة (مستوى M=00) ثم XOR بقناع المواصفة 0x5412 */
    private static function formatBits(int $mask): int
    {
        $data = (0b00 << 3) | $mask;         // ٥ بِتّات: مستوى M=00 + القناع
        $rem = $data << 10;                  // قسمةٌ على المولّد 0x537
        for ($i = 14; $i >= 10; $i--) if (($rem >> $i) & 1) $rem ^= 0x537 << ($i - 10);

        return (($data << 10) | ($rem & 0x3ff)) ^ 0x5412;
    }

    /** BCH(18,6) لمعلومات النسخة (7+) — المولّد 0x1f25 */
    private static function versionBits(int $version): int
    {
        $rem = $version << 12;
        for ($i = 17; $i >= 12; $i--) if (($rem >> $i) & 1) $rem ^= 0x1f25 << ($i - 12);

        return ($version << 12) | ($rem & 0xfff);
    }

    /** عقوبةُ القناع وفق قواعد المواصفة الأربع (أقلُّها أفضل) */
    private static function penalty(array $m, int $size): int
    {
        $p = 0;
        // (1) خمسةٌ متجاورةٌ فأكثر بنفس اللون — أفقياً وعمودياً
        for ($r = 0; $r < $size; $r++) {
            $run = 1;
            for ($c = 1; $c < $size; $c++) {
                if ($m[$r][$c] === $m[$r][$c - 1]) { $run++; if ($run === 5) $p += 3; elseif ($run > 5) $p++; }
                else $run = 1;
            }
        }
        for ($c = 0; $c < $size; $c++) {
            $run = 1;
            for ($r = 1; $r < $size; $r++) {
                if ($m[$r][$c] === $m[$r - 1][$c]) { $run++; if ($run === 5) $p += 3; elseif ($run > 5) $p++; }
                else $run = 1;
            }
        }
        // (2) كتلٌ 2×2 بنفس اللون
        for ($r = 0; $r < $size - 1; $r++) for ($c = 0; $c < $size - 1; $c++) {
            $v = $m[$r][$c];
            if ($v === $m[$r][$c + 1] && $v === $m[$r + 1][$c] && $v === $m[$r + 1][$c + 1]) $p += 3;
        }
        // (3) نمطُ الكاشف 1:1:3:1:1 مع فراغٍ
        $pat1 = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        $pat2 = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
        for ($r = 0; $r < $size; $r++) for ($c = 0; $c <= $size - 11; $c++) {
            $ok1 = true; $ok2 = true;
            for ($k = 0; $k < 11; $k++) { if ($m[$r][$c + $k] !== $pat1[$k]) $ok1 = false; if ($m[$r][$c + $k] !== $pat2[$k]) $ok2 = false; }
            if ($ok1 || $ok2) $p += 40;
        }
        for ($c = 0; $c < $size; $c++) for ($r = 0; $r <= $size - 11; $r++) {
            $ok1 = true; $ok2 = true;
            for ($k = 0; $k < 11; $k++) { if ($m[$r + $k][$c] !== $pat1[$k]) $ok1 = false; if ($m[$r + $k][$c] !== $pat2[$k]) $ok2 = false; }
            if ($ok1 || $ok2) $p += 40;
        }
        // (4) نسبةُ الوحدات المظلمة
        $dark = 0;
        for ($r = 0; $r < $size; $r++) $dark += array_sum($m[$r]);
        $ratio = $dark * 100 / ($size * $size);
        $p += (int) (abs($ratio - 50) / 5) * 10;

        return $p;
    }
}
