<?php

use App\Http\Controllers\Web\AlertController;
use App\Http\Controllers\Web\AppCenterController;
use App\Http\Controllers\Web\ApprovalDecisionController;
use App\Http\Controllers\Web\AttachmentController;
use App\Http\Controllers\Web\DmController;
use App\Http\Controllers\Web\JourneyController;
use App\Http\Controllers\Web\PrefController;
use App\Http\Controllers\Web\TraceController;
use App\Http\Controllers\Web\AuditController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\CapacityController;
use App\Http\Controllers\Web\CeoController;
use App\Http\Controllers\Web\CommentController;
use App\Http\Controllers\Web\CostController;
use App\Http\Controllers\Web\CustomFieldController;
use App\Http\Controllers\Web\BoardController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DataRoomController;
use App\Http\Controllers\Web\ErrorCenterController;
use App\Http\Controllers\Web\OpsController;
use App\Http\Controllers\Web\FileController;
use App\Http\Controllers\Web\FlowController;
use App\Http\Controllers\Web\ImportController;
use App\Http\Controllers\Web\InboxDocController;
use App\Http\Controllers\Web\ModuleController;
use App\Http\Controllers\Web\MorningController;
use App\Http\Controllers\Web\MySecurityController;
use App\Http\Controllers\Web\StepUpController;
use App\Http\Controllers\Web\NotificationController;
use App\Http\Controllers\Web\OdooController;
use App\Http\Controllers\Web\PerformanceController;
use App\Http\Controllers\Web\PortalController;
use App\Http\Controllers\Web\LegalController;
use App\Http\Controllers\Web\ProfileController;
use App\Http\Controllers\Web\PurchaseController;
use App\Http\Controllers\Web\PwaController;
use App\Http\Controllers\Web\QualityController;
use App\Http\Controllers\Web\QuoteBuilderController;
use App\Http\Controllers\Web\QuoteController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\RoleController;
use App\Http\Controllers\Web\SearchController;
use App\Http\Controllers\Web\SecurityController;
use App\Http\Controllers\Web\StaffController;
use App\Http\Controllers\Web\SupportController;
use App\Http\Controllers\Web\QuoteFlowController;
use App\Http\Controllers\Web\SettingController;
use App\Http\Controllers\Web\UserController;
use App\Http\Controllers\Web\WebhookController;
use Illuminate\Support\Facades\Route;

// ── الوجه العام لغرفة البيانات (بلا تسجيل دخول — الرمز هو المفتاح) ──
Route::get('s/{token}', [DataRoomController::class, 'show'])->name('share.show');
Route::post('s/{token}', [DataRoomController::class, 'unlock'])->name('share.unlock')->middleware('throttle:10,1');
Route::get('s/{token}/file', [DataRoomController::class, 'file'])->name('share.file');

// ── فحص صحي عام لمراقبات Uptime (بلا تسجيل دخول) ──
Route::get('healthz', [OpsController::class, 'health'])->name('healthz')->middleware('throttle:30,1');

// ── PWA: بيان وأيقونة وصفحة بلا اتصال (عامة) ──
Route::get('manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('pwa-icon.svg', [PwaController::class, 'icon'])->name('pwa.icon');
Route::get('offline', [PwaController::class, 'offline'])->name('pwa.offline');

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'show'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->name('login.attempt')
        ->middleware('throttle:10,1');   // حد ١٠ محاولات بالدقيقة من نفس العنوان — يكمل قفل الحساب الموجود
    Route::get('login/otp', [AuthController::class, 'otpShow'])->name('login.otp');
    Route::post('login/otp', [AuthController::class, 'otpVerify'])->name('login.otp.verify')
        ->middleware('throttle:6,1');
});

// التوقيع الإلكتروني — الجهة العامة: العميل بلا حساب، برابط خاص وكلمة سر
// الويبهوك الوارد — سطحٌ عامٌّ يُصادَق بالرمز في الرابط + توقيع HMAC اختياريّ
Route::post('hook/{token}', [\App\Http\Controllers\Web\InboundHookController::class, 'receive'])
    ->name('hook.receive')->middleware('throttle:120,1');

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
    Route::get('files/{path}', [FileController::class, 'show'])->name('file.show')->where('path', 'hub/.*');

    // ── محوّل الشركة النشطة (تصفية القوائم) ──
    Route::post('company-switch', function (\Illuminate\Http\Request $r) {
        $cid = (string) $r->input('company', '');
        if ($cid !== '') {
            abort_unless(hub_can(auth()->user(), 'companies', 'v'), 403);
            $allowed = hub_company_ids();
            abort_if($allowed !== null && ! in_array($cid, $allowed, true), 403, 'هذه الشركة خارج نطاقك');
            abort_unless(\App\Models\Company::whereNull('deleted_at')->whereKey($cid)->exists(), 404);
        }
        session(['hub.company' => $cid]);

        return back()->with('ok', $cid === '' ? 'عدت لعرض كل الشركات' : 'تُصفّى القوائم الآن على الشركة المختارة');
    })->name('company.switch');

    // ── مبدّل مساحة عمل العميل: الوحداتُ نفسُها تعمل داخلياً أو لعميلٍ محدد ──
    // نظيرُ مبدّل الشركة حرفياً: بوابةُ صلاحية، فعزلٌ صارم، فوجودٌ فعلي.
    Route::post('client-switch', function (\Illuminate\Http\Request $r) {
        $kid = (string) $r->input('client', '');
        if ($kid !== '') {
            abort_unless(hub_can(auth()->user(), 'clients', 'v'), 403);
            $allowed = hub_client_ids();
            abort_if($allowed !== null && ! in_array($kid, $allowed, true), 403, 'هذا العميل خارج نطاقك');
            abort_unless(\App\Models\Client::whereNull('deleted_at')->whereKey($kid)->exists(), 404);
        }
        session(['hub.client' => $kid]);

        return back()->with('ok', $kid === '' ? 'عدت للمساحة الداخلية — كل السجلات' : 'تعمل الآن في مساحة العميل المختار');
    })->name('client.switch');

    // ── يوم العمل: حضورٌ وانصرافٌ بضغطة، وشاشةُ الفريق اليومية للمدير ──
    Route::post('workday/check-in', [\App\Http\Controllers\Web\WorkdayController::class, 'checkIn'])
        ->middleware('throttle:30,1')->name('workday.in');
    Route::post('workday/check-out', [\App\Http\Controllers\Web\WorkdayController::class, 'checkOut'])
        ->middleware('throttle:30,1')->name('workday.out');
    Route::get('workforce', [\App\Http\Controllers\Web\WorkdayController::class, 'team'])->name('workforce.team');

    // العرض الميدانيّ للمشرف: لوحةٌ تحليلية، وجلساتُ التتبّع، وإعادةُ عرض المسار
    Route::get('field', [\App\Http\Controllers\Web\FieldController::class, 'dashboard'])->name('field.dashboard');
    Route::get('field/sessions', [\App\Http\Controllers\Web\FieldController::class, 'index'])->name('field.sessions');
    Route::get('field/route/{id}', [\App\Http\Controllers\Web\FieldController::class, 'route'])->name('field.route');

    Route::get('morning', [MorningController::class, 'index'])->name('morning');
    Route::get('calendar', [\App\Http\Controllers\Web\CalendarController::class, 'index'])->name('calendar');
    Route::get('costs', [CostController::class, 'index'])->name('costs.index');
    Route::get('service-costs', [CostController::class, 'services'])->name('servicecosts');
    Route::get('capacity', [CapacityController::class, 'index'])->name('capacity');
    Route::get('impact', [CapacityController::class, 'impact'])->name('impact');
    Route::get('app-quality', [CapacityController::class, 'quality'])->name('appquality');
    Route::get('team', [\App\Http\Controllers\Web\TeamController::class, 'index'])->name('team');
    Route::get('media-center', [\App\Http\Controllers\Web\MediaCenterController::class, 'index'])->name('media.center');
    Route::get('pricing', [\App\Http\Controllers\Web\PricingController::class, 'index'])->name('pricing');
    Route::get('digital-assets', [\App\Http\Controllers\Web\DigitalAssetsController::class, 'index'])->name('digital.assets');
    Route::get('recommendations', [CapacityController::class, 'recommendations'])->name('recs');
    Route::get('delivery', [\App\Http\Controllers\Web\DeliveryController::class, 'index'])->name('delivery');
    Route::get('assets-life', [\App\Http\Controllers\Web\AssetLifeController::class, 'index'])->name('assets.life');
    // مركزُ الكود المصدري: صفحةُ إصداراتٍ على شاكلة ما يعرفه المطوّرون
    Route::get('code-center', [\App\Http\Controllers\Web\CodeCenterController::class, 'index'])->name('code.center');

    // مسحُ ملصق العهدة: مسارٌ قصيرٌ عمداً (`/c/{code}`) — رمزُ QR على ملصقٍ
    // ٤٠×٣٠ مم لا يتّسع لرابطٍ فيه معرّفٌ عشوائيّ بستٍّ وثلاثين خانة: كثافةُ
    // الرمز ترتفع فلا يقرؤه ماسحٌ حراريٌّ ولا هاتفٌ في إضاءةٍ ضعيفة.
    Route::get('c/{code}', [\App\Http\Controllers\Web\CustodyController::class, 'byCode'])->name('custody.code');

    // ── قسم العهد: كتالوجٌ بالأصناف وأكوادها، وملصقٌ وبطاقةٌ وتصاريحُ نقلٍ وخروج ──
    Route::prefix('custody')->name('custody.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Web\CustodyController::class, 'catalog'])->name('catalog');
        Route::get('cat/{code}', [\App\Http\Controllers\Web\CustodyController::class, 'category'])->name('category');
        Route::get('{id}/label', [\App\Http\Controllers\Web\CustodyController::class, 'label'])->name('label');
        Route::get('{id}/spec', [\App\Http\Controllers\Web\CustodyController::class, 'spec'])->name('spec');
        Route::post('{id}/specs', [\App\Http\Controllers\Web\CustodyController::class, 'saveSpecs'])->name('specs');
        Route::post('{id}/handover', [\App\Http\Controllers\Web\CustodyController::class, 'handover'])->name('handover');
        Route::post('{id}/recover', [\App\Http\Controllers\Web\CustodyController::class, 'recover'])->name('recover');
        Route::post('{id}/permit', [\App\Http\Controllers\Web\CustodyController::class, 'permit'])->name('permit');
        Route::get('{id}/permit/{permitId}', [\App\Http\Controllers\Web\CustodyController::class, 'permitDoc'])->name('permit.doc');
        Route::post('{id}/permit/{permitId}/return', [\App\Http\Controllers\Web\CustodyController::class, 'permitReturn'])->name('permit.return');
        Route::post('{id}/permit/{permitId}/cancel', [\App\Http\Controllers\Web\CustodyController::class, 'permitCancel'])->name('permit.cancel');
    });

    // مسحُ ملصق منتجٍ (p/{code}) — نظيرُ c/{code} للعهدة: كودٌ ← سجلُّ طرازه
    Route::get('p/{code}', [\App\Http\Controllers\Web\IdentityController::class, 'byCode'])->name('products.code');

    // ── مركز الهوية: محلّلٌ موحّد، واستكشافٌ خارجي، وتسجيلٌ بالمسح في معاملةٍ واحدة ──
    Route::prefix('identity')->name('identity.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Web\IdentityController::class, 'center'])->name('center');
        // الحسمُ الداخلي رخيصٌ ومتكرر (كل مسحة)، والاستكشافُ نداءاتٌ خارجية تُقنَّن
        Route::get('resolve', [\App\Http\Controllers\Web\IdentityController::class, 'resolve'])
            ->middleware('throttle:240,1')->name('resolve');
        Route::post('discover', [\App\Http\Controllers\Web\IdentityController::class, 'discover'])
            ->middleware('throttle:30,1')->name('discover');
        Route::post('register', [\App\Http\Controllers\Web\IdentityController::class, 'register'])->name('register');
        Route::post('merge/{id}', [\App\Http\Controllers\Web\IdentityController::class, 'merge'])->name('merge');
        Route::get('product/{id}/label', [\App\Http\Controllers\Web\IdentityController::class, 'productLabel'])->name('product.label');
        Route::get('labels', [\App\Http\Controllers\Web\IdentityController::class, 'labels'])->name('labels');
    });
    Route::get('compliance-board', [\App\Http\Controllers\Web\ComplianceController::class, 'index'])->name('compliance.board');
    Route::get('apps-projects', [\App\Http\Controllers\Web\AppsProjectsController::class, 'index'])->name('appsprojects');
    Route::post('apps-projects/fix', [\App\Http\Controllers\Web\AppsProjectsController::class, 'fix'])->name('appsprojects.fix');
    Route::get('kpis', [\App\Http\Controllers\Web\KpiController::class, 'index'])->name('kpis.index');
    Route::post('kpis', [\App\Http\Controllers\Web\KpiController::class, 'store'])->name('kpis.store');
    Route::put('kpis/{id}', [\App\Http\Controllers\Web\KpiController::class, 'update'])->name('kpis.update');
    Route::post('kpis/{id}/toggle', [\App\Http\Controllers\Web\KpiController::class, 'toggle'])->name('kpis.toggle');
    Route::post('kpis/{id}/move', [\App\Http\Controllers\Web\KpiController::class, 'move'])->name('kpis.move');
    Route::delete('kpis/{id}', [\App\Http\Controllers\Web\KpiController::class, 'destroy'])->name('kpis.destroy');

    // ── صندوق الوثائق الوارد ──
    Route::get('inboxdocs', [InboxDocController::class, 'index'])->name('inboxdocs.index');
    Route::post('inboxdocs', [InboxDocController::class, 'store'])->name('inboxdocs.store');
    Route::post('inboxdocs/{id}/classify', [InboxDocController::class, 'classify'])->name('inboxdocs.classify');
    Route::delete('inboxdocs/{id}', [InboxDocController::class, 'destroy'])->name('inboxdocs.destroy');

    // ── الموظفون وحساباتهم: طرفان لشخصٍ واحد ──
    Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
    Route::post('staff/{id}/link', [StaffController::class, 'link'])->name('staff.link');
    Route::post('staff/{id}/unlink', [StaffController::class, 'unlink'])->name('staff.unlink');
    Route::post('staff/{id}/account', [StaffController::class, 'account'])->name('staff.account');
    Route::post('staff/{id}/align', [StaffController::class, 'align'])->name('staff.align');
    Route::post('staff/user/{id}/file', [StaffController::class, 'file'])->name('staff.file');

    // ── غرفة البيانات (الإدارة) ──
    Route::get('dataroom', [DataRoomController::class, 'index'])->name('dataroom.index');
    Route::post('dataroom', [DataRoomController::class, 'store'])->name('dataroom.store');
    Route::post('dataroom/{id}/revoke', [DataRoomController::class, 'revoke'])->name('dataroom.revoke');

    // ── حسم الموافقات المُلزِمة ──
    Route::post('approvals/{id}/approve', [ApprovalDecisionController::class, 'approve'])->name('approvals.approve');
    Route::post('approvals/{id}/reject', [ApprovalDecisionController::class, 'reject'])->name('approvals.reject');

    // ── الإقرار الموثَّق على السجلات (محضر · عهدة · قرار) ──
    Route::post('acks/{module}/{id}', [\App\Http\Controllers\Web\AckController::class, 'store'])->name('acks.store');
    Route::post('acks/{module}/{id}/remind', [\App\Http\Controllers\Web\AckController::class, 'remind'])->name('acks.remind');

    // ── التعليقات وقناة الفريق ──
    Route::get('feed', [CommentController::class, 'feed'])->name('feed');
    Route::post('comments', [CommentController::class, 'store'])->name('comments.store');
    Route::post('comments/{id}/pin', [CommentController::class, 'pin'])->name('comments.pin');
    Route::post('comments/{id}/task', [CommentController::class, 'toTask'])->name('comments.task');
    Route::delete('comments/{id}', [CommentController::class, 'destroy'])->name('comments.destroy');
    Route::post('comments/{id}/react', [CommentController::class, 'react'])->name('comments.react');
    Route::post('comments/{id}/resolve', [CommentController::class, 'resolve'])->name('comments.resolve');
    // v2.123: سلسلة العقد (ملحق/تجديد) + مكتبة البنود
    Route::post('contract/{id}/amend', [\App\Http\Controllers\Web\ContractActionsController::class, 'amend'])->name('contract.amend');
    Route::post('contract/{id}/renew', [\App\Http\Controllers\Web\ContractActionsController::class, 'renew'])->name('contract.renew');
    Route::post('esign/clauses', [\App\Http\Controllers\Web\ContractActionsController::class, 'storeClause'])->name('esign.clause.store');
    Route::delete('esign/clauses', [\App\Http\Controllers\Web\ContractActionsController::class, 'destroyClause'])->name('esign.clause.destroy');

    // ── المراسلة الداخلية المباشرة ──
    Route::get('dm', [DmController::class, 'inbox'])->name('dm.inbox');
    Route::post('dm', [DmController::class, 'start'])->name('dm.start');
    Route::delete('dm/msg/{id}', [DmController::class, 'destroy'])->name('dm.destroy');
    Route::get('dm/{userId}', [DmController::class, 'thread'])->name('dm.thread');
    Route::post('dm/{userId}', [DmController::class, 'send'])->name('dm.send');

    // ── التخصيص الشخصي ──
    Route::get('personalize', [PrefController::class, 'edit'])->name('prefs.edit');
    Route::post('personalize', [PrefController::class, 'update'])->name('prefs.update');
    Route::post('personalize/reset', [PrefController::class, 'reset'])->name('prefs.reset');
    Route::post('personalize/pin', [PrefController::class, 'togglePin'])->name('prefs.pin');
    Route::post('personalize/cols', [PrefController::class, 'saveCols'])->name('prefs.cols');
    Route::post('views', [PrefController::class, 'storeView'])->name('views.store');
    Route::post('views/{id}/default', [PrefController::class, 'defaultView'])->name('views.default');
    Route::delete('views/{id}', [PrefController::class, 'destroyView'])->name('views.destroy');

    // ── خيط التتبع من الفكرة إلى النشر ──
    Route::get('trace/{module}/{id}', [TraceController::class, 'show'])->name('trace');

    // ── رحلة العميل ──
    Route::get('journey/{id}', [JourneyController::class, 'show'])->name('journey');

    // ── مركز الابتكار ──
    Route::get('innovation', [\App\Http\Controllers\Web\InnovationController::class, 'index'])->name('innovation');
    Route::post('ideas/{id}/promote', [\App\Http\Controllers\Web\InnovationController::class, 'promote'])->name('ideas.promote');

    // ── مركز السياسات والإقرارات (والمعرفة الإلزامية) ──
    Route::get('policies', [\App\Http\Controllers\Web\PolicyController::class, 'index'])->name('policies.board');
    Route::post('{module}/{id}/announce', [\App\Http\Controllers\Web\PolicyController::class, 'announce'])
        ->whereIn('module', ['policies', 'kb'])->name('acks.announce');
    Route::post('{module}/{id}/ack', [\App\Http\Controllers\Web\PolicyController::class, 'ack'])
        ->whereIn('module', ['policies', 'kb'])->name('acks.ack');

    // ── لوحة الأهداف والنتائج ──
    Route::get('okrs', [\App\Http\Controllers\Web\OkrController::class, 'index'])->name('okrs.board');
    Route::post('okrs/refresh', [\App\Http\Controllers\Web\OkrController::class, 'refresh'])->name('okrs.refresh');

    // ── المراقبة الحيّة: فحصٌ عند الطلب لسيرفر أو موقع ──
    // حدُّ معدّل (v2.324): الفحصُ يحجز عاملاً ثوانيَ طويلة (مهلةُ الشبكة)، وكان
    // بلا سقف — فضغطاتٌ متتالية تستهلك عمّالَ الخادم كلَّهم بلا أي استغلال
    Route::post('monitor/{module}/{id}/check', [\App\Http\Controllers\Web\MonitorController::class, 'check'])
        ->middleware('throttle:10,1')->name('monitor.check');

    // ── مركز السوشال ميديا: مراقبة وتحليل ──
    Route::get('social', [\App\Http\Controllers\Web\SocialController::class, 'index'])->name('social.index');
    Route::post('social/snapshot', [\App\Http\Controllers\Web\SocialController::class, 'snapshot'])->name('social.snapshot');

    // ── المرفقات الشاملة على أي سجل ──
    // ── الرفعُ المقطَّع: قطعٌ صغيرةٌ تصل حيث لا يمرّ الملفُّ الكبير ──
    // معدّلٌ واسع: غيغابايتٌ بقطعٍ ٤ م.ب = ٢٥٦ طلباً — والحدُّ يحمي من الإغراق
    Route::post('uploads/chunk', [\App\Http\Controllers\Web\UploadChunkController::class, 'chunk'])
        ->name('upload.chunk')->middleware('throttle:900,1');
    Route::post('uploads/finish', [\App\Http\Controllers\Web\UploadChunkController::class, 'finish'])
        ->name('upload.finish')->middleware('throttle:120,1');

    Route::post('attachments', [AttachmentController::class, 'store'])->name('att.store');
    Route::get('attachments/{id}/dl', [AttachmentController::class, 'download'])->name('att.dl');
    Route::get('attachments/{id}/view', [AttachmentController::class, 'preview'])->name('att.view');
    // حزمةُ مرفقات سجلٍّ واحد — بصلاحية السجل نفسه
    Route::get('attachments/{module}/{recordId}/zip', [AttachmentController::class, 'zip'])->name('att.zip');
    Route::post('attachments/{id}/move', [AttachmentController::class, 'move'])->name('att.move');
    Route::delete('attachments/{id}', [AttachmentController::class, 'destroy'])->name('att.destroy');
    Route::get('employee/{id}', [PortalController::class, 'employee'])->name('portal.employee');
    Route::get('app/{id}', [AppCenterController::class, 'show'])->name('apps.center');
    Route::get('quote/{id}/doc', [QuoteController::class, 'doc'])->name('quotes.doc');
    Route::get('quote/{id}/pdf', [QuoteController::class, 'pdf'])->name('quotes.pdf');
    Route::post('quote/{id}/act', [QuoteController::class, 'act'])->name('quotes.act');
    // بنّاء العرض المهنيّ: بنودٌ مهيكلة ومراحلُ دفع (تُعيد حساب الإجمالي خادمياً)
    Route::post('quote/{id}/line', [QuoteBuilderController::class, 'storeLine'])->name('quotes.line.store');
    Route::delete('quote/{id}/line/{line}', [QuoteBuilderController::class, 'destroyLine'])->name('quotes.line.destroy');
    Route::post('quote/{id}/milestone', [QuoteBuilderController::class, 'storeMilestone'])->name('quotes.ms.store');
    Route::delete('quote/{id}/milestone/{ms}', [QuoteBuilderController::class, 'destroyMilestone'])->name('quotes.ms.destroy');
    Route::post('fin/{id}/act', [\App\Http\Controllers\Web\FinController::class, 'act'])->name('fin.act');
    Route::post('entry/{id}/line', [\App\Http\Controllers\Web\EntryController::class, 'line'])->name('entries.line');
    Route::delete('entry/{id}/line/{lineId}', [\App\Http\Controllers\Web\EntryController::class, 'dropLine'])->name('entries.line.drop');
    Route::post('entry/{id}/post', [\App\Http\Controllers\Web\EntryController::class, 'post'])->name('entries.post');
    Route::post('stockmv/{id}/act', [\App\Http\Controllers\Web\StockController::class, 'act'])->name('stockmv.act');
    Route::post('payroll/{id}/act', [\App\Http\Controllers\Web\PayrollController::class, 'act'])->name('payroll.act');
    Route::post('candidates/{id}/hire', [\App\Http\Controllers\Web\HireController::class, 'hire'])->name('recruit.hire');
    Route::post('meetings/{id}/extract', [\App\Http\Controllers\Web\MinutesController::class, 'extract'])->name('meetings.extract');
    Route::get('supplier-scores', [PurchaseController::class, 'scores'])->name('supplierscores');
    Route::get('purchase/{id}/doc', [PurchaseController::class, 'doc'])->name('purchases.doc');
    Route::post('purchase/{id}/act', [PurchaseController::class, 'act'])->name('purchases.act');
    // CTO م2 (v2.134): صفحات مساحات العمل المركزية
    Route::get('w/{key}', [\App\Http\Controllers\Web\WorkspaceController::class, 'show'])->name('workspace');
    Route::get('legal', [LegalController::class, 'index'])->name('legal');
    Route::post('legal/rules/{id}/enable', [LegalController::class, 'enableRule'])->name('legal.rule.enable');
    Route::get('support', [SupportController::class, 'index'])->name('support');
    Route::get('performance', [PerformanceController::class, 'index'])->name('performance');

    // ── الربط الذكي بأودو (عرض فقط) ──
    // «projects» قبل «{module}»: المسار الأدقّ يُسجَّل أولاً فلا يبتلعه العام
    Route::get('odoo/projects/{id}', [OdooController::class, 'project'])->name('odoo.project');
    Route::post('odoo/projects/{id}/conn', [OdooController::class, 'setConn'])->name('odoo.project.conn');
    Route::post('odoo/projects/{id}/channels', [OdooController::class, 'addChannel'])->name('odoo.project.channel.add');
    Route::post('odoo/projects/{id}/channels/remove', [OdooController::class, 'removeChannel'])->name('odoo.project.channel.del');
    Route::post('odoo/projects/{id}/channels/refresh', [OdooController::class, 'refreshChannels'])->name('odoo.project.refresh');
    Route::post('odoo/{module}/{id}/link', [OdooController::class, 'link'])->name('odoo.link');
    Route::post('odoo/{module}/{id}/unlink', [OdooController::class, 'unlink'])->name('odoo.unlink');
    Route::post('odoo/{module}/{id}/refresh', [OdooController::class, 'refresh'])->name('odoo.refresh');
    Route::get('search', [SearchController::class, 'index'])->name('search');
    Route::get('search/mini', [SearchController::class, 'mini'])->name('search.mini');
    Route::get('alerts', [AlertController::class, 'index'])->name('alerts');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/mini', [NotificationController::class, 'mini'])->name('notifications.mini');
    Route::get('notifications/count', [NotificationController::class, 'count'])->name('notifications.count');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.readall');
    Route::get('notifications/{id}/go', [NotificationController::class, 'go'])->name('notifications.go');
    Route::get('reports/finance', [ReportController::class, 'finance'])->name('reports.finance');
    Route::get('ceo', [CeoController::class, 'index'])->name('ceo');

    // ── الملف الشخصي ──
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'password'])->name('profile.password');
    Route::put('profile/notify', [ProfileController::class, 'notifyPrefs'])->name('profile.notify');
    Route::post('profile/token', [ProfileController::class, 'tokenStore'])->name('profile.token.store')->middleware('throttle:10,1');
    Route::delete('profile/token/{id}', [ProfileController::class, 'tokenRevoke'])->name('profile.token.revoke');
    Route::post('profile/token/{id}/rotate', [ProfileController::class, 'tokenRotate'])->name('profile.token.rotate');
    Route::post('profile/2fa/start', [ProfileController::class, 'twofaStart'])->name('profile.2fa.start');
    Route::post('profile/2fa/confirm', [ProfileController::class, 'twofaConfirm'])->name('profile.2fa.confirm');
    Route::post('profile/2fa/disable', [ProfileController::class, 'twofaDisable'])->name('profile.2fa.disable');

    // تصعيد المصادقة (Step-Up): إعادةُ تحقّقٍ قبل الأفعال الحسّاسة
    Route::get('stepup', [StepUpController::class, 'show'])->name('stepup.show');
    Route::post('stepup', [StepUpController::class, 'verify'])->name('stepup.verify')->middleware('throttle:10,1');

    // أمني ذاتي: جلساتي وأجهزتي — لكل مستخدمٍ على نفسه (كان الإبطال للمالك فقط)
    Route::get('my/security', [MySecurityController::class, 'index'])->name('mysec.index');
    Route::post('my/security/sessions/{id}/revoke', [MySecurityController::class, 'revokeSession'])->name('mysec.session.revoke');
    Route::post('my/security/sessions/others', [MySecurityController::class, 'revokeOthers'])->name('mysec.session.others');
    Route::post('my/security/devices/{id}/trust', [MySecurityController::class, 'trustDevice'])->name('mysec.device.trust');
    Route::post('my/security/devices/{id}/revoke', [MySecurityController::class, 'revokeDevice'])->name('mysec.device.revoke');

    // ── الإدارة ──
    Route::get('admin/users', [UserController::class, 'index'])->name('users.index');
    Route::get('admin/users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('admin/users', [UserController::class, 'store'])->name('users.store');
    Route::get('admin/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('admin/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('admin/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    Route::post('admin/users/{id}/restore', [UserController::class, 'restore'])->name('users.restore');
    // بابُ استردادٍ لمن ضاع جهازُ تحقّقه — وإلا فالقفلُ دائمٌ بلا مخرج
    Route::post('admin/users/{user}/twofa-off', [UserController::class, 'twofaOff'])->name('users.twofa.off');

    Route::get('admin/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('admin/roles/create', [RoleController::class, 'create'])->name('roles.create');
    Route::post('admin/roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('admin/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
    Route::put('admin/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::post('admin/roles/{role}/clone', [RoleController::class, 'clone'])->name('roles.clone');
    Route::delete('admin/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

    Route::get('admin/audit', [AuditController::class, 'index'])->name('audit.index');
    Route::get('admin/ops', [OpsController::class, 'index'])->name('ops.index');
    Route::post('admin/ops/test-error', [OpsController::class, 'testError'])->name('ops.testerror');
    Route::post('admin/ops/migrate', [OpsController::class, 'migrate'])->name('ops.migrate');
    Route::post('admin/ops/clear-cache', [OpsController::class, 'clearCache'])->name('ops.clearcache');
    Route::post('admin/ops/starters', [OpsController::class, 'starters'])->name('ops.starters');
    // فاحصان كانا يُرشَد إليهما بطرفيةٍ لا يملكها صاحبُ استضافةٍ مشتركة
    Route::post('admin/ops/verify-audit', [OpsController::class, 'verifyAudit'])->name('ops.verifyaudit');
    Route::post('admin/ops/schema-check', [OpsController::class, 'schemaCheck'])->name('ops.schemacheck');
    Route::post('admin/ops/backup', [OpsController::class, 'backupNow'])->name('ops.backup');
    Route::post('admin/ops/maintenance', [OpsController::class, 'toggleMaintenance'])->name('ops.maintenance');

    // مركز نشاط الموظفين — للمالك فقط
    Route::get('admin/activity', [\App\Http\Controllers\Web\ActivityController::class, 'index'])->name('activity.index');
    Route::get('admin/activity/{id}', [\App\Http\Controllers\Web\ActivityController::class, 'show'])->name('activity.show');

    // QuoteFlow — تطبيق جانبي معزول للمالك وحده، حالته على الخادم
    Route::get('apps/quoteflow', [QuoteFlowController::class, 'page'])->name('quoteflow');
    Route::post('apps/quoteflow/unlock', [QuoteFlowController::class, 'unlock'])->name('quoteflow.unlock');
    Route::post('apps/quoteflow/save', [QuoteFlowController::class, 'save'])->name('quoteflow.save');
    Route::post('admin/demo/reset', function () {
        abort_unless(auth()->user()?->role?->is_owner, 403);
        // شامل: كل وحدة من السجل تنال بيانات تجريبية، والإعدادات الفارغة تُملأ
        \Illuminate\Support\Facades\Artisan::call('hub:demo', ['--full' => true]);

        return back()->with('ok', 'صُفّر الوضع التجريبي — بيانات وهمية جديدة نظيفة في كل الوحدات');
    })->name('demo.reset');
    Route::post('admin/demo/off', function () {
        abort_unless(auth()->user()?->role?->is_owner, 403);
        \Illuminate\Support\Facades\Artisan::call('hub:demo', ['--purge' => true]);

        return back()->with('ok', 'انتهى الوضع التجريبي ومُسحت بياناته الوهمية كلها');
    })->name('demo.off');
    Route::get('admin/quality', [QualityController::class, 'index'])->name('quality.index');
    Route::post('admin/quality/merge', [QualityController::class, 'merge'])->name('quality.merge');
    Route::get('admin/errors', [ErrorCenterController::class, 'index'])->name('errors.index');
    Route::get('admin/errors/{id}', [ErrorCenterController::class, 'show'])->name('errors.show');
    Route::post('admin/errors/{id}/status', [ErrorCenterController::class, 'status'])->name('errors.status');
    Route::post('admin/errors/{id}/task', [ErrorCenterController::class, 'toTask'])->name('errors.task');
    Route::post('jslog', [ErrorCenterController::class, 'jslog'])->name('jslog')->middleware('throttle:20,1');
    Route::get('admin/integrations', [\App\Http\Controllers\Web\IntegrationController::class, 'index'])->name('integrations.index');
    Route::get('admin/integrations/guide', [\App\Http\Controllers\Web\IntegrationController::class, 'guide'])->name('integrations.guide');
    // مركز المراسلة — كل طرق التواصل الخارجة في شاشة واحدة
    Route::get('admin/integrations/messaging', [\App\Http\Controllers\Web\MessagingController::class, 'index'])->name('integrations.messaging');
    Route::post('admin/integrations/messaging/test', [\App\Http\Controllers\Web\MessagingController::class, 'test'])->name('integrations.messaging.test');
    Route::post('admin/integrations/messaging/retry', [\App\Http\Controllers\Web\MessagingController::class, 'retry'])->name('integrations.messaging.retry');
    Route::post('admin/integrations/messaging/mail', [\App\Http\Controllers\Web\MessagingController::class, 'mail'])->name('integrations.messaging.mail');
    Route::post('admin/integrations/messaging/telegram', [\App\Http\Controllers\Web\MessagingController::class, 'telegram'])->name('integrations.messaging.telegram');
    // خوادم أودو المتعددة — الاتصال الافتراضي يبقى في الإعدادات، وهنا الإضافيون
    Route::get('admin/integrations/odoo', [\App\Http\Controllers\Web\OdooConnectionController::class, 'index'])->name('integrations.odoo');
    Route::post('admin/integrations/odoo', [\App\Http\Controllers\Web\OdooConnectionController::class, 'store'])->name('integrations.odoo.store');
    Route::post('admin/integrations/odoo/defaults', [\App\Http\Controllers\Web\OdooConnectionController::class, 'defaults'])->name('integrations.odoo.defaults');
    Route::put('admin/integrations/odoo/{id}', [\App\Http\Controllers\Web\OdooConnectionController::class, 'update'])->name('integrations.odoo.update');
    Route::post('admin/integrations/odoo/{id}/toggle', [\App\Http\Controllers\Web\OdooConnectionController::class, 'toggle'])->name('integrations.odoo.toggle');
    Route::post('admin/integrations/odoo/{id}/test', [\App\Http\Controllers\Web\OdooConnectionController::class, 'test'])->name('integrations.odoo.test');
    Route::delete('admin/integrations/odoo/{id}', [\App\Http\Controllers\Web\OdooConnectionController::class, 'destroy'])->name('integrations.odoo.destroy');
    Route::get('admin/webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
    Route::post('admin/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
    Route::post('admin/webhooks/{id}/toggle', [WebhookController::class, 'toggle'])->name('webhooks.toggle');
    Route::post('admin/webhooks/{id}/test', [WebhookController::class, 'test'])->name('webhooks.test');
    Route::delete('admin/webhooks/{id}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
    Route::get('admin/webhooks/{id}/log', [WebhookController::class, 'log'])->name('webhooks.log');
    Route::post('admin/webhooks/{id}/resend/{did}', [WebhookController::class, 'resend'])->name('webhooks.resend');
    // الويبهوك الوارد — نقاطُ استقبالٍ أصلية (n8n/نماذج/خدمات → النظام)
    Route::get('admin/integrations/hooks', [\App\Http\Controllers\Web\InboundHookController::class, 'index'])->name('hooks.index');
    Route::post('admin/integrations/hooks', [\App\Http\Controllers\Web\InboundHookController::class, 'store'])->name('hooks.store');
    Route::post('admin/integrations/hooks/{id}/toggle', [\App\Http\Controllers\Web\InboundHookController::class, 'toggle'])->name('hooks.toggle');
    Route::delete('admin/integrations/hooks/{id}', [\App\Http\Controllers\Web\InboundHookController::class, 'destroy'])->name('hooks.destroy');
    // n8n — ربطُ مثيلٍ منفصلٍ يعمل على الخادم (Docker) بالنظام
    Route::get('admin/integrations/n8n', [\App\Http\Controllers\Web\N8nController::class, 'index'])->name('integrations.n8n');
    Route::post('admin/integrations/n8n', [\App\Http\Controllers\Web\N8nController::class, 'save'])->name('integrations.n8n.save');
    Route::get('admin/security', [SecurityController::class, 'index'])->name('security.index');
    Route::post('admin/security/lockdown', [SecurityController::class, 'lockdown'])->name('security.lockdown');
    Route::post('admin/security/sessions/{id}/revoke', [SecurityController::class, 'revokeSession'])->name('security.session.revoke');
    Route::post('admin/security/users/{id}/revoke', [SecurityController::class, 'revokeUser'])->name('security.user.revoke');
    Route::get('admin/settings', [SettingController::class, 'edit'])->name('settings.edit');
    Route::post('admin/settings', [SettingController::class, 'update'])->name('settings.update');
    Route::post('admin/settings/odoo-test', [SettingController::class, 'odooTest'])->name('settings.odoo.test');
    Route::get('admin/flows', [FlowController::class, 'index'])->name('flows.index');
    Route::post('admin/flows', [FlowController::class, 'store'])->name('flows.store');
    Route::post('admin/flows/bulk', [FlowController::class, 'bulk'])->name('flows.bulk');
    Route::get('admin/flows/{id}/edit', [FlowController::class, 'edit'])->name('flows.edit');
    Route::put('admin/flows/{id}', [FlowController::class, 'update'])->name('flows.update');
    Route::post('admin/flows/{id}/duplicate', [FlowController::class, 'duplicate'])->name('flows.duplicate');
    Route::get('admin/flows/{id}/sandbox', [FlowController::class, 'sandbox'])->name('flows.sandbox');
    Route::post('admin/flows/{id}/toggle', [FlowController::class, 'toggle'])->name('flows.toggle');
    Route::delete('admin/flows/{id}', [FlowController::class, 'destroy'])->name('flows.destroy');
    Route::get('admin/fields', [CustomFieldController::class, 'index'])->name('fields.index');
    Route::post('admin/fields', [CustomFieldController::class, 'store'])->name('fields.store');
    Route::delete('admin/fields/{module}/{key}', [CustomFieldController::class, 'destroy'])->name('fields.destroy');

    // ── الوحدات (سجل الوحدات يقود كل شيء) ──
    Route::prefix('m/{module}')->name('m.')->group(function () {
        Route::get('/', [ModuleController::class, 'index'])->name('index');
        Route::get('board', [ModuleController::class, 'board'])->name('board');
        // التصدير مسارُ استنزافٍ جماعي — يُحدّ معدله كبقية المسارات الحساسة (v2.367)
        Route::get('export', [ModuleController::class, 'export'])->name('export')->middleware('throttle:20,1');
        Route::get('create', [ModuleController::class, 'create'])->name('create');
        Route::get('import', [ImportController::class, 'form'])->name('import');
        Route::post('import', [ImportController::class, 'map'])->name('import.map');
        Route::post('import/run', [ImportController::class, 'run'])->name('import.run');
        Route::post('{id}/status', [ModuleController::class, 'setStatus'])->name('status');
        // كشفُ السرّ فعلٌ حسّاس: بلا حدٍّ كانت جلسةٌ مخترقةٌ تحصد الخزنة بسرعة HTTP
        Route::post('{id}/secret/{field}', [ModuleController::class, 'revealSecret'])->name('secret')->middleware('throttle:20,1');
        Route::post('bulk', [ModuleController::class, 'bulk'])->name('bulk')->middleware('throttle:30,1');
        Route::post('/', [ModuleController::class, 'store'])->name('store');
        Route::get('{id}', [ModuleController::class, 'show'])->name('show');
        Route::get('{id}/edit', [ModuleController::class, 'edit'])->name('edit');
        Route::put('{id}', [ModuleController::class, 'update'])->name('update');
        Route::delete('{id}', [ModuleController::class, 'destroy'])->name('destroy');
        Route::post('{id}/restore', [ModuleController::class, 'restore'])->name('restore');
        Route::post('{id}/versions/{version}', [ModuleController::class, 'restoreVersion'])->name('version.restore');
    });
});
