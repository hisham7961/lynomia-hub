# الروابط العميقة والروابط العالميّة — سقالةٌ مُهيّأة (Mobile Readiness · الطور H · H.3)

> **صادقٌ لا مُختلَق:** كلُّ معرّفٍ خارجيٍّ هنا **NOT_CONFIGURED** حتى يُصدره فريقُ التطبيق.
> الخادمُ يخدم وثيقتَي الربط **صحيحتَي البنية تربطان صفرَ تطبيق** حتى تُضبط المعرّفاتُ — فلا
> رابطٌ عالميٌّ يعمل قبل ضبطها، ولا نختلق ربطاً.

## 1) الرابطُ العميقُ القانونيّ — `{module, id, action}`

المصدرُ الواحدُ للوجهة هو `App\Support\NotificationLink::target()` — **لا اسمَ شاشةِ جوالٍ صلب
ولا رابطَ ويبٍ صلب**. الشكل:

```json
{ "module": "tickets", "id": "9b1e…", "action": "show" }
```

`target = null` ⇒ لا وجهةَ سجلٍّ (يعود التطبيقُ لقائمة الإشعارات) — نظيرُ الويب تماماً.

**تصدره** هذه النقاط (كلُّها تحت `/api/mobile/v1`):

| النقطة | الحقل |
|---|---|
| `GET notifications/{id}/target` | `target` |
| `POST notifications/{id}/read` | `target` |
| `GET notifications` | `notifications[].target` |
| `GET search` | كلُّ نتيجةٍ `{module, id}` |
| `GET home` | عناصرُ `my_work/due/attention/recent/projects` `{module, id}` |
| `GET approvals` · `GET approvals/{id}` | `target` للسجل الهدف |

## 2) ترميزُ الوجهةِ في رابطٍ عالميّ

| الوجهة | الرابطُ العالميّ |
|---|---|
| `{module, id, action: "show"}` | `https://<host>/m/{module}/{id}` |
| `{module, id, action: "<other>"}` | `https://<host>/m/{module}/{id}/{action}` |

- `<host>` = `config('hub.mobile.deep_links.host')` أو `config('app.url')` — **NOT_CONFIGURED** حتى يُصدَر النطاقُ المُصرَّح.
- أنماطُ المسار التي يلتقطها التطبيق (`paths`) الافتراضيّة: `/m/*` و`/app/*`.

## 3) وثيقتا الربط (تُخدَمان حيّاً)

الخادمُ يولّدهما من الإعداد عبر `App\Http\Controllers\Web\MobileWellKnownController` — **بلا نشرِ
كودٍ عند الضبط**:

| المسار | المُخرَج غيرَ مضبوطٍ (الآن) | المُخرَج مضبوطاً |
|---|---|---|
| `GET /.well-known/apple-app-site-association` | `applinks.details: []` + `x-lynomia.status = NOT_CONFIGURED` + ترويسة `X-Deep-Links-Status: NOT_CONFIGURED` | `details[].appIDs = ["<team>.<bundle>"]` + `components` لأنماط المسار |
| `GET /.well-known/assetlinks.json` | `[]` + ترويسة `X-Deep-Links-Status: NOT_CONFIGURED` | بيانُ `delegate_permission/common.handle_all_urls` للحزمة وبصماتها |

القالبان `apple-app-site-association.template.json` و`assetlinks.template.json` يوثّقان الشكلَ
والمعرّفاتِ الغائبة (`__NOT_CONFIGURED__*`).

## 4) ما يجب أن يُصدره فريقُ التطبيق (external config)

| المعرّف | الإعداد الحيّ | الافتراض في `config/hub.php` |
|---|---|---|
| Apple Team ID | `setting('mobile.dl_apple_team_id')` | `hub.mobile.deep_links.apple.team_id` (فارغ) |
| iOS Bundle ID | `setting('mobile.dl_apple_bundle_id')` | `hub.mobile.deep_links.apple.bundle_id` (فارغ) |
| Android package name | `setting('mobile.dl_android_package')` | `hub.mobile.deep_links.android.package_name` (فارغ) |
| Android SHA-256 cert fingerprints | `setting('mobile.dl_android_fingerprints')` (مفصولة بفاصلة) | `hub.mobile.deep_links.android.sha256_cert_fingerprints` (`[]`) |
| النطاقُ المُصرَّح | `config('hub.mobile.deep_links.host')` | فارغ ⇒ `config('app.url')` |

**تحذير:** لا يُخوَّل أيُّ شيءٍ على «حالةِ العميل المُدَّعاة» (root/jailbreak، أو ادّعاءُ سلامةِ
جهاز) — تلك للعرض/الرصد لا للتحكّم (spec §Security). App Attest / DeviceCheck / Play Integrity
واجهاتٌ محجوزة **NOT_CONFIGURED** (راجع `x-not-configured` في مواصفة الجوال الحيّة).
