# الوكيل ٠٦ — واجهةُ API ومواصفةُ OpenAPI

> **الطبقة ١ · الجولة أ (اكتشاف · للقراءة فقط).** الرأس `1d90626` · النسخة `2.540.1`.
> لم يُعدَّل ملفٌّ في المستودع سوى هذا التقرير؛ `docs/openapi.json` لم يُمَسّ ولم يُعَد توليدُه فوقه
> (`git status docs/` نظيفٌ بعد كلِّ المسابر — التوليدُ ذهب إلى مسارٍ مؤقّتٍ للمقارنة وحدَه).

---

## ٠ · الخلاصةُ التنفيذيّة

| السؤال | الجواب |
|---|---|
| مسارٌ حيٌّ غيرُ موصوف | **١٠** من ٣١ في `/api/v1` (٧ `endpoint/*` + ٣ `reports/*`) · و**٢٩** من ٨٨ في الجوّال موصوفةٌ بلا مضمون |
| مسارٌ موصوفٌ غيرُ موجود | **٠** (الـ١٨٢ كلُّها تُحلّ؛ لا موديلَ غائبٌ في الوحدات الـ٨٥) |
| وصفٌ يكذب | **٨ أصنافٍ مُثبَتة** — أخطرُها أنّ **كلَّ** حمولةِ قراءةٍ تُسمّي حقولَها بغيرِ ما تُعلنه المواصفة |
| مسارُ كتابةٍ بلا تحقّقٍ كامل | **٤** (مِعالجان × سطحَين: `track/start` · `track/{s}/points` و نظيراهما في الجوّال) |
| انحرافُ `docs/openapi.json` عن مولّده | **لا انحراف** — الملفُّ مطابقٌ حرفاً بحرف لمخرج `hub:openapi` |

**الحكم:** العقدُ **مُحكَمٌ في أمنه، كاذبٌ في وصفه.** التخويلُ والتنطيقُ وقناعُ الحقول والـETag
وIdempotency والخنقُ وانتهاءُ المفتاح — كلُّها تعمل كما تُعلَن، بأدلّةٍ حيّة. لكنّ **المواصفةَ لا
تصف هذا النظام**: عميلٌ يُولَّد منها آلياً يقرأ `startDate` فيجد `null` (الخادمُ يُرسل `start_date`)،
ويُرسل نقاطَ تتبّعٍ بالشكل الموثَّق فتُرمى كلُّها بردِّ `200`، ولا يجد في المواصفة سبعةَ مساراتٍ
حيّةٍ يخاطبها وكيلُ الأجهزة كلَّ دقيقة. وثغرتان أمنيّتان حقيقيّتان: **مفتاحٌ بنطاقِ قراءةٍ واحد
يكتب جلساتِ تتبّعٍ جغرافيّ**، و**قائمةُ تعليقاتٍ بلا سقفٍ على سطح الجوال**.

---

## ١ · جدولُ التكافؤ — `/api/v1` (٣١ مساراً حيّاً)

`ت` = التخويل · `ح` = التحقّق · `ر` = الترقيم. (✓ سليم · ⚠ ناقص · ✗ غائب · — لا ينطبق)

| المسار | في الكود | في المواصفة | ت | ح | ر | ملاحظة |
|---|:--:|:--:|:--:|:--:|:--:|---|
| `GET /me` | ✓ | ✓ | ✓ | — | — | هويّةُ صاحب المفتاح؛ لا نطاقَ يلزم |
| `GET /modules` | ✓ | ✓ | ✓ | — | — | يصدق بما يستطيعه المفتاح (`tokenAllows`) |
| `GET /openapi.json` | ✓ | ✓ | ✓ | — | — | مقصورةٌ على ما يراه المفتاح |
| `GET /reports/progress/{projectId}` | ✓ | ✓ | ✓ | — | — | `projects:v` + `hub_scope` |
| `GET /reports/health` | ✓ | ✓ | ✓ | — | — | مالكٌ + `reports:v` |
| `GET /reports/my-daily` | ✓ | **✗** | **⚠** | — | ⚠ | **A06-03** غيرُ موصوف · **A06-02** بلا فحصِ نطاق · **A06-06** غلافُ خطأٍ مكسور |
| `GET /reports/today-compliance` | ✓ | **✗** | **⚠** | — | — | كسابقه، وخطؤه بلا `message` أصلاً |
| `GET /reports/daily` | ✓ | **✗** | ✓ | — | **⚠** | `hr:v` + نطاقٌ ✓ · **A06-10** بترٌ صامتٌ عند ٥٠٠ موظّف |
| `POST /metrics` | ✓ | ✓ | ✓ | ✓ | — | متحقّقٌ نموذجيّ: سقفُ دفعة، `between` على مدى العمود، تحقّقٌ قبل أيِّ كتابة |
| `GET /metrics/{module}/{id}` | ✓ | ✓ | ✓ | ✓ | ⚠ | `days` مسقوفٌ ٧٣٠؛ السلسلةُ بلا ترقيم |
| `GET /identity/resolve/{q}` | ✓ | ✓ | ✓ | — | — | النطاقُ يُفحَص عند الإصابة فقط (لا كشفَ عند `none`) |
| `POST /track/start` | ✓ | ✓ | **✗** | **✗** | — | **A06-02** بلا نطاق · **A06-04** `field_day` بلا تحقّق ⇒ ٥٠٠/تشويه |
| `POST /track/{session}/points` | ✓ | ✓ | **✗** | **✗** | ✓ | **A06-02** · **A06-05** مفاتيحُ إلزاميّةٌ غيرُ موثّقة ⇒ رفضٌ صامتٌ بـ٢٠٠ |
| `POST /track/{session}/end` | ✓ | ✓ | **✗** | — | — | **A06-02** بلا نطاق |
| `GET /projects/{id}/assets` | ✓ | ✓ | ✓ | — | **⚠** | بوّابةٌ مزدوجة `assets:e`+`projects:v` ونطاقُ المفتاح ✓ · سقف ٢٠٠ صامت |
| `POST /projects/{id}/assets` | ✓ | ✓ | ✓ | ✓ | — | `validate` كامل |
| `GET /assets/{id}/projects` | ✓ | ✓ | ✓ | — | **⚠** | سقف ١٠٠ صامت |
| `POST /asset-project/{id}/end` | ✓ | ✓ | ✓ | ✓ | — | `reason` مبتورٌ بـ`mb_substr(…,500)` |
| `GET /{module}` ×٨٤ | ✓ | ✓ | ✓ | — | ✓ | ترقيمٌ مسقوفٌ ١٠٠ · **A06-11** `per` غيرُ الرقميّ ⇒ ١ · **A06-01/09** مفاتيحُ الردّ |
| `POST /{module}` ×٨٤ | ✓ | ✓ | ✓ | ✓ | — | `rules()` كاملة + Idempotency محجوزةٌ قبل التنفيذ |
| `GET /{module}/{id}` ×٨٤ | ✓ | ✓ | ✓ | — | — | ETag ✓ · **A06-01/09** |
| `PUT /{module}/{id}` ×٨٤ | ✓ | ✓ | ✓ | ✓ | — | If-Match اختياريّةٌ كما تُعلَن ✓ |
| `PATCH /{module}/{id}` ×٨٤ | ✓ | ✓ | ✓ | ✓ | — | **A06-13** حقلٌ محجوبٌ وحدَه ⇒ ٢٠٠ بلا كتابة |
| `DELETE /{module}/{id}` ×٨٤ | ✓ | ✓ | ✓ | — | — | سلّةٌ ناعمة |
| `POST /endpoint/enroll` | ✓ | **✗** | ✓ | ✓ | — | رمزٌ مسكوكٌ + خنق `20,1` · **A06-03** |
| `POST /endpoint/heartbeat` | ✓ | **✗** | ✓ | ✓ | — | توقيعُ ES256 قبل أيِّ معالج · `validate` + بترٌ صريح |
| `POST /endpoint/event` | ✓ | **✗** | ✓ | ✓ | — | كسابقه |
| `POST /endpoint/commands/pull` | ✓ | **✗** | ✓ | ✓ | ✓ | سحبٌ ذرّيّ (outbox) |
| `POST /endpoint/commands/result` | ✓ | **✗** | ✓ | ✓ | — | كسابقه |
| `GET /endpoint/agent/manifest` | ✓ | **✗** | ✓ | — | — | `{url, sha256}` مُسجَّلٌ في `download_log` |
| `GET /endpoint/agent/download/{id}` | ✓ | **✗** | ✓ | — | — | كسابقه |

> `/api/v1/users` محجوبٌ عمداً (`resolveApi` ⇒ ٤٠٤) وغائبٌ عن المواصفة — **اتّساقٌ سليم**، أُثبت بالمسبار.

## ٢ · جدولُ التكافؤ — `/api/mobile/v1` (٨٨ مساراً حيّاً · ملخّصٌ بالمجال)

المواصفةُ **مُشتقّةٌ من سجلِّ التوجيه حيّاً** (`MobileOpenApi::mobileRoutes`) فالتغطيةُ الهيكليّة
**١٠٠٪** (٨٨ مساراً ⇒ ٨٠ قالبَ مسار). العيبُ في **المضمون**: ٢٩ مساراً بلا «وصفة» في `opMeta`
فتظهر بملخّصٍ آليٍّ (`"post api/mobile/v1/clients/{client}/members"`) وردٍّ عامّ.

| المجال | المسارات | موصوفةٌ بوصفة | ت | ح | ر | ملاحظة |
|---|:--:|:--:|:--:|:--:|:--:|---|
| `auth/*` + `app-config` + `health` | ١٠ | ١٠/١٠ | ✓ | ✓ | ✓ | خنقٌ ضيّقٌ لكلٍّ (`10,1` · `6,1` · `20,1`)، والتحديثُ يصادِق بنفسه |
| `activation/*` | ٢ | **٠/٢** | ✓ | ✓ | — | **A06-08** `complete` يتطلّب `otp`+`password` ولا `requestBody` في المواصفة |
| `context`/`bootstrap`/`schema*`/`navigation` | ٥ | ٥/٥ | ✓ | — | ✓ | ETag/304 ✓ |
| CRUD `{module}` + `/actions` | ٨ | ٨/٨ | ✓ | ✓ | ✓ | يرث محرّكَ v1 كاملاً · **A06-14** يُصفّ الاعتمادَ بينما v1 يرفض ٤٠٩ |
| `approvals/*` | ٤ | ٤/٤ | ✓ | ⚠ | ✓ | `note` بلا حدِّ طول (يذهب لنصِّ إشعارٍ فقط) |
| `home`/`search`/`prefs*` | ٥ | ٥/٥ | ✓ | ✓ | ✓ | الكتابةُ عبر `PrefService` بقائمةٍ بيضاء |
| `work/*` + `me/documents*` | ٤ | **٠/٤** | ✓ | — | ✓ | **A06-06** `work/today` يكسر غلافَ الخطأ |
| `portal/*` | ١٠ | **٠/١٠** | ✓ | — | **⚠** | سياجُ `mobile.portal` ✓ · **A06-10** سقف ١٠٠ بلا `has_more` |
| `clients/{client}/members*` | ٤ | **٠/٤** | ✓ | ✓ | ⚠ | **A06-08** جسدان إلزاميّان بلا `requestBody` |
| إشعارات/تعليقات/DM/دفع | ١٥ | ١٥/١٥ | ✓ | ✓ | ⚠ | **A06-07** `comments` بلا سقفٍ ولا ترقيم |
| تعاون (`conversations`/`presence`/`saved`/react/typing) | ٩ | **٠/٩** | ✓ | ✓ | ✓ | `since` مسقوفٌ ٥٠ · `saved` ٢٠٠ · **A06-08** لِـ`react` (يتطلّب `emoji`) |
| ملفّات/ماسح/تتبّع | ١٠ | ١٠/١٠ | ✓ | ⚠ | ✓ | `tracking/*` يُعيد استعمالَ v1 ⇒ يرث **A06-04/05** |
| `sync/{module}` | ١ | ١/١ | ✓ | — | ✓ | مؤشّرٌ حتميّ `(updated_at,id)` ✓ |
| `openapi.json` | ١ | ١/١ | — | — | — | عامّةٌ عمداً |

**القائمةُ الكاملةُ للـ٢٩ بلا وصفة** محفوظةٌ في نصِّ الإعادة أدناه (A06-08).

---

## ٣ · الملاحظات

### `A06-01` · **P1** · حمولةُ القراءة تُسمّي حقولَها بأسماءِ الأعمدة، والمواصفةُ تُعلن مفاتيحَ الحقول

**الدليل.** المولِّد يكتب اسمَ الخاصّية من **مفتاح** الحقل:
`app/Support/OpenApi.php:110` — `$props[(string) $f['key']] = ['nullable' => true] + $p;`
والمُشكِّل يُخرج الصفَّ بأسماءِ **الأعمدة** ولا يترجم إلا حين يُطلَب `?fields=`:
`app/Http/Controllers/Api/V1Controller.php:545` — `$arr = is_array($row) ? $row : $row->toArray();`
(ثمّ `unset($arr[$f['col']])` للحجب، ثمّ `return $arr;` في السطر ٥٧٨ — بمفاتيحِ الأعمدة).

مسبارٌ على وحدةٍ واحدة (`projects`) — مقارنةُ خصائصِ المخطّط `Projects` بمفاتيحِ الردّ الفعليّ:

```
[A] spec props : id,version,name,logo,companyId,clientId,engagementId,managerId,…,startDate,launchExp
[A] actual keys: id,name,logo_id,company_id,manager_id,…,start_date,launch_exp,launch_act,…
[A] declared-but-absent (13): logo,companyId,clientId,engagementId,managerId,startDate,
    launchExp,launchAct,desc,revExp,brandColors,brandFonts,brandDesignerId
[A] returned-but-undeclared (23): logo_id,company_id,manager_id,start_date,launch_exp,…
```

**المدى.** ٥٩٩ حقلاً من ١٤٤٦ (٤١٪) مفتاحُه ≠ عمودُه، و**٨٥ وحدةً من ٨٥ مصابة** — أي كلُّ
`GET /{module}` وكلُّ `GET /{module}/{id}` (١٦٨ مسارَ قراءة). الكودُ نفسُه يعرف الفجوة ويسمّيها
في تعليق `aliasColumns` (`V1Controller:339-343`): «القراءةُ تُخرج أسماءَ الأعمدة (`owner_id`,
`next_step`) والكتابةُ تقرأ مفاتيحَ السجلّ (`ownerId`, `nextStep`)» — وقد أُصلح **جانبُ الكتابة**
بقبول الاسمَين، وبقي **جانبُ القراءة** يكذّب المخطّط.

**الإعادة.** `GET /api/v1/projects/{id}` بمفتاحٍ صالح، ثم قارن `array_keys(data)` بـ
`components.schemas.Projects.properties`.

**الإصلاح (خيارٌ واحدٌ قانونيّ، غيرُ كاسر).** إبقاءُ مفاتيحِ الأعمدة في الردّ (عقدٌ قائمٌ يعتمد عليه
n8n) و**تصحيحُ المواصفة لتصفَ الواقع**: في `recordSchema` يُعلَن المخطّطُ **للقراءة** باسم العمود
(`$f['col']`) مع `x-field-key` يحمل المفتاح، ويبقى **مخطّطُ الكتابة** بالمفتاح (ومعه `x-column`
لأنّ `aliasColumns` يقبل الاثنين). فيصدُق الوصفُ ولا يتغيّر بايتٌ في السلوك.

**اختبارُ الانحدار.** `ApiContractTest::test_read_payload_keys_match_the_declared_read_schema` —
يمرّ على **كلِّ** وحدةٍ فيها حقلٌ `key !== col` (لا على واحدةٍ منها)، ينشئ سجلاً، يقرأه،
ويؤكّد `array_keys($data) ⊆ properties` و`required ⊆ array_keys($data)`.

**الثقة: عالية (مُثبَتة).**

---

### `A06-02` · **P1** · نطاقُ مفتاح API يُتجاوَز على خمسةِ مسارات — منها ثلاثةُ **كتابة**

**الدليل.** الانضباطُ قائمٌ في المستودع: `resolveApi` يرفض بـ`INSUFFICIENT_SCOPE`
(`V1Controller:378-383`)، و`progress`/`health` تستدعيان `tokenAllows` (`:434,442`)،
و`teamDaily` كذلك (`ReportsApiController:72`)، و`AssetProjectApiController::gate()` يفحص
النطاقَين معاً (`:34-37`). و**ينقطع** في خمسةِ معالجات: `trackStart`/`trackIngest`/`trackEnd`
(`V1Controller:670,686,703`) و`myDaily`/`todayCompliance` (`ReportsApiController:22,53`) —
لا `tokenAllows` ولا `$token->allows(...)` في أيٍّ منها.

مسبارٌ بمفتاحٍ نطاقُه `tasks:v` حرفيّاً (قراءةُ وحدةٍ واحدة):

```
[E] GET  /api/v1/clients               => 403 code='INSUFFICIENT_SCOPE'   ← الانضباطُ يعمل
[E] GET  /api/v1/reports/daily         => 403 code='INSUFFICIENT_SCOPE'   ← يعمل
[E] GET  /api/v1/reports/health        => 403 code='INSUFFICIENT_SCOPE'   ← يعمل
[E] GET  /api/v1/reports/my-daily      => 200                             ← يتجاوز
[E] GET  /api/v1/reports/today-compliance => 200                          ← يتجاوز
[E] POST /api/v1/track/start           => 201 {"session":"42c94bd1-…","status":"نشطة"}   ← كتابة!
[E] POST /api/v1/track/{s}/points      => 200 {"saved":0,"skipped":1}
[E] POST /api/v1/track/{s}/end         => 200
```

**لماذا P1.** المفتاحُ المحصورُ بالقراءة على وحدةٍ واحدة **يكتب صفّاً في `track_sessions`**، ويختم
`consent_at` باسم صاحبه، ويُقيّد `hub_audit`. ونطاقُ المفتاح وعدٌ مكتوبٌ للعميل («أنشئ مفتاحاً
بنطاقٍ أضيق») — ووعدٌ يصدق في ٢٦ مساراً ويسقط في ٥ أسوأُ من غياب النطاق، لأنّه يُشترى به أمانٌ
غيرُ موجود. والحمولةُ المقروءةُ ليست هيّنة: `project_id`/`task_id`/`problems`/`next`/
`review_feedback` لكلِّ بندِ عملٍ في اليوم.

**الإعادة.** أنشئ مفتاحاً `scopes='tasks:v'` ثمّ `POST /api/v1/track/start {"consent":true}`.

**الإصلاح.** `tokenAllows` موجودةٌ ومجرَّبة — تُستدعى في المعالجات الخمسة بالمفتاح الدلاليّ
الصحيح: `hr:v` للتقريرَين الذاتيَّين (السكّةُ التي يقرؤها `teamDaily` نفسُها)، و`hr:e` للتتبّع
(كتابةُ جلسةٍ على موظّف). وحدةٌ غيرُ مسجّلة (`tracks`, `reports`) لا تمنع الفحص — `allows()`
يطابق `*` أيضاً، فمفتاحٌ بلا نطاقٍ يمرّ كما هو مُوثَّق.

**اختبارُ الانحدار.** `ApiScopeTest::test_narrow_scope_cannot_reach_tracking_or_self_reports` —
يفشل أولاً بـ٢٠١ ثمّ يخضرّ بـ٤٠٣ `INSUFFICIENT_SCOPE`؛ ويُرفَق حارسٌ جامع: كلُّ مسارٍ في
`routes/api.php` تحت `ApiAuth` يُضرَب بمفتاحٍ نطاقُه وحدةٌ لا تخصّه ويُؤكَّد أنّه لا يُعيد 2xx.

**الثقة: عالية (مُثبَتة).**

---

### `A06-03` · **P2** · عشرةُ مساراتٍ حيّةٍ خارجَ المواصفة — والبوّابةُ الآليّةُ عمياءُ عنها

**الدليل.** `OpenApi::staticPaths()` (`app/Support/OpenApi.php:262-326`) يعدّد ١٤ مساراً ثابتاً؛
ولا مدخلَ فيه لِـ:

| المسار | مُعرَّفٌ في |
|---|---|
| `GET /api/v1/reports/my-daily` | `routes/api.php:23` |
| `GET /api/v1/reports/today-compliance` | `routes/api.php:24` |
| `GET /api/v1/reports/daily` | `routes/api.php:25` |
| `POST /api/v1/endpoint/enroll` | `routes/api.php:69` |
| `POST /api/v1/endpoint/heartbeat` | `routes/api.php:82` |
| `POST /api/v1/endpoint/event` | `routes/api.php:83` |
| `POST /api/v1/endpoint/commands/pull` | `routes/api.php:84` |
| `POST /api/v1/endpoint/commands/result` | `routes/api.php:85` |
| `GET /api/v1/endpoint/agent/manifest` | `routes/api.php:94` |
| `GET /api/v1/endpoint/agent/download/{id}` | `routes/api.php:95` |

```
/api/v1/reports/daily       => ABSENT      /api/v1/endpoint/commands/pull   => ABSENT
/api/v1/reports/my-daily    => ABSENT      /api/v1/endpoint/commands/result => ABSENT
/api/v1/reports/today-…     => ABSENT      /api/v1/endpoint/agent/manifest  => ABSENT
/api/v1/endpoint/enroll     => ABSENT      /api/v1/endpoint/agent/download/{id} => ABSENT
/api/v1/endpoint/heartbeat  => ABSENT      /api/v1/endpoint/event           => ABSENT
```

**لماذا بقيت.** بوّابةُ CI (`.github/workflows/ci.yml:74-75`) تقارن `docs/openapi.json` بمخرَج
`hub:openapi` — أي **المولِّدَ بنفسِه**. فهي تكشف انحرافَ الملفِّ ولا تكشف **نقصَ المولِّد**:
ما غاب عن `staticPaths()` غائبٌ عن الطرفَين فيتطابقان في سكوت. وأثبتُّ التطابقَ حيّاً:
`php artisan hub:openapi --out=<tmp>` ⇒ `diff` صامت، `IDENTICAL — no drift`.
ولا اختبارَ في الحزمة يذهب من **سجلِّ التوجيه إلى المواصفة** (عكسَ ما يفعله الجوّال بحقّ).

**لماذا يضرّ.** `endpoint/*` ليست مسارات هامشيّة: هي بروتوكولُ الوكيلِ على أجهزة الشركة
(نبضٌ/أحداثٌ/أوامرُ/تحديثٌ موقَّع). عقدٌ يُبنى عليه برمجيّةٌ تعمل على أجهزةِ المستخدمين ولا يُوثَّق
عقدُه إلّا في تعليقاتٍ عربيّةٍ داخل `routes/api.php`.

**الإصلاح.** ثلاثةُ `reports/*` تُضاف إلى `staticPaths()` (بوسمِ `reports` القائم). وسبعةُ
`endpoint/*` أمنُها **توقيعٌ** لا `bearerAuth`، فتوصَف تحت `securitySchemes` ثانٍ
(`endpointSignature`, `type: apiKey, in: header`) ووسمٍ `endpoint` — أو، إن أُريد فصلُ
الجمهورَين، وثيقةٌ ثانيةٌ على نمط `MobileOpenApi` المُشتقّ من السجلّ.

**اختبارُ الانحدار (الأهمّ).** `ApiContractTest::test_every_live_v1_route_is_described` —
يمرّ على `Route::getRoutes()` مُرشَّحاً على `api/v1`، ويؤكّد لكلِّ `(uri, verb)` وجودَ نظيرِه في
`OpenApi::spec()` (مع توسيعِ `{module}` إلى مفاتيحِ الوحدات)، وإلّا فليكن في `x-deprecations`
صراحةً. هذا الحارسُ وحدَه يمنع تكرارَ العيب لكلِّ مسارٍ يُضاف مستقبلاً.

**الثقة: عالية (مُثبَتة).**

---

### `A06-04` · **P2** · `POST /api/v1/track/start` — `field_day` يُكتب بلا تحقّق: ٥٠٠ على نصّ، وتشويهٌ صامتٌ على تاريخٍ مستحيل

**الدليل.** المعالجُ يتحقّق من `consent` وحدَه (`V1Controller:670-684`) ويُمرّر `field_day` خاماً:
```php
'field_day' => $r->input('field_day'),
```
والخدمةُ تكتبه مباشرةً (`app/Support/Tracking.php:33,43`):
```php
$day = $ctx['field_day'] ?? now()->toDateString();
… TrackSession::create(['field_day' => $day, …]);
```

مسبارٌ (`app.debug=false`):
```
[O] field_day=not-a-date   => 500 stored=NULL
[O] field_day=9999-99-99   => 201 stored='10007-06-07 00:00:00'
[O] field_day=xxxxx…(300)  => 500 stored=NULL
[O] field_day=array        => 500 code='INTERNAL_ERROR'
```

**لماذا P2.** ثلاثةُ أعطالٍ في واحد: (١) **خطأُ عميلٍ يُصيَّر عطلاً داخليّاً** — ٥٠٠ حيث تُعلن
المواصفةُ ٤٢٢، فيُرصد في مركز الأخطاء كعطلٍ زائفٍ ويُوجَّه العميلُ إلى الدعم بدل تصحيح حمولته.
(٢) **تشويهٌ صامت**: `9999-99-99` يُخزَّن **سنةَ ١٠٠٠٧** — وهذا فوق حدِّ `DATE` في MySQL
(`9999-12-31`)، أي بالضبط صنفُ «SQLite متساهلة حيث MySQL صارمة» الذي يحذّر منه `CLAUDE.md`؛
والحزمةُ خضراءُ لأنّ لا اختبارَ يمسّه. (٣) الجلسةُ المؤرَّخةُ سنةَ ١٠٠٠٧ تُفلت من حارسِ
«جلسةٌ نشطةٌ واحدةٌ لليوم» (`whereDate('field_day', $day)`) — فتتكدّس جلساتٌ نشطةٌ بلا سقف.

**الإعادة.** `POST /api/v1/track/start` بـ`{"consent":true,"field_day":"9999-99-99"}`.

**الإصلاح.** سطرٌ واحدٌ قبل `Tracking::start`:
`$r->validate(['consent' => 'accepted', 'field_day' => 'nullable|date_format:Y-m-d|after_or_equal:2020-01-01|before_or_equal:' . now()->addDay()->toDateString()]);`
(نمطُ `metricsIngest` نفسُه: حدٌّ صريحٌ من مدى العمود لا `date` المتساهلة)، ثمّ تُضاف
`format: date` وحدودُه إلى مخطّط `track/start` في `staticPaths()`.

**اختبارُ الانحدار.** `TrackingApiTest::test_field_day_is_validated_not_coerced` — ثلاثُ حالاتٍ
(`not-a-date` · `9999-99-99` · مصفوفة) تُؤكَّد كلُّها ٤٢٢ `VALIDATION_FAILED`، ويُؤكَّد
`TrackSession::count() === 0` بعدها. يفشل اليومَ بـ٥٠٠/٢٠١.

**الثقة: عالية (مُثبَتة).**

---

### `A06-05` · **P2** · `POST /track/{session}/points` — الدفعةُ المرفوضةُ بالكامل تعود `200`، ومفاتيحُها الإلزاميّةُ غيرُ موثّقة

**الدليل.** المواصفةُ تصف الجسدَ هكذا (`app/Support/OpenApi.php:298-300`):
`{"points": {"type":"array","items":{"type":"object","additionalProperties":true}}}` — ولا كلمةَ
عن مفتاحٍ إلزاميّ. والخدمةُ تُسقط كلَّ نقطةٍ تنقصها `op` أو `client_operation_id`
(`app/Support/Tracking.php:69-76`):
```php
$op = (string) ($p['op'] ?? $p['client_operation_id'] ?? '');
if ($lat === null || $lng === null || $op === '' || …) { $skipped++; continue; }
```

مسبار:
```
[P] بلا op => 200 {"saved":0,"skipped":1,"session":"ae70d0d1-…"}
[P] مع op  => 200 {"saved":1,"skipped":0,"session":"ae70d0d1-…"}
[P] نقاطٌ غيرُ كائنات (["xyz",5]) => 200 {"saved":0,"skipped":2,…}
```

**لماذا يضرّ.** عميلٌ يتبع المواصفةَ حرفيّاً يرفع مسارَ مندوبٍ يوماً كاملاً، ويتلقّى `200` في كلِّ
دفعة، ويغلق الجلسةَ — والمسارُ فارغ. لا كودَ خطأ، ولا ترويسة، ولا شيءَ يُنبّه. «نجاحٌ» يعني
هنا «استُلمت الدفعةُ» ويقرؤه العميلُ «حُفظت النقاط».

**الإصلاح.** (أ) المواصفةُ تصف النقطةَ صراحةً: `required: [lat, lng, op]` بمداها
(`lat: -90..90`, `lng: -180..180`, `op: maxLength 80`)، ومخطّطُ الردّ يُعلن `saved`/`skipped`.
(ب) المعالجُ يُصعِّد الصمتَ: `abort_if($res['saved'] === 0 && $res['skipped'] > 0, 422, …)`
بكود `VALIDATION_FAILED` ودليلٍ مهيكلٍ بعدد المرفوض وسببه — فالدفعةُ التي رُفضت كلُّها ليست نجاحاً.
وتبقى الدفعةُ المختلطةُ `200` بعدّادَيها (لا كسرَ لعميلٍ قائم).

**اختبارُ الانحدار.** `TrackingApiTest::test_a_batch_rejected_entirely_is_not_reported_as_success`
— يرسل الحمولةَ **كما تصفها المواصفة** ويؤكّد أنّها لا تعود `200 saved=0`.

**الثقة: عالية (مُثبَتة).**

---

### `A06-06` · **P2** · ثلاثةُ معالجاتٍ تخرق غلافَ الخطأ الموحَّد: بلا `code` ولا `request_id`، وواحدٌ بلا `message`

**الدليل.** `App\Support\Api::error` هي النقطةُ الواحدة، ويلتقط `Api::render` كلَّ استثناء على
`api/*`. وهذه الثلاثةُ تُعيد `response()->json()` يدويّاً فتفلت من الاثنين:
- `app/Http/Controllers/Api/ReportsApiController.php:26` → `['error'=>'no_employee_profile','message'=>…]`
- `app/Http/Controllers/Api/ReportsApiController.php:55` → `['error'=>'no_employee_profile']` ← **بلا `message`**
- `app/Http/Controllers/Api/MobileReportsController.php:25` → كالأوّل

```
[B] /api/v1/reports/my-daily          => 422 {"error":"no_employee_profile","message":"لا ملفَ موظّفٍ…"} X-Error-Code=NULL
[B] /api/v1/reports/today-compliance  => 422 {"error":"no_employee_profile"}                              X-Error-Code=NULL
```

**ما يكذب.** وصفُ المواصفة يقول حرفيّاً «كلُّ خطأٍ يحمل `code` آلياً و`request_id`»
(`OpenApi.php:45`)، ومخطّطُ `Error` يُعلن `required: [error, code, message]` (`OpenApi.php:128`).
فالردُّ الثاني **ينتهك مخطّطاً إلزاميّاً** يولّده النظامُ عن نفسه. و`ApiContractTest` يثبّت
هذا الضمانَ لستّة أصنافِ خطأٍ — ولا يمرّ على هذين المسارَين (إذ لا يعرفهما أحد: راجع A06-03).

**الإصلاح.** استبدالُ الثلاثةِ بـ
`Api::error(Api::BUSINESS_RULE_VIOLATION, 422, 'لا ملفَ موظّفٍ نشطاً مربوطاً بحسابك', ['reason' => 'no_employee_profile'])`
— يبقى `error` نصّاً (توافقٌ قديم)، ويصل `no_employee_profile` في `details.reason`، ويُضاف
`code` و`request_id` وترويسة `X-Error-Code` مجّاناً.

**اختبارُ الانحدار.** توسيعُ `ApiContractTest::test_every_error_class_carries_a_machine_code_and_request_id`
بحارسٍ جامع: يمرّ على **كلِّ** مسارِ `api/*` ويؤكّد أنّ أيَّ ردٍّ `>= 400` يحمل `code` و`message`
و`request_id` يطابق `X-Request-Id`.

**الثقة: عالية (مُثبَتة).**

---

### `A06-07` · **P2** · `GET /api/mobile/v1/comments?module=feed` بلا سقفٍ ولا ترقيم — والشاشةُ تُرقّم ١٥

**الدليل.** `app/Http/Controllers/Api/MobileCommController.php:220` — `$items = $q->get();`
بلا `limit` ولا `paginate`، مسبوقاً بـ`->with('user','replies.user')` (فكلُّ الردودِ ومؤلّفوها
معها)، ومتبوعاً بـ`CommentController::reactionsFor($items)` على المجموعة كلِّها.
والنظيرُ الويبيُّ يُرقّم: `app/Http/Controllers/Web/CommentController.php:34` — `->paginate(15)`.

```
[L] mobile comments?module=feed => 200 returned=120 (created 120) keys=module,record,comments
```

**لماذا أمنٌ وأداءٌ معاً.** `feed` تيّارُ الشركةِ كلُّه ونموُّه غيرُ محدود. طلبٌ واحدٌ من هاتفٍ يسحب
كلَّ منشورٍ وكلَّ ردٍّ وكلَّ مؤلّفٍ وكلَّ تفاعل؛ ومكرّرٌ من عشرة أجهزةٍ مصادَقةٍ يكفي لإسقاط الخدمة
بميزانيّةٍ شرعيّةٍ تماماً تحت سقفِ الخنق (١٢٠/دقيقة). والمواصفةُ لا تُعلن `page`/`per`، فالعميلُ
لا يملك حتّى مفردةً يطلب بها أقلّ.

**الإعادة.** أنشئ ١٢٠ صفَّ `Comment(module=feed)` ثمّ `GET /api/mobile/v1/comments?module=feed`.

**الإصلاح.** مؤشّرٌ على نمط `conversations/{id}/since` القائم في السطح نفسِه
(`MobileCollabController:102` — `limit(50)` + `cursor`): `limit($per + 1)` بـ`$per` مسقوفٍ ٥٠،
مع `cursor` حتميٍّ `(created_at, id)` و`has_more` في الردّ، ووصفةُ `opMeta` تُعلنهما. ويبقى
المسارُ نفسُه وشكلُ `comments` نفسُه (إضافةٌ لا كسر).

**اختبارُ الانحدار.** `MobileCommTest::test_feed_comments_are_bounded` — ١٢٠ منشوراً، ويُؤكَّد
`count(data.comments) <= 50` ووجودُ `has_more=true`، وأنّ اتّباعَ `cursor` يبلغ الصفحةَ الثانية
بلا تكرارٍ ولا فجوة.

**الثقة: عالية (مُثبَتة).**

---

### `A06-08` · **P2** · ٢٩ مساراً جوّالاً بلا وصفة — منها ٥ مساراتِ كتابةٍ جسدُها إلزاميٌّ وغائبٌ عن المواصفة

**الدليل.** `MobileOpenApi::pathItem` (`app/Support/MobileOpenApi.php:143-150`) لا يُصدر
`requestBody` إلّا إن وجد `body` في وصفة `opMeta`؛ ومسارٌ بلا وصفةٍ يخرج بملخّصٍ آليٍّ وردٍّ عامّ.
٥٩ وصفةً في `opMeta` مقابل ٨٨ مساراً حيّاً ⇒ **٢٩ بلا وصفة**، **٠ وصفةً بلا مسار** (الاتّجاهُ
العكسيُّ محروسٌ جيّداً).

```
write ops WITHOUT requestBody in spec: 9
  - POST /api/mobile/v1/activation/{token}/complete   ← يتطلّب otp + password  (MobileActivationController:93-95)
  - POST /api/mobile/v1/clients/{client}/members      ← يتطلّب email + role    (MobileClientMembersController:55-58)
  - PUT  /api/mobile/v1/clients/{client}/members/{membership} ← يتطلّب role    (…:98)
  - POST /api/mobile/v1/comments/{id}/react           ← يتطلّب emoji           (MobileCollabController:238)
  - POST /api/mobile/v1/dm/messages/{id}/react        ← يتطلّب emoji           (…:279-282)
  - POST /api/mobile/v1/auth/logout · logout-all · conversations/{id}/typing · dm/threads/{user}/typing
        ← بلا جسدٍ فعلاً: غيابُ requestBody صادقٌ فيها
```

**القائمةُ الكاملةُ للـ٢٩:** `activation/{token}` · `activation/{token}/complete` · `work/today` ·
`work/daily-report` · `me/documents` · `me/documents/{id}/file` · `portal/home` ·
`portal/engagements` · `portal/projects` · `portal/projects/{id}` · `portal/documents` ·
`portal/documents/{id}` · `portal/invoices` · `portal/invoices/{id}` · `portal/conversations` ·
`portal/conversations/{id}` · `clients/{client}/members` (GET,POST) ·
`clients/{client}/members/{membership}` (PUT,DELETE) · `conversations` ·
`conversations/{id}/since` · `conversations/{id}/typing` · `dm/threads/{user}/since` ·
`dm/threads/{user}/typing` · `dm/messages/{id}/react` · `comments/{id}/react` · `presence` · `saved`.

**لماذا يضرّ.** خمسةُ مساراتِ كتابةٍ يستحيل استدعاؤها من عميلٍ مُولَّد: المولِّدُ يُنتج دالّةً بلا
معاملِ جسد، فيُرسَل جسدٌ فارغٌ ويعود ٤٢٢ دائماً. وعشرُ مساراتِ `portal/*` هي **كلُّ** سطحِ تطبيق
العميل — موصوفةٌ بملخّصٍ آليٍّ وردٍّ `{data: object, additionalProperties: true}`.

**الإصلاح.** إضافةُ ٢٩ مدخلاً إلى `opMeta()` — بنيةٌ قائمةٌ لا شيفرةَ جديدة، والخمسةُ ذاتُ الجسد
أولويّةٌ أولى. وحارسٌ يمنع التكرار.

**اختبارُ الانحدار.** `MobileOpenApiTest::test_every_mobile_route_has_a_recipe` — يؤكّد أنّ
`opMeta()` تغطّي كلَّ مسارٍ مسمّى تحت `api/mobile/v1`؛ و
`test_write_ops_that_validate_a_body_declare_a_requestBody` — لكلِّ `POST|PUT|PATCH` في المواصفة،
إن كان معالجُه يستدعي `validate()` فوجودُ `requestBody` شرط.

**الثقة: عالية (مُثبَتة).**

---

### `A06-09` · **P3** · أعمدةٌ داخليّةٌ غيرُ مُعلَنةٍ تُعاد في كلِّ حمولةِ سجل

**الدليل.** `shape()` يُسقط الحقولَ المُعلَنةَ المخفيّة والأسرار، ثمّ يُسقط `meta` **وحدَه**
(`V1Controller:571-576`) ويعيد ما تبقّى من `toArray()` كما هو.

```
[G] hr undeclared columns returned to non-fieldsec role (7):
    project_id,custom,version,archived,created_by,updated_by,deleted_at
[G] salary present? false   civil_id present? false      ← أمنُ الحقول سليم
[A] projects returned-but-undeclared (23): …,project_id,custom,archived,created_by,updated_by,
    deleted_at,audience,source_quote_id,hold_reason,blocked
```

**لماذا.** التعليقُ فوق الشيفرة يَعِد بأكثرَ ممّا تفعل: «**لا تسريبَ لعمودٍ غيرِ مُعلَن** … يُسقَط
ما لم تُعلِنه الوحدةُ حقلاً باسمِه» — والمُنفَّذُ إسقاطُ `meta` فقط. لا سرَّ يتسرّب (أثبتُّ أنّ
`salary`/`civil_id`/`budget`/`cost` تُحجب صحيحاً)، لكنّ `hold_reason`/`blocked`/`audience`/
`source_quote_id` بياناتُ أعمالٍ لم يقرّر أحدٌ إعلانَها، و`deleted_at`/`created_by`/`updated_by`
بنيةٌ داخليّة. وهي مجتمعةً السببُ الثاني لِـA06-01.

**الإصلاح.** قائمةٌ بيضاء بدل السوداء: `$arr` يُبنى من أعمدةِ الحقول المُعلَنة + `id`/`version`/
`created_at`/`updated_at` (الأربعةُ التي تُعلنها المواصفةُ فعلاً) لا من `toArray()` كاملاً.
والمهاجرةُ آمنة: كلُّ مفتاحٍ تُسقطه هذه الخطوةُ هو مفتاحٌ **لم تُعلنه المواصفةُ قطّ**.

**اختبارُ الانحدار.** `ApiContractTest::test_no_undeclared_column_leaves_the_api` — لكلِّ وحدة:
`array_keys($data) ⊆ (أعمدةُ الحقول ∪ {id,version,created_at,updated_at})`.

**الثقة: عالية (مُثبَتة).**

---

### `A06-10` · **P3** · قوائمُ مبتورةٌ بصمتٍ بلا `has_more` ولا `total`

| المسار | السقف | الدليل |
|---|:--:|---|
| `GET /api/v1/reports/daily` | ٥٠٠ موظّف | `ReportsApiController:77` — `->limit(500)`، والردُّ `{date, employees}` بلا عدّاد |
| `GET /api/v1/projects/{id}/assets` | ٢٠٠ | `AssetProjectApiController:57` |
| `GET /api/v1/assets/{id}/projects` | ١٠٠ | `AssetProjectApiController:75` |
| `GET /api/mobile/v1/portal/*` (٥ قوائم) | ١٠٠ | `MobileClientPortalController:28` — `LIST_CAP = 100` |
| `GET /api/mobile/v1/saved` | ٢٠٠ | `MobileCollabController:305` |

```
[D] reports/daily keys=date,employees status=200      ← لا total ولا has_more ولا page
```

**لماذا.** السقفُ حمايةٌ صحيحة؛ العيبُ أنّه **صامت**. شركةٌ بـ٦٠٠ موظّفاً تقرأ «امتثالَ اليوم»
فتحصل على ٥٠٠ ولا شيءَ يقول إنّ ١٠٠ نقصوا — ومَن نقصوا يبدون كأنّهم غيرُ موجودين، لا كأنّهم
غيرُ مقروئين. وهذا قرارُ إدارةٍ مبنيٌّ على نصفِ حقيقة. (والمقارنة: `Api::list` يُحسن هذا تماماً
في مسارات الوحدات — `total`/`last_page`/`has_more`.)

**الإصلاح.** غلافُ `Api::list` نفسُه (موجودٌ ومجرَّب) على الستّة، أو — إن أُريد أقلُّ تغييرٍ ممكن —
إضافةُ `{"truncated": true, "cap": N}` حين يبلغ العددُ السقفَ، وإعلانُها في المواصفة.

**اختبارُ الانحدار.** `ReportsApiTest::test_team_daily_declares_truncation` — ٥٠١ موظّفاً نشطاً،
يُؤكَّد وجودُ إشارةِ البتر.

**الثقة: عالية (مُثبَتة).**

---

### `A06-11` · **P3** · معاملاتُ الاستعلام تُهمَل بصمتٍ حيث تُعلن المواصفةُ قيماً وحدوداً

**(أ) `per`.** المواصفة: `minimum: 1, maximum: 100, default: 25`. والكود
(`V1Controller:66`): `min(100, max(1, (int) $r->query('per', 25)))` — و`(int)` تُحوّل كلَّ
ما ليس رقماً إلى `0` ثمّ `max(1,0)` = **١**:

```
[D] per=1000 => per=100   ✓   [D] per=0   => per=1   (المُعلَن: ٢٥)
[D] per=-5   => per=1     ✗   [D] per=abc => per=1   (المُعلَن: ٢٥)
```

**(ب) `fl[i][o]`.** المواصفةُ تُعلن `enum: [has,eq,neq,gt,lt,before,after,empty,nempty]`،
والكودُ يُهمل كلَّ شرطٍ لا يطابق (`ModuleController:156` — `continue`) بلا أثر:

```
[N] fl o=gt  => 2 rows   (٥ عملاء، ٢ منهم value > 250)   ✓
[N] fl o=ZZZ => 200، 5 rows   ← القائمةُ **غيرُ مُرشَّحة** والردُّ نجاح
```

و`meta.filters` يعكس `q`/`status`/المرشّحاتِ الزمنيّةَ فقط (`V1Controller:79-82`) — لا `f` ولا
`fl` — فلا سبيلَ للعميل ليكتشف الإهمال.

**لماذا.** على شاشةٍ يرى الإنسانُ رقائقَ الترشيح فيُدرك ما طُبِّق. على API لا يرى شيئاً:
تكاملٌ يسأل «الفواتيرُ المتأخّرةُ فوق ١٠٠٠» بمُعامِلٍ فيه خطأٌ مطبعيٌّ يتلقّى **كلَّ** الفواتير
بردِّ `200`، ويتصرّف على أساسها. (ملاحظة: إهمالُ شرطٍ على حقلٍ محجوبٍ بالدور —
`ModuleController:157` — **قرارٌ أمنيٌّ صحيح** لا يُمسّ؛ ما يُطلب هو الإفصاحُ عنه.)

**الإصلاح.** (١) `per` غيرُ الرقميّ ⇒ الافتراضُ ٢٥ لا ١ (`is_numeric($v) ? … : 25`)، أو ٤٢٢.
(٢) صدىً في `meta`: `applied_filters` و`ignored_filters` بسببِ كلِّ إهمال
(`unknown_field` · `bad_operator` · `hidden_field` · `bad_value`) — بلا كشفِ اسمِ حقلٍ محجوب.
(٣) `ListMeta` في المواصفة يُعلن الحقلَين.

**اختبارُ الانحدار.** `ApiContractTest::test_ignored_filters_are_reported_not_silent` +
`test_non_numeric_per_falls_back_to_the_documented_default`.

**الثقة: عالية (مُثبَتة).**

---

### `A06-12` · **P3** · المواصفةُ تُسقط قيوداً يفرضها المتحقّق، وتُعلن `writeOnly` ثمّ تُرسله في الردّ

**الدليل.** `recordSchema` (`OpenApi.php:82-121`) يُخرج النوعَ و`enum` الـ`sel` فقط. والمتحقّق
(`ModuleController:1764-1795`) يفرض فوق ذلك: `max:` من **عرض العمود** لـ`text/sel/url/sec`،
`max:` من عرض `TEXT` لـ`ta`، `between:` من **دقّة العمود العشريّ** لـ`num/big`، `min:` المُعلَنة
في السجلّ، `regex` للوقت، و`unique` (مفرداً ومركّباً). ولا شيءَ من هذا يظهر كـ`maxLength` أو
`minimum`/`maximum` أو `pattern` في المخطّط.

وفي الاتّجاه المعاكس: حقولُ `sec` تُعلَن `writeOnly: true` (`OpenApi.php:99`) في **مخطّط
القراءة** أيضاً، بينما `shape()` يُعيدها فعلاً لحاملِ علم الأسرار (`V1Controller:544,554`).
و`writeOnly` في OpenAPI 3.1 يعني «لا يظهر في الردود» — فالوصفُ يناقض السلوكَ في الحالتين
(يظهر لحاملٍ، والمولِّدُ يحذفه من نموذج القراءة فيضيع على العميل).

**الأثر.** عميلٌ مُولَّدٌ يتحقّق محليّاً من حمولته، يجدها مطابقةً للمخطّط، فيُرسلها ويتلقّى ٤٢٢
«الحقلُ أطولُ من المسموح» — بحدٍّ لم يُعلَن له قطّ. وهي أكثرُ حالاتِ الاحتكاك شيوعاً مع تكاملٍ
يكتب بياناتٍ حقيقيّة.

**الإصلاح.** الحدودُ **موجودةٌ ومقروءةٌ برمجيّاً** (`hub_col_max`, `hub_col_num_max`,
`$f['min']`, `$f['unique']`, `$f['format']`) — تُمرَّر إلى المخطّط: `maxLength`, `minimum`,
`maximum`, `pattern`, و`x-unique: true`. وحقلُ `sec` يُحذف من مخطّطِ القراءة ويُبقى في
`…Write` بـ`writeOnly` (وصفٌ صادقٌ للحالتين).

**اختبارُ الانحدار.** `OpenApiFidelityTest::test_declared_constraints_match_the_validator` —
لكلِّ حقلٍ في كلِّ وحدة: إن كانت في `rules()` قاعدةُ `max:N` فليكن في المخطّط `maxLength: N`
(ونظيرُه للعدد) — يشتقّ الطرفَين من المصدرَين فلا يتقادم.

**الثقة: عالية (مُثبَتة).**

---

### `A06-13` · **P3** · `PATCH` بحقلٍ محجوبٍ وحدَه يعود `200` ولا يكتب شيئاً

**الدليل.** `apiPatch` يبني `$keys` من حقول الوحدة الحاضرةِ في الطلب (`V1Controller:180`)، ثمّ
`$rules = array_intersect_key($this->rules($def,false), array_flip($keys))` — و`rules()` تُسقط
الحقلَ المحجوب (`ModuleController:1729`) فيصير `$rules` فارغاً ويمرّ التحقّق، ثمّ `fill()` يتخطّاه
(`ModuleController:1864`). وحارسُ «لا حقولَ معروفة» لا يعمل لأنّ الحقلَ **معروفٌ** وإن كان محجوباً.

```
[C] owner budget='987654.000' | employee budget='ABSENT' cost='ABSENT'    ← الحجبُ سليم
[C] employee PATCH {"budget":1} => 200 {"data":{…}}
[C] budget in DB after = 987654.000                                       ← لم يُكتَب — سليمٌ أمنيّاً
```

**الحكم.** **أمنيّاً سليمٌ تماماً** (لا كتابة)، وصدقاً ناقص: التكاملُ يتلقّى «حُفظ» على تغييرٍ لم
يقع. وهو النظيرُ الصامتُ لِـA06-11.

**الإصلاح.** إن كان كلُّ `$keys` محجوباً/قراءةً-فقط ⇒ `Api::error(Api::FORBIDDEN, 403, …,
['fields' => […]])`. وإن كان بعضُه ⇒ `200` مع `meta.ignored_fields`. لا تغييرَ لأيِّ مسارٍ ناجح.

**اختبارُ الانحدار.** `FieldSecurityApiTest::test_patch_of_only_masked_fields_is_refused_not_ignored`.

**الثقة: عالية (مُثبَتة).**

---

### `A06-14` · **P3** · سطحان يختلفان في العمليّة نفسِها: الاعتمادُ يُصفَّف في الجوّال ويُرفض في `/api/v1`

**الدليل.** `MobileResourceController:78-104` — الكتابةُ المحميّةُ بالموافقات تُصفّ طلباً
(`approveGatedWrite`). و`V1Controller:139,175,216` — الكتابةُ نفسُها تُرفض:
`APPROVAL_REQUIRED (409) — 'نفّذها من الواجهة ليُصفّ الطلب'`.

**الحكم.** ليس ثغرةً (الاتّجاهُ الأشدُّ تقييداً)، لكنّه **عقدان لنظامٍ واحد**: التكاملُ الآليّ
(n8n) لا يستطيع تنفيذَ ما يستطيعه الهاتفُ للمستخدم نفسِه بالصلاحيّة نفسِها، والرسالةُ توجّهه
إلى «الواجهة» — وهو ليس إنساناً. والآليّةُ موجودةٌ ومجرّبة (`submitForApproval`).

**الإصلاح.** رفعُ `approveGatedWrite` إلى `V1Controller` (أو إلى `ModuleController` جوهراً
مشتركاً كما تقتضي «الإضافة لا الكسر»)، وإبقاءُ `409 APPROVAL_REQUIRED` سلوكاً افتراضيّاً يُلغى
بترويسةٍ صريحة `X-Queue-Approval: 1` — فلا مستهلكٌ قائمٌ يُفاجَأ بـ`202` مكان `409`.

**الثقة: متوسطة (السلوكُ مقروءٌ من الشيفرة؛ لم يُنفَّذ مسبارُ وحدةٍ تحت قاعدةِ موافقات).**

---

## ٤ · ما فُحص فوُجد **سليماً** (نفيٌ مُثبَتٌ بالدليل)

| البند | الدليل |
|---|---|
| تكافؤُ المواصفة بالاتّجاه المعاكس | ١٨٢ مساراً موصوفاً، **٠** منها غيرُ موجود؛ ٠ وحدةٍ بموديلٍ مفقود |
| `docs/openapi.json` مطابقٌ لمولّده | `hub:openapi --out=<tmp>` ⇒ `diff` صامت · `git status docs/` نظيف |
| أمنُ الحقول على حمولة JSON | `budget`/`cost`/`salary`/`civil_id` تغيب عن ردِّ من لا يحمل `fieldsec`، **قراءةً وكتابةً** |
| `hub_can` + `hub_scope` على CRUD | `/api/v1` و`/api/mobile/v1` يرثان `ModuleController` — محرّكٌ واحدٌ للشاشة والـAPI |
| عزلُ حسابِ العميل على API | `resolveApi` يطبّق `PortalGuard::MODULE_ALLOW` صراحةً (٤٠٤ لا ٤٠٣ — لا كشفَ وجود) |
| `/api/v1/users` | ٤٠٤ `RESOURCE_NOT_FOUND`، ومحذوفٌ من المواصفة — اتّساقٌ تامّ |
| انتهاءُ المفتاح وإبطالُه | `[K] expired token => 401 UNAUTHENTICATED`؛ و`revoked_at` يقتل فوراً (`ApiAuth:33`) |
| حدُّ المعدّل | `[K] X-RateLimit-Limit='120'` · `300/دقيقة` للعنوان · `throttle` **قبل** `ApiAuth` فيُحَدُّ غيرُ المصادَق |
| `If-Match` / تعارضُ النسخة | `ETag='"1"'` · If-Match قديمةٌ ⇒ `409 VERSION_CONFLICT` · غيابُها يمرّ (اختياريّةٌ كما تُعلَن) — على السطحَين |
| `Idempotency-Key` | الحجزُ **قبل** التنفيذ؛ إعادةُ الطلب ⇒ `X-Idempotent-Replay: true` وسجلٌّ واحدٌ فقط |
| `sync_class` | `clients/banks/tasks ⇒ CACHEABLE_INCREMENTAL` · `hr ⇒ ONLINE_ONLY` — سياسةٌ صادقةٌ مختلفةٌ بالوحدة |
| `X-Change-Reason` | مُنفَّذٌ فعلاً (`app/Traits/Auditable.php:99`) لا مُعلَنٌ فقط |
| تسريبُ الأخطاء | ٦ حمولاتٍ خبيثة: لا `SQLSTATE`، لا `select`، لا `App\`، لا مسارَ ملف، لا `PDO` · ٤٠٤ لا تطبع اسمَ الموديل |
| حقنُ الفرز | `sort=id') OR 1=1--` ⇒ ٢٠٠ بترتيبٍ افتراضيّ (قائمةٌ بيضاء) و`meta.sort=created_at` صادق |
| بوّابةُ السلّة | `viewer ?trash=1` ⇒ ٠ صفّ و`meta.trash=false`؛ المالك ⇒ ١ و`true` |
| `in:` عن ثوابت النموذج | ٢٥٥ حقلَ `sel`، **٠** بلا `options` ⇒ `Rule::in` و`enum` متطابقان دائماً |
| تحقّقُ `endpoint/*` | `validate()` على المعالجات الأربعة + بترٌ صريحٌ لكلِّ نصٍّ يُخزَّن |
| التوافقُ الخلفيّ | `git log -p routes/api.php` (آخر ٥ دفعات): **إضافاتٌ فقط**، لا مسارَ حُذف ولا وُقّع عقدٌ قائم |
| `guardStatusRequires` | يُستدعى داخل `fill()` (`ModuleController:1938`) فيغطّي الويبَ والـAPI والجوّال معاً |

---

## ٥ · الأرقام

```
مسارات /api/v1 الحيّة                        31
  منها موصوفةٌ في docs/openapi.json          21
  منها غيرُ موصوفة                           10   (7 endpoint/* + 3 reports/*)

مسارات /api/mobile/v1 الحيّة                 88
  مغطّاةٌ هيكليّاً (مُشتقّةٌ من السجلّ)         88   (100%)
  بوصفةِ تشغيلٍ حقيقيّة                       59
  بملخّصٍ آليٍّ بلا مضمون                     29
  كتابةٌ بجسدٍ إلزاميٍّ غيرِ موصوف              5

مسارات المواصفة                             182   (84 وحدة × 2 + 14 ثابتاً)
  لا نظيرَ لها في الكود                        0
  مخطّطات                                    172

أصنافُ «وصفٍ يكذب» المُثبَتة                  8
  A06-01 أسماءُ حقول القراءة      (168 مسارَ قراءة · 599 حقلاً · 85/85 وحدة)
  A06-05 مفاتيحُ نقطةِ التتبّع الإلزاميّة
  A06-06 غلافُ الخطأ (3 معالجات)
  A06-08 requestBody الغائب (5 مسارات)
  A06-09 أعمدةٌ غيرُ مُعلَنةٍ في الردّ
  A06-11 per المُعلَنة 25 وتصير 1 · fl enum مُعلَنٌ ويُهمَل
  A06-12 maxLength/min/max/unique مفقودة · writeOnly متناقض
  A06-14 تباينُ سلوكِ الاعتماد بين السطحين

مسارات الكتابة بلا تحقّقٍ كامل                 4
  POST /api/v1/track/start              ← field_day خامّ
  POST /api/v1/track/{s}/points         ← عناصرُ points خامّة
  POST /api/mobile/v1/tracking/start    ← يُعيد استعمالَ الأوّل
  POST /api/mobile/v1/tracking/{s}/points ← يُعيد استعمالَ الثاني
  (كلُّ مسارات CRUD الـ٨٤ وmetrics وendpoint/* وسائرُ الجوّال: تحقّقٌ كامل)

مسارات بلا فحصِ نطاقِ المفتاح                  5   (3 كتابةٍ + 2 قراءة)
قوائمُ مبتورةٌ بصمتٍ بلا has_more              6
قوائمُ بلا سقفٍ إطلاقاً                        1   (mobile comments?module=feed)
```

## ٦ · ترتيبُ الإصلاح المقترح (لجولةِ التصميم)

1. **A06-02** — خمسةُ أسطرِ `tokenAllows`. أعلى مردودٍ أمنيٍّ لكلِّ سطر.
2. **A06-03 + حارسُها** — الحارسُ `test_every_live_v1_route_is_described` أهمُّ من المسارات
   العشرة نفسِها: بدونه يتكرّر العيبُ مع كلِّ مسارٍ يُضاف.
3. **A06-01** — تصحيحُ المواصفة (لا الردّ). يمسّ ١٦٨ مسارَ قراءةٍ بتغييرٍ في مولّدٍ واحد.
4. **A06-04/05** — تحقّقُ التتبّع وصدقُ ردّه.
5. **A06-07** — سقفُ `comments` (نمطٌ جاهزٌ في السطح نفسِه).
6. **A06-06 + A06-08 + A06-09** — صدقُ الغلاف والمواصفة.
7. **A06-10/11/12/13/14** — شفافيّةٌ ودقّةُ وصفٍ، غيرُ حاجزة.

> **ملاحظةٌ منهجيّة.** كلُّ ملاحظةٍ أعلاه (عدا A06-14 المعلَّمةِ «متوسّطة») مسنودةٌ إلى ملفٍّ وسطرٍ
> **و** مخرجٍ حقيقيٍّ منسوخٍ من مسبارٍ نُفِّذ على `/api/*` الحيّة. المسابرُ مؤقّتةٌ خارج المستودع
> (`scratchpad/probe/A06Probe*.php`) ولم يُضَف منها ملفٌّ إلى `tests/` — اختباراتُ الانحدار
> المقترحةُ تُكتب في جولةِ التنفيذ، **تفشل أولاً** كما يقتضي `CLAUDE.md`.
