<?php

use App\Http\Controllers\Api\V1Controller;
use App\Http\Middleware\ApiAuth;
use Illuminate\Support\Facades\Route;

/** REST API v1 — Authorization: Bearer <token> · نفس صلاحيات ونطاق الواجهة */
Route::prefix('v1')->middleware(ApiAuth::class)->group(function () {
    Route::get('me', [V1Controller::class, 'me']);
    Route::get('modules', [V1Controller::class, 'modules']);

    Route::get('reports/progress/{projectId}', [V1Controller::class, 'progress']);
    Route::get('reports/health', [V1Controller::class, 'health']);

    Route::get('{module}', [V1Controller::class, 'apiIndex']);
    Route::post('{module}', [V1Controller::class, 'apiStore']);
    Route::get('{module}/{id}', [V1Controller::class, 'apiShow']);
    Route::put('{module}/{id}', [V1Controller::class, 'apiUpdate']);
    Route::delete('{module}/{id}', [V1Controller::class, 'apiDestroy']);
});
