<?php

/*
|---------------------------------------------------------------------------
| مساراتُ الويب — مركزُ الذكاء الاصطناعيّ
|---------------------------------------------------------------------------
| جزءٌ من مجموعة `auth` في routes/web.php يُضمَّن **في موضعه نفسِه** (docs/REORG_PLAN.md §R3):
| الترتيبُ يحكم مطابقةَ المسارات ذات المتغيّرات، والوسائطُ موروثةٌ من المجموعة. تغييرُ
| ترتيبٍ أو اسمٍ أو وسيطٍ تُسقطه لقطةُ المسارات (tests/Fixtures/structure/routes.json).
*/

use Illuminate\Support\Facades\Route;

    // n8n — ربطُ مثيلٍ منفصلٍ يعمل على الخادم (Docker) بالنظام
    /* ── مركزُ الذكاء الاصطناعيّ (v2.559 · المرحلة ١) ─────────────────────
     * الحارسُ في المتحكّم (`AiCenterController::gate`) هو حارسُ الرابطِ في
     * الشريطِ نفسُه — ثابتُ المنصّة: رؤيةُ الرابطِ تطابق بوّابةَ متحكّمِه.
     */
    Route::get('admin/ai', [\App\Http\Controllers\Web\AiCenterController::class, 'index'])->name('ai.index');
    /* ── الأقسامُ السبعةُ (المرحلة ٢ · W8 · §١١) ──────────────────────────
     * `ai.index` صارت **نظرةً** — تجيب «أيعمل؟ وما الخطوةُ التالية؟». وشاشةُ
     * المرحلةِ الأولى انتقلت إلى `ai.settings` بوصفِها قسمَ الإعدادات، **وثلاثةُ
     * مساراتِ الكتابةِ بعناوينِها كما هي** فلا عقدَ يُكسَر.
     */
    Route::get('admin/ai/settings', [\App\Http\Controllers\Web\AiCenterController::class, 'settings'])
        ->name('ai.settings');
    Route::get('admin/ai/usage', [\App\Http\Controllers\Web\AiCenterController::class, 'usage'])
        ->name('ai.usage');
    Route::get('admin/ai/diagnostics', [\App\Http\Controllers\Web\AiCenterController::class, 'diagnostics'])
        ->name('ai.diagnostics');
    // المدقّق (docs/ai-hub/46-ai-roadmap.md §٣.٥ · A4) — دقّتُه وحالتُه: أعدادٌ لا محتوى
    Route::get('admin/ai/auditor', [\App\Http\Controllers\Web\AiAuditorController::class, 'index'])
        ->name('ai.auditor');
    Route::post('admin/ai/auditor/{key}/toggle', [\App\Http\Controllers\Web\AiAuditorController::class, 'toggle'])
        ->name('ai.auditor.toggle')->middleware('throttle:30,1')->where('key', '[a-z_]{3,40}');
    // تصالحُ الحالةِ مع البوّابة (W9 · §١٧) — يخرج إلى الشبكةِ فيُخنَق كنظائرِه
    Route::post('admin/ai/reconcile', [\App\Http\Controllers\Web\AiCenterController::class, 'reconcile'])
        ->name('ai.reconcile')->middleware('throttle:10,1');
    Route::get('admin/ai/models', [\App\Http\Controllers\Web\AiModelController::class, 'all'])
        ->name('ai.models.all');
    Route::post('admin/ai', [\App\Http\Controllers\Web\AiCenterController::class, 'save'])->name('ai.save');
    Route::post('admin/ai/forget-key', [\App\Http\Controllers\Web\AiCenterController::class, 'forgetKey'])->name('ai.forget');
    // الفحصُ يخرج إلى الشبكة، فيُخنَق كنظائرِه (فاحصُ أودو ‎10,1)
    Route::post('admin/ai/test', [\App\Http\Controllers\Web\AiCenterController::class, 'test'])
        ->name('ai.test')->middleware('throttle:10,1');

    /* ── مزوّدو الذكاء — دورةُ حياةِ الاعتماد (المرحلة ٢ · W4) ────────────
     * الحارسُ نفسُه (`AiProviderController::gate`)، و**كلُّ كتابةٍ خلف تصعيدِ
     * الهويّة** كما تنصّ خطّةُ W4 — لا إدخالُ السرِّ وحدَه.
     * والكتابةُ تخرج إلى البوّابة، فتُخنَق كنظائرِها.
     */
    Route::get('admin/ai/providers', [\App\Http\Controllers\Web\AiProviderController::class, 'index'])
        ->name('ai.providers.index');
    Route::post('admin/ai/providers', [\App\Http\Controllers\Web\AiProviderController::class, 'store'])
        ->name('ai.providers.store')->middleware('throttle:20,1');
    // تحديثُ قائمةِ المزوّدين من البوّابة — مسارٌ ساكنٌ قبل المساراتِ ذاتِ المعرّف
    Route::post('admin/ai/providers/refresh', [\App\Http\Controllers\Web\AiProviderController::class, 'refresh'])
        ->name('ai.providers.refresh')->middleware('throttle:10,1');
    Route::post('admin/ai/providers/{provider}/rotate', [\App\Http\Controllers\Web\AiProviderController::class, 'rotate'])
        ->name('ai.providers.rotate')->middleware('throttle:20,1');
    Route::post('admin/ai/providers/{provider}/revoke', [\App\Http\Controllers\Web\AiProviderController::class, 'revoke'])
        ->name('ai.providers.revoke')->middleware('throttle:20,1');
    Route::post('admin/ai/providers/{provider}/toggle', [\App\Http\Controllers\Web\AiProviderController::class, 'toggle'])
        ->name('ai.providers.toggle');
    Route::delete('admin/ai/providers/{provider}', [\App\Http\Controllers\Web\AiProviderController::class, 'destroy'])
        ->name('ai.providers.destroy')->middleware('throttle:20,1');

    /* ── سجلُّ النماذجِ والاكتشاف (المرحلة ٢ · W5) ────────────────────────
     * الحارسُ نفسُه، و**الكتابةُ خلف تصعيدِ الهويّة**؛ أمّا الاكتشافُ فقراءةٌ
     * محضةٌ لا تكتب صفّاً فلا تصعيدَ عليها. والنداءاتُ تخرج إلى البوّابةِ
     * فتُخنَق كنظائرِها.
     */
    Route::get('admin/ai/providers/{provider}/models', [\App\Http\Controllers\Web\AiModelController::class, 'index'])
        ->name('ai.models.index');
    Route::post('admin/ai/providers/{provider}/models/discover', [\App\Http\Controllers\Web\AiModelController::class, 'discover'])
        ->name('ai.models.discover')->middleware('throttle:20,1');
    Route::post('admin/ai/providers/{provider}/models/import', [\App\Http\Controllers\Web\AiModelController::class, 'import'])
        ->name('ai.models.import')->middleware('throttle:20,1');
    Route::post('admin/ai/providers/{provider}/models/register', [\App\Http\Controllers\Web\AiModelController::class, 'register'])
        ->name('ai.models.register')->middleware('throttle:20,1');

    // ═══ الاكتشافُ الموحَّد — شاشةُ الاختيارِ بلا كتابةِ معرّف ═══
    // قراءةٌ محضةٌ بكلفةِ صفر: سجلُّ البوّابةِ وكتالوجُها، ولا طلبَ يبلغ مزوّداً.
    Route::get('admin/ai/providers/{provider}/models/browse', [\App\Http\Controllers\Web\AiModelController::class, 'browse'])
        ->name('ai.models.browse')->middleware('throttle:30,1');
    // والتبنّي كتابةٌ عند البوّابةِ — فيُخنَق كنظائرِه ويمرّ بالتصعيد
    Route::post('admin/ai/providers/{provider}/models/adopt', [\App\Http\Controllers\Web\AiModelController::class, 'adopt'])
        ->name('ai.models.adopt')->middleware('throttle:20,1');
    Route::post('admin/ai/providers/{provider}/models/refresh', [\App\Http\Controllers\Web\AiModelController::class, 'refresh'])
        ->name('ai.models.refresh')->middleware('throttle:20,1');
    Route::post('admin/ai/models/{model}/configure', [\App\Http\Controllers\Web\AiModelController::class, 'configure'])
        ->name('ai.models.configure');
    Route::post('admin/ai/models/{model}/toggle', [\App\Http\Controllers\Web\AiModelController::class, 'toggle'])
        ->name('ai.models.toggle');
    Route::post('admin/ai/models/{model}/override', [\App\Http\Controllers\Web\AiModelController::class, 'override'])
        ->name('ai.models.override');

    /* ── الفواحصُ الخمسة (المرحلة ٢ · W6) — والمستوى A في `ai.test` فلا يُبنى مرّتين.
     * والمدفوعةُ (B · D · E) لا تُنفَّذ إلّا بإقرارٍ صريحٍ بالكلفة.
     */
    Route::post('admin/ai/providers/{provider}/probe', [\App\Http\Controllers\Web\AiProviderController::class, 'probe'])
        ->name('ai.providers.probe')->middleware('throttle:10,1');
    Route::post('admin/ai/models/{model}/probe', [\App\Http\Controllers\Web\AiModelController::class, 'probe'])
        ->name('ai.models.probe')->middleware('throttle:10,1');

    // ═══ دورةُ الحياةِ — بابُ الخروجِ الذي لم يكن ═══
    // الإزالةُ تمسّ الطرفَين (البوّابةَ ثمّ السجلّ)، ففيها تصعيدٌ وخنقٌ كنظائرِها.
    Route::post('admin/ai/models/{model}/unlink', [\App\Http\Controllers\Web\AiModelController::class, 'unlink'])
        ->name('ai.models.unlink')->middleware('throttle:20,1');
    Route::delete('admin/ai/models/{model}', [\App\Http\Controllers\Web\AiModelController::class, 'destroy'])
        ->name('ai.models.destroy')->middleware('throttle:20,1');
    // والمصالحةُ قراءةٌ محضة — تكشف الافتراقَ ولا تُصلحه من نفسِها
    Route::get('admin/ai/providers/{provider}/models/reconcile', [\App\Http\Controllers\Web\AiModelController::class, 'reconcile'])
        ->name('ai.models.reconcile')->middleware('throttle:30,1');

    /* ── الأغراضُ وسلاسلُ التوجيه (المرحلة ٢ · W7 · §٨ · §٩) ─────────────
     * الحارسُ نفسُه، و**التصعيدُ على كلِّ كتابة**. ولا نداءَ شبكةٍ في أيٍّ من
     * هذه المسارات: سياسةٌ تُقرَأ وتُرتَّب، فلا خنقَ لخروجٍ لا يقع.
     */
    Route::get('admin/ai/profiles', [\App\Http\Controllers\Web\AiProfileController::class, 'index'])
        ->name('ai.profiles.index');
    Route::post('admin/ai/profiles/seed', [\App\Http\Controllers\Web\AiProfileController::class, 'seed'])
        ->name('ai.profiles.seed');
    Route::post('admin/ai/profiles/{profile}/attach', [\App\Http\Controllers\Web\AiProfileController::class, 'attach'])
        ->name('ai.profiles.attach');
    Route::post('admin/ai/profiles/{profile}/reorder', [\App\Http\Controllers\Web\AiProfileController::class, 'reorder'])
        ->name('ai.profiles.reorder');
    Route::post('admin/ai/profiles/{profile}/toggle', [\App\Http\Controllers\Web\AiProfileController::class, 'toggle'])
        ->name('ai.profiles.toggle');
    /*
     * **اختيارُ غرضِ «اسأل Hub» من الشاشةِ لا من الشيفرة** (جاهزيّةُ الإنتاج).
     * ويسبق مسارَ `{link}` ترتيباً لأنّه مسارٌ ثابتٌ تحت `profiles/`.
     */
    Route::post('admin/ai/profiles/{profile}/ask', [\App\Http\Controllers\Web\AiProfileController::class, 'askProfile'])
        ->name('ai.profiles.ask')->middleware('throttle:20,1');
    Route::post('admin/ai/chain/{link}/toggle', [\App\Http\Controllers\Web\AiProfileController::class, 'linkToggle'])
        ->name('ai.profiles.link.toggle');
    Route::delete('admin/ai/chain/{link}', [\App\Http\Controllers\Web\AiProfileController::class, 'detach'])
        ->name('ai.profiles.detach');

    /* ── الحوكمة: السياساتُ والميزانيّات (المرحلة ٤ · P4-W7) ─────────────
     *
     * **والواجهةُ ليست طبقةَ الفرض.** المنعُ يقع في `AiPolicy`/`AiBudgets`
     * قبل أن تُبنى صفحةٌ واحدة، فمن نادى المسارَ مباشرةً يصطدم بالحارسِ نفسِه.
     * وكلُّ كتابةٍ هنا **قرارُ حوكمةٍ يُدقَّق** — ففيها تصعيدٌ وخنقٌ كنظائرِها.
     */
    Route::get('admin/ai/policies', [\App\Http\Controllers\Web\AiGovernanceController::class, 'policies'])
        ->name('ai.policies.index');
    Route::post('admin/ai/policies', [\App\Http\Controllers\Web\AiGovernanceController::class, 'storePolicy'])
        ->name('ai.policies.store')->middleware('throttle:20,1');
    Route::post('admin/ai/policies/{policy}/toggle', [\App\Http\Controllers\Web\AiGovernanceController::class, 'togglePolicy'])
        ->name('ai.policies.toggle')->middleware('throttle:20,1');
    Route::delete('admin/ai/policies/{policy}', [\App\Http\Controllers\Web\AiGovernanceController::class, 'destroyPolicy'])
        ->name('ai.policies.destroy')->middleware('throttle:20,1');

    Route::get('admin/ai/budgets', [\App\Http\Controllers\Web\AiGovernanceController::class, 'budgets'])
        ->name('ai.budgets.index');
    Route::post('admin/ai/budgets', [\App\Http\Controllers\Web\AiGovernanceController::class, 'storeBudget'])
        ->name('ai.budgets.store')->middleware('throttle:20,1');
    Route::post('admin/ai/budgets/{budget}/toggle', [\App\Http\Controllers\Web\AiGovernanceController::class, 'toggleBudget'])
        ->name('ai.budgets.toggle')->middleware('throttle:20,1');
    Route::delete('admin/ai/budgets/{budget}', [\App\Http\Controllers\Web\AiGovernanceController::class, 'destroyBudget'])
        ->name('ai.budgets.destroy')->middleware('throttle:20,1');

