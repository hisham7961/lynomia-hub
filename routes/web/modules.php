<?php

/*
|---------------------------------------------------------------------------
| مساراتُ الويب — الوحدات (سجلُّ الوحدات يقود كلَّ شيء)
|---------------------------------------------------------------------------
| جزءٌ من مجموعة `auth` في routes/web.php يُضمَّن **في موضعه نفسِه** (docs/REORG_PLAN.md §R3):
| الترتيبُ يحكم مطابقةَ المسارات ذات المتغيّرات، والوسائطُ موروثةٌ من المجموعة. تغييرُ
| ترتيبٍ أو اسمٍ أو وسيطٍ تُسقطه لقطةُ المسارات (tests/Fixtures/structure/routes.json).
*/

use App\Http\Controllers\Web\ImportController;
use App\Http\Controllers\Web\ModuleController;
use Illuminate\Support\Facades\Route;

    // ── الوحدات (سجل الوحدات يقود كل شيء) ──
    Route::prefix('m/{module}')->name('m.')->group(function () {
        Route::get('/', [ModuleController::class, 'index'])->name('index');
        Route::get('board', [ModuleController::class, 'board'])->name('board');
        // التصدير مسارُ استنزافٍ جماعي — يُحدّ معدله كبقية المسارات الحساسة (v2.367)
        Route::get('export', [ModuleController::class, 'export'])->name('export')->middleware('throttle:20,1');
        Route::get('create', [ModuleController::class, 'create'])->name('create');
        Route::get('import', [ImportController::class, 'form'])->name('import');
        // الاستيرادُ مسارُ كتابةٍ جماعيّةٍ ثقيل — يُحدّ معدلُه كالتصدير والجماعيّ (Permissions 360 · 17.4)
        Route::post('import', [ImportController::class, 'map'])->name('import.map')->middleware('throttle:20,1');
        Route::post('import/run', [ImportController::class, 'run'])->name('import.run')->middleware('throttle:20,1');
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

