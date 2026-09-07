<?php

use App\Http\Controllers\Api\V1Controller;
use App\Http\Middleware\ApiAuth;
use Illuminate\Support\Facades\Route;

/** REST API v1 — Authorization: Bearer <token> · نفس صلاحيات ونطاق الواجهة.
 *  حدُّ المعدل (120/دقيقة لكل مفتاح) كان مُعرَّفاً في AppServiceProvider بلا
 *  مسارٍ يحمله — تعريفٌ بلا تطبيق: عميلٌ جامح كان يضرب بلا سقف. */
// throttle قبل ApiAuth: المحدِّد يفرز بـ bearerToken()/ip() لا بالمستخدم المُحلَّل،
// فبهذا الترتيب يُحَدُّ المهاجمُ غير المُصادَق (رمزٌ خاطئ) قبل أن يقصّه ApiAuth بـ401.
Route::prefix('v1')->middleware(['throttle:api', ApiAuth::class])->group(function () {
    Route::get('me', [V1Controller::class, 'me']);
    Route::get('modules', [V1Controller::class, 'modules']);
    // مواصفةُ OpenAPI مولَّدةٌ من سجل الوحدات — مقصورةً على ما يراه صاحبُ المفتاح (قبل {module} كي لا تبتلعها)
    Route::get('openapi.json', [V1Controller::class, 'openapi']);

    Route::get('reports/progress/{projectId}', [V1Controller::class, 'progress']);
    Route::get('reports/health', [V1Controller::class, 'health']);

    // المقاييس الزمنية: استقبالٌ آلي (n8n) وقراءةُ السلسلة — قبل {module} كي لا تبتلعها
    Route::post('metrics', [V1Controller::class, 'metricsIngest']);
    Route::get('metrics/{module}/{id}', [V1Controller::class, 'metricsShow']);

    // المحلّل الموحّد: «ما هذا المعرّف؟» — بصلاحيات صاحب المفتاح ونطاقه نفسِه
    Route::get('identity/resolve/{q}', [V1Controller::class, 'identityResolve']);

    // تتبّعُ المسار الميدانيّ (الجوال): بدءُ جلسةٍ بموافقة، ثم استيعابُ نقاطٍ دفعيّ
    // منعُ تكرارٍ بنيويّ، ثم إنهاء. الاستيعابُ حركةٌ مكثّفة فله سقفُ نقاطٍ لا معدل
    // عاديّ (كنمط رفع القطع). قبل {module} كي لا يبتلعها.
    Route::post('track/start', [V1Controller::class, 'trackStart']);
    Route::post('track/{session}/points', [V1Controller::class, 'trackIngest']);
    Route::post('track/{session}/end', [V1Controller::class, 'trackEnd']);

    // تسجيلُ النقاط الطرفية (Work OS · WP-J.1): عامٌّ بالرمز المسكوك لا بمفتاح API —
    // فهو **خارج** مجموعة ApiAuth أدناه (يُعرَّف بعد المجموعة بمساره الكامل).
    Route::get('{module}', [V1Controller::class, 'apiIndex']);
    Route::post('{module}', [V1Controller::class, 'apiStore']);
    Route::get('{module}/{id}', [V1Controller::class, 'apiShow']);
    Route::put('{module}/{id}', [V1Controller::class, 'apiUpdate']);
    // تعديلٌ جزئيّ: يكتب الحقول المُرسَلة وحدها — PUT يبقى استبدالاً كاملاً كما هو موثَّق (لا كسر)
    Route::patch('{module}/{id}', [V1Controller::class, 'apiPatch']);
    Route::delete('{module}/{id}', [V1Controller::class, 'apiDestroy']);
});

/*
 * ── النقاط الطرفية (Work OS · الطور J · WP-J.1 · §43) ──
 * `enroll` **عامٌّ عمداً**: الجهازُ الجديد لا يملك جلسةً ولا مفتاحَ API — يصادِقه
 * رمزُ التسجيل المسكوك (sha256، لمرّةٍ، قصيرُ المهلة) وحدَه، والحمولةُ لا تحمل
 * إلا هويّةً ومفتاحاً **عامّاً** (الخاصُّ لا يُرسَل قط — عقدُ Es256). ثلاثةُ
 * أجزاءٍ في المسار فلا يبتلعه `{module}` أعلاه، وحدُّ معدلٍ ضيّقٌ يصدّ التخمين.
 *
 * بقيةُ مسارات الأجهزة (heartbeat/أحداث/أوامر) تهبط في WP-J.2 داخل مجموعةٍ
 * خلف الوسيط المسجَّل `endpoint.signature` (بوّابة العقد قبل أيّ معالج).
 */
Route::post('v1/endpoint/enroll', [\App\Http\Controllers\Api\EndpointEnrollController::class, 'enroll'])
    ->middleware('throttle:20,1')->name('enroll.device');

/*
 * ── بروتوكولُ الجهاز الموقَّع (Work OS · الطور J · WP-J.2 · §43) ──
 * أربعةُ مسارات خلف وسيط `endpoint.signature` (المسجَّل في bootstrap/app.php):
 * عقدُ Es256 كاملاً — طابعٌ ±300ث، nonce فريدٌ لكل جهاز (الإعادةُ 409)، تحقّقٌ
 * بالمفتاح العامّ المخزَّن — **قبل أيّ منطقِ معالج**. لا جلسةَ ولا ApiAuth:
 * التوقيعُ هو المصادقة كلُّها، والجمهورُ أجهزةُ الشركة الداخلية لا حساباتٌ.
 * الخصوصيّةُ تُفرَض في المتحكّم (حقولُ المراقبة 422 مسجَّلةً لا تُخزَّن)،
 * وسحبُ الأوامر ادّعاءٌ ذرّيّ (آلةُ حالة outbox — لا ازدواجَ إرسال).
 */
Route::prefix('v1/endpoint')->middleware(['throttle:120,1', 'endpoint.signature'])->group(function () {
    Route::post('heartbeat', [\App\Http\Controllers\Api\EndpointProtocolController::class, 'heartbeat'])->name('endpoint.heartbeat');
    Route::post('event', [\App\Http\Controllers\Api\EndpointProtocolController::class, 'event'])->name('endpoint.event');
    Route::post('commands/pull', [\App\Http\Controllers\Api\EndpointProtocolController::class, 'commandsPull'])->name('endpoint.commands.pull');
    Route::post('commands/result', [\App\Http\Controllers\Api\EndpointProtocolController::class, 'commandResult'])->name('endpoint.commands.result');
});
