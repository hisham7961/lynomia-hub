<?php

namespace App\Http\Middleware;

use App\Support\ErrorLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** معرّف طلب لكل نداء (X-Request-Id) + التقاط الطلبات البطيئة (> ثانية) */
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
            // تعميم المعرّفات في المسار: بدونه صار كل سجل بطيء صفَّ خطأ مستقلاً بلا تجميع
            $path = preg_replace([
                '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
                '/\/\d+(?=\/|$)/',
            ], ['{id}', '/{n}'], $request->path());
            ErrorLog::capture('slow', 'طلب بطيء (' . $tier . '): ' . $request->method() . ' ' . $path);
        }

        return $response;
    }
}
