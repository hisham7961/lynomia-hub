# الإشعاراتُ والدفع — Notifications / Push (Mobile Readiness · الطور E · §109)

> **نقطةُ اختناقٍ واحدة:** كلُّ إشعارٍ يمرّ بنموذج `HubNotification`، فالدفعُ يتفرّع من هناك
> على حدث `created` **بعد الالتزام** — يغطّي كلَّ مواضع الإنشاء بلا لمسها، وعطلُ المزوّد لا
> يُفقِد الإشعارَ الداخليّ أبداً. الحمولةُ **آمنةٌ بالبناء** (عنوانٌ عامٌّ + رابطٌ عميق + عددُ
> غير المقروء — لا نصَّ حسّاس). بلا اعتماداتِ FCM يقول المزوّدُ الصفريُّ الحقيقةَ (`NOT_CONFIGURED`)
> ولا يُزيّف نجاحاً. كلُّ ما يلي **مبنيٌّ ومُختبَرٌ على المحرّكَين**.

---

## 1) نقاطُ الإشعارات (هويّةٌ خاصّةٌ صارمة)

كلُّها مُصادَقة خلف `['throttle:api','mobile.session','mobile.context']` (`routes/api.php:127`)،
والمعالجُ `App\Http\Controllers\Api\MobileCommController`. القراءةُ **منطَّقةٌ بـ`user_id = auth()->id()`
وحدَه** — لا يبلغ المُنادي إشعارَ أحدٍ سواه أبداً.

| المسار | التسجيل / الاسم | المعالج | الغرض |
|---|---|---|---|
| `GET notifications` | `routes/api.php:186` · `mobile.notifications.index` | `notifications` (`MobileCommController.php:63`) | قائمةُ إشعاراتي بمؤشّرٍ على `(created_at DESC, id DESC)`؛ `?unread=1` يقصر على غير المقروء؛ `?per=` (افتراض ٢٥، سقف ٥٠) |
| `GET notifications/unread-count` | `routes/api.php:187` · `mobile.notifications.unread` | `unreadCount` (`:98`) | عدُّ غيرِ المقروءِ لي وحدي |
| `POST notifications/{id}/read` | `routes/api.php:190` · `mobile.notifications.read` | `markRead` (`:110`) | تعليمُ إشعاري مقروءاً + `target` القانونيّ؛ إشعارٌ ليس لي ⇒ `RESOURCE_NOT_FOUND` 404 |
| `POST notifications/read-all` | `routes/api.php:188` · `mobile.notifications.read_all` | `markAllRead` (`:127`) | تعليمُ كلِّ إشعاراتي مقروءةً |
| `GET notifications/{id}/target` | `routes/api.php:189` · `mobile.notifications.target` | `notificationTarget` (`:142`) | الوجهةُ القانونيّة `{module,id,action}` دون ختمِ القراءة |

- **المؤشّرُ:** keyset معتِمٌ على `(created_at, id)` (`MobileCommController.php:516-530`) — لا
  `updated_at` لأنّ `HubNotification::$timestamps = false` وحذفُه صلبٌ (INVENTORY §9). غيرُ مُوقَّعٍ
  لأنّه لا يحمل تخويلاً (الاستعلامُ منطَّقٌ بـ`user_id` أصلاً): مؤشّرٌ مُختلَقٌ يزيح النافذةَ داخلَ
  إشعاراتي وحدَها لا غير.
- كلُّ عنصرٍ يحمل وجهتَه القانونيّة `{module,id,action}` من `NotificationLink::target($n)`
  (`app/Support/NotificationLink.php:28`) — لا رابطَ ويبٍ صلبٌ ولا اسمَ شاشة (راجع
  `08-versioning-deep-links.md`).
- الحذفُ الصلبُ للنموذج يعني لا شواهدَ حذف؛ يُزامَن العميلُ بالمؤشّر ويُسقِط من خبيئته ما لم يعد يرد.

---

## 2) تفريعُ الدفع — من نقطة الاختناق الواحدة، بعد الالتزام

الفان-أوت مُوصَّلٌ على حدث `created` في نموذج `HubNotification` (`app/Models/HubNotification.php:77-79`):

```php
static::created(function (self $n) {
    \App\Support\PushService::scheduleFanout($n);
});
```

- **على حدث `created` لا على `hub_notify`** (Critic F6): يغطّي **كلَّ** مواضع الإنشاء المباشرة
  الستّة (`AlertEngine`/`FlowRunner`/`LoginSentry`/`EsignController`/`HubDigest`/`HubAutomation`)
  التي تتجاوز `hub_notify` — بلا لمسِ أيٍّ منها.
- **بعد الالتزام لا سطريّاً:** `scheduleFanout` يجدوله عبر `DB::afterCommit`
  (`app/Support/PushService.php:126-135`)، فاستثناءُ مزوّدٍ لا يقع **داخلَ** المعاملة المحيطة
  (اعتماد/استيعابُ مقاييس/مسار) فيُرجِعَ الإشعارَ الملتزَم (spec §Push: «failed push must not
  lose internal notification»). واستثناءُ التفريعِ نفسِه ملتقَطٌ (`report($e)`) — الإشعارُ الداخليُّ نجا.
- **الأنواعُ المكتومة لا تُدفَع بلا حارسٍ زائد:** hook الكتمِ يُلغي `creating` بـ`return false`
  (`HubNotification.php:59`)، فلا يقع `created` لها أصلاً — فلا تبلغ التفريعَ (Critic F6).

`FlowRunner::fire → HubEvents::dispatch` تبقى السكّةَ الدلاليّة للأحداث؛ الدفعُ **لا** يُضاف
مشتركاً ثالثاً هناك — بل عند طبقةِ الإشعار، كي يبقى دفعُ الجوال ومكتبُ إشعارِ الويب متطابقَين.

---

## 3) خدمةُ الدفع والمزوّدون

`App\Support\PushService` (`app/Support/PushService.php`) هي التجريد؛ خلفَها واجهةٌ ومنفّذان:

| المكوّن | الملف | الدور |
|---|---|---|
| `PushProvider` (واجهة) | `app/Support/Push/PushProvider.php` | `name()` · `isConfigured()` · `send($token,$platform,$payload)` |
| `NullPushProvider` | `app/Support/Push/NullPushProvider.php` | الافتراضيُّ بلا اعتمادات: كلُّ محاولةٍ `not_configured` — **لا نجاحٌ مُزيَّف** |
| `FcmPushProvider` | `app/Support/Push/FcmPushProvider.php` | نداءُ FCM الحقيقيّ **محجوبٌ خلف وجود الاعتماد**؛ `delivered` عند 2xx فقط؛ تصنيفٌ تقنيٌّ للفشل |
| `PushSendResult` | `app/Support/Push/PushSendResult.php` | قيمةٌ محايدة: `delivered`/`failed`/`not_configured`/`skipped` — لا رسالةَ مزوّدٍ خام |

**اختيارُ المزوّد** (`PushService::provider()` · `:75`): مزوّدٌ محقونٌ عبر الحاوية (اختبار/إدارة)
أوّلاً، ثم سائقُ الإعدادات (`setting('mobile.push_driver')` فوق `config('hub.mobile.push.driver')`)
إن كان `fcm` **وكانت الاعتماداتُ حاضرة** (`isConfigured()`)، وإلّا `NullPushProvider`. النداءُ
الحقيقيُّ محجوبٌ خلف الاعتماد — بلا رمزِ وصولٍ صالحٍ يقول `send` الحقيقةَ.

**التفريعُ الفعليّ** (`PushService::fanout()` · `:145`): لكلِّ رمزِ دفعٍ حيٍّ لصاحب الإشعار
(`PushToken::active()->where('user_id',…)` بترتيبٍ حتميّ) يبني الحمولةَ **مرّةً**، يحاول التسليم،
ويسجّل صفَّ `push_deliveries` لكلِّ محاولة. لا رموز ⇒ لا محاولة ⇒ لا سجلّ. ورمزٌ ردّ المزوّدُ أنّه
`unregistered` يُبطَل تلقائيّاً (`revoked_at`) كي لا نعاود إليه (`:176-178`).

---

## 4) الجدولان — `push_tokens` و`push_deliveries`

**`push_tokens`** (`database/migrations/2026_09_19_000001_e_create_push_tokens.php` · نموذج
`app/Models/PushToken.php`):

| العمود | ملاحظة |
|---|---|
| `id` (uuid) · `installation_id` (uuid) · `user_id` (uuid) | الرمزُ منسوبٌ إلى (المستخدم + تنصيبِ جلسته) |
| `platform` (10) · `provider` (20, null) | `ios\|android` · `fcm\|apns` — allowlist في التطبيق لا DB enum (`PushToken::PLATFORMS/PROVIDERS`) |
| `token` (512) | **رمزُ توجيهِ جهازٍ لا سرَّ خادم** (spec §Push «no provider secrets») — لا يُسجَّل قط |
| `last_confirmed_at` · `revoked_at` | إبطالٌ ناعمٌ يوقف التسليم (الخروج/الإلغاء) |
| قيدٌ فريد `(provider, token)` | إزالةُ التكرار — الحاجزُ الأخير تحت التسابق (F7) |
| فهارس `(user_id, revoked_at)` · `installation_id` | «رموزي الحيّة» · إبطالُ رموزِ تنصيبٍ عند الخروج |

**`push_deliveries`** (`database/migrations/2026_09_19_000002_e_create_push_deliveries.php` ·
نموذج `app/Models/PushDelivery.php`): صفٌّ لكلِّ محاولة — `notification_id`، `installation_id`
(null لمحاولةِ `not_configured`/`skipped` بلا رمز)، `provider`، `status` (من
`PushDelivery::STATUSES = queued|attempted|delivered|failed|not_configured|skipped`)،
`error_category` (تصنيفٌ تقنيّ: `unregistered|invalid_token|provider_error|rate_limited|exception`)،
`attempts`، `queued_at`، `attempted_at`. **لا مفتاحَ مزوّدٍ ولا نصَّ إشعارٍ هنا** — الحمولةُ
الحسّاسةُ لا تُخزَّن في سجلّ التسليم.

---

## 5) خصوصيّةُ الدفع — الحمولةُ آمنةٌ بالبناء

`PushService::payloadFor($n)` (`app/Support/PushService.php:202`) يبني:

```json
{
  "title": "طلبُ موافقة",
  "body": "افتح التطبيق للاطّلاع على التفاصيل",
  "category": "approval",
  "data": {
    "notification_id": "…",
    "category": "approval",
    "unread": "7",
    "module": "approvals", "id": "…", "action": "show"
  }
}
```

- **العنوانُ عامٌّ حسب النوع** من خريطة `KIND_TITLES` (`:48-64`) — **لا يُردَّد نصُّ الإشعار الخام**
  (قد يحمل سرّاً/رقماً ماليّاً/جسمَ DM أو تعليق). الأنواعُ ذاتُ اللاحقة (`rule:<uuid>`) تُختزَل
  إلى جذرها قبل البحث (`baseKind` · `:297`).
- **الجسمُ ثابتٌ عامّ** (`GENERIC_BODY` · `:41`) — «افتح التطبيق للاطّلاع على التفاصيل».
- **الوجهةُ القانونيّة** `{module,id,action}` من `NotificationLink::target` — لا رابطَ ويبٍ ولا اسمَ شاشة.
- **عددُ غير المقروء** لصاحب الإشعار وحدَه (`unreadFor` · `:303`).
- على FCM تُرسَل `notification.{title,body}` + `data` (نصوصٌ) فقط (`FcmPushProvider.php:53-63`) — لا سرَّ في الحمولة.

---

## 6) التسجيلُ وإعادةُ التوجيه عبر المستخدمين (Critic F7)

| المسار | التسجيل / الاسم | المعالج |
|---|---|---|
| `POST push/register` | `routes/api.php:203` · `mobile.push.register` | `MobilePushController@register` (`app/Http/Controllers/Api/MobilePushController.php:56`) |
| `POST push/unregister` | `routes/api.php:204` · `mobile.push.unregister` | `MobilePushController@unregister` (`:120`) |

- **التنصيبُ من الجلسة لا العميل:** `$session->installation_id` الذي أرسته `MobileSessionAuth`
  (`MobilePushController.php:78`) — العميلُ لا يختار تنصيباً ولا مستخدماً.
- **إزالةُ التكرار مع إعادة التوجيه** (`PushService::register()` · `PushService.php:239-276`):
  عند تسجيلِ رمزٍ كان لمالكٍ آخر (جهازٌ أُعيد توفيرُه/سُلِّم، أو أعادت المنصّةُ إصدارَه) يُبطَل
  ربطُ المالكِ السابق (`revoked_at` · `:247-251`) ثم يُعاد توجيهُ صفِّ `(provider, token)` للحاليّ —
  **وإلّا استمرّ الأوّلُ يتلقّى إشعاراتِ الثاني** (تسريبُ دفعٍ عابرٌ للمستخدمين). القيدُ الفريدُ
  `(provider, token)` هو الحاجزُ الأخير تحت التسابق.
- **الإبطالُ لي وحدي (لا IDOR):** `PushService::revoke()` مقصورٌ على `user_id = auth()->id()`
  (`PushService.php:282-292`) — يعيد ٠ حين لا رمزَ لي بهذه القيمة (لا كشفَ وجودِ رمزِ سواي).
- **الخروجُ/الخروجُ الشامل/إلغاءُ الجلسة يُبطلان رموزَ الدفع:** `MobileAuthController::logout`
  (`revokePushTokensForInstallation`) و`logoutAll` — فيتوقّف التسليمُ فوراً.
- الردُّ لا يعيد نصَّ الرمزِ قطّ (`tokenShape` · `MobilePushController.php:245`) — معرّفُ الصفِّ وبياناتُه فقط.

---

## 7) إدارةُ الدفع — صادقةٌ بلا سرّ (للمالك وحدَه)

| المسار | التسجيل / الاسم | المعالج |
|---|---|---|
| `GET push/admin/status` | `routes/api.php:210` · `mobile.push.admin.status` | `adminStatus` (`MobilePushController.php:155`) |
| `POST push/admin/test` | `routes/api.php:211` · `mobile.push.admin.test` | `adminTest` (`:182`) |

كلاهما `hub_is_owner()` (وإلّا `FORBIDDEN` 403). الحالةُ (`PushService::status()` · `:94`) تعيد
`driver` (`null|fcm`)، `configured` (صدقُ NOT_CONFIGURED)، `requested`، و**حضورَ** المشروعِ ورمزِ
الوصول (`has_project_id`/`has_access_token`) — **حضورٌ لا قيمة**، لا يُعرَض مفتاحٌ خاصٌّ أبداً
(spec §Push «never show private key»). والاختبارُ يُجرَّب على رموزِ المالكِ نفسِه بحمولةٍ عامّةٍ
آمنة، والحكمُ الإجماليُّ صادق: بلا اعتمادٍ ⇒ `not_configured` صراحةً (لا نجاحٌ مُزيَّف).

---

## 8) NOT_CONFIGURED — الإعدادُ الخارجيُّ لـ FCM/APNs (يُوثَّق لا يُختلَق)

الدفعُ في الشجرة **مكتملُ المسار لكن غيرُ مُهيّأٍ افتراضاً**: السائقُ فارغٌ (`config/hub.php:9362-9366`)
⇒ `NullPushProvider` ⇒ كلُّ إشعارٍ يُسجَّل `not_configured` (صدقٌ لا تزييف). لتشغيلِ التسليم الحقيقيّ
يلزم **إعدادٌ خارجيٌّ** يوفّره فريقُ التطبيق — يُقرأ الحيُّ عبر `setting()` فوق افتراضِ `config`:

| المفتاح الحيّ | الغرض | الافتراض |
|---|---|---|
| `setting('mobile.push_driver')` | `''` (صفريّ) أو `'fcm'` | `config('hub.mobile.push.driver')` = `''` |
| `setting('mobile.push_fcm_project_id')` | معرّفُ مشروع Firebase | `config('hub.mobile.push.fcm.project_id')` = `''` |
| `setting('mobile.push_fcm_access_token')` | رمزُ وصولِ حساب الخدمة (OAuth2) لـ FCM v1 | — (حضورٌ لا قيمة؛ لا يُسجَّل قط) |

**خطواتُ التهيئة (FCM):** ١) أنشئ مشروعَ Firebase واحصل على `project_id`. ٢) أنشئ حسابَ خدمة
بصلاحية إرسال الرسائل واستخرج رمزَ وصولٍ (OAuth2) لنقطة `fcm.googleapis.com/v1/projects/<id>/messages:send`.
٣) اضبط `mobile.push_driver = fcm` و`mobile.push_fcm_project_id` و`mobile.push_fcm_access_token` عبر
الإعدادات. ٤) تحقّق عبر `GET push/admin/status` أنّ `configured = true` ثم `POST push/admin/test`.

**APNs (iOS):** المزوّدُ الحاليُّ FCM (يمرّر iOS عبر FCM ذاته). تسجيلُ الرمز يقبل `platform=ios`
و`provider=apns` بنيويّاً في `push_tokens`، لكن **لا مزوّدَ APNs مباشرٌ مبنيٌّ** — نداءُ APNs
المباشر (`.p8`/team id/key id/bundle id) **NOT_CONFIGURED**: يلزم منفّذُ `PushProvider` جديدٌ
(نظيرُ `FcmPushProvider`) قبل الاعتماد عليه. لا تُختلَق قدرةٌ لم تُبنَ.

**الاعتماداتُ لا تهبط قاعدةً ولا نسخةً:** لا مفتاحَ مزوّدٍ في `push_tokens`/`push_deliveries`،
ولا يُسجَّل رمزٌ ولا اعتماد (spec §Security).

---

## 9) التغطيةُ الاختباريّة (فاشلٌ أوّلاً · المحرّكان)

- `tests/Feature/Mobile/MobileNotificationsTest.php` (١٣): قائمة/مؤشّر/عدُّ غيرِ المقروء/تعليمُ
  القراءة/قراءةُ الكلّ — **هويّةٌ خاصّةٌ فقط** (لا يقرأ المُنادي إشعارَ غيره)، والوجهةُ القانونيّة.
- `tests/Feature/Mobile/MobilePushFanoutTest.php` (١١): تفريعٌ يسجّل محاولةً · **عطلُ المزوّدِ يبقي
  الإشعارَ الداخليّ** · الحسّاسُ لا يُكشَف في الحمولة · بلا رموزٍ لا سجلّ · صدقُ NOT_CONFIGURED.
- `tests/Feature/Mobile/MobilePushRegisterTest.php` (١٢): تسجيل/تأكيد/**dedupe عابرُ المستخدمين
  (F7)**/إبطالٌ لي وحدي/التنصيبُ من الجلسة.
- `tests/Feature/Mobile/MobilePushLogoutRevokeTest.php` (٢): الخروج/الخروجُ الشامل يعطّلان التسليم.
