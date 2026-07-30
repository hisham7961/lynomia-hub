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
use App\Http\Controllers\Web\NotificationController;
use App\Http\Controllers\Web\OdooController;
use App\Http\Controllers\Web\PerformanceController;
use App\Http\Controllers\Web\PortalController;
use App\Http\Controllers\Web\LegalController;
use App\Http\Controllers\Web\ProfileController;
use App\Http\Controllers\Web\PurchaseController;
use App\Http\Controllers\Web\PwaController;
use App\Http\Controllers\Web\QualityController;
use App\Http\Controllers\Web\QuoteController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\RoleController;
use App\Http\Controllers\Web\SearchController;
use App\Http\Controllers\Web\SecurityController;
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

Route::middleware('auth')->group(function () {
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

    Route::get('morning', [MorningController::class, 'index'])->name('morning');
    Route::get('calendar', [\App\Http\Controllers\Web\CalendarController::class, 'index'])->name('calendar');
    Route::get('costs', [CostController::class, 'index'])->name('costs.index');
    Route::get('service-costs', [CostController::class, 'services'])->name('servicecosts');
    Route::get('capacity', [CapacityController::class, 'index'])->name('capacity');
    Route::get('impact', [CapacityController::class, 'impact'])->name('impact');
    Route::get('app-quality', [CapacityController::class, 'quality'])->name('appquality');
    Route::get('recommendations', [CapacityController::class, 'recommendations'])->name('recs');
    Route::get('kpis', [\App\Http\Controllers\Web\KpiController::class, 'index'])->name('kpis.index');
    Route::post('kpis', [\App\Http\Controllers\Web\KpiController::class, 'store'])->name('kpis.store');
    Route::delete('kpis/{id}', [\App\Http\Controllers\Web\KpiController::class, 'destroy'])->name('kpis.destroy');

    // ── صندوق الوثائق الوارد ──
    Route::get('inboxdocs', [InboxDocController::class, 'index'])->name('inboxdocs.index');
    Route::post('inboxdocs', [InboxDocController::class, 'store'])->name('inboxdocs.store');
    Route::post('inboxdocs/{id}/classify', [InboxDocController::class, 'classify'])->name('inboxdocs.classify');
    Route::delete('inboxdocs/{id}', [InboxDocController::class, 'destroy'])->name('inboxdocs.destroy');

    // ── غرفة البيانات (الإدارة) ──
    Route::get('dataroom', [DataRoomController::class, 'index'])->name('dataroom.index');
    Route::post('dataroom', [DataRoomController::class, 'store'])->name('dataroom.store');
    Route::post('dataroom/{id}/revoke', [DataRoomController::class, 'revoke'])->name('dataroom.revoke');

    // ── حسم الموافقات المُلزِمة ──
    Route::post('approvals/{id}/approve', [ApprovalDecisionController::class, 'approve'])->name('approvals.approve');
    Route::post('approvals/{id}/reject', [ApprovalDecisionController::class, 'reject'])->name('approvals.reject');

    // ── التعليقات وقناة الفريق ──
    Route::get('feed', [CommentController::class, 'feed'])->name('feed');
    Route::post('comments', [CommentController::class, 'store'])->name('comments.store');
    Route::post('comments/{id}/pin', [CommentController::class, 'pin'])->name('comments.pin');
    Route::post('comments/{id}/task', [CommentController::class, 'toTask'])->name('comments.task');
    Route::delete('comments/{id}', [CommentController::class, 'destroy'])->name('comments.destroy');
    Route::post('comments/{id}/react', [CommentController::class, 'react'])->name('comments.react');

    // ── المراسلة الداخلية المباشرة ──
    Route::get('dm', [DmController::class, 'inbox'])->name('dm.inbox');
    Route::get('dm/{userId}', [DmController::class, 'thread'])->name('dm.thread');
    Route::post('dm/{userId}', [DmController::class, 'send'])->name('dm.send');

    // ── التخصيص الشخصي ──
    Route::get('personalize', [PrefController::class, 'edit'])->name('prefs.edit');
    Route::post('personalize', [PrefController::class, 'update'])->name('prefs.update');
    Route::post('personalize/reset', [PrefController::class, 'reset'])->name('prefs.reset');
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

    // ── المرفقات الشاملة على أي سجل ──
    Route::post('attachments', [AttachmentController::class, 'store'])->name('att.store');
    Route::get('attachments/{id}/dl', [AttachmentController::class, 'download'])->name('att.dl');
    Route::delete('attachments/{id}', [AttachmentController::class, 'destroy'])->name('att.destroy');
    Route::get('employee/{id}', [PortalController::class, 'employee'])->name('portal.employee');
    Route::get('app/{id}', [AppCenterController::class, 'show'])->name('apps.center');
    Route::get('quote/{id}/doc', [QuoteController::class, 'doc'])->name('quotes.doc');
    Route::post('quote/{id}/act', [QuoteController::class, 'act'])->name('quotes.act');
    Route::get('supplier-scores', [PurchaseController::class, 'scores'])->name('supplierscores');
    Route::get('purchase/{id}/doc', [PurchaseController::class, 'doc'])->name('purchases.doc');
    Route::post('purchase/{id}/act', [PurchaseController::class, 'act'])->name('purchases.act');
    Route::get('legal', [LegalController::class, 'index'])->name('legal');
    Route::get('support', [SupportController::class, 'index'])->name('support');
    Route::get('performance', [PerformanceController::class, 'index'])->name('performance');

    // ── الربط الذكي بأودو (عرض فقط) ──
    Route::post('odoo/{module}/{id}/link', [OdooController::class, 'link'])->name('odoo.link');
    Route::post('odoo/{module}/{id}/unlink', [OdooController::class, 'unlink'])->name('odoo.unlink');
    Route::post('odoo/{module}/{id}/refresh', [OdooController::class, 'refresh'])->name('odoo.refresh');
    Route::get('search', [SearchController::class, 'index'])->name('search');
    Route::get('search/mini', [SearchController::class, 'mini'])->name('search.mini');
    Route::get('alerts', [AlertController::class, 'index'])->name('alerts');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/mini', [NotificationController::class, 'mini'])->name('notifications.mini');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.readall');
    Route::get('reports/finance', [ReportController::class, 'finance'])->name('reports.finance');
    Route::get('ceo', [CeoController::class, 'index'])->name('ceo');

    // ── الملف الشخصي ──
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'password'])->name('profile.password');
    Route::post('profile/token', [ProfileController::class, 'tokenStore'])->name('profile.token.store');
    Route::delete('profile/token/{id}', [ProfileController::class, 'tokenRevoke'])->name('profile.token.revoke');
    Route::post('profile/token/{id}/rotate', [ProfileController::class, 'tokenRotate'])->name('profile.token.rotate');
    Route::post('profile/2fa/start', [ProfileController::class, 'twofaStart'])->name('profile.2fa.start');
    Route::post('profile/2fa/confirm', [ProfileController::class, 'twofaConfirm'])->name('profile.2fa.confirm');
    Route::post('profile/2fa/disable', [ProfileController::class, 'twofaDisable'])->name('profile.2fa.disable');

    // ── الإدارة ──
    Route::get('admin/users', [UserController::class, 'index'])->name('users.index');
    Route::get('admin/users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('admin/users', [UserController::class, 'store'])->name('users.store');
    Route::get('admin/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('admin/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('admin/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

    Route::get('admin/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('admin/roles/create', [RoleController::class, 'create'])->name('roles.create');
    Route::post('admin/roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('admin/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
    Route::put('admin/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::delete('admin/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

    Route::get('admin/audit', [AuditController::class, 'index'])->name('audit.index');
    Route::get('admin/ops', [OpsController::class, 'index'])->name('ops.index');
    Route::post('admin/ops/test-error', [OpsController::class, 'testError'])->name('ops.testerror');
    Route::post('admin/ops/migrate', [OpsController::class, 'migrate'])->name('ops.migrate');
    Route::post('admin/ops/clear-cache', [OpsController::class, 'clearCache'])->name('ops.clearcache');

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
    Route::post('admin/errors/{id}/status', [ErrorCenterController::class, 'status'])->name('errors.status');
    Route::post('jslog', [ErrorCenterController::class, 'jslog'])->name('jslog')->middleware('throttle:20,1');
    Route::get('admin/webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
    Route::post('admin/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
    Route::post('admin/webhooks/{id}/toggle', [WebhookController::class, 'toggle'])->name('webhooks.toggle');
    Route::post('admin/webhooks/{id}/test', [WebhookController::class, 'test'])->name('webhooks.test');
    Route::delete('admin/webhooks/{id}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
    Route::get('admin/webhooks/{id}/log', [WebhookController::class, 'log'])->name('webhooks.log');
    Route::post('admin/webhooks/{id}/resend/{did}', [WebhookController::class, 'resend'])->name('webhooks.resend');
    Route::get('admin/security', [SecurityController::class, 'index'])->name('security.index');
    Route::post('admin/security/lockdown', [SecurityController::class, 'lockdown'])->name('security.lockdown');
    Route::get('admin/settings', [SettingController::class, 'edit'])->name('settings.edit');
    Route::post('admin/settings', [SettingController::class, 'update'])->name('settings.update');
    Route::post('admin/settings/odoo-test', [SettingController::class, 'odooTest'])->name('settings.odoo.test');
    Route::get('admin/flows', [FlowController::class, 'index'])->name('flows.index');
    Route::post('admin/flows', [FlowController::class, 'store'])->name('flows.store');
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
        Route::get('export', [ModuleController::class, 'export'])->name('export');
        Route::get('create', [ModuleController::class, 'create'])->name('create');
        Route::get('import', [ImportController::class, 'form'])->name('import');
        Route::post('import', [ImportController::class, 'map'])->name('import.map');
        Route::post('import/run', [ImportController::class, 'run'])->name('import.run');
        Route::post('{id}/status', [ModuleController::class, 'setStatus'])->name('status');
        Route::post('/', [ModuleController::class, 'store'])->name('store');
        Route::get('{id}', [ModuleController::class, 'show'])->name('show');
        Route::get('{id}/edit', [ModuleController::class, 'edit'])->name('edit');
        Route::put('{id}', [ModuleController::class, 'update'])->name('update');
        Route::delete('{id}', [ModuleController::class, 'destroy'])->name('destroy');
        Route::post('{id}/restore', [ModuleController::class, 'restore'])->name('restore');
        Route::post('{id}/versions/{version}', [ModuleController::class, 'restoreVersion'])->name('version.restore');
    });
});
