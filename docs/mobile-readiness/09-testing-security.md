# الاختبارُ والمراجعةُ الأمنيّة — Testing / Security (Mobile Readiness · الطور I · §109)

> **إثباتٌ لا ادّعاء (CLAUDE.md):** كلُّ عيبٍ أمنيٍّ كُتِب **اختباراً يفشل أوّلاً** ثم أُصلح، وكلُّ
> ميزةٍ تُختبَر على قاعدةٍ معزولة قبل الدفع. **الحزمةُ خضراءُ على المحرّكَين** (SQLite + MySQL) —
> والأخطرُ ما تُخفيه القرعةُ (ترتيبُ الصفوف، مفاتيحُ JSON، عرضُ العمود) يُكشَف على MySQL الصارمة.
> **٢٦٤ اختباراً** على سطح الجوال عبر ٢٤ ملفّاً، **إضافةً صِرفة** لا تمسّ `/api/v1` ولا `ApiAuth`.

---

## 1) التغطيةُ حسب المجال (فاشلٌ أوّلاً · المحرّكان)

| المجال | الطور | الملفّات (`tests/Feature/Mobile/`) | العدد |
|---|---|---|---|
| المصادقة والجلسة | B | `MobileAuthTest` (٣١) · `MobileAuthContractTest` (٩) | **٤٠** |
| السياق/الإقلاع/الإعداد/المخطّط | C | `MobileContextTest` (١٠) · `MobileBootstrapTest` (٧) · `MobileAppConfigTest` (١٠) · `MobileSchemaTest` (١٠) | **٣٧** |
| تكافؤُ الأعمال (CRUD/إجراءات/اعتمادات/لوحة/تفضيلات) | D | `MobileCrudTest` (٨) · `MobileActionsTest` (٨) · `MobileApprovalWriteTest` (١١) · `MobileWorkspaceTest` (٩) · `MobileIdempotencyTest` (٥) | **٤١** |
| الاتصال (إشعارات/تعليقات/DM/دفع) | E | `MobileNotificationsTest` (١٣) · `MobileCommentsTest` (١١) · `MobileDmTest` (٩) · `MobilePushFanoutTest` (١١) · `MobilePushRegisterTest` (١٢) · `MobilePushLogoutRevokeTest` (٢) | **٥٨** |
| الملفّات/الماسح/الموقع | F | `MobileFilesTest` (٢١) · `MobileScannerTest` (٦) · `MobileTrackingTest` (٨) | **٣٥** |
| المزامنة/التعارُض | G | `MobileSyncTest` (٢٢) · `MobileSyncConcurrencyTest` (٦) | **٢٨** |
| الوثائق/OpenAPI | H | `MobileOpenApiTest` (١٢) | **١٢** |
| عزلُ المسارات (عابرُ الأطوار · F9) | — | `MobileRouteIsolationTest` (١٣) | **١٣** |
| **المجموع** | | | **٢٦٤** |

مساعِدان مشتركان (لا اختباراتٍ فيهما): `InteractsWithMobileAuth.php` (سكُّ جلسةٍ حقيقيّة للاختبار)
و`AssertsMobilePayload.php` (تأكيدُ غلافِ `Api::*` وعدمِ تسريبِ سرّ).

---

## 2) البوّابةُ ثنائيّةُ المحرّك — لماذا الاثنان

القاعدةُ من CLAUDE.md: لا تُدفَع حزمةٌ حمراءُ على أيٍّ من المحرّكَين.

- **محليّاً:** `./vendor/bin/phpunit` (SQLite) و`./vendor/bin/phpunit -c phpunit.mysql.xml` (MySQL).
- **على CI** (`.github/workflows/ci.yml`): مصفوفةٌ تُشغّل الاثنين عند كل دفعة — SQLite على PHP 8.2
  و8.4 (`ci.yml:27-28`)، وMySQL 8.0 على PHP 8.4 (`ci.yml:29`) بخدمةِ `mysql` حقيقيّة (`:32-39`).
- **بوّابةُ OpenAPI خارجَ الحزمتَين** (`ci.yml:67-75`): تُعيد توليدَ `docs/openapi.json` من السجلّ
  وتُسقط الدفعةَ إن انحرف — فمواصفةُ v1 لا تُحرَّر يدويّاً. (مواصفةُ الجوال وثيقةٌ حيّةٌ منفصلة
  `GET /api/mobile/v1/openapi.json` لا تلمس هذا الملفّ.)
- **فحصُ المخطّط على MySQL** (`ci.yml:92`): `hub:schema-check` — عمودٌ غائبٌ/عرضٌ ضيّقٌ يُكشَف حيث تكون MySQL صارمة.

**لماذا يهمّ عمليّاً هنا:** جدولا `push_tokens`/`push_deliveries` يعلنان عرضَ العمود حرفيّاً ويقصّان
بـ`hub_fit` قبل الكتابة (`06-notifications-push.md` §4)؛ ومزامنةُ الجوال تفرض `orderBy(updated_at)->orderBy(id)`
حتميّاً (`05-offline-sync.md` §3) لأنّ قرعةَ ترتيب MySQL 8 تُسقِط الصفوفَ صامتاً دونه؛ وتأكيداتُ
الحمولة تُختبَر مفتاحاً مفتاحاً لا على المصفوفة كلِّها (قرعةُ مفاتيح JSON على MySQL).

---

## 3) قائمةُ المراجعةِ الأمنيّة — الخادمُ يُخوّل، والتطبيقُ غيرُ موثوق

المبدأ الحاكم: لا يُخوَّل شيءٌ على زرٍّ مخفيٍّ ولا اسمِ مسارٍ ولا دورٍ مُدَّعىً ولا `user_id`/`owner`
يرسلها العميل. الهويّةُ من `MobileSessionAuth` وحدَها (`Auth::setUser`)، والصلاحيّةُ من `hub_can`/`hub_scope`/`hub_field_mode`.

| البند | الدفاعُ في الشجرة | أين يُثبَت |
|---|---|---|
| **تجاوزُ المصادقة** | `MobileSessionAuth` يطابق `access_hash` (sha256) + `access_expires_at`، ويُعيد الحراسَ الخمسة (موقوف/مقفول/منتهٍ/`allowed_ips`/lockdown) بعد التوثيق (`app/Http/Middleware/MobileSessionAuth.php:43-74`) — السطحُ الخامسُ يفرض بوّابات الويب نفسَها (لا دخولٌ موازٍ أضعف) | `MobileAuthTest` · `MobileAuthContractTest` |
| **إعادةُ تشغيل الرمز (replay)** | لا رمزَ نصّيٌّ يُخزَّن: `access_hash`/`refresh_hash` sha256 فقط (نظيرُ `ApiToken`)؛ لا يُسجَّل رمزٌ في تدقيقٍ ولا سجلّ | `MobileAuthTest` (hashes-not-plaintext) · `AssertsMobilePayload` |
| **إعادةُ استعمال رمز التحديث** | التدويرُ لمرّةٍ واحدة؛ استعمالُ رمزٍ مُدوَّرٍ ⇒ إبطالُ العائلة `family_id` كاملةً + `SecurityRadar::record` + `REFRESH_TOKEN_INVALID` | `MobileAuthTest` |
| **IDOR (هويّةٌ خاصّة)** | الإشعاراتُ/الرموزُ منطَّقةٌ بـ`user_id = auth` وحدَه؛ DM بمعرّف الطرف الآخر والمفتاحُ يُبنى خادميّاً (لا `thread_key` من العميل · F8)؛ المرفقُ عبر `guardRecord(...,'v')`+`findOrFail` | `MobileNotificationsTest` · `MobileDmTest` · `MobilePushRegisterTest` · `MobileFilesTest` |
| **تسرُّبُ المستأجر (شركة/عميل/مشروع)** | `hub_scope` صارمٌ كلَّ استعلام + `MobileContext::apply` (تضييقٌ لا توسيع) — الترويسةُ تُتقاطَع مع `hub_company_ids`/`hub_client_ids` ولا توسّع | `MobileContextTest` · `MobileCrudTest` · `MobileWorkspaceTest` · `MobileApprovalWriteTest` · `MobileScannerTest` · `MobileCommentsTest` |
| **تسرُّبُ الحقل** | `hub_field_mode`/`hub_visible_fields` (المخفيُّ غائبٌ، `sec` لا يُعادُ لغيرِ المخوَّل)؛ المخطّطُ يُسقِط العمودَ الفيزيائيّ `table`؛ المزامنةُ تُجرّد `sec` حتى للمخوَّل | `MobileSchemaTest` · `MobileSyncTest` · `MobileContextTest` |
| **تسرُّبُ المرفق** | القرصُ الخاصُّ `local` وحدَه (لا رابطٌ عامّ/base64) + `guardRecord(...,'v')` + `abort_if(av_status==='infected',423)` + `Content-Disposition: attachment` | `MobileFilesTest` |
| **تسرُّبُ الدفع** | الحمولةُ آمنةٌ بالبناء (عنوانٌ عامٌّ + رابطٌ عميق + عددُ غير المقروء — لا نصَّ الإشعار)؛ dedupe عابرُ المستخدمين (F7) | `MobilePushFanoutTest` · `MobilePushRegisterTest` |
| **إجراءٌ اعتباطيّ** | `GET {module}/{id}/actions` يُركّب **قائمةَ التحوّلات الصالحة** (status_via_action + requires + `hub_can` + approval + locked + السلة) — لا «نفّذ أيَّ شيء» | `MobileActionsTest` |
| **الإسنادُ الجَماعيّ (mass assignment)** | كلُّ كتابةٍ عبر مُشكّلٍ/قائمةٍ بيضاء صريحة (لا يُثبَّت `is_owner`/`owner`/عمودٌ داخليٌّ من العميل) | `MobileCrudTest` · `MobileContextTest` |
| **تجاوزُ الخنق (rate-limit)** | `auth/login` `throttle:10,1` (نظيرُ الويب) · `auth/mfa/verify` `6,1` · `auth/refresh` `20,1` (`routes/api.php:104-108`) + قفلُ الحساب `bumpFailedAttempts` — لا `throttle:api` الفضفاض على الدخول (F4) | `MobileAuthTest` · `MobileAuthContractTest` · `MobileDmTest` |
| **الأسرار/تسرُّبُ السجلّ** | لا رمزَ/كلمةَ سرٍّ/سرَّ MFA/اعتمادَ دفعٍ في تدقيقٍ أو سجلّ؛ `push/admin/status` يعرض **حضورَ** الاعتماد لا قيمتَه | `MobileAuthTest` · `AssertsMobilePayload` · `MobilePushRegisterTest` |
| **تليمتري السياق لا يُخوّل** | `X-Lynomia-Company`/`-Client` تُضيّق العرضَ فقط؛ ترويسةٌ تسمّي شركةً لا يراها المستخدمُ تُرفَض/تُتجاهَل، لا توسّع | `MobileContextTest` |
| **التوافقُ الرجعيّ** | `/api/v1` + `ApiAuth` + `ApiToken` + `docs/openapi.json` غيرُ ممسوسة؛ مُخرَجُ مولّد v1 مطابقٌ للملفّ الملتزَم عدا `servers` المُطبَّعة | `MobileOpenApiTest` · `MobileRouteIsolationTest` · `MobileSchemaTest` · `MobileCrudTest` · `MobileAuthContractTest` · `MobileIdempotencyTest` · `MobileSyncConcurrencyTest` |

---

## 4) التليمتري الأمنيّ — سكّةٌ واحدة (لا «مركزُ أمنِ جوالٍ» ثانٍ)

`SecurityRadar::record($request, event, detail)` هو الأثرُ الواحد؛ يُستدعى من `MobileSessionAuth`
على كلِّ رفضٍ (`MobileSessionAuth.php:37,45,52`) ومن مسار الدخول على فشلٍ/إعادةِ استعمالِ تحديثٍ/وصولِ
جلسةٍ مُبطَلة. والتدقيقُ الحسّاسُ يدخل **القيدَ نفسَه** (`hub_audit`) بوسمِ `source=mobile` (يقرؤه
`Api::requestSource`) وبمعرّف الجلسة/التنصيب و`request_id` الواحد — **دون تسجيل رمزٍ/كلمةٍ/سرِّ MFA/اعتمادِ دفعٍ**.
مُثبَتٌ بتأكيدات `SecurityRadar` في `MobileAuthTest`.

---

## 5) NOT_CONFIGURED — لا نجاحٌ مُزيَّف في الاختبار

الاختباراتُ تحقن مزوّدَ دفعٍ عبر الحاوية (لا حالةٌ ساكنةٌ تتسرّب)، والمزوّدُ الصفريُّ يقول
`not_configured` صدقاً؛ فمسارُ «غيرِ المُهيّأ» مُختبَرٌ صراحةً (`MobilePushFanoutTest` · `MobilePushRegisterTest`):
لا FCM ⇒ `push_deliveries` تسجّل `not_configured` لا `delivered`. وكلُّ الواجهات الخارجيّة (FCM/APNs
credentials + project ids · App Attest/DeviceCheck/Play Integrity · Universal/App Links ids · store URLs)
تبقى NOT_CONFIGURED صادقةً حتى يُصدرها فريقُ التطبيق (راجع `10-mobile-app-handoff.md`).
