# 01 · مصفوفةُ التكافؤ Web → Mobile

> Mobile Readiness · الطور H · H.1. تكملةُ هيكل PLAN.md بعد شحن الأطوار B–G.
> **كلُّ صفٍّ منفَّذٌ (route + test)** أو مؤجَّلٌ **صراحةً بصدق**. الأعمدة:
> Area · Feature · Web Route · Backend Action · Existing `/api/v1` · Mobile API ·
> Permission · MobileStatus. `MobileStatus = DONE` يعني **مسارٌ حيٌّ في
> `routes/api.php` + اختبارٌ يمرّ على المحرّكين**.

**تحقّقُ الاكتمال:** كلُّ مسارٍ في مجموعتَي `api/mobile/v1` (`routes/api.php:102-280`)
مُمثَّلٌ أدناه — **٥٨ مساراً** (`php artisan route:list --path=api/mobile`): ٦ عامّة
+ ٥٢ مُصادَقة. لا مسارٌ بلا صفّ، ولا صفٌّ بلا مسار.

## B · المصادقة (`MobileAuthController` · `mobile.auth.*`)

| Area | Feature | Web Route | Backend Action | Existing API | Mobile API | Permission | MobileStatus |
|---|---|---|---|---|---|---|---|
| Auth | Login (كلمة مرور) | `web.php:90` POST login | `AuthController::login` | — | `POST auth/login` (`api.php:103`) | عامّ + ٥ حراس | **DONE** — `MobileAuthTest`, `MobileAuthContractTest` |
| Auth | MFA/OTP | `web.php:93` POST otp | `AuthController::otpVerify` + `Totp::verifyOnce` | — | `POST auth/mfa/verify` (`api.php:105`) | تحدٍّ `challenge_id` | **DONE** — `MobileAuthTest` (totp path) |
| Auth | تدوير الرمز | — (كوكي جلسة) | `MobileSessionService::rotate` | — | `POST auth/refresh` (`api.php:107`) | `refresh_hash` (معالجٌ خاصّ · F5) | **DONE** — `MobileAuthTest` (rotation/reuse) |
| Auth | خروج | تسجيلُ خروج الويب | `MobileSessionService::revokeSession` | — | `POST auth/logout` (`api.php:128`) | self (الجلسة الحالية) | **DONE** — `MobilePushLogoutRevokeTest` |
| Auth | خروجٌ شامل | — | `MobileSessionService::revokeAllForUser` | — | `POST auth/logout-all` (`api.php:129`) | self (كلُّ جلساته) | **DONE** — `MobilePushLogoutRevokeTest` |
| Auth | جلساتي | إدارةُ جلسات الويب | `MobileSession` (own only) | — | `GET auth/sessions` (`api.php:130`) | self — لا جلسةَ غيره | **DONE** — `MobileAuthTest` |
| Auth | إلغاءُ جلسة | — | `MobileSessionService::revokeSession` | — | `DELETE auth/sessions/{id}` (`api.php:131`) | self · 404 لغيره (لا IDOR) | **DONE** — `MobileAuthTest` |
| Auth | تصعيدُ المصادقة | تصعيدُ الويب (`StepUp`) | `StepUp::checkCredential` + `mobile_stepup_grants` | — | `POST auth/step-up` (`api.php:132`) | self + purpose | **DONE** — `MobileAuthTest` (step-up) |

## C · السياق / الإقلاع / الإعدادات / المخطّط (`MobileAuthController` + `MobileContextController`)

| Area | Feature | Web Route | Backend Action | Existing API | Mobile API | Permission | MobileStatus |
|---|---|---|---|---|---|---|---|
| Config | إعداداتُ ما قبل الدخول | — | `setting()` + `config('hub.mobile')` | — | `GET app-config` (`api.php:109`) | عامّ (بلا سرّ) | **DONE** — `MobileAppConfigTest` |
| Health | حالةُ الخدمة | `/up` (إطار) | `MobileAuthController::health` | — | `GET health` (`api.php:111`) | عامّ | **DONE** — `MobileAppConfigTest` |
| Context | شركات/عملاء | مساعِداتُ النطاق | `hub_company_ids`/`hub_client_ids` + `hub_scope` | — | `GET context` (`api.php:142`) | scope — لا IDOR | **DONE** — `MobileContextTest` |
| Context | إقلاعٌ بارد | تحميلُ اللوحة | `MobileContextController::bootstrap` (ETag/304) | `me`+`modules` | `GET bootstrap` (`api.php:143`) | auth | **DONE** — `MobileBootstrapTest` |
| Schema | مخطّطٌ مُنطَّق | — | `hub_visible_fields` + `hub_field_mode` + `hub_sync_class` | `GET /api/v1/modules` | `GET schema/modules` (`api.php:144`) | per-field | **DONE** — `MobileSchemaTest` |
| Schema | الوثيقةُ الكاملة | — | `MobileContextController::schema` (ETag/304) | — | `GET schema` (`api.php:145`) | per-field | **DONE** — `MobileSchemaTest` |

**فارقٌ متعمّد عن v1:** مخطّطُ الجوال **يُسقط `table`/`col` الفيزيائيَّين** (يُصلح
تسريبَ `V1Controller.php:33`)، ويضيف `required`/`options`/`readonly` (تحقّقٌ مشتقّ) +
`sync_class` + بصمةَ نسخةٍ/ETag. `/api/v1/modules` يبقى كما هو (توافق).

## D · تكافؤُ واجهةِ الأعمال (`MobileResourceController` + `MobileWorkController`)

| Area | Feature | Web Route | Backend Action | Existing API | Mobile API | Permission | MobileStatus |
|---|---|---|---|---|---|---|---|
| CRUD | قائمة | `m.index` | `V1Controller::apiIndex` (موروثٌ) | `GET /api/v1/{module}` | `GET {module}` (`api.php:274`) | `hub_can(v)` + scope | **DONE** — `MobileCrudTest` |
| CRUD | إنشاء | `m.store` | `V1Controller::apiStore` (+ Idempotency مالكُ الجوال) | `POST /api/v1/{module}` | `POST {module}` (`api.php:275`) | `hub_can(a)` | **DONE** — `MobileCrudTest`, `MobileIdempotencyTest` |
| CRUD | عرض | `m.show` | `V1Controller::apiShow` (ETag) | `GET /api/v1/{module}/{id}` | `GET {module}/{id}` (`api.php:276`) | `hub_can(v)` + scope | **DONE** — `MobileCrudTest` |
| CRUD | استبدالٌ كامل | `m.update` | `V1Controller::apiUpdate` (If-Match) | `PUT /api/v1/{module}/{id}` | `PUT {module}/{id}` (`api.php:277`) | `hub_can(e)` + approval-aware | **DONE** — `MobileCrudTest`, `MobileApprovalWriteTest` |
| CRUD | تعديلٌ جزئيّ | `m.update` | `V1Controller::apiPatch` | `PATCH /api/v1/{module}/{id}` | `PATCH {module}/{id}` (`api.php:278`) | `hub_can(e)` + approval-aware | **DONE** — `MobileCrudTest` |
| CRUD | حذفٌ ناعم | `m.destroy` | `V1Controller::apiDestroy` | `DELETE /api/v1/{module}/{id}` | `DELETE {module}/{id}` (`api.php:279`) | `hub_can(d)` + approval-aware | **DONE** — `MobileCrudTest` |
| Actions | الانتقالاتُ الصالحة | — | `listActions` (allowlist من الحالة + `requires` + `status_via_action`) | — | `GET {module}/{id}/actions` (`api.php:270`) | `hub_can` + state | **DONE** — `MobileActionsTest` |
| Actions | تنفيذُ إجراء | `web.php:749/758/759/491` | `runAction` ⇒ setStatus/restore/restoreVersion/ack | — | `POST {module}/{id}/actions/{action}` (`api.php:271`) | `hub_can` + state + approval + step-up + Idempotency | **DONE** — `MobileActionsTest` |
| Approvals | قائمة | `web.php` اعتمادات | `MobileWorkController::approvals` | — | `GET approvals` (`api.php:162`) | `hub_approver` | **DONE** — `MobileApprovalWriteTest` |
| Approvals | عرض | — | `MobileWorkController::approvalShow` | — | `GET approvals/{id}` (`api.php:163`) | `hub_approver` | **DONE** — `MobileApprovalWriteTest` |
| Approvals | اعتماد | `web.php:417` | `ApprovalService::decide('approve')` + Idempotency | 409 dead-end | `POST approvals/{id}/approve` (`api.php:164`) | `hub_approver` | **DONE** — `MobileApprovalWriteTest` |
| Approvals | رفض | `web.php:418` | `ApprovalService::decide('reject')` | 409 dead-end | `POST approvals/{id}/reject` (`api.php:165`) | `hub_approver` | **DONE** — `MobileApprovalWriteTest` |
| Home | مساحةُ العمل | `web.php:152` | `MobileWorkController::home` (تجميعُ استعلاماتٍ مُنطَّقة) | — | `GET home` (`api.php:168`) | scope | **DONE** — `MobileWorkspaceTest` |
| Search | بحثٌ عالميّ | `web.php:578-579` | محرّكُ `SearchController` عبر `MobileWorkController::search` | — | `GET search` (`api.php:169`) | `hub_can(v)` + scope | **DONE** — `MobileWorkspaceTest` |
| Prefs | قراءةُ التفضيلات | `web.php:468-472` | `MobileWorkController::prefs` | — | `GET prefs` (`api.php:170`) | self | **DONE** — `MobileWorkspaceTest` |
| Prefs | تحديثُ التفضيلات | `web.php:468-472` | `MobileWorkController::prefsUpdate` | — | `PUT prefs` (`api.php:171`) | self | **DONE** — `MobileWorkspaceTest` |
| Prefs | تثبيتُ وحدة | `web.php:468-472` | `MobileWorkController::pin` (+ Idempotency) | — | `POST prefs/pin` (`api.php:172`) | self | **DONE** — `MobileWorkspaceTest` |

**قتلُ «نفّذها من الواجهة» (Critic F3/D.4):** كتابةُ الجوال المحميّةُ بالموافقات لا
تُعيد dead-end `V1Controller.php:140` — بل تُصفّ طلباً حقيقيّاً عبر
`ApprovalService::submit` (`MobileResourceController` · `submitForApproval`) ثم تُحسَم
من مسار الاعتمادات أعلاه.

## E · الاتصال (`MobileCommController` + `MobilePushController`)

| Area | Feature | Web Route | Backend Action | Existing API | Mobile API | Permission | MobileStatus |
|---|---|---|---|---|---|---|---|
| Notify | قائمة (cursor) | `web.php:581` | `MobileCommController::notifications` (scoped `user_id`) | — | `GET notifications` (`api.php:186`) | self فقط | **DONE** — `MobileNotificationsTest` |
| Notify | عدُّ غير المقروء | `web.php:582` | `MobileCommController::unreadCount` | — | `GET notifications/unread-count` (`api.php:187`) | self | **DONE** — `MobileNotificationsTest` |
| Notify | تعليمُ الكلّ مقروءاً | `web.php` read-all | `MobileCommController::markAllRead` | — | `POST notifications/read-all` (`api.php:188`) | self | **DONE** — `MobileNotificationsTest` |
| Notify | وجهةُ الرابط | `web.php:585` `{id}/go` | `NotificationLink::target` ⇒ `{module,id,action}` | — | `GET notifications/{id}/target` (`api.php:189`) | self | **DONE** — `MobileNotificationsTest` |
| Notify | تعليمُ واحدٍ مقروءاً | — | `MobileCommController::markRead` | — | `POST notifications/{id}/read` (`api.php:190`) | self | **DONE** — `MobileNotificationsTest` |
| Comments | تغذيةُ سجل | `web.php:425-431` | `CommentService::guardTarget` | — | `GET comments` (`api.php:193`) | `hub_can(v)` + scope | **DONE** — `MobileCommentsTest` |
| Comments | نشرُ تعليق | `web.php:425` | `CommentService::create` (+ reply-integrity + Idempotency) | — | `POST comments` (`api.php:194`) | `hub_can(v)` + scope | **DONE** — `MobileCommentsTest` |
| DMs | قائمةُ الخيوط | `DmController` inbox | `DmService::threadRows(auth id)` | — | `GET dm/threads` (`api.php:197`) | participant | **DONE** — `MobileDmTest` |
| DMs | رسائلُ خيط | `DmController::thread` | `DmService::thread(auth id, {user})` | — | `GET dm/threads/{user}/messages` (`api.php:198`) | participant · لا A↔B↔C (F8) | **DONE** — `MobileDmTest` |
| DMs | إرسال | `DmController` send | `DmService::send` (+ Idempotency) | — | `POST dm/threads/{user}/send` (`api.php:199`) | participant | **DONE** — `MobileDmTest` |
| DMs | تعليمُ قراءة | — | `DmService::markThreadRead(auth id, {user})` | — | `POST dm/threads/{user}/read` (`api.php:200`) | participant | **DONE** — `MobileDmTest` |
| Push | تسجيلُ رمز | — | `PushService::register` (dedupe عابرُ المستخدمين · F7) | — | `POST push/register` (`api.php:203`) | self | **DONE** — `MobilePushRegisterTest` |
| Push | إلغاءُ رمز | — | `PushService::revoke` (`user_id=auth` فقط) | — | `POST push/unregister` (`api.php:204`) | self | **DONE** — `MobilePushRegisterTest` |
| Push | حالةُ الإدارة | Settings/Integrations | `PushService::status` (بلا سرّ) | — | `GET push/admin/status` (`api.php:210`) | owner فقط | **DONE** — `MobilePushRegisterTest` |
| Push | اختبارٌ آمن | — | `MobilePushController::adminTest` (NOT_CONFIGURED صدقاً) | — | `POST push/admin/test` (`api.php:211`) | owner فقط | **DONE** — `MobilePushRegisterTest` |
| Push | فَنْأَوت | — | hook `created` ⇒ `PushService::scheduleFanout` (afterCommit · F6) | — | (داخليّ — لا مسار) | — | **DONE** — `MobilePushFanoutTest` |

## F · الملفّات / الماسح / الموقع (`MobileFileController`)

| Area | Feature | Web Route | Backend Action | Existing API | Mobile API | Permission | MobileStatus |
|---|---|---|---|---|---|---|---|
| Files | جلسةُ رفع | `web.php:516` | `AttachmentService::guardRecord` + `ChunkedUpload::token` | — | `POST files/upload-session` (`api.php:231`) | `guardRecord(v)` | **DONE** — `MobileFilesTest` |
| Files | رفعُ قطعة | (ResolveChunkedUploads) | `ChunkedUpload::append` (حدُّ النظام) | — | `PUT files/upload-session/{id}/chunk` (`api.php:232`) | رمزُ الرفعة | **DONE** — `MobileFilesTest` |
| Files | إتمامُ الرفع | `web.php:516` | `ChunkedUpload::claim` + `AttachmentService::attach` (+ Idempotency) | — | `POST files/upload-session/{id}/complete` (`api.php:233`) | `guardRecord(v)` | **DONE** — `MobileFilesTest` |
| Files | إرفاقٌ مفرد | `web.php:516` `att.store` | `AttachmentService::attach` (whitelist + checksum) | — | `POST files/attach` (`api.php:234`) | `guardRecord(v)` | **DONE** — `MobileFilesTest` |
| Files | تنزيل | `web.php:517` `att.dl` | `AttachmentService::download` (+ `abort_if infected`) | — | `GET files/{id}/download` (`api.php:238`) | `guardRecord(v)` | **DONE** — `MobileFilesTest` |
| Files | بثّ | `web.php:518` `att.view` | `AttachmentService::stream` | — | `GET files/{id}/stream` (`api.php:239`) | `guardRecord(v)` | **DONE** — `MobileFilesTest` |
| Scanner | حلُّ QR/سيريال | — | `V1Controller::identityResolve` (موروثٌ) | `GET /api/v1/identity/resolve/{q}` | `GET identity/resolve/{q}` (`api.php:243`) | `hub_can` + scope | **DONE** — `MobileScannerTest` |
| Location | بدءُ تتبّع | — | `V1Controller::trackStart` (consent=true) | `POST /api/v1/track/start` | `POST tracking/start` (`api.php:248`) | consent | **DONE** — `MobileTrackingTest` |
| Location | استيعابُ نقاط | — | `V1Controller::trackIngest` (+ Idempotency) | `POST /api/v1/track/{session}/points` | `POST tracking/{session}/points` (`api.php:249`) | consent | **DONE** — `MobileTrackingTest` |
| Location | إنهاءُ تتبّع | — | `V1Controller::trackEnd` | `POST /api/v1/track/{session}/end` | `POST tracking/{session}/end` (`api.php:250`) | consent | **DONE** — `MobileTrackingTest` |

## G · المزامنة (`MobileSyncController`)

| Area | Feature | Web Route | Backend Action | Existing API | Mobile API | Permission | MobileStatus |
|---|---|---|---|---|---|---|---|
| Sync | تزايُديّة | — | `hub_scope` + `hub_sync_class` + `updated_at,id` + tombstones | — | `GET sync/{module}` (`api.php:266`) | `hub_can(v)` + scope | **DONE** — `MobileSyncTest`, `MobileSyncConcurrencyTest` |

**سياسةُ التخبئة (G.2):** الوحدةُ تقودها `hub_sync_class` (`config/hub.php:9224`):
`CACHEABLE_INCREMENTAL`/`CACHEABLE_READ_ONLY` تبثّ سجلّاتٍ + شواهدَ حذف؛
`ONLINE_ONLY`/`SENSITIVE_NO_PERSIST`/`NOT_APPLICABLE` تعيد سياسةً صادقةً بلا سجلّات
(`phones`/`carriers`/`vault` = `SENSITIVE_NO_PERSIST`؛ `users` = `NOT_APPLICABLE`).
التفصيلُ في `05-offline-sync.md`.

## H · التوثيق / OpenAPI / الروابط العميقة

| Area | Feature | Web Route | Backend Action | Existing API | Mobile API | Permission | MobileStatus |
|---|---|---|---|---|---|---|---|
| Docs | مواصفةُ الجوال | — | `MobileOpenApi::spec` (من المسارات الحيّة) | `GET /api/v1/openapi.json` (منفصل) | `GET openapi.json` (`api.php:120`) | عامّ | **DONE** — `MobileOpenApiTest` |
| DeepLink | AASA | `web.php:91` | `MobileWellKnownController::appleAppSiteAssociation` | — | `GET /.well-known/apple-app-site-association` | عامّ | **DONE (NOT_CONFIGURED)** — `MobileOpenApiTest` |
| DeepLink | assetlinks | `web.php:98` | `MobileWellKnownController::assetLinks` | — | `GET /.well-known/assetlinks.json` | عامّ | **DONE (NOT_CONFIGURED)** — `MobileOpenApiTest` |

## عزلُ المسارات — العقدُ الأمنيّ (Critic F9)

كلُّ حرفيٍّ (context/bootstrap/schema/approvals/home/search/prefs/notifications/
comments/dm/push/files/identity/tracking/sync) مُسجَّلٌ **قبل** الـcatch-all
`{module}` (`api.php:274`) وإلّا ابتلعه (`GET approvals` ⇒ `apiIndex('approvals')`).
مُختبَرٌ صراحةً في `MobileRouteIsolationTest` (١٣ اختباراً): كلُّ حرفيٍّ يُحلّ إلى
معالجه لا إلى `{module}`.

## الإجرائيّاتُ المؤجَّلةُ صراحةً (NOT_CONFIGURED / external-config)

ليست ثغراتٍ بل **اعتمادٌ خارجيٌّ صريح** — انظر `10-mobile-app-handoff.md`:

| البند | الحالة | الموضع |
|---|---|---|
| WebAuthn في MFA للجوال | **مؤجَّل** — يُعلَن `totp` حصراً في `methods[]` (لا يُختلق ما لا يُتحقَّق · F10) | `MobileAuthController.php:124` |
| اعتماداتُ FCM/APNs + project id | **external-config** — بلا سائقٍ ⇒ `NullPushProvider` ⇒ `NOT_CONFIGURED` صدقاً | `config/hub.php:9362`, `PushService::status` |
| App Attest / DeviceCheck / Play Integrity | **NOT_CONFIGURED** — لا واجهةَ تخويلٍ على وضعيّةٍ يُبلِّغها العميل | لا يُبنى — موثَّقٌ في §09 |
| Universal/App Links (team/bundle/package/cert) | **NOT_CONFIGURED** — تُخدَم وثيقةٌ صحيحةٌ تربط صفرَ تطبيق | `config/hub.php:9385`, `MobileWellKnownController` |
| روابطُ المتجر + بوّابةُ الإصدار | **فارغةٌ عمداً** — لا تحجب نسخَ التطوير حتى يُضبط حدٌّ | `config/hub.php:9337` |

---

**التحقّق:** المصفوفةُ تغطّي ٥٨ مساراً في `routes/api.php` + وثيقتَي well-known.
كلُّ `DONE` مسنودٌ باختبارٍ مذكورٍ في `tests/Feature/Mobile/`. لا مسارٌ موثَّقٌ غيرُ
موجود، ولا قدرةٌ مُدَّعاةٌ بلا كودٍ + اختبار.
