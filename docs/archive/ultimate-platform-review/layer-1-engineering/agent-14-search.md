# الوكيل ١٤ — البحثُ ولوحةُ الأوامر والاكتشاف (طبقة ١ · الجولة أ)

> المستودع: `/home/user/lynomia-hub` · الرأس المفحوص: `c06a945` (النسخة `2.540.1`،
> وهي الدفعةُ التالية مباشرةً لـ`1d90626`؛ الفارقُ وثائقُ المراجعة وحدها).
> النطاق: `app/Support/InformationArchitecture.php` · `config/hub_ia.php` ·
> `config/hub_nav.php` · `config/hub_workspaces.php` ·
> `app/Http/Controllers/Web/SearchController.php` · `SystemMapController.php` ·
> `MessageSearchController.php` · `ModuleController::refOptions` ·
> `app/Traits/Searchable.php` · `app/Support/helpers.php`
> (`hub_ref_options` · `hub_ref_options_scoped` · `hub_pins` · `hub_pin_targets`) ·
> `app/Support/Workspaces.php` · `resources/views/partials/sidebar.blade.php` ·
> `searchmini.blade.php` · `search/index.blade.php` · `system-map.blade.php` ·
> `partials/_field.blade.php` · `partials/participant_picker.blade.php` ·
> وكلُّ منتقٍ في `resources/views/**`.
>
> **قراءةٌ فقط.** لم يُعدَّل من المستودع إلّا هذا الملفّ. كلُّ مسابر الاستكشاف
> (`ZzProbeA14*Test` — ١١ مسباراً) كُتبت، شُغّلت، ثمّ **أُخرجت** من `tests/` إلى
> `…/scratchpad/probe/`؛ `git status` نظيف. التشغيلُ على `phpunit.xml` (sqlite)،
> ومسبارُ `A14-08` شُغّل على المحرّكين معاً.

---

## ٠. الخلاصة التنفيذيّة

**البحثُ عن السجلات محروسٌ حراسةً ممتازة. الاكتشافُ حولَه مثقوبٌ في ثلاثة مواضع،
وأخطرُها ليس البحثَ بل المنتقيات.**

سكّةُ نتائجِ السجلات (`SearchController::results/index` → `searchableModules` +
`hub_scope` + `hub_client_scope` + `MobileContext`) صمدت أمام كلِّ ما طرقتُه:
لا عنوانٌ ولا معرّفٌ ولا **عدُّ نتائجٍ** يخرج من نطاق القارئ، ووجهاتُ لوحة الأوامر
تطابق أبوابَها في خمسِ شخصيّاتٍ × ٩٠ رابطاً، و«الأخيرة» والمثبّتاتُ تُعيدان التحقّقَ
لحظةَ العرض فتسقطان فوراً عند سحب الصلاحيّة. هذا كلُّه مُثبَتٌ بالتشغيل في الفصل ٥.

لكنّ ثلاثةَ ثقوبٍ بقيت، كلُّها **خارجَ** ما تقيسه الحزمةُ اليوم:

1. **المنتقياتُ (`ref`) لا تسأل عن الرؤية أصلاً** — `hub_ref_options_scoped` يطبّق
   عزلَ الشركة/العميل/المشروع، ولا يسأل `hub_can($u, $ref, 'v')` ولا مرّة. فمن يملك
   وحدةً واحدةً يعدّد **أسماءَ موظّفي المنشأة وعناوينَ أسرارِ الخزنة** من نموذجِ
   إنشاءٍ عاديّ. **٣٠١ حقلَ مرجعٍ** على هذه الحال، وثلاثةٌ فقط محروسةٌ بـ`edge`.
2. **البحثُ يطبع عمودَ العرضِ المحجوبَ عن الدور** — `/m/hr` يحجب الاسمَ كما أُمر،
   ولوحةُ الأوامر و`/search` و«الأخيرة» تطبعه نصّاً. الحقلُ محجوبٌ في ستّ شاشاتٍ
   ومفتوحٌ في ثلاثٍ.
3. **خريطةُ النظام بلا رابطٍ واحد** — `foreach ($x ?? [] as &$d)` يكتب في نسخةٍ
   مؤقّتة، فـ`url` لا تُضبط أبداً: ١٧٧ وجهةً تُرسَم شاراتٍ ميّتةً تقول «تُفتح من
   سجلّها»، والحارسُ الذي يُفترض أن يمنع هذا يطرق ٨٣ رابطاً **كلُّها من الشريط
   والترويسة**، ولا واحدٌ منها من الخريطة.

| # | الخطورة | العنوان | الموضع |
|---|---|---|---|
| `A14-01` | **عالية** | منتقياتُ المراجع تعدّد سجلاتِ وحداتٍ لا يملك القارئُ عرضَها | `helpers.php:986` · `ModuleController.php:1718` |
| `A14-02` | **عالية** | خريطةُ النظام بلا رابطٍ حيٍّ واحد — كتابةٌ في نسخةٍ مؤقّتة | `SystemMapController.php:27` |
| `A14-03` | **عالية** | البحثُ و«الأخيرة» يطبعان عمودَ العرض/الحالةِ المحجوبَ عن الدور | `SearchController.php:59,98,138` |
| `A14-04` | متوسطة | `graph.explore` وجهةٌ ميّتة: تُعرَض للجميع ويردُّ بابُها ٤٠٤ للجميع | `hub_ia.php:160` · `RelationshipExplorerController.php:56` |
| `A14-05` | متوسطة | منتقي الإشارة (`@`) يعرض **حسابات بوّابة العملاء** في كلِّ صفحة سجلّ | `CommentController.php:343` |
| `A14-06` | منخفضة | خمسةُ منتقياتٍ بشريّةٍ خارجَ السكّة المنطَّقة (`hub_ref_options` الخام) | `custody_card.blade.php:61,294` وثلاثةٌ غيرُها |
| `A14-07` | منخفضة | `inboxdocs` تبثّ كتالوجَ الوحداتِ كاملاً بلا `hub_can` | `InboxDocController.php:55` |
| `A14-08` | منخفضة | هروبُ أحرفِ البدل في بحثِ الرسائل يخالف اصطلاحَ المستودع ويفترق بين المحرّكين | `MessageSearchController.php:35` |
| `A14-09` | منخفضة | فتاتُ الخبز لا يُفضي إلى وجهة — `url` دائماً `null` | `InformationArchitecture.php:588` |
| `A14-10` | منخفضة | نهايةُ البحثِ المسدودة لا تسلّم إلى بحثِ الرسائل | `searchmini.blade.php:22` |

**الأرقام المطلوبة**

- **منتقياتٌ غيرُ منطَّقة:** `٣٠١` حقلَ مرجعٍ بلا بوّابةِ رؤية (`A14-01`) + `٥`
  مواضعَ تستدعي `hub_ref_options` الخامَّ بدل `_scoped` (`A14-06`) + `٢` دليلَي
  مستخدمين يضمّان حساباتِ العملاء (`A14-05`). المجموعُ العمليّ: **٣٠٨**.
- **وحداتٌ غيرُ قابلةٍ للبحث فيها بيانات:** `٠`. الوحداتُ الـ٨٥ كلُّها لها
  `scopeSearch`، و`users` وحدَها مستثناةٌ عمداً (لها صفحتُها الإداريّة)،
  و`endpoints` مشدودةٌ لمالك/`secOps` عمداً وموثَّقاً.
- **وجهاتٌ معروضةٌ بلا باب:** `١` (`graph.explore` — ٤٠٤ للجميع بمن فيهم المالك).
  ويُضاف إليها **١٧٧** وجهةً في خريطة النظام لا بابَ لها لأنّها لا تُرسَم رابطاً
  أصلاً (`A14-02` — عطلُ عرضٍ لا عطلُ صلاحية).

---

## ١. البحثُ الشامل — ما يُغطّى وما لا يُغطّى

### ١.١ التغطية

`SearchController::searchableModules()` (`:281`) يمرّ على `hub_modules()` كلِّها
(٨٥ وحدة) ويُسقط:

- `users` — عمداً، بابُها `/users` الإداريّ؛
- `endpoints` — عمداً: «رقابةٌ لا شاشةَ عموم»، مشدودةٌ لـ`hub_is_owner ||
  hub_monitor_group('secOps')` فوقَ المصفوفة (`:288`)؛
- وحدةً بلا صنفِ موديل — **لا وجودَ لها**: فحصتُ الـ٨٥ فوجدتُ صنفاً موجوداً
  و`scopeSearch` حاضراً في **٨٤** منها (الاستثناءُ `User` وهو المستثنى عمداً).

> **مسبار:** `/tmp/.../scratchpad/probe/searchable.php` —
> `modules=85 withSearch=84 · no model class 0 · no scopeSearch 1 (users)`.

فوقَ الوحدات، البحثُ يبلغ **كياناتٍ ليست وحدةَ سجلّ** عبر `workOs()` (`:238`):
القنواتُ بعضويّتها، وكشوفُ العهدة بنطاقها — وكلاهما يحمل فلترَ شاشته الخادميَّ
حرفاً بحرف. و`operational()` (`:186`) يبلغ معرّفَ الطلبِ وبصمةَ الخطأ وعنوانَ IP
ومفتاحَ الإعداد وبريدَ الحساب، كلٌّ بحارسِ شاشته.

### ١.٢ ما لا يُبحث — اكتشافٌ ضائعٌ مقصودٌ ومُحتَسب

| السطح | يُبحث؟ | أين يُبحث بدلاً منه |
|---|---|---|
| نصُّ الرسائل (خلاصة/قناة/DM) | لا | `/search/messages` (`MessageSearchController`) — وجهةٌ مُعلَنةٌ في `hub_ia.php:104` ومرئيّةٌ في مركز التواصل |
| أسماءُ المرفقات والوثائق | **لا** | لا شيء — يُفتَّش يدويّاً داخلَ صفحةِ السجلّ |
| التعليقاتُ على السجلات | لا | `/search/messages` يقرأ `comments` لكن بوحدة `feed`/`channel` فقط، لا تعليقاتِ السجلات |
| سجلُّ التدقيق | لا | `/audit` بمرشِّحاته |

الصفُّ الثاني هو الفجوةُ الحقيقيّة: **لا سبيلَ للعثور على مرفقٍ باسمه** في أيِّ
سطحٍ من أسطح المنتج. لم أرفعه ملاحظةً مستقلّةً لأنّه قرارُ نطاقٍ لا عطل، لكنّه
يستحقّ قراراً مُعلَناً كقرارِ `restores` في `NavCoverageTest`.

### ١.٣ الوحداتُ الأربعُ خارجَ الشريط — كلُّها بقرارٍ مكتوب ✅

مسبارٌ جمع كلَّ `/m/{key}` ترسمه أسطحُ التنقّل (الرئيسية · الثماني مساحات ·
`/system-map` · `/personalize` · الشريط الكلاسيكيّ) للمالك:

```
[modules linked from nav surfaces] 81 / 85
[BURIED] endpoints, autos, users, restores
```

والأربعةُ كلُّها مذكورةٌ في جدول `NavCoverageTest::DELIBERATE` بسببها المكتوب
(`tests/Feature/NavCoverageTest.php:28-39`)، والاختبارُ يُسقط الحزمةَ إن غابت
وحدةٌ بلا قرار. **هذا نموذجُ الحارسِ الذي ينبغي أن تُحتذى به بقيّةُ الأسطح.**

---

## ٢. التسريبُ عبر البحث — لم أجد ثقباً واحداً ✅

طرقتُ السؤالَ الثاني (هل يجد ما لا يحقّ له؟) من خمس جهات:

| الهجوم | النتيجة |
|---|---|
| البحثُ بكلمةٍ في سجلٍّ خارج نطاق الشركة | لا عنوان، لا معرّف، **ولا عدُّ نتائج** — `query()` (`:313`) يبني `hub_client_scope(hub_scope(...))` قبل أيِّ عدّ، و`index()` (`:132`) يحسب `count` على الاستعلام المنطَّق نفسِه |
| قراءةُ حقلٍ محجوبٍ بتجربةِ مطابقات | `Searchable::scopeSearch` (`app/Traits/Searchable.php:28-34`) يُسقط كلَّ حقلٍ `hub_field_mode === 'hide'`، ويُسقط الحقولَ الحسّاسةَ لمن لا يحمل `fieldsec` — **عدا عمودِ العرض** (انظر `A14-03`) |
| حسابُ بوّابةِ عميلٍ يطرق `/search` | `PortalGuard` قائمةٌ بيضاءُ لا تضمّه → ٤٠٤، و`workOs()` يعيد `[]` دفاعاً في العمق (`:243`) |
| أحرفُ البدل (`%%`) لمسحِ ٨٠ جدولاً | مهرَّبةٌ بـ`ESCAPE '!'` في السكّتين (`Searchable:20` و`workOs:247`) |
| أسطولُ النقاط الطرفية بالكتابة الحرّة | مشدودٌ لمالك/`secOps` في الفهرس نفسِه (`:288`) |

الحزمةُ تغطّي هذا الصنفَ تغطيةً جيّدة: `SearchDmLeakTest` · `WorkOsGlobalSearchTest`
· `OperationalSearchTest` (وفيه ميزانيّةُ استعلاماتٍ لا مجرّدَ صحّة).

> **تقاطعٌ:** الوكيل ١٢ يسجّل تسريباً في `/search/messages` (`A12-01`) — وهو سطحٌ
> **آخرُ** لا يمرّ بسكّةِ `SearchController` هذه. لم أُعِد فحصَه.

---

## ٣. لوحةُ الأوامر — «شرطُ العرض = شرطُ الباب» ✅ (بثقبٍ واحد)

### ٣.١ المسحُ الشامل

المسبارُ يطرق **كلَّ** رابطٍ يرسمه سطحُ البحث (`/search?q=…` بعشرين حرفاً شائعاً
+ `/search/mini` على التركيز) لخمسِ شخصيّات:

```
=== owner ===                        knocked=90  bad=0
=== employee(all modules, no flags)  knocked=54  bad=0
=== viewer(view-only)                knocked=51  bad=0
=== narrow(tasks only)               knocked=19  bad=0
=== isolated+flags                   knocked=38  bad=0
```

(الـ«bad» الوحيدةُ في كلِّ تشغيلٍ كانت `/css/app.css` و`/css/fonts.css` — ملفّاتٌ
ساكنةٌ لا يخدمها مُشغِّلُ الاختبارات.)

ومسبارٌ ثانٍ يطرق **الكتالوجَ كلَّه** (`catalogDestinations` — ما يعرضه التركيزُ
بلا كتابة وما تقرؤه الخريطة) لستِّ شخصيّات:

```
=== CATALOG owner ===          knocked=166 contextual=5 bad=1   (graph.explore)
=== CATALOG employee ===       knocked=130 contextual=4 bad=1
=== CATALOG viewer ===         knocked=130 contextual=3 bad=1
=== CATALOG narrow-tasks ===   knocked=24  contextual=0 bad=1
=== CATALOG isolated+flags === knocked=46  contextual=1 bad=1
=== CATALOG monitor-hr ===     knocked=46  contextual=1 bad=1
```

**صفرُ وجهةٍ تُعرَض ويردُّها بابُها ٤٠٣** في ٧٩٤ طرقةً (٢٥٢ من سطح البحث + ٥٤٢ من
الكتالوج). القاعدةُ مطّردة.

### ٣.٢ إجراءاتُ «＋ جديد»

```
=== ACTIONS owner ===          offered=81 bad=0
=== ACTIONS employee ===       offered=81 bad=0
=== ACTIONS add-everywhere === offered=81 bad=0
```

`quickActions` (`:110`) يشترط `hub_can($u, $key, 'a')` و`m.create` يشترطها نفسَها.
مطّردةٌ كذلك.

> **حدٌّ مكتوب:** `quickActions` يمرّ على `hub_nav($u)` لا على `hub_modules()`، فالوحداتُ
> الأربعُ خارجَ الشريط (`restores` مثلاً) لا «＋ جديد» لها في اللوحة ولو ملك القارئُ
> إضافتَها. أثرٌ صغيرٌ ومتّسقٌ مع قرار `NavCoverageTest`.

### ٣.٣ الثقبُ الوحيد — انظر `A14-04`.

---

## ٤. جدولُ المنتقيات والإكمالِ التلقائيّ — كلُّ سطحٍ في الواجهة

> **لا إكمالَ تلقائيَّ بطلبِ خادمٍ في المنتج كلِّه** عدا لوحةِ الأوامر نفسِها
> (`hx-get search.mini`): `datalist` واحدةٌ فقط (`odoo/project.blade.php`)، وكلُّ
> المنتقيات `<select>` مملوءةٌ خادميّاً أو قائمةُ مربّعاتِ اختيار. هذا **قرارٌ جيّد
> أمنيّاً** — سطحُ الهجوم قائمةٌ واحدةٌ مرئيّةٌ لا نقطةُ استعلامٍ صامتة.

الرمز: ✅ منطَّقٌ صحيحاً · ⚠️ منطَّقٌ جزئيّاً (عزلٌ بلا بوّابةِ رؤية) · ❌ غيرُ منطَّق.

| # | المنتقي | الملفّ:السطر | المصدر | منطَّق؟ | الدليل |
|---|---|---|---|---|---|
| 1 | حقلُ مرجعٍ مفرد (`ref`) | `partials/_field.blade.php:59` | `ModuleController::refOptions:1718` → `hub_ref_options_scoped` | ⚠️ | عزلُ شركة/عميل/مشروع نعم؛ `hub_can(ref,'v')` **لا** — `A14-01` |
| 2 | حقلُ مرجعٍ متعدّد (رقائق) | `partials/_field.blade.php:45` | نفسُه | ⚠️ | نفسُه |
| 3 | حافّةُ بنية (`edge`) | `ModuleController.php:1709-1713` | `hub_can(ref,'v')` وإلّا «—» | ✅ | **٣ حقولٍ فقط** من ٣٠٦ (`servers.stationId/assetId/hrId`) |
| 4 | حقلٌ مخصَّصٌ من نوع مرجع | `modules/_form.blade.php:33` | `hub_ref_options_scoped` | ⚠️ | كالصفّ ١ |
| 5 | تسليمُ العهدة — المستلم | `partials/custody_card.blade.php:61` | `hub_ref_options('users', …)` | ❌ | `A14-06` — الخامُّ لا `_scoped` |
| 6 | تصريحُ الخروج — المنقول إليه | `partials/custody_card.blade.php:294` | `hub_ref_options('users')` | ❌ | `A14-06` |
| 7 | مشروعُ العهدة | `partials/custody_card.blade.php` | `hub_ref_options_scoped('projects')` | ⚠️ | كالصفّ ١ |
| 8 | محطّةُ العهدة | `partials/custody_card.blade.php` | `hub_ref_options_scoped('stations')` | ⚠️ | كالصفّ ١ |
| 9 | شاغلُ المحطّة | `modules/custom/stations.blade.php:119` | `hub_ref_options('users', …)` | ❌ | `A14-06` |
| 10 | مسحُ الهويّة — المستخدم | `identity/_scan.blade.php:6` | `hub_ref_options('users')` | ❌ | `A14-06` — والسطرُ **التالي** له يستعمل `_scoped` للشركات |
| 11 | مسحُ الهويّة — الشركة | `identity/_scan.blade.php:7` | `hub_ref_options_scoped('companies')` | ✅ | |
| 12 | ضبطُ وصولِ المرفق — أشخاص | `partials/attachments.blade.php:19` | `User::where('status','نشط')->limit(500)` | ❌ | بلا `hub_scope`؛ خلفَ `hub_is_owner \|\| hub_can($aModule,'e')` |
| 13 | ضبطُ وصولِ المرفق — أدوار | `partials/attachments.blade.php:18` | `Role::where('is_owner',false)` | ✅ | الأدوارُ كيانٌ عامّ |
| 14 | إشارةُ `@` في التعليقات | `partials/comments.blade.php:28` | `CommentController::userNames():343` | ❌ | يضمّ **حساباتِ العملاء** — `A14-05` |
| 15 | إشارةُ `@` في الخلاصة | `feed/index.blade.php` | نفسُه | ❌ | `A14-05` |
| 16 | إشارةُ `@` في القناة | `conversations/show.blade.php` | نفسُه | ❌ | `A14-05` |
| 17 | بدءُ محادثةٍ مباشرة | `dm/_compose.blade.php` · `dm/inbox.blade.php` | `DmController::startableUsers:146` → `dmReachable` | ✅ | داخليّون + تقاطعُ شركة |
| 18 | منتقي المشاركين (مجموعات) | `partials/participant_picker.blade.php` | `DmController::reachableColleagues:163` | ✅ | `!hub_is_client` + `dmReachable` |
| 19 | هدفُ إشعارِ مسارِ العمل | `flows/_form.blade.php` | `FlowController::notifyTargets:83` | ⚠️ | يضمّ حساباتِ العملاء لكن **موسومةً** «حساب عميل (بوّابة خارجيّة)» |
| 20 | مُسنَدُ إليه الخطأ | `ops/error_show.blade.php` | `ErrorCenterController:154` — كلُّ نشط | ⚠️ | خلفَ بوّابةِ مركزِ الأخطاء |
| 21 | مرشِّحُ التدقيق — مستخدم | `audit/index.blade.php` | `AuditController:76` — كلُّ المستخدمين | ⚠️ | خلفَ `hub_flag('audit')`؛ الصفوفُ منطَّقةٌ والقائمةُ لا |
| 22 | مرشِّحُ التدقيق — شركة/مشروع/عميل | `audit/index.blade.php` | `AuditController:80-91` | ✅ | `whereIn($cids/$kids/visibleProjectIds)` |
| 23 | مرشِّحُ الجلسات — مستخدم | `security/sessions.blade.php` | `SecurityController:661` | ✅ | `when($vis …)` |
| 24 | تشخيصُ الوصول — مستخدم | `access/index.blade.php` | `AccessController:30` | ✅ | الصفحةُ كلُّها `hub_is_owner` |
| 25 | كشفُ العهدة — بنوك/زملاء/سلف | `custody-wallet/employee.blade.php` | `EmployeeCustodyController:120-124` | ✅ | `hub_scope(banks)` · `hub_scope(hr)` · سلفُ الموظفِ نفسِه |
| 26 | فئةُ العهدة — الحائزون | `custody/category.blade.php` | `CustodyController:101` `hub_ref_labels` | ✅ | تسمياتٌ لمعرّفاتٍ ظاهرةٍ سلفاً، لا تعداد |
| 27 | بندُ عرضِ السعر — خدمة | `modules/custom/quotes.blade.php:11` | `hub_scope(Service::query(),'services')` | ✅ | |
| 28 | سطرُ القيد — حسابٌ دفتريّ | `modules/custom/entries.blade.php:9` | `hub_scope(LedgerAccount,'accounts2')` | ✅ | |
| 29 | تسويةُ مستندٍ ماليّ — بنك | `partials/fin_actions.blade.php:5` | `hub_can('banks','v') ? … : collect()` | ✅ | **النموذجُ الصحيح** — بوّابةُ رؤيةٍ صريحة |
| 30 | مركزُ الكود — تطبيق/مشروع | `code/center.blade.php` | `CodeCenterController:35,39` — `hub_can(…,'v') ? …` | ✅ | النموذجُ الصحيح |
| 31 | عدسةُ المشروع | `partials/lens.blade.php:4` | `hub_lens_projects` → `hub_scope` | ✅ | |
| 32 | حضورُ اليوم — مشروع/عميل | `partials/widgets/checkin.blade.php` | `Workday.php:529` `hub_ref_options_scoped` | ⚠️ | كالصفّ ١ |
| 33 | التوقيع — عقد | `esign/index.blade.php` | `EsignController:110` `hub_scope('contracts')` | ✅ | |
| 34 | التوقيع — قالب | `esign/index.blade.php` | `SignTemplate::orderBy` | ✅ | قوالبُ عامّةٌ بلا بيانات |
| 35 | صندوقُ الوارد — شركة | `inboxdocs.blade.php` | `InboxDocController:58` — `hub_can + hub_company_ids` | ✅ | |
| 36 | صندوقُ الوارد — وحدةُ التصنيف | `inboxdocs.blade.php` | `InboxDocController:55` — `config('hub.modules')` خامّاً | ❌ | `A14-07` |
| 37 | مبدّلُ الشركةِ العلويّ | `layouts/app.blade.php:59` | كاش + `->only(hub_company_ids())` | ✅ | |
| 38 | مبدّلُ مساحةِ العميل | `layouts/app.blade.php:90` | ثلاثةُ مرشّحات + الشركةُ النشطة | ✅ | |
| 39 | `MDM`/إصداراتُ الوكيل — شركة | `endpoints/mdm.blade.php` · `releases.blade.php` | `Company::limit(500)` خامّاً | ✅ | الشاشتان `hub_is_owner` (`EndpointMdmController:28`) |
| 40 | النقاطُ الطرفية — أصل | `endpoints/index.blade.php` | `hub_scope(Asset::query(),'assets')` | ✅ | |
| 41 | تذكرةُ بوّابةِ العميل — عميل/مشروع | `portal/client/ticket-new.blade.php` | `ClientPortalData::clientsOf/projectRows($ids)` | ✅ | `$ids` من عضويّةِ الحساب |
| 42 | الموظّفون — دور/حسابٌ بلا ملف | `staff.blade.php:75` | `Staff::mayOpenAccounts` + عزلُ شركة | ✅ | |
| 43 | بطاقةُ حسابِ الموظف — دور | `partials/staff_account_card.blade.php` | `hub_assignable_roles()` | ✅ | |
| 44 | التوظيف — دور | `modules/custom/recruit.blade.php` | `Role::when(!hub_is_owner …)` | ✅ | |
| 45 | مؤشّراتُ الأداء — أشخاص | `kpis/index.blade.php` | `KpiController:89` `hub_can('hr','v') ? …` | ✅ | النموذجُ الصحيح |
| 46 | أودو — اتّصال (`datalist`) | `odoo/project.blade.php` | `Odoo::connections()` | ✅ | الشاشةُ خلفَ `hub_can('projects','e')` |
| 47 | منتجاتٌ (تسعير) | `modules/custom/products.blade.php` | `hub_ref_options_scoped('products')` | ⚠️ | كالصفّ ١ |
| 48 | أعضاءُ العميل — دور | `modules/custom/clients.blade.php` | `$cmRoles` ثابتة | ✅ | |

**الخلاصة:** ٢٥ منتقياً منطَّقاً تنطيقاً صحيحاً، ٨ منقوصةٌ جزئيّاً، ٦ خارجَ السكّة —
والصفوفُ ١ و٢ و٤ و٧ و٨ و٣٢ و٤٧ تختصر **٣٠١ حقلَ مرجع**.

---

## ٥. الملاحظات

### `A14-01` — **عالية** — منتقياتُ المراجع تعدّد سجلاتِ وحداتٍ لا يملك القارئُ عرضَها

**الموضع:** `app/Support/helpers.php:986-1014` (`hub_ref_options_scoped`)
· `app/Http/Controllers/Web/ModuleController.php:1700-1721` (`refOptions`).

**الدليل** (مسبار `ZzProbeA14Test`، sqlite):

```php
$u = $this->only(['phones', 'apps']);          // مصفوفتُه وحدتان لا غير
// hub_can($u,'hr','v')    = no
// hub_can($u,'vault','v') = no
GET /m/phones/create  →  [hr in phones/create]    LEAK   ← «سِرّيّةُ الرواتب» (اسمُ موظّف)
GET /m/apps/create    →  [vault in apps/create]   LEAK   ← «مفتاحُ بوّابةِ الدفع» (عنوانُ سرٍّ في الخزنة)
```

**التحليل.** `hub_ref_options_scoped` يطبّق ثلاثةَ عوازل (مشاريع · شركات · عملاء)
ولا يسأل **ولا مرّة** `hub_can($user, $ref, 'v')`. الحارسُ الوحيدُ هو رايةُ `edge`
في `refOptions:1709`، وهي على **ثلاثةِ حقولٍ فقط** من ٣٠٦ — كلُّها في وحدة `servers`.

الإحصاءُ الكامل (`probe/count.php`):

```
cross-module ref pickers WITHOUT a hub_can(v) gate: 301
self-refs: 1   edge-gated: 3
host modules affected: 83   distinct target modules: 47
  users 72 · companies 56 · projects 47 · clients 16 · apps 11 · servers 8
  hr 8 · services 8 · vault 6 · domains 5 · engagements 4 · assets 4 …
```

وعزلُ الشركةِ لا يُنقذ: ستُّ وحداتٍ مرجعيّة (`competitors` · `ideas` · `incidents`
· `plans` · `requests` · `roles`) بلا عمودِ شركةٍ أصلاً، و`users` عمودُها
`company_id` **عمودٌ ميّت** موثَّقٌ في `IsolatedUserCanStillSeePeopleTest` —
فـ`hub_company_null_is_unowned('users')` يُمرّر كلَّ صفٍّ `NULL` عمداً.

**لماذا هذا أخطرُ من تسريبِ بحث.** البحثُ يُظهر ما طابق كلمةً؛ المنتقي **يعدّد
الجدولَ كلَّه** (حتّى ٥٠٠ صفّ) بضغطةٍ واحدةٍ على نموذجِ إنشاءٍ عاديّ، بلا كلمةٍ ولا
تخمين. وعنوانُ سرٍّ في الخزنة («مفتاحُ حسابِ سترايب الإنتاجيّ») يكشف بنيةَ المنشأة
حتى دون قيمتِه.

**الإصلاح.** تعميمُ قاعدة `edge` على **كلِّ** حقلِ مرجع، بنفسِ الشيفرة الموجودة:

```php
// ModuleController::refOptions — يصير الشرطُ عامّاً لا خاصّاً بـedge
if (! hub_can(auth()->user(), (string) $f['ref'], 'v')) {
    $out[$f['key']] = array_fill_keys(array_map('strval', array_filter((array) $cur)), '—');
    continue;
}
```

وأنظفُ منه: نقلُ الشرطِ إلى `hub_ref_options_scoped` نفسِها (مصدرٌ واحد)، فتَتبعه
تلقائيّاً المواضعُ العشرةُ التي تستدعيها — ومنها `hub_field_required:1838` الذي
**سيُسقط نجمةَ الإلزام** عن حقلٍ صارت قائمتُه خاوية، وهو السلوكُ المقصودُ أصلاً
(`M-F2`). أمّا الوحداتُ التي يجب أن يبقى مرجعُها مفتوحاً رغمَ غيابِ `v` (`companies`
و`projects` غالباً) فتُعلَن في قائمةٍ بيضاءَ صريحةٍ ومكتوبةِ السبب، لا بالسكوت.

**استثناءٌ لازمٌ قبل الإصلاح:** قياسُ عدد الحقول التي ستفرغ قوائمُها لكلِّ دورٍ
قائم — `301` حقلاً، وبعضُها **إلزاميّ**. الإصلاحُ بلا هذا القياس يشلّ إنشاءَ
سجلاتٍ اليومَ تُنشَأ.

**اختبارُ الانحدار.** مُعمَّمٌ لا نقطيّ — نظيرُ `NavCoverageTest`:

```php
/** لكلِّ حقلِ مرجعٍ في السجلّ: قارئٌ بلا `v` على وحدةِ الطرفِ الآخر لا يُعدَّد له صفٌّ واحد */
public function test_no_ref_picker_enumerates_a_module_the_reader_cannot_view(): void
// يبني صفّاً واحداً في كلِّ جدولٍ هدف بقيمةٍ بصمةٍ فريدة، ثم يفتح
// /m/{host}/create بدورٍ مصفوفتُه {host} وحدَها، ويؤكّد غيابَ كلِّ بصمة.
// جدولُ استثناءاتٍ مكتوبِ السبب (companies/projects) كـNavCoverageTest::DELIBERATE.
```

**الثقة: عالية** (مُثبَتٌ بالتشغيل على وحدتين، والإحصاءُ من السجلّ نفسِه).

---

### `A14-02` — **عالية** — خريطةُ النظام بلا رابطٍ حيٍّ واحد

**الموضع:** `app/Http/Controllers/Web/SystemMapController.php:27-46`.

**الدليل** (مسبار `ZzProbeA14Graph4Test`، المالك على `/system-map`):

```
[map destination LINKS] 0     [map destination BADGES] 177     [map destination rows] 262
```

كلُّ وجهةٍ تُرسَم `<span class="bdg" title="وجهةٌ سياقيّة (تُفتح من سجلّها)">` —
بما فيها «لوحة التحكم» و«المهامّ» و«الموظفون». الصفحةُ التي تقدّمها الترويسةُ
بوصفها «أداةَ اكتشافٍ لكلِّ مستخدم» (`layouts/app.blade.php:54`) **لا تُنقَر**.

**السبب الجذريّ.** سطران في `annotate()`:

```php
foreach ($node['sections'] ?? [] as &$s) {          //  ← ?? يُنتِج قيمةً لا مرجعاً
    foreach ($s['destinations'] ?? [] as &$d) {     //  ← والكتابةُ تقع في نسخةٍ مؤقّتة
        $d['url'] = …;
```

PHP لا يرفض `foreach (EXPR ?? [] as &$ref)` — يُكرِّر على **نسخة**، فالكتابةُ تضيع
صامتةً. أثبتُّه مجرَّداً:

```php
foreach ($node['sections'] ?? [] as &$s) …   →  {"sections":{"a":{"destinations":[{"route":"r"}]}}}
foreach ($node['sections']      as &$s) …   →  {"sections":{"a":{"destinations":[{"route":"r","url":"SET"}]}}}
```

**لماذا لم تكشفه الحزمة.** `SearchAndMapOfferMatchesDestinationTest::refusedMapLinks`
(`tests/Feature/SearchAndMapOfferMatchesDestinationTest.php:95-116`) يستخرج `href=`
من **الصفحة كلِّها** — والصفحةُ ترث `layouts.app`. فالـ٨٣ رابطاً التي يطرقها هي
الشريطُ الجانبيُّ والترويسة:

```
[hrefs the existing map test would knock] 83
/css/fonts.css · /css/app.css · /manifest.webmanifest · /w/entities · /w/work · /morning · /collab · /me …
```

**ولا واحدٌ منها من الخريطة.** الحارسُ أخضرُ وهو لا يقيس ما وُضع ليقيسه — وهذا
عينُ ما يحذّر منه `CLAUDE.md`: «الضررُ ليس السقوطَ الكاذبَ بل العادةَ التي يُعلّمها».

**الإصلاح.** إسقاطُ `?? []` (البنيةُ مضمونةٌ من `systemMap()` الذي يبني
`'sections' => …` دائماً)، أو الأنظف: بناءُ الشجرةِ بالقيمة وإعادتُها بدل الكتابةِ
بالمرجع.

**اختبارُ الانحدار.**

```php
public function test_the_system_map_draws_a_live_link_for_every_reachable_destination(): void
{
    $html = $this->actingAs($this->owner)->get(route('system-map'))->getContent();
    // روابطُ الخريطةِ نفسِها لا روابطُ التخطيط: نقصُّ على .card قبل الاستخراج
    $this->assertGreaterThan(100, preg_match_all('~<a class="btn ghost xs" href="~', $html),
        'الخريطةُ لا ترسم روابط — الوجهاتُ شاراتٌ ميّتة');
    // وشارةُ «سياقيّة» تبقى للسياقيّة وحدَها (رحلةُ العميل، خريطةُ الأثر)
}
```

وفي `refusedMapLinks` نفسِه: قصُّ المستخرَجِ على منطقةِ الخريطة، وتأكيدُ
`$this->knocked > 0` **من الخريطة وحدَها** كي لا يعود العمى.

**الثقة: عالية**.

---

### `A14-03` — **عالية** — البحثُ و«الأخيرة» يطبعان عمودَ العرضِ المحجوبَ عن الدور

**الموضع:** `SearchController.php:59` و`:98` و`:138` · `resources/views/search/index.blade.php:38`
· `resources/views/partials/searchmini.blade.php:17` · `app/Traits/Searchable.php:32`.

**الدليل** (مسبار `ZzProbeA14FieldTest`): دورٌ `field_rules = ['hr' => ['name' => 'hide']]`:

```
[field mode hr.name]        hide
[index shows name]          no      ← /m/hr يحجب كما أُمر ✅
[palette shows name]        YES     ← /search/mini يطبعه ❌
[search page shows name]    YES     ← /search يطبعه ❌
```

ومسبار `ZzProbeA14RecentTest` يضيف السطحَ الثالث:

```
[recent shows HIDDEN name]  LEAK    ← «الأخيرة» في التركيز تطبعه ❌
```

**التحليل.** المنتجُ يستشير `hub_field_mode` قبل الطباعة في تسعِ شاشاتٍ على الأقلّ
(`modules/index.blade.php:65` · `modules/show.blade.php:103` · `custody-wallet/_wallet.blade.php:5`
· `endpoints/show.blade.php:17` …). وأسطحُ البحث الثلاثةُ **لا تستشيرها**:

- `results():59` → `$row->{hub_display_col($key)}` يُمرَّر خامّاً إلى
  `searchmini.blade.php:17`؛
- `index():138` → `'display'`/`'status'` يُطبعان في `search/index.blade.php:38-40`
  — **والحالةُ أيضاً**، بينما `modules/index.blade.php:155` يحرسها بـ`=== ''`؛
- `recents():98` → نفسُ العمود.

والنصفُ الآخر أخطر: `Searchable::scopeSearch:32` يُسقط الحقولَ المحجوبةَ ثمّ
`->push(hub_display_col(MODULE))` **يُعيد عمودَ العرضِ بلا مرورٍ بالمُسقِط**. فالعمودُ
المحجوب ليس مطبوعاً فحسب — بل **قابلٌ للاستنطاق حرفاً حرفاً**، وهو بالضبط «البابُ
المنسيّ» الذي يصفه تعليقُ السمة نفسُها في السطور ٢٣-٢٦.

**ما يخفّف الخطورةَ قليلاً:** لا وحدةَ من الـ٨٥ عمودُ عرضِها أو حالتِها موسومٌ
`fieldsec`-حسّاساً (`probe/sens.php` → `0`)، فالتسريبُ يحتاج قاعدةَ دورٍ صريحة.
لكنّ واجهةَ الأدوار تعرض «مخفي» لكلِّ حقلٍ بلا استثناءِ عمودِ العرض
(`roles/form.blade.php:193,214`) — فالضبطُ متاحٌ ومُتَجاوَز.

**الإصلاح.**

1. في `Searchable::scopeSearch`: لا تُضَفْ `hub_display_col` إلّا إن لم تكن `hide`.
2. في `SearchController`: تسميةٌ آمنةٌ واحدةٌ مشتركة —
   `hub_field_mode($u,$m,$dispKey) === 'hide' ? '— محجوب #'.Str::limit($row->id,8) : $row->{$disp}`
   تُستعمل في `results` و`recents` و`index` (والحالةُ مثلُها).
3. الرابطُ يبقى: القارئُ يملك `v` على السجلّ، المحجوبُ هو الحقلُ لا السجلّ.

**اختبارُ الانحدار.**

```php
/** حقلٌ يحجبه الدورُ لا يُطبَع في أيِّ سطحِ بحثٍ ولا يُستنطَق به */
public function test_a_role_hidden_display_column_never_reaches_a_search_surface(): void
{
    // ثلاثةُ تأكيداتٍ: /search · /search/mini?q= (الأخيرة) · /search/mini?q=<جزءٌ من الاسم>
    // ورابعٌ: البحثُ بالاسمِ المحجوب لا يُعيد السجلَّ أصلاً (لا أوراكل)
    $this->assertMaskedValueAbsent($html, 'زينبُ المحجوبة');
}
```

(يُستعمل `assertMaskedValueAbsent` لا `assertStringNotContainsString` — قاعدةُ
`v2.540.1`؛ والقيمةُ هنا نصٌّ عربيٌّ فلا قرعةَ معرّفاتٍ أصلاً، لكنّ الاصطلاحَ واحد.)

**الثقة: عالية**.

---

### `A14-04` — متوسطة — `graph.explore` وجهةٌ ميّتة تُعرَض للجميع

**الموضع:** `config/hub_ia.php:160-163` · `app/Http/Controllers/Web/RelationshipExplorerController.php:53-58`.

**الدليل:**

```
[GET /graph/explore as owner]  404
[search page offers it]        YES
[palette offers it]            YES
=== CATALOG {owner,employee,viewer,narrow,isolated,monitor} ===  bad=1  404 مستكشف العلاقات → graph.explore
```

**التحليل.** `explore()` يستدعي `target()` الذي يرمي `abort_unless($module !== '' &&
$id !== '', 404)` — فالمسارُ **سياقيٌّ بطبعه**: لا يُفتَح إلّا من زرِّ «العلاقات»
في صفحةِ ٣٦٠ بمعامليِ `?m=&id=`. لكنّ إعلانَه في `hub_ia.php` يحمل `route` بلا
`args`، فيَبني `route('graph.explore')` رابطاً صالحَ البناءِ ميّتَ الوجهة.

والدليلُ على أنّ الاصطلاحَ معروفٌ في الملفّ نفسِه: الجارةُ المباشرة «رحلة العميل»
(`hub_ia.php:150`) تحمل `'contextual' => true` ولا `route` — فتُرسَم شارةً لا رابطاً.
و«خريطة الأثر» كذلك. `graph` وحدَها شذّت.

**لماذا لم تكشفه الحزمة.** `SearchAndMapOfferMatchesDestinationTest` يعدّ **٤٠٣
وحدَه سقوطاً** (السطر ٨٤: `=== 403`) — بمنطقٍ مكتوبٍ ومقنع: «وجهةٌ تعتذر بـ٤٠٤
رسالةٌ صادقةٌ لا دعوةٌ إلى بابٍ مغلق». وهو صحيحٌ **لوجهةٍ محجوبةٍ عن قارئٍ بعينه**؛
أمّا وجهةٌ تردُّ ٤٠٤ على **المالك نفسِه** فليست رسالةً صادقة — هي رابطٌ مكسور.

**الإصلاح.** إمّا `'contextual' => true` وحذفُ `route` (نظيرُ `journey`)، وإمّا
صفحةُ هبوطٍ للمستكشف تسأل عن الجذر. **والثاني أفضل**: المستكشفُ قدرةٌ حقيقيّةٌ لا
يبلغها أحدٌ إلّا بمعرفةِ زرٍّ داخلَ صفحةِ ٣٦٠.

**اختبارُ الانحدار.** توسيعُ الحارسِ القائم: `$refused[] = …` يلتقط `404` **أيضاً
حين يكون القارئُ هو المالك** — فالمالكُ يجد كلَّ شيءٍ وكلُّ بابٍ يُفتَح له، وهو
نصُّ `test_the_owner_finds_everything_and_every_door_opens` بعينه.

**الثقة: عالية**.

---

### `A14-05` — متوسطة — منتقي الإشارة يعرض حساباتِ بوّابةِ العملاء

**الموضع:** `app/Http/Controllers/Web/CommentController.php:343-346`.

```php
public static function userNames(): array
{
    return User::whereNull('deleted_at')->orderBy('name')->pluck('name', 'id')->all();
}
```

بلا `account_type`، وبلا نطاقِ شركة. تُستهلَك في `partials/comments.blade.php:28`
(منتقي `mention[]` في **كلِّ** صفحةِ سجلّ)، وفي `feed/index.blade.php`،
و`conversations/show.blade.php` (`ConversationController:260`)، و`CollaborationController:65,89`.

**التحليل.** المستودعُ يعرف هذه القاعدةَ ويطبّقها في موضعين آخرين:
`hub_ref_options:760-766` يستبعد `account_type = 'client'` بتعليقٍ يشرح الحادثةَ
التي أوجبته («ظهرت عبير وسامي — بوّابة عملاء — خيارَين لإسناد مهمّةٍ داخليّة»)،
و`DmController::reachableColleagues:167` يستبعدهم بـ`!hub_is_client($u)`.
`userNames()` هو الموضعُ الثالثُ ولم يتبع.

الأثرُ: اسمٌ خارجيٌّ في قائمةِ الإشارةِ الداخليّة يقود إلى إشعارِ حسابِ عميلٍ
بتعليقٍ داخليّ. ولم أُثبت أنّ الإشعارَ **يصل** فعلاً (ذلك سطحُ الوكيل ١٧)، فالخطورةُ
متوسطةٌ لا عالية.

**الإصلاح.** استبعادُ حساباتِ العملاء في `userNames()` بالصيغةِ ذاتها التي في
`hub_ref_options`، وإضافةُ نطاقِ الشركةِ بمُحدِّد `dmReachable` إن أُريد الاتّساقُ الكامل.

**اختبارُ الانحدار.**

```php
public function test_the_mention_picker_never_offers_a_client_portal_account(): void
// حسابُ بوّابةٍ باسمٍ بصمة + فتحُ /m/tasks/{id} بحسابٍ داخليّ → غيابُ البصمة في <select name="mention[]">
```

**الثقة: عالية** في الحقيقة، **متوسطة** في الأثر النهائيّ.

---

### `A14-06` — منخفضة — خمسةُ منتقياتٍ بشريّةٍ خارجَ السكّة المنطَّقة

**المواضع:** `partials/custody_card.blade.php:61` و`:294` · `modules/custom/stations.blade.php:119`
· `identity/_scan.blade.php:6` · `partials/attachments.blade.php:19`.

الأربعةُ الأولى تستدعي `hub_ref_options('users', …)` الخامَّ بينما النظيرُ المنطَّق
`hub_ref_options_scoped` موجودٌ ومستعمَلٌ في عشرةِ مواضعَ أخرى — وفي
`identity/_scan.blade.php` **السطرُ التالي مباشرةً** يستعمل `_scoped` للشركات مع
تعليقٍ يشرح لماذا. والخامسةُ تبني قائمةَ ٥٠٠ حسابٍ داخليٍّ بيدها.

**لماذا منخفضة.** `users.company_id` عمودٌ ميّتٌ عمليّاً (`IsolatedUserCanStillSeePeopleTest`
يوثّقه ويُبقيه ميّتاً عمداً)، و`hub_company_null_is_unowned('users') === true` —
فـ`_scoped` نفسُها لا تُرشِّح إلّا الحسابَ المختومَ صراحةً بشركةٍ أخرى، وهو نادر.
الفارقُ الفعليُّ إذن ضيّق، **لكنّ الاتّساقَ مهمّ**: يومَ يُحيا العمودُ ستنكشف هذه
الخمسةُ وحدَها.

**الإصلاح.** استبدالُ `hub_ref_options(` بـ`hub_ref_options_scoped(` في الأربعة،
وتمريرُ `$aPeople` في `attachments.blade.php` عبر السكّة نفسِها.

**اختبارُ الانحدار.** حارسُ نحوٍ لا حارسُ سلوك — نظيرُ حرّاس الأسلوب القائمة
(`StyleVocabularyTest`):

```php
public function test_no_blade_calls_the_unscoped_ref_options(): void
// grep على resources/views/** عن hub_ref_options( غيرِ المتبوعةِ بـ_scoped
// مع جدولِ استثناءاتٍ مكتوبِ السبب
```

**الثقة: عالية**.

---

### `A14-07` — منخفضة — `inboxdocs` تبثّ كتالوجَ الوحداتِ كاملاً

**الموضع:** `app/Http/Controllers/Web/InboxDocController.php:55`.

```php
'modules' => collect(config('hub.modules'))->map(fn ($d) => $d['label']),
```

تُرسَم `<select>` تصنيفِ الوثيقة (`inboxdocs.blade.php`). والنظيرُ الصحيحُ على
بُعدِ ملفّين: `AuditController:75` —
`array_filter(hub_modules(), fn ($mk) => hub_can($u, $mk, 'v'), ARRAY_FILTER_USE_KEY)`
بتعليقٍ يقول «الوحدةُ المحجوبة لا تُسمّى ولا تُعدّ».

أثران: كشفُ أسماءِ ٨٥ وحدةً لمن يرى ثلاثاً، ووعدٌ كاذبٌ بتصنيفِ وثيقةٍ إلى وحدةٍ
لا يبلغها. وفي السطر ٥٤ `'users' => User::pluck('name','id')` — بلا
`whereNull('deleted_at')` وبلا استبعادِ حساباتِ العملاء (خريطةُ أسماءٍ للعرض،
لا منتقٍ — لذا لم أفردها).

**الإصلاح.** نسخُ شرطِ `AuditController:75` حرفيّاً.

**اختبارُ الانحدار.** `test_the_inbox_classifier_only_names_modules_the_reader_can_view`.

**الثقة: عالية**.

---

### `A14-08` — منخفضة — هروبُ أحرفِ البدل في بحثِ الرسائل يفترق بين المحرّكين

**الموضع:** `app/Http/Controllers/Web/MessageSearchController.php:35`.

```php
$like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
// ثم: ->where('body', 'LIKE', $like)   — بلا ESCAPE
```

**الدليل** (مسبار `ZzProbeA14Like3Test`، جسمُ الرسالة `الملف report_2026 جاهز`):

```
[driver] sqlite
[MessageSearch pattern] %report\_2026%  -> rows=0     ← سلبٌ كاذب
[Searchable  pattern]   %report!_2026%  -> rows=1     ← صحيح
```

**التحليل.** MySQL يتّخذ `\` حرفَ هروبٍ افتراضيّاً في `LIKE`؛ SQLite **لا** — فالنمطُ
يصير «حرفُ شرطةٍ خلفيّةٍ حرفيّ ثمّ حرفٌ واحد». والنتيجةُ صفرُ نتائجَ على SQLite لأيِّ
استعلامٍ فيه `_` أو `%` — وهما في أسماءِ الملفّات والإصدارات (`report_2026` ·
`v2_final` · `خصم 50%`) أكثرُ من نادرين.

والاصطلاحُ مكتوبٌ في المستودع ومُعلَّلٌ: `app/Traits/Searchable.php:18-19` —
«حرف الهروب `!` لا `\`: literal الشرطة الخلفية نفسه يختلف بين المحرّكين فيتباعد
سلوكهما — و`!` واحدٌ فيهما». و`SearchController::workOs:247` يتبعه. هذا الملفُّ وحدَه شذّ.

الإنتاجُ على MySQL فالأثرُ اليومَ محدود — **إلّا** أنّ `sql_mode=NO_BACKSLASH_ESCAPES`
يقلبه فوراً، وأنّ الحزمةَ على SQLite تُخضِرّ على سلوكٍ مختلفٍ عن الإنتاج، وهو عينُ
ما يحذّر منه `CLAUDE.md`.

**الإصلاح.** `whereRaw("body LIKE ? ESCAPE '!'", [$t])` بالهروبِ نفسِه (`!`).

**اختبارُ الانحدار.** `test_message_search_finds_an_underscore_literally_on_both_engines`
(يمرّ على المحرّكين عبر `phpunit.mysql.xml` في CI).

**الثقة: عالية**.

---

### `A14-09` — منخفضة — فتاتُ الخبز لا يُفضي إلى وجهة

**الموضع:** `app/Support/InformationArchitecture.php:588-631` · `partials/breadcrumbs.blade.php:20`
· `partials/pagehead.blade.php:7-11`.

`breadcrumbs()` يبني المسارَ الدلاليَّ كاملاً (مجال ← قسم ← وجهة ← سجلّ) ثمّ يضع
`'url' => null` في **كلِّ** عقدة — أربعُ مرّاتٍ في الدالة. والقالبُ مهيّأٌ للرابط
(`@if (! empty($c['url']))`) لكنّه لا يستلمه أبداً. وفتاتُ `pagehead` الموازي:
`27` صفحةً تمرّر `crumb`، **٧** منها فقط تمرّر `crumbUrl`.

فالسؤال «أتُفضي إلى وجهةٍ صحيحةٍ دائماً؟» جوابُه: **لا تُفضي إلى شيء**. لا وجهةَ
خاطئةً — ولا وجهةَ أصلاً. وأثرُه اكتشافيّ: المستخدمُ في `/m/hr/{id}` يقرأ «الموارد
البشرية ‹ الموظفون» ولا يستطيع النقرَ للصعود.

**الإصلاح.** في `breadcrumbs()` تُحلُّ `url` للعقدِ التي لها مسارٌ عامٌّ ومرئيّ:
المجالُ → `route('workspace', $key)` إن كانت له مساحة، والوجهةُ →
`resolveDestination()['route']` إن مرّت `destinationVisible`. والعقدةُ غيرُ
المرئيّةِ تبقى نصّاً — فلا يُفتح بالفتاتِ بابٌ مغلق.

**اختبارُ الانحدار.** `test_the_breadcrumb_climbs_to_a_door_the_reader_owns`
— مع طَرْقِ كلِّ رابطِ فتاتٍ كما في `NavOfferMatchesDestinationTest`.

**الثقة: عالية** في الحقيقة، **متوسطة** في كونها عطلاً لا اختياراً (التعليقُ يقول
«محايدٌ للصلاحية (لا يحمل إلا تسمياتِ الموقع)» — وهو تبريرٌ لعدمِ حملِ البيانات لا
لعدمِ حملِ الروابط).

---

### `A14-10` — منخفضة — نهايةُ البحثِ المسدودة لا تسلّم إلى بحثِ الرسائل

**الموضع:** `resources/views/partials/searchmini.blade.php:22-24` · `search/index.blade.php:24-25`.

حين لا نتيجة: «لا نتائج لـ«X»» ونقطة. والبحثُ الشامل **لا يقرأ نصَّ رسالةٍ أبداً**
(يجد القناةَ بعنوانها لا بمحتواها — موثَّقٌ في `MessageSearchController:17`)،
فالمستخدمُ الذي يبحث عن جملةٍ قالها زميلٌ يصطدم بالجدار ولا يُقال له أين يُكمل،
رغم وجودِ `/search/messages` في المنتج.

**الإصلاح.** سطرٌ واحدٌ في حالةِ الفراغ: «لم نجدها في السجلات —
[ابحث في الرسائل عن «X»](route('search.messages', ['q' => $q]))». والشرطُ `authed`
وهو محقَّقٌ أصلاً.

**اختبارُ الانحدار.** `test_the_empty_search_hands_off_to_message_search`.

**الثقة: متوسطة** (حكمُ منتَجٍ لا عطلُ شيفرة).

---

## ٦. ما فُحص ووُجد سليماً — تسجيلٌ صريح

| السطح | الفحص | النتيجة |
|---|---|---|
| نتائجُ السجلات | نطاق + عميل + سياقُ جوال قبل كلِّ عدٍّ واستعلام | ✅ `SearchController:305-317` |
| عددُ النتائج | محسوبٌ على الاستعلام المنطَّق نفسِه | ✅ `:133` |
| القنواتُ في البحث | العضويّةُ الفعّالةُ شرطُ الظهور، والمالكُ بلا عضويّةٍ لا يُفهرَس له | ✅ `:255-266` |
| كشوفُ العهدة | `hub_can('custody','v')` + عزلُ الشركةِ على الطرفين + **بلا أرقام** | ✅ `:272-286` |
| المطابقاتُ التشغيليّة | خمسةُ أنماطٍ كلٌّ بحارسِ شاشته، وبلا استعلامٍ لنصٍّ عاديّ | ✅ `:186-232` |
| حسابُ بوّابةِ العميل | ٤٠٤ على `/search` + `workOs` يعيد `[]` | ✅ |
| وجهاتُ اللوحة مقابل الأبواب | ٥ شخصيّات × ٢٥٢ رابطاً · ٦ شخصيّات × ٥٤٢ وجهةَ كتالوج | ✅ صفرُ ٤٠٣ |
| إجراءاتُ «＋ جديد» | ٨١ إجراءً × ٣ شخصيّات | ✅ صفرُ فشل |
| «الأخيرة» عند سحب الصلاحيّة | `hub_can` + `hub_scope` + `deleted_at` لحظةَ العرض | ✅ `:80-105` (مُثبَتٌ بالتشغيل) |
| المثبّتاتُ عند سحب الصلاحيّة | `hub_pin_targets` يُعاد بناؤه من `hub_top_links` + `hub_can` | ✅ `helpers.php:594-627` (مُثبَتٌ بالتشغيل) |
| وحداتٌ خارجَ الشريط | ٤، كلُّها بقرارٍ مكتوبٍ وحارسٍ آليّ | ✅ `NavCoverageTest:28-39` |
| الاختصارات | `Ctrl/⌘+K` و`/` و`↑↓` و`Enter` و`Esc` — ومعالجٌ واحدٌ لا اثنان | ✅ `public/js/app.js:474-520` |
| حجبُ الحقولِ في البحث | `hide` + `fieldsec` مُسقَطان من أعمدةِ البحث | ✅ `Searchable.php:28-34` — **عدا عمودِ العرض** (`A14-03`) |

---

## ٧. ترتيبُ المعالجة المقترَح

1. **`A14-03`** أوّلاً — تسريبُ حقلٍ محجوبٍ، والإصلاحُ صغيرٌ ومحصور (دالّةُ تسميةٍ
   واحدةٌ + سطرٌ في السمة).
2. **`A14-02`** ثانياً — سطرانِ يُصلَحان، ويُعيدان صفحةً كاملةً إلى الحياة؛ ومعه
   إصلاحُ عمى الحارس، وإلّا تكرّر.
3. **`A14-01`** ثالثاً — الأثرُ الأمنيُّ الأكبر، **لكنّ الإصلاحَ يحتاج قياساً أوّلاً**
   (٣٠١ حقلاً، بعضُها إلزاميّ) كي لا يُشَلَّ إنشاءُ سجلاتٍ اليومَ تُنشَأ.
4. `A14-04` و`A14-05` — إصلاحان صغيران واضحا الحدّ.
5. الباقي (`A14-06` … `A14-10`) — نظافةُ اتّساقٍ واكتشاف.

---

## ٨. ملحق: المسابر

جميعُها في `…/scratchpad/probe/` خارجَ شجرةِ المشروع (أُخرجت من `tests/` بعد التشغيل):

| المسبار | يُثبت |
|---|---|
| `ZzProbeA14Test.php` | `A14-01` — تسريبُ `hr` و`vault` في نموذجَي `phones`/`apps` |
| `ZzProbeA14SweepTest.php` | مسحُ روابط `/search` × ٥ شخصيّات |
| `ZzProbeA14CatalogTest.php` | طَرْقُ الكتالوج × ٦ شخصيّات — كشف `A14-04` |
| `ZzProbeA14Graph*.php` | `A14-04` و`A14-02` (صفرُ روابطَ في الخريطة) |
| `ZzProbeA14ActionsTest.php` | إجراءاتُ «＋ جديد» × ٣ شخصيّات |
| `ZzProbeA14FieldTest.php` | `A14-03` — الحقلُ المحجوب في البحث |
| `ZzProbeA14RecentTest.php` | «الأخيرة» والمثبّتاتُ عند السحب + `A14-03` في «الأخيرة» |
| `ZzProbeA14BuriedTest.php` | الوحداتُ الأربعُ خارجَ الشريط |
| `ZzProbeA14MapBlindTest.php` | `A14-02` — عمى الحارس (٨٣ رابطاً من التخطيط) |
| `ZzProbeA14Like3Test.php` | `A14-08` — افتراقُ الهروب بين المحرّكين |
| `refs.php` · `refs2.php` · `count.php` · `nav.php` · `ia.php` · `searchable.php` · `sens.php` | الإحصاءاتُ من السجلّ نفسِه |
