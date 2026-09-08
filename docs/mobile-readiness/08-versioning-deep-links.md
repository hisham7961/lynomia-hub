# بوّابةُ الإصدار والروابطُ العميقة — Versioning / Deep Links (Mobile Readiness · الطور C/H · §109)

> **بوّابةُ الإصدار إشارةُ عرضٍ لا تخويل، وفارغةٌ عمداً:** فراغُ الحدِّ الأدنى ⇒ **لا حجبَ أبداً**
> — نسخُ التطوير لا تُحجَب حتى يُضبط حدٌّ صراحةً. **الروابطُ العميقة قانونيّةٌ `{module,id,action}`**
> لا أسماءَ شاشاتٍ صلبة، والروابطُ العالميّة سقالةٌ **صادقةٌ NOT_CONFIGURED** تربط صفرَ تطبيقٍ حتى
> يُصدر فريقُ التطبيق معرّفاته — لا اختلاق. كلُّ ما يلي **مبنيٌّ ومُختبَرٌ على المحرّكَين**.

---

## 1) app-config وبوّابةُ الإصدار (قبل الدخول · بلا سرّ)

| المسار | التسجيل / الاسم | المعالج |
|---|---|---|
| `GET app-config` | `routes/api.php:109` · `mobile.app_config` (عامّة · `throttle:60,1`) | `MobileAuthController@appConfig` (`app/Http/Controllers/Api/MobileAuthController.php:289`) |
| `GET health` | `routes/api.php:111` · `mobile.health` (عامّة · `throttle:60,1`) | `MobileAuthController@health` (`:320`) |

`app-config` عامّةٌ وصولاً دائماً (كي يقرأ التطبيقُ منها رابطَ التحديث حتى حين يكون التحديثُ
إلزاميّاً — لا حجبَ لهذه النقطة نفسِها). يجمع من `setting()` فوق افتراضات `config('hub.mobile')`:

```json
{
  "data": {
    "mobile_api_version": "1",
    "server_time": "2026-09-08T…Z",
    "timezone": "…",
    "maintenance": false,
    "maintenance_message": null,
    "lockdown": false,
    "login_available": true,
    "version_gate": {
      "ios":     { "min": "", "latest": "" },
      "android": { "min": "", "latest": "" },
      "force_update": false
    },
    "update_required": false,
    "support_url": null,
    "store_urls": { "ios": null, "android": null }
  },
  "request_id": "…"
}
```

**البوّابة** (`mobileVersionGate()` · `MobileAuthController.php:499-514`): تقرأ الحيَّ من
`setting('mobile.min_version_ios'|'…latest_version_ios'|'…_android'|'mobile.force_update')` فوق
افتراضات `config('hub.mobile.version_gate')` (`config/hub.php:9339-9344` — كلُّها فارغةٌ افتراضاً).

- **الفارغُ ⇒ `null` ⇒ لا حجب** (`normStr` · `:488-492`) — نسخُ التطوير تمرّ.
- `store_urls`/`support_url` الغائبةُ تُعاد `null` صادقةً (NOT_CONFIGURED) لا مُختلَقة.
- `maintenance_message` يُعرَض فقط حين تكون الصيانةُ قائمة (وإلّا `null` — لا نصَّ بائت).

**حسابُ «هل يلزم هذا العميلَ تحديث؟»** (`clientUpdateRequired()` · `:522-536`): منصّةُ العميل
وإصدارُه من ترويسات التليمتري (`X-Lynomia-App-Platform`/`-Version`، أو معلمتَي استعلامٍ بديلتين).
منصّةٌ مجهولةٌ **أو** حدٌّ فارغٌ **أو** إصدارُ عميلٍ مجهولٌ ⇒ **لا حجب**. المقارنةُ `version_compare`
(semver): `update_required = version_compare($appVer, $min, '<')`. **لا يُخوّل شيئاً — إشارةُ عرضٍ فقط.**

`health` يعيد الحالةَ بأسبقيّة: قفلٌ ← صيانةٌ ← تحديثٌ مطلوبٌ (لهذا العميل) ← سليم
(`status ∈ {lockdown, maintenance, update_required, ok}` · `MobileAuthController.php:329-330`) — بلا
تليمتري بنيةٍ ولا أسرار.

---

## 2) الكودُ `APP_UPDATE_REQUIRED` (426)

مُضافٌ في `Api::CODES` من دون كسرٍ (Critic F14): `app/Support/Api.php:56` (الثابت)، والرسالةُ
العربيّة `:89`، والربطُ `426 => APP_UPDATE_REQUIRED` (`:179`). قيمةُ enum إضافيّةٌ — توافقيّةٌ لا
كاسرة، تظهر في مواصفة الجوال (`app/Support/MobileOpenApi.php:739,782`). **بوّابةُ الإصدار اليوم
إشارةُ عرضٍ** (`update_required` في `app-config`/`health`) لا حاجزٌ يردّ 426 على كلِّ نقطة؛ الكودُ
محجوزٌ للحظرِ الصريح متى قرّر فريقُ التطبيق فرضَه، فلا يُختلَق حجبٌ لم يُطلَب.

---

## 3) الرابطُ العميقُ القانونيّ — `{module, id, action}`

المصدرُ الواحدُ للوجهة `App\Support\NotificationLink::target()` (`app/Support/NotificationLink.php:28`)
— **لا اسمَ شاشةِ جوالٍ صلب ولا رابطَ ويبٍ صلب**:

```json
{ "module": "tickets", "id": "9b1e…", "action": "show" }
```

`target = null` ⇒ لا وجهةَ سجلٍّ (يعود التطبيقُ لقائمة الإشعارات) — نظيرُ الويب تماماً
(`NotificationLink::webUrl` · `:45` يطبّق الخريطةَ نفسَها فيأخذ الويبُ رابطَه دون تفرّعٍ ثانٍ). الشرطُ:
إشعارٌ على سجلِّ وحدةٍ مسجَّلةٍ (`module`+`record_id`+`hub_mod`) ⇒ وجهةُ ذلك السجل بـ`action: "show"`.

**النقاطُ التي تُصدر الوجهةَ القانونيّة** (كلُّها تحت `/api/mobile/v1`):

| النقطة | الحقل |
|---|---|
| `GET notifications/{id}/target` (`routes/api.php:189`) | `target` |
| `POST notifications/{id}/read` (`routes/api.php:190`) | `target` |
| `GET notifications` (`routes/api.php:186`) | `notifications[].target` |
| `GET search` (`routes/api.php:169`) | كلُّ نتيجةٍ `{module, id}` (نظيرُ محرّك البحث — النتيجةُ هي الوجهة) |
| `GET home` (`routes/api.php:168`) | عناصرُ لوحة العمل `{module, id}` |
| حمولةُ الدفع | `data.{module,id,action}` (`PushService::payloadFor` · `app/Support/PushService.php:213-217`) |

---

## 4) ترميزُ الوجهة في رابطٍ عالميّ

| الوجهة | الرابطُ العالميّ |
|---|---|
| `{module, id, action: "show"}` | `https://<host>/m/{module}/{id}` |
| `{module, id, action: "<other>"}` | `https://<host>/m/{module}/{id}/{action}` |

- `<host>` = `config('hub.mobile.deep_links.host')` وإلّا `config('app.url')` — **NOT_CONFIGURED** حتى
  يُصدَر النطاقُ المُصرَّح (`config/hub.php:9387`).
- أنماطُ المسار التي يلتقطها التطبيق (`paths`) الافتراضيّة: `/m/*` و`/app/*` (`config/hub.php:9388`).

---

## 5) وثيقتا الربط (تُخدَمان حيّاً · صادقتان NOT_CONFIGURED)

المعالجُ `App\Http\Controllers\Web\MobileWellKnownController` يخدمهما عند جذر النطاق **بلا إعادة توجيه**
وبنوع JSON (حيث تفرضهما Apple/Google):

| المسار | التسجيل | غيرَ مضبوطٍ (الآن) | مضبوطاً |
|---|---|---|---|
| `GET /.well-known/apple-app-site-association` | `routes/web.php:91` · `mobile.aasa` · `throttle:60,1` | `applinks.details: []` + `x-lynomia.status = NOT_CONFIGURED` + ترويسة `X-Deep-Links-Status: NOT_CONFIGURED` | `details[].appIDs = ["<team>.<bundle>"]` + `components` لأنماط المسار |
| `GET /.well-known/assetlinks.json` | `routes/web.php:98` · `mobile.assetlinks` · `throttle:60,1` | `[]` + ترويسة `X-Deep-Links-Status: NOT_CONFIGURED` | بيانُ `delegate_permission/common.handle_all_urls` للحزمة وبصماتها |

- الطريقان **عامّان** ومُجرَّدان من وسطاء الجلسة/الصيانة (`withoutMiddleware([...])` · `routes/web.php:94-100`)
  كي يبقى ردُّ CDN نظيفاً — نظيرُ `healthz`.
- الوثيقةُ تُولَّد من الإعداد (`MobileWellKnownController::config()` · `MobileWellKnownController.php:91`)
  — **بلا نشرِ كودٍ عند الضبط**: تُملأ المعرّفاتُ فتُخدَم الوثيقةُ الحقيقيّةُ تلقائيّاً.
- القالبان `docs/mobile-readiness/deep-links/apple-app-site-association.template.json` و
  `assetlinks.template.json` يوثّقان الشكلَ والمعرّفاتِ الغائبة (راجع `deep-links/README.md`).

---

## 6) ما يجب أن يُصدره فريقُ التطبيق (external config — يُوثَّق لا يُختلَق)

| المعرّف | الإعداد الحيّ | الافتراض في `config/hub.php` | الحالة |
|---|---|---|---|
| Apple Team ID | `setting('mobile.dl_apple_team_id')` | `hub.mobile.deep_links.apple.team_id` (`config:9390`) | NOT_CONFIGURED |
| iOS Bundle ID | `setting('mobile.dl_apple_bundle_id')` | `hub.mobile.deep_links.apple.bundle_id` (`config:9391`) | NOT_CONFIGURED |
| Android package name | `setting('mobile.dl_android_package')` | `hub.mobile.deep_links.android.package_name` (`config:9394`) | NOT_CONFIGURED |
| Android SHA-256 cert fingerprints | `setting('mobile.dl_android_fingerprints')` (مفصولة بفاصلة) | `hub.mobile.deep_links.android.sha256_cert_fingerprints` (`config:9395`) | NOT_CONFIGURED |
| النطاقُ المُصرَّح | `config('hub.mobile.deep_links.host')` | فارغ ⇒ `config('app.url')` (`config:9387`) | NOT_CONFIGURED |
| روابطُ المتجر | `setting('mobile.store_url_ios'|'…_android')` | `hub.mobile.store_urls` (`config:9345-9348`) | NOT_CONFIGURED |
| رابطُ الدعم | `setting('mobile.support_url')` | — | NOT_CONFIGURED |
| حدُّ/أحدثُ الإصدار + الإجبار | `setting('mobile.min_version_ios'|'latest_version_ios'|'…android'|'force_update')` | `hub.mobile.version_gate` (`config:9339-9344`) | فارغ = لا حجب |

---

## 7) سلامةُ الجهاز واختبارُ التطبيق (App Attest / DeviceCheck / Play Integrity)

**واجهاتٌ محجوزةٌ NOT_CONFIGURED فقط** (spec §Version gate): لا يُخوَّل أيُّ شيءٍ على **حالةِ العميل
المُدَّعاة** — root/jailbreak، أو ادّعاءُ سلامةِ جهاز. تلك للعرض/الرصد لا للتحكّم (التطبيقُ غيرُ
موثوق). حين يقرّر فريقُ التطبيق تفعيلَ App Attest (iOS) أو Play Integrity (Android) يلزم:

- iOS: معرّفُ الفريق + معرّفُ الحزمة (`bundle id`) + مفتاحُ App Attest — للتحقّق من شهادة الجهاز خادميّاً.
- Android: مشروعُ Google Cloud + رمزُ Play Integrity API — للتحقّق من رمز الاختبار خادميّاً.

كلُّها **إعدادٌ خارجيٌّ يُوثَّق ولا يُختلَق**، وأيُّ تحقّقٍ منها يجب أن يكون **خادميّاً** (لا يُصدّق
الادّعاءُ من العميل). راجع `x-not-configured` في مواصفة الجوال الحيّة (`GET /api/mobile/v1/openapi.json`).

---

## 8) التغطيةُ الاختباريّة (فاشلٌ أوّلاً · المحرّكان)

- `tests/Feature/Mobile/MobileAppConfigTest.php` (١٠): `app-config` عامّةٌ بلا أسرار · بوّابةٌ فارغةٌ
  لا تحجب · صدقُ الصيانة/القفل · `update_required` محسوبٌ لإصدار العميل من الترويسات · `health` بالأسبقيّة.
- `tests/Feature/Mobile/MobileOpenApiTest.php` (١٢): الرابطُ العميق القانونيّ في `x-deep-link` بـ`action: "show"`.
- وثيقتا الربط ومعرّفاتُها الغائبة موثَّقتان في `deep-links/README.md` والقالبَين المجاورَين.
