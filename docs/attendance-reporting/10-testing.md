# 10 — الاختبارُ ومصفوفةُ الانحدار

> **إثباتٌ لا ادّعاء (§5):** كلُّ عيبٍ يُكتَب اختباراً **يفشل أوّلاً على الكود القائم**
> ثمّ يُصلَح؛ وكلُّ حالةٍ قانونيّةٍ في `DailyWorkCompliance` يحرسها اختبارٌ مسمّى.
> الحزمةُ تُشغَّل على **المحرّكين** (SQLite صامتةٌ حيث MySQL صارمة، §118).

## استراتيجيّة الاختبار

- **قاعدةٌ معزولةٌ تُبنى لكلِّ اختبار.** `Tests\TestCase` يستعمل `RefreshDatabase`
  (`tests/TestCase.php:16`) — هجرةٌ نظيفةٌ لكلِّ دالّة، لا حالةٌ متسرّبةٌ بين الاختبارات.
- **الزمنُ محسومٌ لا صدفة.** المهلةُ (`report_deadline_at`) والامتثالُ يعتمدان «الآن»؛
  فكلُّ اختبارٍ يثبّت `Carbon::setTestNow(...)` بتوقيت `Asia/Kuwait` ويُلغيه في
  `tearDown` (`AttendanceReportComplianceTest.php:34-38`) — «قبلَ المهلة» و«بعدها»
  يُختبَران بلا اعتمادٍ على ساعةِ الجدار.
- **الهُويّةُ مُثبَتةٌ لا مفترَضة.** المُعِينُ `linkedEmployee()` (`:42-53`) يبني
  `Employee` عبر جسرِ `Employee.user_id ↔ User.id`، ويؤكّد كلُّ اختبارِ عيبٍ أنّ
  `Employee.id ≠ User.id` (`:64`) — فلا نجاحٌ يتّكئ على تصادفِ UUID (§108).
- **دورٌ منفِّذٌ عاديّ.** الموظفُ المختبَر يملك `updates:v/a/e` فقط لا `hr` ولا مالك
  (`:44-46`) — يُطابق مستخدمَ الإنتاج، ويكشف تسريباتِ الصلاحية.
- **القاعدةُ الذهبيّة في كلِّ الحالات:** تقريرٌ ناقصٌ ≠ غياب، والحضورُ الفيزيائيُّ
  لا يُطمَس — تُؤكَّد قيمةً قيمةً على `physical` و`state` و`effective`.

## الإثباتُ الفاشلُ أوّلاً (§5)

`test_report_after_checkout_is_recognized_not_present_without_report`
(`AttendanceReportComplianceTest.php:57-86`) يعيد سيناريو العيب حرفيّاً:
حضورٌ ← انصرافٌ ← ثمّ `WorkUpdate` **بعد** الانصراف. على الكود القبل الإصلاح كانت
`attendance.status` عالقةً `NO_REPORT` (الحالةُ حُسِبت مرّةً عند الانصراف ولم تُعَد،
[ROOT_CAUSE.md](ROOT_CAUSE.md))، فيسقط الاختبار. بعد الإصلاح يؤكّد ثلاثَ حقائق:

| المحور | التأكيد | السطر |
|---|---|---|
| الحضورُ الفيزيائيّ | `!= NO_REPORT` و`== PRESENT` | `:77-79` |
| الامتثالُ المشتقّ | `report_submitted` و`state == present_reported` | `:82-84` |
| الأثرُ الفعّال | `effective == 'present'` | `:85` |

## مصفوفةُ الانحدار — §102–§117 (١٩ اختباراً)

كلُّ صفٍّ اختبارٌ في `tests/Feature/AttendanceReportComplianceTest.php`:

| §المرساة | الدالّة | تحرس | السطر |
|---|---|---|---|
| §102/§5/§108 | `test_report_after_checkout_is_recognized_not_present_without_report` | العيبُ الأصليّ: تقريرٌ بعد الانصراف | `:57` |
| §103 | `test_no_report_is_pending_before_deadline_and_missing_after` | بانتظار قبلَ المهلة → حضورٌ بلا تقرير بعدها | `:108` |
| §104 | `test_late_report_preserves_lateness_and_needs_review` | التأخّرُ محفوظٌ ويستوجب مراجعة | `:136` |
| §105 | `test_approved_leave_requires_no_report_and_no_warning` | إجازةٌ معتمدة: `not_required`/`leave` | `:155` |
| §106 | `test_open_shift_is_never_a_final_missing_report` | ورديةٌ مفتوحة: لا غياب-تقرير نهائيّ | `:176` |
| §107 | `test_report_before_checkout_is_recognized` | تقريرٌ قبل الانصراف يُعرَف صحيحاً | `:192` |
| §110 | `test_multi_project_day_is_compliant_and_hours_not_double_counted` | يومٌ متعدّدُ المشاريع بلا عدٍّ مزدوج | `:208` |
| §111 | `test_non_project_work_counts_as_a_valid_report` | عملٌ داخليٌّ بلا مشروع = تقريرٌ صالح | `:229` |
| §13/§24 | `test_placeholder_report_does_not_satisfy_requirement` | نائبٌ رمزيّ («-») لا يُرضي الاشتراط | `:246` |
| §116 | `test_missing_report_notification_is_idempotent_across_reconcile_runs` | تنبيهٌ واحدٌ عبرَ ثلاثِ تشغيلاتِ مصالحة | `:261` |
| §117 | `test_changing_grace_immediately_changes_evaluation` | تغييرُ `grace` يسري فوراً على التقييم | `:284` |
| §72 | `test_restoring_a_work_update_restores_task_hours` | استعادةُ بندٍ تُعيد `act_h` — لا نقصٌ صامت | `:306` |
| §112/§30 | `test_review_cycle_and_accepted_report_is_locked_for_author` | تنقيح→تعديل→قبول ثمّ قفلُ المؤلّف | `:323` |
| §113 | `test_unauthorized_user_cannot_review_anothers_report` | لا مراجعةَ بلا صلاحية، ولا مراجعةُ الذات → 403 | `:356` |
| §114 | `test_client_account_cannot_reach_reports_surfaces` | حسابُ العميل: كلُّ الوجهات → 404 | `:377` |
| §115 | `test_company_isolation_in_reports_center` | لا تسرّبَ تقارير/حضور عبرَ الشركات | `:394` |
| §45 | `test_report_without_attendance_keeps_facts_separate` | تقريرٌ بلا حضور: `physical` يبقى `null` | `:419` |
| §93/§94 | `test_api_v1_my_daily_uses_the_same_resolver` | REST يستهلك المُحلِّلَ نفسَه | `:435` |
| §93/§94 | `test_api_v1_reports_deny_client_account` | REST للعميل → 404 | `:452` |

## التعديلاتُ على حِزَمٍ أخرى

- **`WorkforceTest::test_missing_report_never_overwrites_physical_presence`**
  (`WorkforceTest.php:98-135`) — يؤكّد المبدأ من جهةِ الحضور: حضورٌ ← انصرافٌ بلا بند
  ⇒ `status == 'حاضر'` (لا يُطمَس)، والامتثالُ حالةٌ منفصلةٌ `report_pending` قبلَ
  المهلة بأثرٍ `present` (لا عقوبةٌ مبكّرة)؛ ويغطّي: بندٌ قبل الانصراف ⇒
  `present_reported`، وإطفاءُ `report_required` ⇒ `not_required`.
- **`IntelligenceReportComplianceTest`** (`:19-51`) — إشارةُ «تقريرٌ يوميٌّ ناقص»
  في طبقة الذكاء تظهر لمن حضر بلا تقرير و**تزول لحظةَ تقديمه** (اشتقاقٌ حيّ، مفتاحٌ
  لكلِّ مستخدمِ HR)، ولا تظهر حين لا حضورَ أصلاً (الغيابُ شأنٌ آخر).

## البوّابةُ ثنائيّةُ المحرّك (§118)

الحزمةُ نفسُها تُشغَّل على محرّكين لأنّ SQLite تتساهل حيث تصرم MySQL:

| المحرّك | الإعداد | القاعدة |
|---|---|---|
| SQLite | `phpunit.xml` | `:memory:` — سريعٌ للتطوير |
| MySQL 8 | `phpunit.mysql.xml` | `hub_test` — المحرّكُ الإنتاجيّ فعلاً |

CI (`.github/workflows/ci.yml:26-29`) يُشغّل مصفوفةً `fail-fast: false`:
`8.2·sqlite`، `8.4·sqlite`، `8.4·mysql` — فتُقرأ نتيجةُ الاثنين في جولةٍ واحدة، ولا
يبقى «أخضر» متّكئاً على أن يتذكّر أحدٌ الأمر. وقبلَ الحزمة على MySQL تمرّ الهجراتُ
كلُّها ثمّ `hub:schema-check` (`:86-92`): عمودٌ في السجلّ بلا هجرةٍ يسقط هنا لا على
الخادم. وبوّابةُ **OpenAPI** (`:66-75`) خارجَ الحزمتين — لا تكشفها `phpunit` — تُسقط
الدفعةَ إن انحرف `docs/openapi.json` عن السجلّ.

محليّاً قبل الدفع، على المحرّكين:

```bash
./vendor/bin/phpunit                          # SQLite
./vendor/bin/phpunit -c phpunit.mysql.xml     # MySQL
```
