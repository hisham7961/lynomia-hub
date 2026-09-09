# قراءةُ المشروع 360 (02)

المشروعُ 360 **تركيبٌ** فوق القرّاءِ القائمين — لا خدمةَ دومينٍ جديدة:

| القسم | المصدر |
|---|---|
| النظرة (حالة/إنجاز/عميل/فريق/عدّادات) | حقولُ المشروع + `hub_project_health` + `NextAction` |
| العمل (مهام/قضايا/حوادث/تغييرات/موافقات) | `hub_related('projects',$id)` عبر `partials.record_list` |
| الملفّات/الموارد التقنيّة (خوادم/نطاقات/قواعد/هواتف) | نفسُ `hub_related` (أبناءٌ بعمود `project_id`) |
| **الأصول** (جديد) | `AssetProjectService::activeForProject` (محدود · لا N+1) |
| المالية | `hub_project_pl` (للمخوَّل فقط) |
| الغرف | `ensureProjectRooms` + روابطُ /collab |
| النشاط | `hub_timeline('projects',$id)` |
| العلاقات | `graph.explore?m=projects&id=…` |

قسمُ الأصولِ يستعمل `AssetProjectService` (قراءةٌ محدودة) لا queries في Blade — والعدّادُ مُنطَّقٌ (نفسُ نطاقِ الصفوف · §69).
