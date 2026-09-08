# 05 — تسليمُ التنقّل للجوال (IA · الطور 9)

> **المبدأ:** سطحُ الجوال يقرأ **نفسَ** معماريةِ IA التي تقرؤها الويب — لا سجلَّ تنقّلٍ
> ثانٍ (`mobile_nav.php`) ولا تكرار. مصدرُ الحقيقة الواحد `config/hub_ia.php` عبر خدمة
> `App\Support\InformationArchitecture`، مُنطَّقاً بصلاحية المستخدم قبل التسلسل.

## النقاط

| النقطة | الوصف | التخبئة |
|---|---|---|
| `GET /api/mobile/v1/navigation` | شجرةُ IA المُنطَّقة + أعلامُ القدرة + نسخةُ المخطّط | ETag/304 (بصمةٌ لكلِّ مستخدم) |
| `GET /api/mobile/v1/bootstrap` | يحمل `ia` (نفسُ الشجرة) + `nav` (`hub_nav` — توافقٌ خلفيّ) | ETag/304 |

## شكلُ الحمولة (`ia`)

```jsonc
{
  "surfaces": [ { "key": "home",   "label": "الرئيسية", "icon": "🏠", "sections": [ … ] },
                { "key": "mywork", "label": "مهامّي",   "icon": "✅", "sections": [ … ] } ],
  "domains":  [ { "key": "entities", "label": "الكيانات والعلاقات", "icon": "🏢",
                  "plane": "work",
                  "sections": [
                    { "key": "companies", "label": "الشركات والمشاريع",
                      "destinations": [
                        { "label": "الشركات", "type": "module", "importance": "primary",
                          "mobile": "suitable", "module": "companies", "route": "m.index",
                          "args": ["companies"] }, … ] }, … ] }, … ]
}
```

- **`type`** ∈ `module | center | admin | entity | personal | system`.
- **`module`** — مفتاحٌ منطقيٌّ لشاشةِ قائمةِ الوحدة في التطبيق (الوجهةُ الأساسيّة للجوال).
- **`route` / `args`** — اسمُ مسارِ الويب (مرجعُ ربطٍ عميقٍ للوجهاتِ غيرِ الوحدات).
- **`importance`** ∈ `primary | secondary | advanced` — لترتيبِ العرض/الطيّ.
- **`mobile`** ∈ `suitable | deep-link-only | web-only` — الوجهةُ `web-only` (مثل `esign`) تُفتح
  في متصفّحٍ مضمَّن لا شاشةً أصيلة.

## الضمانات (مُثبَتةٌ باختبار `MobileIaTest`)

1. **مطابقةُ الصلاحية:** مفاتيحُ `data.ia.domains` == `InformationArchitecture::visibleDomains($user)`
   على الويب لنفس المستخدم — لا تسريبَ ولا حجبٌ خاطئ.
2. **لا تسريبَ مجالٍ محجوب:** الموظّفُ العاديُّ لا يرى مجالَ «الإدارة والنظام» (سطحَ النظام).
3. **لا مفاتيحَ أسرار** في أيِّ عمق (`assertNoKeysDeep`).
4. **ETag/304:** الطلبُ الثاني بنفس البصمة ⇒ 304 فارغة.
5. **توافقٌ خلفيّ:** الإقلاعُ ما زال يحمل `nav` (`{g,items}`) إلى جانب `ia`.

## قاعدةُ الحرس

التنقّلُ عرضٌ لا تخويل: كلُّ وجهةٍ تبقى محروسةً بمتحكّمها (الرابطُ لا يفتح باباً أغلقه المتحكّم).
ظهورُ الوجهةِ في القائمة = مرورُها بمُسنِدِها القائم (`hub_can` / `hub_top_links` / `hub_admin_links` /
الحارسُ المُسمّى) — والخادمُ يعيد الفحصَ في كلِّ نقطةٍ لاحقة.
