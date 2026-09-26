> **وثيقةُ اكتشافٍ كما سُلِّمت (Work OS · مرحلةُ الاستكشاف قبل الطور A):** جردُ القائمِ الذي بُني عليه — تُحفَظ هنا مرجعاً تاريخياً بجانب تقرير §103؛ أرقامُها لحظةَ v2.408.1 لا الحاضر.

# Lynomia Work OS — سجلُّ التنفيذ الإلزامي (MANDATORY IMPLEMENTATION INVENTORY · spec §0)

> **التوجيه الآمر (من §0/§8):** وسّع → طبّع → اربط → أمّن → أتمِت → اعرض؛ لا استبدال ولا تكرار ولا تشظية.
> كلُّ سطرٍ أدناه متتبَّعٌ إلى الحزمة (bundle) أو إلى تحقُّقٍ مباشر. التسميات: `STATICALLY_REVIEWED` / `COMMAND_VERIFIED` / `RUNTIME_VERIFIED`.
> الحالاتُ المصحّحة (`corrected=true` من REFUTER) تفوز وتُعلَّم بـ`✎`.
> **المرجع القاطع:** لا محرّكَ رسائل ثانٍ، لا RBAC موازٍ، لا دفترَ محاسبيّ ثانٍ، لا سجلَّ أصول/اتصالات ثانٍ، لا معرّفَ ارتباطٍ ثانٍ. عزلُ العميل ولا مقاييسَ/مُوقِّعاتٍ/حجبَ USB مزيّف — قواعدُ §3/§26/§37/§43 الصلبة.

---

### SCHEMA BASELINE

**العدّادات (COMMAND_VERIFIED):** 126 Models · 191 migrations · 82 hub modules (`config('hub.modules')`) · 395 routes · **160 جدولاً** بعد `migrate --force` نظيفٍ على SQLite رمليّ · `VERSION=2.408.1`.

**الأرصفة (rails) الحاملة الجاهزة للإعادة (helpers.php):** `hub_scope`(141-164 project+company+client whereIn) · `hub_can`(248) · `hub_field_mode`(1599) · `hub_client_ids`(172)/`hub_client_col`(185)/`hub_company_ids`(122)/`hub_company_col` · `hub_require_stepup`(679)+`StepUp::fresh` · `hub_audit`(2692)+`Auditable` SHA-256 chain · `Api::requestId` (X-Request-Id واحد) · `FlowRunner::fire`→`HubEvents::dispatch`(55, Model-typed) · `Settings::put`(448 single writer) · `hub_notify`(2670)/`HubNotification` MUTEABLE · `hub_build_children_map`(1047)/`hub_related`(3104 يفرض can+scope بالبناء) · `Identity::resolve/attach` + `Qr::svg` (self-host, لا CDN) · `AlertEngine`/`SecurityRadar`/`SecurityEvents`/`Risk`/`SecurityPosture` · `Attachment` (av_status+checksum+version) · `ip_allowed`(27-50 IPv4/IPv6/CIDR).

**أشكالٌ محورية مؤكَّدة (COMMAND_VERIFIED على probe.sqlite):**

| الكيان | الشكل الحاليّ | الفجوة المؤكَّدة |
|---|---|---|
| `comments` | module,record_id,parent_id,mentions(json),read_by(json),task_id,pinned,resolved_at,**internal** | **لا** conversation_id / client_id / audience — internal فقط (tickets-only) |
| `dm_messages` | thread_key(sorted pair),from_id,to_id,read_at,deleted_at | **لا** company/client/audience/conversation_id — أيُّ مستخدمٍ يراسل أيَّ مستخدم بلا نطاق |
| `phone_numbers` | number,country,carrier(نصّ حر),company_id,project_id,owner_id(User),sms,wa,cost,expiry,status | **لا** iccid/imsi/msisdn/carrier_id/employee_id/device_id/plan/roaming |
| `users` | …companies(json),clients(json),status,locked_until,allowed_ips,totp | **لا account_type** — «عميل»=clients JSON غير فارغة فقط |
| `journal_entries` | doc_no,fin_id,state('مرحّل'=مقفل),meta.posted_at | `fin_id_index` **غير فريد** (unique=0) → لا حاجزَ ترحيلٍ مزدوجٍ صلب |
| `asset_custody` | asset_id,user_id,action,at,due,returned_at,permit_no,sign_id,project_id,client_id | **لا** station_id — الحائزُ User فقط (`assets.station_id` غائب) |
| `user_devices` | cookie_hash,label,platform,trust('معلّق'),first/last_ip/seen | ثقةُ جلسةٍ بالكوكيز — **ليست** سجلَّ MDM (لا UUID/OS/hw/heartbeat/posture) |
| `access_denials` | kind,user_id,ip,method,path,request_id | تغذيةٌ خام لدفاع IP — لا جدولَ حظر/سماح |

**غيابٌ بنيويّ مؤكَّد (COMMAND_VERIFIED — 0 هجرة `Schema::create` لكلٍّ):** `conversations`, `conversation_members`, `channels`, `spaces`, `stations`, `station_assignments`, `endpoint_devices`, `enrollment_tokens`, `ip_rules`, `carriers`, `employee_custody_moves`, `inventory_sessions`.

---

### EXISTING — REUSE

**التعاون (§3-8) — Comment هو محرّك الرسائل الوحيد:** `Comment`+`CommentController` (mentions/replies/reactions/att/read_by/pin/resolve/comment→task) — `guardTarget`(279-291) يفرض hub_mod+can('v')+scope+findOrFail؛ `toTask`(154-202) هو نموذجُ أوامر المحادثة الحقيقية. `feed` قناةٌ عامةٌ واحدة (channel seed + ثغرةُ عزل، CommentController:25). `DmMessage`+`DmController` (1:1، threadKey، read receipts، recall، presence عبر `sessions_log`:24-41). `reactions` table (unique comment_id,user_id,emoji). `hub_notify`/`HubNotification` MUTEABLE. `Attachment` av-gateway على comments وdm. **يُعاد كلّه كما هو حين تكتسب Comment `conversation_id`.**

**المشاريع/التجاري (§9-17) — «الجوهرة» قائمة:** `QuoteController::toProject`(368-461) idempotent+transactional+lockForUpdate على `quote.meta.project_id`، ينشئ Engagement+Project+PlanItems+`meta.baseline` snapshot، يطلق `FlowRunner quote.converted`. CPQ كامل: `Quote/QuoteLine/QuoteMilestone` + `recalc()`(110-161) + mrr/arr/tcv/margin. `msInvoice` فوترةُ معالمَ بحارس double-billing + clamp-to-remaining. `ChangeOrderController::apply` idempotent يطوّر baseline دون مسّ العرض. `hub_project_health`(2253)/`hub_project_pl`(2106) نقاطُ خطرٍ مُفسَّرة تغذّي `ExecutionStats`/`ActionCenter`/`NextAction`.

**عزل العميل (§12-16) — الرصيف الناضج:** `hub_scope` client branch(159-161)+`hub_client_ids`/`hub_client_col`؛ `findScoped`→findOrFail(ModuleController:1120) يُرجع **404 خارج النطاق** (سلوكُ §14 المفضَّل) للوحدات؛ `guardClient`/`inheritClient`(1176-1216)+`snapshotScopeError`(911-931) عزلُ الكتابة/الإصدار. `Engagement` طبقةُ التنظيم (client_note vs notes = بذرةُ audience). e-sign OTP (`EsignController`:811-819 hash+expire+OutboxMessage، 1134-1142 single-use) = رصيفُ التفعيل. `AuthController` دورةُ حياةٍ مدقَّقةٌ بالسلسلة(170)+lockout. `ShareLink`/DataRoom + e-sign = القنواتُ الخارجية بلا حساب.

**العهدة المالية (§18-21) — الدفتر غير القابل للتحرير:** `JournalEntry::booted`(52-78) يقفل `مرحّل` ضد update/delete ويفرض توازن debit==credit (عكسٌ فقط). `StockMove`(33-77) القالبُ الحرفيّ: `$posting` flag، kind-typed immutable moves، رصيدٌ مشتقّ، reverse-only. `FinController::autoJournal`/`PayrollController::autoJournal` نمطُ الترحيل الموحَّد (transaction+`$posting`+`accId()`+finance.auto_journal). `AuditEntry`(15-88) sha256 chain. `PayrollLine.advance` رقمُ سلفةٍ يجب أن يُصالَح لا يُكرَّر.

**الأصول والمحطات (§25-32):** `Asset` (nextCode collision-retry 68-111، owner_scope/client_id، specs json) + `AssetCustody` (row-per-move، `Custody::move`170-201 transaction) + permits (nextPermitNo، overdue) + `Identity::resolve`(120 scope-filtered) + `Qr::svg` (self-host) + `c/{code}` داخل auth+can+scoped = §26 «QR يتطلب دخولاً» مُرضًى أصلاً. `AssetLife` تحليلات (custody-by-person، warranty، مغادرون). `PortalController::employee`(30-198 bundle+workProfile) = مجمِّعُ الموظف 360 (يُوسَّع لتبويبات لا يُعاد بناؤه). سجل الوحدات (`ModuleController`) يمنح CRUD/scope/board/export مجاناً لأيّ وحدةٍ جديدة (stations).

**الاتصالات (§22-24):** `PhoneNumber`+phone_numbers (Auditable+HasVersions+Searchable) يخدمها المحرّك العام؛ `expiry` field + `HubAlertsStarter`(118-119)+`HubFlowsStarter`(228 recovery-number) = دورةُ حياةٍ/تنبيهاتٌ جاهزة؛ `hub_children`/`hub_related` = آليةُ ربط 360 (يكفي إعلانُ `ref`)؛ `AssetCustody` = قالبُ سجلّ الإسناد إن لزم.

**البنية والجراف (§33-38):** 12 وحدةً تقنيةً في السجل (Server/Service/DatabaseReg/Domain/Website/PlatformAccount/EmailAccount/VaultSecret/Deployment/CodeRelease/Application/Incident). `DigitalAssets`(infraBlastRadius/mailboxBlastRadius/vaultHealth، مفتاح hub_scope_key:345) = محرّكُ التبعية الذي يستهلكه الجراف. `hub_build_children_map`(1047)+`hub_related`(3104) = backbone الإسقاط (يفرض النطاق بالبناء). `VaultSecret` (EncryptedOrPlain+AUDIT_SECRET redaction)+`revealSecret`(377-389 «عرض حساس») = «مراجع لا قيَم». `public/vendor/leaflet/1.9.4` = سابقةُ الاستضافة المحلية بلا CDN. `Correlation`+`SystemTraceController` = معرّفُ الارتباط الواحد.

**الأمن التكيّفي (§39-42):** `SecurityRadar`(intel 79-209)+`AccessRadar` (يسجّل 403/404-guess، لا يحظر) · `AlertEngine::fireSource`(344-375 per-IP spray مع distinct-account + dedup/cooldown/incident) · `Risk`(59-123 factors مُفسَّرة، لا geo مخترَع) · `ip_allowed`(27-50) المطابقُ الوحيد · `HubMaintenance` lockdown (owner-exempt، JSON 503 — نمطُ الحظر العالميّ الأقرب) · `hub_security_incident`(563-632 fingerprint dedup) · `users.allowed_ips`+`user_ips`(LoginSentry) = مصدرُ تعافي المالك.

**النقاط الطرفية (§43-62) — الأرصفة للبناء عليها (الميدان كلُّه MISSING):** `Webauthn`(ES256/P-256 verifyAssertion/verifyRegistration، openssl_verify، sign-count) + `PasskeyController` (الجهاز يولّد المفتاح، الخادم يخزّن public فقط) = محرّكُ التسجيل. `InboundHookController::receive` (HMAC + ±300s window + nonce insertOrIgnore) = حمايةُ الإعادة. `OutboxMessage`/`HubOutbox` (state+claimed_at+attempts+next_at، حارسُ double-dispatch) = نمطُ طابور الأوامر. `ApiAuth` (token_hash + suspend/lock/lockdown/company guards + idempotency_keys) = هيكلُ حراسة الحساب. `metric_points`/`Series`/`hub_metric_put` = heartbeat/posture series. `AttachmentController` (auth+storage/local+download_log+sha256+Content-Disposition) = مركزُ تنزيل الوكيل. `SecurityEvents::CODES` = تصنيفُ الأحداث بعتبات.

---

### GAPS

**التعاون (§3-8):** Spaces/Channels/channel-Threads غائبةٌ كلياً؛ `feed` قناةٌ عامةٌ واحدة يجب أن تصير واحدةً من كثير. عضويةٌ مطبَّعة (owner/moderator/member/guest) غائبة (اليوم read_by JSON). audience(internal/client/both) وvisibility model غائبان. **§6 رقابةُ الاتصالات** (قارئٌ للقراءة فقط لا يغيّر read_at، سببٌ إلزاميّ، أثرٌ كامل عبر audits) غائبة تماماً. **§8 أوامرُ المحادثة** (parser + dispatcher عبر خدمةٍ حقيقية) غائبة. ثغرتا عزلٍ: feed بلا نطاق، dm بلا نطاق.

**عزل العميل (§12-16):** لا **account_type** صريح (تصنيفٌ بنيويّ)؛ لا **PortalGuard** middleware ولا مجموعةَ مساراتِ عميل؛ لا **client_memberships** مطبَّعة بأدوار (Owner/Lead/Technical/Finance/Viewer)؛ لا تدفّقَ تفعيل (email→OTP→كلمة سرّ ينشئها هو)؛ لا **audience** enum على المستندات/الرسائل؛ لا بوابةَ عميلٍ (views/portal/client). §15: عزلٌ بنيويّ ناقصٌ على conversations/documents (بلا client_id) و`DmController`/`DataRoomController` لا يستدعيان hub_scope. ✎ (الملفات/المرفقات **تمرّ فعلاً** عبر hub_scope — التصحيح: الفجوة على comments/dm/documents وحدها).

**التسليم (§9-17):** غرفتان منفصلتان (internal/client) لكلّ مشروعٍ خارجيّ غائبتان؛ `hub_related` يعرض كلَّ الأبناء بلا **audience filter** (نقطةُ التسريب الملموسة)؛ لوحةُ PSA التشغيلية (نشطة/في خطر/تنتظر العميل/محجوب/معالمُ مستحقة) وإشارةُ hold/blocked (عمودٌ إضافيّ لا حالةٌ جديدة) غائبتان؛ `project_members` مطبَّعة غائبة (اليوم members JSON)؛ حدثُ `client_workspace_created`/`project.provisioned` غائب.

**العهدة المالية (§18-21):** لا subledger مفتاحُه employee (`employee_custody_moves`)؛ العشرُ أنواعِ حركاتٍ غائبةٌ كـenum مطبَّع؛ لا حسابَ `custody` في `finance.accounts`؛ **§21b:** لا `UNIQUE(source_module,source_id,kind)` ولا `entry_id` FK — الحاجزُ الوحيد اليوم القفلُ + meta.posted_at (وfin_id غير فريد — COMMAND_VERIFIED)؛ تبويبُ §28 المالي ولايةُ حياةِ +500→إيصال→اعتماد→ترحيل-مرةً→عكس غائبان.

**الاتصالات (§22-24):** SIM/eSIM/ICCID/IMSI/MSISDN/PIN/PUK/APN وplan/data/voice/sms/roaming/billing_cycle وروابطُ employee/device/station/client غائبةٌ كلها (أعمدةٌ إضافيةٌ على phone_numbers)؛ حالاتُ الحياة الأغنى + activated_at/deactivated_at؛ **§23** سجلُّ `carriers` المطبَّع غائبٌ كلياً.

**الأصول والمحطات (§25-32):** كيانُ Station وstation_assignments وصفحةُ محطةٍ 360 وs/{code} وجلساتُ الجرد (inventory_sessions/items/scans) غائبةٌ كلها؛ `assets.station_id` + `asset_custody.station_id` غائبان؛ حالاتُ §30 (11 حالة) تحتاج توسيعَ options + خريطةَ انتقالٍ في Custody (إبقاءُ الخمسة القديمة aliases)؛ الموظف 360 تبويباتٌ لا بطاقات.

**البنية والجراف (§33-38):** لا مساحةَ عملٍ تقنية موحّدة (صفحة/متحكّم واحد يجمع الوحدات + تحليلات DigitalAssets)؛ لا مستكشفَ علاقات (شجرة+جراف) ولا خدمةَ إسقاطٍ متعددةَ القفزات ولا مكتبةَ جرافٍ محلية ولا بديلٍ نصّيّ؛ حوافُ servers→station/asset/hr غائبة (refs غير معلَنة)؛ قرارُ جرد IP الشبكيّ معلَّق؛ عزلُ البنية عن العميل يعتمد على المصفوفة وحدها (لا client_id على جداول البنية) فيلزم **رفضٌ صلب** على hub_client_ids()!==null في المتحكّم.

**الأمن التكيّفي (§39-42):** لا مخزنَ `ip_rules` (block/allow، temp/permanent/auto) ولا سلّمَ تصعيدٍ (15د→ساعة→24س) ولا middleware إنفاذٍ فعليّ ولا محوّلاتِ Cloudflare/Nginx ولا فحصَ posture للحظر ولا حدثَ `ip_auto_blocked`؛ حمايةُ حبس المالك (allow-wins + trusted_ips + fail-open) غائبة؛ auto-block يجب أن يكون **معطَّلاً افتراضياً**.

**النقاط الطرفية (§43-62):** الميدانُ كلُّه غائب — endpoint_devices/enrollment_tokens/endpoint_events/endpoint_commands/endpoint_policies، middleware التوقيع لكلّ جهاز، مركزُ تنزيلٍ، وحدةُ `endpoints` في config، ومشروعُ `agent/` بـGo وCI بناؤه واختباراته. **قواعدُ الصدق الإلزامية:** الوكيلُ يشحن `UNSIGNED DEVELOPMENT BUILD`، الحجبُ «requires MDM»، ingest يرفض حقولَ المراقبة (keylog/screenshot/clipboard/browsing/network-scan) fail-closed، posture مجهولةٌ = «not-configured» لا «active».

---

### MODELS / CONTROLLERS / VIEWS / SUPPORT CLASSES TO MODIFY

**Models:**
- `Comment` — إضافة علاقة conversation() وسلوكِ audience (لا flag ثانٍ بجانب internal).
- `DmMessage` — ربطُ conversation_id + وسمُ company_id/client_id/audience للنطاق والرقابة.
- `Employee` — hasMany(EmployeeCustodyMove) + accessor رصيدٍ مشتقّ (لا عمودَ balance).
- `Asset` — إضافة station_id (locked field) + إبقاءُ Identity::attach.
- `PhoneNumber` — أعمدةُ SIM/plan/lifecycle + refs carrier/employee/device/client + PIN/PUK كـ`sec` + Identity::attach(iccid/msisdn).
- `Server` — أعمدةُ ref station/asset/hr للجراف والـ360.
- `User` — account_type + clients مشتقٌّ من client_memberships (توافقٌ رجعيّ).
- `Project` — audience + source_quote_id + hold_reason/blocked؛ `visibleProjectIds` يُعاد توجيهُه إلى project_members.

**Controllers:**
- `Web\CommentController` — تعميمُ internal→audience، guardConversation، مسارُ رسائل القناة عبر conversation_id، parser أوامرِ المحادثة على نمط toTask.
- `Web\DmController` — قارئُ رقابةٍ للقراءة فقط لا يقلب read_at، ووسمُ النطاق.
- `Web\ModuleController` — audience filter على `hub_related`/children؛ تبويباتُ مركز قيادة المشروع؛ إعلانُ حقول ref الجديدة (servers، phones).
- `Web\CustodyController` — إسنادُ أصلٍ لمحطةٍ **أو** موظف؛ عمودُ «المحطة»؛ توسيعُ Custody::move لهدفٍ محطة.
- `Web\PortalController` — إعادةُ تنظيم employee.blade إلى partials/cc/tabs بتبويباتٍ مُحرَّسةٍ خادمياً (Station/Assets/FinancialCustody/… حسب توفّر الرصيف).
- `Web\SecurityController` — أزرارُ Block/Allow/Extend/Revoke على security.ip(s) + صفحةُ security.blocks + بطاقةُ حالةِ الحظر الصادقة.
- `Web\QuoteController::toProject` — توسيعُ نفسِ المعاملة لتوفير غرف/عضوية العميل + إطلاق client_workspace_created (بلا مسارٍ ثانٍ).

**Support/Traits:**
- `helpers.php` — `hub_related`/`hub_build_children_map` (audience-aware، حواف station/asset/hr)؛ `hub_client_ids` يُعاد تسييرُه من client_memberships؛ `hub_home_url` يتفرّع على account_type.
- `Support\Custody` — خريطةُ حالاتِ الأصل §30 (إبقاءُ الخمسة aliases) + هدفُ محطة.
- `Support\DigitalAssets` — يُستهلَك من مساحة العمل التقنية والجراف (لا يُعاد بناؤه).
- `Support\SecurityRadar`/`SecurityEvents` — إضافةُ `ENDPOINT_*` codes + وعيُ حالةِ الحظر.
- `Support\Settings` — مفاتيحُ security.autoblock_*/trusted_ips/edge_adapter وcustody عبر الكاتب الواحد.
- `config/hub.php` — تسجيلُ وحدات `stations`/`carriers`/`endpoints`؛ حقولُ phones/servers الجديدة؛ توسيعُ assets.status options؛ حدثا `client_workspace_created`/`ip_auto_blocked`/`project.provisioned` في hub.events؛ حسابُ `custody` في finance.accounts.

**Views:** `partials/comments.blade`+`_comment.blade` (مدفوعةً بـconversation_id) · `feed/index.blade`→قناة · `dm/inbox.blade` (رأسُ سياق) · `modules/show.blade`+`custom/projects.blade` (تبويبات+غرفتان+صحة) · `portal/employee.blade`+`_hr.blade`+`staff.blade` (تبويبات 360) · `custody/category.blade`+`label.blade` (محطة) · `security/ips.blade`+`ip.blade` (أزرار الحظر) · `partials/cc/*` (يُعاد استخدامها لكل المراكز الجديدة).

---

### NEW TABLES / MIGRATIONS REQUIRED

> كلها **إضافيةٌ آمنة، nullable، مفهرسة، ومختبرةٌ على SQLite+MySQL** (§63-73، وقاعدةُ CLAUDE.md ثنائيةِ المحرّك). عرضُ أعمدة enum/kind/status سخيٌّ (درسُ `notifications_hub.kind`). `ORDER BY` حتميٌّ (id tie-break).

**التعاون (§3-8):**
- `conversations` — uuid id; kind(company/dept/team/project/client/issue/incident/task/ticket/change/record/dm/feed); company_id/client_id/project_id nullable; module+record_id (polymorphic context); audience(internal/client/both) default internal; visibility(internal_public/role/team/project/private/client_shared/restricted); title; archived_at; created_by; softDeletes. **idx:** company_id, client_id, project_id, kind, (module,record_id), audience.
- `conversation_members` — uuid id; conversation_id; user_id; role(owner/moderator/member/guest); source(inherited/explicit); last_read_at; muted; joined_at. **idx:** unique(conversation_id,user_id), (user_id), (conversation_id,role).
- **تعديلٌ إضافيّ:** `comments` += conversation_id(uuid) + audience default internal (تعميمُ internal، لا flag ثانٍ). **idx:** (conversation_id,created_at). `dm_messages` += conversation_id + company_id/client_id/audience.

**عزل العميل (§12-16):**
- `client_memberships` — uuid; client_id; user_id; role(owner/lead/technical/finance/viewer); status(invited/active/suspended); invited_by/at; activated_at; softDeletes. **idx:** unique(client_id,user_id), (user_id,status), (client_id,role), (client_id,status). **مصدرُ الحقيقةِ** لـhub_client_ids؛ users.clients يُشتقّ.
- `account_activations` — uuid; user_id; email; token_hash(unique); otp_hash; otp_expires_at; consumed_at; ip. (على نمط e-sign OTP؛ لا كلمةَ سرٍّ تُخزَّن/تُرسَل).
- **تعديل:** `users` += account_type string(20) default 'internal' NOT NULL, indexed. audience(internal/client/both) default internal على المستندات/الرسائل القابلةِ للمشاركة (تعميمُ engagements.client_note).

**التسليم (§9-17):**
- `project_members` — id; project_id; user_id; role(owner/manager/member/guest); source(explicit/inherited). **idx:** unique(project_id,user_id), (user_id). (يُبقي members JSON متزامناً للتوافق).
- **تعديل:** `projects` += audience default internal + source_quote_id + hold_reason/blocked. **idx:** (audience,status),(source_quote_id),(engagement_id),(client_id,status). (لا جدولَ رسائلِ مشروع — الغرفتان تركبان رصيف §3-8 عبر project_id+audience).

**العهدة المالية (§18-21):**
- `employee_custody_moves` — uuid; employee_id; company_id; kind(varchar 48+ للأنواع العشرة); amount decimal(16,3); currency; sign/direction; source_module+source_id; entry_id FK→journal_entries; cc_id; project_id; reverses_id(self); approval_state; receipt(module,record_id); posted_at; by_id; at; meta. **لا عمودَ رصيد، لا softDeletes (عكسٌ فقط).** **idx:** (employee_id,at,id), (company_id), **unique(source_module,source_id,kind)**, (entry_id), (reverses_id), (project_id), (cc_id). (يُرحِّل داخل journal_entries عبر `$posting`؛ حسابُ `custody` يُضاف لـfinance.accounts).

**الاتصالات (§22-24):**
- `carriers` — uuid; name; code; country; portal_url; support_phone; account_no; company_id; meta; archived; softDeletes (وحدةُ config عامة، لا متحكّمَ خاص؛ creds عبر VaultSecret refs). **idx:** (company_id).
- **تعديل:** `phone_numbers` += line_type/iccid(unique)/imsi/msisdn/apn/pin(sec)/puk(sec) + plan_name/plan_data/plan_voice/plan_sms/roaming/billing_cycle + carrier_id/employee_id/device_id/client_id/station_id + activated_at/deactivated_at + توسيعُ status. **idx:** unique(iccid), carrier_id, employee_id, device_id, client_id, line_type, (status,expiry).

**الأصول والمحطات (§25-32):**
- `stations` — uuid; code(unique gen); name; company_id; project_id; type/dept/facility/floor/zone/room/desk; status; current_employee_id(nullable); custom/meta; version; archived; softDeletes (وحدةُ config؛ **لا client_id** — داخليّةٌ فقط). **idx:** unique(code), company_id, status, type, dept, current_employee_id.
- `station_assignments` — id; station_id; user_id(nullable); company_id; action(إسناد/إخلاء); at(date cast); note; by_id; softDeletes. **idx:** (station_id,at)+id، (user_id).
- `inventory_sessions` — id; code; scope_type/scope_ref; company_id; status(مسودة/مجمّدة/قيد المسح/مغلقة); frozen_at; n_expected/n_scanned/n_missing/n_extra/n_moved. **idx:** (company_id,status).
- `inventory_items` — id; session_id; asset_id; expected_holder_id/station_id/status; scanned; verdict(موجود/مفقود/غير متوقع/انتقل). **idx:** unique(session_id,asset_id), (session_id,verdict).
- `inventory_scans` — id; session_id; asset_id(nullable); code_scanned; result; station_id; by_id(required); scanned_at. **idx:** (session_id,result), (asset_id).
- **تعديل:** `assets` += station_id(nullable, locked field) · `asset_custody` += station_id/from_station_id.

**البنية (§37):** لا جدولَ nodes/edges (§33 صريح: إسقاطٌ لا شجرةٌ مخزّنة). **تعديل:** `servers` += station_id/asset_id/hr_id (refs معلَنة في config). قرارٌ معلَّق: `network_ip_allocations` (مطبَّع) فقط إن طُلب جردُ IP شبكيٍّ حقيقيّ، وإلا يُكتفى بـservers.ip. لقطاتُ الجراف في `SavedView` القائم.

**الأمن التكيّفي (§39-42):**
- `ip_rules` (Eloquent `IpRule` + Auditable لأجل FlowRunner Model-typed) — uuid; ip(45); is_cidr; mode(block/allow); origin(manual/auto); reason(190); severity; created_by; expires_at(NULL=دائم); escalation_level; hits; last_hit_at; revoked_at/by/reason; request_id. **idx:** (ip), (mode,expires_at), (expires_at), (origin). **تعديل:** `access_denials` += composite(ip,created_at). المفاتيح عبر Settings::put (لا جدول).

**النقاط الطرفية (§43-62):**
- `endpoint_devices` — uuid; device_uuid(unique); company_id; employee_id/asset_id/station_id(nullable); hostname/os/os_version/hw(json); agent_version; public_key(PEM)/pubkey_fp(unique); status; enrolled_at/last_heartbeat_at/last_ip; posture(json); policy_id; softDeletes. **idx:** unique(device_uuid,pubkey_fp), company_id, employee_id, asset_id, status, last_heartbeat_at.
- `enrollment_tokens` — uuid; token_hash(unique); company_id; employee_id/asset_id(nullable); label; expires_at(short); used_at; used_by_device_id. **idx:** unique(token_hash), company_id, expires_at, used_at.
- `endpoint_events` — id; device_id; company_id; kind(usb/posture/network_self/policy/agent); severity; summary(redacted); meta; nonce; request_id. **idx:** (device_id,created_at), (company_id,kind), severity, unique(device_id,nonce), request_id.
- `endpoint_commands` — id; device_id; company_id; type(ENUM allowlist — لا shell); args; state(queued/dispatched/acked/failed/expired); claimed/dispatched/acked_at; result/result_sig; attempts/next_at/expires_at; ikey; issued_by; request_id. **idx:** (device_id,state), (state,next_at), unique(device_id,ikey), request_id. (نمطُ outbox claim-transition).
- `endpoint_policies` — id; company_id; name; usb_mode(audit/alert/block_unknown/block_allowlist/allow_approved); usb_allowlist; enforce(default false = «Audit only / requires MDM»); posture_checks. **idx:** (company_id).
- **توافقٌ رجعيّ:** كلُّ جدولٍ جديدٍ خارج سجل الوحدات يُضاف إلى `HubBackup` (وإلا يسقط `BackupRestoreTest`).

---

### SECURITY RISKS TO ADDRESS

**مخاطرُ التكرار (كلُّ واحدةٍ خرقٌ للتوجيه الآمر):**
1. محرّكُ رسائلَ ثانٍ (Message/messages) بدل Comment+conversation_id — Comment يملك mentions/reactions/read/pin/resolve/attach/task-link.
2. RBAC/عضويةٍ ثانية (channel_permissions JSON، client-permission matrix، project ACL) بدل جداولٍ مطبَّعة تُحلّ عبر Role/hub_can/hub_scope. §5/§11 صريحان: مطبَّعة لا JSON، لا RBAC موازٍ.
3. سجلُّ رقابةٍ/وصولٍ ثانٍ بدل `audits` عبر hub_audit — AuditEntry يحمل viewer/reason/ip/request_id/record_id.
4. دفترُ محاسبةٍ ثانٍ / رصيدٌ محرَّر — العهدة تُرحِّل داخل JournalEntry عبر `$posting`؛ الرصيد=SUM(signed)؛ ممنوعٌ عمودُ balance (BankAccount.balance ليس قدوةً هنا).
5. سجلُّ SIM/carrier/station/endpoint/asset ثانٍ — تُطوَّر phone_numbers، وتُبنى stations/carriers/endpoints كوحداتٍ في السجل تركب المحرّك العام؛ الأصولُ المُدارةُ تربط asset_id لا تكرّر الجرد.
6. معرّفُ ارتباطٍ ثانٍ — `Api::requestId` الوحيد يُنقل إلى كل صفٍّ جديد (endpoint/ip/conversation/custody).
7. محرّكُ إشعاراتٍ/تنبيهاتٍ/presence/CIDR/QR/Identity ثانٍ — hub_notify، AlertEngine dedup، DmController::presence، ip_allowed، Qr/Identity.
8. مصادقةٌ تُضعِف الأمن: bearer مشترك للوكيل — الوكيلُ **موقَّعٌ لكل جهاز** (middleware جديد يلفّ حراسة ApiAuth).

**قواعدُ الإنفاذ الصلبة (الحرسُ في المتحكّم/الخدمة لا في إخفاء الرابط — §88-90):**
- كلُّ قراءة/كتابةِ محادثةٍ تمرّ hub_scope+hub_can+conversation_members؛ القنواتُ المرتبطةُ بسجلٍّ ترث نطاقَ السجل (guardTarget).
- **إصلاحُ تسريب feed:** نطاقُ شركة + audience='internal'؛ حسابُ العميل يفشل 404 على القنوات الداخلية. **إصلاحُ تسريب DM:** وسمُ client/audience + حارسٌ يمنع مراسلةَ/تعدادَ مستخدمين داخليين اعتباطاً.
- **PortalGuard:** account_type='client' → المساراتُ الداخلية 404 (مفضَّل)؛ البنية/الأمن/المالية/التدقيق داخليّةٌ بالقاعدة مهما قالت المصفوفة (لا يُكتفى بـwhereIn — جداولُ البنية بلا client_id). IDOR: A → 404 (لا 403) على سجلات/ملفات/رسائل/بنية B.
- **§6 رقابةُ الاتصالات** صلاحيةٌ عالية الخطورة (role flag): step-up + سببٌ إلزاميّ + قراءةٌ لا تقلب read_at/read_by + لا تحرّر رسالةَ غيره + أثرٌ كامل بالسلسلة.
- **§8 الأوامر** تعمل فقط بعد hub_can(op) على الوحدة الهدف + hub_scope على السجل الهدف، تنادي الخدمةَ الحقيقية، تكتب أثراً، تضع ربطاً عكسياً، وترفض المجهول/غير المصرّح (لا أمرَ ميت).
- **العهدة:** posted move يرفض update/delete (422) عبر booted guards؛ **UNIQUE(source_module,source_id,kind)+entry_id** يمنع الترحيلَ المزدوج (القفلُ وحده لا يكفي)؛ step-up على العكس/التصحيح؛ الشحنُ من بنكٍ يمرّ رصيفَ FinController (currency+scope+e).
- **الأسرار:** مراجعُ VaultSecret فقط في الجراف/مساحة العمل/الاتصالات/الحظر؛ PIN/PUK/creds كـ`sec`/vault؛ revealSecret خادميٌّ بأثر «عرض حساس» + step-up؛ لا قيمةَ سرٍّ في HTML/CSV/JSON.
- **الجراف:** كلُّ عقدةٍ/حافّةٍ/عدّادٍ يُرشَّح على الخادم عبر hub_read؛ ممنوعٌ إرسالُ الكلّ ثم إخفاءُ JS؛ مفتاحُ الخبيئة يحمل بصمةَ القارئ (hub_scope_key)؛ مكتبةٌ محلية (script-src) لا CDN.
- **دفاع IP:** إدارةُ الحظر owner-only + step-up + أثر SECURITY_POLICY_CHANGED؛ **fail-open** على أيّ خطأ (جدولٌ مكسورٌ لا يُسقط الخدمة)؛ **allow/trusted_ips تفوز**؛ المالكُ المصادَق لا يُحظر أبداً؛ auto-block معطَّلٌ افتراضياً ولا يشتعل على فشلٍ واحد؛ حالةٌ صادقة (Application Block: ACTIVE / Network Edge: NOT CONFIGURED).
- **النقاط الطرفية:** enrollment token لمرّةٍ قصير + step-up؛ الخادمُ يخزّن public key فقط؛ توقيعٌ ES256 على (timestamp.nonce.body) + ±300s + nonce فريد؛ رفضٌ عبر-الشركة (404)؛ أوامرُ allowlist لا shell (isolate/lock/wipe → step-up+سبب+أثر)؛ ingest يرفض حقولَ المراقبة (fail-closed)؛ posture صادقة؛ binaries عبر AttachmentController لا public.

---

### TESTS TO ADD

> **فشلٌ أولاً، وعلى المحرّكين (SQLite + `phpunit.mysql.xml`).** الأسسُ الجاهزة للتقليد: `CompanyIsolationTest`, `ClientOperationsTest:122-153`, `AuditScopeLeakTest`, `TenancyLeakRound8Test`, `SearchDmLeakTest`, `FieldPermissionBypassTest`, `ColumnFitsItsWriterTest`. `seedCore()` يعطّل sec.hours_on/step-up/watchdog افتراضياً — أعِدْ تفعيلَها. 404 لا 403 للسجل الأجنبيّ. أكِّدْ على **كل** الصفوف لا واحدٍ (قرعةُ الترتيب).

- **عزلُ العميل/IDOR (§74):** عميلُ A → 404 على client/project/engagement/document/attachment/dm/comment/servers/audit/finance لـB وعلى الداخليّ؛ حسابُ عميلٍ → 404 على m/servers,m/audit,security,costs,phones,stations,endpoints (يثبت أن المصفوفةَ المُخطئة لا تُسرِّب)؛ حسابٌ بلا عضويةٍ يرى بوابةً صادقةً فارغة.
- **التعاون:** غيرُ العضو لا يقرأ قناةً خاصة؛ رقابةٌ بلا step-up→428، بلا سبب→رفض، لا تقلب read_at، لا تحرّر رسالةَ غيره؛ audits row فيه viewer/reason/record_id=conversation/ip/request_id؛ أمرُ /task ينشئ Task حقيقياً بـhub_can+scope، غيرُ المصرّح→403، المجهول→رفضٌ صريح، ربطٌ عكسيّ؛ رجعةُ feed: شركتان لا تريان منشوراتِ بعضهما.
- **التفعيل (§12):** email→OTP→كلمةُ سرٍّ ينشئها هو؛ لا كلمةَ سرٍّ في التخزين/OutboxMessage؛ OTP single-use+expiry+throttle؛ hub_password_rule.
- **العهدة (§18-21):** +500 ثم -200 → رصيدٌ مشتقٌّ 300 (لا عمودَ balance)؛ اعتمادٌ مرتين → حركةٌ واحدة + JournalEntry واحد (UNIQUE)؛ posted يرفض update/delete 422؛ العكسُ يُنشئ صفاً معاكساً + قيدَ عكس؛ step-up على العكس؛ عزلُ الشركة/العميل؛ مصالحةُ PayrollLine.advance بلا ازدواج.
- **المحطات/الأصول (§25-32):** code فريدٌ مُولَّد + retry على 23000؛ s/{code}: ضيف→login، خارج الشركة→404؛ إسناد/إخلاء يكتب صفاً ويحدّث current_employee_id؛ **بقاءُ التاريخ بعد المغادرة**؛ أصلٌ يُسنَد لمحطةٍ **أو** موظف (لا إجبار)؛ رمزُ QR ثابتٌ عبر النقل؛ جردٌ: موجود/مفقود/غير متوقع/انتقل + غير معروفٍ لأصلٍ خارج النطاق (لا تسريب) + by_id لكل مسح؛ step-up للإغلاق/المصالحة.
- **الاتصالات (§22-24):** عزلُ شركةٍ على index/show/export/api؛ عزلُ حساب عميل؛ PIN/PUK لا يظهران في HTML/CSV (••••)+revealSecret أثر؛ **ICCID فريدٌ على المحرّكين**؛ صرامةُ العرض (over-length يُرفَض على MySQL)؛ carrier_id options منطَّقة؛ 360 عكسيّ حتميُّ الترتيب.
- **دفاع IP (§39-42):** إنفاذُ web+API؛ **allow/trusted تفوز**؛ انتهاءُ المؤقت + prune؛ المالكُ/trusted لا يُحظر؛ سلّمُ التصعيد 15د→ساعة→24س؛ IPv4/IPv6/CIDR عبر ip_allowed؛ step-up + أثر؛ IDOR على security.blocks؛ **fail-open** عند غياب/كسر الجدول؛ auto-block معطَّلٌ افتراضياً ولا يشتعل على فشلٍ واحد؛ حالةٌ صادقة. ✎ (`IpIntelTest` يغطّي التجميع، `SupportTest:57-77` يغطّي ip_allowed — يُعاد استخدامهما).
- **أمنُ API للنقاط (§74-81):** token منتهٍ/معاد؛ توقيعٌ خاطئ/nonce معاد/timestamp قديم(>300s)؛ انتحالُ جهاز؛ عبر-الشركة (404)؛ أمرٌ غير مصرّح (allowlist)؛ توقيعُ النتيجة؛ heartbeat rate 429؛ **حدُّ الخصوصية:** ingest يرفض keylog/screenshot/clipboard/file-content/browsing/network-scan (fail-closed)؛ الخادمُ يخزّن public key فقط. + اختباراتُ Go (signing/enrollment/replay/policies/command/update، amd64/arm64).
- **الهجرات والمخطط:** unique/الفهارس الجديدة على MySQL؛ عرضُ kind/status/usb_mode يسع القيم؛ ترتيبٌ حتميّ (at,id)؛ HubBackup يغطّي الجداول الجديدة؛ SettingsCenter: كلُّ مفتاحٍ جديدٍ مسجَّل؛ **إعادةُ توليد `docs/openapi.json`** بعد وحدات/مسارات/VERSION (بوّابةُ CI خارج phpunit).

---

### CLASSIFICATION TABLE

> كلُّ بندٍ في المواصفة، حالتُه، الدليلُ (file:line / table.column / route)، والتحقّق. `✎`=مصحَّحٌ (يفوز).

#### A · عزل العميل والبوابة والتفعيل (§12-16)
| §  | القدرة | الحالة | الدليل | تحقّق |
|----|--------|--------|--------|-------|
| §12 | تفعيلُ حسابٍ آمن (email→OTP→كلمةُ سرّ) | MISSING | لا account_activations؛ AuthController تسجيلُ دخولٍ فقط | COMMAND_VERIFIED |
| §13 | عضويةٌ مطبَّعة بأدوارٍ لكل عميل | MISSING | users.clients JSON فقط؛ لا client_memberships | COMMAND_VERIFIED |
| §14 | تصنيفٌ صريح internal/client | EXISTS_WEAK | لا users.account_type (probe cols)؛ عميل=clients!==null | COMMAND_VERIFIED |
| §14 | PortalGuard (عميل→404 داخليّ) | MISSING | مجموعةُ auth واحدة web.php:108؛ لا middleware بوابة | COMMAND_VERIFIED |
| §15 | عزلُ IDOR عدائيّ شامل | PARTIAL `✎` | الوحداتُ عبر findScoped 404؛ الملفات/المرفقات **تمرّ** hub_scope (Attachment:386,File:188)؛ الفجوة: comments/dm/documents بلا client_id، DataRoom/Dm لا يستدعيان hub_scope | STATICALLY_REVIEWED |
| §16 | audience صريح (internal/client/both) افتراضُه داخليّ | MISSING `✎` | policies/kb_articles.audience موجودة لكن all/project/company (2026_07_31_000023)؛ internal/client/both غائب | COMMAND_VERIFIED |
| §13/§98 | بوابةُ عميلٍ أبسطُ عمداً | MISSING | resources/views/portal = me/employee فقط | COMMAND_VERIFIED |
| §63/§98 | توفيرٌ آليٌّ لمساحة العميل على القبول | PARTIAL | toProject idempotent موجود لكن بلا membership/activation/event | STATICALLY_REVIEWED |

#### C · التعاون والرسائل (§3-8)
| §  | القدرة | الحالة | الدليل | تحقّق |
|----|--------|--------|--------|-------|
| §3 | Spaces/Channels/Threads/DMs/سجل | PARTIAL | DM+record-comments فقط؛ feed قناةٌ عامةٌ واحدة (CommentController:25) | COMMAND_VERIFIED |
| §4 | أنواعُ قنوات مطبَّعة | MISSING | لا conversations.kind؛ config channel=intake enum | COMMAND_VERIFIED |
| §5a | سياقُ القناة (project/client/company/audience) | MISSING | لا أعمدةَ سياق على comments/dm (probe) | COMMAND_VERIFIED |
| §5b | نموذجُ رؤية | MISSING | لا visibility؛ feed عامّ | STATICALLY_REVIEWED |
| §5c | عضويةٌ مطبَّعة (owner/mod/member/guest) | MISSING | read_by JSON فقط | STATICALLY_REVIEWED |
| §6 | رقابةُ الاتصالات (امتثالٌ ظاهر) | MISSING | لا مسار؛ DmController:133-134 يقلب read_at؛ Comment/Dm ليسا Auditable | STATICALLY_REVIEWED |
| §7 | reactions/att/mentions/read/pin/resolve/comment→task | EXISTS_STRONG | كلها على Comment (CommentController react/toTask/pin/resolve) | STATICALLY_REVIEWED |
| §8 | أوامرُ محادثة (/task /issue /assign) | MISSING | لا parser (grep)؛ toTask:154-202 هو القدوة | COMMAND_VERIFIED |
| §6/§74 | عزلُ العميل للمحادثات/DM/feed | MISSING | feed+dm بلا نطاق؛ auth group عامّ | STATICALLY_REVIEWED |
| §63-73 | بحثٌ/correlation/أحداثٌ للمحادثات | PARTIAL | Comment/Dm ليسا Searchable؛ HubEvents+requestId جاهزان | COMMAND_VERIFIED |

#### D · التسليم والمشاريع/PSA (§9-11,17)
| §  | القدرة | الحالة | الدليل | تحقّق |
|----|--------|--------|--------|-------|
| §9 | غرفتان منفصلتان (internal/client) | MISSING | لا audience على projects/comments؛ internal tickets-only | COMMAND_VERIFIED |
| §10 | تحويلٌ idempotent (transaction+lock) | EXISTS_STRONG | toProject:368-461 lockForUpdate+meta.project_id guard | STATICALLY_REVIEWED |
| §10 | Engagement+Project+Plan+baseline بلا تكرار بيانات | EXISTS_STRONG | toProject:413-447 references لا نسخ | STATICALLY_REVIEWED |
| §11 | مركزُ قيادةٍ بتبويبات | PARTIAL | بطاقاتٌ مسطّحة، لا tabs/غرف (show+custom/projects) | STATICALLY_REVIEWED |
| §17 | PSA lifecycle + عروضٌ تشغيلية | PARTIAL | at-risk موجود (health<55)؛ لا حالةَ waiting/blocked ولا لوحةَ PSA | STATICALLY_REVIEWED |
| §17 | فوترةُ معالمَ بلا ازدواج + سقف | EXISTS_STRONG | msInvoice + liveMilestoneInvoicedTotals | STATICALLY_REVIEWED |
| §11/§17 | ChangeOrder يطوّر baseline | EXISTS_STRONG | apply idempotent(applied_at)+lock | STATICALLY_REVIEWED |
| §12-16 | عزلُ العميل على غرف/صفحات المشروع | EXISTS_WEAK | hub_scope يعزل لكن hub_related بلا audience filter؛ لا portal guard | STATICALLY_REVIEWED |
| §63-73 | حدثُ client_workspace_created/project.provisioned | MISSING | hub.events فيه quote.converted لا هذا | COMMAND_VERIFIED |
| §9 | عضويةُ مشروعٍ مطبَّعة | EXISTS_WEAK | members JSON + whereJsonContains (User:79-88) | STATICALLY_REVIEWED |
| §11 | baseline لقطةٌ ثابتة | EXISTS_STRONG | project.meta.baseline؛ CO يُلحق لا يمسّ | STATICALLY_REVIEWED |

#### E · العهدة المالية (§18-21)
| §  | القدرة | الحالة | الدليل | تحقّق |
|----|--------|--------|--------|-------|
| §18 | محفظةُ موظفٍ على دفترٍ غيرِ محرَّر | PARTIAL | GL immutable قويّ (JournalEntry:52-78)؛ لا subledger مفتاحُه employee | STATICALLY_REVIEWED + COMMAND_VERIFIED |
| §19 | عشرةُ أنواعِ حركات | MISSING | PayrollLine.advance + leave 'سلفة' فقط | STATICALLY_REVIEWED |
| §20 | لا حذف — عكوسٌ فقط | EXISTS_STRONG | StockMove:68-76 / JournalEntry:52-60 قدوةٌ جاهزة | STATICALLY_REVIEWED |
| §21a | تكاملٌ مع المحاسبة (لا دفترَ ثانٍ) | EXISTS_STRONG | FinController/PayrollController autoJournal `$posting` | STATICALLY_REVIEWED |
| §21b | منعُ الترحيل المزدوج (مرجعٌ فريد+idempotency) | EXISTS_WEAK | القفلُ+meta.posted_at فقط؛ **journal_entries.fin_id غير فريد** (unique=0) | COMMAND_VERIFIED |
| §21c | منفصلٌ عن عهدة الأصول | EXISTS_STRONG | asset_custody أصلٌ فقط؛ جدولٌ مستقلّ يُرضي بالبناء | STATICALLY_REVIEWED |
| §28 | تبويبُ FinancialCustody في 360 | MISSING | grep العهدة المالية = 0 | COMMAND_VERIFIED |
| §74-81 | اختباراتُ العهدة الإلزامية | MISSING | لا مراجعَ custody في tests/ | COMMAND_VERIFIED |
| §98-102 | سيناريو +500→اعتماد→ترحيل-مرةً→عكس | MISSING | لا دورةَ حياة؛ Approval/Attachment جاهزان | STATICALLY_REVIEWED |

#### F · المحطات والأصول + الموظف 360 (§25-32,§28)
| §  | القدرة | الحالة | الدليل | تحقّق |
|----|--------|--------|--------|-------|
| §25 | كيانُ Station (مقعدٌ دائم) | MISSING | لا نموذج/جدول/عمود station (grep)؛ Facility صحّيّةٌ لا تُعاد | COMMAND_VERIFIED |
| §26 | تاريخُ إسناد المحطة | MISSING | لا station_assignments؛ asset_custody قدوة | COMMAND_VERIFIED |
| §26 | QR يتطلب دخولاً | EXISTS_STRONG | c/{code} داخل auth+byCode scoped (CustodyController:129-136) | COMMAND_VERIFIED |
| §27 | صفحةُ محطةٍ 360 | MISSING | لا StationController/view؛ PortalController::employee قدوة | COMMAND_VERIFIED |
| §29 | أصلٌ لموظفٍ أو محطة | PARTIAL | holder=User فقط (Asset:118)؛ لا assets.station_id (probe) | COMMAND_VERIFIED |
| §30 | حالاتُ الأصل الإحدى عشرة | EXISTS_WEAK | status string(80) لكن options=5 (hub.php:4586) | COMMAND_VERIFIED |
| §31 | تاريخُ نقلٍ يحفظ QR؛ أصولٌ بلا مالك سليمة | EXISTS_STRONG | asset_custody؛ code لا يُعاد توليده | STATICALLY_REVIEWED |
| §32 | جلساتُ جرد (تجميد/مسح/مقارنة/تصنيف) | MISSING | لا inventory_*؛ 'الجرد' سرديٌّ (AssetLife:147)؛ Identity::resolve قدوة | COMMAND_VERIFIED |
| §28 | تبويباتُ الموظف 360 المُحرَّسة | PARTIAL | bundle/workProfile مجمِّعٌ لكن بطاقاتٌ لا tabs؛ تبويباتٌ ناقصة | STATICALLY_REVIEWED |
| §28 | تبويبُ عهدة الأصول قائم | EXISTS_STRONG | assets where holder_id (PortalController:122) | STATICALLY_REVIEWED |

#### G · الاتصالات (§22-24)
| §  | القدرة | الحالة | الدليل | تحقّق |
|----|--------|--------|--------|-------|
| §22 | تطويرُ PhoneNumber (لا جدولَ SIM ثانٍ) | PARTIAL | phone_numbers موجود يخدمه المحرّك العام؛ حقولُ الأصل ناقصة | STATICALLY_REVIEWED |
| §22 | SIM/eSIM/ICCID/IMSI/MSISDN/PIN/PUK/APN | MISSING | grep=0؛ لا iccid/carrier_id (probe) | COMMAND_VERIFIED |
| §22 | carrier reference | EXISTS_WEAK | carrier نصٌّ حر (config:1644) | STATICALLY_REVIEWED |
| §22 | plan/data/voice/sms/roaming/billing | PARTIAL | cost+sms(bool)+wa فقط | STATICALLY_REVIEWED |
| §22 | دورةُ حياةٍ بحفظ التاريخ | PARTIAL | status=3 قيم؛ التاريخ محفوظٌ بـRecordVersion/timeline | STATICALLY_REVIEWED |
| §22 | ربطُ موظف | MISSING | owner_id→users(مسؤول) لا employee؛ لا ref→hr | STATICALLY_REVIEWED |
| §22 | ربطُ جهاز | MISSING | لا device_id/ref→assets | STATICALLY_REVIEWED |
| §22 | ربطُ محطة | MISSING | لا Station أصلاً (تبعيةٌ لـ§25) | COMMAND_VERIFIED |
| §22 | ربطُ مشروع/شركة | EXISTS_STRONG | company_id+project_id منطَّقان | STATICALLY_REVIEWED |
| §22 | خطٌّ مملوكٌ للعميل | MISSING | لا client_id (hub_client_col('phones')=null) | STATICALLY_REVIEWED |
| §23 | سجلُّ مزوّدين قابلٌ للضبط | MISSING | لا carriers؛ suppliers=AP عامّ | COMMAND_VERIFIED |
| §24 | دورةُ حياةٍ كاملةٌ بحفظ التاريخ | PARTIAL | audit+versions+timeline؛ لا سجلَّ إسنادٍ دلاليّ | STATICALLY_REVIEWED |
| §28 | تبويبُ Telecom في الموظف 360 | PARTIAL | hub_related جاهز؛ ينقص ref→hr على phones | STATICALLY_REVIEWED |

#### H · البنية التقنية + مستكشف العلاقات (§33-38)
| §  | القدرة | الحالة | الدليل | تحقّق |
|----|--------|--------|--------|-------|
| §37 | مساحةُ عملٍ تقنية موحّدة | PARTIAL | القطعُ وحداتٌ منفصلة+DigitalAssets؛ لا صفحةَ جمع | COMMAND_VERIFIED |
| §37 | مراجعُ أسرارٍ لا قيَم | EXISTS_STRONG | VaultSecret EncryptedOrPlain+revealSecret:377 | STATICALLY_REVIEWED |
| §37/§13 | عزلُ البنية عن العميل | EXISTS_WEAK | يرتكز على المصفوفة؛ جداولُ البنية بلا client_id؛ لا حارسَ صلب | STATICALLY_REVIEWED |
| §33 | خدمةُ إسقاطٍ (لا شجرةٌ مخزّنة) | PARTIAL | hub_build_children_map(1047) قفزةٌ واحدة؛ لا متعدّدة | STATICALLY_REVIEWED |
| §33 | وضعا شجرة+جراف | MISSING | grep graph/tree/explorer routes/views=0 | COMMAND_VERIFIED |
| §33 | ترشيحٌ خادميٌّ كامل | EXISTS_WEAK | hub_related يفرض can+scope(3108-3129)؛ لا إسقاطَ متعدّداً بعد | STATICALLY_REVIEWED |
| §34 | مكتبةٌ محلية + بديلٌ نصّيّ | MISSING | لا cytoscape/vis/d3؛ سابقةُ leaflet self-host | COMMAND_VERIFIED |
| §33 | توسّعٌ تدريجيّ | MISSING | hub_related limit+1(3122) لبنةٌ بلا واجهة | STATICALLY_REVIEWED |
| §63-73 | مدخلُ جرافٍ من كل سجل | PARTIAL | modules.show يعرض hub_related؛ لا زرّ مستكشف | STATICALLY_REVIEWED |
| §37 | جردُ IP شبكيّ | MISSING | IpAsset=ملكيةٌ فكرية؛ servers.ip نصّ حر | STATICALLY_REVIEWED |
| §37 | حوافُ servers→station/asset/hr | MISSING | Server يربط project/company/owner فقط | STATICALLY_REVIEWED |
| §63-73 | إعادةُ معرّف الارتباط | EXISTS_STRONG | Correlation.php+SystemTraceController | COMMAND_VERIFIED |

#### I · الدفاع التكيّفي عن IP (§39-42)
| §  | القدرة | الحالة | الدليل | تحقّق |
|----|--------|--------|--------|-------|
| §39 | توسيعُ SecurityRadar/AccessRadar | EXISTS_STRONG | يسجّلان 403/404-guess per-IP (SecurityRadar:24-209) | STATICALLY_REVIEWED |
| §39 | تجميعُ فشلِ الدخول/403/knocking | EXISTS_STRONG | AlertEngine::fireSource:344-375 (spray+dedup+incident) | STATICALLY_REVIEWED |
| §40 | خطرٌ مُفسَّرٌ لا مخترَع | EXISTS_STRONG | Risk::session/activity factors(59-123) | STATICALLY_REVIEWED |
| §41 | قوائمُ IP سماح/حظر (مؤقت/دائم/آليّ) | MISSING | grep ip_block/firewall/ban=0؛ users.allowed_ips فقط | COMMAND_VERIFIED |
| §41 | تصعيدٌ قابلٌ للضبط 15د→ساعة→24س | MISSING | lockout قيمةٌ ثابتةٌ واحدة (AuthController:212) | COMMAND_VERIFIED |
| §41 | حمايةٌ من حبس المالك | PARTIAL | lockdown owner-exempt+user_ips لكن غيرُ موصولٍ بالحظر | STATICALLY_REVIEWED |
| §41 | step-up+أثرٌ للتغييرات | EXISTS_STRONG | hub_require_stepup+Settings::batch(SecurityController:253) | STATICALLY_REVIEWED |
| §42 | حظرٌ تطبيقيٌّ يعمل في كل مكان | MISSING | لا middleware إنفاذ؛ AccessRadar يسجّل فقط | STATICALLY_REVIEWED |
| §42 | محوّلاتُ Cloudflare/Nginx اختيارية | MISSING | grep=0؛ Integrations webhooks/odoo فقط | COMMAND_VERIFIED |
| §42 | حالةٌ صادقة (ACTIVE/NOT CONFIGURED) | MISSING | SecurityPosture لا يملك فحصَ حظر | STATICALLY_REVIEWED |
| §68 | حدثُ ip_auto_blocked في FlowRunner | MISSING | لا انبعاث؛ HubEvents Model-typed → ip_rules لازمٌ Model | STATICALLY_REVIEWED |
| §74-81 | بطاريةُ اختباراتِ الدفاع | PARTIAL `✎` | IpIntelTest(تجميع)+SupportTest:57-77(ip_allowed) موجودان؛ الباقي غائب | STATICALLY_REVIEWED |

#### J/K/L · النقاط الطرفية + الوكيل + البناء (§43-62)
| §  | القدرة | الحالة | الدليل | تحقّق |
|----|--------|--------|--------|-------|
| §43 | خادمُ نقاطٍ لأجهزة الشركة | MISSING | لا endpoint_devices/module/routes (route:list+grep) | COMMAND_VERIFIED |
| §43 | تسجيلٌ بمفتاحٍ غير مشترك | MISSING | لا enrollment_tokens؛ Webauthn/Passkey قدوة | STATICALLY_REVIEWED |
| §43 | سجلُّ أجهزة (UUID/OS/hw/agent/posture) | MISSING | user_devices=cookie trust فقط (probe) | COMMAND_VERIFIED |
| §43 | heartbeat خفيفٌ مضبوط | MISSING | لا مسار؛ metric_points/Series قدوة | STATICALLY_REVIEWED |
| §43 | حدودُ الخصوصية (لا keylog/screenshot/…) | MISSING | لا ingest؛ Redactor::json للتنقية | STATICALLY_REVIEWED |
| §43 | «USB monitoring»=أحداثٌ لا نسخُ ملفات | MISSING | لا نموذج usb | COMMAND_VERIFIED |
| §43 | Wi-Fi للجهاز نفسه لا مسحٌ للشبكة | MISSING | لا تليمتري شبكة | COMMAND_VERIFIED |
| §43 | سياساتُ USB بأسبقيةٍ لا فرضٌ صامت | MISSING | لا endpoint_policies؛ enforce=false صادق | COMMAND_VERIFIED |
| §43 | posture صادقة | MISSING | SecurityPosture=فحصُ الهَب لا الجهاز | STATICALLY_REVIEWED |
| §43 | أوامرُ registry صارمة لا shell | MISSING | لا endpoint_commands؛ outbox قدوة | STATICALLY_REVIEWED |
| §43/§63 | أحداثٌ→مركزُ الأمن بعتبات | MISSING | SecurityEvents::CODES بلا ENDPOINT_* | STATICALLY_REVIEWED |
| §44/§62 | مركزُ تنزيلِ الوكيل (نسخة+SHA-256+توقيع) | MISSING | AttachmentController قدوة؛ لا مسارَ إصدار | STATICALLY_REVIEWED |
| §45 | مشروعُ agent/ بـGo | MISSING | ls agent = غير موجود | COMMAND_VERIFIED |
| §45 | تشفيرٌ/هويّةٌ لا تماثلية بلا shell/مراقبة | MISSING | عقدُ Webauthn ES256 للتوافق | STATICALLY_REVIEWED |
| §62 | بناءُ CI (msi/pkg، checksum، UNSIGNED إن لا شهادات) | MISSING | ci.yml=PHP فقط | STATICALLY_REVIEWED |
| §62 | تحديثٌ ذاتيٌّ بالتحقق | MISSING | لا وكيل؛ AttachmentController checksum سابقة | STATICALLY_REVIEWED |
| §62 | اختباراتُ Go | MISSING | لا agent/ | COMMAND_VERIFIED |
| §74-81 | اختباراتُ أمنِ API للنقاط | MISSING | لا endpoint tables؛ InboundHook/Webauthn/ApiAuth قدواتٌ للتقليد | STATICALLY_REVIEWED |
| §28 | تبويبُ EndpointSecurity في 360 | PARTIAL | employee/staff.blade موجودان؛ لا تبويب؛ hub_field_mode جاهز | COMMAND_VERIFIED |
| §63-73 | request_id على endpoint_events/commands + openapi | MISSING | Api::requestId جاهز؛ الجداولُ غائبة | STATICALLY_REVIEWED |

---
*انتهى — كلُّ بندٍ متتبَّعٌ إلى الحزمة أو تحقُّقٍ مباشر؛ probe.sqlite (READ-ONLY) وrepo grep يؤكّدان الأساسَ المخطَّطيّ. لا ملفٌّ متتبَّعٌ عُدِّل.*
