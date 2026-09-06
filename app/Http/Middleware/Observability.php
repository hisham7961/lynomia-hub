<?php

namespace App\Http\Middleware;

use App\Support\ErrorLog;
use App\Support\Series;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * معرّف طلب لكل نداء (X-Request-Id) + التقاط الطلبات البطيئة (> ثانية)
 * + دلاءُ RED كلَّ ٥ دقائق في terminate بعد إرسال الردّ (الطور ٢ · WP-2.2)
 */
class Observability
{
    public function handle(Request $request, Closure $next)
    {
        // معرّفٌ سابقٌ (وضعه ردُّ خطأٍ مبكّر عبر Api::requestId) يُحترَم فلا يتبدّل بين الجسم والترويسة
        // معرّفٌ يرسله العميلُ (X-Request-Id) يُحترَم إن كان سليمَ الشكل (v2.399): التكاملُ يمرّر أثرَه فيُقرأ
        // القيدُ والتسليمُ والسجلُّ بمعرّفه هو — وإلا يُولَّد هنا.
        // السقف ٤٠ محرفاً = عرضُ أعمدة `request_id` كلِّها: أطولُ منه كان يُكتب خاماً في
        // audits على MySQL الصارمة فيُسقط أيَّ طلبٍ مدقَّق — يُستبدل به UUID فيبقى الأثرُ سليماً.
        $sent = trim((string) $request->headers->get('X-Request-Id', ''));
        $rid = (string) ($request->attributes->get('request_id')
            ?: (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,39}$/', $sent) ? $sent : Str::uuid()));
        $request->attributes->set('request_id', $rid);
        // (WP-1.4) وسمُ المصدر والخارجيّ — **لا معرّفَ جديداً**: `Api::requestId` يبقى المصدرَ
        // الوحيد. «خارجيّ» يعني أنّ العميل أرسل المعرّفَ فاحتُرم ⇒ لا يُفترَض تفرّدُه،
        // والمصدرُ (web|api|hook) وسمُ عرضٍ وترابطٍ لا تخويل.
        $request->attributes->set('request_id_external', $sent !== '' && $rid === $sent);
        $path = ltrim($request->path(), '/');
        $request->attributes->set('request_source',
            str_starts_with($path, 'api/') ? 'api' : (str_starts_with($path, 'hook/') ? 'hook' : 'web'));
        $start = microtime(true);
        // (WP-2.2) لحظةُ البدء تُحفظ على الطلب نفسِه: terminate() يعمل على **نسخةٍ
        // جديدةٍ** من الوسيط تصنعها الحاوية بعد إرسال الردّ، فلا حالةَ صنفٍ تصلها —
        // والطلبُ هو الجسرُ الوحيد بين المرحلتين.
        $request->attributes->set('obs_started_at', $start);

        // **سجلٌّ مهيكل**: كلُّ سطرٍ يُكتب أثناء هذا الطلب (Log::…/report) يحمل معرّفَه
        // ومسارَه ومستخدمَه — فيُربط بمركز الأخطاء والتدقيق بالمعرّف نفسه.
        try {
            \Illuminate\Support\Facades\Log::withContext(array_filter([
                'request_id' => $rid,
                'method' => $request->method(),
                'path' => '/' . ltrim($request->path(), '/'),
                'user_id' => auth()->id(),
                'ip' => $request->ip(),
                'release' => (string) config('hub.version'),
            ]));
        } catch (\Throwable $e) {
            // السياقُ إثراءٌ لا شرط
        }

        $response = $next($request);

        if (method_exists($response, 'header')) $response->header('X-Request-Id', $rid);

        $ms = (int) ((microtime(true) - $start) * 1000);
        $limit = max(50, (int) rescue(fn () => setting('ops.slow_ms', 1000), 1000, false));   // عتبة البطء قابلة للضبط — ولا تسقط الطلب إن سقطت القاعدة
        if ($ms > $limit && ! $request->is('files/*', 'storage/*')) {
            // المدة تُقرَّب لمرتبة: لو دخلت الرسالةَ بالمللي ثانية لصار كل طلب بطيء
            // صفاً فريداً — فيغرق مركز الأخطاء بدل أن يعدّ تكرار البطء نفسه.
            $tier = $ms >= 30000 ? '>30ث' : ($ms >= 10000 ? '>10ث' : ($ms >= 3000 ? '>3ث' : '>ثانية'));
            // تعميم المعرّفات في المسار: بدونه صار كل سجل بطيء صفَّ خطأ مستقلاً بلا
            // تجميع — بالمطبِّع الواحد نفسِه الذي تجمع به دلاءُ RED (WP-2.2): نسخةٌ
            // محلية منه هنا انحرفت يوماً عن أختها فتشظّى التجميع، فحُذفت لصالحه.
            $path = ErrorLog::routePattern($request);
            ErrorLog::capture('slow', 'طلب بطيء (' . $tier . '): ' . $request->method() . ' ' . $path);
        }

        return $response;
    }

    /**
     * (WP-2.2) دلاءُ RED: صفٌّ لكل (حاوية ٥ دقائق × سطح × فعل × مسار مطبَّع)
     * يتجمّع فيه العددُ وأخطاءُ 4xx/5xx والبطءُ والأزمنة. تجري **بعد إرسال
     * الردّ** (terminate تحت FPM بعد fastcgi_finish_request) فلا يدفع المستخدمُ
     * ثمنَ الكتابة — وكلفتُها سقفُها استعلامان رخيصان: تحديثٌ ذرّي مشروط، ثم
     * insertOrIgnore عند غياب الصفّ (نمطُ Api::countUsage الحرفيّ) — ولا ترمي
     * أبداً: القياسُ إثراءٌ لا شرطٌ للردّ.
     *
     * التنافسُ على الصفّ الساخن (كلُّ الطلبات المتزامنة على أزحم مسارٍ تلتقي في
     * صفِّ الحاوية الواحد) يُحسم بالتحديث الذرّي **بلا معاملة** عمداً: قفلٌ أوسع
     * انتظارٌ تحت الحمل، وضياعُ طلبٍ واحدٍ في سباق إدراجٍ نادرٍ ثمنٌ مقبول.
     */
    public function terminate(Request $request, $response): void
    {
        try {
            // الملفاتُ والأصول الثابتة خارج القياس — تدفّقاتٌ طويلة تلوّث توزيعَ الأزمنة
            if ($request->is('files/*', 'storage/*', 'build/*', 'favicon.ico')) return;
            // حارسُ مخطّطٍ مخبّأ (hub_has_col — ٥ دقائق): لا استعلامَ معلوماتٍ لكل
            // طلب، وقاعدةٌ لم تُرحَّل بعدُ تُسكِت القياسَ بلا كسر. جدولٌ أُسقط تحت
            // كاشٍ دافئ يرمي QueryException — فيبتلعها الغلاف أدناه.
            if (! hub_has_col('http_metric_buckets', 'hist')) return;

            $start = (float) ($request->attributes->get('obs_started_at')
                ?: $request->server('REQUEST_TIME_FLOAT', 0));
            $ms = $start > 0 ? max(0, (int) round((microtime(true) - $start) * 1000)) : 0;
            $status = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : 200;
            $slowAt = max(50, (int) rescue(fn () => setting('ops.slow_ms', 1000), 1000, false));

            // المطبِّعُ الواحد (ErrorLog::routePattern): UUID ⇒ {id} ورقمٌ ⇒ {n}
            // فتلتقي كلُّ معرّفات المسار الواحد في صفٍّ واحد. العرضُ ١٦٠ يُفرض هنا
            // عند الكاتب — MySQL الصارمة ترمي ما يفيض حيث تبتره SQLite صامتةً.
            $route = mb_substr(ErrorLog::routePattern($request), 0, 160);
            $surface = mb_substr((string) ($request->attributes->get('request_source') ?: 'web'), 0, 8);
            $method = mb_substr($request->method(), 0, 10);
            $at = hub_metric_bucket(now())->toDateTimeString();

            // مدرَّجُ الأزمنة: دلوُ Series (نموّ ١٫١٥) — النسبُ المئوية المشتقّة منه
            // **تقريبيةٌ بحدّ خطأٍ نسبيٍّ مُعلَن**: ممثِّلُ الدلو وسطُه الهندسيّ فالخطأ
            // ≤ √1.15−1 ≈ ±7.5٪ (Series::MAX_REL_ERROR — القرار ق٥: لا نسبَ مزيّفة،
            // والشاشاتُ تُوسَم بالتقريب). json_set/json_extract تعملان بالاسم نفسِه
            // على SQLite (JSON1) وMySQL 8 فلا لهجةَ محرّكٍ في الزيادة الذرّية.
            $b = Series::bucket((float) $ms);
            $jp = '$."' . $b . '"';
            $sets = [
                'count' => DB::raw('count + 1'),
                'sum_ms' => DB::raw('sum_ms + ' . $ms),
                'max_ms' => DB::raw('case when max_ms < ' . $ms . ' then ' . $ms . ' else max_ms end'),
                'hist' => DB::raw("json_set(coalesce(hist, '{}'), '{$jp}', coalesce(json_extract(hist, '{$jp}'), 0) + 1)"),
                'updated_at' => now(),
            ];
            if ($status >= 400 && $status < 500) $sets['err4'] = DB::raw('err4 + 1');
            if ($status >= 500) $sets['err5'] = DB::raw('err5 + 1');
            if ($ms > $slowAt) $sets['slow'] = DB::raw('slow + 1');

            $hit = DB::table('http_metric_buckets')
                ->where('bucket_at', $at)->where('surface', $surface)
                ->where('method', $method)->where('route', $route)
                ->update($sets);
            if (! $hit) {
                DB::table('http_metric_buckets')->insertOrIgnore([
                    'bucket_at' => $at, 'surface' => $surface, 'method' => $method, 'route' => $route,
                    'count' => 1,
                    'err4' => $status >= 400 && $status < 500 ? 1 : 0,
                    'err5' => $status >= 500 ? 1 : 0,
                    'slow' => $ms > $slowAt ? 1 : 0,
                    'sum_ms' => $ms, 'max_ms' => $ms,
                    'hist' => json_encode([(string) $b => 1]),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // صمتٌ تامّ — القياسُ لا يُبطئ الطلبَ ولا يُسقطه
        }
    }
}
