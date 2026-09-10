# الخطّة كما نُفِّذت — الحضور × التقارير اليوميّة × مراجعةُ التقدّم والامتثال (AR-1..AR-7)

> **المبدأ:** إصلاحُ عيبٍ لا بناءُ محرّكٍ ثانٍ. ثلاثُ حقائقَ تُفصَل (§6)، واشتقاقٌ مركزيٌّ
> واحدٌ يحلّ محلَّ عمودِ حالةٍ يتبيّت. لا حذفَ مسارٍ، لا عقدَ API مكسور، لا هجرةَ
> مُدمِّرة — إضافةٌ فوق السكّتين القائمتين (`work_updates` و`attendance`). النسخة v2.476.0.

## AR-1) الجذر أوّلاً — إثباتٌ يفشل قبل الإصلاح

`Workday::evaluate()` كان يحسب حالةَ اليوم **مرّةً عند الانصراف** ويُرجع `NO_REPORT`
(«حاضر — بلا تقرير») فيدهس الحضورَ الفيزيائيّ، والمُنادي الوحيد `checkOut()` — فمن قدّم
تقريرَه **بعد** الانصراف بقي يومُه عالقاً أبداً ([ROOT_CAUSE.md](ROOT_CAUSE.md)، ثلاثةُ
عيوبٍ متضافرة، كلُّها CONFIRMED). العيبُ الحقيقيّ كُتب اختباراً يفشل أوّلاً ثم أُصلح:
`test_report_after_checkout_is_recognized_not_present_without_report`
(`AttendanceReportComplianceTest.php:57`) — موظّفٌ E ومستخدمٌ U حيث **E.id ≠ U.id**،
حضورٌ ← انصرافٌ ← تقريرٌ بعده؛ يُتوقَّع `hasSubmittedReport = true` وأثرٌ «حاضر». نُفي أنّ
السببَ تطابقُ الهُويّة أو `work_date` من `created_at` أو المنطقةُ الزمنيّة (RULED OUT).

## AR-2) المُحلِّلُ المركزيّ — الحالاتُ والمهلةُ والإعدادات

- **`app/Support/DailyWorkCompliance.php`** — الحقيقةُ الواحدةُ لحالةِ اليوم (§10/§101). ثمانِ
  حالاتٍ قانونيّة (`:32-40`)، والمسنَدُ الوحيد `hasSubmittedReport()` (`:68`) فوق تعريفِ
  التقريرِ الصالح `isValidReportRow()` (`:55`)، و`resolve()`/`resolveMany()` (N+1=0،
  `:88`/`:107`)، وآلةُ الحالاتِ `deriveState()` (`:240`) والأثرُ `deriveEffective()`
  (`:268`). الحضورُ الفيزيائيّ لا يُطمَس، والقديمُ «بلا تقرير» يُقرأ حضوراً (`:362-369`).
- **`Workday::evaluate()`** أُعيد إلى **حضورٍ محضٍ** لا يُرجع `NO_REPORT` أبداً (`Workday.php:167-175`)؛
  و`checkOut()` يختم المهلةَ (`attendance.report_deadline_at`، `:130-134`) ويصوغ رسالتَه من
  المُحلِّلِ لا من عمودٍ مطموس (`:145-155`).
- **التاريخُ التجاريّ** `app/Support/BusinessDate.php` (§48/§49/§50، tz=Asia/Kuwait) — مصدرٌ واحدٌ
  للتاريخِ بدل `now()->toDateString()` المتناثر؛ عليه تُبنى المهلةُ الحيّةُ `computeDeadline()`
  (`DailyWorkCompliance.php:293`).
- **الإعدادات** `config/hub_settings.php:634-695` — `report_required`، `report_grace_minutes`
  (120)، `report_cutoff_time`، `missing_report_policy` (warning_only/non_compliant/
  absence_equivalent)، `report_reminder`، `review_required`. تُقرأ حيّاً فيَسري تغييرُها فوراً
  (يُثبِته `test_changing_grace_immediately_changes_evaluation`).

## AR-3) إعادةُ الحسابِ اللحظيّة + المصالحةُ + تنبيهٌ لا يتكرّر

الاشتقاقُ حيٌّ على كلِّ قراءة — لا خطّافَ إعادةِ تقييم: كتابةُ بندٍ ترفع ختمَ `work_updates`
العامّ فتُبطِل خبيئةَ كلِّ شاشةٍ تقرؤه (شاشةُ الفريق مفتاحُها يضمّ الجدول، `Workday.php:225`).
وشبكةُ الأمان المجدولة `app/Console/Commands/AttendanceReconcileReports.php` (`attendance:reconcile-reports`)
تجد **المرشَّحين فقط** (فات موعدُهم ولم يُقفَلوا، نافذةٌ خلفيّةٌ محدودة، `:43-49`) عبر فهرسِ
`attend_deadline_final`، وتُشعِر بالتقريرِ الناقصِ إشعاراً **واحداً** لكلِّ (موظّف/يوم) عبر مسنَدِ
الوجودِ في `notifications_hub` (`:70-72`، §39) — لا تكرارَ مهما أُعيد التشغيل. موصولةٌ بأتمتة
`hub:automation` اليوميّة، لا تقفل الأثرَ (القفلُ قرارُ إنسان).

## AR-4) دلالةُ التقديمِ والمراجعة

- **`app/Models/WorkUpdate.php`** — أُضيف `submitted_at` (يُختم مرّةً في خطّافِ `saving`، `:60-62`،
  لا مفهومَ مسودّة) وحقولُ المراجعةِ الخفيفة `review_status/reviewed_by/reviewed_at/review_feedback`
  محروسةٌ من التعبئةِ الجماعيّة (`$guarded`, `:32-33`)، وخطّافُ `restored()` يستردّ `act_h`
  الناقص (§72، `:109-113`).
- **`app/Support/ReportReview.php`** — مراجعةٌ خفيفةٌ فوق الحقولِ القائمة لا محرّكَ موافقاتٍ ثانٍ:
  `accept/needsRevision/reopen` (`:77-102`)، وصلاحيّةٌ بالتنطيقِ لا اسمِ الدور (`canReview`/
  `canReviewAny`، `:35-60`)، وقفلُ التحريرِ بعد القبول (`isLockedForEditor`, `:68`). والقبولُ لا
  يمسّ الساعاتِ (§73). وقفلُ الامتثالِ اليدويّ المُدقَّق `finalizeCompliance()`/`clearComplianceLock()`
  (`:122`/`:139`) — بمن ومتى ولماذا، لا يمسّ `time_in/time_out` (§7)، ويعلو على الاشتقاقِ الحيّ
  في `compose()` (`DailyWorkCompliance.php:179-195`).
- **الهجرةُ الإضافيّة** `database/migrations/2026_09_26_000001_attendance_report_compliance.php` — أعمدةٌ
  دنيا لا تُشتقّ فقط + فهرسا `attend_deadline_final`/`wu_review_date`، محروسةٌ بـ`hasColumn`،
  عكوسةٌ، غيرُ مُدمِّرة؛ الصفوفُ القائمةُ مُلئت `submitted_at` من `created_at` (`:78-79`).

## AR-5) أسطحُ الواجهة

`app/Http/Controllers/Web/ReportsController.php` — `index` (مركز التقارير §17)، `day` (تفصيلُ يوم §68)،
`review` (الطابور §31)، `reviewAct` (§27)، `finalize` (§40/§90)، `mine` (تقريري §22)؛ حسابُ العميلِ
⇒ ٤٠٤ عبر `guardInternal()` (`:30`). المساراتُ في `routes/web.php:251-257`. وسطوحٌ مُضمَّنة: مصفوفةُ
الحقائقِ الثلاث في «فريقي اليوم» (`workforce/team.blade.php`، §12/§67 عبر `Workday::teamCalc`)،
وتبويبُ «📝 التقارير اليوميّة» في ملفِّ الموظّف (`portal/employee.blade.php`، §21/§61)، وشريطُ «📋 التقارير
والتقدّم» في المشروع (`modules/custom/projects.blade.php`، §19/§20/§57)، ورابطُ «تقرير اليوم» في ودجةِ
الحضور (`partials/widgets/checkin.blade.php`، §63).

## AR-6) التنطيقُ والعزلُ والـAPI والسجلّ

- **البوّابةُ والعزل:** الفريقُ بوّابتُه `hr:v`؛ العزلُ عبر `hub_company_scope(hub_scope(...,'hr'),'hr')`؛
  طابورُ المراجعةِ منطَّقٌ مشروعاً وشركةً. يُثبِته `test_company_isolation_in_reports_center` و
  `test_unauthorized_user_cannot_review_anothers_report` و`test_client_account_cannot_reach_reports_surfaces`.
- **القراءةُ REST بنفسِ المُحلِّل** (لا سلوكٌ ثانٍ، §93/§94): `ReportsApiController` (`/api/v1/reports/my-daily`
  · `today-compliance` · `daily`) و`MobileReportsController` (`/api/mobile/v1/work/today` + `work/daily-report`
  مرادفاً) يمرّان عبر `DailyWorkCompliance::apiShape()` (`:327`)؛ والتقديمُ يُعادُ استعمالُ CRUD الوحدة
  `updates` — لا مسارَ تقديمٍ مكرّر. OpenAPI **يُولَّد** لا يُحرَّر.
- **القدرةُ والاكتشاف:** رايةٌ واحدةٌ `workos.daily_reports` (`config/hub_features.php:131-135`، domain=work_os،
  permissions=hr، web_routes=index/review/mine، ENABLED، introduced=v2.476.0)، والوجهاتُ الثلاث في
  `config/hub_ia.php` بحرّاسِ `reports_mine`/`reports_center`/`reports_review` (`InformationArchitecture.php:109-113`).

## AR-7) الاختباراتُ والتوثيقُ والمحرّكان والنسخة

- `AttendanceReportComplianceTest.php` — **١٩ اختباراً**: العيبُ الدقيق (§102)، ناقص/متأخر/إجازة/ورديةٌ
  مفتوحة/قبلَ الانصراف، متعدّدُ المشاريع (لا عدٌّ مزدوجٌ للساعات §110)، غيرُ مشروعيّ (§111)، النائبُ الرمزيّ
  (§13/§24)، لاتكرارُ التنبيه (§116)، الإعداداتُ الحيّة (§117)، استردادُ الساعات (§72)، دورةُ المراجعة+القفل
  (§112/§30)، تصريحُ المراجعة (§113)، العميلُ (§114)، عزلُ الشركة (§115)، تقريرٌ بلا حضور (§45)، وتكافؤُ الـAPI
  (§93/§94). و`WorkforceTest.php` (الحضورُ الفيزيائيُّ لا يُطمَس أبداً) و`IntelligenceReportComplianceTest.php`.
- **الإثبات لا الادّعاء:** كلُّ عيبٍ اختبارٌ يفشل أوّلاً. **المحرّكان أخضران قبل الدفع** (SQLite + MySQL)،
  وترتيبُ الصفوفِ صريحٌ بـ`orderBy` (`resolve` يرتّب بـ`submitted_at,id`، `:97`)، وتأكيدُ JSON مفتاحاً
  مفتاحاً. التوثيقُ تحت `docs/attendance-reporting/` (00–10 + ROOT_CAUSE)، ورقمُ النسخة رُفع في `VERSION`
  و`README.md` (v2.476.0).

## قرارُ التصميم (§56): لا جدولَ `daily_reports` جديد

«التقريرُ اليوميّ» **تجميعٌ حيٌّ** لبنودِ `work_updates` بمفتاحِ `work_date → created_by → project`،
يُركَّب في `DailyWorkCompliance::compose()` (`:150-156`) — لا صفَّ رأسٍ مخزَّن ولا حالةُ «قُدِّم» مُبيَّتة.
**لماذا؟** (١) جدولُ الرأسِ يُعيد إنتاجَ العيبِ نفسِه: حالةٌ مخزَّنةٌ تحتاج خطّافَ إعادةِ تقييمٍ عند كلِّ
تقديمٍ متأخّر، بينما السؤالُ من الوجودِ يُجيب فوراً. (٢) البندُ (`WorkUpdate`) هو الذرّةُ القائمة التي
تغذّي `tasks.act_h` والربحيّةَ بلا إدخالٍ مزدوج — رأسٌ ثانٍ = مصدرُ حقيقةٍ مكرّر. (٣) تقريرُ اليوم يجمع
بنوداً على عدّةِ مشاريعَ وبنوداً بلا مشروعٍ معاً؛ الرأسُ الواحدُ يفرض شكلاً يكسر ذلك. التفصيلُ في
[04-daily-report-architecture.md](04-daily-report-architecture.md).

**اللاأهداف المحفوظة:** لا محرّكَ تقاريرَ ثانٍ، لا درجةَ إنتاجيّة، لا تطبيقٌ أصليّ/WebSockets/Redis،
لا عقوبةٌ رجعيّة، ولا تزويرُ الحضورِ الفيزيائيِّ غياباً.
