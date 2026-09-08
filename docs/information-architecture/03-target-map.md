# 03 — الخريطة المستهدفة (Target Information Architecture)

> **DISCOVERY — تصميمٌ لا تنفيذ.** head `v2.429.0`. صفرُ فقدان ميزة، لا إعادة تصميم بصري، لا سجل وحداتٍ ثانٍ. كل مجالٍ/قسم/وجهةٍ مُسنَدةٌ لوحدةٍ أو مركزٍ حقيقيّ. **بيوتٌ أساسيّةٌ مكرّرة = 0** — لكل وحدةٍ ومركزٍ بيتٌ أساسيٌّ واحد؛ الوصولُ الثانوي (مثبّتات/بحث/علاقات/سياق كيان) مذكورٌ منفصلاً ولا يغيّر البيت.

## المبدأ: المساحات الثماني هي طبقة المجالات — نُضيف الأقسام لا نعيد البناء

النموذج المستهدف = **سطحان عامّان** (الرئيسية · مهامّي) فوق **٩ مجالات** (٨ مجالات عمل = مساحات hub_workspaces الثماني حرفياً + مجالٌ للنظام). العمقُ الدلاليّ الأقصى = 4 مستويات: مجال → قسم → وجهة → سجلّ. **العمل مفصولٌ عن النظام**: المجالات 1‑8 عمل، المجال 9 نظام (وهو أصلاً في شريط الترس فيزيائياً).

مستويات الأهمية (تنقّلٌ فقط، لا صلاحية): **PRIMARY** يومي · **SECONDARY** مستوى المساحة · **ADVANCED** عبر القسم/البحث/خريطة النظام (متاحٌ دوماً، لا يُخفى بتكرار الاستخدام).

---

## سطوحٌ عامّة فوق المجالات

### 🏠 الرئيسية (Home) — GLOBAL
| قسم | وجهات (route) | أهمية | ملاحظة |
|---|---|---|---|
| لوحة التحكم | dashboard | PRIMARY | لوحة widgets — بلا تغيير بصري |
| البحث وخريطة النظام | search · search.mini · **system-map (جديد P6)** | PRIMARY | البحث الموحّد + «خريطة النظام» |
| لوحات تنفيذية | ceo (owner) · kpis (monitor) · recs (monitor) | ADVANCED | تحليلاتٌ شاملةٌ عبر الوحدات — حارسٌ owner/monitor كما هو |

### ✅ مهامّي (My Work) — USER_PERSONAL
> يجمع دوالّ المستخدم الحالي بإعادة استعمال النقاط القائمة (لا بيانات وهمية، لا منطق مكرّر). عناصرُ العمل المُسنَدة «لي» **وصولٌ شخصيٌّ ثانوي** لوحداتٍ بيتُها الأساسيّ في مجال «العمل» — لا بيتٌ مكرّر.
| قسم | وجهات | أهمية |
|---|---|---|
| يومي | morning · alerts (رادار /alerts) · calendar | PRIMARY |
| مهامّي وموافقاتي | tasks*·approvals*·issues*·requests* (منظورٌ «لي») · boards | PRIMARY |
| رسائلي | dm.inbox · dm.thread · conversations · feed | PRIMARY |
| صندوقي | inboxdocs · notifications(.index/.mini/.count/.go) | PRIMARY |
| حسابي | portal.me · profile.edit · prefs.edit · mysec.index · stepup | SECONDARY |

`*` = منظورٌ مُنطَّقٌ للمستخدم على وحدةٍ بيتُها «العمل» (وصولٌ ثانوي).

---

## المجالات التسعة

### 1) 🏢 الكيانات والعلاقات (Entities & Relationships) — مساحة `entities`
| قسم | وحدات/مراكز (key) | أهمية |
|---|---|---|
| الشركات والمشاريع | companies · projects | PRIMARY |
| العملاء والـCRM | clients · engagements · **sales.dashboard**(center) · journey(ctx) | PRIMARY (sales: SECONDARY) |
| العروض والعلامات | services · brands · competitors | SECONDARY |
| الأثر والعلاقات | **impact**(center) · **graph.explore/expand**(center) | ADVANCED |
- بيوتٌ أساسيّة هنا: modules companies/projects/clients/engagements/services/brands/competitors · centers sales·impact·graph · entity journey.
- وصولٌ ثانوي: impact يظهر أيضاً في مساحة digital (سياق)؛ appsprojects (بيته Technology) يُربط من هنا بالعلاقة.

### 2) 🗂️ العمل والتسليم (Work & Delivery) — مساحة `work`
| قسم | وحدات/مراكز | أهمية |
|---|---|---|
| المهام والتنفيذ | tasks · updates · designs · **boards**(center) | PRIMARY |
| التذاكر والدعم | tickets · **support**(center) | PRIMARY |
| الاجتماعات والقرارات | meetings · decisions · approvals | SECONDARY |
| الأهداف والخطة | okrs · krs · **okrs.board**(center) · feats · **delivery**(center) | PRIMARY (delivery: SECONDARY) |
| الطلبات والإشراف | requests · **oversight**(center) | SECONDARY (oversight: ADVANCED) |
- بيوتٌ أساسيّة: modules tasks/updates/designs/tickets/meetings/decisions/approvals/okrs/krs/feats/requests · centers boards·support·okrs.board·delivery·oversight.
- وصولٌ ثانوي: innovation (بيته Knowledge) يُربط من قسم المهام؛ approvals لها منظورٌ في «مهامّي».

### 3) 💰 المالية والمشتريات (Finance & Procurement) — مساحة `finance`
| قسم | وحدات/مراكز | أهمية |
|---|---|---|
| الفواتير والمحاسبة | fin · entries · accounts2 · banks | PRIMARY (fin) |
| العروض وأوامر التغيير | quotes · changeorders | PRIMARY |
| الميزانيات والتكاليف | budgets · costc · recur · subs · **costs**(center) · **servicecosts**(center) | PRIMARY (budgets) |
| المشتريات والموردون | suppliers · purchases · **supplierscores**(center) | PRIMARY |
| التقارير والتسعير | **reports.finance**(center) · **pricing**(center) | SECONDARY |
- بيوتٌ أساسيّة: modules fin/entries/accounts2/banks/quotes/changeorders/budgets/costc/recur/subs/suppliers/purchases · centers costs·servicecosts·supplierscores·reports.finance·pricing.
- **ملاحظة تطبيع:** مركز `pricing` بيتُه Finance (تجاري)؛ وحدة `plans` (بياناته) بيتُها Knowledge — كائنان مختلفان، بلا ازدواج بيت.

### 4) 👥 الموظفون والموارد البشرية (People) — مساحة `hr`
| قسم | وحدات/مراكز | أهمية |
|---|---|---|
| ملفات الموظفين | hr · hrlog · skills · **team**(center) · **staff**(center) | PRIMARY (hr) |
| الحضور والإجازات | attend · leaves | PRIMARY |
| الرواتب والتوظيف | payroll · recruit | SECONDARY |
| القوى والأداء | **workforce.team**(center) · **workforce.overview**(center) · **capacity**(center) · **performance**(center) · **custody-wallet**(center) | SECONDARY (analytics: ADVANCED) |
- بيوتٌ أساسيّة: modules hr/hrlog/skills/attend/leaves/payroll/recruit · centers team·staff·workforce.team·workforce.overview·capacity·performance·custody.wallet.
- وصولٌ ثانوي: portal.employee(ctx) = ملف موظف من هنا.

### 5) 💠 التقنية والبنية الرقمية (Technology) — مساحة `digital` (+ يتيمان)
| قسم | وحدات/مراكز | أهمية |
|---|---|---|
| التطبيقات والكود | apps · code · deploys · deps · changes · **apps.center**(ctx) · **code.center**(center) · **appsprojects**(center) · **appquality**(center) | PRIMARY (apps/code) |
| البنية التحتية | servers · domains · websites · dbs · **endpoints**(orphan→home) · endpoints.releases · **stations**(orphan→home) | PRIMARY (servers) |
| الحسابات والاتصالات | accounts · emails · phones · carriers · vault | SECONDARY |
| التكاملات والحوادث | apis · incidents | SECONDARY |
| السوشال والرقمنة | social · posts · **social.index**(center) · **digital.assets**(center) | SECONDARY |
- بيوتٌ أساسيّة: modules apps/code/deploys/deps/changes/servers/domains/websites/dbs/accounts/emails/phones/carriers/vault/apis/incidents/social/posts + orphans **endpoints, stations** · centers code.center·appsprojects·appquality·social.index·digital.assets · entity apps.center · odoo.project(ctx).
- وصولٌ ثانوي: incidents يظهر في كتالوج التشغيل الإداري (منظورٌ ثانوي، بيته هنا)؛ impact/appsprojects مُربطان بالعلاقة.

### 6) 📜 الأصول والعقود والامتثال (Assets, Contracts & Compliance) — مساحة `legalws`
| قسم | وحدات/مراكز | أهمية |
|---|---|---|
| الأصول والعهد | assets · assetlog · **custody**(center+cat/label/spec/permit) · **assets.life**(center) · **identity**(center+labels/resolve/product) | PRIMARY (assets) |
| المخزون والمنتجات | products · stock · stockmv · **inventory**(center+show) | PRIMARY (products/stock) |
| العقود والتوقيع | contracts · obligations · **legal**(center) · **esign**(center+edit/tpl/doc/pdf/cert) | PRIMARY (contracts) |
| الملكية والامتثال | ip · compliance · **compliance.board**(center) | PRIMARY |
- بيوتٌ أساسيّة: modules assets/assetlog/products/stock/stockmv/contracts/obligations/ip/compliance · centers custody·assets.life·identity·inventory·legal·esign·compliance.board.
- وصولٌ ثانوي/عام: custody.code(c/{code})·products.code(p/{code}) مداخلُ QR عامّة تشير إلى وجهاتِ هذا المجال.

### 7) 🧭 العمليات الميدانية (Field Operations) — مساحة `fieldops`
| قسم | وحدات/مراكز | أهمية |
|---|---|---|
| الشبكة الميدانية | hcps · facilities | PRIMARY |
| المناطق والتغطية | territories · terrassigns | PRIMARY |
| الدورات والزيارات | cycles · visits · **field**(center: dashboard/route/sessions) | PRIMARY |
- بيوتٌ أساسيّة: modules hcps/facilities/territories/terrassigns/cycles/visits · center field.

### 8) 📚 المعرفة والمستندات (Knowledge & Documents) — مساحة `knowledge`
| قسم | وحدات/مراكز | أهمية |
|---|---|---|
| قاعدة المعرفة | kb · ideas · **innovation**(center) | PRIMARY (kb) |
| الملفات والمستندات | files | PRIMARY |
| السياسات والإقرارات | policies · policyacks · **policies.board**(center) | PRIMARY |
| الإعلام والفعاليات | media · events · **media.center**(center) | PRIMARY |
| التنبيهات والباقات | rules · plans | SECONDARY |
- بيوتٌ أساسيّة: modules kb/ideas/files/policies/policyacks/media/events/rules/plans · centers innovation·policies.board·media.center.
- وصولٌ ثانوي: inboxdocs (بيته «مهامّي») يُربط من الملفات؛ innovation منظورٌ في «العمل»؛ pricing (بيته Finance) بيانتُه plans هنا.

### 9) ⚙️ الإدارة والنظام (Administration & System) — سطح النظام (شريط الترس)
> **مفصولٌ عن العمل.** يظهر فقط لمن يملكه (owner/رايات) — بلا تسريب. حارسُ كل وجهةٍ يبقى في `gate()` بالمتحكّم.
| قسم | وجهات (route) | أهمية |
|---|---|---|
| الأمن والرقابة | audit(+coverage/show) · security(+13 مساراً) · activity(+show) · dataroom | PRIMARY (audit/security) |
| التشغيل والمراقبة | control · ops(+health/runbooks) · errors(+logs/show) · alerts.center · **restores**(orphan→home) · system.trace(owner) | PRIMARY (control/ops) |
| الجودة والحوكمة | quality · fields · flows(+edit/sandbox) | PRIMARY |
| الإعدادات والتكاملات | settings(+export) · integrations(+guide/hooks/messaging/n8n/odoo) · webhooks(+log) · quoteflow | PRIMARY (settings) |
| المستخدمون والوصول | **users**(orphan→home, ADMIN_ONLY)(+create/edit) · roles(+create/edit) | PRIMARY |
- بيوتٌ أساسيّة: كل `hub_admin_links` + orphans users/restores.
- **DEPRECATED_CONFIRMED:** `autos` (label «مؤرشفة — انظر مسارات العمل») — لا بيتَ تنقّل؛ خليفتُه **flows** في «الجودة والحوكمة». المسار m.index[autos] يبقى يعمل (صفر فقدان).
- وصولٌ ثانوي: incidents (بيته Technology) وalerts.center مُتاحان هنا كمنظور تشغيلي؛ prefs.edit (بيته «مهامّي») يظهر في البحث من كتالوج الإدارة.

---

## أسطحٌ خارج مجالات العمل التسعة (جمهورٌ/طبيعةٌ مختلفة — تُذكر لا تُدمج)
- **بوّابة العميل (PORTAL, 10):** portal.home/conversations/documents/invoices/projects/engagements(+ show). جمهور `account_type=client` عبر PortalGuard. سطحٌ مستقلٌّ تماماً، لا يدخل الشريط الداخلي ولا خريطة النظام الداخلية.
- **مداخل عامّة (PUBLIC, 14):** login/otp · activate · sign.*/verify.* · share.* · c/{code}·s/{code}·p/{code}. قبل الدخول أو رمزيّة — لا بيتَ تنقّل داخلي.
- **بنية تحتية (SYSTEM, 9):** up·healthz·pwa.*·well-known.* — نقاطٌ آليّة بلا تنقّل. system.trace/trace = تشخيصٌ سياقيّ (system.trace بيته Administration/التشغيل؛ trace سياقيٌّ على صفحة السجل، لا بيت تنقّل).
- **مولّدات (UTILITY, 9):** att.*/file.show/*.pdf/*.doc/quotes.diff — تُولَّد من السجلات، سياقيّةٌ لا تنقّليّة (تظهر في صفحة الوحدة/المركز التي تخصّها).

## جرد الإسناد — تغطيةٌ كاملة
- **الوحدات 85 → بيتٌ أساسيٌّ واحد:** 80 عبر مساحتها/مجالها + 5 أيتام مُسنَدة (users→Admin, restores→Admin, endpoints→Technology, stations→Technology, autos→DEPRECATED بلا بيت تنقّل مع بقاء المسار). **يتيمٌ بلا حسم = 0.**
- **المراكز (hub_top_links 40 + مستقلّة) → بيتٌ أساسيٌّ واحد لكلٍّ.** المراكزُ الثنائيةُ المساحة (impact/innov/appsproj/inboxdocs/social/pricing) حُسِم بيتُها الأساسيُّ أعلاه ووصولُها الثانويُّ مذكور. **بيوتٌ مكرّرة = 0.**
- **المجالات = 9** (8 عمل = مساحات + 1 نظام) + سطحان عامّان (الرئيسية/مهامّي). ضمن نطاق «6‑9 مجالات».
- **العمق ≤ 4** مستويات في كل فرع.

## علاقة المصادر (للـP2)
`config/hub_ia.php` الجديد **يشير** لمفاتيح hub.php ولا يكرّرها؛ `hub_nav.php`/`hub_workspaces.php` يصيران محوّلَين (adapters) مُشتقَّين منه (يُبقيان مؤقتاً للتوافق). المراكزُ (`hub_top_links`) والإدارةُ (`hub_admin_links`) تُلفّان في السجل بعلاقة `center-relation` مع بقاء حرّاسها `ok`. لا يمنح IA صلاحيةً قطّ.
