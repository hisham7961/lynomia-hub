<?php

use App\Http\Controllers\Web\ActivationController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\BoardController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DataRoomController;
use App\Http\Controllers\Web\OpsController;
use App\Http\Controllers\Web\FileController;
use App\Http\Controllers\Web\PortalController;
use App\Http\Controllers\Web\PwaController;
use Illuminate\Support\Facades\Route;

// ── مسحُ ملصق المحطة (s/{code}) — نظيرُ c/{code} للعهدة وp/{code} للمنتج
//    (Work OS · الطور F · WP-F.1 · §26). **QR يتطلّب دخولاً**: بوسيطِ `auth`
//    (الضيفُ يُحوَّل للدخول) وحرسُ العميل عبر PortalGuard (في مجموعة web) → ٤٠٤.
//    مُقدَّمٌ عمداً على رابط المشاركة العام `s/{token}` أدناه، ومُقيَّدٌ بشكل كود
//    المحطة (يحوي شرطة: ST-2026-0001) — بينما رمزُ المشاركة `Str::random(48)` بلا
//    شرطة — فلا يتقاطعان، ولا يُكسَر رابطُ المشاركة القائم (إضافةٌ لا تغيير).
Route::middleware('auth')->get('s/{code}', [\App\Http\Controllers\Web\StationController::class, 'byCode'])
    ->where('code', '[A-Za-z0-9]+-[A-Za-z0-9-]+')->name('stations.code');

// ── الوجه العام لغرفة البيانات (بلا تسجيل دخول — الرمز هو المفتاح) ──
Route::get('s/{token}', [DataRoomController::class, 'show'])->name('share.show');
Route::post('s/{token}', [DataRoomController::class, 'unlock'])->name('share.unlock')->middleware('throttle:10,1');
Route::get('s/{token}/file', [DataRoomController::class, 'file'])->name('share.file');

// ── فحص صحي عام لمراقبات Uptime (بلا تسجيل دخول) ──
// **بلا وسطاء الجلسة والصيانة** (v2.399): كان وضعُ الصيانة يردّ صفحةَ HTML ٥٠٣ على المسبار
// فتُنذر مراقبةُ Uptime بانقطاعٍ لا وجودَ له، وكان `setting()` في وسيطٍ سابقٍ يرمي ٥٠٠ حين
// تسقط القاعدةُ فلا يقول المسبارُ «القاعدة ساقطة». الصحّةُ تُقال صريحةً: MAINTENANCE حالةٌ لا عطل.
Route::get('healthz', [OpsController::class, 'health'])->name('healthz')->middleware('throttle:30,1')
    ->withoutMiddleware([\App\Http\Middleware\HubMaintenance::class, \App\Http\Middleware\WorkHours::class,
        \App\Http\Middleware\SessionSentry::class, \App\Http\Middleware\TrackVisits::class,
        \App\Http\Middleware\Require2faForPrivileged::class, \App\Http\Middleware\ResolveChunkedUploads::class,
        \App\Http\Middleware\DownloadPing::class, \App\Http\Middleware\AccessRadar::class]);

// ── PWA: بيان وأيقونة وصفحة بلا اتصال (عامة) ──
Route::get('manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('pwa-icon.svg', [PwaController::class, 'icon'])->name('pwa.icon');
Route::get('offline', [PwaController::class, 'offline'])->name('pwa.offline');

// ── سقالةُ الروابط العالميّة للجوال (Mobile Readiness · الطور H · H.3) ──
// تفرض Apple/Google هاتين الوثيقتين عند جذر النطاق **بلا إعادةِ توجيه** وبنوع JSON.
// عامّتان — كنظيرِ `healthz` تُجرَّدان من وسطاء الجلسة/الصيانة كي يبقى ردُّ CDN نظيفاً.
// **صادقتان NOT_CONFIGURED** حتى تُصدَر المعرّفاتُ الحقيقيّة (تربطان صفرَ تطبيق) — لا اختلاق.
Route::get('.well-known/apple-app-site-association',
    [\App\Http\Controllers\Web\MobileWellKnownController::class, 'appleAppSiteAssociation'])
    ->name('mobile.aasa')->middleware('throttle:60,1')
    ->withoutMiddleware([\App\Http\Middleware\HubMaintenance::class, \App\Http\Middleware\WorkHours::class,
        \App\Http\Middleware\SessionSentry::class, \App\Http\Middleware\TrackVisits::class,
        \App\Http\Middleware\Require2faForPrivileged::class, \App\Http\Middleware\ResolveChunkedUploads::class,
        \App\Http\Middleware\DownloadPing::class, \App\Http\Middleware\AccessRadar::class]);
Route::get('.well-known/assetlinks.json',
    [\App\Http\Controllers\Web\MobileWellKnownController::class, 'assetLinks'])
    ->name('mobile.assetlinks')->middleware('throttle:60,1')
    ->withoutMiddleware([\App\Http\Middleware\HubMaintenance::class, \App\Http\Middleware\WorkHours::class,
        \App\Http\Middleware\SessionSentry::class, \App\Http\Middleware\TrackVisits::class,
        \App\Http\Middleware\Require2faForPrivileged::class, \App\Http\Middleware\ResolveChunkedUploads::class,
        \App\Http\Middleware\DownloadPing::class, \App\Http\Middleware\AccessRadar::class]);

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'show'])->name('login');
    // (الجولة 1 · F35) خانقُ الدخول المسمّى (AppServiceProvider): ١٠ محاولاتٍ
    // بالدقيقة لكلِّ (بريد+عنوان) لا للعنوان وحدَه — مكتبٌ خلف NAT واحدٍ كان
    // يستنفد حصّةَ العنوان بدخولَين لكلِّ زميل. وسقفٌ ثانٍ أوسعُ على العنوان
    // يصدّ الإغراق، وقفلُ الحساب القائم يكمل الحماية على الحساب نفسه.
    Route::post('login', [AuthController::class, 'login'])->name('login.attempt')
        ->middleware('throttle:login');
    Route::get('login/otp', [AuthController::class, 'otpShow'])->name('login.otp');
    Route::post('login/otp', [AuthController::class, 'otpVerify'])->name('login.otp.verify')
        ->middleware('throttle:6,1');
});

// التوقيع الإلكتروني — الجهة العامة: العميل بلا حساب، برابط خاص وكلمة سر
// الويبهوك الوارد — سطحٌ عامٌّ يُصادَق بالرمز في الرابط + توقيع HMAC اختياريّ
Route::post('hook/{token}', [\App\Http\Controllers\Web\InboundHookController::class, 'receive'])
    ->name('hook.receive')->middleware('throttle:120,1');

// مفاتيحُ المرور — الدخولُ بلا كلمة سر (زائر): خياراتٌ ثم تحقّق
Route::post('passkey/login/options', [\App\Http\Controllers\Web\PasskeyController::class, 'loginOptions'])
    ->name('passkey.login.options')->middleware('throttle:30,1');
Route::post('passkey/login/verify', [\App\Http\Controllers\Web\PasskeyController::class, 'loginVerify'])
    ->name('passkey.login.verify')->middleware('throttle:30,1');

// تفعيلُ حساب العميل — عامٌّ (ما قبل المصادقة)، برموزٍ لمرّةٍ ومقيَّدةٍ بالمعدّل
// (Work OS · الطور B · WP-B.1 · §12). حدُّ المعدّل يحاكي حدَّ الدخول (web.php:78):
// العميلُ يفتح الرابطَ، يُدخل الرمزَ، ثم يضع كلمةَ سرّه بنفسه — لا كلمةَ سرٍّ تُرسَل.
Route::middleware('throttle:10,1')->group(function () {
    Route::get('activate/{token}', [ActivationController::class, 'show'])->name('activate.show');
    Route::post('activate/{token}/otp', [ActivationController::class, 'otp'])->name('activate.otp');
    Route::post('activate/{token}/set', [ActivationController::class, 'set'])->name('activate.set');
});

Route::get('sign/{token}', [\App\Http\Controllers\Web\EsignController::class, 'show'])->name('sign.show');
Route::post('sign/{token}/otp', [\App\Http\Controllers\Web\EsignController::class, 'sendOtp'])->name('sign.otp')->middleware('throttle:6,10');
Route::post('sign/{token}/unlock', [\App\Http\Controllers\Web\EsignController::class, 'unlock'])->name('sign.unlock');
Route::post('sign/{token}', [\App\Http\Controllers\Web\EsignController::class, 'sign'])->name('sign.sign');
Route::post('sign/{token}/decline', [\App\Http\Controllers\Web\EsignController::class, 'decline'])->name('sign.decline');
Route::get('sign/{token}/doc', [\App\Http\Controllers\Web\EsignController::class, 'clientDoc'])->name('sign.doc');
Route::get('sign/{token}/certificate', [\App\Http\Controllers\Web\EsignController::class, 'clientCertificate'])->name('sign.cert');
Route::get('sign/{token}/pdf', [\App\Http\Controllers\Web\EsignController::class, 'clientPdf'])->name('sign.pdf');
Route::match(['get', 'post'], 'verify', [\App\Http\Controllers\Web\EsignController::class, 'verify'])->name('sign.verify');
// فتحُ الوثيقة الموقّعة مباشرةً بمسح QR المطبوع عليها — عامٌّ بلا حساب، للموقّعة
// فقط، مقيّدٌ بالمعدّل. الرمزُ مطبوعٌ على الوثيقة فحاملُها يملكها أصلاً.
Route::get('verify/{code}/doc', [\App\Http\Controllers\Web\EsignController::class, 'verifyDoc'])
    ->name('sign.verify.doc')->middleware('throttle:20,1');

Route::middleware('auth')->group(function () {
    // التوقيع الإلكتروني — الجهة الداخلية (بصلاحية العقود)
    Route::get('esign', [\App\Http\Controllers\Web\EsignController::class, 'index'])->name('esign.index');
    Route::post('esign', [\App\Http\Controllers\Web\EsignController::class, 'store'])->name('esign.store');
    Route::post('esign/templates', [\App\Http\Controllers\Web\EsignController::class, 'storeTemplate'])->name('esign.tpl.store');
    Route::delete('esign/templates/{id}', [\App\Http\Controllers\Web\EsignController::class, 'destroyTemplate'])->name('esign.tpl.destroy');
    Route::get('esign/templates/{id}/edit', [\App\Http\Controllers\Web\EsignController::class, 'editTemplate'])->name('esign.tpl.edit');
    Route::put('esign/templates/{id}', [\App\Http\Controllers\Web\EsignController::class, 'updateTemplate'])->name('esign.tpl.update');
    Route::post('esign/templates/{id}/archive', [\App\Http\Controllers\Web\EsignController::class, 'archiveTemplate'])->name('esign.tpl.archive');
    Route::post('esign/preview', [\App\Http\Controllers\Web\EsignController::class, 'preview'])->name('esign.preview');
    Route::get('esign/{id}/doc', [\App\Http\Controllers\Web\EsignController::class, 'doc'])->name('esign.doc');
    Route::get('esign/{id}/certificate', [\App\Http\Controllers\Web\EsignController::class, 'certificate'])->name('esign.cert');
    Route::get('esign/{id}/pdf', [\App\Http\Controllers\Web\EsignController::class, 'pdf'])->name('esign.pdf');
    Route::post('esign/{id}/cancel', [\App\Http\Controllers\Web\EsignController::class, 'cancel'])->name('esign.cancel');
    Route::post('esign/{id}/resend', [\App\Http\Controllers\Web\EsignController::class, 'resend'])->name('esign.resend');
    Route::post('esign/{id}/extend', [\App\Http\Controllers\Web\EsignController::class, 'extend'])->name('esign.extend');
    Route::post('esign/{id}/approve', [\App\Http\Controllers\Web\EsignController::class, 'approve'])->name('esign.approve');
    Route::post('esign/{id}/reject', [\App\Http\Controllers\Web\EsignController::class, 'reject'])->name('esign.reject');
    Route::get('esign/{id}/edit', [\App\Http\Controllers\Web\EsignController::class, 'edit'])->name('esign.edit');
    Route::put('esign/{id}', [\App\Http\Controllers\Web\EsignController::class, 'update'])->name('esign.update');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // باني اللوحات — لوحات متعددة مبنيّة من سجل الودجات
    Route::get('boards', [BoardController::class, 'index'])->name('boards.index');
    Route::post('boards', [BoardController::class, 'store'])->name('boards.store');
    Route::get('boards/{id}', [BoardController::class, 'edit'])->name('boards.edit');
    Route::put('boards/{id}', [BoardController::class, 'update'])->name('boards.update');
    Route::delete('boards/{id}', [BoardController::class, 'destroy'])->name('boards.destroy');
    Route::put('boards/{id}/layout', [BoardController::class, 'saveLayout'])->name('boards.layout');
    Route::post('boards/{id}/widgets', [BoardController::class, 'addWidget'])->name('boards.widget.add');
    Route::delete('boards/{id}/widgets/{widgetId}', [BoardController::class, 'removeWidget'])->name('boards.widget.remove');
    Route::get('me', [PortalController::class, 'me'])->name('portal.me');
    // «عهدتي» — خدمةٌ ذاتيّة (الجولة 1 · F1): سطحٌ واحدٌ لسؤال «ما الذي بيدي؟»
    Route::get('me/custody', [PortalController::class, 'myCustody'])->name('portal.custody');
    /*
     * **«وثائقي»** — خدمةٌ ذاتيّةٌ بالتعليلِ نفسِه (مجلس الخبراء · N-5). رادارُ
     * «ينتهي قريباً» يُنذر صاحبَ الشأنِ بإقامتِه ويسوقه إلى `/me`، و`/me` لم تكن
     * تعرض مرفقاً واحداً — **إنذارٌ بلا وجهة**. و`att.dl`/`att.view` لا تصلحان
     * وجهةً: حارسُهما `hub_can($u,'hr','v')` وهي صلاحيّةٌ لا يملكها الموظّفُ ولا
     * ينبغي. فالارتباطُ بـ`employees.user_id` هو التفويض — كـ«عهدتي» تماماً —
     * والقاعدةُ `DocumentPolicy::subjectMay` نفسُها التي يقرؤها الرادار.
     */
    Route::get('me/documents/{id}/dl', [PortalController::class, 'docDownload'])->name('portal.doc.dl');
    Route::get('me/documents/{id}/view', [PortalController::class, 'docPreview'])->name('portal.doc.view');
    // قرارُ طلب الإجازة بأزرارٍ صريحة (الجولة 1 · F5) — لا تحريرَ سجلٍّ خام
    Route::post('m/leaves/{id}/decide', [\App\Http\Controllers\Web\LeaveDecisionController::class, 'decide'])->name('leaves.decide');
    Route::get('files/{path}', [FileController::class, 'show'])->name('file.show')->where('path', 'hub/.*');

    require __DIR__ . '/web/workspace.php';
    require __DIR__ . '/web/workforce.php';
    require __DIR__ . '/web/ask.php';
    require __DIR__ . '/web/assets.php';
    require __DIR__ . '/web/identity.php';
    require __DIR__ . '/web/collaboration.php';
    require __DIR__ . '/web/insights.php';
    require __DIR__ . '/web/odoo.php';
    require __DIR__ . '/web/profile.php';
    require __DIR__ . '/web/admin.php';
    require __DIR__ . '/web/ai-center.php';
    require __DIR__ . '/web/admin-platform.php';
    require __DIR__ . '/web/modules.php';
    require __DIR__ . '/web/control-plane.php';
});
