# جرد المشروع 360 (INVENTORY) — دليلٌ موثَّق قبل التنفيذ

> **تجميعٌ لا تكرار.** المشروعُ 360 موجودٌ سلفاً كسطحٍ ذي تبويبات؛ وربطُ الأصلِ بالمشروع هو
> القدرةُ المؤجَّلةُ الوحيدةُ الغائبة. هذا الجردُ يوثّق ما يُعاد استعمالُه بأدلّةٍ ملفٍّ:سطر.

## 1) المشروعُ 360 القائم — يُرقّى لا يُبنى

- **السطح:** `resources/views/modules/custom/projects.blade.php` — «مركزُ قيادةِ المشروع»: قشرةُ تبويبات
  (النظرة · التسليم · الأساس التجاري · الغرف · المالية · النشاط)، يُبلَغ عبر `route('m.show',['projects',id])`.
  العميلُ يرى «النظرة» الآمنةَ وحدَها (`hub_is_client` · redaction صلبة).
- **مغذّي المتحكّم:** `ModuleController::show()` يمرّر `$row,$def,$children (hub_related),$timeline (hub_timeline),$comments,…` (`app/Http/Controllers/Web/ModuleController.php:342-387`).
- **لا وحدةَ ProjectController خاصّة** — المشروعُ وحدةٌ عامّة (`m.show`).

## 2) محرّكاتُ القراءة المُعادُ استعمالُها (لا محرّكَ ثانٍ)

| القدرة | المصدر | ملاحظة |
|---|---|---|
| السجلّاتُ المرتبطة (مهام/قضايا/حوادث/تغييرات/موافقات/ملفّات/خوادم/نطاقات/قواعد/هواتف) | `hub_related('projects',$id)` (`helpers.php:3197`) | كلٌّ عبر عمود `project_id` حقيقيّ، مُرشَّحٌ audience وclient |
| صحّةُ التسليم | `hub_project_health($id)` | ٦ عوامل موزونة |
| ربحيّةُ المشروع (P&L) | `hub_project_pl($id,$fresh)` (`helpers.php:2193`) | إيراد/تكلفة/ربح/هامش/ميزانية — لا دفترَ ثانٍ |
| خطُّ الأساس التجاريّ | `$project->meta['baseline']` | من عرضٍ مقبول |
| الخطوة التالية | `App\Support\NextAction::for('projects',$row)` | — |
| الخطُّ الزمنيّ | `hub_timeline('projects',$id)` (`helpers.php:2555`) | تدقيق+تعليقات+مرفقات+نسخ |
| الغرفتان | `ConversationController::ensureProjectRooms($project)` (`ConversationController.php:108-148`) | idempotent · `['internal','client']` |
| المستكشف | رابطٌ قائمٌ في ترويسة العرض `graph.explore?m=projects&id=…` (`modules/show.blade.php:47`، محجوبٌ عن العميل) | `RelationshipProjection::expand` |

## 3) الصلاحية والعزل (تبقى كما هي)

- عضويّةُ المشروع: `manager_id` أو مصفوفة `members` (JSON) — `User::visibleProjectIds()` (كاش `user:{id}:projects`).
- `hub_scope(q,'projects')`: عضويّة + عزلُ الشركة (`hub_company_ids`) + عزلُ العميل (`hub_client_ids`، fail-closed).
- `hub_can('projects',…)` يحرس كلَّ سطح؛ العميلُ يرى مشروعَه فقط عبر `ClientPortalData` (أعمدةٌ عميليّةٌ فقط، بلا cost/budget/rev).
- **جمهورُ المشروع:** `internal`/`client` (`Project::isExternal()` = `client_id!==null || audience==='client'`). الغرفتان منفصلتان فيزيائيّاً (لا علامةُ رسالة).

## 4) ربطُ الأصلِ بالمشروع — الحالةُ الراهنة (الفجوة)

- **`assets.project_assignment` = DEFERRED** (`config/hub_features.php:140`): «غيرُ first-class للأصول الماديّة».
- يوجد **حقلُ ref بسيط** `assets.project_id` (`config/hub.php:4981-4987`) — مؤشّرٌ مفردٌ قابلُ التعديل، بلا تاريخ، يوحي بمشروعٍ واحد، ويخلط التخصيصَ بالموضعِ الماديّ. **§16 يمنع الاعتماد عليه** للتخصيص.
- **العهدةُ منفصلة:** `assets.holder_id`/`station_id` + `asset_custody` (holder history) + `station_assignments` (seat history). التخصيصُ للمشروعِ **ليس** عهدةً.
- **لا جدولَ تخصيصٍ زمنيّ للأصل↔المشروع.** ⇒ هذه هي القدرةُ التي يبنيها هذا الطور.

## 5) محرّكُ العلاقات (نقطةُ التوسعة)

- `RelationshipProjection` (`app/Support/RelationshipProjection.php`) **مدفوعٌ بالإعداد**: لا قائمةَ حوافٍّ ثابتة —
  الحوافُّ حقولُ `type=ref` في `config/hub.php`، والعكسيّةُ من `hub_children` (`helpers.php:1107-1149`). كلُّ عقدةٍ
  تمرّ بـ`hub_read` (تفويضٌ تلقائيّ — لا رافدٌ ثانٍ). الجذرُ من `?m=&id=` (`RelationshipExplorerController::target`).
- **تعليمُ الحافّةِ الجديدة (§40):** توسعةٌ صغيرةٌ موجّهةٌ في `neighbors` لتُضيف جيرانَ «التخصيصِ النشط»
  من `asset_project_assignments`، مُصرَّحةً بـ`hub_read('projects')`/`hub_read('assets')` — **لا مخزنَ رسمٍ ثانٍ**.

## 6) الملفّات/الموارد/المالية

- الملفّات: وحدةُ `documents` بعمود `project_id` + **`audience` (`internal|client|both`) + `client_id`** و`scopeVisibleToClient` — الوحيدةُ ذاتُ رؤيةِ عميلٍ على مستوى السجلّ.
- المواردُ التقنيّة: `servers`/`domains`/`databases_reg`(dbs)/`phone_numbers`(phones) كلٌّ بعمود `project_id` — تظهر سلفاً كأبناءٍ في `hub_related`.
- **المخرجات (Deliverables): لا دومين.** لا جدولَ ولا نموذج — فقط حقلُ نصٍّ حرٍّ `deliverables` على وحدة `services`. يُوثَّق بصدق، لا يُختلَق.

## 7) API والجوال

- REST عامّ: `/api/v1/{module}` (projects/assets وحدتان). تقاريرُ مشروعٍ: `/api/v1/reports/progress/{projectId}` · `/health`.
- بوّابةُ العميلِ للجوال: `/api/mobile/v1/portal/projects[/{id}]` عبر `ClientPortalData` نفسِه.
- **التخصيصُ الجديد يُعرَض عبر REST قائمٍ** بخدمةٍ مشتركةٍ (لا منطقَ مكرَّر · §56). الجوّالُ الأصيلُ يبقى مؤجَّلاً.

## 8) IA/البحث

- `projects` وجهةٌ وحدةٍ أساسيّةٌ في `hub_ia` (مجال entities · «الشركات والمشاريع»). لا وجهةَ 360 منفصلة — الـ360 هو `m.show/projects`.
- البحثُ عامٌّ (`SearchController::searchableModules`) — لا خصوصيّةَ مشروعٍ. الأصولُ كذلك.

## 9) ما يبنيه هذا الطور (الفجوةُ الحقيقيّة فقط)

1. **`asset_project_assignments`** (تاريخٌ زمنيّ · نشطٌ فريدٌ عبر المحرّكين · مستقلٌّ عن العهدة) + نموذج + `AssetProjectService` (إنشاء/إنهاء · idempotent · قفلٌ للتزامن · تدقيق).
2. متحكّم + مسارات ويب + API (تخصيصٌ من جهتَي المشروعِ والأصل) — بخدمةٍ واحدة.
3. **قسمُ الأصول** في المشروع 360 + **قسمُ المشاريع** في تفصيل الأصل (نشط + تاريخ + تخصيص/إنهاء).
4. حافّةُ الأصل↔المشروع في `RelationshipProjection` (مصرَّحةٌ الطرفين).
5. رفعُ `assets.project_assignment` من DEFERRED إلى الحالةِ الصادقة.
6. لمساتٌ في المشروع 360: روابطُ «افتح الغرفةَ في /collab» (§37)، وعدّاداتٌ واقعيّة، وحالاتٌ فارغةٌ بأفعال.

> كلُّ ما عدا ذلك (العمل/الملفّات/التقنية/المالية/النشاط/العلاقات/الغرف) **قائمٌ ويُعاد استعمالُه** — لا يُكرَّر.
