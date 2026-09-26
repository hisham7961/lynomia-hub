# الوكيل ٠١ — معمارُ التطبيق (طبقة ١ · الجولة أ · اكتشافٌ للقراءة فقط)

> **الرأس** `1d90626` · **النسخة** `2.540.1` · **التاريخ** 2026-09-17
> **قاعدةُ هذه الوثيقة:** كلُّ ملاحظةٍ تحمل `ملف:سطر` ومقتطفَ كودٍ منسوخاً من المستودع.
> ما لم أستطع إثباتَه موسومٌ **ظنّيّ** صراحةً. لم يُعدَّل ملفٌّ واحدٌ في المستودع غيرَ هذه الوثيقة.

---

## ١ · الملخّصُ التنفيذيّ (خمسةُ أسطر)

1. **السكّتان حقيقيّتان لا شعار:** سجلُّ الوحدات (٨٥ وحدة) والمفتاحُ `(module, record_id)` يقودان الشاشاتِ والـAPI والجوّالَ من تعريفٍ واحد، وأكثرُ المجالاتِ الجوهريّة (الحضور، SLA، الإعدادات، التدقيق، المرفقات، بصمةُ الخبيئة) محرّكٌ واحدٌ فعلاً — فحصتُها فردةً فردةً ولم أجد ثانياً.
2. **لكنّ ادّعاءَ «0 محرّكٍ منافسٍ غيرِ مُبرَّر» (`docs/archive/final-audit/FINAL_REPORT.md:31,88`) مُفنَّد:** وجدتُ **خمسةَ** مجالاتٍ لها مصدرا حقيقةٍ متنافسان (عطلةُ الأسبوع، تعريفُ «المفتوح»، هامشُ الخدمة، الإقرارات، تفسيرُ الصلاحيّة) — أربعةٌ منها تُنتج **رقمين مختلفين لسؤالٍ واحد** على شاشتين.
3. **أخطرُها ثلاثةٌ:** كنسُ الغياب اليوميُّ يفصل `cost.weekend` بفاصلةٍ لاتينيّةٍ وحدَها بينما الإعدادُ يقبل الفاصلةَ العربيّة صراحةً (`A01-01`)؛ و٣٦ مرشِّحَ حالةٍ `whereNotIn` يُسقطون الصفَّ ذا الحالةِ الفارغة صامتاً بجوارِ `hub_open_scope` الذي وُضع لهذا بعينه (`A01-02`)؛ وهامشُ الخدمة يُحسَب داخلَ قالبِ Blade بوحدةٍ تخالف وحدةَ المحرّك فيُضاعَف تقريباً (`A01-03`).
4. **جذرُ أكثرِ الهشاشة واحد:** محرّكُ الوحدات يسكن في **متحكّمِ HTTP** (`ModuleController`, ١٩٦٧ سطراً)، فمن أراده ورِثَه أو استدعاه بـ`app(Controller::class)` — فانقلبت الطبقاتُ في ٨ أصنافِ خدمةٍ وتكوّنت ثلاثُ دوراتٍ صريحة.
5. **الوثائقُ متقدّمةٌ على الكود في مواضع:** `docs/ARCHITECTURE.md` يعلن عموداً مشتركاً (`OpStatus`) بلا قارئٍ إنتاجيّ واحد، ويعدّ ٨٢ وحدةً و١٧٢ هجرةً بينما الحقيقةُ ٨٥ و٢٥٠.

---

## ٢ · جردُ المجالاتِ وحدودِها

### ٢٫١ الحجمُ المقيس (بأوامرَ نُفِّذت على الرأس `1d90626`)

| البُعد | العدد | الأمر |
|---|---|---|
| وحداتُ السجلّ | **85** | `config("hub.modules")` عبر `artisan tinker` |
| `config/hub.php` | 9 497 سطراً | `wc -l` |
| نماذج (`app/Models`) | **152** | `ls \| wc -l` |
| متحكّمات | **141** (ويب 121 · API 19 + `Controller.php`) | `find app/Http/Controllers -name '*.php'` |
| أصنافُ `app/Support` | **136** جذراً (**151** بالفروع) | `ls` / `find` |
| `app/Support/helpers.php` | **7 325** سطراً · **208** دالّة | `wc -l` / `grep -c '^if (! function_exists'` |
| وسطاء | 18 | `ls app/Http/Middleware` |
| أوامرُ طرفيّة | 25 | `ls app/Console/Commands` |
| قوالب Blade | **384** | `find resources/views -name '*.blade.php'` |
| هجرات | **250** | `ls database/migrations` |
| مسارات | **632** (بلا تكرارِ `method+uri` — فحصتُه) | `route:list --json` |
| ملفّاتُ اختبار | 691 (منها 624 في `tests/Feature`) | `find tests` |

### ٢٫٢ خريطةُ المجالات وحدودُها الفعليّة

| المجال | المالكُ المُعلَن | المالكُ الفعليُّ في الكود | الحكم |
|---|---|---|---|
| محرّكُ الوحدات (CRUD/تصدير/دفعات) | `ModuleController` | `ModuleController` ← يرثه `V1Controller` ← يرثه `MobileResourceController` | **واحدٌ** — لكنّه في طبقةِ HTTP (`A01-05`) |
| الصلاحيّةُ والتنطيق | `hub_can`/`hub_scope`/`hub_field_mode` | كذلك — و**تفسيرُها** في `PermissionInspector` بنموذجٍ ثانٍ | **اثنان** (`A01-04`) |
| الإعدادات (الكتابة) | `Settings::put` | `Settings::put` + خمسةُ كُتّابٍ مباشرين (نبضات/تكاملات/حقول مخصّصة) — كلُّهم يُبطلون الخبيئةَ يدويّاً | مقصودٌ جزئيّاً · بلا تاريخٍ ولا أثرٍ للمباشرين |
| صحّةُ المشروع | `hub_project_health` | `hub_project_health` (+ `_for` يُعيد الحسابَ عند حجبِ الميزانيّة) | **واحدٌ** · عتبتُه منسوخةٌ ٦ مرّات (`A01-07`) |
| «مشروعٌ مفتوح/مغلق» | `hub_closed_states`/`hub_open_scope` | هما + **٣٦** قائمةَ حالاتٍ حرفيّةً NULL-unsafe | **متنافسان** (`A01-02`) |
| عطلةُ الأسبوع | `cost.weekend` | ثلاثةُ محلّلاتٍ للنصّ نفسِه، أحدُها يخالف الاثنين | **متنافسان** (`A01-01`) |
| الحضورُ والامتثال | `DailyWorkCompliance` | `DailyWorkCompliance` حصراً؛ `Workday` و`MonthlyAttendance` يستهلكانه | **واحدٌ — نظيف** |
| SLA | `hub_sla` | `hub_sla` في ٦ قرّاء | **واحدٌ — نظيف** (إسقاطُ «أوّلِ ردّ» مكرَّرٌ يدويّاً · `A01-14`) |
| الإشعارات | `HubNotification` + خطّافاتُ النموذج | كذلك (الكتمُ والقصُّ والدفعُ في `booted()` فيغطّي كلَّ الكُتّاب) | **واحدٌ — نظيف** |
| التعاون | `Conversation`/`comments`/`dm_messages` | كذلك — لكنّ جوهرَ DM في `DmController` والخدمةُ تستدعيه | **واحدٌ** · طبقتُه مقلوبة (`A01-05`) |
| الإقرارات | `Acks` + `config/hub_acks.php` + `record_acks` | **بالإضافة إلى** `hub_ack_*` + `PolicyAck` + `policy_acks` بسجلٍّ ثانٍ مكتوبٍ في `helpers.php` | **متنافسان** (`A01-06`) |
| هامشُ الخدمة | `hub_service_costs` | هو + حسابٌ ثانٍ داخلَ قالبِ Blade بوحدةٍ مخالفة | **متنافسان** (`A01-03`) |
| الرايات (flags) | — | `RoleController::FLAGS` — كتالوجُ مجالٍ داخلَ متحكّم، يقرؤه ٣ أصنافِ خدمةٍ و٤ قوالب | **واحدٌ** · في المكان الخطأ (`A01-05`) |
| المُطهِّر (Redactor) | عمودٌ مشترك | يقرؤه **٢٠** ملفاً | **واحدٌ — نظيف** |
| بصمةُ خبيئةِ الشاشة | `hub_scope_key` | يحمل الدورَ والمستخدمَ والشركةَ والعميلَ والعدسةَ وختمَ `roles`/`users` | **واحدٌ — نظيف** |

---

## ٣ · الملاحظات

### A01-01 · محرّكان لعطلةِ الأسبوع: كنسُ الغياب يعمى عن قيمتين يقبلهما الإعدادُ نفسُه

**الخطورة: P1** · **الثقة: مؤكَّد** (الأثرُ بحجمِ P0 لكنّه مشروطٌ بقيمةِ إعدادٍ بعينها)

**الدليل — ثلاثةُ محلّلاتٍ لنصٍّ واحد:**

`app/Support/MonthlyAttendance.php:60-64` و`app/Support/helpers.php:4553-4555` (متطابقان):

```php
$off = array_filter(array_map('intval', preg_split('/[،,\s]+/u',
    (string) setting('cost.weekend', '5,6'), -1, PREG_SPLIT_NO_EMPTY)));
return $off ?: [5, 6];
```

`app/Support/Workday.php:371-373` (المخالف):

```php
// عطلة الأسبوع من الإعداد نفسه الذي تقرؤه hub_workdays — لا غياب في عطلة
$weekend = array_map('intval', array_filter(explode(',', (string) setting('cost.weekend', '5,6'))));
if (in_array((int) date('N', strtotime($date)), $weekend, true)) return 0;
```

**والإعدادُ يقبل الفاصلةَ العربيّة صراحةً** — `config/hub_settings.php:478`:

```php
'validation'  => ['re' => '/^[1-7](\s*[,،]\s*[1-7])*$/u',
                  'msg' => 'أرقام ١-٧ مفصولةً بفاصلة (٥ الجمعة · ٦ السبت · ٧ الأحد) …'],
```

**البرهانُ المُشغَّل** (‏`php -r` على القيمة `"5،6"` وهي قيمةٌ يقبلها التحقّقُ أعلاه):

```
Workday:                     [5]
helpers/MonthlyAttendance:   [5,6]
القيمةُ الفارغة · Workday:    []          ← ولا ارتدادَ إلى [5,6]
```

**والقيمةُ الفارغةُ مقبولةٌ صراحةً أيضاً** — `app/Support/Settings.php:505-506`:

```php
$v = trim((string) $value);
if ($v === '' || preg_match($re, $v)) return null;      // الفراغُ يمرّ بلا تحقّق
```

فمسحُ الحقلِ من شاشةِ الإعدادات فعلٌ عاديٌّ مسموح، وبعده: `hub_workdays`/`MonthlyAttendance` يرتدّان إلى `[5,6]` بـ`?: [5, 6]`، و`Workday` يبقى على `[]` — **فلا عطلةَ أسبوعٍ عنده إطلاقاً**.

**والمسارُ حيٌّ:** `app/Console/Commands/HubAutomation.php:35` → `\App\Support\Workday::close()` يومياً.

**لماذا يضرّ:** `Workday::close()` (‏`app/Support/Workday.php:367-425`) يكتب صفَّ `attendance` بحالة `ABSENT` في **السجلِّ الدائم** لكلِّ موظّفٍ نشط، والتعليقُ فوقَه يقول «‏`effective` هو العمودُ الذي تُبنى عليه المحاسبةُ الشهريّة». فمالكٌ عربيُّ الكيبورد يكتب `5،6` — وهي قيمةٌ **صحيحةٌ** يقبلها الحارس — فتقرأ الشاشاتُ والقدراتُ والسِّجلُّ الشهريُّ السبتَ عطلةً بينما **يُدين الكنسُ كلَّ موظّفٍ نشطٍ غياباً كلَّ سبت**. والقيمةُ الفارغةُ أسوأ: `[]` عند `Workday` و`[5,6]` عند الباقين ⇒ غيابٌ مكتوبٌ يومَي الجمعة والسبت معاً. التناقضُ لا يظهر في أيّ شاشة: الشاشةُ تقول «عطلة» والقاعدةُ فيها «غائب»، والمحاسبةُ الشهريّةُ تقرأ القاعدة.

**الإصلاحُ المقترح:** حذفُ السطر ٣٧٢ واستدعاءُ `MonthlyAttendance::weekendDays()` (‏أو `MonthlyAttendance::isWeekend($date)` مباشرة) — فهو المحلّلُ الذي يقرؤه بقيّةُ النظام. ويُحوَّل `hub_workdays` (‏`helpers.php:4553`) إليه أيضاً كي يبقى محلّلٌ واحدٌ لا ثلاثة.

**اختبارُ الانحدارِ المقترح:**

```php
public function test_sweep_honours_arabic_comma_weekend(): void
{
    $this->hubSetting('cost.weekend', '5،6');           // فاصلةٌ عربيّة — يقبلها التحقّق
    $sat = \Illuminate\Support\Carbon::parse('2026-09-19')->toDateString();  // سبت
    $emp = Employee::factory()->create(['status' => 'نشط']);
    $this->assertSame(0, \App\Support\Workday::close($sat), 'السبتُ عطلةٌ فلا ختمَ غياب');
    $this->assertDatabaseMissing('attendance', ['emp_id' => $emp->id, 'date' => $sat]);
    // والقيمةُ الفارغةُ ترتدّ للافتراضيّ لا تُلغي العطلةَ
    $this->hubSetting('cost.weekend', '');
    $this->assertSame(0, \App\Support\Workday::close($sat));
}
```

---

### A01-02 · «المفتوح» بلا سلطةٍ واحدة: ٣٦ مرشِّحاً يُسقط الصفَّ ذا الحالةِ الفارغة صامتاً

**الخطورة: P1** · **الثقة: مؤكَّد**

**الدليل — السلطةُ موجودةٌ ومكتوبةٌ لهذا بعينه** (‏`app/Support/helpers.php:3769-3774`):

```php
function hub_open_scope($q, string $col = 'status', array $alsoClosed = [])
{
    $closed = array_values(array_unique(array_merge(hub_closed_states(), $alsoClosed)));
    return $q->where(fn ($w) => $w->whereNull($col)->orWhereNotIn($col, $closed));
}
```

والدرسُ مكتوبٌ في `SupportController.php:19-22` بعد عطلٍ حقيقيّ (v2.324):

```php
// NULL NOT IN يُقيَّم «مجهولاً» لا «صحيحاً» على المحرّكين معاً، فتذكرةٌ
// بلا حالة كانت تسقط من اللوحة صامتةً: لا أحد يردّ ولا شيء يقول لماذا (v2.324)
->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', $this->closed));
```

**ومع ذلك: ٣٦ من ٣٧ موضعِ `whereNotIn('status', …)` في `app/` ليس فيها حارسُ `NULL`** (فحصٌ آليٌّ بسياقِ ثلاثةِ أسطر حول كلِّ موضع). والأشدُّ دلالةً أنّ **الانقسامَ داخلَ الدالّةِ الواحدة** — `hub_project_health`:

| السطر | العامل | الصياغة |
|---|---|---|
|  `helpers.php:3412` | ٣ · انضباطُ المهام | `hub_open_scope(...)` ✔ **مُصلَح ومُعلَّقٌ عليه** |
| `helpers.php:3424` | ٤ · المخاطرُ المفتوحة | `->whereNotIn('status', ['مغلقة','محلولة','ملغاة'])` ✘ |
| `helpers.php:3443` | ٦ · عبءُ الدعم | `->whereNotIn('status', ['تم الحل','مغلقة'])` ✘ |

والتعليقُ فوق العامل ٣ يشرح العطلَ بنفسِه:

```php
// **السلطةُ الواحدةُ لـ«مفتوحة»** (مجلس الخبراء · A-1): كانت قائمةً
// حرفيّةً، و`NULL NOT IN (…)` **لا يصدُق في SQL** — فمهمّةٌ بلا حالةٍ
// … تسقط من عدّادِ المتأخّر بصمت: «٠ متأخرة من ١٢» ومهامُّ المشروعِ كلُّها فائتة.
```

**والعمودُ فارغٌ بالبناء:** `database/migrations/…_create_tickets_table.php:23` → `$t->string('status', 80)->nullable()->index();` (‏وكذلك `issues`, `tasks`, `projects`, `companies`…)، و`config/hub.php` يعرّف `tickets.status` بلا `req`:

```json
{"key":"status","col":"status","label":"الحالة","type":"sel",
 "options":["جديدة","قيد المعالجة","بانتظار العميل","محوّلة لمهمة","تم الحل","مغلقة"]}
```

**عيّنةٌ من المواضع المصابة** (‏٣٦ إجمالاً):

| الملف:السطر | القارئ | الأثرُ حين تكون الحالةُ فارغة |
|---|---|---|
| `app/Support/AlertEngine.php:364` | تصعيدُ خرقِ SLA | تذكرةٌ متجاوزةٌ **لا يُنبَّه عليها أحد** |
| `app/Support/helpers.php:3443` | عاملُ «عبء الدعم» في صحّةِ المشروع | الصحّةُ تُقرأ **أعلى من الحقيقة** |
| `app/Support/helpers.php:3424` | عاملُ «المخاطر المفتوحة» | كذلك |
| `app/Support/Health.php:387` | عدّادُ الحوادثِ المفتوحة | لوحةُ التشغيل تقول «لا حوادث» |
| `app/Http/Controllers/Web/MorningController.php:67,81,120,243` | الموجزُ الصباحيّ | بنودٌ تختفي من موجزِ المالك |
| `app/Support/Engagements.php:98,116,123` | صحّةُ ارتباطِ العميل | كذلك |
| `app/Console/Commands/HubDigest.php:64` · `HubAutomation.php:162` | المُلخَّصُ والأتمتة | كذلك |
| `app/Models/Contract.php:55` · `LegalController.php:64` | التزاماتُ العقد | و**بإملاءٍ مختلف**: `'ملغي'` هنا، `'ملغى'` عند `Engagements:116` |

**لماذا يضرّ:** ليس السقوطُ الصامتُ وحدَه؛ بل أنّ **الشاشتين تختلفان على العدد نفسِه**: `/support` يعرض التذكرةَ الفارغةَ الحالةِ ويعدّها، و`AlertEngine` لا يراها فلا يُنبّه، و`hub_project_health` لا يعدّها فيُظهر مشروعاً «سليماً». وهذه القرعةُ تُعلّم العادةَ الخطأ: يُصلَح الموضعُ المشتكى منه وحدَه ثلاثَ مرّاتٍ ويبقى ثلاثةٌ وثلاثون.

**تصحيحٌ لسجلِّ الدَّين:** `docs/TECH_DEBT.md:37` يسجّل هذا بنداً **P3** («~٢٥ مصفوفةَ حالاتٍ مغلقة حرفية»). العددُ الفعليُّ ٣٦، والأثرُ **إسقاطُ صفوفٍ من مساراتِ تنبيهٍ وصحّةٍ ومحاسبة** لا مجرّدُ تكرارٍ أسلوبيّ — فالتصنيفُ P3 أدنى مما يستحقّ.

**الإصلاحُ المقترح:** استبدالُ كلِّ `whereNotIn('status', [...])` بـ`hub_open_scope($q, 'status', [... الحالاتُ الخاصّةُ بالوحدة])` — الدالّةُ تقبل `alsoClosed` فتغطّي المفرداتِ الخاصّة (`مغلق بتقرير`, `مُستعاد`) بلا فقدِ دلالة. ويُضاف حارسٌ ثابت: اختبارٌ يمسح `app/` بتعبيرٍ نمطيّ ويسقط على أيّ `whereNotIn('status'` جديدٍ بلا `whereNull` مجاور.

**اختبارُ الانحدارِ المقترح:**

```php
public function test_status_null_row_is_open_everywhere(): void
{
    $p = Project::factory()->create();
    $t = Ticket::factory()->create(['project_id' => $p->id, 'status' => null]);   // حالةٌ فارغة

    // ١) يُعَدّ في عبء الدعم داخل صحّة المشروع
    $h = hub_project_health($p->id, fresh: true);
    $load = collect($h['factors'])->firstWhere('k', 'عبء الدعم');
    $this->assertStringContainsString('1 تذكرة مفتوحة', $load['note']);

    // ٢) يبلغه محرّكُ التنبيه
    $t->forceFill(['due' => now()->subMonth()])->saveQuietly();
    $out = [];
    (new \App\Support\AlertEngine)->evaluate($out);
    $this->assertTrue(collect($out)->contains(fn ($x) => str_contains(json_encode($x), $t->id)));

    // ٣) حارسٌ ثابتٌ ضدّ عودةِ النمط
    $bad = [];
    foreach (\Illuminate\Support\Facades\File::allFiles(app_path()) as $f) {
        foreach (file($f) as $i => $line) {
            if (str_contains($line, "whereNotIn('status'")
                && ! str_contains(implode('', array_slice(file($f), max(0,$i-2), 4)), "whereNull('status')")) {
                $bad[] = $f->getRelativePathname() . ':' . ($i + 1);
            }
        }
    }
    $this->assertSame([], $bad, "مرشِّحُ حالةٍ NULL-unsafe — استعمل hub_open_scope:\n" . implode("\n", $bad));
}
```

---

### A01-03 · هامشُ الخدمة يُحسَب في القالب بوحدةٍ تخالف وحدةَ المحرّك — فيُضاعَف تقريباً

**الخطورة: P1** · **الثقة: مؤكَّد**

**الدليل — الحسابُ في القالب** (‏`resources/views/modules/custom/services.blade.php:3-5,38-41`):

```php
$svcPrice = (float) ($row->price ?? 0);
$svcCost = (float) ($row->cost ?? 0);
$svcMargin = ($svcCanCost && $svcPrice > 0) ? round(($svcPrice - $svcCost) * 100 / $svcPrice, 1) : null;
…
<b class="{{ $svcMargin < 20 ? 'txt-bad' : '' }}">{{ $svcMargin }}٪</b>
<span>الهامش · تكلفة {{ $svcNum($svcCost) }}</span>
```

**والحسابُ في المحرّك** (‏`app/Support/helpers.php:4960-4999`):

```php
$priceM = hub_cycle_monthly($s->price, $s->cycle);                        // :4960
$declared = $s->cost !== null ? (float) $s->cost : null;   // «تكلفة التشغيل الشهرية» شهرية أصلاً
…                                                          // $serverM ÷ المتشاركين · $domainM ÷ ١٢ · $tools
$parts = array_filter([$declared, $serverM, $domainM, $tools ?: null], fn ($v) => $v !== null);
$costM = $parts ? round(array_sum($parts), 3) : null;                     // :4986
$margin = ($priceM !== null && $costM !== null) ? round($priceM - $costM, 3) : null;
'marginPct' => ($margin !== null && $priceM > 0) ? (int) round($margin / $priceM * 100) : null,
```

**وتعريفُ الحقول يُثبت اختلافَ الوحدة** (‏`config/hub.php` · وحدةُ `services`):

```json
{"key":"price","label":"السعر","type":"num","money":true}
{"key":"cost","label":"تكلفة التشغيل الشهرية","type":"num","money":true}
{"key":"cycle","label":"دورة الفوترة","type":"sel","options":["شهري","ربع سنوي","سنوي","مرة واحدة"]}
```

و`hub_cycle_monthly` (‏`helpers.php:4918-4929`) يقسم `سنوي ÷ 12` و`ربع سنوي ÷ 3` — **والقالبُ لا يستدعيه**.

**لماذا يضرّ:** خدمةٌ سنويّةٌ سعرُها ١٢٠٠ وتكلفتُها الشهريّةُ ٥٠:
* شاشةُ السجلّ (‏`/m/services/{id}`) تقول: `(1200 − 50) / 1200 = ٩٥٫٨٪ هامش` — **أخضرُ مطمئنّ**.
* شاشةُ التكاليف (‏`/costs/services`) تقول: `priceM = 100`, `costM = 50 + حصّةُ السيرفر + الدومين + الأدوات` — قد يكون الهامشُ **سالباً**.

فرقمان لسؤالٍ واحد على شاشتين متجاورتين، والقرارُ المبنيُّ عليه قرارُ تسعير. والأسوأ أنّ القالبَ يضع نبرةَ الخطر عند `< 20` — أي أنّه **لن يُنذر أبداً** على خدمةٍ سنويّة مهما خسرت.

**الإصلاحُ المقترح:** يُحذَف الحسابُ من القالب ويُقرأ من المحرّك: `hub_service_costs()['rows']` مفهرسةً بالمعرّف (‏أو دالّةٌ رقيقة `hub_service_margin(string $id): ?array` تقرأ الخبيئةَ نفسَها فلا استعلامَ إضافيّ). ويبقى حارسُ `hub_field_mode(…, 'cost') === 'hide'` كما هو.

**اختبارُ الانحدارِ المقترح:**

```php
public function test_service_margin_is_identical_on_record_and_costs_screens(): void
{
    $s = Service::factory()->create(['price' => 1200, 'cycle' => 'سنوي', 'cost' => 50, 'status' => 'نشطة']);
    $engine = collect(hub_service_costs(fresh: true)['rows'])->firstWhere('id', $s->id);

    $html = $this->actingAs($this->owner())->get(route('m.show', ['services', $s->id]))->getContent();
    preg_match('/>([\d.]+)٪<\/b>\s*<span>الهامش/u', $html, $m);

    $this->assertNotEmpty($m, 'لم يُعرَض الهامشُ في بطاقة الخدمة');
    $this->assertSame((int) $engine['marginPct'], (int) round((float) $m[1]),
        'هامشُ البطاقة يخالف هامشَ hub_service_costs — مصدرا حقيقةٍ متنافسان');
}
```

---

### A01-04 · مُفسِّرُ الصلاحيّة يخالف المحرّكَ في الاتّجاهين: يقول «ممنوع» حيث يسمح، و«مسموح» حيث يمنع

**الخطورة: P1** · **الثقة: مؤكَّد**

`PermissionInspector` هو ما تعرضه شاشةُ «تشخيصِ الوصول» للمالك (‏`routes/web.php:798` · `AccessController:43-53,87-90`) جواباً عن «ماذا يرى هذا الموظف ولماذا؟». ويُعلن عن نفسه (`app/Support/PermissionInspector.php:11-18`):

> «طبقةُ قراءةٍ وتفسيرٍ **لا محرّكُ توثيقٍ ثانٍ** … **تفوّض لا تكرّر** … لا تُتَّخذ قرارُ سماحٍ هنا أبداً».

**لكنّه يُعيد بناءَ القرارِ بقواعدَ لا يملكها المحرّك، وينقص عنه قاعدةً يملكها:**

**(أ) «الكتابةُ تستلزم العرض» — قاعدةٌ يخترعها المفسِّر ولا يفرضها المحرّك.**
`app/Support/PermissionInspector.php:87-92`:

```php
// 5) الكتابةُ تستلزم العرض (دلالةُ المحرّك) — يُبيَّن للمُفسِّر
if ($op !== 'v' && ! hub_can($user, $module, 'v')) {
    $add("العمليّة ({$op})", false, 'الكتابةُ تستلزم العرضَ أولاً، والعرضُ ممنوع');
    return self::verdict(false, 'DENIED_ROLE', …);
}
```

والمحرّكُ **لا يشترطها** — `app/Http/Controllers/Web/ModuleController.php:16-19`:

```php
protected function resolve(string $module, string $op): array
{
    $def = hub_mod($module);
    abort_if(! $def || $module === 'users', 404);
    abort_unless(hub_can(auth()->user(), $module, $op), 403, 'لا تملك صلاحية على هذه الوحدة');
```

و`hub_can` نفسُها (‏`helpers.php:381-389`) لا تعرف `$op` إلا مفتاحاً في المصفوفة: `return (bool) (($matrix[$module][$op] ?? 0));`. فدورٌ ضُبط `a=1, v=0` **يُنشئ السجلَّ فعلاً**، والشاشةُ تقول للمالك «ممنوع».

**(ب) حارسُ `endpoints` — قاعدةٌ يملكها المحرّكُ ولا يعرفها المفسِّر.**
`ModuleController.php:22-27`:

```php
if ($module === 'endpoints') {
    abort_unless(hub_is_owner() || hub_monitor_group('secOps'), 403,
        'أسطولُ النقاطِ الطرفيّة للمالكِ أو حاملِ مجموعةِ الأمن (secOps)');
}
```

وفحصُ `grep -n "endpoints\|hub_monitor_group" app/Support/PermissionInspector.php` يعود **فارغاً**. فدورٌ يحمل `endpoints:v` بلا `secOps` تقول عنه الشاشةُ «مسموح» ويردّه البابُ ٤٠٣.

**لماذا يضرّ:** هذه الشاشةُ هي أداةُ المالكِ لمراجعةِ الصلاحيّات قبل منحِها أو سحبِها — و`moduleMatrix` (‏`PermissionInspector.php:200-218`) تبني منها **مصفوفةً كاملةً** لكلِّ وحدةٍ × كلِّ عمليّة. فالمالكُ يقرأ خانةً حمراءَ فيطمئنُّ إلى منعٍ لا وجودَ له (أ)، أو خانةً خضراءَ فيظنُّ الوصولَ ممنوحاً ثمّ يشكو الموظّفُ ٤٠٣ (ب). ومفسِّرُ صلاحيّةٍ يخالف الحارسَ **أسوأُ من غيابه**، لأنّه يُغلق بابَ الشكّ.

**الإصلاحُ المقترح:** أن يصير القرارُ اشتقاقاً لا محاكاة — إمّا:
* رفعُ القاعدتين إلى المحرّك: `hub_can` تفرض «الكتابةُ تستلزم العرض»، و`endpoints` يصير مدخلاً في سجلِّ حرّاسٍ (`config/hub_permissions.php`) يقرؤه الطرفان؛ أو
* بقاءُ الاستثناءات في `ModuleController` مع تصديرِها ثابتاً عامّاً (`ModuleController::GATES`) يقرؤه المفسِّر — وهو الأسرع، وإن زاد الاقترانَ المشخَّص في `A01-05`.

**اختبارُ الانحدارِ المقترح:**

```php
public function test_inspector_verdict_matches_engine_for_every_module_and_op(): void
{
    $role = Role::factory()->create(['matrix' => ['projects' => ['a' => 1, 'v' => 0], 'endpoints' => ['v' => 1]]]);
    $u = User::factory()->create(['role_id' => $role->id]);

    foreach (array_keys(config('hub.modules')) as $mk) {
        foreach (['v', 'a', 'e', 'd'] as $op) {
            $said = \App\Support\PermissionInspector::allows($u, $mk, $op);
            $did  = $this->engineAllows($u, $mk, $op);   // يضرب البابَ فعلاً ويقرأ 403/404 مقابل ما سواه
            $this->assertSame($did, $said, "المفسِّرُ يخالف المحرّكَ على {$mk}.{$op}");
        }
    }
}
```

---

### A01-05 · محرّكُ الوحدات يسكن في متحكّمِ HTTP — فانقلبت الطبقاتُ وتكوّنت ثلاثُ دوراتٍ صريحة

**الخطورة: P2** · **الثقة: مؤكَّد**

**الدليل ١ — أصنافُ الخدمة تستورد المتحكّمات** (‏٨ ملفات في `app/Support/`):

```
app/Support/CommentService.php:5      use App\Http\Controllers\Web\ConversationController;
app/Support/DmService.php:5           use App\Http\Controllers\Web\DmController;
app/Support/ChatCommands.php:5        use App\Http\Controllers\Web\CommentController;
app/Support/ApprovalService.php:5     use App\Http\Controllers\Web\ModuleController;
app/Support/CollaborationRail.php:5-6 use …\ConversationController;  use …\DmController;
app/Support/SecurityExposure.php:5    use App\Http\Controllers\Web\RoleController;
app/Support/IdentityRisk.php:5        use App\Http\Controllers\Web\RoleController;
app/Support/MobilePlatform.php:290    app(\App\Http\Controllers\Api\MobileAuthController::class)->appConfig($req);
```

**الدليل ٢ — الدوراتُ الثلاث** (كلُّ طرفٍ يستورد الآخر):

| الدورة | الاتّجاه الأوّل | الاتّجاه الثاني |
|---|---|---|
| DM | `DmController.php:11` → `use App\Support\DmService;` | `DmService.php:42,57,89` → `DmController::deriveDmCompany/ensureDmConversation/dmReachable` |
| التعليقات | `CommentController.php:11` → `use App\Support\ChatCommands;` | `ChatCommands.php:208` → `CommentController::userNames()` |
| الاعتماد | `ModuleController.php:1250` → `\App\Support\ApprovalService::submit(...)` | `ApprovalService.php:150` → `app(ModuleController::class)->applyApprovedPayload(...)` |

**الدليل ٣ — مفرداتُ المجال تسكن في متحكّم.** `RoleController.php:13-38` يحمل **كتالوجَ الرايات** كلَّه (‏`users, audit, approve, monitor, opsAnalytics, finAnalytics, secOps, secrets, copySec, dataroomShare, exp, mobile, oversight`) و`RISKY_FLAGS` — يقرؤه ٣ أصنافِ خدمة (`SecurityExposure`, `IdentityRisk`, `PermissionInspector`) و٤ قوالب. وهو **سجلٌّ مجاليّ** مكانُه `config/` بحسب مبدأ السكّتين في `docs/ARCHITECTURE.md`.

**الدليل ٤ — المتحكّماتُ تُستدعى خدماتٍ** (١٢ موضعاً):

```
app/Http/Controllers/Api/MobileWorkController.php:181  app(\App\Http\Controllers\Web\SearchController::class)->results($q, $per, $cap);
app/Http/Controllers/Web/LegalController.php:82,111    app(EsignController::class)->filterVisible(…)
app/Http/Controllers/Web/PasskeyController.php:208     app(AuthController::class)->finishLogin($u, $r, 'مفتاح مرور');
app/Http/Controllers/Web/ActivationController.php:149  app(AuthController::class)->finishLogin($user, $r, 'تفعيل');
app/Http/Controllers/Web/ConversationController.php:234 (new CommentController)->markReadPublic($messages);
```

**الجذر:** محرّكُ الوحدات نفسُه في `ModuleController` (١٩٦٧ سطراً · ١٨ دالّةً عامّة · ٥٤ دالّةً إجمالاً)، فسلسلةُ الوراثة هي طريقُ إعادةِ الاستعمالِ الوحيدة:
`MobileResourceController extends V1Controller extends ModuleController` (‏`Api/MobileResourceController.php:41` · `Api/V1Controller.php:12`).

**لماذا يضرّ:**
* **الجوهرُ لا يُختبَر إلا عبر HTTP.** `applyApprovedPayload` و`applyStatusTransition` و`performRestore` جوهرُ أعمالٍ لا يُبلَغ إلا بإنشاءِ متحكّمٍ من الحاوية.
* **الدورةُ تُخفي التغيير.** من عدّل `DmController::dmReachable` (بوّابةَ «من يجوز مراسلتُه») يظنّ أنّه لمس الويبَ وحدَه، وهو لمس الجوّالَ أيضاً عبر `DmService::reachable`.
* **الطبقةُ المقلوبةُ تجرّ الحالةَ معها:** `MobilePlatform.php:290` يبني `Request` اصطناعيّاً ليستدعي متحكّمَ API — فما يعتمده ذاك المتحكّمُ من الجلسةِ أو الترويسات يصير سلوكاً غيرَ معرَّف.

**الإصلاحُ المقترح (تدريجيٌّ · بلا كسرِ عقد):**
1. إخراجُ الكتالوجات أوّلاً — أرخصُ خطوةٍ وأعلاها عائداً: `RoleController::FLAGS/RISKY_FLAGS` → `config/hub_permissions.php` (الملفُّ قائمٌ بالفعل)، والمتحكّمُ يقرؤه.
2. إخراجُ جواهرِ المحرّك الأربعة (`applyApprovedPayload`, `applyStatusTransition`, `performRestore`, `performRestoreVersion`) إلى `App\Support\ModuleEngine` والمتحكّمُ يفوّض — فتنكسر دورةُ `ModuleController ↔ ApprovalService`.
3. `DmController::deriveDmCompany/ensureDmConversation/dmReachable` → `DmService` (وهي وجهتُها المُعلَنة في ترويسة الخدمة أصلاً).

**اختبارُ الانحدارِ المقترح:** حارسُ طبقاتٍ ثابتٌ في الحزمة:

```php
public function test_support_layer_does_not_depend_on_http_layer(): void
{
    $bad = [];
    foreach (\Illuminate\Support\Facades\File::allFiles(app_path('Support')) as $f) {
        $src = file_get_contents($f);
        if (preg_match('/(use|app\(\\\\?)\s*App\\\\Http\\\\Controllers/', $src)) $bad[] = $f->getRelativePathname();
    }
    $this->assertSame([], $bad,
        "أصنافُ الخدمةِ تعتمد على طبقةِ HTTP — أخرِج الجوهرَ إلى Support:\n" . implode("\n", $bad));
}
```

(يُبدأ بقائمةِ استثناءٍ مُعلَنةٍ تُقصَّر دفعةً بعد دفعة، فلا تسقط الحزمةُ يومَ إضافته.)

---

### A01-06 · محرّكا إقرارٍ متوازيان بسجلَّين وجدولين — وأحدُ السجلَّين مصفوفةٌ مكتوبةٌ في `helpers.php`

**الخطورة: P2** · **الثقة: مؤكَّد**

**المحرّكُ الأوّل** — السكّةُ العامّة `(module, record_id)`:

`config/hub_acks.php` (سجلٌّ مُعلَن) + `app/Support/Acks.php:17-31` + جدولُ `record_acks`:

```php
public static function registry(): array { return (array) config('hub_acks', []); }
public static function enabled(string $module): bool
{ return self::def($module) !== null && Schema::hasTable('record_acks'); }
```

يخدم: `meetings`, `assets`, `decisions`, `incidents`.

**المحرّكُ الثاني** — سجلٌّ **مكتوبٌ داخلَ دالّة**، وجدولٌ خاصّ:

`app/Support/helpers.php:6621-6629`:

```php
function hub_ack_modules(): array
{
    return [
        'policies' => ['col' => 'ack_required', 'ver' => 'ver', 'label' => 'سياسة'],
        'kb'       => ['col' => 'must_read',    'ver' => 'ver', 'label' => 'مقال معرفة'],
    ];
}
```

وكتّابُه `hub_ack_do` (`helpers.php:6765`) و`hub_ack_announce` (`:6678`) و`hub_ack_reset` (`:6801`) — كلُّهم على `\App\Models\PolicyAck` / جدولِ `policy_acks`، ومعهم كاتبٌ ثالثٌ في `EsignController.php:1355`.

**والعطلُ الذي أنتجه هذا الانقسامُ موثّقٌ في الكود نفسِه** — `app/Support/Inbox.php:189-192`:

```php
/**
 * كان يقرأ `kb_reads` — جدولٌ **لا يكتب فيه أحد**: `hub_ack_do('kb')` يكتب
 * في `policy_acks`. فالنتيجة: تقول لوحةُ السياسات «١٠٠٪ مُقَرّ» ويبقى البند …
 */
```

**ثلاثةُ قرّاءٍ لسؤالٍ واحد:** `hub_ack_state` (helpers) · `hub_ack_board` (‏`PolicyController.php:17`) · و`Inbox::policies`/`Inbox::mustRead` اللذان **يُعيدان بناءَ الاستعلامِ خاماً** (‏`Inbox.php:164-175`) بدل استدعاءِ أيٍّ منهما.

**لماذا يضرّ:** مطوّرٌ يضيف وحدةً تحتاج إقراراً لا يجد في `docs/ARCHITECTURE.md §8` («أين تضيف ماذا») ذكراً للإقرارات أصلاً، فيختار عشوائيّاً — ومن اختار السكّةَ الثانية ورث جدولاً خاصّاً وسجلاً مخفيّاً في `helpers.php` لا يحرسه `SettingsCenterTest` ولا أيُّ اختبارِ سجلّ. وتعريفُ «مُقَرّ» يبقى معرّفاً مرّتين، فينحرف أحدُهما كما انحرف `kb_reads`.

**الإصلاحُ المقترح:** ترحيلُ `policies`/`kb` إلى `config/hub_acks.php` + `record_acks` (هجرةٌ إضافيّةٌ تنسخ الصفوفَ بلا حذفِ `policy_acks`)، ويبقى `hub_ack_*` غلافاً رقيقاً فوق `Acks` لئلّا يُكسر قارئٌ قائم. وهو ما يسجّله `docs/TECH_DEBT.md:37` نفسُه («‏ترحيلُ `policy_acks` إلى `record_acks`»).

**اختبارُ الانحدارِ المقترح:**

```php
public function test_single_ack_registry(): void
{
    // لا سجلَّ إقرارٍ خارج config/hub_acks.php
    $this->assertSame([], array_diff(array_keys(hub_ack_modules()), array_keys(config('hub_acks'))),
        'سجلُّ إقرارٍ ثانٍ مكتوبٌ في helpers.php');

    // والحالةُ واحدةٌ من أيّ قارئ
    $p = Policy::factory()->create(['ack_required' => 1, 'ver' => '1']);
    $u = $this->internalUser();
    $this->assertSame(
        \App\Support\Acks::state('policies', $p)['done'],
        hub_ack_state('policies', $p->id)['done'],
        'قارئا الإقرارِ يختلفان على السجلّ نفسِه');
}
```

---

### A01-07 · عتبةُ «مشروعٌ في خطر» (٥٥) منسوخةٌ ستَّ مرّات — والتعليقُ يدّعي أنّها واحدة

**الخطورة: P2** · **الثقة: مؤكَّد**

**الدليل — ستُّ نسخٍ حرفيّة، ولا ثابتَ مشترك:**

```
app/Http/Controllers/Web/DeliveryController.php:27   private const RISK_THRESHOLD = 55;
app/Support/ExecutionStats.php:182                   if (($h['score'] ?? 100) < 55) {
app/Support/NextAction.php:103                       if (($h['score'] ?? 100) < 55) {
app/Support/helpers.php:5156                         if (($h['score'] ?? 100) < 55) $sick[] = …
app/Http/Controllers/Web/MorningController.php:245   if (($h['score'] ?? 100) < 55) {
app/Support/helpers.php:3537-3539 و3586-3588         ($score >= 80 ? 'ok'  : ($score >= 55 ? 'wn' : 'bad'))
                                                     ($score >= 80 ? 'سليم' : ($score >= 55 ? 'يحتاج انتباهاً' : 'متعثر'))
```

**والتعليقاتُ تُحيل إلى مالكٍ لا وجودَ له:**
`ExecutionStats.php:175-176` → «صحةُ hub_project_health دون ٥٥ (**عتبةُ ActionCenter نفسُها — لا عتبةَ ثانية**)»، و`DeliveryController.php:26` → «**عتبةُ ActionCenter نفسُها، لا ثانيةَ**». وفحصُ `grep -n "55" app/Support/ActionCenter.php app/Support/AttentionQueue.php` يعود **فارغاً**: `ActionCenter` لا يحمل العتبةَ أصلاً.

**وخريطةُ النبرة/اللقب مكرّرةٌ حرفيّاً** بين `hub_project_health` (‏`helpers.php:3536-3539`) و`hub_project_health_for` (‏`helpers.php:3585-3588`) — ٥٠ سطراً بينهما.

**لماذا يضرّ:** ستُّ شاشاتٍ تقول «في خطر» بستّةِ مصادرَ للعتبة. من رفعها إلى ٦٠ في `DeliveryController` (الموضعُ الوحيدُ الذي يُعلنها ثابتاً، فهو أوّلُ ما يُفتَح) يجد `/delivery/psa` يعدّ مشاريعَ أكثرَ بينما `workforce/overview` و`الموجزُ الصباحيّ` و`صفُّ الفعل` لا تتحرّك — وليس في الحزمةِ ما يكشف الانحراف.

**الإصلاحُ المقترح:** `hub_project_health` تُعيد `tone`/`label` أصلاً؛ فتُقرأ منها بدل إعادةِ المقارنة (`$h['tone'] === 'bad'` بدل `$h['score'] < 55`) — فتصير العتبةُ في موضعٍ واحدٍ بالبناء لا بالاتّفاق. وخريطةُ النبرة/اللقب تُستخرَج إلى دالّةٍ داخليّةٍ واحدةٍ يستدعيها القارئان.

**اختبارُ الانحدارِ المقترح:**

```php
public function test_risk_threshold_has_exactly_one_home(): void
{
    $hits = [];
    foreach (\Illuminate\Support\Facades\File::allFiles(app_path()) as $f) {
        foreach (file($f) as $i => $l) {
            if (preg_match("/\['score'\]\s*\?\?\s*100\)\s*<\s*\d+|score.*<\s*self::RISK_THRESHOLD/", $l)) {
                $hits[] = $f->getRelativePathname() . ':' . ($i + 1);
            }
        }
    }
    $this->assertSame([], $hits, "مقارنةُ عتبةِ خطرٍ خارجَ hub_project_health — اقرأ tone:\n" . implode("\n", $hits));
}
```

---

### A01-08 · قوالبُ Blade تحمل استعلاماتٍ ومنطقَ أعمال، وتنطيقُها يدويٌّ لا بنيويّ

**الخطورة: P2** · **الثقة: مؤكَّد**

**الدليل:** ‏١٧ قالباً في `resources/views/` يفتح القاعدةَ مباشرةً، منها ١٥ تحت `modules/custom/` (نقطةُ امتدادٍ معلَنة: `resources/views/modules/show.blade.php:93` → `@includeIf('modules.custom.' . $module)`). و`hub_scope` يُطبَّق فيها **باليد**، فبعضُها يطبّقه وبعضُها لا:

**مع تنطيق** (النمطُ الصحيح):

```php
resources/views/modules/custom/territories.blade.php:4
    $trKids = hub_scope(\App\Models\Territory::whereNull('deleted_at') …
resources/views/modules/custom/contracts.blade.php:12
    ? hub_scope(\App\Models\ContractObligation::query(), 'obligations')->where('contract_id', $row->id) …
```

**بلا تنطيق** (يعتمد ضمناً على أنّ حارسَ السجلِّ الأمِّ كافٍ):

```php
resources/views/modules/custom/contracts.blade.php:5
    $wsReqs = \App\Models\SignRequest::where('contract_id', $row->id)->orderByDesc('created_at')->limit(10)->get();
resources/views/modules/custom/stations.blade.php:23
    $stNames = \Illuminate\Support\Facades\DB::table('users') …
resources/views/modules/custom/entries.blade.php:5
    $jlLines = \App\Models\JournalLine::where('entry_id', $row->id) …
resources/views/modules/custom/policies.blade.php:3
    $pcAcks = \App\Models\PolicyAck::where('policy_id', $row->id)->where('ver', (string) $row->ver)->get();
```

ومعها منطقُ أعمالٍ محسوبٌ في القالب: `entries.blade.php:14` (توازنُ القيد) · `policies.blade.php:7` (نسبةُ الإقرار) · `projects.blade.php:60` (الميزانيّةُ الجارية بعد أوامر التغيير) · `services.blade.php:5` (الهامش — انظر `A01-03`).

**لماذا يضرّ:** هذا هو **التجريدُ الناقص** بعينه: نمطٌ يتكرّر في عشرين نقطةً ويُطبَّق باليد، فمن نسي `hub_scope` في قالبٍ واحدٍ فتح تسرّباً لا يُلتقَط — لأنّ القالبَ خارجُ مراجعةِ «القرّاء» التي تفحص `app/`. والحسابُ في القالب لا يُختبَر إلا بتصييرِ الصفحة، فيهرب من الحزمة (وقد هرب بالفعل في `A01-03`).

وفي `docs/ARCHITECTURE.md:8§` يُعلَن: «وحدةً جديدة | مدخلٌ في `config/hub.php` + هجرةٌ إضافية — **لا Controller ولا View**». الحقيقةُ: **٣٥ وحدةً من ٨٥** (٤١٪) لها قالبٌ مخصّص.

**الإصلاحُ المقترح:**
1. لا استعلامَ في Blade: كلُّ `modules/custom/<key>.blade.php` يُزوَّد ببياناته من `ModuleController::show` عبر خطّافٍ مُعلَن (مثلاً `App\Support\ModuleExtras::for($module, $row)` — صنفٌ واحدٌ بمفتاحٍ لكلِّ وحدة) فيبقى القالبُ عرضاً بحتاً.
2. وحتّى ذلك: حارسٌ يمنع `DB::table(` و`::where(` في `resources/views/` إلا داخلَ `hub_scope(...)`.

**اختبارُ الانحدارِ المقترح:**

```php
public function test_blade_templates_do_not_query_unscoped(): void
{
    $bad = [];
    foreach (\Illuminate\Support\Facades\File::allFiles(resource_path('views')) as $f) {
        foreach (file($f) as $i => $l) {
            if (preg_match('/(DB::table\(|\\\\App\\\\Models\\\\\w+::(where|query)\()/', $l)
                && ! str_contains($l, 'hub_scope(')) {
                $bad[] = $f->getRelativePathname() . ':' . ($i + 1) . '  ' . trim($l);
            }
        }
    }
    $this->assertSame([], $bad, "استعلامٌ غيرُ منطَّقٍ في قالب:\n" . implode("\n", $bad));
}
```

---

### A01-09 · `helpers.php` مجالٌ بلا حدود: ٧ ٣٢٥ سطراً و٢٠٨ دوالَّ تعبر كلَّ المجالات

**الخطورة: P2** · **الثقة: مؤكَّد**

**الدليل:** الملفُّ الأكبرُ في المستودع (`wc -l` = 7 325؛ أكبرُ من أيِّ صنفِ خدمةٍ بخمسةِ أضعاف)، و`grep -c '^if (! function_exists'` = **208**. وتوزيعُ دوالِّه يعبر كلَّ حدٍّ مجاليّ:

| المجال | دوالُّ `helpers.php` | الصنفُ المتخصّصُ القائم |
|---|---|---|
| الصلاحيّة والنطاق | `hub_can`, `hub_scope`, `hub_field_mode`, `hub_flag`, `hub_company_scope`, `hub_client_scope` | — (هذا موضعُها الصحيح) |
| ماليّة | `hub_mrr`, `hub_pipeline`, `hub_project_pl`, `hub_budget_actual`, `hub_hourly_rates`, `hub_fin_sum`, `hub_fin_outstanding`, `hub_service_costs`, `hub_supplier_scores` | `Pricing`, `Currency`, `JournalPostingService` |
| موارد بشريّة | `hub_workdays`, `hub_capacity` | `Workday`, `MonthlyAttendance`, `DailyWorkCompliance`, `Staff` |
| مؤشّرات وأهداف | `hub_kpis`, `hub_kpi_value`, `hub_kpi_metric`, `hub_kpi_explain`, `hub_okr_board`, `hub_okr_progress`, `hub_okr_pace` | `KpiCentre`, `OkrCentre` |
| قياس | `hub_metric_put/series/latest/growth/spark/bulk_*` | `Metrics`, `Series` |
| صحّة | `hub_health`, `hub_project_health`, `hub_project_health_for` | `Health` |
| إقرارات | `hub_ack_modules/targets/announce/state/do/reset/board` | `Acks` |
| وثائق | `hub_doc_spec/label/sensitive/expiry/belt`, `hub_dossier` | `DocumentPolicy`, `DigitalAssets` |

**لماذا يضرّ:**
* **الحدودُ غيرُ قابلةٍ للفرض.** لا يستطيع مراجعٌ أن يقول «هذا التغييرُ يمسّ مجالَ الماليّة» — كلُّ تغييرٍ يمسّ `helpers.php`.
* **التخالفُ يتولّد هنا:** ثلاثةٌ من أصلِ خمسةِ محرّكاتٍ متنافسةٍ وجدتُها لها طرفٌ في هذا الملفّ (`hub_workdays` مقابل `Workday`؛ `hub_ack_modules` مقابل `config/hub_acks.php`؛ `hub_service_costs` مقابل القالب).
* **الاستيرادُ العالميّ يُغري.** كلُّ دالّةٍ هنا متاحةٌ في كلِّ قالبٍ وكلِّ صنفٍ بلا `use` — وهو ما يجعل الحسابَ في Blade (`A01-08`) سهلاً كسهولةِ الحسابِ في الخدمة.

**الشاهدُ على أنّ الفريقَ يعرف هذا:** `app/Support/KpiCentre.php:16-18` يعتذر عنه صراحةً — «ولماذا صنفٌ ثالث بدل توسيع `hub_kpis`؟ لأنّ `helpers.php` **مِلكُ الطور الأول** (§٠٫٤)». أي أنّ الملفَّ صار منطقةً مجمَّدةً يُبنى حولَها لا فيها — فتتكاثر الطبقاتُ فوق مصدرٍ لا يُنظَّف.

**الإصلاحُ المقترح (بلا كسر):** تُبقى كلُّ دالّةٍ عامّةٍ كما هي (عقدٌ منشور) ويُنقَل **جسمُها** إلى صنفِ المجال المقابل، فيصير `helpers.php` طبقةَ توافقٍ رقيقةً:

```php
function hub_service_costs(bool $fresh = false): array { return \App\Support\ServiceCosts::all($fresh); }
```

يُبدأ بالمجموعات التي لها صنفٌ متخصّصٌ قائمٌ بالفعل (KPI, OKR, Metrics, Acks) — فالسطرُ يهبط من ٧ ٣٢٥ دون أن يتغيّر عقدٌ واحد.

**اختبارُ الانحدارِ المقترح:** حارسُ حجمٍ لا يرتفع (ratchet):

```php
public function test_helpers_file_does_not_grow(): void
{
    $lines = count(file(app_path('Support/helpers.php')));
    $this->assertLessThanOrEqual(7325, $lines,
        "helpers.php نما إلى {$lines} سطراً — أضِف إلى صنفِ المجال لا إلى الملفّ العامّ");
}
```

---

### A01-10 · `DashboardController::legacyData` تُحسَب في كلّ طلبٍ وتُهمَل في نصفها

**الخطورة: P2** · **الثقة: مؤكَّد**

**الدليل** — `app/Http/Controllers/Web/DashboardController.php:39-48`:

```php
if ($board) {
    return view('dashboard', [
        'board' => $board, 'boards' => $boards, 'hid' => $hid,
        'layout' => $this->layoutFor($board, $user),
    ] + $this->legacyData($user));          // ← تُحسَب دائماً
}
return view('dashboard', ['board' => null, …] + $this->legacyData($user));
```

و`legacyData` (‏`:69-85`) يستدعي **ثمانيةَ** حلّالاتِ ودجاتٍ + `pendingLine`:

```php
'cards' => WidgetRegistry::resolve('counts', $user) ?? [],
'kpis'  => WidgetRegistry::resolve('kpis',   $user) ?? [],
'expiry'=> WidgetRegistry::resolve('expiry', $user) ?? collect(),
'apps'  => …, 'taskSlices' => …, 'audits' => …, 'links' => …, 'due' => …,
'pending' => $this->pendingLine($user),
```

وكلُّ `resolve` ينفّذ حلّالَ الودجة فعلاً (`app/Support/WidgetRegistry.php:77-85`):

```php
return isset($def['resolver']) ? ($def['resolver'])($user) : null;
```

**والقالبُ لا يعرض شيئاً منها حين تكون هناك لوحةٌ مبنيّة** — `resources/views/dashboard.blade.php:23-36` مقابل `:44-59`:

```blade
@if ($board)
    … @foreach ($layout as $w) … @endforeach …        {{-- لا ذكرَ لـ cards/kpis/expiry/… --}}
@else
    @include('partials.widgets.kpis',   ['data' => $kpis])
    @include('partials.widgets.counts', ['data' => $cards])
    …
@endif
```

**لماذا يضرّ:** كلُّ فتحةٍ للوحةٍ مخصّصة تدفع ثمنَ حساب **ثمانِ ودجاتٍ لا تُعرَض** — والحقلُ `pending` وحدَه ثلاثةُ استعلاماتٍ (‏`:91-116`). وهو مسارٌ ميّتٌ بالمعنى الدقيق: `legacyData` كُتب حين كان القالبُ واحداً، وبقي بعد أن انقسم. ومعمارياً: مسارا بياناتٍ لشاشةٍ واحدة (`layout` و`legacyData`)، أحدُهما لا يُقرأ في نصف الحالات.

**الإصلاحُ المقترح:** نقلُ `+ $this->legacyData($user)` إلى فرعِ `$board === null` وحدَه. سطرٌ واحد، بلا تغييرِ سلوكٍ مرئيّ.

**اختبارُ الانحدارِ المقترح:**

```php
public function test_custom_board_does_not_resolve_default_widgets(): void
{
    $u = $this->internalUser();
    $b = Dashboard::factory()->for($u, 'owner')->create(['is_default' => true]);
    $b->widgets()->create(['widget_key' => 'links', 'w' => 6, 'h' => 1]);

    \Illuminate\Support\Facades\DB::enableQueryLog();
    $this->actingAs($u)->get(route('dashboard', ['d' => $b->id]))->assertOk();
    $n = count(\Illuminate\Support\Facades\DB::getQueryLog());

    \Illuminate\Support\Facades\DB::flushQueryLog();
    $this->actingAs($u)->get(route('dashboard', ['d' => '']))->assertOk();   // الافتراضيّة
    $this->assertLessThan(count(\Illuminate\Support\Facades\DB::getQueryLog()), $n,
        'اللوحةُ المبنيّةُ تحسب ودجاتِ الافتراضيّةِ ولا تعرضها');
}
```

---

### A01-11 · `OpStatus` — عمودٌ معماريٌّ مُعلَنٌ في الوثيقة بلا قارئٍ إنتاجيٍّ واحد

**الخطورة: P3** · **الثقة: مؤكَّد**

**الدليل:** `docs/ARCHITECTURE.md` (جدولُ «مستوى التحكّم المؤسسي — الطور الأول») يعلنه عموداً مشتركاً:

> «خرائطُ الشدّة والحالة | `Severity::normalize/label/tone/rank` · **`OpStatus::fromHealth`** · `IssueState::MAP` …»

ومسحُ المستودع كلِّه:

```
$ grep -rn "OpStatus" app/ resources/ routes/ config/
app/Support/OpStatus.php:13:final class OpStatus       ← تعريفُه وحدَه
$ grep -rln "OpStatus" tests/
tests/Feature/SeverityMapTest.php                        ← القارئُ الوحيد
```

وللمقارنة: `Severity::` يقرؤه ١٤ ملفاً، و`IssueState::` ٤ ملفات، و`Redactor::` ٢٠ ملفاً — فالعمودان الآخران حيّان، وهذا وحدَه ميّت.

**لماذا يضرّ:** الوثيقةُ تَعِد قارئَها بعمودٍ «تُفوَّض إليه» الشاشاتُ، فيبني عليه مطوّرٌ جديدٌ ظانّاً أنّه سكّةٌ قائمة. والاختبارُ الأخضرُ (`SeverityMapTest`) يمنح الصنفَ مظهرَ الحياة بينما لا يمسّه الإنتاج — فهو **يختبر ما لا يُستعمَل**، وهذا أضرُّ من عدمِ اختبارِه لأنّه يستهلك ثقةً بلا تغطية.

**الإصلاحُ المقترح:** أحدُ أمرين صراحةً، لا ثالث: (أ) تمريرُ حالاتِ التكاملات والمراقبةِ الخارجيّة (`Integrations::judge` تعيد `ok/degraded/down/unknown/off`) عبر `OpStatus::fromHealth` فيصير له قارئٌ حقيقيّ؛ أو (ب) شطبُه من `docs/ARCHITECTURE.md` وحذفُ الصنفِ مع اختبارِه.

**اختبارُ الانحدارِ المقترح:** حارسٌ يمنع تكرارَ الحالة — لكلِّ صنفٍ في `app/Support` يُعلَن في `docs/ARCHITECTURE.md` عموداً مشتركاً، يجب أن يوجد له قارئٌ واحدٌ على الأقلّ خارج `tests/`.

---

### A01-12 · أرقامُ `docs/ARCHITECTURE.md` متخلّفةٌ عن الكود

**الخطورة: P3** · **الثقة: مؤكَّد**

| الادّعاء | الموضع | المقيس | الأمر |
|---|---|---|---|
| «سجلّ الوحدات `config/hub.php` (**٨٢ وحدة**)» | `docs/ARCHITECTURE.md:12` | **85** | `count(config('hub.modules'))` |
| «جداولُ الوحدات (**٨٢**)» | `:38` | **85** | كذلك |
| «الهجرات: إضافيةٌ فقط (**١٧٢ ملفاً**)» | `:40` | **250** | `ls database/migrations \| wc -l` |
| «ملفّاتُ اختبارِ الميزات **524**» | `docs/archive/final-audit/01-architecture.md` | **624** | `ls tests/Feature/*.php \| wc -l` |

**لماذا يضرّ:** الوثيقةُ هي أوّلُ ما يُقرأ («اقرأها أولاً» في `CLAUDE.md`)، ورقمٌ متخلّفٌ فيها يُعلّم قارئَها أنّ أرقامَها تقريبيّةٌ — فيتوقّف عن التحقّق من بقيّتها، وفيها ادّعاءاتٌ نوعيّةٌ أخطرُ (مثل `OpStatus` في `A01-11`، ومثل «لا Controller ولا View» في `A01-08`).

**الإصلاحُ المقترح:** تُولَّد أرقامُ الحجمِ لا تُكتَب — أمرُ `hub:arch-stats` يطبع الجدولَ، واختبارٌ يقارنه بما في الوثيقة (على نمط بوّابةِ `docs/openapi.json` المذكورةِ في `CLAUDE.md`).

**اختبارُ الانحدارِ المقترح:**

```php
public function test_architecture_doc_counts_match_reality(): void
{
    $md = file_get_contents(base_path('docs/ARCHITECTURE.md'));
    $ar = fn (int $n) => strtr((string) $n, '0123456789', '٠١٢٣٤٥٦٧٨٩');
    $this->assertStringContainsString($ar(count(config('hub.modules'))) . ' وحدة', $md);
    $this->assertStringContainsString($ar(count(glob(database_path('migrations/*.php')))) . ' ملفاً', $md);
}
```

---

### A01-13 · `KpiCentre::NEAR_BAND` — ثابتٌ ميّتٌ يُكرّر عتبةً حرفيّةً في `helpers.php`

**الخطورة: P3** · **الثقة: مؤكَّد**

`app/Support/KpiCentre.php:32-36`:

```php
/**
 * حزامُ «تحذير» حول الهدف — **نفسُ عتبة `hub_kpis`** حرفياً (٨٠٪ صعوداً،
 * ١٢٠٪ نزولاً)، فلا عتبتان لشيءٍ واحد. النبرةُ تُقرأ من هناك لا تُحسب هنا.
 */
public const NEAR_BAND = 0.20;
```

والعتبةُ الحقيقيّةُ رقمان حرفيّان في `app/Support/helpers.php:5794`:

```php
$near = ($k->good ?? 'up') === 'up' ? $val >= $k->target * 0.8 : $val <= $k->target * 1.2;
```

ومسحُ `grep -rn "NEAR_BAND" app/ resources/ tests/ docs/` يعود بسطرِ التعريفِ **وحدَه** — فالثابتُ لا يُقرأ قطّ، و`KpiCentre::health` (‏`:319-331`) يفوّض النبرةَ فعلاً كما يَعِد التعليق.

**لماذا يضرّ:** الثابتُ يقول «فلا عتبتان» وهو **عتبةٌ ثانيةٌ بعينها** — نسخةٌ من رقمٍ حرفيٍّ في ملفٍّ آخر، لا مصدرٌ له. فمن عدّل `0.8/1.2` في `helpers.php` يترك هنا `0.20` تشهد زوراً على قيمةٍ لم تعد قائمة.

**الإصلاحُ المقترح:** إمّا أن يُقرأ فعلاً — `helpers.php:5794` يستعمل `KpiCentre::NEAR_BAND` بدل `0.8`/`1.2` — أو يُحذف. الأوّلُ أفضل: يصير للحزام مالكٌ واحد.

**اختبارُ الانحدارِ المقترح:** توليدُ مؤشّرٍ هدفُه ١٠٠ وقيمتُه ٧٩٫٩ ثمّ ٨٠٫١، والتأكيدُ أنّ النبرةَ تنقلب عند `target * (1 - KpiCentre::NEAR_BAND)` بالضبط.

---

### A01-14 · إسقاطُ «أوّلِ ردٍّ عامّ» منسوخٌ في ستّةِ قرّاءٍ بلا تجريد

**الخطورة: P3** · **الثقة: مؤكَّد**

ستُّ نسخٍ من الاستعلام نفسِه:

```
app/Support/ExecutionStats.php:163-168        app/Support/ExecutionStats.php:1150-1155
app/Support/AlertEngine.php:369-373           app/Http/Controllers/Web/QualityController.php:360-364
app/Http/Controllers/Web/SupportController.php:34-38 و59-63
app/Http/Controllers/Web/MorningController.php:92-96
```

نصُّ الخمسةِ الأولى حرفيّاً:

```php
DB::table('comments')->where('module', 'tickets')->whereIn('record_id', $ids)
    ->whereNull('deleted_at')
    ->where(fn ($q) => $q->where('internal', false)->orWhereNull('internal'))
    ->select('record_id', DB::raw('MIN(created_at) as at'))->groupBy('record_id')
```

**تحقّقٌ نافٍ:** نسخةُ `AlertEngine` تُسقط `whereNull('deleted_at')` — وفحصتُ `app/Models/Comment.php:12` فوجدتُه `use HasUuid, SoftDeletes;` أي أنّ Eloquent يضيف الشرطَ آليّاً. **فلا عطلَ هنا اليوم.**

**لماذا يضرّ رغم ذلك:** تعريفُ «أوّلِ ردٍّ يقاس به زمنُ الاستجابة» — أهو التعليقُ غيرُ الداخليّ؟ أيدخل الردُّ الآليّ؟ أيحتسب المحذوفُ؟ — قرارُ أعمالٍ موزّعٌ على ستّةِ ملفّاتٍ بلا مالك، بينما `hub_sla` (مالكُ بقيّةِ حسابِ SLA) لا يعرفه. وتغييرُ واحدٍ (كإدخالِ التعليقِ الداخليِّ في القياس) يُنتج ستَّ إجاباتٍ مختلفة — وهي بالضبط الحالةُ التي وقعت في `A01-01` و`A01-02`.

**الإصلاحُ المقترح:** `hub_sla_first_replies(array $ticketIds): Collection` بجوار `hub_sla` — القرّاءُ الستّة يستدعونها، والتعريفُ يصير في موضعٍ واحدٍ يُوثَّق ويُختبَر.

**اختبارُ الانحدارِ المقترح:** تذكرةٌ بردٍّ داخليٍّ ثمّ عامٍّ ثمّ محذوف؛ يُؤكَّد أنّ القرّاءَ الستّةَ (ExecutionStats · AlertEngine · QualityController · SupportController ×2 · MorningController) يقيسون الزمنَ نفسَه.

---

### A01-15 · ثلاثةُ معانٍ لكلمة «صحّة» في فضاءِ أسماءٍ واحد

**الخطورة: P3** · **الثقة: ظنّيّ** (اسميٌّ لا سلوكيّ — لم أجد قارئاً خلط بينها)

* `App\Support\Health::check()` — صحّةُ **النظام** (قاعدة/خبيئة/تخزين/مجدولات).
* `hub_health()` (‏`helpers.php:2153`) — صحّةُ **المنشأة** (أبعادٌ ماليّةٌ وتشغيليّةٌ من بيانات الأعمال).
* `hub_project_health()` (‏`helpers.php:3359`) — صحّةُ **المشروع** (ستّةُ عوامل).

وفي `app/Support/` سبعُ دوالَّ عامّةٍ اسمُها `health()` (‏`MdmService`, `KpiCentre`, `MobilePlatform`, `Engagements`, `AttentionQueue`, `Mdm\*Provider`) تعني خمسةَ أشياءَ مختلفة.

**لماذا يضرّ (احتمالاً):** التطابقُ الاسميُّ يدعو إلى الخلطِ في القراءةِ والمراجعة، ويجعل البحثَ النصّيَّ عن «من يقرأ الصحّة؟» عديمَ الجدوى. لم أرصد قارئاً استدعى الخطأَ منها — ولذلك **ظنّيّ**.

**الإصلاحُ المقترح:** تسميةٌ تفصل المجالَ لا الصنف: `hub_org_health()` و`hub_project_health()` و`Health::system()` — مع إبقاء الأسماء القديمة أغلفةً (الإضافةُ لا الكسر).

---

## ٤ · ما فحصتُه ولم أجد فيه عيباً

هذه الفحوصُ نُفِّذت بالكامل وخرجت نظيفة — وقيمتُها أنّها تحصر مساحةَ الشكّ:

| ما فُحص | كيف | النتيجة |
|---|---|---|
| **بصمةُ خبيئةِ الشاشة** | قراءةُ `hub_scope_key` (`helpers.php:4160-4174`) ومطابقتُها بكلِّ `Cache::remember` في `helpers.php` | **نظيف.** المفتاحُ يحمل الدورَ والمستخدمَ والشركةَ النشطةَ ومساحةَ العميلِ والعدسةَ **وختمَ `roles`/`users`** — فسحبُ صلاحيّةٍ يُبطل الخبيئةَ فوراً. والقرّاءُ ذوو المفتاحِ العامّ (`svc:costs`, `sup:scores`) فحصتُ محتواهم: غيرُ منطَّقٍ أصلاً، وأبوابُهم محروسةٌ بـ`hub_org_analytics_guard()` الذي يردّ الحسابَ المحدودَ النطاق (`helpers.php:2642`) |
| **محرّكُ الحضور** | قراءةُ `Workday::teamCalc` و`DailyWorkCompliance::resolveMany/rollCall` و`MonthlyAttendance::sheet` | **نظيف** (عدا محلّلِ العطلة · `A01-01`). `DailyWorkCompliance` هو المسنَدُ الواحد؛ `Workday` و`MonthlyAttendance` يستهلكانه ولا يُعيدان الحساب. و`teamCalc:468` ينصّ على أنّ نداءَ اليومِ يُقرأ **من اللقطةِ نفسِها** لا حيّاً — إغلاقٌ متعمّدٌ لنافذةِ تناقضٍ كانت قائمة |
| **محرّكُ SLA** | تتبّعُ كلِّ استدعاءٍ لـ`hub_sla` | **نظيف.** ستّةُ قرّاءٍ وحاسبةٌ واحدة — لا حسابَ ثانياً (الإسقاطُ المكرَّرُ في `A01-14` مجاورٌ لا بديل) |
| **محرّكُ الإشعارات** | قراءةُ `app/Models/HubNotification.php` كاملاً + جردُ كلِّ `HubNotification::create` | **نظيف بتصميمٍ ذكيّ.** سبعةُ كُتّابٍ يتجاوزون `hub_notify`، لكنّ القصَّ والكتمَ وتفريعَ الدفعِ في `booted()` — فالخطّافُ يغطّيهم جميعاً بلا لمسِ أيٍّ منهم. والملفُّ يقول ذلك صراحةً (`:66-72`) |
| **إعادةُ استعمالِ المحرّك في API والجوّال** | `Api/V1Controller.php:12` · `Api/MobileResourceController.php:41` | **نظيف وظيفيّاً.** الجوّالُ يرث المحرّكَ فيرث `hub_can`/`hub_scope`/`fill`/`assertVersion`/Idempotency — لا نسخةَ ثانية. (موضعُ المحرّكِ نفسُه هو الملاحظةُ `A01-05` لا إعادةُ استعماله) |
| **تكرارُ المسارات** | `route:list --json` ثمّ تجميعٌ على `method+uri` | **صفرُ تكرار** من ٦٣٢ مساراً |
| **المتحكّماتُ الميّتة** | مطابقةُ كلِّ دالّةٍ عامّةٍ في `app/Http/Controllers` بأفعالِ المسارات | **٩ فقط** بلا مسار — وفحصتُها واحدةً واحدة: كلُّها جواهرُ مشتركةٌ يستدعيها كودٌ آخر (`lanes`, `results`, `finishLogin`, `filterVisible`, `markReadPublic`, `performRestore`, `performRestoreVersion`, `applyStatusTransition`, `applyApprovedPayload`). **لا مسارَ توافقٍ ميّت** |
| **أصنافُ الخدمةِ الميّتة** | فحصُ كلِّ صنفٍ في `app/Support` عن مرجعٍ خارجَ نفسِه | **واحدٌ فقط** (`OpStatus` · `A01-11`) من ١٣٦ |
| **المُطهِّرُ الواحد** | `grep -rln "Redactor::" app/` | **نظيف.** ٢٠ ملفاً يفوّضون إليه — يفوق ما تعِد به الوثيقةُ (٧) |
| **كاتبُ الإعداداتِ الواحد** | جردُ كلِّ `Setting::updateOrCreate` و`Settings::put` | **مقبولٌ مع تحفّظ.** خمسةُ كُتّابٍ مباشرين، **كلُّهم يُبطلون الخبيئةَ** (فحصتُ كلَّ موضع: `Health.php:244`, `Health.php:449-456` بترقيعٍ موضعيٍّ للمفتاحين مُعلَّلٍ عمداً، `Integrations.php:78`, `CustomFieldController.php:77,116`) — فلا قيمةٌ قديمةٌ تُقرأ. لكنّهم خارجَ `setting_changes` والأثرِ والتحقّق؛ ذاك مقبولٌ للنبضاتِ ومشكوكٌ فيه لـ`custom.fields` (تغييرٌ بنيويٌّ في النماذج بلا تاريخٍ ولا مَن غيّره) |
| **بوّابةُ لوحاتِ المنشأة** | `hub_org_analytics_guard` / `hub_can_project_finance` وقارئوهما | **نظيف.** الحسابُ المنطَّقُ بمشاريعَ أو شركاتٍ يُردّ قبل بلوغِ الأرقامِ غيرِ المنطَّقة |
| **تعريفُ «مغلق» في `hub_closed_states`** | قراءةُ القائمةِ كاملةً (`helpers.php:3674-3704`) | **نظيفٌ ومُعلَّلٌ ببراعة** — يعالج صورَ التذكيرِ والتأنيثِ والمفرداتِ الخاصّةِ بكلِّ وحدة. المشكلةُ ليست فيه بل في **مَن لا يستدعيه** (`A01-02`) |
| **دورُ `OkrCentre`** | قراءةُ ترويسته وتتبّعُ `objectives.progress` | **نظيف.** الازدواجُ السابقُ (لوحةٌ تحسب وأخرى تقرأ العمودَ المخزَّن) **أُغلق فعلاً**؛ العمودُ بقي أثرَ تثبيتٍ لا مصدرَ عرض، والقالبُ `okrs.blade.php:18` يُظهر التعارضَ للقارئ بدل أن يخفيه |

---

## ٥ · الحكمُ على ادّعاءِ «0 محرّكٍ منافسٍ غيرِ مُبرَّر»

| المجال | ادّعاءُ `final-audit/01-architecture.md` | الواقعُ المُثبَت |
|---|---|---|
| Settings | «1 · لا ازدواج» | ‏١ كاتبٌ كامل + ٥ كُتّابٍ مباشرين (‏٤ مبرَّرون · `custom.fields` لا) |
| الصلاحيّة/التوثيق | «1 · لا ازدواج» | **٢** — `hub_can` + نموذجُ `PermissionInspector` المخالفُ في الاتّجاهين (`A01-04`) |
| Collaboration | «1 · لا ازدواج» | ‏١ — لكنّ جوهرَه موزّعٌ بين خدمةٍ ومتحكّمٍ في دورة (`A01-05`) |
| Notifications | «1 (`HubNotification`) · لا ازدواج» | **صحيح** |
| Audit / Search / Relationship Graph | «1 · لا ازدواج» | **صحيح** (فحصتُ `Redactor` و`InformationArchitecture`) |
| — (غيرُ مذكورٍ أصلاً) | — | **عطلةُ الأسبوع: ٣ محلّلات، أحدُها مخالف** (`A01-01`) |
| — (مذكورٌ P3 بوصفه «تكراراً») | «~٢٥ مصفوفةَ حالاتٍ حرفية» | **٣٦ مرشِّحاً NULL-unsafe** يُسقطون صفوفاً من مساراتِ تنبيهٍ ومحاسبة (`A01-02`) |
| — (غيرُ مذكور) | — | **هامشُ الخدمة: محرّكٌ + حسابٌ في القالب بوحدةٍ مخالفة** (`A01-03`) |
| — (مذكورٌ P3) | «نظامان للإقرارات» | **قائمٌ بسجلَّين وثلاثةِ قرّاء** (`A01-06`) |

**الخلاصة:** العبارةُ «**محرّكاتٌ منافِسةٌ غيرُ مُبرَّرة = 0**» غيرُ صحيحة. الأدقُّ: *«خمسةُ مجالاتٍ لها مصدرا حقيقةٍ متنافسان، أربعةٌ منها تُنتج رقمين مختلفين لسؤالٍ واحد، واثنان منها (‏`A01-01`, `A01-03`) لم يُرصَدا في أيِّ تقريرٍ سابق»*. والسببُ البنيويُّ لغيابها عن المسحِ السابق أنّ المسحَ بحث عن **أصنافٍ** متنافسة، والمنافسُ هنا كان **سطراً في قالبٍ** و**تعبيراً حرفيّاً في دالّة** — وهما خارج حدودِ أيِّ صنف.
