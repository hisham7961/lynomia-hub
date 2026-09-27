# التدقيق الأمنيّ v2.600.0 — البلاغات والأحكام

**المنهج:** ٨ وكلاء مستقلّين (بُعدٌ لكلٍّ) → مُفنِّدٌ عدائيٌّ لكلِّ بلاغٍ يحاول نقضَه
على الشيفرة والحرّاس الفعليّين → فحصٌ حيٌّ على نسخةٍ معزولة. **٢٥ وكيلاً.** كلُّ
بلاغٍ مؤكَّدٍ أُغلق بـ«اختبارٍ يفشل أوّلاً ثمّ إصلاح» (إثبات لا ادّعاء).

**١٧ بلاغاً خاماً → ٩ مؤكَّدة، ٨ مُفنَّدة.**

## المؤكَّدة (٩) — أُغلقت جميعاً

| # | الخطورة | العيب | الموضع | CWE | الاختبار |
|---|---|---|---|---|---|
| AUTH-1 | عالية | جلساتُ الجوال لا تُبطَل عند تغيير كلمة المرور | `MobileSessionAuth`·`MobileAuthController`·`ProfileController`·`UserController` | 613 | `MobilePasswordChangeRevokesSessionTest` |
| SSRF-1 | عالية | منافذُ البنيةِ الحسّاسة على loopback (Redis/Postgres…) مقبولةٌ بوّابةً — مسحُ خدمات | `AiCenterController`·`AiGateway` | 918 | `AiGatewayLoopbackSsrfTest` |
| AUTH-2 | متوسطة | سطحُ الجوال يتجاوز إجبارَ تبديلِ الكلمة والتحقّقِ بخطوتين | `MobileAuthController::policyBlock` | 306 | `MobileLoginEnforcesPoliciesTest` |
| FIO-1 | متوسطة | حجبُ المُصاب يعمى عن المحذوفِ ناعماً والمصغّرة | `FileController` | 424 | `InfectedSoftDeletedFileBlockedTest` |
| SEC-1 | متوسطة | كشفُ PUK عبر الـAPI بلا التصعيدِ الذي يفرضه الويب | `V1Controller::shape` | 306/620 | `PukStepUpNotBypassedViaApiTest` |
| AUTH-3 | منخفضة | مقارنةُ حالةٍ حرفيّة `status==='موقوف'` على ٤ أسطح | `MobileSessionAuth`·`ApiAuth`·`PasskeyController`·`MobileAuthController` | — | ضمن `MobilePasswordChangeRevokesSessionTest` |
| WEBHOOK-1 | منخفضة | إعادةُ ويبهوكٍ موقَّعٍ بلا طابعٍ زمنيّ (نافذة غير محدودة) | `InboundHookController` | 294 | `InboundHookReplayRejectedTest` |
| WEBHOOK-2 | منخفضة | مُبطِلُ التكرار يتحكّم به المهاجم (ترويسةُ العميل) | `InboundHookController` | 294 | `InboundHookReplayRejectedTest` |
| SSRF-2 | منخفضة | «افحص الآن» ينعكس داخليّاً لغيرِ المالك حين allow_private | `MonitorController` | 918 | `MonitorAllowPrivateOwnerOnlyTest` |

## المُفنَّدة (٨) — فُحِصت فوُجدت محصورةً بضابطٍ قائم

- **AUTHZ:** مُشكِّلُ الـAPI يُسقط الأعمدةَ غيرَ المُعلَنة (`$hidden` + إسقاطُ meta + حقولُ `sec`).
- **INJ (LIKE):** التنطيق يُطبَّق قبل مرشّح `like` — حرفا البدل يوسّعان داخلَ النطاق لا خارجَه.
- **SSRF-3/4:** `hub_outbound_ok` يرفض loopback و RFC1918 والمحجوز و link-local (بيانةُ السحابة).
- **SEC-2:** رسالةُ الاستثناءِ الخام خلف `if (config('app.debug'))` — false افتراضاً في الإنتاج.
- **SEC-3:** النسخُ الاحتياطيّة بلا مسارِ ويبٍّ لمحتواها (backupNow يعيد ملخّصاً لا الملفّ).
- **CFG-1:** لا `script-src` في CSP، لكن `frame-ancestors 'self'` + `X-Frame SAMEORIGIN` يحجبان التأطير.
- **CFG-2:** جذرُ الويب = `public/` في النشرِ الموصى به، فملفّاتُ الجذر خارجَ الخدمة.

## الأساسُ المقيسُ حيّاً (نسخةٌ معزولة)

لا كشفَ مستخدمين (ردٌّ متطابقٌ حرفاً · توقيتٌ متقارب) · مسارات `admin/*` تُحوَّل للدخول ·
ملفّاتٌ حسّاسة 404 · لا أثرَ أخطاءٍ · `/api/*` يرفض بلا رمز (٤٠١ مسطّح) · CSRF محصورٌ في
`hook/{token}` · HMAC آمنٌ زمنيّاً (`hash_equals` + ربطُ الطابع).

**الحكم:** لا اختراقَ من مهاجمٍ مجهولٍ عن بُعد. التسعةُ المؤكَّدة تتطلّب وصولاً موجوداً
(رمزٌ مُسرَّب · رايةٌ خطرة · مُطّلعٌ داخليّ) — أُغلقت جميعاً بأقلِّ تحصينٍ يغلق العيب،
إضافةً لا كسراً.
