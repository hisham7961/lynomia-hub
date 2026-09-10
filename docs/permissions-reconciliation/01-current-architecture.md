# 01 — المعمارُ القائم (محرّكُ توثيقٍ واحد)

## المحاور

| المحور | المصدر | الدلالة |
|---|---|---|
| **صلاحيّةُ CRUD** | `Role.matrix = {module:{v,a,e,d}}` | ما يراه/يضيفه/يعدّله/يحذفه الدور |
| **قدراتٌ خاصّة** | `Role.flags = {flag:1}` | محورٌ منفصلٌ (users/audit/approve/monitor/secrets/copySec/exp/mobile) |
| **قيودُ الحقول** | `Role.field_rules = {module:{field:'ro'|'hide'}}` | إخفاء/تجميدُ حقلٍ داخلَ وحدةٍ مرئيّة |
| **الملكيّة** | `Role.is_owner` | تجاوزٌ كامل |
| **نطاقُ المشروع** | `Role.scope ∈ {all,proj}` | كلُّ المشاريع أو المُسنَدة |
| **نطاقُ الشركة/العميل** | `users.companies` / `users.clients` | عزلٌ صفّيّ (null = لا قيد) |
| **نوعُ الحساب** | `users.account_type` + `PortalGuard::MODULE_ALLOW` | حدُّ العميلِ يعلو على المصفوفة |

## نقاطُ القرار (واحدةٌ لكلِّ محور — لا ازدواج)

- `hub_can($u,$module,$op)` — المالك⇒true؛ وإلّا `matrix[$module][$op]`. يعمل لأيِّ مفتاح.
- `User::can2($module,$op)` — توأمٌ (نفسُ الدلالة).
- `hub_flag($u,$key)` — المالك⇒true؛ وإلّا `flags[$key]`.
- `hub_scope($q,$module)` — يحقن عزلَ المشروع + الشركة + العميل.
- `hub_field_mode($u,$module,$field)` — `''`/`'ro'`/`'hide'`؛ حقولُ `locked` تُقفَل للجميع.
- `PortalGuard`/`MobilePortalGuard` — يحصر حسابَ العميل في `MODULE_ALLOW` (٤٠٤ لغيره).

## نقاطُ الفرض (كلُّها تفوّض إلى الأعلى — لا نظامَ ثانٍ)

- **الويب:** `ModuleController` + الحرّاسُ الخاصّةُ للمراكز → `hub_can`/`hub_flag`.
- **الشريط الجانبيّ:** `hub_nav` (وضعٌ كلاسيكيّ) + `IA::visibleDomains/Sections` (وضعٌ فضائيّ) →
  `hub_can(...,'v')`.
- **البحث ولوحةُ الأوامر:** `SearchController` + `IA::searchDestinations` → `hub_can`.
- **خريطةُ النظام:** `IA::systemMap` → `hub_can`.
- **API:** `/api/v1` و`/api/mobile/v1` عبر `V1Controller::resolveApi` → `hub_can` (GET→v،
  POST→a، PUT/PATCH→e، DELETE→d) بعد حدِّ العميل.

## سجلُّ القدرات ≠ صلاحيّةُ الدور

`FeatureRegistry` يقول «هل القدرةُ موجودةٌ في المنتَج»، لا «هل يراها هذا الموظف». يُقرأ في ٣ مواضعَ
فقط (قدرتا `collab.presence`/`collab.typing` القابلتان للتبديل). **لا رؤيةَ وحدةٍ تعتمده.**

## لا محرّكٌ ثانٍ (§3 مُحقَّق)

- محرّكُ RBAC واحد، جدولُ أدوارٍ واحد، جدولُ صلاحيّاتٍ = عمودُ JSON واحد.
- لا مصفوفةَ صلاحيّةِ تنقّلٍ ثانية، لا سجلَّ قدراتٍ ثانٍ، **لا قوائمَ أدوارٍ مثبّتةٍ في الشاشات**
  (تأكّد: القرارُ الوحيدُ باسمِ الدور هو مسؤولُ الرقابة، مُشتقٌّ من إعدادٍ لا اسمٍ ثابت).
