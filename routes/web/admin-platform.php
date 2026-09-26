<?php

/*
|---------------------------------------------------------------------------
| مساراتُ الويب — الإدارة: الأمنُ والإعداداتُ والقدراتُ والمساراتُ والحقول
|---------------------------------------------------------------------------
| جزءٌ من مجموعة `auth` في routes/web.php يُضمَّن **في موضعه نفسِه** (docs/REORG_PLAN.md §R3):
| الترتيبُ يحكم مطابقةَ المسارات ذات المتغيّرات، والوسائطُ موروثةٌ من المجموعة. تغييرُ
| ترتيبٍ أو اسمٍ أو وسيطٍ تُسقطه لقطةُ المسارات (tests/Fixtures/structure/routes.json).
*/

use App\Http\Controllers\Web\CustomFieldController;
use App\Http\Controllers\Web\FlowController;
use App\Http\Controllers\Web\SecurityController;
use App\Http\Controllers\Web\SettingController;
use Illuminate\Support\Facades\Route;

    Route::get('admin/integrations/n8n', [\App\Http\Controllers\Web\N8nController::class, 'index'])->name('integrations.n8n');
    Route::post('admin/integrations/n8n', [\App\Http\Controllers\Web\N8nController::class, 'save'])->name('integrations.n8n.save');
    Route::get('admin/security', [SecurityController::class, 'index'])->name('security.index');
    Route::post('admin/security/lockdown', [SecurityController::class, 'lockdown'])->name('security.lockdown');
    Route::post('admin/security/freeze/{key}', [SecurityController::class, 'freeze'])->name('security.freeze');
    Route::post('admin/security/sessions/{id}/revoke', [SecurityController::class, 'revokeSession'])->name('security.session.revoke');
    Route::post('admin/security/users/{id}/revoke', [SecurityController::class, 'revokeUser'])->name('security.user.revoke');
    Route::get('admin/settings', [SettingController::class, 'edit'])->name('settings.edit');
    Route::post('admin/settings', [SettingController::class, 'update'])->name('settings.update');
    // سجلُّ القدرات — الإدارة ← التهيئة ← القدرات (المالك حصراً · FeatureController::gate).
    //    القراءةُ للسجلّ، والتبديلُ عبر Settings::put (كاتبٌ واحد). {key} يحمل نقاطاً.
    Route::get('admin/features', [\App\Http\Controllers\Web\FeatureController::class, 'index'])->name('features.index');
    Route::get('admin/features/{key}', [\App\Http\Controllers\Web\FeatureController::class, 'show'])
        ->where('key', '[A-Za-z0-9_.\-]+')->name('features.show');
    Route::post('admin/features/{key}/toggle', [\App\Http\Controllers\Web\FeatureController::class, 'toggle'])
        ->where('key', '[A-Za-z0-9_.\-]+')->middleware('throttle:30,1')->name('features.toggle');
    Route::post('admin/settings/odoo-test', [SettingController::class, 'odooTest'])->name('settings.odoo.test')->middleware('throttle:10,1');   // (WP-9.4 · §7.10)
    Route::get('admin/flows', [FlowController::class, 'index'])->name('flows.index');
    Route::post('admin/flows', [FlowController::class, 'store'])->name('flows.store');
    Route::post('admin/flows/bulk', [FlowController::class, 'bulk'])->name('flows.bulk');
    Route::get('admin/flows/{id}/edit', [FlowController::class, 'edit'])->name('flows.edit');
    Route::put('admin/flows/{id}', [FlowController::class, 'update'])->name('flows.update');
    Route::post('admin/flows/{id}/duplicate', [FlowController::class, 'duplicate'])->name('flows.duplicate');
    Route::get('admin/flows/{id}/sandbox', [FlowController::class, 'sandbox'])->name('flows.sandbox');
    Route::post('admin/flows/{id}/toggle', [FlowController::class, 'toggle'])->name('flows.toggle');
    Route::delete('admin/flows/{id}', [FlowController::class, 'destroy'])->name('flows.destroy');
    Route::get('admin/fields', [CustomFieldController::class, 'index'])->name('fields.index');
    Route::post('admin/fields', [CustomFieldController::class, 'store'])->name('fields.store');
    Route::delete('admin/fields/{module}/{key}', [CustomFieldController::class, 'destroy'])->name('fields.destroy');

