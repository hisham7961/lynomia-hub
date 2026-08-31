<?php

namespace App\Support;

/**
 * باركود خطي **Code 128** كـSVG — صرفُ PHP بلا مكتبةٍ ولا امتدادٍ ولا CDN،
 * على فلسفة `QrEncoder` نفسها: يُشحن مع الكود فيظهر على أي استضافة.
 *
 * المجموعة B للنصوص (أكواد LYN-…) والمجموعة C للأرقام الزوجية الطول
 * (GTIN/EAN) — فالرقميّ بنصف العرض تقريباً. أي فشلٍ يعيد null والواجهات
 * تكتفي بالنص — لا كسر أبداً.
 */
class Barcode
{
    /**
     * جدول أنماط Code 128 القياسي: ستُّ خاناتٍ لكل رمز (عرض شريط، فراغ، …)
     * مجموعُها ١١ وحدةً دائماً — والاختبار يتحقق من هذا الثابت حرفياً.
     */
    public const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312',
        '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
        '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131',
        '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321',
        '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121',
        '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321',
        '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224',
        '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114',
        '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112',
        '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113',
        '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412',
        '211214', '211232',
    ];

    public const STOP = '2331112';
    public const START_B = 104;
    public const START_C = 105;

    /** قيمُ الرموز للنص المُدخل — المجموعة C للأرقام الزوجية، وB لما سواها */
    public static function codes(string $text): ?array
    {
        $text = trim($text);
        if ($text === '' || strlen($text) > 60) return null;

        if (ctype_digit($text) && strlen($text) % 2 === 0) {
            $vals = [self::START_C];
            foreach (str_split($text, 2) as $pair) $vals[] = (int) $pair;
        } else {
            $vals = [self::START_B];
            foreach (str_split($text) as $ch) {
                $o = ord($ch);
                if ($o < 32 || $o > 126) return null;       // المجموعة B: ASCII المطبوع فقط
                $vals[] = $o - 32;
            }
        }

        // خانةُ التحقق: البداية + مجموع (قيمة × موقعها) على 103
        $sum = $vals[0];
        foreach (array_slice($vals, 1) as $i => $v) $sum += $v * ($i + 1);
        $vals[] = $sum % 103;

        return $vals;
    }

    /** SVG بارتفاعٍ معطى — العرض بحسب المحتوى، وviewBox بالوحدات للقياس الحر */
    public static function svg(string $text, int $height = 44, bool $label = true): ?string
    {
        $vals = self::codes($text);
        if ($vals === null) return null;

        $quiet = 10;                                        // المنطقة الهادئة إلزامٌ للمسح
        $bars = '';
        $x = $quiet;
        $draw = function (string $pattern) use (&$bars, &$x) {
            foreach (str_split($pattern) as $i => $w) {
                if ($i % 2 === 0) {                          // الزوجي شريط، والفردي فراغ
                    $bars .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="40"/>';
                }
                $x += (int) $w;
            }
        };
        foreach ($vals as $v) $draw(self::PATTERNS[$v]);
        $draw(self::STOP);
        $x += $quiet;

        $textLine = $label
            ? '<text x="' . ($x / 2) . '" y="49" text-anchor="middle" font-family="monospace" font-size="8">'
              . htmlspecialchars($text, ENT_QUOTES) . '</text>'
            : '';
        $vh = $label ? 52 : 40;

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $x . ' ' . $vh . '"'
            . ' height="' . $height . '" role="img" aria-label="' . htmlspecialchars($text, ENT_QUOTES) . '"'
            . ' shape-rendering="crispEdges"><g fill="#000">' . $bars . $textLine . '</g></svg>';
    }
}
