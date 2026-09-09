# جاهزيّةُ الجوال (08)

> كلُّ قدرةٍ ذاتِ صلةٍ بالجوال تعلن **جاهزيّتها** بصدق. **الجوالُ الأصيلُ يبقى مؤجَّلاً** — لا
> تطويرَ أصيلٌ في هذا النطاق.

## حقولُ جاهزيّة الجوال (في الكتالوج)

- `mobile_backend`: `ready` (الخلفيّةُ جاهزة) · `development` · `not_applicable`.
- `native_mobile`: `deferred` (مؤجَّلة) · `available` · `not_applicable`.
- `openapi`: `documented` · `not_applicable`.

القدراتُ الإداريّةُ الداخليّةُ لا تحتاج تكافؤَ جوالٍ (`not_applicable`).

## الحالةُ الصادقة (مجال «الجوال»)

| القدرة | الحالة |
|---|---|
| `mobile.backend` | **READY** (الخلفيّةُ جاهزة، التطبيقُ مؤجَّل) |
| `mobile.auth_apis` · `mobile.collab_apis` · `mobile.workos_apis` · `mobile.openapi` | **ENABLED** (واجهاتٌ حيّةٌ ومُختبَرةٌ وموثَّقة) |
| `mobile.native_app` | **DEFERRED** (قرارٌ استراتيجيّ ثابت) |
| `mobile.production_push` | **NOT_CONFIGURED** (يُشتقُّ من `PushService::status().configured` — لا مزوّد) |
| `mobile.store_release` | **DEFERRED** (تبعاً للتطبيق الأصيل) |

> الواجهاتُ حيّةٌ فحالتُها ENABLED (الكودُ والاختباراتُ مرجعٌ · §60)، بينما **جاهزيّةُ المنتَجِ
> للجوال** تُلخَّص بـ`mobile.backend = READY` + `native_app = DEFERRED`. لا جاهزيّةَ مزيَّفة.
