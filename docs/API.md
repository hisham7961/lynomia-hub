# Lynomia Hub — REST API v1

كل الطلبات تتطلب مفتاحاً تُنشئه من **حسابي ← مفاتيح API**، ويُرسل في الترويسة:

```
Authorization: Bearer lyn_xxxxxxxxxxxx
Accept: application/json
```

**المفتاح يحمل صلاحيات صاحبه نفسها**: مصفوفة الدور، نطاق المشاريع، والموافقات المُلزِمة
(العمليات المحمية تُرفض بـ 409 وتُنفذ من الواجهة). كل كتابة تُسجل في التدقيق باسم صاحب المفتاح.
حد المعدل: ١٢٠ طلباً بالدقيقة لكل مفتاح.

## نقاط عامة

| الطلب | الوصف |
|---|---|
| `GET /api/v1/me` | هوية صاحب المفتاح ودوره |
| `GET /api/v1/modules` | الوحدات المتاحة له بحقولها وصلاحياته عليها |
| `GET /api/v1/reports/progress/{projectId}` | نسبة إنجاز مشروع بتفاصيل مكوناتها |
| `GET /api/v1/reports/health` | صحة الشركة (مالكون فقط) |

## وحدات الأعمال (الـ ٥١ كلها)

`{module}` = مفتاح الوحدة من `GET /modules` (مثل `clients`، `tasks`، `fin`…)

| الطلب | الوصف |
|---|---|
| `GET /api/v1/{module}?q=&status=&page=&per=` | قائمة مرقمة (per ≤ 100) مع بحث وفلتر حالة |
| `GET /api/v1/{module}/{id}` | سجل واحد |
| `POST /api/v1/{module}` | إنشاء — أرسل الحقول **بمفاتيح السجل** (مثل `nameAr`، `clientId`) بنفس تحقق النماذج |
| `PUT /api/v1/{module}/{id}` | تعديل — **استبدال كامل**: أرسل كل الحقول التي تريد إبقاءها؛ الحقل الغائب يُفرَّغ (اقرأ السجل بـ GET ثم أعد إرساله معدلاً) |
| `DELETE /api/v1/{module}/{id}` | نقل للسلة |

## أمثلة

```bash
# هويتي
curl -H "Authorization: Bearer $TOKEN" https://hub.lynomia.com/api/v1/me

# عملاء فيهم كلمة «الخليج»
curl -H "Authorization: Bearer $TOKEN" "https://hub.lynomia.com/api/v1/clients?q=الخليج"

# إنشاء مهمة (لاحظ: مفاتيح الحقول لا أعمدة القاعدة)
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"title":"مراجعة الفاتورة","projectId":"<uuid>","assigneeId":"<uuid>","status":"جديدة"}' \
  https://hub.lynomia.com/api/v1/tasks

# نسبة إنجاز مشروع
curl -H "Authorization: Bearer $TOKEN" https://hub.lynomia.com/api/v1/reports/progress/<uuid>
```

## الأخطاء

| كود | المعنى |
|---|---|
| 401 | مفتاح مفقود/غير صالح/منتهٍ |
| 403 | لا صلاحية على الوحدة أو الحساب موقوف |
| 404 | وحدة أو سجل غير موجود (أو خارج نطاق مشاريعك) |
| 409 | العملية محمية بالموافقات — نفّذها من الواجهة |
| 422 | فشل التحقق — التفاصيل في `errors` |
| 429 | تجاوز حد المعدل |
| 503 | قفل طوارئ (غير المالكين) |
