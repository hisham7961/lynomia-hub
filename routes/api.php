<?php

use App\Http\Controllers\Api\V1Controller;
use App\Http\Middleware\ApiAuth;
use Illuminate\Support\Facades\Route;

/** REST API v1 — Authorization: Bearer <token> · نفس صلاحيات ونطاق الواجهة.
 *  حدُّ المعدل (120/دقيقة لكل مفتاح) كان مُعرَّفاً في AppServiceProvider بلا
 *  مسارٍ يحمله — تعريفٌ بلا تطبيق: عميلٌ جامح كان يضرب بلا سقف. */
Route::prefix('v1')->middleware([ApiAuth::class, 'throttle:api'])->group(function () {
    Route::get('me', [V1Controller::class, 'me']);
    Route::get('modules', [V1Controller::class, 'modules']);

    Route::get('reports/progress/{projectId}', [V1Controller::class, 'progress']);
    Route::get('reports/health', [V1Controller::class, 'health']);

    // المقاييس الزمنية: استقبالٌ آلي (n8n) وقراءةُ السلسلة — قبل {module} كي لا تبتلعها
    Route::post('metrics', [V1Controller::class, 'metricsIngest']);
    Route::get('metrics/{module}/{id}', [V1Controller::class, 'metricsShow']);

    Route::get('{module}', [V1Controller::class, 'apiIndex']);
    Route::post('{module}', [V1Controller::class, 'apiStore']);
    Route::get('{module}/{id}', [V1Controller::class, 'apiShow']);
    Route::put('{module}/{id}', [V1Controller::class, 'apiUpdate']);
    Route::delete('{module}/{id}', [V1Controller::class, 'apiDestroy']);
});
