# الجردُ الكامل — كلُّ ملفٍّ جديدٍ أو معدَّل (v2.476.0)

> **جردُ سطحٍ واحد:** كلُّ ملفٍّ مسَّته ميزةُ «الحضور × التقرير × الامتثال»، بغرضِه
> بسطرٍ واحدٍ والفقرةِ (§) التي يخدمها. الحقيقةُ الواحدةُ لحالةِ اليوم في مُحلِّلٍ
> مركزيٍّ واحد (`DailyWorkCompliance`)، والباقي طبقاتٌ فوقه أو مُستهلِكاتٌ له — لا محرّكَ
> تقاريرَ ثانٍ (§101). التفصيلُ الدلاليُّ في `00`–`09`؛ هذا الملفُّ خريطةُ الملفّاتِ فحسب.

## ١) أصنافُ الدعم — `app/Support`

| الملفّ | الحالة | الغرض | § |
|---|---|---|---|
| `DailyWorkCompliance.php` | جديد | المُحلِّلُ المركزيّ: الحقائقُ الثلاث تُشتقُّ حيّاً. الحالاتُ القانونيّة (`:33-40`)، `isValidReportRow` (`:55`)، المسنَدُ الوحيد `hasSubmittedReport` (`:68`)، `resolve`/`resolveMany` (`:88`/`:107` · N+1=0)، `computeDeadline` (`:293`)، `policy`/`reviewRequired` (`:310`/`:318`)، `apiShape` (`:327`)، والتوافقُ الرجعيّ «بلا تقرير ⇒ حضور» (`:362-369`) | §10/§101 |
| `BusinessDate.php` | جديد | مصدرٌ واحدٌ للتاريخِ التجاريّ بتوقيتِ المنشأة (Asia/Kuwait): `today`/`now` (`:28-37`)، `of` (`:49`)، `at` لحسابِ المهلة (`:63`) | §48/§49/§50 |
| `ReportReview.php` | جديد | خدمةُ المراجعةِ وقفلِ الامتثال: `canReview`/`canReviewAny`/`isLockedForEditor` (`:35`/`:56`/`:68`)، `accept`/`needsRevision`/`reopen` (`:77`/`:87`/`:97`)، `finalizeCompliance`/`clearComplianceLock` (`:122`/`:139`)، `employeeOf` (`:177`) | §27/§30/§94 |
| `Workday.php` | معدَّل | `evaluate()` صار حضوراً فيزيائيّاً محضاً لا يطمس غيابَ التقرير (`:167-175`)؛ `checkOut()` يختم `report_deadline_at` ورسالتُه من المُحلِّل (`:128-155`)؛ `teamCalc()` يستدعي `resolveMany` (`:240`) | §6/§53 |

## ٢) النماذجُ والهجرة

| الملفّ | الحالة | الغرض | § |
|---|---|---|---|
| `app/Models/WorkUpdate.php` | معدَّل | `submitted_at` يُختم مرّةً في خطّاف `saving` (`:60-62`)؛ حقولُ التقديمِ والمراجعةِ محروسةٌ من التعبئة الجماعية (`:32-33`)؛ خطّافُ `restored` يستردّ `act_h` المخصوم (`:109-113`) | §13/§72 |
| `app/Models/Attendance.php` | معدَّل | جسرُ الموظّف `emp() → Employee` (`:33-36`) — التقاطُ صفِّ الحضورِ لصاحبه في المُحلِّل والقفل | §101 |
| `database/migrations/2026_09_26_000001_attendance_report_compliance.php` | جديد | أعمدةٌ إضافيّةٌ دنيا لا تُشتقّ فقط: `attendance` (`report_deadline_at`/`compliance_*`، `:34-51`)، `work_updates` (`submitted_at`/`review_*`، `:58-75`)، ملءُ `submitted_at` من `created_at` (`:78-79`)، فهرسا `attend_deadline_final`/`wu_review_date` (`:54`/`:82`). عكوسةٌ غيرُ مُدمِّرة | §54/§55 |

## ٣) الأمرُ والمتحكّمات

| الملفّ | الحالة | الغرض | § |
|---|---|---|---|
| `app/Console/Commands/AttendanceReconcileReports.php` | جديد | `attendance:reconcile-reports` — شبكةُ أمانٍ تجد المرشّحين فقط (فات موعدُهم ولم يُقفَلوا، `:43-49`) وتُشعِر إشعاراً واحداً لكلِّ (موظّف/يوم) عبر مسنَدِ الوجود (`:70-72`) | §51/§52 |
| `app/Http/Controllers/Web/ReportsController.php` | جديد | `index` مركزُ التقارير (`:44`)، `day` التفصيل (`:91`)، `review` الطابور (`:114`)، `reviewAct` القبول/التنقيح (`:142`)، `finalize` ختمُ الأثر (`:173`)، `mine` تقريري (`:194`)؛ العميلُ ⇒ ٤٠٤ (`:31`)، بوّابةُ الفريق `hr:v` (`:37`) | §17/§27/§31/§40/§68 |
| `app/Http/Controllers/Api/ReportsApiController.php` | جديد | REST v1: `myDaily` (`:22`)، `todayCompliance` (`:51`)، `teamDaily` (`:62`) — نفسُ المُحلِّلِ، والعميلُ ٤٠٤ | §93/§94 |
| `app/Http/Controllers/Api/MobileReportsController.php` | جديد | جوّال v1: `today` حالُ اليومِ وبنودُه (`:21`)؛ التقديمُ يُعادُ استعمالُ CRUD وحدة `updates` (`:46`) | §93/§94 |
| `app/Console/Commands/HubAutomation.php` | معدَّل | وصلُ `attendance:reconcile-reports` في الأتمتة اليوميّة (`:50`) | §52 |

## ٤) الواجهات — `resources/views`

| الملفّ | الحالة | الغرض | § |
|---|---|---|---|
| `reports/index.blade.php` | جديد | مركزُ التقارير: نظرةُ اليومِ للفريق + ملخّصُ التغطية والمرشّحات | §17/§18 |
| `reports/day.blade.php` | جديد | تفصيلُ يومٍ لموظّف: الحقائقُ الثلاث + بنودُه بمشاريعِها ومهامِّها | §68 |
| `reports/review.blade.php` | جديد | طابورُ المراجعة: قبول/طلبُ تنقيح/إعادةُ فتح | §31 |
| `reports/mine.blade.php` | جديد | تقريري اليوم للموظّف نفسِه + حالتُه ومهلتُه | §22/§66 |
| `workforce/team.blade.php` | معدَّل | مصفوفةُ الحقائقِ الثلاث في «فريقي اليوم» عبر `Workday::teamCalc` | §12/§67 |
| `portal/employee.blade.php` | معدَّل | تبويبُ «📝 التقارير اليوميّة» في ملفِّ الموظّف (`Employee360::dailyReports`) | §21/§61 |
| `modules/custom/projects.blade.php` | معدَّل | شريطُ «📋 التقارير والتقدّم» الزمنيّ في المشروع | §19/§20/§57 |
| `partials/widgets/checkin.blade.php` | معدَّل | رابطُ «تقرير اليوم» في ودجة الحضور | §63 |

## ٥) الإعدادُ والتسجيل

| الملفّ | الحالة | الغرض | § |
|---|---|---|---|
| `config/hub_settings.php` (`:634-695`) | معدَّل | ستّةُ مفاتيحِ إعدادٍ للاشتراطِ والمهلةِ والسياسةِ والتذكيرِ والمراجعة (الجدولُ أدناه) | §9/§117 |
| `config/hub_features.php` (`:131-135`) | معدَّل | قدرةٌ واحدةٌ `workos.daily_reports` (domain `work_os`، permissions `hr`، web_routes `reports.index/review/mine`) | §120 |
| `config/hub_ia.php` (`:179-188`) + `app/Support/InformationArchitecture.php` (`:109-113`) | معدَّل | ثلاثُ وجهاتٍ في العمل → «المهام والتنفيذ» بحرّاسِ `reports_mine`/`reports_center`/`reports_review` | §121 |
| `routes/web.php` (`:251-258`) | معدَّل | مساراتُ الويب الستّة (الجدولُ أدناه) | §17 |
| `routes/api.php` (`:23-25`, `:198-199`) | معدَّل | مساراتُ REST v1 والجوّال (الجدولُ أدناه) | §93 |

## ٦) الاختبارات — `tests/Feature`

| الملفّ | الحالة | الغرض | § |
|---|---|---|---|
| `AttendanceReportComplianceTest.php` | جديد | ١٩ اختباراً: العيبُ عينُه، بلا تقرير، متأخّر، إجازة، ورديةٌ مفتوحة، قبلَ الانصراف، متعدّدُ المشاريع، بلا مشروع، النائبُ الرمزيّ، لاتكرارُ الإشعار، الإعدادات، استردادُ الساعات، دورةُ المراجعة والقفل، صلاحيةُ المراجعة، العميل، عزلُ الشركة، تقريرٌ بلا حضور، تكافؤُ الواجهة | §102/§103/§104/§112/§116 |
| `WorkforceTest.php` | معدَّل | الحضورُ الفيزيائيُّ لا يُطمَس بعد الميزة | §6 |
| `IntelligenceReportComplianceTest.php` | جديد | امتثالُ التقارير في طبقةِ الذكاء | — |

## ٧) جدولُ الإعدادات (`config/hub_settings.php`)

| المفتاح | النوع | الافتراضيّ | الأثر |
|---|---|---|---|
| `work.report_required` | onoff | `1` | هل التقريرُ اليوميُّ مطلوب؟ مُطفأً ⇒ الحضورُ وحدَه كافٍ (`compose:168`) |
| `work.report_grace_minutes` | number | `120` | سماحيةُ التقديم بعد الانصراف قبل «حضور بلا تقرير» (`computeDeadline:295`) |
| `work.report_cutoff_time` | text | `''` | حدٌّ يوميٌّ نهائيّ ثابت (HH:MM)؛ تُؤخَذ المهلةُ الأبعدُ فالألطف (`computeDeadline:296`) |
| `work.missing_report_policy` | text | `absence_equivalent` | أثرُ الناقصِ بعد المهلة: `warning_only`/`non_compliant`/`absence_equivalent` (`deriveEffective:274`) |
| `work.report_reminder` | onoff | `1` | تذكيرُ الموظّفِ بتقريرٍ ناقصٍ إشعاراً واحداً (`AttendanceReconcileReports:36`) |
| `work.review_required` | onoff | `0` | هل مراجعةُ المدير للتقارير مطلوبة؟ (`reviewRequired:318`) |

## ٨) جدولُ المسارات

| السطح | الفعل والمسار | الاسم | البوّابة |
|---|---|---|---|
| ويب | `GET reports/daily` | `reports.index` | `hr:v` |
| ويب | `GET reports/daily/day` | `reports.day` | `hr:v` |
| ويب | `GET reports/review` | `reports.review` | `ReportReview::canReviewAny` |
| ويب | `GET my/report` | `reports.mine` | أيُّ داخليٍّ بملفِّ موظّف |
| ويب | `POST reports/review/{id}` | `reports.review.act` | `ReportReview::canReview` |
| ويب | `POST reports/compliance/{id}/finalize` | `reports.finalize` | `hr:e`/مالك |
| api v1 | `GET reports/my-daily` | `api.v1.reports.my_daily` | داخليٌّ بملفِّ موظّف |
| api v1 | `GET reports/today-compliance` | `api.v1.reports.today` | داخليٌّ بملفِّ موظّف |
| api v1 | `GET reports/daily` | `api.v1.reports.daily` | `hr:v` |
| جوّال v1 | `GET work/today` | `mobile.work.today` | داخليٌّ (self) |
| جوّال v1 | `GET work/daily-report` | `mobile.work.daily_report` | مرادفٌ لـ`work/today` |

> التقديمُ لا مسارَ له هنا: يُعادُ استعمالُ `POST updates` (ويب/جوّال) — لا مسارَ تقديمٍ مكرّر (§94).

## ٩) جدولُ الحالاتِ القانونيّة (`DailyWorkCompliance:33-40`)

| الحالة (`state`) | المعنى | الأثرُ الفعّالُ المشتقّ |
|---|---|---|
| `not_required` | إجازة/عطلة أو الاشتراطُ مُطفأ | `leave` / `present` |
| `absent` | لا حضورَ في يومِ عمل | `absent` (وإن قُدِّم تقرير — §45) |
| `checked_in` | ورديةٌ مفتوحةٌ بعد | `present` |
| `report_pending` | انصرف، بلا تقرير، قبلَ المهلة | `present` |
| `present_reported` | حضورٌ + تقريرٌ صالحٌ في وقته | `present` |
| `present_without_report` | حضورٌ بلا تقريرٍ بعدَ المهلة | بالسياسة: `present`/`non_compliant`/`absent_due_to_missing_report` |
| `absent_due_to_missing_report` | أثرُ سياسةِ `absence_equivalent` | `absent_due_to_missing_report` |
| `late_report` | تقريرٌ قُدِّم بعدَ المهلة | `present` (يحتاج مراجعة) |

> الأثرُ الفعّالُ الخام في `deriveEffective` (`:268`): `present`/`leave`/`absent`/`absent_due_to_missing_report`/`non_compliant`؛ و`excused` يُضاف عند القفلِ اليدويّ (`finalizeCompliance:124`). القفلُ يعلو الاشتقاق (`compose:191`) بمن ومتى ولماذا، ولا يمسّ ختمَي الحضور/الانصراف (§7).
