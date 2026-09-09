# تصنيف القدرات للاكتشاف (02)

> المدخلُ: **سجلُّ القدرات** (`config/hub_features.php` + `FeatureRegistry`) — ١٣٤ قدرةً، ١٠٢ منها `ENABLED`.
> لا نُكدِّس ١٣٤ رابطاً؛ نُصنِّف لنكشفَ ما هو مُتاحٌ بلا مسارِ اكتشاف.

## توزيعُ الحالة (من `FeatureRegistry::counts()`)

| الحالة | العدد |
|---|---|
| ENABLED | 102 |
| READY | 3 |
| DEFERRED | 5 |
| NOT_CONFIGURED | 10 |
| EXTERNAL | 3 |
| SYSTEM_INVARIANT | 11 |
| **المجموع** | **134** |

## تصنيفُ الـ١٠٢ المفعَّلة بحسب سطحِ الاكتشاف

| التصنيف | كيف يُشتقّ | العدد | الاكتشاف |
|---|---|---|---|
| **PRIMARY / وحدة** | `web_routes` مُعلَنة، وتقع في سطح/مجال IA ملاحيّ | 17 | الشريطُ الجانبيّ / صفحةُ المساحة |
| **ADMIN** | `admin_surface` بلا `web_routes` عامّة | 3 | شريطُ الإدارة (`hub_admin_links`) |
| **API_ONLY / MACHINE** | `api_routes` فقط (خلفيّةُ الجوال/التكامل) | 4 | لا مدخلَ بصريّ (مقصود) |
| **CONTEXTUAL / سلوكيّة** | سلوكٌ داخلَ سطحٍ ملاحيّ (تفاعلات/خيوط/إشارات/تثبيت/حفظ…) | 78 | يكشفها سطحُ الأبِ (مركزُ التواصل، السجلّ، الوحدة) |

> **القاعدةُ الآليّة (مُختبَرة):** كلُّ مسارِ ويبٍ لقدرةٍ `ENABLED` **يوجد** ويُصنَّف في IA (لا «غيرُ مصنَّف»)
> — `DiscoverabilityAuditTest::test_every_enabled_user_facing_capability_route_is_classified_in_ia`.
> عيبٌ حقيقيٌّ كُشِف وأُصلِح: `workos.global_search` كان يشير إلى مسارٍ غيرِ موجود `search.index` (الصحيح `search`).

## المؤجَّل/الخارجيّ/غيرُ المُهيَّأ — لا فعلَ مستخدمٍ زائف

`collab.voice_notes` · `collab.link_previews` · `assets.project_assignment` · `mobile.native_app` ·
`mobile.store_release` (مؤجَّل) — تبقى مرئيّةً في **مركز القدرات** فقط، بلا زرٍّ مستخدمٍ زائف. وكذلك
`NOT_CONFIGURED`/`EXTERNAL` (الدفعُ الإنتاجيّ، MDM، Intune/Jamf…) لا تُعرَض بواجهةٍ فعّالةٍ مُضلِّلة.

## الثوابتُ الأمنيّة (11 · SYSTEM_INVARIANT)

authorization · company_scopes · client_isolation · portal_guard · audit_trail · audit_chain_verification ·
step_up · secret_encryption · idor_defense · endpoint.signature_verification · endpoint.anti_replay —
**لا تُطفأ ولا تُعرَض كفعلٍ مستخدم**؛ اكتشافُها عبر مركز القدرات (سطحٌ إداريّ) لا الشريط.
