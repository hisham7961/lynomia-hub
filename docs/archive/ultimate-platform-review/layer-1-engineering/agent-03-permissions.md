# الوكيل ٠٣ — الصلاحيّات 360

> **المراجعةُ الشاملة · طبقة ١ (الهندسة) · الجولة أ**
> المستودع `/home/user/lynomia-hub` · الرأس `1d90626` (v2.540.1) · قراءةٌ فقط.
> السؤالُ الحاكم: **«لماذا يرى هذا المستخدمُ هذا الشيء؟»** — جوابٌ واحدٌ لكلِّ وصولٍ ذي معنى، لا جوابان ولا صفر.

---

## ٠. خلاصةُ الحكم

السكّةُ نفسُها — `hub_can()` + `hub_scope()` + `hub_field_mode()` — **سليمةٌ ومركزيّةٌ وموثَّقةٌ توثيقاً نادراً**.
زحفٌ آليٌّ على ١٧٩ صفحةَ ويبٍ بلا وسائط وعلى ٨٤ وحدةً عبر `/api/v1` بثلاث شخصيّاتٍ معزولة
(شركةً · عميلاً · مشروعاً) لم يكشف إلّا **ثلاثةَ مواضعِ تسرّبٍ**، وكلُّها خارجَ السكّة لا فيها:
قارئٌ أعاد كتابةَ التنطيقِ بيده، ومنسدلةٌ نسيت العزل، ولوحةُ سجلٍّ لم تستشر قناعَ الحقل.

والعيبان الأخطرُ ليسا تسرّباً بل **صفرَ جواب**: صلاحيّتان مقروءتان في الشيفرةِ لا يمكن منحُهما
من شاشةِ الأدوار أصلاً (`custody:*` و`files:docsec`) — فبابٌ كاملٌ (محفظةُ العهدة) محجوزٌ
للمالكِ وحدَه إلى الأبد، ومنحُهما يدوياً في القاعدة **يُمحى صمتاً** عند أوّلِ حفظٍ للدور.

ويليهما **جوابان متناقضان**: `endpoints:v` تفتح الأسطولَ عبر `/api/v1` بينما يردّها الويبُ ٤٠٣.

و`PermissionInspector` — وهو الحكمُ حين يُسأل «لماذا؟» — **يكذب في أربع حالاتٍ متطرّفة**
أُثبتت بالتشغيل. ومفسِّرٌ يكذب أخطرُ من مفسِّرٍ غائب: يُقنع المالكَ أنّه فهم فيتوقّف عن السؤال.

| # | الخطورة | العنوان |
|---|---|---|
| A03-01 | **P1** | `endpoints`: الويبُ يشترط `secOps` والـAPI/الجوالُ لا — المصفوفةُ وحدَها تفتح الأسطول |
| A03-02 | **P1** | `custody` وحدةٌ محروسةٌ خارجَ سجلِّ الوحدات — لا تُمنح لأحد، والمنحُ اليدويُّ يُمحى عند أوّلِ حفظِ دور |
| A03-03 | **P2** | `files:docsec` مقروءةٌ في `hub_scope` وغيرُ مُعلَنةٍ في الكتالوج — وعدٌ لا يُمنح |
| A03-04 | **P2** | ودجةُ «آخر النشاطات» تعيد كتابةَ تنطيقِ التدقيقِ بيدها وتُسقط **عزلَ العملاء** (ويسري على الجوال) |
| A03-05 | **P2** | لوحةُ بنودِ العرض في صفحةِ السجل تطبع الإجماليَّ المحجوب — والطباعةُ تحترمه |
| A03-06 | **P2** | منسدلتا «المشروع» و«العميل» في سجلِّ التدقيق تتجاوزان عزلَ الشركات |
| A03-07 | **P2** | المفسِّر يقول «مسموح» لِما يردّه الويبُ ٤٠٣ (`endpoints`) — و`navigation()` تُدرجها مرئيّة |
| A03-08 | **P3** | المفسِّر يصمت عن `fieldsec`/`locked`/مجموعاتِ الكتابة في «قيودُ الحقل» |
| A03-09 | **P3** | المفسِّر يبدّل عمليّةً غيرَ معروفةٍ بـ`v` **صمتاً**: `explain(fin,'export')` يقول «مسموح» و`hub_can` يقول لا |
| A03-10 | **P3** | ملاحظةُ النطاقِ تقول «لا قيدَ نطاقٍ إضافيّ» بينما `hub_scope` تحجب `files` السريّة وتقصر `fin` على فواتيرِ العميل |
| A03-11 | **P3** | تجميدُ التصديرِ الطارئ لا يغطّي تنزيلَ مرفقٍ ولا **حزمةَ السجلّ** (`att.zip`) |
| A03-12 | **P3** | البحثُ يبحث دوماً في عمودِ العرضِ ولو كان محجوباً — عرّافُ وجود |
| A03-13 | **P3** | ٢٩ من ٣٤ لوحةً مخصَّصةً في صفحاتِ السجلّات لا تستشير `hub_field_mode` إطلاقاً (الفئةُ التي منها A03-05) |
| A03-14 | **P4** | `inboxdocs` اسمُ صلاحيّةٍ ميّتٌ — يُقرأ دائماً مع `|| files` فلا أثرَ لغيابه |
| A03-15 | **P4** | `secrets` و`copySec`: التسميةُ تَعِد بفصلٍ لا يقع — `copySec` وحدَها تكشف كلَّ حقلِ `sec` |

**الأرقامُ المطلوبة:** ٨٥ وحدةً في السجلّ · ٨٤ في شاشةِ الأدوار (`users` مستثناةٌ عمداً وبنصٍّ صريح) ·
**وحدةٌ واحدةٌ (`endpoints`) لها جوابان** · **وحدةٌ واحدةٌ (`custody`) خارجَ السجلِّ بلا جوابٍ أصلاً** ·
**٣ وحداتٍ بلا أيِّ عمودِ تنطيق** (`restores` · `competitors` · `plans`) · **٥ وحداتٍ بعمودِ مشروعٍ بلا عمودِ شركة**
(`ideas` · `incidents` · `deploys` · `requests` · `deps`) — هذه تُحكَم بالمصفوفةِ وحدَها، وهو قرارٌ معلَنٌ في
`hub_scope` لا سهوٌ، لكنّه يعني أنّ العزلَ الصارمَ لا يبلغها.

---

## ١. المصفوفة: ٨٥ وحدة × ٤ عمليّات

### ١.١ التمثيل في شاشةِ الأدوار — سليم

```
modules=85 · shown in RoleController::groupedModules()=84 · missing=[users] · extra=[]
```
(المسبار: `scratchpad/probe/p1.php`)

استثناءُ `users` **مقصودٌ وموثَّق** (`RoleController.php:60-71`): خاناتُها الأربعُ كانت عقيمةً لأنّ
`ModuleController::resolve` يردّ ٤٠٤ عليها و`UserController` يحرس برايةِ `users`. هذا نموذجُ ما ينبغي:
حارسٌ واحدٌ، معلَنٌ، ومكتوبٌ سببُه.

### ١.٢ اختبارُ المنحِ المفرد — ٨٣ من ٨٤ تفتح بابَها

منحُ وحدةٍ واحدةٍ فقط (`v`) ثمّ فتحُ `/m/{module}` لكلِّ وحدة:

```
--- single-grant failures --- 
endpoints      web=403
--- end (1) ---
```
(المسبار: `A03SingleTest.php`)

فلا وحدةَ «لا تُمنح» في الشاشةِ إلّا `endpoints` — وتفصيلُها في A03-01.

### ١.٣ لكنّ المصفوفةَ ليست كلَّ ما يُقرأ بـ`hub_can`

مسحٌ لكلِّ نداءات `hub_can($u, '<x>', …)` في `app/` و`resources/` مقابلَ مفاتيحِ السجلّ:

```
--- used with hub_can but NOT a registered module ---
custody
inboxdocs
```

`custody` = A03-02 (خطير) · `inboxdocs` = A03-14 (ميّتٌ غيرُ ضارّ).

---

## ٢. الأعلام — لا رايةً ميّتة، ولا رايةً غيرَ مُعلَنة

مسحٌ لكلِّ `hub_flag($x, '<flag>')` في المستودع:

| الراية | مواضعُ القراءة | البابُ الذي تفتحه |
|---|---|---|
| `users` | ١٦ | `UserController::gate` · `Staff::canManage` · `hub_user_admins` · `guardAccountRequest` |
| `audit` | ٩ | `AuditController::index/show` (٤٠٣) · مراكزُ التحقيق |
| `approve` | ٨ | `hub_approver` → الموافقات · صندوقُ الوارد · `AlertEngine` |
| `monitor` | ٦ | `hub_monitor` (٣٢ نداء) → اللوحاتُ التحليليّة كافّة |
| `opsAnalytics` / `finAnalytics` / `secOps` | ١ لكلٍّ + ٢٧ عبر `hub_monitor_group` | مجموعاتُ اللوحاتِ الثلاث + `EndpointCentre` |
| `secrets` | ٢ | غرفةُ البيانات + `can_secrets` في سياقِ الجوال |
| `copySec` | ١ | `hub_copy_secrets` → كشفُ حقلِ `sec` (وتُقرأ `secrets` معها) |
| `dataroomShare` | ١ | `DataRoomController::gate` (بالـOR مع `secrets`) |
| `exp` | ١ | `hub_exporter` → حزامُ التصدير |
| `mobile` | ٢ | `MobilePlatformController::gate` + روابطُ الإدارة |
| `oversight` | ٠ بـ`hub_flag` — **لكنّها تُقرأ خاماً** | `OversightController::isOversightOfficer:61` (عمداً: لا تُورَّث للمالك) |

**لا رايةَ مُعلَنةٍ بلا قارئ** — و`oversight` التي تبدو ميّتةً في المسحِ الآليّ تُقرأ من
`$user->role->flags` مباشرةً بسببٍ مكتوب. **ولا رايةَ تُقرأ وهي غيرُ مُعلَنةٍ في `FLAGS`**.

### فعلٌ حسّاسٌ بلا حارس؟

زحفٌ على **كلِّ** مسارات الكتابةِ بلا وسائطَ بمستخدمٍ **بلا مصفوفةٍ ولا رايات**
(`A03WriteTest.php` ثمّ `A03AdminWriteTest.php` بعد تصفيرِ المحدِّد):

```
admin/settings/odoo-test 403 · admin/security/blocks 404 · admin/settings/import 403
admin/settings/import/apply 403 · admin/integrations/n8n/test 403 · admin/settings/restore 403
remediation 403 · admin/quality/merge/preview 403 · admin/mobile-platform/push/test 403
```

ما بلغ جسدَ المتحكّمِ هو الخدمةُ الذاتيّةُ حصراً (`workday/check-in` · `profile/*` · `personalize/*` ·
`company-switch`/`client-switch` — وكلاهما يتحقّق من `hub_company_ids`/`hub_client_ids` قبل الحفظ،
`routes/web.php:205-231`). **لا فعلَ حسّاسٍ بلا حارس.**

---

## ٣. النطاق — التنطيقُ يعمل، والخروقُ الثلاثةُ خارجَ السكّة

`hub_scope()` (`helpers.php:169-254`) يجمع أربعَ طبقاتٍ في موضعٍ واحد: مشاريعُ الدور ← عزلُ الشركات
← عزلُ العملاء ← قيدان دلاليّان (`fin` لحسابِ العميل، `files` السرّيّة). و٤٠٩ نداءَ `hub_scope` في `app/`.

**الزحفُ الآليّ** (`A03ScopeCrawlTest.php` / `A03Crawl2Test.php`): مشروعٌ وعميلٌ وموظّفٌ بأسماءَ فريدةٍ
في شركةٍ أجنبيّة، ثمّ فتحُ كلِّ صفحةِ ويبٍ بلا وسائط (١٧٩ مساراً) بثلاث شخصيّات:

```
=== A) معزولُ شركة (كلُّ المصفوفةِ وكلُّ الرايات) ===
admin/audit :: project          ← A03-06
admin/audit :: client           ← A03-06
=== B) معزولُ عميل ===
/ :: client                     ← A03-04
=== C) محدودُ المشاريع (بلا مشروعٍ مُسنَد) ===
(لا شيء)
```

**والـAPI أنظف**: مسحٌ لِـ٨٤ وحدةً عبر `/api/v1` بمستخدمٍ معزولِ شركةٍ — `API read leaks: none`،
والوصولُ المباشرُ لسجلٍّ أجنبيٍّ ٤٠٤ في الويبِ والـAPI معاً، والكتابةُ بـ`companyId` أجنبيٍّ
**تُقصَر قسراً** على شركةِ الكاتب (`inheritCompany`, `ModuleController:1487`):

```
API write foreign company => 201 ... stored company_id = <شركةُ الكاتب> (لا الأجنبيّة)
```

---

## ٤. قيودُ الحقل — `hide` / `ro` (و«mask» ليست وضعاً ثالثاً)

`hub_field_mode()` تُرجع `''` · `'ro'` · `'hide'` فقط. و«التقنيع» ليس وضعاً في المحرّك:
`hub_masked()` (`helpers.php:4417`) مجرّدُ غلافٍ (`!== ''`)، والنجومُ `••••` تُطبع في موضعِ النداء.
فالوعدُ الثلاثيُّ في الواجهةِ ثنائيٌّ في المحرّك — وهذا اتّساقٌ لا عيب، لكنّه يستحقّ التسمية.

### السطوحُ التي تحترمه (مُثبَتة)

| السطح | الموضع | الحال |
|---|---|---|
| القائمة | `columnsAndLabels` → `hub_visible_fields` (`ModuleController:1652`) | ✔ |
| صفحةُ السجل (الحقولُ المُعلَنة) | `modules/show.blade.php:103` | ✔ |
| الفرزُ والترشيح | `buildQuery:81` · `applyAdvancedFilters:157` | ✔ |
| التصدير CSV | `exportColumnsInclude:1002` | ✔ |
| الطباعة (عرضُ السعر) | `QuoteController::doc:39` (`hideTotal`/`hideItems`) | ✔ |
| API | `V1Controller::shape:553` | ✔ |
| البحثُ النصّي | `Traits/Searchable.php:31` | ✔ جزئيّاً — انظر A03-12 |
| الكتابة | `fill():1864` (يسري على الويبِ والـAPI والجوال) | ✔ |
| الرادارُ والعدّادات | `hub_expiry` (`helpers.php:1952`) — وحدةٌ ثمّ حقلٌ ثمّ نطاق | ✔ |
| CSV الحضورِ والرواتب | `ReportsController:324-329,439` | ✔ |
| **لوحاتُ السجلِّ المخصَّصة** | `resources/views/modules/custom/*` | ✘ **A03-05 / A03-13** |

قياسٌ آليّ: ٢٩ من ٣٤ لوحةً مخصَّصةً لا تذكر `hub_field_mode` ولا مرّة.

---

## ٥. تجاوزُ المالك — موضعٌ واحدٌ في السكّة، وسبعُ نوافذَ حولها

| الموضع | الملف:السطر | الأثر |
|---|---|---|
| `hub_can` | `helpers.php:386` | `is_owner ⇒ true` لكلِّ وحدةٍ وكلِّ مفتاح |
| `hub_flag` | `helpers.php:1070` | كلُّ رايةٍ (عدا `oversight` — تُقرأ خاماً) |
| `hub_scoped` | `helpers.php:70` | المالكُ ليس محدودَ المشاريع أبداً |
| `hub_company_ids` | `helpers.php:125` | `null` = بلا عزلِ شركات |
| `hub_client_ids` | `helpers.php:275` | `null` = بلا عزلِ عملاء |
| `hub_field_mode` | `helpers.php:2487` | `''` لكلِّ حقلٍ — **عدا `locked`** (يعود قبلَه، ص ٢٤٨٠) |
| `hub_guard_scope_input` | `helpers.php:1042` | لا يُفحَص إدخالُ المالك |

فالتجاوزُ **مركزيٌّ بالفعل**: كلُّ قارئٍ يمرّ بإحدى هذه السبع. وما تناثر خارجَها
(`$u->role?->is_owner ||` في `Staff` و`AlertEngine` و`Dashboard` و`ContractApprovals`) إمّا تفويضٌ
لـ`hub_is_owner` وإمّا حالةٌ خاصّةٌ موثَّقة (`Staff::canManage:94` — «لا يُدير مالكاً إلّا مالك»).
و`RoleController` يحرس الدورَ نفسَه ثلاثَ مرّات (`update`/`clone`/`destroy` كلُّها `abort_if(is_owner)`).
**لا شذوذَ يُذكَر هنا.**

---

## ٦. الخدمةُ الذاتيّة — متّسقةٌ في الأثر، متناثرةٌ في التعريف

لا دالّةَ `hub_self()` واحدة. «سجلّي أنا» يُعرَّف في خمسةِ مواضعَ بخمسِ صيغ:

| الموضع | القاعدة | الحارس |
|---|---|---|
| `hub_scope` (محدودُ المشاريع) | `assignee_id = me` أو (`project_id IS NULL` و`created_by = me`) | داخلَ السكّة |
| `portal.me` | `employees.user_id = me` | بلا `hr:v` — عمداً |
| `portal.custody` | `assets.holder_id = me` | **بلا `assets:v` وبلا `hub_scope`** — «الحيازةُ نفسُها هي التفويض» (`PortalController:84`) |
| `EmployeeDocuments::forUser` | وثائقُ ملفِّ الموظّفِ المرتبطِ بالحساب | + `DocumentPolicy::subjectMay` |
| `ModuleController::setStatus` | `tasks` بلا `e` ⇒ تحديثُ نسبةِ مهمّتي وحدَها | `:736,756` |

كلُّ استثناءٍ **مكتوبٌ سببُه في موضعه**، والأعمدةُ المعروضةُ في المسارِ الذاتيِّ محصورةٌ يدوياً
(لا شراءَ ولا ماليّة). فالخطرُ ليس في الحاضرِ بل في المستقبل: **خامسُ تعريفٍ لا يُنسَخ بالضرورةِ سادساً**.
لا ملاحظةَ P1/P2 هنا — توصيةٌ معماريّةٌ في §١٠.

---

## ٧. الوثائقُ والملفّاتُ الفرديّة — السكّةُ مُحكَمة

`AttachmentService::guardRecord` هي **نقطةُ التخويلِ الوحيدة** (`hub_can(module,'v')` + `hub_scope→findOrFail`)،
وفوقَها `DocumentPolicy::authorize` في **كلا** البابين:

| البابُ | الحارس | الحال |
|---|---|---|
| `att.dl` | `guardRecord` + `DocumentPolicy::authorize('download')` (`AttachmentService:154-156`) | ✔ |
| `att.view` | `guardRecord` + `authorize('preview')` (`:200-202`) | ✔ |
| `att.zip` | `guardRecord` + ترشيحُ `DocumentPolicy::allows` لكلِّ ملفٍّ (`AttachmentController:149`) | ✔ |
| `file.show` (المسارُ الخام) | `mayRead()` + **إعادةُ فرضِ `DocumentPolicy`** إن كان المسارُ مرفقاً (`FileController:41`) | ✔ — الالتفافُ مسدود |
| بوّابةُ العميلِ والموظّف | `serve()`/`streamServe()` بعد تخويلٍ مختلفٍ صراحةً (عضويّة/`user_id`) | ✔ |
| **حزامُ التصديرِ الطارئ** | — | ✘ **A03-11** |

---

## ٨. قيودُ العميل (`PortalGuard`) — القائمةُ البيضاءُ تصمد

حسابُ عميلٍ **بمصفوفةٍ كاملةٍ (٨٥ وحدة × ٤) وثمانِ راياتٍ** — كلُّ ما يبلغه ٢٠٠:

```
portal · portal/engagements · portal/projects · portal/documents · portal/invoices
portal/conversations · portal/tickets · portal/tickets/new · profile
api/mobile/v1/{app-config,health,openapi.json}   ← المجموعةُ العامّةُ قبلَ المصادقة
--- client API 200 modules (3) --- projects, fin, engagements
```
(المسبار: `A03PortalTest.php`)

لا صفحةً داخليّةً واحدة. و`resolveApi` يُعيد فرضَ `MODULE_ALLOW` خادميّاً لأنّ `/api/v1` لا يمرّ
بالوسيط. و`ModuleController::show:447` يُفرِغ المرفقاتِ والإصداراتِ والتعليقاتِ والخطَّ الزمنيَّ
لحسابِ العميلِ حتى داخلَ الوحداتِ الثلاثِ المسموحة. **لا ملاحظةَ هنا — هذا أقوى حاجزٍ في النظام.**

---

## ٩. `PermissionInspector` — مفسِّرٌ يكذب أربعَ مرّات

الصنفُ يُعلن أنّه «يفوّض لا يكرّر». وهو صادقٌ في القرارِ الأساس (`hub_can` نفسُها)، **وكاذبٌ
في السلسلةِ التي حولَه** — وهي المنتَجُ كلُّه. أُثبتت أربعُ حالاتٍ بالتشغيل (`A03InspectorTest.php`).
تفصيلُها في A03-07 … A03-10.

---

## ١٠. الملاحظات

### A03-01 · P1 · `endpoints`: الويبُ يشترط `secOps` والـAPI/الجوالُ لا

**الدليل** — `app/Http/Controllers/Web/ModuleController.php:25-28` تُضيف حارساً لا نظيرَ له في
`app/Http/Controllers/Api/V1Controller.php:356-385` (`resolveApi`)، والجوالُ يرث `resolveApi` نفسَه
(`MobileResourceController:17`).

```php
// ModuleController::resolve
if ($module === 'endpoints') {
    abort_unless(hub_is_owner() || hub_monitor_group('secOps'), 403, '…');
}
```

**الإعادة** (`A03EndpointsTest.php`) — دورٌ مصفوفتُه `['endpoints' => ['v' => 1]]` بلا رايات:

```
INSPECTOR: allowed=true state=ALLOWED reason=مسموحٌ عبر مصفوفةِ الدور
NAV shows endpoints: false        ← الشريطُ لا يعرضها (ليست في config/hub_nav)
INSPECTOR nav visible: true       ← والمفسِّرُ يقول إنّها مرئيّة
WEB /m/endpoints => 403
API /api/v1/endpoints => 200      ← أسطولُ الأجهزةِ كاملاً
```

مسحُ الوحداتِ الـ٨٤ كلِّها (`A03MatrixTest.php`) أعطى انحرافاً واحداً: `endpoints insp=true web=403 api=200`.

**الأثر** — سجلُّ النقاطِ الطرفيّة (أجهزةٌ، حالاتُ تشفير، ارتباطٌ بموظّفين) يُقرأ كاملاً بمفتاحِ API
لدورٍ لا يبلغ الشاشةَ نفسَها. والخانةُ معروضةٌ في شاشةِ الأدوار فيظنّ المالكُ أنّه منح «عرضاً فقط»
بلا أثر — بينما منح البابَ الخلفيَّ وحدَه.

**الإصلاح** — نقلُ الحارسِ إلى موضعٍ يراه السطحان: إمّا إعلانُه في سجلِّ الوحدة
(`config/hub.php` → `'gate' => 'secOps'`) يقرؤه `resolve` و`resolveApi` معاً، وإمّا نسخُه حرفيّاً
في `resolveApi`. **الأوّلُ أولى**: نسخةٌ ثانيةٌ تنحرف ثالثةً.

**اختبارُ الانحدار** — `tests/Feature/EndpointsGateParityTest.php`: دورٌ بـ`endpoints:v` بلا `secOps`
⇒ `/m/endpoints` **و**`/api/v1/endpoints` **و**`/api/mobile/v1/endpoints` كلُّها ٤٠٣؛ ومع `secOps` ⇒ ٢٠٠.

**الثقة: مُثبَتة.**

---

### A03-02 · P1 · `custody` وحدةٌ محروسةٌ خارجَ السجلّ — والمنحُ اليدويُّ يُمحى

**الدليل**

- `app/Http/Controllers/Web/EmployeeCustodyController.php:48` — `abort_unless(hub_can($u,'custody',$op), 403)`
- `resources/views/custody-wallet/employee.blade.php:4` — `hub_can(…, 'custody', 'e')` و`'approve'`
- `app/Support/Employee360.php:57` · `app/Support/helpers.php:552` · `app/Support/InformationArchitecture.php:79`
- `routes/web.php:438` — `Route::prefix('custody-wallet')` (مركزٌ كامل)
- **و`custody` ليست مفتاحاً في `config/hub.php → modules`** (٨٥ مفتاحاً، ليس فيها).

**الإعادة** (`A03CustodyTest.php`):

```
roles form status=200
  has matrix[custody]:        false      ← لا خانةَ في الشاشة
  has matrix[files][docsec]:  false      ← A03-03
  has matrix[hr][docsec]:     true       ← الكتالوجُ يعمل حيث أُعلن
before save: custody:v=true  files:docsec=true  wallet=200     ← زُرعت في القاعدة يدوياً
after  save: matrix keys = files,hr
             custody:v=false  files:docsec=false               ← مُحيت بحفظِ اسمِ الدور
```

**الأثر** — محفظةُ العهدةِ الماليّة (`custody-wallet`، مركزٌ في الشريط) **محجوزةٌ للمالكِ وحدَه أبداً**:
لا أمينَ صندوقٍ ولا محاسبَ يبلغها. والأخطرُ أنّ `RoleController::data()` — الذي يحمل حارساً صريحاً
اسمُه «لا محوَ لمفتاحٍ مسمّى غيرِ مُعلَن» (`:361-367`) — يحرس المفاتيحَ **داخلَ الوحداتِ المعروفة فقط**،
فوحدةٌ كاملةٌ غيرُ معروفةٍ تسقط من الحلقة `foreach (array_keys(hub_modules()) as $mod)` وتُمحى صمتاً.
فمن أصلحها بالقاعدةِ يفقدها عند أوّلِ تعديلِ اسم.

**الإصلاح** — تسجيلُ `custody` وحدةً في `config/hub.php` (لها جدولُ `asset_custody`)، أو — إن أُريد
إبقاؤها بلا شاشةِ CRUD — إعلانُها مفتاحاً في كتالوجِ `config/hub_permissions.php` وجعلُ `data()`
يبني صفوفَ المصفوفةِ من **اتّحادِ** الوحداتِ والمفاتيحِ المُعلَنة لا من الوحداتِ وحدَها.

**اختبارُ الانحدار** — `RoleMatrixKeyPreservationTest`: (أ) شاشةُ `roles/create` تحوي خانةً لكلِّ مفتاحٍ
تقرؤه الشيفرةُ بـ`hub_can`؛ (ب) حفظُ دورٍ لا يُنقص مفتاحاً كان في `matrix` قبلَه. والفحصُ الآليّ
في (أ) يُبنى من نفسِ المسحِ المستعملِ هنا فلا يشيخ.

**الثقة: مُثبَتة.**

---

### A03-03 · P2 · `files:docsec` — وعدٌ مقروءٌ لا يُمنح

**الدليل** — `app/Support/helpers.php:240-245`:

```php
if ($module === 'files' && ! hub_can($user, 'files', 'docsec')) {
    $q->where(fn ($w) => $w->where('secrecy', '!=', 'سري') ->orWhereNull('secrecy')
                           ->orWhere('created_by', (string) $user->id));
}
```

والتوثيقُ فوقَه يعِد: «"سري" يُرى لحامل `docsec` على الوحدة أو رافعِ الوثيقة». لكنّ
`config/hub_permissions.php` يُعلن `docsec` على `['hr','companies','suppliers','clients']` — **بلا `files`**:

```
docsec modules: hr, companies, suppliers, clients
fine perms for files: exportNight, attach
```

**الإعادة** — أعلاه في A03-02: `has matrix[files][docsec]: false` مقابل `has matrix[hr][docsec]: true`.

**الأثر** — كلُّ وثيقةٍ مصنَّفةٍ «سري» في وحدةِ الملفّات محجوبةٌ عن **كلِّ** غيرِ مالكٍ إلى الأبد
(إلّا رافعَها) في القائمةِ والسجلِّ والبحثِ والتصديرِ والـAPI ومزامنةِ الجوال معاً. والفرعُ
`hub_can($user,'files','docsec')` **شيفرةٌ ميّتةٌ لغيرِ المالك** — والمالكُ لا يبلغه أصلاً (يعود قبلَ النطاق).

**الإصلاح** — إضافةُ `'files'` إلى `modules` في مدخلِ `docsec` من `config/hub_permissions.php`،
مع هجرةِ منحٍ للأدوارِ التي كانت تراها (نمطُ `grant_fieldsec` القائم) كي لا يُكشَف ما كان محجوباً
ولا يُحجَب ما كان مكشوفاً.

**اختبارُ الانحدار** — يُغطّيه فحصُ A03-02 (أ): كلُّ مفتاحٍ مقروءٍ له خانة. ويُضاف اختبارٌ دلاليّ:
دورٌ مُنح `files:docsec` يرى وثيقةً «سري» في `/m/files`، وبدونه ٤٠٤ على رابطِها المباشر.

**الثقة: مُثبَتة.**

---

### A03-04 · P2 · «آخر النشاطات» تُسقط عزلَ العملاء (وتسري على الجوال)

**الدليل** — `app/Support/Audit.php:37-110` (`Audit::scopedQuery`) هو القارئُ الموحَّد، وفيه **أربعُ** طبقات:
وحدةٌ مرئيّة (١) · نطاقُ المشاريع (٢) · عزلُ الشركات (٣) · **عزلُ العملاء (٤، ص ٨١-١٠٠)**.
و`app/Support/WidgetRegistry.php:210-244` يعيد كتابةَ الأولى والثانيةِ والثالثةِ **بيده** ويُسقط الرابعة.
و`app/Http/Controllers/Api/MobileWorkController.php:473` يستدعي الودجةَ نفسَها.

**الإعادة** (`A03HomeLeakTest.php` / `A03HomeCtxTest.php`) — مستخدمٌ داخليٌّ محصورٌ بعميلٍ واحد:

```
### /            status=200  leaks=true
  line 294: <div class="sub">العملاء (CRM): عميلزقنبوت</div>     ← ودجةُ «آخر النشاطات»
### /m/clients   status=200  leaks=false                          ← القائمةُ تحجبه كما يجب
```

**الأثر** — أسماءُ سجلاتِ عملاءَ آخرين (وكلُّ وحدةٍ لها عمودُ عميل) تُسرَد على **الصفحةِ الأولى**
لكلِّ مستخدمٍ محصورٍ بعميل — على الويبِ وفي تطبيقِ الجوال. ومعها فارقٌ ثانٍ: الودجةُ تحجب
القيدَ المعدومَ الشركةِ حجباً تامّاً بينما `scopedQuery` تُبقيه لصاحبه — **جوابان لسؤالٍ واحد**.

**الإصلاح** — حذفُ المنطقِ اليدويِّ من الودجةِ واستدعاءُ `Audit::scopedQuery($u)` (هو مبنيٌّ لهذا:
«WP-5.1 وحّده في `Audit::scopedQuery` ليقرأ منه كلُّ قارئٍ للجدول» — `AuditController:53`).

**اختبارُ الانحدار** — `ActivityWidgetScopeParityTest`: لكلٍّ من الشخصيّاتِ الثلاث، مجموعةُ معرّفاتِ
القيودِ التي تعيدها الودجةُ ⊆ مجموعةُ ما تعيده `Audit::scopedQuery` — **لا مقارنةَ نصٍّ على صفحة**.

**الثقة: مُثبَتة.**

---

### A03-05 · P2 · لوحةُ بنودِ العرض تطبع الإجماليَّ المحجوب

**الدليل** — `resources/views/modules/custom/quotes.blade.php:111-113` (والسطران ٩٥-٩٦ لبنودِ الجدول):

```blade
<span class="chip">الصافي…: <b class="mono">{{ number_format((float) $row->amount, 3) }}</b></span>
<span class="chip">الضريبة: <b class="mono">{{ number_format((float) $row->tax, 3) }}</b></span>
<span class="chip">الإجمالي: <b class="mono">{{ number_format((float) $row->total, 3) }} {{ $row->currency }}</b></span>
```

اللوحةُ تستشير `hub_field_mode` **مرّةً واحدة** — للتكلفةِ الداخليّةِ فقط (`:8 $showInternal`) — ولا
تستشيرها للإجماليِّ ولا للصافي ولا للضريبةِ ولا للبنود. بينما `QuoteController::doc:39-44` يحجب
`total` و`items` صراحةً، ومعه تعليقٌ يقول: **«قفلُ الحقل يسري على الورقة كما على الشاشة (v2.323):
ما حُجب في القائمة كان يُطبع هنا كاملاً»** — فالنيّةُ مُعلَنةٌ والشاشةُ هي التي تخلّفت.

**الإعادة** (`A03FieldSurfacesTest.php`) — دورٌ `field_rules = ['quotes' => ['total' => 'hide']]`، عرضٌ بإجماليّ `123,456.789`:

```
doc (طباعة HTML)     status=200 leaks_total=no
pdf (Proposal)       status=200 leaks_total=no
m/quotes (قائمة)     status=200 leaks_total=no
m/quotes/{id}        status=200 leaks_total=YES      ← اللوحةُ المخصَّصة
m/quotes/export      status=403 leaks_total=no
api/v1/quotes        status=200 leaks_total=no
```

**الأثر** — «الحقلُ المحجوبُ في شاشةٍ وظاهرٌ في أخرى». والدورُ الذي أُنشئ خصّيصاً ليبيعَ بلا رؤيةِ
الأرقامِ يرى الأرقامَ في أوّلِ صفحةٍ يفتحها.

**الإصلاح** — تمريرُ قناعٍ واحدٍ إلى اللوحاتِ المخصَّصة: `$fm = fn($k) => hub_field_mode(auth()->user(), $module, $k)`
يُحقَن من `ModuleController::show` مرّةً، وتستشيره اللوحاتُ كلُّها (النمطُ قائمٌ في
`resources/views/portal/_hr.blade.php:28` — `$fm('salary')`).

**اختبارُ الانحدار** — `CustomPanelFieldMaskTest` مُعمَّم: لكلِّ وحدةٍ لها لوحةٌ مخصَّصة، يُحجب كلُّ
حقلٍ رقميٍّ مُعلَنٍ على حِدة ثمّ تُفتح صفحةُ السجلّ ويُؤكَّد غيابُ قيمةٍ من **ستِّ خاناتٍ فأكثر**
(أو عبر `assertMaskedValueAbsent` — تفادياً لقرعةِ المعرّفات، `CLAUDE.md`).

**الثقة: مُثبَتة.**

---

### A03-06 · P2 · منسدلتا «المشروع» و«العميل» في سجلِّ التدقيق تتجاوزان عزلَ الشركات

**الدليل** — `app/Http/Controllers/Web/AuditController.php:64-92`. التعليقُ فوقَها يقول:
«مصادرُ المنسدلات منطَّقةٌ كالقائمة نفسِها: المحصورُ لا تُسمّى له شركةٌ أو عميلٌ خارج نطاقه ولو في مرشِّح».
و`companies` تُطبّقه (`->when($cids !== null, …)`)، بينما:

```php
'projects' => DB::table('projects')
    ->when(hub_scoped($u), fn ($w) => $w->whereIn('id', $u->visibleProjectIds()))   // ← لا $cids
    ->orderBy('name')->orderBy('id')->pluck('name', 'id'),
'clients' => DB::table('clients')
    ->when($kids !== null, …)                                                        // ← لا $cids
```

**الإعادة** (`A03AuditLeakTest.php`) — مستخدمٌ محصورٌ بشركةٍ ورايةُ `audit` فقط:

```
AUDIT line 312: <option value="OPAQUE-ID" >زقنبوتالسري</option>     ← مشروعُ شركةٍ أخرى
m/projects contains secret: false                                   ← القائمةُ تحجبه
audit page contains 'شركةٌ أخرى': false                              ← منسدلةُ الشركاتِ سليمة
```

وزحفُ الشخصيّاتِ أعطى `admin/audit :: project` و`admin/audit :: client` معاً.

**الأثر** — جردُ أسماءِ مشاريعِ المنشأةِ كلِّها وعملائِها لأيِّ حاملِ رايةِ `audit` محصورٍ بشركة.
اسمُ المشروعِ وحدَه إفشاءٌ (نمطُ `AuditScopeLeakTest` المعتمَدِ في المستودع).

**الإصلاح** — استبدالُ البنائين بـ`hub_ref_options_scoped('projects')` و`hub_ref_options_scoped('clients')` —
وهي الدالّةُ المصنوعةُ لهذا بعينِه: «كان هذا الموضع يعيد بناء مرشّحَي المشاريع والشركات بيده
ويُغفل العملاء» (`ModuleController:1712`). القارئُ المنطَّقُ الواحدُ موجود؛ هذا البابُ لم يستعمله.

**اختبارُ الانحدار** — `AuditFilterOptionsScopeTest`: لكلٍّ من الشخصيّاتِ الثلاث، مفاتيحُ كلِّ منسدلةٍ
(`companies`/`projects`/`clients`) ⊆ ما تعيده `hub_ref_options_scoped` للمرجعِ نفسِه.

**الثقة: مُثبَتة.**

---

### A03-07 · P2 · المفسِّر يقول «مسموح» لِما يردّه الويبُ ٤٠٣

**الدليل** — `app/Support/PermissionInspector.php:103-107` تُنهي السلسلةَ عند `hub_can` وحدَها،
و`:435-448` (`navigation`) تمرّ على كلِّ وحدةٍ بالقرارِ نفسِه.

**الإعادة** — أعلاه في A03-01: `INSPECTOR: allowed=true … WEB /m/endpoints => 403`،
و`INSPECTOR nav visible has endpoints: true` بينما `hub_nav` لا تعرضها.

**الأثر** — شاشةُ «الوصول ٣٦٠» (`AccessController`) هي الموضعُ الذي يُسأل فيه «لماذا؟». حين تقول
«مسموحٌ عبر مصفوفةِ الدور» عن بابٍ يردّ ٤٠٣، يتوقّف المالكُ عن البحثِ عند جوابٍ خاطئ. ومعكوسُها
أسوأ: يقول إنّ الوحدةَ مرئيّةٌ في التنقّلِ وهي ليست في `config/hub_nav` أصلاً — فمعاينةُ التنقّلِ
لا تحاكي التنقّل.

**الإصلاح** — (أ) بعد A03-01 يصير الحارسُ مُعلَناً في السجلّ فيقرؤه المفسِّرُ كخطوةٍ خامسةٍ
(«حارسُ الوحدةِ المُعلَن: `secOps`»). (ب) `navigation()` تُبنى من `hub_nav($user)` نفسِها لا من
`hub_modules()`، فتصير معاينةً لا تخميناً.

**اختبارُ الانحدار** — `InspectorMatchesDoorTest`: لكلِّ وحدةٍ ولكلِّ شخصيّةٍ من ثلاثِ أدوارٍ نموذجيّة،
`PermissionInspector::allows($u,$m,'v') === (GET /m/{m} === 200)`. (هو المسبارُ `A03MatrixTest` مُثبَّتاً.)

**الثقة: مُثبَتة.**

---

### A03-08 · P3 · المفسِّر يصمت عن `fieldsec` و`locked` ومجموعاتِ الكتابة

**الدليل** — `PermissionInspector::fieldNote:170-181` يقرأ `$user->role?->field_rules[$module]` **فقط**.
بينما `hub_field_mode` (`helpers.php:2468-2525`) تحجب لأربعةِ أسبابٍ مرتَّبة: `locked` ← `fieldsec` ←
قاعدةُ الدور ← مجموعاتُ الكتابةِ (`projTeam`/`projFin`/`projTech`). **ثلاثةٌ من الأربعةِ لا يراها المفسِّر.**

**الإعادة** (`A03InspectorTest.php`) — دورٌ مصفوفتُه `['hr' => ['v','a','e']]` بلا `fieldsec`:

```
(أ) hub_field_mode(hr.salary) = 'hide'
    [الوحدة] ✔ «ملفات الموظفين» (hr) — جدول employees
    [الدور] ✔ …
    [المصفوفة [hr][v]] ✔ مضبوطٌ في مصفوفةِ الدور
    [النطاق] ✔ كلُّ السجلّاتِ ضمنَ الصلاحيّة (لا قيدَ نطاقٍ إضافيّ)
                                     ← لا سطرَ واحدٌ عن الراتبِ المحجوب
(د) owner field note: ''             ← والمالكُ نفسُه محجوبٌ عن الحقولِ المقفولة
```

**الأثر** — «لماذا لا يرى الراتب؟» سؤالٌ حقيقيٌّ يُطرَح، والمفسِّرُ لا يملك جوابَه. فيُظنّ عيباً
في الشاشةِ ويُفتَح تذكرةٌ — أو يُمنح الدورُ صلاحيّةً أوسعَ عشوائيّاً حتى «يشتغل».

**الإصلاح** — `fieldNote` تحسب النمطَ الفعليَّ لكلِّ حقلٍ عبر `hub_field_mode` (المحرّكُ نفسُه، بلا نسخ)
وتُصنّف الأسبابَ: «مقفولٌ سجلّياً (n)» · «حسّاسٌ بلا `fieldsec` (n)» · «قاعدةُ الدور (n)» ·
«مجموعةُ كتابةٍ غيرُ ممنوحة (n)».

**اختبارُ الانحدار** — `InspectorFieldNoteTest`: لدورٍ بلا `hr:fieldsec`، `explain($u,'hr','v')['chain']`
يحوي خطوةَ حقولٍ تذكر `salary`.

**الثقة: مُثبَتة.**

---

### A03-09 · P3 · عمليّةٌ غيرُ معروفةٍ تُبدَّل بـ`v` صمتاً

**الدليل** — `PermissionInspector::explain:40` — `$op = array_key_exists($op, self::OPS) ? $op : 'v';`
بينما `hub_can($user, $module, $op)` يقرأ **أيَّ** مفتاحٍ من `role.matrix` — وكتالوجُ المفاتيحِ الدقيقةِ
تسعةَ عشرَ مفتاحاً حقيقيّاً (`approve`, `export`, `docsec`, `fieldsec`, `bankPost`, `command`, `enroll`, …).

**الإعادة**:

```
(ج) explain(fin,'export') => allowed=true op=v reason=مسموحٌ عبر مصفوفةِ الدور
    | hub_can(fin,export)=false
```

**الأثر** — سؤالٌ مشروعٌ («أيُصدِّر هذا الدورُ الماليّة؟») يُجاب بثقةٍ تامّةٍ وبالعكسِ تماماً،
والحقلُ `op` المُعاد يقول `v` فلا يلاحظ القارئُ التبديل.

**الإصلاح** — قبولُ مفاتيحِ `hub_fine_perms()` صراحةً في `OPS` الموسَّعة، أو رميُ
`state = 'UNKNOWN_OP'` بدل التبديلِ الصامت. **الصمتُ هو العيب، لا التبديل.**

**اختبارُ الانحدار** — `InspectorUnknownOpTest`: `explain($u,$m,'export')['allowed'] === hub_can($u,$m,'export')`
أو `state === 'UNKNOWN_OP'` — والثالثةُ (سماحٌ كاذب) تسقط.

**الثقة: مُثبَتة.**

---

### A03-10 · P3 · «لا قيدَ نطاقٍ إضافيّ» بينما `hub_scope` تحجب

**الدليل** — `PermissionInspector::scopeNote:157-167` يبني الملاحظةَ من ثلاثةِ مصادرَ فقط
(`hub_scoped` · `hub_company_ids` · `hub_client_ids`)، ويغفل القيدين الدلاليّين في `hub_scope`:
`files` السريّة (`helpers.php:240`) و`fin` لحسابِ العميل (`helpers.php:231`).

**الإعادة**:

```
(ب) files:  [النطاق] ✔ كلُّ السجلّاتِ ضمنَ الصلاحيّة (لا قيدَ نطاقٍ إضافيّ)
```
بينما `hub_scope('files')` تحجب عنه كلَّ وثيقةٍ «سري» لم يرفعها هو.

**الأثر** — ينضمّ إلى A03-03: الوثيقةُ محجوبةٌ بقاعدةٍ لا يمكن رفعُها (A03-03) ولا يُعلَن وجودُها (هنا).
فالمالكُ يرى «مسموح · لا قيدَ إضافيّ» ثمّ يسمع من الموظّفِ أنّه لا يرى الوثيقة.

**الإصلاح** — `scopeNote` تستقصي قيودَ `hub_scope` الدلاليّةَ من الموضعِ نفسِه (خريطةٌ مُعلَنة
`module ⇒ [شرط، وصف]` يقرؤها المحرّكُ والمفسِّرُ معاً).

**اختبارُ الانحدار** — `InspectorScopeNoteTest`: لدورٍ بـ`files:v` بلا `docsec`، ملاحظةُ النطاقِ تذكر «سري».

**الثقة: مُثبَتة.**

---

### A03-11 · P3 · تجميدُ التصديرِ الطارئ لا يغطّي المرفقاتِ ولا حزمةَ السجلّ

**الدليل** — `security.freeze_exports` يُفحَص في ثلاثةِ مواضعَ فقط:
`ModuleController:923` · `ReportsController:300` · `hub_doc_belt` (`helpers.php:1295`).
و`AttachmentService::download/stream` و`AttachmentController::zip` لا تسأله. (حظرُ الليلِ **يغطّيها**
عبر `WorkHours::FILE_ROUTES:25` — فالفجوةُ في مفتاحِ الطوارئِ وحدَه.)

**الإعادة** (`A03FreezeTest.php`) — مع `security.freeze_exports = 1`:

```
m/quotes/export              => 423
att.dl (تنزيلُ مرفق)          => 200      ← يخرج
att.zip (حزمةُ السجل)         => 200      ← كلُّ مرفقاتِ السجلِّ في ملفٍّ واحد
quotes.pdf (حزامُ المستند)    => 423
```

**الأثر** — المفتاحُ يُرفع «لحظةَ الاشتباه». وفي تلك اللحظةِ بالذات يبقى بابُ سحبِ الملفّاتِ
الجماعيِّ مفتوحاً. وبنصِّ `exportBelt` نفسِه: «الحارسُ الذي يُطبَّق في بابٍ ويُنسى في آخر
ليس حارساً بل قناعةٌ كاذبة».

**الإصلاح** — نداءُ `hub_doc_belt($a->module)` في `AttachmentService::serve/streamServe`
و`AttachmentController::zip` (يعطي التجميدَ والوسمَ الزمنيَّ معاً، ولا يشترط `exp` — وهو القرارُ
المعلَنُ في `hub_doc_belt` فلا يُنزَع من أحدٍ ما يملكه اليوم).

**اختبارُ الانحدار** — `ExportFreezeCoverageTest`: مع التجميد، كلُّ مسارٍ في `WorkHours::FILE_ROUTES`
يُعيد ٤٢٣ (أو ٤٠٣ لحارسٍ أسبق) — فالقائمتان تتطابقان بالبناء ولا تتباعدان.

**الثقة: مُثبَتة.**

---

### A03-12 · P3 · البحثُ يبحث دوماً في عمودِ العرضِ ولو كان محجوباً

**الدليل** — `app/Traits/Searchable.php:28-35`:

```php
$cols = collect($def['fields'] ?? [])
    ->whereIn('type', ['text','ta','url','sel','tags'])
    ->reject(fn ($f) => hub_field_mode($u, static::MODULE, (string) ($f['key'] ?? '')) === 'hide')
    ->pluck('col')
    ->push(hub_display_col(static::MODULE))          // ← بلا شرط
```

الترشيحُ صحيحٌ ومكتوبٌ سببُه («البحث كان الباب المنسي»)، ثمّ يُضاف عمودُ العرضِ بعدَه بلا فحص.
وعمودُ العرضِ **يمكن حجبُه** فعلاً: `ReportsController:324` يفحص `hub_field_mode($u,'hr','name') === 'hide'`.

**الإعادة** — دورٌ `field_rules = ['hr' => ['name' => 'hide']]`، ثمّ `/m/hr?q=<اسمٌ كامل>`:
الجدولُ لا يعرض عمودَ الاسمِ (صحيح) لكنّه **يعيد صفَّ المطابقة** — فيُستنطَق الاسمُ حرفاً حرفاً.
(ظنّيّةٌ في الأثرِ العمليّ: الحجبُ التامُّ لعمودِ العرضِ إعدادٌ نادرٌ، والصفُّ سيظهر بلا اسم.)

**الإصلاح** — `->push(...)` مشروطةً بـ`hub_field_mode(...) !== 'hide'`، مع بقاءِ `id` مسلكاً للبحثِ
بالمعرِّفِ كي لا يصير الصفُّ غيرَ قابلٍ للوصولِ إطلاقاً.

**اختبارُ الانحدار** — `SearchRespectsHiddenDisplayColumnTest`.

**الثقة: ظنّيّة** (الشيفرةُ مؤكَّدة؛ سيناريو الاستغلالِ محدودُ القيمة).

---

### A03-13 · P3 · ٢٩ من ٣٤ لوحةً مخصَّصةً لا تستشير `hub_field_mode`

**الدليل** — مسحٌ آليّ على `resources/views/modules/custom/*.blade.php`:

```
0 نداء: apps brands budgets changeorders clients contracts cycles engagements entries facilities
        hcps incidents issues leaves meetings okrs payroll phones policies posts products recruit
        servers social stockmv territories tickets visits websites          (29)
1 نداء: assets hr quotes services stations       3 نداءات: projects        (5)
```

**الأثر** — A03-05 عيّنةٌ مُثبَتةٌ من هذه الفئة. الباقي يتفاوت: أكثرُها يعرض سجلاتٍ أبناءَ
(بنودٌ، حركات) لا حقولَ السجلِّ نفسِه، لكنّ `clients.blade.php` يطبع `$row->value` و`payroll.blade.php`
يطبع صافيَ راتبِ كلِّ موظّفٍ لحاملِ `payroll:v` — والراتبُ في وحدةِ `hr` خلفَ `fieldsec`.
فهناك **جوابان لسؤالٍ واحد**: «كم راتبُ فلان؟» محجوبٌ في `hr` ومكشوفٌ في `payroll`.

**الإصلاح** — قناعُ الحقلِ يُحقَن مرّةً من `ModuleController::show` (انظر A03-05)، ومراجعةُ
`config/hub_field_sec.php` لتشمل `payroll` إن كان الفصلُ مقصوداً.

**اختبارُ الانحدار** — `CustomPanelFieldMaskTest` المُعمَّم (A03-05).

**الثقة: مُثبَتة** (القياسُ الآليّ) · **ظنّيّةٌ** في تصنيفِ أثرِ كلِّ لوحةٍ على حِدة.

---

### A03-14 · P4 · `inboxdocs` اسمُ صلاحيّةٍ ميّت

**الدليل** — أربعةُ مواضعَ تقرؤه، وكلُّها `hub_can($u,'inboxdocs',$op) || hub_can($u,'files',$op)`
(`InboxDocController:22` · `FileController:259` · `helpers.php:523` · `WidgetRegistry:260`).
و`inboxdocs` ليست مفتاحاً في السجلّ، فالطرفُ الأوّلُ `false` أبداً لغيرِ المالك.

**الأثر** — لا تسرّبَ ولا منع (الـOR يُنقذ). لكنّه اسمُ صلاحيّةٍ في الشيفرةِ لا يُمنح ولا يُسحَب:
قارئُ الشيفرةِ يظنّ أنّ صندوقَ الوثائقِ يُفصَل عن الملفّاتِ وهو غيرُ مفصول.

**الإصلاح** — إمّا تسجيلُ `inboxdocs` (لها جدولُ `inbox_documents` ومتحكّمٌ كامل)، وإمّا حذفُ
الطرفِ الميّتِ من النداءاتِ الأربعة. **والثاني أصدق** إن لم يكن الفصلُ مقصوداً.

**اختبارُ الانحدار** — يُغطّيه فحصُ A03-02 (أ) نفسُه: كلُّ مفتاحٍ مقروءٍ إمّا مُعلَنٌ وإمّا محذوف.

**الثقة: مُثبَتة.**

---

### A03-15 · P4 · `secrets` و`copySec`: تسميةٌ تَعِد بفصلٍ لا يقع

**الدليل** — `hub_copy_secrets` = `secrets || copySec` (`helpers.php:1188`)، وهي الحارسُ الوحيدُ
لكشفِ حقلِ `sec` في `ModuleController::revealSecret:473` وفي `V1Controller::shape:543`.
و`hub_secrets` (رايةُ `secrets` وحدَها) تحرس غرفةَ البياناتِ فقط (`DataRoomController:24`).

**الأثر** — التسميةُ في الشاشة: `secrets` = «كشف أسرار الخزنة» و`copySec` = «نسخ السرّ للحافظة».
والواقعُ أنّ `copySec` وحدَها **تكشف** كلَّ حقلِ `sec` في كلِّ وحدة. التعليقُ في `RoleController:27-29`
يعترف بهذا («الاسم القديم كان يَعِد بحاجزٍ لا وجود له») لكنّ الاسمَ الجديدَ ما زال يوحي بالفرقِ نفسِه.

**الإصلاح** — تسميةٌ صريحة: `secrets` ⇒ «كشفُ الأسرار **وغرفةُ البيانات**»، `copySec` ⇒ «كشفُ الأسرار
(بلا غرفةِ البيانات)» — أو دمجُهما إن لم يعد الفرقُ يستحقّ رايتين.

**الثقة: مُثبَتة** (قراءةُ شيفرة).

---

## ١١. جدولُ الوحداتِ الخمسِ والثمانين

**القراءة:** كلُّ وحدةٍ محروسةٌ بـ`hub_can(module, op)` في `ModuleController::resolve` (الويب)
و`V1Controller::resolveApi` (API والجوال) — فعمودُ «الحارس» ثابتٌ ولا يُكرَّر.
والمذكورُ هنا ما **يزيد** على ذلك: أعمدةُ التنطيقِ الفعليّة، والحقولُ الحسّاسة، والمفاتيحُ الدقيقة، والثغرة.

| الوحدة | الاسم | التنطيق | حقولٌ حسّاسة | مفاتيحُ دقيقة | الثغرة |
|---|---|---|---|---|---|
| `companies` | الشركات | مشروع·شركة | — | exportNight,docsec,attach | — |
| `projects` | المشاريع | مشروع·شركة·عميل | budget,cost | export,exportNight,fieldsec,assetAssign,projTeam,projFin,projTech,attach | — |
| `apps` | التطبيقات | مشروع·شركة | — | exportNight,attach | — |
| `code` | الكود المصدري | مشروع·شركة | — | exportNight,attach | — |
| `websites` | المواقع | مشروع·شركة | — | exportNight,attach | — |
| `domains` | الدومينات | مشروع·شركة | — | exportNight,attach | — |
| `servers` | السيرفرات | مشروع·شركة | — | exportNight,attach | — |
| `accounts` | حسابات المنصات | مشروع·شركة | — | exportNight,attach | — |
| `emails` | البريد الإلكتروني | مشروع·شركة | — | exportNight,attach | — |
| `phones` | أرقام الهواتف | مشروع·شركة·عميل | — | exportNight,attach | — |
| `carriers` | مزوّدو الاتصالات | شركة | — | exportNight,attach | — |
| `vault` | الخزنة الآمنة | مشروع·شركة | — | exportNight,attach | — |
| `tasks` | المهام | مشروع·شركة | — | exportNight,attach | — |
| `updates` | تحديثات العمل | مشروع·شركة·عميل | — | exportNight,attach | — |
| `issues` | المشاكل والمخاطر | مشروع·شركة | — | exportNight,attach | — |
| `files` | الملفات والمستندات | مشروع·شركة·عميل | — | exportNight,attach | **A03-03** `files:docsec` تُقرأ ولا تُمنح |
| `subs` | الاشتراكات والتجديدات | مشروع·شركة | — | exportNight,attach | — |
| `meetings` | الاجتماعات | مشروع·شركة·عميل | — | exportNight,attach | — |
| `decisions` | سجل القرارات | مشروع·شركة·عميل | — | exportNight,attach | — |
| `approvals` | الموافقات | مشروع·شركة | — | exportNight,attach | — |
| `social` | السوشال ميديا | مشروع·شركة | — | exportNight,attach | — |
| `posts` | منشورات ومشاهدات | مشروع·شركة | — | exportNight,attach | — |
| `fin` | المحاسبة والفواتير | مشروع·شركة·عميل | — | export,exportNight,attach | — |
| `accounts2` | دليل الحسابات | مشروع·شركة | — | exportNight,attach | — |
| `entries` | قيود اليومية | مشروع·شركة | — | exportNight,attach | — |
| `engagements` | ارتباطات العملاء | مشروع·شركة·عميل | — | exportNight,attach | — |
| `hcps` | مقدمو الرعاية الصحية | مشروع·شركة | — | exportNight,attach | — |
| `facilities` | المنشآت الصحية | مشروع·شركة·عميل | — | exportNight,attach | — |
| `territories` | المناطق الميدانية | مشروع·شركة | — | exportNight,attach | — |
| `terrassigns` | إسناد المناطق | مشروع·شركة | — | exportNight,attach | — |
| `cycles` | الدورات والحملات | مشروع·شركة | — | exportNight,attach | — |
| `visits` | الزيارات | مشروع·شركة·عميل | — | exportNight,attach | — |
| `clients` | العملاء (CRM) | مشروع·شركة·عميل | — | export,exportNight,docsec,membersManage,attach | — |
| `services` | الخدمات والمنتجات | مشروع·شركة | — | exportNight,attach | — |
| `contracts` | العقود والالتزامات | مشروع·شركة·عميل | — | export,exportNight,attach | — |
| `products` | سجل المنتجات | مشروع·شركة | — | exportNight,attach | — |
| `assets` | الأصول والعهد | مشروع·شركة·عميل | — | exportNight,custodyAssign,assetStatus,assetStation,assetInventory,attach | — |
| `stations` | المحطات | مشروع·شركة | — | exportNight,attach | — |
| `endpoints` | النقاط الطرفية | شركة | — | exportNight,command,enroll,attach | **A03-01** جوابان: ويب ٤٠٣ / API ٢٠٠ |
| `assetlog` | سجل الصيانة | مشروع·شركة | — | exportNight,attach | — |
| `stock` | المخزون | مشروع·شركة | — | exportNight,attach | — |
| `hr` | ملفات الموظفين | مشروع·شركة | salary,civilId,iban,iqama,passport,passExp | export,exportNight,docsec,fieldsec,staffAccounts,attach | — |
| `leaves` | الإجازات والطلبات | مشروع·شركة | — | exportNight,attach | — |
| `banks` | البنوك والصناديق | مشروع·شركة | iban,balance | export,exportNight,bankPost,fieldsec,attach | — |
| `okrs` | الأهداف والنتائج (OKR) | مشروع·شركة | — | exportNight,attach | — |
| `krs` | النتائج الرئيسية (KR) | مشروع·شركة | — | exportNight,attach | — |
| `kb` | قاعدة المعرفة والسياسات | مشروع·شركة | — | exportNight,attach | — |
| `autos` | الأتمتة (مؤرشفة — انظر مسارات العمل) | مشروع·شركة | — | exportNight,attach | — |
| `dbs` | قواعد البيانات | مشروع·شركة | — | exportNight,attach | — |
| `apis` | التكاملات و APIs | مشروع·شركة | — | exportNight,attach | — |
| `quotes` | عروض الأسعار | مشروع·شركة·عميل | cost | approve,export,exportNight,fieldsec,attach | **A03-05** لوحةُ السجل تطبع الإجماليَّ المحجوب |
| `changeorders` | أوامر التغيير | مشروع·شركة·عميل | — | exportNight,attach | — |
| `budgets` | الميزانيات | مشروع·شركة | — | exportNight,attach | — |
| `costc` | مراكز التكلفة | مشروع·شركة | — | exportNight,attach | — |
| `recur` | المصروفات المتكررة | مشروع·شركة | — | exportNight,attach | — |
| `stockmv` | حركات المخزون | مشروع·شركة | — | exportNight,attach | — |
| `attend` | الحضور والانصراف | مشروع·شركة·عميل | — | export,exportNight,attach | — |
| `payroll` | مسيّرات الرواتب | مشروع·شركة | — | approve,export,exportNight,attach | **A03-13** صافي الراتبِ بلا `fieldsec` (ونظيرُه محجوبٌ في `hr`) |
| `recruit` | التوظيف | مشروع·شركة | — | exportNight,attach | — |
| `hrlog` | سجلات الموظفين | مشروع·شركة | — | exportNight,attach | — |
| `rules` | قواعد التنبيه | مشروع·شركة | — | exportNight,attach | — |
| `feats` | خطة العمل والمزايا | مشروع·شركة | — | exportNight,attach | — |
| `designs` | التصاميم | مشروع·شركة | — | exportNight,attach | — |
| `tickets` | تذاكر العملاء | مشروع·شركة·عميل | — | exportNight,attach | — |
| `users` | المستخدمون | شركة | — | exportNight,attach | خارجَ المصفوفة عمداً — رايةُ `users` وحدَها |
| `suppliers` | الموردون | مشروع·شركة | — | export,exportNight,docsec,attach | — |
| `purchases` | المشتريات | مشروع·شركة·عميل | — | approve,export,exportNight,attach | — |
| `changes` | التغييرات التقنية | مشروع·شركة | — | exportNight,attach | — |
| `skills` | المهارات والشهادات | شركة | — | exportNight,attach | — |
| `policies` | السياسات والإقرارات | مشروع·شركة | — | exportNight,attach | — |
| `obligations` | التزامات العقود | مشروع·شركة | — | exportNight,attach | — |
| `compliance` | سجل الامتثال | شركة | — | exportNight,attach | — |
| `ideas` | مركز الابتكار | مشروع | — | exportNight,attach | بلا عمودِ شركة — العزلُ الصارمُ لا يبلغها |
| `policyacks` | إقرارات السياسات | شركة | — | exportNight,attach | — |
| `incidents` | إدارة الحوادث التقنية | مشروع | — | exportNight,attach | بلا عمودِ شركة — العزلُ الصارمُ لا يبلغها |
| `deploys` | سجل النشر والإصدارات | مشروع | — | exportNight,attach | بلا عمودِ شركة — العزلُ الصارمُ لا يبلغها |
| `restores` | اختبار استعادة النسخ | **لا عمود** | — | exportNight,attach | **بلا عمودِ تنطيق** — المصفوفةُ وحدَها |
| `requests` | الطلبات الواردة | مشروع | — | exportNight,attach | بلا عمودِ شركة — العزلُ الصارمُ لا يبلغها |
| `deps` | سجل الاعتماديات | مشروع | — | exportNight,attach | بلا عمودِ شركة — العزلُ الصارمُ لا يبلغها |
| `competitors` | المنافسون | **لا عمود** | — | exportNight,attach | **بلا عمودِ تنطيق** — المصفوفةُ وحدَها |
| `brands` | العلامات التجارية | مشروع·شركة | — | exportNight,attach | — |
| `media` | مركز الإعلام | شركة | — | exportNight,attach | — |
| `ip` | الملكية الفكرية | مشروع·شركة | — | exportNight,attach | — |
| `events` | الأحداث والمعارض | شركة | — | exportNight,attach | — |
| `plans` | الباقات والتسعير | **لا عمود** | — | exportNight,attach | **بلا عمودِ تنطيق** — المصفوفةُ وحدَها |

**وصفٌ خارجَ الجدول — `custody`:** محفظةُ العهدةِ الماليّة محروسةٌ بـ`hub_can($u,'custody', v/a/e/approve)`
في `EmployeeCustodyController:48` و`custody-wallet/*.blade.php`، ولها مركزٌ في الشريط
(`helpers.php:552`) ومسارٌ كامل (`routes/web.php:438`) — **وليست في السجلّ**، فلا صفَّ لها هنا
ولا خانةَ لها في شاشةِ الأدوار. انظر **A03-02**.

---

## ١٢. المسابر (إعادةُ التوليد)

جميعُ المسابرِ مؤقّتةٌ وخارجَ `tests/` (في `scratchpad/probe/`)، وتُشغَّل بـ:
`./vendor/bin/phpunit -c phpunit.xml <ملف>`

| المسبار | يُثبت |
|---|---|
| `p1.php` (tinker) | ٨٥ وحدة · ٨٤ في الشاشة · الناقصةُ `users` |
| `p3.php` (tinker) | جدولُ §١١ (أعمدةُ التنطيقِ والحقولِ الحسّاسةِ والمفاتيحِ الدقيقة) |
| `A03MatrixTest` | انحرافُ الويبِ عن الـAPI عبر ٨٤ وحدة ⇒ `endpoints` وحدَها |
| `A03SingleTest` | منحُ وحدةٍ واحدةٍ يفتح بابَها ⇒ `endpoints` وحدَها تفشل |
| `A03EndpointsTest` | A03-01 · A03-07 |
| `A03CustodyTest` | A03-02 · A03-03 (شاشةُ الأدوار + المحوُ عند الحفظ) |
| `A03ScopeCrawlTest` / `A03Crawl2Test` | زحفُ ١٧٩ صفحةً بثلاثِ شخصيّاتٍ معزولة |
| `A03HomeLeakTest` / `A03HomeCtxTest` | A03-04 (الودجةُ والسطرُ الحرفيّ) |
| `A03AuditLeakTest` | A03-06 (المنسدلة) |
| `A03FieldSurfacesTest` / `A03ShowLeakTest` | A03-05 (ستُّ سطوحٍ، واحدةٌ تسرّب) |
| `A03InspectorTest` | A03-08 · A03-09 · A03-10 |
| `A03FreezeTest` | A03-11 |
| `A03PortalTest` | §٨ (قائمةُ ما يبلغه حسابُ العميلِ كاملةً) |
| `A03WriteTest` / `A03AdminWriteTest` | §٢ (لا فعلَ حسّاسٍ بلا حارس) |
| `A03ApiScopeTest` | §٣ (لا تسرّبَ عبر API · الكتابةُ تُقصَر قسراً) |

## ١٣. ما لم يُفحَص (حدودُ هذه الجولة)

- **المساراتُ ذاتُ الوسائط** (`{id}`) لم تُزحَف آليّاً إلّا عيّناتٍ منتقاة؛ الزحفُ الآليُّ اقتصر
  على ١٧٩ مساراً بلا وسائط. الوصولُ المباشرُ بالرابطِ فُحص عيّنةً (٤٠٤ في الويبِ والـAPI).
- **مسارُ الكتابةِ للوحداتِ الـ٨٤** (`a`/`e`/`d`) لم يُزحَف وحدةً وحدة — فُحص `tasks` عبر API نموذجاً.
- **`hub_needs_approval` وطابورُ الموافقات** كطبقةِ تخويلٍ ثانيةٍ لم تُفحَص هنا (وكيلٌ آخر).
- **مزامنةُ الجوال** (`MobileSyncController`) فُحصت بالوراثةِ (`resolveApi`) لا بالتشغيل.
- **الروابطُ العامّة** (`ShareLink` / `sign/{token}`) خارجَ سكّةِ الصلاحيّات — لم تُفحَص.
