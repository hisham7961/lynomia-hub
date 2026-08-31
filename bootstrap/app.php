<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
        $middleware->appendToGroup('web', \App\Http\Middleware\HubMaintenance::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\SessionSentry::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\WorkHours::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\TrackVisits::class);
        // الملفُّ المقطَّع يصير ملفاً مرفوعاً عادياً قبل أن يصل المتحكّم
        $middleware->appendToGroup('web', \App\Http\Middleware\ResolveChunkedUploads::class);
        // ختمُ بدء البثّ للتنزيلات — تُخفي به الصفحةُ لوحةَ «يُحضَّر»
        $middleware->appendToGroup('web', \App\Http\Middleware\DownloadPing::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\SecurityHeaders::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\Observability::class);
        // رادارُ الكشف: يُلحَق أخيراً (الأقربَ للمتحكّم) فيلتقط منعَ ٤٠٣ الذي يرميه
        // `abort` من المتحكّم قبل أن يصعد، ويُعيد رميَه فلا يتغيّر ردُّ المنع.
        $middleware->appendToGroup('web', \App\Http\Middleware\AccessRadar::class);
        // وضعُ الصيانة يسري على API كما على الويب (v2.324): كانت الكتابةُ تستمرّ
        // من الباب الخلفيّ أثناء الترحيل — والصيانةُ تُعلَن لتتوقّف الكتابةُ كلُّها
        $middleware->appendToGroup('api', \App\Http\Middleware\HubMaintenance::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\SecurityHeaders::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\Observability::class);
        // رادارُ الكشف على API أيضاً (v2.367): كان مقصوراً على الويب فمُنِعُ ٤٠٣
        // على مسارات API (نطاقٌ ممنوع، سجلٌّ خارج الملكية) لا يُرصد إطلاقاً.
        $middleware->appendToGroup('api', \App\Http\Middleware\AccessRadar::class);
        // الويبهوك الوارد سطحٌ آليّ لا نموذج له: يُصادَق بالرمز في الرابط + توقيع
        // HMAC، فلا CSRF عليه (المُرسِل خدمةٌ خارجية لا متصفّح يحمل الرمز).
        $middleware->validateCsrfTokens(except: ['hook/*']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // كل استثناء يُجمَّع في مركز الأخطاء (بلا كسر المعالجة الأصلية)
        $exceptions->report(fn (\Throwable $e) => \App\Support\ErrorLog::exception($e));
    })->create();
