# 09 — التخويل: من يقرأ، من يراجع، وما الحدود

> **كلُّ سطحٍ في مركز التقارير منطَّقٌ بثلاث طبقاتٍ متعامدة:** حاجزُ الحساب (العميلُ
> يُردّ ٤٠٤ في كلِّ مكان §79/§114)، وحاجزُ الصلاحية (`hub_can` عبر التنطيق لا اسمَ
> الدور §32/§76)، وحاجزُ العزل (الشركةُ عبر `hub_company_scope`، والمشروعُ عبر
> `hub_scope` §80/§115). لا يُمنَح وصولٌ إلا بمرورِ الطبقات الثلاث. والتخويلُ خادميٌّ
> صرفٌ — لا يُشتقّ من واجهةٍ ولا اسمِ دور (ثابتٌ أمنيّ `security.authorization`،
> `config/hub_features.php:200`).

## الطبقةُ الأولى — حاجزُ الحساب (§79/§114)

في `ReportsController` حارسان يُستدعيان في مطلع كلِّ منفذ
(`app/Http/Controllers/Web/ReportsController.php`):

| الحارس | السطر | الفعل |
|---|---|---|
| `guardInternal()` | `:30-33` | `abort_if(hub_is_client(auth()->user()), 404)` — حسابُ العميلِ لا يرى **أيَّ** تقريرٍ داخليّ |
| `guardTeam()` | `:36-40` | يستدعي `guardInternal()` ثمّ `abort_unless(hub_can(..., 'hr', 'v'), 403)` |

فحصُ العميل يسبق فحصَ الصلاحية دائماً، ويردُّ **٤٠٤ لا ٤٠٣** — فلا يُفصح السطحُ عن
وجودِه لحسابٍ خارجيّ (§79). كلُّ منفذٍ يبدأ بأحد الحارسين: `index/day` بـ`guardTeam`،
و`review/reviewAct/finalize/mine` بـ`guardInternal` ثمّ حاجزِ صلاحيّته الخاصّ.

الواجهةُ (API) تُطبّق العزلَ نفسَه: `ReportsApiController` يردُّ العميلَ ٤٠٤ في كلِّ
منفذ (`app/Http/Controllers/Api/ReportsApiController.php:24,53,64`)، و`today-compliance`
و`daily` يشترطان `hr:v` بعده (`:65`). يشهد بذلك
`test_api_v1_reports_deny_client_account`
(`tests/Feature/AttendanceReportComplianceTest.php:452-465`).

## الطبقةُ الثانية — من يراجع (§32/§33/§76/§77)

المراجعةُ **تنطيقٌ لا اسمُ دور**. القارئان في `ReportReview`
(`app/Support/ReportReview.php`):

### `canReview(User $actor, WorkUpdate $w)` — أيراجع هذا البندَ بعينه؟ (`:35-53`)

| الفاعل | يراجع؟ | المرجع |
|---|---|---|
| حسابُ عميل | لا | `:37` |
| صاحبُ التقرير (`created_by == actor`) | **لا** إلّا إن كان مالكاً | `:41` (فصلُ التنفيذ عن الحكم §76) |
| المالك (`role.is_owner`) | نعم مطلقاً — حتى تقريرَه | `:42` |
| مسؤولُ موارد بشرية (`hr:e`) | نعم | `:45` |
| مديرُ المشروع (`updates:e` + البندُ ضمن `hub_scope('projects')`) | نعم | `:48-51` |

القاعدةُ الحاكمة: **لا يراجع أحدٌ تقريرَه إلّا المالك**. مديرُ المشروع محصورٌ ببنودِ
مشاريعه (`hub_scope(projects)` على `project_id` البند، `:49-50`) — فلا يبلغ بنداً خارج
نطاقه. يشهد `test_unauthorized_user_cannot_review_anothers_report`
(`tests/Feature/AttendanceReportComplianceTest.php:356-373`): زميلٌ بلا صلاحيةٍ يُمنع،
وصاحبُ التقريرِ يُمنع من نفسِه، والمنفذُ `reviewAct` يردّ ٤٠٣.

### `canReviewAny(?User $actor)` — أيرى مركزَ المراجعة أصلاً؟ (`:56-60`)

بوّابةُ السطح: `owner || hr:v || updates:e`، والعميلُ محجوبٌ دائماً (`:58`). تُستعمل في
`review()` (`ReportsController:117`) وفي حارسِ IA — فالطابورُ لا يُعرَض لمن لا صلاحيةَ
مراجعةٍ له بحال، وكلُّ فعلٍ داخلَه يُعاد فحصُه ببندٍ محدَّدٍ عبر `canReview`.

### القفلُ بعد القبول (§30)

`isLockedForEditor()` (`:68-73`): تقريرٌ `accepted` مقفولٌ على كاتبِه — لا يعيد كتابتَه
صامتاً — إلّا أن يكون مالكاً أو `hr:e` (يُعيدان فتحَه بأثرٍ مدقَّق). يشهد
`test_review_cycle_and_accepted_report_is_locked_for_author`
(`tests/Feature/AttendanceReportComplianceTest.php:323-352`).

### ختمُ الأثرِ الفعّال (§40/§90)

`finalize()` (`ReportsController:173-190`) أضيقُ من المراجعة: `hr:e` أو المالكُ حصراً
(`:176`) — لا يكفي `hr:v`. والصفُّ معزولٌ بالشركة (٤٠٤، `:178`)، والختمُ يرفض بلا سببٍ
موثّق (عدا `clear`).

## الطبقةُ الثالثة — العزل (§80/§115)

**عزلُ الشركة:** كلُّ استعلامٍ يمرّ بـ`hub_company_scope(hub_scope(Employee::query(),
'hr'), 'hr')` — `hub_scope` يفرض العزلَ الصارم، و`hub_company_scope`
(`app/Support/helpers.php:1783-1792`) يقصر على الشركةِ النشطة عبر عمودها
(`hub_company_col`, `:1733-1758`). في `index()` (`:49`) للقائمة، وفي `day()` (`:96-97`)
بفحصِ وجودٍ يردُّ ٤٠٤ لموظّفِ شركةٍ أخرى، وفي `finalize()` على صفِّ الحضور بوحدة
`attend` (`:178`). يشهد `test_company_isolation_in_reports_center`
(`tests/Feature/AttendanceReportComplianceTest.php:394-415`): مديرٌ منطَّقٌ على «ألف»
يرى موظّفَها ولا يرى موظّفَ «باء».

**عزلُ المشروع في الطابور:** `reviewableUpdates()` (`ReportsController:212-229`) — المالكُ
يرى الكلَّ؛ وغيرُه يرى بنودَ مشاريعِه (`hub_scope('projects')`, `:218`) واتّحادَ بنودِ
موظّفي شركتِه إن ملك `hr:v` (`:220-223`). فالطابورُ لا يعرض بنداً خارج نطاقِ مشاريعِ
المستخدمِ أو شركتِه.

## حرّاسُ الهندسة المعماريّة (IA)

الوجهاتُ الثلاثُ في مسار `work → exec` (`config/hub_ia.php:179-188`)، وحرّاسُها في
`InformationArchitecture::guards()` تعكس منطقَ المنافذ تماماً
(`app/Support/InformationArchitecture.php`):

| الحارس | الشرط | السطر |
|---|---|---|
| `reports_mine` | `$u !== null && ! hub_is_client($u)` — أيُّ موظّفٍ داخليٍّ لنفسه | `:113` |
| `reports_center` | `! hub_is_client($u) && hub_can($u, 'hr', 'v')` — يطابق `guardTeam` | `:109` |
| `reports_review` | `ReportReview::canReviewAny($u)` — المصدرُ نفسُه لا نسخةٌ منه | `:111` |

الحارسُ يحكم **الظهورَ** فقط؛ والمنفذُ يُعيد الفحصَ خادميّاً — فإخفاءُ الرابطِ ليس
أمناً. واسمُ حارسٍ مجهولٍ يُعامَل `authed` (أضيقُ افتراضٍ آمن، لا يمنح صلاحيةً،
`:117-124`).

## سجلُّ القدرات

قدرةٌ واحدةٌ قانونيّةٌ للسطح كلِّه (`config/hub_features.php:131-135`):

```
'workos.daily_reports' => domain: work_os, permissions: ['hr'], status: ENABLED,
    web_routes: ['reports.index', 'reports.review', 'reports.mine'], introduced: v2.476.0
```

لا مفتاحَ لكلِّ تبويب (§120): مركزُ التقارير والمراجعةُ قدرةٌ واحدةٌ فوق `WorkUpdate`
القائم، لا محرّكَ تقاريرَ ثانٍ. `hub_capability` (`app/Support/helpers.php:309-314`)
بوّابةُ التوافرِ المستقلّةُ عن الصلاحية — كلاهما يجب أن يمرّ: «أمتاحةٌ القدرة؟» و«أمصرَّحٌ
للمستخدم؟».
