# 06 — تطابقُ التنقّل (§45-52/§103)

**المبدأ:** الرؤيةُ اشتقاقٌ من التوثيق. كلُّ سطحٍ يبوّب الوحدةَ بـ`hub_can($u,$key,'v')` — مصدرٌ واحد.

| السطح | الآليّة | البوّابة |
|---|---|---|
| الشريط (كلاسيكيّ) | `hub_nav()` على `config/hub_nav.php` | `hub_can(...,'v')` + `nav.hidden` (تخصيصٌ شخصيّ) |
| الشريط (فضائيّ) | `IA::visibleDomains/Sections` + `Workspaces::for` | `hub_can(...,'v')` |
| البحث (سجلّات) | `SearchController::searchableModules` | `hub_can(...,'v')` |
| البحث (وجهات) + لوحةُ الأوامر | `IA::searchDestinations`/`catalogDestinations` | `destinationVisible` → `hub_can` |
| خريطةُ النظام | `IA::systemMap` | `hub_can` عبر visibleDomains/Sections |
| `/api/v1`، `/api/mobile/v1` | `V1Controller::resolveApi` | `hub_can($module,$op)` + حدُّ العميل |

## النتائجُ المُثبَتة

- **`apps` و`projects` متطابقان** في كلِّ سطح — أُثبِت بثلاثِ جولاتِ تدقيقٍ وبالتجربة. الفرقُ
  الوحيد: `projects ∈ MODULE_ALLOW` و`apps ∉` (حدُّ العميلِ المقصود).
- **مُنِح العرضُ ⇒ مُكتشَف؛ نُزِع ⇒ مخفيّ** — لكلِّ الوحداتِ (اختبار
  `test_every_module_is_discoverable_when_view_granted` + `test_no_module_visible_without_grant`).
- **84/85 وحدةً لها وجهةُ IA**؛ `autos` مؤرشفة؛ `users` محكومةٌ بعلَم.
- **الشريط/البحث/لوحةُ الأوامر متّفقةٌ** (نفسُ المُسنَد) — لا تناقضَ سطحٍ مع آخر.

## البند الوحيدُ الجديرُ بالملاحظة (تخصيصٌ لا صلاحيّة)

الوضعُ الكلاسيكيُّ للشريط يحترم تفضيلَ `nav.hidden` الشخصيّ (`helpers.php:335`): قد يخفي بندَ
وحدةٍ **شخصيّاً** بينما `hub_can` يسمح — فتبقى الوحدةُ مُكتشَفةً عبر البحث/الرابط المباشر/الشريط
الفضائيّ. **تخصيصُ مستخدمٍ لا حاجزُ صلاحيّة**، وموثّقٌ في المُفسِّر كي لا يُخلَط بالمنع.

## القرارات = 0

granted-view-but-hidden = `0` · denied-view-but-visible = `0` · Sidebar/search خلاف = `0` ·
Sidebar/Command-Palette خلاف = `0` · UI/direct-route خلاف = `0` · UI/API خلاف = `0`.
