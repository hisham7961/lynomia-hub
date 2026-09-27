<?php

/*
|---------------------------------------------------------------------------
| مساراتُ الويب — العهدةُ والمحطّاتُ والنقاطُ الطرفيّة وMDM والجرد
|---------------------------------------------------------------------------
| جزءٌ من مجموعة `auth` في routes/web.php يُضمَّن **في موضعه نفسِه** (docs/REORG_PLAN.md §R3):
| الترتيبُ يحكم مطابقةَ المسارات ذات المتغيّرات، والوسائطُ موروثةٌ من المجموعة. تغييرُ
| ترتيبٍ أو اسمٍ أو وسيطٍ تُسقطه لقطةُ المسارات (tests/Fixtures/structure/routes.json).
*/

use App\Http\Controllers\Web\AttachmentController;
use Illuminate\Support\Facades\Route;

    // ── قسم العهد: كتالوجٌ بالأصناف وأكوادها، وملصقٌ وبطاقةٌ وتصاريحُ نقلٍ وخروج ──
    Route::prefix('custody')->name('custody.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Web\CustodyController::class, 'catalog'])->name('catalog');
        Route::get('cat/{code}', [\App\Http\Controllers\Web\CustodyController::class, 'category'])->name('category');
        Route::get('{id}/label', [\App\Http\Controllers\Web\CustodyController::class, 'label'])->name('label');
        Route::get('{id}/spec', [\App\Http\Controllers\Web\CustodyController::class, 'spec'])->name('spec');
        Route::post('{id}/specs', [\App\Http\Controllers\Web\CustodyController::class, 'saveSpecs'])->name('specs');
        Route::post('{id}/handover', [\App\Http\Controllers\Web\CustodyController::class, 'handover'])->name('handover');
        Route::post('{id}/recover', [\App\Http\Controllers\Web\CustodyController::class, 'recover'])->name('recover');
        // (الطور F · WP-F.2 · §29–31 · C11) الحالةُ والمحطةُ حقلان مقفلان يُكتبان
        // عبر Custody المقفلة المُدقَّقة وحدَها — لا من النموذج العامّ ولا سحب الكانبان
        Route::post('{id}/status', [\App\Http\Controllers\Web\CustodyController::class, 'changeStatus'])->name('status');
        Route::post('{id}/station', [\App\Http\Controllers\Web\CustodyController::class, 'assignStation'])->name('station');
        Route::post('{id}/permit', [\App\Http\Controllers\Web\CustodyController::class, 'permit'])->name('permit');
        Route::get('{id}/permit/{permitId}', [\App\Http\Controllers\Web\CustodyController::class, 'permitDoc'])->name('permit.doc');
        Route::post('{id}/permit/{permitId}/return', [\App\Http\Controllers\Web\CustodyController::class, 'permitReturn'])->name('permit.return');
        Route::post('{id}/permit/{permitId}/cancel', [\App\Http\Controllers\Web\CustodyController::class, 'permitCancel'])->name('permit.cancel');
    });

    // ── المحطات: الإسناد والإخلاء (Work OS · الطور F · WP-F.1 · §26) ──
    // الـCRUD/العرض من m.* (وحدةُ stations في السجل). هذان المساران يكتبان شاغلَ
    // المقعد عبر المعاملةِ المقفلةِ المُدقَّقة وحدَها (نمطُ Custody::move) — فحقلُ
    // current_employee_id مقفلٌ عن CRUD. داخليّةٌ فقط: العميلُ ٤٠٤ (PortalGuard فوق
    // المصفوفة)، والحرسُ في المتحكّم (stations:e + عزلُ الشركة). حدٌّ للمعدل كبقية الكتابة.
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('stations/{id}/assign', [\App\Http\Controllers\Web\StationController::class, 'assign'])->name('stations.assign');
        Route::post('stations/{id}/vacate', [\App\Http\Controllers\Web\StationController::class, 'vacate'])->name('stations.vacate');
    });

    // ── النقاط الطرفية: سكُّ رمزِ تسجيلٍ (Work OS · الطور J · WP-J.1 · §43) ──
    // داخليٌّ حصراً: PortalGuard قائمةٌ بيضاءُ لا تضمّ enroll.* → حسابُ العميل ٤٠٤
    // فوق المصفوفة (ودفاعٌ ثانٍ في المتحكّم). المتحكّمُ يحرس: مالك/مراقب +
    // `hub_require_stepup` + إسنادُ شركةٍ ضمن نطاق الساكّ (عبرَ شركةٍ ٤٠٤) +
    // قيدُ تدقيق. النصُّ الصريح يُعرَض مرةً واحدةً — القاعدةُ لا تحمل إلا sha256.
    Route::post('endpoints/enroll-token', [\App\Http\Controllers\Api\EndpointEnrollController::class, 'mint'])
        ->middleware('throttle:30,1')->name('enroll.mint');

    // ── النقاط الطرفية: إصدارُ أمرٍ لجهاز (Work OS · الطور J · WP-J.2 · §43) ──
    // داخليٌّ حصراً كنظيره أعلاه (PortalGuard قائمةٌ بيضاء → العميل ٤٠٤ + دفاعٌ
    // في المتحكّم). القائمةُ المغلقة الخمسة لا غير (C10 — لا shell)؛ isolate/lock
    // تصعيدُ هويةٍ + سببٌ إلزاميّ + قيدُ تدقيق؛ وUNIQUE(device_id,ikey) يجعل
    // الإصدارَ المكرَّر يعيد الأمرَ القائم لا أمراً ثانياً.
    Route::post('endpoints/{id}/command', [\App\Http\Controllers\Api\EndpointProtocolController::class, 'issue'])
        ->middleware('throttle:60,1')->name('endpoints.command');

    // ── مركزُ النقاط الطرفية (Work OS · الطور J · WP-J.3 · §43/§63) ──
    // قراءةٌ للمالك/المراقب فوق سجل J.1 وبروتوكول J.2 — لا كاتبَ فيه (الأوامرُ
    // والسكُّ بمساريهما المقفلين أعلاه). داخليٌّ حصراً: PortalGuard قائمةٌ بيضاءُ
    // لا تضمّ endpoints.* → حسابُ العميل ٤٠٤ فوق المصفوفة، ودفاعٌ ثانٍ في
    // المتحكّم؛ وعزلُ الشركة على كل قارئ (جهازٌ أجنبيّ ٤٠٤). الوضعيّةُ تُعرَض
    // **صادقةً** (C15): الممنوعُ «غير مُهيّأ» لا «فعّالة»، وUSB بلا MDM رصدٌ فقط.
    Route::get('endpoints', [\App\Http\Controllers\Web\EndpointCentreController::class, 'index'])->name('endpoints.index');

    // ── مركزُ تنزيل الوكيل (Work OS · الطور L · WP-L.2 · §44/§62) ──
    // انضباطُ AttachmentController — **لا سكّةَ تقديمٍ ثانية ولا public storage**:
    // ملفاتٌ تحت storage/app/agent-releases/ تُقدَّم attachment وتُسجَّل في
    // download_log. داخليٌّ حصراً (PortalGuard قائمةٌ بيضاءُ لا تضمّ
    // endpoints.releases* → العميلُ ٤٠٤ فوق المصفوفة + دفاعٌ في المتحكّم)؛
    // الإدارةُ (نشر/سحب) للمالك وحدَه خلف hub_require_stepup — نشرُ ثنائيّةٍ
    // يبتلعها الأسطولُ كلُّه قرارُ سلسلةِ توريد؛ والتنزيلُ لكل داخليٍّ مُصادَق.
    // sha256 تُحسب خادمياً، وsigning_status **صادقةٌ دوماً**: unsigned-dev —
    // لا شهادةَ مُهيّأةً فلا ادّعاءَ توقيعٍ (C15). **قبل** endpoints/{id} كي
    // لا يبتلع الوسيطُ الجامح كلمةَ releases.
    Route::get('endpoints/releases', [\App\Http\Controllers\Web\EndpointReleaseController::class, 'index'])->name('endpoints.releases');
    Route::get('endpoints/releases/{id}/download', [\App\Http\Controllers\Web\EndpointReleaseController::class, 'download'])->name('endpoints.releases.download');
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('endpoints/releases', [\App\Http\Controllers\Web\EndpointReleaseController::class, 'store'])->name('endpoints.releases.store');
        Route::post('endpoints/releases/{id}/publish', [\App\Http\Controllers\Web\EndpointReleaseController::class, 'publish'])->name('endpoints.releases.publish');
        Route::post('endpoints/releases/{id}/delete', [\App\Http\Controllers\Web\EndpointReleaseController::class, 'destroy'])->name('endpoints.releases.delete');
    });

    // ── تكاملُ MDM (Intune/Jamf) — مسارُ التصحيح §8/§9 ──
    // إدارةُ وصلةِ التكامل: للمالك وحدَه (تكاملٌ يمسّ سياسةَ الأسطول)، والعميلُ ٤٠٤.
    // الوصلةُ تحمل الإعدادَ لا السرَّ (السرُّ في VaultSecret مشفَّراً). كلُّ المزوّدات
    // اليومَ **رصدٌ فقط** (جسرُ الفرض الحيّ مؤجَّل) — لا حجبَ يُزعَم (C15). **قبل**
    // endpoints/{id} كي لا يبتلع الوسيطُ الجامح كلمةَ mdm.
    Route::get('endpoints/mdm', [\App\Http\Controllers\Web\EndpointMdmController::class, 'index'])->name('endpoints.mdm');
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('endpoints/mdm', [\App\Http\Controllers\Web\EndpointMdmController::class, 'store'])->name('endpoints.mdm.store');
        Route::post('endpoints/mdm/{id}/toggle', [\App\Http\Controllers\Web\EndpointMdmController::class, 'toggle'])->name('endpoints.mdm.toggle');
        Route::post('endpoints/mdm/{id}/health', [\App\Http\Controllers\Web\EndpointMdmController::class, 'health'])->name('endpoints.mdm.health');
        Route::post('endpoints/mdm/{id}/sync', [\App\Http\Controllers\Web\EndpointMdmController::class, 'sync'])->name('endpoints.mdm.sync');
        Route::post('endpoints/mdm/{id}/delete', [\App\Http\Controllers\Web\EndpointMdmController::class, 'destroy'])->name('endpoints.mdm.delete');
    });

    Route::get('endpoints/{id}', [\App\Http\Controllers\Web\EndpointCentreController::class, 'show'])->name('endpoints.show');

    // ── عهدةُ الموظف المالية (Work OS · الطور E · WP-E.3 · §19/§28/§98) ──
    // محفظةٌ مشتقّةُ الرصيد **منفصلةٌ تماماً** عن عهدة الأصول أعلاه (تلك وحدةُ `assets`،
    // وهذه وحدةُ `custody`). داخليّةٌ حصراً: `PortalGuard` قائمةٌ بيضاء والعهدةُ ليست
    // فيها، فحسابُ العميل ٤٠٤ على كلّ مسارٍ هنا فوق المصفوفة. المتحكّمُ يحرس كلَّ مسارٍ
    // بـ`hub_can('custody',...)`+عزلِ الشركة؛ والعكسُ/التصحيحُ خلفَ `hub_require_stepup`.
    Route::prefix('custody-wallet')->name('custody.wallet.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Web\EmployeeCustodyController::class, 'center'])->name('center');
        Route::get('e/{id}', [\App\Http\Controllers\Web\EmployeeCustodyController::class, 'employee'])->name('employee');
        Route::middleware('throttle:60,1')->group(function () {
            Route::post('advance',    [\App\Http\Controllers\Web\EmployeeCustodyController::class, 'advance'])->name('advance');
            Route::post('charge',     [\App\Http\Controllers\Web\EmployeeCustodyController::class, 'charge'])->name('charge');
            Route::post('expense',    [\App\Http\Controllers\Web\EmployeeCustodyController::class, 'expense'])->name('expense');
            Route::post('repayment',  [\App\Http\Controllers\Web\EmployeeCustodyController::class, 'repayment'])->name('repayment');
            Route::post('transfer',   [\App\Http\Controllers\Web\EmployeeCustodyController::class, 'transfer'])->name('transfer');
            Route::post('deduction',  [\App\Http\Controllers\Web\EmployeeCustodyController::class, 'deduction'])->name('deduction');
            Route::post('settlement', [\App\Http\Controllers\Web\EmployeeCustodyController::class, 'settlement'])->name('settlement');
            Route::post('correct',    [\App\Http\Controllers\Web\EmployeeCustodyController::class, 'correct'])->name('correct');
            Route::post('{id}/reverse', [\App\Http\Controllers\Web\EmployeeCustodyController::class, 'reverse'])->name('reverse');
        });
    });

    // ── جلساتُ الجرد (Work OS · الطور F · WP-F.3 · §32/§99) ──
    // حاويةٌ يقودها InventoryController (لقطةٌ مجمَّدة → مسحٌ مُصادَق → مصالحة) فوق سكّتين
    // قائمتين: `Custody::scoped` (الأصولُ المنطَّقة) و`Identity::resolve` (المحلِّلُ الموحّد).
    // داخليّةٌ حصراً: `PortalGuard` قائمةٌ بيضاءُ لا تضمّ inventory.* → حسابُ العميل ٤٠٤ فوق
    // المصفوفة. المتحكّمُ يحرس كلَّ مسارٍ بـ`hub_can('assets',...)`+عزلِ الشركة؛ والإغلاقُ/
    // المصالحةُ الكتابيّة خلفَ `hub_require_stepup`. حدٌّ للمعدل على المسح كبقية الكتابة.
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Web\InventoryController::class, 'center'])->name('center');
        Route::get('{id}', [\App\Http\Controllers\Web\InventoryController::class, 'show'])->name('show');
        Route::middleware('throttle:120,1')->group(function () {
            Route::post('freeze', [\App\Http\Controllers\Web\InventoryController::class, 'freeze'])->name('freeze');
            Route::post('{id}/scan', [\App\Http\Controllers\Web\InventoryController::class, 'scan'])->name('scan');
            Route::post('{id}/reconcile', [\App\Http\Controllers\Web\InventoryController::class, 'reconcile'])->name('reconcile');
            Route::post('{id}/close', [\App\Http\Controllers\Web\InventoryController::class, 'close'])->name('close');
        });
    });

    // مسحُ ملصق منتجٍ (p/{code}) — نظيرُ c/{code} للعهدة: كودٌ ← سجلُّ طرازه
    Route::get('p/{code}', [\App\Http\Controllers\Web\IdentityController::class, 'byCode'])->name('products.code');

