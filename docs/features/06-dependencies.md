# نموذجُ الاعتماديّات (06)

> نظامُ اعتماديّةٍ **محدود** (لا محرّكَ سير عملٍ عملاق): كلُّ قدرةٍ تُعلن `depends` (إلزاميّة)
> و`optional_depends` و`external_depends` (أنظمةٌ خارجيّة) و`provider`.

## الخفضُ بالاعتماديّة

عند اشتقاقِ الحالة، قدرةٌ **متاحةٌ** يعطّلها اعتمادٌ إلزاميٌّ **غيرُ متاح** ⇒ تُخفَّض إلى
`NOT_CONFIGURED` بسببٍ صريح («يتوقّف على X وهي …»). الثوابتُ النظاميّةُ لا تُخفَّض.

## أمثلةٌ مُطبَّقة

| القدرة | تتوقّف على |
|---|---|
| `endpoint.usb_enforcement` | `endpoint.usb_observation` + `endpoint.mdm` + مزوّد MDM |
| `collab.websocket_provider` | `collab.event_contract` + عمليّةٌ دائمة + مزوّد WebSocket |
| `mobile.native_app` | `mobile.backend` + تطويرُ التطبيق الأصيل |
| `mobile.production_push` | جاهزيّةُ الجوال + اعتماداتُ مزوّد الدفع |
| `mobile.store_release` | `mobile.native_app` |
| `endpoint.apple_notarization` | `endpoint.macos_signing` + خدمةُ توثيق Apple |
| `telecom.live_provisioning` | `telecom.provisioning_adapter` + واجهةُ المشغّل |

## كشفُ الدورات

`FeatureRegistry::cycles()` (DFS بألوانٍ ثلاثة) يكشف أيَّ دورةٍ في رسمِ `depends`؛ ووجودُها يُدرَج
في `integrity()` فيُسقطُ اختبارَ النزاهة. **لا تفعيلٍ صامتٍ لاعتماديّةٍ أمنيّةٍ حسّاسة** — الحواجزُ
تُعرَض في الواجهة (تفصيلُ القدرة) ولا تُتجاوَز آليّاً.

## في الواجهة

تفصيلُ القدرة يعرض: الحواجزَ الإلزاميّة (روابطُ للقدرات المعطِّلة بحالتها)، والاعتماديّاتِ،
والأنظمةَ الخارجيّةَ المطلوبة، والمزوّد، و**ما يعتمد على هذه القدرة** (الأثرُ العكسيّ).
