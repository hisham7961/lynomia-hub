<?php

/*
|---------------------------------------------------------------------------
| مساراتُ الويب — التتبّعُ ورحلةُ العميل والابتكارُ والسياساتُ والأهدافُ والمراقبةُ والمرفقات
|---------------------------------------------------------------------------
| جزءٌ من مجموعة `auth` في routes/web.php يُضمَّن **في موضعه نفسِه** (docs/REORG_PLAN.md §R3):
| الترتيبُ يحكم مطابقةَ المسارات ذات المتغيّرات، والوسائطُ موروثةٌ من المجموعة. تغييرُ
| ترتيبٍ أو اسمٍ أو وسيطٍ تُسقطه لقطةُ المسارات (tests/Fixtures/structure/routes.json).
*/

use App\Http\Controllers\Web\AppCenterController;
use App\Http\Controllers\Web\AttachmentController;
use App\Http\Controllers\Web\JourneyController;
use App\Http\Controllers\Web\TraceController;
use App\Http\Controllers\Web\PerformanceController;
use App\Http\Controllers\Web\PortalController;
use App\Http\Controllers\Web\LegalController;
use App\Http\Controllers\Web\PurchaseController;
use App\Http\Controllers\Web\QuoteBuilderController;
use App\Http\Controllers\Web\QuoteController;
use App\Http\Controllers\Web\SupportController;
use Illuminate\Support\Facades\Route;

    // ── خيط التتبع من الفكرة إلى النشر ──
    Route::get('trace/{module}/{id}', [TraceController::class, 'show'])->name('trace');

    // ── رحلة العميل ──
    Route::get('journey/{id}', [JourneyController::class, 'show'])->name('journey');

    // ── مركز الابتكار ──
    Route::get('innovation', [\App\Http\Controllers\Web\InnovationController::class, 'index'])->name('innovation');
    Route::post('ideas/{id}/promote', [\App\Http\Controllers\Web\InnovationController::class, 'promote'])->name('ideas.promote');

    // ── مركز السياسات والإقرارات (والمعرفة الإلزامية) ──
    Route::get('policies', [\App\Http\Controllers\Web\PolicyController::class, 'index'])->name('policies.board');
    Route::post('{module}/{id}/announce', [\App\Http\Controllers\Web\PolicyController::class, 'announce'])
        ->whereIn('module', ['policies', 'kb'])->name('acks.announce');
    Route::post('{module}/{id}/ack', [\App\Http\Controllers\Web\PolicyController::class, 'ack'])
        ->whereIn('module', ['policies', 'kb'])->name('acks.ack');

    // ── لوحة الأهداف والنتائج ──
    Route::get('okrs', [\App\Http\Controllers\Web\OkrController::class, 'index'])->name('okrs.board');
    Route::post('okrs/refresh', [\App\Http\Controllers\Web\OkrController::class, 'refresh'])->name('okrs.refresh');

    // ── المراقبة الحيّة: فحصٌ عند الطلب لسيرفر أو موقع ──
    // حدُّ معدّل (v2.324): الفحصُ يحجز عاملاً ثوانيَ طويلة (مهلةُ الشبكة)، وكان
    // بلا سقف — فضغطاتٌ متتالية تستهلك عمّالَ الخادم كلَّهم بلا أي استغلال
    Route::post('monitor/{module}/{id}/check', [\App\Http\Controllers\Web\MonitorController::class, 'check'])
        ->middleware('throttle:10,1')->name('monitor.check');

    // ── مركز السوشال ميديا: مراقبة وتحليل ──
    Route::get('social', [\App\Http\Controllers\Web\SocialController::class, 'index'])->name('social.index');
    Route::post('social/snapshot', [\App\Http\Controllers\Web\SocialController::class, 'snapshot'])->name('social.snapshot');

    // ── المرفقات الشاملة على أي سجل ──
    // ── الرفعُ المقطَّع: قطعٌ صغيرةٌ تصل حيث لا يمرّ الملفُّ الكبير ──
    // معدّلٌ واسع: غيغابايتٌ بقطعٍ ٤ م.ب = ٢٥٦ طلباً — والحدُّ يحمي من الإغراق
    Route::post('uploads/chunk', [\App\Http\Controllers\Web\UploadChunkController::class, 'chunk'])
        ->name('upload.chunk')->middleware('throttle:900,1');
    Route::post('uploads/finish', [\App\Http\Controllers\Web\UploadChunkController::class, 'finish'])
        ->name('upload.finish')->middleware('throttle:120,1');

    Route::post('attachments', [AttachmentController::class, 'store'])->name('att.store');
    Route::get('attachments/{id}/dl', [AttachmentController::class, 'download'])->name('att.dl');
    Route::get('attachments/{id}/view', [AttachmentController::class, 'preview'])->name('att.view');
    // حزمةُ مرفقات سجلٍّ واحد — بصلاحية السجل نفسه
    Route::get('attachments/{module}/{recordId}/zip', [AttachmentController::class, 'zip'])->name('att.zip');
    Route::post('attachments/{id}/move', [AttachmentController::class, 'move'])->name('att.move');
    // قواعدُ الوصولِ للوثيقةِ على مستوى المورد (المستوى 5/6) — للمالكِ أو محرِّرِ وحدةِ السجل
    Route::post('attachments/{id}/access', [AttachmentController::class, 'access'])->name('att.access');
    Route::delete('attachments/{id}/access/{ruleId}', [AttachmentController::class, 'accessClear'])->name('att.access.clear');
    Route::delete('attachments/{id}', [AttachmentController::class, 'destroy'])->name('att.destroy');
    Route::get('employee/{id}', [PortalController::class, 'employee'])->name('portal.employee');
    Route::get('app/{id}', [AppCenterController::class, 'show'])->name('apps.center');
    Route::get('quote/{id}/doc', [QuoteController::class, 'doc'])->name('quotes.doc');
    Route::get('quote/{id}/pdf', [QuoteController::class, 'pdf'])->name('quotes.pdf');
    Route::get('quote/{id}/diff', [QuoteController::class, 'diff'])->name('quotes.diff');
    Route::post('quote/{id}/act', [QuoteController::class, 'act'])->name('quotes.act');
    // بنّاء العرض المهنيّ: بنودٌ مهيكلة ومراحلُ دفع (تُعيد حساب الإجمالي خادمياً)
    Route::post('quote/{id}/line', [QuoteBuilderController::class, 'storeLine'])->name('quotes.line.store');
    Route::delete('quote/{id}/line/{line}', [QuoteBuilderController::class, 'destroyLine'])->name('quotes.line.destroy');
    Route::post('quote/{id}/line/{line}/toggle', [QuoteBuilderController::class, 'toggleLine'])->name('quotes.line.toggle');
    // أوامر التغيير: تطبيقٌ على المشروع + مستند PDF
    Route::post('changeorder/{id}/apply', [\App\Http\Controllers\Web\ChangeOrderController::class, 'apply'])->name('changeorders.apply');
    Route::get('changeorder/{id}/pdf', [\App\Http\Controllers\Web\ChangeOrderController::class, 'pdf'])->name('changeorders.pdf');
    Route::post('quote/{id}/milestone', [QuoteBuilderController::class, 'storeMilestone'])->name('quotes.ms.store');
    Route::delete('quote/{id}/milestone/{ms}', [QuoteBuilderController::class, 'destroyMilestone'])->name('quotes.ms.destroy');
    Route::post('fin/{id}/act', [\App\Http\Controllers\Web\FinController::class, 'act'])->name('fin.act');
    Route::post('entry/{id}/line', [\App\Http\Controllers\Web\EntryController::class, 'line'])->name('entries.line');
    Route::delete('entry/{id}/line/{lineId}', [\App\Http\Controllers\Web\EntryController::class, 'dropLine'])->name('entries.line.drop');
    Route::post('entry/{id}/post', [\App\Http\Controllers\Web\EntryController::class, 'post'])->name('entries.post');
    Route::post('stockmv/{id}/act', [\App\Http\Controllers\Web\StockController::class, 'act'])->name('stockmv.act');
    Route::post('payroll/{id}/act', [\App\Http\Controllers\Web\PayrollController::class, 'act'])->name('payroll.act');
    Route::post('candidates/{id}/hire', [\App\Http\Controllers\Web\HireController::class, 'hire'])->name('recruit.hire');
    Route::post('meetings/{id}/extract', [\App\Http\Controllers\Web\MinutesController::class, 'extract'])->name('meetings.extract');
    Route::get('supplier-scores', [PurchaseController::class, 'scores'])->name('supplierscores');
    Route::get('purchase/{id}/doc', [PurchaseController::class, 'doc'])->name('purchases.doc');
    Route::post('purchase/{id}/act', [PurchaseController::class, 'act'])->name('purchases.act');
    // CTO م2 (v2.134): صفحات مساحات العمل المركزية
    // (الطور H · WP-H.3 · §37–38) مساحةُ العمل التقنية — توسيعٌ لمساحة /w/digital:
    // تبويباتٌ تجمع وحداتِ البنية القائمة + تحليلاتِ DigitalAssets. داخليّةٌ حصراً:
    // PortalGuard يردّ حسابَ العميل ٤٠٤ قبلها، والمتحكّمُ يعيد الرفضَ الصلبَ فوق
    // المصفوفة (عميلٌ أو معزولٌ بعملاء → ٤٠٤) ثم `$owner || hub_monitor`.
    Route::get('w/digital/tech', [\App\Http\Controllers\Web\TechWorkspaceController::class, 'index'])->name('tech.workspace');
    // (الطور H · WP-H.2 · §33–36) مستكشفُ العلاقات — إسقاطٌ فوق النماذج الحيّة
    // (لا شجرةَ مخزّنة): كلُّ عقدةٍ عبر hub_read داخل RelationshipProjection.
    // داخليٌّ حصراً: PortalGuard يردّ حسابَ العميل ٤٠٤ قبله، والمتحكّمُ يعيد
    // الرفضَ الصلب (عميلٌ أو معزولٌ بعملاء → ٤٠٤) دفاعاً في العمق.
    // معاملاتُ الهدف query لا مسار — كي تركبها عروضُ SavedView (module='graph') حرفياً.
    Route::get('graph/explore', [\App\Http\Controllers\Web\RelationshipExplorerController::class, 'explore'])->name('graph.explore');
    Route::get('graph/expand', [\App\Http\Controllers\Web\RelationshipExplorerController::class, 'expandNode'])->name('graph.expand');
    Route::get('w/{key}', [\App\Http\Controllers\Web\WorkspaceController::class, 'show'])->name('workspace');
    Route::get('legal', [LegalController::class, 'index'])->name('legal');
    Route::post('legal/rules/{id}/enable', [LegalController::class, 'enableRule'])->name('legal.rule.enable');
    Route::get('support', [SupportController::class, 'index'])->name('support');
    Route::get('performance', [PerformanceController::class, 'index'])->name('performance');

