<?php

namespace App\Support;

/**
 * **تصنيفُ العطل وشدّتُه** — من الاستثناء نفسِه لا من ظنّ القارئ.
 *
 * الصنفُ يجيب «أين الخلل؟» (قاعدة؟ تكامل؟ تخزين؟ شبكة؟ إعداد؟)، والشدّةُ
 * تجيب «كم يهمّ الآن؟». والقاعدةُ الحاكمة: **CRITICAL تعني انقطاعاً فعلياً**
 * (قاعدةٌ لا تُجيب، مفتاحُ تعميةٍ غائب) لا كلَّ استثناء — فحين يكون كلُّ شيءٍ
 * حرجاً لا يكون شيءٌ حرجاً.
 */
final class ErrorTaxonomy
{
    public const CATEGORIES = ['VALIDATION', 'AUTHENTICATION', 'AUTHORIZATION', 'BUSINESS_RULE', 'DEPENDENCY',
        'DATABASE', 'INTEGRATION', 'NETWORK', 'TIMEOUT', 'QUEUE', 'STORAGE', 'CONFIGURATION', 'APPLICATION',
        'SECURITY', 'UNKNOWN'];

    public const SEVERITIES = ['INFO', 'WARNING', 'ERROR', 'HIGH', 'CRITICAL'];

    /** ترتيبُ الشدّة للفرز والمقارنة */
    public const RANK = ['INFO' => 0, 'WARNING' => 1, 'ERROR' => 2, 'HIGH' => 3, 'CRITICAL' => 4];

    /** تسمياتٌ عربية للشاشات */
    public const LABELS = [
        'VALIDATION' => 'تحقّق', 'AUTHENTICATION' => 'مصادقة', 'AUTHORIZATION' => 'صلاحية', 'BUSINESS_RULE' => 'قاعدة عمل',
        'DEPENDENCY' => 'اعتمادية', 'DATABASE' => 'قاعدة البيانات', 'INTEGRATION' => 'تكامل خارجيّ', 'NETWORK' => 'شبكة',
        'TIMEOUT' => 'مهلة', 'QUEUE' => 'طابور', 'STORAGE' => 'تخزين', 'CONFIGURATION' => 'إعداد', 'APPLICATION' => 'تطبيق',
        'SECURITY' => 'أمن', 'UNKNOWN' => 'غير مصنَّف',
        'INFO' => 'معلومة', 'WARNING' => 'تحذير', 'ERROR' => 'خطأ', 'HIGH' => 'عالٍ', 'CRITICAL' => 'حرج',
    ];

    /**
     * @return array{0:string,1:string} [الصنف، الشدّة]
     */
    public static function classify(\Throwable $e): array
    {
        $msg = (string) $e->getMessage();
        $cls = get_class($e);

        if ($e instanceof \Illuminate\Validation\ValidationException) return ['VALIDATION', 'INFO'];
        if ($e instanceof \Illuminate\Auth\AuthenticationException) return ['AUTHENTICATION', 'INFO'];
        if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) return ['AUTHORIZATION', 'WARNING'];
        if ($e instanceof \Illuminate\Session\TokenMismatchException) return ['SECURITY', 'INFO'];
        if ($e instanceof \Illuminate\Encryption\MissingAppKeyException
            || $e instanceof \Illuminate\Contracts\Encryption\DecryptException) return ['CONFIGURATION', 'CRITICAL'];

        if ($e instanceof \Illuminate\Database\QueryException || $e instanceof \PDOException) {
            // انقطاعُ الاتصال حرجٌ فعلاً؛ استعلامٌ فاسد أو قيدٌ مرفوض خطأٌ عالٍ
            $state = (string) ($e instanceof \Illuminate\Database\QueryException ? ($e->errorInfo[0] ?? '') : $e->getCode());
            $down = str_starts_with($state, '08') || preg_match('/gone away|refused|too many connections|Lost connection|unable to open database/i', $msg);

            return ['DATABASE', $down ? 'CRITICAL' : 'HIGH'];
        }

        if ($e instanceof \Illuminate\Http\Client\ConnectionException) {
            return [preg_match('/timed? ?out|timeout/i', $msg) ? 'TIMEOUT' : 'NETWORK', 'WARNING'];
        }
        if ($e instanceof \Illuminate\Http\Client\RequestException) return ['INTEGRATION', 'WARNING'];

        if (str_starts_with($cls, 'League\\Flysystem\\') || preg_match('/No space left|Permission denied|failed to open stream|Disk quota/i', $msg)) {
            return ['STORAGE', 'HIGH'];
        }
        if (str_starts_with($cls, 'Mpdf\\')) return ['DEPENDENCY', 'ERROR'];
        if (str_starts_with($cls, 'Symfony\\Component\\Mailer\\') || str_contains($cls, 'Swift')) return ['INTEGRATION', 'WARNING'];

        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            $st = $e->getStatusCode();
            if ($st === 401) return ['AUTHENTICATION', 'INFO'];
            if ($st === 403) return ['AUTHORIZATION', 'WARNING'];
            if ($st === 422) return ['VALIDATION', 'INFO'];
            if (in_array($st, [502, 503, 504], true)) return ['DEPENDENCY', 'HIGH'];
            if ($st >= 500) return ['APPLICATION', 'ERROR'];

            return ['BUSINESS_RULE', 'INFO'];
        }

        // رسائلُ التكاملات الأصيلة (أودو/تلجرام/بريد) تُميَّز بنصّها — لا صنفَ استثناءٍ لها
        if (preg_match('/أودو|Odoo|تلجرام|Telegram|SMTP|بريد/u', $msg)) {
            return [preg_match('/مهلة|timeout|timed out/iu', $msg) ? 'TIMEOUT' : 'INTEGRATION', 'WARNING'];
        }
        if (preg_match('/env\(|APP_KEY|configuration|misconfigured|not configured|إعداد/iu', $msg)) return ['CONFIGURATION', 'HIGH'];
        if (preg_match('/hmac|signature|توقيع|CSRF|token mismatch/iu', $msg)) return ['SECURITY', 'WARNING'];

        if ($e instanceof \Error || $e instanceof \ErrorException) return ['APPLICATION', 'ERROR'];

        return ['APPLICATION', 'ERROR'];
    }

    /** صنفٌ وشدّة افتراضيان لأنواع الالتقاط غير الاستثنائية (بطء، متصفّح، دفعة) */
    public static function forKind(string $kind): array
    {
        return match ($kind) {
            'slow' => ['APPLICATION', 'WARNING'],
            'js' => ['APPLICATION', 'INFO'],
            'bulk' => ['APPLICATION', 'ERROR'],
            default => ['UNKNOWN', 'ERROR'],
        };
    }

    /**
     * بصمةُ التجميع: الرسالةُ بعد تعميم ما يتبدّل بين وقوعٍ وآخر (معرّفات UUID،
     * أرقامٌ طويلة، بصمات hex) — فخطأٌ واحد بمئة معرّف صفٌّ واحد بمئة تكرار،
     * لا مئةُ صفٍّ يغرق بينها ما يهمّ. الرسالةُ المخزَّنة تبقى كما وقعت أوّلَ مرة.
     */
    public static function fingerprintOf(string $message): string
    {
        $m = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '{uuid}', $message);
        $m = preg_replace('/\b[0-9a-f]{16,}\b/i', '{hex}', (string) $m);
        $m = preg_replace('/\b\d{4,}\b/', '{n}', (string) $m);

        return (string) $m;
    }
}
