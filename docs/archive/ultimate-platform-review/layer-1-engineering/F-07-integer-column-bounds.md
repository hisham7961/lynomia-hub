# F-07 — حدُّ العمودِ الصحيح: ٩٩٩٩ في خانةٍ مداها ٠–١٠٠

> المراجعةُ الشاملة · الطبقة ١ (تدقيقٌ هندسيّ) · أُغلق في **v2.550.0**
> الاختبار: `tests/Feature/UltimateReview/IntegerColumnsHaveBoundsTest.php` (٨ اختبارات · ١٩ تأكيداً)

## الدعوى

منذ **v2.318** يشتقّ النموذجُ سقفاً عدديّاً من **دقّةِ العمودِ العشريّ**،
وتوثيقُ الموضع في `ModuleController::rules()` يقول غايتَه صراحةً:

> «كان num/big يُتحقّق كـ`numeric` بلا حدّ، فقيمةٌ تفوق decimal(M,D) تمرّ على
> SQLite ثم يرفضها MySQL بـ22003 (٥٠٠ ورسالةٌ تُسرّب القيمة).»

الغايةُ صحيحةٌ والعلاجُ صحيحٌ — لكنّه **لم يبلغ نصفَ المشكلة**. فـ`hub_col_nums()`
كانت تقرأ من مصدرِ الهجرات نمطاً واحداً:

```php
preg_match_all("/->decimal\(\s*'([a-z0-9_]+)'\s*,\s*(\d+)\s*,\s*(\d+)/", …)
```

`decimal` وحدَها. و**العمودُ الصحيحُ لا يُقرأ أصلاً**، فيعود `hub_col_num_max()`
بـ`null` ويسقط الشرطُ كلُّه، فينال الحقلُ `numeric` عارياً: بلا سقفٍ ولا أرضيّة.

## الإثبات

### ① القارئُ صامتٌ عن العمودِ الصحيح

```
hub_col_num_max('websites', 'lighthouse')   →   NULL
```

و`websites.lighthouse` معرَّفٌ في `config/hub.php` حقلَ نموذجٍ رقميّاً:

```php
['key' => 'lighthouse', 'col' => 'lighthouse',
 'label' => 'درجة الأداء (Lighthouse)', 'type' => 'num']
```

وفي الهجرة `2026_07_31_000020_add_live_monitoring_fields.php:39`:

```php
$t->unsignedTinyInteger('lighthouse')->nullable();   // درجة الأداء ٠–١٠٠
```

### ② والقاعدةُ الحيّةُ ترفض — لا نظريّاً

نوعُ العمودِ كما **تراه القاعدةُ** لا كما تقوله الهجرة:

```sql
SELECT COLUMN_TYPE FROM information_schema.COLUMNS
 WHERE TABLE_NAME='websites' AND COLUMN_NAME='lighthouse';
→ tinyint(3) unsigned
```

وثلاثُ محاولاتِ إدراجٍ على جدولٍ مؤقّتٍ بالنوعِ نفسِه (`sql_mode` القائم:
`STRICT_TRANS_TABLES,…`):

| القيمة | النتيجة |
|---|---|
| `9999` | `ERROR 1264 (22003): Out of range value for column 'lighthouse' at row 1` |
| `-5`   | `ERROR 1264 (22003): Out of range value for column 'lighthouse' at row 1` |
| `88`   | ✓ خُزّنت `88` |

فـ«٩٩٩٩» — رقمٌ يكتبه مستخدمٌ ساهٍ في خانةٍ مداها ٠–١٠٠ — كانت تمرّ التحقّقَ،
وتبتلعها SQLite صامتةً في الحزمة، وترفضها MySQL في الإنتاج: **خمسمئةٌ على مسارٍ
مصادَق**، ورسالةُ الخطأ تحمل القيمةَ إلى مركز الأخطاء.

### ③ والاختبارُ قبل الإصلاح

```
test_an_out_of_range_integer_is_rejected_before_the_database   FAIL  Session is missing expected key [errors]
test_a_negative_value_is_rejected_on_an_unsigned_column        FAIL  Session is missing expected key [errors]
```

أي أنّ النموذجَ **قَبِل** ٩٩٩٩ وقَبِل ‏-5، فكُتب السجلُّ على SQLite.

## الأرضيّةُ ليست تفصيلاً

جردُ تصريحاتِ الأعمدةِ الصحيحةِ في `database/migrations/`:

```
111  ->integer(                 (مُوقَّع: ‏±٢١٤٧٤٨٣٦٤٧)
 54  ->unsignedInteger(
 13  ->unsignedSmallInteger(
 10  ->unsignedTinyInteger(
  6  ->unsignedBigInteger(
  1  ->tinyInteger(   ->smallInteger(   ->bigInteger(  (واحدٌ لكلٍّ)
```

الغالبُ الساحقُ `unsigned` — مداه **٠..N لا ‏±N**. وسقفٌ متناظرٌ كالذي يُشتقّ
للعشريّ (`between:-255,255`) **يقبل `-5`** فترفضه القاعدةُ بالخطأ نفسِه الذي
ترفض به ٩٩٩٩. فالعلاجُ **مدىً** لا سقفاً.

## العلاج

`hub_col_num_meta()` تقرأ الآن من مصدرِ الهجرات **العشريَّ والصحيحَ معاً**،
وتعيد لكلِّ عمودٍ `['min' => …, 'max' => …, 'int' => bool]` نصّاً دقيقاً
(`bigInteger` يتجاوز مدى `int` في PHP فلا يُمثَّل رقماً). وفوقها ثلاثةُ قُرّاء:

| القارئ | يعيد | مَن يستعمله |
|---|---|---|
| `hub_col_num_max($t,$c)` | أقصى نصّاً — **كما كان** | `PayrollController:55`، واختباران قائمان |
| `hub_col_num_range($t,$c)` | `[أدنى، أقصى]` | `ModuleController::rules()`، `ImportController` |
| `hub_col_is_int($t,$c)` | أعمودٌ صحيحٌ هو؟ | مسحُ التغطية في الاختبار |

والعشريُّ يبقى **متناظراً كما كان** (`['-9999999999999.999', '9999999999999.999']`)
فلا ينكسر ما بُني عليه — وهذا مؤكَّدٌ باختبارٍ صريح.

### موضعان أُصلحا

1. **`ModuleController::rules()`** — `between:-$nm,$nm` صارت `between:$rg[0],$rg[1]`.
   للعشريّ: نفسُ السلوكِ حرفاً بحرف. للصحيح: مدىً حيث لم يكن حدٌّ أصلاً.

2. **`ImportController`** — كان يقيس `abs($n) > (float) $nm`. و`abs()` تُمرّر
   `-5` إلى عمودٍ `unsigned` فتسقط **الدفعةُ كلُّها** بـ22003 عند التنفيذ.
   صار يقيس الطرفَين.

## القياسُ بعد الإصلاح

**تسعةَ عشرَ** حقلاً رقميّاً في النماذج تقف على أعمدةٍ صحيحة — كلُّها نالت مدىً:

```
websites     lighthouse       0..255              cycles       target_visits  0..4294967295
servers      cores            0..65535            cycles       frequency      0..4294967295
facilities   radius_m         0..4294967295       stock        carton_qty     0..4294967295
change_orders timeline_days   -2147483648..2147483647
rules        window_min       0..4294967295       rules        cooldown_min   0..4294967295
ideas        impact           0..255              ideas        confidence     0..255
ideas        ease             0..255              incidents    downtime_min   0..4294967295
deploys      duration_min     0..4294967295       restores     size_mb        0..4294967295
restores     duration_min     0..4294967295       competitors  trial_days     0..65535
media        reach            0..4294967295       events       leads          0..4294967295
```

## ما يقطع عودةَ الصنف

الاختبارُ الثامن ليس تأكيداً على حقلٍ بعينه بل **مسحٌ شاملٌ** يمرّ على كلِّ
وحدةٍ في السجلّ، وكلِّ حقلٍ من نوع `num`/`big`، ويسقط إن وجد واحداً يقف على
عمودٍ صحيحٍ بلا مدىً. فحقلٌ رقميٌّ جديدٌ يُضاف غداً على عمودٍ صحيحٍ **يُمسَك
في الحزمة** لا في الإنتاج.

## ما بقي خارجَ النطاق عمداً

قيمةٌ **كسريّةٌ** في عمودٍ صحيح (`lighthouse = 88.7`): MySQL تُدوّرها إلى ٨٩
بملحوظةٍ لا بخطأ، فلا خمسمئةَ ولا تعطُّل. هي فرقٌ دلاليٌّ بين المحرّكَين لا
عطلٌ إنتاجيّ، وإضافةُ قاعدةِ `integer` تمنع مستخدماً يكتب «٨٨٫٠» مشروعةً.
تُترك موصوفةً لا مُصلَحةً بلا دليلِ ضرر.
