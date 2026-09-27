<?php

namespace App\Support\Security;

/**
 * **سياسةُ السكربتات في CSP** (بند الدَّين #12 · FE-03) — مصدرٌ واحدٌ للوضع والـnonce والسياسة.
 *
 * كانت CSP بلا `script-src` أصلاً لأنّ حصرَها يكسر الواجهة (نصوصٌ مضمَّنة ومعالجاتُ
 * أحداثٍ في السمات). والطريقُ المعتمد: **Report-Only أوّلاً** — تُعلَن السياسةُ
 * ولا تُفرض، فيُبلغ المتصفّحُ عن كلِّ ما كانت ستحجبه، ويُجمَع ذلك في مركز الأخطاء
 * جرداً لما بقي قبل الفرض. ثمّ `enforce` حين يصمت السجلّ مدّةً كافية.
 *
 * السياسةُ **تطابق كيف يحمّل النظامُ سكربتاته فعلاً**:
 *  · كلُّ ملفّات السكربت من أصلنا (`js/app.js` · `js/htmx.min.js` · Leaflet المضمَّن محلياً
 *    · مكتبةُ الرسم) ⇐ `'self'`، ولا CDN واحد.
 *  · النصوصُ المضمَّنة في القوالب العامّة تحمل `nonce` هذا الطلب ⇐ `'nonce-…'`؛ وما لم
 *    يُوسَم بعدُ يظهر في التقارير فيُوسَم تدريجياً.
 *  · معالجاتُ الأحداث في السمات (onclick/onchange/onsubmit — نحو ٩٠ موضعاً) مسموحةٌ في
 *    هذه المرحلة بـ`script-src-attr 'unsafe-inline'` صراحةً — نقلُها إلى مستمِعاتٍ في
 *    `app.js` خطوةٌ لاحقة، وحذفُ هذا الاستثناء بعدها.
 *
 * ما تكسبه السياسةُ مفروضةً اليوم: `<script src>` من أصلٍ أجنبيّ و`<script>` محقونٌ بلا
 * nonce يُحجبان — وهما صورتا الحقن الأشيع.
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
     * الوضعُ الساري: `off` · `report` (الافتراضي) · `enforce`. قيمةٌ مجهولة تسقط إلى
     * `report` — فلا يُفرض شيءٌ بخطأ كتابة. وتعذُّرُ القراءة (قاعدةٌ ساقطة) يسقط إليه كذلك
     * كي لا يصير ترويسةُ أمانٍ سببَ ٥٠٠ على `healthz`.
     */
    public static function mode(): string
    {
        try {
            $m = strtolower(trim((string) setting('security.csp_script', 'report')));
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

    /** نصُّ السياسة (بلا وجهة التقرير) — واحدٌ في الوضعين فما يُقاس هو ما يُفرض */
    public static function scriptPolicy(): string
    {
        return "script-src 'self' 'nonce-" . self::nonce() . "'; script-src-attr 'unsafe-inline'";
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
