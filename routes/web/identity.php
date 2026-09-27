<?php

/*
|---------------------------------------------------------------------------
| مساراتُ الويب — مركزُ الهويّة
|---------------------------------------------------------------------------
| جزءٌ من مجموعة `auth` في routes/web.php يُضمَّن **في موضعه نفسِه** (docs/REORG_PLAN.md §R3):
| الترتيبُ يحكم مطابقةَ المسارات ذات المتغيّرات، والوسائطُ موروثةٌ من المجموعة. تغييرُ
| ترتيبٍ أو اسمٍ أو وسيطٍ تُسقطه لقطةُ المسارات (tests/Fixtures/structure/routes.json).
*/

use Illuminate\Support\Facades\Route;

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

    // (Project 360 · §19/§20/§21) تخصيصُ الأصولِ للمشاريع — علاقةٌ زمنيّةٌ لا عهدةٌ، بخدمةٍ واحدة.
    // العميلُ محجوبٌ (PortalGuard + abort)، والحلُّ ضمنَ النطاق (٤٠٤ عبر الشركات).
    Route::post('projects/{id}/assets', [\App\Http\Controllers\Web\AssetProjectController::class, 'assignFromProject'])
        ->middleware('throttle:30,1')->name('projects.assets.assign');
    Route::post('assets/{id}/projects', [\App\Http\Controllers\Web\AssetProjectController::class, 'assignFromAsset'])
        ->middleware('throttle:30,1')->name('assets.projects.assign');
    Route::post('asset-project/{id}/end', [\App\Http\Controllers\Web\AssetProjectController::class, 'end'])
        ->middleware('throttle:30,1')->name('assetproject.end');
    Route::get('kpis', [\App\Http\Controllers\Web\KpiController::class, 'index'])->name('kpis.index');
    Route::post('kpis', [\App\Http\Controllers\Web\KpiController::class, 'store'])->name('kpis.store');
    Route::put('kpis/{id}', [\App\Http\Controllers\Web\KpiController::class, 'update'])->name('kpis.update');
    // إسنادُ المالكِ المقترَح: نقرةٌ تعتمد ما عُرض بدليله — والجماعيُّ للمؤكَّدِ وحدَه
    Route::post('kpis/adopt-all', [\App\Http\Controllers\Web\KpiController::class, 'adoptAll'])->name('kpis.adoptAll');
    Route::post('kpis/{id}/adopt', [\App\Http\Controllers\Web\KpiController::class, 'adopt'])->name('kpis.adopt');
    Route::post('kpis/{id}/toggle', [\App\Http\Controllers\Web\KpiController::class, 'toggle'])->name('kpis.toggle');
    Route::post('kpis/{id}/move', [\App\Http\Controllers\Web\KpiController::class, 'move'])->name('kpis.move');
    Route::delete('kpis/{id}', [\App\Http\Controllers\Web\KpiController::class, 'destroy'])->name('kpis.destroy');

