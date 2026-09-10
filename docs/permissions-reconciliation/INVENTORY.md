# INVENTORY — جردُ معمارِ الصلاحيّات (مسنودٌ إلى الكود)

> جردٌ من الكود لا تخمين (§4). كلُّ بندٍ مسنودٌ إلى `file:line`. نقطةُ الانطلاق: HEAD
> `406ec11` / v2.474.1.

## 1. محرّكُ التوثيق الواحد

| المكوّن | الموقع | الدور |
|---|---|---|
| `Role.matrix` | `Role.php` (cast array) | `{module: {v/a/e/d: 1}}` — صلاحيّةُ CRUD لكلِّ وحدة |
| `Role.flags` | `Role.php` (cast array) | `{flag: 1}` — قدراتٌ خاصّةٌ (محورٌ منفصلٌ عن المصفوفة) |
| `Role.field_rules` | `Role.php` (cast array) | `{module: {field: 'ro'\|'hide'}}` — قيودُ الحقول |
| `Role.is_owner` | `Role.php` (bool) | المالكُ يتجاوز كلَّ شيء |
| `Role.scope` | `Role.php` (`all`\|`proj`) | نطاقُ المشروع |
| `hub_can($u,$module,$op)` | `helpers.php:286` | المالك⇒true؛ وإلّا `matrix[$module][$op]` — لأيِّ مفتاح |
| `User::can2($module,$op)` | `User.php:76` | توأمُ hub_can (نفسُ الدلالة) |
| `hub_flag($u,$key)` | `helpers.php:896` | المالك⇒true؛ وإلّا `flags[$key]` |
| `hub_scope($q,$module,$u)` | `helpers.php:141` | حقنُ عزلِ المشروع + الشركة + العميل على الاستعلام |
| `hub_field_mode($u,$module,$field)` | `helpers.php:1661` | `''`\|`'ro'`\|`'hide'` — locked⇒ro للجميع، المالك⇒'' |
| `PortalGuard::MODULE_ALLOW` | `PortalGuard.php` (public const) | `['engagements','projects','fin']` — حدُّ حساب العميل |

**نموذجٌ فعّالٌ واحد:** الوصولُ الفعّال = توفّرُ القدرة (Feature) ∧ حدُّ نوعِ الحساب ∧ صلاحيّةُ
الدور (matrix) ∧ رايةٌ خاصّةٌ إن لزم ∧ نطاقُ الشركة ∧ نطاقُ السجلّ ∧ قواعدُ الحقل. **لا محرّكَ
توثيقٍ ثانٍ.**

## 2. الوحدات (85) والاكتشاف

- المجموع: **85** وحدةً في `config('hub.modules')`.
- في الشريط الجانبيّ (`config/hub_nav.php`): **80**. خارجَه: `stations, endpoints, autos, users,
  restores`.
- لها وجهةٌ في IA (`config/hub_ia.php` — module/center/workspace): **84**. الوحيدةُ بلا وجهة:
  `autos` (مؤرشفةٌ صراحةً — DEPRECATED_CONFIRMED، خليفتُها `flows`، `hub_ia.php:513`).
- `endpoints` وجهتُها **مركزٌ** (`type=center`, `endpoints.index`, `hub_ia.php:317`) لا وحدةَ CRUD.
- `stations`/`restores` لهما وجهةُ IA (مركز/إداريّ).
- `users` مستثناةٌ من المصفوفة قصداً: يحرسها **علَمُ `users`** لا المصفوفة (`RoleController.php:64`؛
  `ModuleController::resolve` يردّ ٤٠٤ على `users`).

**النتيجة:** لا وحدةٌ إنسانيّةٌ «تُمنَح ولا تُكتشَف» — كلُّ وحدةٍ فعّالةٍ لها وجهةٌ قانونيّة.

## 3. محرّكُ الشريط الجانبيّ

- `hub_nav($u)` (`helpers.php:324`): يمرّ على مجموعات `config('hub_nav')`، ويُظهر كلَّ بندٍ إن
  `hub_mod($k) && hub_can($u,$k,'v')` وليس في تفضيلِ `nav.hidden` الشخصيّ.
- الشريطُ «الفضائيّ» (spaces، الافتراضيّ، `sidebar.blade.php`) يُبنى من `IA::visibleDomains/Sections`
  + `Workspaces::for` — كلُّها تفوّض إلى `hub_can(...,'v')`.
- **تفضيلُ `nav.hidden`** (`helpers.php:335`) يخفي بندَ الوضع الكلاسيكيّ **شخصيّاً** دون أن يمسّ
  `hub_can` — فالوحدةُ تبقى مُكتشَفةً عبر البحث/الرابط المباشر/الشريط الفضائيّ. تخصيصٌ لا صلاحيّة.

## 4. محرّرُ الأدوار (canonical)

- `RoleController` (owner-only، `:31`). `groupedModules()` (`:40`) يبني قائمةَ الوحدات من
  `config('hub_nav')` + التقاطِ الباقي في «أخرى» — فكلُّ وحدةٍ (عدا `users`) تظهر.
- `data()` (`:298`) يحفظ **كلَّ** مفاتيح `hub_modules()` (`:311`)، ويفرض «الكتابة تستلزم العرض»
  (`v` يُضبَط تلقائيّاً مع أيِّ عمليّة، `:318`).
- تدقيقٌ لكلِّ تغيير (`trail`/`changeSummary`، `:123`/`:170`) بفرقٍ محدود (`وحدة+ apps (v)`).
- تصعيدُ مصادقةٍ عند منحِ رايةٍ خطرة أو توسيعِ النطاق (`:230`). استنساخُ دورٍ (`clone`, `:241`).

## 5. الرايات (8) — كلُّها قابلةُ الإدارة

`RoleController::FLAGS` (`:13`): `users, audit, approve, monitor, secrets, copySec, exp, mobile`.
كلٌّ منها له مستهلكٌ فعّالٌ ومربّعُ ضبطٍ في محرّر الأدوار. لا رايةٌ يتيمةٌ ولا رايةٌ بلا ضبط.
الخطرةُ (`RISKY_FLAGS`, `:27`): `users, secrets, copySec, exp, audit, mobile`.

## 6. قواعدُ الحقول

`field_rules = {module: {field: 'ro'|'hide'}}`. القارئُ الوحيد `hub_field_mode`. حقولُ `locked`
في تعريفِ الوحدة تُقفَل للجميع (حتى المالك). المستهلكون: تشكيلُ ردِّ API (`V1Controller`)، عرضُ
الوحدة (`ModuleController`)، جوّال، عروضُ الأسعار (كلفة/حالة)، HR (راتب/إقامة/أداء)، ماليّة، مشتريات،
أصول (سعر)، عهدة. **قواعدُ الحقلِ منفصلةٌ عن رؤيةِ الوحدة.**

## 7. سطوحُ API

- `/api/v1/{module}`: `resolveApi()` (`V1Controller.php:355`) — 404 على `users`؛ 404 للعميل خارج
  `MODULE_ALLOW`؛ ثمّ `hub_can($u,$module,$op)` (GET→v، POST→a، PUT/PATCH→e، DELETE→d)؛ ثمّ نطاقُ الرمز.
- `/api/mobile/v1/{module}`: `MobileResourceController extends V1Controller` — نفسُ `resolveApi`/`hub_can`.
- **تكافؤ:** apps وprojects يُوثَّقان بنفسِ المسار على السطحين للداخليّ. الفرقُ الوحيد: `projects ∈
  MODULE_ALLOW` (يبلغه العميلُ مُنطَّقاً صفّيّاً) و`apps ∉` (داخليٌّ فقط، ٤٠٤ للعميل) — عزلٌ مقصود.

## 8. سجلُّ القدرات ليس بوّابةَ صلاحيّة

`FeatureRegistry`/`hub_capability` يقرؤه ٣ مواضعُ فقط، كلُّها قدرتا `collab.presence`/`collab.typing`
القابلتان للتبديل. لا رؤيةَ وحدةٍ تعتمد حالةَ سجلِّ القدرات. حقلُ `permissions` في `hub_features.php`
توثيقٌ خامل. **سجلُّ القدرات ≠ صلاحيّةُ الدور.**

## 9. العيبُ المُبلَّغ (Projects تُرى، Applications لا) — الجذرُ المُثبَت (§6)

**لا انحرافَ في الكود.** المفتاحُ القانونيُّ لـ«التطبيقات» هو **`apps`** (لا `applications`؛
label التطبيقات، model Application، table applications). أُثبِت تجريبيّاً: دورٌ يمنح
`apps.v=1` و`projects.v=1` لموظفٍ داخليّ ⇒ `hub_can apps.v = true`، وapps **يظهر** في `hub_nav`
وفي بحث IA تماماً كـprojects، و`apps.a=false` يمنع الإضافةَ بحقّ. فالسلوكُ صحيحٌ ومتماثل.

السببُ الحقيقيُّ للعرَض المُبلَّغ أحدُ سلوكين صحيحين لم يملك المالكُ أداةً للتمييز بينهما:
1. **دورُ الموظفِ لم يمنح `apps.v` فعلاً** (قالبٌ/تحديدٌ جزئيٌّ منح projects لا apps)، أو
2. **الحسابُ عميلٌ** (`account_type=client`): `projects ∈ MODULE_ALLOW` فيُرى في البوّابة، و`apps ∉`
   فيُردُّ ٤٠٤ — مهما كانت المصفوفة (حدُّ نوعِ الحساب يعلو).

**فالعيبُ الجوهريّ = غيابُ مُفسِّرِ الصلاحيّة الفعّالة** (§12/§22/§59)، لا مسارُ تنقّلٍ مكسور.
العلاجُ إضافةُ طبقةِ تفسيرٍ (PermissionInspector) + شاشةِ تشخيصٍ للمالك + اختباراتِ مطابقةٍ بنيويّة
تُثبّت تماثلَ apps==projects، لا تغييرُ سلوكِ apps (فهو صحيح) ولا منحُ الجميعِ كلَّ شيء (§116).
