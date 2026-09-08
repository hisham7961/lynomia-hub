# تسليمٌ لفريق التطبيق الأصيل — Mobile App Handoff (Mobile Readiness · §109)

> ما يحتاجه فريقُ iOS/Android لبناء تطبيقٍ فوق منصّةِ Lynomia الجاهزة: **النقاطُ، ودورةُ حياةِ
> المصادقة/الجلسة، والعقدُ (ترويسات + أكواد الخطأ)، وعقدُ الرابطِ العميق، ونموذجُ المزامنة، ثم
> الإعدادُ الخارجيُّ الدقيقُ الباقي** قبل الإطلاق. **الخادمُ يُخوّل، والتطبيقُ غيرُ موثوق** — لا
> تُبنَ صلاحيّةٌ على حالةٍ محليّة. النطاقُ `/api/mobile/v1/*` **مستقلٌّ** عن `/api/v1` (التكامل)
> ولا يمسّه. كلُّ نقطةٍ أدناه **موجودةٌ في `routes/api.php` ومُختبَرةٌ على المحرّكَين**.

---

## 1) القنواتُ الثلاث — أين مكانُ الجوال

`/api/v1` (رمزُ تكاملٍ طويل عبر `ApiAuth`/`ApiToken` — n8n/التكاملات، لا يتغيّر) · `/api/mobile/v1`
(جلساتُ مستخدمٍ أصيلة، هذا التسليم) · الويب. **نواةٌ واحدة، ثلاثةُ عملاء** — نفسُ محرّك الصلاحيّات
(`hub_can`/`hub_scope`/`hub_field_mode`) ونفسُ عقدِ الخطأ (`Api::*`). لا منطقَ أعمالٍ مكرَّر.

---

## 2) دورةُ حياةِ المصادقة والجلسة

جلسةُ الجوال زوجُ رمزَين: **وصولٌ قصير** (`access_token`، افتراض ١٥ دقيقة `setting('mobile.access_ttl_min')`)
+ **تحديثٌ متجدّدٌ لمرّة** (`refresh_token`، افتراض ٣٠ يوماً `setting('mobile.refresh_ttl_days')`)،
كلاهما مربوطٌ بتنصيبٍ (`installation_uuid` يولّده التطبيق) ويُخزَّن **sha256 خادميّاً فقط** — لا نصَّ صريحاً في القاعدة.

```
POST auth/login  { email, password, installation_uuid, platform, app_version, device_model, os_version, locale, tz }
     ├─ نجاح (بلا MFA)         → 200 { access_token, refresh_token, token_type:"Bearer", session_id,
     │                                  installation_id, access_expires_at, refresh_expires_at, access_expires_in }
     ├─ MFA مطلوب              → 401 MFA_REQUIRED { challenge_id, methods:["totp"] }   (لا رموزَ بعد)
     └─ فشلُ اعتماد            → 401 UNAUTHENTICATED   (مجهولُ البريد = خاطئُ الكلمة — لا تعداد · F12)

POST auth/mfa/verify { challenge_id, code }   → 200 (نفسُ حمولة الجلسة)  |  401 MFA_REQUIRED (أعِد ضمن المهلة)

POST auth/refresh { refresh_token }
     ├─ نجاح                   → 200 (زوجٌ جديد؛ الرمزُ القديمُ يبطل — تدويرٌ لمرّة)
     └─ إعادةُ استعمالِ مُدوَّر → 401 REFRESH_TOKEN_INVALID  (تُبطَل العائلةُ family_id كاملةً + حدثٌ أمنيّ + إشعار)
```

- **الحراسُ الخمسة** (موقوف/مقفول/منتهٍ/`allowed_ips`/lockdown) تُفرَض بعد إثباتِ الاعتماد وحدَه
  (نظيرُ الويب · لا كشفَ حالةٍ لمجهول) في الدخول، وتُعاد على كلِّ طلبٍ مُصادَقٍ في `MobileSessionAuth`.
- **الخنقُ نظيرُ الويب:** `auth/login` `10,1` · `auth/mfa/verify` `6,1` · `auth/refresh` `20,1`
  (`routes/api.php:104-108`) — لا دخولٌ موازٍ أضعف.
- كلُّ طلبٍ مُصادَقٍ يحمل `Authorization: Bearer <access_token>`؛ انتهاءُ الوصول ⇒ `UNAUTHENTICATED`
  (استعمل `refresh`)، وجلسةٌ مُبطَلة ⇒ `SESSION_REVOKED` (أعِد الدخول).
- **إدارةُ الجلسات/التصعيد:** `GET auth/sessions` · `DELETE auth/sessions/{id}` · `POST auth/logout` ·
  `POST auth/logout-all` · `POST auth/step-up` (تصعيدٌ مربوطٌ بالجلسة+الغرض+المهلة).
- **الحيويّة (biometrics) على العميل فقط** — لا يستقبل الخادمُ قوالبَ بصمةٍ قط.

---

## 3) الترويسات (سياقٌ/تليمتري — لا تخويلَ أبداً)

| الترويسة | الغرض |
|---|---|
| `Authorization: Bearer <access_token>` | الهويّة (النقاطُ المُصادَقة) |
| `Idempotency-Key: <uuid>` | على كلِّ أثرٍ قابلٍ لإعادة المحاولة (إنشاء/إجراء/تعليق/رسالة/إتمامُ رفع/دفعةُ تتبّع) |
| `If-Match: "<version>"` | القفلُ التفاؤليّ على الكتابة ⇒ `VERSION_CONFLICT` عند التعارُض |
| `X-Lynomia-App-Platform` / `-Version` / `-Build` / `-Installation-Id` | تليمتري (تحسبُ عليه بوّابةُ الإصدار `update_required`) |
| `X-Lynomia-Company` / `-Client` | **تضييقُ العرض فقط** — تُتقاطَع مع المسموح ولا توسّع الصلاحيّة أبداً |
| `X-Request-Id` | معرّفُ الارتباط (يُختَم على الردّ والتدقيق والإشعار — الواحدُ نفسُه) |

**عقدُ الخطأ:** غلافٌ `{data|error, code, details, request_id}` وترويسةُ `X-API-Version: 1`. الأكوادُ
مُعادةٌ من `Api::*` (الكلاينت يقرأ `code` لا العربيّة). الأربعةُ المُضافةُ للجوال:
`MFA_REQUIRED` (401) · `REFRESH_TOKEN_INVALID` (401) · `SESSION_REVOKED` (401) · `APP_UPDATE_REQUIRED` (426)
— قيَمُ enum إضافيّةٌ لا تكسر مستهلكي v1.

---

## 4) خريطةُ النقاط الكاملة (٥٨ مساراً · كلُّها في `routes/api.php`)

**عامّةٌ (بلا رمز وصول):**

| المسار | الاسم |
|---|---|
| `POST auth/login` · `POST auth/mfa/verify` · `POST auth/refresh` | `mobile.auth.login/mfa_verify/refresh` |
| `GET app-config` · `GET health` | `mobile.app_config` · `mobile.health` |
| `GET openapi.json` | `mobile.openapi` (مواصفةٌ حيّةٌ 3.1 منفصلةٌ عن v1) |

**مُصادَقة** (خلف `mobile.session` + `mobile.context`):

| المجال | المسارات |
|---|---|
| الجلسة | `POST auth/logout` · `POST auth/logout-all` · `GET auth/sessions` · `DELETE auth/sessions/{id}` · `POST auth/step-up` |
| السياق/المخطّط | `GET context` · `GET bootstrap` (ETag) · `GET schema/modules` · `GET schema` (ETag) |
| الأعمال | `GET approvals` · `GET approvals/{id}` · `POST approvals/{id}/approve` · `POST approvals/{id}/reject` · `GET home` · `GET search` · `GET prefs` · `PUT prefs` · `POST prefs/pin` |
| الاتصال | `GET notifications` · `GET notifications/unread-count` · `POST notifications/read-all` · `GET notifications/{id}/target` · `POST notifications/{id}/read` · `GET comments` · `POST comments` · `GET dm/threads` · `GET dm/threads/{user}/messages` · `POST dm/threads/{user}/send` · `POST dm/threads/{user}/read` · `POST push/register` · `POST push/unregister` · `GET push/admin/status` · `POST push/admin/test` |
| الملفّات/الماسح/الموقع | `POST files/upload-session` · `PUT files/upload-session/{id}/chunk` · `POST files/upload-session/{id}/complete` · `POST files/attach` · `GET files/{id}/download` · `GET files/{id}/stream` · `GET identity/resolve/{q}` · `POST tracking/start` · `POST tracking/{session}/points` · `POST tracking/{session}/end` |
| المزامنة + CRUD | `GET sync/{module}` · `GET {module}` · `POST {module}` · `GET {module}/{id}` · `PUT {module}/{id}` · `PATCH {module}/{id}` · `DELETE {module}/{id}` · `GET {module}/{id}/actions` · `POST {module}/{id}/actions/{action}` |

كلُّ الحرفيّاتِ مُسجَّلةٌ **قبل** الـcatch-all `{module}` كي لا يبتلعها (عقدٌ أمنيٌّ · F9)، ومواصفةُ
`GET /api/mobile/v1/openapi.json` مولّدةٌ من المسارات الحيّة — **هي المرجعُ الآليُّ لتوليد عميلٍ**.
التفاصيلُ في: `03-authentication-security.md` · `04-permissions-context.md` · `05-offline-sync.md` ·
`06-notifications-push.md` · `07-files-scanner-location.md` · `08-versioning-deep-links.md`.

---

## 5) عقدُ الرابطِ العميق

الوجهةُ القانونيّة `{module, id, action}` (المصدرُ الواحد `NotificationLink::target`) — لا اسمَ
شاشةٍ صلب. `action:"show"` ⇒ `https://<host>/m/{module}/{id}`. التطبيقُ يلتقط `/m/*` و`/app/*`.
تُصدرها: الإشعاراتُ (`target`)، البحثُ (`{module,id}` لكلِّ نتيجة)، اللوحةُ، وحمولةُ الدفع. راجع
`08-versioning-deep-links.md` و`deep-links/README.md`.

---

## 6) نموذجُ المزامنة (كيف يبني التطبيقُ خبيئتَه)

`GET sync/{module}?updated_since=&cursor=&limit=` بمؤشّرٍ حتميّ على `(updated_at, id)` وشواهدِ حذف
وتصنيفٍ لكل وحدة (`sync_class`):

- `CACHEABLE_INCREMENTAL` (٨١ وحدة): خبّئ، زامِن بالمؤشّر، طبّق `tombstones`، احتفظ بـ`version`
  وابعثه في `If-Match` عند الكتابة، وأعِد المزامنةَ كاملةً حين تتبدّل `sync_version`.
- `CACHEABLE_READ_ONLY`: خبّئ للقراءة، بلا `If-Match`.
- `ONLINE_ONLY` (الافتراض): لا تخبّئ سجلّاً.
- `SENSITIVE_NO_PERSIST` (`phones`/`carriers`/`vault`): **لا تكتب على القرص أبداً** — لا يُبَثُّ سجلٌّ أصلاً.
- `NOT_APPLICABLE` (`users`): خذ من `context`/`bootstrap`.

حقلُ `sec` لا يُخبَّأ قطّ (يُجرَّد خادميّاً). التفصيلُ في `05-offline-sync.md`.

---

## 7) الإعدادُ الخارجيُّ الباقي قبل الإطلاق (NOT_CONFIGURED — يوفّره فريقُ التطبيق)

كلُّ ما يلي **واجهاتٌ مبنيّةٌ تنتظر معرّفاتٍ خارجيّة**؛ الخادمُ يقول الحقيقةَ (NOT_CONFIGURED) ولا
يُختلَق شيء. لا شيءَ من هذا يُخوّل على «حالةِ العميل المُدَّعاة».

| البند | لماذا | أين يُوصَل |
|---|---|---|
| **FCM: `project_id` + رمزُ وصولِ حساب الخدمة** | تسليمُ الدفع الحقيقيّ (بلاها المزوّدُ الصفريُّ ⇒ `not_configured` لكل إشعار) | `setting('mobile.push_driver'=fcm')` + `mobile.push_fcm_project_id` + `mobile.push_fcm_access_token`؛ تحقّق `GET push/admin/status` (§`06`) |
| **APNs مباشر (اختياريّ)** | دفعُ iOS دون وساطة FCM | **منفّذُ `PushProvider` جديدٌ** (نظيرُ `FcmPushProvider`) + `.p8`/team id/key id/bundle id — غيرُ مبنيٍّ بعد؛ الرمزُ يُسجَّل بـ`provider=apns` بنيويّاً |
| **App Attest / DeviceCheck (iOS)** | إثباتُ سلامةِ الجهاز خادميّاً | معرّفُ الفريق + الحزمة + مفتاحُ App Attest — **تحقّقٌ خادميٌّ فقط** (واجهةٌ محجوزة NOT_CONFIGURED) |
| **Play Integrity (Android)** | إثباتُ سلامةِ الجهاز خادميّاً | مشروعُ Google Cloud + رمزُ Play Integrity — **تحقّقٌ خادميٌّ فقط** (واجهةٌ محجوزة) |
| **موقفُ root/jailbreak** | إشارةُ رصدٍ لا تحكُّم | يُرصَد لا يُخوَّل عليه أبداً (التطبيقُ غيرُ موثوق) |
| **روابطُ المتجر (iOS/Android)** | زرُّ التحديث في بوّابة الإصدار | `setting('mobile.store_url_ios'|'…_android')` — تظهر في `app-config` |
| **بوّابةُ الإصدار (min/latest/force)** | حجبُ الإصدارات القديمة | `setting('mobile.min_version_ios'|'latest_version_ios'|'…android'|'force_update')` — **فارغ = لا حجب** |
| **Universal/App Links: Apple Team+Bundle، Android package+SHA-256، النطاق المُصرَّح** | فتحُ الروابط في التطبيق | `setting('mobile.dl_apple_team_id'|'dl_apple_bundle_id'|'dl_android_package'|'dl_android_fingerprints')` + `config('hub.mobile.deep_links.host')`؛ تُخدَم تلقائيّاً في `/.well-known/*` (§`08`) |
| **رابطُ الدعم** | شاشةُ المساعدة | `setting('mobile.support_url')` — يظهر في `app-config` |
| **توقيعُ التطبيق (signing)** | نشرُ المتجر + تطابقُ App Links | شهاداتُ التوقيع (خارجُ الخادم كليّاً) — بصمةُ SHA-256 تُدخَل في `assetlinks` أعلاه |

**قبل الإطلاق تحقّق:** `GET health` = `ok` · `GET push/admin/status` = `configured:true` · `/.well-known/*`
ترويستُها `X-Deep-Links-Status: CONFIGURED` · بوّابةُ الإصدار مضبوطةٌ بحدٍّ أدنى · روابطُ المتجر/الدعم غيرُ `null`.

---

## 8) ما لا يجب افتراضُه

- لا تبنِ صلاحيّةً على زرٍّ مخفيٍّ/اسمِ مسار/دورٍ مُدَّعىً/`user_id`/رايةِ مالكٍ يرسلها التطبيق —
  الخادمُ وحدَه يُخوّل.
- لا تُخبّئ `SENSITIVE_NO_PERSIST` ولا أيَّ حقلِ `sec`.
- لا تعتمد على تسليمِ الدفع للأمانِ الوظيفيّ: الإشعارُ الداخليُّ هو الحقيقة (`GET notifications`)،
  والدفعُ إثراءٌ قد يفشل بلا فقدِ الإشعار.
- لا ترسل قوالبَ حيويّةٍ للخادم، ولا تعتمد على `update_required` كحاجزٍ خادميٍّ (هو إشارةُ عرضٍ اليوم).

---

## 9) التنقّلُ من الخادم (IA · الطور 9)

التطبيقُ لا يبني قائمةَ تنقّلٍ بيده: يقرؤها من الخادم — **نفسُ معماريةِ الويب** (لا `mobile_nav`
ثانٍ)، مُنطَّقةً بصلاحية المستخدم.

- **`GET /api/mobile/v1/navigation`** (ETag/304): يعيد `{ schema_version, feature_flags, ia:{ surfaces[], domains[] } }`.
  كلُّ سطحٍ/مجالٍ: `{ key, label, icon, sections:[ { key, label, destinations:[ … ] } ] }`.
  كلُّ وجهة: `{ label, type, importance, mobile, module?, route?, args? }`.
  - `type` ∈ `module|center|admin|entity|personal|system`.
  - `module` = مفتاحٌ منطقيٌّ لشاشةِ قائمةِ الوحدة في التطبيق (الوجهةُ الأساسيّة).
  - `route`/`args` = اسمُ مسارِ الويب (مرجعُ ربطٍ عميقٍ للوجهات غيرِ الوحدات).
  - `mobile` ∈ `suitable|deep-link-only|web-only` — الوجهةُ `web-only` تُفتح في متصفّحٍ مضمَّن.
- **`GET /api/mobile/v1/bootstrap`** يحمل `ia` (نفسُ الشجرة) إلى جانب `nav` (توافقٌ خلفيٌّ — مجموعاتُ `hub_nav`).
  فالإقلاعُ البارد يكفي لرسمِ التنقّل دون طلبٍ ثانٍ؛ و`GET navigation` للتحديثِ عند تغيّر الصلاحية.
- **التنطيقُ سابقٌ للتسلسل:** ما لا يراه المستخدمُ لا يُسلسَل — لا تسريبَ اسمِ وحدةٍ/مركزٍ محجوب.
  ومطابقةُ الصلاحيةِ لِـ`visibleDomains` على الويب مضمونةٌ باختبار (`MobileIaTest`).
- **الحرسُ لا يُبنى على التنقّل:** كلُّ وجهةٍ تبقى محروسةً بمتحكّمها؛ ظهورُها في القائمة عرضٌ لا تخويل.
