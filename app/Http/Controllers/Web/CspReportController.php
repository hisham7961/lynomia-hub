<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Ops\ErrorLog;
use App\Support\Security\ContentSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * **مستقبِلُ تقارير CSP** (بند الدَّين #12 · FE-03) — وجهةُ `report-uri` في سياسة السكربتات.
 *
 * نقطةٌ عامّة بالضرورة: المتصفّحُ يرسل التقريرَ بلا رمز CSRF وقد يرسله بلا كعكة. فتُحصَّن
 * بما يناسب سطحاً لا يُصادَق:
 *  · حدُّ معدّلٍ لكل عنوان (المسار) + **سقفٌ يوميٌّ عامّ** (`REPORT_DAILY_CAP`) + حجمٌ أقصى للجسم.
 *  · يُقبل التقريرُ عن **صفحاتنا وحدها** (مضيفُ `document-uri` = مضيفُ الطلب) — لا يصير
 *    قناةَ حشوٍ لمركز الأخطاء من أيّ موقع.
 *  · يُخزَّن بنوع `js` (بلاغُ متصفّح): يُجمَّع بالبصمة، **ولا يُدفع إشعاراً** للمالكين، ولا
 *    يُرسَل نصُّه إلى نموذجٍ (ErrorSnippet يرفض `js`) — نصٌّ حرٌّ من الخارج لا قناةَ تصيّد.
 *  · الردُّ ٢٠٤ دائماً — لا يكشف للمُرسِل ما قُبل وما أُسقط.
 *
 * يقبل الصيغتين: `report-uri` القديمة (`{"csp-report": {...}}` بنوع application/csp-report)
 * وReporting API (`[{"type":"csp-violation","body":{...}}]`).
 */
class CspReportController extends Controller
{
    public function store(Request $r)
    {
        $raw = (string) $r->getContent();
        if ($raw === '' || strlen($raw) > ContentSecurity::REPORT_MAX_BYTES) return response()->noContent();

        $j = json_decode($raw, true);
        if (! is_array($j)) return response()->noContent();

        $reports = [];
        if (isset($j['csp-report']) && is_array($j['csp-report'])) {
            $reports[] = $j['csp-report'];
        } elseif (array_is_list($j)) {
            foreach (array_slice($j, 0, 5) as $x) {
                if (is_array($x) && ($x['type'] ?? '') === 'csp-violation' && is_array($x['body'] ?? null)) $reports[] = $x['body'];
            }
        }

        foreach ($reports as $rep) {
            $this->capture($r, $rep);
        }

        return response()->noContent();
    }

    protected function capture(Request $r, array $rep): void
    {
        $pick = function (string ...$keys) use ($rep): string {
            foreach ($keys as $k) {
                if (isset($rep[$k]) && is_scalar($rep[$k]) && (string) $rep[$k] !== '') return (string) $rep[$k];
            }

            return '';
        };

        $doc = $pick('document-uri', 'documentURL');
        $docHost = strtolower((string) parse_url($doc, PHP_URL_HOST));
        // صفحاتُنا وحدها — بالعنوان المضبوط لا بترويسة Host التي يتحكّم فيها المُرسِل
        $ours = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($docHost === '' || ($ours !== '' ? $docHost !== $ours : $docHost !== strtolower($r->getHost()))) return;

        // سقفان: يوميٌّ عامّ، ويوميٌّ لكلِّ عنوان — فلا يستنفد مُرسِلٌ مجهولٌ واحدٌ حصّةَ اليوم كلَّها
        $day = now()->toDateString();
        $ipKey = 'cspreport:' . $day . ':' . sha1((string) $r->ip());
        foreach ([['cspreport:' . $day, ContentSecurity::REPORT_DAILY_CAP], [$ipKey, ContentSecurity::REPORT_IP_DAILY_CAP]] as [$key, $cap]) {
            Cache::add($key, 0, now()->endOfDay());
            if ((int) Cache::get($key, 0) >= $cap) return;
        }
        Cache::increment('cspreport:' . $day);
        Cache::increment($ipKey);

        $directive = mb_substr(preg_replace('/[^a-z\-]/', '', strtolower($pick('effective-directive', 'effectiveDirective', 'violated-directive'))), 0, 40);
        $disposition = $pick('disposition') === 'enforce' ? 'مفروضة' : 'تقرير';
        $blocked = self::shortUri($pick('blocked-uri', 'blockedURL'));
        // المسارُ مطبَّعاً (رموزُ التوقيع والاستعادة والتفعيل ⇐ {tok}) — لا يُخزَّن رمزٌ صالحٌ في مركز الأخطاء
        $docPath = ErrorLog::maskPath(ltrim((string) (parse_url($doc, PHP_URL_PATH) ?: '/'), '/'));
        $docPath = '/' . $docPath;
        $source = self::shortUri($pick('source-file', 'sourceFile'));
        $line = (int) $pick('line-number', 'lineNumber');

        $msg = 'CSP ' . ($directive ?: 'script-src') . ' (' . $disposition . '): ' . ($blocked ?: 'inline')
            . ' — في ' . mb_substr($docPath, 0, 200);

        ErrorLog::capture('js', mb_substr($msg, 0, 400), $source !== '' ? $source : mb_substr($docPath, 0, 250), $line ?: null);
    }

    /** `inline`/`eval` كما هما؛ والرابطُ أصلُه ومسارُه بلا سلسلة استعلام (قد تحمل رمزاً) */
    protected static function shortUri(string $u): string
    {
        $u = trim($u);
        if ($u === '' || ! str_contains($u, '/')) return mb_substr(preg_replace('/[^A-Za-z0-9\-]/', '', $u), 0, 20);
        $p = parse_url($u);
        if (! is_array($p)) return '';
        $out = isset($p['host']) ? (($p['scheme'] ?? 'https') . '://' . $p['host']) : '';

        $path = isset($p['path']) ? '/' . ErrorLog::maskPath(ltrim((string) $p['path'], '/')) : '';

        return mb_substr($out . $path, 0, 200);
    }
}
