<?php

namespace App\Support\Security;

/**
 * **سياسةُ السكربتات في CSP** (بند الدَّين #12 · FE-03) — مصدرٌ واحدٌ للوضع والـnonce والسياسة.
 *
 * كانت CSP بلا `script-src` أصلاً لأنّ حصرَها يكسر الواجهة (نصوصٌ مضمَّنة ومعالجاتُ
 * أحداثٍ في السمات). مرّت بمرحلة **Report-Only** جرداً لما بقي، ثمّ نُظِّفت الواجهةُ كلُّها
 * فصار الافتراضيُّ **`enforce`** (v2.617):
 *
 *  · كلُّ ملفّات السكربت من أصلنا (`js/app.js` · `js/actions.js` · `js/htmx.min.js` ·
 *    Leaflet المضمَّن محلياً · مكتبةُ الرسم) ⇐ `'self'`، ولا CDN واحد.
 *  · كلُّ `<script>` مضمَّنٍ في القوالب يحمل `@cspNonce` (nonce هذا الطلب) ⇐ `'nonce-…'`.
 *  · **لا معالجاتِ أحداثٍ في السمات ولا روابطَ `javascript:` ولا `hx-on`** ⇐
 *    `script-src-attr 'none'`. الأفعالُ مُعلَنةٌ بسمات data-* ومستمِعاتُها المفوَّضة في
 *    `public/js/actions.js`. وحارسةُ `CspInlineHandlersGuardTest` تمسح القوالبَ كلَّها
 *    فتُسقط الحزمةَ عند أوّل عودةٍ لأيٍّ منها — فلا يرجع الدَّينُ بصمت.
 *  · `'strict-dynamic'` **غيرُ مستعمَلة عمداً**: تُسقط `'self'` في المتصفّحات الحديثة
 *    فتستلزم nonce على كلِّ `<script src>` أيضاً — ولا مكسبَ لنا منها (لا مُحمِّلَ سكربتاتٍ ديناميّ).
 *
 * **مفتاحُ المالك باقٍ** (`security.csp_script` في الإعدادات): `enforce` (الافتراضي) ·
 * `report` يعيدها Report-Only فيُبلغ ولا يحجب (لتشخيص عطلٍ في الإنتاج دون كسر) · `off`
 * يُسقطها. والتقاريرُ في الوضعين الأوّلين تصل مركزَ الأخطاء عبر `/csp-report`.
 *
 * **استثناءٌ مُسمّى واحد:** تطبيقُ QuoteFlow الجانبيّ ملفُّ HTML مُضمَّنٌ كما هو (للمالك وحده
 * خلف كلمة سرٍّ ثانية) يبني أزرارَه بمعالجاتٍ في السمات — يطلب `allowLegacyInline()` فتُخفَّف
 * السياسةُ **لتلك الاستجابة وحدها** إلى `'self' 'unsafe-inline'` مع عنوان مكتبة PDF التي يحمّلها بعينه
 * (ويبقى كلُّ أصلٍ أجنبيٍّ آخر محجوباً).
 */
final class ContentSecurity
{
    public const MODES = ['off', 'report', 'enforce'];

    /** سقفُ التقارير المخزَّنة في اليوم كلّه — نقطةٌ عامّةٌ لا تملأ الجدولَ بلا حدّ */
    public const REPORT_DAILY_CAP = 500;

    /** وسقفٌ يوميٌّ لكلِّ عنوان — مُرسِلٌ مجهولٌ واحدٌ لا يستنفد حصّةَ اليوم */
    public const REPORT_IP_DAILY_CAP = 25;

    /** أقصى جسمٍ مقبول لتقريرٍ واحد (بايت) — التقريرُ الحقيقيّ بضعُ مئات */
    public const REPORT_MAX_BYTES = 16384;

    /**
     * الوضعُ الساري: `enforce` (الافتراضي منذ v2.617) · `report` · `off`. قيمةٌ مجهولة تسقط
     * إلى `report` — فخطأُ كتابةٍ لا يُطفئ الحمايةَ ولا يكسر الواجهة. وتعذُّرُ القراءة
     * (قاعدةٌ ساقطة) يسقط إليه كذلك كي لا يصير ترويسةُ أمانٍ سببَ ٥٠٠ على `healthz`.
     */
    public static function mode(): string
    {
        try {
            $m = strtolower(trim((string) setting('security.csp_script', 'enforce')));
        } catch (\Throwable $e) {
            return 'report';
        }

        return in_array($m, self::MODES, true) ? $m : 'report';
    }

    /**
     * nonce هذا الطلب — يُولَّد مرّةً ويُحفظ على الطلب فيطابق ما تطبعه القوالب ما
     * تكتبه الترويسة. ١٨ بايتاً عشوائياً (١٤٤ بت) بترميز base64.
     */
    public static function nonce(): string
    {
        $r = request();
        $n = $r->attributes->get('csp_nonce');
        if (! is_string($n) || $n === '') {
            $n = base64_encode(random_bytes(18));
            $r->attributes->set('csp_nonce', $n);
        }

        return $n;
    }

    /**
     * استجابةٌ بعينها تطلب السياسةَ المخفَّفة (QuoteFlow وحده اليوم) — تُعلَّم على الطلب
     * فتقرؤها الوسيطةُ حين تكتب الترويسة. لا مفتاحَ عامّاً ولا إعداد: استدعاءٌ صريحٌ في متحكّمه.
     */
    public static function allowLegacyInline(array $scriptUrls = []): void
    {
        // مصادرُ خارجيّةٌ بعينها (عنوانٌ كاملٌ https لا نطاقٌ مفتوح) — ما يحمّله الملفُّ المضمَّن فعلاً
        $urls = array_values(array_filter($scriptUrls,
            fn ($u) => is_string($u) && preg_match('#^https://[A-Za-z0-9.\-]+/[A-Za-z0-9._~/\-]+$#', $u)));
        request()->attributes->set('csp_legacy_inline', $urls);
    }

    /** نصُّ السياسة (بلا وجهة التقرير) — واحدٌ في الوضعين فما يُقاس هو ما يُفرض */
    public static function scriptPolicy(): string
    {
        $legacy = request()->attributes->get('csp_legacy_inline');
        if (is_array($legacy)) {
            return trim("script-src 'self' 'unsafe-inline' " . implode(' ', $legacy)) . "; script-src-attr 'unsafe-inline'";
        }

        return "script-src 'self' 'nonce-" . self::nonce() . "'; script-src-attr 'none'";
    }

    /** السياسةُ مع وجهة التقرير — `report-uri` نسبيّةٌ لأصلنا */
    public static function scriptPolicyWithReport(): string
    {
        $uri = '/csp-report';
        try {
            $uri = route('csp.report', [], false);
        } catch (\Throwable $e) {
            // المسارُ غير مسجَّل (سياقٌ جزئيّ) — المسارُ الحرفيّ نفسُه
        }

        return self::scriptPolicy() . '; report-uri ' . $uri;
    }
}
