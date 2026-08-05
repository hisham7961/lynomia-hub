<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * رمز QR كـ SVG — pure PHP بلا CDN ولا طلب خارجي ولا امتداد xmlwriter.
 *
 * كان يُبنى عبر SvgImageBackEnd في BaconQrCode، وهي **تشترط امتداد xmlwriter**:
 * على استضافةٍ عادية بلا هذا الامتداد كان الرمز يفشل صامتاً فيختفي من الوثيقة
 * («qr مش ظاهر»). أما **مُرمِّز** BaconQrCode (Encoder) فهو PHP صرفٌ لا يمسّ XML —
 * فنأخذ مصفوفته المُثبَتة ونرسمها SVG بأنفسنا ببناء نصٍّ بسيط. فالرمزُ يظهر على أي
 * استضافة PHP فيها ملفات المكتبة (المثبَّتة عبر composer)، بلا اشتراط امتدادٍ إضافي.
 *
 * غيابُ المكتبة أو أي فشلٍ يعيد null والواجهات تكتفي بالنص — لا كسر أبداً.
 */
class Qr
{
    public static function svg(string $text, int $size = 160): ?string
    {
        if (! class_exists(Encoder::class)) return null;

        try {
            // ECC مستوى M (تصحيحٌ ~15%): يتحمّل خدشاً/طيّةً في الوثيقة المطبوعة
            $matrix = Encoder::encode($text, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();
            $n = $matrix->getWidth();
            if ($n < 1) return null;

            $quiet = 4;                         // المنطقة الهادئة إلزامٌ للمسح — بلا هامشٍ لا يُقرأ
            $span = $n + $quiet * 2;

            // مسارٌ واحدٌ لكل الوحدات الداكنة، بضغطٍ أفقيٍّ (run-length) — ملفٌّ صغير
            $path = '';
            for ($y = 0; $y < $n; $y++) {
                $x = 0;
                while ($x < $n) {
                    if ($matrix->get($x, $y) !== 1) { $x++; continue; }
                    $run = 1;
                    while ($x + $run < $n && $matrix->get($x + $run, $y) === 1) $run++;
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
