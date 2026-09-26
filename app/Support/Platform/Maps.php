<?php

namespace App\Support\Platform;

/**
 * **مصدرُ بلاطات الخريطة** (بند الدَّين #13 · FE-02) — إعدادٌ واحدٌ تقرؤه المواضعُ الثلاثة:
 * مسارُ الجلسة الميدانية (Leaflet)، وموقعُ المنشأة، وموقعُ تنفيذ الزيارة.
 *
 * كانت الثلاثة تشير إلى OpenStreetMap مباشرةً — فكلُّ فتحٍ للخريطة يُرسل إحداثياتِ
 * العمل (مسارَ المندوب، موقعَ العميل) وعنوانَ المتصفّح إلى طرفٍ ثالث. والمالكُ يختار:
 *
 *  · `osm`  (الافتراضي — السلوكُ القائم بحرفه): بلاطاتُ tile.openstreetmap.org ورابطُ
 *           «افتح على الخريطة» إلى openstreetmap.org.
 *  · `self` خادمُ بلاطاتٍ خاصّ أو وكيلٌ داخليّ بقالب `maps.tiles_url`
 *           (`https://tiles.example.com/{z}/{x}/{y}.png` أو مسارٌ من أصلنا `/tiles/{z}/{x}/{y}.png`):
 *           الخرائطُ ترسم منه، والمنشأةُ/الزيارةُ تعرضان خريطةً مصغّرةً مضمَّنة بدل رابطٍ خارجيّ.
 *           قالبٌ فارغ أو فاسد ⇐ تُعامَل كـ`off` برسالةٍ تسمّي السبب — لا رجوعَ صامتاً إلى OSM.
 *  · `off`  لا خرائط: الإحداثياتُ تبقى نصّاً، ورسالةٌ صادقة تقول إنّ الخرائط معطّلة.
 */
final class Maps
{
    public const MODES = ['osm', 'self', 'off'];

    public const OSM_TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

    /**
     * قالبُ الخادم الخاصّ: https بمضيفٍ صريح أو مسارٌ من أصلنا (لا `//مضيف` بلا بروتوكول)،
     * ويحمل `{z}` و`{x}` و`{y}`، وبلا مسافاتٍ ولا علامات اقتباسٍ ولا `<>`.
     * **مصدرُ قاعدة الرفض في الكتالوج** — فلا تفترق قاعدةُ الشاشة عن قاعدة القراءة.
     */
    public const TILES_URL_RE = '~^(?=\S*\{z\})(?=\S*\{x\})(?=\S*\{y\})(?:https://[^/\s"\'<>]+)?/(?!/)[^\s"\'<>]*$~';

    public const ATTRIBUTION = '© OpenStreetMap';

    /** الوضعُ كما ضُبط (مجهولٌ ⇐ `osm`: السلوكُ القائم لا يتغيّر بخطأ كتابة) */
    public static function configured(): string
    {
        $m = strtolower(trim((string) setting('maps.tiles', 'osm')));

        return in_array($m, self::MODES, true) ? $m : 'osm';
    }

    /** الوضعُ الساري: `self` بلا قالبٍ صالح يسري `off` */
    public static function mode(): string
    {
        $m = self::configured();

        return $m === 'self' && self::selfTilesUrl() === null ? 'off' : $m;
    }

    public static function enabled(): bool
    {
        return self::mode() !== 'off';
    }

    /** قالبُ البلاطات الساري لـLeaflet — null حين تكون الخرائط معطّلة */
    public static function tilesUrl(): ?string
    {
        return match (self::mode()) {
            'osm'  => self::OSM_TILES,
            'self' => self::selfTilesUrl(),
            default => null,
        };
    }

    /** رابطُ «افتح على الخريطة» الخارجيّ — في `osm` وحده (الخاصُّ يعرض خريطةً مضمَّنة) */
    public static function pointUrl(float $lat, float $lng): ?string
    {
        if (self::mode() !== 'osm') return null;

        return 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lng . '#map=17/' . $lat . '/' . $lng;
    }

    /** لماذا لا خريطة — نصٌّ صادقٌ يسمّي السبب (فارغٌ حين تعمل) */
    public static function offReason(): string
    {
        if (self::mode() !== 'off') return '';

        return self::configured() === 'self'
            ? 'الخرائط معطّلة: اختير خادمُ بلاطاتٍ خاصّ ولم يُضبط قالبُ رابطه (maps.tiles_url) ضبطاً صالحاً.'
            : 'الخرائط معطّلة بقرار الإدارة — الإحداثياتُ معروضةٌ نصّاً، ولا تُرسَل إلى أيّ خدمة خرائط.';
    }

    private static function selfTilesUrl(): ?string
    {
        $u = trim((string) setting('maps.tiles_url'));

        return $u !== '' && preg_match(self::TILES_URL_RE, $u) ? $u : null;
    }
}
