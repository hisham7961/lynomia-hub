# بوّابةُ القبول والتقريرُ الختاميّ (10)

> **مسارُ التصحيح §21/§22.** تقسيةٌ وتصحيحٌ لتنفيذٍ **قائم** (Work OS · الأطوار J/K/L)
> — لا إعادةُ بناء، ولا نظامٌ ثانٍ. كلُّ بندٍ مقرونٌ **بدليلٍ** (اختبارٌ/ملف).

## أ) بوّابةُ القبول (§21) — ٣٣ بنداً

| # | البند | الدليل |
|---|---|---|
| 1 | `/api/mobile/v1/*` بلا مساسٍ ولا انحدار (§0) | الحزمتان خضراوان؛ لا تعديلَ في `routes/api.php` mobile ولا `Mobile*` |
| 2 | أربعُ هويّاتٍ مستقلّة (ويب/جوال/API/جهاز) (§0) | `EndpointSignature` منفصلٌ عن `MobileAuth`؛ `docs/endpoint/00` |
| 3 | لا تحويلَ جهازٍ↔جلسةِ جوال (§0) | التسجيلُ يولّد `EndpointDevice` لا `MobileSession` |
| 4 | الأنظمةُ المدعومة Windows/macOS من مصدرٍ واحد (§1) | `App\Support\Endpoint::SUPPORTED`؛ `EndpointOsSupportTest` |
| 5 | صفوفُ linux القديمة تبقى، والتسجيلُ الجديدُ يُحجَب (§1) | `STORED` تبقي linux؛ enroll يرفضه ٤٢٢؛ `EndpointOsSupportTest` |
| 6 | التسجيلُ مربوطٌ بأصلٍ مؤهّلٍ مملوكٍ للشركة (§2) | `EnrollmentToken::mint(Asset)`؛ `EndpointAssetEnrollmentTest` |
| 7 | الشركة/الموظف/المحطّة/الأصل خادميّةٌ لا من الحمولة (§2) | `enroll` يسند من الرمز؛ `EndpointAssetEnrollmentTest` |
| 8 | الشخصيّ/BYOD/المتقاعد/المفقود/التالف مرفوضٌ fail-closed (§2) | `Asset::endpointIneligibleReason`؛ `EndpointAssetEnrollmentTest` |
| 9 | عبرَ الشركات ٤٠٤ لا تسريبَ وجود (§2) | `hub_company_ids` في كل قارئ؛ `WorkOsEndpointCentreTest` |
| 10 | لا تسجيلَ نشطٍ مزدوج + دورةُ استبدال (§2) | `activeEndpoint()` ٤٠٩؛ `EndpointAssetEnrollmentTest` |
| 11 | حالةُ الأصل تقود حالةَ الجهاز (§3) | `Endpoint::onAssetStatusChanged`؛ `EndpointLifecycleTest` |
| 12 | هويّةُ P-256؛ المفتاحُ الخاصُّ لا يغادر الجهاز (§4) | `identity` 0600؛ `Es256::isP256PublicKey`؛ `EndpointHardeningTest` |
| 13 | الخادمُ يرفض أيَّ مادّةٍ خاصّة (§4) | حارسُ «PRIVATE KEY» في `enroll`؛ `EndpointHardeningTest` |
| 14 | سلسلةُ ثقةِ الإصدار الكاملة (§5) | أعمدةُ `EndpointRelease` + البيان؛ `EndpointReleaseTrustChainTest` |
| 15 | حالاتُ الإصدار draft/published/withdrawn (§6) | `STATES` + softDelete؛ `EndpointReleaseTrustChainTest` |
| 16 | توقيعٌ صادق: Authenticode/DevID/notarization = NOT_CONFIGURED (§6) | `signing_status`/`notarization_status`؛ `signing-status.sh`؛ CI |
| 17 | هندسةُ مثبِّتَي MSI/PKG (§6) | `agent/packaging/`؛ `docs/endpoint/05` |
| 18 | ترقيةٌ آمنة: لا تنازل + جسر + تحقّقُ sha256 قبل التبديل (§7) | `update.Precheck` + `update.Apply`؛ `precheck_test.go` |
| 19 | طرحٌ مرحليّ (all/company/canary) غيرُ مُلزَمٍ افتراضاً (§7) | `targetsDevice`؛ `EndpointReleaseTrustChainTest` |
| 20 | USB رصدٌ مقابل فرض — لا «محجوب» زائف (§8) | `MdmService::effectiveUsbMode`؛ `EndpointMdmUsbEnforcementTest` |
| 21 | مزوّدُ MDM (Null/Intune/Jamf) NOT_CONFIGURED/OBSERVE_ONLY (§8) | `app/Support/Mdm/`؛ `EndpointMdmUsbEnforcementTest` |
| 22 | طبقةُ تكامل MDM config/health/mapping/sync عبر VaultSecret (§9) | `EndpointMdmConnection`؛ `EndpointMdmUsbEnforcementTest` |
| 23 | وضعيّةُ Wi-Fi: اتصالُ الجهاز فقط، لا مسحَ شبكة (§10) | `network.WifiPosture`؛ `guardrails_test`؛ `EndpointPostureWifiTest` |
| 24 | قائمةُ SSID معتمدة؛ مُنعُ القراءة ليس امتثالاً (§10) | `PostureContract::evaluateWifi`؛ `EndpointPostureWifiTest` |
| 25 | عقدُ حالةِ الوضعيّة (٦ حالات) (§11) | `PostureContract`؛ `security` (Go)؛ `EndpointPostureWifiTest` |
| 26 | سلامةُ الأوامر: قائمةٌ مغلقة، لا تنفيذَ عامّ (§12) | `EndpointCommand::TYPES`؛ `EndpointHardeningTest` |
| 27 | حدُّ الخصوصيّة: لا مراقبةَ + حرّاسُ مصدر (§13) | `EndpointPrivacy::FORBIDDEN`؛ `guardrails_test`؛ `EndpointHardeningTest` |
| 28 | مركزٌ يعرض تقريراً صادقاً (§14) | `EndpointCentreController`؛ `WorkOsEndpointCentreTest` |
| 29 | 360 للأصل/الموظف/المحطّة؛ العميلُ محجوب (§15) | `partials/endpoint_devices`؛ `EndpointRelation360Test` |
| 30 | Mobile API خارج النطاق / لا انحدار (§16) | لا تعديلَ في مسارات mobile؛ الحزمتان خضراوان |
| 31 | هجراتٌ إضافيّةٌ عكوسةٌ غيرُ هادمة؛ لا افتراضَ كاذبٍ لحالةٍ تاريخية (§17) | ٤ هجراتٍ محروسةٍ عكوسة؛ `DatabaseSafetyTest` |
| 32 | المحرّكان + Go + CI خضر (§18) | phpunit×٢ + `go test`/gofmt/vet؛ `.github/workflows/ci.yml` |
| 33 | وثائقُ 00–10 + حَوكمةُ النسخ (§19/§20) | `docs/endpoint/00–10`؛ `VERSION`+`README` لكل دفعة |

**النتيجة: ٣٣/٣٣ مستوفاة** (مع الحدود الصادقة المذكورة في القسم ب).

## ب) التقريرُ الختاميّ (§22) — ٢٨ بنداً

### ما نُفِّذ (تصحيحٌ وتقسية)

1. **مصدرٌ واحدٌ للأنظمة** (`Endpoint::SUPPORTED`) — Windows/macOS؛ linux قديمٌ محفوظٌ ومحجوبُ التسجيل.
2. **تسجيلٌ مربوطٌ بأصلٍ مملوك** — أهليّةٌ fail-closed، إسنادٌ خادميّ، لا تسجيلَ مزدوج.
3. **اتساقُ دورةِ الحياة** — حالةُ الأصلِ تعلّق/تُنبّه عبر `Custody` الوحيدة.
4. **سلسلةُ ثقةِ الإصدار** — بناء/توقيع/توثيق/أدنى نسخ/رقعةُ طرح، والبيانُ يحملها.
5. **حالاتُ الإصدار** — مسوَّدة/منشور/مسحوب (حذفٌ ناعمٌ فعليّ).
6. **طرحٌ مرحليّ** — all/company/canary حتميّة، غيرُ مُلزَمٍ افتراضاً.
7. **ترقيةٌ آمنة** — `Precheck` (لا تنازل/جسر/لا عملَ على المطابق) فوقَ `Apply` (تحقّقُ التجزئة).
8. **طبقةُ MDM** — مزوّدٌ Null/Intune/Jamf + وصلةٌ عبر VaultSecret + وضعٌ فعليٌّ صادق.
9. **USB رصدٌ مقابل فرض** — لا «محجوب» يُزعَم؛ `MdmActionResult` بلا حالةِ blocked.
10. **وضعيّةُ Wi-Fi الشركة** — اتصالُ الجهاز فقط، قائمةُ SSID معتمدة، لا مسحَ شبكة.
11. **عقدُ الوضعيّة الموسَّع** — ٦ حالات؛ مُنعُ القراءة ليس امتثالاً.
12. **مثبِّتات MSI/PKG** — سقالةٌ + توقيعٌ صادقٌ (NOT_CONFIGURED) في CI.
13. **تقسيةُ الهويّة** — P-256 حصراً + رفضُ المادة الخاصّة.
14. **سلامةُ الأوامر** — قائمةٌ مغلقةٌ خمسيّة ضدّ التنفيذ العامّ.
15. **حدُّ الخصوصيّة** — قائمةُ حظرٍ غنيّةٌ مفروضةٌ بالعمق + حرّاسُ مصدر.
16. **360 للأصل/الموظف/المحطّة** — منطَّقٌ ومنضبطٌ ومحجوبٌ عن العميل.
17. **٦ فئاتِ اختبارٍ خادميّة جديدة** + توسيعُ اختباراتِ Go — إثباتٌ لا ادّعاء.
18. **٤ هجراتٍ إضافيّةٌ عكوسة** — لا حذفَ، لا افتراضَ كاذبٍ لحالةٍ تاريخية.
19. **حَوكمةُ النسخ** — رفعُ `VERSION`+`README`+OpenAPI لكل دفعة، خطّافُ pre-push.
20. **الحزمتان + الوكيل خضر** على كل دفعة.

### حدودٌ صادقة (NOT_CONFIGURED / DEFERRED — لا تزييف)

21. **توقيعُ المثبِّتات** — لا Authenticode/Developer ID/notarization مُهيّأة؛ كلٌّ NOT_CONFIGURED. سلطةُ الثقة SHA-256.
22. **فرضُ USB الفعليّ** — يتطلب مزوّدَ MDM قادراً؛ الجسرُ الحيُّ (Graph/Jamf API) مؤجَّلٌ خلف `canEnforce()`.
23. **بناءُ MSI/PKG الفعليّ** — يجري على مضيفَي Windows/macOS (WiX/pkgbuild)؛ السقالةُ تمرّ بلطفٍ على ubuntu.
24. **تمييزُ permission-denied في Wi-Fi** — متاحٌ في عقد الخادم؛ واجهةُ المنصّة الحاليّة تُبلّغ unavailable حين تعجز.

### مبادئُ غيرُ قابلةٍ للتفاوض حُفظت

25. **المفتاحُ الخاصُّ لا يغادر الجهاز** — وُلد محليّاً 0600، والخادمُ يرفض أيَّ مادّةٍ خاصّة.
26. **لا تنفيذَ عامّ** — لا shell في الخادم ولا الوكيل (حارسُ المصدر).
27. **حدُّ الخصوصيّة يُقسَّى فقط** — لا keylog/screenshot/clipboard/webcam/mic/browser/file-content/LAN-scan/تتبّعَ موقع.
28. **ليست برمجيّةَ تجسّسٍ على الموظف** — الصدقُ عقدٌ: ما لا يُفعَل يُعرَض NOT_CONFIGURED/unsupported، لا وضعيّةٍ مختلَقة ولا «محجوب» زائف ولا «موقَّع» زائف.
