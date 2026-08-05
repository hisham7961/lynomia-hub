<?php

namespace App\Support;

/**
 * رمز QR كـ SVG — **صرفُ PHP بلا مكتبةٍ خارجية ولا امتدادٍ ولا CDN**.
 *
 * تاريخُ العطل: كان يُبنى عبر BaconQrCode — مكتبةٌ تُثبَّت بـcomposer و`vendor/`
 * مستبعدٌ من المستودع؛ فعلى استضافةٍ تُرفَع بنسخ الملفات (بلا composer install، أو
 * بـvendor قديمٍ سابقٍ لإضافة المكتبة) تغيب المكتبة فيعيد الرمزُ null ويختفي من
 * الوثيقة («qr مش ظاهر»). الحلّ: مُرمِّزٌ صرفُ PHP (App\Support\QrEncoder) يُشحَن
 * مع الكود دائماً — فالرمزُ يظهر على أي استضافة PHP بلا شرط.
 *
 * أيُّ فشلٍ يعيد null والواجهاتُ تكتفي بالنص — لا كسر أبداً.
 */
class Qr
{
    public static function svg(string $text, int $size = 160): ?string
    {
        try {
            $matrix = QrEncoder::matrix($text);   // مصفوفةٌ ثنائية 0/1 (مظلم=1)
            if (! $matrix) return null;
            $n = count($matrix);

            $quiet = 4;                         // المنطقة الهادئة إلزامٌ للمسح — بلا هامشٍ لا يُقرأ
            $span = $n + $quiet * 2;

            // مسارٌ واحدٌ لكل الوحدات الداكنة، بضغطٍ أفقيٍّ (run-length) — ملفٌّ صغير
            $path = '';
            for ($y = 0; $y < $n; $y++) {
                $row = $matrix[$y];
                $x = 0;
                while ($x < $n) {
                    if ($row[$x] !== 1) { $x++; continue; }
                    $run = 1;
                    while ($x + $run < $n && $row[$x + $run] === 1) $run++;
                    $px = $x + $quiet;
                    $py = $y + $quiet;
                    $path .= "M{$px} {$py}h{$run}v1h-{$run}z";
                    $x += $run;
                }
            }

            // خلفيةٌ بيضاء تحت المنطقة الهادئة كلها، ثم الوحدات الداكنة مسارًا واحدًا
            return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size
                . '" viewBox="0 0 ' . $span . ' ' . $span . '" shape-rendering="crispEdges" role="img">'
                . '<rect width="' . $span . '" height="' . $span . '" fill="#fff"/>'
                . '<path fill="#000" d="' . $path . '"/></svg>';
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
