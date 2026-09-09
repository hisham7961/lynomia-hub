# معماريّةُ السجلّ (02)

> سجلٌّ واحدٌ لا محرّكَ أعلامٍ ثانٍ: كتالوجٌ كوديٌّ للحقيقةِ البنيويّة، وصنفُ خدمةٍ يشتقُّ
> الحالةَ السارية، وتبديلٌ يمرّ بمركز الإعدادات القائم حصراً.

## المكوّنات

| المكوّن | الدور |
|---|---|
| `config/hub_features.php` | **الكتالوجُ الكوديّ** — المجالاتُ + كلُّ قدرةٍ بحقولها البنيويّة (بلا closures) |
| `App\Support\FeatureStatus` | **نموذجُ الحالة** — الحالاتُ التسع + التسمياتُ + قواعدُ الانتقال |
| `App\Support\FeatureRegistry` | **المحرّك** — يقرأ الكتالوج، يشتقُّ الحالة، يحلّ الاعتماديّات، يبدّل عبر `Settings` |
| `hub_capability($key)` | **بوّابةُ التوافر** المركزيّة — مستقلّةٌ عن `hub_can` |
| `feature.*` في `config/hub_settings.php` | مفاتيحُ رايةِ التبديلِ للقدرات الاختياريّة — يملكها `features.index` |

## حقولُ مدخلِ القدرة

`key · domain · category · title_ar/en · desc_ar/en · status · derive · toggleable · setting_key ·
security_class · depends · optional_depends · external_depends · provider · permissions ·
account_types · admin_surface · web_routes · api_routes · mobile_backend · native_mobile · openapi ·
introduced · docs · limitations · deferred_notes`. الحقولُ الناقصةُ تأخذ افتراضيّاتِ
`FeatureRegistry::DEFAULTS` — فلا يُذكر إلا ما يختلف.

## اشتقاقُ الحالة السارية (`FeatureRegistry::status`)

1. `security_class === 'invariant'` ⇒ **SYSTEM_INVARIANT** (يغلب كلَّ شيء).
2. المُعلَنُ `DEFERRED`/`DEVELOPMENT` ⇒ يبقى (لا يُشتقُّ من فاحص — التنفيذُ غائب).
3. `toggleable` + `setting_key` ⇒ الرايةُ تحسم `ENABLED`/`DISABLED` (غيابُ الرايةِ = الافتراضيّ).
4. `derive` ⇒ فاحصٌ حيّ: `push` (PushService) · `usb.enforce` (MdmService) · `edge` (EdgeDefense) ·
   `integration:<key>` (Integrations) · `websocket` (READY/NOT_CONFIGURED). **يقرأ لا يكرّر.**
5. وإلا ⇒ الحالةُ المُعلَنة.
6. **خفضُ الاعتماديّة:** قدرةٌ متاحةٌ يعطّلها اعتمادٌ إلزاميٌّ غيرُ متاح ⇒ `NOT_CONFIGURED` بسببٍ صريح.

## التبديلُ الآمن (كاتبٌ واحد)

`FeatureRegistry::setEnabled($key, $on, $reason)` ⇒ **`Settings::put($settingKey, '1'|'0', 'features', $reason)`**
حصراً — تاريخٌ في `setting_changes` + قيدُ تدقيق. يرفض: الثابتَ النظاميّ، وغيرَ القابلِ للتبديل،
والانتقالَ غيرَ المشروع (`NOT_CONFIGURED/DEFERRED → ENABLED`).

## النزاهة (`FeatureRegistry::integrity`)

قائمةٌ فارغةٌ حين يصحّ كلُّ شيء — يحرسها `FeatureRegistryIntegrityTest`: مفاتيحُ مكرّرة · حالةٌ
غيرُ مشروعة · مجالٌ مجهول · اعتمادٌ مجهول · **دورةُ اعتماد** (DFS بألوانٍ ثلاثة) · عنوانٌ ناقص ·
قابلٌ للتبديلِ بلا مفتاحِ إعدادٍ مُعلَن · ثابتٌ نظاميٌّ قابلٌ للتبديل · مؤجَّلٌ قابلٌ للتبديل.

## الخفّة (§21)

الاشتقاقُ مُذكَّرٌ لكلِّ طلب (`resolveAll` مرّةً)، والفاحصاتُ محاطةٌ بـ`rescue` (تدهورٌ رشيق)،
ولا استعلامَ جداولَ تشغيليّةٍ كثيرة: الكتالوجُ كودٌ، والرايةُ من خبيئةِ الإعدادات نفسِها.
