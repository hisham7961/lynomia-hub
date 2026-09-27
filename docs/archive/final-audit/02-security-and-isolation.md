# 02 — الأمنُ والعزل

## سكّةُ الصلاحيّة والتنطيق (واحدة، لا التفافَ عليها)

كلُّ قارئٍ/استعلامٍ يمرّ بـ`hub_scope($q,$module)` (شركة + مشروع + عميل) و`hub_can($u,$module,$op)`
و`hub_field_mode`. المالكُ يختصر؛ وإلّا تُقرأ مصفوفةُ الدور.

## عزلُ الشركات (cross-company)

- `hub_scope` يحقن قيدَ `company_id` على كلِّ استعلامٍ مُنطَّق.
- الاختباراتُ الشاهدة: `CompanyIsolationTest`, `CompanyScopeTest`, `ScopeLeakAuditTest`,
  `AuditScopeLeakTest`, `ChildScopeLeakTest` — كلُّها خضراء على المحرّكين.
- **تسريبٌ عبرَ الشركات = 0.**

## عزلُ العميل (client-internal)

- `PortalGuard` يحصر حساباتِ العميل في `MODULE_ALLOW = ['engagements','projects','fin']`
  و`NAME_ALLOW = ['logout','portal.*','profile.*']`، وإلّا 404 (لا كشفَ وجود).
- `MobilePortalGuard` يعكس ذلك على `/api/mobile/v1`.
- المراكزُ الداخليّة (مثل مركزِ التواصل) تضيف دفاعاً فوق الحارس: `abort_if(hub_is_client($user), 404)`.
- الاختباراتُ الشاهدة: `WorkOsClientPortalTest`, `WorkOsPortalGuardTest`, `ClientOperationsTest`,
  `FieldTrackIsolationTest`, `SearchDmLeakTest` — خضراء.
- **تسريبٌ داخليٌّ للعميل = 0.**

## AUDIT-1 — ثغرةُ `/api/v1` (أُصلِحت · P1)

**كانت:** مسارات `/api/v1` تعمل تحت `ApiAuth` **لا** `PortalGuard`، فحسابُ عميلٍ يحمل رمزَ API
ومُنِح دوراً واسعاً قد يبلغ وحداتٍ داخليّةً عبر `/api/v1/{module}` — العزلُ الصفّيّ يمنع رؤيةَ
بيانات شركةٍ أخرى، لكنّ **وجودَ الوحدةِ نفسِه** كان مكشوفاً (200 بدل 404).

**الإصلاح:** في `V1Controller::resolveApi()`، بعد تحليل الوحدة، حاجزٌ صريح:

```php
if (hub_is_client(auth()->user())
    && ! in_array($module, \App\Http\Middleware\PortalGuard::MODULE_ALLOW, true)) {
    \App\Support\Api::abort(\App\Support\Api::RESOURCE_NOT_FOUND, 404, 'وحدة غير معروفة', [...]);
}
```

رُفِعت رؤيةُ `PortalGuard::MODULE_ALLOW` من `protected` إلى `public const` (مصدرٌ واحدٌ لقائمة
العميل يُعاد استخدامه، لا قائمةٌ ثانيةٌ تتباعد). **اختبارُ الانحدار:**
`FinalAuditHardeningTest::test_client_api_token_is_confined_to_allowed_modules` (servers → 404،
projects → 200) و`test_internal_api_token_still_reaches_modules` (الداخليُّ لا يُكسَر).

## IDOR

- المفتاحُ متعدّدُ الأشكال `(module, record_id)` يمرّ دائماً بـ`hub_read`/`hub_scope`، فلا يُقرأ
  سجلٌّ خارجَ نطاقِ المستخدم بتخمين مُعرِّف.
- المحادثاتُ المباشرة: `threadKey` يُشتَقّ من هُويّةِ الطالبِ لا من مُدخَلِه (`DmService`).
- التنزيلاتُ والمرفقات: قرصٌ خاصّ + تفويضٌ قبل البثّ (لا URL عامّ، لا base64).
- **حاجزاتُ IDOR = 0.**

## XSS / SQLi / SSRF / الأسرار / إعادةُ التوجيه

- **XSS المخزَّن:** Blade يهرّب افتراضاً؛ لا `{!! !!}` على مُدخَلِ مستخدمٍ غيرِ مُطهَّر. **= 0 حاجز.**
- **SQLi:** كلُّ الاستعلاماتِ عبر Query Builder/Eloquent مع ربطٍ معلَّم؛ لا إقحامَ نصّيّ. **= 0.**
- **SSRF:** لا جلبُ URL يتحكّم به المستخدمُ في مسارٍ حسّاس. **= 0 حاجز.**
- **الأسرار:** لا سرَّ في السجلّات ولا في التدقيق (`SecretsNeverInAuditRound7Test`,
  `SettingsSecretSweepTest` خضراء). العيبُ الوحيدُ من صنفِ الأسرار: كلمةُ مرورِ بذرةٍ افتراضيّة
  `ChangeMe!2026` في `CoreSeeder.php` — **P3** (بذرةٌ لا إنتاج، تُغيَّر عند التنصيب).
- **إعادةُ التوجيه المفتوحة:** لا `redirect()->to($request->input(...))` بلا قائمةٍ بيضاء. **= 0.**

## AUDIT-2 — صدقُ دفاعِ IP في طبقةِ التطبيق (أُصلِح · P1)

`security.app_ip_defense` كان يشتقّ حالتَه من مزوّدِ `edge` فيظهر «غيرَ مُهيّأ» زوراً بينما الحجبُ
في طبقةِ التطبيق نشطٌ فعلاً. أُزيل `derive => 'edge'` فصار `ENABLED` صادقاً (ثابت)، وبقي الحجبُ
عند الحافّة `security.edge_blocking` صادقاً `NOT_CONFIGURED` بلا مزوّد.
اختبارُ الانحدار: `test_app_ip_defense_reports_enabled_not_misconfigured`.

## رسمُ العلاقات — تسريبُ الصلاحيّة

`RelationshipProjection::expand()` يُنطِّق كلَّ عقدةٍ بصلاحيّةِ وحدتِها؛ العميلُ لا يبلغ الرسمَ
الداخليّ. **تسريبُ صلاحيّةِ الرسم = 0** (`Entity360GraphTest`, `WorkOsRelationshipExplorerTest`).

## خلاصةُ العزل

| البند | العدد |
|---|---|
| تسريبٌ عبرَ الشركات | 0 |
| تسريبٌ داخليٌّ للعميل | 0 |
| تسريبُ صلاحيّةِ API | 0 (بعد AUDIT-1) |
| حاجزاتُ IDOR | 0 |
| كشفُ الأسرار | 0 (P3 بذرةٌ فقط) |
| XSS مخزَّنٌ حاجز | 0 |
| SSRF حاجز | 0 |
| تسريبُ صلاحيّةِ الرسم | 0 |
