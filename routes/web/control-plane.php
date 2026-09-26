<?php

/*
|---------------------------------------------------------------------------
| مساراتُ الويب — مستوى التحكّم (الأطوار ١–١٠) والحظرُ والسماح
|---------------------------------------------------------------------------
| جزءٌ من مجموعة `auth` في routes/web.php يُضمَّن **في موضعه نفسِه** (docs/REORG_PLAN.md §R3):
| الترتيبُ يحكم مطابقةَ المسارات ذات المتغيّرات، والوسائطُ موروثةٌ من المجموعة. تغييرُ
| ترتيبٍ أو اسمٍ أو وسيطٍ تُسقطه لقطةُ المسارات (tests/Fixtures/structure/routes.json).
*/

use App\Http\Controllers\Web\AuditController;
use App\Http\Controllers\Web\ErrorCenterController;
use App\Http\Controllers\Web\OpsController;
use App\Http\Controllers\Web\QualityController;
use App\Http\Controllers\Web\SecurityController;
use App\Http\Controllers\Web\SettingController;
use Illuminate\Support\Facades\Route;

    // ── Control Plane: Phase 1 ──
    // أثرُ الطلب الواحد عبر الطبقات — الاسمُ `system.trace` لأن `trace` مملوكٌ لسلسلة التسليم
    Route::get('system/trace/{rid}', [\App\Http\Controllers\Web\SystemTraceController::class, 'show'])
        ->name('system.trace')->middleware('throttle:60,1')->where('rid', '[A-Za-z0-9._:-]{1,64}');

    // ── Control Plane: Phase 2 ──
    // (WP-2.6) إعادةُ رسالةٍ صادرةٍ واحدة من مركز التشغيل — مالكٌ + تأكيدُ هوية داخل
    // الفعل (hub_require_ops_stepup) + قيدُ تدقيق، وحدُّ معدلٍ يصدّ حلقةَ إعادةٍ عمياء
    Route::post('admin/ops/outbox/{id}/retry', [OpsController::class, 'outboxRetry'])
        ->name('ops.outbox.retry')->middleware('throttle:30,1');

    // ── Control Plane: Phase 3 ──
    // (WP-3.5) بحثُ السجلّ المحدود: يقرأ ذيلَ الملفات المؤرَّخة (السائق daily —
    // laravel-YYYY-MM-DD.log) بسقفِ بايتات. الحارسُ في المتحكّم (مالكٌ فقط)،
    // وحدُّ معدلٍ لأن كلَّ طلبٍ قراءةُ قرصٍ حقيقية
    Route::get('admin/errors/logs', [ErrorCenterController::class, 'logs'])
        ->name('errors.logs')->middleware('throttle:30,1');

    // ── Control Plane: Phase 4 ──
    // (WP-4.1/4.2) مركزُ النتائج الأمنية وتاريخُ الوضعية: القراءةُ مالكٌ أو حامل
    // monitor (ق١ — منطَّقةً بالشركة ومطموسةَ البريد والعنوان في المتحكّم)، والفعلُ
    // (إقرار/إغلاق) مالكٌ وحدَه + قيدُ تدقيق، وبحدِّ معدلٍ يصدّ نقراً أعمى.
    Route::get('admin/security/findings', [SecurityController::class, 'findings'])->name('security.findings');
    Route::get('admin/security/findings/{id}', [SecurityController::class, 'finding'])->name('security.finding');
    Route::post('admin/security/findings/{id}/ack', [SecurityController::class, 'findingAck'])
        ->name('security.finding.ack')->middleware('throttle:30,1');
    Route::post('admin/security/findings/{id}/resolve', [SecurityController::class, 'findingResolve'])
        ->name('security.finding.resolve')->middleware('throttle:30,1');
    // (WP-4.3) خطرُ الهويّة ومراجعةُ الامتيازات: القراءةُ مالكٌ أو monitor (ق١ +
    // critic #9 — منطَّقةً بالشركة ومطموسةَ البريد في المتحكّم، ولا عناوينَ شبكةٍ
    // تُعرض أصلاً)، والأفعالُ كلُّها مساراتُها القائمة للمالك وحدَه (إنهاءُ الجلسات،
    // إقرارُ النتيجة، الإيقافُ من ملف المستخدم). حدُّ معدلٍ لأن كلَّ طلبٍ ستُّ
    // تجميعاتٍ على الجداول الساخنة.
    Route::get('admin/security/identity', [SecurityController::class, 'identity'])
        ->name('security.identity')->middleware('throttle:60,1');
    Route::get('admin/security/privileged', [SecurityController::class, 'privileged'])
        ->name('security.privileged')->middleware('throttle:60,1');
    // (WP-4.4) الجلساتُ والأجهزة وذكاءُ العناوين: القراءةُ مالكٌ أو monitor —
    // مطموسةَ البريد والعنوان (hub_field_mode + قناعُ IP) ومنطَّقةً بالشركة في
    // المتحكّم (critic #9)، وبحدِّ معدلٍ لأنها تجميعاتٌ على الجداول الساخنة.
    Route::get('admin/security/sessions', [SecurityController::class, 'sessions'])
        ->name('security.sessions')->middleware('throttle:60,1');
    Route::get('admin/security/devices', [SecurityController::class, 'devices'])
        ->name('security.devices')->middleware('throttle:60,1');
    Route::get('admin/security/ips', [SecurityController::class, 'ips'])
        ->name('security.ips')->middleware('throttle:60,1');
    Route::get('admin/security/ips/{ip}', [SecurityController::class, 'ip'])
        ->name('security.ip')->middleware('throttle:60,1')->where('ip', '[0-9A-Fa-f:.]{3,45}');
    // ── Work OS · الطور I (WP-I.3 · §39/§42) — قواعدُ الحظر والسماح ──
    // الشاشةُ والأفعالُ **للمالك وحدَه و٤٠٤ لغيره** (الحارسُ في المتحكّم —
    // blocksGate: سطحُ دفاعٍ لا يُثبَت وجودُه)، وكلُّ إضافة/تمديد/إلغاء خلف
    // step-up + قيدِ تدقيقٍ بدلالة SECURITY_POLICY_CHANGED + حمايةِ حبس آخرِ
    // مالكٍ الخادمية. حدُّ معدلٍ كسائر سطوح الأمن الحساسة.
    Route::get('admin/security/blocks', [SecurityController::class, 'blocks'])
        ->name('security.blocks')->middleware('throttle:60,1');
    Route::post('admin/security/blocks', [SecurityController::class, 'blockStore'])
        ->name('security.blocks.store')->middleware('throttle:30,1');
    Route::post('admin/security/blocks/{id}/extend', [SecurityController::class, 'blockExtend'])
        ->name('security.blocks.extend')->middleware('throttle:30,1');
    Route::post('admin/security/blocks/{id}/revoke', [SecurityController::class, 'blockRevoke'])
        ->name('security.blocks.revoke')->middleware('throttle:30,1');
    // إنهاءُ «الباقي» لمستخدمٍ (§18): مالكٌ + تصعيدُ هويةٍ داخل الفعل + قيدُ تدقيق —
    // جلسةُ المنفّذ الحالية تبقى. وحدُّ معدلٍ يصدّ نقراً أعمى.
    Route::post('admin/security/users/{id}/revoke-others', [SecurityController::class, 'revokeOthers'])
        ->name('security.user.revokeothers')->middleware('throttle:30,1');
    // (WP-4.5) مركزُ رموز API وصحّةُ الأسرار: **للمالك وحدَه** (بياناتُ اعتمادٍ
    // وأسرارُ منشأة — critic #9: monitor يُصَدّ هنا لا يُطمَس)، والإبطالُ الإداريّ
    // بتصعيدِ اعتماد (§18) وقيدِ تدقيقٍ برمز API_CREDENTIAL_REVOKED. ولا مسارَ
    // **تدويرٍ** لرمز مستخدمٍ آخر أصلاً (ق٧ — النصُّ الصريح كان سيصل المدير).
    Route::get('admin/security/tokens', [SecurityController::class, 'tokens'])
        ->name('security.tokens')->middleware('throttle:60,1');
    Route::get('admin/security/secrets', [SecurityController::class, 'secrets'])
        ->name('security.secrets')->middleware('throttle:60,1');
    Route::post('admin/security/tokens/{id}/revoke', [SecurityController::class, 'revokeToken'])
        ->name('security.token.revoke')->middleware('throttle:30,1');
    // (WP-4.6) تفصيلُ الحدث الأمنيّ الواحد بمفتاح المصدر+المعرّف (audits.id أو
    // access_denials.id — السجلُّ مشتقٌّ فلا جدولَ ولا معرّفَ جديدَين): القراءةُ
    // بحارس المركز نفسِه (مالكٌ أو monitor مطموساً ومنطَّقاً — critic #9)، وزرُّ
    // «افتح حادثة» يمرّر تعبئةً لآليّة m.create القائمة بلا مسارِ كتابةٍ جديد.
    Route::get('admin/security/event/{source}/{id}', [SecurityController::class, 'event'])
        ->name('security.event')->middleware('throttle:60,1')
        ->where('source', 'audit|radar')->where('id', '[0-9]+');
    // ── Control Plane: Phase 5 ──
    // (WP-5.4) صفحةُ تفصيل قيد التدقيق — الحارس في المتحكّم: راية audit ثم
    // Audit::scopedQuery فالخارجُ عن النطاق 404 لا 403 يفشي الوجود. القيدُ
    // رقميٌّ تسلسليّ ({id} عدديّ حصراً) فلا يظلّل مساراتِ الطور اللاحقة (coverage)
    Route::get('admin/audit/{id}', [AuditController::class, 'show'])
        ->name('audit.show')->where('id', '[0-9]+');

    // (WP-5.5) محلّلُ تغطية التدقيق — الحارس في المتحكّم (مالكٌ فقط): الصفحةُ
    // خريطةُ ما يُدقَّق وما لا يُدقَّق، وهي لغير المالك خريطةُ ما لا يترك أثراً
    Route::get('admin/audit/coverage', [AuditController::class, 'coverage'])->name('audit.coverage');

    // ── Control Plane: Phase 6 ──
    // (WP-6.3) مركزُ التنبيهات — حالةُ التنبيه (alert_instances) لا سيلُ إشعاراته.
    // الاسمُ `alerts.center` لأن `alerts` مملوكٌ لرادار «ينتهي قريباً» القائم.
    // القراءةُ مالكٌ أو monitor (ق١ — مطموسةَ البريد في المتحكّم)، والفعلُ
    // (إقرار/فتحُ حادثة) مالكٌ وحدَه + قيدُ تدقيق، وبحدِّ معدلٍ يصدّ نقراً أعمى.
    Route::get('admin/alerts', [\App\Http\Controllers\Web\AlertCenterController::class, 'index'])
        ->name('alerts.center')->middleware('throttle:60,1');
    Route::post('admin/alerts/{id}/ack', [\App\Http\Controllers\Web\AlertCenterController::class, 'ack'])
        ->name('alerts.ack')->middleware('throttle:30,1');
    Route::post('admin/alerts/{id}/incident', [\App\Http\Controllers\Web\AlertCenterController::class, 'incident'])
        ->name('alerts.incident')->middleware('throttle:30,1');
    // (WP-6.2) ربطُ دليلٍ بحادثة (§8.2): من يحرّر الحادثةَ يربط أدلّتَها
    // (`hub_can('incidents','e')` في المتحكّم) + قيدُ تدقيق، والفريدُ المنطقيّ على
    // (incident_id, kind, ref) يجعل إعادةَ الربط تحديثاً لا تكراراً. وحدُّ معدلٍ
    // لأن الزرّ يظهر على ثلاث شاشاتِ مصادر فيسهل النقرُ الأعمى المتكرّر.
    Route::post('admin/incidents/{id}/link', [\App\Http\Controllers\Web\IncidentLinkController::class, 'store'])
        ->name('incidents.link')->middleware('throttle:30,1')->whereUuid('id');
    // ── Control Plane: Phase 7 ──
    // (WP-7.2) نظرةُ القوى العاملة: عدّاداتُ التنفيذ على مستوى المنشأة
    // (ExecutionStats::org — القارئُ الواحد الذي سيعيد الطورُ ٨ استعمالَه).
    // الاسمُ `workforce.overview` لأن `workforce.team` قائمٌ لشاشة «فريقي اليوم».
    // الحارسُ في المتحكم: hub_monitor + hub_org_analytics_guard (نمطُ القدرات) —
    // وحدُّ معدلٍ لأن الصفحةَ تجميعاتٌ على الجداول الساخنة وفحصُ صحةِ مشاريع.
    Route::get('workforce/overview', [\App\Http\Controllers\Web\WorkforceController::class, 'overview'])
        ->name('workforce.overview')->middleware('throttle:60,1');
    // ── Control Plane: Phase 9 ──
    // (WP-9.4 · §7.11) تصديرُ الإعدادات: مالكٌ (الحارس في المتحكّم) + مفتاحُ تجميد
    // التصدير (٤٢٣) + أثرٌ يُصنَّف DATA_EXPORT. وحدُّ معدلٍ لأنه سحبُ بياناتٍ جماعيّ
    // كتصدير الوحدات — و**لا سرَّ ولا صفَّ حالةٍ** يخرج منه (القرارُ في `Settings`).
    Route::get('admin/settings/export', [SettingController::class, 'export'])
        ->name('settings.export')->middleware('throttle:20,1');
    // (WP-9.4 · §7.12) الاستيرادُ خطوتان لا واحدة: الرفعُ يقرأ ويقارن ويَسِم الخطر
    // **ولا يكتب**، والتطبيقُ فعلٌ ثانٍ بتأكيدٍ (وتصعيدِ هويةٍ إن كان في الحمولة
    // مفتاحٌ عالي الخطورة) يمرّ بالكاتب الواحد بمصدر `import`.
    Route::post('admin/settings/import', [SettingController::class, 'import'])
        ->name('settings.import')->middleware('throttle:20,1');
    Route::post('admin/settings/import/apply', [SettingController::class, 'importApply'])
        ->name('settings.import.apply')->middleware('throttle:20,1');
    // (WP-9.4 · §7.10) فاحصُ n8n — لم يكن له فاحصٌ قطّ. مالكٌ + حارسُ الطلبات
    // الصادرة داخل الفاحص + خنقٌ كأخويه
    Route::post('admin/integrations/n8n/test', [\App\Http\Controllers\Web\N8nController::class, 'test'])
        ->name('integrations.n8n.test')->middleware('throttle:10,1');
    // (WP-9.3 · §7.7 · §18) معاينةُ الحفظ: خطوةٌ **جافّة** تعيد «من ماذا إلى ماذا»
    // لكل مفتاحٍ يتبدّل وتَسِم عاليَ الخطورة، وتُسلّم الحمولةَ عبر الجلسة إلى
    // تأكيدٍ على سكّة `data-confirm` القائمة. مالكٌ (الحارس في المتحكّم)، ولا
    // تصعيدَ لها: لا تكتب حرفاً، وما تعرضه معروضٌ في الشاشة نفسِها أصلاً.
    Route::post('admin/settings/preview', [SettingController::class, 'preview'])
        ->name('settings.preview')->middleware('throttle:60,1');
    // (WP-9.3 · §7.8 · critic #7) استعادةُ الافتراضي: **كتابةُ** قيمة `default`
    // المُعلَنة لا حذفُ الصفّ (حذفُه يُعيد إشعالَ `sec.hours_on`/`sec.strict_files`
    // لأنّ افتراضيَّهما مُشغَّل). مالكٌ + تأكيدٌ في الشاشة + `hub_require_stepup`
    // لعالي الخطورة + أثرٌ بفعلٍ مستقلّ (`SETTINGS_RESTORED`).
    Route::post('admin/settings/restore', [SettingController::class, 'restore'])
        ->name('settings.restore')->middleware('throttle:30,1');

    // ── Control Plane: Phase 8 ──
    // (WP-8.5 · §6.13) فعلُ المعالجة: مدخلٌ واحدٌ لأربعة مصادر (نتيجةُ جودة ·
    // مؤشّرٌ خارج الهدف · هدفٌ متعثّر · خرقُ SLA) يفتح **مهمّةً** في نظام المهامّ
    // القائم — لا جدولَ «إجراءاتٍ تصحيحية» ثانياً بجانبه. الحارسُ في المتحكّم:
    // monitor ثم `hub_can('tasks','a')` ثم تحقّقٌ من أنّ النتيجةَ نتيجةٌ فعلاً
    // (٤٢٢ وإلا)، ونتائجُ الجودة للمالك وحدَه (المسحُ غيرُ منطَّق). وحدُّ معدلٍ
    // يصدّ النقرَ الأعمى المتكرّر على زرٍّ يظهر في أكثر من شاشة.
    Route::post('remediation', [\App\Http\Controllers\Web\RemediationController::class, 'store'])
        ->name('remediation.store')->middleware('throttle:30,1');
    // (WP-8.3 · §6.4 · §31 · §23.5) معاينةُ دمج المكررات: خطوةُ «ماذا سيقع» قبل
    // فعلٍ لا رجعةَ فيه — نفسُ حلقة المراجع بـ`COUNT` بدل `UPDATE`، فما تَعِدُ به
    // هو ما ينفّذه `quality.merge` بالضبط. الحارسُ في المتحكّم: المالكُ وحدَه
    // (الكشفُ غيرُ منطَّق ويُظهر أسماءَ عملاءَ من كل الشركات)، ثم تحقّقٌ من أنّ
    // المعرّفاتِ تنتمي لمجموعةِ تكرارٍ **مكتشَفة** (٤٢٢ وإلا). وحدُّ معدلٍ لأنّها
    // تقرأ خمسةَ عشرَ جدولاً في كل نقرة.
    Route::post('admin/quality/merge/preview', [QualityController::class, 'preview'])
        ->name('quality.merge.preview')->middleware('throttle:30,1');

    // ── Control Plane: Phase 10 ──
    // (WP-10.1 · §32 · §12) نظرةُ التحكّم: ستُّ بطاقاتٍ **قارئةٍ فقط** تحيل إلى
    // مراكزها — لا لوحةَ عملاقةٌ تكرّر التفاصيل، ولا رقمَ يُحسب في المتحكّم.
    // الحارسُ في المتحكّم (نمطُ المستودع): مالكٌ، ولحاملِ راية المراقبة ما تمنحه
    // ق١ وحدَه (النتائجُ الأمنية والجودةُ والتنفيذ) — وبطاقةٌ محجوبةٌ لا تُحسَب.
    // وحدُّ معدلٍ لأنّ الفتحةَ الباردة تشمل `Health::check` (عشراتُ الاستعلامات)
    // وإن كانت مخبّأةً ٦٠ ثانية بختمِ جداولها. و**لا مسارَ إقرارٍ جديد**:
    // صفُّ «يستدعي تدخّلك» يُعرض هنا ويُتصرَّف به على `recs.act` القائم (WP-10.2).
    Route::get('admin/control', [\App\Http\Controllers\Web\ControlController::class, 'index'])
        ->name('control.index')->middleware('throttle:60,1');

    // مركزُ منصّة تطبيق الهاتف (Mobile Platform Center) — قنصليّةٌ إداريّةٌ فوق قدرات
    // الجوال القائمة. الاسمُ `mobileplatform.*` متمايزٌ عن بادئةِ `mobile` (دلو IA/الـAPI).
    // الحرسُ في المتحكّم (مالك/رايةُ mobile) — لا middleware مسارٍ (كسائر مراكز الإدارة).
    Route::get('admin/mobile-platform', [\App\Http\Controllers\Web\MobilePlatformController::class, 'index'])
        ->name('mobileplatform.index')->middleware('throttle:60,1');
    Route::post('admin/mobile-platform/sessions/{id}/revoke', [\App\Http\Controllers\Web\MobilePlatformController::class, 'revokeSession'])
        ->name('mobileplatform.session.revoke')->middleware('throttle:30,1');
    // اختبارُ دفعٍ إداريٌّ آمن — إلى جهازِ المُختبِرِ وحدَه عبر المزوّدِ القائم (لا تجاوزَ ضبط). خنقٌ ضيّق.
    Route::post('admin/mobile-platform/push/test', [\App\Http\Controllers\Web\MobilePlatformController::class, 'pushTest'])
        ->name('mobileplatform.push.test')->middleware('throttle:6,1');
