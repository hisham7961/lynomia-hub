# الثوابتُ الأمنيّة (05)

> سلوكاتٌ أمنيّةٌ/منصّيّةٌ إلزاميّةٌ **ليست مفاتيحَ منتَج**. تظهر في السجلِّ للرؤيةِ فقط،
> بحالة `SYSTEM_INVARIANT` — بلا زرِّ إطفاء، بلا مفتاحِ تشغيلٍ زمنيّ، بلا تجاوزٍ عبر الاستيراد،
> بلا انتقالٍ عبر API، بلا تجاوزٍ إداريّ.

## المسجَّلة (11)

`security.authorization` (hub_can) · `security.company_scopes` · `security.client_isolation` ·
`security.portal_guard` · `security.audit_trail` · `security.audit_chain_verification` ·
`security.step_up` · `security.secret_encryption` · `security.idor_defense` ·
`endpoint.signature_verification` (ES256) · `endpoint.anti_replay`.

## الفرضُ الصلب

- `security_class => 'invariant'` في الكتالوج ⇒ `FeatureRegistry` يشتقُّ `SYSTEM_INVARIANT` **يغلب
  كلَّ شيء** (لا رايةَ تبديل، لا اشتقاقَ من فاحص).
- `FeatureStatus::transitionAllowed` يرفض أيَّ انتقالٍ من/إلى `SYSTEM_INVARIANT`.
- `FeatureRegistry::setEnabled` يرمي استثناءً على أيِّ ثابتٍ نظاميّ.
- `FeatureRegistry::isToggleable` يعيد `false` له دائماً.
- لا مفتاحَ إعدادٍ له ⇒ الاستيرادُ لا يمسّه، والـAPI لا ينقله إلى `disabled`.

## الحراسة بالاختبار (§5/§23)

`FeatureRegistryIntegrityTest` و`FeatureCenterTest` يثبتان: لا ثابتَ نظاميٍّ قابلٌ للتبديل، ومحاولةُ
إطفائه عبر المركز تُرفَض، وحالتُه تبقى `SYSTEM_INVARIANT` بعد المحاولة. **لا بابَ خلفيٌّ للإطفاء.**
