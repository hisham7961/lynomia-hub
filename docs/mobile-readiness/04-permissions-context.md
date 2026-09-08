# 04 · الصلاحيّاتُ والسياق

> Mobile Readiness · الطور H · H.1. كيف يُعيد سطحُ الجوال استعمالَ محرّكات الصلاحية
> والعزل والقناع دون فرعٍ ثانٍ، وكيف يعمل سياقُ العرض عديمُ الحالة (narrow-never-widen).
> **مبنيٌّ على الشجرة الحقيقيّة.** القاعدة (spec §Headers · §Security): الترويسةُ
> **تُضيّق ولا تُوسِّع**، والعميلُ **غيرُ موثوق**.

## 1. المحرّكاتُ الثلاثةُ المُعادة — تعمل على `auth()->user()` لا الجلسة

كلُّها في `app/Support/helpers.php`، تُشتقّ الهويّةَ من المستخدم لا من الجلسة —
فهي **مهيّأةٌ للجوال أصلاً**؛ الجوالُ قارئٌ ثالثٌ لها لا محرّكٌ رابع:

| المحرّك | التوقيع | الدور |
|---|---|---|
| `hub_can` | `hub_can($user, $module, $op = 'v')` (`helpers.php:288`) | الصلاحية: المالكُ يُختصر؛ وإلا `role.matrix[module][op]`، الأوبُ `{v,a,e,d}` |
| `hub_scope` | `hub_scope($q, $module, $user = null)` (`helpers.php:141`) | العزلُ الصلب: ثلاثُ `whereIn` (مشروع/شركة/عميل) |
| `hub_field_mode` | `hub_field_mode($user, $module, $fieldKey)` (`helpers.php:1639`) | قناعُ الحقل: `''` / `'ro'` / `'hide'` |

### 1a. `hub_can` — نفسُ المصفوفة، بلا ثقةٍ بالعميل
سطحُ الجوال لا يمرّ بـ`tokenAllows` (لا مفتاحَ API في الجوال، فالصلاحيةُ الكاملةُ
للمستخدم تحت `hub_can` وحدَه — `resolveApi:366-371` و`tokenAllows:575-579` يعملان
`if ($token && …)` فمع غياب `api_token` يُعطَّل قصُّ النطاق بأمان · Critic F3). لا زرٌّ
خفيٌّ ولا دورٌ مُدَّعىً ولا `is_owner` من العميل يُصدَّق — الخادمُ يعيد فحصَ `hub_can`
في **كلِّ** نقطة، حتى إن أرسل الإقلاعُ رايةَ قدرةٍ للعرض (`feature_flags` في bootstrap
لا تُخوّل شيئاً · `MobileContextController.php:99-106`).

### 1b. `hub_scope` — العزلُ الصلب (ثلاثةُ أبعادٍ مستقلّة)
`hub_scope` يقصّ ثلاثةَ `whereIn` (INVENTORY §8):
- **المشروع** — `visibleProjectIds()` (manager_id أو أعضاءُ JSON).
- **الشركة** — `hub_company_ids()` ∩ `hub_company_col` (عزلٌ صارم).
- **العميل** — مصدرُ الحقيقة `client_memberships` (عبر `hub_client_ids()`).

كلُّ قارئٍ جوالٍ يمرّ به: CRUD يرثه من `V1Controller::buildQuery` (`MobileResourceController.php:116`)،
والسياقُ يحلّ به قوائمَه (`MobileContextController::contextDimension:183`)، والمزامنةُ
تبني عليه (`MobileSyncController`)، والبحثُ داخلَ `hub_can`+`hub_scope` لكلِّ وحدة.
**لا فرعٌ ثانٍ** — فما لا يُرى لا يُذكَر (لا IDOR بنيويّاً).

### 1c. `hub_field_mode` — القناعُ يسري على العرض والمخطّط
`''` (كامل) · `'ro'` (قراءةٌ فقط — `locked` لكلِّ الأدوار، أو `field_rules[ro]` للدور) ·
`'hide'` (يُسقَط). `hub_visible_fields` (`helpers.php:1672`) يُسقط `hide`. مخطّطُ الجوال
يبني منه: كلُّ حقلٍ يحمل `readonly = hub_field_mode(...) === 'ro'`
(`MobileContextController.php:236`)، والمحجوبُ لا يظهر أصلاً. الفرزُ/الفلترةُ بحقلٍ
محجوبٍ ممنوعان (إفشاءٌ بلا عرض · `Api::sort:286`).

## 2. سياقُ العرض عديمُ الحالة (narrow-never-widen)

لا «شركةٌ حاليّة» في جلسة — الترويسةُ تحملها لكلِّ طلب. الوسيط:
`app/Http/Middleware/MobileContext.php` (اسمُ الوسيط `mobile.context`)، مُلحَقٌ **بعد**
`mobile.session` في المجموعة المُصادَقة (`routes/api.php:127`) — فيقرأ المستخدمَ الذي
أرسته الجلسة.

### 2a. الحلُّ (`MobileContext::resolve:53`)
`X-Lynomia-Company`/`X-Lynomia-Client` تتقاطعان مع `hub_company_ids()`/`hub_client_ids()`:

- فارغةٌ أو أطولُ من ٦٤ حرفاً ⇒ `null` (لا تضييق · قصٌّ دفاعيّ).
- **مقيَّدٌ** (`hub_company_ids()` تُعيد مصفوفة) والقيمةُ **خارج** مجموعته ⇒ `null`
  (**تُتجاهَل، لا توسيع، لا كسرٌ للطلب**). فرمزُ التضييق الفاسد أو البائت لا يشلّ
  الخروجَ/الجلسات، و`GET context` يُعلم التطبيقَ بمجموعته الحقيقيّة ليصحّح.
- **غيرُ مقيَّد** (المالك — `hub_company_ids()` تُعيد `null`) ⇒ القيمةُ تُقبَل تضييقاً
  لنفسه (رمزٌ غيرُ موجودٍ ⇒ عرضٌ فارغ، لا تسريبَ أبداً).

### 2b. التطبيقُ (`MobileContext::apply:89`) — طبقةٌ فوق `hub_scope` لا بديلٌ عنه
```
hub_scope($q, $module)              // العزلُ الصلب أوّلاً (لا يُمَسّ)
  ->when(company, ->where(hub_company_col, cid))   // تضييقٌ (AND) فوقه
  ->when(client,  ->where(hub_client_col,  kid))
```
التضييقُ **AND على مجموعةٍ محقَّقةٍ ⊆ المسموح** — فبرهانُ «لا توسيع» بنيويّ لا ادّعائيّ.
`hub_scope` يبقى العزلَ الصارم؛ هذه طبقةُ عرضٍ فوقه.

### 2c. `GET context` — المجموعةُ الحقيقيّة (لا IDOR)
`MobileContextController::context` (`:53`) يعيد `companies`/`clients` عبر
`contextDimension` الذي يحلّ المجموعةَ المسموحة **عبر `hub_scope`** إلى `{id,name}`
مرتّبةً حتميّاً (اسمُ العرض ثم `id` · C13)، مع `restricted`/`active`/`count`/`has_more`
ومسقوفةً بـ٢٠٠ (بوّابةٌ ضدّ المجموعات الضخمة للمالك). ما لا يراه المستخدمُ لا يُذكَر.

## 3. المخطّطُ الواعي بالصلاحية (`GET schema` · `schema/modules`)

`MobileContextController::buildSchemaModules` (`:218`) — نظيرُ `V1Controller::modules`
الأسلم:

- **البوّابة:** `hub_can($user, $key, 'v')` لكلِّ وحدة (لا `tokenAllows` — §1a).
- **الحقول:** `hub_visible_fields` (يُسقط `hide`)، كلُّ حقلٍ:
  `{key, label, type, required, ref, multi, options, hint, readonly}`.
- **لا تسريبَ بنيةٍ فيزيائيّة (يُصلح `V1Controller.php:33`):** يُسقط `table` (اسمَ
  الجدول) و`col` (العمودَ الفعليّ)، ويكتفي بالمفاتيح **المنطقيّة**.
- **مُضافٌ فوق v1:** `required`/`options`/`readonly` (تحقّقٌ مشتقّ) + `sync_class`
  (`hub_sync_class` · SF-5) + `can[v,a,e,d]`.
- **نسخةٌ + ETag:** `schemaVersion()` (`:271`) بصمةٌ حتميّةٌ على **شكل العقد العامّ**
  (`ksort` · لا تتبع ترتيبَ الملف)؛ وETag مُنطَّقٌ لكلِّ مستخدمٍ على ما يراه فعلاً
  (فتغيّرُ صلاحيةِ حقلٍ لدورٍ يبدّل بصمتَه دون أن يمسّ نسخةَ العقد العامّة).

`/api/v1/modules` يبقى كما هو حرفيّاً (توافقٌ · لا كسر).

## 4. رايةُ القدرة في الإقلاع ليست تخويلاً

`bootstrap.feature_flags` (`MobileContextController.php:99-106`):
`can_approve`/`can_monitor`/`can_secrets`/`mfa_enrolled`/`restricted_company`/
`restricted_client` — كلُّها **هويّةُ المُنادي نفسِه**، تقود إظهارَ تبويبات التطبيق
دون أن **تُخوّل شيئاً**: الخادمُ يعيد فحصَ الصلاحية في كل نقطةٍ لاحقة. مخفيُّ الزرّ ليس
محميَّ الباب — الحمايةُ خادميّة.

## 5. تكافؤُ الصلاحية == الويب (لا سطحَ أشدَّ ولا أضعف)

بما أن الجوال يدخل `hub_can`/`hub_scope`/`hub_field_mode` نفسَها، فسلوكُ الأدوار
(مالك/كامل/مقيَّد/مشروع/شركة/عميل) مطابقٌ للويب بنيويّاً. مُختبَرٌ في `MobileContextTest`
(١٠) و`MobileSchemaTest` (١٠): شركة/عميلٌ مسموح/ممنوع · عبرَ-الشركات ممنوع · ترويسةٌ
ناقصةٌ/مشوَّهةٌ تُتجاهَل (لا توسيع) · ETag/304 · لا تسريبَ `table` · الحقولُ المرشَّحة
تطابق `hub_visible_fields`.

---

**التحقّق:** أسطرُ المحرّكات مقروءةٌ من `helpers.php` عند head `v2.427.0`
(`hub_can:288`, `hub_scope:141`, `hub_field_mode:1639`, `hub_company_ids:122`,
`hub_client_ids:184`, `hub_visible_fields:1672`). سياقُ العرض من `MobileContext.php`
والمخطّطُ من `MobileContextController.php`. لا محرّكٌ موصوفٌ هنا غيرُ مُعادِ الاستعمال.
