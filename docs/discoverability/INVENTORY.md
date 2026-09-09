# جرد الاكتشاف (INVENTORY)

> مصدرُ الحقيقة: `FeatureRegistry` (١٣٤ قدرة) + IA + المسارات الحقيقيّة. هذا الجردُ يُبرِز الوجهاتِ
> ذاتَ المستخدمِ البشريّ؛ والقاعدةُ الآليّة (`DiscoverabilityAuditTest`) تضمن أنّ كلَّ مسارِ قدرةٍ
> `ENABLED` موجودٌ ومُصنَّفٌ في IA (صفرُ يتيم · صفرُ رابطٍ ميّت).

الأعمدة: القدرة · الحالة · المجال · التصنيف · المسار · المستخدم · مسارُ التنقّل الرئيسيّ · سياقيّ · بحث · لوحة · خريطة · الاكتشافُ الآن · عيبٌ · الإصلاح.

## التعاون (Collaboration)

| القدرة | الحالة | التصنيف | المسار | المستخدم | التنقّل الرئيسيّ | بحث | خريطة | عيب | الإصلاح |
|---|---|---|---|---|---|---|---|---|---|
| `collab.center` | ENABLED | PRIMARY | `collab.center` | داخليّ | **الشريط: مركز التواصل** | ✅ | ✅ | **DEFECT A** (بحثٌ فقط) | أُضيف لِ`hub_top_links`+IA center |
| `collab.channels` | ENABLED | PRIMARY | `conversations.index` | داخليّ | سكّةُ المركز · اكتشف القنوات | ✅ | ✅ | — | — |
| `collab.private_channels` | ENABLED | CONTEXTUAL | — | داخليّ | + إنشاء ← قناة خاصّة | ✅ | ✅ | خفيّةٌ في الإنشاء | ظاهرةٌ في «+ إنشاء» |
| `collab.dm` | ENABLED | PRIMARY | `dm.inbox` | داخليّ | سكّةُ المركز · + إنشاء ← رسالة | ✅ | ✅ | — | — |
| `collab.group_dm` | ENABLED | PRIMARY | `groups.index` | داخليّ | + إنشاء ← مجموعة | ✅ | ✅ | **DEFECT B** (منتقٍ غامض) | منتقٍ صالح |
| `collab.mentions` | ENABLED | CONTEXTUAL | `collab.attention` | داخليّ | **الانتباه والإشارات** | ✅ | ✅ | خفيّة | وجهةٌ في السكّة (§19) |
| `collab.saved` | ENABLED | CONTEXTUAL | `saved.index` | داخليّ | **المحفوظات** (سكّة) | ✅ | ✅ | — | ظاهرةٌ أوّليّاً (§20) |
| `collab.search` | ENABLED | CONTEXTUAL | `search.messages` | داخليّ | **بحثُ الرسائل** (سكّة) | ✅ | ✅ | — | ظاهرةٌ في السكّة (§21) |
| `collab.threads`/`reactions`/`pins`/`edit`/`unread` | ENABLED | CONTEXTUAL | — (سلوكٌ داخلَ المحادثة) | داخليّ | مركزُ التواصل | — | — | — | سلوكيّةٌ لا وجهة |
| `collab.presence`/`typing` | ENABLED (قابلُ التبديل) | CONTEXTUAL | — | داخليّ | مركزُ التواصل | — | — | — | يُطفأ بأمانٍ من مركز القدرات |
| `collab.voice_notes`/`link_previews` | DEFERRED | — | — | — | مركزُ القدرات فقط | — | — | — | لا فعلَ زائف |

## الإدارة والمنصّة

| القدرة | الحالة | التصنيف | المسار | التنقّل الرئيسيّ | عيب |
|---|---|---|---|---|---|
| `platform.feature_registry` | ENABLED | ADMIN | `features.index` | شريطُ الإدارة ← الإعدادات ← سجلّ القدرات | — (§34) |
| `platform.settings` | ENABLED | ADMIN | `settings.edit` | شريطُ الإدارة ← الإعدادات | — |
| `platform.integrations` | ENABLED | ADMIN/DEVELOPER | `integrations.index` | شريطُ الإدارة ← الإعدادات | — |
| `platform.system_map` | ENABLED | ADMIN | `system-map` | الشريطُ العلويّ 🗺️ | — |
| `security.*` (control/audit/activity) | ENABLED/INVARIANT | ADMIN/SECURITY | `control.index`/`audit.index` | شريطُ الإدارة ← الأمن/التشغيل | — |
| `endpoint.center` | ENABLED | ADMIN | `endpoints.index` | مجالُ التقنية (IA) | — |
| `mobile.platform_center` | ENABLED | ADMIN | `mobileplatform.index` | شريطُ الإدارة ← التشغيل | — |
| `workos.global_search` | ENABLED | PRIMARY | `search` | الشريطُ العلويّ (بحثٌ وأوامر) | **مسارٌ ميّت** `search.index` → صُحِّح |

## نظام العمل والأصول والاتصالات (تمثيلٌ · مُصنَّفةٌ بالكامل في IA)

الوحداتُ الكبرى (المشاريع · العملاء · المهامّ · القضايا · الموافقات · الملفّات · المعرفة · الموظّفون ·
المحطّات · الأصول · المخزون · العهدة · المالية · الاتصالات) **PRIMARY** في مجالاتِ IA ذاتِ صفحةِ المساحة
والشريطِ الجانبيّ. القدراتُ السياقيّة (موظّف 360 · مشروع 360 · محفظةُ العهدة · مستكشفُ العلاقات · النقطةُ
الطرفيّة) **CONTEXTUAL** تُكشَف من سجلِّ الأب. الواجهاتُ (`mobile.*` · `endpoint.*` ردود) **API_ONLY/MACHINE** بلا مدخلٍ بصريّ.

## الحالةُ النهائيّة

| المقياس | القيمة |
|---|---|
| قدراتٌ `ENABLED` مُتاحةٌ للمستخدمِ بلا مسار (يتيمة) | **0** |
| روابطُ تنقّلٍ ميّتة | **0** (بعد إصلاح `search.index`) |
| مركزُ التواصلِ بحثاً فقط | **0** |
| عيوبُ تحديدِ مشاركين مُسبق | **0** |
| تسريبُ تنقّلٍ داخليٍّ للعميل | **0** |
| محرّكاتُ تنقّلٍ مزدوجة | **0** |
