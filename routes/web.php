<?php

use App\Http\Controllers\Web\AlertController;
use App\Http\Controllers\Web\AppCenterController;
use App\Http\Controllers\Web\ApprovalDecisionController;
use App\Http\Controllers\Web\AuditController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\CeoController;
use App\Http\Controllers\Web\CommentController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DataRoomController;
use App\Http\Controllers\Web\ImportController;
use App\Http\Controllers\Web\ModuleController;
use App\Http\Controllers\Web\NotificationController;
use App\Http\Controllers\Web\PortalController;
use App\Http\Controllers\Web\LegalController;
use App\Http\Controllers\Web\ProfileController;
use App\Http\Controllers\Web\PurchaseController;
use App\Http\Controllers\Web\QuoteController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\RoleController;
use App\Http\Controllers\Web\SearchController;
use App\Http\Controllers\Web\SecurityController;
use App\Http\Controllers\Web\SettingController;
use App\Http\Controllers\Web\UserController;
use Illuminate\Support\Facades\Route;

// ── الوجه العام لغرفة البيانات (بلا تسجيل دخول — الرمز هو المفتاح) ──
Route::get('s/{token}', [DataRoomController::class, 'show'])->name('share.show');
Route::post('s/{token}', [DataRoomController::class, 'unlock'])->name('share.unlock')->middleware('throttle:10,1');
Route::get('s/{token}/file', [DataRoomController::class, 'file'])->name('share.file');

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
    Route::get('me', [PortalController::class, 'me'])->name('portal.me');

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
    Route::get('employee/{id}', [PortalController::class, 'employee'])->name('portal.employee');
    Route::get('app/{id}', [AppCenterController::class, 'show'])->name('apps.center');
    Route::get('quote/{id}/doc', [QuoteController::class, 'doc'])->name('quotes.doc');
    Route::post('quote/{id}/act', [QuoteController::class, 'act'])->name('quotes.act');
    Route::get('purchase/{id}/doc', [PurchaseController::class, 'doc'])->name('purchases.doc');
    Route::post('purchase/{id}/act', [PurchaseController::class, 'act'])->name('purchases.act');
    Route::get('legal', [LegalController::class, 'index'])->name('legal');
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
    Route::get('admin/security', [SecurityController::class, 'index'])->name('security.index');
    Route::post('admin/security/lockdown', [SecurityController::class, 'lockdown'])->name('security.lockdown');
    Route::get('admin/settings', [SettingController::class, 'edit'])->name('settings.edit');
    Route::post('admin/settings', [SettingController::class, 'update'])->name('settings.update');

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
