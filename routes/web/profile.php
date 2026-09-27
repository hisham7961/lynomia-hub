<?php

/*
|---------------------------------------------------------------------------
| مساراتُ الويب — الملفُّ الشخصيّ
|---------------------------------------------------------------------------
| جزءٌ من مجموعة `auth` في routes/web.php يُضمَّن **في موضعه نفسِه** (docs/REORG_PLAN.md §R3):
| الترتيبُ يحكم مطابقةَ المسارات ذات المتغيّرات، والوسائطُ موروثةٌ من المجموعة. تغييرُ
| ترتيبٍ أو اسمٍ أو وسيطٍ تُسقطه لقطةُ المسارات (tests/Fixtures/structure/routes.json).
*/

use App\Http\Controllers\Web\MySecurityController;
use App\Http\Controllers\Web\StepUpController;
use App\Http\Controllers\Web\ProfileController;
use Illuminate\Support\Facades\Route;

    // ── الملف الشخصي ──
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'password'])->name('profile.password');
    Route::put('profile/notify', [ProfileController::class, 'notifyPrefs'])->name('profile.notify');
    Route::post('profile/token', [ProfileController::class, 'tokenStore'])->name('profile.token.store')->middleware('throttle:10,1');
    Route::delete('profile/token/{id}', [ProfileController::class, 'tokenRevoke'])->name('profile.token.revoke');
    Route::post('profile/token/{id}/rotate', [ProfileController::class, 'tokenRotate'])->name('profile.token.rotate');
    Route::post('profile/2fa/start', [ProfileController::class, 'twofaStart'])->name('profile.2fa.start');
    Route::post('profile/2fa/confirm', [ProfileController::class, 'twofaConfirm'])->name('profile.2fa.confirm');
    Route::post('profile/2fa/disable', [ProfileController::class, 'twofaDisable'])->name('profile.2fa.disable');

    // تصعيد المصادقة (Step-Up): إعادةُ تحقّقٍ قبل الأفعال الحسّاسة
    Route::get('stepup', [StepUpController::class, 'show'])->name('stepup.show');
    Route::post('stepup', [StepUpController::class, 'verify'])->name('stepup.verify')->middleware('throttle:10,1');

    // أمني ذاتي: جلساتي وأجهزتي — لكل مستخدمٍ على نفسه (كان الإبطال للمالك فقط)
    // مفاتيحُ المرور — التسجيلُ والتصعيدُ والإدارة (مستخدمٌ داخل)
    Route::post('passkey/register/options', [\App\Http\Controllers\Web\PasskeyController::class, 'registerOptions'])->name('passkey.register.options');
    Route::post('passkey/register/verify', [\App\Http\Controllers\Web\PasskeyController::class, 'registerVerify'])->name('passkey.register.verify');
    Route::delete('passkey/{id}', [\App\Http\Controllers\Web\PasskeyController::class, 'destroy'])->name('passkey.destroy');
    Route::post('passkey/stepup/options', [\App\Http\Controllers\Web\PasskeyController::class, 'stepupOptions'])->name('passkey.stepup.options');
    Route::post('passkey/stepup/verify', [\App\Http\Controllers\Web\PasskeyController::class, 'stepupVerify'])->name('passkey.stepup.verify');

    Route::get('my/security', [MySecurityController::class, 'index'])->name('mysec.index');
    Route::post('my/security/sessions/{id}/revoke', [MySecurityController::class, 'revokeSession'])->name('mysec.session.revoke');
    Route::post('my/security/sessions/others', [MySecurityController::class, 'revokeOthers'])->name('mysec.session.others');
    Route::post('my/security/devices/{id}/trust', [MySecurityController::class, 'trustDevice'])->name('mysec.device.trust');
    Route::post('my/security/devices/{id}/revoke', [MySecurityController::class, 'revokeDevice'])->name('mysec.device.revoke');

