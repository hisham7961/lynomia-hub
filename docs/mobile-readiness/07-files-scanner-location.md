# الملفّاتُ والماسحُ والموقع — Files / Scanner / Location (Mobile Readiness · الطور F · §109)

> **الجوهرُ المشترك لا نسخةٌ ثانية:** رفعُ الملفّات يعيد استعمال `App\Support\AttachmentService`
> (قائمةٌ بيضاء + بصمةُ sha256 + القرصُ الخاصُّ `local`) و`App\Support\ChunkedUpload` للتقطيع
> (لا جدولَ جديد). **لا base64، لا رابطٌ عامّ** — كلُّ بايتٍ خلفَ بوّابةِ التخويل نفسِها، والمصابُ
> يُحجَب. الماسحُ يعيد استعمال المحلّلِ الموحّد، والموقعُ يعيد استعمال سكّةِ التتبّع بموافقةٍ صريحة.
> كلُّ ما يلي **مبنيٌّ ومُختبَرٌ على المحرّكَين**.

المعالجُ الموحّد `App\Http\Controllers\Api\MobileFileController` (يرث `V1Controller` ليَرِثَ
الـIdempotency على مالكِ الجوال). كلُّ نقاطه مُصادَقة خلف `['throttle:api','mobile.session','mobile.context']`.

---

## 1) الرفعُ المقطَّع (جلسة ← قطعة ← إتمام) + الرفعُ المفرد — F.1

| المسار | التسجيل / الاسم | المعالج |
|---|---|---|
| `POST files/upload-session` | `routes/api.php:231` · `mobile.files.upload_session` | `uploadSession` (`MobileFileController.php:60`) |
| `PUT files/upload-session/{id}/chunk` | `routes/api.php:232` · `mobile.files.upload_chunk` | `uploadChunk` (`:110`) |
| `POST files/upload-session/{id}/complete` | `routes/api.php:233` · `mobile.files.upload_complete` | `uploadComplete` (`:151`) |
| `POST files/attach` | `routes/api.php:234` · `mobile.files.attach` | `attach` (`:215`) |

**بدءُ الجلسة** (`uploadSession`): يُعلن الهدفَ (`module`/`record_id`/`filename`/`mime`/`size`)
ويُحقَّق **خادميّاً الآن**:
- `AttachmentService::guardRecord($module,$record_id,'v')` (`MobileFileController.php:73`) — إخفاقٌ
  مبكّرٌ إن لم يُخوَّل (لا IDOR)، والحارسُ نفسُه يُعاد عند «الإتمام» فلا مسارَ تصعيد.
- **حاجزُ الامتداد على الاسم المُعلَن** قبل رفع أيِّ بايت (`:76-80`): امتدادٌ في
  `AttachmentService::BLOCKED` ⇒ `VALIDATION_FAILED` 422.
- **الحجمُ المُعلَن** يُقاس بحدِّ النظام (`hub_upload_cap()['appKb']`) ⇒ `PAYLOAD_TOO_LARGE` 413 (`:83-87`).
- **لا جدولَ قاعدةٍ للجلسة:** الحالةُ على القرص في مجلّد المستخدم وحدَه (`ChunkedUpload::dir(auth id)`)
  — القرارُ موثَّق. الرمزُ المسكوكُ (`ChunkedUpload::token()`) هو الجلسة، ويُعاد `chunk_size`/`max_parts`/`max_bytes`.

**إلحاقُ القطعة** (`uploadChunk`): تحقّقُ الرمز (`ChunkedUpload::validToken`) + الفهرس (`i` صفريّ،
`< MAX_PARTS` = ٤٠٩٦ · `ChunkedUpload::MAX_PARTS`) ثم `ChunkedUpload::append` — الحدُّ على المجموع
حدُّ **النظام** لا حدُّ الطلب الواحد (لهذا وُجد التقطيع). قطعةٌ خارجَ الدور ⇒ ٤٢٢ (لا ثقبٌ صامت)،
والتجاوزُ يُلغي الرفعة ⇒ ٤١٣. الرمزُ يُطالَب من مجلّد صاحبه وحدَه — فلا يُستهلك رمزُ غيره ولو خُمِّن.

**الإتمام** (`uploadComplete`):
- **Idempotency (مالكُ الجوال · F1) يُحجَز أوّلاً** فلا تُضاعِف إعادةُ الإتمام المرفقَ (`:157`).
- تحقّقُ اكتمالِ القطع (`seen === parts`)، ثم رفعُ علامة `ResolveChunkedUploads::FLAG` كي يرفع
  `hub_upload_cap` سقفَ الطلب عن المجمَّع (`:173`)، ثم `ChunkedUpload::claim` ⇒ `UploadedFile`
  يُحقَن في الطلب كـ`file` (نظيرُ الوسيط حرفاً).
- يمرّ **بالجوهرِ المشترك نفسِه**: `AttachmentService::validateUpload` + `guardRecord(...,'v')` +
  `filesFromRequest` + `attach` (`:189-192`) — لا مسارَ جانبيّ. ثم `ChunkedUpload::forget` وتدقيقٌ
  `hub_audit('إرفاق ملفٍ مقطَّعٍ عبر الجوال', …)`.

**الرفعُ المفرد** (`attach`): الجوهرُ المشترك مباشرةً (`validateUpload` + `guardRecord(...,'v')` +
`filesFromRequest` + `attach`) خلفَ Idempotency على المالكِ الجوال.

---

## 2) الجوهرُ المشترك — `AttachmentService` (قائمةٌ بيضاء + بصمة + قرصٌ خاصّ)

`app/Support/AttachmentService.php` هو المصدرُ الواحد لكلا السطحَين (الويب والجوال) — لا نسخةَ ثانية:

- **حاجزُ الامتداد** `BLOCKED` (`AttachmentService.php:41-42`): `php php3..php8 phtml phar cgi pl sh
  htaccess html htm xhtml svg svgz js mjs` — رفضٌ ٤٢٢. (SVG/HTML قد تحمل سكربتات.)
- **البصمةُ والحجمُ من الخادم:** `checksum = hash_file('sha256', …)` (`:134`)، والـ`mime`/الحجم/`uploaded_by`
  من الخادم لا من العميل. الصفُّ يُبنى بقائمةٍ بيضاء صريحة — لا mass-assignment (`Attachment::$guarded=['id']`
  فالأمانُ في مصفوفةِ `attach` المبنيّة يدوياً).
- **القرصُ الخاصُّ `local` وحدَه:** `store('hub/att','local')` (`:119`) — **لا رابطَ عامّ ولا base64**.
  (`config/filesystems.php` — القرصُ `public` للعلامةِ التجاريّة فقط.)
- **`kind`** مقيَّدٌ بمفاتيح ملفِّ وثائق الوحدة (`hub_doc_spec`) عبر `Rule::in` (`:69-70`) — مفتاحٌ معلنٌ لا نصٌّ حر.

---

## 3) التنزيل/البثُّ المُصادَق + حاجزُ المصاب — F.2

| المسار | التسجيل / الاسم | المعالج |
|---|---|---|
| `GET files/{id}/download` | `routes/api.php:238` · `mobile.files.download` | `download` (`MobileFileController.php:253`) |
| `GET files/{id}/stream` | `routes/api.php:239` · `mobile.files.stream` | `stream` (`:269`) |

كلاهما يعيد استعمال الجوهر المشترك:
- **التنزيل** `AttachmentService::download` (`:152`): `guardRecord(...,'v')` (وحدةٌ غيرُ مرئيّة ⇒ ٤٠٣،
  وسجلٌّ خارجَ النطاق ⇒ `hub_scope->findOrFail` ٤٠٤ — **لا IDOR**) + `abort_if(av_status==='infected', 423)`
  (`:157`) + عدّادٌ + سجلُّ تنزيلٍ + تدقيقُ الوصولِ المصنَّف + ترويسة `Content-Disposition: attachment`
  (فملفُ HTML/SVG مرفوعٌ لا يُنفَّذ). `findOrFail` غيرَ الموجود/المحذوفِ ناعماً ⇒ ٤٠٤.
- **البثّ** `AttachmentService::stream` (`:183`): حرّاسُ التنزيل نفسُها + حصرُ الأنواع على
  `INLINE_MIMES` (صورٌ نقطيّة + PDF؛ غيرُها ٤١٥ يُنزَّل) + `Content-Disposition: inline` مع
  `X-Content-Type-Options: nosniff` وCSP صارمة (`:203`).
- **لا رابطٌ عامّ:** غلافُ المرفقات المُنشأة (`attachmentsPayload` · `MobileFileController.php:357`)
  يعيد مسارَي `download`/`stream` **نسبيَّين** لنقاطٍ مُصادَقة (`route(…, false)`) لا روابطَ عامّة،
  ولا يعيد مسارَ القرص.

---

## 4) الماسح — المحلّلُ الموحّد (QR/باركود/سيريال) — F.3

| المسار | التسجيل / الاسم | المعالج |
|---|---|---|
| `GET identity/resolve/{q}` | `routes/api.php:243` · `mobile.identity.resolve` | `identityResolve` (`MobileFileController.php:286`) |

يعيد استعمال `V1Controller::identityResolve` حرفاً (`app/Http/Controllers/Api/V1Controller.php:587`)
عبر `parent::identityResolve($q)` — لا محرّكٌ ثانٍ. لا `api_token` في الجوال ⇒ `tokenAllows` يمرّ
فتُطبَّق صلاحيّاتُ المستخدم كاملةً تحت `hub_can`، والنطاقُ داخلَ `Identity::resolve` — فلا يُكشَف
سجلٌّ خارجَ نطاق المستخدم. الحمولةُ معرِّفاتٌ لا حقولُ سجلٍّ (تفاصيلُه من مساره القياسيّ بحقول دوره).
مقطعٌ مفردٌ كنظيرِ v1 (`api.php:26`) — لا يبتلعه `{module}/{id}` (المقطعُ الأوّلُ حرفيٌّ `identity`).

---

## 5) الموقع — تتبّعٌ ميدانيٌّ بموافقةٍ صريحة — F.4

| المسار | التسجيل / الاسم | المعالج |
|---|---|---|
| `POST tracking/start` | `routes/api.php:248` · `mobile.tracking.start` | `trackingStart` (`MobileFileController.php:302`) |
| `POST tracking/{session}/points` | `routes/api.php:249` · `mobile.tracking.points` | `trackingPoints` (`:317`) |
| `POST tracking/{session}/end` | `routes/api.php:250` · `mobile.tracking.end` | `trackingEnd` (`:341`) |

- يعيد استعمال `V1Controller::trackStart/trackIngest/trackEnd` (`V1Controller.php:654/670/687`) عبر
  `parent::…` — لا محرّكٌ ثانٍ.
- **البدءُ** يفرض `consent=true` (لا تتبّعَ بلا إقرار) و`field_role` (لأصحاب الدور الميدانيّ) ويُدقّق.
- **دفعةُ النقاط** خلفَ Idempotency (مالكُ الجوال · F1 · `:321-332`) فلا تُضاعِف إعادةُ المحاولة النقاط؛
  الجلسةُ لصاحبها حصراً (`emp_id`)، والدفعةُ محدودةٌ (`Tracking::BATCH_MAX`)، والتكرارُ البنيويُّ
  والتسلسلُ يُحسمان في `Tracking::ingest`.
- **الإنهاءُ الصريح** هو ما يمنع التتبّعَ الدائمَ الخفيّ (بندُ المهمّة: «no hidden permanent tracking»).

---

## 6) الـIdempotency على الأثر القابلِ لإعادة المحاولة

مالكُ الـIdempotency على الجوال هو الجلسة (`Idempotency::owner` ⇒ `mobile_session->id` ·
`app/Support/Idempotency.php:40-47`) — فلا يعود ردُّ مستخدمٍ لآخر. يُحجَز على: **إتمامُ الرفع**
(`uploadComplete`)، **الرفعُ المفرد** (`attach`)، و**دفعةُ نقاط التتبّع** (`trackingPoints`). يُحجَز
قبل أيِّ أثرٍ (رفعِ المرفق/الإرفاق) فإعادةُ المحاولة بالمفتاح نفسِه تعيد الردَّ المخزَّن ولا تكرّر أثراً.

---

## 7) التغطيةُ الاختباريّة (فاشلٌ أوّلاً · المحرّكان)

- `tests/Feature/Mobile/MobileFilesTest.php` (٢١): رفعٌ مسموح · **نوعٌ ممنوع (MIME/امتداد)** ·
  **تجاوزُ الحجم** · **غيرُ مخوَّل (cross-scope-denied)** · إعادةُ قطعة · **إتمامٌ مكرّرٌ idempotent** ·
  **تنزيلٌ مخوَّل** · **مصابٌ محجوب** · لا رابطٌ عامّ.
- `tests/Feature/Mobile/MobileScannerTest.php` (٦): حلٌّ في النطاق · لا كشفَ خارجَ النطاق.
- `tests/Feature/Mobile/MobileTrackingTest.php` (٨): موافقةٌ + دفعة + تكرارٌ + تسلسل + إنهاء.
