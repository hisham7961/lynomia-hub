<?php

namespace App\Support\Security;

use Illuminate\Http\UploadedFile;

/**
 * **سياسةُ امتداداتِ الرفع — مصدرُ الحقيقةِ الواحد (FS-04 · TECH_DEBT #22).**
 *
 * كانت القائمةُ منسوخةً بيدٍ في موضعَين غيرِ متطابقَين: المرفقاتُ بقائمةٍ أشدّ
 * (`php3…php8`/`cgi`/`pl`/`sh`/`htaccess`)، وحقولُ ملفّاتِ الوحدات بقائمةٍ أقصر —
 * ومساراتٌ أخرى (تعليقاتٌ ورسائلُ خاصّة ويب/جوال، غرفةُ البيانات، صندوقُ الوثائق)
 * بلا حاجزٍ أصلاً. هنا قائمةٌ واحدةٌ **على الأشدّ** يستعملها كلُّ مسارِ رفعٍ حرّ
 * (المساراتُ ذاتُ قائمةِ السماح الضيّقة — الاستيرادُ وأرتيفاكتاتُ الوكيل والهويّةُ
 * البصريّة — أشدُّ منها أصلاً فتبقى كما هي).
 *
 * لماذا هذه الامتدادات: PHP وأخواتُها قنبلةٌ إن لمسها الخادم يوماً؛ وHTML/SVG/JS
 * تحمل سكربتاً يعمل بأصلِ التطبيق إن فُتحت. البوّاباتُ تخدم الغريبَ تنزيلاً قسرياً —
 * وهذا حزامُ الأمانِ الثاني لا الأوّل.
 */
final class UploadPolicy
{
    /** امتداداتٌ تُرفض في كلِّ مسارِ رفعٍ مهما كان الإعداد (أحرفٌ صغيرة) */
    public const BLOCKED = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'cgi', 'pl', 'sh', 'htaccess',
        'html', 'htm', 'xhtml', 'svg', 'svgz', 'js', 'mjs'];

    /** رسالةُ الرفض الموحَّدة لقواعد التحقّق */
    public const MESSAGE = 'هذا النوع من الملفات لا يُرفع — قد يحمل شيفرةً تنفيذية. حوّله إلى PDF أو صورة.';

    /** هل الامتدادُ (أو اسمُ الملف) محظور؟ — يقبل «php» أو «x.PHP» أو ملفاً مرفوعاً */
    public static function blocked(UploadedFile|string|null $file): bool
    {
        if ($file === null) return false;

        $ext = $file instanceof UploadedFile
            ? (string) $file->getClientOriginalExtension()
            : (str_contains($file, '.') ? (string) pathinfo($file, PATHINFO_EXTENSION) : $file);

        return in_array(mb_strtolower(trim($ext)), self::BLOCKED, true);
    }

    /**
     * قاعدةُ تحقّقٍ جاهزة (Closure) تُضاف إلى قواعد أيِّ حقلِ ملف:
     * `'att' => ['nullable', 'file', 'max:…', UploadPolicy::rule()]`.
     */
    public static function rule(): \Closure
    {
        return function (string $attr, $file, \Closure $fail): void {
            if ($file instanceof UploadedFile && self::blocked($file)) {
                $fail(self::MESSAGE);
            }
        };
    }
}
