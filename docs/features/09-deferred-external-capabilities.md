# المؤجَّلُ والخارجيُّ وغيرُ المُهيَّأ (09)

> غايةٌ جوهريّة: **الوظيفةُ المستقبليّةُ لا تُنسى.** المؤجَّلُ يبقى مرئيّاً في السجلّ بسببه وما
> بقي منه. والاعتماديّةُ الخارجيّةُ تُمثَّل بصدق — **لا جاهزيّةَ مزيَّفة**.

## المؤجَّل (`DEFERRED` · 5)

| القدرة | لماذا | ما بقي |
|---|---|---|
| `collab.voice_notes` | منتَجٌ مؤجَّل | لا UI تسجيلٍ زائف حتى يُقرَّر المنتَج |
| `collab.link_previews` | أمنٌ مؤجَّل | لا جلبَ خادميّ حتى تُصمَّم مضادّاتُ SSRF |
| `assets.project_assignment` | غيرُ first-class للأصول الماديّة | الربطُ عبر الموظّف/المحطّة قائم؛ المباشرُ للأصول الرقميّة وحدها |
| `mobile.native_app` | قرارٌ استراتيجيّ | الخلفيّةُ والعقودُ جاهزة متى بدأ |
| `mobile.store_release` | تبعاً للتطبيق الأصيل | — |

> لا **أدواتَ مستخدمٍ زائفة** ولا **UX نائبٌ** خارجَ مركزِ القدرات الإداريّ.

## غيرُ المُهيَّأ (`NOT_CONFIGURED` · 10) — يُشتقُّ من فاحصٍ لا يُدّعى

`mobile.production_push` (PushService) · `collab.websocket_provider` (websocket) ·
`endpoint.usb_enforcement` + `endpoint.mdm` (MdmService) · `security.edge_blocking` (EdgeDefense) ·
`telecom.live_provisioning` · `endpoint.production_signing` · `endpoint.windows_signing` ·
`endpoint.macos_signing`. كلُّها تحمل `external_depends` + `provider` صريحين.

## الخارجيُّ (`EXTERNAL` · 3)

`endpoint.intune` · `endpoint.jamf` · `endpoint.apple_notarization` — تعتمد أساساً على مزوّدٍ خارجيّ.

## قاعدةُ الأسرار

فاحصاتُ الاتصالُ (`ConnectionProbe`/`Integrations`) تُقرأ حالتُها **مطموسةً**؛ لا قيمةَ اعتمادٍ تُعرَض
في مركزِ القدرات قطّ — الأسرارُ تبقى في مركزِ الإعدادات ببصمةٍ لا نصّ.
