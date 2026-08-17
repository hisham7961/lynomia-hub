<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * **الرفعُ المقطَّع: غيغابايتٌ عبر خادمٍ سقفُه ميغابايتان.**
 *
 * رفعُ السقف في الإعدادات لا يرفع شيئاً: PHP يقطع الطلبَ قبل أن يصل التطبيقَ
 * أصلاً إن تجاوز `upload_max_filesize` أو `post_max_size`، وأكثرُ الاستضافات
 * المشتركة تضعهما عند ٢–٦٤ ميغابايت ولا تُمكّن من رفعهما: `php_value` في
 * `.htaccess` تُسقط الموقع، و`.user.ini` قد تكون معطَّلة، ولوحةُ الاستضافة قد
 * لا تعرض المفتاح أصلاً.
 *
 * فالمخرجُ ألّا نطلب من الخادم ما لا يعطيه: **يُقطَّع الملفُّ في المتصفح** إلى
 * قطعٍ أصغرَ من سقف الطلب الواحد، وتصل كلُّ قطعةٍ في طلبٍ مستقلٍّ صغير، ويُجمَّع
 * الملفُّ هنا على القرص. فلا سقفَ إلا سقفُ **النظام** (`files.max_kb`) والقرص.
 *
 * والقطعُ تُلحق **بالترتيب** (`i` يساوي ما وصل فعلاً) لا بالإزاحة: كتابةُ
 * إزاحةٍ عشوائية تفتح ثقباً يُملأ بأصفارٍ صامتة، والملفُّ يخرج سليمَ الحجم
 * فاسدَ المحتوى. وكلُّ رفعةٍ في مجلد **صاحبها** فلا يُطالب أحدٌ برمز غيره.
 */
class ChunkedUpload
{
    /** ما يبقى من رفعةٍ لم تكتمل قبل أن تُكنَس (ساعة) */
    public const TTL_MIN = 60;

    /** أقصى عددِ قطعٍ لملفٍ واحد — ١ غيغابايت بقطعٍ ٤ م.ب = ٢٥٦ قطعة */
    public const MAX_PARTS = 4096;

    /** رمزٌ صالح: حروفٌ وأرقامٌ من صنع المتصفح — وما عداه لا يُقترب من القرص */
    public static function validToken(?string $t): bool
    {
        return is_string($t) && preg_match('/^[A-Za-z0-9]{16,64}$/', $t) === 1;
    }

    /** مجلدُ رفعات المستخدم — العزلُ بالمجلد لا بالفحص وحده */
    public static function dir(?string $userId = null): string
    {
        $uid = preg_replace('/[^A-Za-z0-9-]/', '', (string) ($userId ?? auth()->id() ?? 'anon'));
        $dir = storage_path('app/hub/chunks/' . ($uid ?: 'anon'));
        if (! is_dir($dir)) @mkdir($dir, 0775, true);

        return $dir;
    }

    protected static function part(string $token, ?string $userId = null): string
    {
        return self::dir($userId) . '/' . $token . '.part';
    }

    protected static function meta(string $token, ?string $userId = null): string
    {
        return self::dir($userId) . '/' . $token . '.json';
    }

    /**
     * إلحاقُ قطعةٍ بالترتيب. تُعيد عدد البايتات المتراكمة، أو رسالةَ رفضٍ صريحة.
     * الفحصُ على **ما وصل فعلاً**: قطعةٌ خارج الدور تُرفض بدل أن تُترك ثقباً.
     */
    public static function append(string $token, int $index, UploadedFile $chunk, int $capKb): array
    {
        $path = self::part($token);
        $have = is_file($path) ? filesize($path) : 0;
        $seen = self::seen($token);

        if ($index !== $seen) {
            return ['ok' => false, 'msg' => 'قطعةٌ خارج الدور — المتوقَّع رقم ' . $seen, 'have' => $have];
        }
        if ($have + $chunk->getSize() > $capKb * 1024) {
            self::forget($token);

            return ['ok' => false, 'msg' => 'تجاوز الملفُّ الحدَّ المسموح — أُلغيت الرفعة', 'have' => 0];
        }

        $in = fopen($chunk->getRealPath(), 'rb');
        $out = fopen($path, 'ab');
        if (! $in || ! $out) {
            if ($in) fclose($in);
            if ($out) fclose($out);

            return ['ok' => false, 'msg' => 'تعذّرت الكتابة على القرص', 'have' => $have];
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        self::bump($token, $index + 1);

        return ['ok' => true, 'have' => (int) filesize($path), 'next' => $index + 1];
    }

    /** كم قطعةً وصلت لهذه الرفعة (يُقرأ من ملفّ الحالة لا من الحجم) */
    public static function seen(string $token): int
    {
        $m = self::meta($token);

        return is_file($m) ? (int) (json_decode((string) file_get_contents($m), true)['seen'] ?? 0) : 0;
    }

    protected static function bump(string $token, int $seen): void
    {
        file_put_contents(self::meta($token), json_encode(['seen' => $seen, 'at' => time()]));
    }

    /**
     * تحويلُ الرفعة المكتملة إلى ملفٍ يُعامَل كأيّ ملفٍ مرفوع.
     *
     * تُعاد `UploadedFile` بعلامة الاختبار كي يجوز نقلُها من مجلدنا (لم تأتِ من
     * `is_uploaded_file`) — ثم يمرّ بكل ما يمرّ به المرفوع عادةً: قواعدُ التحقق،
     * ومنعُ الامتدادات التنفيذية، والتخزين، والبصمة. **لا مسارَ جانبيّ**.
     */
    public static function claim(string $token, string $name, ?string $userId = null): ?UploadedFile
    {
        if (! self::validToken($token)) return null;

        $path = self::part($token, $userId);
        if (! is_file($path) || filesize($path) === 0) return null;

        $safe = trim(preg_replace('#[\\\\/:*?"<>|\x00-\x1F]#u', '-', $name)) ?: 'ملف';
        $mime = self::mime($path, $safe);

        return new UploadedFile($path, hub_fit($safe, 200), $mime, null, true);
    }

    /** نوعُ المحتوى: من الملف نفسِه، وإلا من امتداده — لا من ادّعاء المتصفح */
    protected static function mime(string $path, string $name): string
    {
        $m = @mime_content_type($path);
        if (is_string($m) && $m !== '' && $m !== 'application/octet-stream') return $m;

        return match (mb_strtolower((string) pathinfo($name, PATHINFO_EXTENSION))) {
            'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
            'gif' => 'image/gif', 'pdf' => 'application/pdf', 'zip' => 'application/zip',
            'mp4' => 'video/mp4', 'mov' => 'video/quicktime',
            default => 'application/octet-stream',
        };
    }

    /** إسقاطُ رفعةٍ بكل أثرها */
    public static function forget(string $token, ?string $userId = null): void
    {
        if (! self::validToken($token)) return;
        @unlink(self::part($token, $userId));
        @unlink(self::meta($token, $userId));
    }

    /** رمزٌ جديدٌ للاختبارات وللمسارات الخادمية */
    public static function token(): string
    {
        return Str::random(32);
    }

    /**
     * كنسُ ما لم يكتمل: اتصالٌ انقطع في منتصف رفعةٍ يترك قطعاً على القرص إلى
     * الأبد. ساعةٌ تكفي أطولَ رفعةٍ معقولة، وما فوقها يُمسح.
     */
    public static function prune(): int
    {
        $root = storage_path('app/hub/chunks');
        if (! is_dir($root)) return 0;

        $n = 0;
        $deadline = time() - self::TTL_MIN * 60;
        foreach (glob($root . '/*/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < $deadline) { @unlink($f); $n++; }
        }

        return $n;
    }
}
