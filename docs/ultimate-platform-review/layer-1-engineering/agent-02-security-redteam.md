# الوكيل ٠٢ — الفريقُ الأحمرُ الأمنيّ · طبقة ١ · الجولة أ

**المستودع:** `/home/user/lynomia-hub` · **الرأس:** `1d90626` (v2.540.1)
**المنهج:** هجومٌ حيٌّ لا قراءةُ كود. كلُّ ثغرةٍ أدناه أُثبتت **بطلبٍ فعليٍّ نجح وهو يجب أن يفشل**،
وباختباراتِ استكشافٍ مؤقّتةٍ خارجَ `tests/` (حُذفت بعد الانتهاء). ما لم يُثبَت وُسم `ظنّيّ`.
**لم يُعدَّل أيُّ ملفٍّ في المستودع** سوى هذا التقرير.

---

## الخلاصة

| # | الخطورة | العنوان | الملف:سطر | الحال |
|---|---------|---------|-----------|-------|
| A02-01 | **P1** | إداريُّ الحسابات يرفع نفسَه إلى أيِّ دورٍ غير مالك (`PUT /admin/users/{self}`) | `app/Http/Controllers/Web/UserController.php:154`, `:36` | **مُثبَتة** |
| A02-02 | **P1** | منتقي الشركات في شاشة المستخدمين غيرُ منطَّق — كشفُ سجلِّ المستأجرين | `app/Http/Controllers/Web/UserController.php:72` | **مُثبَتة** |
| A02-03 | **P1** | منتقي المشاريع في سجل التدقيق غيرُ منطَّق بعزل الشركات | `app/Http/Controllers/Web/AuditController.php:84` | **مُثبَتة** |
| A02-04 | P2 | `GET /api/v1/identity/resolve/{q}` يردّ ٥٠٠ على خطِّ اتصالات (مفتاحٌ غائبٌ في خريطة) | `app/Http/Controllers/Api/V1Controller.php:607` | **مُثبَتة** |

لا `P0`: لم أجد تجاوزَ مصادقةٍ ولا تسريبَ بياناتٍ لمجهول. تفاصيلُ ما صمد في الفصل الأخير — وهو نصفُ القيمة.

---

## A02-01 · إداريُّ الحسابات يرفع نفسَه إلى أيِّ دورٍ غير مالك

**الخطورة:** P1 (تجاوزُ تخويلٍ — تصعيدُ امتيازٍ أفقيٌّ ورأسيّ)
**الحال:** مُثبَتة

### الوصف

`UserController::update()` يحرس شيئين بعناية: (١) `guardEscalation` يمنع منحَ **الملكية** ومسَّ حسابِ
مالك، و(٢) على **النفس** يُسقط `companies` صراحةً بتعليقٍ لا لبسَ فيه:

```php
// app/Http/Controllers/Web/UserController.php:176-181
$isSelf = $user->id === auth()->id();
if ($isSelf && ! hub_is_owner()) {
    unset($data['companies']);       // «توسيعُ نطاقِ المرء نفسه قرارُ مالكٍ لا قرارُ إداريّ»
}
```

لكنّ `role_id` **لا يُسقَط على النفس**، ولا يُفحَص بسقفٍ يعلو دورَ الفاعل. و`assignableRoles()`
تعرض **كلَّ** دورٍ غيرِ مالك. فحاملُ رايةِ `users` وحدَها — بمصفوفةِ صلاحياتٍ **صفرية** — يفتح ملفَّه
ويختار أقوى دورٍ في النظام فيصير هو.

والتناقضُ داخليٌّ لا استنتاجيّ: `twofaOff` و`unlock` في المتحكّم نفسِه تفرضان سقفاً
(`Staff::mayTouch` — «هذا الحساب ذو امتياز، فكُّ قفله يتطلب صلاحيةً تعلوه») **وتصعيدَ هوية**
(`hub_require_stepup`)، بينما تبديلُ الدورِ — وهو أخطرُ منهما — بلا سقفٍ ولا تصعيد.

### الدليل — الطلبُ الفعليّ والاستجابة

فاعلٌ: `onlyusers@test.local`، دورُه `flags = ['users' => 1]` و`matrix` كلُّها أصفار.

```
[BEFORE] /m/hr=403  /admin/audit=403  /admin/security/secrets=403

PUT /admin/users/{self}
    role_id=<id of "مدير عمليات">   (is_owner=false, flags: secrets/monitor/audit/exp/users, matrix: كلُّ الوحدات)
    name=… email=… status=نشط password=(فارغة) companies=[]
  -> 302  Location: http://127.0.0.1:8000/admin/users        ← لا ٤٠٣ ولا ٤٢٨ (تصعيد)

[AFTER ] role=مدير عمليات  /m/hr=200  /admin/audit=200  /admin/security/secrets=403
```

`403 → 200` على `/m/hr` يعني: ملفّاتُ الموظفين كاملةً (الرواتب وIBAN والهويّات) لمن كان قبل
الطلبِ بثانيةٍ لا يملك عرضَ وحدةٍ واحدة. ومركزُ الأمان بقي ٤٠٣ لأنه **للمالك حصراً**
(`SecurityController::gate` = `hub_is_owner`) — وهو الحاجزُ الوحيدُ الذي حال دون بلوغ الخزنة.

ومسارٌ ثانٍ مُثبَتٌ للنتيجة نفسِها بلا لمسِ حسابِ النفس: سكُّ حسابٍ جديدٍ بالدور القويّ
وبكلمةِ مرورٍ يختارها الفاعل، ثم الدخولُ به:

```
POST /admin/users   role_id=<مدير عمليات>  password=Puppet!2026xy
  -> 302   created=true
POST /login  email=puppet@test.local  password=Puppet!2026xy  -> 302 /
[puppet] /m/hr=200  /admin/audit=200
```

### خطواتُ الإعادة

1. أنشئ دوراً `A`: `flags=['users'=>1]`، `matrix` كلُّها أصفار. وأنشئ مستخدماً عليه.
2. تأكّد أن دوراً قوياً غيرَ مالكٍ موجودٌ في النظام (أيُّ تنصيبٍ حقيقيٍّ فيه واحدٌ على الأقل).
3. ادخل بحساب `A`، افتح `/admin/users/{self}/edit`، اختر الدورَ القويَّ واحفظ.
4. افتح `/m/hr` — كان ٤٠٣ وصار ٢٠٠.

### السببُ الجذريّ

- `app/Http/Controllers/Web/UserController.php:154` (`update`) — `role_id` يُقبل على النفس بلا قيد.
- `app/Http/Controllers/Web/UserController.php:36` (`guardEscalation`) — تحرس الملكيةَ وحدَها؛ لا سقفَ
  «لا تمنح ما لا تملك».
- `app/Http/Controllers/Web/UserController.php:78` (`assignableRoles`) — تعرض كلَّ دورٍ غيرِ مالك
  بلا مقارنةِ سلطةٍ بدور الفاعل.

### الإصلاح المقترح

1. **على النفس:** أسقط `role_id` كما تُسقَط `companies` حرفاً بحرف:
   ```php
   if ($isSelf && ! hub_is_owner()) { unset($data['companies'], $data['role_id']); }
   ```
   (ولا يُكسر شيء: الإداريُّ يُعدّل اسمَه وهاتفَه كما كان، ورفعُ الدورِ يبقى للمالك.)
2. **على الغير:** سقفٌ صريحٌ «لا تمنح ما لا تملك» في `guardEscalation`: مصفوفةُ الدور المُسنَد
   وراياتُه ⊆ مصفوفةِ الفاعل ورايته (المالكُ مستثنًى). هذا يغلق سكَّ الدمية أيضاً.
3. **تصعيدُ هوية** على تبديل الدور كما على `twofaOff`/`unlock`:
   `if ($resp = hub_require_stepup(route('users.index', absolute: false))) return $resp;`
4. طبِّق `Staff::mayTouch($user)` على `update` كما هي مطبَّقةٌ على `twofaOff`/`unlock`.

### اختبارُ الانحدار

`tests/Feature/UserAdminCannotPromoteSelfTest.php` — يفشل أوّلاً ثم يُصلَح:

```php
$adm = /* flags: users فقط، matrix أصفار */;
$this->actingAs($adm);
$this->assertSame(403, $this->get('/m/hr')->getStatusCode());
$this->put('/admin/users/'.$adm->id, [... 'role_id' => $strongRole->id ...]);
$this->assertSame($admRole->id, $adm->fresh()->role_id, 'الإداريُّ لا يبدّل دورَ نفسِه');
$this->assertSame(403, $this->get('/m/hr')->getStatusCode());
// والوجهُ الثاني: لا يسكّ دميةً أقوى منه
$this->post('/admin/users', [... 'role_id' => $strongRole->id ...]);
$this->assertFalse(User::where('email','puppet@test.local')->exists());
```

**الثقة:** عالية (طلبٌ فعليٌّ، انقلابُ ٤٠٣ ← ٢٠٠ موثَّق).

---

## A02-02 · منتقي الشركات في شاشة المستخدمين غيرُ منطَّق — كشفُ سجلِّ المستأجرين

**الخطورة:** P1 (تجاوزُ تخويل — تسرّبٌ عابرُ العزل)
**الحال:** مُثبَتة

### الوصف

`UserController::companies()` تبني منسدلةَ «عزل الشركات» من **كلّ** الجدول بلا نطاق:

```php
// app/Http/Controllers/Web/UserController.php:72-75
protected function companies()
{
    return \App\Models\Company::whereNull('deleted_at')->orderBy('name_ar')->pluck('name_ar', 'id');
}
```

بينما وحدةُ الشركات نفسُها تحجب الشركةَ الأخرى حجباً تامّاً (`hub_scope` ⇒ ٤٠٤)، ومنتقياتُ
النماذج الأخرى كلُّها منطَّقةٌ بـ`hub_ref_options_scoped`. فالشاشةُ الإداريةُ هي البابُ الوحيدُ
الذي يُفشي **اسمَ ومعرِّفَ** كلِّ شركةٍ في المنصّة لمن عُزل عن واحدةٍ منها.

القيمةُ المتسرّبة ليست هامشية: في منصّةٍ متعدّدةِ المستأجرين، سجلُّ أسماءِ العملاءِ المؤسّسيّين
هو أوّلُ ما يُستطلَع (تحديدُ المنافس، والاستهدافُ، وبناءُ خريطةِ المعرّفات للهجوم التالي).

### الدليل — الطلبُ الفعليّ والاستجابة

فاعلٌ: `admA@test.local` معزولٌ على «شركة أ» (`companies = [A]`)، يحمل رايةَ `users`.
الشركةُ الأخرى اسمُها `الخصم ZQXJ9182736450`.

```
# خطُّ الأساس — الوحدةُ تحجب كما يجب
GET /m/companies/{B}   -> 404
GET /m/companies       -> 200  (لا أثرَ للاسم في الجسم)

# والبابُ الإداريُّ يُفشي
GET /admin/users/create        -> 200  ***LEAK***
    ...<option value="{B-uuid}">الخصم ZQXJ9182736450</option>...
GET /admin/users/{id}/edit     -> 200  ***LEAK***
    ...<option value="{B-uuid}">الخصم ZQXJ9182736450</option>...
```

### خطواتُ الإعادة

1. أنشئ شركتين `A` و`B`.
2. أنشئ مستخدماً `companies=[A]` بدورٍ غيرِ مالك يحمل `flags=['users'=>1]`.
3. `GET /m/companies/{B}` ⇒ ٤٠٤ (خطُّ الأساس سليم).
4. `GET /admin/users/create` ⇒ ٢٠٠ واسمُ `B` ومعرّفُه في الـ`<option>`.

### السببُ الجذريّ

`app/Http/Controllers/Web/UserController.php:72` — استعلامٌ خامٌّ بلا `hub_company_ids()`.
(والكتابةُ سليمةٌ: `store:119` و`update:203` يتقاطعان مع نطاق الفاعل — فالثغرةُ **قراءةٌ** لا كتابة.)

### الإصلاح المقترح

```php
protected function companies()
{
    $ids = hub_company_ids();                     // null = بلا قيد (مالك)
    return \App\Models\Company::whereNull('deleted_at')
        ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
        ->orderBy('name_ar')->orderBy('id')->pluck('name_ar', 'id');
}
```

هذا هو **النمطُ المطبَّقُ سلفاً** في `AuditController:80-83` للشركات — فالإصلاحُ توحيدٌ لا اختراع.
وحين يُعدَّل حسابٌ يحمل شركةً خارجَ نطاق المحرِّر تبقى مخزَّنةً (التقاطعُ في `update` يصونها —
راجع تعليق «شركةٌ حُذفت ناعماً لا تُعرض في النموذج فلا تُرسَل»)، فيُحسن أن تُعرَض للمحرِّر
مُعطَّلةً باسمٍ محايد («شركة خارج نطاقك») لا بالاسم الحقيقيّ.

### اختبارُ الانحدار

`tests/Feature/UserFormCompanyPickerIsScopedTest.php`:

```php
// معزولٌ على A + رايةُ users
$this->assertSame(404, $this->get("/m/companies/{$b->id}")->getStatusCode());
$html = $this->get('/admin/users/create')->getContent();
$this->assertMaskedValueAbsent($html, 'الخصم ZQXJ9182736450');
$this->assertStringContainsString('شركة أ', $html);   // لا تُفرَّغ القائمةُ إفراطاً
```

**الثقة:** عالية.

---

## A02-03 · منتقي المشاريع في سجل التدقيق غيرُ منطَّق بعزل الشركات

**الخطورة:** P1 (تجاوزُ تخويل — تسرّبٌ عابرُ العزل)
**الحال:** مُثبَتة

### الوصف

في `AuditController::index` تُبنى ثلاثُ منسدلاتٍ متجاورة. الشركاتُ والعملاءُ **منطَّقان**
بـ`hub_company_ids`/`hub_client_ids`، والمشاريعُ **لا**:

```php
// app/Http/Controllers/Web/AuditController.php:80-91
'companies' => DB::table('companies')->…->when($cids !== null, fn ($w) => $w->whereIn('id', $cids))…
'projects'  => DB::table('projects') ->…->when(hub_scoped($u),  fn ($w) => $w->whereIn('id', $u->visibleProjectIds()))…
'clients'   => DB::table('clients')  ->…->when($kids !== null, fn ($w) => $w->whereIn('id', $kids))…
```

`hub_scoped($u)` تصدق **فقط** على دورِ `scope='proj'`. أمّا العزلُ بالشركات — وهو العزلُ
السائدُ في المنصّة — فلا يمسّ هذا السطر. والتعليقُ فوق الكتلة يَعِد صراحةً: «مصادرُ المنسدلات
منطَّقةٌ كالقائمة نفسِها: المحصورُ لا تُسمّى له شركةٌ أو عميلٌ خارج نطاقه ولو في مرشِّح» —
**فالوعدُ مكتوبٌ والمشاريعُ خارجَه**. وهذا ما يجعله عيباً لا قراراً.

### الدليل — الطلبُ الفعليّ والاستجابة

فاعلٌ معزولٌ على «شركة أ» يحمل رايةَ `audit`. مشروعُ الخصم في «شركة ب».

```
# خطُّ الأساس
GET /m/projects/{B-project}   -> 404

# والمنسدلة تُفشيه — اسماً ومعرِّفاً
GET /admin/audit              -> 200
  <select name="project"> …
    <option value="837a5610-8a87-4960-bee0-327b5e0a1add">مشروع الخصم ZQXJ9182736450</option>
    <option value="b51e2946-7a6d-4083-8723-aacca1b8dfc6">مشروعي</option>
  </select>
```

ولاحظ أنّ منسدلةَ الشركات في **الصفحةِ نفسِها** لم تُفشِ «الخصم» — فالخللُ سطرٌ واحدٌ
بين سطرين سليمين.

### خطواتُ الإعادة

1. شركتان `A`/`B`، ومشروعٌ في كلٍّ منهما.
2. مستخدمٌ `companies=[A]`، دورٌ غيرُ مالك، `flags=['audit'=>1]`، `scope='all'`.
3. `GET /m/projects/{B-project}` ⇒ ٤٠٤.
4. `GET /admin/audit` ⇒ ٢٠٠ واسمُ مشروعِ `B` ومعرّفُه في `<option>`.

> ملاحظةٌ مهمّة: **صفوفُ السجلّ نفسُها لا تتسرّب.** `Audit::scopedQuery` منطَّقٌ إحكاماً
> (وحدةٌ محجوبة، عزلُ الشركة مع «المعدومُ الشركةِ لصاحبه وحده»، عزلُ العميل). الثغرةُ في
> **المرشِّح** لا في القائمة — لكنّ الاسمَ وحدَه تسريب، وهو المبدأُ الذي أقامه هذا الملفُّ لنفسِه.

### السببُ الجذريّ

`app/Http/Controllers/Web/AuditController.php:84-87` — القيدُ الوحيدُ `hub_scoped()`، بلا
`hub_company_ids()` ولا `hub_client_ids()`، خلافاً لجارَتيه.

### الإصلاح المقترح

استعمل المحرّكَ الواحد بدل بناءِ قيدٍ يدويٍّ ثالث:

```php
'projects' => hub_scope(DB::table('projects')->whereNull('deleted_at'), 'projects', $u)
    ->orderBy('name')->orderBy('id')->pluck('name', 'id'),
```

`hub_scope` تجمع الثلاثةَ (المشروع + الشركة + العميل) في نداءٍ واحد — وهي المستعمَلةُ فعلاً
في `MobileWorkController:496` و`MorningController:242` و`Employee360:36` لنفس الجدول.
وابحث في الدفعة نفسِها عن أخواتها: `ExecutionStats:177` و`ExecutionStats:1030` تقرآن
`hub_open_scope(DB::table('projects'))` بلا `hub_scope` كذلك — وهما اليومَ محميّتان
**بالبوّابة** (`hub_can_org_analytics` تردّ المعزولَ عن لوحات المنشأة) لا بالاستعلام، فدفاعٌ
في العمقِ يقتضي تنطيقَهما أيضاً (`ظنّيّ` كتسرّبٍ: لم أجد مساراً يبلغهما بحسابٍ معزول).

### اختبارُ الانحدار

`tests/Feature/AuditFiltersAreScopedTest.php`:

```php
$html = $this->actingAs($isolated)->get('/admin/audit')->getContent();
$this->assertMaskedValueAbsent($html, 'مشروع الخصم ZQXJ9182736450');
$this->assertStringContainsString('مشروعي', $html);
$this->assertMaskedValueAbsent($html, $rivalProject->id);
```

**الثقة:** عالية.

---

## A02-04 · `identity/resolve` يردّ ٥٠٠ على خطِّ اتصالات (مفتاحٌ غائبٌ في خريطة)

**الخطورة:** P2 (تصلّبٌ ناقص — توافريّة، لا تسريب)
**الحال:** مُثبَتة

### الوصف

`Identity::resolve()` تُعيد خمسةَ أنواع، منها `'phone'` (خطُّ الاتصالات — ICCID/MSISDN،
الطور G). لكنّ `V1Controller::identityResolve` يترجم النوعَ بخريطةٍ من **ثلاثةِ** مفاتيح:

```php
// app/Http/Controllers/Api/V1Controller.php:606-608
if ($hit['type'] !== 'none') {
    $module = ['asset' => 'assets', 'product' => 'products', 'stock' => 'stock'][$hit['type']];
    abort_unless($this->tokenAllows($module, 'v'), 403, …);
}
```

`$hit['type'] === 'phone'` ⇒ مفتاحٌ غير معرَّف ⇒ `null` ⇒ `tokenAllows(string $module, …)`
ترمي `TypeError` ⇒ ٥٠٠. المسحُ الميدانيُّ لشريحةٍ مسجَّلةٍ في `record_identifiers` يسقط
على السطحَين معاً (`/api/v1/identity/resolve/{q}` و`/api/mobile/v1/identity/resolve/{q}`
الذي يستدعي `parent::identityResolve`).

**لا تسريب:** `Identity::openScoped('phones', …)` تفرض `hub_can($user,'phones','v')`
و`hub_scope` قبل أن تصل النتيجةُ هنا، و`Api::render` تُغلّف الاستثناء بردٍّ عامٍّ بلا أثرِ
استثناءٍ ولا اسمِ صنفٍ داخليّ. الضررُ عطلٌ لا كشف — لكنّه عطلٌ في مسارٍ ميدانيٍّ يُمسَح به
ملصقٌ في الحقل، ويُسجَّل حادثاً في مركز الأخطاء عند كلِّ مسحة.

### الدليل — الطلبُ الفعليّ والاستجابة

```
PhoneNumber(number=96599887766, iccid=8996500000000012345)
Identity::attach('phones', {id}, 'iccid', '8996500000000012345')

GET /api/v1/identity/resolve/8996500000000012345
Authorization: Bearer lyn_…                       (مفتاحُ المالك — بلا قيدِ نطاق)
  -> 500
  {"error":"عطلٌ داخليٌّ سُجّل تلقائياً — أعد المحاولة، وأرفق معرّف الطلب (request_id) …"}
```

### خطواتُ الإعادة

1. أنشئ `PhoneNumber` بـ`iccid`، وسجّله في سجلِّ المعرّفات (`Identity::attach('phones', …)`).
2. `GET /api/v1/identity/resolve/<iccid>` بأيِّ مفتاحٍ صالح لصاحبِ `phones:v` ⇒ ٥٠٠.

### السببُ الجذريّ

`app/Http/Controllers/Api/V1Controller.php:607` (والنسخةُ الثانيةُ من الخريطة في `:614`) —
خريطةُ `type ⇒ module` تأخّرت عن `Identity::KINDS`/`openScoped` حين أُضيف خطُّ الاتصالات.

### الإصلاح المقترح

خريطةٌ واحدةٌ مسمّاةٌ بجانب `Identity` (لا نسختان في المتحكّم)، مع `?? null` صريح:

```php
// App\Support\Identity
public const TYPE_MODULE = ['asset' => 'assets', 'product' => 'products',
                            'stock' => 'stock', 'phone' => 'phones'];

// V1Controller::identityResolve
$module = \App\Support\Identity::TYPE_MODULE[$hit['type']] ?? null;
if ($module !== null) abort_unless($this->tokenAllows($module, 'v'), 403, …);
```
وأضِف `'phone'` إلى ذراعِ `match` كي يُعاد الخطُّ بحمولته (نوع/وحدة/معرّف) بدل `type=none`.
واختبارُ عقدٍ يضمن أنّ **كلَّ** نوعٍ تُعيده `Identity::resolve` له مدخلٌ في الخريطة.

### اختبارُ الانحدار

```php
// tests/Feature/IdentityResolveCoversEveryTypeTest.php
foreach (['asset','product','stock','phone'] as $t) {
    $this->assertArrayHasKey($t, \App\Support\Identity::TYPE_MODULE);
}
$this->withToken($tok)->getJson('/api/v1/identity/resolve/'.$iccid)->assertOk()
     ->assertJsonPath('type', 'phone');
```

**الثقة:** عالية.

---

# ما هاجمتُه وصمد

هذا الفصلُ هو نصفُ القيمة: ما دون ذكرِه يتكرّر الهجومُ نفسُه في الجولة القادمة.

## ١) المصادقة — صمدت

- **تجاوزُ الدخول / أوراكلُ التعداد:** `AuthController::login` تحسب سببَ المنع (موقوف/منتهٍ/
  مقفول/خارجُ الشبكة/قفلُ طوارئ) ولا تُفصح عنه **إلا بعد** نجاح `Auth::attempt` — فلا يُميَّز
  البريدُ الموجودُ من المعدوم بطلبٍ بلا كلمة سرّ (`AuthController.php:42-89`).
- **تثبيتُ الجلسة:** `session()->regenerate()` عند كلِّ انتقالِ حالة (قبل تحدّي 2FA وفي
  `finishLogin`)، و`invalidate()+regenerateToken()` عند الخروج وعند الردّ بعد `$blocked`.
- **«تذكّرني»:** مُلغاةٌ بالبناء — `Auth::attempt($data, remember: false)` وكذلك بعد TOTP.
  فلا كعكةَ إحياءٍ بلا كلمةِ مرور. و`SESSION_LIFETIME=720` (١٢ ساعة) لا سنة.
- **TOTP:** `otpVerify` يفحص قفلَ الحساب **أيضاً**، ويزيد عدّادَ الفشل، ويكتب أثراً،
  و`Totp::verifyOnce` تمنع إعادةَ استعمال الرمز بمفتاحٍ لكلِّ مستخدم (`login:{id}`).
  والحسابُ الممنوعُ لا يبلغ خطوةَ الرمز أصلاً (يُفحص `$blocked` قبلها).
- **step-up (`security.stepup_ops` = 1 افتراضاً):** مفروضٌ على `twofaOff`/`unlock`/تدوير مفاتيح
  API/سحبِ عضويّةِ عميل/تصديرٍ كبير/تصديرِ ICCID الجماعيّ. `StepUpController` يحدّ التخمين
  (٥/دقيقة على المستخدم + `throttle:10,1` على المسار) ويكتب أثرَ الفشل والنجاح.
- **إعادةُ التوجيه بعد التصعيد:** حاولتُ فتحَها بـ`next=/\evil.example.com/x` (تجاوزُ `//` المعتاد
  بالشرطة الخلفية). `safeNext` يقبل السلسلة، **لكنّ** Laravel تُقدّم أصلَ التطبيق
  فتخرج `Location: http://127.0.0.1:8000/\evil.example.com/x` — والمتصفّحُ يطبّع `\` إلى `/`
  **داخل المسار** بعد أن حُسم المضيف، فيبقى على الأصل نفسِه. **ليست ثغرة.**
- **رمزُ التحديث (الجوال):** `MobileSessionService::rotate` يكشف إعادةَ الاستعمال ويُبطل
  **العائلةَ كلَّها**، ويُشعر صاحبَ الحساب، ويكتب أثراً ورادارَ أمن. والتدويرُ يعيد فحصَ حالة
  الحساب (محذوف/موقوف/منتهٍ/IP/قفل طوارئ) فلا يُمدَّد اعتمادٌ لحسابٍ صار محجوباً.
- **`MobileSessionAuth`:** يطابق حراسَ `ApiAuth` الخمسة حرفاً، ويصادِق `access_hash` فقط
  (لا يقبل رمزَ التحديث)، ولا يثق بأيِّ هويّةٍ يرسلها العميل.
- **قفلُ الحساب:** `bumpFailedAttempts` على كلا البابين (كلمة المرور والرمز)، وخانقٌ مسمّى
  `throttle:login` لكلِّ (بريد+عنوان) + سقفٌ على العنوان.

## ٢) التخويل — صمد (عدا A02-01)

- **كنسُ كلِّ مسارات GET بلا وسائط بحسابٍ صفريِّ الصلاحيات:** ٣٣ استجابةَ ٢٠٠ فقط، كلُّها
  أسطحٌ شخصيّةٌ مشروعة (الملفّ، أماني، التنبيهات، البحث، اللوحات، التقويم، التخصيص). لا شاشةَ
  إدارةٍ ولا مركزَ أمنٍ ولا وحدةَ بيانات.
- **كنسُ كلِّ مسارات الكتابة (POST/PUT/DELETE) بلا وسائط بالحساب نفسِه:** لا مسارَ كتابةٍ
  اجتاز الحارس؛ ما لم يُردّ ٤٠٣/٤٠٤ رُدّ بالتحقّق (٣٠٢ إلى النموذج) أو كان سطحاً شخصيّاً.
- **`ModuleController` (محرّكُ ٨٥ وحدة):** كلُّ بابٍ يمرّ بـ`resolve()` (`hub_can` + حجبُ
  `users` + بوّابةُ `endpoints`) ثم `findScoped()` (`hub_scope` + `findOrFail`). فحصتُ
  `show/edit/update/destroy/restore/setStatus/restoreVersion/board/export/bulk` — كلُّها.
- **التصدير:** حزامٌ واحدٌ (`exportBelt`) على مساري CSV معاً: مفتاحُ `export` الدقيق، ومفتاحُ
  تجميدٍ طارئ (٤٢٣)، وعتبةُ «تصديرٍ كبير» بتصعيدٍ ووسمٍ في التدقيق، وقيدُ «خارج الدوام»،
  وتصعيدٌ مستقلٌّ لتصدير ICCID الجماعيّ.
- **حسابُ العميل (البوّابة):** `PortalGuard` (ويب) و`MobilePortalGuard` (جوال) و**تكرارٌ خادميٌّ
  ثالثٌ** في `resolveApi` (لأنّ `/api/v1` لا تمرّ بوسيط الويب) — قائمةٌ بيضاءُ فوق المصفوفة،
  و٤٠٤ لا ٤٠٣ (لا كشفَ وجود). و`hub_scope` تحصر `fin` على أنواعِ فواتيرِ العميل.
- **رايةُ التدقيق:** `Audit::scopedQuery` تحجب وحدةً لا يراها القارئ، وتفرض عزلَ الشركة
  («المعدومُ الشركةِ لصاحبه وحده») وعزلَ العميل بربطٍ على جدولِ كلِّ وحدة. و`Audit::diff`
  تفحص `hub_can(module,'v')` **ثم** `hub_field_mode` لكلِّ عمود، فتطبع «••• محجوب» بدل القيمة.

## ٣) IDOR — صمد

- **عزلُ الشركات:** بحسابٍ معزولٍ على «شركة أ» وبمصفوفةٍ كاملةٍ وكلِّ الرايات، حقنتُ بصمةً
  (`ZQXJ9182736450`) في اسمِ شركةٍ ومشروعٍ وموظّفٍ (براتب 987654) وفاتورةٍ ومهمّةٍ لشركة `ب`،
  ثم كنستُ **كلَّ** مسارات GET بلا وسائط + قوائمَ ٥ وحداتٍ + بحثَها + تصديرَها + سجلَّاتها
  المباشرةَ وصفحاتِ تحريرها. النتيجة: `/m/{module}/{B-id}` و`…/edit` ⇒ **٤٠٤** في كلِّ وحدة،
  ولا ظهورَ للبصمةِ ولا للراتب في أيِّ شاشة — عدا الموضعَين المذكورَين في A02-02/A02-03
  (منسدلتان، لا بيانات).
- **المرفقات:** `AttachmentService::download/stream` ⇒ `guardRecord` (وحدة + `hub_can` +
  `hub_scope`+`findOrFail`) ثم `DocumentPolicy::authorize`، وحاجزُ «مصاب» ٤٢٣، وسجلُّ تنزيل،
  وتدقيقُ وصولٍ مصنَّف. والتنزيلُ الجماعيُّ (`zip`) يُسقط كلَّ وثيقةٍ تمنعها السياسة **فرديّاً**
  فلا يلتفّ على منعٍ واحد.
- **الأسطحُ الذاتيّة:** `MySecurityController::{trustDevice,revokeDevice,revokeSession}`،
  `ProfileController::{tokenRevoke,tokenRotate}`، `PasskeyController::destroy` — كلُّها
  `where('user_id', auth()->id())` قبل `findOrFail`. لا IDOR.
- **بوّابةُ العميل:** `ClientPortalController::documentDownload` تعزل بعضويّةِ العميل وجمهورِ
  الوثيقة، ثم `DocumentPolicy`، ثم تحجب «سري» لغير حاملِ `docsec` بـ**٤٠٤** (لا ٤٠٣ — لا إثباتَ وجود).

## ٤) الحقن — صمد

- لا موضعَ واحدٍ يُمرِّر مدخلَ مستخدمٍ إلى `whereRaw`/`selectRaw`/`orderByRaw`/`DB::raw`.
  فحصتُ الستّةَ والخمسين موضعاً: كلُّها ثوابتُ نصّية، أو أعمدةٌ من **سجل الوحدات** (`config/hub.php`)،
  أو قيمٌ مربوطةٌ بـ`?`.
- **الفرز:** `Api::sort` قائمةٌ بيضاءُ من حقول الوحدة + حجبُ الأنواع الحسّاسة (`sec/file/img/tags`)
  + حجبُ ما `hub_field_mode = 'hide'`. و`AuditController::SORTS` و`SecurityController::$sorts`
  خرائطُ ثابتة. لا `orderBy($request->…)` في أيِّ مكان.
- **الترشيح:** `buildQuery` و`applyAdvancedFilters` يرفضان الترشيحَ على حقلٍ **مخفيّ** (أوراكلُ
  استدلال)، ويحصران الأعمدةَ الضمنيّةَ في قائمةٍ بيضاءَ من عمودَين، ويهرّبان `%`/`_` في `LIKE`
  بحرفِ هروبٍ موحّدٍ بين المحرّكين (`!`).
- **البحث:** `Searchable::scopeSearch` يهرّب أحرفَ البدل، ويقرأ الأعمدةَ من سجل الوحدات لا من
  المستخدم، و**يُسقط كلَّ حقلٍ مخفيٍّ عن الدور** — فالبحثُ ليس بابَ الأوراكل المنسيّ.
- **`Observability::terminate`:** `json_set('$."{$b}"')` — و`$b` مخرجُ `Series::bucket((float)$ms)`
  من عددٍ محسوبٍ خادميّاً لا من الطلب.

## ٥) XSS — صمد

- ٦٢ موضعَ `{!! !!}` في القوالب، فحصتُ كلَّ واحدٍ منها: رسومُ QR/باركود مولَّدةٌ خادميّاً
  (`Qr::svg`, `Barcode`)، أو HTML ثابتٌ في القالب، أو نصٌّ مرّ بـ`e()` قبل التركيب.
- `CodeHub::notesHtml` — تُطبّق `e()` على **كامل** النصّ ثمّ تبني الوسومَ من النصّ المهروب
  (`<ul>/<li>/<h4>/<p>`)، فلا يبقى وسمٌ خامّ.
- `hub_brand_css()` — تقبل `#RRGGBB` بـ`preg_match` صارمة فقط، فلا حقنَ CSS من الإعدادات.
- `hub_safe_url()` — تجرّد محارفَ التحكّم **قبل** فحص المخطّط (فـ`java[tab]script:` يُحيَّد)
  وتحصر المخطّطاتِ في `http/https/mailto/tel`. جرّبتُ `\\evil.example.com` فتمرّ (المتصفّحُ
  يطبّع `\` إلى `/`) — لكنّ الأثرَ **معدوم**: الدالّةُ تسمح بـ`https://evil.example.com` أصلاً
  (روابطٌ خارجيّةٌ مقصودة، بـ`target="_blank" rel="noopener"`)، فحجبُ `//` تفادياً للغموض لا
  حاجزُ أمن. لا أرفعه ثغرة.

## ٦) الإسنادُ الجماعيّ (Mass assignment) — صمد

- `ModuleController::fill()` يمرّ على `$def['fields']` **حصراً** — فمفتاحٌ ليس في سجل الوحدة
  لا يُكتب أبداً مهما حُقن. ويتخطّى كلَّ حقلٍ `hub_field_mode !== ''` (مخفيّ أو قراءةٌ فقط).
- لا موضعَ واحدٍ لـ`fill($request->all())`/`create($request->all())` في المستودع.
- `company_id`/`project_id`/`client_id`: `guardCompany`/`guardProject`/`guardClient` ترفض قيمةً
  خارجَ نطاق الكاتب بـ`ValidationException`، و`inherit*` تورّث نطاقَ الكاتب لا أكثر.
- `role_id`: محجوبٌ عن محرّك الوحدات كلّياً (`resolve()` تردّ `users` بـ٤٠٤) — بابُه الوحيد
  `UserController`، وهو موضعُ A02-01.
- **استعادةُ نسخةٍ قديمة** (`HasVersions::restoreVersion`) — بابٌ خلفيٌّ محتملٌ للكتابة، **وهو
  مسدود**: تُسقط كلَّ عمودٍ `hub_field_mode !== ''` قبل `fill`، و`snapshotScopeError` ترفض
  لقطةً تُعيد السجلَّ إلى شركةٍ/عميلٍ/مشروعٍ خارج نطاق المستعيد.

## ٧) CSRF — صمد

- الإعفاءُ الوحيدُ `hook/*` (`bootstrap/app.php`)، وهو سطحٌ آليٌّ لا متصفّحَ له: يُصادَق برمزٍ
  `Str::random(48)` في المسار + HMAC اختياريٍّ مربوطٍ بالزمن (`X-Hub-Timestamp` ±٣٠٠ث) +
  منعِ إعادةٍ بـ`X-Hub-Event-Id` وقيدٍ فريدٍ + سقفِ حمولةٍ + `throttle:120,1`.
- كلُّ مسارات الجوال/API خارجَ مجموعةِ `web` أصلاً (رمزٌ حامل، لا كعكة) فلا CSRF عليها.
- **ملاحظةٌ للتصلّب (`ظنّيّ`، لا ثغرة):** توقيعُ HMAC على الويبهوك الوارد **اختياريّ**
  (`signed` عند الإنشاء). نقطةٌ بلا سرٍّ يكفيها الرمزُ في الرابط — وهو سرٌّ يُنسخ ويُلصق في
  أنظمةٍ خارجيّة. يُحسن جعلُ التوقيع افتراضاً للجديد.

## ٨) SSRF — صمد

- كلُّ نداءٍ صادرٍ في المنتج يمرّ بـ`hub_outbound_ok`: المسبار (`ConnectionProbe`)، والويبهوك
  الصادر (`WebhookDispatcher`)، وأودو (`Odoo`, `OdooConnectionController`)، والتوافر (`Uptime`)،
  والاكتشاف (`Discovery\Engine`)، وn8n. تحقّقتُ من المستدعين واحداً واحداً.
- الحارسُ يرفض غيرَ `http/https`، والأسماءَ المحليّة قبل أيِّ DNS، وكلَّ عنوانٍ خاصٍّ أو محجوز،
  و`169.254.0.0/16` صراحةً (بوّابةُ بيانات السحابة).
- **وTOCTOU مغلق:** `hub_resolve_pin` تثبّت `CURLOPT_RESOLVE` على العنوان الذي أجازه الحارس،
  فلا يُعيد curl التحليلَ ولا يبدّله DNS rebinding بين الفحص والاتصال. وUptime لا تتبع
  إعادةَ التوجيه.
- النداءان الباقيان وجهتُهما ثابتةٌ في الكود: `api.telegram.org` و`FcmPushProvider`.

## ٩) الملفّات — صمد

- **القرصُ خاصٌّ صراحةً** في كلِّ مسارِ رفع: `store('hub/att','local')`، `store('dataroom','local')`،
  `store('imports','local')`، `store('hub','local')`. الاستثناءُ الوحيدُ `hub/branding` على
  `public` — شعارٌ مقصودُ العلنيّة. فلا ملفَّ مستخدمٍ يُخدَم بلا بوّابة.
- **اجتيازُ المسار:** لا مسارَ يُبنى من مدخلِ مستخدم. `MobilePlatform::docContent` تقصر الاسمَ
  بـ`basename` + `preg_match('/^[A-Za-z0-9._-]+\.md$/')` + **مطابقةِ `realpath` بقائمةِ
  `glob` الحقيقيّة**. و`ClientPortalController::documentDownload` تشترط `str_starts_with('hub/')`
  و`! str_contains('..')` فوق قيمةٍ مصدرُها القاعدة.
- **التنفيذ:** غرفةُ البيانات تفرض سياسةَ النوع قبل سياسة التنزيل — الصورُ وPDF وحدَها
  `inline`، وكلُّ ما عداه (HTML/SVG خاصّةً) تنزيلٌ قسريّ، مع `nosniff` و`X-Robots-Tag`.
  وحين يكون الرابطُ «عرضٌ فقط» تُحرق العلامةُ المائيةُ في البايتات نفسِها ويفشل **مغلقاً**.
- **الحزمة:** `ZipArchive` بأسماءٍ مُرقّمةٍ عند التكرار، وملفٌّ مفقودٌ لا يُسقط الحزمة، والمصابُ
  والممنوعُ بالسياسة لا يدخلان.

## ١٠) الأسرار — صمدت

- `APP_DEBUG=false` في `.env.example`، و`Api::render` تُغلّف كلَّ استثناءٍ على `/api/*` بردٍّ
  موحّدٍ بلا اسمِ صنفٍ داخليّ ولا أثرِ استثناء (أكّدتُه بردِّ الـ٥٠٠ في A02-04).
- `audits.before/after`: `Audit::MASKED` تحجب أعمدةَ الاعتماد، و`Audit::diff` تحجب بـ`hub_can`
  ثم `hub_field_mode`، ثمّ يمرّ الطرفان على `Redactor::text` فيُطمَس رمزٌ داخلَ قيمةٍ بريئة.
- الويبهوكُ الوارد يُخزَّن **مطموساً** (`Redactor::json`) بعد التحقّق من التوقيع على الخام.
- مركزُ رموز API: **لا قيمةَ رمزٍ ولا بصمتَه في الصفحة أبداً**، وهو للمالك وحدَه.
- `hub_field_sec` (راتب/هويّة/جواز/IBAN/ميزانيّةُ مشروعٍ وتكلفتُه/تكلفةُ عرضٍ/رصيدُ بنك) خلف
  مفتاحِ `fieldsec` — ويسري في **كلِّ** سطحٍ يستشير `hub_field_mode`: النموذجُ والعرضُ و`fill`
  والتصديرُ والـAPI والكانبان والفرزُ والترشيحُ والبحثُ وقيدُ التدقيق واستعادةُ النسخة.
- **خبيئةُ الشاشات ليست بابَ تسريب:** `hub_scope_key` تحمل الدورَ والمستخدمَ والشركةَ النشطةَ
  ومساحةَ العميل وعدسةَ المشروع + ختمَ `roles`/`users` — فسحبُ صلاحيةٍ يُبطل المفتاحَ فوراً،
  ولا يقرأ أحدٌ نسخةَ غيرِه.

## ١١) إعادةُ التوجيه المفتوحة — صمدت

- لا `redirect($request->input(...))` في المستودع. الموضعان الوحيدان (`StepUpController::safeNext`
  و`hub_require_stepup::$safeLocal`) يشترطان مساراً داخليّاً، والمخرَجُ يُقدَّم بأصل التطبيق
  (راجع التجربةَ في §١ أعلاه).
- `redirect()->intended()` تقرأ `url.intended` التي يكتبها وسيطُ المصادقة من الطلب الداخليّ
  نفسِه لا من مدخلِ مستخدم.

## ١٢) أسطحٌ عامّةٌ بلا مصادقة — فُحصت وصمدت

`healthz` · `manifest.webmanifest` · `pwa-icon.svg` · `offline` · `.well-known/*` ·
`s/{token}` (غرفةُ البيانات) · `sign/{token}*` (التوقيع) · `verify` و`verify/{code}/doc` ·
`activate/{token}/*` · `hook/{token}` · `passkey/login/*` · `v1/endpoint/*`.

- رموزُ المشاركة `Str::random(48)`، ورمزُ التحقّق `LYN-XXXX-XXXX` (≈٤١ بت فعليّة بعد
  `strtoupper`) خلف `throttle` ضيّقٍ لكلِّ مسارٍ **بدلوٍ مستقلّ** — التخمينُ غيرُ عمليّ.
- صفحةُ التحقّق لا تردّ إلا على **الموقَّع** (المسودةُ لا تُكشف)، ولا تكتب في سجلِّ الأدلّة
  على فتحٍ مجهول (كان بابَ إغراق).
- `v1/endpoint/*` خلف `EndpointSignature`: عقدُ ES256 (طابعٌ ±٣٠٠ث + nonce فريدٌ لكلِّ جهاز
  ⇒ ٤٠٩ عند الإعادة + تحقّقٌ بالمفتاح العامّ المخزَّن) **قبل أيّ منطقِ معالج**.

## ١٣) سياقُ الجوال — صمد

`MobileContext` يقرأ `X-Lynomia-Company`/`X-Lynomia-Client` **تضييقاً لا تخويلاً**: القيمةُ خارجَ
مجموعةِ المستخدم تُتجاهَل (لا تُحجب ولا توسّع)، و`apply()` تضيف `AND` **فوق** `hub_scope` لا بدلاً
عنه. برهانُ «لا توسيع» بنيويٌّ لأنّ المجموعةَ المطبَّقةَ ⊆ المسموح.

---

## مواضعُ تصلّبٍ مقترحة (لا ثغراتٌ مُثبَتة)

1. **توقيعُ الويبهوك الوارد اختياريّ** — اجعله افتراضاً للنقاط الجديدة (§٧).
2. **`ExecutionStats:177` و`:1030`** تقرآن `projects` بلا `hub_scope` — محميّتان اليومَ بالبوّابة
   (`hub_can_org_analytics`) لا بالاستعلام. دفاعٌ في العمقِ يقتضي تنطيقَهما (§A02-03).
3. **`QualityController::duplicates()`** تقرأ `clients` بلا نطاق وتعرض الاسمَ والبريدَ والهاتف —
   وهو **مقصودٌ ومُوثَّق** (تبويبُ «البيانات» في `OWNER_TABS` للمالك وحدَه). يُحسن إضافةُ
   اختبارِ انحدارٍ يثبّت أنّ التبويبَ لا يُفتح لغير المالك، كي لا ينزلق الحارسُ يوماً.
4. **`UserController::index`** غيرُ منطَّقٍ بالشركات — وهو مقصودٌ ومُعلَّلٌ في `hub_company_null_is_unowned`
   («المعزولُ لا يُصبح أعمى عن البشر»)؛ أثبتُّ أنّه قرارٌ لا سهو.
