# 00 — جردُ المكوّنات القائمة (Mobile Platform Center · الطور 1)

> **العقد:** مركزُ منصّة الجوال **يستهلك** الأنظمةَ القائمةَ ولا يبني بديلاً. هذا الجرد
> يربط كلَّ قسمٍ في المركز بمصدرِه الحيّ. **تكرارُ الخلفيّة المطلوب = 0.**

## خريطةُ القسم ← المصدر

| قسمُ المركز | المصدرُ القائم (يُستهلَك، لا يُكرَّر) |
|---|---|
| نظرة عامّة / بطاقات | تجميعٌ فوق المصادر أدناه — لا مخزنَ جديد |
| الجلسات | `mobile_sessions` (`App\Models\MobileSession`) + `App\Support\MobileSessionService` (revokeSession/revokeAllForUser) |
| الأجهزة/التنصيبات | `mobile_installations` (`App\Models\MobileInstallation`) |
| منحُ التصعيد | `mobile_stepup_grants` (`App\Models\MobileStepupGrant`) |
| الدفع (مزوّد/حالة) | `App\Support\PushService::status()` + `Push\{Fcm,Null}PushProvider` |
| رموز الدفع | `push_tokens` (`App\Models\PushToken`) |
| سجلّ تسليم الدفع | `push_deliveries` (`App\Models\PushDelivery`) |
| اختبارُ الدفع | `PushService::provider()->send(...)` (نفسُ مسار التفريع، بلا التفاف) |
| إصداراتُ التطبيق / بوّابة التحديث | إعدادات `mobile.min_version_*`/`latest_version_*`/`force_update`/`store_url_*` (`config/hub_settings.php` + `config/hub.php` mobile.version_gate) |
| معاينةُ app-config | `App\Http\Controllers\Api\MobileAuthController@appConfig` (يُستدعى، لا يُنسَخ) |
| الروابط العميقة / well-known | `App\Http\Controllers\Web\MobileWellKnownController` + `config('hub.mobile.deep_links')` + `setting('mobile.dl_*')` |
| مخطّطُ الرابط العميق | `App\Support\NotificationLink` (`{module,id,action}`) |
| Mobile API / المسارات / OpenAPI | `App\Support\MobileOpenApi::{spec,capabilities,mobileRoutes}()` + `GET /api/mobile/v1/openapi.json` |
| سجلُّ القدرات | `MobileOpenApi::capabilities()` + `docs/mobile-readiness/mobile-capabilities.json` (لا سجلَّ ثانٍ) |
| المزامنة/دون اتصال | `config('hub.mobile_sync')` + `hub_sync_class()` + `MobileSyncController` |
| الملفات | `MobileFileController` + `AttachmentService` |
| الماسح | `MobileCommController`/`Identity::resolve` (المُحلِّل القائم) |
| التتبّع/الموقع | `track_sessions` (`App\Models\TrackSession`) + `MobileWorkController` تتبّع |
| الأحداثُ الأمنيّة | `App\Support\SecurityEvents` (+ `SecurityRadar`) — لا مخزنَ أحداثٍ جديد |
| التدقيق | `hub_audit()` / `App\Models\AuditEntry` (source=mobile) — لا سجلَّ تدقيقٍ موازٍ |
| الصحّة | نمطُ `App\Support\Health` (status/label/why/tone + worst-wins) |
| الوثائق | `docs/mobile-readiness/*` + `MobileDocsController` |

## ما يُنشئه المركزُ (طبقةُ عرضٍ فقط — لا خلفيّةَ جديدة)

- `App\Support\MobilePlatform` — **خدمةُ قراءةٍ مُجمِّعة** تستدعي المصادرَ أعلاه (لا تخزّن ولا تكرّر).
- `App\Http\Controllers\Web\MobilePlatformController` — مركزٌ واحدٌ بتبويبات (`?tab=`)، يقرأ الخدمةَ ويكتب عبر خدماتِ القائمةِ نفسِها (`MobileSessionService::revokeSession`، `PushService`، `Settings`).
- مسارات `admin/mobile-platform` باسمِ `mobileplatform.*` (اسمٌ متمايزٌ عن بادئةِ `mobile` في دلوِ IA/الـAPI).
- رايةُ دورٍ جديدةٌ `mobile` (مصدرُها `RoleController::FLAGS` القائم — لا نظامَ RBAC ثانٍ).
- مدخلٌ في `hub_admin_links` (كتالوجُ الإدارة) + وجهةٌ في مجال IA «الإدارة والنظام» ⇒ يشارك آليّاً في الشريط/البحث/خريطة النظام/الفتات.

## قاعدةُ الأمان

لا يُعرَض قطّ: `access_hash`/`refresh_hash`/`prev_refresh_hash` (جلسات)، `token` (رموز الدفع)،
`mobile.push_fcm_access_token` (سرّ)، أيُّ مفتاحٍ خاصّ/توقيع/سرُّ خزنة — يُعرَض **الحضورُ لا القيمة**.
