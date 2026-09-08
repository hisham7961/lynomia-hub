# المزامنةُ دون اتصال والتزامُن — Offline / Sync (Mobile Readiness · الطور G · §109)

> **سكّةٌ واحدةٌ لا محرّكٌ ثانٍ:** المزامنةُ التزايُديّة تقودها **خريطةُ تصنيفٍ** لكلِّ وحدة،
> وتعيد استعمالَ نطاقِ `V1Controller` (`hub_scope`) ومُشكّلِ الحقول نفسِه (`shape`) الذي
> يستعمله `apiIndex/apiShow`. لا استعلامَ خامّ، ولا سجلٌّ حسّاسٌ يبلغ خبيئةَ الجهاز أبداً.
> كلُّ ما يلي **مبنيٌّ ومُختبَرٌ حيّاً على المحرّكَين** (SQLite + MySQL) — لا تصميمَ ورقيّ.

---

## 1) النقطةُ الواحدة — `GET /api/mobile/v1/sync/{module}`

| العنصر | القيمة |
|---|---|
| المسار | `GET /api/mobile/v1/sync/{module}` |
| التسجيل | `routes/api.php:266` — الاسم `mobile.sync` |
| المعالج | `App\Http\Controllers\Api\MobileSyncController@sync` (`app/Http/Controllers/Api/MobileSyncController.php:61`) |
| المجموعة | مُصادَقة — خلف `['throttle:api','mobile.session','mobile.context']` (`routes/api.php:127`) |
| المعامِلات | `?updated_since=` · `?cursor=` · `?limit=` |

`{sync}` **مقطعان** (الأوّلُ حرفيٌّ `sync`)، مُسجَّلٌ **قبل** الـcatch-all `GET {module}/{id}`
(`routes/api.php:276`) — وإلّا حلّ `GET sync/tickets` إلى `showRecord('sync','tickets')`.
عزلُ الابتلاعِ نفسُه الذي في `/api/v1` (الحرفيُّ قبل `{module}`)، ومُختبَرٌ في
`tests/Feature/Mobile/MobileRouteIsolationTest.php`.

---

## 2) تصنيفُ الوحدة — `sync_class` (خريطةٌ لا افتراض)

الخريطةُ في `config/hub.php:9224` تحت مفتاح `hub.mobile_sync`، وتُقرأ عبر الحارس
`hub_sync_class($module)` (`app/Support/helpers.php:1691`). الافتراضُ الآمنُ لكلِّ ما لم
يُصنَّف هو **`ONLINE_ONLY`** (`config/hub.php:9225`) — فوحدةٌ جديدةٌ تُضاف لا تُخبَّأ بلا قصد.
والقيمةُ خارجَ قائمة الأصناف المُعلَنة (`hub.mobile_sync.classes`) تسقط للافتراض لا تُمرَّر
(صمّامُ أمانٍ · `helpers.php:1698`).

| الصنف | المعنى | العقدُ على `sync/{module}` |
|---|---|---|
| `CACHEABLE_INCREMENTAL` | وحدةٌ حاملةٌ `updated_at` + `deleted_at` (SoftDeletes) + `version` (HasVersions) | مزامنةٌ تزايُديّةٌ كاملة: سجلّاتٌ مُقنَّعة + شواهدُ حذف (tombstones) + `conflict_token: true` (السجلُّ يحمل `version` والكتابةُ If-Match) |
| `CACHEABLE_READ_ONLY` | قابلةٌ للتخبئة بلا رمزِ تعارُض (لا عمودَ `version` ⇒ لا قفلَ تفاؤليّ) | قراءةٌ تزايُديّةٌ بمؤشّرٍ؛ `conflict_token: false`؛ شواهدُ حذفٍ إن كانت تحذف حذفاً ناعماً |
| `ONLINE_ONLY` | تدفّقاتٌ عاليةُ الحجم/لحظيّة (لا تُخبَّأ سجلّاً) | **سياسةٌ صادقةٌ بلا سجلّات** `{cacheable:false, records:[]}` |
| `SENSITIVE_NO_PERSIST` | وحداتٌ حاملةٌ سرّاً/تفصيلاً ماليّاً — لا تُكتَب على الجهاز أبداً | **سياسةٌ صادقةٌ بلا سجلّات** — لا استعلامَ ولا يُلمَسُ نموذجُها |
| `NOT_APPLICABLE` | لا مزامنةَ لها أصلاً | **سياسةٌ صادقةٌ بلا سجلّات** |

**الخريطةُ الحيّةُ الآن** (`config/hub.php:9230-9322`): **٨١ وحدةً `CACHEABLE_INCREMENTAL`**
(المعيارُ العريض: `tasks`, `projects`, `tickets`, `issues`, `approvals`, `fin`, `clients`, …)
· **٣ وحداتٍ `SENSITIVE_NO_PERSIST`**: `phones` (`config:9240`)، `carriers` (`:9241`)،
`vault` (`:9242`) · **`users` = `NOT_APPLICABLE`** (`config:9300`) — لأنّ عقدَ v1 المجمّد
يرفضها في `resolveApi` (`RESOURCE_NOT_FOUND`) إذ لم يُصمَّم مُشكّلُ الأعمال لقناعِ أعمدتها
الداخليّة (`allowed_ips`/`prefs`/`totp_secret_cipher`)؛ فتصنيفُها `NOT_APPLICABLE` يجعل
المخطّطَ (`schema`) والمزامنةَ يتّفقان: كلاهما يعلن «غيرُ قابلٍ للتخبئة» بلا سجلّ. دليلُ
المستخدمين يأتي من `context`/`bootstrap` (الطور C) لا من `sync`.

الصنفُ `CACHEABLE_READ_ONLY` مُعلَنٌ في محرّك المزامنة (يُميَّزُ بلا `conflict_token`) لكن لا
وحدةَ سجلٍّ مُسنَدةٌ إليه اليوم؛ **الإشعاراتُ** — النموذجُ الوحيدُ عمليّاً بلا `updated_at`
وبحذفٍ صلب — تُزامَن عبر نقطتها الخاصّة (`GET notifications` بمؤشّرٍ على `created_at,id` ·
راجع `06-notifications-push.md`) لا عبر `sync/{module}`.

---

## 3) المؤشّرُ الحتميّ — keyset على `(updated_at, id)`

الترتيبُ `orderBy($updatedCol)->orderBy('id')` (`MobileSyncController.php:140`) — فاصلُ `id`
يحسم تساويَ الطابعِ بالثانية (كُتّابٌ دفعيّون في الثانية نفسها). بلا هذا الفاصلِ تسقط
الصفوفُ صامتاً بين الصفحات على قرعةِ ترتيب MySQL/SQLite (قاعدةُ CLAUDE.md: «الترتيبُ ليس
مضموناً إلا بما تطلبه صراحةً»).

- **المؤشّرُ معتِمٌ عن النطاق** (`encodeCursor`/`decodeCursor` · `MobileSyncController.php:215-235`):
  `base64url({u: updated_at الخام, i: id})` — يحمل **موضعاً فقط** لا نطاقاً. مؤشّرٌ مُلاعَبٌ
  قد يزحزح موضعَ الاستئنافِ لكنّه **لا يوسّع النطاقَ أبداً** (النطاقُ يُعادُ اشتقاقُه خادميّاً كلَّ صفحة).
- مؤشّرٌ فاسدٌ ⇒ `[null,null]` (بدايةٌ آمنة، لا خطأ · `MobileSyncController.php:232`).
- **مفتاحُ المجموعة:** `updated_at > cur.u OR (updated_at = cur.u AND id > cur.id)`
  (`MobileSyncController.php:132-137`).
- **أرضيّةُ `updated_since`:** يُطبَّع المدخلُ إلى منطقة التطبيق (`config('app.timezone')`)
  فتصحُّ المقارنةُ عبر المحرّكَين (`MobileSyncController.php:117`)؛ تاريخٌ غيرُ صالح ⇒
  `VALIDATION_FAILED` 422. `cursor` يغلب `updated_since` حين يجتمعان (الموضعُ المحتوم أدقُّ من الأرضيّة).
- **حجمُ الصفحة:** `?limit=` بافتراضِ `config('hub.mobile.sync.default_limit', 100)` وسقفٍ صلبٍ
  `config('hub.mobile.sync.max_limit', 500)` (`config/hub.php:9373-9376` · `MobileSyncController.php:107-109`)
  — تدفّقٌ عالي الحجم لا يُغرِق الخادمَ بصفحةٍ ضخمة. يُطلَب `limit+1` لكشفِ `has_more` بلا عدٍّ ثانٍ.

---

## 4) شواهدُ الحذف (tombstones)

للوحدات الحاملةِ SoftDeletes يمشي الاستعلامُ `withTrashed()` (`MobileSyncController.php:125`)
كي تركب الشواهدُ المؤشّرَ نفسَه. والحذفُ الناعمُ يرفع `updated_at` (Eloquent · `runSoftDelete`)
فتلتقطها المشيةُ التزايُديّةُ نفسُها — **مشيةٌ واحدةٌ لا تفوّت حذفاً**. الصفُّ المحذوفُ يُبَثُّ
شاهدَ حذفٍ نحيلاً (`id` + `deleted_at` فقط — لا حقول · `MobileSyncController.php:150-158`)، والحيُّ
يُبَثُّ سجلاً مُقنَّعاً.

- `has_tombstones: true` ⇒ وحدةٌ تحذف حذفاً ناعماً؛ العميلُ يُسقِط من خبيئته ما ورد في `tombstones`.
- `has_tombstones: false` ⇒ وحدةُ حذفٍ صلب: **الغيابُ = إسقاط** (لا شاهدَ، فيُسقِط العميلُ ما لم يعد يعود).

---

## 5) قناعُ الحقول — لا سرَّ يهبط الجهاز

كلُّ سجلٍّ يمرّ بـ`syncShape` (`MobileSyncController.php:203`) الذي يبني على مُشكّلِ v1
(`shape`) — قناعُ الحقول نفسُه الذي في `apiIndex/apiShow` (الحقلُ المخفيُّ `hide` غائبٌ،
وقيمةُ حقلِ `sec` لا تُعادُ لغيرِ المخوَّل) — **وزيادةً** يُجرَّد أيُّ حقلِ `sec` من قيمته
**حتى للمخوَّل** (`MobileSyncController.php:207-209`): لا سرَّ يُكتَب على خبيئةِ الجهاز البتّة.
قناعٌ يزيد لا يُسقِط أبداً.

**بوّابةُ العرض قبل السياسة** (Hardener G · Finding#2 · `MobileSyncController.php:74-80`): على
وحدةٍ حقيقيّةٍ في السجلّ، مَن لا يملك `hub_can(...,'v')` لا يستبطن حتى تصنيفَها — اتّساقاً مع
`schema` الذي يُسقِط ما لا يراه. الوحدةُ المجهولةُ (خارجَ السجلّ) تبقى على الافتراض الحميد
(`ONLINE_ONLY`) — لا تكشف إلا الافتراضَ العامّ.

**النطاقُ دائماً، كلَّ صفحة:** الاستعلامُ يمرّ بـ`hub_scope` (عزلُ المستأجر الصارم ·
`MobileSyncController.php:126`) ثم `MobileContext::apply` (تضييقُ العرض — طبقةُ AND ⊆ المسموح ·
`:127`). فلا يُسرَّب سجلٌّ خارجَ نطاق المستخدم/الشركة/العميل، ولا يوسّعه مؤشّرٌ ولا ترويسةُ سياق.

---

## 6) شكلُ الردّ

**قابلةٌ للتخبئة** (`CACHEABLE_INCREMENTAL` / `CACHEABLE_READ_ONLY` · `MobileSyncController.php:171-183`):

```json
{
  "data": {
    "module": "tasks",
    "sync_class": "CACHEABLE_INCREMENTAL",
    "cacheable": true,
    "conflict_token": true,
    "records": [ { "id": "…", "…": "حقولٌ مُقنَّعة (لا sec)" } ],
    "tombstones": [ { "id": "…", "deleted_at": "2026-09-08T…Z" } ],
    "has_tombstones": true,
    "next_cursor": "eyJ1IjoiMjAyNi0wOS0wOCAxMDoxMjozNCIsImkiOiI5YjFlIn0",
    "has_more": true,
    "sync_version": "…",
    "server_time": "2026-09-08T…Z"
  },
  "request_id": "…"
}
```

**غيرُ قابلةٍ للتخبئة** (`ONLINE_ONLY` / `SENSITIVE_NO_PERSIST` / `NOT_APPLICABLE` ·
`MobileSyncController.php:187-195`):

```json
{ "data": { "module": "vault", "sync_class": "SENSITIVE_NO_PERSIST", "cacheable": false, "records": [] }, "request_id": "…" }
```

- `conflict_token` **صادقٌ ببنيةٍ لا بلافتة:** `true` فقط حين `CACHEABLE_INCREMENTAL` **و**حاملٌ
  لصفة `HasVersions` فعلاً (`MobileSyncController.php:103`). فلو أُسيءَ تصنيفُ نموذجٍ بلا نسخةٍ
  `INCREMENTAL`، لن يَعِدَ العقدُ برمزِ تعارُضٍ ثم تخلو سجلّاتُه من `version`. ووحدةٌ صُنّفت قابلةً
  للتخبئة لكنّها بلا `updated_at` تسقط إلى `ONLINE_ONLY` بدل بثٍّ غيرِ حتميّ (`:94-98`).
- `sync_version` = نسخةُ العقد المشتركة (`MobileContextController::schemaVersion()` · `:181`) — حين
  تتغيّر يعيد العميلُ المزامنةَ كاملةً (المخطّطُ تبدّل).

---

## 7) التزامُن على الكتابة — If-Match ⇒ `VERSION_CONFLICT`

المزامنةُ قراءةٌ؛ أمّا الكتابةُ فتحفظ القفلَ التفاؤليَّ نفسَه الذي في `/api/v1`. كلُّ كتابةٍ
على مورد (`PUT`/`PATCH`) تمرّ بـ`Api::assertVersion` (`app/Support/Api.php:398`) الذي يقرأ
`If-Match: "n"` (أو `_version` في الجسم) ويقارنه بـ`$m->version`؛ عدمُ التطابق ⇒ `VERSION_CONFLICT`
409 مع `{current_version, your_version}`. غيابُ الترويسة = مسموحٌ (عميلٌ قديم). العميلُ يأخذ
`version` من سجلِّ المزامنة (المحمولِ حين `conflict_token: true`) ويبعثه في `If-Match` عند الكتابة.
مُختبَرٌ في `tests/Feature/Mobile/MobileSyncConcurrencyTest.php`.

---

## 8) الـIdempotency على الأثر الجانبيّ القابلِ لإعادة المحاولة

كلُّ جانبٍ قابلٍ لإعادة المحاولة على سطح الجوال (إنشاءٌ/إجراءٌ/تعليقٌ/رسالةٌ/إتمامُ رفعٍ/دفعةُ تتبّع)
يقبل `Idempotency-Key`. المفتاحُ الحاسمُ أنّ **مالكَ الـIdempotency على الجوال هو الجلسة** —
`Idempotency::owner($r)` يعيد `mobile_session->id` (`app/Support/Idempotency.php:40-47`) الذي يقرؤه
`V1Controller::ikeyOf` (`app/Http/Controllers/Api/V1Controller.php:466-473`). فلا يعود ردُّ مستخدمٍ
لآخر (Critic F1: طلبُ الجوال يحمل `mobile_session` لا `api_token`، ولولا هذا المالكُ لكان
`ikeyOf` يعيد `[null,null]` فيتعطّل الـIdempotency ويتسرّب ردُّ مستخدمٍ لغيره تحت قيدِ `(token_id, ikey)`).

---

## 9) إرشادُ التخبئة على العميل — حسب الصنف

| الصنف | ما يفعله العميلُ الأصيل |
|---|---|
| `CACHEABLE_INCREMENTAL` | خبّئ السجلّاتِ محليّاً؛ زامِن بـ`cursor` المحفوظ؛ طبّق `tombstones` (احذف)؛ احتفظ بـ`version` لكل سجل وابعثه في `If-Match` عند الكتابة؛ حين تتغيّر `sync_version` أعِد مزامنةً كاملةً من الصفر. |
| `CACHEABLE_READ_ONLY` | خبّئ للقراءة فقط؛ لا `If-Match` (لا رمزَ تعارُض)؛ إن كانت `has_tombstones=false` فالغيابُ يعني الإسقاط. |
| `ONLINE_ONLY` | **لا تخبّئ سجلّاً**؛ اقرأ من الخادم عند الحاجة (تدفّقاتٌ عاليةُ الحجم/لحظيّة). |
| `SENSITIVE_NO_PERSIST` | **لا تكتب على القرص أبداً** (خزنة/شرائح/تفصيلٌ ماليّ)؛ اعرض في الذاكرة فقط وامحُ عند الخلفيّة/القفل؛ لا يُبَثُّ لك سجلٌّ أصلاً. |
| `NOT_APPLICABLE` | لا مزامنة؛ خذ ما تحتاجه من `context`/`bootstrap`/المسار القياسيّ للوحدة. |

**قاعدةٌ عامّة:** لا يُخبَّأ حقلُ `sec` قطّ (يُجرَّد خادميّاً حتى للمخوَّل)؛ والحمولةُ لا تحمل روابطَ
عامّة ولا base64 — التنزيلُ خلفَ بوّابةِ التخويل (راجع `07-files-scanner-location.md`).

---

## 10) التغطيةُ الاختباريّة (فاشلٌ أوّلاً · المحرّكان)

`tests/Feature/Mobile/MobileSyncTest.php` (٢٢ اختباراً) و`MobileSyncConcurrencyTest.php` (٦):
مزامنةٌ أوّليّة · `updated_since` · شاهدُ حذفٍ يركب المؤشّر · ترقيمٌ/مؤشّرٌ بلا فقدٍ ولا تكرار ·
مؤشّرٌ فاسدٌ يبدأ آمناً · سياسةُ `SENSITIVE_NO_PERSIST`/`NOT_APPLICABLE` (لا سجلّ) · بوّابةُ العرض
قبل السياسة · تجريدُ `sec` · حتميّةُ `(updated_at,id)` · `VERSION_CONFLICT` على If-Match ·
Idempotency على المالكِ الجوال.
