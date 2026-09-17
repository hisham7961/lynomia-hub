# الوكيل ٠٥ — المسارات والوسطاء

> طبقة ١ · الجولة أ (اكتشاف · قراءةٌ فقط) · الرأس `1d90626` · النسخة `2.540.1`
> الجردُ الكاملُ لكلِّ مسارٍ في الملفّ المرافق: **[`agent-05-routes.csv`](./agent-05-routes.csv)** (٦٣٢ صفّاً + ترويسة).

---

## ٠ · المقيس

| البند | العدد |
|---|---|
| المسارات | **632** (513 ويب · 31 `/api/v1` · 88 `/api/mobile/v1`) |
| الأفعال | GET 283 · POST 300 · PUT 18 · PATCH 2 · DELETE 30 |
| الوسطاء | **18** (`app/Http/Middleware/`) |
| **محروس** | **593** |
| **عامٌّ مقصود** | **38** (34 بالرمز/التوقيع/الخانق + 4 خلف `guest`) |
| **بلا حارس** | **1** — `GET /up` (‏A05‑01) |
| GET يُغيّر حالةَ عمل | **7** (A05‑07) |
| GET يُلحِق سكّةَ التدقيق/التنزيل فقط | 23 (مقصودةٌ بالتصميم) |
| مسارٌ دالّتُه مفقودة | **0** |
| مسارٌ قالبُه مفقود | **0** |
| تظليلُ ترتيبٍ (catch‑all يبتلع أخصَّ منه) | **0** (A05‑12) |
| مسارٌ ميّت/بلا مرجعٍ ولا اختبار | **7** (A05‑01 + A05‑08) |

**طريقةُ الجرد.** `php artisan route:list --json` (632 صفّاً) ثمّ خمسةُ ماسحاتٍ آليّة على كلِّ صفٍّ لا على عيّنة:
(١) تصنيفُ الحارس من مصفوفة الوسطاء، (٢) استخراجُ جسمِ دالّةِ المتحكّم بمُوازِنِ أقواسٍ ومطابقةُ أنماطِ الكتابة على كلِّ GET،
(٣) `class_exists`/`method_exists`/`ReflectionMethod::isPublic` على كلِّ فعلٍ في المصفوفة، (٤) كلُّ نداءِ `view('…')` في `app/` مقابل
`resources/views/`، (٥) بانٍ لعيّنةٍ مطابقةٍ لقيود `where` لكلِّ مسار ثمّ `RouteCollection::match()` — والمسارُ الذي لا يُطابق
**نفسَه** مُظلَّل. السكربتاتُ في مجلّد الجلسة المؤقّت؛ الجردُ الناتج في `agent-05-routes.csv`.

---

## ١ · مسارٌ بلا حارس — الجردُ الكامل

خمسةٌ وثلاثون مساراً لا تحمل `auth` ولا `ApiAuth` ولا `mobile.session` ولا `endpoint.signature`، وأربعةٌ خلف `guest`.
**أربعةٌ وثلاثون منها مقصودةٌ ومبرَّرة**، وواحدٌ ليس كذلك.

### مقصودةٌ ومبرَّرة (٣٨)

| المجموعة | المسارات | لماذا هي مقصودة |
|---|---|---|
| بابُ الدخول (`guest`) | `GET/POST login` · `GET/POST login/otp` | خانقُ `login` مفروزٌ بـ(بريد+IP) `10/د` وسقفٌ ثانٍ `60/د` على العنوان، فوقه `AccountLockout` لكلِّ حساب |
| الدخولُ بلا كلمةِ سرّ | `POST passkey/login/options` · `POST passkey/login/verify` | خانقٌ `30/د` + `RateLimiter` ثانٍ داخل `loginVerify` (`10/د` لكلِّ IP) + تحدٍّ في الجلسة |
| تفعيلُ حسابِ عميل | `GET activate/{token}` · `POST activate/{token}/otp` · `POST activate/{token}/set` | لا حسابَ بعدُ — رمزٌ لمرّةٍ واحدة، خانقٌ `10/د` (نظيرُ حدِّ الدخول) |
| تفعيلٌ على الجوال | `GET api/mobile/v1/activation/{token}` · `POST …/complete` | نفسُ السكّة (`AccountActivation`)، خانق `12/د` و`6/د`، والمجهولُ/المُستهلَك ٤٠٤ بلا كشفِ وجود |
| دخولُ الجوال | `POST auth/login` (10/د) · `auth/mfa/verify` (6/د) · `auth/refresh` (20/د) | رمزُ الوصولِ لم يوجد بعد؛ `refresh` يصادِق برمزِ التحديثِ في معالجه؛ الفشلُ يرفع `AccountLockout` لكلِّ حساب |
| توقيعٌ إلكترونيٌّ عامّ | `sign/{token}` وأخواتُها السبع + `verify` + `verify/{code}/doc` | العميلُ بلا حساب؛ كلُّ محتوىً خلف بوّابةِ `session("sign.ok.{token}")`، وفتحُ البوّابة بـ`RateLimiter` (5 محاولاتٍ لكلِّ رمز+IP) وإرسالُ الرمز بـ3/10د |
| غرفةُ البيانات | `GET/POST s/{token}` · `GET s/{token}/file` | الرمزُ `Str::random(48)` هو المفتاح، وكلمةُ سرٍّ اختياريّةٌ فوقَه بخانق `10/د` على الفتح |
| الويبهوكُ الوارد | `POST hook/{token}` | سطحٌ آليٌّ لا متصفّحَ له — رمزٌ 48 خانةً + HMAC، خانق `120/د`، ومُستثنى من CSRF عمداً (`bootstrap/app.php`) |
| تسجيلُ جهاز | `POST api/v1/endpoint/enroll` | الجهازُ الجديد بلا جلسةٍ ولا مفتاح؛ رمزُ تسجيلٍ مسكوكٌ لمرّةٍ وقصيرُ المهلة، والحمولةُ مفتاحٌ **عامّ**، خانق `20/د` |
| مسبارُ الصحّة | `GET healthz` | مراقبةُ Uptime؛ مجرَّدٌ عمداً من الصيانة/الدوام/الرادار كي يقول «MAINTENANCE حالةٌ لا عطل»، خانق `30/د` |
| سقالةُ الروابط العالميّة | `GET .well-known/apple-app-site-association` · `GET .well-known/assetlinks.json` | تفرضهما Apple/Google عند الجذر بلا مصادقةٍ ولا تحويل؛ `NOT_CONFIGURED` صادقةٌ تربط صفرَ تطبيق، خانق `60/د` |
| PWA | `GET manifest.webmanifest` · `GET pwa-icon.svg` · `GET offline` | بيانٌ وأيقونةٌ وصفحةٌ ثابتةٌ — عامّةٌ بطبيعتها؛ محتواها من `setting('app.name'/'app.color')` لا غير |
| عقدُ الجوال | `GET api/mobile/v1/app-config` · `GET …/health` · `GET …/openapi.json` | يقرؤها التطبيقُ قبل الدخول؛ خانق `60/د` لكلٍّ |

### غيرُ مقصودة (١)

**`GET /up`** — لا وسيطَ عليه إطلاقاً. تفصيلُه في **A05‑01**.

---

## ٢ · وسيطٌ خاطئ

| السؤال | الحكم |
|---|---|
| مسارٌ إداريٌّ بلا حارسِ الإدارة | **لا واحد سلوكيّاً** — الـ131 مسارَ `admin/*` كلُّها تحرس في المتحكّم (`gate()`/`blocksGate()`/`hub_is_owner`/`hub_can`). لكنّ الضمانةَ عُرفيّةٌ لا بنيويّة: **A05‑11** |
| مسارُ عميلٍ بلا `PortalGuard` | `PortalGuard` مُلحَقٌ بمجموعة `web` كلِّها، و`MobilePortalGuard` بمجموعة الجوال المُصادَقة. مجموعةُ `/api/v1` **لا تحمل أيَّ نظيرٍ لهما** — الحجزُ فيها يدويٌّ في `resolveApi()`، وثقبٌ واحدٌ يفلت منه: **A05‑02** |
| مسارٌ حسّاسٌ بلا step‑up | أربعةُ مواضع: ويبهوك صادر/وارد (**A05‑04**)، ونسخةٌ احتياطيّةٌ وعُدّةُ انطلاق (**A05‑05**) |
| مسارُ API بلا حدِّ معدّل | **لا واحد** — كلُّ الـ119 مسارَ API تحمل خانقاً (`throttle:api` 300/IP+120/مفتاح، أو خانقاً ضيّقاً مسمّى). أمّا الويبُ فلا خانقَ على 374 مساراً مُصادَقاً، ومنها ستُّ نقاطٍ ثقيلةٍ بين إخوةٍ محدودة: **A05‑09** |

### ترتيبُ الوسطاء الفعليّ (مُستخرَجٌ حيّاً من `Router::gatherRouteMiddleware`)

```
/            (dashboard)  : EncryptCookies → StartSession → ShareErrors → ValidateCsrf → Authenticate
                            → SubstituteBindings → HubMaintenance → IpDefense → SessionSentry → WorkHours
                            → Require2faForPrivileged → ForcePasswordChange → PortalGuard → TrackVisits
                            → ResolveChunkedUploads → DownloadPing → SecurityHeaders → Observability → AccessRadar
/api/mobile/v1/home      : Throttle:api → SubstituteBindings → HubMaintenance → IpDefense → SecurityHeaders
                            → Observability → AccessRadar → MobileSessionAuth → MobilePortalGuard → MobileContext
/api/v1/me               : Throttle:api → SubstituteBindings → HubMaintenance → IpDefense → SecurityHeaders
                            → Observability → AccessRadar → ApiAuth
/up                      : (لا شيء)
```

الترتيبُ سليمٌ ومطابقٌ لما توثّقه تعليقاتُ `bootstrap/app.php`: `Authenticate` يُرفَع بأولويّة الإطار إلى ما قبل الوسطاء المُلحَقة،
فـ`PortalGuard` يقرأ مستخدماً مُرسًى، و`AccessRadar` أخيراً (الأقربَ للمتحكّم) فيلتقط ٤٠٣ الذي يرميه `abort` قبل أن يصعد،
و`throttle` قبل `ApiAuth`/`mobile.session` فيُحَدُّ غيرُ المصادَقِ قبل أن يُقصَّ بـ401. **لا خللَ ترتيبٍ في أيّ مسار.**

---

## ٣ · تغييرُ حالةٍ عبر GET

سبعةٌ من 283 مسارَ GET تُغيّر حالةَ عمل. (وثلاثةٌ وعشرون غيرُها تُلحِق **سكّةَ التدقيق/التنزيل الواحدة** — `download_log`
و`ContractEvent::log` و`hub_audit` — وهي مقصودةٌ بالتصميم: «حزمةٌ تُخرج اثني عشر ملفاً لا يجوز أن تظهر في الأثر تنزيلاً واحداً».)

| # | المسار | ما يُكتَب | الحكم |
|---|---|---|---|
| 1 | `GET /notifications/{id}/go` | `read = true` ثمّ تحويل | منطَّقٌ بـ`user_id = auth()->id()`؛ أثرُ تزويرٍ عبر `<img>` = تعليمُ إشعارِ الضحيّةِ مقروءاً |
| 2 | `GET /conversations/{id}` | `conversation_members.last_read_at` + `read_by` | إيصالُ قراءةٍ لصاحبه — اصطلاحيّ |
| 3 | `GET /dm/{userId}` | `DmService::markThreadRead` | كسابقه |
| 4 | `GET /dm/{userId}/since` | `dm_messages.read_at` للوارد إليّ | كسابقه |
| 5 | `GET /api/mobile/v1/dm/threads/{user}/since` | نظيرُ 4 | كسابقه |
| 6 | `GET /attachments/{module}/{recordId}/zip` | `attachments.downloads++` + صفوفُ `download_log` | عدّادٌ + أثر |
| 7 | `GET /sign/{token}` | `opened_at` · `opens++` · `ContractEvent('opened')` · **إشعارٌ صادرٌ للمالكين** | **الوحيدُ الذي يُصدِر أثراً خارجيّاً** |

**لا واحدَ منها P1.** الستّةُ الأُوَل مُنطَّقةٌ بالقارئِ نفسِه وعديمةُ الأثر عند الإعادة. والسابعُ — رغم أنّه يكتب حدثاً
ذا قيمةٍ قانونيّةٍ ويُرسل إشعاراً — محجوزٌ خلف `session("sign.ok.{token}")`، فلا يبلغه جالبٌ مسبقٌ (prefetcher) ولا ماسحُ
روابطِ بريدٍ لا جلسةَ له. الملاحظةُ مُسجَّلةٌ بخطورتها الحقيقيّة في **A05‑07**، لا مُضخَّمةً إلى P1 بقاعدةٍ عامّة.

---

## ٤ · مساراتٌ ميّتة · ٥ · عدمُ تطابقِ مسار/قالب

- **دالّةٌ مفقودة: صفر.** فُحصت الـ632 بـ`class_exists` + `method_exists` + `isPublic` — كلُّها موجودةٌ عامّة.
- **قالبٌ مفقود: صفر.** 194 اسمَ قالبٍ متمايزاً في `app/` كلُّها لها `.blade.php`. (الماسحُ الأوّلُ أبلغ عن `ios`/`android`
  في `MobilePlatformController` — إيجابيّةٌ كاذبة: النصُّ `appConfigPre**view('ios'**…` ينتهي بـ`view(`.)
- **بلا مرجعٍ ولا اختبار: 7** — `GET /up` (A05‑01) وستّةٌ في **A05‑08**.

---

## ٦ · الترتيب

**صفرُ تظليل.** بُني لكلِّ مسارٍ عيّنةٌ تحترم قيودَ `where` الخاصّةَ به، ثمّ سُئل `RouteCollection::match()` — وكلُّ مسارٍ
من الـ632 يُطابق **نفسَه**. الانضباطُ الذي تَعِدُ به تعليقاتُ ملفَّي المسارات (Critic F9) مُطبَّقٌ فعلاً:

- `/api/v1`: `openapi.json` · `reports/*` · `metrics/*` · `identity/*` · `track/*` · `projects/{id}/assets` — كلُّها قبل `{module}`.
- `/api/mobile/v1`: المجموعةُ العامّةُ كلُّها قبل المُصادَقة، وكلُّ حرفيّاتها (approvals/home/search/prefs/portal/clients/
  notifications/comments/dm/push/conversations/presence/saved/files/identity/tracking/sync/`{module}/{id}/actions`) قبل الـcatch‑all.
- `/m/{module}`: `board`/`bulk`/`create`/`export`/`import` قبل `{module}/{id}`.
- `s/{code}` (ملصقُ المحطة، مُقيَّدٌ بـ`[A-Za-z0-9]+-[A-Za-z0-9-]+`) قبل `s/{token}` (رمزُ مشاركةٍ بلا شرطة) — لا تقاطع.

**تنبيهٌ بنيويّ (لا عيبٌ اليوم):** `POST {module}/{id}/announce` و`POST {module}/{id}/ack` هما المساران الوحيدان عند **جذر**
الموقع بمقطعين متغيّرين. يحميهما `whereIn('module', ['policies','kb'])`، فلا يبتلعان شيئاً — لكنّ أيَّ مسارٍ جذريٍّ
حرفيٍّ ثلاثيِّ المقاطع يُضاف مستقبلاً بعدهما سيُظلَّل إن وقع اسمُ وحدتِه ضمن القيد. يُسجَّل للمعرفة لا للإصلاح.

---

## ٧ · مساراتُ الآلة

| السطح | الحارس | الحكم |
|---|---|---|
| `POST hook/{token}` | رمزٌ 48 خانةً + HMAC **اختياريّ** + خانق 120/د + `insertOrIgnore` على `event_id` | مقبولٌ بحدود — **A05‑10** (الافتراضُ ينبغي أن يكون التوقيع) |
| `POST/GET api/v1/endpoint/*` | `EndpointSignature`: ES256 على (method, path, ts, nonce, body) + طابعٌ ±300ث + `nonce` فريدٌ ذرّيّاً (409 للإعادة) + تشذيبُ 20د | **قويّ** — وأفضلُ سطحٍ آليٍّ في المنظومة |
| `GET healthz` | خانق 30/د + تجريدٌ صريحٌ من 8 وسطاء + `publicView` (حالةٌ + نسخةٌ فقط، لا رسائلَ ولا آثار) | جيّد — لكنّ `StartSession` باقٍ: **A05‑03** |
| `GET /up` | **لا شيء** | **A05‑01** |
| `GET manifest.webmanifest` · `pwa-icon.svg` | لا خانق، ومحتوىً من إعدادين | مقبول (ثابتٌ ورخيص) — لكنّه يمرّ بـ`StartSession` أيضاً: A05‑03 |
| `GET .well-known/*` | خانق 60/د + تجريدٌ من 8 وسطاء + `NOT_CONFIGURED` صادقة | جيّد — و`StartSession` باقٍ: A05‑03 |

---

## ٨ · الرفعُ والتنزيل

**كلُّ مسارِ تنزيلٍ يمرّ بتخويلٍ على مستوى السجلّ لا الوحدةِ فقط** — عدا استثناءً واحداً موثَّقاً.

| المسار | التخويل |
|---|---|
| `attachments/{id}/dl` · `/view` | `AttachmentService::guardRecord` (`hub_scope(...)->findOrFail` ⇒ خارج النطاق ٤٠٤) ثمّ `DocumentPolicy::authorize` (منعٌ صريحٌ على الوثيقةِ بعينها ⇒ ٤٠٣) |
| `attachments/{module}/{recordId}/zip` | `guardRecord` على السجلّ، ثمّ **`DocumentPolicy::allows` لكلِّ ملفٍّ داخل الحزمة** — فلا يلتفُّ الجماعيُّ على منعٍ فرديّ |
| `me/documents/{id}/dl` · `/view` | `myDoc()` (ارتباطُ `employees.user_id`) + `DocumentPolicy::subjectMay` |
| `portal/documents/{id}/download` | `gate()` عميليّ + `ClientPortalData::documentFile($ids,$id)` + حاجزُ «سري» + `DocumentPolicy::authorize` |
| `api/mobile/v1/files/{id}/download` · `/stream` · `me/documents/{id}/file` | السكّةُ نفسُها (`AttachmentService`) عبر `serve`/`streamServe` |
| `quote|purchase|changeorder|esign /{id}/doc|pdf` | `hub_can(وحدة,'v')` + `hub_scope(...)->findOrFail` + قفلُ الحقل (`hub_field_mode`) يسري على الورقة كما على الشاشة |
| `m/{module}/export` | `resolve($module,'v')` ثمّ `exportBelt` ⇒ `hub_can($u,$module,'export')` صراحةً + `WorkHours` يمنع التصدير ليلاً إلا بـ`exportNight` |
| `s/{token}/file` | الرمز + بوّابةُ كلمةِ السرّ + حاجزُ النوع (SVG/HTML تنزيلٌ قسريّ) + وسمُ «عرض فقط» محروقٌ في البايتات |
| `sign/{token}/doc|pdf|certificate` | `session("sign.ok.{token}")` |
| **`endpoints/releases/{id}/download`** | `abort_if(hub_is_client(...), 404)` فقط — **أيُّ موظّفٍ داخليٍّ مُصادَقٍ ينزّل أيَّ إصدارِ وكيل**. قرارٌ موثَّقٌ صراحةً في الكود (Permissions 360 · 12.6: «التوزيعُ الداخليُّ مقصودٌ لكلِّ موظّفٍ كي يثبّت وكيلَه بنفسه») وكلُّ تنزيلٍ مُقيَّدٌ في `download_log`. **خطرٌ مقبولٌ معلَنٌ لا عيبٌ صامت.** |

**الرفع:** `ResolveChunkedUploads` يُلحَق بمجموعة `web` كلِّها فيحوّل الملفَّ المقطَّع إلى مرفوعٍ عاديٍّ قبل المتحكّم — سكّةٌ واحدة.
والجوالُ يعيد استعمالَ `ChunkedUpload` نفسَه بلا جدولٍ ثانٍ. `WorkHours` يصدُّ **أيَّ** طلبٍ يحمل ملفاً خارج الدوام
(‏`$r->allFiles()` لا قائمةَ مساراتٍ محفوظة) — «لِما يُضاف غداً» كما يقول تعليقُه.

---

# الملاحظات

## A05-01 · P2 · `GET /up`: مسارُ صحّةٍ افتراضيٌّ بلا أيِّ وسيط، ويحمّل سكربتاً خارجيّاً

**الدليل.**
`bootstrap/app.php:12` — `->withRouting(… health: '/up')`. و`route:list` يُظهره بمصفوفةِ وسطاءَ **فارغة**،
ودمجُ الوسطاء الحيُّ (`Router::gatherRouteMiddleware`) يعيد لائحةً خاليةً كذلك. جسمُه هو
`vendor/laravel/framework/src/Illuminate/Foundation/resources/health-up.blade.php` الذي يحمّل
`https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4` و`https://fonts.bunny.net` ويطبع `config('app.name')`.

يترتّب على ذلك أنّ هذا المسار وحدَه في المنظومة كلِّها:
- بلا `SecurityHeaders` ⇒ ردٌّ بلا CSP ولا `X-Frame-Options` ولا `X-Content-Type-Options`؛
- بلا `IpDefense` ⇒ عنوانٌ محظورٌ في `ip_rules` يبلغه؛
- بلا `HubMaintenance` ⇒ يردّ 200 «كلُّ شيءٍ بخير» والنظامُ في صيانة؛
- بلا خانق ⇒ لا سقفَ على طَرقِه؛
- بلا `Observability` ولا `AccessRadar` ⇒ لا يظهر في أيِّ رصدٍ ولا سجلّ.

وهو **مكرِّرٌ** لـ`healthz` الذي صُلِّب عمداً في v2.399 لهذا الغرض بعينه، وصفحتُه إنجليزيّةٌ LTR بهويّة Laravel
لا بهويّة النظام — فلو وصلها مستخدمٌ رأى منتَجاً آخر.

**الإصلاح.** حذفُ `health: '/up'` من `withRouting` (‏`healthz` يؤدّي وظيفتَه أتمَّ). وإن بقي لسببٍ تشغيليّ:
توجيهُه إلى `OpsController@health` مع `middleware('throttle:30,1')` و`withoutMiddleware` نفسِها التي يحملها `healthz`.

**اختبارُ الانحدار.**
```php
public function test_up_probe_is_not_an_unguarded_second_health_surface(): void
{
    $r = $this->get('/up');
    $this->assertTrue($r->isNotFound() || $r->headers->has('Content-Security-Policy'),
        'المسار /up إمّا يُحذف أو يمرّ بـSecurityHeaders');
    if (! $r->isNotFound()) $r->assertDontSee('cdn.jsdelivr.net', false);
}
```

---

## A05-02 · P2 · `/api/v1/identity/resolve/{q}`: لا حجزَ لحسابِ العميل — وثقبٌ فوقَ مصفوفةِ الأدوار

**الدليل.**
`app/Http/Controllers/Api/V1Controller.php:603` — الحارسُ الوحيدُ في `identityResolve()` هو
`abort_unless($this->tokenAllows($module,'v'), 403, …)`. و`tokenAllows` (V1Controller.php:595) تعيد
`! $t || $t->allows($module,$op)` — أي **تمرّ دائماً لمفتاحٍ بلا نطاقات**. ولا نداءَ لـ`resolveApi()`،
وهي الدالّةُ الوحيدةُ في `/api/v1` التي تفرض حجزَ العميل:

```php
// V1Controller.php:363-368  (داخل resolveApi فقط)
if (hub_is_client(auth()->user())
    && ! in_array($module, \App\Http\Middleware\PortalGuard::MODULE_ALLOW, true)) {
    Api::abort(Api::RESOURCE_NOT_FOUND, 404, 'وحدة غير معروفة', …);
}
```

و`Identity::resolve` (‏`app/Support/Identity.php:131,138,159,164`) تحرس بـ`hub_can` + `hub_scope` **فقط**.
وتعليقُ `PortalGuard` نفسِه يشرح لماذا لا يكفي ذلك: «من ٨٢ وحدة، ١٥ فقط لها عمودُ عميلٍ يعزلها `hub_scope`؛
الـ٦٧ الباقية … بلا أيّ ترشيحِ عميلٍ من الرصيف — فلو مُنِح دورُ العميلِ إحداها لعادت **كلَّ** صفوفها بلا فلتر».
والوحداتُ التي يمسّها هذا المسارُ (`assets`, `products`, `stock`, `phones`) **ليست** في `PortalGuard::MODULE_ALLOW`
(‏`engagements`, `projects`, `fin`).

**التفاوتُ يُثبت أنّ هذا سهوٌ لا قرار:** النظيرُ على الجوال مُغلَقٌ ومُختبَر —
`mobile.identity.resolve` غائبٌ عن `MobilePortalGuard::NAME_ALLOW`، و`tests/Feature/Mobile/MobilePortalGuardTest.php:85`
يؤكّد `getJson('/api/mobile/v1/identity/resolve/ABC')->assertStatus(404)` لحسابِ عميل.
أمّا `tests/Feature/IdentityRegistryTest.php:183-189` فيختبر المالكَ وغيرَ المصادَقِ فقط — لا حسابَ عميلٍ إطلاقاً.

**الأثر.** حسابُ عميلٍ مُنِح دوراً فيه `assets:v` (أو `products:v`/`stock:v`) يقرأ **أيَّ** كودِ عهدةٍ أو باركودٍ أو
سيريالٍ في المنظومة كلِّها ويستردّ `id` و`code` و`name` — وهو بالضبط السيناريو الذي بُني `PortalGuard` «فوق المصفوفة»
ليجعله مستحيلاً.

**الإصلاح.** سطرٌ واحدٌ في رأس `identityResolve()`، بنمط `ReportsApiController:24` نفسِه:
```php
abort_if(hub_is_client(auth()->user()), 404);   // نظيرُ MobilePortalGuard — العميلُ لا يمسح
```

**اختبارُ الانحدار.**
```php
public function test_client_account_cannot_resolve_identifiers_over_v1(): void
{
    $client = $this->clientUserWithRole(['assets' => 'v']);   // دورُ عميلٍ مُساءُ الضبط
    $token  = $this->mintApiToken($client);                    // بلا نطاقاتٍ — أوسعُ حالة
    $asset  = Asset::factory()->create(['code' => 'LYN-A-777777']);

    $this->getJson('/api/v1/identity/resolve/LYN-A-777777',
        ['Authorization' => 'Bearer ' . $token])->assertNotFound();

    // والسطحُ الجوّاليُّ يبقى مغلقاً كما هو (لا انحدار)
    $this->withHeaders($this->clientMobileHeaders())
        ->getJson('/api/mobile/v1/identity/resolve/LYN-A-777777')->assertNotFound();
}
```

---

## A05-03 · P3 · مساراتُ الآلة تمرّ بـ`StartSession` — جلسةٌ تُكتَب لكلِّ نبضةِ مراقبةٍ وكلِّ ويبهوك

**الدليل.**
`routes/web.php:78-81` يَعِد أنّ `healthz` «**بلا وسطاء الجلسة والصيانة**» ويُعلّل: «كان `setting()` في وسيطٍ سابقٍ
يرمي ٥٠٠ حين تسقط القاعدةُ فلا يقول المسبارُ ‹القاعدة ساقطة›». لكنّ قائمةَ `withoutMiddleware` تستثني ثمانيةَ
وسطاءَ **من صنعِ المشروع** فقط (`HubMaintenance`, `WorkHours`, `SessionSentry`, `TrackVisits`,
`Require2faForPrivileged`, `ResolveChunkedUploads`, `DownloadPing`, `AccessRadar`) — و`StartSession` عضوٌ في
مجموعة `web` الافتراضيّةِ فيبقى. الترتيبُ الفعليُّ المُستخرَج:

```
healthz : EncryptCookies → AddQueuedCookies → StartSession → ShareErrorsFromSession
          → ValidateCsrfToken → Throttle:30,1 → SubstituteBindings → IpDefense
          → ForcePasswordChange → PortalGuard → SecurityHeaders → Observability
```

و`Illuminate\Session\Store::start()` تضع `_token` في كلِّ جلسةٍ جديدة، فتُحفَظ الجلسةُ فعلاً ويُرسَل `Set-Cookie`
لكلِّ طلبٍ مجهول. ينطبق ذلك على `healthz` و`.well-known/apple-app-site-association` و`.well-known/assetlinks.json`
و`manifest.webmanifest` و`pwa-icon.svg` و`offline` و`s/{token}` و**`hook/{token}` بخانقِ 120/د**.

**الأثر.** (أ) مسبارُ مراقبةٍ كلَّ 30 ثانية = 2,880 جلسةً في اليوم، وويبهوكٌ مزدحمٌ = مئاتُ الآلاف؛
(ب) وأخطرُ منه: مع `SESSION_DRIVER=database` يكتب المسبارُ في القاعدةِ نفسِها التي جاء ليُخبر عن صحّتها —
فسقوطُ القاعدة يُحوّل الردَّ الصادقَ «db: fail» إلى استثناءٍ في وسيطٍ سابق، وهو عينُ العلّةِ التي نصَّ التعليقُ على إصلاحها.

**الإصلاح.** إضافةُ `EncryptCookies` و`AddQueuedCookiesToResponse` و`StartSession` و`ShareErrorsFromSession`
و`ValidateCsrfToken` إلى `withoutMiddleware([...])` في `healthz` والوثيقتين، ونقلُ `hook/{token}` إلى مجموعة `api`
(هي بلا جلسةٍ أصلاً، والاستثناءُ `validateCsrfTokens(except:['hook/*'])` يصير حينئذٍ زائداً لا محذوفاً).

**اختبارُ الانحدار.**
```php
public function test_machine_probes_do_not_open_a_session(): void
{
    foreach (['/healthz', '/.well-known/assetlinks.json', '/.well-known/apple-app-site-association'] as $u) {
        $cookies = $this->get($u)->headers->getCookies();
        $names = array_map(fn ($c) => $c->getName(), $cookies);
        $this->assertNotContains(config('session.cookie'), $names, "المسبار {$u} فتح جلسة");
    }
}
```

---

## A05-04 · P3 · الويبهوكُ الصادرُ والواردُ يُنشآن بلا تصعيدِ هوية

**الدليل.**
`app/Http/Controllers/Web/WebhookController.php:31` — `store()` تبدأ بـ`$this->gate()` (مالك) ثمّ تُنشئ الاشتراك
مباشرةً. ولا `hub_require_stepup` في `store` ولا `toggle:56` ولا `destroy:67` ولا `test:80` ولا `resend:114`.
ونظيرُه الوارد: `InboundHookController@store:110` و`toggle:135` و`destroy:145` — كلُّها `gate()` فقط.

وهذا شذوذٌ داخل المستودع نفسِه: كلُّ فعلٍ آخرَ من هذا الوزن يطلب تصعيداً —
`SettingController` (موضعان) · `UserController@destroy:264`/`unlock:298` · `RoleController` (موضعان) ·
`SecurityController` (سبعةُ مواضع) · `OpsController` (أربعةٌ عبر `hub_require_ops_stepup`) ·
`ProfileController@tokenStore:61`/`tokenRotate:119` (عبر `hub_require_credential_stepup`) ·
`InventoryController` · `EmployeeCustodyController` · `EndpointMdmController` · `EndpointReleaseController` ·
`OversightController` · `ClientMemberController` · `ModuleController` · `FeatureController`.

**الأثر.** اشتراكُ ويبهوكٍ صادرٍ قناةُ تسريبٍ **دائمة**: كلُّ حدثِ أعمالٍ يُدفَع إلى عنوانٍ يختاره المهاجم.
حارسُ SSRF عند الإنشاء (`WebhookController:40-41`) يردّ العناوينَ الخاصّةَ/الداخليّة فقط — والعنوانُ العامُّ مقبول.
ونقطةُ استقبالٍ واردةٌ قناةُ **كتابةٍ** دائمةٌ برمزٍ يختاره النظامُ ويراه المهاجم. وسرقةُ الجلسةِ وحدَها تفتح أيَّهما.

**الإصلاح.** في `store`/`destroy`/`toggle` من المتحكّمين:
```php
if ($resp = hub_require_stepup(route('webhooks.index', absolute: false))) return $resp;
if ($resp = hub_require_stepup(route('hooks.index',    absolute: false))) return $resp;
```

**اختبارُ الانحدار.**
```php
public function test_creating_an_outbound_webhook_requires_fresh_identity(): void
{
    $this->actingAs($this->owner())                       // بلا تصعيدٍ طازج
        ->post(route('webhooks.store'), ['name' => 'x', 'url' => 'https://evil.example/x', 'events' => '*'])
        ->assertRedirectContains(route('stepup.show', absolute: false));

    $this->assertDatabaseMissing('webhooks', ['url' => 'https://evil.example/x']);
}
```

---

## A05-05 · P3 · `admin/ops/backup` و`admin/ops/starters`: أفعالٌ عاليةُ الأثر بلا تصعيدٍ بين إخوةٍ مُصعَّدة

**الدليل.** في `OpsController` نفسِه:

| الدالّة | السطر | تصعيد |
|---|---|---|
| `toggleMaintenance` | 666 | ✅ `hub_require_ops_stepup()` |
| `migrate` | 687 | ✅ |
| `clearCache` | 811 | ✅ |
| `outboxRetry` | 1011 | ✅ |
| **`backupNow`** | **644** | ❌ `$this->gate()` فقط |
| **`starters`** | **864** | ❌ `$this->gate()` فقط |

`backupNow` يُشغّل `Artisan::call('hub:backup')` بمهلة 300 ثانية — ويُنتج **أغنى قطعةٍ في المنظومة كلِّها**:
نسخةً كاملةً من القاعدة على القرص. و`clearCache` — وهو أقلُّ خطراً منه بمراحل — مُصعَّد. `starters` يُشغّل ثلاثةَ
أوامرِ توليدٍ تكتب في `flows` و`alerts` و`kpis`.

**الإصلاح.** السطرُ نفسُه في الدالّتين: `if ($resp = hub_require_ops_stepup()) return $resp;`

**اختبارُ الانحدار.**
```php
public function test_manual_backup_requires_ops_step_up(): void
{
    setting_put('security.stepup_ops', '1');
    $this->actingAs($this->owner())->post(route('ops.backup'))
        ->assertRedirectContains(route('stepup.show', absolute: false));
}
```

---

## A05-06 · P3 · ثلاثةُ مساراتٍ حيّةٍ على `/api/v1` خارجَ المواصفةِ المنشورة

**الدليل.** `routes/api.php:22-24` يُسجّل ثلاثةَ مسارات:
```
GET /api/v1/reports/my-daily          → ReportsApiController@myDaily
GET /api/v1/reports/today-compliance  → ReportsApiController@todayCompliance
GET /api/v1/reports/daily             → ReportsApiController@teamDaily
```
و`docs/openapi.json` يحوي 182 مساراً ليس فيها واحدٌ منها (بينما `/api/v1/reports/health` و
`/api/v1/reports/progress/{projectId}` موجودان). السببُ الجذريّ: `app/Support/OpenApi.php:255+` يحتفظ بالمسارات
**الحرفيّة** (غيرِ `{module}`) في مصفوفةٍ مكتوبةٍ باليد داخل المولِّد — فمسارٌ حرفيٌّ جديدٌ يسقط من العقد صامتاً،
ولا تكشفه بوّابةُ CI لأنّ الملفَّ المولَّد لم ينحرف عن نفسِه.

والمقارنةُ تُثبت أنّ الحلَّ معروفٌ في المستودع: `app/Support/MobileOpenApi.php:13` يبني من
`RouteFacade::getRoutes()` مُرشَّحاً على البادئة — فلا يتقادم أبداً.

**الإصلاح.** الأفضلُ ليس إضافةَ الثلاثةِ يدوياً بل إغلاقُ الباب: يُسأل المولِّدُ عن كلِّ مسارِ `api/v1/*` بلا
معامل `{module}` وخارجَ `v1/endpoint/*`، ويرمي إن وجد واحداً بلا مدخل.

**اختبارُ الانحدار.**
```php
public function test_every_literal_v1_route_is_in_the_published_spec(): void
{
    $spec = array_keys(json_decode(file_get_contents(base_path('docs/openapi.json')), true)['paths']);
    foreach (Route::getRoutes() as $r) {
        if (! str_starts_with($r->uri(), 'api/v1/')) continue;
        if (str_contains($r->uri(), '{module}') || str_starts_with($r->uri(), 'api/v1/endpoint/')) continue;
        $this->assertContains('/' . $r->uri(), $spec, 'مسارٌ حيٌّ خارج المواصفة: ' . $r->uri());
    }
}
```

---

## A05-07 · P4 · سبعةُ مساراتِ GET تُغيّر حالةَ عمل

**الدليل** — الجدولُ في القسم ٣، ومواضعُه:
`NotificationController@go:55` (`forceFill(['read'=>true])->save()`) ·
`ConversationController@show:234,238-241` (`markReadPublic` + `last_read_at`) ·
`DmController@thread:259` (`DmService::markThreadRead`) · `DmController@since:419-422` (`read_at`) ·
`MobileCollabController@dmSince` (نظيرُه) · `AttachmentController@zip:117+` (عدّادٌ + `download_log`) ·
`EsignController@show:788-796` (`opened_at` + `ContractEvent::log('opened')` + `notifyOwners` + `increment('opens')`).

**الحكمُ الصادق.** ستّةٌ منها إيصالاتُ قراءةٍ وعدّاداتٌ منطَّقةٌ بالقارئِ نفسِه — لا تعبر حدودَ مستخدمٍ ولا تُصدِر أثراً،
وإعادتُها لا تُغيّر شيئاً. والسابعُ (`sign.show`) يكتب حدثاً ذا قيمةٍ قانونيّةٍ ويُرسل إشعاراً، لكنّه محجوزٌ خلف
`session("sign.ok.{$token}")` (‏EsignController:778) — فماسحُ روابطِ البريدِ الذي يجلب الرابطَ مسبقاً لا جلسةَ له
ولا يبلغ سطرَ الكتابة. **لا واحدَ منها قابلٌ للاستغلال بأكثر من ضجيج**، ورفعُها إلى P1 بقاعدةٍ عامّةٍ يُغرِق الإشارةَ.

**الإصلاح (تجميلٌ لا إسعاف).** نقلُ `notifications/{id}/go` إلى `POST` داخلَ نموذجٍ صغيرٍ في القائمة (هو الوحيدُ
الذي يقبل التحويلَ بلا كسرِ رابطٍ مُرسَل)، وإبقاءُ البقيّةِ كما هي مع تعليقٍ يوثّق القرار.

**اختبارُ الانحدار.** ماسحٌ بنيويٌّ يمنع **الجديد** لا يعيد كتابةَ القديم:
```php
public function test_no_new_get_route_writes_business_state(): void
{
    $allowed = ['notifications.go','conversations.show','dm.thread','dm.since',
                'mobile.dm.since','att.zip','sign.show'];   // مُعلَنةٌ بأسبابها أعلاه
    foreach (Route::getRoutes() as $r) {
        if (! in_array('GET', $r->methods(), true) || in_array($r->getName(), $allowed, true)) continue;
        $body = $this->controllerMethodSource($r);          // مُوازِنُ أقواسٍ كما في ماسحِ الوكيل
        $this->assertDoesNotMatchRegularExpression(
            '/->save\(\)|saveQuietly\(|->forceFill\(|->increment\(|markThreadRead/', $body,
            'مسارُ GET جديدٌ يكتب: ' . $r->uri());
    }
}
```

---

## A05-08 · P4 · ستّةُ مساراتٍ حيّةٍ لا يمسّها اختبارٌ ولا مرجعٌ في المستودع — وفيها اسمان لدالّةٍ واحدة

**الدليل.** بحثٌ عن اسمِ المسار وعن بادئةِ رابطِه الحرفيّةِ في `resources/` و`app/` و`tests/` و`config/`
و`public/` و`agent/` و`docs/openapi.json`، فلم يُذكر أيٌّ من هذه إلا في تعريفِه ومتحكّمِه:

| المسار | ملاحظة |
|---|---|
| `GET /api/mobile/v1/portal/documents` · `/{id}` | بقيّةُ `portal/*` مُغطّاةٌ في `tests/Feature/Mobile/MobileClientPortalTest.php` (home/projects/invoices/conversations) — الوثائقُ وحدَها خارجَه |
| `GET /api/mobile/v1/portal/engagements` | كسابقه |
| `GET /api/mobile/v1/work/today` | لا اختبار |
| `GET /api/mobile/v1/work/daily-report` | **اسمٌ ثانٍ لنفسِ الدالّةِ حرفيّاً** (`MobileReportsController@today`) — اسمان لمعالجٍ واحدٍ لا يُشير إلى أيٍّ منهما عميل |
| `GET /api/v1/reports/today-compliance` | لا اختبار، **وخارجَ المواصفة** كذلك (A05‑06) — مسارٌ لا يعرفه أحد |

هذه ليست «ميّتةً» بالمعنى الصارم (دالّاتُها موجودةٌ وسطحُ الجوال مولَّدٌ في مواصفتِه)، لكنّها **بلا شبكةٍ**:
انحدارٌ فيها لا يُسقط شيئاً. و`work/daily-report` مرشّحُ حذفٍ أو توثيقٍ صريحٍ كاسمٍ مستعارٍ متوافق.

**الإصلاح.** اختبارُ تغطيةٍ واحدٌ لكلٍّ منها (وجودٌ + عزلٌ عبر مستخدمٍ آخر)، وحسمُ أمرِ الاسم المستعار.

**اختبارُ الانحدار.**
```php
public function test_mobile_portal_documents_are_isolated_per_client(): void
{
    $h = $this->clientHeaders();                                  // عميلُ A
    $this->withHeaders($h)->getJson('/api/mobile/v1/portal/documents')->assertOk()
         ->assertJsonMissing(['id' => $this->documentOfClientB->id]);
    $this->withHeaders($h)->getJson('/api/mobile/v1/portal/documents/' . $this->documentOfClientB->id)
         ->assertNotFound();
}
```

---

## A05-09 · P4 · نقاطٌ ثقيلةٌ بلا حدِّ معدّلٍ بين إخوةٍ محدودة

**الدليل.** 374 مساراً تحمل `auth + web` بلا خانق. ذلك عاديٌّ لتطبيقِ جلسةٍ — إلّا أنّ السياسةَ نفسَها **غيرُ متّسقة**
على النقاط المُكلِفة:

| محدودة | غيرُ محدودةٍ رغم كلفتها المماثلة |
|---|---|
| `m/{module}/export` — `throttle:20,1` | `reports/monthly/export` — `auth` فقط |
| `admin/settings/export` — `20,1` | `quote/{id}/pdf` · `changeorder/{id}/pdf` · `esign/{id}/pdf` (تصييرُ mPDF) |
| `portal/documents/{id}/download` — `60,1` | `attachments/{module}/{recordId}/zip` (بناءُ أرشيفٍ على القرص) |
| `admin/security/ips/{ip}` — `60,1` | `admin/ops/backup` (نسخةُ قاعدةٍ كاملة، `set_time_limit(300)`) |

وأبرزُها سطحٌ **عامّ**: `GET s/{token}/file` بلا خانقٍ إطلاقاً، وعلى روابط «عرض فقط» يُشغّل
`Watermark::pdf`/`Watermark::image` لكلِّ طلبٍ (‏`DataRoomController:165-171`) — تصييرٌ ثقيلٌ بلا سقف.
(‏`POST s/{token}` الفاتحُ محدودٌ بـ10/د، والقراءةُ ليست كذلك.)

**الإصلاح.** قاعدةٌ واحدةٌ مكتوبة: كلُّ مسارٍ يُصيّر PDF أو يبني أرشيفاً أو يُصدّر صفوفاً يحمل `throttle:20,1`،
والعامُّ `share.file` يحمل `throttle:60,1`.

**اختبارُ الانحدار.**
```php
public function test_expensive_routes_carry_a_rate_limit(): void
{
    $heavy = ['quotes.pdf','changeorders.pdf','esign.pdf','att.zip','reports.monthly.export','ops.backup','share.file'];
    foreach (Route::getRoutes() as $r) {
        if (! in_array($r->getName(), $heavy, true)) continue;
        $this->assertTrue((bool) preg_grep('/^throttle:/', $r->gatherMiddleware()),
            'نقطةٌ ثقيلةٌ بلا خانق: ' . $r->getName());
    }
}
```

---

## A05-10 · P4 · الويبهوكُ الوارد: التوقيعُ اختياريٌّ وحمايةُ الإعادةِ اختياريّةٌ مرّتين

**الدليل.** `InboundHookController@receive`:
- السطر 41: `if (filled($hook->secret))` — التحقّقُ من HMAC يقع **فقط** إن كان للنقطة سرّ، والسرُّ يُسكّ فقط إن
  أُشِّر مربّعُ `signed` عند الإنشاء (`store:122`: `$r->boolean('signed') ? Str::random(64) : null`).
- السطر 46: ربطُ التوقيع بالطابع الزمنيّ يسري **فقط** إن اختار المُرسِلُ إرسالَ `X-Hub-Timestamp`.
- السطر 65: منعُ الإعادة يسري **فقط** إن اختار المُرسِلُ إرسالَ `X-Hub-Event-Id`.

فالنقطةُ المُنشَأةُ بالافتراض تقبل أيَّ `POST` ممّن يحوز الرمزَ، بلا توقيعٍ ولا نافذةٍ زمنيّةٍ ولا منعِ إعادة —
والرمزُ يسافر **في مسار الرابط**، فيقع في سجلّاتِ الوسيط وسجلّاتِ الوصولِ وترويسة `Referer`.
الكودُ يُعلن هذا صراحةً في تعليقِه («توقيعُ HMAC اختياريٌّ») فليس خداعاً — لكنّ الافتراضَ الآمنَ أولى من الاختيارِ الآمن.

**الإصلاح.** جعلُ `signed` مؤشَّراً افتراضاً في نموذج الإنشاء، ووسمُ النقاط غيرِ الموقَّعةِ في الشاشة
بتحذيرٍ صريحٍ («غير موقَّعة — من يحوز الرابطَ يكتب»)، وإضافةُ عدِّها إلى `SecurityPosture`.

**اختبارُ الانحدار.**
```php
public function test_new_inbound_hooks_are_signed_by_default(): void
{
    $this->actingAs($this->owner())->post(route('hooks.store'), ['name' => 'n8n']);
    $this->assertNotNull(InboundHook::where('name', 'n8n')->value('secret'),
        'نقطةُ استقبالٍ أُنشئت بلا سرّ — من يحوز الرمزَ يكتب');
}
```

---

## A05-11 · معلومة · لا وسيطَ إدارة — 131 مسارَ `admin/*` تخويلُها كلُّه عُرفٌ في المتحكّم

**الدليل.** لا اسمَ مستعارَ `admin` في `bootstrap/app.php`، ولا `$this->middleware(…)` في أيِّ متحكّم
(صفرُ نتائج في `app/Http/Controllers/`). ومع ذلك **فحصُ الـ131 مساراً واحداً واحداً أثبت أنّ كلَّ معالجٍ منها
يحرس فعلاً** — `$this->gate()` (‏187 نداءً في متحكّمات الويب) أو `blocksGate()` أو `hub_is_owner` أو
`hub_can` أو `abort_unless`. **فالحالةُ اليومَ نظيفة.**

لكنّ الضمانةَ **سلوكيّةٌ لا بنيويّة**: مسارُ `admin/*` جديدٌ يُنسى فيه سطرُ `gate()` يصير «مُصادَقاً بلا تخويل»
ولا يلتقطه شيء — لا وسيطٌ ولا اختبارٌ ولا مراجعةٌ آليّة. وهذا هو الصنفُ نفسُه من العيوب الذي يوثّق `CLAUDE.md`
أنّ الحزمةَ تكون صامتةً عنه.

**الإصلاح المقترَح (بنيويّ).** تسجيلُ وسيطٍ مسمّى `admin` يفرض `hub_is_owner() || hub_monitor_group(...)` وإلحاقُه
بمجموعةِ `admin/*`، مع إبقاءِ الحراسِ الدقيقةِ في المتحكّمات (دفاعٌ في العمق، لا استبدال). وإن تُرك العُرفُ، فاختبارٌ
بنيويٌّ يحرسه:

```php
public function test_every_admin_route_authorizes_in_its_handler(): void
{
    foreach (Route::getRoutes() as $r) {
        if (! str_starts_with($r->uri(), 'admin/')) continue;
        $body = $this->controllerMethodSource($r);
        $this->assertMatchesRegularExpression(
            '/->gate\(|Gate\(\)|hub_is_owner\(|hub_can\(|abort_unless\(|abort_if\(|hub_monitor_group\(/',
            $body, 'مسارٌ إداريٌّ بلا تخويلٍ في معالجه: ' . $r->uri());
    }
}
```

---

## A05-12 · معلومة · الترتيبُ سليمٌ تماماً — صفرُ تظليل (تحقّقٌ إيجابيّ)

**الدليل.** لكلٍّ من الـ632 مساراً بُنيت عيّنةٌ تحترم قيودَ `where` الخاصّةَ به (‏`whereUuid`, `whereIn`,
`'hub/.*'`, `'[0-9A-Fa-f:.]{3,45}'`, `'audit|radar'`, `'[A-Za-z0-9]+-[A-Za-z0-9-]+'`) ثمّ سُئل
`RouteCollection::match()` عن كلِّ فعلٍ من أفعاله — و**كلُّ مسارٍ طابق نفسَه**. لا `{module}` يبتلع حرفيّاً،
ولا `{id}` يبتلع `board`/`create`/`export`، ولا `s/{token}` يبتلع `s/{code}`.

هذا يستحقّ التسجيلَ لأنّه القسمُ الذي كانت التعليقاتُ تَعِد فيه بأكثرِ ما تَعِد (Critic F9 مذكورٌ ستَّ مرّاتٍ في
`routes/api.php`) — والوعدُ **مُنفَّذٌ حرفيّاً**. يُقترَح تثبيتُه باختبارٍ كي لا يُنقَض سهواً:

```php
public function test_no_route_is_shadowed_by_an_earlier_one(): void
{
    $routes = Route::getRoutes();
    foreach ($routes as $r) {
        $sample = $this->sampleUriRespectingWheres($r);      // بانٍ يحترم $r->wheres
        foreach (array_diff($r->methods(), ['HEAD']) as $m) {
            $this->assertSame($r, $routes->match(Request::create('/'.$sample, $m)),
                "مسارٌ مُظلَّل: {$m} {$r->uri()}");
        }
    }
}
```

---

## A05-13 · P4 · خانقُ دخولِ الجوال بالعنوان وحدَه — الدرسُ الذي تعلّمه الويبُ ولم ينتقل

**الدليل.** `routes/web.php:85-89` يوثّق درساً بعينه: «كان `throttle:10,1` بالعنوان وحدَه — فمكتبٌ كاملٌ خلف NAT
واحدٍ … يتقاسم حصّةً واحدة، ودورةُ فريقٍ صباحيّةٌ تستنفدها قبل ثالثِ زميل»، فصار `throttle:login` مفروزاً بـ(بريد+IP)
مع سقفٍ ثانٍ على العنوان. لكنّ `routes/api.php:117` يُسجّل `POST api/mobile/v1/auth/login` بـ`throttle('10,1')`
**بالعنوان وحدَه** — العيبُ نفسُه حرفيّاً، على السطح الذي يُستعمَل من هاتفٍ في مكتبٍ خلف NAT أكثرَ من أيِّ سطحٍ آخر.

**التخفيفُ القائم (يجعلها P4 لا P3).** `MobileAuthController@login:88` يستدعي `AccountLockout::bump($user)`
عند كلِّ فشلٍ — فقفلُ الحسابِ الواحد قائمٌ بكامله، والناقصُ عدالةُ التوزيعِ لا الحماية.

**الإصلاح.** محدِّدٌ مسمّى `mobile-login` بنفس شكل `login`:
```php
RateLimiter::for('mobile-login', fn ($r) => [
    Limit::perMinute(10)->by('mlogin:' . sha1(mb_strtolower(trim((string) $r->input('email')))) . '|' . $r->ip()),
    Limit::perMinute(60)->by('mlogin-ip:' . $r->ip()),
]);
```

**اختبارُ الانحدار.**
```php
public function test_two_phones_behind_one_nat_do_not_share_a_login_quota(): void
{
    for ($i = 0; $i < 10; $i++) $this->postJson('/api/mobile/v1/auth/login', $this->loginBody('a@x.com','bad'));
    $this->postJson('/api/mobile/v1/auth/login', $this->loginBody('b@x.com','right'))
        ->assertStatus(200);   // زميلٌ ثانٍ من العنوان نفسِه يدخل — لا 429
}
```

---

## خلاصةُ الأرقام

| السؤال | الجواب |
|---|---|
| كم مساراً بلا حارس؟ | **1** — `GET /up` (‏38 مساراً عامّاً مقصوداً كلُّها مبرَّرةٌ في القسم ١) |
| كم GET مُغيِّراً للحالة؟ | **7** (لا واحدَ منها P1؛ 23 آخرُ يُلحِق سكّةَ التدقيق/التنزيل بالتصميم) |
| كم مساراً ميّتاً؟ | **7** — `GET /up` + 6 بلا اختبارٍ ولا مرجع. **صفرُ** دالّةٍ مفقودة، **صفرُ** قالبٍ مفقود، **صفرُ** تظليلِ ترتيب |
