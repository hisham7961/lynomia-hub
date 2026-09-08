# 02 · عقدُ واجهةِ الجوال `/api/mobile/v1`

> Mobile Readiness · الطور H · H.1. العقدُ الكامل، **مبنيٌّ على الشجرة الحقيقيّة**.
> المصدرُ الحيُّ للحقيقة: `GET /api/mobile/v1/openapi.json` (مولَّدٌ من المسارات — لا
> يتقادم). هذه الوثيقةُ شرحُه للبشر. الأكوادُ والمفاتيحُ والمساراتُ بالإنجليزية حرفاً.

## 1. القاعدةُ والنطاق

- **Base URL:** `{host}/api/mobile/v1` — نطاقٌ **مستقلٌّ** عن `/api/v1` (لا يظلّله ولا
  يُمَسّ). `host` = نطاقُ التثبيت.
- **إصدارُ العقد:** `1`. يُبثّ `X-API-Version: 1` على كل ردّ (`MobileSessionAuth.php:92`؛
  والمواصفةُ `info.version = "1"`).
- **الترميز:** JSON دائماً؛ `Accept: application/json` تُفرَض خادميّاً
  (`MobileSessionAuth.php:33`) والعربيةُ تُبثّ بـ`JSON_UNESCAPED_UNICODE`.
- **مجموعتان (Critic F5):**
  - **عامّةٌ** (بلا رمزِ وصول): login · mfa/verify · refresh · app-config · health ·
    openapi.json — كلٌّ بخنقٍ ضيّقٍ خاصّ (`routes/api.php:102-122`).
  - **مُصادَقةٌ** خلف `['throttle:api','mobile.session','mobile.context']`
    (`routes/api.php:127`) — بقيّةُ السطح.

## 2. الترويسات

### 2a. المصادقة
```
Authorization: Bearer <access_token>
```
رمزُ **الوصول** (`access_token` من login/refresh). تُطابِقه `MobileSessionAuth` بـ
`hash('sha256', $token)` ضدّ `mobile_sessions.access_hash`. `auth/refresh` وحدَه
يصادِق بـ**رمز التحديث** (في الجسم `refresh_token` أو Bearer · `MobileAuthController.php:225`).

### 2b. السياق والتليمتري (سياقٌ فقط — **لا تخوّل أبداً**)
| الترويسة | الدور |
|---|---|
| `X-Lynomia-Company` | تضييقُ العرض لشركةٍ ضمن المسموح (يُتجاهَل خارجه — §04) |
| `X-Lynomia-Client` | تضييقُ العرض لعميلٍ ضمن المسموح |
| `X-Lynomia-App-Platform` | `ios`/`android` — تقودُ بوّابةَ الإصدار (`app-config`/`health`) |
| `X-Lynomia-App-Version` | إصدارُ العميل — لمقارنةِ `version_gate` |
| `X-Lynomia-App-Build` | رقمُ البناء (تليمتري) |
| `X-Lynomia-Installation-Id` | معرّفُ التنصيب (تليمتري) |
| `X-Request-Id` | معرّفُ الطلب — يُعاد في `request_id` وقيدِ التدقيق (سكّةُ `Api::requestId`) |

**قاعدةٌ حاكمة:** لا هويّةٌ/دورٌ/ملكيّةٌ/`user_id` يرسلها العميلُ تُصدَّق — الهويّةُ من
الجلسة المُطابَقة وحدَها. الترويسةُ لا تُوسِّع صلاحيةً قط.

## 3. غلافُ الاستجابة (`app/Support/Api.php`)

**نجاحٌ (كائن):**
```json
{ "data": { ... }, "request_id": "..." }
```
**نجاحٌ (قائمةٌ مُصفَّحة · `Api::list` `:264`):**
```json
{ "data": [ ... ], "total": 42, "page": 1, "last_page": 3,
  "meta": { "page": 1, "per": 20, "total": 42, "last_page": 3, "has_more": true },
  "request_id": "..." }
```
**خطأٌ (`Api::error` `:149`):**
```json
{ "error": "رسالةٌ عربيّةٌ للعرض", "code": "MACHINE_CODE",
  "message": "...", "details": { ... }, "request_id": "..." }
```
+ ترويستا `X-Error-Code` و`X-Request-Id`. **العميلُ يُفرّع على `code` (الإنجليزيّ) لا
على النصّ العربيّ.**

### 3a. الأكواد الآليّة (`Api::CODES` · `Api.php:62`)
تُعاد استعمالُ أكوادِ `/api/v1` كلِّها، وتُضاف **أربعةٌ للجوال** (`Api.php:53-56`):

| الكود | HTTP | متى |
|---|---|---|
| `MFA_REQUIRED` | 401 | login يتطلّب خطوةً ثانية — `challenge_id`+`methods` في `details` |
| `REFRESH_TOKEN_INVALID` | 401 | رمزُ تحديثٍ غيرُ صالحٍ/منتهٍ/أُعيد استعمالُه |
| `SESSION_REVOKED` | 401 | الجلسةُ أُبطلت (خروجٌ/إلغاءٌ عن بُعد) |
| `APP_UPDATE_REQUIRED` | 426 | إصدارُ التطبيق دون الحدِّ الأدنى (`Api::codeFor(426)` `:179`) |

الأكوادُ المشتركة الأكثرُ صلةً: `UNAUTHENTICATED` (401) · `ACCOUNT_RESTRICTED` (403) ·
`FORBIDDEN` (403) · `RESOURCE_NOT_FOUND` (404) · `VALIDATION_FAILED` (422) ·
`VERSION_CONFLICT` (409) · `APPROVAL_REQUIRED` (409) · `STEP_UP_REQUIRED` (428) ·
`IDEMPOTENCY_IN_PROGRESS` (409) · `IDEMPOTENCY_KEY_REUSED` (422) · `RATE_LIMITED` (429) ·
`MAINTENANCE`/`LOCKDOWN`/`SERVICE_UNAVAILABLE` (503).

## 4. التزامُن والتعارُض

### 4a. `If-Match` ⇒ `VERSION_CONFLICT` (`Api::assertVersion` `:398`)
الكتابةُ (PUT/PATCH/DELETE، وتنفيذُ إجراءِ `e`) تقرأ `If-Match: "<n>"` (أو `_version`
في الجسم) وتقارنه بـ`$m->version` (عمود `HasVersions`)؛ عدمُ التطابق ⇒ 409 مع
`{current_version, your_version}`. غيابُه = مسموح (عميلٌ قديم). واجبٌ على **كلِّ كتابةِ
جوال** (نُفِّذ عبر وراثة محرّك v1 · `MobileResourceController.php:188`).

### 4b. ETag / `If-None-Match` (304)
`GET bootstrap`/`schema`/`schema/modules` تُصدِر `ETag` وتردّ `304` عند التطابق
(`Api::etagJson` `:468`, `MobileContextController::bootstrap` `:118-130`). البصمةُ على
الحمولة الثابتة وحدَها — `server_time` المتقلّب خارجَها كي يعمل الـ304.

### 4c. `Idempotency-Key` (مالكُ الجوال · Critic F1)
كلُّ عمليةٍ ذاتِ أثرٍ قابلٍ للإعادة تقبل `Idempotency-Key: <uuid>`:
create/action/approval/comment/message/file-complete/tracking/pin. المالكُ عبر
`Idempotency::owner` (`:40`) = `mobile_session->id` — فإعادةُ المحاولة بالمفتاح نفسِه
تُعيد الردَّ المخزَّن، **ولا يُعاد ردُّ مستخدمٍ لآخر**. البصمةُ المختلفةُ بالمفتاح نفسِه
⇒ `IDEMPOTENCY_KEY_REUSED`؛ والجاري ⇒ `IDEMPOTENCY_IN_PROGRESS`.

## 5. الترقيمُ والمؤشّر

- **القوائم القياسيّة** (CRUD/بحث): ترقيمُ صفحاتٍ (`page`/`per`) عبر `Api::list`، مع
  `meta.has_more`. الفرزُ `sort=field` / `sort=-field` / `dir=asc|desc` من **الحقول
  الظاهرة** فقط (`Api::sort` `:286` — الفرزُ بحقلٍ محجوب إفشاء).
- **التدفّقاتُ عاليةُ الحجم** (`sync`، والإشعارات): **مؤشّرٌ معتِمٌ** (cursor). المزامنة
  `GET sync/{module}?updated_since=&cursor=&limit=` تعيد
  `{records, tombstones, next_cursor, has_more, sync_version}` بترتيبٍ حتميّ
  `updated_at,id` (`MobileSyncController.php:176-181`). `limit` مسقوفٌ بـ
  `config('hub.mobile.sync.max_limit', 500)` مهما طُلب.

## 6. جدولُ المسارات الكامل (٥٨ مساراً)

المصدرُ: `php artisan route:list --path=api/mobile`. `[عامّ]` = بلا `mobile.session`.

### المصادقة (`mobile.auth.*`)
| Verb | Path | الاسم | الخنق |
|---|---|---|---|
| POST | `auth/login` | `mobile.auth.login` | `10,1` [عامّ] |
| POST | `auth/mfa/verify` | `mobile.auth.mfa_verify` | `6,1` [عامّ] |
| POST | `auth/refresh` | `mobile.auth.refresh` | `20,1` [عامّ] |
| POST | `auth/logout` | `mobile.auth.logout` | `api` |
| POST | `auth/logout-all` | `mobile.auth.logout_all` | `api` |
| GET | `auth/sessions` | `mobile.auth.sessions.index` | `api` |
| DELETE | `auth/sessions/{id}` | `mobile.auth.sessions.destroy` | `api` |
| POST | `auth/step-up` | `mobile.auth.step_up` | `api` |

### السياق والإقلاع والإعدادات (`mobile.*`)
| Verb | Path | الاسم | ملاحظة |
|---|---|---|---|
| GET | `app-config` | `mobile.app_config` | `60,1` [عامّ] · بلا سرّ |
| GET | `health` | `mobile.health` | `60,1` [عامّ] |
| GET | `openapi.json` | `mobile.openapi` | `60,1` [عامّ] |
| GET | `context` | `mobile.context` | شركات/عملاء |
| GET | `bootstrap` | `mobile.bootstrap` | ETag/304 |
| GET | `schema/modules` | `mobile.schema.modules` | ETag/304 · بلا `table` |
| GET | `schema` | `mobile.schema` | ETag/304 |

### الأعمال (`mobile.approvals.*`, `mobile.home/search/prefs`, `mobile.resource.*`)
| Verb | Path | الاسم |
|---|---|---|
| GET | `approvals` | `mobile.approvals.index` |
| GET | `approvals/{id}` | `mobile.approvals.show` |
| POST | `approvals/{id}/approve` | `mobile.approvals.approve` |
| POST | `approvals/{id}/reject` | `mobile.approvals.reject` |
| GET | `home` | `mobile.home` |
| GET | `search` | `mobile.search` |
| GET | `prefs` | `mobile.prefs.index` |
| PUT | `prefs` | `mobile.prefs.update` |
| POST | `prefs/pin` | `mobile.prefs.pin` |
| GET | `{module}/{id}/actions` | `mobile.resource.actions` |
| POST | `{module}/{id}/actions/{action}` | `mobile.resource.run_action` |
| GET | `{module}` | `mobile.resource.index` |
| POST | `{module}` | `mobile.resource.store` |
| GET | `{module}/{id}` | `mobile.resource.show` |
| PUT | `{module}/{id}` | `mobile.resource.update` |
| PATCH | `{module}/{id}` | `mobile.resource.patch` |
| DELETE | `{module}/{id}` | `mobile.resource.destroy` |

### الاتصال (`mobile.notifications.*`, `mobile.comments.*`, `mobile.dm.*`, `mobile.push.*`)
| Verb | Path | الاسم |
|---|---|---|
| GET | `notifications` | `mobile.notifications.index` |
| GET | `notifications/unread-count` | `mobile.notifications.unread` |
| POST | `notifications/read-all` | `mobile.notifications.read_all` |
| GET | `notifications/{id}/target` | `mobile.notifications.target` |
| POST | `notifications/{id}/read` | `mobile.notifications.read` |
| GET | `comments` | `mobile.comments.index` |
| POST | `comments` | `mobile.comments.store` |
| GET | `dm/threads` | `mobile.dm.threads` |
| GET | `dm/threads/{user}/messages` | `mobile.dm.messages` |
| POST | `dm/threads/{user}/send` | `mobile.dm.send` |
| POST | `dm/threads/{user}/read` | `mobile.dm.read` |
| POST | `push/register` | `mobile.push.register` |
| POST | `push/unregister` | `mobile.push.unregister` |
| GET | `push/admin/status` | `mobile.push.admin.status` |
| POST | `push/admin/test` | `mobile.push.admin.test` |

### الملفّات والماسح والموقع (`mobile.files.*`, `mobile.identity.*`, `mobile.tracking.*`)
| Verb | Path | الاسم |
|---|---|---|
| POST | `files/upload-session` | `mobile.files.upload_session` |
| PUT | `files/upload-session/{id}/chunk` | `mobile.files.upload_chunk` |
| POST | `files/upload-session/{id}/complete` | `mobile.files.upload_complete` |
| POST | `files/attach` | `mobile.files.attach` |
| GET | `files/{id}/download` | `mobile.files.download` |
| GET | `files/{id}/stream` | `mobile.files.stream` |
| GET | `identity/resolve/{q}` | `mobile.identity.resolve` |
| POST | `tracking/start` | `mobile.tracking.start` |
| POST | `tracking/{session}/points` | `mobile.tracking.points` |
| POST | `tracking/{session}/end` | `mobile.tracking.end` |

### المزامنة (`mobile.sync`)
| Verb | Path | الاسم |
|---|---|---|
| GET | `sync/{module}` | `mobile.sync` |

## 7. مثالان مصغّران

**تسجيلُ دخولٍ بلا MFA ⇒ 200:**
```
POST /api/mobile/v1/auth/login
{ "email":"...", "password":"...", "installation_uuid":"...", "platform":"ios" }
→ { "data": { "access_token":"lyma_…", "refresh_token":"lymr_…",
     "token_type":"Bearer", "access_expires_in": 900, "user": {…} }, "request_id":"…" }
```
**كتابةٌ بتعارُضِ نسخة ⇒ 409:**
```
PUT /api/mobile/v1/tasks/{id}    If-Match: "3"      (والسجلُّ version=5)
→ 409 { "code":"VERSION_CONFLICT",
        "details":{ "current_version":5, "your_version":3 }, "request_id":"…" }
```

---

**التحقّق:** جدولُ المسارات مطابقٌ لـ`route:list --path=api/mobile` (٥٨). الغلافُ
والأكوادُ من `app/Support/Api.php`. لا مسارٌ هنا غيرُ مُسجَّلٍ في `routes/api.php`.
