# الوكيل ٠٤ — عزلُ الشركات والعملاء والمشاريع · طبقة ١ · الجولة أ

**المستودع:** `/home/user/lynomia-hub` · **الرأس:** `1d90626` (v2.540.1) · **التاريخ:** 2026-09-17

**المنهج:** هجومٌ حيٌّ لا قراءةُ كود. عالَمان مزروعان (شركةُ «أ» وشركةُ «ب»)، ومستخدمٌ
**بكلِّ صلاحيّاتِ المصفوفة وكلِّ الأعلام** (`fieldsec`/`docsec`/`approve`/`audit`/`monitor`/
`exp`/`users`/`secrets`) ومعزولٌ بـ`companies = [أ]` وحدَها — أسوأُ حالةٍ واقعيّة: إداريٌّ
كاملُ الصلاحيّة داخلَ شركته. كلُّ سجلٍّ في عالَمِ «ب» يحمل كلمةَ سرٍّ نصّيّةً
(`SECRETPROJ`, `SECRETCLIENT`, `SECRETEMP`…)، وكلُّ حكمٍ أدناه مبنيٌّ على **استجابةٍ
فعليّةٍ** فيها هذه الكلمة أو على غيابها. التأكيداتُ تمرّ بـ`TestCase::assertMaskedValueAbsent()`
(تطرح بصماتِ UUID/CSRF) فلا يُقرَع الحارسُ على معرّفٍ (درسُ v2.540.0 في `CLAUDE.md`).

**لم يُعدَّل أيُّ ملفٍّ في المستودع سوى هذه الوثيقة.** اختباراتُ الهجوم كُتبت خارج `tests/`
في `…/scratchpad/a04probe/` وشُغِّلت بـ`./vendor/bin/phpunit -c …/a04.xml`.

---

## ١ · الحكم

| الهدفُ الرقميّ | ادّعاءُ التقرير السابق | ما أثبتُّه |
|---|---|---|
| تسريبُ الشركات = 0 | صفر | **✗ مكسور — ٧ تسريباتٍ مُثبَتة** (٤ منها P0) |
| تسريبُ داخليّاتِ العميل = 0 | صفر | **✓ صمد** — لم أخترقه بعد ٢١ محاولة (§٤) |

**السببُ الجذريُّ المشترك لأربعةٍ من السبعة واحد:** `hub_scope` سكّةٌ صلبة، لكنّ النظامَ
يقرأ من **خارجها** في مواضعَ مُعدَّدة — `DB::table()` خامٍّ لبناء منسدلةٍ أو أثرٍ أو عدّاد،
وهذه المواضعُ لا يحرسها شيء. والدليلُ على أنّه سهوٌ لا قرار: في كلِّ موضعٍ من الأربعة
يقع **بجوارِه سطرٌ منطَّقٌ صحيحٌ يفعل الشيءَ نفسَه** (منسدلةُ الشركات منطَّقةٌ والمشاريعِ
ليست؛ `visibleUserIds()` منطَّقةٌ وأثرُ التدقيق بجوارها ليس؛ عدّاداتُ الهوية الأربعةُ
منطَّقةٌ والخامسُ ليس؛ قواعدُ التنبيه تُنطَّق لكلِّ متلقٍّ وتصعيدُ SLA تحتها لا).

---

## ٢ · جدولُ الأسطحِ المُهاجَمة

| # | السطحُ المُهاجَم | النتيجة | الدليل |
|---|---|---|---|
| 1 | `/m/{module}/{id}` لكلِّ الوحدات ذاتِ عمودِ الشركة | **صمد** | ٤٠٤ على companies/clients/projects/hr/assets/stations/tickets/files/tasks |
| 2 | **`/m/{module}/{id}` للوحدات بلا عمودِ شركة** | **تسرّب `A04-01`** | `m/incidents/{B}` → ٢٠٠ بالمحتوى كاملاً |
| 3 | **تسميةُ مرجعٍ خارجَ النطاق على صفحةِ سجلٍّ داخلَه** | **تسرّب `A04-02`** | «المشروع: مشروع باء SECRETPROJ» على صفحةٍ يملكها القارئ |
| 4 | كنسٌ آليٌّ لكلِّ مسارات GET ذاتِ معاملٍ (104 مساراً × 9 معرّفات) | **صمد** | لا صفحةَ واحدةَ ٢٠٠ تحمل سرَّ «ب» |
| 5 | كنسٌ آليٌّ لكلِّ صفحاتِ GET بلا معاملات (141 مساراً) | **تسرّب `A04-03` + `A04-04`** | `admin/users/create` · `admin/audit` |
| 6 | المعرّفُ المتداخل: محادثةٌ/رسالة · وثيقةُ عميلٍ آخر · مرفقٌ على سجلِّ الغير | **صمد** | ٤٠٤ في كلِّ الحالاتِ العشر |
| 7 | `api/v1/{module}` و`{id}` و`metrics` و`identity/resolve` و`reports/progress` | **تسرّب (نفسُ `A04-01`)** | `api/v1/incidents` و`api/v1/incidents/{id}` → ٢٠٠ |
| 8 | `api/mobile/v1/*` بجلسةِ جوالٍ حقيقيّة + `sync/{module}` | **تسرّب (نفسُ `A04-01`)** | `mobile/v1/incidents` و`sync/incidents` → ٢٠٠ |
| 9 | التنزيلُ والمرفقات: `attachments/{id}/dl|view` · `zip` · بثُّ ملفّات الجوال | **صمد** | ٤٠٤ (`AttachmentService::guardRecord` يمرّ بـ`hub_scope`) |
| 10 | التصدير CSV لسبعِ وحدات (`m/{module}/export`) | **صمد** | لا سرَّ «ب» في أيِّ مجرى — ومنطَّقٌ كالشاشة |
| 11 | البحثُ الشامل · `search/mini` · `search/messages` (عربيّاً ولاتينيّاً) | **صمد** | ٢٠٠ نظيفة |
| 12 | مستكشفُ العلاقات `graph/explore` + الجذرُ الأجنبيّ | **صمد** | ٤٠٤ على جذرِ «ب»؛ كلُّ عقدةٍ تمرّ بـ`hub_read` |
| 13 | **الإشعارات — تصعيدُ SLA** | **تسرّب `A04-05`** | إشعارٌ في جرسِ قارئِ «أ» يحمل عنوانَ تذكرةِ «ب» |
| 14 | **العدّادات** (فرقُ صفحةٍ آليّ: ٧٩ صفحةً قبلَ زرعِ «ب» وبعده) | **تسرّب `A04-06`** | «🆔 9 معرّفاً في السجل» والمرئيُّ 1 |
| 15 | **جنائيّاتُ عنوانِ الشبكة `admin/security/ips/{ip}`** | **تسرّب `A04-07`** | «تعديل · مشروع باء SECRETPROJ · منذ ثانية» |
| 16 | بوّابةُ العميل — التكلفةُ والهامشُ والتعليقُ الداخليُّ والوثيقةُ الداخليّة | **صمد** | صفرُ ظهورٍ لـ`777111`/`888222`/`INTERNALNOTE`/`INTERNALDOC`/`INTERNALCOMMENT` |
| 17 | بوّابةُ العميل — عبورُ الحدِّ إلى عميلٍ آخر (مشروع/وثيقة/فاتورة/تذكرة) | **صمد** | ٤٠٤ في الأربعة |
| 18 | بوّابةُ العميل — فاتورةُ مشترياتٍ (رقمٌ داخليّ) | **صمد** | ٤٠٤ (`CLIENT_INVOICE_KINDS` في `hub_scope`) |
| 19 | نماذجُ الإنشاء والتحرير لكلِّ الوحدات (٨٥ × create + ٨ × edit) | **صمد** | «ALL FORMS CLEAN» — `hub_ref_options_scoped` يعمل |
| 20 | مسحُ الأكواد `s/{code}` · `p/{code}` · `c/{code}` · `identity/resolve` | **صمد** | ٤٠٤ على أكواد «ب» |
| 21 | لوحاتُ المنشأة (`admin/security` · `admin/errors` · `admin/activity`) | **صمد** | ٤٠٣ — `hub_org_analytics_guard` وحارسُ المالك |

---

## ٣ · التسريباتُ المُثبَتة

### `A04-01` · **ثمانيةُ وحداتٍ خارجَ عزلِ الشركاتِ كلّيّاً** — P0

**الطلبُ والاستجابة** (قارئٌ معزولٌ على «أ»):

```
POST (زرعٌ)  incidents{ title:'SECRETINC', project_id: <مشروعُ «ب»> }
GET /m/incidents/{id}                       → 200
GET /api/v1/incidents/{id}                  → 200  (Bearer lyn_…)
GET /api/mobile/v1/incidents/{id}           → 200  (جلسةُ جوالٍ حقيقيّة)
GET /api/mobile/v1/sync/incidents           → 200  ← المزامنةُ تُنزِّلها على الجهاز
GET /m/projects/{مشروعُ «ب»}                 → 404  ← المشروعُ نفسُه محجوب
```

جسمُ الاستجابة (منزوعُ الوسوم):

> 📋 البيانات · عنوان الحادث **انقطاعُ SECRETINC** · مستوى الحادث حرجة · **المشروع مشروع باء SECRETPROJ** ·
> **العملاء المتأثرون وحجم الأثر عميل باء SECRETCLIENT** · **السبب الجذري سببٌ داخليٌّ SECRETROOT**

**السببُ الجذريّ:** `app/Support/helpers.php:214` —

```php
if (($cids = hub_company_ids($user)) !== null && ($ccol = hub_company_col($module))) {
```

الشرطُ الثاني (`$ccol`) يُسقط العزلَ بصمتٍ حين لا تجد الوحدةُ عمودَ شركة. وثمانُ وحداتٍ
من ٨٥ في هذه الحال — وكلُّها **وحداتُ عملٍ لا وحداتُ نظام**:

| الوحدة | الجدول | ما تحمله عن «ب» |
|---|---|---|
| `incidents` | `incidents` | مشروعُها · `clients_hit` · `root_cause` · `lessons` · `postmortem` |
| `requests` | `internal_requests` | مشروعُها · `description` · `justification` · `est_cost` |
| `deploys` | `deployments` | مشروعُها · `commit` · `changes_log` |
| `ideas` | `ideas` | مشروعُها · `problem` · `idea` |
| `deps` | `dependencies` | مشروعُها · مورّدُها · `fallback` · `rto_hours` |
| `restores` | `restore_tests` | نتائجُ استعادةِ نُسَخِ الغير |
| `competitors` | `competitors` | تحليلُ منافسي شركةٍ أخرى بالكامل |
| `plans` | `pricing_plans` | تسعيرُ شركةٍ أخرى |

التعليقُ في `helpers.php:181` يقول «الوحدات بلا عمود مشروع/شركة تبقى محكومة بمصفوفة
الصلاحيات وحدها» — وهو وصفٌ دقيقٌ للسلوك، لكنّه **ليس قراراً يصحّ لهذه الثمانية**: خمسٌ
منها تحمل `project_id` صراحةً، فالشركةُ معروفةٌ عنها بقفزةٍ واحدة. والفرقُ بين «قرارٍ»
و«فجوة» أنّ القرارَ يُختار لكلِّ وحدةٍ بعينها؛ وهنا الفتحُ **تلقائيٌّ** لكلِّ وحدةٍ
سُهيَ عن عمودها — فأيُّ وحدةٍ تُضاف غداً بلا `company_id` تولد مفتوحة.

**الإصلاح (الأضيق):** انحسارٌ عبر المشروع حين يغيب عمودُ الشركة — في `hub_scope` نفسِها،
بعد بندِ الشركة مباشرةً:

```php
// وحدةٌ بلا عمودِ شركةٍ لكن لها عمودُ مشروع: الشركةُ تُستنتَج من المشروع
if ($cids !== null && ! hub_company_col($module) && ($pcol = hub_project_col($module))) {
    $ok = DB::table('projects')->whereIn('company_id', $cids)->pluck('id');
    $q->where(fn ($w) => $w->whereIn($pcol, $ok)->orWhereNull($pcol));
}
```

و`restores`/`competitors`/`plans` (بلا مشروعٍ أصلاً) تُعطى عمودَ `company_id` بهجرةٍ
إضافيّةٍ غيرِ مدمِّرة، أو تُنقَل إلى قائمةٍ بيضاءَ صريحةٍ في السجلّ تقول «عالميّةٌ عمداً»
— **المهمُّ أن يكون الفتحُ مُعلَناً مُعدَّداً لا افتراضاً صامتاً.**

**اختبارُ الانحدار:** `test_a04_01_modules_without_company_column_are_not_isolated` (§٦).

---

### `A04-02` · **صفحةُ سجلٍّ داخلَ النطاق تُسمّي سجلاً خارجَه** — P0

مستقلٌّ عن `A04-01`: حتى حين يكون السجلُّ المضيفُ **داخلَ** نطاقِ القارئ تماماً، تُحَلُّ
تسميةُ مرجعِه بلا نطاق.

**الطلبُ والاستجابة:**

```
وثيقةٌ company_id = «أ»  (داخلَ النطاق)  ·  project_id = مشروعُ «ب» (خارجَه)
GET /m/files/{id}   → 200 ... «المشروع: مشروع باء SECRETPROJ»
GET /m/projects/{مشروعُ «ب»} → 404
```

**السببُ الجذريّ:** `app/Http/Controllers/Web/ModuleController.php:1684`

```php
$labels[$f['key']] = hub_ref_labels($f['ref'], $ids);
```

و`hub_ref_labels` (`app/Support/helpers.php:727`) استعلامٌ خامٌّ بلا نطاق:

```php
return DB::table($table)->whereIn('id', $ids)->pluck(hub_ref_display($ref), 'id')->all();
```

والدليلُ على السهو أنّ **السطرَ الذي فوقَه مباشرةً** (`:1679`) يقنّع حقولَ `edge` بـ«—»
حين لا يملك القارئُ وحدةَ الطرف الآخر، بتعليقٍ يقول حرفيّاً «الاسمُ وحده تسريبٌ» —
فالمبدأُ معروفٌ ومطبَّقٌ على الصلاحيّة، ومتروكٌ على النطاق. و`hub_ref_labels` يُستدعى من
**٢٤ موضعاً** (Asset360 · Station360 · Employee360 · ExecutionStats · AttentionQueue …)
فالسطحُ أوسعُ من صفحةِ السجلّ.

**الإصلاح:** وسيطٌ اختياريٌّ `?string $scopeAs = null` على `hub_ref_labels` يمرّ بـ
`hub_read($ref)` بدل `DB::table` حين يُذكَر، ثم قناعُ «—» لما سقط — ثم تمريرُه من
`ModuleController::refLabels` ومن نظائر 360. النمطُ موجودٌ بحذافيره في
`RelationshipProjection::label()`؛ يُنسَخ لا يُخترَع.

**اختبارُ الانحدار:** `test_a04_02_ref_label_of_out_of_scope_record_is_masked`.

---

### `A04-03` · **منتقي الشركات في نموذج المستخدم يُسمّي كلَّ مستأجري النظام** — P1

**الطلبُ والاستجابة:**

```
GET /admin/users/create   (إداريٌّ معزولٌ على «أ»، يحمل علمَ users)   → 200
```

> عزل الشركات — اتركها كلها بلا تحديد ليصل لكل الشركات · **شركة ألف · شركة باء SECRETCO** ·
> عند التحديد يُعزل المستخدم صرامةً…

ونظيرُها `GET /admin/users/{id}/edit` (المصدرُ نفسُه).

**السببُ الجذريّ:** `app/Http/Controllers/Web/UserController.php:72`

```php
protected function companies()
{
    return \App\Models\Company::whereNull('deleted_at')->orderBy('name_ar')->pluck('name_ar', 'id');
}
```

تُستهلَك في `create()` (`:89`) و`edit()` (`:150`). والكتابةُ محروسةٌ بإحكام — `store` يقاطع
مع `hub_company_ids()` (v2.319) و`update` كذلك بعد `TenancyLeakRound8` — **لكنّ القراءةَ
لم تُحرَس قطّ**: المعزولُ يتعلّم أسماءَ كلِّ الشركاتِ المستأجرة ويستنتج عددَها. وفي منتَجٍ
متعدّدِ المستأجرين هذا إفشاءُ قائمةِ عملاءِ البائع نفسِه.

**الإصلاح:** سطرٌ واحد — `hub_ref_options_scoped('companies', $u?->companies)`. الدالّةُ
قائمةٌ في `helpers.php:986` وتفعل هذا بعينه (وتُبقي القيمةَ المحفوظةَ عبر `$ensure` فلا
يَعمى نموذجُ تحريرِ حسابٍ قديم).

**اختبارُ الانحدار:** `test_a04_03_user_form_company_picker_is_scoped`.

---

### `A04-04` · **منسدلاتُ سجلِّ التدقيق تُسمّي كلَّ المشاريع وكلَّ العملاء** — P1

**الطلبُ والاستجابة:**

```
GET /admin/audit   (معزولٌ على «أ»، يحمل علمَ audit)   → 200
```

> كل الشركات **شركة ألف** ← منطَّقةٌ صحيحاً
> كل المشاريع مشروع ألف**مشروع باء SECRETPROJ** ← تسرّب
> كل العملاء عميل ألف**عميل باء SECRETCLIENT** ← تسرّب

**السببُ الجذريّ:** `app/Http/Controllers/Web/AuditController.php:84‑91`

```php
'projects' => DB::table('projects')->…
    ->when(hub_scoped($u), fn ($w) => $w->whereIn('id', $u->visibleProjectIds()))   // ← المشاريعُ فقط
    …
'clients' => DB::table('clients')->…
    ->when($kids !== null, fn ($w) => $w->whereIn('id', $kids))                      // ← عزلُ العملاءِ فقط
```

الشرطان صحيحان لكنّهما **الشرطان الخطأ**: `hub_scoped()` تسأل «أدورُه `scope=proj`؟»
و`$kids` تسأل «أمعزولٌ بعملاء؟» — ولا واحدةٌ منهما تسأل «أمعزولٌ بشركات؟». وقارئُنا
`scope=all` بلا عزلِ عملاء، فيمرّ من الشرطين. والسطرُ الذي **فوقهما مباشرةً**
(`:80‑83`) يطبّق `$cids` على منسدلةِ الشركات تطبيقاً سليماً — والتعليقُ فوقَ الثلاثةِ
يقول «مصادرُ المنسدلات منطَّقةٌ كالقائمة نفسِها: المحصورُ لا تُسمّى له شركةٌ أو عميلٌ
خارج نطاقه ولو في مرشِّح». النيّةُ مكتوبةٌ والتنفيذُ ناقصٌ ثلثين.

**ملاحظةٌ مطمئنة:** صفوفُ الأثرِ نفسُها و**كلُّ** مؤشّراتِ المدى (`counters()`) منطَّقةٌ
سليمةً عبر `Audit::scopedQuery` — التسريبُ في المنسدلتين حصراً.

**الإصلاح:**

```php
'projects' => DB::table('projects')->…
    ->when(hub_scoped($u), fn ($w) => $w->whereIn('id', $u->visibleProjectIds()))
    ->when($cids !== null, fn ($w) => $w->whereIn('company_id', $cids))       // ← المضاف
'clients'  => DB::table('clients')->…
    ->when($kids !== null, fn ($w) => $w->whereIn('id', $kids))
    ->when($cids !== null, fn ($w) => $w->whereIn('company_id', $cids))       // ← المضاف
```

**اختبارُ الانحدار:** `test_a04_04_audit_filters_are_company_scoped`.

---

### `A04-05` · **إشعارُ تصعيدِ SLA يعبر حدَّ الشركة بعنوانِ التذكرة** — P0

هذا هو **البندُ الثامنُ من خطّةِ الهجومِ حرفيّاً**: «هل يصل إشعارٌ يحمل عنوانَ سجلٍّ
خارجَ نطاقِ المتلقّي؟» — نعم.

**الطلبُ والاستجابة:**

```
تذكرةٌ: client_id=«ب» · company_id=«ب» · بلا مسؤول · بلا مشروع · عاجلة · عمرُها 30 يوماً
(new AlertEngine)->evaluate();
```

صفُّ `hub_notifications` لقارئِ «أ» (حاملِ علمِ `approve`):

> `kind=ticket  module=tickets`
> `📣 تصعيد SLA: تذكرة «عطلٌ في نظامِ SECRETTICKETSLA» متجاوزةٌ موعدَ الحلّ منذ 29 أيام وبلا مسؤولٍ مُسنَد — تحتاج صاحباً`

```
GET /notifications      → 200  والنصُّ كاملاً في الصفحة
GET /notifications/{id}/go → 302 → /m/tickets/{id}  ثمّ 404
```

الوجهةُ محروسةٌ — **والعنوانُ قد تسرّب قبلها.** والقارئُ لا يحتاج للنقر: الجرسُ يعرض النصّ.

**السببُ الجذريّ:** `app/Support/AlertEngine.php:432`

```php
if (! $told) {
    foreach ($this->approvers() as $uid) {
        $tell($uid, "📣 تصعيد SLA: تذكرة «{$subject}» …");
    }
}
```

و`approvers()` (`:296`) تجمع **كلَّ** حاملي علمِ `approve` في النظام بلا أيِّ نطاق:

```php
return $this->approverIds ??= User::whereNull('deleted_at')->with('role')
    ->orderBy('created_at')->orderBy('id')->get()
    ->filter(fn ($u) => hub_flag($u, 'approve') || $u->role?->is_owner)
    ->take(5)->pluck('id')…;
```

والفرعان الآخران (`$t->assignee_id` و`$mgrUid` و`$pmUid`) بلا فحصِ نطاقٍ كذلك — تذكرةُ
«ب» تُسنَد خطأً لموظّفِ «أ» فيصله عنوانُها ثمّ يُردُّ ٤٠٤ عن الرابط.

**والتناقضُ يُثبت أنّه سهو:** في الملفِّ نفسِه، على بُعدِ ٢٣٠ سطراً (`:197‑202`)، تُنطَّق
قواعدُ التنبيه **لكلِّ متلقٍّ على حدة** بتعليقٍ صريح: «النطاق يُفرض لكل مستلم على حدة —
القاعدة لا تُسرّب عنوان سجل خارج نطاقه». تصعيدُ SLA أُضيف لاحقاً (الجولة 1 · F33)
ولم يرث الدرس.

**الإصلاح:** يُلفّ `$tell` بفحصِ نطاقٍ لكلِّ متلقٍّ — بالسكّةِ نفسِها لا بثانية:

```php
$tell = function ($uid, string $text) use (&$told, $t, &$out) {
    $uid = (string) ($uid ?: '');
    if ($uid === '' || isset($told[$uid])) return;
    $u = \App\Models\User::find($uid);
    // لا يُخبَر بعنوانِ تذكرةٍ من لا يقرؤها — العزلُ قبل الإسناد (نظير :197)
    if (! $u || ! hub_scope(\App\Models\Ticket::query(), 'tickets', $u)->whereKey($t->id)->exists()) return;
    …
};
```

وتبقى قاعدةُ «لا ختمَ لتصعيدٍ لم يبلغ أحداً» (`:439`) عاملةً كما هي — فالتذكرةُ اليتيمةُ
تبقى في الطابور حتى تجد صاحباً **داخلَ شركتها**.

**اختبارُ الانحدار:** `test_a04_05_sla_escalation_respects_recipient_scope`.

---

### `A04-06` · **العدُّ نفسُه تسريب: «معرّفاً في السجل» عدٌّ عالميّ** — P2

اكتُشف بفرقٍ آليّ: لُقِطت ٧٩ صفحةً بلا معاملات بعينِ قارئِ «أ» قبلَ زرعِ عالَمِ «ب»
وبعده (بزمنٍ مُجمَّد `Carbon::setTestNow` لنفيِ فروقِ «منذ كذا ثانية»)، ثم قورنت أرقامُها.

**الطلبُ والاستجابة:**

```
GET /identity   قبلَ زرعِ أصولِ «ب»  → «🧬0 طرازاً ✅0 موثّقاً 📦0 قطعة 🔗0 مربوطة 🆔0 معرّفاً في السجل»
GET /identity   بعدَ زرعِ 10 أصولٍ لـ«ب» → «🧬0 …  🔗0 مربوطة  🆔10 معرّفاً في السجل»   ← الرقمُ وحدَه تحرّك
```

بطاقةٌ تقول «١٠» والقارئُ يرى صفراً — العدُّ يفشي وجودَ عشرةِ أصولٍ في شركةٍ أخرى،
ويصير عدّادَ نموٍّ مجّانيّاً على منافسٍ يشارك المنصّة.

**السببُ الجذريّ:** `app/Http/Controllers/Web/IdentityController.php:54`

```php
'nIds' => RecordIdentifier::count(),
```

وفوقَه بثمانيةِ أسطر (`:45‑47`) تعليقٌ وعقدٌ صريح: «**الأرقام على نطاق القارئ — لا لوحة
منشأةٍ لمعزول**»، وأربعةُ عدّاداتٍ منطَّقةٌ بـ`hub_company_scope(hub_scope(…))`.
الخامسُ وحدَه أفلت.

**الإصلاح:**

```php
'nIds' => \App\Models\RecordIdentifier::whereIn('module', array_keys(hub_modules()))
    ->where(fn ($w) => collect(['assets', 'products'])
        ->each(fn ($m) => $w->orWhere(fn ($x) => $x->where('module', $m)
            ->whereIn('record_id', hub_read($m)?->pluck('id') ?? []))))
    ->count(),
```

أو الأبسطُ والأمتنُ: عدُّ المعرّفاتِ التابعةِ للصفوفِ التي يعدّها `$aQ()`/`$pQ()` أصلاً.

**ملاحظةٌ على السطر `:56`:** `IdentityLookup::orderByDesc('checked_at')->limit(8)` تعرض
آخرَ ما استُكشف خارجيّاً — وهو كاشُ باركوداتٍ عالميّةٍ لا صفوفَ مستأجِر، لكنّه يُفشي
**ما مسحه مستأجرٌ آخرُ لتوّه**. أقلُّ خطورةً وأستحقُّ نظرةً في جولةٍ لاحقة.

**اختبارُ الانحدار:** `test_a04_06_identity_counter_is_scoped`.

---

### `A04-07` · **جنائيّاتُ عنوانِ الشبكة تكشف أثرَ شركةٍ أخرى بعناوينِ سجلّاتها** — P0

**الطلبُ والاستجابة:**

```
مستخدمُ «ب» يعدّل مشروعَه من 203.0.113.77
GET /admin/security/ips/203.0.113.77   بعينِ قارئِ «أ» (علمُ monitor) → 200
```

> **🧾 أحدث الأثر في التدقيق** — الفعل: **تعديل** · الاسم/الهدف: **مشروع باء SECRETPROJ** · متى: منذ ثانية

**السببُ الجذريّ:** `app/Http/Controllers/Web/SecurityController.php:792`

```php
$trail = DB::table('audits')->where('ip', $ip)
    ->orderByDesc('created_at')->orderByDesc('id')->limit(15)
    ->get(['action', 'name', 'created_at']);
```

استعلامٌ خامٌّ على `audits` — بينما الشاشةُ المخصَّصةُ للأثر (`AuditController`) تمرّ دائماً
بـ`Audit::scopedQuery($user)` الذي يفرض `whereIn('audits.company_id', $cids)`.

**والملفُّ نفسُه يعرف الدرسَ في ثلاثةِ مواضعَ حولَه:**
- `:776` `$vis = $this->visibleUserIds();` ثمّ `:783` `->whereIn('user_ips.user_id', $vis)` — قائمةُ المستخدمين **منطَّقةٌ بالشركة**.
- `:795‑802` طمسُ البريدِ في `name` لغير المالك — الحقلُ نفسُه يُعالَج أمنيّاً، للـPII لا للنطاق.
- `:359‑363` `findingsQuery()` يمرّ بـ`SecurityFindings::scopeCompanies(…, hub_company_ids())`.

فالمُهملُ هو النطاقُ على الأثرِ وحدَه. ويزيدُه غرابةً أنّ `/admin/security` (الجذر) يردّ
**٤٠٣** على القارئ نفسِه، بينما صفحةُ العنوانِ الأعمقُ تردّ ٢٠٠ — الحارسُ الخارجيُّ
موجودٌ والباطنيُّ مفقود.

**و`$denials` (`:803‑806`) نظيرُها:** تعرض `path` الخام، وهو يحمل معرّفاتِ سجلّاتِ الغير.

**الإصلاح:**

```php
$trail = \App\Support\Audit::scopedQuery(auth()->user())->where('audits.ip', $ip)
    ->orderByDesc('audits.created_at')->orderByDesc('audits.id')->limit(15)
    ->get(['action', 'name', 'created_at']);
```

سطرٌ واحد، بالسكّةِ القائمة، بلا محرّكِ عزلٍ ثانٍ.

**اختبارُ الانحدار:** `test_a04_07_ip_forensics_trail_is_company_scoped`.

---

## ٤ · ما هاجمتُه وصمد — بالتفصيل

### ٤٫١ العزلُ الأساسيُّ للسجلّات (المعرّفُ المباشر)

`m/{module}/{id}` على companies · clients · projects · hr · assets · stations · tickets ·
files · tasks — **٤٠٤ في التسعة**. و`employee/{id}` و`journey/{id}` و`inventory/{id}` و
`trace/{module}/{id}` و`graph/explore` كذلك. السببُ أنّ `hub_scope` يسبق `findOrFail` في
كلِّ هذه المسارات فيصير الحجبُ «غيرَ موجود» لا «ممنوع» — والعقدُ محفوظ.

### ٤٫٢ الكنسُ الآليُّ لكلِّ مسارٍ ذي معامل

كُنِس **كلُّ** مسارِ GET في `Route::getRoutes()` يحوي معاملاً (104 مساراً)، كلٌّ منها بتسعةِ
معرّفاتٍ من عالَمِ «ب» (وبتعويضِ `{module}` بوحدتها الصحيحة في المسارات ثنائيّةِ المعامل).
النتيجة: **صفرُ صفحةٍ ٢٠٠ تحمل سرَّ «ب»** — الوحيدةُ التي ردّت ٢٠٠ هي `system/trace/{rid}`
وفُحصت يدويّاً بمعرّفِ ارتباطٍ حقيقيٍّ من طلبِ مستخدمٍ آخر: لا تُظهر شيئاً.

### ٤٫٣ المعرّفُ المتداخل

- **محادثةُ شركةِ «ب» + رسالةٌ فيها** → `conversations/{id}` و`/since` ٤٠٤.
- **مراقبةُ الامتثال** `oversight/{id}?reason=…` → ٤٠٣ (وفوقَها فحصُ `company_id`/`client_id`
  الصريحُ في `OversightController:194‑201` — دفاعٌ في العمق مكتوبٌ بوعي).
- **عرضُ سعرٍ ومشترياتٌ ومورّدٌ لشركةِ «ب»** → `m/quotes/{id}` · `quote/{id}/doc|pdf|diff` ·
  `m/purchases/{id}` · `purchase/{id}/doc` · `m/suppliers/{id}` — ٤٠٤ في السبعة.
- **مرفقٌ على سجلِّ شركةِ «ب»** → `attachments/{id}/dl` و`/view` و`attachments/projects/{B}/zip`
  ٤٠٤ (`AttachmentService::guardRecord` يمرّ بـ`hub_scope` قبل البثّ).
- **`app/{id}` · `boards/{id}` · `endpoints/{id}`** → ٤٠٤.

### ٤٫٤ الـAPI وسطحُ الجوال

`api/v1/{module}` للوحدات التسع → القوائمُ منطَّقةٌ (صفٌّ واحدٌ لكلٍّ)، و`{id}` الأجنبيُّ
٤٠٤، و`metrics/{module}/{id}` و`reports/progress/{projectId}` و`identity/resolve/{q}` و
`projects/{id}/assets` و`assets/{id}/projects` كلُّها ٤٠٤. وعلى الجوال — بجلسةٍ حقيقيّةٍ
مسكوكةٍ من `POST auth/login` لا بـ`actingAs` — `projects`/`hr`/`clients`/`files` و
`sync/{module}` منطَّقةٌ، و`portal/*` يردّ ٤٠٣ للداخليّ. الانحرافُ الوحيدُ هو `incidents`
وأخواتُها (`A04-01`) — أي أنّ سطحَ الجوال **يرث** عزلَ الويب بأمانة، لا أكثرَ ولا أقلّ.

### ٤٫٥ التصديرُ منطَّقٌ كالشاشة

`m/{module}/export` لسبعِ وحدات — لا سرَّ «ب» في أيِّ مجرى. (والتصديرُ يُقرأ عبر
`streamedContent()` لا `getContent()`؛ فحصٌ بـ`getContent()` وحده كان سيمرّ كاذباً.)

### ٤٫٦ البحثُ ولوحةُ الأوامر

`search?q=` و`search/mini?q=` و`search/messages?q=` — بكلمةِ السرِّ اللاتينيّة `SECRET`
وبالعربيّة «باء». ٢٠٠ نظيفة في الستّ.

### ٤٫٧ مستكشفُ العلاقات — أمتنُ ما في المنظومة

قرأتُ `RelationshipProjection` سطراً سطراً وحاولتُ العبورَ عبر وسيط. لا يُعبَر، **بالبناء**:
كلُّ عقدةٍ — أماميّةً كانت أو عكسيّةً أو حافّةَ `asset_project_assignments` أو الحافّةَ
المشتقّةَ محطّة→مشروع — تمرّ بـ`hub_read($ref)` **قبل** أن تُدرَج، والحافّةُ لا تُبنى إلّا بين
طرفَين مُدرَجَين. وأدقُّ ما فيه اثنان: العدّادُ (`counts`) يُحسب على الاستعلامِ **المُرشَّح**
لا الخام (فلا يفشي عدداً يحجبه العزل)، ومفتاحُ الخبيئة `hub_scope_key` يحمل بصمةَ القارئ
كاملةً (دورٌ · مستخدمٌ · شركةٌ · عميلٌ · عدسةٌ · ختمُ صلاحيّات) فلا يتقاسم قارئان إسقاطاً.
هذا هو المستوى الذي يجب أن تبلغه بقيّةُ الأسطح.

### ٤٫٨ بوّابةُ العميل — صمدت أمام إحدى عشرة محاولة

زُرِع لعميلِ «أ»: مشروعٌ بتكلفةٍ `777111` وميزانيّةٍ `888222` وإيرادٍ متوقَّعٍ `999333`
وملاحظةٍ داخليّةٍ `INTERNALNOTE`؛ ووثيقةٌ `audience=internal` باسمٍ `INTERNALDOC`؛ وفاتورةُ
مشترياتٍ `PUR-COSTDOC`؛ وتعليقٌ `internal=true` باسمِ `INTERNALCOMMENT` وردٌّ عامٌّ بجواره.
ثم فُتحت بحسابِ عميلٍ صلبٍ (`account_type=client` بعضويّةٍ `active`) الوجهاتُ الثلاثَ عشرة:

- **صفرُ ظهورٍ** لأيٍّ من: `777111` · `888222` · `999333` · `INTERNALNOTE` · `INTERNALDOC` ·
  `INTERNALCOMMENT` · `PUR-COSTDOC` — لا تكلفةَ ولا هامشَ ولا تعليقاً داخليّاً ولا وثيقةً داخليّة.
- **صفرُ ظهورٍ** لاسمِ موظّفٍ أو لسجلِّ عميلٍ آخر.
- عبورُ الحدِّ إلى عميلٍ آخر: `portal/projects/{B}` · `portal/documents/{B}` · `portal/tickets/{B}` → **٤٠٤** (لا ٤٠٣).
- فاتورةُ المشترياتِ لعميله نفسِه → **٤٠٤** (`hub_scope` يقصر `fin` على `CLIENT_INVOICE_KINDS`).
- `api/v1/*` بحساب العميل → **٤٠١/٤٠٤** (PortalGuard فوق المصفوفة).

**الملاحظةُ الوحيدة (لا تسريب):** صفحةُ التذكرة تعرض **اسمَ الموظّفِ الداخليِّ الذي كتب
الردَّ العلنيّ**. أراه عقداً مقصوداً (العميلُ يعرف من يكلّمه) لا تسريباً — وأذكره لأنّ
سياسةَ «الأسماءُ الداخليّةُ لا تظهر للعميل» إن كانت مطلوبةً فهذا موضعُها. التعليقُ الداخليُّ
نفسُه لم يظهر لا نصّاً ولا كاتباً.

### ٤٫٩ نماذجُ الإنشاء والتحرير

`/m/{module}/create` لكلِّ الوحدات الـ٨٥ التي ردّت ٢٠٠، و`/m/{module}/{id}/edit` لثمانيةِ
سجلّاتٍ من عالَمِ «أ» — **«ALL FORMS CLEAN»**. `hub_ref_options_scoped` (`helpers.php:986`)
يفعل عملَه على المشاريعِ والشركاتِ والعملاء. وهذا بالضبط ما يجعل `A04-03` شاذّاً: النظامُ
يملك الدالّةَ الصحيحة ويستعملها في ٨٥ نموذجاً، ونموذجُ المستخدمِ وحدَه لا يستعملها.

### ٤٫١٠ مسحُ الأكواد والملصقات

`s/{code}` (محطّة) · `p/{code}` (منتج) · `c/{code}` (عهدة) · `identity/product/{id}/label` —
بأكواد شركةِ «ب» → ٤٠٤ في الأربعة. و`identity/resolve?q=AS-B-1` ٢٠٠ بلا حسم.

### ٤٫١١ لوحاتُ المنشأة — الحارسُ المركزيُّ يعمل

`hub_org_analytics_guard` / `hub_org_analytics_block` (`helpers.php:2635`) يردّ ٤٠٣ لكلِّ
معزولٍ بشركاتٍ أو عملاءَ أو مشاريع. فحصتُ: `admin/security` (الجذر) ٤٠٣ · `admin/errors` ٤٠٣ ·
`admin/activity/{user}` ٤٠٣ (للمالك حصراً) · `admin/access/role/{role}` ٤٠٣ ·
`admin/users/{owner}/edit` ٤٠٣ (حارسُ التصعيد). هذه بنيةٌ صحيحةٌ — و`A04-07` ثقبٌ فيها
لا غيابٌ لها.

### ٤٫١٢ العدّاداتُ التي ظننتُها تسرّبت ثمّ لم تكن

أُسجّلها كي لا يُعادَ الجهدُ: الفرقُ الأوّلُ أظهر حركةً في `/` و`media-center` و
`admin/security/identity` و`admin/security/ips` و`admin/control`. **كلُّها زائفة**:

- `/` و`media-center` — أرقامُ «منذ N ثانية» تتحرّك لأنّ اللقطةَ الثانيةَ بعدَ ١١ ثانيةً من
  الأولى. زالت كلُّها بتجميدِ الزمن (`Carbon::setTestNow`).
- `admin/security/identity` و`/ips` و`admin/control` — عدّاداتُها تحرّكت لأنّ **دالّةَ اللقطةِ
  نفسَها** زارت ٧٩ صفحةً مرّتين فولّدت `page_visits` و`user_ips.hits` وقيودَ تدقيق.
  أُعيدت التجربةُ بمسبارٍ مصغَّرٍ يزرع ٧٠ صفّاً لـ«ب» بلا زيارةِ صفحاتٍ وسيطة → **لا فرق**.
- `admin/audit` — الرقمُ المتحرّك (٨ ← ٤٩) هو طولُ **سلسلةِ نزاهةِ التدقيق** (`Audit::verifyTail`)،
  وهو مقياسُ سلامةٍ نظاميٌّ لا محتوى مستأجِر. يُفشي **حجمَ الكتابةِ الكلّيَّ** في المنصّة
  ولا يُفشي شيئاً عن مضمونه. أُسجّله ملاحظةً (P4) لا تسريباً.

**الدرسُ المنهجيّ:** فرقُ الصفحاتِ أداةٌ قويّةٌ لصيدِ العدّادات، لكنّها **تحتاج زمناً
مُجمَّداً ومسباراً لا يُلوّث ما يقيس** — وإلا أنتجت خمسةَ إنذاراتٍ كاذبةٍ مقابل تسريبٍ
واحدٍ حقيقيّ (`A04-06`).

### ٤٫١٣ `hub_scope` — قراءةٌ سطراً سطراً

قرأتُها كاملةً (`helpers.php:177‑279`). البنودُ الستّة: نطاقُ المشاريع · عزلُ الشركات ·
عزلُ العملاء · قصرُ حسابِ العميلِ على أنواعِ فواتيره · سرّيّةُ الوثائق (`files`) — كلُّها
تُضيّق ولا تُوسّع، ومرتّبةٌ بحيث يبقى الأضيقُ أضيق. **البندُ الوحيدُ الذي يوسّع** هو
`orWhere('assignee_id', …)` و`orWhere(created_by)` داخلَ فرعِ المشاريع — وهو محصورٌ داخلَ
`hub_scoped()` فلا يمسّ عزلَ الشركات (يُطبَّق بعده فيضيّق). **الفجوةُ الوحيدةُ فيها هي
`A04-01`**: شرطُ `&& ($ccol = hub_company_col($module))` يجعل غيابَ العمودِ فتحاً صامتاً.

**قرارٌ مُعلَنٌ لا تسريب — يُسجَّل للمجلس:** `hub_company_null_is_unowned('users') === true`
(`helpers.php:145‑163`) يجعل **كلَّ حسابات النظام مرئيّةً لكلِّ معزول** (الاسم والبريد
والدور). التعليقُ يشرح السبب (عمودُ `users.company_id` متروكٌ لا يكتبه أحد، فالعزلُ عليه
كان يُعمي المعزولَ عن البشرِ كافّةً و٧٢ حقلَ `ref→users` معه). القرارُ مفهومٌ وموثَّقٌ،
لكنّه **يبقى إفشاءَ دليلِ موظّفي مستأجِرٍ لمستأجِرٍ آخر** — وعلاجُه الصحيحُ ملءُ
`users.company_id`/`users.companies` في مسارِ الكتابة ثم رفعُ الاستثناء، لا إبقاؤه أبداً.

---

## ٥ · الخلاصةُ للمجلس

| البند | الحال |
|---|---|
| تسريباتٌ مُثبَتةٌ باستجابةٍ حقيقيّة | **٧** |
| منها P0 | **٤** (`A04-01` · `A04-02` · `A04-05` · `A04-07`) |
| منها P1 | **٢** (`A04-03` · `A04-04`) |
| منها P2 | **١** (`A04-06`) |
| تسريبُ داخليّاتِ العميل | **٠** — صمد بعد ١١ محاولة |
| أسطحٌ هوجمت وصمدت | **١٤ من ٢١** |
| إصلاحاتٌ بسطرٍ واحد | **٤** (`A04-03` · `A04-04` · `A04-06` · `A04-07`) |

**الجملةُ الواحدة:** العزلُ في Lynomia **صحيحٌ حيث يمرّ بـ`hub_scope`، ومفقودٌ حيث لا
يمرّ** — والقراءاتُ التي لا تمرّ ليست نادرة: منسدلةٌ هنا، وأثرٌ هناك، وعدّادٌ هنالك،
وإشعارٌ يُبنى في محرّكٍ خلفيّ. الإصلاحُ ليس محرّكاً جديداً بل **إغلاقُ الأبوابِ الجانبيّة**:
لا `DB::table()` خامٌّ يقرأ بياناتِ مستأجِرٍ في متحكّم، ولا `count()` بلا نطاق، ولا
`hub_notify` قبل أن يُسأل: أيقرأ المتلقّي هذا السجلَّ أصلاً؟

---

## ٦ · حزمةُ الانحدارِ المقترحة (تفشل اليوم · سبعةٌ من سبعة)

تُوضَع في `tests/Feature/Agent04IsolationTest.php`. شُغِّلت على الرأس `1d90626`:
**`Tests: 7, Assertions: 8, Failures: 7`** — كلُّ حارسٍ يفشل أوّلاً كما تقتضي قاعدةُ
«إثبات لا ادّعاء» في `CLAUDE.md`.

```php
/** مستخدمٌ بكلِّ الصلاحيّات والأعلام، معزولٌ على شركةِ «أ» وحدَها */
protected function isolatedUser(array $companies): User
{
    $matrix = collect(array_keys(config('hub.modules')))
        ->mapWithKeys(fn ($m) => [$m => ['v'=>1,'a'=>1,'e'=>1,'d'=>1,'fieldsec'=>1,'docsec'=>1]])->all();
    $role = Role::create(['name' => 'معزول', 'scope' => 'all',
        'flags' => ['approve'=>1,'users'=>1,'audit'=>1,'exp'=>1,'monitor'=>1,'secrets'=>1],
        'matrix' => $matrix]);
    return User::create(['name' => 'معزول', 'email' => Str::random(8).'@t.local',
        'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
        'companies' => $companies, 'password_changed_at' => now()]);
}

/** A04-01 */
public function test_modules_without_company_column_are_isolated_too(): void
{
    $inc = Incident::create(['title' => 'SECRETINC', 'project_id' => $this->prB->id,
        'severity' => 'حرجة', 'status' => 'مفتوحة']);
    $this->actingAs($this->spy)->get('/m/incidents/'.$inc->id)->assertNotFound();
    $this->actingAs($this->spy)->getJson('/api/v1/incidents/'.$inc->id)->assertNotFound();
}

/** A04-02 — سجلٌّ داخلَ النطاق لا يُسمّي مرجعاً خارجَه */
public function test_ref_label_of_out_of_scope_record_is_masked(): void
{
    $d = Document::create(['name' => 'وثيقةٌ لألف', 'company_id' => $this->coA->id]);
    DB::table('documents')->where('id', $d->id)->update(['project_id' => $this->prB->id]);
    $this->assertMaskedValueAbsent(
        (string) $this->actingAs($this->spy)->get('/m/files/'.$d->id)->getContent(), 'SECRETPROJ');
}

/** A04-03 */
public function test_user_form_company_picker_is_scoped(): void
{
    $this->assertMaskedValueAbsent(
        (string) $this->actingAs($this->spy)->get('/admin/users/create')->getContent(), 'SECRETCO');
}

/** A04-04 */
public function test_audit_filter_dropdowns_are_company_scoped(): void
{
    $b = (string) $this->actingAs($this->spy)->get('/admin/audit')->getContent();
    $this->assertMaskedValueAbsent($b, 'SECRETPROJ');
    $this->assertMaskedValueAbsent($b, 'SECRETCLIENT');
}

/** A04-05 — الإشعارُ لا يحمل عنوانَ سجلٍّ خارجَ نطاقِ متلقّيه */
public function test_sla_escalation_respects_recipient_scope(): void
{
    $this->spy->role->forceFill(['flags' => ['approve' => 1]])->save();
    Ticket::create(['subject' => 'SECRETSLA', 'client_id' => $this->clB->id,
        'company_id' => $this->coB->id, 'status' => 'جديدة', 'priority' => 'عاجلة',
        'created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30)]);
    (new AlertEngine)->evaluate(['ok' => true]);
    $this->assertStringNotContainsString('SECRETSLA',
        HubNotification::where('user_id', $this->spy->id)->pluck('text')->implode(' | '));
}

/** A04-06 — العدُّ نفسُه تسريب */
public function test_identity_counter_is_scoped(): void
{
    for ($i = 0; $i < 7; $i++) {
        Asset::create(['name' => "أصل باء $i", 'code' => "ASBX$i",
            'company_id' => $this->coB->id, 'status' => 'نشط']);
    }
    $b = preg_replace('/\s+/', ' ',
        strip_tags((string) $this->actingAs($this->spy)->get('/identity')->getContent()));
    preg_match('/([0-9,]+)\s*معرّفاً في السجل/u', $b, $m);
    // للقارئ أصلٌ واحدٌ في نطاقه — والباقي لشركةٍ أخرى
    $this->assertLessThanOrEqual(1, (int) str_replace(',', '', $m[1] ?? '0'));
}

/** A04-07 */
public function test_ip_forensics_trail_is_company_scoped(): void
{
    $bUser = $this->isolatedUser([$this->coB->id]);
    $this->actingAs($bUser)->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
        ->put('/m/projects/'.$this->prB->id,
            ['name' => 'مشروع باء SECRETPROJ', 'companyId' => $this->coB->id, 'status' => 'نشط']);
    $this->assertMaskedValueAbsent((string) $this->actingAs($this->spy)
        ->get('/admin/security/ips/203.0.113.77')->getContent(), 'SECRETPROJ');
}
```

**تنبيهُ المحرّكَين (قاعدةُ `CLAUDE.md`):** هذه الحرّاسُ لا تقرأ عمودَ JSON بـ`assertSame`
على مصفوفةٍ ترابطيّة، ولا تعتمد `->first()` بلا `orderBy`، وتُثبّت الزمنَ حيث يلزم —
فلا قرعةَ بين SQLite وMySQL. وقيمةُ التأكيدِ كلمةٌ من سبعةِ أحرفٍ فأكثر (`SECRETPROJ`)
لا رقمٌ قصير، وتمرّ بـ`assertMaskedValueAbsent` — فلا تُقرَع على معرّفاتِ الصفحة.
