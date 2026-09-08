# 01 — جرد الوجهات الكامل (Destination Inventory)

> **DISCOVERY.** head `v2.429.0`. كل صفٍّ مسارٌ حقيقيٌّ من `php artisan route:list --method=GET` (226 مسار = 188 ويب + 38 API). لا اختراع. التصنيف: GLOBAL / WORKSPACE / MODULE / ENTITY / CONTROL_CENTER / ADMINISTRATION / USER_PERSONAL / UTILITY / PUBLIC / PORTAL / SYSTEM. **UNCLASSIFIED = 0** (جدول العدّ في §نهاية).

الأعمدة: `route` (الاسم) · `uri` · `controller@method` · permission (الحارس الحقيقي في المتحكّم/الكتالوج) · التصنيف مُعنوَنٌ بالقسم.

---

## GLOBAL (7) — وجهاتٌ عليا شاملة، متاحةٌ دوماً
| route | uri | controller | permission |
|---|---|---|---|
| dashboard | `/` | DashboardController@index | auth |
| search | `search` | SearchController@index | auth |
| search.mini | `search/mini` | SearchController@mini | auth |
| morning | `morning` | MorningController@index | auth |
| alerts | `alerts` | AlertController@index | auth (رادار الانتهاء الشخصي) |
| calendar | `calendar` | CalendarController@index | auth |
| feed | `feed` | CommentController@feed | auth |

## USER_PERSONAL / My Work (14) — شخصيٌّ للمستخدم الحالي
| route | uri | controller | permission |
|---|---|---|---|
| portal.me | `me` | PortalController@me | auth (بوّابتي الذاتية) |
| profile.edit | `profile` | ProfileController@edit | auth |
| prefs.edit | `personalize` | PrefController@edit | auth (كتالوج admin `ok=true`) |
| mysec.index | `my/security` | MySecurityController@index | auth |
| stepup.show | `stepup` | StepUpController@show | auth (MFA step-up) |
| dm.inbox | `dm` | DmController@inbox | auth |
| dm.thread | `dm/{userId}` | DmController@thread | auth |
| conversations.index | `conversations` | ConversationController@index | auth |
| conversations.show | `conversations/{id}` | ConversationController@show | auth + scope |
| notifications.index | `notifications` | NotificationController@index | auth |
| notifications.count | `notifications/count` | NotificationController@count | auth |
| notifications.mini | `notifications/mini` | NotificationController@mini | auth |
| notifications.go | `notifications/{id}/go` | NotificationController@go | auth |
| inboxdocs.index | `inboxdocs` | InboxDocController@index | can(inboxdocs\|files,v) |

## WORKSPACE (2) — مراكز المساحات الثماني
| route | uri | controller | permission |
|---|---|---|---|
| workspace | `w/{key}` | WorkspaceController@show | مساحةٌ تظهر فقط إن رأى المستخدم وحدةً منها (Workspaces::for) |
| tech.workspace | `w/digital/tech` | TechWorkspaceController@index | مساحة digital الفرعية |

## MODULE (7 مسارات عامّة تغطّي كل الوحدات الـ85)
| route | uri | controller | permission |
|---|---|---|---|
| m.index | `m/{module}` | ModuleController@index | hub_can(module,v) + hub_scope + hub_field_mode |
| m.board | `m/{module}/board` | ModuleController@board | hub_can(module,v) |
| m.create | `m/{module}/create` | ModuleController@create | hub_can(module,a) |
| m.show | `m/{module}/{id}` | ModuleController@show | hub_can(module,v) + scope |
| m.edit | `m/{module}/{id}/edit` | ModuleController@edit | hub_can(module,e) |
| m.export | `m/{module}/export` | ModuleController@export | hub_can(module,v) |
| m.import | `m/{module}/import` | ImportController@form | hub_can(module,a) |

> كل وحدةٍ من الـ85 (بما فيها الأيتام stations/endpoints/restores/users/autos) تُطرَق عبر هذه السبعة. `users` مُدارةٌ عبر admin/users أيضاً؛ `autos` مؤرشفة.

## ENTITY (4) — مراكز سياق سجلٍّ واحد
| route | uri | controller | permission |
|---|---|---|---|
| apps.center | `app/{id}` | AppCenterController@show | مركز سياق تطبيق |
| journey | `journey/{id}` | JourneyController@show | can(clients,v) + scope |
| portal.employee | `employee/{id}` | PortalController@employee | ملف موظف |
| odoo.project | `odoo/projects/{id}` | OdooController@project | مرآة مشروع Odoo (تكامل) |

## CONTROL_CENTER (63) — تجارب تشغيليّة عبر الوحدات
### لوحات تحليليّة (analytics)
| route | uri | controller | permission |
|---|---|---|---|
| ceo | `ceo` | CeoController@index | owner |
| performance | `performance` | PerformanceController@index | monitor |
| sales.dashboard | `sales` | SalesController@dashboard | monitor |
| reports.finance | `reports/finance` | ReportController@finance | can(fin,v) |
| costs.index | `costs` | CostController@index | monitor |
| servicecosts | `service-costs` | CostController@services | monitor |
| kpis.index | `kpis` | KpiController@index | monitor |
| capacity | `capacity` | CapacityController@index | monitor |
| recs | `recommendations` | CapacityController@recommendations | monitor |
| impact | `impact` | CapacityController@impact | monitor |
| appquality | `app-quality` | CapacityController@quality | monitor |
| delivery | `delivery` | DeliveryController@index | can(feats\|deploys\|requests\|designs,v) |
| delivery.psa | `delivery/psa` | DeliveryController@psa | كسابقتها |
| digital.assets | `digital-assets` | DigitalAssetsController@index | monitor |
| kpis (KPI) | — | — | (أعلاه) |
### مراكز متخصّصة (centers)
| route | uri | controller | permission |
|---|---|---|---|
| custody.catalog | `custody` | CustodyController@catalog | can(assets,v) |
| custody.category | `custody/cat/{code}` | CustodyController@category | can(assets,v) |
| custody.label | `custody/{id}/label` | CustodyController@label | can(assets,v) |
| custody.spec | `custody/{id}/spec` | CustodyController@spec | can(assets,v) |
| custody.permit.doc | `custody/{id}/permit/{permitId}` | CustodyController@permitDoc | can(assets,v) |
| custody.wallet.center | `custody-wallet` | EmployeeCustodyController@center | عهدة الموظفين |
| custody.wallet.employee | `custody-wallet/e/{id}` | EmployeeCustodyController@employee | كسابقتها |
| identity.center | `identity` | IdentityController@center | can(assets,v) |
| identity.labels | `identity/labels` | IdentityController@labels | can(assets,v) |
| identity.resolve | `identity/resolve` | IdentityController@resolve | can(assets,v) |
| identity.product.label | `identity/product/{id}/label` | IdentityController@productLabel | can(assets,v) |
| code.center | `code-center` | CodeCenterController@index | can(code,v) |
| assets.life | `assets-life` | AssetLifeController@index | can(assets,v) |
| compliance.board | `compliance-board` | ComplianceController@index | can(compliance,v) |
| appsprojects | `apps-projects` | AppsProjectsController@index | can(apps\|projects,v) |
| pricing | `pricing` | PricingController@index | can(plans,v) |
| media.center | `media-center` | MediaCenterController@index | can(media\|events,v) |
| team | `team` | TeamController@index | can(hr,v) |
| staff.index | `staff` | StaffController@index | can(hr,v) |
| workforce.team | `workforce` | WorkdayController@team | can(hr,v) |
| workforce.overview | `workforce/overview` | WorkforceController@overview | monitor |
| okrs.board | `okrs` | OkrController@index | can(okrs,v) |
| policies.board | `policies` | PolicyController@index | can(policies,v) |
| social.index | `social` | SocialController@index | can(social,v) |
| legal | `legal` | LegalController@index | can(contracts,v) |
| esign.index | `esign` | EsignController@index | can(contracts,v) |
| esign.edit | `esign/{id}/edit` | EsignController@edit | can(contracts,e) |
| esign.tpl.edit | `esign/templates/{id}/edit` | EsignController@editTemplate | can(contracts,e) |
| esign.doc | `esign/{id}/doc` | EsignController@doc | can(contracts,v) |
| esign.pdf | `esign/{id}/pdf` | EsignController@pdf | can(contracts,v) |
| esign.cert | `esign/{id}/certificate` | EsignController@certificate | can(contracts,v) |
| support | `support` | SupportController@index | can(tickets,v) |
| innovation | `innovation` | InnovationController@index | can(ideas,v) |
| supplierscores | `supplier-scores` | PurchaseController@scores | can(suppliers,v) |
| boards.index | `boards` | BoardController@index | auth (owner=مشاركة) |
| boards.edit | `boards/{id}` | BoardController@edit | auth + ملكية اللوح |
| inventory.center | `inventory` | InventoryController@center | can(assets,v) |
| inventory.show | `inventory/{id}` | InventoryController@show | can(assets,v) |
| endpoints.index | `endpoints` | EndpointCentreController@index | owner\|\|monitor |
| endpoints.show | `endpoints/{id}` | EndpointCentreController@show | owner\|\|monitor |
| endpoints.releases | `endpoints/releases` | EndpointReleaseController@index | owner |
| endpoints.releases.download | `endpoints/releases/{id}/download` | EndpointReleaseController@download | owner |
| field.dashboard | `field` | FieldController@dashboard | owner\|\|(can(hr,v)&&monitor) |
| field.route | `field/route/{id}` | FieldController@route | كسابقتها |
| field.sessions | `field/sessions` | FieldController@index | كسابقتها |
| oversight.index | `oversight` | OversightController@index | monitor/owner (gate) — PortalGuard |
| oversight.show | `oversight/{id}` | OversightController@show | كسابقتها |
| graph.explore | `graph/explore` | RelationshipExplorerController@explore | داخلي (بنية العلاقات) |
| graph.expand | `graph/expand` | RelationshipExplorerController@expandNode | داخلي |

## UTILITY (9) — مولّدات ووثائق وتنزيلات
| route | uri | controller | permission |
|---|---|---|---|
| att.dl | `attachments/{id}/dl` | AttachmentController@download | scope المرفق |
| att.view | `attachments/{id}/view` | AttachmentController@preview | scope المرفق |
| att.zip | `attachments/{module}/{recordId}/zip` | AttachmentController@zip | scope السجل |
| file.show | `files/{path}` | FileController@show | scope الملف |
| changeorders.pdf | `changeorder/{id}/pdf` | ChangeOrderController@pdf | can(changeorders,v) |
| quotes.pdf | `quote/{id}/pdf` | QuoteController@pdf | can(quotes,v) |
| quotes.doc | `quote/{id}/doc` | QuoteController@doc | can(quotes,v) |
| quotes.diff | `quote/{id}/diff` | QuoteController@diff | can(quotes,v) |
| purchases.doc | `purchase/{id}/doc` | PurchaseController@doc | can(purchases,v) |

## ADMINISTRATION (49) — شريط الترس ⚙️ (سطح النظام)
> **⚠️ RISK (يجب أن يعرفه بناءُ IA):** كتلة الإدارة كلها `routes/web.php:638-957` داخل `Route::middleware('auth')` **بلا وسيطٍ على مستوى المسار**. كل التخويل في `gate()` بكل متحكّم. طبقة IA يجب أن **تعيد استعمال حارس الكتالوج (`ok`) وحارس المتحكّم نفسه** — لا تشتقّ الرؤية من `route:list`.

### الأمن والرقابة (Security & Oversight)
| route | uri | controller | permission |
|---|---|---|---|
| audit.index | `admin/audit` | AuditController@index | hub_flag(audit) |
| audit.coverage | `admin/audit/coverage` | AuditController@coverage | hub_flag(audit) |
| audit.show | `admin/audit/{id}` | AuditController@show | hub_flag(audit) |
| security.index | `admin/security` | SecurityController@index | owner |
| security.blocks | `admin/security/blocks` | SecurityController@blocks | owner |
| security.devices | `admin/security/devices` | SecurityController@devices | owner |
| security.event | `admin/security/event/{source}/{id}` | SecurityController@event | owner |
| security.findings | `admin/security/findings` | SecurityController@findings | owner |
| security.finding | `admin/security/findings/{id}` | SecurityController@finding | owner |
| security.identity | `admin/security/identity` | SecurityController@identity | owner |
| security.ips | `admin/security/ips` | SecurityController@ips | owner |
| security.ip | `admin/security/ips/{ip}` | SecurityController@ip | owner |
| security.privileged | `admin/security/privileged` | SecurityController@privileged | owner |
| security.secrets | `admin/security/secrets` | SecurityController@secrets | owner (secrets) |
| security.sessions | `admin/security/sessions` | SecurityController@sessions | owner |
| security.tokens | `admin/security/tokens` | SecurityController@tokens | owner |
| activity.index | `admin/activity` | ActivityController@index | owner |
| activity.show | `admin/activity/{id}` | ActivityController@show | owner |
| dataroom.index | `dataroom` | DataRoomController@index | hub_secrets |

### التشغيل (Operations & Monitoring)
| route | uri | controller | permission |
|---|---|---|---|
| control.index | `admin/control` | ControlController@index | owner\|\|monitor |
| ops.index | `admin/ops` | OpsController@index | owner |
| ops.health | `admin/ops/health` | OpsController@healthDetail | owner |
| ops.runbooks | `admin/ops/runbooks` | OpsController@runbooks | owner |
| errors.index | `admin/errors` | ErrorCenterController@index | owner |
| errors.logs | `admin/errors/logs` | ErrorCenterController@logs | owner |
| errors.show | `admin/errors/{id}` | ErrorCenterController@show | owner |
| alerts.center | `admin/alerts` | AlertCenterController@index | owner\|\|monitor |

### الجودة والحوكمة (Data Quality & Governance)
| route | uri | controller | permission |
|---|---|---|---|
| quality.index | `admin/quality` | QualityController@index | owner |
| fields.index | `admin/fields` | CustomFieldController@index | owner |
| flows.index | `admin/flows` | FlowController@index | owner |
| flows.edit | `admin/flows/{id}/edit` | FlowController@edit | owner |
| flows.sandbox | `admin/flows/{id}/sandbox` | FlowController@sandbox | owner |

### الإعدادات (Configuration & Access)
| route | uri | controller | permission |
|---|---|---|---|
| settings.edit | `admin/settings` | SettingController@edit | owner |
| settings.export | `admin/settings/export` | SettingController@export | owner |
| integrations.index | `admin/integrations` | IntegrationController@index | owner |
| integrations.guide | `admin/integrations/guide` | IntegrationController@guide | owner |
| hooks.index | `admin/integrations/hooks` | InboundHookController@index | owner |
| integrations.messaging | `admin/integrations/messaging` | MessagingController@index | owner |
| integrations.n8n | `admin/integrations/n8n` | N8nController@index | owner |
| integrations.odoo | `admin/integrations/odoo` | OdooConnectionController@index | owner |
| webhooks.index | `admin/webhooks` | WebhookController@index | owner |
| webhooks.log | `admin/webhooks/{id}/log` | WebhookController@log | owner |
| roles.index | `admin/roles` | RoleController@index | owner |
| roles.create | `admin/roles/create` | RoleController@create | owner |
| roles.edit | `admin/roles/{role}/edit` | RoleController@edit | owner |
| users.index | `admin/users` | UserController@index | hub_flag(users) |
| users.create | `admin/users/create` | UserController@create | hub_flag(users) |
| users.edit | `admin/users/{user}/edit` | UserController@edit | hub_flag(users) |
| quoteflow | `apps/quoteflow` | QuoteFlowController@page | owner |

## PUBLIC (14) — مداخل خارجية / رمزيّة / قبل الدخول
| route | uri | controller | permission |
|---|---|---|---|
| login | `login` | AuthController@show | guest |
| login.otp | `login/otp` | AuthController@otpShow | guest |
| activate.show | `activate/{token}` | ActivationController@show | token |
| sign.show | `sign/{token}` | EsignController@show | token (توقيع العميل) |
| sign.cert | `sign/{token}/certificate` | EsignController@clientCertificate | token |
| sign.doc | `sign/{token}/doc` | EsignController@clientDoc | token |
| sign.pdf | `sign/{token}/pdf` | EsignController@clientPdf | token |
| sign.verify | `verify` | EsignController@verify | عام (تحقّق) |
| sign.verify.doc | `verify/{code}/doc` | EsignController@verifyDoc | code |
| share.show | `s/{token}` | DataRoomController@show | token مشاركة |
| share.file | `s/{token}/file` | DataRoomController@file | token |
| custody.code | `c/{code}` | CustodyController@byCode | مسح QR عهدة |
| stations.code | `s/{code}` | StationController@byCode | مسح QR محطة (+scope داخلي) |
| products.code | `p/{code}` | IdentityController@byCode | مسح QR منتج |

## PORTAL — جمهور العميل (10) — PortalGuard قائمةٌ بيضاء لـ`portal.*`
| route | uri | controller |
|---|---|---|
| portal.home | `portal` | ClientPortalController@home |
| portal.conversations | `portal/conversations` | ClientPortalController@conversations |
| portal.conversation | `portal/conversations/{id}` | ClientPortalController@conversation |
| portal.documents | `portal/documents` | ClientPortalController@documents |
| portal.document | `portal/documents/{id}` | ClientPortalController@document |
| portal.engagements | `portal/engagements` | ClientPortalController@engagements |
| portal.invoices | `portal/invoices` | ClientPortalController@invoices |
| portal.invoice | `portal/invoices/{id}` | ClientPortalController@invoice |
| portal.projects | `portal/projects` | ClientPortalController@projects |
| portal.project | `portal/projects/{id}` | ClientPortalController@project |

> جمهورٌ منفصلٌ تماماً (حساب `account_type=client`). ليس جزءاً من مجالات IA الداخلية الـ9؛ سطحٌ مستقلٌّ محروسٌ بـ`PortalGuard` (المسارات الداخلية تُعيد 404 لحساب العميل).

## SYSTEM (9) — بنيةٌ تحتية/تقنية بلا بيتٍ في التنقّل
| route | uri | controller |
|---|---|---|
| up | `up` | Closure (Laravel health) |
| healthz | `healthz` | OpsController@health |
| mobile.aasa | `.well-known/apple-app-site-association` | MobileWellKnownController@appleAppSiteAssociation |
| mobile.assetlinks | `.well-known/assetlinks.json` | MobileWellKnownController@assetLinks |
| pwa.manifest | `manifest.webmanifest` | PwaController@manifest |
| pwa.offline | `offline` | PwaController@offline |
| pwa.icon | `pwa-icon.svg` | PwaController@icon |
| system.trace | `system/trace/{rid}` | SystemTraceController@show | owner (تشخيص) |
| trace | `trace/{module}/{id}` | TraceController@show | scope السجل (منشأ/أثر) |

## API GET (38) — السطح الآلي (يستهلك IA، ليس تنقّلاً بشرياً)
- **mobile.\*** (27): app_config · approvals(.index/.show) · auth.sessions · **bootstrap** · comments · **context** · dm(threads/messages) · files(download/stream) · health · home · identity.resolve · notifications(index/unread/target) · openapi · prefs · push.admin.status · **schema**/schema.modules · search · sync · resource(index/show/actions). المُستهلك الأساسي لطبقة IA: `mobile.bootstrap`/`mobile.context`/`mobile.schema`.
- **api/v1/\*** (9، غير مسمّاة): identityResolve · me · metricsShow · modules · openapi · health · progress · apiIndex · apiShow (Api\V1Controller).
- **endpoint.agent.\*** (2): agent.download · agent.manifest (EndpointProtocolController).

---

## جدول العدّ النهائي — UNCLASSIFIED = 0

| التصنيف | العدد |
|---|---|
| GLOBAL | 7 |
| USER_PERSONAL / My Work | 14 |
| WORKSPACE | 2 |
| MODULE (عامّ، يغطّي 85 وحدة) | 7 |
| ENTITY | 4 |
| CONTROL_CENTER | 63 |
| UTILITY | 9 |
| ADMINISTRATION | 49 |
| PUBLIC | 14 |
| PORTAL (عميل) | 10 |
| SYSTEM | 9 |
| **مجموع الويب GET** | **188** |
| API GET (mobile 27 + v1 9 + agent 2) | 38 |
| **مجموع GET الكلّي** | **226** |
| **UNCLASSIFIED** | **0** |
| **FUTURE** | **0** (لا وحدةَ/مسارَ مستقبليّ؛ `autos` = DEPRECATED_CONFIRMED لا FUTURE) |

### وجهاتٌ قاومت التصنيف الأوّلي (حُسِمت — لا متبقّي)
- **PORTAL (10):** لا يوجد تصنيفٌ في القائمة المعيارية لجمهور العميل؛ حُسِمت كفئةٍ صريحةٍ «PORTAL» (سطحٌ منفصلٌ محروسٌ بـPortalGuard، خارج مجالات IA الداخلية). لا تُدمج في أي مجالٍ داخلي.
- **prefs.edit:** يظهر في كتالوج admin لكن `ok=true` للجميع → صُنِّف USER_PERSONAL (تخصيص شخصي)، ويبقى قابلاً للاكتشاف عبر البحث كما اليوم.
- **oversight / workforce.overview / control:** حراسٌ `monitor/owner` — صُنِّفت حسب وظيفتها (CONTROL_CENTER للأولى، ADMINISTRATION للأخيرة) لا حسب الحارس.
- **الأيتام الخمسة (stations/endpoints/restores/users/autos):** مساراتها عامّة (m.\*) أو مركزٌ مخصّص؛ صُنِّفت وتُعطى بيتاً أساسياً في 03 (autos = مؤرشفة).
