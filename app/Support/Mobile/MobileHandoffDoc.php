<?php

namespace App\Support\Mobile;

/**
 * **خريطةُ النقاط في وثيقة التسليم — مولَّدةٌ لا مكتوبة** (خطّةُ التطبيق · 2.5).
 *
 * كانت `docs/mobile-readiness/10-mobile-app-handoff.md` تقول «٧٥ مساراً» والمسجَّلُ ٩٥ —
 * عددٌ كُتب باليد فتقادم بصمت. الآن القسمُ بين العلامتين يُولَّد من المسارات الحيّة
 * (`MobileOpenApi::capabilities()` — المصدرُ نفسُه لبيان القدرات)، و`hub:mobile-handoff --check`
 * واختبارُ الانحراف يُسقطان أيَّ تباعد.
 */
final class MobileHandoffDoc
{
    public const PATH = 'docs/mobile-readiness/10-mobile-app-handoff.md';

    public const BEGIN = '<!-- routes:begin (مولَّد: php artisan hub:mobile-handoff --write — لا يُحرَّر باليد) -->';

    public const END = '<!-- routes:end -->';

    /** عددُ المسارات (طريقةٌ + مسار) تحت البادئة */
    public static function count(): int
    {
        $n = 0;
        foreach (MobileOpenApi::capabilities()['areas'] as $a) $n += (int) $a['count'];

        return $n;
    }

    /** القسمُ المولَّد: عنوانٌ بالعدد + جدولٌ بالمجال (عامّ ثم مُصادَق) */
    public static function render(): string
    {
        $areas = MobileOpenApi::capabilities()['areas'];
        $n = self::count();
        $ar = strtr((string) $n, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);

        $lines = ["## 4) خريطةُ النقاط الكاملة ({$ar} مساراً · كلُّها في `routes/api.php`)", '',
            'مولَّدةٌ من المسارات الحيّة — العامّةُ بلا رمز وصول (`public`)، والباقي خلف `mobile.session` + `mobile.portal` + `mobile.context`.', '',
            '| المجال | الطريقة والمسار (بعد `/' . MobileOpenApi::PREFIX . '/`) | الاسم | المصادقة |', '|---|---|---|---|'];
        foreach ($areas as $area => $a) {
            foreach ($a['endpoints'] as $e) {
                $path = ltrim(substr($e['path'], strlen('/' . MobileOpenApi::PREFIX)), '/');
                $lines[] = '| ' . $area . ' | `' . $e['method'] . ' ' . $path . '` | `' . $e['name'] . '` | '
                    . ($e['auth'] === 'public' ? 'عامّة' : 'مُصادَقة') . ' |';
            }
        }

        return self::BEGIN . "\n" . implode("\n", $lines) . "\n" . self::END;
    }

    /** الوثيقةُ بعد استبدال القسم المولَّد (أو null إن غابت العلامتان) */
    public static function sync(string $doc): ?string
    {
        $b = strpos($doc, self::BEGIN);
        $e = strpos($doc, self::END);
        if ($b === false || $e === false || $e < $b) return null;

        return substr($doc, 0, $b) . self::render() . substr($doc, $e + strlen(self::END));
    }
}
