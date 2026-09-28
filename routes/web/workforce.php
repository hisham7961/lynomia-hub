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
    // «تقارير حسب المشروع» — ملخّصُ الذكاء لكلِّ مشروع (ProjectReportDigest)؛ الحرّاس في المتحكّم
    // (نطاقُ المشروع + updates:v، والعميلُ ٤٠٤). «تحديث الآن» مخنوقٌ للمستخدم وللمشروع معاً.
    Route::get('reports/projects', [\App\Http\Controllers\Web\ProjectDigestController::class, 'index'])->name('reports.projects');
    Route::get('reports/projects/{id}', [\App\Http\Controllers\Web\ProjectDigestController::class, 'show'])
        ->whereUuid('id')->name('reports.projects.show');
    Route::post('reports/projects/{id}/refresh', [\App\Http\Controllers\Web\ProjectDigestController::class, 'refresh'])
        ->whereUuid('id')->middleware('throttle:6,10')->name('reports.projects.refresh');
    // «تقارير الأداء (ذكاء اصطناعي)» — تقريرُ أداءٍ لكلِّ موظّفٍ لكلِّ فترة (EmployeePerformance)؛ الحرّاس في
    // المتحكّم (نطاقُ hr أو المديرُ المباشر، والعميلُ ٤٠٤، والموظّفُ نفسُه ٤٠٤). «تحديث» مخنوقٌ للمستخدم وللموظّف.
    Route::get('reports/performance', [\App\Http\Controllers\Web\PerformanceReportController::class, 'index'])->name('reports.performance');
    Route::get('reports/performance/{id}', [\App\Http\Controllers\Web\PerformanceReportController::class, 'show'])
        ->whereUuid('id')->name('reports.performance.show');
    Route::post('reports/performance/{id}/refresh', [\App\Http\Controllers\Web\PerformanceReportController::class, 'refresh'])
        ->whereUuid('id')->middleware('throttle:6,10')->name('reports.performance.refresh');
    // صندوقُ اقتراحات الذكاء (docs/ai-hub/47 §العمود أ) — الحرّاسُ في ProposalService: تعديلُ الوحدة
    // + نطاقُ السجلّ + حجبُ الحقل، والعميلُ ٤٠٤. والقرارُ يكتب في سجلٍّ أعماليّ فيُخنَق.
    Route::get('ai/proposals', [\App\Http\Controllers\Web\AiProposalController::class, 'index'])->name('ai.proposals');
    Route::post('ai/proposals/{id}/apply', [\App\Http\Controllers\Web\AiProposalController::class, 'apply'])
        ->whereUuid('id')->middleware('throttle:60,1')->name('ai.proposals.apply');
    Route::post('ai/proposals/{id}/reject', [\App\Http\Controllers\Web\AiProposalController::class, 'reject'])
        ->whereUuid('id')->middleware('throttle:60,1')->name('ai.proposals.reject');
    // المتابِع (docs/ai-hub/47 §العمود ب): صاحبُ الالتزام يجيب بنقرة، والمديرُ المباشر يرى فريقَه وحدَه
    Route::get('followups', [\App\Http\Controllers\Web\FollowUpController::class, 'mine'])->name('followups.mine');
    Route::get('followups/team', [\App\Http\Controllers\Web\FollowUpController::class, 'team'])->name('followups.team');
    Route::post('followups/{id}/answer', [\App\Http\Controllers\Web\FollowUpController::class, 'answer'])
        ->whereUuid('id')->middleware('throttle:60,1')->name('followups.answer');
    Route::post('followups/{id}/close', [\App\Http\Controllers\Web\FollowUpController::class, 'close'])
        ->whereUuid('id')->middleware('throttle:60,1')->name('followups.close');
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

