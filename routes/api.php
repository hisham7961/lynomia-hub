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

    // ── بيانُ تحديث الوكيل وتنزيلُه (الطور L · WP-L.2 · §44/§62) ──
    // خلف التوقيع نفسِه — **لا مصادقةَ ثانية ولا public storage**: البيانُ
    // `{url, sha256}` بالشكل الذي يستهلكه agent/internal/update.Apply حرفياً
    // (التجزئةُ الحقيقيةُ المحسوبةُ خادمياً عند النشر — انحرافُها رفضُ تبديلٍ
    // قاطعٌ على الجهاز)، والنطاقُ نظامُ الجهاز المصادَق ومعماريّتُه، والتنزيلُ
    // مُسجَّلٌ في download_log (السكّةُ الواحدة). GET والجسدُ فارغٌ — عقدُ
    // Es256 يوقّع (method, path, ts, nonce, body) فيبقى الطلبُ محكماً.
    Route::get('agent/manifest', [\App\Http\Controllers\Api\EndpointProtocolController::class, 'agentManifest'])->name('endpoint.agent.manifest');
    Route::get('agent/download/{id}', [\App\Http\Controllers\Api\EndpointProtocolController::class, 'agentDownload'])->name('endpoint.agent.download');
});

/*
 * ── سطحُ الجوال الأصيل (Mobile Readiness · الطور B · §109) ──
 * نطاقٌ **مستقلٌّ** عن `/api/v1` (مفتاحُ التكامل) — لا يمسّه ولا يظلّله. جلسةُ
 * الجوال زوجُ رمزَين (وصولٌ قصيرٌ + تحديثٌ متجدّدٌ لمرّة)، مفهومٌ غيرُ `ApiToken`.
 *
 * **الفصلُ إلى مجموعتين (Critic F5):**
 *  • **عامّةٌ** (بلا `mobile.session`): دخولٌ/تحقّقٌ ثنائيٌّ/تحديثٌ/إعداداتٌ/صحّة —
 *    لا رمزَ وصولٍ بعد. لكلٍّ خنقُه الخاصّ الضيّق (لا `throttle:api` الفضفاض
 *    ٣٠٠/دقيقة · F4): دخولٌ `10,1` (نظيرُ الويب web.php:90)، تحقّقٌ ثنائيٌّ
 *    `6,1` (نظيرُ الويب web.php:93)، تحديثٌ `20,1` ضيّق. و`refresh` يصادِق
 *    بـ**رمز التحديث** في معالجه لا عبر `mobile.session` (التي تصادِق رمزَ الوصول).
 *  • **مُصادَقةٌ** خلف `['throttle:api','mobile.session']` (الخنقُ قبل المصادقة،
 *    نمطُ v1 أعلاه): خروجٌ/خروجٌ شامل/إدارةُ الجلسات/تصعيد.
 *
 * المساراتُ الحرفيّةُ كلُّها قبل أيّ catch-all (لا يوجد في الطور B — انضباطٌ
 * محفوظٌ · F9). الأسماء `mobile.auth.*` (والعامّتان الخفيفتان `mobile.*`).
 */
Route::prefix('mobile/v1')->group(function () {
    Route::post('auth/login', [\App\Http\Controllers\Api\MobileAuthController::class, 'login'])
        ->middleware('throttle:10,1')->name('mobile.auth.login');
    Route::post('auth/mfa/verify', [\App\Http\Controllers\Api\MobileAuthController::class, 'mfaVerify'])
        ->middleware('throttle:6,1')->name('mobile.auth.mfa_verify');
    Route::post('auth/refresh', [\App\Http\Controllers\Api\MobileAuthController::class, 'refresh'])
        ->middleware('throttle:20,1')->name('mobile.auth.refresh');
    Route::get('app-config', [\App\Http\Controllers\Api\MobileAuthController::class, 'appConfig'])
        ->middleware('throttle:60,1')->name('mobile.app_config');
    Route::get('health', [\App\Http\Controllers\Api\MobileAuthController::class, 'health'])
        ->middleware('throttle:60,1')->name('mobile.health');

    // ── مواصفةُ OpenAPI للجوال (الطور H · H.2) ──
    // **عامّةٌ عمداً** (لا رمزَ وصول): كي يقرأها التطبيقُ وأدواتُ التوليد قبل الدخول.
    // **مولَّدةٌ من المسارات الحيّة** (`MobileOpenApi::spec`) لا مكتوبةٌ باليد — وثيقةٌ
    // **منفصلةٌ تماماً** عن `/api/v1/openapi.json` (لا تمسّه ولا `docs/openapi.json`).
    // في المجموعةِ العامّةِ (قبل المجموعةِ المُصادَقةِ التي فيها catch-all `{module}`)
    // فلا يبتلعها — نظيرُ انضباطِ `/api/v1` (`api.php:16` قبل `{module}`).
    Route::get('openapi.json', [\App\Http\Controllers\Api\MobileDocsController::class, 'openapi'])
        ->middleware('throttle:60,1')->name('mobile.openapi');

    // ── تفعيلُ حساب العميل (تطبيق العميل · §13) — عامٌّ بخنقٍ ضيّق ──
    // سكّةُ `AccountActivation` الويبية نفسُها (لا محرّكَ ثانٍ)؛ الخطوتان مدموجتان
    // ذرّيّاً لغياب جلسة الويب. المجهولُ/المُستهلَك 404 (لا كشفَ وجود).
    Route::get('activation/{token}', [\App\Http\Controllers\Api\MobileActivationController::class, 'show'])
        ->middleware('throttle:12,1')->name('mobile.activation.show');
    Route::post('activation/{token}/complete', [\App\Http\Controllers\Api\MobileActivationController::class, 'complete'])
        ->middleware('throttle:6,1')->name('mobile.activation.complete');
});

// المجموعةُ المُصادَقة: الخنقُ قبل المصادقة (نمطُ v1)، ثم `mobile.session` (تُرسي
// الهويّة)، ثم `mobile.context` (تحلّ X-Lynomia-Company/-Client تضييقاً للعرض لا
// تخويلاً · SF-4 · C). الترتيبُ مقصود: السياقُ يقرأ المستخدمَ الذي أرسته الجلسة.
// `mobile.portal` (سياجُ حساب العميل — قائمةٌ بيضاءُ فوق المصفوفة، نظيرُ PortalGuard
// الويبيّ) بعد `mobile.session` (يحتاج الهويّةَ) وقبل `mobile.context` والمتحكّمات.
Route::prefix('mobile/v1')->middleware(['throttle:api', 'mobile.session', 'mobile.portal', 'mobile.context'])->group(function () {
    Route::post('auth/logout', [\App\Http\Controllers\Api\MobileAuthController::class, 'logout'])->name('mobile.auth.logout');
    Route::post('auth/logout-all', [\App\Http\Controllers\Api\MobileAuthController::class, 'logoutAll'])->name('mobile.auth.logout_all');
    Route::get('auth/sessions', [\App\Http\Controllers\Api\MobileAuthController::class, 'sessions'])->name('mobile.auth.sessions.index');
    Route::delete('auth/sessions/{id}', [\App\Http\Controllers\Api\MobileAuthController::class, 'destroySession'])->name('mobile.auth.sessions.destroy');
    Route::post('auth/step-up', [\App\Http\Controllers\Api\MobileAuthController::class, 'stepUp'])->name('mobile.auth.step_up');

    /*
     * ── السياقُ + الإقلاعُ + المخطّط (Mobile Readiness · الطور C · §109) ──
     * قراءةٌ فقط: C.1 السياق (شركاتُ/عملاءُ المستخدم + التضييقُ النشط)، C.2 الإقلاعُ
     * المبصوم (لقطةُ إقلاعٍ باردةٍ · ETag/304)، C.4 المخطّطُ المُنطَّق (بلا اسمِ
     * جدولٍ/عمودٍ فيزيائيّ · ETag/304). **مساراتٌ حرفيّةٌ كلُّها — تُسجَّل قبل أيّ
     * catch-all (`{module}`) يأتي في الطور D كي لا يبتلعها (Critic F9).** الأخصُّ
     * (`schema/modules`) قبل الأعمّ (`schema`) انضباطاً.
     */
    Route::get('context', [\App\Http\Controllers\Api\MobileContextController::class, 'context'])->name('mobile.context');
    Route::get('bootstrap', [\App\Http\Controllers\Api\MobileContextController::class, 'bootstrap'])->name('mobile.bootstrap');
    Route::get('schema/modules', [\App\Http\Controllers\Api\MobileContextController::class, 'schemaModules'])->name('mobile.schema.modules');
    Route::get('schema', [\App\Http\Controllers\Api\MobileContextController::class, 'schema'])->name('mobile.schema');
    // الطور 9 · تنقّلُ IA المُنطَّق (نفسُ معماريةِ الويب — لا mobile_nav ثانٍ)
    Route::get('navigation', [\App\Http\Controllers\Api\MobileContextController::class, 'navigation'])->name('mobile.navigation');

    /*
     * ── تكافؤُ واجهةِ الأعمال (Mobile Readiness · الطور D · §109) ──
     *
     * **ترتيبُ التسجيلُ عقدٌ أمنيّ (Critic F9):** كلُّ المسارات الحرفيّة
     * (اعتمادات/لوحة/بحث/تفضيلات + لاحقةُ `/actions` على المورد) تُسجَّل **قبل**
     * الـcatch-all `{module}` (CRUD) الذي يأتي **أخيراً** — وإلّا ابتلعها `{module}`
     * (`GET approvals` يُحلّ إلى `apiIndex('approvals')`, و`GET approvals/{id}` إلى
     * `show`, …). نظيرُ انضباطِ `/api/v1` (`api.php:15,21,26` قبل `{module}`).
     * التوجيهُ «أوّلُ مطابقٍ يفوز»، فالحرفيُّ الأخصُّ يسبق العامَّ الأعمَّ.
     *
     * والهيكلُ يملؤه بناةُ D.1-D.7 اللاحقون؛ الأساسُ المشترك (الجواهرُ + مالكُ
     * الـIdempotency + ApprovalService + محرّكُ البحث) جاهزٌ في هذه الدفعة.
     */

    // D.4 — الاعتمادات (حرفيّةٌ: `approvals` قبل `{module}` كي لا يُحلّ إلى apiIndex)
    Route::get('approvals', [\App\Http\Controllers\Api\MobileWorkController::class, 'approvals'])->name('mobile.approvals.index');
    Route::get('approvals/{id}', [\App\Http\Controllers\Api\MobileWorkController::class, 'approvalShow'])->name('mobile.approvals.show');
    Route::post('approvals/{id}/approve', [\App\Http\Controllers\Api\MobileWorkController::class, 'approvalApprove'])->name('mobile.approvals.approve');
    Route::post('approvals/{id}/reject', [\App\Http\Controllers\Api\MobileWorkController::class, 'approvalReject'])->name('mobile.approvals.reject');

    // D.5 — اللوحة · D.6 — البحث · D.7 — التفضيلات (حرفيّةٌ أُحاديّةُ المقطع قبل `{module}`)
    Route::get('home', [\App\Http\Controllers\Api\MobileWorkController::class, 'home'])->name('mobile.home');
    Route::get('search', [\App\Http\Controllers\Api\MobileWorkController::class, 'search'])->name('mobile.search');
    Route::get('prefs', [\App\Http\Controllers\Api\MobileWorkController::class, 'prefs'])->name('mobile.prefs.index');
    Route::put('prefs', [\App\Http\Controllers\Api\MobileWorkController::class, 'prefsUpdate'])->name('mobile.prefs.update');
    Route::post('prefs/pin', [\App\Http\Controllers\Api\MobileWorkController::class, 'pin'])->name('mobile.prefs.pin');

    // ── بوّابةُ العميل (تطبيق العميل · §12/§18) — حرفيّةُ `portal/*` قبل الـcatch-all ──
    // لحسابات العملاء حصراً (mobile.portal يردّ الداخليَّ 403 — له لوحتُه)؛ القرّاءُ
    // مشترَكون مع الويب (`ClientPortalData`) بفشلٍ مغلقٍ وأعمدةٍ عميليّةٍ حصراً.
    Route::get('portal/home', [\App\Http\Controllers\Api\MobileClientPortalController::class, 'home'])->name('mobile.portal.home');
    Route::get('portal/engagements', [\App\Http\Controllers\Api\MobileClientPortalController::class, 'engagements'])->name('mobile.portal.engagements');
    Route::get('portal/projects', [\App\Http\Controllers\Api\MobileClientPortalController::class, 'projects'])->name('mobile.portal.projects.index');
    Route::get('portal/projects/{id}', [\App\Http\Controllers\Api\MobileClientPortalController::class, 'project'])->name('mobile.portal.projects.show');
    Route::get('portal/documents', [\App\Http\Controllers\Api\MobileClientPortalController::class, 'documents'])->name('mobile.portal.documents.index');
    Route::get('portal/documents/{id}', [\App\Http\Controllers\Api\MobileClientPortalController::class, 'document'])->name('mobile.portal.documents.show');
    Route::get('portal/invoices', [\App\Http\Controllers\Api\MobileClientPortalController::class, 'invoices'])->name('mobile.portal.invoices.index');
    Route::get('portal/invoices/{id}', [\App\Http\Controllers\Api\MobileClientPortalController::class, 'invoice'])->name('mobile.portal.invoices.show');
    Route::get('portal/conversations', [\App\Http\Controllers\Api\MobileClientPortalController::class, 'conversations'])->name('mobile.portal.conversations.index');
    Route::get('portal/conversations/{id}', [\App\Http\Controllers\Api\MobileClientPortalController::class, 'conversation'])->name('mobile.portal.conversations.show');

    // ── إدارةُ أعضاء العميل (§15) — لوحةُ المدير الداخليّ (العميلُ محجوبٌ بالسياج) ──
    // حرفيّةُ `clients/{client}/members*` قبل الـcatch-all؛ owner/سحب خلف تصعيد الجوال.
    Route::get('clients/{client}/members', [\App\Http\Controllers\Api\MobileClientMembersController::class, 'index'])->name('mobile.clients.members.index');
    Route::post('clients/{client}/members', [\App\Http\Controllers\Api\MobileClientMembersController::class, 'invite'])->name('mobile.clients.members.invite');
    Route::put('clients/{client}/members/{membership}', [\App\Http\Controllers\Api\MobileClientMembersController::class, 'setRole'])->name('mobile.clients.members.role');
    Route::delete('clients/{client}/members/{membership}', [\App\Http\Controllers\Api\MobileClientMembersController::class, 'revoke'])->name('mobile.clients.members.revoke');

    /*
     * ── الاتصال (Mobile Readiness · الطور E · §109) ──
     *
     * **ترتيبُ التسجيلِ عقدٌ أمنيّ (Critic F9):** كلُّ حرفيّاتِ الطور E
     * (إشعارات/تعليقات/DM/دفع) تُسجَّل **قبل** الـcatch-all `{module}` أدناه — وإلّا
     * ابتلعها (`GET notifications` ⇒ `apiIndex('notifications')`, و`GET
     * notifications/unread-count` ⇒ `{module}/{id}`, و`POST push/register` ⇒
     * `{module}/{id}`, …). الهيكلُ يملؤه بناةُ E.1–E.5؛ الأساسُ المشترك
     * (`NotificationLink`/`CommentService`/`DmService`/`PushService`) جاهزٌ في هذه الدفعة.
     */

    // E.1/E.2 — الإشعارات (هويّةٌ خاصّةٌ فقط) + وجهةُ الرابطِ العميق
    Route::get('notifications', [\App\Http\Controllers\Api\MobileCommController::class, 'notifications'])->name('mobile.notifications.index');
    Route::get('notifications/unread-count', [\App\Http\Controllers\Api\MobileCommController::class, 'unreadCount'])->name('mobile.notifications.unread');
    Route::post('notifications/read-all', [\App\Http\Controllers\Api\MobileCommController::class, 'markAllRead'])->name('mobile.notifications.read_all');
    Route::get('notifications/{id}/target', [\App\Http\Controllers\Api\MobileCommController::class, 'notificationTarget'])->name('mobile.notifications.target');
    Route::post('notifications/{id}/read', [\App\Http\Controllers\Api\MobileCommController::class, 'markRead'])->name('mobile.notifications.read');

    // E.3 — التعليقات (reuse CommentService::guardTarget — نقطةُ التخويلِ الوحيدة · F2)
    Route::get('comments', [\App\Http\Controllers\Api\MobileCommController::class, 'comments'])->name('mobile.comments.index');
    Route::post('comments', [\App\Http\Controllers\Api\MobileCommController::class, 'postComment'])->name('mobile.comments.store');

    // E.4 — DM (خصوصيّةُ الطرفَين · F8: `{user}` = الطرفُ الآخر، المفتاحُ من auth لا العميل)
    Route::get('dm/threads', [\App\Http\Controllers\Api\MobileCommController::class, 'dmThreads'])->name('mobile.dm.threads');
    Route::get('dm/threads/{user}/messages', [\App\Http\Controllers\Api\MobileCommController::class, 'dmMessages'])->name('mobile.dm.messages');
    Route::post('dm/threads/{user}/send', [\App\Http\Controllers\Api\MobileCommController::class, 'dmSend'])->name('mobile.dm.send');
    Route::post('dm/threads/{user}/read', [\App\Http\Controllers\Api\MobileCommController::class, 'dmMarkRead'])->name('mobile.dm.read');

    // E.5 — تسجيلُ الدفع (dedupe عابرُ المستخدمين · F7)
    Route::post('push/register', [\App\Http\Controllers\Api\MobilePushController::class, 'register'])->name('mobile.push.register');
    Route::post('push/unregister', [\App\Http\Controllers\Api\MobilePushController::class, 'unregister'])->name('mobile.push.unregister');

    // E.7 — إدارةُ الدفع (للمالكِ وحدَه · صادقةٌ بلا سرّ): حالةٌ + اختبارٌ آمنٌ يقول
    // NOT_CONFIGURED حين لا اعتمادات. حرفيّةٌ ثلاثيّةُ المقطع (`push/admin/*`) — لا
    // يبتلعها `{module}/{id}` (مقطعان) ولا `{module}/{id}/actions` (المقطعُ الثالثُ
    // حرفيٌّ `actions`)؛ ومع ذلك تُسجَّل قبل الـcatch-all انضباطاً (F9).
    Route::get('push/admin/status', [\App\Http\Controllers\Api\MobilePushController::class, 'adminStatus'])->name('mobile.push.admin.status');
    Route::post('push/admin/test', [\App\Http\Controllers\Api\MobilePushController::class, 'adminTest'])->name('mobile.push.admin.test');

    /*
     * ── تكافؤُ التعاون (المرحلة ٩ · §106 · إضافيّ) ──
     *
     * قدراتُ مركزِ التواصلِ الجديدةُ على الجوال فوق السككِ نفسِها (`CollaborationRail`/
     * `Collaboration`/`Presence`/`Typing`/`DmService`) — لا محرّكٌ ثانٍ. **حرفيّةٌ كلُّها
     * (`conversations`/`presence`/`saved`/لاحقاتُ since/typing/react) تُسجَّل قبل الـ
     * catch-all `{module}` أدناه (Critic F9)** كي لا يبتلعها. العزلُ خادميٌّ في المتحكّم
     * (guardConversation/dmReachable)؛ المركزُ داخليٌّ فالعميلُ يُطوى ٤٠٤ (له `portal/*`).
     */
    Route::get('conversations', [\App\Http\Controllers\Api\MobileCollabController::class, 'conversations'])->name('mobile.conversations.index');
    Route::get('conversations/{id}/since', [\App\Http\Controllers\Api\MobileCollabController::class, 'channelSince'])->name('mobile.conversations.since');
    Route::post('conversations/{id}/typing', [\App\Http\Controllers\Api\MobileCollabController::class, 'channelTyping'])->name('mobile.conversations.typing');
    Route::get('dm/threads/{user}/since', [\App\Http\Controllers\Api\MobileCollabController::class, 'dmSince'])->name('mobile.dm.since');
    Route::post('dm/threads/{user}/typing', [\App\Http\Controllers\Api\MobileCollabController::class, 'dmTyping'])->name('mobile.dm.typing');
    Route::post('dm/messages/{id}/react', [\App\Http\Controllers\Api\MobileCollabController::class, 'dmReact'])->name('mobile.dm.react');
    Route::post('comments/{id}/react', [\App\Http\Controllers\Api\MobileCollabController::class, 'commentReact'])->name('mobile.comments.react');
    Route::get('presence', [\App\Http\Controllers\Api\MobileCollabController::class, 'presence'])->name('mobile.presence');
    Route::get('saved', [\App\Http\Controllers\Api\MobileCollabController::class, 'saved'])->name('mobile.saved.index');

    /*
     * ── ملفّاتٌ + ماسحٌ + موقع (Mobile Readiness · الطور F · §109) ──
     *
     * **ترتيبُ التسجيلِ عقدٌ أمنيّ (Critic F9):** كلُّ حرفيّاتِ الطور F
     * (files/identity/tracking) تُسجَّل **قبل** الـcatch-all `{module}` أدناه — وإلّا
     * ابتلعها (`GET files/x/download` سليمٌ لأنّ المقطعَ الثالثَ حرفيٌّ، لكنّ الانضباطَ
     * يُبقيها أوّلاً؛ و`POST files/attach` قد يلتبس، و`identity`/`tracking` أحاديّاتُ
     * المقطع يبتلعها `GET/POST {module}`). نظيرُ انضباطِ `/api/v1` (`api.php:26,31-33`
     * حيث identity/track قبل `{module}`).
     *
     * **هيكلٌ في دفعةِ الأساس:** المعالجاتُ في `MobileFileController` موصَّفةٌ بخطّةِ
     * إعادةِ الاستعمال (AttachmentService · ChunkedUpload · parent::identityResolve ·
     * parent::track*) وتُملأ في دفعةِ التنفيذ. الجوهرُ المشترك (`AttachmentService`)
     * والقرارُ (إعادةُ استعمالِ `ChunkedUpload` بلا جدولٍ جديد) جاهزان في هذه الدفعة.
     */

    // F.1 — الرفعُ المقطَّع (جلسة ← قطعة ← إتمام) + الرفعُ المفرد. حرفيّاتٌ:
    // `files/upload-session*` و`files/attach` قبل الـcatch-all (F9).
    Route::post('files/upload-session', [\App\Http\Controllers\Api\MobileFileController::class, 'uploadSession'])->name('mobile.files.upload_session');
    Route::put('files/upload-session/{id}/chunk', [\App\Http\Controllers\Api\MobileFileController::class, 'uploadChunk'])->name('mobile.files.upload_chunk');
    Route::post('files/upload-session/{id}/complete', [\App\Http\Controllers\Api\MobileFileController::class, 'uploadComplete'])->name('mobile.files.upload_complete');
    Route::post('files/attach', [\App\Http\Controllers\Api\MobileFileController::class, 'attach'])->name('mobile.files.attach');

    // F.2 — التنزيل/البثّ المُصادَق (`files/{id}/download|stream` · مقطعٌ ثالثٌ حرفيّ
    // يميّزها عن `{module}/{id}/actions`، ومع ذلك أوّلاً انضباطاً · F9). لا رابطٌ عامّ.
    Route::get('files/{id}/download', [\App\Http\Controllers\Api\MobileFileController::class, 'download'])->name('mobile.files.download');
    Route::get('files/{id}/stream', [\App\Http\Controllers\Api\MobileFileController::class, 'stream'])->name('mobile.files.stream');

    // F.3 — الماسح: المحلّلُ الموحّد (reuse V1Controller::identityResolve). حرفيّةٌ
    // `identity/resolve/{q}` — لا يبتلعها `{module}/{id}` (المقطعُ الأوّلُ حرفيٌّ).
    Route::get('identity/resolve/{q}', [\App\Http\Controllers\Api\MobileFileController::class, 'identityResolve'])
        ->name('mobile.identity.resolve');   // مقطعٌ مفردٌ كنظيرِ v1 (api.php:26) — لا `.*`

    // F.4 — الموقع: تتبّعٌ بموافقةٍ صريحة (reuse V1Controller::trackStart/Ingest/End).
    // حرفيّاتٌ `tracking/*` قبل الـcatch-all (F9) — لا تتبّعٌ خفيٌّ دائم.
    Route::post('tracking/start', [\App\Http\Controllers\Api\MobileFileController::class, 'trackingStart'])->name('mobile.tracking.start');
    Route::post('tracking/{session}/points', [\App\Http\Controllers\Api\MobileFileController::class, 'trackingPoints'])->name('mobile.tracking.points');
    Route::post('tracking/{session}/end', [\App\Http\Controllers\Api\MobileFileController::class, 'trackingEnd'])->name('mobile.tracking.end');

    /*
     * ── المزامنة/الصمود (Mobile Readiness · الطور G · §109) ──
     *
     * **`GET sync/{module}`** — مزامنةٌ تزايُديّةٌ يقودها تصنيفُ الوحدة
     * (`hub_sync_class`): CACHEABLE_INCREMENTAL/READ_ONLY تبثّ سجلّاتٍ مُقنَّعةً +
     * شواهدَ حذفٍ بمؤشّرٍ حتميّ (updated_at,id)؛ والباقي (ONLINE_ONLY/
     * SENSITIVE_NO_PERSIST/NOT_APPLICABLE) يعيد سياسةً صادقةً بلا سجلّات.
     *
     * **ترتيبُ التسجيلِ عقدٌ أمنيّ (Critic F9):** `sync/{module}` (مقطعان، الأوّلُ
     * حرفيٌّ `sync`) تُسجَّل **قبل** الـcatch-all `GET {module}/{id}` (مقطعان كلاهما
     * وسيط) — وإلّا حلَّ `GET sync/tickets` إلى `showRecord('sync','tickets')`. نظيرُ
     * انضباطِ `/api/v1` (الحرفيُّ قبل `{module}`). التنطيقُ/التعارُض/الـIdempotency
     * (G.2/G.3/G.4) في المحرّكِ المُعادِ استعمالُه لا في مسارٍ ثانٍ.
     */
    Route::get('sync/{module}', [\App\Http\Controllers\Api\MobileSyncController::class, 'sync'])->name('mobile.sync');

    // D.2/D.3 — إجراءاتُ المورد: لاحقةُ `/actions` **قبل** `{module}/{id}` (المقطعُ
    // الحرفيّ `actions` يميّزها، ومع ذلك تُسجَّل أوّلاً انضباطاً · F9)
    Route::get('{module}/{id}/actions', [\App\Http\Controllers\Api\MobileResourceController::class, 'listActions'])->name('mobile.resource.actions');
    Route::post('{module}/{id}/actions/{action}', [\App\Http\Controllers\Api\MobileResourceController::class, 'runAction'])->name('mobile.resource.run_action');

    // D.1 — الـcatch-all العامّ (CRUD) **أخيراً** بعد كلِّ حرفيّ (F9)
    Route::get('{module}', [\App\Http\Controllers\Api\MobileResourceController::class, 'listRecords'])->name('mobile.resource.index');
    Route::post('{module}', [\App\Http\Controllers\Api\MobileResourceController::class, 'createRecord'])->name('mobile.resource.store');
    Route::get('{module}/{id}', [\App\Http\Controllers\Api\MobileResourceController::class, 'showRecord'])->name('mobile.resource.show');
    Route::put('{module}/{id}', [\App\Http\Controllers\Api\MobileResourceController::class, 'replaceRecord'])->name('mobile.resource.update');
    Route::patch('{module}/{id}', [\App\Http\Controllers\Api\MobileResourceController::class, 'patchRecord'])->name('mobile.resource.patch');
    Route::delete('{module}/{id}', [\App\Http\Controllers\Api\MobileResourceController::class, 'deleteRecord'])->name('mobile.resource.destroy');
});
