<?php

/*
|---------------------------------------------------------------------------
| مساراتُ الويب — الربطُ بأودو (عرض فقط)
|---------------------------------------------------------------------------
| جزءٌ من مجموعة `auth` في routes/web.php يُضمَّن **في موضعه نفسِه** (docs/REORG_PLAN.md §R3):
| الترتيبُ يحكم مطابقةَ المسارات ذات المتغيّرات، والوسائطُ موروثةٌ من المجموعة. تغييرُ
| ترتيبٍ أو اسمٍ أو وسيطٍ تُسقطه لقطةُ المسارات (tests/Fixtures/structure/routes.json).
*/

use App\Http\Controllers\Web\AlertController;
use App\Http\Controllers\Web\CeoController;
use App\Http\Controllers\Web\NotificationController;
use App\Http\Controllers\Web\OdooController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\SearchController;
use Illuminate\Support\Facades\Route;

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
    // خريطةُ النظام (IA · الطور 6): الشجرةُ المُنطَّقةُ الكاملة — اسمٌ جديدٌ لا يصطدم
    Route::get('system-map', [\App\Http\Controllers\Web\SystemMapController::class, 'index'])->name('system-map');
    Route::get('alerts', [AlertController::class, 'index'])->name('alerts');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/mini', [NotificationController::class, 'mini'])->name('notifications.mini');
    Route::get('notifications/count', [NotificationController::class, 'count'])->name('notifications.count');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.readall');
    Route::get('notifications/{id}/go', [NotificationController::class, 'go'])->name('notifications.go');
    Route::get('reports/finance', [ReportController::class, 'finance'])->name('reports.finance');
    Route::get('ceo', [CeoController::class, 'index'])->name('ceo');

