# الوكيل ١٦ — الإعداداتُ وسجلُّ القدرات

> **النطاق:** `/home/user/lynomia-hub` · الرأسُ المُجمَّد `1d90626` (v2.540.1) · طبقة ١ · الجولة أ
> **المنهج:** قراءةٌ فقط للمستودع + مسابرُ PHPUnit مؤقّتةٌ خارجَه (`scratchpad/probe/`). لا ملفَّ في المستودع تغيّر إلّا هذا التقرير. **لم يُلمَس `lynomia_month`.**
> **المقيس (مُعادُ التحقّق):** `FeatureRegistry::all()` = **137** · ENABLED 107 · SYSTEM_INVARIANT 11 · NOT_CONFIGURED 9 · DEFERRED 4 · READY 3 · EXTERNAL 3.
> **الإعدادات:** 114 مفتاحاً معروضاً في `config/hub_settings.php` + 65 مفتاحاً داخليّاً = **179**.

---

## ٠) الخلاصةُ في سطور

| السؤال | الجواب |
|---|---|
| إعدادٌ مُعلَنٌ بلا قارئٍ في الكود | **١** (`ops.http_p95_ms`) — ومعه صفٌّ شبحيٌّ يُبذر في كلّ تنصيبٍ ولا يقرؤه أحد: `notify.quiet` |
| إعدادٌ معروضٌ بلا تحقّقٍ خادميٍّ يرفض | **١٠٣ من ١١٤** (١١ فقط تحمل `re` مُنفَّذاً؛ و٩٤ بلا أيّ كتلةِ `validation` أصلاً) + الـ٦٥ الداخليّةُ كلُّها |
| حالةُ قدرةٍ كاذبة | ادّعاءُ «٠ حالةٍ كاذبة» **لا يصمد**: ١ كاذبةٌ جزئياً (`security.step_up`) · ٧ مشكوكٌ فيها · ٦ «صادقةٌ جامدة» لا فاحصَ يرفعها · وواحدةٌ (`endpoint.usb_enforcement`/`endpoint.mdm`) **تنقلب كاذبةً بسطرِ إعدادٍ واحد — وقد أُثبِت** |
| حالاتٌ تنحرف عن المُعلَن في خطّ الأساس | **صفر** — والسببُ أنّ الفاحصاتِ كلَّها تعود إلى الفرع «غيرُ مُهيَّأ»؛ فالاشتقاقُ لا يُنتج فرقاً أصلاً، وأوّلُ حالةٍ يُنتج فيها فرقاً هي الحالةُ التي يكذب فيها (A16-01) |
| قدراتٌ بلا وصفٍ وبلا أيِّ مؤشّرٍ (مسار/شاشة/صلاحية/وثيقة) | **٦٤ من ١٣٧** (٤٧٪) — و**٩٩** بلا أيِّ مؤشّرٍ آليٍّ قابلٍ للفحص |
| قدراتٌ قابلةٌ للتبديل فعلاً | **٢ من ١٣٧** (`collab.presence` · `collab.typing`) — وكلتاهما **مُنفَّذةٌ بصدق** عبر `hub_capability()` في الويب والجوال معاً |

---

## ١) ما وجدتُه صحيحاً — يُقال قبل النقد

قبل الملاحظات، هذه أشياءٌ فحصتُها فوجدتُها **كما تَعِد**، وهي ليست قليلة:

- **الكاتبُ الواحد قائمٌ فعلاً.** `Settings::put/forget/batch` هو البابُ الوحيد لكلِّ شاشةٍ وأمرِ طرفيّةٍ واستيراد (٤٢ نداءً)، ومعه التشفيرُ للحسّاس وإبطالُ الخبيئة وقيدُ تدقيقٍ واحدٌ للدفعة وصفٌّ في `setting_changes`. المواضعُ التي تكتب مباشرةً (`Health` · `Integrations` · `HubOpsSnapshot` · `EsignController` · `ContractActionsController` · `HubDemo`) **كلُّها تُبطل الخبيئة**، وكلُّها صفوفُ حالةٍ أو تحمل تدقيقَها الخاصّ — **إلّا واحداً** (A16-12).
- **الأرضياتُ المُعلَنةُ الثمانِ صادقةٌ كلُّها.** فحصتُ كلَّ واحدةٍ في قارئها: `max(30,…)` و`max(7,…)` و`max(500,…)` و`max(0.1,…)` و`max(1,min(365,…))` — كلُّها موجودةٌ حرفيّاً حيث يقول الكتالوج. والقاعدةُ `in` على `work.missing_report_policy` مُنفَّذةٌ في `DailyWorkCompliance::policy()` (وإن لم تُنفَّذ عند الكتابة — A16-06).
- **`audit.retention_days`/`audit.retention_policy` نموذجُ صدقٍ يُحتذى:** مفتاحان بلا أيِّ أثرٍ تنفيذيّ، والكتالوجُ يقول ذلك بنفسه («إعلانُ سياسةٍ لا أمرُ تنفيذ … **ولا كودَ تقليمٍ يقرؤها إطلاقاً**»). هكذا يُعلَن المفتاحُ الوصفيُّ بدل أن يُدسّ.
- **تبديلُ القدرتين الاختياريّتين مُنفَّذٌ لا مزعوم.** أطفأتُ `collab.typing` و`collab.presence` ذهنيّاً وتتبّعتُ كلَّ سطحٍ: `MobileCollabController` (٤ مواضع) · `DmController` (٣) · `ConversationController` (٢) — وكلُّها تمرّ بـ`hub_capability()`، والحضورُ يُقطع من `DmController::presence()` فيسقط تلقائياً عن `CollaborationRail` وشريطِ التعاون. فشلٌ آمن (٤٠٤/قائمةٌ فارغة) لا تسريب.
- **كنسةُ السرّ مشتقّةٌ من الكتالوج لا مكتوبةٌ بيد** (`SettingsSecretSweepTest`)، فمفتاحٌ حسّاسٌ يُضاف غداً يدخل الحراسةَ تلقائياً. والأسرارُ الستّةُ الموسومةُ تُخزَّن `enc:` وتخرج بصمةً فقط — أكّدتُه على مسارِ التصدير والمعاينة والتاريخ.
- **طبقةُ MDM نفسُها نموذجُ صدقٍ عند الفعل:** `RemoteMdmProvider::applyUsbPolicy` لا تعيد «حُجب» أبداً، وأقصاها `observe-only` بتفصيلٍ يقول إنّ الجسرَ مؤجَّل. المشكلةُ ليست في الطبقة بل في **السجلِّ الذي يقرؤها نصفَ قراءة** (A16-01).

---

## ٢) الملاحظات

### A16-01 | 🔴 عالية | «الإنفاذُ متاحٌ لكلِّ سياسةٍ وجهاز» — وعدٌ لا يقع، بسطرِ إعدادٍ واحد
**القدرة:** `endpoint.usb_enforcement` · `endpoint.mdm` · **الثقة: عالية جداً (مُثبَتٌ بتشغيل)**

`FeatureRegistry::deriveFromSource('usb.enforce')` يقرأ من `MdmService::status()` حقلاً واحداً:

```php
$configured = (bool) rescue(fn () => MdmService::status()['configured'] ?? false, false, false);
return $configured
    ? ['status' => FeatureStatus::READY, 'reason' => 'MDM مُهيَّأٌ — الإنفاذُ متاحٌ لكلِّ سياسةٍ/جهاز (رصدٌ افتراضاً)']
    : [...NOT_CONFIGURED...];
```

والحقلُ الذي يقول الحقيقةَ — `can_enforce` — **مطبوعٌ في المصفوفةِ نفسِها ويُتجاهَل**. و`RemoteMdmProvider::canEnforce()` تعيد `false` **دوماً** بتعليقٍ صريح: «مؤجَّلٌ صراحةً (C15): لا وصلَ API حيّاً — فلا فرضَ يُزعَم مهما اكتملت الاعتمادات».

**الدليل** (مسبارٌ شغّلتُه — مزوّدٌ `isConfigured()=true` و`canEnforce()=false`):

```
MdmService::status = {"driver":"intune","configured":true,"can_enforce":false,...}
endpoint.usb_enforcement => READY :: MDM مُهيَّأٌ — الإنفاذُ متاحٌ لكلِّ سياسةٍ/جهاز (رصدٌ افتراضاً)
endpoint.mdm             => READY :: MDM مُهيَّأٌ — الإنفاذُ متاحٌ لكلِّ سياسةٍ/جهاز (رصدٌ افتراضاً)
```

فمديرُ الأمن الذي يحفظ مستأجرَ Intune وسرَّه في `endpoints.mdm` يرى في **مركز القدرات** أنّ «تنفيذَ USB» صار `READY` والإنفاذُ متاحٌ — ويرفع تقريرَ امتثالٍ بذلك — بينما كلُّ منفذِ USB في المنشأة مفتوحٌ كما كان، والطبقةُ الدنيا تقول `observe-only` لمن يقرؤها. هذا **بالضبط** ما يحرّمه الكتالوجُ في ترويسته: «لا حالةَ متفائلة».

وهو أخطرُ من حالةٍ كاذبةٍ ساكنة: السجلُّ صادقٌ اليوم لأنّ لا أحدَ ضبط MDM؛ فالخطأُ **نائمٌ حتى اللحظةِ التي يُصدَّق فيها**.

**الإصلاح:** `usb.enforce` يشترط الاثنين معاً:
```php
$s = rescue(fn () => MdmService::status(), ['configured'=>false,'can_enforce'=>false], false);
if (! ($s['configured'] ?? false)) return [NOT_CONFIGURED, 'لا مزوّدَ MDM مُهيَّأً — النظامُ يرصد USB ولا يحجب'];
if (! ($s['can_enforce'] ?? false)) return [FeatureStatus::DEGRADED,
    'اعتماداتُ MDM حاضرةٌ وجسرُ الفرض الحيِّ مؤجَّل — رصدٌ فقط، لا حجبَ منفذٍ يقع'];
return [READY, 'MDM مُهيَّأٌ وقادرٌ على الفرض'];
```
**اختبارُ الانحدار:** يُضاف إلى `FeatureRegistryIntegrityTest`: احقن `MdmProvider` بـ`isConfigured()=true`/`canEnforce()=false` وأكّد أنّ `endpoint.usb_enforcement` **ليست** `READY` وأنّ سببَها لا يحوي «الإنفاذُ متاح». (المسبارُ جاهزٌ في `scratchpad/probe/A16MdmProbeTest.php`.)

---

### A16-02 | 🔴 عالية | ثابتٌ نظاميٌّ «لا يصير مفتاحاً» — وله مفتاحان
**القدرة:** `security.step_up` · **المفتاحان:** `security.stepup_ops` · `security.stepup_credentials` · **الثقة: عالية (مُثبَت)**

`security.step_up` مُعلَنةٌ `SYSTEM_INVARIANT` بوصفٍ قاطع: «حدودُ التأكيد الإلزاميّة — **لا تصير مفتاحاً**»، ويعرضها مركزُ القدرات بسببٍ ثابت: «سلوكٌ أمنيٌّ/منصّيٌّ إلزاميّ — للرؤية فقط، **لا يُطفأ**».

وفي كتالوج الإعدادات الداخليّ مفتاحان يفعلان ذلك بالضبط:

| المفتاح | ما يُطفئه |
|---|---|
| `security.stepup_ops` | تأكيدَ كلمةِ المرور قبل **الترحيل** وتبديلِ **الصيانة** ومسحِ الكاش وتصفيرِ الوضع التجريبيّ |
| `security.stepup_credentials` | تأكيدَ كلمةِ المرور قبل **سكِّ مفتاح API** أو تدويره، وقبل تسجيلِ مفتاح مرورٍ أو حذفِه |

والقارئُ لا يجامل:
```php
function hub_require_ops_stepup(?string $next = null) {
    if ((string) setting('security.stepup_ops', '1') !== '1') return null;   // ← مفتاحُ إطفاء
    return hub_require_stepup($next);
}
```

**الدليل:** بعد `Settings::put('security.stepup_ops','0','cli')` عادت `hub_require_ops_stepup()` بـ`null` — أي «مرَّ بلا تأكيد». والمفتاحان يُكتبان من `php artisan hub:set` بلا عائق.

الكتالوجُ يسمّيهما «(للاختبارات فقط)» — لكنّ **النيّةَ ليست حاجزاً**: لا شيءَ في الكود يقيّدهما ببيئةِ الاختبار. وحزمةُ تحديثٍ صامتةٌ أو سطرٌ في نصِّ نشرٍ يُطفئ تصعيدَ الاعتمادات على الإنتاج، والسجلُّ يقول للمدقّق إنّ التصعيدَ «ثابتٌ لا يُطفأ».

> **يُنصَف الكودُ في شيء:** الإطفاءُ نفسُه يمرّ بـ`Settings::put` فيترك قيدَ تدقيقٍ وصفَّ تاريخ. فالأثرُ موجود — الكاذبُ هو **الحالةُ المعروضة**، لا السجلّ.

**الإصلاح (أحدُ اثنين، لا ثالث):**
1. **صدقُ الثابت:** يُقيَّد المفتاحان بالاختبار — `if (! app()->runningUnitTests() && setting(...) !== '1') { /* تجاهل */ }` — فيعود الثابتُ ثابتاً؛ **أو**
2. **صدقُ السجلّ:** تُنزَع `security_class => 'invariant'` وتُعلَن القدرةُ بحدودها الحقيقيّة، ويُذكر في `limitations` أنّ سطحَي «التشغيل» و«الاعتمادات» يُطفآن بمفتاحٍ داخليّ.

**اختبارُ الانحدار:** في `FeatureRegistryIntegrityTest`: لكلِّ قدرةٍ `SYSTEM_INVARIANT`، **لا يوجد** مفتاحُ إعدادٍ (معروضٌ أو داخليّ) يُطفئ حارسَها — يُحرَس بمسحٍ ساكنٍ على `app/` يربط كلَّ `setting('…')` داخل دالّةِ حارسٍ ثابتٍ بقدرةٍ ثابتة.

---

### A16-03 | 🔴 عالية | حفظُ مفتاحٍ أمنيٍّ بلا تصعيدِ هويّة — بينما **استعادةُ افتراضيّه** تطلبه
**المسار:** `POST /admin/settings` (`settings.update`) · **الثقة: عالية (مُثبَت)**

التصعيدُ (`hub_require_stepup`) مطلوبٌ في ثلاثة مواضعَ من مركز الإعدادات: **الاستعادة** (`SettingController:299`)، و**تطبيقُ الاستيراد** (`:550`)، و**تبديلُ قدرةٍ** ذاتِ مفتاحٍ خطر (`FeatureController:113`). وليس مطلوباً في الموضع الرابع — **الحفظُ العاديّ**.

ووسيطُ المسار (من `route:list` الحيّ):

```
settings.update    admin/settings          [web, auth]            ← لا تصعيد ولا throttle
settings.restore   admin/settings/restore  [web, auth, throttle:30,1]
```

**الدليل** (مسبارٌ شغّلتُه بجلسةِ مالك، بلا أيِّ تصعيد):
```
2fa='0'   trusted='0.0.0.0/0'   pw_min='0'
```
أي: أُطفئ **إلزامُ التحقّق بخطوتين للمميَّزين**، وفُتحت قائمةُ العناوين الموثوقة للعالم، وأُنزلت أرضيّةُ كلمة المرور — بطلبٍ واحدٍ من جلسةٍ مسروقة.

والمفارقةُ أنّ الكودَ نفسَه يُعلّل التصعيدَ في الاستعادة بأنها «فعلٌ نادرٌ عريضُ الأثر». وإعادةُ `auth.pw_min` إلى **١٠** تطلب إثباتَ هويّة، وإنزالُها إلى **٠** لا يطلب. الحارسُ موضوعٌ على الاتجاه الأقلِّ خطراً.

**الإصلاح:** في `SettingController::update` بعد بناء `$map` وقبل `Settings::batch`:
```php
$plan = Settings::diff($map);
if (array_filter($plan, fn ($r) => $r['risky']) && ($stop = hub_require_stepup())) return $stop;
```
(المعاينةُ تحسب `risky` أصلاً — الأداةُ حاضرةٌ ولم تُستعمل.) ويُضاف `throttle:30,1` على المسار أسوةً بأخواته.

**اختبارُ الانحدار:** مالكٌ بلا تصعيدٍ حديثٍ يُرسل `security_trusted_ips` ⇒ يُعاد إلى شاشة التصعيد، و`setting('security.trusted_ips')` **لم تتغيّر**؛ ومفتاحٌ غيرُ خطرٍ (`app_currency`) يُحفظ بلا تصعيد.

---

### A16-04 | 🟠 متوسطة-عالية | «الأرضياتُ الحقيقيّةُ من الشيفرة» تغطّي ٨ من ٥٤ — فالشاشةُ تعرض قيمةً لا تسري
**المفاتيح:** ٣١ معروضاً + ١٧ داخليّاً · **الثقة: عالية (مُثبَت)**

ترويسةُ `Settings` تَعِد: «وتقرأ **الأرضياتِ الحقيقية من الشيفرة** فتقول من فرض الحدّ». وآليّةُ التنفيذ — `Settings::applyFloor` — لا تقرأ الشيفرةَ إطلاقاً؛ تقرأ `validation.min/max` **المُعلَنَ في الكتالوج**. والكتالوجُ يُعلنه لثمانيةِ مفاتيح، بينما الشيفرةُ تفرض أرضيّةً على **٥٤** (نمط `max(N, (int) setting('…'))`).

**الدليل:**
```
auth.max_fail  effective='0'  imposed=NULL      ← والقارئُ AccountLockout::bump يستعمل max(1, 0) = 1
auth.lock_min  effective='0'  imposed=NULL      ← والقارئُ يستعمل max(1, 0) = 1
```

ومعها ينهار **نصُّ الخطر المكتوب في الكتالوج نفسِه** لـ`auth.lock_min`: «‏«0» تُبطل الحارس كلّه بصمت: وقت القفل يساوي اللحظة نفسها فلا يُعدّ قفلاً». هذا لم يعد صحيحاً — القفلُ دقيقةٌ واحدة. فالمشغّلُ يقرأ تحذيراً من خطرٍ غيرِ قائم، ويثق بشاشةٍ تقول «الساري: 0».

ومعاينةُ §18 تحمل العيبَ نفسَه: `Settings::diff()` تعرض `A → B` بالقيمةِ المخزَّنة لا بالسارية، فتَعِد بـ«0» وتقع «1».

**الأثرُ الأوسع:** ٣١ مفتاحاً معروضاً (منها `risk.band_*` و`security.stepup_minutes` و`security.autoblock_threshold` و`security.idle_days_*` و`endpoint.enroll_ttl_min` و`portal.activation_ttl_min`) تعرض في الشاشة قيمةً قد لا تكون هي السارية، بلا أيِّ سطرِ «الأرضيةُ المفروضة».

**الإصلاح:** تُعلَن `validation.min` (و`by`) للمفاتيح الـ٣١؛ والأصلبُ منه: تُستخرج الأرضياتُ إلى ثابتٍ واحد (`Settings::FLOORS`) يقرؤه القارئُ و`applyFloor` معاً، فلا يُكتب الرقمُ مرّتين.
**اختبارُ الانحدار:** مسحٌ ساكنٌ على `app/` يلتقط كلَّ `max(<عدد>, … setting('k'…)` ويشترط لكلِّ مفتاحٍ **معروضٍ** منها `validation.min` مساوياً — على غرار المسحِ الساكن القائم في `SettingsModelTest` على `CoreSeeder`.

---

### A16-05 | 🟠 متوسطة | حارسُ SSRF ليس «عاليَ الخطورة» — فلا وسمَ في المعاينة ولا تصعيدَ في الاستعادة
**المفتاح:** `monitor.allow_private` (ومعه `audit.retention_*` · `quoteflow.pass` · `endpoint.enroll_ttl_min` · `portal.activation_ttl_min` · `identity.providers` · `finance.accounts`) · **الثقة: عالية (مُثبَت)**

تصنيفُ الخطر مُشتقٌّ من نمطٍ نصّيٍّ واحد:
```php
const HIGH_RISK_RE = '/security\.|auth\.|sec\.|api\.token|risk\.|2fa|maintenance\.|mail\.|odoo\.|files\.max_kb|cost\.work_/u';
```
فـ٤٧ مفتاحاً من ١١٤ «عالي الخطورة» — والاختيارُ بالاسم لا بالأثر. و`monitor.allow_private` لا يبدأ بـ`security.` فيسقط، مع أنّ الكتالوجَ يصفه بنفسه: «يُبطل حارس SSRF فيصير النظام مِجَسّاً على شبكتك الداخلية».

**الدليل:**
```
monitor.allow_private   isHighRisk=لا
معاينةُ تعطيل حارس SSRF: risky=false
استعادةُ المفتاح: 302 → بلا أيِّ تصعيد
```
فالمفتاحُ الذي يفتح الشبكةَ الداخليّةَ للمِجَسّ يمرُّ في المعاينةِ سطراً عادياً بين خمسةٍ وتسعين، بلا الوسمِ الأحمر الذي يناله `mail.from_name`.

> يُنصَف الكودُ: `SecurityPosture::ssrf()` **يرصده** ويرفع بطاقةً حمراء في وضعيّة الأمان. فالنظامُ يعرف أنّه خطر — في موضعٍ واحدٍ ولا يعرفه في الآخر.

**الإصلاح:** يُشتقُّ الخطرُ من الكتالوج لا من الاسم: `isHighRisk($k) = preg_match(RE, $k) || trim(entry($k)['risk'] ?? '') !== ''` — فكلُّ مفتاحٍ وُثِّق خطرُه يُعامَل خطراً، ويصير الحقلُ `risk` ذا أثرٍ بدل أن يكون نثراً.
**اختبارُ الانحدار:** لكلِّ مفتاحٍ في الكتالوج بنصِّ `risk` غيرِ فارغ ⇒ `Settings::isHighRisk($k) === true`، و`Settings::diff([$k => …])[0]['risky'] === true`.

---

### A16-06 | 🟠 متوسطة | الكاتبُ الواحد لا يرفض إلّا بـ`re`: ١٠٣ من ١١٤ مفتاحاً بلا أيِّ تحقّق
**النطاق:** كلُّ الكتالوج · **الثقة: عالية (مُثبَت)**

`Settings::validate()` — «قاعدةُ الرفض من الكتالوج، **المصدرُ الواحد** لكل الأبواب» — تقرأ حقلاً واحداً: `validation.re`. وفي الكتالوج **١١** مفتاحاً تحمله. أمّا:

| ما يُعلَن | كم | من يُنفّذه |
|---|---|---|
| `validation.re` | ١١ | `Settings::validate` ✔ |
| `validation.min/max` | ٨ | **لا أحد عند الكتابة** — `applyFloor` عرضٌ فقط، والقارئُ يقصُّ |
| `validation.in` | ١ | **لا أحد** — لا عند الكتابة ولا في العرض |
| نوعُ `ta` (JSON) | ٣ | **شاشةُ الإعدادات وحدَها** (`SettingController::intended`) — لا الكاتب |
| نوعُ `number` | ٦٠ | لا أحد (٥٢ منها بلا `validation` إطلاقاً) |

**الدليل:**
```
Settings::validate('work.missing_report_policy','strict')     → null   (القاعدةُ in لا ترفض)
effective = 'strict'   imposed = NULL   |   السارية فعلاً: absence_equivalent
Settings::validate('finance.accounts','ليس JSON')             → null   (الكاتبُ لا يفحص ta)
finance.accounts stored = 'ليس JSON'
```

فالسياسةُ المكتوبة `strict` تُقبَل وتُعرَض «سارية»، والنظامُ يعامل كلَّ غائبٍ بلا تقريرٍ معاملةَ **الغياب الكامل** — وهي أقسى السياسات الثلاث. والمشغّلُ يظنّ أنّه اختار الأخفّ.

وفحصُ JSON في الشاشةِ وحدَها يعني أنّ **الاستيراد** و`hub:set` بابان يكتبان JSON فاسداً في `finance.accounts` و`contracts.approval_steps` — و`importPlan` لا يستدعي إلّا `Settings::validate`.

**الإصلاح:** يُوسَّع `Settings::validate` ليغطّي ما يُعلنه الكتالوج فعلاً: `in` (رفضٌ صريح)، و`type === 'ta'` (‏`json_decode` غيرُ null)، و`type === 'number'` (`is_numeric`)، و`min/max` (رفضٌ أو رفعٌ صريحٌ برسالة). فتصير الجملةُ «المصدرُ الواحد» صحيحةً لا موعودة.
**اختبارُ الانحدار:** مسحٌ على الكتالوج: لكلِّ مفتاحٍ يحمل `validation.in` ⇒ `validate($k,'قيمةٌ خارج القائمة') !== null`؛ ولكلِّ `type==='ta'` ⇒ `validate($k,'ليس JSON') !== null`؛ ولكلِّ `type==='number'` ⇒ `validate($k,'abc') !== null`.

---

### A16-07 | 🟠 متوسطة | `depends` تُعلَن لثلاثَ عشرةَ مجموعةً ويُتحقَّق من خمس
**النطاق:** ٤٧ مفتاحاً · **الثقة: عالية**

ترويسةُ الكتالوج تقول: «`depends` اسمُ مجموعةٍ **تُتحقَّق مجتمعةً**». والمجموعاتُ المُعلَنةُ على المفاتيح ثلاثَ عشرة، والمُنفَّذةُ في `Settings::DEPENDS` خمس:

| المجموعة | المفاتيح | مُتحقَّقة؟ |
|---|---|---|
| `mail` | ٧ | ✔ |
| `odoo` | ٤ | ✔ |
| `risk_bands` | ٣ | ✔ |
| `work_hours` | ١٢ **مُعلَنة** | ✔ **على ٣ منها فقط** (`sec.hours_start/hours_end/strict_from`) |
| `telegram` | — | ✔ (مُنفَّذةٌ بلا إعلانٍ في الكتالوج) |
| `idle_tiers` | ٣ | ✘ |
| `discovery` | ٣ | ✘ |
| `ops_cpu` · `ops_mem` · `ops_disk` · `ops_queue` · `ops_regression` | ١٠ | ✘ |
| `slo` | ٥ | ✘ |
| `finance` | ٢ | ✘ |

فـ`ops.cpu_warn = 90` مع `ops.cpu_crit = 60` تُحفَظ بلا اعتراض (تنبيهٌ «تحذير» لا يسبق «حرج» — سلّمٌ مقلوب)، و`security.idle_days_1/2/3` تُحفَظ غيرَ مرتَّبة (درجاتُ خمولٍ ميّتة) — وهو بالضبط العيبُ الذي يحرسه `risk_bands` لنفسه. القاعدةُ صحيحةٌ ومطبَّقةٌ على ثلاثِ مفاتيحَ ومتروكةٌ على عشرين.

**الإصلاح:** تُنفَّذ المجموعاتُ الغائبة (كلُّها قواعدُ ترتيبٍ بسيطة: `warn < crit`، `idle_1 < idle_2 < idle_3`، «نافذةُ SLO مع هدفٍ واحدٍ على الأقل»)، **أو** يُنزَع وسمُ `depends` عمّا لا يُتحقَّق — فالوسمُ الذي لا يفعل شيئاً يُطمئن المراجعَ بلا سبب.
**اختبارُ الانحدار:** لكلِّ قيمةِ `depends` في الكتالوج ⇒ `array_key_exists($g, Settings::DEPENDS)`، وكلُّ مفاتيح المجموعة في الكتالوج مذكورةٌ في `DEPENDS[$g]['keys']`.

---

### A16-08 | 🟡 متوسطة-منخفضة | `EXTERNAL` سترٌ لنقص: جسرُ Intune/Jamf مؤجَّلٌ في الكود ومُعلَنٌ «خارجيّاً» في السجلّ
**القدرات:** `endpoint.intune` · `endpoint.jamf` · **الثقة: عالية**

الحالتان `EXTERNAL` بسببٍ يقول: «تعتمد أساساً على مزوّدٍ خارجيّ: Microsoft Intune»، والوصفُ «اعتمادٌ خارجيّ — **لا اعتماداتٍ مُهيَّأة**». يقرأ المشغّلُ من هذا: «امنحني اعتماداتٍ أعمل». والحقيقةُ في `app/Support/Mdm/RemoteMdmProvider.php` بلفظِها: «الجسرَ الحيَّ **مؤجَّلٌ صراحةً** (لا وصلَ Graph/Jamf API في هذا النظام)».

فالنقصُ ليس في اعتمادٍ ينقص، بل في **تنفيذٍ لم يُكتب** — وهذه حالةُ `DEFERRED` لا `EXTERNAL`. والفرقُ ليس لفظيّاً: `EXTERNAL` تُعدّ «متاحة» في بعض القراءات وتُقرأ وعداً قريباً، و`DEFERRED` تُعدّ غيرَ متاحةٍ ويحرسها `test_deferred_features_are_never_available_or_enabled`.

**الإصلاح:** إمّا `DEFERRED` مع `deferred_notes => 'DEFERRED_INTEGRATION — سيمُ المزوّد قائمٌ وجسرُ API الحيُّ غيرُ مكتوب'`، أو الإبقاءُ على `EXTERNAL` مع `limitations` صريحة: «رصدٌ فقط — لا جسرَ فرضٍ حيّ مهما اكتملت الاعتمادات».
**اختبارُ الانحدار:** لكلِّ قدرةٍ `EXTERNAL` ذاتِ مزوّدٍ في `app/Support/*` ⇒ يجب أن يكون لمزوّدها طريقُ عملٍ حيٌّ واحدٌ على الأقلّ غيرُ مُعطَّلٍ ثابتاً (أو `limitations` غيرُ فارغة).

---

### A16-09 | 🟡 منخفضة | خمسُ حالاتٍ **جامدة** لا فاحصَ يرفعها أبداً
**القدرات:** `endpoint.production_signing` · `endpoint.windows_signing` · `endpoint.macos_signing` · `endpoint.apple_notarization` · `telecom.live_provisioning` · **الثقة: عالية**

قاعدةُ الكتالوج في ترويسته: «الخارجيُّ/غيرُ المُهيَّأ **يُشتقُّ من فاحصٍ (`derive`) لا يُدّعى**». وهذه الخمسُ **مُدّعاةٌ ساكنةً** بلا `derive`. هي صادقةٌ اليوم (النظامُ لا يملك موضعاً لاعتماداتِ توقيعٍ أصلاً)، لكنّ `EndpointRelease` **يدعم** `signing_status = 'signed'` خلف إقرارٍ متحقَّق و`notarization_status = 'notarized'` — فمنشأةٌ توقّع إصداراتِها وتنشرها ستبقى ترى «التوقيعُ الإنتاجيُّ: غيرُ مُهيَّأ» إلى الأبد. الحالةُ ليست مشتقّةً، فهي لا تتعلّم.

**الإصلاح:** إمّا `derive` حقيقيٌّ (‏`EndpointRelease::whereSigningStatus('signed')->exists()` مثلاً)، وإمّا حقلٌ صريحٌ `static_reason` يقول «حالةٌ ثابتةٌ في هذا النطاق — لا فاحصَ يرفعها» فلا يظنّ القارئُ أنّها تتحرّك.
**اختبارُ الانحدار:** كلُّ قدرةٍ `NOT_CONFIGURED`/`EXTERNAL` إمّا لها `derive` وإمّا لها `limitations`/`deferred_notes` تشرح ثباتَها — لا ثالث.

---

### A16-10 | 🟡 منخفضة | فاحصٌ ليس فاحصاً · ومصدرا فحصٍ مذكوران لا يُستشاران · وفرعٌ ميّت
**القدرة:** `collab.websocket_provider` والسجلُّ عامّةً · **الثقة: عالية**

ثلاثةٌ في موضعٍ واحد:

1. **`derive => 'websocket'` ثابتٌ لا فاحص.** فرعُه في `deriveFromSource` يعيد `NOT_CONFIGURED` **بلا نظرٍ في أيِّ شيء** — لا إعدادَ ولا اتصالَ ولا `config()`. هو صادقٌ اليوم (لا `config/broadcasting.php` في المستودع أصلاً)، لكنّه يلبس ثوبَ الاشتقاق وليس منه.
2. **`MobilePlatform` و`Integrations` مذكوران في ترويسة `FeatureRegistry`** بوصفهما من «فاحصاتِ الاتصال/الحالةِ القائمة» — و`MobilePlatform` **لا يُستدعى في الصنف إطلاقاً**.
3. **فرعُ `integration:` شيفرةٌ ميّتة.** `deriveFromSource` يحمل معالجاً كاملاً لـ`integration:<key>` ودالّةً `fromIntegrationHealth()` بأربعِ حالات — و**صفرُ مدخلٍ في الكتالوج يستعملها** (`grep -c "integration:" config/hub_features.php` = 0). فصحّةُ أودو/تلجرام/البريد/n8n لا تبلغ سجلَّ القدرات: `platform.integrations` تبقى `ENABLED` وكلُّ تكاملٍ ساقط.

**الإصلاح:** يُوصَل `platform.integrations` (أو قدرةٌ لكلِّ تكامل) بـ`derive => 'integration:<key>'` فيحيا الفرعُ ويصدُق العرض؛ ويُصحَّح نصُّ الترويسة بما يُستشار فعلاً.
**اختبارُ الانحدار:** كلُّ فرعٍ في `deriveFromSource` يستعمله مدخلٌ واحدٌ على الأقلّ في الكتالوج (وإلّا فهو ميّت).

---

### A16-11 | 🟡 منخفضة | ٦٤ قدرةً من ١٣٧ حالتُها **غيرُ قابلةٍ للتكذيب**
**النطاق:** السجلُّ كلُّه · **الثقة: عالية**

٩٩ قدرةً (٧٢٪) لا تحمل في الكتالوج أيَّ مؤشّرٍ آليٍّ (لا `web_routes` ولا `api_routes` ولا `admin_surface` ولا `setting_key` ولا `derive` ولا `docs`)، و**٦٤** منها لا تحمل حتى `desc_ar`. مدخلُها كلُّه سطرٌ كهذا:

```php
'collab.threads' => ['domain' => 'collaboration', 'category' => 'messaging',
    'title_ar' => 'الخيوط', 'title_en' => 'Threads', 'status' => 'ENABLED', 'introduced' => 'v2.456.0'],
```

تتبّعتُ يدويّاً ٧٥ منها إلى شيفرةٍ حقيقيّة (وأدرجتُ الدليلَ في الجدول أدناه) فوجدتُها **قائمةً فعلاً** — الخيوطُ في `Comment::parent_id/bumpThread`، والمفضّلةُ في `ConversationMember::favorite_at`، والأرشفةُ في `Conversation::archived_at`، والعهدةُ غيرُ القابلة للتعديل في `EmployeeCustodyMove` (‏`updating`/`deleting` مقفولان + `reverse()`)… إلخ. **فالكتالوجُ ليس كاذباً هنا — لكنّه غيرُ محروس.**

وهذا هو بالضبط سببُ أنّ الادّعاءَ «٠ حالةٍ كاذبة» لا يعني شيئاً بصيغته الحاليّة: `FeatureRegistryIntegrityTest` يفحص **بنيةَ** الكتالوج (مفاتيحُ فريدة · مجالاتٌ معروفة · دوراتُ اعتماد · مفاتيحُ إعدادٍ غيرُ يتيمة) ولا يفحص في أيِّ موضعٍ أنّ قدرةً `ENABLED` موصولةٌ بشيفرةٍ حيّة. فـ«صفرُ مشاكل» هنا تعني «الكتالوجُ متّسقٌ مع نفسه»، لا «الكتالوجُ صادقٌ عن النظام».

وفي الجرد ازدواجٌ واحدٌ صريح: **`telecom.sim_registry` و`telecom.phone_registry` قدرتان لنموذجٍ واحد** (`PhoneNumber` — «أصلُ الاتصالات (SIM/eSIM/الخط)»)، فالعدُّ ١٣٧ يحمل سطحاً محسوباً مرّتين.

**الإصلاح:** يُشترط لكلِّ مدخلٍ **مرساةٌ واحدةٌ آليّةٌ على الأقل** — مسارٌ أو شاشةٌ أو صلاحيةٌ أو `code_anchor` جديد (`ClassName::method` أو `table:column`) — يفحصها اختبارُ النزاهة بوجودِ الملفّ/المسار. لا يلزم توثيقُ كلِّ شيء؛ يلزم أن يكون كلُّ ادّعاءٍ **قابلاً للتكذيب**.
**اختبارُ الانحدار:** `FeatureRegistryIntegrityTest`: كلُّ قدرةٍ `ENABLED` تحمل مرساةً واحدةً موجودةً فعلاً (مسارٌ مسجَّل · صنفٌ موجود · عمودٌ موجود)، وإلّا فالكتالوجُ ذو مشكلة.

---

### A16-12 | 🟡 منخفضة | `custom.fields` يُكتب خارج الكاتب الواحد — وبلا أيِّ أثرٍ في التدقيق
**المفتاح:** `custom.fields` · `custom.fields_seq` · **الثقة: عالية**

`CustomFieldController` يكتب مباشرةً:
```php
protected function save(array $all): void {
    Setting::updateOrCreate(['key' => 'custom.fields'], ['value' => $all]);
    Cache::forget('settings:all');
}
```
و`grep -c "hub_audit" app/Http/Controllers/Web/CustomFieldController.php` = **0**.

فإضافةُ حقلٍ مخصَّصٍ أو حذفُه — وهو فعلٌ يغيّر نماذجَ كلِّ وحدةٍ ويُحدّد كيف تُقرأ بياناتُ `custom` في كلِّ سجلّ — لا يترك **قيدَ تدقيقٍ واحداً** ولا صفّاً في `setting_changes`. لا «من» ولا «متى» ولا «ماذا كان قبله». وسؤالُ المدقّق «من أضاف حقلَ رقم الملفّ الضريبيّ ومتى؟» بلا جواب.

> يُنصَف الكودُ: إبطالُ الخبيئة حاضرٌ (وهو ما كان يُنسى تاريخيّاً)، والفعلُ للمالك وحدَه (`hub_is_owner`). العيبُ في الأثر لا في الصلاحية.

**الإصلاح:** يُلفّ الحفظُ في `Settings::batch('screen', fn () => Settings::put('custom.fields', $all, 'screen', 'إضافة/حذف حقلٍ مخصَّص'), ['action' => 'تعديل الحقول المخصّصة', 'module' => 'settings', 'name' => $module])` — فيرث تلقائياً الأثرَ والتاريخَ والإبطال.
**اختبارُ الانحدار:** بعد `POST /admin/fields` يوجد قيدُ تدقيقٍ واحدٌ يحمل اسمَ الوحدةِ والحقل، وصفٌّ في `setting_changes` لمفتاح `custom.fields`.

---

### A16-13 | 🟢 منخفضة | مفتاحٌ مُعلَنٌ بلا قارئ · وصفٌّ شبحيٌّ يُبذر في كلِّ تنصيب
**المفاتيح:** `ops.http_p95_ms` · `notify.quiet` · **الثقة: عالية**

- **`ops.http_p95_ms`** مُعلَنٌ في `internal` بلا قارئٍ واحد في `app/` — والإعلانُ **يقرّ بذلك** («…ويبدأ قارئُه مع حاويات `http_metric_buckets`»). صدقٌ في النصّ، لكنّه مفتاحٌ قائمٌ في الجرد وفي عدّادِ «الداخليّ» بلا أثر.
- **`notify.quiet`** أسوأُ نوعاً: يبذره `CoreSeeder` في جدول `settings` على **كلِّ تنصيبٍ جديد** بقيمةِ `{"on":false,"from":22,"to":7}`، وهو مُدرَجٌ في `Settings::SEEDED`، و**ليس في الكتالوج ولا في `internal`**، ولا يقرؤه سطرٌ واحد. صفٌّ يَعِد بـ«ساعاتِ هدوءٍ للإشعارات» لا وجودَ لها.

وسببُ نجاتهما بنيويّ: حارسُ `SettingsCenterTest::test_no_exposed_key_is_dead` يفحص **المعروضَ فقط** — فالداخليُّ الميّتُ والمبذورُ الميّتُ خارجَ مرماه.

**الإصلاح:** يُنزَع `notify.quiet` من `CoreSeeder` و`Settings::SEEDED` (أو يُكتب قارئُه)، ويُوسَّع الحارسُ ليشمل الداخليَّ مع قائمةِ استثناءٍ **صريحةٍ ومُعلَّلة** للمفاتيح التي يسبق إعلانُها قارئَها.
**اختبارُ الانحدار:** كلُّ مفتاحٍ داخليٍّ له قارئٌ في `app/` (نصّاً أو عبر عائلةٍ ديناميّةٍ مُعلَنة)، وكلُّ مفتاحٍ في `Settings::SEEDED` له مدخلٌ في الكتالوج.

---

### A16-14 | 🟢 منخفضة | «تحقّقٌ من الكتالوج» — والكاتبُ يقبل أيَّ مفتاحٍ يُخترَع
**النطاق:** `Settings::put` · `hub:set` · **الثقة: عالية (مُثبَت)**

ترويسةُ `Settings::commit` تصف السلسلةَ: «**تحقّقٌ من الكتالوج** → تشفيرُ الحسّاس → كتابة → …». والتحقّقُ الفعليُّ هو `validate()`، وهي تعود `null` لأيِّ مفتاحٍ لا مدخلَ له.

**الدليل:** `Settings::put('totally.made.up.key','x','cli')` ⇒ الصفُّ مكتوبٌ و`setting('totally.made.up.key') === 'x'`.

الأثرُ عمليّاً محدود (الكاتبُ للمالك/الطرفيّة، والاستيرادُ محصورٌ بـ`exportableKeys`)، لكنّه يفتح بابَ تلوّثِ الجدول بمفاتيحَ لا يقرؤها أحد — وهو نفسُ المرضِ الذي يحاربه A16-13، ويجعل جملةَ الترويسة أدقَّ ممّا تفعل.

**الإصلاح:** ترفض `put` مفتاحاً ليس له مدخلٌ معروضٌ ولا داخليٌّ ولا يطابق بادئةَ حالةٍ مُعلَنة — برسالةٍ تقول «أعلِنه في الكتالوج أوّلاً».
**اختبارُ الانحدار:** `Settings::put('totally.made.up.key', 'x', 'cli')` يرمي `InvalidArgumentException`.

---

### A16-15 | 🟢 منخفضة | مؤشّراتُ `where` في الكتالوج تقادمت عن مواضعِها
**النطاق:** ٥ مفاتيحَ مؤكَّدة من ١٠٦ مفحوصة · **الثقة: عالية**

ترويسةُ الكتالوج تقول: «وكل قيمةٍ هنا **مقروءةٌ من الشيفرة** لا مكتوبةٌ بالتخمين». وفحصتُ آلياً «القارئَ المباشر» المُعلَن في `where` (ما قبل السهم `←`) مقابل الملفّ الذي يحوي المفتاح فعلاً:

| المفتاح | `where` يقول | القارئُ الحقيقيّ |
|---|---|---|
| `auth.max_fail` | `AuthController::login:52` | `app/Support/AccountLockout.php:32` |
| `auth.lock_min` | `AuthController::login:53` | `app/Support/AccountLockout.php:33` |
| `security.token_unused_days` | `SecurityPosture::apiStaleParts` | `app/Support/ApiTokens.php:43` |
| `finance.auto_journal` | `FinController::pay:66` | `app/Support/JournalPostingService.php:37` |
| `sec.hours_start` · `sec.strict_from` | `Middleware/WorkHours::handle:30,31` | `app/Support/helpers.php::hub_off_hours:1212-1213` |

وأولُ سطرين هما الأثقلُ لأنّ **نصَّ الخطر تقادم معهما** (انظر A16-04): الكتالوجُ يحذّر من خطرٍ أبطلته الشيفرةُ حين نقلت القراءةَ إلى قارئٍ يقصّ.

**الإصلاح:** أرقامُ الأسطر تُحذف (مدخلُ `odoo.url` يفعل ذلك بوعيٍ: «وأرقامُ الأسطر لا تُذكر فلا تتقادم»)، ويُستعاض عنها بـ`Class::method` تُفحَص آليّاً.
**اختبارُ الانحدار:** مسحٌ ساكن: أوّلُ رمزٍ في `where` (قبل `←`) يجب أن يكون ملفّاً/صنفاً يحوي `setting('<key>'` فعلاً.

---

## ٣) الجدولُ الكامل — الإعدادات (١١٤ معروضاً + ٦٥ داخليّاً)

> «مواضعُ القراءة الفعليّة» مُستخرجةٌ آليّاً بمسحِ `app/` · `routes/` · `resources/` · `database/` عن `setting('<key>'` (وعن أبواب `Settings::`/`updateOrCreate` للكتابة). «أيغيّر السلوك؟» = **وُجد قارئٌ فعليّ** — وهو شرطٌ ضروريٌّ لا كافٍ؛ الملاحظاتُ أعلاه تُفصّل أين يكون القارئُ موجوداً والقيمةُ المعروضةُ غيرَ السارية (A16-04 · A16-06).

| # | المفتاح | المجموعة | النوع | الافتراضيّ | شاشةُ التحرير | مواضعُ القراءة الفعليّة | أيغيّر السلوك؟ | تحقّقٌ خادميّ | عالي الخطورة (تدقيق/تصعيد) | ملاحظة |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | `app.name` | 🏷️ الهوية والعرض | text | «فارغ» | إعدادات | `app/Models/AccountActivation.php`<br>`app/Support/OpenApi.php`<br>`app/Support/Proposal.php`<br>`app/Support/ChangeOrderDoc.php`<br>+25 | نعم | — | لا | بيئة: `APP_NAME` |
| 2 | `app.company` | 🏷️ الهوية والعرض | text | «فارغ» | إعدادات | `app/Support/Proposal.php`<br>`app/Support/ChangeOrderDoc.php`<br>`app/Http/Controllers/Web/IdentityController.php`<br>`app/Http/Controllers/Web/CustodyController.php`<br>+2 | نعم | — | لا | — |
| 3 | `app.currency` | 🏷️ الهوية والعرض | text | `'د.ك'` | إعدادات | `app/Support/AssetLife.php`<br>`app/Support/SalesBoard.php`<br>`app/Support/CeoBoard.php`<br>`app/Support/helpers.php`<br>+24 | نعم | — | لا | — |
| 4 | `app.logo` | 🏷️ الهوية والعرض | img | «فارغ» | إعدادات | `app/Support/Proposal.php`<br>`app/Support/ChangeOrderDoc.php`<br>`app/Http/Controllers/Web/PurchaseController.php`<br>`app/Http/Controllers/Web/CustodyController.php`<br>+6 | نعم | — | لا | — |
| 5 | `app.color` | 🏷️ الهوية والعرض | color | `'#0E7C66'` | إعدادات | `app/Support/Proposal.php`<br>`app/Support/ChangeOrderDoc.php`<br>`app/Support/helpers.php`<br>`app/Http/Controllers/Web/PwaController.php`<br>+2 | نعم | `re` (مُنفَّذ) | لا | — |
| 6 | `auth.pw_min` | 🔐 الدخول والأمان | number | `10` | إعدادات | `app/Support/helpers.php`<br>`resources/views/auth/activate_set.blade.php`<br>`resources/views/profile/portal.blade.php`<br>`resources/views/profile.blade.php` | نعم | — | نعم | — |
| 7 | `auth.max_fail` | 🔐 الدخول والأمان | number | `5` | إعدادات | `app/Support/AccountLockout.php` | نعم | — | نعم | **أرضيةُ كودٍ غيرُ معلنة** |
| 8 | `auth.lock_min` | 🔐 الدخول والأمان | number | `15` | إعدادات | `app/Support/AccountLockout.php` | نعم | — | نعم | **أرضيةُ كودٍ غيرُ معلنة** |
| 9 | `portal.activation_ttl_min` | 🔐 الدخول والأمان | number | `60` | إعدادات | `app/Models/AccountActivation.php` | نعم | — | لا | **أرضيةُ كودٍ غيرُ معلنة** |
| 10 | `auth.session_min` | 🔐 الدخول والأمان | number | `0` | إعدادات | `app/Providers/AppServiceProvider.php` | نعم | — | نعم | بيئة: `SESSION_LIFETIME` |
| 11 | `approval.rules` | 🔐 الدخول والأمان | text | «فارغ» | إعدادات | `app/Support/helpers.php` | نعم | — | لا | — |
| 12 | `api.token_max_days` | 🔐 الدخول والأمان | number | `365` | إعدادات | `app/Http/Controllers/Web/ProfileController.php` | نعم | — | نعم | — |
| 13 | `security.stepup_minutes` | 🔐 الدخول والأمان | number | `10` | إعدادات | `app/Support/StepUp.php`<br>`app/Http/Controllers/Api/MobileAuthController.php` | نعم | — | نعم | **أرضيةُ كودٍ غيرُ معلنة** |
| 14 | `security.stepup_secrets` | 🔐 الدخول والأمان | onoff | `'0'` | إعدادات | `app/Http/Controllers/Web/ModuleController.php` | نعم | — | نعم | — |
| 15 | `auth.passkeys_on` | 🔐 الدخول والأمان | onoff | `'1'` | إعدادات | `app/Http/Controllers/Web/StepUpController.php`<br>`app/Http/Controllers/Web/PasskeyController.php`<br>`app/Http/Controllers/Web/MySecurityController.php`<br>`resources/views/auth/login.blade.php`<br>+1 | نعم | — | نعم | — |
| 16 | `auth.2fa_required_priv` | 🔐 الدخول والأمان | onoff | `'0'` | إعدادات | `app/Http/Middleware/Require2faForPrivileged.php` | نعم | — | نعم | — |
| 17 | `risk.band_medium` | 🔐 الدخول والأمان | number | `30` | إعدادات | `app/Support/Risk.php` | نعم | — | نعم | **أرضيةُ كودٍ غيرُ معلنة** · مجموعة `risk_bands` |
| 18 | `risk.band_high` | 🔐 الدخول والأمان | number | `60` | إعدادات | `app/Support/Risk.php` | نعم | — | نعم | **أرضيةُ كودٍ غيرُ معلنة** · مجموعة `risk_bands` |
| 19 | `risk.band_critical` | 🔐 الدخول والأمان | number | `80` | إعدادات | `app/Support/Risk.php` | نعم | — | نعم | **أرضيةُ كودٍ غيرُ معلنة** · مجموعة `risk_bands` |
| 20 | `security.export_stepup_rows` | 🔐 الدخول والأمان | number | `0` | إعدادات | `app/Http/Controllers/Web/ReportsController.php`<br>`app/Http/Controllers/Web/ModuleController.php` | نعم | — | نعم | — |
| 21 | `security.sessions_keep_days` | 🔐 الدخول والأمان | number | `180` | إعدادات | `app/Console/Commands/HubAutomation.php` | نعم | أرضية/سقف **عرضٌ فقط** | نعم | — |
| 22 | `field.points_keep_days` | 🔐 الدخول والأمان | number | `90` | إعدادات | `app/Support/Tracking.php` | نعم | أرضية/سقف **عرضٌ فقط** | لا | — |
| 23 | `security.ip_keep_days` | 🔐 الدخول والأمان | number | `365` | إعدادات | `app/Console/Commands/HubAutomation.php` | نعم | أرضية/سقف **عرضٌ فقط** | نعم | — |
| 24 | `security.idle_days_1` | 🔐 الدخول والأمان | number | `30` | إعدادات | `app/Support/SecurityPosture.php` | نعم | — | نعم | **أرضيةُ كودٍ غيرُ معلنة** · مجموعة `idle_tiers` (**بلا تحقّق**) |
| 25 | `security.idle_days_2` | 🔐 الدخول والأمان | number | `60` | إعدادات | `app/Support/SecurityPosture.php` | نعم | — | نعم | **أرضيةُ كودٍ غيرُ معلنة** · مجموعة `idle_tiers` (**بلا تحقّق**) |
| 26 | `security.idle_days_3` | 🔐 الدخول والأمان | number | `90` | إعدادات | `app/Support/SecurityPosture.php` | نعم | — | نعم | **أرضيةُ كودٍ غيرُ معلنة** · مجموعة `idle_tiers` (**بلا تحقّق**) |
| 27 | `security.secret_stale_days` | 🔐 الدخول والأمان | number | `180` | إعدادات | `app/Support/SecurityPosture.php`<br>`app/Http/Controllers/Web/SecurityController.php` | نعم | — | نعم | **أرضيةُ كودٍ غيرُ معلنة** |
| 28 | `security.token_unused_days` | 🔐 الدخول والأمان | number | `90` | إعدادات | `app/Support/ApiTokens.php` | نعم | — | نعم | **أرضيةُ كودٍ غيرُ معلنة** |
| 29 | `security.autoblock_enabled` | 🔐 الدخول والأمان | onoff | `'0'` | إعدادات | `app/Support/AlertEngine.php` | نعم | — | نعم | — |
| 30 | `security.autoblock_threshold` | 🔐 الدخول والأمان | number | `10` | إعدادات | `app/Support/AlertEngine.php` | نعم | — | نعم | **أرضيةُ كودٍ غيرُ معلنة** |
| 31 | `security.autoblock_window_min` | 🔐 الدخول والأمان | number | `60` | إعدادات | `app/Support/AlertEngine.php` | نعم | — | نعم | **أرضيةُ كودٍ غيرُ معلنة** |
| 32 | `security.autoblock_steps` | 🔐 الدخول والأمان | text | `'15,60,1440'` | إعدادات | `app/Support/AlertEngine.php` | نعم | `re` (مُنفَّذ) | نعم | — |
| 33 | `security.trusted_ips` | 🔐 الدخول والأمان | text | «فارغ» | إعدادات | `app/Support/AlertEngine.php`<br>`app/Http/Controllers/Web/SecurityController.php`<br>`app/Http/Middleware/IpDefense.php` | نعم | — | نعم | — |
| 34 | `audit.retention_days` | 🔐 الدخول والأمان | number | `0` | إعدادات | `app/Http/Controllers/Web/AuditController.php` | نعم | — | لا | — |
| 35 | `audit.retention_policy` | 🔐 الدخول والأمان | ta | «فارغ» | إعدادات | `app/Http/Controllers/Web/AuditController.php` | نعم | — | لا | — |
| 36 | `sec.hours_on` | 🕗 ساعات العمل والقدرات | onoff | `'1'` | إعدادات | `app/Support/helpers.php`<br>`app/Http/Middleware/WorkHours.php` | نعم | — | نعم | مجموعة `work_hours` |
| 37 | `sec.hours_start` | 🕗 ساعات العمل والقدرات | text | `'08:00'` | إعدادات | `app/Support/Workday.php`<br>`app/Support/LoginSentry.php`<br>`app/Support/helpers.php`<br>`app/Support/DailyWorkCompliance.php`<br>+3 | نعم | `re` (مُنفَّذ) | نعم | مجموعة `work_hours` |
| 38 | `sec.hours_end` | 🕗 ساعات العمل والقدرات | text | `'16:00'` | إعدادات | `app/Support/LoginSentry.php`<br>`app/Support/Risk.php`<br>`app/Http/Controllers/Web/AuditController.php`<br>`app/Http/Controllers/Web/ActivityController.php` | نعم | `re` (مُنفَّذ) | نعم | مجموعة `work_hours` |
| 39 | `sec.strict_from` | 🕗 ساعات العمل والقدرات | text | `'17:00'` | إعدادات | `app/Support/helpers.php` | نعم | `re` (مُنفَّذ) | نعم | مجموعة `work_hours` |
| 40 | `sec.strict_minutes` | 🕗 ساعات العمل والقدرات | number | `10` | إعدادات | `app/Http/Middleware/WorkHours.php` | نعم | — | نعم | **أرضيةُ كودٍ غيرُ معلنة** · مجموعة `work_hours` |
| 41 | `sec.strict_files` | 🕗 ساعات العمل والقدرات | onoff | `'1'` | إعدادات | `app/Support/helpers.php`<br>`app/Http/Middleware/WorkHours.php` | نعم | — | نعم | مجموعة `work_hours` |
| 42 | `cost.work_hours` | 🕗 ساعات العمل والقدرات | number | `8` | إعدادات | `app/Support/helpers.php` | نعم | — | نعم | **أرضيةُ كودٍ غيرُ معلنة** |
| 43 | `cost.work_days` | 🕗 ساعات العمل والقدرات | number | `22` | إعدادات | `app/Support/helpers.php` | نعم | — | نعم | **أرضيةُ كودٍ غيرُ معلنة** |
| 44 | `cost.weekend` | 🕗 ساعات العمل والقدرات | text | `'5,6'` | إعدادات | `app/Support/Workday.php`<br>`app/Support/helpers.php`<br>`app/Support/MonthlyAttendance.php` | نعم | `re` (مُنفَّذ) | لا | — |
| 45 | `contracts.doc_no_format` | 📜 العقود والتوقيع | text | `'CTR-{YEAR}-{SEQ}'` | إعدادات | `app/Models/Contract.php` | نعم | `re` (مُنفَّذ) | لا | — |
| 46 | `quotes.doc_no_format` | 📜 العقود والتوقيع | text | `'QT-{YEAR}-{SEQ}'` | إعدادات | `app/Models/Quote.php` | نعم | — | لا | — |
| 47 | `quotes.approve_amount` | 📜 العقود والتوقيع | number | `0` | إعدادات | `app/Http/Controllers/Web/QuoteController.php` | نعم | — | لا | — |
| 48 | `quotes.approve_discount` | 📜 العقود والتوقيع | number | `0` | إعدادات | `app/Http/Controllers/Web/QuoteController.php` | نعم | — | لا | — |
| 49 | `quotes.margin_floor` | 📜 العقود والتوقيع | number | `0` | إعدادات | `app/Models/Quote.php`<br>`app/Http/Controllers/Web/QuoteController.php`<br>`resources/views/modules/custom/quotes.blade.php` | نعم | — | لا | — |
| 50 | `contracts.approval_steps` | 📜 العقود والتوقيع | ta | «فارغ» | إعدادات | `app/Support/ContractApprovals.php` | نعم | — | لا | — |
| 51 | `contracts.auto_expire` | 📜 العقود والتوقيع | onoff | `'0'` | إعدادات | `app/Console/Commands/HubAutomation.php` | نعم | — | لا | — |
| 52 | `esign.link_days_default` | 📜 العقود والتوقيع | number | «فارغ» | إعدادات | `resources/views/esign/index.blade.php` | نعم | — | لا | — |
| 53 | `esign.remind_days` | 📜 العقود والتوقيع | text | `'3,7'` | إعدادات | `app/Console/Commands/HubAutomation.php` | نعم | `re` (مُنفَّذ) | لا | — |
| 54 | `assets.code_format` | 🧰 الأصول والعهد | text | `'LYN-{CAT}-{YEAR}-{SEQ}'` | إعدادات | `app/Models/Asset.php` | نعم | `re` (مُنفَّذ) | لا | — |
| 55 | `assets.permit_format` | 🧰 الأصول والعهد | text | `'PRM-{YEAR}-{SEQ}'` | إعدادات | `app/Support/Custody.php` | نعم | `re` (مُنفَّذ) | لا | — |
| 56 | `stations.code_format` | 🧰 الأصول والعهد | text | `'ST-{YEAR}-{SEQ}'` | إعدادات | `app/Models/Station.php` | نعم | `re` (مُنفَّذ) | لا | — |
| 57 | `identity.providers` | 🧰 الأصول والعهد | text | `'upcitemdb,openfoodfacts,openlibrary'` | إعدادات | `app/Support/Discovery/Engine.php` | نعم | — | لا | مجموعة `discovery` (**بلا تحقّق**) |
| 58 | `identity.timeout_ms` | 🧰 الأصول والعهد | number | `3500` | إعدادات | `app/Support/Discovery/Engine.php` | نعم | أرضية/سقف **عرضٌ فقط** | لا | مجموعة `discovery` (**بلا تحقّق**) |
| 59 | `work.late_grace` | 🧰 الأصول والعهد | number | `15` | إعدادات | `app/Support/Workday.php`<br>`app/Support/DailyWorkCompliance.php` | نعم | — | لا | **أرضيةُ كودٍ غيرُ معلنة** · مجموعة `work_hours` |
| 60 | `work.report_required` | 🧰 الأصول والعهد | onoff | `'1'` | إعدادات | `app/Support/DailyWorkCompliance.php` | نعم | — | لا | — |
| 61 | `work.report_grace_minutes` | 🧰 الأصول والعهد | number | `120` | إعدادات | `app/Support/DailyWorkCompliance.php` | نعم | أرضية/سقف **عرضٌ فقط** | لا | مجموعة `work_hours` |
| 62 | `work.report_cutoff_time` | 🧰 الأصول والعهد | text | «فارغ» | إعدادات | `app/Support/DailyWorkCompliance.php` | نعم | — | لا | مجموعة `work_hours` |
| 63 | `work.missing_report_policy` | 🧰 الأصول والعهد | text | `'absence_equivalent'` | إعدادات | `app/Support/DailyWorkCompliance.php` | نعم | `in` **غيرُ مُنفَّذ** | لا | مجموعة `work_hours` |
| 64 | `work.report_reminder` | 🧰 الأصول والعهد | onoff | `'1'` | إعدادات | `app/Console/Commands/AttendanceReconcileReports.php` | نعم | — | لا | مجموعة `work_hours` |
| 65 | `work.review_required` | 🧰 الأصول والعهد | onoff | `'0'` | إعدادات | `app/Support/DailyWorkCompliance.php` | نعم | — | لا | مجموعة `work_hours` |
| 66 | `work.geo` | 🧰 الأصول والعهد | onoff | `'0'` | إعدادات | `app/Support/Workday.php`<br>`resources/views/partials/widgets/checkin.blade.php` | نعم | — | لا | — |
| 67 | `work.progress_auto` | 🧰 الأصول والعهد | onoff | `'0'` | إعدادات | `app/Models/WorkUpdate.php` | نعم | — | لا | — |
| 68 | `identity.cache_days` | 🧰 الأصول والعهد | number | `30` | إعدادات | `app/Support/Discovery/Engine.php`<br>`resources/views/identity/center.blade.php` | نعم | — | لا | **أرضيةُ كودٍ غيرُ معلنة** · مجموعة `discovery` (**بلا تحقّق**) |
| 69 | `maintenance.on` | 🚧 التشغيل والمراقبة | onoff | `'0'` | إعدادات | `app/Support/SecurityPosture.php`<br>`app/Support/Health.php`<br>`app/Http/Controllers/Api/MobileAuthController.php`<br>`app/Http/Controllers/Web/OpsController.php`<br>+1 | نعم | — | نعم | للقراءة — تملكه `ops.index` |
| 70 | `maintenance.msg` | 🚧 التشغيل والمراقبة | text | «فارغ» | إعدادات | `app/Http/Controllers/Api/MobileAuthController.php`<br>`app/Http/Middleware/HubMaintenance.php` | نعم | — | نعم | — |
| 71 | `ops.slow_ms` | 🚧 التشغيل والمراقبة | number | `1000` | إعدادات | `app/Http/Middleware/Observability.php` | نعم | — | لا | **أرضيةُ كودٍ غيرُ معلنة** |
| 72 | `fin.base_currency` | 🚧 التشغيل والمراقبة | text | «فارغ» | إعدادات | `app/Support/Currency.php` | نعم | — | لا | — |
| 73 | `radar.window_days` | 🚧 التشغيل والمراقبة | number | `60` | إعدادات | `app/Support/helpers.php` | نعم | أرضية/سقف **عرضٌ فقط** | لا | — |
| 74 | `radar.lookback_days` | 🚧 التشغيل والمراقبة | number | `60` | إعدادات | `app/Support/helpers.php` | نعم | أرضية/سقف **عرضٌ فقط** | لا | — |
| 75 | `graph.max_nodes` | 🚧 التشغيل والمراقبة | number | `120` | إعدادات | `app/Support/RelationshipProjection.php` | نعم | — | لا | **أرضيةُ كودٍ غيرُ معلنة** |
| 76 | `graph.max_hops` | 🚧 التشغيل والمراقبة | number | `3` | إعدادات | `app/Support/RelationshipProjection.php` | نعم | — | لا | **أرضيةُ كودٍ غيرُ معلنة** |
| 77 | `monitor.timeout` | 🚧 التشغيل والمراقبة | number | `8` | إعدادات | `app/Support/Uptime.php` | نعم | — | لا | — |
| 78 | `monitor.allow_private` | 🚧 التشغيل والمراقبة | onoff | `'0'` | إعدادات | `app/Support/SecurityPosture.php`<br>`app/Support/helpers.php` | نعم | — | لا | — |
| 79 | `ops.backup_before_migrate` | 🚧 التشغيل والمراقبة | onoff | `'1'` | إعدادات | `app/Http/Controllers/Web/OpsController.php` | نعم | — | لا | — |
| 80 | `files.max_kb` | 🚧 التشغيل والمراقبة | number | `1048576` | إعدادات | `app/Support/helpers.php` | نعم | — | نعم | — |
| 81 | `ops.cpu_warn` | 🚧 التشغيل والمراقبة | number | `60` | إعدادات | `app/Support/SysMonitor.php` | نعم | — | لا | **أرضيةُ كودٍ غيرُ معلنة** · مجموعة `ops_cpu` (**بلا تحقّق**) |
| 82 | `ops.cpu_crit` | 🚧 التشغيل والمراقبة | number | `90` | إعدادات | `app/Support/SysMonitor.php` | نعم | — | لا | مجموعة `ops_cpu` (**بلا تحقّق**) |
| 83 | `ops.mem_warn` | 🚧 التشغيل والمراقبة | number | `75` | إعدادات | `app/Support/SysMonitor.php` | نعم | — | لا | **أرضيةُ كودٍ غيرُ معلنة** · مجموعة `ops_mem` (**بلا تحقّق**) |
| 84 | `ops.mem_crit` | 🚧 التشغيل والمراقبة | number | `90` | إعدادات | `app/Support/SysMonitor.php` | نعم | — | لا | مجموعة `ops_mem` (**بلا تحقّق**) |
| 85 | `ops.disk_warn` | 🚧 التشغيل والمراقبة | number | `85` | إعدادات | `app/Support/Health.php`<br>`resources/views/ops/parts/system.blade.php` | نعم | — | لا | **أرضيةُ كودٍ غيرُ معلنة** · مجموعة `ops_disk` (**بلا تحقّق**) |
| 86 | `ops.disk_crit` | 🚧 التشغيل والمراقبة | number | `97` | إعدادات | `app/Support/Health.php` | نعم | — | لا | مجموعة `ops_disk` (**بلا تحقّق**) |
| 87 | `ops.db_ms_warn` | 🚧 التشغيل والمراقبة | number | `500` | إعدادات | `app/Support/Health.php` | نعم | — | لا | **أرضيةُ كودٍ غيرُ معلنة** |
| 88 | `ops.queue_age_warn` | 🚧 التشغيل والمراقبة | number | `20` | إعدادات | `app/Support/Health.php` | نعم | — | لا | **أرضيةُ كودٍ غيرُ معلنة** · مجموعة `ops_queue` (**بلا تحقّق**) |
| 89 | `ops.queue_age_crit` | 🚧 التشغيل والمراقبة | number | `60` | إعدادات | `app/Support/Health.php` | نعم | — | لا | مجموعة `ops_queue` (**بلا تحقّق**) |
| 90 | `ops.scheduler_late_factor` | 🚧 التشغيل والمراقبة | number | `1` | إعدادات | `app/Support/Health.php` | نعم | أرضية/سقف **عرضٌ فقط** | لا | — |
| 91 | `ops.regression_pct` | 🚧 التشغيل والمراقبة | number | `30` | إعدادات | `app/Http/Controllers/Web/OpsController.php` | نعم | — | لا | **أرضيةُ كودٍ غيرُ معلنة** · مجموعة `ops_regression` (**بلا تحقّق**) |
| 92 | `ops.regression_min_n` | 🚧 التشغيل والمراقبة | number | `100` | إعدادات | `app/Http/Controllers/Web/OpsController.php` | نعم | — | لا | **أرضيةُ كودٍ غيرُ معلنة** · مجموعة `ops_regression` (**بلا تحقّق**) |
| 93 | `slo.window_days` | 🚧 التشغيل والمراقبة | number | «فارغ» | إعدادات | `app/Http/Controllers/Web/OpsController.php` | نعم | — | لا | مجموعة `slo` (**بلا تحقّق**) |
| 94 | `slo.availability_pct` | 🚧 التشغيل والمراقبة | number | «فارغ» | إعدادات | `app/Http/Controllers/Web/OpsController.php` | نعم | — | لا | مجموعة `slo` (**بلا تحقّق**) |
| 95 | `slo.latency_ms` | 🚧 التشغيل والمراقبة | number | «فارغ» | إعدادات | `app/Http/Controllers/Web/OpsController.php` | نعم | — | لا | مجموعة `slo` (**بلا تحقّق**) |
| 96 | `slo.latency_pct` | 🚧 التشغيل والمراقبة | number | «فارغ» | إعدادات | `app/Http/Controllers/Web/OpsController.php` | نعم | — | لا | مجموعة `slo` (**بلا تحقّق**) |
| 97 | `slo.error_rate_pct` | 🚧 التشغيل والمراقبة | number | «فارغ» | إعدادات | `app/Http/Controllers/Web/OpsController.php` | نعم | — | لا | مجموعة `slo` (**بلا تحقّق**) |
| 98 | `finance.auto_journal` | 💼 المالية والدعم والتكامل | onoff | `'0'` | إعدادات | `app/Support/JournalPostingService.php` | نعم | — | لا | مجموعة `finance` (**بلا تحقّق**) |
| 99 | `finance.accounts` | 💼 المالية والدعم والتكامل | ta | «فارغ» | إعدادات | `app/Support/JournalPostingService.php` | نعم | — | لا | مجموعة `finance` (**بلا تحقّق**) |
| 100 | `sla.rules` | 💼 المالية والدعم والتكامل | text | `'عاجلة:1:8 عالية:4:24 متوسطة:8:72 منخفضة:24:120 افتراضي:8:72'` | إعدادات | `app/Support/helpers.php` | نعم | — | لا | — |
| 101 | `mail.host` | 💼 المالية والدعم والتكامل | text | «فارغ» | إعدادات | `app/Support/MailSettings.php`<br>`resources/views/integrations/messaging.blade.php` | نعم | — | نعم | بيئة: `MAIL_HOST` · مجموعة `mail` |
| 102 | `mail.port` | 💼 المالية والدعم والتكامل | number | `587` | إعدادات | `app/Support/MailSettings.php`<br>`resources/views/integrations/messaging.blade.php` | نعم | — | نعم | بيئة: `MAIL_PORT` · مجموعة `mail` |
| 103 | `mail.username` | 💼 المالية والدعم والتكامل | text | «فارغ» | إعدادات | `app/Support/MailSettings.php`<br>`resources/views/integrations/messaging.blade.php` | نعم | — | نعم | بيئة: `MAIL_USERNAME` · مجموعة `mail` |
| 104 | `mail.password` | 💼 المالية والدعم والتكامل | pass | «فارغ» | إعدادات | `app/Support/MailSettings.php`<br>`app/Http/Controllers/Web/MessagingController.php(كتابة)`<br>`resources/views/integrations/messaging.blade.php` | نعم | — | نعم | سرّ (enc:) · بيئة: `MAIL_PASSWORD` · مجموعة `mail` |
| 105 | `mail.encryption` | 💼 المالية والدعم والتكامل | text | `'tls'` | إعدادات | `app/Support/MailSettings.php`<br>`resources/views/integrations/messaging.blade.php` | نعم | — | نعم | بيئة: `MAIL_ENCRYPTION` · مجموعة `mail` |
| 106 | `mail.from_address` | 💼 المالية والدعم والتكامل | text | «فارغ» | إعدادات | `app/Support/MailSettings.php`<br>`resources/views/integrations/messaging.blade.php` | نعم | — | نعم | بيئة: `MAIL_FROM_ADDRESS` · مجموعة `mail` |
| 107 | `mail.from_name` | 💼 المالية والدعم والتكامل | text | «فارغ» | إعدادات | `app/Support/MailSettings.php`<br>`resources/views/integrations/messaging.blade.php` | نعم | — | نعم | بيئة: `MAIL_FROM_NAME` · مجموعة `mail` |
| 108 | `odoo.url` | 💼 المالية والدعم والتكامل | text | «فارغ» | إعدادات | `app/Support/Odoo.php`<br>`app/Http/Controllers/Web/OdooConnectionController.php(كتابة)` | نعم | — | نعم | مجموعة `odoo` |
| 109 | `odoo.db` | 💼 المالية والدعم والتكامل | text | «فارغ» | إعدادات | `app/Support/Odoo.php`<br>`app/Http/Controllers/Web/OdooConnectionController.php(كتابة)` | نعم | — | نعم | مجموعة `odoo` |
| 110 | `odoo.user` | 💼 المالية والدعم والتكامل | text | «فارغ» | إعدادات | `app/Support/Odoo.php`<br>`app/Http/Controllers/Web/OdooConnectionController.php(كتابة)` | نعم | — | نعم | مجموعة `odoo` |
| 111 | `odoo.key` | 💼 المالية والدعم والتكامل | pass | «فارغ» | إعدادات | `app/Support/Odoo.php`<br>`app/Http/Controllers/Web/OdooConnectionController.php(كتابة)` | نعم | — | نعم | سرّ (enc:) · مجموعة `odoo` |
| 112 | `quoteflow.pass` | 💼 المالية والدعم والتكامل | pass | `'1998'` | إعدادات | `app/Http/Controllers/Web/QuoteFlowController.php` | نعم | — | لا | سرّ (enc:) |
| 113 | `endpoint.enroll_ttl_min` | 🛰️ النقاط الطرفية | number | `15` | إعدادات | `app/Models/EnrollmentToken.php`<br>`resources/views/endpoints/index.blade.php` | نعم | — | لا | **أرضيةُ كودٍ غيرُ معلنة** |
| 114 | `endpoint.heartbeat_interval_min` | 🛰️ النقاط الطرفية | number | `5` | إعدادات | `app/Http/Controllers/Api/EndpointProtocolController.php`<br>`app/Http/Controllers/Web/EndpointCentreController.php` | نعم | — | لا | **أرضيةُ كودٍ غيرُ معلنة** |

### المفاتيح الداخليّة (65) — لا شاشةَ تحريرٍ في مركز الإعدادات

| # | المفتاح | تُدار من | مواضعُ القراءة الفعليّة | أيغيّر السلوك؟ | سرّ؟ | قابلٌ للنقل | ملاحظة |
|---|---|---|---|---|---|---|---|
| 1 | `collab.oversight_role` | — | `app/Http/Controllers/Web/OversightController.php` | نعم | لا | لا | — |
| 2 | `feature.collab_presence` | features.index | **لا قارئ (نصّيّاً)** | **لا** | لا | لا | — |
| 3 | `feature.collab_typing` | features.index | **لا قارئ (نصّيّاً)** | **لا** | لا | لا | — |
| 4 | `notify.flash_min` | — | `app/Http/Controllers/Web/NotificationController.php` | نعم | لا | لا | أرضيةُ كودٍ غيرُ معلنة |
| 5 | `notify.escalate_after` | — | `app/Support/AlertEngine.php` | نعم | لا | لا | — |
| 6 | `n8n.url` | integrations.n8n | `app/Support/ConnectionProbe.php`<br>`app/Http/Controllers/Web/N8nController.php` | نعم | لا | لا | — |
| 7 | `n8n.key` | integrations.n8n | `app/Support/ConnectionProbe.php`<br>`app/Http/Controllers/Web/N8nController.php` | نعم | نعم | لا | — |
| 8 | `notify.tg_token` | integrations.messaging | `app/Support/Integrations.php`<br>`app/Http/Controllers/Web/MessagingController.php`<br>`app/Console/Commands/HubOutbox.php` | نعم | نعم | لا | — |
| 9 | `notify.tg_chat` | integrations.messaging | `app/Http/Controllers/Web/MessagingController.php`<br>`app/Console/Commands/HubOutbox.php` | نعم | لا | لا | — |
| 10 | `auth.pw_hibp` | security.index | `app/Support/helpers.php` | نعم | لا | لا | — |
| 11 | `contracts.clauses` | — | `app/Http/Controllers/Web/ContractActionsController.php` | نعم | لا | لا | — |
| 12 | `custom.fields` | — | `app/Support/helpers.php`<br>`app/Http/Controllers/Web/CustomFieldController.php(كتابة)` | نعم | لا | لا | — |
| 13 | `demo.on` | — | `app/Support/SecurityPosture.php`<br>`app/Console/Commands/HubDemo.php(كتابة)`<br>`resources/views/ops/parts/demo.blade.php`<br>+1 | نعم | لا | لا | صفُّ حالة |
| 14 | `esign.custom_vars` | — | `app/Support/ContractVars.php` | نعم | لا | لا | — |
| 15 | `esign.tpl_seeded` | — | `app/Http/Controllers/Web/EsignController.php` | نعم | لا | لا | صفُّ حالة |
| 16 | `security.lockdown` | security.index | `app/Support/SecurityPosture.php`<br>`app/Support/AlertEngine.php`<br>`app/Support/Health.php`<br>+9 | نعم | لا | لا | — |
| 17 | `security.freeze_exports` | security.index | `app/Support/SecurityPosture.php`<br>`app/Support/Health.php`<br>`app/Support/helpers.php`<br>+4 | نعم | لا | لا | — |
| 18 | `security.freeze_tokens` | security.index | `app/Support/SecurityPosture.php`<br>`app/Support/Health.php`<br>`app/Support/Integrations.php`<br>+2 | نعم | لا | لا | — |
| 19 | `security.edge_adapter` | — | `app/Support/EdgeDefense.php` | نعم | لا | لا | — |
| 20 | `security.edge_cloudflare_secret_ref` | security.index | `app/Support/EdgeDefense.php` | نعم | لا | لا | — |
| 21 | `mobile.access_ttl_min` | — | `app/Support/MobileSessionService.php` | نعم | لا | لا | أرضيةُ كودٍ غيرُ معلنة |
| 22 | `mobile.refresh_ttl_days` | — | `app/Support/MobileSessionService.php` | نعم | لا | لا | أرضيةُ كودٍ غيرُ معلنة |
| 23 | `mobile.mfa_challenge_min` | — | `app/Http/Controllers/Api/MobileAuthController.php` | نعم | لا | لا | أرضيةُ كودٍ غيرُ معلنة |
| 24 | `mobile.support_url` | — | `app/Http/Controllers/Api/MobileAuthController.php` | نعم | لا | لا | — |
| 25 | `mobile.dl_apple_team_id` | — | `app/Support/MobilePlatform.php`<br>`app/Http/Controllers/Web/MobileWellKnownController.php` | نعم | لا | لا | — |
| 26 | `mobile.dl_apple_bundle_id` | — | `app/Support/MobilePlatform.php` | نعم | لا | لا | — |
| 27 | `mobile.dl_android_package` | — | `app/Support/MobilePlatform.php` | نعم | لا | لا | — |
| 28 | `mobile.dl_android_fingerprints` | — | `app/Support/MobilePlatform.php`<br>`app/Http/Controllers/Web/MobileWellKnownController.php` | نعم | لا | لا | — |
| 29 | `mobile.min_version_ios` | — | `app/Http/Controllers/Api/MobileAuthController.php` | نعم | لا | لا | — |
| 30 | `mobile.latest_version_ios` | — | `app/Http/Controllers/Api/MobileAuthController.php` | نعم | لا | لا | — |
| 31 | `mobile.min_version_android` | — | `app/Http/Controllers/Api/MobileAuthController.php` | نعم | لا | لا | — |
| 32 | `mobile.latest_version_android` | — | `app/Http/Controllers/Api/MobileAuthController.php` | نعم | لا | لا | — |
| 33 | `mobile.force_update` | — | `app/Support/MobilePlatform.php`<br>`app/Support/MobileOpenApi.php`<br>`app/Http/Controllers/Api/MobileAuthController.php` | نعم | لا | لا | — |
| 34 | `mobile.store_url_ios` | — | `app/Http/Controllers/Api/MobileAuthController.php` | نعم | لا | لا | — |
| 35 | `mobile.store_url_android` | — | `app/Http/Controllers/Api/MobileAuthController.php` | نعم | لا | لا | — |
| 36 | `mobile.push_driver` | — | `app/Support/PushService.php` | نعم | لا | لا | — |
| 37 | `mobile.push_fcm_project_id` | — | `app/Support/PushService.php` | نعم | لا | لا | — |
| 38 | `mobile.push_fcm_access_token` | — | `app/Support/PushService.php` | نعم | نعم | لا | — |
| 39 | `heartbeat.backup` | — | `app/Support/SecurityPosture.php`<br>`app/Http/Controllers/Web/MorningController.php` | نعم | لا | لا | صفُّ حالة |
| 40 | `api.usage_keep_days` | — | `app/Console/Commands/HubAutomation.php` | نعم | لا | لا | — |
| 41 | `ops.jslog_daily_cap` | — | `app/Http/Controllers/Web/ErrorCenterController.php` | نعم | لا | لا | — |
| 42 | `retention.outbox_days` | — | `app/Console/Commands/HubAutomation.php` | نعم | لا | نعم | أرضيةُ كودٍ غيرُ معلنة |
| 43 | `retention.webhook_failed_days` | — | `app/Console/Commands/HubAutomation.php` | نعم | لا | نعم | أرضيةُ كودٍ غيرُ معلنة |
| 44 | `retention.errors_days` | — | `app/Console/Commands/HubAutomation.php` | نعم | لا | نعم | أرضيةُ كودٍ غيرُ معلنة |
| 45 | `backup.encrypt` | — | `app/Console/Commands/HubBackup.php` | نعم | لا | لا | — |
| 46 | `outbox.max_attempts` | — | `app/Console/Commands/HubOutbox.php` | نعم | لا | لا | أرضيةُ كودٍ غيرُ معلنة |
| 47 | `heartbeat.watchdog_notified` | — | `app/Support/Health.php` | نعم | لا | لا | صفُّ حالة |
| 48 | `ops.watchdog` | — | `app/Support/Health.php` | نعم | لا | لا | — |
| 49 | `security.stepup_ops` | — | `app/Support/helpers.php` | نعم | لا | لا | — |
| 50 | `security.stepup_credentials` | — | `app/Support/helpers.php` | نعم | لا | لا | — |
| 51 | `heartbeat.digest` | — | `app/Console/Commands/HubDigest.php` | نعم | لا | لا | صفُّ حالة |
| 52 | `heartbeat.outbox` | — | `app/Http/Controllers/Web/MessagingController.php` | نعم | لا | لا | صفُّ حالة |
| 53 | `ops.http_p95_ms` | — | **لا قارئ (نصّيّاً)** | **لا** | لا | لا | — |
| 54 | `retention.http_buckets_days` | — | `app/Console/Commands/HubAutomation.php` | نعم | لا | نعم | أرضيةُ كودٍ غيرُ معلنة |
| 55 | `ops.last_version` | — | `app/Console/Commands/HubOpsSnapshot.php` | نعم | لا | لا | صفُّ حالة |
| 56 | `retention.metric_points_days` | — | `app/Console/Commands/HubAutomation.php` | نعم | لا | نعم | أرضيةُ كودٍ غيرُ معلنة |
| 57 | `retention.notifications_days` | — | `app/Console/Commands/HubAutomation.php` | نعم | لا | نعم | أرضيةُ كودٍ غيرُ معلنة |
| 58 | `retention.inbound_hooks_days` | — | `app/Console/Commands/HubAutomation.php` | نعم | لا | نعم | أرضيةُ كودٍ غيرُ معلنة |
| 59 | `integration.odoo.last_ms` | — | **لا قارئ (نصّيّاً)** | **لا** | لا | لا | صفُّ حالة |
| 60 | `errors.occurrences_keep` | — | `app/Support/ErrorLog.php` | نعم | لا | لا | أرضيةُ كودٍ غيرُ معلنة |
| 61 | `retention.error_occurrences_days` | — | `app/Console/Commands/HubAutomation.php` | نعم | لا | نعم | أرضيةُ كودٍ غيرُ معلنة |
| 62 | `errors.notify_cooldown_min` | — | `app/Support/ErrorLog.php` | نعم | لا | لا | أرضيةُ كودٍ غيرُ معلنة |
| 63 | `ops.log_tail_kb` | — | `app/Http/Controllers/Web/ErrorCenterController.php` | نعم | لا | لا | أرضيةُ كودٍ غيرُ معلنة |
| 64 | `security.alert_cooldown_min` | — | `app/Support/AlertEngine.php` | نعم | لا | لا | — |
| 65 | `retention.visits_days` | — | `app/Console/Commands/HubAutomation.php` | نعم | لا | لا | أرضيةُ كودٍ غيرُ معلنة |
---

## ٤) الجدولُ الكامل — سجلُّ القدرات (١٣٧)

> «الحالةُ المُشتقّة (مقيسة)» من تشغيلٍ حيٍّ لـ`FeatureRegistry::resolveAll()` على قاعدةٍ معزولة: **انحرافُ المُعلَن عن المُشتقّ = صفر** في خطِّ الأساس (كلُّ الفاحصات تعود إلى فرع «غيرُ مُهيَّأ»). الحكمُ ليس على خطِّ الأساس وحدَه بل على **ما يقوله السجلُّ حين تتبدّل الحال** — وهناك ينكسر (A16-01).
>
> **رموزُ الدليل:** ✔ مسارٌ/شاشةٌ/وثيقةٌ موجودةٌ فعلاً (فُحصت مقابل `route:list` الحيّ ونظامِ الملفّات) · ✘ مفقودة · والنصُّ الحرّ دليلٌ كوديٌّ تتبّعتُه يدوياً لِما لا مؤشّرَ له في الكتالوج.
>
> **مفاتيحُ الحكم:** **صادقة** = الحالةُ تطابق الشيفرة · **صادقةٌ · جامدة** = صادقةٌ اليوم ولا فاحصَ يرفعها أبداً (A16-09) · **صادقةٌ · ثابتة** = `derive` ثابتٌ لا فاحص (A16-10) · **مشكوكٌ فيها** = الحالةُ تنكسر بتغيّرٍ متوقَّع أو لا سطحَ مستقلّاً لها · **كاذبةٌ جزئياً** = الوصفُ المُعلَن يناقض الشيفرة.

| # | القدرة | المجال | الحالةُ المُعلَنة | الحالةُ المُشتقّة (مقيسة) | الدليلُ في الكود | الحكم | لماذا |
|---|---|---|---|---|---|---|---|
| 1 | `collab.center` | collaboration | ENABLED | ENABLED | ✔ `collab.center`<br>✔ api `mobile.conversations.index`<br>✔ شاشة `collab.center`<br>✔ docs/collaboration/07-unified-center.md | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 2 | `collab.channels` | collaboration | ENABLED | ENABLED | ✔ `conversations.index`<br>✔ `conversations.show`<br>✔ docs/collaboration/03-channel-management.md | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 3 | `collab.private_channels` | collaboration | ENABLED | ENABLED | `app/Models/Conversation.php` VISIBILITIES=private | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 4 | `collab.dm` | collaboration | ENABLED | ENABLED | ✔ `dm.inbox`<br>✔ `dm.thread`<br>✔ api `mobile.dm.threads` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 5 | `collab.group_dm` | collaboration | ENABLED | ENABLED | ✔ `groups.index`<br>✔ docs/collaboration/05-group-dms.md | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 6 | `collab.threads` | collaboration | ENABLED | ENABLED | `app/Models/Comment.php` parent_id + bumpThread/recountThread | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 7 | `collab.reactions` | collaboration | ENABLED | ENABLED | ✔ api `mobile.comments.react`<br>✔ api `mobile.dm.react` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 8 | `collab.mentions` | collaboration | ENABLED | ENABLED | `comments.mentions` (هجرة 2026_01_04) + `CommentService` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 9 | `collab.saved` | collaboration | ENABLED | ENABLED | ✔ `saved.index`<br>✔ api `mobile.saved.index` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 10 | `collab.pins` | collaboration | ENABLED | ENABLED | `app/Models/Comment.php` pinned/pinned_at | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 11 | `collab.search` | collaboration | ENABLED | ENABLED | ✔ `search.messages` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 12 | `collab.unread` | collaboration | ENABLED | ENABLED | `ConversationMember` last_read_at · `DmService` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 13 | `collab.directory` | collaboration | ENABLED | ENABLED | ✔ `conversations.directory` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 14 | `collab.favorites` | collaboration | ENABLED | ENABLED | `ConversationMember::favorite_at` · `CollaborationRail` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 15 | `collab.archive` | collaboration | ENABLED | ENABLED | `Conversation::archived_at` + scope | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 16 | `collab.notify_prefs` | collaboration | ENABLED | ENABLED | `ConversationMember::notify_pref` + `Collaboration::NOTIFY_PREFS` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 17 | `collab.internal_rooms` | collaboration | ENABLED | ENABLED | ✔ docs/collaboration/08-rooms-disclosure.md | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 18 | `collab.client_rooms` | collaboration | ENABLED | ENABLED | ✔ docs/collaboration/08-rooms-disclosure.md | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 19 | `collab.record_discussions` | collaboration | ENABLED | ENABLED | `comments.(module,record_id)` — المفتاح متعدّد الأشكال | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 20 | `collab.chat_commands` | collaboration | ENABLED | ENABLED | `app/Support/ChatCommands.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 21 | `collab.presence` | collaboration | ENABLED | ENABLED | ✔ api `mobile.presence`<br>راية `feature.collab_presence` + `hub_capability()`<br>✔ docs/collaboration/06-presence-typing.md | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 22 | `collab.typing` | collaboration | ENABLED | ENABLED | ✔ api `mobile.conversations.typing`<br>✔ api `mobile.dm.typing`<br>راية `feature.collab_typing` + `hub_capability()`<br>✔ docs/collaboration/06-presence-typing.md | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 23 | `collab.incremental_polling` | collaboration | ENABLED | ENABLED | ✔ docs/collaboration/04-realtime.md | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 24 | `collab.event_contract` | collaboration | ENABLED | ENABLED | `app/Support/Collaboration.php` (message.created/deleted) | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 25 | `collab.websocket_readiness` | collaboration | READY | READY | عقدُ الأحداث في `Collaboration` — لا مزوّدَ بثّ (لا `config/broadcasting.php`) | **صادقة** | READY = العقدُ قائمٌ بلا مزوّد |
| 26 | `collab.websocket_provider` | collaboration | NOT_CONFIGURED | NOT_CONFIGURED | `FeatureRegistry::deriveFromSource('websocket')` — **ثابتٌ لا فاحص** | **صادقةٌ · ثابتة** | `derive` ثابتٌ لا فاحص (A16-10) |
| 27 | `collab.voice_notes` | collaboration | DEFERRED | DEFERRED | لا شيفرةَ تسجيل — مُعلَنٌ مؤجَّلاً | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 28 | `collab.link_previews` | collaboration | DEFERRED | DEFERRED | لا جلبَ خادميّ — مُعلَنٌ مؤجَّلاً | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 29 | `client.portal` | client | ENABLED | ENABLED | ✔ `portal.home`<br>✔ api `mobile.portal.home` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 30 | `client.activation` | client | ENABLED | ENABLED | `app/Models/AccountActivation.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 31 | `client.membership` | client | ENABLED | ENABLED | `app/Support/ClientMembers.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 32 | `client.project_access` | client | ENABLED | ENABLED | `app/Support/ClientPortalData.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 33 | `client.quote_provisioning` | client | ENABLED | ENABLED | `QuoteController` → مشروع | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 34 | `client.internal_workspace` | client | ENABLED | ENABLED | `app/Support/Workspaces.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 35 | `client.workspace` | client | ENABLED | ENABLED | `app/Support/ClientPortalData.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 36 | `client.external_delivery` | client | ENABLED | ENABLED | `app/Support/Delivery.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 37 | `client.psa` | client | ENABLED | ENABLED | `app/Models/Project.php` + وحدة projects | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 38 | `client.visible_files` | client | ENABLED | ENABLED | `audience` على المرفقات/الغرف | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 39 | `client.visible_deliverables` | client | ENABLED | ENABLED | `ClientPortalData` — **لم أجد سطحاً مستقلّاً** | **مشكوكٌ فيها** | لم أجد سطحاً مستقلّاً باسم «تسليمات مرئيّة» (A16-12) |
| 40 | `client.safe_timeline` | client | ENABLED | ENABLED | `ClientPortalData` (خطٌّ زمنيٌّ مرشَّح) | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 41 | `workos.financial_custody` | work_os | ENABLED | ENABLED | `app/Models/EmployeeCustodyMove.php` + `CustodyPostingService` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 42 | `workos.custody_ledger` | work_os | ENABLED | ENABLED | `EmployeeCustodyMove` — `updating`/`deleting` مقفولان | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 43 | `workos.custody_receipt` | work_os | ENABLED | ENABLED | `app/Support/Custody.php` nextPermitNo + مستندُ التصريح | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 44 | `workos.custody_reversal` | work_os | ENABLED | ENABLED | `EmployeeCustodyMove::reverse()` + `reverses_id`/`reversed_at` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 45 | `workos.stations` | work_os | ENABLED | ENABLED | `app/Models/Station.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 46 | `workos.station_identity` | work_os | ENABLED | ENABLED | `Station` + `stations.code_format` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 47 | `workos.station_history` | work_os | ENABLED | ENABLED | `app/Models/StationAssignment.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 48 | `workos.employee_360` | work_os | ENABLED | ENABLED | `app/Support/Employee360.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 49 | `workos.relationship_explorer` | work_os | ENABLED | ENABLED | ✔ `graph.explore` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 50 | `workos.tech_workspace` | work_os | ENABLED | ENABLED | `app/Http/Controllers/Web/TechWorkspaceController.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 51 | `workos.project_360` | work_os | ENABLED | ENABLED | `tests/Feature/Project360Test.php` + تبويباتُ المشروع | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 52 | `workos.daily_reports` | work_os | ENABLED | ENABLED | ✔ `reports.index`<br>✔ `reports.review`<br>✔ `reports.mine`<br>✔ docs/attendance-reporting/00-overview.md | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 53 | `workos.station_360` | work_os | ENABLED | ENABLED | ✔ docs/entity360/03-station360.md | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 54 | `workos.global_search` | work_os | ENABLED | ENABLED | ✔ `search`<br>✔ `search.mini` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 55 | `assets.company_assets` | assets | ENABLED | ENABLED | `app/Models/Asset.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 56 | `assets.employee_assignment` | assets | ENABLED | ENABLED | `app/Models/AssetCustody.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 57 | `assets.station_assignment` | assets | ENABLED | ENABLED | `app/Models/StationAssignment.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 58 | `assets.custody_history` | assets | ENABLED | ENABLED | `asset_custody` (هجرة 2026_08_16) | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 59 | `assets.inventory_sessions` | assets | ENABLED | ENABLED | `app/Models/InventorySession.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 60 | `assets.scan_resolution` | assets | ENABLED | ENABLED | `app/Models/InventoryScan.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 61 | `assets.reconciliation` | assets | ENABLED | ENABLED | `InventorySession` + `InventoryController` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 62 | `assets.endpoint_eligibility` | assets | ENABLED | ENABLED | `app/Models/Asset.php` (eligible) | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 63 | `assets.relationship` | assets | ENABLED | ENABLED | `AssetProjectAssignment` + `RelationshipProjection` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 64 | `assets.asset_360` | assets | ENABLED | ENABLED | ✔ docs/entity360/04-asset360.md | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 65 | `assets.project_assignment` | assets | ENABLED | ENABLED | ✔ api `api.v1.projects.assets`<br>✔ api `api.v1.assets.projects`<br>✔ docs/project360/03-asset-project-assignment.md | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 66 | `telecom.sim_registry` | telecom | ENABLED | ENABLED | `app/Models/PhoneNumber.php` (ICCID/eSIM) — **نفسُ نموذجِ `telecom.phone_registry`** | **مشكوكٌ فيها** | و`telecom.phone_registry` نموذجٌ واحد — قدرتان لسطحٍ واحد (A16-11) |
| 67 | `telecom.phone_registry` | telecom | ENABLED | ENABLED | `app/Models/PhoneNumber.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 68 | `telecom.carrier_registry` | telecom | ENABLED | ENABLED | `app/Models/Carrier.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 69 | `telecom.sim_lifecycle` | telecom | ENABLED | ENABLED | `PhoneNumber` status/lifecycle | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 70 | `telecom.employee_linkage` | telecom | ENABLED | ENABLED | `PhoneNumber.holder_id` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 71 | `telecom.device_linkage` | telecom | ENABLED | ENABLED | `PhoneNumber` ← الأصل/الجهاز | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 72 | `telecom.station_linkage` | telecom | ENABLED | ENABLED | `PhoneNumber`/`StationAssignment` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 73 | `telecom.carrier_vault` | telecom | ENABLED | ENABLED | `VaultSecret` على `Carrier` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 74 | `telecom.provisioning_adapter` | telecom | READY | READY | **لم أجد محوّلَ تزويدٍ باسمٍ صريح** — لا صنفَ Provisioning في `app/` | **مشكوكٌ فيها** | READY بلا صنفٍ يُسمّى محوِّلَ تزويد (A16-12) |
| 75 | `telecom.live_provisioning` | telecom | NOT_CONFIGURED | NOT_CONFIGURED | حالةٌ **جامدة** (لا فاحص) | **صادقةٌ · جامدة** | A16-09 |
| 76 | `security.app_ip_defense` | security | ENABLED | ENABLED | ✔ شاشة `security.index` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 77 | `security.ip_allow` | security | ENABLED | ENABLED | `app/Models/IpRule.php` + `IpDefense` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 78 | `security.ip_block` | security | ENABLED | ENABLED | `app/Http/Middleware/IpDefense.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 79 | `security.auto_block` | security | ENABLED | ENABLED | `app/Support/AlertEngine.php` (security.autoblock_*) | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 80 | `security.edge_blocking` | security | NOT_CONFIGURED | NOT_CONFIGURED | `EdgeDefense::status()` — فاحصٌ حقيقيّ | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 81 | `security.alerts` | security | ENABLED | ENABLED | ✔ شاشة `alerts.center` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 82 | `security.session_management` | security | ENABLED | ENABLED | ✔ `mysec.index` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 83 | `security.api_tokens` | security | ENABLED | ENABLED | `app/Models/ApiToken.php` + `app/Support/ApiTokens.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 84 | `security.incident_investigation` | security | ENABLED | ENABLED | `app/Models/Incident.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 85 | `security.authorization` | security | SYSTEM_INVARIANT | SYSTEM_INVARIANT | `hub_can` في `app/Support/helpers.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 86 | `security.company_scopes` | security | SYSTEM_INVARIANT | SYSTEM_INVARIANT | `hub_company_ids`/`hub_scope` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 87 | `security.client_isolation` | security | SYSTEM_INVARIANT | SYSTEM_INVARIANT | `app/Support/ClientPortalData.php` + PortalGuard | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 88 | `security.portal_guard` | security | SYSTEM_INVARIANT | SYSTEM_INVARIANT | وسيطُ `PortalGuard` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 89 | `security.audit_trail` | security | SYSTEM_INVARIANT | SYSTEM_INVARIANT | `app/Models/AuditEntry.php` (سلسلةُ SHA-256) | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 90 | `security.audit_chain_verification` | security | SYSTEM_INVARIANT | SYSTEM_INVARIANT | `audit_verifications` + `AuditController` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 91 | `security.step_up` | security | SYSTEM_INVARIANT | SYSTEM_INVARIANT | `app/Support/StepUp.php` + `hub_require_stepup` | **كاذبة جزئياً** | «لا تصير مفتاحاً» ومفتاحاها `security.stepup_ops`/`security.stepup_credentials` قائمان (A16-02) |
| 92 | `security.secret_encryption` | security | SYSTEM_INVARIANT | SYSTEM_INVARIANT | `Settings::commit` (enc:) + `VaultSecret` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 93 | `security.idor_defense` | security | SYSTEM_INVARIANT | SYSTEM_INVARIANT | `hub_read`/`hub_guard_scope_input` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 94 | `endpoint.enrollment` | endpoint | ENABLED | ENABLED | ✔ شاشة `endpoints.index` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 95 | `endpoint.identity` | endpoint | ENABLED | ENABLED | `app/Models/EndpointDevice.php` + `Es256` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 96 | `endpoint.agent` | endpoint | ENABLED | ENABLED | `app/Support/Endpoint.php` + `EndpointProtocolController` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 97 | `endpoint.windows` | endpoint | ENABLED | ENABLED | `EndpointRelease::OS` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 98 | `endpoint.macos` | endpoint | ENABLED | ENABLED | `EndpointRelease::OS` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 99 | `endpoint.device_inventory` | endpoint | ENABLED | ENABLED | `app/Models/EndpointDevice.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 100 | `endpoint.wifi_posture` | endpoint | ENABLED | ENABLED | `app/Support/PostureContract.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 101 | `endpoint.usb_observation` | endpoint | ENABLED | ENABLED | `EndpointEvent` + `EndpointPolicy` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 102 | `endpoint.usb_enforcement` | endpoint | NOT_CONFIGURED | NOT_CONFIGURED | `MdmService::status()['configured']` — **يتجاهل `can_enforce`** | **مشكوكٌ فيها** | صادقةٌ اليوم، وكاذبةٌ بمجرّد ضبط اعتمادات MDM (A16-01 — مُثبَت) |
| 103 | `endpoint.alerts` | endpoint | ENABLED | ENABLED | `FlowRunner::fire('posture_alert','endpoints')` + `EndpointEvent::SEVERITIES` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 104 | `endpoint.commands` | endpoint | ENABLED | ENABLED | `app/Models/EndpointCommand.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 105 | `endpoint.isolation` | endpoint | ENABLED | ENABLED | `EndpointCommand` (isolate) | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 106 | `endpoint.lock` | endpoint | ENABLED | ENABLED | `EndpointCommand` (lock) | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 107 | `endpoint.release_distribution` | endpoint | ENABLED | ENABLED | ✔ `endpoints.releases` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 108 | `endpoint.update` | endpoint | ENABLED | ENABLED | `EndpointRelease` + قناةُ canary | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 109 | `endpoint.release_trust` | endpoint | ENABLED | ENABLED | `EndpointRelease::attestSignature/signing_status` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 110 | `endpoint.mdm` | endpoint | NOT_CONFIGURED | NOT_CONFIGURED | `MdmService::status()['configured']` — **يتجاهل `can_enforce`** | **مشكوكٌ فيها** | كسابقتها (A16-01) |
| 111 | `endpoint.intune` | endpoint | EXTERNAL | EXTERNAL | `app/Support/Mdm/IntuneMdmProvider.php` — `canEnforce()` **false دوماً** | **مشكوكٌ فيها** | EXTERNAL تُوحي «تنقصها اعتمادات» والجسرُ الحيُّ مؤجَّلٌ أصلاً (A16-08) |
| 112 | `endpoint.jamf` | endpoint | EXTERNAL | EXTERNAL | `app/Support/Mdm/JamfMdmProvider.php` — `canEnforce()` **false دوماً** | **مشكوكٌ فيها** | كسابقتها (A16-08) |
| 113 | `endpoint.production_signing` | endpoint | NOT_CONFIGURED | NOT_CONFIGURED | لا موضعَ لاعتماداتِ توقيعٍ في النظام — حالةٌ **جامدة** | **صادقةٌ · جامدة** | لا فاحصَ يرفعها أبداً (A16-09) |
| 114 | `endpoint.windows_signing` | endpoint | NOT_CONFIGURED | NOT_CONFIGURED | كسابقتها — **جامدة** | **صادقةٌ · جامدة** | A16-09 |
| 115 | `endpoint.macos_signing` | endpoint | NOT_CONFIGURED | NOT_CONFIGURED | كسابقتها — **جامدة** | **صادقةٌ · جامدة** | A16-09 |
| 116 | `endpoint.apple_notarization` | endpoint | EXTERNAL | EXTERNAL | `EndpointRelease::NOTARIZATION_STATUSES` — **جامدة** | **صادقةٌ · جامدة** | A16-09 |
| 117 | `endpoint.signature_verification` | endpoint | SYSTEM_INVARIANT | SYSTEM_INVARIANT | وسيط `endpoint.signature` على `/api/v1/endpoint/*` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 118 | `endpoint.anti_replay` | endpoint | SYSTEM_INVARIANT | SYSTEM_INVARIANT | `app/Support/Es256.php` + nonce | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 119 | `mobile.backend` | mobile | READY | READY | ✔ شاشة `mobileplatform.index`<br>`app/Support/MobilePlatform.php` + `mobileplatform.index` (**غيرُ مُستعمَلٍ فاحصاً**) | **صادقة** | READY = الخلفيّةُ جاهزةٌ والأصيلُ مؤجَّل — يطابق `mobile.native_app` DEFERRED |
| 120 | `mobile.auth_apis` | mobile | ENABLED | ENABLED | `app/Http/Controllers/Api/MobileAuthController.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 121 | `mobile.collab_apis` | mobile | ENABLED | ENABLED | `app/Http/Controllers/Api/MobileCollabController.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 122 | `mobile.workos_apis` | mobile | ENABLED | ENABLED | `app/Http/Controllers/Api/MobileWorkController.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 123 | `mobile.openapi` | mobile | ENABLED | ENABLED | `app/Support/MobileOpenApi.php` + مسار `mobile.openapi` (**غيرُ مذكورٍ في `api_routes`**) | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 124 | `mobile.native_app` | mobile | DEFERRED | DEFERRED | قرارٌ مُعلَن — لا شيفرةَ أصيلة | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 125 | `mobile.production_push` | mobile | NOT_CONFIGURED | NOT_CONFIGURED | `PushService::status()['configured']` — فاحصٌ حقيقيّ | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 126 | `mobile.store_release` | mobile | DEFERRED | DEFERRED | تابعٌ للسابق | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 127 | `platform.api_surface` | platform | ENABLED | ENABLED | ✔ api `api.openapi` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 128 | `platform.openapi_generation` | platform | ENABLED | ENABLED | `app/Console/Commands/HubOpenApi.php` + `app/Support/OpenApi.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 129 | `platform.mobile_api_surface` | platform | ENABLED | ENABLED | `routes/api.php` بادئة `api/mobile/v1` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 130 | `platform.endpoint_api_surface` | platform | ENABLED | ENABLED | `routes/api.php` بادئة `v1/endpoint` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 131 | `platform.system_trace` | platform | ENABLED | ENABLED | ✔ `system.trace`<br>✔ شاشة `system.trace` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 132 | `platform.integrations` | platform | ENABLED | ENABLED | ✔ `integrations.index`<br>✔ شاشة `integrations.index` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 133 | `platform.webhook_infra` | platform | ENABLED | ENABLED | ✔ `webhooks.index`<br>✔ `hooks.index` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 134 | `platform.connection_checks` | platform | ENABLED | ENABLED | `app/Support/ConnectionProbe.php` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 135 | `platform.audit_tools` | platform | ENABLED | ENABLED | ✔ `audit.index`<br>✔ شاشة `audit.index` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 136 | `platform.monitor_analytics` | platform | ENABLED | ENABLED | ✔ شاشة `control.index` | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
| 137 | `platform.feature_registry` | platform | ENABLED | ENABLED | ✔ `features.index`<br>✔ `features.show`<br>✔ شاشة `features.index`<br>✔ docs/features/00-overview.md | **صادقة** | الشيفرةُ موصولةٌ ومطابقةٌ للحالة |
---

## ٥) اختبارُ ادّعاء «٠ حالةٍ كاذبة»

التقريرُ السابق يقول «٠ حالةٍ كاذبة». اختبرتُه فوجدتُ الادّعاءَ **مبنيّاً على فحصٍ لا يقيس ما يُنسَب إليه**:

`FeatureRegistryIntegrityTest` — الحارسُ الوحيدُ للسجلّ — يفحص ثمانيةَ أشياء، كلُّها **اتّساقُ الكتالوج مع نفسِه**: مفاتيحُ فريدة · حالاتٌ مشروعة · مجالاتٌ معروفة · اعتماديّاتٌ بلا دورات · مفاتيحُ إعدادٍ غيرُ يتيمة · ثابتٌ نظاميٌّ غيرُ قابلٍ للتبديل · مؤجَّلٌ غيرُ متاح · وخمسُ قدراتٍ بأسمائها الحرفيّة «ليست ENABLED زائفة». **ولا سطرَ واحدَ يفحص أنّ قدرةً `ENABLED` موصولةٌ بشيفرةٍ حيّة.** فـ«٠ مشكلة» تعني «الكتالوجُ متّسق»، لا «الكتالوجُ صادقٌ عن النظام». وفحصُ الخمسِ الحرفيّ يمرُّ حتميّاً لأنّ أربعاً منها حالاتٌ ساكنةٌ في الكتالوج (A16-09).

وما وجدتُه بالفحص المستقلّ:

| الصنف | العدد | القدرات |
|---|---|---|
| **كاذبةٌ جزئياً** | ١ | `security.step_up` — «لا تصير مفتاحاً» ولها مفتاحان (A16-02) |
| **تنقلب كاذبةً بسطرِ إعدادٍ واحد — مُثبَت** | ٢ | `endpoint.usb_enforcement` · `endpoint.mdm` (A16-01) |
| **مشكوكٌ فيها** | ٧ | + `endpoint.intune` · `endpoint.jamf` (A16-08) · `telecom.sim_registry` (ازدواجٌ مع `phone_registry`) · `telecom.provisioning_adapter` (READY بلا محوِّلٍ مُسمّى) · `client.visible_deliverables` (لا سطحَ مستقلّاً وجدتُه) |
| **صادقةٌ · جامدةٌ/ثابتة** | ٦ | الخمسُ في A16-09 + `collab.websocket_provider` (A16-10) |
| **صادقةٌ ومُتحقَّقة** | ١٢٣ | تتبّعتُ ٧٥ منها يدويّاً إلى شيفرةٍ حيّةٍ حين لم يكن في الكتالوج مؤشّر (الجدولُ أعلاه) |

**الحكم:** «٠ حالةٍ كاذبة» **لا يصمد** — وأهمُّها A16-01، لأنّه ليس خطأً في وصفٍ بل **انقلابُ معنى** يقع في اللحظة التي يُصدَّق فيها السجلُّ أكثرَ ما يُصدَّق: لحظةَ ضبطِ المزوّد.

---

## ٦) قراءةُ الحال — أين يقف المنتَج

الهندسةُ هنا أفضلُ من متوسّطِ ما يُرى، وبفارقٍ واضح: كاتبُ إعداداتٍ واحدٌ مُنفَّذٌ فعلاً لا موعود، وسجلُّ قدراتٍ بكتالوجٍ وحالاتٍ تسعٍ وفاحصاتٍ حقيقيّةٍ لمزوّدين، وطبقةُ MDM ترفض أن تقول «حُجب» حين لا تحجب، ومفتاحان وصفيّان (`audit.retention_*`) يُعلنان عن أنفسِهما أنّهما بلا مقصّ. هذه ثقافةُ صدقٍ مكتوبةٌ في الكود لا في وثيقةٍ جانبيّة.

والانحرافُ الذي وجدتُه له شكلٌ واحدٌ يتكرّر: **الوعدُ في الترويسة أوسعُ من التنفيذ تحته.**

- «تقرأ الأرضياتِ الحقيقية من الشيفرة» ⇒ تقرأ ما أُعلن في الكتالوج: ٨ من ٥٤.
- «قاعدةُ الرفض من الكتالوج — المصدرُ الواحد لكلِّ الأبواب» ⇒ `re` وحدَه: ١١ من ١١٤، و`in` و`ta` و`number` بلا حارس.
- «`depends` اسمُ مجموعةٍ تُتحقَّق مجتمعةً» ⇒ ٥ مجموعاتٍ من ١٣.
- «الخارجيُّ يُشتقُّ من فاحصٍ لا يُدّعى» ⇒ ٥ حالاتٍ مُدّعاةٌ ساكنة، وفاحصٌ ثابتٌ لا يفحص.
- «حدودُ التأكيد الإلزاميّة — لا تصير مفتاحاً» ⇒ مفتاحان.
- «`MobilePlatform` · `Integrations`» مصدرا فحصٍ ⇒ لا يُستشاران، وفرعُ `integration:` شيفرةٌ ميّتة.

وهذا **الشكلُ نفسُه** هو ما يجعل الخطرَ مستقبليّاً لا حاضراً: كلُّ واحدةٍ من هذه صادقةٌ في خطِّ الأساس لأنّ لا أحدَ ضبط MDM، ولا أحدَ كتب `strict` في سياسة التقارير، ولا أحدَ أنزل `auth.lock_min` إلى صفر. النظامُ يقول الحقيقةَ اليومَ لأنّ لا شيءَ تحرّك. وأخطرُ ما في ذلك أنّ **التوثيقَ الممتاز يشتري ثقةً لا يسندها الحارس**: قارئُ الكتالوج يصدّق «الأرضيةُ المفروضة» فلا يتحقّق، ومديرُ الأمن يصدّق «الإنفاذُ متاح» فلا يفتح جهازاً ليرى.

وفي الطرف الآخر ثلاثُ ملاحظاتٍ تقع اليومَ لا غداً، وهي أولى بالترتيب:

1. **A16-03** — حفظُ `auth.2fa_required_priv = 0` و`security.trusted_ips = 0.0.0.0/0` من جلسةٍ مسروقة، بلا تصعيدٍ ولا حدِّ معدّل، بينما **استعادةُ الافتراضيّ** تطلب إثباتَ هويّة. الحارسُ موضوعٌ على الاتجاه الأقلِّ خطراً — وهذا تصحيحٌ من ثلاثةِ أسطر، والأداةُ (`Settings::diff(...)['risky']`) محسوبةٌ سلفاً.
2. **A16-05** — المفتاحُ الذي يُبطل حارسَ SSRF يمرُّ في المعاينة سطراً عادياً بلا وسمٍ أحمر، بينما يناله `mail.from_name`. والنظامُ يعرف أنّه خطر (‏`SecurityPosture::ssrf`) في موضعٍ ولا يعرفه في آخر.
3. **A16-02** — ثابتٌ نظاميٌّ يُطفأ بأمرِ طرفيّةٍ واحد، والسجلُّ يقول للمدقّق إنّه لا يُطفأ.

**الأولويّة كما أراها:** A16-01 و A16-03 و A16-02 أولاً (أثرٌ أمنيٌّ مباشر أو انقلابُ معنى عند أوّل تغيير) · ثم A16-04 و A16-06 و A16-05 (الشاشةُ تعرض ما لا يسري، والكاتبُ يقبل ما لا يُقرأ) · ثم البقيّة.

وخيطٌ واحدٌ يشدُّ ستّاً من هذه الخمسَ عشرة: **الكتالوجان مليئان بحقولٍ وصفيّةٍ لا تُنفَّذ** — `risk` و`where` و`depends` و`validation.min/in` و`limitations`. كلُّ حقلٍ منها إمّا يُربَط بمُنفِّذٍ (‏`risk` غيرُ فارغٍ ⇒ عالي الخطورة · `depends` ⇒ قاعدةٌ في `DEPENDS` · `min` ⇒ رفضٌ أو أرضيّةٌ معروضة · `where` ⇒ يُفحَص ساكناً)، وإمّا يُحذَف. فالحقلُ الوصفيُّ الذي يبدو حارساً ولا يحرس أسوأُ من غيابه — وهي القاعدةُ نفسُها التي يقوم عليها هذا المستودع: **«إعدادٌ بلا قارئ كذبةٌ في الواجهة»** — تُطبَّق على الكتالوج كما تُطبَّق على الإعداد.

---

## ٧) المسابرُ المستعمَلة (خارج المستودع)

`/tmp/claude-0/-home-user-lynomia-hub/6db9f702-7ba4-5842-b125-87142df990fc/scratchpad/probe/`

| الملفّ | ما يُثبته |
|---|---|
| `A16MdmProbeTest.php` | A16-01 — `configured=true`/`can_enforce=false` ⇒ `READY` و«الإنفاذُ متاح» |
| `A16SettingsProbeTest.php` | A16-02 · A16-03 · A16-04 · A16-06 · A16-14 (٦ اختبارات · ١٢ تأكيداً · كلُّها خضراء) |
| `A16RiskProbeTest.php` | A16-05 — `monitor.allow_private` ليس عاليَ الخطورة، ومعاينتُه `risky=false`، واستعادتُه بلا تصعيد |
| `A16CountsProbeTest.php` | ١٣٧ قدرة · العدُّ يطابق المقيس · انحرافُ المُعلَن عن المُشتقّ = ٠ في خطِّ الأساس |
| `dump_settings.php` · `dump_features.php` · `gen_*_table.php` · `wherecheck2.php` | جردُ الجدولين وفحصُ مؤشّرات `where` |

**لم يُعدَّل أيُّ ملفٍّ في المستودع سوى هذا التقرير. ولم يُلمَس `lynomia_month`.**
