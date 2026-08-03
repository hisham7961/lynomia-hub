<?php

namespace App\Support;

/**
 * رمز QR كـ SVG — pure PHP عبر المكتبة المضمومة (لا CDN ولا طلب خارجي).
 * غيابُ المكتبة أو أي فشلٍ يعيد null والواجهات تكتفي بالنص — لا كسر أبداً.
 */
class Qr
{
    public static function svg(string $text, int $size = 160): ?string
    {
        if (! class_exists(\BaconQrCode\Writer::class)) return null;

        try {
            $renderer = new \BaconQrCode\Renderer\ImageRenderer(
                new \BaconQrCode\Renderer\RendererStyle\RendererStyle($size),
                new \BaconQrCode\Renderer\Image\SvgImageBackEnd
            );
            $svg = (new \BaconQrCode\Writer($renderer))->writeString($text);

            // إسقاط ترويسة XML للتضمين المباشر داخل الصفحة
            return preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg) ?: null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
