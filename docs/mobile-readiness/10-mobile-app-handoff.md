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

<!-- routes:begin (مولَّد: php artisan hub:mobile-handoff --write — لا يُحرَّر باليد) -->
## 4) خريطةُ النقاط الكاملة (١١٣ مساراً · كلُّها في `routes/api.php`)

مولَّدةٌ من المسارات الحيّة — العامّةُ بلا رمز وصول (`public`)، والباقي خلف `mobile.session` + `mobile.portal` + `mobile.context`.

| المجال | الطريقة والمسار (بعد `/api/mobile/v1/`) | الاسم | المصادقة |
|---|---|---|---|
| actions | `GET {module}/{id}/actions` | `mobile.resource.actions` | مُصادَقة |
| actions | `POST {module}/{id}/actions/{action}` | `mobile.resource.run_action` | مُصادَقة |
| activation | `GET activation/{token}` | `mobile.activation.show` | عامّة |
| activation | `POST activation/{token}/complete` | `mobile.activation.complete` | عامّة |
| approvals | `GET approvals` | `mobile.approvals.index` | مُصادَقة |
| approvals | `GET approvals/{id}` | `mobile.approvals.show` | مُصادَقة |
| approvals | `POST approvals/{id}/approve` | `mobile.approvals.approve` | مُصادَقة |
| approvals | `POST approvals/{id}/reject` | `mobile.approvals.reject` | مُصادَقة |
| ask | `POST ask` | `mobile.ask.run` | مُصادَقة |
| ask | `DELETE ask/threads` | `mobile.ask.threads.destroy_all` | مُصادَقة |
| ask | `GET ask/threads` | `mobile.ask.threads.index` | مُصادَقة |
| ask | `DELETE ask/threads/{id}` | `mobile.ask.threads.destroy` | مُصادَقة |
| ask | `GET ask/threads/{id}` | `mobile.ask.threads.show` | مُصادَقة |
| attendance | `POST attendance/check-in` | `mobile.attendance.check_in` | مُصادَقة |
| attendance | `POST attendance/check-out` | `mobile.attendance.check_out` | مُصادَقة |
| attendance | `GET attendance/today` | `mobile.attendance.today` | مُصادَقة |
| auth | `GET app-config` | `mobile.app_config` | عامّة |
| auth | `POST auth/login` | `mobile.auth.login` | عامّة |
| auth | `POST auth/logout` | `mobile.auth.logout` | مُصادَقة |
| auth | `POST auth/logout-all` | `mobile.auth.logout_all` | مُصادَقة |
| auth | `POST auth/mfa/verify` | `mobile.auth.mfa_verify` | عامّة |
| auth | `POST auth/refresh` | `mobile.auth.refresh` | عامّة |
| auth | `GET auth/sessions` | `mobile.auth.sessions.index` | مُصادَقة |
| auth | `DELETE auth/sessions/{id}` | `mobile.auth.sessions.destroy` | مُصادَقة |
| auth | `POST auth/step-up` | `mobile.auth.step_up` | مُصادَقة |
| clients | `GET clients/{client}/members` | `mobile.clients.members.index` | مُصادَقة |
| clients | `POST clients/{client}/members` | `mobile.clients.members.invite` | مُصادَقة |
| clients | `DELETE clients/{client}/members/{membership}` | `mobile.clients.members.revoke` | مُصادَقة |
| clients | `PUT clients/{client}/members/{membership}` | `mobile.clients.members.role` | مُصادَقة |
| comments | `GET comments` | `mobile.comments.index` | مُصادَقة |
| comments | `POST comments` | `mobile.comments.store` | مُصادَقة |
| comments | `GET comments/{id}/attachment` | `mobile.comments.attachment` | مُصادَقة |
| comments | `POST comments/{id}/react` | `mobile.comments.react` | مُصادَقة |
| context | `GET bootstrap` | `mobile.bootstrap` | مُصادَقة |
| context | `GET context` | `mobile.context` | مُصادَقة |
| context | `GET navigation` | `mobile.navigation` | مُصادَقة |
| conversations | `GET conversations` | `mobile.conversations.index` | مُصادَقة |
| conversations | `GET conversations/{id}/since` | `mobile.conversations.since` | مُصادَقة |
| conversations | `POST conversations/{id}/typing` | `mobile.conversations.typing` | مُصادَقة |
| crud | `GET {module}` | `mobile.resource.index` | مُصادَقة |
| crud | `POST {module}` | `mobile.resource.store` | مُصادَقة |
| crud | `DELETE {module}/{id}` | `mobile.resource.destroy` | مُصادَقة |
| crud | `GET {module}/{id}` | `mobile.resource.show` | مُصادَقة |
| crud | `PATCH {module}/{id}` | `mobile.resource.patch` | مُصادَقة |
| crud | `PUT {module}/{id}` | `mobile.resource.update` | مُصادَقة |
| crud | `GET {module}/{id}/versions` | `mobile.versions.index` | مُصادَقة |
| custody | `POST custody/{id}/handover` | `mobile.custody.handover` | مُصادَقة |
| custody | `POST custody/{id}/recover` | `mobile.custody.recover` | مُصادَقة |
| custody | `GET me/custody` | `mobile.me.custody` | مُصادَقة |
| dm | `GET dm/messages/{id}/attachment` | `mobile.dm.attachment` | مُصادَقة |
| dm | `POST dm/messages/{id}/react` | `mobile.dm.react` | مُصادَقة |
| dm | `GET dm/threads` | `mobile.dm.threads` | مُصادَقة |
| dm | `GET dm/threads/{user}/messages` | `mobile.dm.messages` | مُصادَقة |
| dm | `POST dm/threads/{user}/read` | `mobile.dm.read` | مُصادَقة |
| dm | `POST dm/threads/{user}/send` | `mobile.dm.send` | مُصادَقة |
| dm | `GET dm/threads/{user}/since` | `mobile.dm.since` | مُصادَقة |
| dm | `POST dm/threads/{user}/typing` | `mobile.dm.typing` | مُصادَقة |
| files | `GET files` | `mobile.files.index` | مُصادَقة |
| files | `POST files/attach` | `mobile.files.attach` | مُصادَقة |
| files | `POST files/upload-session` | `mobile.files.upload_session` | مُصادَقة |
| files | `PUT files/upload-session/{id}/chunk` | `mobile.files.upload_chunk` | مُصادَقة |
| files | `POST files/upload-session/{id}/complete` | `mobile.files.upload_complete` | مُصادَقة |
| files | `DELETE files/{id}` | `mobile.files.destroy` | مُصادَقة |
| files | `GET files/{id}/download` | `mobile.files.download` | مُصادَقة |
| files | `GET files/{id}/stream` | `mobile.files.stream` | مُصادَقة |
| health | `GET health` | `mobile.health` | عامّة |
| home | `GET home` | `mobile.home` | مُصادَقة |
| inventory | `GET inventory/sessions` | `mobile.inventory.index` | مُصادَقة |
| inventory | `POST inventory/sessions` | `mobile.inventory.freeze` | مُصادَقة |
| inventory | `GET inventory/sessions/{id}` | `mobile.inventory.show` | مُصادَقة |
| inventory | `POST inventory/sessions/{id}/close` | `mobile.inventory.close` | مُصادَقة |
| inventory | `POST inventory/sessions/{id}/reconcile` | `mobile.inventory.reconcile` | مُصادَقة |
| inventory | `POST inventory/sessions/{id}/scan` | `mobile.inventory.scan` | مُصادَقة |
| leaves | `POST leaves/{id}/decide` | `mobile.leaves.decide` | مُصادَقة |
| me | `GET me/documents` | `mobile.me.documents.index` | مُصادَقة |
| me | `GET me/documents/{id}/file` | `mobile.me.documents.file` | مُصادَقة |
| meta | `GET openapi.json` | `mobile.openapi` | عامّة |
| notifications | `GET notifications` | `mobile.notifications.index` | مُصادَقة |
| notifications | `POST notifications/read-all` | `mobile.notifications.read_all` | مُصادَقة |
| notifications | `GET notifications/unread-count` | `mobile.notifications.unread` | مُصادَقة |
| notifications | `POST notifications/{id}/read` | `mobile.notifications.read` | مُصادَقة |
| notifications | `GET notifications/{id}/target` | `mobile.notifications.target` | مُصادَقة |
| portal | `GET portal/conversations` | `mobile.portal.conversations.index` | مُصادَقة |
| portal | `GET portal/conversations/{id}` | `mobile.portal.conversations.show` | مُصادَقة |
| portal | `GET portal/documents` | `mobile.portal.documents.index` | مُصادَقة |
| portal | `GET portal/documents/{id}` | `mobile.portal.documents.show` | مُصادَقة |
| portal | `GET portal/engagements` | `mobile.portal.engagements` | مُصادَقة |
| portal | `GET portal/home` | `mobile.portal.home` | مُصادَقة |
| portal | `GET portal/invoices` | `mobile.portal.invoices.index` | مُصادَقة |
| portal | `GET portal/invoices/{id}` | `mobile.portal.invoices.show` | مُصادَقة |
| portal | `GET portal/projects` | `mobile.portal.projects.index` | مُصادَقة |
| portal | `GET portal/projects/{id}` | `mobile.portal.projects.show` | مُصادَقة |
| prefs | `GET prefs` | `mobile.prefs.index` | مُصادَقة |
| prefs | `PUT prefs` | `mobile.prefs.update` | مُصادَقة |
| prefs | `POST prefs/pin` | `mobile.prefs.pin` | مُصادَقة |
| presence | `GET presence` | `mobile.presence` | مُصادَقة |
| push | `GET push/admin/status` | `mobile.push.admin.status` | مُصادَقة |
| push | `POST push/admin/test` | `mobile.push.admin.test` | مُصادَقة |
| push | `POST push/register` | `mobile.push.register` | مُصادَقة |
| push | `POST push/unregister` | `mobile.push.unregister` | مُصادَقة |
| saved | `GET saved` | `mobile.saved.index` | مُصادَقة |
| saved | `POST saved` | `mobile.saved.store` | مُصادَقة |
| saved | `DELETE saved/{id}` | `mobile.saved.destroy` | مُصادَقة |
| scanner | `GET identity/resolve/{q}` | `mobile.identity.resolve` | مُصادَقة |
| schema | `GET schema` | `mobile.schema` | مُصادَقة |
| schema | `GET schema/modules` | `mobile.schema.modules` | مُصادَقة |
| search | `GET search` | `mobile.search` | مُصادَقة |
| sync | `GET sync/{module}` | `mobile.sync` | مُصادَقة |
| tracking | `POST tracking/start` | `mobile.tracking.start` | مُصادَقة |
| tracking | `POST tracking/{session}/end` | `mobile.tracking.end` | مُصادَقة |
| tracking | `POST tracking/{session}/points` | `mobile.tracking.points` | مُصادَقة |
| work | `GET work/daily-report` | `mobile.work.daily_report` | مُصادَقة |
| work | `GET work/today` | `mobile.work.today` | مُصادَقة |
<!-- routes:end -->

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

---

## 10) تجربةُ العميل — الجمهورُ الثاني (v2.451.0 · §12–§18)

التطبيقُ يخدم جمهورَين على سكّةِ دخولٍ **واحدة**: الداخليَّ والعميلَ. **وضعُ الحساب
حقيقةٌ خادميّة** — `users.account_type` (`internal|client` · `hub_is_client()`)، لا
يُستنتَج من نطاقِ بريدٍ ولا يقرّره التطبيق:

- **الوضعُ يصل في `bootstrap`**: `user.account_type` + `memberships[]` (عضويّاتُه
  الفعّالة `{client_id, client_name, role}`) + `feature_flags.is_client`. ولحسابِ
  العميل تعود `ia` **بوّابيّةً** (مجالٌ واحد `portal` بوجهاتٍ `type:'portal'`:
  home/engagements/projects/documents/invoices/conversations) و`nav=[]` — لا
  وجهةَ داخليّةً تُسلسَل أصلاً. و`GET home` يعيد `data.mode` = `client|internal`
  ولحسابِ العميل حمولةَ بيتِ البوّابة نفسَها (لا لوحةٌ داخليّةٌ بأزرارٍ مخفيّة).
- **`MobilePortalGuard` (`mobile.portal`) على مجموعةِ المصادقة كلِّها**: قائمةٌ بيضاءُ
  **فوق** مصفوفةِ الأدوار — نظيرُ `PortalGuard` الويب. حسابُ العميل خارجَها ⇒
  `404 RESOURCE_NOT_FOUND` (دورٌ مضبوطٌ خطأً لا يُسرِّب شيئاً)، والداخليُّ على
  `portal/*` ⇒ `403 FORBIDDEN`. فلا يبني التطبيقُ أمناً على إخفاءِ تنقّل — الخادمُ
  يصدّ النداءَ المباشر (مُثبَتٌ في `MobilePortalGuardTest` بموظّفٍ كاملِ المصفوفة
  حُوِّل `client`).
- **قرّاءُ البوّابة جوهرُ الويب نفسُه** (`ClientPortalData`): فشلٌ مغلقٌ على العضويّة
  الفعّالة (معلَّقٌ = عالمٌ فارغ)، أعمدةٌ آمنةٌ فقط (لا `cost`/`budget`/تكلفة —
  **ما لا يُحمَّل لا يُسرَّب**)، فواتيرُ مبيعاتٍ حصراً، وثائقُ `audience='client'`،
  وغرفٌ بعضويّةٍ **وجمهورٍ** معاً ورسائلُ `internal` محجوبةٌ بنيويّاً.
- **تفعيلُ الحساب (سكّة B.1):** الدعوةُ تُنشئ مستخدمَ `client` **بلا كلمةِ سرٍّ تُرسَل
  أبداً**؛ رسالةُ الصادر تحمل رابطَ `activate/{token}` ورمزَ ٦ أرقام. التطبيقُ يلتقط
  الرابطَ: `GET activation/{token}` ⇒ `{status: pending|expired, email_masked}`، ثم
  `POST activation/{token}/complete {otp, password, password_confirmation}` — إتمامٌ
  ذرّيٌّ (حرقٌ لمرّة، سقفُ محاولات، كلمةٌ ضعيفةٌ لا تحرق الرمز، ترقيةُ العضويّات
  `invited→active`) يعيد `{activated:true, email}` **بلا جلسة** — الدخولُ بعدها عبر
  `auth/login` الواحدة.
- **إدارةُ الأعضاء (للمدير الداخليّ · `clients:e` + `hub_scope`):** منحُ `owner`
  خلف تصعيدِ `action:clients:member_owner` وسحبُ الوصول خلف
  `action:clients:member_revoke` (428 `STEP_UP_REQUIRED` مع `details.purpose` —
  المِنحةُ مربوطةٌ بالغرض لا تتبادل). السحبُ تعليقٌ فوريُّ الأثر.
- **قائمةُ المحظور على العميل قطعيّاً** (تُفرَض خادميّاً): الخوادم/الخزنة/الأمن/
  التدقيق/ماليّةُ الداخل (تكلفة/هامش/رواتب)/غرفُ الداخل وتعليقاتُ `internal`
  ومرفقاتُها/الموظّفون/الإعدادات — كلُّها 404 بالنداء المباشر لا إخفاءَ زرّ.
