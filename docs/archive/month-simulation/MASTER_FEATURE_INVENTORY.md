# جردُ الميزاتِ الرئيس — الطور ١٧٤ (محاكاةُ شهرٍ كامل · ٣٠ موظّفاً)

> **مُولَّدٌ من المنتجِ نفسِه لا مكتوبٌ باليد** — من سجلّ الوحدات وسجلّ الميزات
> وكتالوج الصلاحيات ومعمارية المعلومات وجدول المسارات الحيّ.
> لا رقمَ هنا مُقدَّر؛ كلُّها مقيسة.

- **الرأس:** `1196f9d` · **النسخة:** `2.533.0` · **الفرع:** `claude/lynomia-hub-enterprise-upgrade-xn4p2t`

## ١ · الأرقامُ المقيسة

| المصدر | العدد |
|---|---|
| وحداتُ السجلّ (`hub_modules`) | **85** |
| مدخلاتُ سجلّ الميزات (`FeatureRegistry`) | **137** |
| الصلاحياتُ الدقيقةُ المُعلَنة (`config/hub_permissions`) | **19** |
| مجالاتُ معمارية المعلومات | **9** |
| المساحات (`hub_workspaces`) | **8** |
| الودجات (`WidgetRegistry`) | **10** |
| المساراتُ كلُّها | **630** |
| مساراتُ `GET` للويب (أسطحٌ بشريّة) | **218** |
| مساراتُ API | **119** |

## ٢ · سجلُّ الميزات — بالمجال

### collaboration — 28 ميزة

| المفتاح | العنوان | مسارُ الويب الأوّل | الصلاحيّات | قابلةُ الإطفاء |
|---|---|---|---|---|
| `collab.archive` | collab.archive | `—` | — | لا |
| `collab.center` | collab.center | `collab.center` | — | لا |
| `collab.channels` | collab.channels | `conversations.index` | — | لا |
| `collab.chat_commands` | collab.chat_commands | `—` | tasks, issues | لا |
| `collab.client_rooms` | collab.client_rooms | `—` | — | لا |
| `collab.directory` | collab.directory | `conversations.directory` | — | لا |
| `collab.dm` | collab.dm | `dm.inbox` | — | لا |
| `collab.event_contract` | collab.event_contract | `—` | — | لا |
| `collab.favorites` | collab.favorites | `—` | — | لا |
| `collab.group_dm` | collab.group_dm | `groups.index` | — | لا |
| `collab.incremental_polling` | collab.incremental_polling | `—` | — | لا |
| `collab.internal_rooms` | collab.internal_rooms | `—` | — | لا |
| `collab.link_previews` | collab.link_previews | `—` | — | لا |
| `collab.mentions` | collab.mentions | `—` | — | لا |
| `collab.notify_prefs` | collab.notify_prefs | `—` | — | لا |
| `collab.pins` | collab.pins | `—` | — | لا |
| `collab.presence` | collab.presence | `—` | — | نعم |
| `collab.private_channels` | collab.private_channels | `—` | — | لا |
| `collab.reactions` | collab.reactions | `—` | — | لا |
| `collab.record_discussions` | collab.record_discussions | `—` | — | لا |
| `collab.saved` | collab.saved | `saved.index` | — | لا |
| `collab.search` | collab.search | `search.messages` | — | لا |
| `collab.threads` | collab.threads | `—` | — | لا |
| `collab.typing` | collab.typing | `—` | — | نعم |
| `collab.unread` | collab.unread | `—` | — | لا |
| `collab.voice_notes` | collab.voice_notes | `—` | — | لا |
| `collab.websocket_provider` | collab.websocket_provider | `—` | — | لا |
| `collab.websocket_readiness` | collab.websocket_readiness | `—` | — | لا |

### endpoint — 25 ميزة

| المفتاح | العنوان | مسارُ الويب الأوّل | الصلاحيّات | قابلةُ الإطفاء |
|---|---|---|---|---|
| `endpoint.agent` | endpoint.agent | `—` | — | لا |
| `endpoint.alerts` | endpoint.alerts | `—` | — | لا |
| `endpoint.anti_replay` | endpoint.anti_replay | `—` | — | لا |
| `endpoint.apple_notarization` | endpoint.apple_notarization | `—` | — | لا |
| `endpoint.commands` | endpoint.commands | `—` | — | لا |
| `endpoint.device_inventory` | endpoint.device_inventory | `—` | — | لا |
| `endpoint.enrollment` | endpoint.enrollment | `—` | endpoints | لا |
| `endpoint.identity` | endpoint.identity | `—` | — | لا |
| `endpoint.intune` | endpoint.intune | `—` | — | لا |
| `endpoint.isolation` | endpoint.isolation | `—` | — | لا |
| `endpoint.jamf` | endpoint.jamf | `—` | — | لا |
| `endpoint.lock` | endpoint.lock | `—` | — | لا |
| `endpoint.macos` | endpoint.macos | `—` | — | لا |
| `endpoint.macos_signing` | endpoint.macos_signing | `—` | — | لا |
| `endpoint.mdm` | endpoint.mdm | `—` | — | لا |
| `endpoint.production_signing` | endpoint.production_signing | `—` | — | لا |
| `endpoint.release_distribution` | endpoint.release_distribution | `endpoints.releases` | — | لا |
| `endpoint.release_trust` | endpoint.release_trust | `—` | — | لا |
| `endpoint.signature_verification` | endpoint.signature_verification | `—` | — | لا |
| `endpoint.update` | endpoint.update | `—` | — | لا |
| `endpoint.usb_enforcement` | endpoint.usb_enforcement | `—` | — | لا |
| `endpoint.usb_observation` | endpoint.usb_observation | `—` | — | لا |
| `endpoint.wifi_posture` | endpoint.wifi_posture | `—` | — | لا |
| `endpoint.windows` | endpoint.windows | `—` | — | لا |
| `endpoint.windows_signing` | endpoint.windows_signing | `—` | — | لا |

### security — 18 ميزة

| المفتاح | العنوان | مسارُ الويب الأوّل | الصلاحيّات | قابلةُ الإطفاء |
|---|---|---|---|---|
| `security.alerts` | security.alerts | `—` | — | لا |
| `security.api_tokens` | security.api_tokens | `—` | — | لا |
| `security.app_ip_defense` | security.app_ip_defense | `—` | — | لا |
| `security.audit_chain_verification` | security.audit_chain_verification | `—` | — | لا |
| `security.audit_trail` | security.audit_trail | `—` | — | لا |
| `security.authorization` | security.authorization | `—` | — | لا |
| `security.auto_block` | security.auto_block | `—` | — | لا |
| `security.client_isolation` | security.client_isolation | `—` | — | لا |
| `security.company_scopes` | security.company_scopes | `—` | — | لا |
| `security.edge_blocking` | security.edge_blocking | `—` | — | لا |
| `security.idor_defense` | security.idor_defense | `—` | — | لا |
| `security.incident_investigation` | security.incident_investigation | `—` | — | لا |
| `security.ip_allow` | security.ip_allow | `—` | — | لا |
| `security.ip_block` | security.ip_block | `—` | — | لا |
| `security.portal_guard` | security.portal_guard | `—` | — | لا |
| `security.secret_encryption` | security.secret_encryption | `—` | — | لا |
| `security.session_management` | security.session_management | `mysec.index` | — | لا |
| `security.step_up` | security.step_up | `—` | — | لا |

### work_os — 14 ميزة

| المفتاح | العنوان | مسارُ الويب الأوّل | الصلاحيّات | قابلةُ الإطفاء |
|---|---|---|---|---|
| `workos.custody_ledger` | workos.custody_ledger | `—` | — | لا |
| `workos.custody_receipt` | workos.custody_receipt | `—` | — | لا |
| `workos.custody_reversal` | workos.custody_reversal | `—` | — | لا |
| `workos.daily_reports` | workos.daily_reports | `reports.index` | hr | لا |
| `workos.employee_360` | workos.employee_360 | `—` | hr | لا |
| `workos.financial_custody` | workos.financial_custody | `—` | custody | لا |
| `workos.global_search` | workos.global_search | `search` | — | لا |
| `workos.project_360` | workos.project_360 | `—` | — | لا |
| `workos.relationship_explorer` | workos.relationship_explorer | `graph.explore` | — | لا |
| `workos.station_360` | workos.station_360 | `—` | — | لا |
| `workos.station_history` | workos.station_history | `—` | — | لا |
| `workos.station_identity` | workos.station_identity | `—` | — | لا |
| `workos.stations` | workos.stations | `—` | stations | لا |
| `workos.tech_workspace` | workos.tech_workspace | `—` | — | لا |

### client — 12 ميزة

| المفتاح | العنوان | مسارُ الويب الأوّل | الصلاحيّات | قابلةُ الإطفاء |
|---|---|---|---|---|
| `client.activation` | client.activation | `—` | — | لا |
| `client.external_delivery` | client.external_delivery | `—` | — | لا |
| `client.internal_workspace` | client.internal_workspace | `—` | — | لا |
| `client.membership` | client.membership | `—` | clients | لا |
| `client.portal` | client.portal | `portal.home` | — | لا |
| `client.project_access` | client.project_access | `—` | — | لا |
| `client.psa` | client.psa | `—` | projects | لا |
| `client.quote_provisioning` | client.quote_provisioning | `—` | quotes, projects | لا |
| `client.safe_timeline` | client.safe_timeline | `—` | — | لا |
| `client.visible_deliverables` | client.visible_deliverables | `—` | — | لا |
| `client.visible_files` | client.visible_files | `—` | — | لا |
| `client.workspace` | client.workspace | `—` | — | لا |

### assets — 11 ميزة

| المفتاح | العنوان | مسارُ الويب الأوّل | الصلاحيّات | قابلةُ الإطفاء |
|---|---|---|---|---|
| `assets.asset_360` | assets.asset_360 | `—` | — | لا |
| `assets.company_assets` | assets.company_assets | `—` | assets | لا |
| `assets.custody_history` | assets.custody_history | `—` | — | لا |
| `assets.employee_assignment` | assets.employee_assignment | `—` | — | لا |
| `assets.endpoint_eligibility` | assets.endpoint_eligibility | `—` | — | لا |
| `assets.inventory_sessions` | assets.inventory_sessions | `—` | — | لا |
| `assets.project_assignment` | assets.project_assignment | `—` | — | لا |
| `assets.reconciliation` | assets.reconciliation | `—` | — | لا |
| `assets.relationship` | assets.relationship | `—` | — | لا |
| `assets.scan_resolution` | assets.scan_resolution | `—` | — | لا |
| `assets.station_assignment` | assets.station_assignment | `—` | — | لا |

### platform — 11 ميزة

| المفتاح | العنوان | مسارُ الويب الأوّل | الصلاحيّات | قابلةُ الإطفاء |
|---|---|---|---|---|
| `platform.api_surface` | platform.api_surface | `—` | — | لا |
| `platform.audit_tools` | platform.audit_tools | `audit.index` | — | لا |
| `platform.connection_checks` | platform.connection_checks | `—` | — | لا |
| `platform.endpoint_api_surface` | platform.endpoint_api_surface | `—` | — | لا |
| `platform.feature_registry` | platform.feature_registry | `features.index` | — | لا |
| `platform.integrations` | platform.integrations | `integrations.index` | — | لا |
| `platform.mobile_api_surface` | platform.mobile_api_surface | `—` | — | لا |
| `platform.monitor_analytics` | platform.monitor_analytics | `—` | — | لا |
| `platform.openapi_generation` | platform.openapi_generation | `—` | — | لا |
| `platform.system_trace` | platform.system_trace | `system.trace` | — | لا |
| `platform.webhook_infra` | platform.webhook_infra | `webhooks.index` | — | لا |

### telecom — 10 ميزة

| المفتاح | العنوان | مسارُ الويب الأوّل | الصلاحيّات | قابلةُ الإطفاء |
|---|---|---|---|---|
| `telecom.carrier_registry` | telecom.carrier_registry | `—` | — | لا |
| `telecom.carrier_vault` | telecom.carrier_vault | `—` | — | لا |
| `telecom.device_linkage` | telecom.device_linkage | `—` | — | لا |
| `telecom.employee_linkage` | telecom.employee_linkage | `—` | — | لا |
| `telecom.live_provisioning` | telecom.live_provisioning | `—` | — | لا |
| `telecom.phone_registry` | telecom.phone_registry | `—` | — | لا |
| `telecom.provisioning_adapter` | telecom.provisioning_adapter | `—` | — | لا |
| `telecom.sim_lifecycle` | telecom.sim_lifecycle | `—` | — | لا |
| `telecom.sim_registry` | telecom.sim_registry | `—` | phones | لا |
| `telecom.station_linkage` | telecom.station_linkage | `—` | — | لا |

### mobile — 8 ميزة

| المفتاح | العنوان | مسارُ الويب الأوّل | الصلاحيّات | قابلةُ الإطفاء |
|---|---|---|---|---|
| `mobile.auth_apis` | mobile.auth_apis | `—` | — | لا |
| `mobile.backend` | mobile.backend | `—` | — | لا |
| `mobile.collab_apis` | mobile.collab_apis | `—` | — | لا |
| `mobile.native_app` | mobile.native_app | `—` | — | لا |
| `mobile.openapi` | mobile.openapi | `—` | — | لا |
| `mobile.production_push` | mobile.production_push | `—` | — | لا |
| `mobile.store_release` | mobile.store_release | `—` | — | لا |
| `mobile.workos_apis` | mobile.workos_apis | `—` | — | لا |

## ٣ · مجالاتُ معمارية المعلومات وأقسامُها

| المجال | العنوان | الأقسام |
|---|---|---|
| `entities` | الكيانات والعلاقات | 4 |
| `work` | العمل والتسليم | 5 |
| `finance` | المالية والمشتريات | 5 |
| `hr` | الموظفون والموارد البشرية | 4 |
| `digital` | التقنية والبنية الرقمية | 5 |
| `legalws` | الأصول والعقود والامتثال | 4 |
| `fieldops` | العمليات الميدانية | 3 |
| `knowledge` | المعرفة والمستندات | 5 |
| `administration` | الإدارة والنظام | 5 |

## ٤ · المساحات

| المفتاح | العنوان | المراكز |
|---|---|---|
| `entities` | الكيانات والعلاقات | impact, recs |
| `digital` | التقنية والبنية الرقمية | appq, impact |
| `work` | العمل والعمليات | support, innov |
| `finance` | المالية والمشتريات | finrep, costs, svccosts, supscores |
| `hr` | الموظفون والموارد البشرية | capacity, perf |
| `legalws` | العقود والشؤون القانونية | legal, esign |
| `fieldops` | العمليات الميدانية | — |
| `knowledge` | المستندات والمعرفة | innov |

## ٥ · الصلاحياتُ الدقيقة

| المفتاح | العنوان | المجموعة | حسّاسة | الوحدات |
|---|---|---|---|---|
| `approve` | اعتماد الطلبات | الاعتماد والحسم | ⚠️ | 4 وحدة |
| `export` | تصدير البيانات (CSV) | التصدير | ⚠️ | 12 وحدة |
| `exportNight` | تصدير خارج الدوام | التصدير | ⚠️ | 1 وحدة |
| `bankPost` | قيد قبضٍ/صرفٍ بنكيّ | الاعتماد والحسم | ⚠️ | 1 وحدة |
| `docsec` | الوثائق الحسّاسة | الوثائق والملفات | ⚠️ | 4 وحدة |
| `fieldsec` | الحقول الحسّاسة | الحقول الحسّاسة | ⚠️ | 4 وحدة |
| `custodyAssign` | إسناد العهدة | الأصول والعهد | ⚠️ | 1 وحدة |
| `assetStatus` | تغيير حالة الأصل | الأصول والعهد |  | 1 وحدة |
| `assetStation` | إسناد الأصل لمحطة | الأصول والعهد |  | 1 وحدة |
| `assetInventory` | جردُ الأصول | الأصول والعهد | ⚠️ | 1 وحدة |
| `command` | أوامر الأجهزة | الأجهزة الطرفيّة | ⚠️ | 1 وحدة |
| `enroll` | سكُّ رموز التسجيل | الأجهزة الطرفيّة | ⚠️ | 1 وحدة |
| `assetAssign` | ربط الأصول بالمشروع | المشاريع |  | 1 وحدة |
| `membersManage` | إدارة أعضاء البوّابة | بوّابة العميل | ⚠️ | 1 وحدة |
| `projTeam` | فريقُ المشروع — كتابة | المشاريع |  | 1 وحدة |
| `projFin` | ماليّةُ المشروع — كتابة | المشاريع | ⚠️ | 1 وحدة |
| `projTech` | تقنيّةُ المشروع — كتابة | المشاريع |  | 1 وحدة |
| `staffAccounts` | فتحُ حساباتِ دخولٍ للموظّفين وإيقافُها | الموارد البشرية | ⚠️ | 1 وحدة |
| `attach` | إرفاق الملفات | الوثائق والملفات |  | 1 وحدة |

## ٦ · الوحداتُ الـ85

| المفتاح | العنوان | الجدول | الحقول |
|---|---|---|---|
| `accounts` | حسابات المنصات | `platform_accounts` | 20 |
| `accounts2` | دليل الحسابات | `ledger_accounts` | 7 |
| `apis` | التكاملات و APIs | `integrations_api` | 16 |
| `approvals` | الموافقات | `approvals` | 13 |
| `apps` | التطبيقات | `applications` | 45 |
| `assetlog` | سجل الصيانة | `asset_maintenance` | 12 |
| `assets` | الأصول والعهد | `assets` | 22 |
| `attend` | الحضور والانصراف | `attendance` | 12 |
| `autos` | الأتمتة (مؤرشفة — انظر مسارات العمل) | `automations` | 8 |
| `banks` | البنوك والصناديق | `bank_accounts` | 11 |
| `brands` | العلامات التجارية | `brands` | 31 |
| `budgets` | الميزانيات | `budgets` | 15 |
| `carriers` | مزوّدو الاتصالات | `carriers` | 8 |
| `changeorders` | أوامر التغيير | `change_orders` | 15 |
| `changes` | التغييرات التقنية | `changes` | 19 |
| `clients` | العملاء (CRM) | `clients` | 20 |
| `code` | الكود المصدري | `code_releases` | 12 |
| `companies` | الشركات | `companies` | 21 |
| `competitors` | المنافسون | `competitors` | 44 |
| `compliance` | سجل الامتثال | `compliance_items` | 14 |
| `contracts` | العقود والالتزامات | `contracts` | 22 |
| `costc` | مراكز التكلفة | `cost_centers` | 7 |
| `cycles` | الدورات والحملات | `cycles` | 13 |
| `dbs` | قواعد البيانات | `databases_reg` | 14 |
| `decisions` | سجل القرارات | `decisions` | 13 |
| `deploys` | سجل النشر والإصدارات | `deployments` | 19 |
| `deps` | سجل الاعتماديات | `dependencies` | 18 |
| `designs` | التصاميم | `design_tasks` | 11 |
| `domains` | الدومينات | `domains` | 16 |
| `emails` | البريد الإلكتروني | `email_accounts` | 14 |
| `endpoints` | النقاط الطرفية | `endpoint_devices` | 10 |
| `engagements` | ارتباطات العملاء | `engagements` | 19 |
| `entries` | قيود اليومية | `journal_entries` | 9 |
| `events` | الأحداث والمعارض | `events` | 19 |
| `facilities` | المنشآت الصحية | `facilities` | 15 |
| `feats` | خطة العمل والمزايا | `plan_items` | 13 |
| `files` | الملفات والمستندات | `documents` | 23 |
| `fin` | المحاسبة والفواتير | `fin_documents` | 24 |
| `hcps` | مقدمو الرعاية الصحية | `hcps` | 15 |
| `hr` | ملفات الموظفين | `employees` | 28 |
| `hrlog` | سجلات الموظفين | `employee_records` | 13 |
| `ideas` | مركز الابتكار | `ideas` | 13 |
| `incidents` | إدارة الحوادث التقنية | `incidents` | 23 |
| `ip` | الملكية الفكرية | `ip_assets` | 17 |
| `issues` | المشاكل والمخاطر | `issues` | 19 |
| `kb` | قاعدة المعرفة والسياسات | `kb_articles` | 10 |
| `krs` | النتائج الرئيسية (KR) | `key_results` | 18 |
| `leaves` | الإجازات والطلبات | `leave_requests` | 10 |
| `media` | مركز الإعلام | `media_items` | 16 |
| `meetings` | الاجتماعات | `meetings` | 13 |
| `obligations` | التزامات العقود | `contract_obligations` | 11 |
| `okrs` | الأهداف والنتائج (OKR) | `objectives` | 11 |
| `payroll` | مسيّرات الرواتب | `payroll_runs` | 8 |
| `phones` | أرقام الهواتف | `phone_numbers` | 32 |
| `plans` | الباقات والتسعير | `pricing_plans` | 16 |
| `policies` | السياسات والإقرارات | `policies` | 14 |
| `policyacks` | إقرارات السياسات | `policy_acks` | 10 |
| `posts` | منشورات ومشاهدات | `social_posts` | 20 |
| `products` | سجل المنتجات | `products` | 15 |
| `projects` | المشاريع | `projects` | 30 |
| `purchases` | المشتريات | `purchases` | 20 |
| `quotes` | عروض الأسعار | `quotes` | 30 |
| `recruit` | التوظيف | `candidates` | 14 |
| `recur` | المصروفات المتكررة | `recurring_docs` | 15 |
| `requests` | الطلبات الواردة | `internal_requests` | 21 |
| `restores` | اختبار استعادة النسخ | `restore_tests` | 19 |
| `rules` | قواعد التنبيه | `alert_rules` | 17 |
| `servers` | السيرفرات | `servers` | 31 |
| `services` | الخدمات والمنتجات | `services` | 33 |
| `skills` | المهارات والشهادات | `skills` | 17 |
| `social` | السوشال ميديا | `social_accounts` | 11 |
| `stations` | المحطات | `stations` | 12 |
| `stock` | المخزون | `stock_items` | 16 |
| `stockmv` | حركات المخزون | `stock_moves` | 13 |
| `subs` | الاشتراكات والتجديدات | `subscriptions` | 20 |
| `suppliers` | الموردون | `suppliers` | 12 |
| `tasks` | المهام | `tasks` | 20 |
| `terrassigns` | إسناد المناطق | `terrassigns` | 9 |
| `territories` | المناطق الميدانية | `territories` | 9 |
| `tickets` | تذاكر العملاء | `tickets` | 16 |
| `updates` | تحديثات العمل | `work_updates` | 14 |
| `users` | المستخدمون | `users` | 8 |
| `vault` | الخزنة الآمنة | `vault_secrets` | 11 |
| `visits` | الزيارات | `visits` | 20 |
| `websites` | المواقع | `websites` | 32 |

## ٧ · أسطحُ `GET` البشريّة — ٢١٨ مساراً

مجموعةً بالمقطعِ الأوّل من المسار:

| المقطع | العدد | أمثلة |
|---|---|---|
| `admin` | 53 | `admin/access`, `admin/access/role/{role}`, `admin/activity` |
| `portal` | 14 | `portal`, `portal/conversations`, `portal/conversations/{id}` |
| `m` | 7 | `m/{module}`, `m/{module}/board`, `m/{module}/create` |
| `reports` | 7 | `reports/daily`, `reports/daily/day`, `reports/finance` |
| `esign` | 6 | `esign`, `esign/templates/{id}/edit`, `esign/{id}/certificate` |
| `custody` | 5 | `custody`, `custody/cat/{code}`, `custody/{id}/label` |
| `endpoints` | 5 | `endpoints`, `endpoints/mdm`, `endpoints/releases` |
| `conversations` | 4 | `conversations`, `conversations/directory`, `conversations/{id}` |
| `identity` | 4 | `identity`, `identity/labels`, `identity/product/{id}/label` |
| `me` | 4 | `me`, `me/custody`, `me/documents/{id}/dl` |
| `notifications` | 4 | `notifications`, `notifications/count`, `notifications/mini` |
| `sign` | 4 | `sign/{token}`, `sign/{token}/certificate`, `sign/{token}/doc` |
| `attachments` | 3 | `attachments/{id}/dl`, `attachments/{id}/view`, `attachments/{module}/{recordId}/zip` |
| `dm` | 3 | `dm`, `dm/{userId}`, `dm/{userId}/since` |
| `field` | 3 | `field`, `field/route/{id}`, `field/sessions` |
| `quote` | 3 | `quote/{id}/diff`, `quote/{id}/doc`, `quote/{id}/pdf` |
| `s` | 3 | `s/{code}`, `s/{token}`, `s/{token}/file` |
| `search` | 3 | `search`, `search/messages`, `search/mini` |
| `.well-known` | 2 | `.well-known/apple-app-site-association`, `.well-known/assetlinks.json` |
| `boards` | 2 | `boards`, `boards/{id}` |
| `collab` | 2 | `collab`, `collab/attention` |
| `custody-wallet` | 2 | `custody-wallet`, `custody-wallet/e/{id}` |
| `delivery` | 2 | `delivery`, `delivery/psa` |
| `graph` | 2 | `graph/expand`, `graph/explore` |
| `inventory` | 2 | `inventory`, `inventory/{id}` |
| `login` | 2 | `login`, `login/otp` |
| `my` | 2 | `my/report`, `my/security` |
| `oversight` | 2 | `oversight`, `oversight/{id}` |
| `verify` | 2 | `verify`, `verify/{code}/doc` |
| `w` | 2 | `w/digital/tech`, `w/{key}` |
| `workforce` | 2 | `workforce`, `workforce/overview` |
| `/` | 1 | `/` |
| `activate` | 1 | `activate/{token}` |
| `alerts` | 1 | `alerts` |
| `app-quality` | 1 | `app-quality` |
| `app` | 1 | `app/{id}` |
| `apps-projects` | 1 | `apps-projects` |
| `apps` | 1 | `apps/quoteflow` |
| `assets-life` | 1 | `assets-life` |
| `c` | 1 | `c/{code}` |
| `calendar` | 1 | `calendar` |
| `capacity` | 1 | `capacity` |
| `ceo` | 1 | `ceo` |
| `changeorder` | 1 | `changeorder/{id}/pdf` |
| `code-center` | 1 | `code-center` |
| `compliance-board` | 1 | `compliance-board` |
| `costs` | 1 | `costs` |
| `dataroom` | 1 | `dataroom` |
| `digital-assets` | 1 | `digital-assets` |
| `employee` | 1 | `employee/{id}` |
| `feed` | 1 | `feed` |
| `files` | 1 | `files/{path}` |
| `groups` | 1 | `groups` |
| `healthz` | 1 | `healthz` |
| `impact` | 1 | `impact` |
| `inboxdocs` | 1 | `inboxdocs` |
| `innovation` | 1 | `innovation` |
| `journey` | 1 | `journey/{id}` |
| `kpis` | 1 | `kpis` |
| `legal` | 1 | `legal` |
| `manifest.webmanifest` | 1 | `manifest.webmanifest` |
| `media-center` | 1 | `media-center` |
| `morning` | 1 | `morning` |
| `odoo` | 1 | `odoo/projects/{id}` |
| `offline` | 1 | `offline` |
| `okrs` | 1 | `okrs` |
| `p` | 1 | `p/{code}` |
| `performance` | 1 | `performance` |
| `personalize` | 1 | `personalize` |
| `policies` | 1 | `policies` |
| `pricing` | 1 | `pricing` |
| `profile` | 1 | `profile` |
| `purchase` | 1 | `purchase/{id}/doc` |
| `pwa-icon.svg` | 1 | `pwa-icon.svg` |
| `recommendations` | 1 | `recommendations` |
| `sales` | 1 | `sales` |
| `saved` | 1 | `saved` |
| `service-costs` | 1 | `service-costs` |
| `social` | 1 | `social` |
| `staff` | 1 | `staff` |
| `stepup` | 1 | `stepup` |
| `supplier-scores` | 1 | `supplier-scores` |
| `support` | 1 | `support` |
| `system-map` | 1 | `system-map` |
| `system` | 1 | `system/trace/{rid}` |
| `team` | 1 | `team` |
| `trace` | 1 | `trace/{module}/{id}` |
| `up` | 1 | `up` |

> القائمةُ الكاملةُ بأسمائها في `FEATURE_COVERAGE_MATRIX.md` — فكلُّ سطحٍ يُسنَد
> إلى شخصيّةٍ ويومٍ ويُختَم بنتيجة.