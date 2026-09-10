# ٠١ — البنيةُ القائمة والمحرّكاتُ المُعاد استخدامها (§122)

مِيزةُ «الحضور × التقارير اليوميّة × الامتثال» لا تبني محرّكاً ثانياً: تمتدُّ على
سِكَكٍ قائمةٍ في المستودع. هنا جردُ تلك السِكَك كما هي في الكود — كلُّ محرّكٍ ومصدرُه
الواحد — قبل أن يُشتقَّ فوقها الامتثالُ في `DailyWorkCompliance`. **لا نسخةَ ثانيةً
عن أيٍّ منها.**

## المحرّكاتُ الستّة القائمة

| المحرّك | المصدرُ الواحد | ما يُقرأ منه للامتثال |
|---|---|---|
| الحضور | `attendance` · module `attend` | الحالةُ الفيزيائيّة، `time_in/out`، `date`، `emp_id` |
| بنودُ التقرير | `work_updates` · module `updates` | «هل قُدِّم تقريرٌ صالحٌ لتاريخ العمل؟» |
| جسرُ الهويّة | `Employee.user_id ↔ User.id` | ربطُ صفِّ الحضور بكاتبِ التقرير |
| يومُ العمل | `App\Support\Workday` | تسجيلُ الحضور/الانصراف والحالةُ الفيزيائيّة والكنس |
| الإشعارات | `notifications_hub` · `HubNotification` | تذكيرُ التقريرِ الناقص (نوع واحد، مرّةً) |
| المجدول | `hub:automation` @ `06:00` | شبكةُ أمانٍ يوميّة فوق الاشتقاق الحيّ |

## ١) الحضور — `attendance` (module `attend`)

`app/Models/Attendance.php`: الجدولُ `attendance`، الوحدةُ `attend`
(`Attendance.php:19-20`)، عمودُ العرضِ `date`. **الوصولُ إلى الموظف عبر `emp()`**
(`Attendance.php:33-36`) — علاقةُ `belongsTo(Employee, 'emp_id')`. أي أنّ صفَّ
الحضور منسوبٌ إلى `Employee.id` عبر `emp_id`، لا إلى `User.id`. الحقولُ
`custom/meta` مصفوفات، و`hours` عشريٌّ بثلاث خانات (`Attendance.php:25-31`).

## ٢) بنودُ التقرير — `work_updates` (module `updates`)

`app/Models/WorkUpdate.php`: الجدولُ `work_updates`، الوحدةُ `updates`
(`WorkUpdate.php:27-28`). **البندُ ليس نسخةً ثانيةً عن مصادرِ الوقت — يغذّيها**:
خطّافُ `created` يزيد `tasks.act_h` بساعاتِ البند مرّةً واحدة
(`WorkUpdate.php:67-69`)، فيعمل محرّكا الربحيّة والقدرات القائمان بلا عدٍّ مزدوج؛
و`updated` يُصالح الفارقَ عند تعديلِ الساعات/المهمة (`WorkUpdate.php:91-97`)،
و`deleted` يستردّ الساعات، و`restored` يُعيدها (§72 · `WorkUpdate.php:100-113`).
خطّافُ `saving` يملأ `work_date` من `now()->toDateString()` إن غاب — **لا من
`created_at`** (`WorkUpdate.php:51`)، ويُسند `created_by = auth()->id()`
(`WorkUpdate.php:57`). **مِفتاحُ المطابقةِ للتقرير هو `created_by` (= `User.id`)
و`work_date`** — لا `attendance.emp_id`.

## ٣) جسرُ الهويّة — `Employee.user_id ↔ User.id`

`app/Models/Employee.php`: الوحدةُ `hr`، والعلاقةُ `user()` = `belongsTo(User,
'user_id')` (`Employee.php:80-83`). **`Employee.id ≠ User.id`** (كلاهما UUID). الحضورُ
يُنسَب بـ`emp_id` (→`Employee.id`)، والتقريرُ بـ`created_by` (→`User.id`) — فالربطُ
بينهما لا يقع إلا بعبورِ `Employee.user_id`. حارسُ التصعيد على تغيير `user_id`
يمنع ربطَ حسابٍ ذي امتيازٍ أو حسابٍ مأخوذ (`Employee.php:51-58`)، والربطُ بالبريد
آليٌّ عند الإنشاء (`Employee.php:61`).

## ٤) محرّكُ يوم العمل — `App\Support\Workday`

منطقُ الحضور كلُّه في مكانٍ واحد (`app/Support/Workday.php`):

- **الجسر**: `Workday::emp(?User)` يجلب الملفَّ النشطَ من `user_id`
  (`Workday.php:44-50`)؛ `today($empId, $date)` صفُّ اليوم بترتيبٍ حتميّ
  (`Workday.php:53-58`).
- **الحضور** `checkIn()` (`Workday.php:66-109`): يكتب لسجلِّ صاحبه وحدَه (بلا
  صلاحيةِ وحدةِ الحضور)، يحسب التأخّرَ بدقائق اليوم من `sec.hours_start` +
  `work.late_grace`، ويختم `meta.checkin` (IP/جهاز).
- **الانصراف** `checkOut()` (`Workday.php:112-156`): يحسب الساعات، ويختم الحالةَ
  الفيزيائيّةَ عبر `evaluate()`، ويستدعي المُحلِّلَ المركزيّ للرسالة والمهلة.
- **`evaluate()`** (`Workday.php:167-175`): يُرجع **الحالةَ الفيزيائيّة فقط**
  (إجازة معتمدة تغلب، ثم متأخّر/ميدانيّ/عن بعد/حاضر) — لا يطمس الحالةَ بغيابِ التقرير.
- **الكنس** `close()` (`Workday.php:195-219`): يومُ عملٍ مجدولٌ بلا حضورٍ ولا إجازةٍ
  معتمدة = غياب، idempotent (الصفُّ الموجود لا يُمسّ، والعطلةُ ليست غياباً).
- **شاشةُ الفريق** `teamCalc()` (`Workday.php:228-288`): تقرأ عبر
  `DailyWorkCompliance::resolveMany` باستعلامَين لا استعلامٍ لكلِّ صف (N+1=0).

## ٥) الإشعارات — `notifications_hub` (`HubNotification`)

`app/Models/HubNotification.php`: الجدولُ `notifications_hub`، بلا `timestamps`
(`created_at` يُضبَط يدويّاً). خطّافُ `creating` يقصّ `text` إلى عرضِ عموده مركزيّاً
(درسُ MySQL `22001`)، ويكتم الأنواعَ القابلةَ للكتم عند المصدر بحسبِ تفضيلات المستلم
(`MUTEABLE`)، ويختم `request_id`. تذكيرُ «التقريرِ الناقص» يمرّ من هنا كنوعٍ واحد،
بلا تكرار (إشعارٌ لكلِّ موظف/يوم/نوع — §39).

## ٦) الإعدادات — `config/hub_settings.php`

مفاتيحُ العملِ القائمةُ المُعاد استخدامها (`hub_settings.php:624-713`): `work.late_grace`
(تأخّرُ الحضور، افتراض 15)، `work.report_required` (onoff, 1)، `work.progress_auto`
(اعتمادُ النسبةِ المقترحة)، `work.geo`. وتُقرأ جميعاً حيّاً عبر `setting(...)` فيسري
تغييرُها فوراً — لا لقطةَ مخزَّنة.

## ٧) المجدول — `hub:automation` @ `06:00`

`routes/console.php:18`: `Schedule::command('hub:automation')->dailyAt('06:00')`
مع `withoutOverlapping(240)`. داخل `handle()` (`HubAutomation.php:61-91`): الخطوةُ
`workdayClose()` تستدعي `Workday::close()` (ختمُ غيابِ الأمس · `HubAutomation.php:31-40/82`)،
كلٌّ في `try/catch` معزولِ الفشل. المجدولُ **شبكةُ أمانٍ لا مصدرَ حقيقة**.

## سكّةُ التحديثِ الحيّ (لا استقصاء ولا WebSockets)

كلُّ كتابةِ Eloquent تُطلق حدثَ `saved/deleted/restored` العامَّ الذي يرفع ختمَ
جدولها عبر `hub_data_bump($table)` (`AppServiceProvider.php:64-69` ·
`helpers.php:3133-3151`). ومفاتيحُ الشاشات المحسوبة تحمل `hub_data_stamp($tables)`
(`helpers.php:3153-3186`)، فشاشةُ الفريق المفتاحُ لها `['attendance','work_updates',
'employees']` (`Workday.php:225`) — أي **كتابةُ بندِ تقريرٍ واحد تُبطِل خبيئةَ الشاشة
فوراً** فيُعاد الاشتقاق. هذا بديلُ إعادةِ التقييمِ اللحظيّة: الاشتقاقُ حيٌّ على القراءة
لا مخزَّنٌ يُعاد حسابُه بخطّاف.

## ما لا يُنشَأ (§122 · non-goals)

لا محرّكَ تقارير يوميّةٍ ثانٍ، ولا درجةَ إنتاجيّة، ولا mobile/WebSockets/Redis أصليّة،
ولا عقوبةَ رجعيّة، ولا تزييفَ للحضورِ الفيزيائيّ غياباً. الامتثالُ (`DailyWorkCompliance`)
والمراجعةُ (`ReportReview`) وتاريخُ العمل (`BusinessDate`) طبقاتُ اشتقاقٍ **فوق** هذه
السِكَك الستّ — تُوثَّق في ملفّاتها.
