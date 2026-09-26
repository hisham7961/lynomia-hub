<?php

/*
|---------------------------------------------------------------------------
| مساراتُ الويب — «اسأل Hub»
|---------------------------------------------------------------------------
| جزءٌ من مجموعة `auth` في routes/web.php يُضمَّن **في موضعه نفسِه** (docs/REORG_PLAN.md §R3):
| الترتيبُ يحكم مطابقةَ المسارات ذات المتغيّرات، والوسائطُ موروثةٌ من المجموعة. تغييرُ
| ترتيبٍ أو اسمٍ أو وسيطٍ تُسقطه لقطةُ المسارات (tests/Fixtures/structure/routes.json).
*/

use App\Http\Controllers\Web\CapacityController;
use App\Http\Controllers\Web\CostController;
use Illuminate\Support\Facades\Route;

    // ── اسأل Hub (المرحلة ٣ · P3-W6) ──
    // بابُها `AskPolicy::gate()` في المتحكّم — وهو شرطُ ظهورِ رابطِها حرفاً بحرف.
    // و`throttle` من `AskPolicy::THROTTLE` فلا رقمانِ يفترقان بين سياسةٍ ومسار.
    Route::get('ask', [\App\Http\Controllers\Web\AskController::class, 'index'])->name('ask.index');
    Route::post('ask', [\App\Http\Controllers\Web\AskController::class, 'run'])
        ->name('ask.run')->middleware('throttle:' . \App\Support\Ai\Ask\AskPolicy::THROTTLE);
    // تقدّمُ القراءة (SSE) — الحرّاسُ نفسُها والخنقُ نفسُه؛ والجوابُ لا يُبثّ قبل مصادقة مراجعه
    Route::post('ask/stream', [\App\Http\Controllers\Web\AskController::class, 'stream'])
        ->name('ask.stream')->middleware('throttle:' . \App\Support\Ai\Ask\AskPolicy::THROTTLE);
    // ذاكرةُ المحادثة (المرحلة ٢): المحوُ لصاحب الخيط وحدَه — والعرضُ على `ask?thread=` نفسِه
    Route::post('ask/forget', [\App\Http\Controllers\Web\AskController::class, 'forget'])
        ->name('ask.forget')->middleware('throttle:30,1');
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
    Route::post('recommendations/act', [CapacityController::class, 'recAct'])->name('recs.act');
    Route::get('delivery', [\App\Http\Controllers\Web\DeliveryController::class, 'index'])->name('delivery');
    // لوحةُ PSA التشغيليّة (Work OS · الطور D · WP-D.3 · §17) — داخليّةٌ حصراً:
    // PortalGuard يردّ العميلَ ٤٠٤ فوق المصفوفة، والمتحكّمُ يحرسها بـprojects:v.
    Route::get('delivery/psa', [\App\Http\Controllers\Web\DeliveryController::class, 'psa'])->name('delivery.psa');
    Route::get('assets-life', [\App\Http\Controllers\Web\AssetLifeController::class, 'index'])->name('assets.life');
    // مركزُ الكود المصدري: صفحةُ إصداراتٍ على شاكلة ما يعرفه المطوّرون
    Route::get('code-center', [\App\Http\Controllers\Web\CodeCenterController::class, 'index'])->name('code.center');

    // مسحُ ملصق العهدة: مسارٌ قصيرٌ عمداً (`/c/{code}`) — رمزُ QR على ملصقٍ
    // ٤٠×٣٠ مم لا يتّسع لرابطٍ فيه معرّفٌ عشوائيّ بستٍّ وثلاثين خانة: كثافةُ
    // الرمز ترتفع فلا يقرؤه ماسحٌ حراريٌّ ولا هاتفٌ في إضاءةٍ ضعيفة.
    Route::get('c/{code}', [\App\Http\Controllers\Web\CustodyController::class, 'byCode'])->name('custody.code');

