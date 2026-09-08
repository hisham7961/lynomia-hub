# وكيلُ ومنصّةُ النقاط الطرفية — الهندسة والحالةُ الراهنة (00)

> **مسارُ التصحيح والتقسية** لتنفيذٍ قائم (Work OS · الأطوار J/K/L) — **لا إعادةُ بناء،
> ولا نظامٌ ثانٍ**. تُوثَّق الحالةُ الفعليّة أولاً (§0)، ثم تُقسّى تدريجيّاً.

## أربعُ هويّاتٍ مستقلّة (لا تتحوّل إحداها للأخرى)

| الهويّة | المصادقة |
|---|---|
| مستخدمُ الويب | جلسةُ متصفّح |
| مستخدمُ الجوال | `/api/mobile/v1` — جلسةُ جوالٍ أصيلة (**بلا مساس**) |
| عميلُ تكاملِ API | `ApiToken` |
| **جهازُ نقطةٍ طرفية** | تسجيلٌ لاتماثليٌّ P-256 + طلباتٌ موقَّعة ES256 |

جهازُ النقطةِ الطرفية عميلُ إدارةِ أجهزةٍ منفصل — **لا يُحوَّل إلى جلسةِ جوال ولا العكس**.

## المكوّنات القائمة (مُثبَتة)

**الخادم (Laravel):**
- نماذج: `EndpointDevice`، `EndpointRelease`، `EndpointEvent`، `EndpointCommand`، `EndpointPolicy`، `EnrollmentToken`.
- جداول: `endpoint_devices`، `enrollment_tokens`، `endpoint_nonces`، `endpoint_events`، `endpoint_commands`، `endpoint_policies`، `endpoint_releases`.
- بروتوكول الوكيل: `Api\EndpointProtocolController` خلف `EndpointSignature` middleware (ES256 على `Es256::canonical`، نافذةُ ±٣٠٠ث، nonce فريدٌ لكلِّ جهاز، إبطالُ إعادةٍ ذرّيّ).
- التسجيل: `Api\EndpointEnrollController` (سكُّ رمزٍ مالك/مراقب + Step-Up؛ التسجيلُ عامٌّ برمزٍ لمرّةٍ واحدة).
- الواجهة: `Web\EndpointCentreController` + `Web\EndpointReleaseController` (عميلٌ ٤٠٤، مالك/مراقب).
- التوقيع: `App\Support\Es256` (P-256/ES256 حصراً). الخصوصيّة: `App\Support\EndpointPrivacy`.

**الوكيل (Go · `agent/`):** هويّة P-256 (المفتاحُ الخاصّ لا يغادر الجهاز)، نقلٌ موقَّع، جردٌ، وضعيّة، USB (metadata فقط)، محرّكُ تحديثٍ يتحقّق من sha256 قبل التبديل، أوامرُ **قائمةُ سماحٍ مغلقة** (لا تنفيذَ عامّ)، وحُرّاسُ مصدرٍ (`guardrails_test.go`) يمنعون shell/keylog/screenshot/clipboard/packet-capture.

## تصنيفُ الحالة (§19)

| القدرة | الحالة |
|---|---|
| التسجيل اللاتماثليّ + توقيعُ الطلبات + منعُ الإعادة | **IMPLEMENTED** |
| قائمةُ الأوامر المغلقة + Step-Up للخطير + تدقيق | **IMPLEMENTED** |
| حسابُ sha256 خادميّاً + تحقّقُ الوكيل قبل التبديل | **IMPLEMENTED** |
| سلسلةُ ثقةِ الإصدار (توقيع/توثيق/بناء/أدنى نسخ) + حالاتٌ (draft/published/withdrawn) | **IMPLEMENTED — §5/§6** (راجع 02) |
| الطرحُ المرحليّ (all/company/canary — غيرُ مُلزَمٍ افتراضاً) | **IMPLEMENTED — §7** (البيانُ ينتقي المستهدِف) |
| الترقيةُ الآمنة (لا تنازل + جسرُ ترقية + لا عملَ على المطابق) | **IMPLEMENTED — §7** (‏`update.Precheck` فوقَ عقد التجزئة) |
| حرّاسُ الخصوصيّة (خادم + مصدرُ الوكيل) | **IMPLEMENTED** |
| أنظمةُ التشغيل المدعومة (Windows/macOS) | **مُصحَّح في هذا المسار — §1** (كان linux مقبولاً) |
| ربطُ التسجيل بأصلٍ مملوكٍ للشركة | **IMPLEMENTED — §2** (ربطٌ خادميٌّ + أهليّةٌ fail-closed — راجع 01) |
| اتساقُ دورةِ الحياة مع الأصل | **IMPLEMENTED — §3** (نهائيّةٌ⇒تعليقٌ+إلغاءُ أوامر؛ مفقود/تالف⇒تنبيهٌ ويبقى نشطاً — عبر Custody) |
| توقيعُ Authenticode / Developer ID / notarization | **NOT_CONFIGURED — يتطلّب شهاداتِ توقيعٍ خارجيّة** |
| مثبّتا Windows MSI / macOS PKG | **DEFERRED → §5/§6** (أرتيفاكتاتُ تطويرٍ حالياً) |
| فرضُ USB (حجب فعليّ) | **NOT_CONFIGURED — يتطلّب MDM (Intune/Jamf)** — اليومَ مراقبةٌ فقط |
| تكاملُ MDM (Intune/Jamf) | **DEFERRED → §8/§9 — يتطلّب اعتماداتِ المزوّد** |
| وضعيّةُ Wi-Fi للشركة | **DEFERRED → §10** (SSID مُعطَّلٌ في الوكيل — not-configured صادق) |
| عزلُ الجهاز (`isolate`) الفعليّ | **NOT_CONFIGURED — يتطلّب MDM** — اليومَ علامةٌ فقط |

## المبادئُ غيرُ القابلة للتفاوض

- المفتاحُ الخاصّ **لا يغادر الجهاز** ولا يُخزَّن ولا يُدوَّن؛ الخادمُ يخزّن العامَّ وبصمتَه فقط.
- **لا تنفيذَ أوامرَ عامّ** (لا shell/PowerShell/Bash/AppleScript/binary/URL/plugin) — قائمةٌ مغلقةٌ مقفولةُ الأنواع.
- **حدُّ الخصوصيّة يُقسَّى فقط:** لا keylog/screenshot/clipboard/webcam/mic/browser-history/file-content/LAN-scan/تتبّعَ موقعٍ عبر الحاسوب.
- **الصدق:** ما لا يمكن فعلُه على النظام أو دون MDM/شهادةٍ يُعرَض `NOT_CONFIGURED`/`unsupported` — لا تزييفَ «موقَّع» ولا «محجوب» ولا وضعيّةٍ مختلَقة.
- `/api/mobile/v1/*` **بلا مساس** (لا انحدار).

*(تُستكمَل الوثائقُ 01–10 مع تقدّم مسار التصحيح.)*
