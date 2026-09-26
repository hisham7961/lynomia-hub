<?php

/*
|---------------------------------------------------------------------------
| مساراتُ الويب — يومُ العمل ومركزُ التقارير اليوميّة
|---------------------------------------------------------------------------
| جزءٌ من مجموعة `auth` في routes/web.php يُضمَّن **في موضعه نفسِه** (docs/REORG_PLAN.md §R3):
| الترتيبُ يحكم مطابقةَ المسارات ذات المتغيّرات، والوسائطُ موروثةٌ من المجموعة. تغييرُ
| ترتيبٍ أو اسمٍ أو وسيطٍ تُسقطه لقطةُ المسارات (tests/Fixtures/structure/routes.json).
*/

use App\Http\Controllers\Web\MorningController;
use Illuminate\Support\Facades\Route;

    // ── يوم العمل: حضورٌ وانصرافٌ بضغطة، وشاشةُ الفريق اليومية للمدير ──
    Route::post('workday/check-in', [\App\Http\Controllers\Web\WorkdayController::class, 'checkIn'])
        ->middleware('throttle:30,1')->name('workday.in');
    Route::post('workday/check-out', [\App\Http\Controllers\Web\WorkdayController::class, 'checkOut'])
        ->middleware('throttle:30,1')->name('workday.out');
    Route::get('workforce', [\App\Http\Controllers\Web\WorkdayController::class, 'team'])->name('workforce.team');

    // ── مركزُ التقارير اليوميّة (الحضور × التقرير × الامتثال) — قراءةٌ ومراجعةٌ فوق
    //    WorkUpdate القائم؛ الحرّاس في المتحكّم (شركةٌ/مشروعٌ/HR)، والعميلُ يُردّ ٤٠٤. ──
    Route::get('reports/daily', [\App\Http\Controllers\Web\ReportsController::class, 'index'])->name('reports.index');
    Route::get('reports/daily/day', [\App\Http\Controllers\Web\ReportsController::class, 'day'])->name('reports.day');
    Route::get('reports/review', [\App\Http\Controllers\Web\ReportsController::class, 'review'])->name('reports.review');
    Route::get('my/report', [\App\Http\Controllers\Web\ReportsController::class, 'mine'])->name('reports.mine');
    // الحضورُ الشهريّ (سجلٌّ لكلِّ موظّف + تصديرٌ للمحاسب) — يكفيه attend:v أو hr:v
    Route::get('reports/monthly', [\App\Http\Controllers\Web\ReportsController::class, 'monthly'])->name('reports.monthly');
    Route::get('reports/monthly/employee', [\App\Http\Controllers\Web\ReportsController::class, 'monthlyEmployee'])->name('reports.monthly.employee');
    Route::get('reports/monthly/export', [\App\Http\Controllers\Web\ReportsController::class, 'monthlyExport'])->name('reports.monthly.export');
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('reports/review/{id}', [\App\Http\Controllers\Web\ReportsController::class, 'reviewAct'])->name('reports.review.act');
        // «حوّله إلى بلاغ» — المعوّقُ المبلَّغُ يصير التزاماً بمالكٍ وموعد (v2.558)
        Route::post('reports/blocker/{id}/to-issue', [\App\Http\Controllers\Web\ReportsController::class, 'blockerToIssue'])->name('reports.blocker.issue');
        Route::post('reports/compliance/{id}/finalize', [\App\Http\Controllers\Web\ReportsController::class, 'finalize'])->name('reports.finalize');
    });

    // العرض الميدانيّ للمشرف: لوحةٌ تحليلية، وجلساتُ التتبّع، وإعادةُ عرض المسار
    Route::get('field', [\App\Http\Controllers\Web\FieldController::class, 'dashboard'])->name('field.dashboard');
    Route::get('sales', [\App\Http\Controllers\Web\SalesController::class, 'dashboard'])->name('sales.dashboard');
    Route::get('field/sessions', [\App\Http\Controllers\Web\FieldController::class, 'index'])->name('field.sessions');
    Route::get('field/route/{id}', [\App\Http\Controllers\Web\FieldController::class, 'route'])->name('field.route');

    Route::get('morning', [MorningController::class, 'index'])->name('morning');

