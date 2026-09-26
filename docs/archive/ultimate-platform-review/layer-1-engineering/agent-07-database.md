# الوكيل ٠٧ — قاعدةُ البيانات ونموذجُ البيانات · طبقة ١ · الجولة أ

> **الرأس** `1d90626` · **النسخة** `2.540.1` · **التاريخ** 2026-09-17
> **المنهج:** قراءةٌ فقط للمستودع. كلُّ رقمٍ أدناه مخرجُ أمرٍ نُفِّذ فعلاً على قاعدةِ
> فحصٍ خاصّةٍ (`a07_probe`) بُنيت بـ`php artisan migrate` من الرأس نفسِه، وعلى قاعدةِ
> المحاكاة `lynomia_month` **قراءةً فقط**. لم تُنشأ هجرةٌ ولا عُدِّل ملفٌّ في المستودع
> سوى هذه الوثيقة. قاعدةُ الفحص أُسقطت بعد الانتهاء.
> ما لم أُثبته موسومٌ **ظنّيّ** صراحةً.

---

## ١ · الملخّصُ التنفيذيّ

1. **حزامُ العرضِ النصّيّ محكمٌ فعلاً — ودرسُ `notifications_hub.kind` مُستوعَب.** قارنتُ
   خريطةَ `hub_col_widths()` بمخطّطِ MySQL الحقيقيّ عموداً بعمود على **١٨٨ جدولاً**:
   **صفرُ انحراف** (لا عمودٌ يُظنّ أوسعَ ممّا هو، ولا أضيق). ولا موضعَ واحدٍ يكتب نصّاً
   أطولَ من عمودِه في مسارٍ حيّ.
2. **لكنّ الحزامَ نفسَه لا يشمل الأعدادَ الصحيحة.** `hub_col_nums()` يقرأ `->decimal(...)`
   وحدَه، فـ**١٩ حقلَ `num`** في ١٥ وحدةً تُكتَب في `tinyint/smallint/int` **بلا أيِّ سقفٍ
   في التحقّق**: القيمةُ تمرّ على SQLite صامتةً ويردّها MySQL بـ`1264 (22003)` خمسمئةً.
   هذا هو **`notifications_hub.kind` نفسُه بوجهٍ عدديّ**، وهو أخطرُ ما وجدت (`A07-01`).
3. **أسخنُ مسارِ قراءةٍ في النظام يمسح ثلاثةَ جداولَ كاملةً في كلِّ طلبِ ملف:**
   `FileController::canRead` يستعلم عن `attachments.path` و`thumb_path` و
   `inbox_documents.path` و`dm_messages.att` — و**لا فهرسَ على أيٍّ منها** (`A07-02`).
4. **`whereDate()` يُبطل الفهرسَ القائم.** `EXPLAIN` على `attendance` يُظهر
   `type: ALL, possible_keys: NULL` مع `whereDate('date', …)` و`type: ref` مع
   `where('date', …)` — والفهرسُ `attendance_date_idx` موجودٌ ومُهدَر في **٤٠+ موضعاً**
   على أعمدةِ `DATE` (`A07-03`).
5. **قاعدةُ «الإضافة لا الكسر» محترمةٌ بالكامل:** **صفرُ هجرةٍ مُدمِّرة** في ٢٥٠ هجرة
   (`dropColumn`/`drop`/`truncate` كلُّها في `down()` دون استثناءٍ واحدٍ حقيقيّ).
   وبالمقابل **صفرُ مفتاحٍ أجنبيٍّ** في ١٨٨ جدولاً — التكاملُ كلُّه بالعقدِ الطوعيّ
   في الكود، وقد أثبتُّ يتامى فعليّين في قاعدةِ المحاكاة (`A07-07`).

---

## ٢ · المقيسُ بالأوامر

| البُعد | العدد | الأمر |
|---|---|---|
| جداول (`a07_probe` بعد ٢٥٠ هجرة) | **188** | `information_schema.tables` |
| أعمدة | **3 627** | `information_schema.columns` |
| صفوفُ فهارس | **1 888** | `information_schema.statistics` |
| **مفاتيحُ أجنبيّة** | **0** | `key_column_usage WHERE referenced_table_name IS NOT NULL` |
| هجرات | **250** | `ls database/migrations` |
| هجراتٌ مُدمِّرةٌ في `up()` | **0** | مُحلِّلٌ يفصل `up()` عن `down()` (§٥) |
| نماذج | **152** | `ls app/Models` |
| جداولُ الوحدات (سجلّ `config/hub.php`) | **85** | `config('hub.modules')` |
| جداولُ فيها `deleted_at` | **108** | مقابل **152** نموذجاً |
| منها بلا أيِّ فهرسٍ يشمل `deleted_at` | **108** (الكلّ) | `A07-09` |
| قيودٌ فريدةٌ على جداولَ ذاتِ حذفٍ ناعم | **22** | §٤٫٤ |
| أعمدةٌ نصّيّةٌ ينحرف عرضُها عمّا يظنّه الكود | **0** | مقارنةُ `hub_col_widths()` بالمخطّط |
| حقولُ `num` عدديّةٌ بلا سقفِ عمود | **19** | §٣ · `A07-01` |

---

## ٣ · الملاحظات

### A07-01 · **عالية** · حقولُ العددِ الصحيح بلا سقفٍ من العمود — `1264` في الإنتاج وصمتٌ في الاختبار

**الجدول/النموذج:** `websites`, `ideas`, `servers`, `competitors`, `alert_rules`,
`cycles`, `deployments`, `events`, `facilities`, `incidents`, `media_items`,
`restore_tests`, `stock_items`, `change_orders` — ١٩ عموداً.

**الدليل.** بانيةُ القواعد تضع سقفاً من العمود للنصّ وللعشريّ فقط:

`app/Http/Controllers/Web/ModuleController.php:1774-1778`
```php
if (in_array($f['type'], ['num', 'big'], true)
    && ($nm = hub_col_num_max($def['table'] ?? '', $f['col'] ?? $f['key'])) !== null) {
    $r[] = 'between:-' . $nm . ',' . $nm;
}
```
و`hub_col_num_max` يقرأ من `hub_col_nums()` التي تمسح **`->decimal(` وحدَها**:

`app/Support/helpers.php:4339`
```php
preg_match_all("/->decimal\\(\\s*'([a-z0-9_]+)'\\s*,\\s*(\\d+)\\s*,\\s*(\\d+)/", $b[2], $cols, ...);
```
فلا مدخلَ لأيِّ عمودِ `tinyInteger/smallInteger/integer` ⇒ `null` ⇒ لا قاعدة.

مخرجُ استدعاءِ `ModuleController::rules()` نفسِها بالانعكاس:
```
websites.lighthouse       ["nullable","numeric"]
ideas.impact              ["nullable","numeric"]
servers.cores             ["nullable","numeric"]
quotes.total              ["nullable","numeric","between:-9999999999999.999,9999999999999.999"]   ← العشريُّ محروس
```
والمحرّكُ يرفض:
```
$ mysql -e "INSERT INTO a07_probe.websites (id,name,url,lighthouse,...) VALUES (UUID(),'p3','http://x',999,...)"
ERROR 1264 (22003): Out of range value for column 'lighthouse' at row 1
$ ... VALUES (UUID(),'p4','http://x',-5,...)
ERROR 1264 (22003): Out of range value for column 'lighthouse' at row 1
```

**جردُ الحقول التسعةَ عشر** (وحدة · جدول · عمود · نوع · التسمية في الواجهة):

| الوحدة | العمود | النوع | المدى | التسمية |
|---|---|---|---|---|
| websites | `lighthouse` | tinyint unsigned | 0..255 | درجة الأداء (Lighthouse) |
| ideas | `impact`, `confidence`, `ease` | tinyint unsigned | 0..255 | «الأثر (١-١٠)» … |
| servers | `cores` | smallint unsigned | 0..65535 | أنوية المعالج |
| competitors | `trial_days` | smallint unsigned | 0..65535 | أيام التجربة المجانية |
| rules | `window_min`, `cooldown_min` | int unsigned | ≥0 | نافذة/تبريد (دقيقة) |
| cycles | `target_visits`, `frequency` | int unsigned | ≥0 | هدف الزيارات · التكرار |
| deploys | `duration_min` | int unsigned | ≥0 | مدة النشر |
| events | `leads` | int unsigned | ≥0 | عدد العملاء المحتملين |
| facilities | `radius_m` | int unsigned | ≥0 | نطاق الوصول (متر) |
| incidents | `downtime_min` | int unsigned | ≥0 | مدة التعطل |
| media | `reach` | int unsigned | ≥0 | الوصول التقديري |
| restores | `size_mb`, `duration_min` | int unsigned | ≥0 | حجم/مدة الاستعادة |
| stock | `carton_qty` | int unsigned | ≥0 | وحدات الكرتونة |
| changeorders | `timeline_days` | int **signed** | ±2.1e9 | أثر الجدول (أيام ±) — الوحيدُ الآمن للسالب |

**الأثرُ الإنتاجيّ.** ثمانيةَ عشرَ عموداً منها `unsigned`: **أيُّ قيمةٍ سالبةٍ تُسقط الحفظَ
بخمسمئة**، لا برسالةِ تحقّق. و«الأثر (١-١٠)» يقبل `300` في المتصفّح ثمّ ينهار عند الكتابة.
ورسالةُ `QueryException` تحمل الاستعلامَ بقيمِه إلى مركز الأخطاء (المسارُ نفسُه الذي
عالجوه للعشريّ في التعليق أعلاه). والحزمةُ خضراءُ على SQLite لأنّها تقبل أيَّ عدد.

**الإصلاح (إضافيٌّ لا مُدمِّر).** لا هجرةَ أصلاً — توسعةُ `hub_col_nums()` لتقرأ الأعداد
الصحيحةَ أيضاً ولتعيد المدى `[min, max]` بدل مطلقٍ واحد (المدى غيرُ متماثلٍ لـ`unsigned`)،
ثمّ `between:min,max` في `ModuleController::rules()`. وإن أُريدت هجرةٌ فهي توسعةُ
`->change()` للأعمدةِ الضيّقةِ جدّاً (`ideas.impact` → smallint) — **توسعةٌ لا تفقد بياناً**.

**اختبارُ الانحدار.** اختبارٌ يفشل أوّلاً: لكلِّ حقلِ `num` في السجلّ، احسب مدى عمودِه من
`information_schema` (أو من مصدر الهجرات) وأكّد أنّ `rules()` تحوي `between:` يطابقه؛
ثمّ اختبارُ طلبٍ حيٍّ يرسل `lighthouse=-1` ويتوقّع `422` لا `500`.

**الثقة: عالية جدّاً** (مخرجُ `rules()` ومخرجُ المحرّك كلاهما منسوخٌ أعلاه).

---

### A07-02 · **عالية** · لا فهرسَ على مساراتِ الملفّات — مسحٌ كاملٌ ×٤ في كلِّ طلبِ ملفٍّ محميّ

**الجدول:** `attachments(path, thumb_path)` · `inbox_documents(path)` · `dm_messages(att)`

**الدليل.** `app/Http/Controllers/Web/FileController.php` يستعلم عن هذه الأعمدةِ في
مسارِ التخويلِ نفسِه — الأسطر `34`, `103`, `106`, `173`, `233`, `265`. والفهارسُ غائبة:
```
$ mysql -N -e "SELECT table_name,index_name,column_name FROM information_schema.statistics
               WHERE table_schema='a07_probe' AND column_name IN ('path','thumb_path','att')"
(لا مخرَج)
```
```
$ mysql -e "EXPLAIN SELECT module,record_id FROM lynomia_month.attachments WHERE path='x' OR thumb_path='x'"
        type: ALL     possible_keys: NULL     key: NULL
$ mysql -e "EXPLAIN SELECT orig FROM lynomia_month.inbox_documents WHERE path='x'"
        type: ALL     possible_keys: NULL     key: NULL
$ mysql -e "EXPLAIN SELECT 1 FROM lynomia_month.dm_messages WHERE att='x'"
        type: ALL     possible_keys: NULL     key: NULL
```

**الأثرُ الإنتاجيّ.** كلُّ صورةٍ ومرفقٍ وشعارٍ في كلِّ صفحةٍ يمرّ بـ`canRead` ⇒ حتى
أربعةُ مسحٍ كاملٍ لكلِّ أصلٍ ثابت. على `attachments` وهو من أسرعِ الجداولِ نموّاً، هذا
هو العنقُ الذي يظهر أوّلاً حين تكبر القاعدة. والعمودان `path` (500) و`thumb_path` (500)
أطولُ من حدِّ فهرسِ InnoDB بعدّاد utf8mb4 (3072 بايتاً = 768 محرفاً) — فالفهرسُ **بادئةٌ**
(`path(191)`) لا كاملٌ، وهو كافٍ تماماً لانتقاءٍ حادّ.

**الإصلاح.** هجرةٌ **إضافيّةٌ محضة** — فهارسُ بادئةٍ لا تمسّ عموداً ولا صفّاً:
```php
Schema::table('attachments', function (Blueprint $t) {
    $t->index([DB::raw('path(191)')], 'attachments_path_idx');      // أو rawStatement للمحرّكين
    $t->index([DB::raw('thumb_path(191)')], 'attachments_thumb_idx');
});
// ونظيرُها على inbox_documents.path و dm_messages.att
```
(على SQLite الفهرسُ كاملٌ بلا بادئة — يُفرَّع بـ`DB::getDriverName()` كما في
`2026_01_12_000001_fix_mysql_portability.php:25`.)

**اختبارُ الانحدار.** اختبارٌ يقرأ `information_schema.statistics` (أو
`Schema::getIndexes`) ويؤكّد وجودَ فهرسٍ يبدأ بكلٍّ من الأعمدةِ الأربعة — يفشل الآن.

**الثقة: عالية جدّاً.**

---

### A07-03 · **عالية** · `whereDate()` على عمودِ `DATE` يُعطّل فهرساً موجوداً (٤٠+ موضعاً)

**الجدول/النموذج:** `attendance`, `work_updates`, `tasks`, `leave_requests`,
`track_sessions`, `asset_custody`, `automations`, `compliance_items` …

**الدليل — على البيانات الحيّة، والفهرسُ موجود:**
```
$ mysql -e "EXPLAIN SELECT * FROM lynomia_month.attendance WHERE deleted_at IS NULL AND DATE(`date`)='2026-09-01'"
        type: ALL   possible_keys: NULL             key: NULL           rows: 148
$ mysql -e "EXPLAIN SELECT * FROM lynomia_month.attendance WHERE deleted_at IS NULL AND `date`='2026-09-01'"
        type: ref   possible_keys: attendance_date_idx  key: attendance_date_idx  rows: 10
```
`attendance.date` من نوع `date` (لا `datetime`) — فـ`DATE()` حولَه لا يفعل شيئاً سوى
منعِ الفهرس. المواضع (عيّنةٌ من ٤٠+):

| ملف:سطر | الاستعلام |
|---|---|
| `app/Support/Workday.php:84,117,154,385,396,449,522` | `whereDate('date'/'work_date', …)` |
| `app/Support/DailyWorkCompliance.php:91,92,180,194,198,286,290,335,336,497,498` | `whereDate('date_from'/'date_to'/'date'/'work_date', …)` |
| `app/Http/Controllers/Web/ReportsController.php:125,158,159,247` | `whereDate('work_date', …)` |
| `app/Http/Controllers/Api/{MobileReportsController:31, ReportsApiController:32}` | `whereDate('work_date', $date)` |
| `app/Support/helpers.php:3040,3041,3414,5060` | `whereDate('date'/'due', …)` |
| `app/Console/Commands/{AttendanceReconcileReports:48, HubAutomation:336}` | `whereDate('date'/'next', …)` |

**الأثرُ الإنتاجيّ.** شاشةُ الحضورِ اليوميّة، وحاسبُ الامتثالِ اليوميّ (يُشغَّل كرونياً
لكلِّ موظّف)، وتقاريرُ العمل، وواجهةُ الجوّالِ كلُّها تمسح الجدولَ كاملاً بدل انتقاءٍ
بفهرس. الأثرُ خطّيٌّ مع عمرِ القاعدة ولا يظهر في الاختبارات (جداولُ الاختبار صغيرة).

**الإصلاح.** لا هجرة — استبدالُ `whereDate($col, $v)` بـ`where($col, $v)` **حين يكون
`$col` من نوع `date`** (والفرقُ صفرٌ دلاليّاً). لأعمدةِ `datetime` يبقى `whereDate`
صحيحاً دلاليّاً لكنّه يُستبدَل بنطاق `whereBetween($col, [$d.' 00:00:00', $d.' 23:59:59'])`.

**اختبارُ الانحدار.** حارسٌ نصّيٌّ (كنمط `JsonKeyOrderGuardTest`): يمسح `app/` عن
`whereDate('X'` حيث `X` عمودٌ نوعُه `date` في المخطّط، ويفشل إن وُجد — مع قائمةِ استثناءٍ
معلَنة. أو اختبارُ أداءٍ يقارن `EXPLAIN` فيؤكّد `type != 'ALL'`.

**الثقة: عالية جدّاً** لمخرجِ `EXPLAIN`؛ **عالية** لجردِ المواضع (مسحٌ لفظيّ).

---

### A07-04 · **متوسطة-عالية** · مرفقٌ محذوفٌ ناعماً ما زال يُخوّل فتحَ مسارِه

**الملف:** `app/Http/Controllers/Web/FileController.php:233-234`

**الدليل.** فرعُ «المرفقُ يُقاس بسجلّه» يستعمل `DB::table` لا النموذج، فيفقد نطاقَ
`SoftDeletes` العامّ — والفرعُ المجاورُ لصندوقِ الوارد (السطر `260`) يذكر
`whereNull('deleted_at')` صراحةً، فالفرقُ سهوٌ لا اختيار:

```php
$rows = DB::table('attachments')->where('path', $path)
    ->orWhere('thumb_path', $path)->get(['module', 'record_id']);      // :233 — بلا deleted_at
...
$iq = DB::table('inbox_documents')->whereNull('deleted_at')->where('path', $path);  // :260 — به
```
مخرجُ `toSql()` يُثبت الفرقَ حرفيّاً:
```
A) Eloquent:  select * from `attachments` where (`path` = ? or `thumb_path` = ?) and `attachments`.`deleted_at` is null
B) DB::table: select * from `attachments` where `path` = ? or `thumb_path` = ?
```
(ولاحظ أنّ Laravel يُغلّف الشرطَين في قوسٍ واحدٍ في الحالة A — فلا خطأَ أسبقيّةٍ هناك.)
والمواضعُ الأخرى نفسُها: `:103` (`attachments`), `:106` (`inbox_documents`), `:173`/`:265`
(`dm_messages`).

**الأثرُ الإنتاجيّ.** حذفُ مرفقٍ لا يُبطل الوصولَ إلى مساره: من يملك رابطَ الملفّ
وصلاحيّةَ الوحدةِ ورؤيةَ السجلِّ يفتحه بعد الحذف كما قبله. الفجوةُ ضيّقةٌ (تشترط
`hub_can(…, 'v')` و`hub_read(...)->exists()`) لكنّها تخالف ما يفهمه المستخدمُ من «حُذف».
ونظيرُها في `:103/:106`: اسمُ الملفّ الأصليُّ ما زال يُقرأ من صفٍّ محذوف — تسريبُ اسم.

**الإصلاح.** لا هجرة — إضافةُ `->whereNull('deleted_at')` إلى المواضع الأربعة (مع
الانتباه لأسبقيّةِ `orWhere` في `:233`: يُغلَّف الشرطان في `where(fn($w) => …)` أوّلاً،
وإلّا انطبق الحارسُ على الفرع الأوّل وحدَه).

**اختبارُ الانحدار.** يفشل أوّلاً: ارفع مرفقاً لسجلٍّ يراه المستخدم، تحقّق `200`، احذف
المرفقَ ناعماً، ثمّ اطلب المسارَ نفسَه وتوقّع `403/404`.

**الثقة: عالية جدّاً** (`toSql()` منسوخٌ أعلاه).

---

### A07-05 · **متوسطة-عالية** · لا قيدَ يمنع جهازَي نقطةٍ طرفيّةٍ نشطَين على أصلٍ واحد — والتعليقُ يُصيب واحداً بالقرعة

**الجدول:** `endpoint_devices`

**الدليل.** الحارسُ تطبيقيٌّ محضٌ و**خارجَ المعاملة** (فحصٌ ثمّ فعل):

`app/Http/Controllers/Api/EndpointEnrollController.php:134-136`
```php
if ($asset->activeEndpoint() !== null) {
    return Api::error(Api::CONFLICT, 409, 'للأصلِ جهازٌ نشطٌ مسجَّلٌ فعلاً — يُتقاعَد قبل تسجيلِ بديل');
}
$device = DB::transaction(function () use ($token, $d) { … EndpointDevice::create([...]) … });
```
والقيودُ الفريدةُ على الجدول لا تشمل `asset_id`:
```
$ mysql -N -e "SHOW INDEX FROM a07_probe.endpoint_devices"
  UNIQ endpoint_devices_device_uuid_unique (device_uuid)
  UNIQ endpoint_devices_pubkey_fp_unique   (pubkey_fp)
       endpoint_devices_asset_idx          (asset_id)      ← غيرُ فريد
```
ثمّ عند بلوغِ الأصلِ حالةً نهائيّة (مفقود/تالف/مباع) يُعلَّق **جهازٌ واحدٌ فقط** بلا ترتيب:

`app/Support/Endpoint.php:86`
```php
$device = \App\Models\EndpointDevice::where('asset_id', $asset->id)->where('status', 'active')->first();
if ($device === null) return;
$device->forceFill(['status' => 'suspended'])->saveQuietly();
```

**الأثرُ الإنتاجيّ.** تسجيلان متزامنان برمزَين صالحَين لأصلٍ واحدٍ يجتازان الفحصَ معاً
فيُنشآن جهازَين `active`. حين يُبلَّغ عن الأصلِ مفقوداً، يُعلَّق أحدُهما — و**الآخرُ يظلّ
`active`** فيواصل المصادقةَ الموقّعةَ (ES256) والنبضَ وتلقّي الأوامر. `EndpointSignature`
يشترط `status === 'active'` (السطر 78) فيمرّ. جهازٌ على عتادٍ مفقودٍ يبقى داخل السياج.

**تناقضٌ ثالثٌ يفاقمه:** ثلاثةُ تعاريفَ لـ«الجهاز النشط» في ثلاثة مواضع —
`Asset::activeEndpoint()` يقول `status != 'retired'`، و`Endpoint::onAssetStatusChanged`
يقول `status = 'active'`، و`EndpointSignature` يقول `status !== 'active'` ⇒ رفض.

**الإصلاح (إضافيٌّ لا مُدمِّر) — بنمطِ المستودعِ نفسِه.** الهجرةُ
`2026_09_25_000001_project360_asset_project_assignments.php:49-54` تستعمل الحيلةَ الصحيحة
على المحرّكين: عمودُ رايةٍ يساوي `1` عند النشاط و`NULL` عند غيره، وفهرسٌ فريدٌ يشمله
(«NULL مختلفٌ عن كلِّ NULL»). يُنسَخ حرفيّاً:
```php
Schema::table('endpoint_devices', function (Blueprint $t) {
    $t->unsignedTinyInteger('active_flag')->nullable();          // إضافةٌ محضة
    $t->unique(['asset_id', 'active_flag'], 'epd_asset_active_uq');
});
// تعبئةٌ أوّليّة: active_flag = 1 حيث status='active' — ثمّ النموذجُ يصونه في booted()
```
ويُبدَّل `->first()` في `Endpoint.php:86` بـ`->get()` وحلقةٍ تعلّق **كلَّ** النشطين
(الإصلاحُ الدلاليّ؛ القيدُ يمنع العودة).

**اختبارُ الانحدار.** (١) إدراجُ صفٍّ ثانٍ `active` لنفس `asset_id` يجب أن يرمي `23000`.
(٢) اختبارٌ يُنشئ جهازَين نشطَين قسراً (`saveQuietly`) ثمّ يستدعي
`Endpoint::onAssetStatusChanged` ويؤكّد أنّ **الاثنين** صارا `suspended` — يفشل الآن.

**الثقة: عالية** للبنية والقرعة؛ **متوسطة** لاحتمالِ السباقِ فعليّاً في الإنتاج (يتطلّب
رمزَي تسجيلٍ لأصلٍ واحدٍ في اللحظة نفسها).

---

### A07-06 · **متوسطة** · `orderByDesc('id')` على مفتاحِ UUID — ترتيبٌ عشوائيٌّ يُظنّ «الأحدث»

**النموذج/الجدول:** `endpoint_devices` عبر `Asset::activeEndpoint()` · `inventory_items`

**الدليل.** كلُّ الجداولِ هنا مفتاحُها `char(36)` UUIDv4 (فحصتُ: **٠** وحدةً بمفتاحٍ
تزايديّ). فترتيبُ `id` تنازليّاً ترتيبٌ **معجميٌّ لقيمةٍ عشوائيّة** — حتميٌّ بين المحرّكين
(فلا يسقط CI) لكنّه بلا معنىً زمنيّ:

`app/Models/Asset.php:154-158`
```php
return \App\Models\EndpointDevice::where('asset_id', $this->id)
    ->where('status', '!=', 'retired')->orderByDesc('id')->first();
```
`app/Support/Asset360.php:93-95`
```php
$verdict = DB::table('inventory_items')->where('asset_id', (string) $asset->id)
    ->orderByDesc('id')->value('verdict');                    // «آخرُ حكمِ جرد»
```
وفي **الدالّةِ نفسِها** قبله بسطرين، الاستعلامُ الشقيقُ يفعلها صواباً:
```php
$last = DB::table('inventory_scans')->where('asset_id', …)->whereNotNull('at')
    ->orderByDesc('at')->orderByDesc('id')->first(['at', 'result']);   // زمنٌ ثمّ فاصلُ تعادل
```
و`inventory_items` فيه `created_at` وقيدٌ فريدٌ `(session_id, asset_id)` — فـ«الأحدث»
يُستخرج من `created_at` أو من جلسةِ الجرد، لا من UUID.

**الأثرُ الإنتاجيّ.** شاشةُ الأصل تعرض حكمَ جردٍ من جلسةٍ قديمةٍ عشوائيّاً بدل الأحدث؛
و`activeEndpoint()` يُبلّغ جهازاً غيرَ الذي يُظنّ عند وجود أكثرَ من صفّ (وهو المسارُ
الذي يحرس التسجيلَ في `A07-05`).

**ملاحظةٌ منصفة:** بقيّةُ المواضعِ (**٦٦** من ٦٨) تستعمل `orderByDesc('id')` **فاصلَ
تعادلٍ بعد** عمودٍ زمنيّ — وهو الاستعمالُ الصحيحُ تماماً وقد رأيتُه معلَّقاً صراحةً في
`WebhookController.php:106` («فاصلُ تعادلٍ حاسم»). العيبُ في الموضعَين اللذين جعلاه
الترتيبَ الأوّلَ.

**الإصلاح.** لا هجرة: `orderByDesc('created_at')->orderByDesc('id')` في الموضعَين.

**اختبارُ الانحدار.** أنشئ صفّين بختمَي إنشاءٍ مختلفَين ومعرّفَين يُخالف ترتيبُهما
المعجميُّ ترتيبَهما الزمنيّ (يُثبَّتان قسراً)، وأكّد أنّ المُعاد هو الأحدثُ زمنيّاً.

**الثقة: عالية جدّاً.**

---

### A07-07 · **متوسطة** · صفرُ مفاتيحَ أجنبيّة في ١٨٨ جدولاً — واليتامى مُثبَتون

**الدليل.**
```
$ mysql -e "SELECT COUNT(*) FROM information_schema.key_column_usage
            WHERE table_schema='lynomia_month' AND referenced_table_name IS NOT NULL"
0
$ grep -rn -e '->foreign(' -e 'constrained(' -e 'foreignId(' database/migrations/ | wc -l
0
```
**ولا واحدَ** في ٢٥٠ هجرة. والتكاملُ يُختبَر عمليّاً على قاعدةِ المحاكاة — مسحتُ ١٣
جدولاً متعدّدَ الأشكال × ٨٥ وحدةً (١ ١٠٥ استعلامِ `LEFT JOIN`):

| الجدول | الوحدة | صفوفٌ يتيمة |
|---|---|---|
| `audits` | approvals | **1** |
| `notifications_hub` | approvals | **2** |
| `record_versions` | approvals | **1** |

```
$ mysql -e "SELECT id,module,record_id,created_at FROM lynomia_month.audits
            WHERE module='approvals' AND record_id NOT IN (SELECT id FROM lynomia_month.approvals)"
id: 1962   module: approvals   record_id: afc1c52b-…-4dbc96efa399   created_at: 2026-09-16 17:38:00
```
(الانضمامُ بلا `whereNull('deleted_at')` عمداً — فحتى الصفُّ المحذوفُ ناعماً كان سيُطابق.
هذا **حذفٌ نهائيّ** أو صفٌّ لم يوجد قطّ.) وبقيّةُ الروابطِ الجوهريّة نظيفةٌ تماماً:
`audits.user_id`, `tasks.assignee_id`, `employees.user_id`, `attachments.uploaded_by`,
`users.company_id`, `users.role_id`, `journal_lines.entry_id`, `notifications_hub.user_id`
⇒ **٠ يتيم** لكلٍّ.

**الأثرُ الإنتاجيّ.** أربعةُ صفوفٍ اليوم في قاعدةِ محاكاةٍ عمرُها شهر. لا ما يمنع النموَّ:
لا القاعدةُ تحرس، ولا الكودُ ينظّف عند الحذفِ النهائيّ. أثرُه المرئيّ: نسخةُ سجلٍّ
(`record_versions`) لا يمكن استعادتُها، وإشعارٌ رابطُه يؤدّي إلى ٤٠٤، وصفُّ تدقيقٍ بلا
سجلٍّ يُفحَص. وسلسلةُ التدقيقِ نفسُها مُختَمة، فالصفُّ اليتيمُ لا يُحذف تصحيحاً.

**الإصلاح (إضافيٌّ).** المفتاحُ الأجنبيُّ غيرُ ممكنٍ على `(module, record_id)` متعدّدِ
الأشكال — وهو سببٌ وجيه. المتاحُ إضافيّاً: (١) مفاتيحُ أجنبيّةٌ على الروابطِ الأحاديّة
الصريحة (`journal_lines.entry_id`, `quote_lines.quote_id`, `payroll_lines.run_id`,
`conversation_members.conversation_id`) بـ`ON DELETE RESTRICT` — هجرةٌ إضافيّةٌ لا تمسّ
صفّاً، تُسبَق بفحصِ يتامى يوقفها إن وُجدوا؛ (٢) أمرٌ دوريٌّ `hub:orphans` يجرد ويُبلّغ
(لا يحذف) — على السكّةِ نفسِها التي مسحتُ بها أعلاه.

**اختبارُ الانحدار.** اختبارُ جردٍ يمرّ على كلِّ جدولٍ متعدّدِ الأشكال × كلِّ وحدةٍ في
السجلّ ويؤكّد `0` يتيماً على قاعدةٍ مبذورة — يحرس التدهور لا الوضعَ القائم.

**الثقة: عالية جدّاً.**

---

### A07-08 · **متوسطة** · `(module, record_id)` بلا فهرسٍ مركّبٍ في ثلاثةِ جداول

**الدليل.** من ١٣ جدولاً يحملان العمودَين، **عشرةٌ** لها الفهرسُ المركّبُ الصحيح
(`attachments`, `audits`, `comments`, `conversations`, `incident_links`, `metric_points`,
`notifications_hub`, `record_acks`, `record_identifiers`, `record_locks`, `record_versions`)
— وثلاثةٌ لا:

| الجدول | ما هو موجود |
|---|---|
| `alert_instances` | **لا فهرسَ على `module` ولا على `record_id` إطلاقاً** |
| `inbox_documents` | `(module)` وحدَه |
| `signal_states` | `(module)` و`(record_id)` منفصلَين — و«الاثنان منفصلان» ليسا مركّباً |

و`AlertEngine` يستعلم بـ`record_id` صراحةً (`app/Support/AlertEngine.php:144,215,227,370,372`).

**الأثرُ الإنتاجيّ.** محرّكُ التنبيهاتِ يُقيَّم دوريّاً لكلِّ قاعدةٍ ولكلِّ صفٍّ مستحقّ؛
بلا فهرسٍ على `(module, record_id)` يصير كلُّ فحصٍ مسحاً. يتفاقم خطّيّاً مع عمرِ
`alert_instances` — وهو جدولُ تاريخٍ لا يُقلَّم.

**الإصلاح.** هجرةٌ إضافيّةٌ محضة: `$t->index(['module','record_id'], '<t>_module_record_idx')`
للثلاثة (مع حارسِ `hasIndex` كما في `2026_08_16_000001_asset_custody_codes.php:60`).

**اختبارُ الانحدار.** حارسٌ بنيويّ: لكلِّ جدولٍ يحمل `module` و`record_id` معاً، أكّد
وجودَ فهرسٍ بادئتُه `(module, record_id)` — يفشل الآن بثلاثة.

**الثقة: عالية جدّاً.**

---

### A07-09 · **متوسطة** · `deleted_at` خارجَ كلِّ فهرسٍ في ١٠٨ جداول

**الدليل.**
```
$ (مُحلِّلٌ على information_schema لقاعدة a07_probe)
جداولُ فيها deleted_at:                                 108
منها بلا أيِّ فهرسٍ يحتوي deleted_at:                   108      ← الكلُّ بلا استثناء
```
منها الجداولُ الأسخن: `tasks`, `documents`, `attachments`, `attendance`, `work_updates`,
`comments`, `users`, `assets`, `contracts`, `fin_documents`, `projects`, `clients` …

**الأثرُ الإنتاجيّ.** `SoftDeletes` يضيف `deleted_at IS NULL` إلى **كلِّ** قراءة. الفهارسُ
القائمةُ لا تشمله، فالمحرّكُ ينتقي بالفهرسِ ثمّ يُرشّح الصفوفَ المُسترجَعةَ — والقصّةُ
تسوء حيث الفهرسُ منتقٍ ضعيفاً (`status`, `archived`, `company_id` وحدَها). ليس عطلاً
وظيفيّاً، لكنّه ضريبةٌ على كلِّ شاشةِ قائمةٍ في النظام.

**الإصلاح (إضافيٌّ).** لا يُعقل ١٠٨ فهارسَ جديدة. الجدوى في تذييلِ الفهارسِ المركّبةِ
القائمةِ للجداولِ العشرين الأسخن — مثلاً
`(project_id, created_at)` ⇐ `(project_id, deleted_at, created_at)` على `tasks` و
`work_updates` و`attendance`. هجرةٌ إضافيّة: يُنشَأ الفهرسُ الجديدُ ويُترَك القديم
(لا `dropIndex` — القاعدةُ صريحة).

**اختبارُ الانحدار.** لا اختبارَ وظيفيٌّ يُحرس هنا؛ قياسٌ بـ`EXPLAIN` على قاعدةٍ مبذورةٍ
بحجمٍ واقعيّ (يوثَّق في `docs/` لا في الحزمة).

**الثقة: عالية** للقياس؛ **متوسطة** لأولويّةِ الإصلاح (ظنّيّةٌ بلا قياسِ إنتاجٍ حقيقيّ).

---

### A07-10 · **متوسطة** · قيودٌ فريدةٌ على جداولَ ذاتِ حذفٍ ناعم — بعضُها محروسٌ وبعضُها لا

**الدليل.** **٢٢** قيداً فريداً يقع على جدولٍ فيه `deleted_at` ولا يشمله:

```
asset_custody.permit_no · assets.code · change_orders.doc_no · client_memberships(client_id,user_id)
contracts.doc_no · currency_rates(from_cur,to_cur,as_of) · endpoint_devices.device_uuid
endpoint_devices.pubkey_fp · endpoint_releases(version,os,arch) · fin_documents.doc_no
mobile_installations.installation_uuid · payroll_runs(company_id,month_key) · phone_numbers.iccid
products.code · sign_requests.token · sign_requests.verify_code · stations.code
user_devices(user_id,cookie_hash) · users.email · webauthn_credentials.credential_id
attendance(emp_id,day_key) · asset_project_assignments(asset_id,project_id,active_flag)
```

**والمشهدُ منصف: أكثرُها محروسٌ عمداً ومُعلَّلٌ في الكود.**

| الحالة | الحارس |
|---|---|
| `payroll_runs(company_id, month_key)` | هجرةٌ تُفرّغ `month_key` للمحذوف — `2026_09_16_000001_release_month_key_on_trashed_payroll_runs.php` |
| `assets.code` / `products.code` / `stations.code` | المولّدُ يمرّ بـ`withTrashed()` («المحذوفُ يحجز كودَه» — `Asset.php:101-109`) |
| `users.email` | فرعٌ صريحٌ `Staff::emailHeldByDeleted()` — `app/Support/Staff.php:241-243` |
| `endpoint_releases(version, os, arch)` | التقاطُ `QueryException` — `EndpointReleaseController.php:141` |
| `client_memberships` | `ClientMembership.php:118` يفحص `withTrashed` صراحةً |
| `attendance(emp_id, day_key)` | `day_key` مشتقٌّ ويُدار في `Attendance::booted()` |
| `asset_project_assignments` | نمطُ `active_flag` — الحلُّ الصحيح |

**وما بقي بلا حارسٍ ولا التقاطٍ لـ23000:** `change_orders.doc_no`, `contracts.doc_no`,
`fin_documents.doc_no`, `asset_custody.permit_no`, `phone_numbers.iccid`,
`user_devices(user_id, cookie_hash)`, `webauthn_credentials.credential_id`,
`mobile_installations.installation_uuid`, `endpoint_devices.device_uuid/pubkey_fp`.

**الأثرُ الإنتاجيّ.** المسارُ المرئيُّ الأقرب: يُحذف عقدٌ/مستندٌ ناعماً بسبب خطأِ إدخال،
ثمّ يُعاد إنشاؤه **بالرقم نفسِه** ⇒ `SQLSTATE[23000] 1062 Duplicate entry` خمسمئةً بلا
تفسيرٍ للمستخدم (وهو حرفيّاً ما وصفته هجرةُ `payroll_runs` عن نفسِها: «اصطدم بخطأِ
قاعدةِ بياناتٍ خام»). وأمّا `webauthn_credentials.credential_id` و`device_uuid` فهما
مقصودان (هويّةُ عتادٍ لا تُعاد) — لا يحتاجان إصلاحاً بل تعليقاً.

**الإصلاح.** لكلِّ عمودِ رقمِ مستند: لا هجرة — التقاطُ `23000` في المتحكّم وردُّ رسالةٍ
تقترح الاستعادةَ من السلّة، على نمطِ `EndpointReleaseController:141`. أو (الأمتن)
هجرةٌ إضافيّةٌ تُبدّل القيدَ الفريدَ إلى شكلِ `active_flag` — لكنّها تمسّ فهرساً قائماً
فتخالف روحَ «الإضافة لا الكسر»؛ فالأولُ أوفق.

**اختبارُ الانحدار.** لكلِّ عمود: أنشئ صفّاً، احذفه ناعماً، أنشئ بالقيمةِ نفسِها —
وتوقّع `422` برسالةٍ عربيّة لا `500`.

**الثقة: عالية** للبنية؛ **متوسطة** لأولويّةِ كلِّ عمودٍ على حدة.

---

### A07-11 · **متوسطة** · ثمانيةُ جداولِ وحداتٍ بلا `company_id` — و`hub_scope` يفتح عند غيابِ العمودِ لا يُغلق

**الدليل.** من ٨٥ وحدةً في السجلّ، **٨** جداولٍ بلا عمودِ شركة:
```
competitors · deployments · dependencies · ideas · incidents · pricing_plans · internal_requests · restore_tests
```
و`hub_scope` لا يُطبّق عزلَ الشركةِ إلّا إن وُجد العمود:

`app/Support/helpers.php:211-215`
```php
if (($cids = hub_company_ids($user)) !== null && ($ccol = hub_company_col($module))) {
    …whereIn($ccol, $cids)…
}
```
و`hub_company_col()` يعيد `null` حين لا عمودَ في الجدول (`helpers.php:…: return $map[$module] = $has ? 'company_id' : null;`).

**الأثرُ الإنتاجيّ.** مستخدمٌ محصورٌ بشركةٍ واحدةٍ يرى **كلَّ** الحوادث، وكلَّ الطلبات
الداخليّة، وكلَّ عمليّاتِ النشر، وكلَّ اختباراتِ الاستعادة، لكلِّ الشركات. بعضُها عامٌّ
بطبيعته (`pricing_plans`, `competitors`, `dependencies`) — لكنّ `incidents`
و`internal_requests` و`deployments` و`restore_tests` بياناتٌ تشغيليّةٌ لشركةٍ بعينها.
والعزلُ هنا **يفشل مفتوحاً**، وهو الاتجاهُ الخطأ لحارس.

**(هذا في صميمِ الوكيلِ ٠٦؛ أُدرجه لأنّه حقيقةُ مخطّطٍ لا سلوكِ كود.)**

**الإصلاح (إضافيٌّ).** هجرةٌ تضيف `company_id` (nullable + فهرس) للجداولِ الأربعةِ
التشغيليّة، وتعبئةٌ من المشروعِ/المُنشئ حيث أمكن؛ ثمّ إعلانُ الحقلِ `ref → companies`
في `config/hub.php` فيلتقطه `hub_company_col` تلقائيّاً. وللوحداتِ العامّةِ عمداً:
قائمةُ إعفاءٍ **معلَنةٌ صراحةً** بدل أن يُستنتج الإعفاءُ من غيابِ عمود.

**اختبارُ الانحدار.** لكلِّ وحدةٍ في السجلّ: إمّا `hub_company_col()` غيرُ فارغٍ، وإمّا
اسمُها في قائمةِ الإعفاءِ المعلَنة — يفشل الآن بثمانية.

**الثقة: عالية جدّاً** للحقيقةِ البنيويّة.

---

### A07-12 · **متوسطة** · `alert_instances.company_id` و`endpoint_commands.company_id`: عمودٌ موجودٌ بلا فهرسٍ **وبلا قارئٍ يُرشّح به**

**الدليل.**
```
$ grep -rn "company_id" app/ | grep -i "alert_instance\|AlertInstance"     → (لا مخرَج)
$ grep -rn "company_id" app/ | grep -i "endpoint_command\|EndpointCommand" → (لا مخرَج)
$ mysql -N -e "SHOW INDEX FROM a07_probe.alert_instances"
  PRIMARY(id) · ai_dedup_unique(dedup_key) · ai_status_sev_last_idx(status,severity,last_at)
  · ai_incident_idx(incident_id) · alert_instances_rule_id_index(rule_id)      ← لا company_id
```
العمودُ يُكتَب ولا يُقرَأ ترشيحاً. (بالمقابل `security_findings` **يُرشَّح** بـ`company_id`
في `app/Support/SecurityFindings.php:319` — وفهرسُه ناقصٌ أيضاً، لكنّ `EXPLAIN` يُظهر أنّ
`sf_status_severity_idx` يحمل الحملَ فيبقى الأثرُ مقبولاً.)

**الأثرُ الإنتاجيّ.** مركزُ التنبيهاتِ وسجلُّ أوامرِ النقاطِ الطرفيّة بلا عزلِ شركةٍ
فعليّ — عمودٌ يوحي بالعزلِ ولا يُنفّذه. وهذا أسوأُ من غيابِه: يُقرأ في المراجعةِ كأنّه
محروس.

**الإصلاح.** تطبيقُ `hub_company_ids()` في قارئَي المركزَين + فهرسٌ
`(company_id, status)` إضافيّ.

**اختبارُ الانحدار.** مستخدمٌ محصورٌ بشركةٍ `A` يفتح مركزَ التنبيهات ولا يرى نسخةَ
تنبيهٍ لشركة `B` — يفشل الآن.

**الثقة: عالية جدّاً** للحقيقة؛ **متوسطة** لتصنيفِ الخطورةِ (قد يكون العزلُ مقصوداً
غيابُه للتنبيهاتِ التنظيميّة — لكن لا تعليقَ يقوله).

---

### A07-13 · **متوسطة-منخفضة** · قرعةُ `->first()` بلا ترتيبٍ ولا قيدٍ فريدٍ يحرس

**الجرد.** مسحتُ كلَّ عبارةِ `->first()/firstOrFail()/sole()` في `app/`, `routes/`,
`database/seeders/`، وأسقطتُ ما فيه `orderBy` أو `whereKey` أو عمودٌ فريد، ثمّ قابلتُ
أعمدةَ `where` بقائمةِ القيودِ الفريدةِ لكلِّ جدول: **٥٩ موضعاً** مرشَّحاً، أكثرُها
تجميعاتٌ (`selectRaw('SUM…')->first()`) أو مطابقاتٌ أحاديّة. الذي يحمل **أثراً دلاليّاً
مثبتاً ثمانيةٌ**:

| # | ملف:سطر | الاستعلام | لماذا قرعة |
|---|---|---|---|
| 1 | `app/Support/Endpoint.php:86` | `EndpointDevice where(asset_id) where(status='active')->first()` | لا قيدَ فريدٌ على `(asset_id, active)` — انظر `A07-05` |
| 2 | `app/Console/Commands/HubDigest.php:30` | `User whereNull(deleted_at) where(status='نشط') whereHas(role.is_owner)->first()` | **مالكون كُثُر مسموحون** — المُلخَّصُ اليوميُّ يُرسَل لمالكٍ بالقرعة |
| 3 | `app/Http/Controllers/Web/EsignController.php:1351` | `ContractSigner where(request_id) where(role='موقّع') whereNotNull(email)->value('email')` | طلبُ توقيعٍ له **عدّةُ** موقّعين بالدور نفسِه — و`contract_signers` بلا قيدٍ فريدٍ غيرِ `token` |
| 4 | `app/Http/Controllers/Web/FileController.php:34` | `Attachment where(path)->orWhere(thumb_path)->first()` | لا قيدَ فريدٌ على `path` — والمُنتقى يحكم `403` |
| 5 | `app/Support/Identity.php:233` | `RecordIdentifier where(module='products') where(norm)->first()` | القيدُ الفريدُ `(module, **kind**, norm)` — الاستعلامُ بلا `kind` فيطابق أصنافاً عدّة |
| 6 | `app/Http/Controllers/Web/IdentityController.php:192` | نفسُه | نفسُه |
| 7 | `app/Http/Controllers/Web/IdentityController.php:229` | نفسُه (`firstOrFail`) | نفسُه |
| 8 | `app/Console/Commands/HubRolesWidenCoreWork.php:33` | `Role where('name', $name)->first()` | `roles.name` بلا قيدٍ فريد — انظر `A07-15` |

**الأثرُ الأبرز (#3).** سجلُّ الامتثال: `PolicyAck` يُنسَب إلى مستخدمٍ يُحَلّ من بريدِ
«موقّعٍ ما» في الطلب. بأكثرَ من موقّعٍ يُنسَب الإقرارُ لغيرِ من وقّع — وهو سجلٌّ
رقابيٌّ يُدقَّق. و**#2** يعني أنّ إيقافَ مالكٍ عن العمل قد يُسكت المُلخَّصَ اليوميَّ كلَّه
أو ينقلَه لغيرِ المقصود، بلا أثرٍ يُنبّه.

**الإصلاح.** (أ) ترتيبٌ دلاليٌّ صريح: `orderBy('created_at')->orderBy('id')` لـ#2،
و`orderBy('stage')->orderBy('id')` لـ#3 (أوّلُ موقّعٍ في الترتيب لا «أيُّ» موقّع)، و
`->where('kind', $kind)` لـ#5-#7. (ب) هجرةٌ إضافيّةٌ تُثبّت القيدَ حيث المعنى يقتضيه:
`unique(request_id, email)` على `contract_signers`، و`unique(name)` على `roles`.

**اختبارُ الانحدار.** لكلِّ موضع: بذرُ **صفَّين** يطابقان الشرط، ثمّ تأكيدُ **أيّ**
الصفَّين عاد — فالاختبارُ الذي يبذر صفّاً واحداً لا يمسّ العيبَ (وهو تحديداً ما تحذّر منه
`CLAUDE.md`: «اختبارٌ يأخذ صفاً واحداً من أربعة يبدو ناجحاً وهو لم يمسّ ثلاثة»).

**الثقة: عالية** للجرد؛ **عالية** للثمانية المسمّاة.

---

### A07-14 · **منخفضة-متوسطة** · `ORDER BY` على عمودٍ خشنٍ بلا فاصلِ تعادل — ٧٤ موضعاً

**الجرد.** مسحتُ عبارات `orderBy/orderByDesc/latest/oldest` على أعمدةٍ خشنة
(`created_at`, `at`, `date`, `due`, `last_at`, `started_at`, `bucket_at` …) ينتهي بها
`first()/limit()/get()/paginate()` **بلا** `orderBy('id')` أو نظيره: **٧٤ موضعاً**.

الأكثرُ أثراً:

| ملف:سطر | ما يقع |
|---|---|
| `app/Console/Commands/HubOutbox.php:124` | دفعةُ تسليمِ Webhooks مرتّبةٌ بـ`created_at` (دقّةُ ثانية) والمفتاحُ UUID — فلا فاصلَ تعادلٍ ممكنٍ أصلاً. تحت دفقةٍ في ثانيةٍ واحدة تختلف تركيبةُ الدفعةِ بين المحرّكين |
| `app/Support/AlertEngine.php:220` | سيرُ سلسلةِ التصعيد `orderByDesc('created_at')->pluck('created_at')` — إشعاران في الثانية نفسِها يقلبان حسابَ «منذ متى» فيتغيّر يومُ التصعيد |
| `app/Support/Health.php:296` · `app/Support/Integrations.php:197,290` | «آخرُ خطأ» من صفوفٍ متعادلةِ الختم |
| `app/Console/Commands/HubDemo.php:292` | `users … orderBy('created_at')->value('id')` — «أوّلُ مستخدم» بالقرعة (بذرٌ فقط) |

**وأمّا الباقي فأثرُه عرضٌ لا حقيقة** (قوائمُ «أحدثُ ن»): يتبدّل ترتيبُ المتعادلَين
على الشاشة. لا أدّعي أكثرَ من ذلك.

**الإصلاح.** إضافةُ `->orderBy('id')` (أو `orderByDesc('id')`) فاصلَ تعادلٍ — وهو النمطُ
المتَّبَعُ سلفاً في **٦٦** موضعاً في المستودع نفسِه، فالإصلاحُ محاذاةٌ لا اختراع.

**اختبارُ الانحدار.** بذرُ صفَّين بالختمِ نفسِه بالضبط وتأكيدُ الترتيبِ المتوقَّع.

**الثقة: عالية** للجرد؛ **متوسطة** لتصنيفِ أيُّها دلاليّ.

---

### A07-15 · **منخفضة** · `roles.name` بلا قيدٍ فريدٍ ولا قاعدةِ تحقّق

**الدليل.**
```
$ mysql -N -e "SHOW INDEX FROM a07_probe.roles"
  UNIQ PRIMARY (id)          ← ولا شيءَ غيرُه
```
`app/Http/Controllers/Web/RoleController.php:342`
```php
$d = $r->validate(['name' => 'required|string|max:80', 'scope' => 'required|in:all,proj']);
```
لا `unique:roles,name`. والقرّاءُ بالاسم: `HubRolesWidenCoreWork.php:33`,
`DemoCompanySeeder.php:213`.

**الأثرُ الإنتاجيّ.** دوران باسم «مدير» ممكنان؛ منتقي الدورِ في شاشةِ المستخدم يعرضهما
متطابقَين بلا تمييز، وأمرُ توسيعِ الصلاحيّاتِ يمنحها لأحدهما بالقرعة ⇒ انحرافُ صلاحيّاتٍ
صامت. (لا تكرارَ في قاعدةِ المحاكاة اليوم — فحصتُه.)
**ملاحظةٌ جانبيّة:** التحقّقُ `max:80` أضيقُ من العمود `varchar(120)` — الاتجاهُ الآمن.

**الإصلاح.** قاعدةُ `Rule::unique('roles','name')->ignore($id)` أوّلاً (لا تُسقط بياناتٍ
قائمة)، ثمّ — بعد فحصِ خلوِّ الإنتاجِ من التكرار — هجرةٌ إضافيّةٌ `$t->unique('name')`
محروسةٌ بفحصِ تكرارٍ يوقفها.

**اختبارُ الانحدار.** إنشاءُ دورٍ باسمٍ قائمٍ يجب أن يردّ `422`.

**الثقة: عالية جدّاً.**

---

### A07-16 · **منخفضة** · كتابةٌ في عمودٍ غيرِ موجود تُبتلَع صامتةً — تصنيفُ سرِّ MDM يُفقَد

**الملف:** `app/Http/Controllers/Web/EndpointMdmController.php:66`

**الدليل.**
```php
$vs = VaultSecret::create([
    'title' => 'سرُّ تكامل MDM · ' . $d['provider'] . ' · ' . now()->format('Y-m-d H:i'),
    'kind' => 'مفتاح',                    // ← لا عمودَ بهذا الاسم؛ العمودُ الصحيح `type`
    …
]);
```
عمودُ `vault_secrets`: `… title, **type**, company_id, … secret_cipher …` — لا `kind`.
والنموذجُ يستعمل `$guarded` لا `$fillable` (`VaultSecret.php:23`)، فـLaravel يُسقط المفتاحَ
عبر `isGuardableColumn()` **بلا استثناء**. أثبتُّه على قاعدةِ الفحص:
```
$ tinker: VaultSecret::create(['title'=>…, 'kind'=>'مفتاح', 'secret_cipher'=>'x'])
CREATED OK id=11769697-…
$ mysql -e "SELECT title, type FROM a07_probe.vault_secrets"
title: سرُّ تكامل MDM …      type: NULL
```

**الأثرُ الإنتاجيّ.** كلُّ سرِّ تكاملِ MDM يدخل الخزنةَ بلا نوع. شاشةُ الخزنةِ ترشّح
بالنوع، وفحصُ التدوير في `SecurityPosture` يصنّف بالنوع ⇒ هذه الأسرارُ تسقط من الترشيح
والتصنيف. عطلٌ صامتٌ تماماً: لا استثناءَ، ولا تحذير، ولا فرقَ بين المحرّكين.

**(وهذا أيضاً السببُ الذي يجعل هذا الصنفَ لا يُمسَك: `$guarded` + `isGuardableColumn`
يبتلع كلَّ اسمِ عمودٍ خاطئ. مسحتُ كلَّ `Model::create/insert/updateOrCreate` في المستودع
فوجدتُ هذا الموضعَ وحدَه — وهو دليلُ انضباطٍ عالٍ عموماً.)**

**الإصلاح.** لا هجرة — `'type' => 'مفتاح'`. وحارسٌ عامّ: تشغيلُ
`Model::preventSilentlyDiscardingAttributes()` في `AppServiceProvider` **في بيئةِ الاختبارِ
والتطوير وحدَها** (لا الإنتاج — فهو يقلب السهوَ عطلاً حيّاً).

**اختبارُ الانحدار.** اختبارٌ يمرّ على كلِّ `X::create([...])` في المستودع ويؤكّد أنّ كلَّ
مفتاحٍ حرفيٍّ عمودٌ حقيقيٌّ في جدولِ `X` — يفشل الآن بواحد. (هذا أمتنُ من تصحيحِ السطر
وحدَه: يحرس الصنفَ لا الحالة.)

**الثقة: عالية جدّاً** (منسوخٌ من تنفيذٍ فعليّ).

---

### A07-17 · **منخفضة** · `hub_col_widths()` لا يفرّق بين `up()` و`down()` — فخٌّ حيٌّ لم يقع بعد

**الملف:** `app/Support/helpers.php:4290-4305`

**الدليل.** المُحلِّلُ يقسم ملفَّ الهجرةِ بـ`Schema::(create|table)` ويأخذ كلَّ
`->string('col', N)` في الكتلة — **بلا نظرٍ إلى أيِّ دالّةٍ هي**، و«الأخيرُ يفوز».
و`down()` يأتي بعد `up()` في الملفّ. مسحتُ كتلَ `down()` كلَّها: موضعٌ واحدٌ يُصرّح
بعرضٍ فعلاً — `2026_07_31_000003_fix_approvals_pending_default.php:27` بـ`status(30)` —
وهو مطابقٌ لعرضِ `up()` (`varchar(30)`) فلا انحرافَ اليوم.

**والتحقّقُ الشامل:** قابلتُ الخريطةَ كلَّها بمخطّطِ MySQL الحقيقيّ:
```
MAP TOO WIDE (hub_fit لن يحمي → MySQL 1406): count=0
MAP TOO NARROW (بترٌ صامتٌ زائد):            count=0
مدخلاتٌ بلا عمود:  companies_root.id · webhooks.secret · inbound_hooks.secret   (3)
```
⇒ **صفرُ عمودٍ نصّيٍّ أضيقُ ممّا يُكتب فيه.** درسُ `notifications_hub.kind` مُستوعَبٌ
بالكامل، والحزامُ يعمل.

**الأثرُ الإنتاجيّ.** لا شيءَ اليوم. لكنّ أوّلَ هجرةِ توسعةٍ يكتب مؤلّفُها `down()` يعيد
العرضَ القديمَ الأوسعَ (وهو ما يفعله `down()` الصحيح لتوسعةٍ عكسيّة) ستجعل الخريطةَ
تُبلّغ عرضاً **أوسعَ من الحقيقة** ⇒ `hub_fit` لا يقصّ ⇒ `1406` في الإنتاج بينما
الحزمةُ خضراء. وهو الصنفُ نفسُه الذي بُني الحزامُ لمنعه.

**الإصلاح.** لا هجرة — يمسح المُحلِّلُ كتلةَ `up()` وحدَها (استخراجُ نصِّ الدالّةِ بموازنةِ
الأقواس، كما فعلتُ في مُحلِّلي). والثلاثةُ «بلا عمود» تُنظَّف أو تُعلَّق.

**اختبارُ الانحدار.** اختبارٌ يقارن `hub_col_widths()` بـ`Schema::getColumns()` لكلِّ
جدولٍ ويؤكّد التطابقَ التامّ — يخضرّ اليوم، ويحمي غداً. (وهو الاختبارُ الذي كتبتُه هنا
يدويّاً ولا وجودَ له في `tests/`.)

**الثقة: عالية جدّاً** للقياس؛ **عالية** لتوصيفِ الفخّ.

---

### A07-18 · **منخفضة** · `dm_messages`: حذفٌ ناعمٌ يدويٌّ بلا `SoftDeletes`

**الدليل.** `dm_messages` هو **الجدولُ الوحيدُ** من ١٠٨ فيه `deleted_at` ولا يستعمل
نموذجُه سمةَ `SoftDeletes` (فحصتُ الـ١٥٢ نموذجاً). البديلُ نطاقٌ يدويّ:

`app/Models/DmMessage.php:20-25`
```php
public function scopeAlive($q)
{
    return hub_has_col('dm_messages', 'deleted_at') ? $q->whereNull('deleted_at') : $q;
}
```

**والتقييمُ منصف: هذا اختيارٌ مُعلَّلٌ لا سهو** — «المحذوفةُ تبقى صفّاً يُقرأ منه *حُذفت
رسالة*». وعدّادُ غيرِ المقروء يُرشّح صراحةً (`DmController.php:530`)، وقارئُ الخيطِ
يُبقيها عمداً (`DmService.php:166`). فحصتُ المواضعَ الاثنَي عشرَ كلَّها: لا موضعَ يُخالف
نيّتَه المعلَنة.

**الخطر الباقي.** العقدُ يعتمد على تذكّرِ `alive()` في كلِّ قارئٍ جديد — لا حارسَ آليّ.
و`OversightController.php:279` يبدأ من `DmMessage::query()` خامّاً (قراءةٌ رقابيّة —
صحيحٌ أن تشمل المحذوف، لكنّه غيرُ موثَّقٍ هناك).

**الإصلاح.** لا هجرة. توثيقٌ في النموذج + حارسٌ نصّيٌّ يمسح `DmMessage::` عن استعمالاتٍ
بلا `alive()` ولا `withDeleted`-نيّةٍ معلَنة.

**الثقة: عالية** (جردٌ كامل).

---

### A07-19 · **منخفضة** · حارسُ ترتيبِ مفاتيح JSON يغطّي ثمانيةَ لواصقَ من سبعةٍ وأربعين

**الملف:** `tests/Feature/JsonKeyOrderGuardTest.php:31-34`

**الدليل.** قائمةُ مصادرِ JSON في الحارس:
```php
private const JSON_SOURCES = ['json_decode', '->after', '->before', '->meta', '->custom',
                              '->payload', '->evidence', '->extra'];
```
بينما أسماءُ الأعمدةِ المُلقاةِ `array/json` في النماذجِ الـ١٥٢ **٤٧ اسماً**:
```
custom meta chain payload specs before after snapshot value flags services domain_ids
social_ids app_ids tags mentions read_by product_ids config parts asset_ids args result
hw posture usb_policy_map posture_checks approved_ssids team actions facility_ids providers
formula members matrix field_rules competitor_ids idea_ids opts blocks simplified
notify_prefs prefs companies clients recovery_codes allowed_ids
```
⇒ **٣٩ لاصقةً خارجَ التغطية** (`->matrix`, `->field_rules`, `->flags`, `->posture`,
`->hw`, `->specs`, `->snapshot`, `->config` …). وثغرةٌ ثانيةٌ في الشكل: الـregex يشترط
`assertSame(` و`[` و`=>` **في السطرِ نفسِه**، فمقارنةٌ تبدأ مصفوفتُها في السطر التالي
تفلت.

**وبالفحص: لا خرقَ اليوم.** أعدتُ المسحَ باللواصقِ السبعِ والأربعين وبنافذةِ خمسةِ أسطر
على `tests/` كلِّها ⇒ **٠ مخالفة**. والموضعان المعروفان (`CouncilAuditExtraGuardTest:66`
و`CouncilPdfBeltTest:218`) مُصلَحان بـ`sort($keys)` قبل التأكيد، مع تعليقٍ يشرح الدرس.

**الأثرُ.** لا شيءَ الآن؛ الحارسُ يدّعي تغطيةً أوسعَ ممّا يملك، وأوّلُ اختبارٍ يقارن
`->matrix` أو `->flags` كاملاً سيمرّ من تحته ثمّ يسقط على CI.

**الإصلاح.** اشتقاقُ `JSON_SOURCES` من النماذجِ نفسِها (مسحُ `$casts` عن
`array|json|collection|object|AsArrayObject|AsCollection`) بدل قائمةٍ مكتوبةٍ بيد —
فتنمو التغطيةُ مع كلِّ عمودٍ جديدٍ بلا تذكُّر. وتوسيعُ النافذةِ لتقبل بدايةَ المصفوفةِ
في سطرٍ لاحق.

**الثقة: عالية جدّاً.**

---

### A07-20 · **منخفضة** · جداولُ أبناءٍ بلا `deleted_at` تحت آباءٍ ذوي حذفٍ ناعم (١١ زوجاً)

**الدليل.**

| الابن | الأب | الابن `deleted_at` | الأب |
|---|---|---|---|
| `journal_lines` | `journal_entries` | لا | نعم |
| `payroll_lines` | `payroll_runs` | لا | نعم |
| `contract_signers` | `sign_requests` | لا | نعم |
| `contract_events` | `contracts` | لا | نعم |
| `conversation_members` | `conversations` | لا | نعم |
| `dashboard_widgets` | `dashboards` | لا | نعم |
| `endpoint_events`, `endpoint_commands` | `endpoint_devices` | لا | نعم |
| `track_points` | `track_sessions` | لا | نعم |
| `inventory_items`, `inventory_scans` | `inventory_sessions` | لا | نعم |

(وبالمقابل `quote_lines`, `quote_milestones`, `contract_obligations`, `client_memberships`,
`key_results` متماثلةٌ مع آبائها — فالفرقُ ليس عشوائيّاً.)

**الأثرُ الإنتاجيّ — محدودٌ اليوم وأثبتُّ حدودَه.** مسحتُ قرّاءَ `journal_lines` كلَّهم:
جميعُهم يُقيَّدون بـ`entry_id` من قيدٍ حيٍّ مقروءٍ سلفاً، فلا تقريرَ يجمع البنودَ مباشرةً.
وأكّدتُه بالبيانات:
```
JE unbalanced:                          0
JE with no lines:                       0
journal_lines with soft-deleted entry:  0
payroll_lines orphaned by deleted run:  0
quote_lines with soft-deleted quote:    0
```
فالأبناءُ الثابتون (`endpoint_events`, `track_points`, `inventory_scans`, `contract_events`)
**مقصودون**: سجلّاتُ وقائعَ لا تُحذف. والخطرُ الكامن في الثلاثة الأخرى
(`journal_lines`, `payroll_lines`, `conversation_members`): أوّلُ تقريرٍ يجمع من جدول
الأبناءِ مباشرةً سيضمّ بنودَ رؤوسٍ محذوفة.

**الإصلاح.** لا هجرة. توثيقُ النيّة (ثابتٌ عمداً) في النماذج، وحارسٌ يمنع الجمعَ من
جدولِ الأبناءِ بلا انضمامٍ إلى الأبِ الحيّ.

**اختبارُ الانحدار.** استعلامُ سلامةٍ (الأربعةُ أعلاه) يُشغَّل على قاعدةٍ مبذورةٍ بعد
حذفِ رأسٍ ناعماً، ويؤكّد `0`.

**الثقة: عالية** للبنية؛ **عالية** لكونِ الأثرِ كامناً لا حيّاً اليوم.

---

## ٤ · ما فحصتُه ولم أجد فيه عيباً (يُقال كي لا يُعاد)

### ٤٫١ عرضُ الأعمدةِ النصّيّة — نظيفٌ تماماً
- خريطةُ `hub_col_widths()` ↔ المخطّطُ الحقيقيّ: **٠ انحراف** على ١٨٨ جدولاً.
- مسحُ كلِّ `X::create/insert/updateOrCreate` عن قيمةٍ حرفيّةٍ أطولَ من عمودها: **٠**.
- مسحُ ثوابتِ النماذج (`const STATUS_*`, `const KINDS = [...]`) مقابلَ عرضِ عمودها: **٠**،
  ولا قيمةَ **مساويةً** للعرض بالضبط (وهي علامةُ خطرٍ سابقةٌ للفيض).
- ٩٥ استدعاءَ `hub_fit(...)`، وكلُّ احتياطيٍّ `?? N` فيها **يساوي أو يقلّ** عن العرضِ
  الحقيقيّ — فحصتُ الاثنين والعشرين كلَّها.
- مدخلاتُ المستخدمِ غيرُ المحدودة (`X-Endpoint-Nonce`, `hostname`, `agent_version`,
  `summary`) كلُّها مقصوصةٌ **عند الكاتب** بـ`mb_substr` بعرضِ العمود — لا بـ`substr`
  التي تكسر الحرفَ العربيّ.

### ٤٫٢ الهجراتُ — «الإضافة لا الكسر» محترمة
مُحلِّلٌ يفصل جسمَ `up()` عن `down()` بموازنةِ الأقواس، ثمّ يبحث عن
`dropColumn|dropIfExists|Schema::drop|truncate|forceDelete|dropUnique|dropIndex|renameColumn`:
**نتيجةٌ واحدةٌ** — `2026_01_01_000000_create_core_tables.php:12`
```php
Schema::create('companies_root', fn ($t) => $t->uuid('id')->primary());   // placeholder يُحذف
Schema::dropIfExists('companies_root');
```
جدولٌ يُنشأ ويُسقَط في السطرَين المتجاورَين — لا بياناتٍ ولا كسر. ⇒ **صفرُ هجرةٍ مُدمِّرة**.
و١٥ هجرةً تُعدّل قيماً (`->update(...)`) كلُّها تطبيعُ مرادفاتٍ أو تعبئةٌ خلفيّةٌ موثّقة.

### ٤٫٣ لهجةُ SQL بين المحرّكين — نظيفة
`ALTER TABLE … MODIFY` (٥ هجرات) محروسةٌ كلُّها بـ`DB::getDriverName() === 'mysql'`.
`CREATE INDEX … (col DESC)` مقبولةٌ على المحرّكين. `json_extract`/`meta->x` مُعلَّقٌ عليها
صراحةً بأنّها تعمل على الاثنين. ولا `ON DUPLICATE KEY` ولا `GROUP_CONCAT` ولا `strftime`
ولا `julianday` في `app/`. و**لا عمودَ `TIMESTAMP` واحدٍ** يحمل `ON UPDATE CURRENT_TIMESTAMP`
ضمنيّاً في القاعدة كلِّها (فحصتُه في `information_schema` — الهجراتُ الثلاثُ التي عالجته
أدّت عملَها).

### ٤٫٤ ترتيبُ مفاتيح JSON — العقدُ مصونٌ حيث يهمّ
الموضعان اللذان أسقطا أربعَ دفعاتٍ (`v2.525.0`←`v2.527.0`) مُصلَحان بالنمط الصحيح:
تأكيدُ كلِّ قيمةٍ بمفتاحها، ثمّ `sort($keys)` قبل تأكيد المفاتيح. و`JsonKeyOrderGuardTest`
يختبر **نفسَه** (`test_the_guard_itself_catches_the_shape_it_claims_to_catch`) — وهو نمطٌ
يستحقّ التعميم. الفجوةُ في اتّساعِ تغطيته لا في صحّته (`A07-19`).

### ٤٫٥ اتّساقُ المجاميع في قاعدةِ المحاكاة
```
قيودٌ غيرُ متوازنة (Σمدين ≠ Σدائن):        0
قيودٌ بلا بنود:                              0
مسيّراتُ رواتبَ يخالف مجموعُها بنودَها:     0
بنودٌ يتيمةٌ تحت رأسٍ محذوف:                 0 (payroll · quote · journal)
```
و«٤ عروضٍ يخالف مجموعُها بنودَها» التي رصدتُها أوّلاً تبيّن أنّها عروضٌ قديمةٌ بصيغةِ
`items` النصّيّة بلا بنودٍ أصلاً — لا تناقض.

### ٤٫٦ النماذجُ مقابلَ المخطّط
`$casts`/`$fillable`/`$dates` في ١٥٢ نموذجاً: **١٦ مفتاحَ `cast`** لا عمودَ له، وكلُّها
في أربعةِ نماذجَ تتقاسم قائمةَ لواصقَ واحدة (`AuditEntry`, `RecordLock`, `RecordVersion`,
`Setting` — `before/after/snapshot/value/flags`). ملقىً زائدٌ بلا أثر (Eloquent يتجاهل
لاصقةً لسمةٍ غائبة). ولا نموذجَ يستعمل `$fillable` (كلُّها `$guarded`) ⇒ لا حقلَ
«يُقرأ ولا وجودَ له» في التعريفات. و**٠** وحدةٍ بمفتاحٍ غيرِ UUID ⇒ لا تصادمَ أنواعٍ في
`(module, record_id)`.

---

## ٥ · الأرقامُ المطلوبة

| السؤال | الجواب |
|---|---|
| **كم عموداً أضيقَ ممّا يُكتب فيه؟** | **٠ نصّيّاً** (خريطةُ العرضِ تطابق المخطّطَ تماماً على ١٨٨ جدولاً) — و**١٩ حقلاً عدديّاً** بلا سقفِ عمودٍ في التحقّق، وهو الصنفُ العدديُّ المكافئُ لدرسِ `notifications_hub.kind` (`A07-01`) |
| **كم فهرساً ناقصاً مؤثّراً؟** | **٩** — `attachments.path`, `attachments.thumb_path`, `inbox_documents.path`, `dm_messages.att` (`A07-02`) · `(module,record_id)` على `alert_instances`, `inbox_documents`, `signal_states` (`A07-08`) · `company_id` على `alert_instances`, `endpoint_commands` (`A07-12`). **و١٠٨ جدولٍ** يقع فيها `deleted_at` خارجَ كلِّ فهرسٍ (`A07-09`، أثرٌ عامٌّ لا موضعيّ) |
| **كم موضعَ قرعةِ ترتيب؟** | **١٣٣ مرشَّحاً** = ٥٩ `->first()` بلا ترتيبٍ ولا قيدٍ فريد + ٧٤ `ORDER BY` على عمودٍ خشنٍ بلا فاصلِ تعادل. منها **١٤ ذاتُ أثرٍ دلاليٍّ مُثبَت** (٨ في `A07-13` + ٤ في `A07-14` + ٢ في `A07-06`) |
| **كم هجرةً مُدمِّرة؟** | **٠** من ٢٥٠ (الوحيدةُ المرشَّحةُ تُسقط جدولاً أنشأته في السطرِ السابق) |

---

## ٦ · التسلسلُ المقترَح للتنفيذ

| # | الملاحظة | الكلفة | لماذا هنا |
|---|---|---|---|
| ١ | `A07-01` سقفُ الأعدادِ الصحيحة | ساعة | أوسعُ أثرٍ إنتاجيٍّ، وبلا هجرةٍ أصلاً، وقياسُه فوريّ |
| ٢ | `A07-02` فهارسُ المسارات | ساعة | هجرةٌ إضافيّةٌ محضة، وأسخنُ مسارٍ في النظام |
| ٣ | `A07-04` حارسُ الحذفِ الناعمِ في `FileController` | نصفُ ساعة | سطرٌ واحدٌ ×٤، وأثرٌ تخويليّ |
| ٤ | `A07-05` قيدُ الجهازِ النشطِ الواحد | نصفُ يوم | نمطٌ جاهزٌ في المستودع (`active_flag`) |
| ٥ | `A07-03` `whereDate` على أعمدةِ `DATE` | نصفُ يوم | ٤٠+ موضعاً ميكانيكيّاً، وحارسٌ نصّيٌّ يمنع العودة |
| ٦ | `A07-13`/`A07-06` القرعاتُ الأربعَ عشرة | نصفُ يوم | كلٌّ منها سطرٌ + اختبارٌ ببذرِ صفَّين |
| ٧ | `A07-08`, `A07-12`, `A07-10`, `A07-15` | يوم | فهارسُ وقيودٌ إضافيّةٌ محروسة |
| ٨ | `A07-17`, `A07-19`, `A07-16` حرّاسٌ آليّون | يوم | لا يُصلحون عطلاً قائماً بل يمنعون صنفَه — وهو أعلى عائدٍ طويلِ الأمد |

---

## ٧ · بيئةُ الفحصِ ونظافتُها

```bash
mysql -uroot -e "CREATE DATABASE a07_probe CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
DB_CONNECTION=mysql DB_DATABASE=a07_probe … php artisan migrate --force     # ٢٥٠ هجرة · ١٨٨ جدولاً
# … كلُّ استعلاماتِ الفحصِ أعلاه …
mysql -uroot -e "DROP DATABASE a07_probe"
```
**`lynomia_month` لم تُكتَب ولم تُحذَف** — كلُّ ما جرى عليها `SELECT` و`EXPLAIN`.
**المحرّكُ المحلّيّ MariaDB 10.11.14** لا MySQL 8 (وهو تحديداً ما تُعلنه
`JsonKeyOrderGuardTest` عن نفسِها) — فما يخصّ إعادةَ ترتيبِ مفاتيحِ JSON لم أستطع
إثباتَه تشغيليّاً محلّيّاً، واعتمدتُ فيه على المسحِ النصّيِّ وحدَه، وقلتُه.
