# 00 · المعماريّة القائمة — نواةٌ واحدةٌ، ثلاثةُ عملاء

> Mobile Readiness · الطور H · H.1. وثيقةٌ **مبنيّةٌ على الشجرة الحقيقيّة** (head
> `v2.427.0`) — كلُّ ادّعاءٍ يحمل `file:line` أو مساراً. لا توصيات: ما هنا **مُنفَّذٌ
> ومُختبَرٌ** على المحرّكين (SQLite + MySQL). المنهجُ نفسُه الذي في
> `docs/work-os/FINAL_REPORT.md`.

## 1. المبدأ: نواةٌ واحدةٌ، ثلاثةُ عملاء (Web · Mobile · Integrations)

منطقُ الأعمال والأمنُ يعيشان في **سكّةٍ واحدة**؛ الطبقاتُ الثلاث للنقل (الويب،
والجوال، والتكامل) **عملاءُ** لها لا فروعٌ منها. الجوهرُ أن محرّكات المنصّة تُشتقّ
الهويّةَ من `auth()->user()` **لا من الجلسة** — فهي أصلاً عديمةُ الحالة، ومهيّأةٌ
لعميلٍ بلا كوكي:

- `hub_can($user, $module, $op)` (`app/Support/helpers.php:288`) — الصلاحية.
- `hub_scope($q, $module, $user)` (`helpers.php:141`) — العزلُ الصلب (مشروع/شركة/عميل).
- `hub_field_mode($user, $module, $key)` (`helpers.php:1639`) — قناعُ الحقول.

الطبقاتُ الثلاث تدخل هذه المحرّكاتِ نفسَها؛ لا محرّكَ أعمالٍ ثانٍ للجوال. القاعدةُ
المُلزِمة (CLAUDE.md · «الإضافة لا الكسر»): **لا حذفَ مسارٍ، ولا تغييرَ عقد API، ولا
هجرةً مُدمِّرة** — كلُّ الطور B–H **إضافةٌ صرفة** فوق السكّتين القائمتين (سجلُّ
الوحدات `config/hub.php`، ومفتاح `(module, record_id)` متعدد الأشكال).

| العميل | سطحُ النقل | المصادقة | التعريف |
|---|---|---|---|
| **Web** | `routes/web.php` (439 مساراً) | جلسةُ Laravel + كوكي | `AuthController` + `SessionSentry` |
| **Integrations** | `/api/v1/*` (`routes/api.php:12-44`) | `Authorization: Bearer <ApiToken>` طويلُ العمر | `ApiAuth` (`app/Http/Middleware/ApiAuth.php`) |
| **Mobile** (جديد) | `/api/mobile/v1/*` (`routes/api.php:102-280`) | زوجُ رمزَين: وصولٌ قصيرٌ + تحديثٌ متجدّد | `MobileSessionAuth` (`app/Http/Middleware/MobileSessionAuth.php`) |

`/api/v1` و`ApiToken` و`ApiAuth` و`docs/openapi.json` **لم تُمَسّ**: `git status`
يُظهر `docs/openapi.json` و`app/Support/OpenApi.php` و`V1Controller.php` و`Api.php`
و`ApiAuth.php` **نظيفةً كلَّها** — n8n والتكاملاتُ تعمل بلا هجرة.

## 2. جلسةُ الجوال ≠ ApiToken — مفهومان منفصلان

`ApiToken` (`app/Models/ApiToken.php`, جدول `api_tokens`) رمزٌ **طويلُ العمر، غيرُ
مربوطٍ بجهاز، بلا تحديث، بلا منصّة، بلا تنصيب** (INVENTORY §3a) — فجلسةُ الجوال لا
تُبنى عليه. القرارُ (INVENTORY §3c · **الفصل B**): جداولُ جوالٍ مخصّصة، لا تمديدُ
`user_devices` (تلك ثقةُ متصفّحٍ بمفتاح كوكي، تقرؤها `SessionSentry` في كل طلب —
خلطُ صفوفِ الجوال بها يُفسد نموذجَ الثقة القائم).

**الجداولُ الخمسةُ الجديدة (إضافيّةٌ محروسة، قابلةٌ للعكس، على المحرّكين):**

| الجدول | الهجرة | الدور |
|---|---|---|
| `mobile_installations` | `2026_09_18_000001_b_create_mobile_installations.php` | تنصيبُ تطبيقٍ عرّف نفسَه بـ`installation_uuid` **يولّده التطبيق** — لا IMEI/adid/بصمةٌ غازية (spec §Models) |
| `mobile_sessions` | `2026_09_18_000002_b_create_mobile_sessions.php` | زوجُ رمزَين (`access_hash`/`refresh_hash` — sha256 hex ٦٤ حرفاً حصراً)، `family_id`، `prev_refresh_hash` (سلسلةُ التدوير) |
| `mobile_stepup_grants` | `2026_09_18_000003_b_create_mobile_stepup_grants.php` | مِنحةُ تصعيدٍ مربوطةٌ بـ`(mobile_session_id, purpose, expires_at)` — لا `session()` |
| `push_tokens` | `2026_09_19_000001_e_create_push_tokens.php` | رمزُ توجيهِ الدفع، `unique(provider, token)` (إزالةُ التكرار · Critic F7) |
| `push_deliveries` | `2026_09_19_000002_e_create_push_deliveries.php` | سجلُّ محاولاتِ التسليم (`queued|attempted|delivered|failed|not_configured|skipped`) — **لا سرَّ، لا نصَّ إشعار** |

الفروقُ الجوهريّةُ عن `ApiToken`:

- **قصيرُ العمر:** رمزُ الوصول `setting('mobile.access_ttl_min', 15)` دقيقة
  (`MobileSessionService::accessTtlAt` — `app/Support/MobileSessionService.php:37`)؛
  التحديثُ `setting('mobile.refresh_ttl_days', 30)` يوماً (`:43`).
- **مربوطٌ بتنصيبٍ وعائلة:** كلُّ تسجيلِ دخولٍ عائلةٌ جديدة (`family_id` · `:74`).
- **تدويرٌ لمرّةٍ واحدة + كشفُ إعادة:** `MobileSessionService::rotate` (`:101`) — انظر
  `03-authentication-security.md`.
- **تجزئةٌ فقط:** كلا الرمزين sha256 hex؛ النصُّ الصريحُ يُعاد مرّةً ولا يُخزَّن ولا
  يُسجَّل ولا يُدقَّق أبداً (نمطُ `ApiToken.token_hash`).

## 3. بوّابةُ `MobileSessionAuth` — تطابق ترتيبَ `ApiAuth` حرفيّاً

`app/Http/Middleware/MobileSessionAuth.php` تُطابق ترتيبَ حراس `ApiAuth.php:14-94`
كي لا يكون سطحُ الجوال بوّابةً **أضعف** (spec §Auth: «NO weaker parallel login»):

1. فرضُ `Accept: application/json` (`MobileSessionAuth.php:33`, نمطُ `ApiAuth:17`).
2. `bearerToken()` حاضرٌ وإلا `UNAUTHENTICATED` 401 + `SecurityRadar::record` (`:36-40`).
3. `MobileSession::where('access_hash', hash('sha256', $plain))` + `access_expires_at`
   (`:43-48`) — تجزئةٌ حصراً (نمطُ `ApiAuth:25`).
4. `revoked_at` ⇒ `SESSION_REVOKED` 401 (الكودُ المخصَّص · `:51-55`) — كي يعرف
   العميلُ أن يعيد الدخول لا أن يعيد المحاولة.
5. **الحراسُ الخمسةُ للحساب** (`:59-72`): موقوف · منتهٍ (`expires_at`) · مقفول
   (`locked_until`) · `allowed_ips` عبر `ip_allowed()` · `security.lockdown`
   (المالكُ مُستثنى) — نظيرُ `ApiAuth:43-63` و`AuthController::login` gates.
6. `Auth::setUser($user)` + `attributes->set('mobile_session', $s)` (`:74-75`) —
   للنطاق والـIdempotency لاحقاً.
7. `X-API-Version` على كل ردّ (`:92`, نمطُ `ApiAuth:86`).

**الرمزُ ACCESS لا refresh:** `auth/refresh` يصادِق بـ`refresh_hash` في معالجه الخاصّ
**خارجَ** هذه البوّابة (Critic F5) — فهو في المجموعة العامّة (`routes/api.php:107`).

## 4. السكك المُعادُ استعمالُها (لا نظامَ ثانٍ لأيٍّ منها)

المبدأ: كلُّ متحكّمِ جوالٍ يرث `V1Controller` أو يستدعي **خدمةً مشتركةً** استُخرجت من
منطق الويب — لا استدعاءَ لدوالِّ الويب التي تُعيد redirect/view/flash (Critic F2).

| القلق | السكّةُ المُعادة | الدليل |
|---|---|---|
| **الصلاحية** | `hub_can` / `hub_field_mode` | لا يُوثَق بالعميل — الخادمُ يعيد الفحص |
| **العزل** | `hub_scope` (+ شركة/عميل) | الترويسةُ تُضيّق لا توسّع (§5) |
| **الإشعارات** | نقطةُ الاختناق `HubNotification` (جدول `notifications_hub`) | كلُّ إشعارٍ يمرّ بالموديل الواحد |
| **الدفع** | hook `created` ⇒ `PushService::scheduleFanout` عبر `DB::afterCommit` | `HubNotification.php:77-78`, `PushService.php:126-128` (Critic F6) |
| **الأحداث** | `FlowRunner::fire` ⇒ `HubEvents::dispatch` | لا مُوزِّعَ ثالثٌ للدفع — الدفعُ عند طبقة الإشعار |
| **تعارضُ النسخ** | `Api::assertVersion` + عمود `version` (`HasVersions`) | `If-Match` ⇒ `VERSION_CONFLICT` |
| **الـIdempotency** | `V1Controller::idempotentBegin` + جدول `idempotency_keys` | مالكُ المفتاح موحَّدٌ عبر `Idempotency::owner` (§6) |
| **الملفّات** | `AttachmentService` (whitelist + checksum، قرص `local` الخاص) | لا رابطٌ عامّ ولا base64 |
| **التعليقات** | `CommentService::guardTarget` | نقطةُ التخويلِ الوحيدة |
| **DM** | `DmService` + مفتاحُ `auth()->id()`+الطرف | لا A-يستعلم-عن-B↔C |
| **الاعتمادات** | `ApprovalService::decide`/`submit` | يقتل «نفّذها من الواجهة» |
| **البحث** | محرّكُ `SearchController` عبر `MobileWorkController::search` | النتيجة = وجهةُ رابطٍ عميق `{module,id}` |
| **الهويّة/المسح** | `V1Controller::identityResolve` | المحلّلُ الموحّد نفسُه |
| **التتبّع** | `V1Controller::trackStart/Ingest/End` | موافقةٌ + دفعةٌ + تسلسل |
| **رادارُ الأمن** | `SecurityRadar::record` / `LoginSentry::inspect` / `hub_audit` | سكّةٌ واحدة · `source=mobile` — لا «مركز أمن جوال» منفصل |

### 4a. الاختناقُ الواحد للإشعارات ⇒ الدفع

جدول `notifications_hub` (موديل `HubNotification`) نقطةُ اختناقٍ واحدة: كلُّ إشعارٍ
يمرّ به. الطور E وصل الدفعَ **عند حدث `created`** لا عند `hub_notify` — فيغطّي مواضعَ
الإنشاء الستّةَ المباشرة (`AlertEngine`/`FlowRunner`/`EsignController`/`HubDigest`/
`HubAutomation`) لا نقطةَ المساعِد وحدَها (Critic F6b). والفَنْأَوت **مؤجَّلٌ عبر
`DB::afterCommit`** (`PushService.php:128`)، فاستثناءُ مزوّدٍ لا يُرجِع معاملةً
تحمل الإشعارَ الداخليّ (spec: «failed push must not lose internal notification» ·
Critic F6a). والأنواعُ المكتومة لا تبلغ `created` أصلاً — hook الكتمِ يُلغي
`creating` بـ`return false` (`HubNotification.php:74-75`) فلا حارسَ زائد.

### 4b. مالكُ الـIdempotency عبر السطوح (Critic F1)

`Idempotency::owner($r)` (`app/Support/Idempotency.php:40`) سكّةٌ مشتركة: `api_token->id`
لسطح التكامل (byte-identical لـ`/api/v1`)، و`mobile_session->id` لسطح الجوال — فكلُّ
جلسةٍ مالكٌ مستقلٌّ، **ولا يُعاد ردُّ مستخدمٍ لآخر**. `V1Controller::ikeyOf` (`:466`)
يقرؤها، فآلةُ الـIdempotency الموروثةُ تعمل للجوال دون أن تُصبح `[null,null]` (العيبُ
الذي كان سيُنفّذ كلَّ عمليةِ جوالٍ مرّتين ويُتيح إعادةَ ردٍّ عابرةً للمستخدمين).

## 5. سياقُ العرض عديمُ الحالة (narrow-never-widen)

لا «شركةٌ حاليّة» في جلسة. الترويستان `X-Lynomia-Company` / `X-Lynomia-Client`
(وسيط `MobileContext` · `app/Http/Middleware/MobileContext.php`) **تُضيّقان العرض لا
تخوّلان**: تُلحَقان **بعد** `mobile.session` (التي أرست `Auth::setUser`)، فتتقاطعان مع
`hub_company_ids()`/`hub_client_ids()` — قيمةٌ خارجَ المسموح **تُتجاهَل** (`null`) لا
تُوسِّع ولا تكسر الطلب. طبقةٌ **فوق** `hub_scope` (AND على مجموعةٍ محقَّقةٍ ⊆ المسموح)
لا بديلٌ عنه — فبرهانُ «لا توسيع» بنيويّ. التفصيلُ في `04-permissions-context.md`.

## 6. عقدُ الاستجابة الموحَّد

كلُّ ردٍّ بغلاف `Api::*`: نجاحٌ `{data, request_id}` (+ `meta` للقوائم)، وخطأٌ
`{error, code, details, request_id}` بكودٍ آليٍّ (العربيةُ لا تُحلَّل من العميل)،
و`X-API-Version: 1` على كل ردّ. الأكوادُ من `Api::CODES` (`app/Support/Api.php:62`)
تُضاف إليها **أربعةٌ للجوال فقط** (`Api.php:53-56`): `MFA_REQUIRED`,
`REFRESH_TOKEN_INVALID`, `SESSION_REVOKED`, `APP_UPDATE_REQUIRED` — إضافةٌ لا تُعيد
تسميةَ كودٍ ولا تحذف (لا تكسر n8n). التفصيلُ الكاملُ في `02-mobile-api-contract.md`.

## 7. التوثيقُ الحيّ (OpenAPI)

مواصفةُ الجوال **مولَّدةٌ من المسارات الحيّة** لا مكتوبةٌ باليد:
`App\Support\MobileOpenApi::spec()` يُرشِّح `RouteFacade::getRoutes()` على
`api/mobile/v1`، فلا يوثّق مساراً غيرَ موجودٍ ولا يُسقط مساراً حقيقيّاً. تُخدَم عبر
`GET /api/mobile/v1/openapi.json` (`MobileDocsController@openapi` · `routes/api.php:120`،
**عامّةٌ** في المجموعة قبل catch-all). وثيقةٌ **منفصلةٌ تماماً**: العنوانُ «Lynomia
Business Hub — Mobile API»، `openapi: 3.1.0`، ٥٢ مساراً، **صفرُ مسارٍ مشتركٍ** مع
`/api/v1/openapi.json`، ومخطّطُ أمانٍ `mobileBearerAuth` — لا تمسّ `docs/openapi.json`
(الذي يولّده المنسّق لرفع النسخة). التفصيلُ في `08-versioning-deep-links.md`.

---

**التحقّق:** كلُّ سطرٍ هنا مقروءٌ من الشجرة عند head `v2.427.0`؛ الأرقامُ
(58 مساراً، ٥ جداول، ٤ أكواد) من `php artisan route:list --path=api/mobile`،
والهجرات، و`Api.php`. لا مكوّنٌ موصوفٌ هنا غيرُ مبنيّ.
