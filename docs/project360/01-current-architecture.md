# المعمارية القائمة المُعاد استعمالها (01)

انظر `INVENTORY.md` للأدلّةِ الكاملةِ ملفٍّ:سطر. الملخّص:

- **المشروعُ 360:** `m.show/projects` → `modules/custom/projects.blade.php` (تبويبات: النظرة/التسليم/الأساس/الغرف/المالية/النشاط). العميلُ يرى «النظرة» الآمنة وحدَها.
- **التجميع:** `hub_related('projects',$id)` يجمع كلَّ الأبناء (tasks/issues/incidents/changes/approvals/documents/servers/domains/dbs/phones) عبر عمود `project_id`، مُرشَّحاً audience/client.
- **القرّاء:** `hub_project_health` · `hub_project_pl` · `meta.baseline` · `NextAction` · `hub_timeline`.
- **الغرف:** `ConversationController::ensureProjectRooms` (idempotent · internal/client).
- **المستكشف:** رابطٌ قائمٌ في الترويسة `graph.explore?m=projects&id=…` (محجوبٌ عن العميل).
- **الصلاحية:** `User::visibleProjectIds` (عضويّة) + `hub_scope` (شركة/عميل) + `hub_can`.
- **العميل:** `ClientPortalData` (أعمدةٌ عميليّةٌ فقط، بلا cost/budget/rev، fail-closed).

**لم يُبنَ محرّكٌ ثانٍ لأيٍّ من هذه.**
