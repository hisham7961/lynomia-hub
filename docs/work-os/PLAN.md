> **خطةُ الأطوار كما سُلِّمت (Work OS · قبل التنفيذ):** الخطةُ التي حكمت الأطوار A–M — تُحفَظ هنا مرجعاً تاريخياً بجانب تقرير §103؛ ما انحرف عنها التنفيذُ مُدوَّنٌ في التقرير لا هنا.

# خطةُ الأطوار A–M — Lynomia Work OS

> **القاعدة الحاكمة (من المواصفة §1، §8، §88–90):** وسِّع ← طَبِّع ← اربط ← أمِّن ← أتمِت ← اعرض. لا استبدال، لا تكرار، لا تفتيت. كلُّ قارئٍ/كاتبٍ جديد يمرّ بـ`hub_scope`+`hub_can`+`hub_field_mode`؛ الحرسُ في المتحكّم/الخدمة لا في إخفاء الروابط؛ الأسرارُ مراجعُ `VaultSecret` لا قيَم؛ لا مقاييسَ/أزراراً/توقيعاً/حجبَ USB زائفاً. عربيٌّ أولاً RTL، توافقٌ رجعيّ، Blade القائم.
>
> **الحاجزُ المؤسسيّ في كل دفعة (CLAUDE.md + CI):** `VERSION` يُرفع (minor لميزة، patch لإصلاح) + مدخلُ README + `phpunit` (SQLite) و`phpunit.mysql.xml` (MySQL) خضراوان + `php artisan hub:openapi --out=docs/openapi.json` مُدرَج + `hub:schema-check` + `composer audit`. الأطوار A–M كلُّها تحترم هذا الحاجز في كل WP.
>
> النسخة الأساس: **v2.408.1** · الفرع: `claude/lynomia-hub-enterprise-upgrade-xn4p2t`.

---

## 0) الأساسُ المشترك (Shared Foundation — يُبنى في الطور A، تعتمد عليه كلُّ الأطوار اللاحقة)

هذه الرِّكائز الخمس تُبنى مرةً واحدةً في الطور A ولا تُكرَّر؛ كل طورٍ بعده مستهلكٌ لها لا بانٍ لها:

| الركيزة | ما هي | يمتدّ على | تعتمد عليه الأطوار |
|---|---|---|---|
| **SF-1 حدُّ الحساب internal/client** | عمود `users.account_type` (internal\|client، default internal، مُفهرَس) — المصنِّفُ الصلب؛ لا يُستنتج من `users.clients` غير الفارغة | يمتدّ `users` (core_tables) + `hub_client_ids` (helpers:172) يُعاد تغذيته من العضويات | B, C, D, E, F, G, H, I, J (كلُّ «العميل لا يرى») |
| **SF-2 العضويةُ المطبَّعة للعميل** | جدول `client_memberships` (client_id,user_id,role,status,lifecycle) = مصدرُ الحقيقة لمن ينتمي لأيّ عميل وبأيّ صفة؛ `users.clients` يُشتقّ منه (توافق رجعيّ) | يمتدّ `clients` + `hub_client_ids`/`hub_client_col` (helpers:172,185) | B (البوابة), D (غرف العميل) |
| **SF-3 حاويةُ القناة+العضوية** | جدولا `conversations`(kind/scope/audience/visibility) + `conversation_members`(role/last_read_at) — الحاويةُ المطبَّعة؛ الرسائلُ تبقى في `comments`/`dm_messages` عبر `conversation_id` | يمتدّ `Comment`/`DmMessage` (لا محرّكَ رسائل ثانٍ) | C (القنوات/الرقابة/الأوامر), D (الغرفتان) |
| **SF-4 دلالةُ audience** | تعميمُ `comments.internal` إلى `audience`(internal\|client\|both، default internal) على الحاوية والكيانات المشترَكة — على نمط `policies.audience`/`kb_articles.audience` القائم | يمتدّ `comments.internal` (CommentController:95) + قيَم audience القائمة | B, C, D, F, G (كل ما قد يواجه عميلاً) |
| **SF-5 بدائيّةُ الرقابة + العزل** | `PortalGuard` middleware (client → 404 على الداخليّ) + صلاحيةُ `comms_oversight` (flag) + قراءةٌ رقابيّة عبر `hub_require_stepup`+`hub_audit` بلا لمسِ `read_at`/`read_by` | يمتدّ `hub_require_stepup` (helpers:679) + `audits` (SHA-256 chain) — لا سجلَّ وصولٍ ثانٍ | C (§6), وكلُّ حارسِ بوابة |

**فهارسُ الأساس (تُنشأ في A، تُختبَر على المحرّكين):** `users(account_type)` · `client_memberships` UNIQUE(client_id,user_id)+INDEX(user_id,status)+INDEX(client_id,role) · `conversations(company_id)`,(client_id),(project_id),(kind),(audience),(module,record_id) · `conversation_members` UNIQUE(conversation_id,user_id)+INDEX(user_id) · `comments(conversation_id,created_at)`.

**قاعدةٌ أمنيّةٌ عابرةٌ لكل طور (تُكرَّر عمداً):** العميلُ (account_type=client) لا يرى **أبداً**: قنواتٍ داخلية، بنيةً تقنية، مالية/تكاليف/أرباح، تدقيقاً، أمناً، نقاطاً طرفية، عهدةً مالية — رفضٌ صلبٌ في المتحكّم (404 مفضَّل) فوق مصفوفة الأدوار لا بديلاً عنها؛ لا قيمةَ سرٍّ في أيّ شاشة؛ لا مراقبةَ مستخدم؛ لا توقيع/MDM/حجبَ USB زائف.

---

## الطور A — أساساتُ العزل والقنوات (Isolation & Channels Foundation)

**الهدف:** إرساءُ الرِّكائز الخمس SF-1..SF-5 دون أيّ واجهةِ عميل بعد؛ إغلاقُ ثغرتَي التسرّب القائمتين (feed غير منطَّق، DM غير منطَّق) على السكّة نفسها.

### WP-A.1 — تصنيفُ الحساب الصلب (SF-1)
- **§:** §14. **يمتدّ:** `users` + `hub_client_ids`/`hub_scope` (helpers:141,172). **ينشئ/يعدّل:** migration `..._add_account_type_to_users` (nullable→backfill 'internal'→NOT NULL default 'internal', index)؛ `app/Models/User.php` (accessor `isClientAccount()`)؛ `helpers.php` (يقرأ account_type لا استنتاج JSON).
- **هجرة/فهارس:** إضافيّةٌ محروسة (add-if-not-exists نمط 2026_07_31_000004)؛ INDEX(account_type). **routes:** لا شيء. **settings:** لا شيء. **FlowRunner:** لا شيء.
- **اختبارات-أولاً:** يمتدّ `ClientOperationsTest` — حسابٌ account_type=client بلا عضويات يرى **لا شيء** داخلياً ويُصنَّف صلباً لا استنتاجاً؛ `ColumnFitsItsWriterTest` لعرض العمود على MySQL.
- **يقدّم:** سيناريو §98 «عزلٌ تامّ».

### WP-A.2 — العضويةُ المطبَّعة للعميل (SF-2)
- **§:** §13. **يمتدّ:** `clients` + `hub_client_ids` (helpers:172-180) يُعاد تغذيته من الصفوف الفعّالة. **ينشئ:** migration `client_memberships`(id,client_id,user_id,role enum[owner/lead/technical/finance/viewer],status[invited/active/suspended],invited_by,invited_at,activated_at,softDeletes)؛ `app/Models/ClientMembership.php`؛ تعديل `helpers.php` ليشتقّ `hub_client_ids` من العضويات الفعّالة + `users.clients` يُشتقّ للتوافق.
- **هجرة/فهارس:** UNIQUE(client_id,user_id)+INDEX(user_id,status)+INDEX(client_id,role)+INDEX(client_id,status). **routes:** لا شيء (الإدارة في الطور B). **settings:** لا شيء. **FlowRunner:** يُسجّل الأحداث في الطور B.
- **اختبارات-أولاً:** يمتدّ `CompanyIsolationTest`+`ClientOperationsTest:122-153` — `hub_client_ids` من العضويات = سلوكُ `users.clients` السابق للحسابات المُرحَّلة (توافق رجعيّ)؛ على المحرّكين.
- **يقدّم:** §98 «عضويّةٌ متعددة».

### WP-A.3 — حارسُ البوابة PortalGuard (SF-5 جزء ١)
- **§:** §14. **يمتدّ:** مجموعةُ `auth` middleware (web.php:108) + سلوكُ 404 في `findScoped()->findOrFail` (ModuleController:1120). **ينشئ:** `app/Http/Middleware/PortalGuard.php` (إن account_type=client والمسارُ داخليّ → 404، وإلى /portal)؛ تسجيلٌ في bootstrap/app.php؛ رفضٌ صلبٌ للوحدات internal-only (servers/audit/security/costs/fin/endpoints) بمعزلٍ عن المصفوفة.
- **routes:** لا مساراتٍ جديدة؛ يلفّ القائمة. **الجمهور:** يفرض internal/client. **settings:** لا شيء. **FlowRunner:** لا شيء.
- **اختبارات-أولاً:** يمتدّ `ClientOperationsTest` — حساب عميل على /m/servers, /audit, /costs, /m/fin → 404؛ ودورٌ يمنح هذه الوحدات (misconfig) **لا يُسرِّب** (الحارس يفوق المصفوفة).
- **يقدّم:** §98/§14 «فشلُ العميل على المسارات الداخلية».

### WP-A.4 — حاويةُ القناة والعضوية (SF-3)
- **§:** §3–5. **يمتدّ:** `Comment`/`DmMessage` (محرّكُ الرسائل الوحيد). **ينشئ:** migration `conversations`(uuid,kind,company_id,client_id,project_id,module,record_id,audience default internal,visibility,title,archived_at,created_by,softDeletes) + `conversation_members`(conversation_id,user_id,role,source,last_read_at,muted)؛ migration إضافيّ `add_conversation_id_to_comments`(nullable,index with created_at)؛ `app/Models/Conversation.php`+`ConversationMember.php`. **لا** جدولَ رسائلَ ثانٍ.
- **هجرة/فهارس:** كلُّ فهارس الأساس أعلاه؛ `dm_messages` يكتسب `conversation_id`+`audience`+`client_id` (nullable) لاحقاً في C. **routes:** لا واجهةً بعد. **settings:** `collab.oversight_role` (اسم الدور الحامل لـcomms_oversight). **FlowRunner:** يُعرَّف `conversation_created` في config('hub.events') (8516) — يُطلَق في C.
- **اختبارات-أولاً:** يمتدّ `ColumnFitsItsWriterTest`+`SearchDmLeakTest` — عرضُ الأعمدة على MySQL؛ UNIQUE(conversation_id,user_id) يُنفَّذ؛ audience default='internal' مُطبَّق.
- **يقدّم:** أساسُ C/D.

### WP-A.5 — إغلاقُ تسرّب feed وDM على السكّة (SF-4/SF-5 جزء ٢)
- **§:** §5a, §6/§74. **يمتدّ:** `CommentController@feed` (25، بلا نطاق) + `DmController` (بلا نطاق) — يصبح feed **قناةً واحدة** kind='feed' بـcompany scope+audience=internal؛ DM يكتسب company_id/client_id/audience + حارسَ نطاق. **يعدّل:** `CommentController`,`DmController`,`DmMessage`.
- **routes:** القائمة (`feed`,`dm.*`) تُبقى؛ تُحرَس داخلياً. **الجمهور:** internal فقط لـfeed؛ DM موسومٌ بالنطاق. **settings:** لا شيء. **FlowRunner:** لا شيء.
- **اختبارات-أولاً:** يمتدّ `CompanyIsolationTest`+`SearchDmLeakTest` — مستخدمو شركتين لا يرون feed بعضهما؛ عميلٌ لا يقرأ feed داخلياً (404)؛ عميلٌ لا يُعدِّد/يراسل مستخدماً داخلياً؛ على المحرّكين.
- **يقدّم:** §98 «عزلٌ تامّ» على طبقة الرسائل.

**قواعدُ أمنٍ للطور A:** لا واجهةَ عميلٍ بعد؛ الحدُّ الصلب account_type يُختبَر عدائياً قبل بناء أيّ سطحِ عميل؛ feed/DM لا يُشرَّعان لعميلٍ بأيّ حال؛ العضويةُ الرقابيّة (comms_oversight) تُعرَّف كـflag قابلٍ للمنح لدورٍ غير المالك (لا بابٍ خفيّ).

---

## الطور B — مساحةُ عمل العميل (Client Workspace & Portal)

**الهدف:** بوابةُ عميلٍ أبسطُ عمداً فوق SF-1..SF-4؛ تفعيلٌ آمن؛ توفيرُ مساحةٍ آليّ عند قبول العرض.

### WP-B.1 — تفعيلُ الحساب الآمن (email→OTP→كلمةُ سرٍّ ذاتية)
- **§:** §12. **يمتدّ:** نمطُ OTP في `EsignController:811-819,1134-1142` (hash+expire+OutboxMessage+single-use) + `Totp::verifyOnce` + `password_rules()` (helpers:54) + سلسلةُ تدقيق الدخول (AuthController:170). **ينشئ:** migration `account_activations`(user_id,email,token_hash unique,otp_hash,otp_expires_at,consumed_at,ip)؛ `ActivationController`؛ `resources/views/auth/activate*.blade.php`.
- **هجرة/فهارس:** UNIQUE(token_hash)+INDEX(user_id)+INDEX(otp_expires_at). **routes:** `activate.show/otp/set` داخل throttle (نمط login throttle web.php:78)؛ **الجمهور:** ما قبل المصادقة، برموزٍ لمرّة. **settings:** `portal.activation_ttl_min`. **FlowRunner:** `client_account_activated`.
- **اختبارات-أولاً:** يمتدّ اختباراتِ OTP في e-sign — لا كلمةَ سرٍّ تُخزَّن/تُرسَل قط (تأكيدٌ على OutboxMessage.text)؛ OTP single-use/منتهٍ/مقيَّدُ المعدّل مرفوض؛ كلمةُ السرّ النهائية تحترم `password_rules()`.
- **يقدّم:** §98 «تفعيلٌ آمن».

### WP-B.2 — مساحةُ العميل (شل منفصل، أبسط)
- **§:** §13/§82–86. **يمتدّ:** بدائيّاتُ layouts RTL/dark/PWA + `partials/cc/*` + نمطُ `PortalController::bundle` (لكن شلّ عميلٍ منفصل، بلا `hub_top_links` الداخلية). **ينشئ:** `ClientPortalController` + `resources/views/portal/client/**` (home/engagements/projects read-only/shared docs/conversations/invoices).
- **routes:** مجموعةٌ `/portal/*` خلف PortalGuard؛ **الجمهور:** client فقط. **settings:** لا شيء. **FlowRunner:** لا شيء.
- **اختبارات-أولاً:** يمتدّ `ClientOperationsTest`+`ReaderScopeLeaksTest` — عميلٌ بلا بيانات يرى «لا بيانات بعد» صادقاً (لا تلفيق §82)؛ عميل A لا يصل مشروع/وثيقة/مرفق B (404).
- **يقدّم:** §98 «دعوةُ زملاء/رسائل» (الواجهة).

### WP-B.3 — لوحةُ العضوية على عميل 360 + الدعوات
- **§:** §13/§98. **يمتدّ:** وحدةُ `clients` القائمة (config:3898) — تُوسَّع صفحةُ العرض بلوحة عضويّة؛ `hub_admin_links`. **ينشئ:** لوحةُ إدارة العضوية (منح دور/دعوة تفعيل)؛ يُعيد استخدام `partials/cc/*`.
- **routes:** `clients.members.*` داخلياً؛ **الجمهور:** internal (مدير الحساب)؛ منحُ Client Owner/سحبُ وصولٍ = `hub_require_stepup`. **settings:** لا شيء. **FlowRunner:** `client_membership_granted`/`client_membership_revoked` (يدخل سلسلةَ التدقيق).
- **اختبارات-أولاً:** يمتدّ `FieldPermissionBypassTest` — Client Finance يرى الفواتير لا التكاليف/الهوامش؛ Viewer للقراءة؛ Owner يدعو؛ كلٌّ بمستخدمٍ معزول.
- **يقدّم:** §98 «دعوةُ زملاء».

### WP-B.4 — التوفيرُ الآليّ لمساحة العميل عند القبول
- **§:** §63/§98. **يمتدّ:** **داخلَ** معاملة `QuoteController::toProject` القائمة (368-461، lockForUpdate + حارسُ meta.project_id) — تُضاف خطوةُ إنشاء عضويّةِ Owner + إصدارِ تفعيلٍ + حدثٍ، بلا مسارٍ ثانٍ. **يعدّل:** `QuoteController`.
- **هجرة/فهارس:** لا شيء جديد (يستهلك client_memberships). **routes:** لا شيء. **settings:** لا شيء. **FlowRunner:** `client_workspace_created` (config:8516) داخل المعاملة نفسها.
- **اختبارات-أولاً:** يمتدّ `TenancyLeakRound8Test`+idempotency — قبولان متزامنان → عضويّةٌ واحدة/مساحةٌ واحدة (لا تكرار)؛ الحدث يُطلَق مرةً.
- **يقدّم:** §98 «توفيرٌ آليّ».

**قواعدُ أمنٍ للطور B:** لا كلمةَ سرٍّ تُرسَل/تُخزَّن؛ audience الافتراضيّ داخليّ فالوثيقةُ بلا audience غيرُ مرئيّةٍ للعميل؛ شلُّ العميل لا يعرض `hub_nav`/`hub_top_links` الداخلية؛ كلُّ قراءةِ عميلٍ تمرّ `hub_scope` (البوابةُ قارئٌ أصرمُ لا سكّةٌ ثانية)؛ الأسرار/التكاليف محجوبةٌ بـ`hub_field_mode`+audience.

---

## الطور C — التعاون (Collaboration: Channels · Oversight · Commands)

**الهدف:** رفعُ الرسائل إلى طبقةٍ أولى فوق SF-3: قنواتٌ = `Comment.conversation_id`؛ رقابةُ امتثالٍ ظاهرة؛ أوامرُ محادثةٍ حقيقية.

### WP-C.1 — القنواتُ والفضاءات فوق Comment
- **§:** §3–5, §7. **يمتدّ:** `Comment` (رسالةُ القناة = تعليقٌ بـconversation_id)؛ يُعاد استخدام mentions/reactions/read/pin/resolve/att/task-link كما هي؛ `reactions` القائم (لا جدولَ ثانٍ). **ينشئ:** `ConversationController` (+`guardConversation()` على نمط `guardTarget` CommentController:279)؛ `resources/views/conversations/*` تُعيد استخدام `partials/comments.blade.php`.
- **هجرة/فهارس:** تُستهلَك فهارس A. **routes:** `conversations.index/show/store/member.*`؛ **الحرس:** `hub_scope`+`hub_can`+عضويّة `conversation_members`؛ **الجمهور:** internal افتراضاً، وقنواتُ audience=client مرئيّةٌ للعميل عبر البوابة. **settings:** لا شيء. **FlowRunner:** `conversation_created`.
- **اختبارات-أولاً:** يمتدّ `ClientOperationsTest`+`SearchDmLeakTest` — غيرُ العضو لا يقرأ قناةً خاصة (403/404)؛ حدودُ owner/moderator/member/guest؛ ردٌّ لا يُحقَن في قناةٍ لا يراها القارئ (يمتدّ CommentController:78).
- **يقدّم:** §98 «رسائل».

### WP-C.2 — رقابةُ الاتصالات (§6) — ميزةُ امتثالٍ ظاهرة
- **§:** §6. **يمتدّ:** `hub_require_stepup` (helpers:679) + `StepUp::fresh` + `hub_audit` (helpers:2692) — سجلُّ الوصول هو سلسلةُ التدقيق (لا جدولَ وصولٍ ثانٍ). **ينشئ:** قارئٌ رقابيٌّ للقراءة فقط لا يلمس `read_at`/`read_by`؛ نافذةُ سببٍ إلزاميّ؛ لوحةُ رقابةٍ تُعيد استخدام `partials/cc/{tabs,kpis,findings}` بلافتة «وصولُ امتثالٍ — مُسجَّل».
- **routes:** `oversight.index/show`؛ **الحرس:** flag `comms_oversight`+`hub_require_stepup`+سبب؛ **الجمهور:** internal مخوَّلٌ (دورٌ غير المالك). **settings:** `collab.oversight_role`. **FlowRunner:** لا حدثٌ جديد (يُكتفى بأثر hub_audit).
- **اختبارات-أولاً:** يمتدّ `AuditScopeLeakTest`+`FieldPermissionBypassTest` — طلبٌ بلا step-up → 428/تحويل؛ بلا سبب → مرفوض؛ القراءةُ الرقابيّة **لا** تغيّر read_at؛ لا تحرير/حذفَ رسالةِ غيره (403)؛ صفُّ audit فيه viewer/reason/record_id=conversation/ip/request_id — على المحرّكين ولمحرّكَي الرسائل (Comment+DM).
- **يقدّم:** §101/§6 (باب الامتثال الظاهر).

### WP-C.3 — أوامرُ المحادثة (/task /issue /assign)
- **§:** §8. **يمتدّ:** نمطُ `CommentController@toTask:154-202` (خدمةٌ حقيقية + `hub_can(op)` على الوحدة الهدف + `guardTarget` نطاق + back-link). **ينشئ:** مُحلِّل+موزِّع أوامرَ يستدعي خدماتِ Task/Issue/Assign الحقيقية، يكتب `hub_audit`، يُطلق `HubEvents`، ويرفض المجهول/غير المصرّح صراحةً.
- **routes:** ضمن `comments.store`/`conversations.store`؛ **الحرس:** `hub_can(op)`+`hub_scope` على السجل الهدف. **settings:** لا شيء. **FlowRunner:** حدثُ الوحدة الهدف (task/issue) القائم.
- **اختبارات-أولاً:** يمتدّ `FieldPermissionBypassTest` — `/task` يُنشئ Task حقيقياً بـhub_can('tasks','a')+نطاق؛ غيرُ مصرّحٍ → 403؛ أمرٌ مجهول → رفضٌ صريح (لا صامت/زائف)؛ back-link مضبوط؛ مثله لـ/issue,/assign.
- **يقدّم:** §98/§8 (لا أوامرَ زائفة).

### WP-C.4 — طيُّ DM في الحاوية (هويّةٌ واحدة)
- **§:** §6/§7. **يمتدّ:** `DmMessage` — يُسجَّل صفُّ conversation kind='dm' مطابق (thread_key→conversation_id) فتصبح العضويّة/الرقابة/البحث نظاماً واحداً. **يعدّل:** `DmController`,`DmMessage` (يكتسب conversation_id/company_id/client_id/audience من A.5).
- **routes:** القائمة تُبقى. **settings:** لا شيء. **FlowRunner:** لا شيء.
- **اختبارات-أولاً:** يمتدّ `SearchDmLeakTest` — الرقابةُ تكتشف DM عبر الحاوية موحّدةً؛ لا هويّتا حاويةٍ (thread_key وrow).
- **يقدّم:** §98/§6.

**قواعدُ أمنٍ للطور C:** رقابةٌ = ميزةٌ ظاهرةٌ مُدقَّقة لا بابٌ خفيّ، لا تغيّر حالةَ القراءة، لا تحرّر رسالةَ غيرها؛ الأوامرُ تُنفَّذ بعد hub_can+نطاق على الهدف وتُدقَّق وتُربَط؛ مرفقاتُ القنوات عبر بوّابة `Attachment`/`hub_upload_cap`/av-scan؛ لا كشفَ VaultSecret في رسالة/أمر؛ البحثُ العالميّ للقنوات مُرشَّحٌ بالعضوية+النطاق على الخادم (لا تسرّبَ فهرس).

---

## الطور D — مركزُ التسليم (Client Delivery / PSA)

**الهدف:** غرفتان فيزيائياً منفصلتان لكل مشروعٍ خارجيّ؛ مركزُ قيادةِ مشروعٍ بتبويبات؛ لوحةُ PSA تشغيليّة — كلُّه تجميعٌ فوق سكّة العرض→المشروع القائمة.

### WP-D.1 — الغرفتان (داخلية/عميل) لكل مشروع خارجيّ
- **§:** §9. **يمتدّ:** حاويةُ C — صفّا conversation (audience=internal + audience=client) بـproject_id (توصيةُ الحزمة: صفّان لا audience لكل رسالة، لمنع تسرّبِ رسالة). **يعدّل:** صفحةُ المشروع (`modules/custom/projects.blade.php`) لعرض لوحتَي غرفة؛ الغرفةُ العميليّة تُرشَّح audience=client.
- **هجرة/فهارس:** `projects` يكتسب `audience`(default internal)+`source_quote_id`(index). **routes:** عبر conversations؛ **الجمهور:** الغرفةُ الداخلية internal، غرفةُ العميل client. **settings:** لا شيء. **FlowRunner:** لا شيء.
- **اختبارات-أولاً:** يمتدّ `ClientOperationsTest` — تعليقٌ داخليٌّ لا يظهر في الغرفة العميليّة؛ عميلٌ ينشر في الداخلية → رفض؛ `hub_related` أبناءُ المشروع مُرشَّحون audience للعميل (سدُّ ثغرة helpers:3104).
- **يقدّم:** §98 «غرفةٌ داخلية منفصلة».

### WP-D.2 — مركزُ قيادةِ المشروع (تبويبات)
- **§:** §11. **يمتدّ:** `modules/show.blade.php`+`custom/projects.blade.php` — تُحوَّل البطاقاتُ المسطّحة إلى `partials/cc/tabs` (overview/delivery/commercial-baseline/rooms/finance/activity)؛ تجميعٌ لا تكرار. **يعدّل:** بلادات المشروع.
- **routes:** `m.show projects` القائم. **settings:** لا شيء. **FlowRunner:** لا شيء.
- **اختبارات-أولاً:** يمتدّ `FieldPermissionBypassTest` — التكلفة/الهامش/الميزانية/cost_delta محجوبةٌ للعميل عبر hub_field_mode؛ url/staging/git لا تُعرَض في غرفة العميل.
- **يقدّم:** §11 (مركزُ القيادة).

### WP-D.3 — لوحةُ PSA التشغيليّة
- **§:** §17. **يمتدّ:** `hub_project_health`/`hub_project_pl` (helpers:2253,2106) + `ExecutionStats`/`ActionCenter` + `quote_milestones` (reached-not-invoiced) — تُقرأ لا تُعاد صياغتها. **ينشئ:** لوحةُ `delivery.psa` بدلاءِ (نشطة/في خطر<55/تنتظر العميل/محجوب داخلياً/معالمُ مستحقة).
- **هجرة/فهارس:** `projects` يكتسب `hold_reason`/`blocked` (إشارةٌ إضافيّةٌ لا حالةٌ جديدةٌ تكسر kanban)؛ INDEX(client_id,status),(audience,status). **routes:** `delivery.psa` داخلياً. **settings:** لا شيء. **FlowRunner:** لا شيء.
- **اختبارات-أولاً:** يمتدّ `CompanyIsolationTest` — ازدواجُ الفوترة على المعالم محجوب (regression)؛ ترتيبٌ حتميّ (id لا created_at)، تأكيدٌ على كل الصفوف.
- **يقدّم:** §17 (العروض التشغيلية).

### WP-D.4 — حدثُ التوفير project.provisioned
- **§:** §63–73. **يمتدّ:** معاملةُ `toProject` — يُضاف `project.provisioned` بجانب `quote.converted` القائم. **يعدّل:** config('hub.events'):8516.
- **FlowRunner:** `project.provisioned` داخل المعاملة. **اختبارات-أولاً:** يمتدّ idempotency — الحدثُ مرةً واحدة.
- **يقدّم:** §98 «تحويلٌ لعمل».

**قواعدُ أمنٍ للطور D:** الغرفتان فيزيائياً منفصلتان (صفّان)، لا audience لكل رسالة؛ التوفيرُ داخلَ معاملةٍ واحدةٍ مقفلةٍ idempotent؛ التكاليف/الهوامش/البنية محجوبةٌ عن غرفة العميل بـhub_field_mode + بنية Proposal/ChangeOrderDoc القائمة؛ ChangeOrder يبقى المسارَ الوحيد لتطوّر الأساس.

---

## الطور E — العهدةُ المالية (Financial Custody Wallet)

**الهدف:** محفظةُ موظفٍ مشتقّةُ الرصيد من حركاتٍ ثابتة، تُرحِّل في دفتر Lynomia القائم، منفصلةٌ عن عهدة الأصول.

### WP-E.1 — دفترُ الحركات الثابت employee_custody_moves
- **§:** §18–20. **يمتدّ:** نمطُ `StockMove`/`JournalEntry::booted` (StockMove:33-77 immutable/reverse-only/derived) + `Employee` (accessor رصيدٍ مشتقّ، لا عمودَ رصيد). **ينشئ:** migration `employee_custody_moves`(employee_id,company_id,kind[10 أنواع varchar واسع],amount decimal(16,3),sign,source_module,source_id,entry_id,reverses_id,approval_state,receipt(module,record_id),posted_at,by_id,at,meta؛ **بلا** softDeletes/عمودِ رصيد)؛ `EmployeeCustodyMove` (Auditable).
- **هجرة/فهارس:** INDEX(employee_id,at,id) [ترتيبٌ حتميّ]، (company_id)، UNIQUE(source_module,source_id,kind) [منعُ ترحيلٍ مزدوج نمط 2026_09_02_000001]، (entry_id)،(reverses_id)،(project_id)،(cc_id). **routes:** في E.3. **settings:** لا شيء بعد. **FlowRunner:** في E.3.
- **اختبارات-أولاً:** يمتدّ `LedgerAndStockIntegrityTest` — الرصيد = SUM(signed)؛ صفٌّ مُرحَّل يرفض update/delete (422)؛ عرضُ kind يسع الأنواع العشرة على MySQL (`ColumnFitsItsWriterTest`)؛ decimal(16,3) ضمن المدى.
- **يقدّم:** §102 «رصيد/لا تحرير صامت/عكس».

### WP-E.2 — خدمةُ الترحيل المشترَكة (لا دفترَ ثانٍ)
- **§:** §21a/b. **يمتدّ:** نمطُ `JournalEntry::$posting` المُكرَّر في `FinController::autoJournal`+`PayrollController::autoJournal` — يُستخرَج إلى خدمةِ ترحيلٍ واحدةٍ تعيد استخدامها العهدةُ (لا نسخةً ثالثة). **ينشئ:** `CustodyPostingService`؛ إضافةُ رمز `custody` إلى خريطة `finance.accounts` (Settings:68).
- **settings:** يُوسَّع map (إضافيّ) + بوابةُ `finance.auto_journal` القائمة. **FlowRunner:** `custody.charged`/`custody.approved`/`custody.reversed` (config:8516).
- **اختبارات-أولاً:** يمتدّ `PayrollJournalTest`+`FinalAuditLedgerCompanyTest` — الترحيلُ ينتج قيداً متوازناً مقفلاً بـmeta.custody_move_id عبر رمز custody؛ اعتمادُ المصروف مرتين → حركةٌ واحدة+قيدٌ واحد (UNIQUE)؛ نقرٌ متزامن مُسلسَلٌ بـlockForUpdate.
- **يقدّم:** §102 «ترحيلٌ مرةً واحدة».

### WP-E.3 — دورةُ الحياة + تبويبُ العهدة في الموظف 360
- **§:** §19/§28/§98. **يمتدّ:** `Approval`+`Attachment`(module,record_id) للإيصال + `hub_require_stepup`؛ `Employee` 360. **ينشئ:** نماذجُ الحركات (سلفة/شحن مموَّلٌ من بنكٍ عبر سكّة FinController:72-92/مصروفٌ باعتماد+إيصال/سداد/تحويل/خصم يصالِح PayrollLine.advance/تسوية/تصحيح step-up/عكس step-up)؛ مركزُ عهدةٍ + تبويبُ 360 بـ`partials/cc/*`+`hub_field_mode`.
- **routes:** `custody.*` داخلياً؛ **الحرس:** `hub_can('custody',v/e/approve)`+`hub_scope`؛ العكس/التصحيح `hub_require_stepup`؛ **الجمهور:** internal فقط (عميلٌ → 404). **settings:** (اختياري) توحيدُ التوقيت مع finance.auto_journal.
- **اختبارات-أولاً:** يمتدّ `CompanyIsolationTest`+`FieldPermissionBypassTest` — عزلُ شركة (IDOR)؛ عميلٌ → 404 على كل مسار؛ العكسُ يُنشئ صفاً معاكساً+قيداً معاكساً، والعكسُ المزدوج محجوب؛ لا عمودَ رصيدٍ للتحرير.
- **يقدّم:** §102 كاملاً.

**قواعدُ أمنٍ للطور E:** لا رصيدٌ محرَّر (SUM فقط)؛ لا حذفَ — عكوسٌ بـstep-up؛ لا دفترَ محاسبيّ موازٍ (كلُّ أثرٍ عبر JournalEntry)؛ عزلُ شركة + عميلٌ ممنوعٌ كليّاً؛ الإيصالُ عبر Attachment مُنطَّق؛ لا كشفَ IBAN/سرٍّ خارج field-mode.

---

## الطور F — المحطاتُ والأصول (Stations · Asset Upgrade · Inventory · 360)

**الهدف:** Station = المقعدُ الدائم؛ الأصلُ يُسنَد لموظفٍ أو محطة؛ جلساتُ جرد؛ إعادةُ هيكلة الموظف 360 إلى تبويبات.

### WP-F.1 — وحدةُ Station مُدارةٌ بالبيانات + تاريخُ الإسناد
- **§:** §25–27. **يمتدّ:** `ModuleController` + سجل config (CRUD/scope مجّاناً) + `Asset::nextCode` (توليدُ الرمز) + `Identity::attach`/`Qr::svg` (لا محرّكَ QR ثانٍ)؛ **لا** يُعاد استخدام `facilities` الصحّية. **ينشئ:** migration `stations`(code unique,company_id,project_id,facility/floor/zone/room/desk,type,dept,status,current_employee_id,softDeletes) + وحدةُ 'stations' في config/hub.php؛ migration `station_assignments`(نمط asset_custody: station_id,user_id,action,at,by_id,softDeletes)؛ `StationController::assign/vacate` (نمط `Custody::move` DB::transaction).
- **هجرة/فهارس:** UNIQUE(code)،INDEX(company_id/status/type/dept/current_employee_id)؛ station_assignments INDEX(station_id,at)+tie-break id،(user_id). **routes:** `m.* stations` + `s/{code}` (نمط `custody.code` byCode، داخل auth)؛ **الحرس:** `hub_can('stations',v)`+scoped→404؛ **الجمهور:** internal فقط (بلا client_id). **settings:** لا شيء. **FlowRunner:** `station.assigned`/`station.vacated`.
- **اختبارات-أولاً:** يمتدّ `CompanyIsolationTest` — رمزٌ فريدٌ مولَّدٌ بإعادةِ محاولةٍ عند التصادم؛ `s/{code}` ضيفٌ→دخول، خارجَ الشركة→404؛ إسناد/إخلاء يكتب صفاً ويحدّث current_employee_id؛ التاريخُ يبقى بعد مغادرة الموظف.
- **يقدّم:** §99 «إنشاء→QR→إسناد→٣٦٠→مغادرة→بقاءُ التاريخ».

### WP-F.2 — ترقيةُ الأصل: الإسنادُ لمحطةٍ أو موظف + الحالاتُ الجديدة
- **§:** §29–31. **يمتدّ:** `assets` + `Custody::move` — عمودٌ منفصلٌ `station_id` (لا يُحمَّل holder_id)؛ توسيعُ قائمة `status` (config:4586) للحالات الـ11 (اسمٌ مقفلٌ locked يُكتَب عبر Custody فقط)؛ `asset_custody` يكتسب station_id. **يعدّل:** config assets fields، `CustodyController` handover.
- **هجرة/فهارس:** `assets.station_id`(index)،`asset_custody.station_id`(index)؛ الحالاتُ config فقط (العمود string(80) واسعٌ أصلاً)؛ إبقاءُ القيم الخمس القديمة aliases (توافق رجعيّ §86). **routes:** القائمة. **settings:** لا شيء. **FlowRunner:** أحداثُ الأصل القائمة.
- **اختبارات-أولاً:** يمتدّ `LedgerAndStockIntegrityTest` — أصلٌ يُسنَد لمحطةٍ أو موظفٍ (لا إجبار)؛ رمزُ QR محفوظٌ عبر النقل؛ الحالاتُ الجديدة تُقبَل والقديمة تعمل.
- **يقدّم:** §99 «أصول/SIM على المحطة».

### WP-F.3 — جلساتُ الجرد
- **§:** §32. **يمتدّ:** `Identity::resolve` (رمز→أصل مُنطَّق، لا محلِّلَ ثانٍ) + `Auditable`/by_id للماسِح؛ **لا** خلطٌ مع stock_moves 'جرد'. **ينشئ:** migrations `inventory_sessions`+`inventory_items`(لقطةٌ مجمَّدة)+`inventory_scans`؛ `InventoryController`(freeze/scan/reconcile)+views.
- **هجرة/فهارس:** sessions(company_id,status)؛ items UNIQUE(session_id,asset_id)+INDEX(session_id,verdict)؛ scans(session_id,result),(asset_id). **routes:** `inventory.*`؛ **الحرس:** hub_can+scope؛ الإغلاقُ/المصالحةُ الكتابيّة `hub_require_stepup`؛ **الجمهور:** internal. **settings:** لا شيء. **FlowRunner:** `inventory.session_closed`.
- **اختبارات-أولاً:** يمتدّ `CompanyIsolationTest` — التجميدُ لقطةٌ ثابتة؛ تصنيفُ الفروق (موجود/مفقود/غير متوقع/انتقل)؛ مسحُ أصلٍ خارج الشركة → «غير معروف» لا تسريب؛ كلُّ مسحٍ يختم by_id.
- **يقدّم:** §99 «مسحٌ بمصادقة».

### WP-F.4 — إعادةُ هيكلة الموظف 360 إلى تبويبات (§28)
- **§:** §28. **يمتدّ:** `PortalController::bundle/workProfile` + `partials/cc/tabs` — البطاقاتُ المكدَّسة → تبويباتٌ مُحرَسةٌ خادمياً (hub_can/hub_field_mode)؛ إضافةُ تبويب Station. **يعدّل:** `portal/employee.blade.php`,`_hr.blade.php`. تبويباتُ Telecom/FinancialCustody/Systems/EndpointSecurity **تُوصَل عند وصولِ سككها** (لا بطاقاتٍ زائفة §82).
- **routes:** القائمة. **اختبارات-أولاً:** يمتدّ `FieldPermissionBypassTest` — دورُ HR لا يرى أسراراً تقنية والتقنيّ لا يرى الراتب؛ GET مباشرٌ لتبويبٍ بلا hub_can → 403.
- **يقدّم:** §28 (الأساس التبويبيّ).

**قواعدُ أمنٍ للطور F:** المحطاتُ internal فقط (بلا client_id، ممنوعةٌ لأدوار العميل)؛ QR يتطلب دخولاً (لا كشفَ عامّ)؛ station_id/asset_custody يُكتَبان عبر Custody فقط (locked) فيُدقَّق كلُّ تغييرِ مقعد؛ المسحُ يختم الماسِح ويحلّ عبر resolver مُنطَّق؛ حجبُ IP/MAC/IMEI بـfield-mode.

---

## الطور G — الاتصالات (Telecom · SIM · Carrier Registry)

**الهدف:** تطويرُ `PhoneNumber` إلى أصل الاتصالات (لا جدولَ SIM ثانٍ) + سجلُّ مزوّدين قابلٌ للضبط.

### WP-G.1 — حقولُ هويّة SIM/eSIM والخطة ودورةُ الحياة
- **§:** §22/§24. **يمتدّ:** `phone_numbers` + وحدةُ 'phones' (config:1614) + المحرّكُ العامّ (لا متحكّمَ اتصالاتٍ خاص). **يعدّل:** migration إضافيّةٌ تضيف line_type,iccid,imsi,msisdn,apn,pin/puk (**type 'sec'**),plan_name/data/voice/sms,roaming,billing_cycle,activated_at,deactivated_at؛ توسيعُ status؛ config phones fields؛ `PhoneNumber::saved` يربط ICCID/MSISDN بـ`Identity::attach`.
- **هجرة/فهارس:** UNIQUE(iccid)؛ INDEX(line_type)؛ (status,expiry) لقواعد التنبيه. **routes:** القائمة (m.*+api/v1). **settings:** لا شيء. **FlowRunner:** يُعاد استخدام تدفّقات الانتهاء القائمة (HubFlowsStarter:228/HubAlertsStarter:118).
- **اختبارات-أولاً:** يمتدّ `CompanyIsolationTest`+`FieldPermissionBypassTest` — PIN/PUK لا يظهران في HTML/CSV (masked ••••)+revealSecret يكتب «عرض حساس»؛ UNIQUE(iccid) على المحرّكين؛ الاتصالاتُ internal (عميلٌ→403/404).
- **يقدّم:** §22–24 (الأصل + دورةُ الحياة).

### WP-G.2 — سجلُّ المزوّدين + روابطُ الموظف/الجهاز/المحطة
- **§:** §22/§23/§28. **يمتدّ:** آليّةُ `ref` + `hub_children`/`hub_related` (تُضيء تبويبَ الاتصالات في الموظف 360 تلقائياً). **ينشئ:** وحدةُ config 'carriers' + جدول `carriers`(اعتماداتُ البوّابة = مراجعُ VaultSecret) على المحرّك العامّ (لا CarrierController)؛ إضافةُ carrier_id/employee_id(→hr)/device_id(→assets)/client_id + station_id(→stations، أُضيف بعد F).
- **هجرة/فهارس:** INDEX(carrier_id/employee_id/device_id/client_id). **routes:** `m.* carriers` داخلياً. **settings:** لا شيء. **FlowRunner:** لا شيء.
- **اختبارات-أولاً:** يمتدّ `FieldPermissionBypassTest` — خياراتُ carrier_id مُنطَّقةٌ بالشركة؛ تبويبُ الاتصالات في الموظف 360 يُضيء عبر `hub_related` (نطاقٌ لكل ابن)؛ ترتيبٌ حتميّ.
- **يقدّم:** §23 + §28 (تبويبُ Telecom).

**قواعدُ أمنٍ للطور G:** الاتصالاتُ بنيةٌ داخلية (عميلٌ ممنوع)؛ PIN/PUK/كلماتُ بوّابةٍ = 'sec'/VaultSecret لا تُطبَع؛ كشفُ PUK/تصديرُ ICCID الجماعيّ = step-up؛ ICCID/MSISDN عبر Identity الموحّد لا لوكَبٍ ثانٍ.

> **مؤجَّلٌ صراحةً (G):** التوفيرُ الحيّ عبر **APIs المشغّلين** (تفعيل/تعليق SIM حقيقيّ) — يتطلب اتفاقياتِ وواجهاتِ المشغّل؛ حتى ذلك السجلُّ config-only بلا توفيرٍ خارجيّ حيّ.

---

## الطور H — مستكشفُ العلاقات + مساحةُ العمل التقنية (§33–38)

**الهدف:** إسقاطٌ فوق النماذج القائمة (لا شجرةَ مخزّنة) بترشيحٍ خادميٍّ كامل؛ مساحةُ عملٍ تقنية داخليّةٌ تجمع الوحدات القائمة.

### WP-H.1 — حوافُ البنية نحو المحطة/الأصل/الموظف
- **§:** §37. **يمتدّ:** `servers` + آليّةُ `ref` (يلتقطها `hub_build_children_map` تلقائياً، helpers:1047-1064) — لا جدولَ حوافٍ ثانٍ. **يعدّل:** migration إضافيّةٌ تضيف servers.station_id/asset_id/hr_id(nullable,index نمط 2026_07_31_000004) + إعلانُها ref في config.
- **هجرة/فهارس:** INDEX(station_id/asset_id/hr_id). **routes:** لا شيء. **settings:** لا شيء. **FlowRunner:** لا شيء.
- **اختبارات-أولاً:** يمتدّ `AuditScopeLeakTest` — حافةٌ تظهر مُنطَّقةً فقط لمن يملك وحدتَي طرفَيها.
- **يقدّم:** الجرافَ المؤسسيّ (§5).

### WP-H.2 — خدمةُ الإسقاط متعددةُ القفزات + المستكشف
- **§:** §33–36. **يمتدّ:** `hub_children`/`hub_related` (نطاقٌ+صلاحيةٌ مفروضان بالبناء helpers:3104) + `hub_scope_key` للمخبَّأ + `SavedView` (لا جدولَ graph_views). **ينشئ:** `RelationshipProjection` (توسّعٌ تدريجيٌّ يمرّ كل عقدة/حافّة/عدّاد عبر `hub_read`) + `RelationshipExplorerController` + `graph/explore.blade.php` (وضعان: شجرة `<ul>` دلاليّ = البديل النصّيّ + جراف بمكتبةٍ **محليّةٍ تحت public/vendor** لا CDN) + زرّ «افتح في المستكشف» على `modules/show`.
- **routes:** `graph.explore`,`graph.expand`؛ **الحرس:** hub_read لكل عقدة؛ **الجمهور:** internal (رفضٌ صلبٌ لـhub_client_ids()!==null). **settings:** `graph.max_nodes`,`graph.max_hops`. **FlowRunner:** لا شيء.
- **اختبارات-أولاً:** يمتدّ `ReaderScopeLeaksTest`+`SearchDmLeakTest` — لا عقدة/حافّة خارج النطاق، العدّادُ = المُرشَّح لا الخام؛ توسّعٌ متعدّدُ القفزات لا يعبر حدَّ شركة/عميل عبر عقدةٍ وسيطة؛ لا CDN؛ عزلُ المخبَّأ بـhub_scope_key.
- **يقدّم:** §33–36 + «روابطُ علاقاتٍ من كل صفحة» (§63).

### WP-H.3 — مساحةُ العمل التقنية (§37–38)
- **§:** §37–38. **يمتدّ:** وحداتُ البنية القائمة (servers/vault/dbs/domains…) + تحليلاتُ `DigitalAssets` (blastRadius/vaultHealth) + `partials/cc/*` — **تُوسَّع** صفحةُ `/w/digital` لا تُستبدَل. **ينشئ:** تبويباتٌ تجمع الوحدات + دمجُ التحليلات؛ الأسرارُ مراجعُ VaultSecret فقط.
- **routes:** `tech.workspace` داخلياً؛ **الحرس:** `$owner||hub_monitor` + رفضُ العميل. **settings:** لا شيء. **FlowRunner:** لا شيء.
- **اختبارات-أولاً:** يمتدّ `ReaderScopeLeaksTest` — قيمةُ secret_cipher لا تظهر قط (عنوان/نوعٌ فقط)؛ عميلٌ→404 على مساحة العمل والجراف.
- **يقدّم:** §37–38.

**قواعدُ أمنٍ للطور H:** الترشيحُ خادميٌّ لكل عقدة/حافّة/عدّاد (لا إرسالَ الكلّ ثم إخفاء JS)؛ مكتبةٌ محليّةٌ + بديلٌ نصّيّ؛ العميلُ لا يرى أيَّ بنية (رفضٌ صلبٌ على hub_client_ids)؛ مراجعُ VaultSecret لا قيَم؛ step-up لكشفِ سرّ/تغييرِ اعتماد.

> **قرارٌ مفتوح (H):** «IPs» في §37 — الاكتفاءُ بـ`servers.ip` أم وحدةُ IP شبكيّةٍ مطبَّعة؛ إن بُنِيت فبمفتاح (module,record_id) القائم لا نظاماً موازياً (لا خلطَ مع IpAsset=ملكية فكرية).

---

## الطور I — الأمنُ التكيّفي (Adaptive IP Defense)

**الهدف:** قوائمُ IP سماح/حظر (مؤقت/دائم/آليّ) بتصعيدٍ قابلٍ للضبط، حمايةٌ من حبس المالك، حظرٌ تطبيقيٌّ يعمل فعلاً، ومحوّلاتُ حافّةٍ صادقةٌ اختيارية.

### WP-I.1 — مخزنُ القواعد ip_rules (Eloquent Model)
- **§:** §41. **يمتدّ:** `ip_allowed()` (helpers:27، matcher CIDR/IPv4/IPv6 — لا محلِّلَ ثانٍ) + `Auditable` + `FlowRunner`. **ينشئ:** migration `ip_rules`(ip,is_cidr,mode[block/allow],origin[manual/auto],reason,severity,expires_at nullable=دائم,escalation_level,hits,revoked_*,request_id)؛ `App\Models\IpRule` (Eloquent — لأن FlowRunner::fire Model-typed).
- **هجرة/فهارس:** INDEX(ip)،(mode,expires_at)،(expires_at)،(origin)؛ `access_denials` يكتسب composite(ip,created_at). **routes:** في I.3. **settings:** في I.2. **FlowRunner:** في I.2.
- **اختبارات-أولاً:** يمتدّ `SupportTest` (ip_allowed) + `IpIntelTest` (التجميع) — إعادةُ استخدام matcher؛ عرضُ الأعمدة MySQL.
- **يقدّم:** §102 «حظرٌ مؤقت/allowlist/انتهاء».

### WP-I.2 — التصعيدُ الآليّ + حدثُ ip_auto_blocked
- **§:** §41/§68. **يمتدّ:** `AlertEngine::fireSource` (يُصدر بالفعل رشقَ IP subject=IP مع dedup/cooldown، AlertEngine:344) — المُطلِقُ الآليّ يستهلكه؛ `hub_open_incident`. **يعدّل:** مسارُ التقييم يُنشئ حظراً عند تجاوز العتبة.
- **settings:** `security.autoblock_enabled`(default '0'),`_threshold`,`_window_min`,`_steps`(15,60,1440),`security.trusted_ips`,`security.edge_adapter`,`security.edge_cloudflare_secret_ref`(VaultSecret id) عبر `Settings::put`. **FlowRunner:** `ip_auto_blocked` (config:8516).
- **اختبارات-أولاً:** يمتدّ `IpIntelTest` — التصعيد 15د→ساعة→24س؛ **مطفأٌ افتراضياً** (فشلٌ مفردٌ لا يحظر)؛ حدثٌ واحدٌ عند التجاوز.
- **يقدّم:** §102 «تجميع→خطرٌ مُفسَّر→حظرٌ مؤقت».

### WP-I.3 — الفرضُ + حمايةُ المالك + الحالةُ الصادقة
- **§:** §39/§42. **يمتدّ:** دلالاتُ `HubMaintenance` (owner-exempt/JSON-negotiated) + `SecurityController::gate` + `SecurityPosture` row-shape + صفحاتُ `security.ips/ip`. **ينشئ:** middleware `IpDefense` (fail-open، allow يفوز block، مالكٌ مصادَقٌ لا يُحظَر، trusted_ips/user_ips تعافٍ) + `security.blocks` view + بطاقةُ «Application Block: ACTIVE / Network Edge Block: NOT CONFIGURED» + محوّلُ حافّةٍ صادق (best-effort، VaultSecret ref، يتدهور NOT CONFIGURED).
- **routes:** `security.blocks*` throttle+owner-only؛ **الحرس:** `hub_is_owner`+`hub_require_stepup` لكل add/extend/revoke (SECURITY_POLICY_CHANGED). **الجمهور:** internal owner. **FlowRunner:** لا شيء جديد.
- **اختبارات-أولاً:** يمتدّ `SupportTest`+`IpIntelTest`+`ClientOperationsTest` — الفرضُ web+API؛ allowlist يفوز؛ انتهاءُ المؤقت يُقلَّم؛ مالكٌ من IP محظورٍ يعبر؛ fail-open عند جدولٍ مكسور؛ عميلٌ/غيرُ مالكٍ→404؛ IPv4/IPv6/CIDR.
- **يقدّم:** §102 كاملاً + §42 «الحظرُ يعمل في كل مكان».

**قواعدُ أمنٍ للطور I:** الإدارةُ owner-only+step-up+أثر؛ حمايةُ حبس المالك إلزاميّةٌ خادمياً (لا يُحبَس آخرُ مالك)؛ الفرضُ fail-open (جدولٌ مكسورٌ ≠ انقطاع)؛ آليٌّ مطفأٌ افتراضياً ولا يُطلَق على فشلٍ مفرد؛ اعتماداتُ الحافّة مراجعُ VaultSecret؛ الحالةُ صادقةٌ (لا حظرَ حافّةٍ زائف).

> **مؤجَّلٌ صراحةً (I):** الحظرُ على **حافّة الشبكة** فعلياً — يتطلب **اعتماداتِ Cloudflare API** أو وصولَ خادمِ Nginx؛ يُعرَض «Network Edge Block: NOT CONFIGURED» حتى تُضبَط؛ الحظرُ التطبيقيّ authoritative ويعمل بلا تبعية.

---

## الطور J — خادمُ النقاط الطرفية (Endpoint Server) — buildable هنا

**الهدف:** خادمُ إدارةِ أجهزةِ الشركة (Win/macOS): سجلٌّ، تسجيلٌ لا تماثليّ، heartbeat، أحداثٌ، أوامرُ registry صارمة، سياساتُ USB، وضعيّةٌ صادقة — كلُّه فوق السكك القائمة.

### WP-J.1 — سجلُّ الأجهزة + التسجيلُ اللاتماثليّ
- **§:** §43. **يمتدّ:** `Webauthn.php` ES256 (verifyRegistration/verifyAssertion، الخادمُ يخزّن العامّ فقط) + انضباطُ `ApiToken.token_hash` (sha256). **ينشئ:** migrations `endpoint_devices`(device_uuid unique,company_id,employee_id,asset_id,station_id nullable,hostname,os,hw json,agent_version,public_key PEM,pubkey_fp unique,status,posture json,policy_id,softDeletes)+`enrollment_tokens`(token_hash unique,single-use,short-TTL,company/employee pre-assign)؛ وحدةُ 'endpoints' في config؛ نماذجُ Eloquent.
- **هجرة/فهارس:** UNIQUE(device_uuid),(pubkey_fp),(token_hash)؛ INDEX(company_id/employee_id/asset_id/status/last_heartbeat_at). **routes:** `enroll.*` (mint=step-up)؛ **الحرس:** middleware توقيعٍ لكل جهازٍ يلفّ حرسَ ApiAuth (تعليق/قفل/lockdown/company). **settings:** `endpoint.enroll_ttl_min`. **FlowRunner:** `endpoint.enrolled`.
- **اختبارات-أولاً:** يمتدّ اختباراتِ InboundHook/Webauthn — token لمرّةٍ (استخدامٌ ثانٍ 409)؛ الخادمُ يخزّن العامَّ فقط (الخاصُّ لا يُخزَّن قط)؛ عبرَ شركةٍ مرفوض (404).
- **يقدّم:** §100 «تسجيل».

### WP-J.2 — heartbeat + أحداثٌ (خصوصيّةٌ مفروضةٌ خادمياً) + أوامرُ صارمة
- **§:** §43. **يمتدّ:** `metric_points`/`Series`/`hub_metric_put` (لا مخزنَ مقاييسَ ثانٍ) + نمطُ replay في `InboundHookController` (timestamp ±300s + nonce unique) + نمطُ آلةِ حالة `outbox` (claim/transition/retry). **ينشئ:** migrations `endpoint_events`(kind[usb/posture/network_self/policy/agent],severity,summary مُنقَّح,nonce unique per device,request_id)+`endpoint_commands`(type **ENUM allowlist**: refresh_inventory/refresh_posture/apply_policy/isolate/lock — **لا shell**,state,ikey,result_sig)؛ `endpoint_policies`(usb_mode 5-modes,enforce default false,posture_checks)؛ مُصادِقُ ابتلاعٍ **يرفض** حقولَ المراقبة.
- **هجرة/فهارس:** events INDEX(device_id,created_at),(company_id,kind),(severity)+UNIQUE(device_id,nonce)؛ commands INDEX(device_id,state),(state,next_at)+UNIQUE(device_id,ikey). **routes:** `endpoint.heartbeat/event/command*` (توقيعٌ+throttle)؛ **الجمهور:** أجهزةٌ داخليّةٌ فقط. **settings:** `endpoint.heartbeat_interval_min` (اسمٌ متمايزٌ عن heartbeat.* الخاص بالمراقب). **FlowRunner:** `endpoint.usb_event`/`endpoint.posture_alert` بعتباتٍ عبر SecurityEvents (ENDPOINT_* codes).
- **اختبارات-أولاً:** failing-first جديدة (على المحرّكين) — nonce معاد/timestamp قديم/توقيعٌ مزوّرٌ مرفوض؛ الابتلاعُ **يرفض** keystrokes/screenshot/clipboard/browsing/file-content؛ نوعُ أمرٍ غير مُدرَجٍ مرفوض؛ لا ازدواجَ إرسالٍ تحت claim متزامن.
- **يقدّم:** §100 «USB مجهول→تقريرٌ بلا محتوى→سياسة→تنبيه».

### WP-J.3 — دمجُ مركز الأمن + وضعيّةٌ صادقة + تبويبُ 360
- **§:** §43/§63/§28. **يمتدّ:** `SecurityEvents::CODES` (يُضاف ENDPOINT_*) بعتباتٍ لا لكل حدث؛ الموظف 360 (تبويب EndpointSecurity، hub_field_mode). **ينشئ:** مركزُ نقاطٍ + عرضُ وضعيّةٍ صادق (Defender/firewall/BitLocker/FileVault؛ إن منعها النظام → 'not-configured' لا 'active').
- **routes:** `endpoints.index/show` owner/monitor؛ **الجمهور:** internal (عميلٌ→404). **FlowRunner:** أعلاه. **اختبارات-أولاً:** يمتدّ `ClientOperationsTest` — عميلٌ→404 على كل مسارِ نقاط؛ الوضعيّةُ المرفوضةُ تُخزَّن 'not-configured' لا تُدَّعى.
- **يقدّم:** §100 «مركزُ الأمن→أثر» + §28.

**قواعدُ أمنٍ للطور J:** النقاطُ بنيةٌ داخلية (عميلٌ→404)؛ الخادمُ يخزّن العامَّ فقط؛ replay مفروضٌ (توقيع/nonce/timestamp)؛ عبرَ شركةٍ مرفوض؛ أوامرُ allowlist لا shell، وisolate/lock/wipe = step-up+سبب+أثر؛ الخصوصيّةُ مفروضةٌ خادمياً (رفضُ حقول المراقبة)؛ الوضعيّةُ صادقة.

> **buildable & verifiable هنا (J):** نماذجُ الخادم، تشفيرُ التسجيل ES256 (إعادةُ Webauthn)، replay/nonce/timestamp، أوامرُ allowlist، السياساتُ بـenforce=false («Audit only»)، الوضعيّةُ الصادقة، اختباراتُ PHPUnit العدائية على المحرّكين.
> **مؤجَّلٌ صراحةً (J):** الفرضُ المدمِّرُ الحقيقيّ لسياسات USB يتطلب **تسجيلَ MDM** (Jamf/Intune) — يُعرَض «requires MDM / Audit only»؛ لا حجبَ USB زائف.

---

## الطور K — وكيلُ Windows/macOS (Go Agent) — buildable هنا (source + go test)

**الهدف:** مشروعُ `agent/` منفصلٌ بـGo يطابق عقدَ الخادم (ES256/nonce/timestamp)، بلا shell وبلا مراقبة.

### WP-K.1 — هيكلُ مشروع Go والهويّة
- **§:** §45. **يمتدّ:** عقدُ ES256/P-256 للخادم (Webauthn verifier) للتوافق. **ينشئ:** `agent/` (cmd + internal/{enrollment,identity,inventory,network,usb,security,policy,commands,update,platform/{windows,darwin}})؛ توليدُ زوجِ مفاتيحٍ محليّاً، توقيعُ الطلبات، لا shell exec، لا جامعاتِ مراقبة.
- **اختبارات-أولاً:** go table-tests (على نمط failing-first) — signing/enrollment/replay.
- **يقدّم:** §100 (جانبُ الوكيل).

### WP-K.2 — الجرد/الشبكة/USB/الوضعيّة/الأوامر/التحديث الذاتي
- **§:** §45–62. **يمتدّ:** عقودُ J (heartbeat/event/command). **ينشئ:** جامعُ جردٍ، اتصالُ Wi-Fi **للجهاز نفسه فقط** (لا مسحَ شبكة)، أحداثُ USB (لا نسخَ ملفات)، قراءةُ وضعيّةٍ صادقة، مُحلِّلُ أوامرِ allowlist، تحديثٌ ذاتيٌّ بالتحقق من SHA-256.
- **اختبارات-أولاً:** go tests — policies/command-parsers/update integrity/network-self-only/no-surveillance.
- **يقدّم:** §100 كاملاً (جانبُ الوكيل).

**قواعدُ أمنٍ للطور K:** لا shell، لا keylogging/لقطات/كاميرا/ميكروفون/حافظة/تاريخ تصفح/حصادِ ملفات؛ Wi-Fi للجهاز فقط؛ USB أحداثٌ لا محتوى؛ المفتاحُ الخاصُّ لا يغادر الجهاز.

> **buildable & verifiable هنا (K):** مصدرُ Go كاملاً + go test (signing/enrollment/replay/policies/parsers/update).
> **مؤجَّلٌ صراحةً (K):** الفرضُ المدمِّرُ الحقيقيّ (حجبُ USB) يتطلب **MDM**؛ الوكيلُ يُبلِّغ ويطبّق registry الآمن فقط.

---

## الطور L — البناءُ والتوزيع (CI Build · Signing · Download Center)

**الهدف:** بناءٌ متعدّدُ المعماريّة مع checksum وحالةِ توقيعٍ صادقة، ومركزُ تنزيلٍ مُصادَق.

### WP-L.1 — وظيفةُ بناء Go في CI
- **§:** §62. **يمتدّ:** `.github/workflows/ci.yml` (يضاف job Go). **ينشئ:** بناءُ `.exe/.msi` + `.pkg` (amd64/arm64) + checksum؛ توقيعٌ **إن توفّرت الشهادات وإلا `UNSIGNED DEVELOPMENT BUILD`**.
- **اختبارات-أولاً:** go build/test عبرَ المعماريّتين؛ CI أخضر.
- **يقدّم:** §100 (توزيعُ الوكيل).

### WP-L.2 — مركزُ تنزيلِ الوكيل (مُصادَق + مُسجَّل)
- **§:** §44/§62. **يمتدّ:** انضباطُ `AttachmentController` (auth+storage/local+Content-Disposition attachment+download_log+sha256، **لا public storage**). **ينشئ:** سطحُ إصداراتٍ (نسخة+SHA-256+حالةُ توقيع) + صفحةُ تنزيلٍ؛ تحديثٌ ذاتيٌّ يتحقّق من checksum المنشور.
- **routes:** `endpoints.releases*` owner-only؛ **الجمهور:** internal. **اختبارات-أولاً:** يمتدّ `ReaderScopeLeaksTest` (بوّابةُ الملفات) — تقديمٌ مُصادَقٌ مُسجَّل لا public؛ عرضُ الحالة «UNSIGNED DEVELOPMENT BUILD» صادقاً.
- **يقدّم:** §100.

> **buildable & verifiable هنا (L):** خطُّ CI، الأرتيفاكتُ غيرُ الموقَّع بـchecksum، تحقّقُ سلامة التحديث الذاتيّ، مركزُ التنزيل المُصادَق.
> **مؤجَّلٌ صراحةً (L):** التوقيعُ الحقيقيّ — **Windows Authenticode** (شهادةُ توقيعِ كودٍ OV/EV من CA) و**macOS Developer ID + notarization** (عضويّةُ Apple Developer Program + notarytool)؛ حتى ذلك يُشحَن `UNSIGNED DEVELOPMENT BUILD` صادقاً (لا مُوقِّعَ زائف).

---

## الطور M — التكاملُ والصقل (Integration & Polish)

**الهدف:** ربطُ الجزر، إغلاقُ الحلقات العابرة، وإثباتُ سيناريوهات القبول §98–102 من طرفٍ إلى طرف، ثم تقريرُ §103.

### WP-M.1 — البحثُ العالميّ + روابطُ العلاقات من كل صفحة
- **§:** §63–73. **يمتدّ:** `SearchController` (فهرسةٌ مُرشَّحةٌ بالعضوية+النطاق على الخادم) + زرُّ المستكشف من H. **اختبارات-أولاً:** يمتدّ `SearchDmLeakTest` — لا تسرّبَ فهرسٍ لقنواتٍ/عملاءَ خارج النطاق.

### WP-M.2 — إشعاراتٌ موحّدة + X-Request-Id واحد + hub.events
- **§:** §63–73. **يمتدّ:** `hub_notify`/`HubNotification` (MUTEABLE dedup/cooldown) + `Api::requestId` (لا معرّفَ ثانٍ) + config('hub.events'). **اختبارات-أولاً:** كلُّ الأحداث الجديدة (client_workspace_created…ip_auto_blocked…endpoint.*) عبر FlowRunner القائم بمعرّفٍ واحد.

### WP-M.3 — تكملةُ تبويبات الموظف 360 + تغطيةُ النسخ الاحتياطيّ + OpenAPI
- **§:** §28/§74–81. **يمتدّ:** `portal/employee.blade.php` (توصيلُ تبويبات Telecom/FinancialCustody/Systems/EndpointSecurity عند اكتمال سككها) + `HubBackup` (إضافةُ الجداول الجديدة، وإلا يفشل `BackupRestoreTest`) + `hub:openapi`. **اختبارات-أولاً:** يمتدّ `BackupRestoreTest`+`SettingsCenterTest` — الجداولُ الجديدة في الـdump وتدور؛ كلُّ مفتاحِ settings مُسجَّلٌ (exposedKeys/internal).

### WP-M.4 — سيناريوهاتُ القبول §98–102 من طرفٍ إلى طرف
- **§:** §98–102. **يمتدّ:** كلُّ ما سبق. **اختبارات-أولاً:** سيناريوهاتٌ كاملة (عميل/محطة/نقطة/عهدة/أمن) على المحرّكين، كلٌّ يمتدّ سلفَه الموسوم أعلاه.
- **يقدّم:** §98–102 كاملةً + تقريرُ §103 (IMPLEMENTED/REUSED/DATABASE/SECURITY/ENDPOINT AGENT/PERFORMANCE/TESTS/RESULTS/VERSION/DOCS/DEFERRED).

**قواعدُ أمنٍ للطور M:** لا معرّفَ ارتباطٍ ثانٍ؛ لا محرّكَ إشعاراتٍ ثانٍ؛ البحثُ مُرشَّحٌ خادمياً؛ كلُّ سطحٍ عابرٍ للحدود يُدقَّق؛ لا بطاقاتٍ زائفةً في 360 (تبويبٌ يُوصَل عند وصولِ سكّته فقط).

---

## مخطّطُ التبعية والتسلسل

```
                         ┌──────────────────────────────────────────┐
                         │  A  أساساتُ العزل والقنوات (SF-1..SF-5)   │  ← يجب أولاً
                         └───┬───────────────┬───────────┬──────┬────┘
        ┌────────────────────┘        ┌──────┘           │      └────────────┐
        ▼ (المسار الأماميّ)            ▼ (مستقلّ)          ▼(مستقلّ)            ▼(مستقلّ)
   ┌─────────┐   depends SF-1/2/4  ┌────────┐        ┌────────┐          ┌──────────┐
   │ B بوابة │───────────────────▶│ E عهدة │        │ I أمن  │          │ J خادمُ  │
   └────┬────┘                     └────────┘        └────────┘          │  النقاط  │
        ▼ SF-3                      (SF-1 فقط)        (SF-1 فقط)          └────┬─────┘
   ┌─────────┐                     ┌────────┐                                 ▼ عقدُ ES256
   │ C تعاون │───────────────────▶│ F محطات│───(station_id ref)──┐      ┌──────────┐
   └────┬────┘  (الغرفتان=SF-3)    └───┬────┘                     ▼      │ K وكيلُ  │
        ▼                              ▼ ref                  ┌────────┐ │  Go      │
   ┌─────────┐                     ┌────────┐  servers→sta/   │ G اتص. │ └────┬─────┘
   │ D تسليم │                     │ H جراف │◀─asset/hr edges─┘└────────┘      ▼
   └─────────┘                     └────────┘  (F+G+E تُثري الحواف)      ┌──────────┐
        │                              │                                │ L بناء/  │
        └──────────────┬───────────────┴───────────────┬────────────────┤  توزيع   │
                       ▼                                ▼                └────┬─────┘
                 ┌───────────────────────────────────────────────────────────▼──┐
                 │  M  التكامل والصقل + §98–102 القبول + §103 التقرير (يعتمد الكلّ) │
                 └──────────────────────────────────────────────────────────────┘
```

**قِيَدُ التسلسل الصلبة:** A قبل الكلّ · B قبل C قبل D (يتشاركون سكّة الرسائل SF-3) · E/I لا تحتاجان إلا SF-1 (الحدَّ الصلب) · F قبل تفعيلِ station_id في G وقبل حواف servers→station في H · J قبل K قبل L (عقدُ الخادم ثم الوكيل ثم البناء) · H أغنى بعد F+G+E (لكن نواتُها تُبنى على refs القائمة) · M آخِراً.

**أطوارٌ تصلح لـworktrees متوازية بعد اندماج A:**
- **Track 1 (أماميّ):** B → C → D (يتشاركون Conversation/audience — تسلسليٌّ داخلياً).
- **Track 2:** E العهدة المالية (جداولُ/متحكّماتٌ منفصلة — مستقلٌّ تماماً).
- **Track 3:** F → G (المحطاتُ ثم ref الاتصالات).
- **Track 4:** I الأمن التكيّفي (ip_rules/middleware منفصل — مستقلّ).
- **Track 5:** J → K → L النقاطُ الطرفية (خادمٌ ثم وكيلٌ ثم بناء — مستقلٌّ عن البقية).
- **H** يبدأ متوازياً على refs القائمة، ويُكمَّل بعد اندماج F/G/E. **M** يُدمَج أخيراً.

**تنسيقُ الworktrees المتوازية (حاجزُ VERSION):** خطّاف `.githooks/pre-push` يرفض دفعةً دون رفع `VERSION`، فكلُّ worktree يرفع نسختَه؛ عند الدمج يُعادُ توفيقُ `VERSION`+README ويُعادُ توليدُ `docs/openapi.json` **مرةً واحدةً لكل دمج** (بوّابةُ CI تُسقط الانحراف). التصادماتُ الأقلُّ احتماليّةً بين المسارات لأن كلَّ مسارٍ يمسّ جداول/متحكّماتٍ متمايزة؛ نقاطُ التماس المشترَكة (config/hub.php modules+events، helpers.php isolation، `bootstrap/app.php` middleware، `HubBackup`، `SecurityEvents::CODES`) تُدار بدمجٍ مبكّرٍ متكرّرٍ من A ومراجعةِ تعارضٍ يدويّة.

---

## ثوابتُ عابرةٌ لكل WP (لا تُكرَّر في نصّ كل بند لكنها ملزِمة)
- الحزمتان خضراوان: `phpunit` (SQLite) + `phpunit.mysql.xml` (MySQL)؛ عرضُ الأعمدة يسع كاتبيها (درسُ notifications_hub.kind)؛ ترتيبٌ حتميٌّ (id/created_at+id لا قرعة)، تأكيدٌ على **كل** الصفوف.
- إثباتٌ لا ادّعاء: كل عيبِ عزلٍ/صلاحيةٍ اختبارٌ **يفشل أولاً** يمتدّ سابقةً مسمّاةً (CompanyIsolationTest/ClientOperationsTest/AuditScopeLeakTest/TenancyLeakRound8Test/SearchDmLeakTest/ReaderScopeLeaksTest/FieldPermissionBypassTest/IpIntelTest/SupportTest/LedgerAndStockIntegrityTest/PayrollJournalTest).
- الإضافةُ لا الكسر: هجراتٌ إضافيّةٌ محروسةٌ مفهرسة؛ لا حذفَ مسارٍ ولا كسرَ عقدِ API ولا هجرةٍ مدمِّرة؛ RefreshDatabase يعيد بناءَ المخطط لكل اختبار فالهجرةُ الكاسرةُ تُسقط الحزمةَ كلَّها. لا `migrate:fresh/refresh` على MySQL `hub_test`.
- بعد أيّ تغييرٍ في config/hub.php أو مسارِ API أو VERSION: `php artisan hub:openapi --out=docs/openapi.json` + `hub:schema-check` + مفتاحُ settings مُسجَّلٌ في exposedKeys/internal (SettingsCenterTest) + الجداولُ الجديدة في HubBackup (BackupRestoreTest).

---

## CRITIC FINDINGS

> نقدٌ للـPLAN/INVENTORY مقابل المستودع الحقيقي (READ-ONLY). كلُّ بندٍ بدليلٍ file:line / table.column، مرتَّبٌ بالخطورة. تصنيفُ التحقّق: COMMAND_VERIFIED ما لم يُذكر غيرُه.
> **بلاغٌ إيجابيٌّ أولاً (تكرارٌ مُتجنَّبٌ صحيحاً):** لا محرّكَ رسائل ثانٍ (Comment/DmMessage عبر conversation_id) · لا RBAC ثانٍ (Role.flags/matrix + conversation_members بيانات) · لا دفترَ ثانٍ (custody يرحّل في JournalEntry) · لا معرّفَ ارتباطٍ ثانٍ (Api::requestId) · لا محرّكَ إشعاراتٍ ثانٍ (hub_notify/AlertEngine) · IpRule≠IpAsset · endpoint_devices≠user_devices · stations≠facilities. الأرصفةُ المُعاد استعمالها كلُّها موجودة (StepUp/Settings/Webauthn/Correlation/Redactor/Custody/DigitalAssets/AlertEngine/SecurityEvents/SecurityPosture/Risk/HubMaintenance/FlowRunner Model-typed). الـ18 جدولاً المقترحة كلُّها غائبةٌ فعلاً (0 create) فليست تكراراً. بوّابتا CI (المحرّكان + انحرافُ openapi) والحاجزُ pre-push وHubBackup وكلُّ اختباراتِ السابقة المذكورة موجودةٌ فعلاً.

**C1 — [حرج · قاعدة §14/§37 الصلبة] PortalGuard يجب أن يكون allowlist لا denylist.** `hub_scope` يعزل العميلَ فقط على الوحدات التي لها عمودُ عميل، وتعليقُ المصدر صريحٌ: «وحدةٌ بلا عمودِ عميلٍ تبقى محكومةً بمصفوفة الصلاحيات» (helpers.php:155-161). من 82 وحدة، **15 فقط** لها ref→clients يعزلها hub_scope (projects/fin/assets/quotes/contracts/tickets/…)؛ الـ67 الباقية (servers/vault/dbs/domains/payroll/banks/costc/stations/phones/endpoints/custody…) بلا أيّ ترشيحِ عميلٍ من الرصيف. خطةُ WP-A.3 تصف رفضاً صلباً لِـ«servers/audit/security/costs/fin/endpoints» فقط (denylist من 6). **الأثر:** دورُ عميلٍ مُساءُ الضبط يُمنح أيَّ وحدةٍ من الـ67 → hub_scope يعيد **كلَّ** الصفوف بلا فلتر. **الإجراء:** اعكِس PortalGuard إلى allowlist صريح (engagements + projects[audience=client] + الفواتير + الوثائق المشترَكة + قنوات العميل)، وكلُّ ما عداه 404 لِـaccount_type=client بمعزلٍ تامٍّ عن المصفوفة. حدِّث اختبارَ A.3 ليضرب عيّنةً واسعةً من الـ67 لا 4 مسارات.

**C2 — [حرج · فجوةُ ميزة] لا عمودَ audience/client_id على `documents` — «الوثائق المشترَكة» في بوابة العميل بلا سندٍ مخطّطيّ.** migration إنشاء documents (2026_01_02_000016) بلا client_id/audience؛ العميلُ الوحيدُ الذي كسبه هو fin_documents (2026_02_04_000001). `DataRoomController` رابطٌ عامٌّ بـtoken (Str::random(48)+share_views، بلا حساب) — **ليس** بوابةَ العميل المُصادَقة. WP-B.2 يَعِدُ «shared docs» بلا هجرةٍ تسنده، والـINVENTORY يذكر documents.audience لكن **لا WP في الخطة يُنفّذه**. **الإجراء:** أضِف WP لِـ`documents += audience(internal/client/both) + client_id` (nullable/index)، أو صرّح أن مشاركةَ وثيقةٍ للعميل المُصادَق تمرّ عبر آليّةٍ مُنطَّقة صريحة؛ لا شاشةَ مشاركةٍ بلا عمود.

**C3 — [حرج · توافقٌ رجعيّ] إعادةُ تسيير `hub_client_ids` من client_memberships بلا WP تعبئةٍ خلفية.** helpers.php:176 يقرأ `users.clients` JSON، وhub_scope:159 يبني **كلَّ** عزل العميل عليه؛ الحساباتُ الحاليّةُ لها clients JSON مأهولة. WP-A.2 يقول «hub_client_ids من العضويات» لكن لا هجرةَ backfill من users.clients→client_memberships مذكورة. **الأثر:** عند النشر، كلُّ مستخدمِ عميلٍ حاليٍّ بلا عضويّةٍ مُعبّأة يفقد وصولَه فوراً. **الإجراء:** WP-A.2 يجب أن يشمل هجرةَ تعبئةٍ صريحة (users.clients→memberships role=viewer/…)، أو يوحّد hub_client_ids المصدرين حتى تكتمل التعبئة؛ واختبارُ التوافق يؤكّد المساواة قبل/بعد على حساباتٍ مُرحَّلة.

**C4 — [متوسط · ثغرةُ عزل §9 + قرعةُ ترتيب] `hub_related` بلا فلتر audience وبترتيبٍ غير حتميّ.** helpers.php:3114-3122: يفرض hub_scope+hub_can لكن **بلا** audience، و`orderByDesc('created_at')->limit($limit+1)` — قرعةٌ (created_at بدقّة الثانية، درسُ CLAUDE.md) + بترٌ عند الحدّ. WP-D.1 يعتمد عليه للغرفتين، وWP-H.2 يمشي الحوافَ فوقه. **الإجراء:** D.1 يضيف فلترَ audience **و**tie-break بـid؛ H.2 لا يرث بترَ limit عند تعداد العقد/الحواف؛ اختباراتُ القبول تؤكّد على **كل** الصفوف.

**C5 — [متوسط · دقّةٌ في إعادة الاستعمال J/K] إعادةُ `Webauthn::verifyAssertion` للوكيل غيرُ دقيقة.** verifyAssertion/verifyRegistration خاصّتان بمراسم WebAuthn (clientDataJSON/authenticatorData/rpIdHash/flags/challenge/signCount)، بينما الوكيلُ يوقّع `timestamp.nonce.body` خاماً بـECDSA-P256. الجزءُ القابلُ للإعادة هو COSE→PEM (Webauthn.php:136-146) + `openssl_verify` ES256 (Webauthn.php:15-30)، **لا** verifyAssertion كاملةً؛ وتخزينُ public_key PEM (لا attestationObject COSE) يعني verifyRegistration لا ينطبق كما هو. **الإجراء:** J.1/K.1 يستخرجان بدائيّةَ ECDSA-P256-SHA256 (تحقّق/توقيع على بايتات) لا مراسمَ WebAuthn؛ صِفْ عقدَ التوقيع صراحةً (ما الذي يُوقَّع، بأيّ ترميز).

**C6 — [متوسط · توتّرُ قاعدتين] تعميمُ `comments.internal`→audience يصطدم بـ«لا flag ثانٍ» ضد «إضافيٌّ غيرُ مدمِّر».** comments.internal بوليان يُكتَب فقط لِـtickets (CommentController:95)، ويُقرأ في Comment.php وpartials/comments.blade.php وغيرها. إبقاءُ internal + إضافةُ audience = flagان (يخالف SF-4)؛ إسقاطُ internal = هجرةٌ مدمِّرة تكسر قرّاءه. **الإجراء:** صرّح المسار: أبقِ internal، عبّئ audience منه، كاتبٌ واحدٌ يزامنهما، ورحّل القرّاءَ تدريجيّاً؛ لا تَعِدْ «لا flag ثانٍ» دون خطةِ ترحيلِ القرّاء.

**C7 — [متوسط · اتساقُ بيانات] كتابةٌ مزدوجة project_members + members JSON.** User::visibleProjectIds:85 = `whereJsonContains('members', id)`؛ الخطة تُبقي JSON متزامناً مع project_members المطبَّع. الكتابةُ المزدوجةُ مصدرُ انحرافٍ صامت. **الإجراء:** عيّن كاتباً واحداً (الجدولُ مصدرُ الحقيقة، JSON مُشتقٌّ عبر observer)، واختبارٌ يؤكّد التطابقَ بعد كل مسارِ عضوية.

**C8 — [متوسط · تسلسل/تنسيق] `toProject` تمدّه ثلاثةُ أطوار + خلقُ مستخدمِ العميل غيرُ محدَّد.** المعاملةُ الواحدةُ المقفلة (QuoteController::toProject) تُمدَّد في B.4 (عضويّة+تفعيل+client_workspace_created)، D.1 (الغرفتان)، D.4 (project.provisioned). وaccount_activations.user_id FK يستلزم مستخدماً، لكن B.4 لا يصرّح بخلقِ User(account_type=client) من جهةِ اتصال العميل عند القبول. **الأثر:** خطرُ مسارَين للتوفير (يخالف «لا مسارَ ثانٍ») أو غرفٌ غيرُ موجودةٍ لحظةَ التوفير. **الإجراء:** طبقاتٌ إضافيّةٌ واحدةٌ داخل المعاملة نفسِها؛ B.4 يخلق المستخدمَ العميلَ (بلا كلمةِ سرّ) قبل العضويّة/التفعيل؛ D.1 يبذر الصفّين داخلَ المعاملةِ ذاتها لا في مسارٍ منفصل.

**C9 — [متوسط · §6 امتثال] طيُّ DM في الحاوية (C.4) يتطلب backfill وإلا رقابةٌ عمياء.** dm_messages بلا conversation_id (2026_01_30_000001)؛ §6 يكتشف DM عبر الحاوية. بلا تعبئةِ threads القائمة إلى conversations+members، تبقى محادثاتُ DM السابقةُ **غيرَ مرئيّةٍ للرقابة**. **الإجراء:** هجرةُ تعبئةٍ (thread_key→conversation_id لكل ثنائيّ) أو رقابةٌ تمسح dm_messages الخام أيضاً؛ اختبارُ C.4 يؤكّد اكتشافَ DM قديمٍ.

**C10 — [جدوى · MySQL] تجنّب `$t->enum(...)` لِـendpoint_commands.type/usb_mode/kind.** درسُ CLAUDE.md (notifications_hub.kind) وقاعدةُ «هجراتٌ إضافيّة»: إضافةُ قيمةِ enum على MySQL = ALTER شبهُ مدمِّر. الخطةُ تكتب «ENUM allowlist». **الإجراء:** استخدم `string` واسعاً + allowlist مُتحقَّقٌ منه في التطبيق (لا DB ENUM)، فتبقى الإضافةُ آمنة؛ ونفسُه لِـcustody.kind وconversations.kind.

**C11 — [جدوى · حالات] توسيعُ assets.status إلى 11 يتطلب تغطيةَ مساعِدات الحالة.** assets.status options=5 حاليّاً (hub.php)، وhub_closed_states موجود (helpers.php:2354). إبقاءُ الخمسةِ aliases لا يكفي إن لم تُصنَّف الحالاتُ الجديدةُ في open/closed وخريطةِ انتقال Custody::move. **الإجراء:** WP-F.2 يحدّث hub_closed_states/open وخريطةَ الانتقال ويختبر القديمَ والجديدَ معاً على المحرّكين.

**C12 — [جدوى · CI للطور L] مصفوفةُ CI حاليّاً PHP فقط.** ci.yml مصفوفةٌ sqlite(8.2/8.4)+mysql(8.4) بلا Go. WP-L.1 يضيف بناءَ Go متعدّدَ المعماريّة. **الإجراء:** أضِف setup-go + cache للوحدات + بناءً متقاطعاً (amd64/arm64) كـjob مستقلّ لا يُعطّل بوّابةَ PHP؛ تأكّد من وصولِ الوكيل لوحداتِ Go في بيئة CI.

**C13 — [جدوى · صرامة MySQL] أيُّ تجميعٍ جديد (رصيدُ العهدة، عدّادُ تصعيد IP) بترتيبٍ حتميّ وبلا عمودٍ غيرِ مُجمَّع.** إعادةُ AlertEngine::fireSource (per-IP groupBy، AlertEngine.php:350-358) سليمةٌ تحت ONLY_FULL_GROUP_BY، لكن SUM(signed) للرصيد و`escalation_level` counts يجب أن تلتزم بالنمط نفسِه. **الإجراء:** كلُّ تجميعٍ جديدٍ يختار مفاتيحَ التجميعِ + مُجمَّعاتٍ فقط، ويرتّب بـid؛ اختبارٌ على MySQL.

**C14 — [تغطية · §63 بحث] الكياناتُ الجديدةُ في البحث العالميّ يجب أن تُرشَّح خادميّاً.** Comment/DmMessage ليسا Searchable (INVENTORY)؛ conversations/custody/endpoints ليست وحداتِ list قياسيّة تنال البحثَ تلقائيّاً عبر ModuleController. **الإجراء:** WP-M.1 يصرّح مسارَ فهرسةٍ لكلٍّ منها بفلترِ العضويّة+النطاق على الخادم (لا تسرّبَ فهرس)؛ اختبارُ SearchDmLeak يُمدَّد للقنوات والعهدة والنقاط.

**C15 — [مؤجَّلاتٌ خارجيّةٌ صحيحة — تأكيد] J/K/L تُرجئ الحواجزَ الحقيقيّةَ بصدق.** التوقيعُ (Authenticode OV/EV + Apple Developer ID/notarytool)، الفرضُ المدمِّرُ لِـUSB (MDM: Jamf/Intune)، APIs المشغّلين (G)، وحافّةُ Cloudflare/Nginx (I) — كلُّها اعتماداتٌ/امتيازاتٌ غائبةٌ فعلاً وإرجاؤها مطابقٌ لِـ§3/§43. **تأكيدٌ لا اعتراض؛** اشترِط فقط أن تفرض اختباراتُ J.3/L.2 حالةَ «not-configured/UNSIGNED DEVELOPMENT BUILD» الصادقةَ (لا مُوقِّع/حجب/توفير زائف)، ويرفض ingest حقولَ المراقبة fail-closed كما نصّت الخطة.

*انتهى نقدُ CRITIC — 15 بنداً بأدلّةٍ من المستودع الحقيقيّ. الخطةُ سليمةٌ في تجنّب التكرار الكبير؛ الإصلاحاتُ أعلاه تسدّ ثغراتِ العزل الملموسة (C1/C2)، والتوافقَ الرجعيّ (C3)، والدقّةَ (C4-C9)، والجدوى الثنائيّة (C10-C14).*
