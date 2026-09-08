# 00 — الخريطة الحالية للتنقّل (Current Information Architecture)

> **DISCOVERY — قراءةٌ لا تعديل.** رأس النسخة `v2.429.0`. كل صفٍّ مُثبَّتٌ بمسارٍ/وحدةٍ/مفتاح‑config حقيقي. لا اختراع.

هذه الوثيقة ترسم ما هو موجودٌ **فعلاً** اليوم — لا ما ينبغي أن يكون. الخريطة المستهدفة في `03-target-map.md`.

## 1. البنية الفيزيائية للتنقّل: خمس طبقاتٍ في سطحين

التنقّل مرسومٌ في سطحين اثنين لا واحد:

| السطح | الملف | الطبقات | الجمهور |
|---|---|---|---|
| الشريط الجانبي (يسار RTL) | `resources/views/partials/sidebar.blade.php` | Home · Pins · Workspaces · Tools&Boards · Modules | العمل اليومي |
| شريط الترس ⚙️ العلوي | `resources/views/layouts/app.blade.php:159` | Administration/System | النظام (مالك/رايات) |

الشريط الجانبي **يُصرّح** أن النظام انتقل للأعلى: `sidebar.blade.php:90` «قسم «النظام» انتقل إلى قائمة الترس ⚙️ في البار العلوي — الجانبي للعمل اليومي فقط». فالفصل بين العمل والنظام **قائمٌ فيزيائياً**، لكنه غير مُعبَّرٍ عنه كـ«خريطةٍ واحدة».

### الطبقات الخمس بالتفصيل

```
Application
├─ 🏠 لوحة التحكم            dashboard  /                      [sidebar.blade.php:10]  GLOBAL
│
├─ 📌 مثبّتاتي (Pins)         hub_pins()                        [sidebar.blade.php:14-28]  USER_PERSONAL
│    └─ رموز: مفتاح top-link أو m:{module} — pref nav.pins (لا جدول)
│
├─ مساحات العمل (Workspaces) Workspaces::for()                 [sidebar.blade.php:32-44]  WORKSPACE
│    ├─ 🏢 الكيانات والعلاقات        /w/entities   + شارة انتباه
│    ├─ 💠 التقنية والبنية الرقمية   /w/digital
│    ├─ 🗂️ العمل والعمليات           /w/work
│    ├─ 💰 المالية والمشتريات        /w/finance
│    ├─ 👥 الموظفون والموارد البشرية /w/hr
│    ├─ 📜 العقود والشؤون القانونية  /w/legalws
│    ├─ 🧭 العمليات الميدانية        /w/fieldops
│    └─ 📚 المستندات والمعرفة        /w/knowledge
│         (+ /w/digital/tech = tech.workspace — مساحة فرعية خاصة)
│
├─ الأدوات واللوحات (Tools & Boards)  hub_top_groups()         [sidebar.blade.php:52-67]  CONTROL_CENTER
│    ├─ 🧭 مساحتي اليومية (open)   = مجموعة daily (7 روابط)
│    │     morning · me · alerts · calendar · feed · dm · inboxdocs
│    └─ 📊 اللوحات والمراكز (collapsed) = analytics(14) + centers(18) مدموجتان
│          ceo · perf · sales · finrep · costs · svccosts · kpis · capacity · recs · impact ·
│          appq · delivery · dassets · social · okrb · custody · identity · workteam · codehub ·
│          assetlife · compb · appsproj · pricing · mediac · teamdir · staff · polb · legal ·
│          esign · support · innov · supscores
│
└─ الوحدات (Modules)          hub_nav()                         [sidebar.blade.php:76-88]  MODULE
     يظهر فقط عند nav.style=classic أو request()->is('m/*')  [sidebar.blade.php:75-76]
     8 مجموعات / 80 وحدة → كلها عبر m.index/{module}
```

```
Top bar ⚙️ (Administration / System)  hub_admin_links()        [layouts/app.blade.php:159]
     شرط ظهور الشريط: owner || flag(users) || flag(audit) || secrets()   [app.blade.php:142]
├─ الأمن والرقابة   audit · security · activity · dataroom
├─ التشغيل          control · ops · errors · incidents · alerts(center)
├─ الجودة والحوكمة  quality · fields · flows
└─ الإعدادات        settings · integrations · roles · users · prefs · quoteflow
```

**رقم النسخة** في ذيل الشريط `sidebar.blade.php:95` من `config('hub.version')` (يقرأ `VERSION` حيّاً).

## 2. مصدر الحقيقة والوصلات بين الطبقات

- **`config/hub.php`** — سجل الوحدات (85 وحدة)، المصدر الوحيد. لا علمَ للسجل بالتنقّل: لا يوجد حقل `nav`/`hidden`/`admin` على أي وحدة (histogram = 0). ظهورُ الوحدة في التنقّل **مُشتقٌّ** من (أ) عضويتها في مجموعة `hub_nav`، (ب) `hub_can($user,$key,'v')`، (ج) حالاتٍ يدوية (مثل استثناء `users`). **هذا بالضبط سبب الحاجة لطبقة IA: مكانُ الوحدة غير مُشفَّرٍ في أي مكان واحد.**
- **`config/hub_nav.php`** → `hub_workspaces.php`: الوصل **بسلسلة الاسم العربي** لا بمفتاح. `Workspaces.php:20`: `$navGroups[$ws['nav']]['items']`. المساحة ترث وحداتها من مجموعة hub_nav المطابقة اسماً.
- **`hub_top_links`** كتالوج المراكز (دالّة PHP، لا ملف config). مراكز المساحة (`centers[]`) مفاتيحُ منه. المثبّتات تشير إليه أيضاً.
- **`SearchController::destinations`** (`SearchController.php:154-190`) يقرأ **نفس** `hub_top_links` + `hub_nav` + `hub_admin_links` — فالبحث والشريط من مصدرٍ واحد. هذا الاتحاد هو ما يجب أن يرثه IA.
- **الجوال** `MobileContextController.php:114`: `'nav' => hub_nav($u)` — يستهلك **طبقةً واحدةً** من الخمس (الوحدات فقط). المساحات والمراكز والمثبّتات **غائبةٌ عن تنقّل الجوال**.

## 3. التداخل الحقيقي — لماذا يبدو النظام «مستودعَ ٨٠ وحدة»

نفس الوجهة تُطرَق من طبقاتٍ متعددة بلا «بيتٍ أساسيٍّ واحد». أمثلة موثَّقة:

| الوجهة | يظهر في | التداخل |
|---|---|---|
| وحدة `assets` | Workspace legalws · Module nav · Pin `m:assets` · مراكز custody/identity/assetlife/inventory | 4+ مداخل، لا بيت أساسي معلن |
| `impact` (خريطة الأثر) | centers مساحة entities **و** مساحة digital · مجموعة analytics | مركزٌ في مساحتين |
| `innov` (الابتكار) | centers مساحة work **و** مساحة knowledge · مجموعة centers | مركزٌ في مساحتين |
| `inboxdocs` | مجموعة daily · مرتبطٌ بـ files (knowledge) | شخصي + معرفة |
| `pricing`/`plans` | مركز pricing (تجاري) · وحدة plans في مجموعة knowledge | المركز والوحدة في مجالين |
| `staff`/`team`/`workteam` | ثلاثة مراكز أفراد متجاورة في centers | تسميةٌ غير مُطبَّعة (dashboard/center/overview) |
| الوحدات الـ80 | مجموعة Modules (مسطّحة) + بطاقات المساحات | قائمةٌ مسطّحةٌ بـ71+ رابطاً خلف classic |

المجموعة المدموجة **«اللوحات والمراكز»** (`helpers.php:472`) تعترف صراحةً بازدواج المصطلح (Dashboards **و** Centers) الذي يجب تطبيعُه.

## 4. جدول كل وجهةٍ حالية (مُلخَّص بالفئة — التفصيل الكامل في 01)

> الأعمدة: label · route · module · category · workspace · center · permission · current-location · alt-locations · purpose. التفصيل الصفّي الكامل (188 مسار) في `01-destination-inventory.md`؛ هنا التلخيص البنيوي.

### 4.1 GLOBAL (7)
| label | route | permission | current-location | purpose |
|---|---|---|---|---|
| لوحة التحكم | dashboard `/` | auth | sidebar top | لوحة widgets رئيسية |
| بحث شامل | search / search.mini | auth | topbar `#gq` | البحث الموحّد (بديل ⌘K المُزال، `app.blade.php:216`) |
| تشغيل اليوم | morning | auth | daily group | ملخّص يومي شخصي |
| ينتهي قريباً | alerts | auth | daily group | رادار الانتهاء |
| التقويم | calendar | auth | daily group | تقويم |
| قناة الفريق | feed | auth | daily group | تغذية الفريق |

### 4.2 USER_PERSONAL / My Work (14)
portal.me · profile.edit · prefs.edit · mysec.index · stepup.show · dm.inbox/thread · conversations.index/show · notifications.index/count/mini/go · inboxdocs.index — كلها auth شخصية، موزّعةٌ بين daily group و topbar، **بلا قسم «مهامّي» جامع**.

### 4.3 WORKSPACE (2) — 8 مساحات عبر `/w/{key}` + `/w/digital/tech`
كل مساحة: label/icon/color/desc + modules (من hub_nav) + centerLinks (من hub_top_links). صفحة مركزية WorkspaceController@show.

### 4.4 MODULE (7 مسارات عامّة تغطّي 85 وحدة)
m.index · m.board · m.create · m.show · m.edit · m.export · m.import — الحارس `hub_can` + `hub_scope` + `hub_field_mode` داخل ModuleController.

### 4.5 CONTROL_CENTER (63) — المراكز واللوحات
40 في كتالوج hub_top_links + 23 مركزاً مستقلاً (boards/oversight/inventory/endpoints/field/journey/graph/apps.center/workforce/custody-wallet…). الحارس في المتحكّم (owner/monitor/hub_can). التفصيل في 01 §CONTROL_CENTER.

### 4.6 ADMINISTRATION (49) — شريط الترس
18 رابطاً في 4 مجموعات + مساراتها الفرعية (security.* = 13، integrations.* = 6…). لا وسيط على مستوى المسار — الحارس في `gate()` بكل متحكّم (خطر مُوثَّق في 01 §RISK).

### 4.7 UTILITY (9) · PUBLIC (14) · PORTAL-client (10) · SYSTEM (9)
مولِّدات وثائق/PDF/تنزيل · مداخل خارجية (login/توقيع/تحقّق/مشاركة/QR) · بوابة العميل (PortalGuard) · بنية تحتية (health/pwa/well-known/trace).

## 5. الطبقات المحفوظة أثناء الترحيل (لا تُكسَر)
- **المثبّتات** `nav.pins` (pref، لا جدول) — `PrefService::togglePin` سقفُها 12. ويب `prefs.pin` + جوال `mobile.prefs.pin` نفسُ الخدمة.
- **لوحة الأوامر ⌘K** = حقلُ البحث العلوي `#gq` (اللوحة المنفصلة أُزيلت — `app.blade.php:216`؛ اختصار Ctrl/⌘K يركّز الحقل — `public/js/app.js:457`).
- **نمط classic** (`nav.style=classic`) = القائمة المسطّحة الكاملة — يبقى fallback.
- **تبديل الشركة/العميل** = سياقُ تشغيلٍ لا هيكل.

## 6. الخلاصة البنيوية (المدخل إلى 03)
- الفصل عمل/نظام **قائمٌ** (شريط جانبي vs ترس) لكنه ليس «خريطةً واحدة».
- المساحات الثماني **هي بالفعل طبقة المجالات** — لكنها بلا أقسام داخلية، والمراكز موزّعةٌ عليها بازدواج.
- لا «بيتٌ أساسيٌّ واحد» لكثيرٍ من الوجهات (assets/impact/innov…).
- الجوال يرى طبقةً واحدة من خمس.
- لا مصدرَ IA واحد يوحّد: الشريط + البحث + الخريطة + الجوال. `config/hub_ia.php` و`InformationArchitecture` **غير موجودَين** — هذا ما يُبنى في P2.
