<?php

/*
|---------------------------------------------------------------------------
| مساراتُ الويب — الإدارة
|---------------------------------------------------------------------------
| جزءٌ من مجموعة `auth` في routes/web.php يُضمَّن **في موضعه نفسِه** (docs/REORG_PLAN.md §R3):
| الترتيبُ يحكم مطابقةَ المسارات ذات المتغيّرات، والوسائطُ موروثةٌ من المجموعة. تغييرُ
| ترتيبٍ أو اسمٍ أو وسيطٍ تُسقطه لقطةُ المسارات (tests/Fixtures/structure/routes.json).
*/

use App\Http\Controllers\Web\AuditController;
use App\Http\Controllers\Web\ErrorCenterController;
use App\Http\Controllers\Web\OpsController;
use App\Http\Controllers\Web\QualityController;
use App\Http\Controllers\Web\RoleController;
use App\Http\Controllers\Web\QuoteFlowController;
use App\Http\Controllers\Web\UserController;
use App\Http\Controllers\Web\WebhookController;
use Illuminate\Support\Facades\Route;

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
    Route::post('admin/users/{user}/unlock', [UserController::class, 'unlock'])->name('users.unlock');

    Route::get('admin/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('admin/roles/create', [RoleController::class, 'create'])->name('roles.create');
    Route::post('admin/roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('admin/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
    Route::put('admin/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::post('admin/roles/{role}/clone', [RoleController::class, 'clone'])->name('roles.clone');
    Route::delete('admin/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    // تشخيصُ الوصول (Permissions Reconciliation · §22/§59/§60) — مالكٌ حصراً، طبقةُ عرضٍ فوق PermissionInspector
    Route::get('admin/access', [\App\Http\Controllers\Web\AccessController::class, 'index'])->name('access.index');
    Route::get('admin/access/role/{role}', [\App\Http\Controllers\Web\AccessController::class, 'role'])->name('access.role');

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
    // الصحّةُ المفصّلة (نموذج الصحّة الواحد) — للمالك؛ /healthz العامّ يعرض الحالاتِ وحدها
    Route::get('admin/ops/health', [OpsController::class, 'healthDetail'])->name('ops.health');
    // كتيّباتُ التشغيل من الملفّ الحيّ docs/RUNBOOKS.md — تُقرأ حيث يُحتاج إليها لا في المستودع وحده
    Route::get('admin/ops/runbooks', [OpsController::class, 'runbooks'])->name('ops.runbooks');

    // مركز نشاط الموظفين — للمالك فقط
    Route::get('admin/activity', [\App\Http\Controllers\Web\ActivityController::class, 'index'])->name('activity.index');
    Route::get('admin/activity/{id}', [\App\Http\Controllers\Web\ActivityController::class, 'show'])->name('activity.show');

    // QuoteFlow — تطبيق جانبي معزول للمالك وحده، حالته على الخادم
    Route::get('apps/quoteflow', [QuoteFlowController::class, 'page'])->name('quoteflow');
    Route::post('apps/quoteflow/unlock', [QuoteFlowController::class, 'unlock'])->name('quoteflow.unlock');
    Route::post('apps/quoteflow/save', [QuoteFlowController::class, 'save'])->name('quoteflow.save');
    Route::post('admin/demo/reset', function () {
        abort_unless(auth()->user()?->role?->is_owner, 403);
        if ($resp = hub_require_ops_stepup()) return $resp;   // يبذر بياناتٍ وهمية في كل الوحدات — بتأكيد هوية (v2.399)
        // شامل: كل وحدة من السجل تنال بيانات تجريبية، والإعدادات الفارغة تُملأ
        \Illuminate\Support\Facades\Artisan::call('hub:demo', ['--full' => true]);

        return back()->with('ok', 'صُفّر الوضع التجريبي — بيانات وهمية جديدة نظيفة في كل الوحدات');
    })->name('demo.reset');
    Route::post('admin/demo/off', function () {
        abort_unless(auth()->user()?->role?->is_owner, 403);
        if ($resp = hub_require_ops_stepup()) return $resp;
        \Illuminate\Support\Facades\Artisan::call('hub:demo', ['--purge' => true]);

        return back()->with('ok', 'انتهى الوضع التجريبي ومُسحت بياناته الوهمية كلها');
    })->name('demo.off');
    /*
     * أسعارُ الصرف (تعدّدُ العملات · v2.528.0): محرّكُ `Currency` بلا بابٍ يُدخِل
     * السعرَ **ميزةٌ مخفيّة** — وهو الصنفُ الذي لاحقته هذه الجلسةُ كلُّها. البوّابةُ
     * بوّابةُ الماليّة: `fin:v` يقرأ و`fin:e` يُدخِل — لا صلاحيّةَ تُخترَع لمقبضٍ واحد.
     */
    Route::get('admin/currency-rates', [\App\Http\Controllers\Web\CurrencyRateController::class, 'index'])->name('currency.rates');
    Route::post('admin/currency-rates', [\App\Http\Controllers\Web\CurrencyRateController::class, 'store'])->name('currency.rates.store');
    Route::delete('admin/currency-rates/{id}', [\App\Http\Controllers\Web\CurrencyRateController::class, 'destroy'])->name('currency.rates.destroy');
    Route::get('admin/quality', [QualityController::class, 'index'])->name('quality.index');
    Route::post('admin/quality/merge', [QualityController::class, 'merge'])->name('quality.merge');
    Route::get('admin/errors', [ErrorCenterController::class, 'index'])->name('errors.index');
    // معرّفُ الخطأ UUID دائماً (HasUuid) — القيدُ يُفسح `admin/errors/logs` (الطور ٣)
    // لمساره بدل أن يبتلعه {id} فيردّ ٤٠٤ عن صفحةٍ حيّة
    Route::get('admin/errors/{id}', [ErrorCenterController::class, 'show'])->name('errors.show')->whereUuid('id');
    Route::post('admin/errors/{id}/status', [ErrorCenterController::class, 'status'])->name('errors.status');
    Route::post('admin/errors/{id}/task', [ErrorCenterController::class, 'toTask'])->name('errors.task');
    // (المرحلة ٥ · مساعدُ التطوير) شرحٌ واقتراحُ إصلاحٍ ونموذجُ «مشكلة» معبّأ — لا يكتب شيئاً
    Route::post('admin/errors/{id}/explain', [\App\Http\Controllers\Web\DevAssistController::class, 'explain'])
        ->name('errors.explain')->whereUuid('id')->middleware('throttle:' . \App\Support\Ai\Ask\AskPolicy::THROTTLE);
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
    // (WP-9.4 · §7.10) كلُّ نقرةٍ تطرق خادماً خارجياً — خنقٌ كي لا يصير الزرُّ مسبارَ منافذ
    Route::post('admin/integrations/odoo/{id}/test', [\App\Http\Controllers\Web\OdooConnectionController::class, 'test'])->name('integrations.odoo.test')->middleware('throttle:10,1');
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
