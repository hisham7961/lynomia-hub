# ٠٧ — التصريحُ والخصوصيّة (§52/§70–79)

## التصريحُ لكلِّ قسم (§70–72)

رؤيةُ السجلِّ لا تمنح أقسامَه: كلُّ قسمِ 360 خلف `hub_can(module,'v')` + `hub_scope` + `hub_field_mode`.
- الموظف: المالية خلف `custody:v`+حقلِ المبلغ؛ النقاطُ خلف `endpoints:v`؛ الأنظمةُ خلف `servers:v`؛ …
- المحطة: الأصولُ خلف `assets:v`؛ النقاطُ خلف `endpoints:v` (لا إدارةَ نقاطٍ بشراكةِ المحطة).
- الأصل: المشاريعُ خلف `projects:v`؛ المحطةُ خلف `stations:v`؛ الجردُ خلف `assets:v`.

## عزلُ الشركات (§73) — تسريبٌ = 0

كلُّ استعلامِ تجميعٍ يمرّ بـ`hub_scope`؛ وجذرُ 360 عبرَ `hub_scope(...)->findOrFail` (خارجُ النطاق ٤٠٤).
مُثبَتٌ: `*360Test::cross_company_*_is_404`.

## أمانةُ العدّ (§74)

العدّاداتُ بنفسِ نطاقِ الصفوف (`hub_scope`)؛ ما لا يُرى لا يُعدّ (null لا رقمٌ خام).
مُثبَتٌ: `Station360Test::count_safety`, `Employee360Test::overview_counts_are_permission_scoped`.

## أمانةُ التاريخ (§75)

التاريخُ حسّاسٌ كالحاضر: يمرّ بنفسِ الصلاحيّةِ والنطاق (`hub_scope` على `station_assignments`/`asset_custody`).

## عزلُ العميل (§76) — تسريبٌ = 0

- الموظف 360 (`portal.employee`) → **٤٠٣** (لا `hr:v`).
- المحطة/الأصل 360 (`m.show`) → **٤٠٤** (خارجُ قائمة `PortalGuard`).
- الرسم (`graph.explore`) → **٤٠٤** (`guardInternal`).
مُثبَتٌ: `*360Test::client_cannot_reach_*`.

## المدير/المراقب (§77/§78)

نموذجُ v2.471.1 محفوظ: لم تُوسَّع صلاحيّةٌ لأنّ 360 يُجمّع مجالات؛ كلُّ قسمٍ منطَّقٌ كما هو.
`ManagerPersonaNavigationTest`/`MonitorPersonaNavigationTest` خضراوان.

## الرسم (§52/§99) — تسريبُ عقدة/حافّة/عدّ = 0

كلُّ عقدةٍ عبرَ `hub_read`؛ الحافّةُ بين طرفَين مقروءَين بالبناء؛ الوسيطُ لا يشفع لجاره.
مُثبَتٌ: `Entity360GraphTest::graph_hides_node_and_edge_for_unauthorized_endpoint`.

## الخصوصيّة (§18/§79) — لا مراقبة

لا لقطةَ شاشةٍ/keylog/كاميرا/ميكروفون/حافظة/تصفّح — **لا عمودَ لها في أيِّ جدول**، و`EndpointPrivacy::FORBIDDEN`
يرفض ابتلاعَها (٤٢٢). فبطاقاتُ النقاطِ في 360 آمنةٌ ببنيتها، تُظهر `os/status/آخر نبضة` فقط.
