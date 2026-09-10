# جردُ سطوحِ النظام الأمنيّة — Permissions 360

> جردٌ حيٌّ من تدقيقِ الـ20 وكيلاً (للقراءة فقط). كلُّ سطحٍ مصنَّفٌ في **فئةٍ واحدةٍ** من ثمانٍ.
> **سطوحٌ غيرُ مصنَّفة = 0.** المصدر: `docs/permissions-360/agents/*` و`SYSTEM_SURFACE_INVENTORY`.

## 1) الحصيلة

| المقياس | القيمة |
|---|---|
| سطوحٌ مفحوصة | 521 |
| مفاتيحُ دقيقةٌ مقترحة | 120 |
| نتائجُ تدقيقٍ | 83 |
| ورشُ التدقيق | 20 |

## 2) التوزيع على الفئات الثمانِ (المجموع = 521)

| الفئة | العدد | المعنى |
|---|--:|---|
| `ASSIGNABLE_PERMISSION` | 297 | يمنحها/يمنعها المالكُ من الأدوار |
| `OWNER_ONLY` | 65 | حدُّ حوكمةٍ صلب (لا يُمنح) |
| `SELF_SERVICE` | 50 | عمليّةُ الحسابِ على نفسِه |
| `PARTICIPATION_GOVERNED` | 36 | محكومةٌ بعضويّةِ محادثة/غرفة |
| `CLIENT_PORTAL_GOVERNED` | 29 | محكومةٌ بحدِّ حسابِ العميل |
| `SYSTEM_INTERNAL` | 17 | جدولةٌ/Webhook/آلةٌ — لا صلاحيّةَ بشريّة |
| `SECURITY_INVARIANT` | 14 | ثابتٌ أمنيٌّ لا يُجعل قابلاً للضبط |
| `PUBLIC_AUTHENTICATED_BASE` | 13 | أساسٌ للمصادَقين حيث يُقصد ذلك |

## 3) السطوحُ حسبَ المجال

### نواة التوثيق (24)

| السطح | النوع | المرجع | التوثيق الحالي | التصنيف | المفتاح | الحساسية |
|---|---|---|---|---|---|---|
| hub_can — matrix engine | api | app/Support/helpers.php:288-296 | owner⇒true; else (bool) matrix[module][op] | ASSIGNABLE_PERMISSION | matrix[module][v\|a\|e\|d] (any op string supported) | sensitive |
| User::can2 / User::flag — alternate engine reads | api | app/Models/User.php:76-86 | isOwner()⇒true; else role.matrix[module][action] / role.flags[f] | ASSIGNABLE_PERMISSION | matrix[module][action] |  |
| hub_flag — 8 admin flags | api | app/Support/helpers.php:929-939 | owner⇒true; else role.flags[flag] | ASSIGNABLE_PERMISSION | flags: users,audit,approve,monitor,secrets,copySec,exp,mobile | sensitive |
| Role.is_owner — global bypass | field | app/Models/Role.php:16 / helpers.php:293,935,1715 | boolean role attribute; true ⇒ bypasses matrix, flags, field_rules, scope | OWNER_ONLY | — | owner_only |
| hub_field_mode / hub_visible_fields — field-level rules | field | app/Support/helpers.php:1696-1733 | locked field⇒'ro' (even owner); else owner⇒''; else role.field_rules[module][field] in {'','ro','hide'} | ASSIGNABLE_PERMISSION | field_rules[module][field] | sensitive |
| hub_scope — project/company/client isolation | api | app/Support/helpers.php:141-164 | applies whereIn on project/company/client column IF the module exposes one; else no filter | SECURITY_INVARIANT | companies/clients JSON + role.scope=proj | sensitive |
| hub_company_ids / hub_client_ids / hub_scoped | api | app/Support/helpers.php:122-131,184-220,66-71 | owner⇒null(unscoped); else user.companies / active client_memberships / role.scope | SECURITY_INVARIANT | — | sensitive |
| hub_is_client + PortalGuard — client boundary | api | app/Support/helpers.php:5919-5924 / User.php:70-73 | account_type==='client' ⇒ restricted to PortalGuard::MODULE_ALLOW (overrides matrix) | CLIENT_PORTAL_GOVERNED | — | sensitive |
| hub_require_stepup / StepUp — sensitive-action re-auth | api | app/Support/helpers.php:776-806 / app/Support/StepUp.php:28-40 | session TTL window (default 10 min); OWNER NOT exempt | SECURITY_INVARIANT | — | owner_only |
| hub_exporter (exp flag) — CSV export gate | export | app/Support/helpers.php:1044 / ModuleController.php:794-814 | single global flag exp (owner⇒true) | ASSIGNABLE_PERMISSION | flags.exp | sensitive |
| hub_monitor (monitor flag) — analytics/dashboards gate | route | app/Support/helpers.php:964 / hub_top_links helpers.php:417-448 (perf,sales,costs,kpis,capacity,recs,impact,appq,svccosts,dassets) | single global flag monitor (owner⇒true) | ASSIGNABLE_PERMISSION | flags.monitor | sensitive |
| hub_approver (approve flag) — decision gate | button | app/Support/helpers.php:972 / ApprovalService:61, PayrollController:143, PurchaseController:108 | single global flag approve (owner⇒true) | ASSIGNABLE_PERMISSION | flags.approve | sensitive |
| hub_secrets / hub_copy_secrets (secrets,copySec) | button | app/Support/helpers.php:980-1039 / RoleController:19-21 | flags.secrets reveals vault; copy = secrets\|\|copySec | ASSIGNABLE_PERMISSION | flags.secrets, flags.copySec | owner_only |
| hub_flag('mobile') / MobilePlatformController | route | app/Http/Controllers/Web/MobilePlatformController.php:49 / helpers.php:5724 | owner \|\| flags.mobile | ASSIGNABLE_PERMISSION | flags.mobile | sensitive |
| hub_flag('audit') / AuditController + SystemTrace + Control | route | AuditController.php:40,125 / SystemTraceController.php:21 / ControlController.php:142 | owner \|\| flags.audit | ASSIGNABLE_PERMISSION | flags.audit | sensitive |
| RoleController — role editor | route | app/Http/Controllers/Web/RoleController.php:31-34 (gate) | abort_unless(hub_is_owner()) | OWNER_ONLY | — | owner_only |
| RoleController::data() — matrix/flags/field_rules writer | form | app/Http/Controllers/Web/RoleController.php:298-361 | owner (via gate) | OWNER_ONLY | writes matrix[module][v\|a\|e\|d] only | owner_only |
| RoleController::update — step-up on escalation | form | app/Http/Controllers/Web/RoleController.php:223-232 | step-up required only when a RISKY flag is ADDED or scope proj→all | SECURITY_INVARIANT | — | owner_only |
| RoleController::fromTemplate — template seeding | import | app/Http/Controllers/Web/RoleController.php:272-296 | owner | OWNER_ONLY | v/a/e/d letters + registered mods only |  |
| UserController::gate + guardEscalation | route | app/Http/Controllers/Web/UserController.php:14-17,29-39 | gate=flags.users; guardEscalation blocks non-owner assigning owner role or editing an owner account | SECURITY_INVARIANT | flags.users | owner_only |
| AccessController /admin/access — diagnostic | route | app/Http/Controllers/Web/AccessController.php:20-23 | abort_unless(hub_is_owner()) | OWNER_ONLY | — | owner_only |
| PermissionInspector — explain/matrix/navigation | api | app/Support/PermissionInspector.php:24-207 | delegates to hub_can/hub_flag/PortalGuard/scope (no local grant) | OWNER_ONLY | OPS = v/a/e/d only | sensitive |
| hub_org_analytics_guard — isolated-user compensating control | api | app/Support/helpers.php:1802-1810 | abort_if company_ids!==null or client_ids!==null (403) | SECURITY_INVARIANT | — | sensitive |
| User::visibleProjectIds — permission caching | api | app/Models/User.php:89-98 | cached 300s at user:{id}:projects | SYSTEM_INTERNAL | — |  |

### مسارات الويب (34)

| السطح | النوع | المرجع | التوثيق الحالي | التصنيف | المفتاح | الحساسية |
|---|---|---|---|---|---|---|
| admin/ops center + backup/migrate/maintenance/clear-cache/schema-check | route | ops.index / ops.backup / ops.migrate / ops.maintenance | OpsController::gate() -> abort_unless(hub_is_owner()); mutations also hub_require_ops_stepup() | OWNER_ONLY | — | owner_only |
| admin/security center + freeze/lockdown/revoke sessions,tokens,users | route | security.* (SecurityController) | gate()/findingsReadGate()/blocksGate() -> hub_is_owner (findings also allow hub_monitor); write actions hub_require_stepup | OWNER_ONLY | — | owner_only |
| admin/roles editor (store/update/clone/destroy) | route | roles.* (RoleController) | gate() -> hub_is_owner; abort_if is_owner role edited/cloned/deleted; risky-flag/scope changes require stepup | OWNER_ONLY | — | owner_only |
| admin/users (store/update/destroy/restore/twofa-off) | route | users.* (UserController) | gate() -> hub_flag('users'); guardEscalation blocks non-owner granting/altering owner; twofaOff adds Staff::mayTouch + stepup | ASSIGNABLE_PERMISSION | flag: users | sensitive |
| admin/settings update/import/import-apply/restore | route | settings.* (SettingController) | gate() -> hub_is_owner; risky keys require hub_require_stepup | OWNER_ONLY | — | owner_only |
| admin/features toggle, admin/webhooks, admin/fields, quoteflow | route | features.* / webhooks.* / fields.* / quoteflow.* | per-controller gate() -> hub_is_owner (FeatureController/WebhookController/CustomFieldController/QuoteFlowController) | OWNER_ONLY | — | owner_only |
| admin/audit index/show + coverage | route | audit.* (AuditController) | abort_unless(hub_flag('audit')) each method; coverage owner-only; module rows filtered by hub_can(v) | ASSIGNABLE_PERMISSION | flag: audit | sensitive |
| admin/access diagnostics (impersonation view of any user perms) | route | access.index / access.role (AccessController) | gate() -> hub_is_owner | OWNER_ONLY | — | owner_only |
| admin/quality center + merge (record de-dup/merge) | route | quality.* (QualityController) | tabGate($tab) per tab; base hub_monitor, OWNER_TABS owner-only; merge gated | ASSIGNABLE_PERMISSION | flag: monitor (+ owner tabs) | sensitive |
| admin/integrations messaging/odoo/n8n/hooks (POST test/save/store/destroy) | route | integrations.* / hooks.* / odoo.* (Messaging/N8n/OdooConnection/InboundHook) | each controller gate() -> hub_is_owner | OWNER_ONLY | — | owner_only |
| admin/mobile-platform push-test / session revoke | route | mobileplatform.* (MobilePlatformController) | gate() -> hub_flag('mobile') / owner (mobile flag) | ASSIGNABLE_PERMISSION | flag: mobile | sensitive |
| endpoints MDM/releases/command/enroll-token (device fleet mgmt) | route | endpoints.mdm.* / endpoints.releases.* / endpoints.command / enroll.mint | abort_if hub_is_client (404) + abort_unless(hub_is_owner()\|\|hub_monitor()) + hub_require_stepup on writes | ASSIGNABLE_PERMISSION | flag: monitor / owner | sensitive |
| generic module CRUD m/{module} index/create/store/edit/update/destroy/restore | route | m.* (ModuleController) | resolve() -> hub_can(module,op) 403; findScoped -> hub_scope()->findOrFail (404 out-of-scope); users module -> 404 | ASSIGNABLE_PERMISSION | matrix v/a/e/d per module | sensitive |
| m/{module}/{id}/secret/{field} reveal secret | route | m.secret (ModuleController::revealSecret) | hub_can(v) + hub_copy_secrets (secrets\|copySec) + field_mode!=hide + allowed_ids list + optional stepup; audits 'عرض حساس' | ASSIGNABLE_PERMISSION | flag: secrets/copySec | sensitive |
| m/{module}/export + m/{module}/bulk(do=export) CSV export | export | m.export / m.bulk (ModuleController::exportBelt) | hub_can(v) + exportBelt: hub_exporter() (exp flag) + freeze_exports 423 + big/ICCID stepup + audit | ASSIGNABLE_PERMISSION | flag: exp | sensitive |
| m/{module}/bulk (status/delete) + setStatus (kanban drag) | button | m.bulk / m.status (ModuleController) | resolve(e/d) + per-record hub_scope + field_mode + hub_needs_approval + guardStatusRequires | ASSIGNABLE_PERMISSION | matrix e/d | sensitive |
| m/{module}/import/run (bulk import) | import | m.import.run (ImportController) | hub_can(module,'a') + abort_if(hub_scoped) full-scope only + row cap 5000 | ASSIGNABLE_PERMISSION | matrix a | sensitive |
| approvals/{id}/approve\|reject | button | approvals.approve/reject (ApprovalDecisionController->ApprovalService::decide) | hub_approver() (approve flag) + hub_can(module,op) + hub_scope on target + lockForUpdate + no double-decide + version check | ASSIGNABLE_PERMISSION | flag: approve | sensitive |
| custody-wallet advance/charge/expense/transfer/settlement/reverse/correct (employee money) | form | custody.wallet.* (EmployeeCustodyController) | hub_can('custody',v/e) + employee via hub_scope('hr') 404 + bank via hub_scope('banks'); reverse/correct behind hub_require_stepup | ASSIGNABLE_PERMISSION | matrix custody e (+ banks e) | sensitive |
| payroll/{id}/act (generate/approve/pay run) | button | payroll.act (PayrollController) | hub_can('payroll','e'); approve branch hub_approver(); hub_scope('payroll'/'hr'); balance/total invariants | ASSIGNABLE_PERMISSION | matrix payroll e + flag approve | sensitive |
| fin/{id}/act, entry post/line, stockmv act, purchase/quote/changeorder act | button | fin.act / entries.* / stockmv.act / purchases.act / quotes.act / changeorders.apply | hub_can(module,'e') + hub_scope()->findOrFail; cross-module writes (banks/fin/projects) re-check hub_can; approver where needed | ASSIGNABLE_PERMISSION | matrix e per module | sensitive |
| attachments store/download/preview/zip/move/destroy | download | att.* (AttachmentController->AttachmentService) | guardRecord() -> hub_can(module,op) + hub_scope()->findOrFail; download forces attachment, preview limits INLINE_MIMES + nosniff+CSP; av 'infected' 423 | ASSIGNABLE_PERMISSION | matrix v/e | sensitive |
| comments store/edit/pin/resolve/react/toTask/destroy | widget | comments.* (CommentController->CommentService::guardTarget) | guardTarget: feed=auth, channel=membership, else hub_can(v)+hub_scope; edit/destroy owner-only; pin/resolve owner-or-manage; toTask needs tasks:a + source scope | PARTICIPATION_GOVERNED | matrix / membership | normal |
| conversations store/join/addMember/removeMember/setRole/archive (channels) | widget | conversations.* (ConversationController::guardConversation) | guardConversation(op): membership + roleCanPost/roleCanManage; add/setRole/remove enforce role rank; owner-only archive; last-owner protected | PARTICIPATION_GOVERNED | conversation role | normal |
| dm send/edit/destroy/react + groups store/fork/leave | widget | dm.* / groups.* (DmController/GroupController) | dmReachable() company-scope; edit/destroy from_id===auth; group actions via guardConversation membership; clients 404 | PARTICIPATION_GOVERNED | participant | normal |
| clients/{client}/members invite/revoke/role (client-account membership) | form | clients.members.* (ClientMemberController) | hub_can('clients','e') + hub_scope('clients'); grant Owner / revoke behind hub_require_stepup | ASSIGNABLE_PERMISSION | matrix clients e | sensitive |
| portal/* (client self-service: engagements/projects/documents/invoices/conversations) | route | portal.* (ClientPortalController) | PortalGuard whitelist + hub_client_ids() isolation + audience filter; internal user redirected | CLIENT_PORTAL_GOVERNED | — | sensitive |
| oversight communications monitor (read ALL channels + DMs) | route | oversight.index / oversight.show (OversightController) | isOversightOfficer(): user.role.name === setting('collab.oversight_role') string match; + hub_require_stepup + mandatory audited reason | ASSIGNABLE_PERMISSION | raw role-name string (NOT a matrix key/flag) | owner_only |
| dataroom create/revoke + share s/{token} unlock/file (external data room) | download | dataroom.* / share.unlock (DataRoomController) | management gate() -> hub_secrets(); public link via signed token + optional password_hash + no-download flag | ASSIGNABLE_PERMISSION | flag: secrets | sensitive |
| esign sign/decline/otp/unlock (public signer) + internal esign mgmt | route | sign.* [web only] / esign.* [web\|auth] (EsignController) | signer routes token-gated (no auth by design); internal create/approve/cancel/extend hub_can + scope | PUBLIC_AUTHENTICATED_BASE | token / matrix | sensitive |
| self-service: profile, my/security devices+sessions, personalize/views/saved, stepup, workday check-in/out | form | profile.* / mysec.* / prefs.* / views.* / saved.* / stepup.* / workday.in\|out | all scoped to auth()->id() with where(user_id)+findOrFail; stepup rate-limited; workday operates on $r->user() only | SELF_SERVICE | — | normal |
| notifications read-all/go/count + message search + system-map + search | route | notifications.* / search.messages / system-map / search.index | notifications where(user_id); message search scoped to feed-company + channel membership + own DM threads; system-map/search filter every node by hub_can(v) | SELF_SERVICE | — | normal |
| hook/{token} inbound webhook receive; healthz; jslog; well-known aasa/assetlinks; passkey login options | api | hook.receive / healthz / jslog / mobile.aasa\|assetlinks / passkey.login.* | token / machine / throttled; healthz returns masked publicView; jslog auth+throttle | SYSTEM_INTERNAL | token / none | normal |
| analytics dashboards: cost(P&L), capacity, workforce, digital-assets, tech-workspace, sales, performance, kpis | route | cost.* / capacity.* / workforce.* / digitalassets.* / techworkspace / sales.* / kpis.* (monitor-gated) | abort_unless(hub_monitor()) (some also owner); reads hub_scope; kpi/remediation writes also monitor | ASSIGNABLE_PERMISSION | flag: monitor | sensitive |

### API/الجوّال (34)

| السطح | النوع | المرجع | التوثيق الحالي | التصنيف | المفتاح | الحساسية |
|---|---|---|---|---|---|---|
| POST /api/mobile/v1/auth/login | api | mobile.auth.login | public + throttle:10,1; creds-first (Auth::getProvider) then accountGate (5 guards); MFA if totp_enabled | PUBLIC_AUTHENTICATED_BASE | — | sensitive |
| POST /api/mobile/v1/auth/refresh | api | mobile.auth.refresh | public + throttle:20,1; refresh-token hash match + rotate-once family; re-runs accountGate on rotate | SECURITY_INVARIANT | — | sensitive |
| POST /api/mobile/v1/auth/step-up | api | mobile.auth.step_up | mobile.session; StepUp::checkCredential (totp\|password) → mobile_stepup_grants(purpose) | SELF_SERVICE | — | sensitive |
| GET/DELETE /api/mobile/v1/auth/sessions{/id} | api | mobile.auth.sessions.* | mobile.session; where user_id=auth; destroy where id+user_id=auth ⇒ 404 else | SELF_SERVICE | — | normal |
| GET/POST/PUT/PATCH/DELETE /api/mobile/v1/{module}{/id} | api | mobile.resource.* | mobile.session+mobile.portal+mobile.context; resolveApi(hub_can+client MODULE_ALLOW)+hub_scope; MobileContext narrows lists; fill() skips ro/hide fields; writes under hub_needs_approval queue an ApprovalService request | ASSIGNABLE_PERMISSION | v/a/e/d | sensitive |
| GET /api/mobile/v1/{module}/{id}/actions & POST .../actions/{action} | api | mobile.resource.actions/run_action | resolveApi(op per action: restore=d, ack=v, status/restore-version=e)+hub_scope; synthesizeActions allowlist (field_mode+status_via_action+requires+trash); protected ⇒ queue approval | ASSIGNABLE_PERMISSION | v/e/d | sensitive |
| GET /api/mobile/v1/sync/{module} | api | mobile.sync | resolveApi(hub_can v + client MODULE_ALLOW)+hub_scope+MobileContext; non-cacheable classes return empty policy; sec fields stripped even for authorized | ASSIGNABLE_PERMISSION | v | sensitive |
| GET /api/mobile/v1/search | api | mobile.search | mobile.session; SearchController::results (per-module hub_can+hub_scope); client results filtered to clientModuleAllowed | ASSIGNABLE_PERMISSION | v | sensitive |
| GET/POST /api/mobile/v1/comments | api | mobile.comments.* | CommentService::guardTarget (feed⇒company_ids, channel⇒guardConversation membership, module⇒hub_can v+hub_scope findOrFail); client blocks internal + non-client audiences | PARTICIPATION_GOVERNED | v (module) / membership (channel) | sensitive |
| GET/POST /api/mobile/v1/notifications* | api | mobile.notifications.* | mobile.session; HubNotification where user_id=auth only | SELF_SERVICE | — | normal |
| GET/POST /api/mobile/v1/dm/threads/{user}/* | api | mobile.dm.* | mobile.session; {user}=other party, thread_key rebuilt server-side from auth+other; DmService::reachable company scope ⇒ 404 | PARTICIPATION_GOVERNED | — | sensitive |
| POST /api/mobile/v1/dm/messages/{id}/react & comments/{id}/react | api | mobile.dm.react/mobile.comments.react | dm: auth∈[from,to] else 404; comment: guardTarget(module,record) | PARTICIPATION_GOVERNED | v (comment) | normal |
| GET /api/mobile/v1/conversations{/id/since} & presence | api | mobile.conversations.*/presence | denyClient(404); guardConversation('v') membership; presence filtered to dmReachable users | PARTICIPATION_GOVERNED | — | normal |
| GET /api/mobile/v1/saved | api | mobile.saved.index | where user_id=auth; each row re-guarded (guardTarget/DM membership) ⇒ available=false + body withheld if no longer visible | SELF_SERVICE | — | normal |
| POST /api/mobile/v1/files/attach & upload-session/* | api | mobile.files.upload_*/attach | AttachmentService::validateUpload + guardClientModule + guardRecord(module,record,'v') + BLOCKED ext bar; chunk state in per-user dir | ASSIGNABLE_PERMISSION | v (parent record) | sensitive |
| GET /api/mobile/v1/files/{id}/download\|stream | api | mobile.files.download/stream | Attachment::findOrFail + guardClientModule + AttachmentService::guardRecord(...,'v') + hub_scope findOrFail + av_status bar + Content-Disposition attachment | ASSIGNABLE_PERMISSION | v (parent record) | sensitive |
| GET/POST/PUT/DELETE /api/mobile/v1/clients/{client}/members* | api | mobile.clients.members.* | mobile.portal blocks client accounts; manageClient: hub_can('clients','e') else 403, hub_scope(clients) else 404; owner-grant & revoke behind mobile step-up grant | ASSIGNABLE_PERMISSION | clients.e (+ step-up member_owner/member_revoke) | owner_only |
| POST /api/mobile/v1/approvals/{id}/approve\|reject | api | mobile.approvals.* | ApprovalService::decide (hub_approver inside) + Idempotency; approvalShow visible to requester or in-scope approver | ASSIGNABLE_PERMISSION | approve flag | sensitive |
| GET /api/mobile/v1/push/admin/status\|test | api | mobile.push.admin.* | mobile.session; hub_is_owner() else 403; test only owner's own tokens; never returns key value | OWNER_ONLY | — | owner_only |
| POST /api/mobile/v1/push/register\|unregister | api | mobile.push.register/unregister | mobile.session; installation from session (not client); PushService dedupe cross-user; revoke scoped to auth user | SELF_SERVICE | — | normal |
| GET /api/mobile/v1/portal/* | api | mobile.portal.* | mobile.portal (client accounts only, internal⇒403); ClientPortalData readers fail-closed on hub_client_ids; curated client-safe columns + kind∈sales/receipts | CLIENT_PORTAL_GOVERNED | — | sensitive |
| GET /api/mobile/v1/context\|bootstrap\|schema\|navigation | api | mobile.context/bootstrap/schema*/navigation | mobile.session; hub_scope on context dims; schema built via hub_can(v)+hub_visible_fields; client gets clientIa only; no table/col leak | ASSIGNABLE_PERMISSION | v (per module in schema) | normal |
| GET /api/mobile/v1/work/today\|daily-report | api | mobile.work.* | mobile.session; hub_is_client⇒404; Workday::emp(self); own WorkUpdate rows only | SELF_SERVICE | — | normal |
| GET /api/v1/{module}{/id} CRUD | api | apiIndex/Show/Store/Update/Patch/Destroy | ApiAuth; resolveApi = hub_can(op)+token->allows(module,op)+client MODULE_ALLOW; hub_scope; shape() masks hide+sec; writes under approval ⇒ 409 APPROVAL_REQUIRED | ASSIGNABLE_PERMISSION | v/a/e/d + token scope | sensitive |
| POST /api/v1/metrics & GET /api/v1/metrics/{module}/{id} | api | V1Controller::metricsIngest/metricsShow | ingest: resolveApi(module,'e') per point (token-scoped)+hub_scope exists; show: resolveApi(module,'v')+hub_scope findOrFail | ASSIGNABLE_PERMISSION | e (ingest) / v (show) + token scope | normal |
| GET /api/v1/reports/daily (teamDaily) | api | api.v1.reports.daily | ApiAuth; hub_is_client⇒404; hub_can('hr','v') ONLY — NO tokenAllows('hr','v'); hub_company_scope+hub_scope | ASSIGNABLE_PERMISSION | hr.v (token scope NOT enforced) | sensitive |
| GET /api/v1/reports/my-daily\|today-compliance | api | api.v1.reports.my_daily/today | ApiAuth; hub_is_client⇒404; Workday::emp(self); own WorkUpdate only; no tokenAllows | SELF_SERVICE | — (token scope not checked, self-only so low impact) | normal |
| GET/POST /api/v1/projects/{id}/assets, assets/{id}/projects, asset-project/{id}/end | api | AssetProjectApiController | ApiAuth; gate(): hub_is_client⇒404, hub_can('assets','e')&&hub_can('projects','v') — NO tokenAllows; hub_scope on both sides | ASSIGNABLE_PERMISSION | assets.e + projects.v (token scope NOT enforced) | sensitive |
| POST /api/v1/track/start\|{session}/points\|end | api | V1Controller::track* | ApiAuth; fieldEmp() (own active employee + field_role) ; consent=true required; session where emp_id=own | SELF_SERVICE | — (field_role gate) | sensitive |
| GET /api/v1/identity/resolve/{q} & mobile identity | api | V1Controller/MobileFileController::identityResolve | Identity::resolve($q, auth user) applies hub_can(v)+hub_scope+hub_company_scope per type; /api/v1 also tokenAllows(module,'v') | ASSIGNABLE_PERMISSION | v (assets/products/stock/phones) + token scope | normal |
| POST /api/v1/endpoint/enroll | api | enroll.device | public + throttle:20,1; minted enroll token (sha256, one-time, short TTL); public key only | SYSTEM_INTERNAL | — | sensitive |
| POST /api/v1/endpoint/{heartbeat,event,commands/pull,commands/result} & agent/manifest\|download | api | endpoint.* | endpoint.signature (Es256 sig + ±300s ts + per-device nonce replay + status=active + lockdown); all command queries scoped where device_id=authenticated device | SYSTEM_INTERNAL | — (device signature) | sensitive |
| GET/POST /admin/mobile-platform{,/push/test,/sessions/{id}/revoke} | route | mobileplatform.* | web\|auth; controller gate(): hub_is_owner()\|\|hub_flag('mobile'); revokeSession/pushTest both gated | OWNER_ONLY | mobile flag (RISKY) | owner_only |
| GET/POST /api/mobile/v1/activation/{token}{/complete} | api | mobile.activation.* | public + throttle:12,1/6,1; AccountActivation token + 6-digit otp + attempts cap; only isClientAccount() may be activated | CLIENT_PORTAL_GOVERNED | — | sensitive |

### التنقّل/الاكتشاف (28)

| السطح | النوع | المرجع | التوثيق الحالي | التصنيف | المفتاح | الحساسية |
|---|---|---|---|---|---|---|
| Sidebar module nav (hub_nav) | widget | app/Support/helpers.php:324 | per-item hub_can(user,module,'v'); hidden-pref is display-only | ASSIGNABLE_PERMISSION | v | normal |
| Catalog centers (hub_top_links) | widget | app/Support/helpers.php:393 | per-link 'ok' predicate (owner / $mon / hub_can(...) / !hub_is_client) | ASSIGNABLE_PERMISSION | per-center hub_can key | sensitive |
| Sidebar tools/boards groups (hub_top_groups) | widget | app/Support/helpers.php:519 | reads hub_top_links(user) + nav.hidden_top pref | ASSIGNABLE_PERMISSION | — | normal |
| Admin gear bar (hub_admin_links + app.blade) | widget | resources/views/layouts/app.blade.php:145 | outer: owner\|\|flag(users)\|\|flag(audit)\|\|hub_secrets; per-link ok | OWNER_ONLY | users/audit/secrets flags | owner_only |
| Control overview center | route | hub_admin_links:5710 / ControlController@gate:75 | nav: owner\|\|hub_monitor (link ok); gate: owner\|\|hub_monitor | ASSIGNABLE_PERMISSION | monitor flag | sensitive |
| Alert center | route | hub_admin_links:5720 / AlertCenterController@readGate:27 | nav: owner\|\|hub_monitor; gate: owner\|\|hub_monitor | ASSIGNABLE_PERMISSION | monitor flag | sensitive |
| Mobile platform center | route | hub_admin_links:5723 / MobilePlatformController::canView:49 | nav: owner\|\|hub_flag(mobile); gate: owner\|\|hub_flag(mobile) | ASSIGNABLE_PERMISSION | mobile flag | sensitive |
| Pinned shortcuts (hub_pins/hub_pin_targets) | widget | app/Support/helpers.php:461 | targets filtered by hub_top_links.ok + hub_can(v); stale tokens dropped | SELF_SERVICE | — | normal |
| Start-screen home preference (hub_home_url) | route | app/Support/helpers.php:547 | m: prefix re-checks hub_can; catalog key re-checks hub_top_links(user); else dashboard | SELF_SERVICE | — | normal |
| IA named-guard map | widget | app/Support/InformationArchitecture.php:58-108 | one predicate per guard mirroring the controller gate | ASSIGNABLE_PERMISSION | varies | sensitive |
| IA destinationVisible (admin branch) | widget | app/Support/InformationArchitecture.php:175 | admin type checks adminOk[key] (per-link ok) — NOT the domain guard | ASSIGNABLE_PERMISSION | — | sensitive |
| IA visibleDomains (domain guard) | widget | app/Support/InformationArchitecture.php:243-247 | applies domain-level 'guard' (admin_bar) before destinations | OWNER_ONLY | admin_bar | sensitive |
| Unified search — destinations | search | app/Http/Controllers/Web/SearchController.php:154 | IA searchDestinations (per-destination guard) + Workspaces::for + operational + workOs | ASSIGNABLE_PERMISSION | per-destination | sensitive |
| Unified search — record results | search | app/Http/Controllers/Web/SearchController.php:49 | searchableModules (hub_can v) + hub_scope + hub_client_scope; endpoints shed to owner/monitor | ASSIGNABLE_PERMISSION | v | sensitive |
| Search — operational (id/ip/email/error/setting) | search | app/Http/Controllers/Web/SearchController.php:234 | per-pattern: audit for trace/ip, owner for errors/settings, flag(users) for email | ASSIGNABLE_PERMISSION | audit/users flags, owner | sensitive |
| Search — Work OS (channels/custody) | search | app/Http/Controllers/Web/SearchController.php:316 | channel: active membership EXISTS + company/client scope + record-module hub_can; custody: hub_can(custody,v)+company scope | PARTICIPATION_GOVERNED | membership / custody.v | sensitive |
| Search — recents | search | app/Http/Controllers/Web/SearchController.php:76 | re-checks hub_mod + hub_can(v) + hub_scope per row at render | SELF_SERVICE | v | normal |
| Search — quick +new actions | button | app/Http/Controllers/Web/SearchController.php:107 | hub_nav items + hub_can(user,module,'a') | ASSIGNABLE_PERMISSION | a | normal |
| System map (owner diagnostic) | route | app/Http/Controllers/Web/SystemMapController.php:21 | auth() to view; systemMap permission-filters; diagnostic only if hub_is_owner | PUBLIC_AUTHENTICATED_BASE | — | normal |
| Mobile navigation payload | api | app/Http/Controllers/Api/MobileContextController.php:174 | hub_is_client ? [] : navigationPayload(u) (permission-filtered) | ASSIGNABLE_PERMISSION | per-destination | sensitive |
| Breadcrumbs (IA) | widget | app/Support/InformationArchitecture.php:582 | computed for the current already-authorized route; generic container labels | PUBLIC_AUTHENTICATED_BASE | — | normal |
| Dashboard quick-links widget | widget | app/Support/WidgetRegistry.php:246 | per-tile 'ok'; but inboxdocs.index tile is ok=true unconditionally | ASSIGNABLE_PERMISSION | mixed | normal |
| Dashboard widgets (counts/expiry/audits/kpis) | widget | app/Support/WidgetRegistry.php:64 | isVisible gate + per-row hub_can/hub_scope inside resolvers | ASSIGNABLE_PERMISSION | per-widget | sensitive |
| Saved messages (favorites) | widget | app/Http/Controllers/Web/SavedController.php:66 | per-viewer; guardTargetVisible re-authorizes each target at open time | SELF_SERVICE | — | normal |
| Workspace page (/w/{key}) | route | app/Http/Controllers/Web/WorkspaceController.php:18 | abort_unless(Workspaces::find(key,u)) — null unless >=1 visible module | ASSIGNABLE_PERMISSION | v | normal |
| Enterprise analytics centers (capacity/recs/impact/kpis/costs) | route | app/Support/helpers.php:417-426 | nav: ok=$mon (owner\|\|monitor); controllers add hub_org_analytics_guard() (403 for scoped) | ASSIGNABLE_PERMISSION | monitor flag | sensitive |
| Identity/scan center | route | app/Support/helpers.php:429 / IdentityController@center:39 | nav: hub_can(assets,v); gate: assets.v \|\| products.v | ASSIGNABLE_PERMISSION | assets.v/products.v | normal |
| Monthly attendance sheet center (attmonth) | route | app/Support/helpers.php:436 / ReportsController@monthly:49 | nav & gate both attend.v \|\| hr.v | ASSIGNABLE_PERMISSION | attend.v | sensitive |

### اللوحات/العدّادات (30)

| السطح | النوع | المرجع | التوثيق الحالي | التصنيف | المفتاح | الحساسية |
|---|---|---|---|---|---|---|
| Base dashboard | route | dashboard (DashboardController@index) | web\|auth only; every widget re-gated at render via WidgetRegistry::isVisible | PUBLIC_AUTHENTICATED_BASE | — | normal |
| counts widget (top counters) | widget | app/Support/WidgetRegistry.php:108 | per-module hub_can(u,key,'v') + hub_scope() before count(); cached per user id 60s | ASSIGNABLE_PERMISSION | v (per module) | normal |
| kpis widget | widget | app/Support/WidgetRegistry.php:139 | gate hub_monitor(u); values via hub_kpis(u)→hub_kpi_metric hub_scope+hub_can | ASSIGNABLE_PERMISSION | flag:monitor | sensitive |
| expiry radar widget | widget | app/Support/WidgetRegistry.php:147 | hub_expiry(u): hub_can+hub_field_mode(hide)+hub_scope; re-filtered by hub_can | ASSIGNABLE_PERMISSION | v (per module) | normal |
| apps progress widget | widget | app/Support/WidgetRegistry.php:155 | gate hub_can(u,'apps','v') + hub_scope(applications,'apps') | ASSIGNABLE_PERMISSION | apps.v | normal |
| donut tasks-by-status widget | widget | app/Support/WidgetRegistry.php:172 | gate hub_can(u,'tasks','v') + hub_scope(tasks) before GROUP BY | ASSIGNABLE_PERMISSION | tasks.v | normal |
| due tasks widget | widget | app/Support/WidgetRegistry.php:183 | gate hub_can(u,'tasks','v') + hub_scope(tasks) | ASSIGNABLE_PERMISSION | tasks.v | normal |
| audits activity widget | widget | app/Support/WidgetRegistry.php:207 | audits.module IN visible-modules + company_id IN hub_company_ids (or own) + project scope | ASSIGNABLE_PERMISSION | v (per module) | sensitive |
| checkin (my workday) widget | widget | app/Support/WidgetRegistry.php:102 | gate Workday::emp(u)!==null; resolver Workday::mine(u) | SELF_SERVICE | — | normal |
| pendingLine header counter | widget | DashboardController.php:90 | tasks assignee_id=self; tickets hub_can+hub_scope; approvals hub_flag(approve)\|\|owner + hub_read + hub_open_scope | SELF_SERVICE | — | normal |
| CEO dashboard | route | ceo (CeoController@index) | abort_unless(hub_is_owner(),403) | OWNER_ONLY | role.is_owner | owner_only |
| CeoBoard decision cards | widget | app/Support/CeoBoard.php awaiting/leaks/conc/risks/gov | owner-only page; hub_read()+hub_open_scope on each source | OWNER_ONLY | role.is_owner | owner_only |
| Performance dashboard | route | performance (PerformanceController@index) | abort_unless(hub_monitor(),403) + hub_org_analytics_guard() | ASSIGNABLE_PERMISSION | flag:monitor | sensitive |
| Performance people KPI board | widget | PerformanceController.php:115 peopleKpis | org guard; employees.perf gated by hub_field_mode(hr,perf)!=='hide' | ASSIGNABLE_PERMISSION | flag:monitor + field hr.perf | sensitive |
| Capacity / impact / recs / quality | route | capacity,impact,recs,appquality (CapacityController) | gate(): hub_monitor() + hub_org_analytics_guard(); impact deps lens-scoped | ASSIGNABLE_PERMISSION | flag:monitor | sensitive |
| KPI center / builder | route | kpis.index (KpiController@index) | gate hub_monitor() ONLY (no org guard); values hub_kpis(u) scoped; trend org-wide | ASSIGNABLE_PERMISSION | flag:monitor | sensitive |
| KPI write ops | button | KpiController store/update/toggle/move/destroy | gate hub_monitor() + hub_can on formula modules | ASSIGNABLE_PERMISSION | flag:monitor | normal |
| Cost / profitability dashboard | route | costs.index (CostController@index) | hub_monitor() + hub_scope(Project) per-project P&L | ASSIGNABLE_PERMISSION | flag:monitor | sensitive |
| Service costs + MRR | route | servicecosts (CostController@services) | hub_monitor() + hub_org_analytics_guard() | ASSIGNABLE_PERMISSION | flag:monitor | sensitive |
| Sales dashboard | route | sales.dashboard (SalesController@dashboard) | owner\|\|monitor; SalesBoard hub_scope(quotes)+hub_scope(clients); quotes.cost via field mode; cache per hub_scope_key | ASSIGNABLE_PERMISSION | flag:monitor | sensitive |
| Alert center | route | alerts.center (AlertCenterController@index) | readGate owner\|\|monitor; PII masked for non-owner; counts org-wide | ASSIGNABLE_PERMISSION | flag:monitor | sensitive |
| Alert act (ack / incident) | button | alerts.ack, alerts.incident | gate() abort_unless(hub_is_owner()) + hub_audit | OWNER_ONLY | role.is_owner | owner_only |
| Live uptime check | button | monitor.check (MonitorController@check) | hub_can(u,module,'e') + hub_scope()->findOrFail | ASSIGNABLE_PERMISSION | e (per module) | normal |
| Communications oversight | route | oversight.index/show (OversightController) | role.name==setting(collab.oversight_role) + hub_require_stepup + mandatory reason + company/client scope + audit | PARTICIPATION_GOVERNED | raw_role (by design, setting-driven) | owner_only |
| Workforce overview | route | workforce.overview (WorkforceController@overview) | gate hub_monitor() + hub_org_analytics_guard(); ExecutionStats::org org-wide | ASSIGNABLE_PERMISSION | flag:monitor | sensitive |
| Control plane (6 cards) | route | control.index (ControlController@index) | gate owner\|\|monitor; per-card visible gate BEFORE compute; quality/execution require monitor&&org guard; security scoped by hub_company_ids; incidents hub_scope | ASSIGNABLE_PERMISSION | flag:monitor / flag:audit / owner | sensitive |
| Attention queue | widget | app/Support/AttentionQueue.php:131 | owner\|\|monitor; each source re-gated (health/errors/quality owner-only, audit flag:audit, okr hub_can+hub_scope, security scopeCompanies+maskPII); cache per hub_scope_key | ASSIGNABLE_PERMISSION | flag:monitor + per-source | sensitive |
| Sidebar attention badges | widget | app/Support/Workspaces.php:50 attentionByModule/byWorkspace | hub_expiry(u) per-user (hub_can+field_mode+hub_scope); cache keyed per scoped user | ASSIGNABLE_PERMISSION | v (per module) | normal |
| Collaboration unread rail badges | widget | app/Support/CollaborationRail.php:29 | conversation_members membership + company/client scope; unreadCounts only for member convs | PARTICIPATION_GOVERNED | membership | normal |
| Attention / notification inbox | widget | collab.attention + NotificationController | HubNotification where user_id=auth id; unread count self-scoped | SELF_SERVICE | — | normal |

### واجهة/أزرار (35)

| السطح | النوع | المرجع | التوثيق الحالي | التصنيف | المفتاح | الحساسية |
|---|---|---|---|---|---|---|
| Module list — new/edit/delete/restore row actions | button | resources/views/modules/index.blade.php:34,144,184,186 / show.blade.php:49,52 | hub_can(module,a\|e\|d) in view; server ModuleController::resolve():19 abort_unless hub_can(module,op)+hub_scope | ASSIGNABLE_PERMISSION | v/a/e/d | normal |
| Module CSV export (header + bulk 'export selected') | export | resources/views/modules/index.blade.php:28-30,216-218 | hub_exporter() [exp flag] in view; server ModuleController::exportBelt():796 abort_unless hub_exporter()+freeze/stepup, resolve('v') | ASSIGNABLE_PERMISSION | exp flag (global) | sensitive |
| Module import (upload/map/run) | import | resources/views/modules/index.blade.php:31-33 / import/map.blade.php | hub_can(module,a)&&!hub_scoped in view; server ImportController::resolve():24-25 hub_can(module,a)+!hub_scoped | ASSIGNABLE_PERMISSION | a + not-scoped | sensitive |
| Module bulk status/delete/export bar | button | resources/views/modules/index.blade.php:205-224 | hub_can(module,e/d)+field_mode+!needs_approval / hub_exporter; server ModuleController::bulk() | ASSIGNABLE_PERMISSION | e/d/exp | normal |
| Secret field reveal | button | resources/views/partials/_display.blade.php:6-23 | hub_copy_secrets() in view; server ModuleController::secret():400-410 hub_copy_secrets+type=sec+field_mode!=hide+allowed_ids allowlist | ASSIGNABLE_PERMISSION | copySec flag | owner_only |
| Attachment download / preview / zip / delete | download | resources/views/partials/attachments.blade.php:6-8,29,46-54 | delete guarded uploader\|\|owner\|\|hub_can(module,e); server AttachmentService::download/stream guardRecord()=hub_can(module,v)+hub_scope | ASSIGNABLE_PERMISSION | module v (read) / e (delete) | sensitive |
| Module file/img field download link | download | resources/views/partials/_display.blade.php:40-51 -> route file.show | server FileController::mayRead():182-200 hub_can(module,v)+hub_scope ONLY — does NOT check hub_field_mode | ASSIGNABLE_PERMISSION | module v | sensitive |
| Custody wallet — advance/charge/repayment/transfer/deduction/settlement | form | resources/views/custody-wallet/employee.blade.php:24-88 | hub_can(custody,e) in view; server EmployeeCustodyController::can('e') per method | ASSIGNABLE_PERMISSION | custody.e | sensitive |
| Custody wallet — expense/correct/reverse (approved spend) | form | resources/views/custody-wallet/employee.blade.php:90-117 | hub_can(custody,approve) in view; server can('approve')+hub_require_stepup on correct/reverse | ASSIGNABLE_PERMISSION | custody.approve (named key already in use) | sensitive |
| Payroll — generate/adjust/approve/pay | button | resources/views/modules/custom/payroll.blade.php:52-72 | only hub_can(payroll,e) wraps ALL buttons incl approve; server PayrollController::approve() ALSO requires hub_approver(), pay() requires status=approved | ASSIGNABLE_PERMISSION | payroll.e (+approve flag on server for approve) | sensitive |
| Journal entry — add line / drop line / post | button | resources/views/modules/custom/entries.blade.php:33,51,64-68 | hub_can(entries,e) in view; server EntryController::entry():hub_can(entries,e) for post & line | ASSIGNABLE_PERMISSION | entries.e | sensitive |
| Financial doc — record payment (fin.act do=pay) | form | resources/views/partials/fin_actions.blade.php:9,17 | hub_can(fin,e) in view; server FinController::act() abort_unless hub_can(fin,e) | ASSIGNABLE_PERMISSION | fin.e | sensitive |
| Stock movement — post(confirm)/cancel | button | resources/views/partials/stockmv_actions.blade.php:5,15,24,28 | hub_can(stockmv,e) in view; server StockController::act() hub_can(stockmv,e) | ASSIGNABLE_PERMISSION | stockmv.e | sensitive |
| Quote — send/accept/reject/clone + convert to contract/invoice/project | button | resources/views/partials/quote_actions.blade.php:17-47 | hub_can(quotes,e)(+projects,a for project) in view; server QuoteController::act() hub_can(quotes,e) | ASSIGNABLE_PERMISSION | quotes.e | sensitive |
| Purchase — submit/approve/send/receive/bill/return | button | resources/views/partials/purchase_actions.blade.php:6,18-19 | hub_can(purchases,e)+hub_approver() for approve in view; server PurchaseController::approve() abort_unless hub_approver() | ASSIGNABLE_PERMISSION | purchases.e + approve flag | sensitive |
| Change order — apply to project | button | resources/views/modules/custom/changeorders.blade.php:17-22 | hub_can(changeorders,e) in view; server ChangeOrderController::apply() requires changeorders:e AND projects:e | ASSIGNABLE_PERMISSION | changeorders.e (+projects.e server-only) | sensitive |
| Protected-op approval — approve&execute / reject | button | resources/views/partials/approval_exec.blade.php:22,54-64 | hub_approver() in view; server ApprovalService::decide():61 hub_approver()+hub_can(target,op)+scope | ASSIGNABLE_PERMISSION | approve flag | sensitive |
| Client workspace members — invite/setRole/revoke | form | resources/views/modules/custom/clients.blade.php:57,84-113,126-140 | hub_can(clients,e) in view; server ClientMemberController::manageClient() hub_can(clients,e)+stepUp for owner-grant/revoke | CLIENT_PORTAL_GOVERNED | clients.e + stepup | sensitive |
| Staff — open system account / link / unlink / align | form | resources/views/partials/staff_account_card.blade.php:16,53-63 / staff.blade.php | UI: hub_flag(users)+hub_can(hr,e); server StaffController::account() gate('e') then Staff::makeAccountResult() abort_unless hub_flag(users)+owner-role check | OWNER_ONLY | users flag (enforced in model) | sensitive |
| Recruit — hire candidate (+ optional account with role) | form | resources/views/modules/custom/recruit.blade.php:4,13-27 | UI: hub_can(recruit,e)&&hub_can(hr,a); account block behind hub_flag(users); server HireController::hire() recruit:e+hr:a, account only if hub_flag(users) | ASSIGNABLE_PERMISSION | recruit.e+hr.a (+users for account) | sensitive |
| Role matrix editor — store/update/duplicate/destroy | form | resources/views/roles/form.blade.php (no in-view guard) | server RoleController::gate() hub_is_owner() in every method; is_owner never writable (data() forces false) | OWNER_ONLY | is_owner | owner_only |
| Security center — freeze/lockdown/revoke session/revoke token/block IP/finding ack-resolve | button | resources/views/security/parts/*.blade.php (zero in-view guard) | server SecurityController::gate()/blocksGate() hub_is_owner()+hub_require_stepup on high-impact | SECURITY_INVARIANT | is_owner + stepup | owner_only |
| Ops center — migrate/clearCache/backup/maintenance/outbox-retry/demo reset-off | button | resources/views/ops/parts/*.blade.php + web.php:769-783 demo closures | server OpsController::gate() hub_is_owner()+ops_stepup; demo closures abort_unless is_owner+ops_stepup | OWNER_ONLY | is_owner + stepup | owner_only |
| Webhooks / Flows / Custom fields / Feature toggles admin | form | admin/webhooks.blade.php, flows/index.blade.php, fields/index.blade.php, features/detail.blade.php (zero in-view guard) | server *Controller::gate() hub_is_owner() in every method | OWNER_ONLY | is_owner | owner_only |
| Data room — create share link / revoke | form | resources/views/dataroom/index.blade.php (zero in-view guard) | server DataRoomController::gate() hub_secrets() [secrets flag] | ASSIGNABLE_PERMISSION | secrets flag | sensitive |
| E-sign — send/approve/reject/cancel/extend/resend + template edit/archive/destroy | button | resources/views/esign/index.blade.php (zero in-view guard) | server EsignController::gate(a/e/v) hub_can(contracts,op); approve/reject add ContractApprovals::canDecide() | ASSIGNABLE_PERMISSION | contracts.a/e + approver | sensitive |
| Conversation — add/remove member, set role, archive | button | resources/views/conversations/show.blade.php (zero in-view guard) | server ConversationController guard() membership+Conversation::roleCanManage(role); archive owner-only | PARTICIPATION_GOVERNED | conversation membership role | normal |
| DM — send/edit/destroy/react | button | resources/views/dm/_messages.blade.php (zero in-view guard) | server DmController::edit/destroy abort_unless from_id===auth id | SELF_SERVICE | own message | normal |
| Mobile platform — revoke session / push test | button | resources/views/mobile-platform/tabs/devices.blade.php,push.blade.php (zero in-view guard) | server MobilePlatformController::gate() hub_is_owner()\|\|hub_flag(mobile) | ASSIGNABLE_PERMISSION | mobile flag | sensitive |
| Dashboards (boards) — store/edit/update/destroy/add widget | form | resources/views/boards/index.blade.php (zero in-view guard) | server BoardController: own owner_id; guard editableBy(); shared/role-scope only hub_is_owner; widget WidgetRegistry::isVisible() | SELF_SERVICE | owner_id / is_owner for shared | normal |
| Project 360 — finance (P&L) tab | tab | resources/views/modules/custom/projects.blade.php:23,66,297-325 | $pcFin = field_mode(cost)!=hide && field_mode(budget)!=hide; client accounts stripped ($pcCliHide) | ASSIGNABLE_PERMISSION | field_rules cost+budget (hide) | sensitive |
| Costs center — per-project P&L (?p=) | route | app/Http/Controllers/Web/CostController.php:31-39 | gate() hub_monitor() [monitor flag] + hub_scope(projects) | ASSIGNABLE_PERMISSION | monitor flag | sensitive |
| Reports — finance / monthly / monthly-employee (salary) | route | resources/views/reports/finance.blade.php + ReportController::finance():25 | hub_can(fin,v); $seesTotals masks numbers when absent | ASSIGNABLE_PERMISSION | fin.v | sensitive |
| HR employee 360 — custody balance / salary display | widget | resources/views/modules/custom/hr.blade.php:41-73 | hub_can(custody,v); amount masked when field_mode(custody,amount)=hide | ASSIGNABLE_PERMISSION | custody.v + field_mode | sensitive |
| Saved views — set default / destroy | button | resources/views/modules/index.blade.php:96-99 + PrefController:201,213 | server scoped SavedView::where(user_id=auth id)->findOrFail | SELF_SERVICE | own record | normal |

### التعاون (24)

| السطح | النوع | المرجع | التوثيق الحالي | التصنيف | المفتاح | الحساسية |
|---|---|---|---|---|---|---|
| conversations.show / collab openConversation (channel/group timeline+members+pins+files) | route | ConversationController@show / CollaborationController::openConversation | guardConversation('v') = member(conversation_members) + company/client scope + hub_can(module,'v') for record threads | PARTICIPATION_GOVERNED | — | normal |
| conversations.store (create channel, optional client audience) | route | ConversationController@store | auth only; client_id validated against hub_client_ids; company auto-tagged | PUBLIC_AUTHENTICATED_BASE | — | sensitive |
| conversations.directory (discoverable channels) | route | ConversationController@directory | abort_if(hub_is_client); audience=internal + visibility∈{company,public} + scope + not-already-member | PARTICIPATION_GOVERNED | — | normal |
| conversations.join (self-join) | route | ConversationController@join | client-blocked; re-validates audience=internal + discoverable + scope server-side | SELF_SERVICE | — | normal |
| conversations.member.add | route | ConversationController@addMember:551 | guardConversation('manage') owner/moderator; owner-only for granting moderator/owner | PARTICIPATION_GOVERNED | — | sensitive |
| conversations.member.remove / member.role | route | ConversationController@removeMember:632 / setRole:593 | guardConversation('manage'); rank guards (can't touch >= own rank); last-owner protection | PARTICIPATION_GOVERNED | — | normal |
| conversations.archive | route | ConversationController@toggleArchive:533 | guardConversation('v',includeArchived) then role==='owner' | PARTICIPATION_GOVERNED | — | normal |
| conversations.favorite / notify (per-member prefs) | route | ConversationController@toggleFavorite:513 / setNotifyPref:485 | guardConversation('v'); writes only the caller's own membership row | SELF_SERVICE | — | normal |
| conversations.since / typing (incremental poll) | route | ConversationController@since:424 / typing:467 | guardConversation('v'); typing gated by hub_capability('collab.typing') | PARTICIPATION_GOVERNED | — | normal |
| collab.center / collab.attention | route | CollaborationController@center:30 / attention:74 | abort_if(hub_is_client) + guardConversation/openDm per selection; attention reads own HubNotifications | PARTICIPATION_GOVERNED | — | normal |
| dm.thread / dm.start / dm.send | route | DmController@thread:244 / start:287 / send:297 | dmReachable(other) (company intersection); recipient must be active; self blocked | PARTICIPATION_GOVERNED | — | normal |
| dm.edit / dm.destroy / dm.react | route | DmController@edit:337 / destroy:358 / react:462 | party-only (in_array auth in [from,to]); edit/destroy author-only; no react on deleted | PARTICIPATION_GOVERNED | — | normal |
| dm.since / dm.typing | route | DmController@since:395 / typing:444 | dmReachable(other); thread_key from auth()+other server-side; inCompanyScope on rows | PARTICIPATION_GOVERNED | — | normal |
| groups.index / groups.store | route | GroupController@index:31 / store:56 | index: own membership; store: abort_if(client) + validateParticipants (internal, in-scope, non-client, non-self) | PARTICIPATION_GOVERNED | — | normal |
| groups.fork / groups.leave | route | GroupController@fork:96 / leave:119 | guardConversation('v') + kind==='group'; fork enforces MAX_MEMBERS; add-member forbidden on groups (history-audience safety) | PARTICIPATION_GOVERNED | — | normal |
| search.messages (cross-surface message text search) | search | MessageSearchController@index:27 | feed: company scope; channels: whereIn member conversation_ids; DMs: party + inCompanyScope | PARTICIPATION_GOVERNED | — | normal |
| meetings.extract (minutes -> decisions/tasks) | button | MinutesController@extract:18 | hub_can(meetings,'e') + hub_scope(Meeting)->findOrFail + hub_can(decisions,'a') + hub_can(tasks,'a') | ASSIGNABLE_PERMISSION | meetings:e / decisions:a / tasks:a | normal |
| integrations.messaging index/mail/telegram/test/retry (SMTP+Telegram secrets, outbox) | route | MessagingController@gate:24 | hub_is_owner() | OWNER_ONLY | owner | owner_only |
| portal.conversations / portal.conversation (web client rooms) | route | ClientPortalController@conversations:134 / conversation:143 -> ClientPortalData::conversationRows/Detail | PortalGuard whitelist + client gate + membership + audience∈{client,both}; messages filter internal | CLIENT_PORTAL_GOVERNED | — | sensitive |
| mobile conversations/{id}/since,typing,presence,saved | api | MobileCollabController | denyClient() (404 for clients) + guardConversation('v') / dmReachable; saved re-authorizes each row via guardTarget | PARTICIPATION_GOVERNED | — | normal |
| mobile dm threads/messages/send/read/since/typing/react | api | MobileCommController / MobileCollabController | DmService::reachable + thread_key rebuilt server-side from auth()+{user} (F8); party-only react | PARTICIPATION_GOVERNED | — | normal |
| mobile comments/{id}/react | api | MobileCollabController@commentReact:238 | CommentService::guardTarget(auth,module,record) then toggle reaction | PARTICIPATION_GOVERNED | — | normal |
| mobile portal/conversations/{id} (client rooms) | api | MobileClientPortalController@conversations:169 / conversation:184 | MobilePortalGuard + ClientPortalData membership+audience; internal messages filtered | CLIENT_PORTAL_GOVERNED | — | sensitive |
| mobile POST comments (channel write incl. client rooms) | api | MobileCommController@postComment:242 | guardConversation('post') + (client) audience∈{client,both} else 404 + internal forced false for clients | CLIENT_PORTAL_GOVERNED | — | sensitive |

### المشاريع (27)

| السطح | النوع | المرجع | التوثيق الحالي | التصنيف | المفتاح | الحساسية |
|---|---|---|---|---|---|---|
| Project list/index | route | m.index (ModuleController::index:177) | hub_can(projects,'v') via resolve():19 | ASSIGNABLE_PERMISSION | projects.v | normal |
| Project show / Project360 | route | m.show (ModuleController::show:342 + modules/custom/projects.blade.php) | hub_can(projects,'v') | ASSIGNABLE_PERMISSION | projects.v | sensitive |
| Project edit (members/manager/financials/tech in one form) | form | m.update (ModuleController::update:444) | hub_can(projects,'e') | ASSIGNABLE_PERMISSION | projects.e | sensitive |
| Project 360 — Finance tab (full P&L) | widget | resources/views/modules/custom/projects.blade.php:297-328 | !isCli && field_mode(projects.cost)!=hide && field_mode(projects.budget)!=hide (defaults OPEN) | ASSIGNABLE_PERMISSION | — (proposed projects.fin.view) | sensitive |
| Project 360 — Activity/work-log tab | widget | resources/views/modules/custom/projects.blade.php:36-40, 331-380 | !isCli ONLY (no hub_can(updates,'v')) | ASSIGNABLE_PERMISSION | — (should require updates.v) | sensitive |
| Project 360 — Delivery health + children | widget | resources/views/modules/custom/projects.blade.php:164-199 | projects.v; children via hub_related() per-child hub_can(ck,'v') (helpers.php:3237) | ASSIGNABLE_PERMISSION | projects.v | normal |
| Project 360 — Baseline / change orders | widget | resources/views/modules/custom/projects.blade.php:206-251 | projects.v (money rows gated by $pcFin field-mode) | ASSIGNABLE_PERMISSION | projects.v | sensitive |
| Project 360 — internal/client rooms | widget | resources/views/modules/custom/projects.blade.php:253-295 | Conversation::roleOf membership; roleCanPost/roleCanManage | PARTICIPATION_GOVERNED | — | sensitive |
| Costs dashboard (/costs, /costs?p=) | route | costs.index (CostController::index:31, gate:12-16) | hub_monitor() flag ONLY + hub_scope + hub_org_analytics_guard | ASSIGNABLE_PERMISSION | monitor flag | sensitive |
| Service costs (/service-costs) | route | servicecosts (CostController::services:19) | hub_monitor() | ASSIGNABLE_PERMISSION | monitor flag | sensitive |
| Capacity lens (/capacity?p=) | route | capacity (CapacityController::index:21, gate:13) | hub_monitor() + org_analytics_guard; hub_lens() validates ?p= scope | ASSIGNABLE_PERMISSION | monitor flag | sensitive |
| Impact map (/impact) | route | impact (CapacityController::impact:77) | hub_monitor() + org_analytics_guard | ASSIGNABLE_PERMISSION | monitor flag | normal |
| Recommendations / act (/recommendations) | route | recs / recs.act (CapacityController::recommendations:45, recAct:56) | hub_monitor() | ASSIGNABLE_PERMISSION | monitor flag | normal |
| App quality lens (/app-quality) | route | appquality (CapacityController::quality:32) | hub_monitor() | ASSIGNABLE_PERMISSION | monitor flag | normal |
| Asset→Project assign (web, both directions) | button | projects.assets.assign / assets.projects.assign (AssetProjectController:31-38,54,80) | hub_can(assets,'e') && hub_can(projects,'v') + hub_scope on BOTH asset and project + client blocked | ASSIGNABLE_PERMISSION | assets.e (proposed project.asset.assign) | sensitive |
| Asset→Project assign / end (API v1) | api | api.v1.projects.assets, assetproject end (AssetProjectApiController:27-33) | same gate() as web + ApiAuth | ASSIGNABLE_PERMISSION | assets.e | sensitive |
| Change-order apply to project | button | changeorders.apply (ChangeOrderController::apply:18-24) | hub_can(changeorders,'e') && hub_can(projects,'e') + hub_scope + idempotent lock | ASSIGNABLE_PERMISSION | changeorders.e + projects.e | sensitive |
| Approval decide (web) | button | approvals.approve/reject (ApprovalDecisionController -> ApprovalService::decide:58) | hub_approver() + hub_can(target module,op) + hub_scope on target record + row lock | ASSIGNABLE_PERMISSION | approve flag | sensitive |
| Approval decide (mobile) | api | mobile.approvals.* (MobileWorkController:53,109,117) | hub_is_owner\|\|hub_approver + hub_scope + ApprovalService::decide re-check | ASSIGNABLE_PERMISSION | approve flag | sensitive |
| Report review center + act | route | reports.review / reports.review.act (ReportsController:133,161; ReportReview::canReview:35) | ReportReview::canReviewAny (owner\|\|hr.v\|\|updates.e); per-item canReview (owner\|\|hr.e\|\|(updates.e && project in scope)); no self-review except owner | ASSIGNABLE_PERMISSION | updates.e / hr.e (proposed updates.review) | sensitive |
| Daily reports center | route | reports.index/day (ReportsController::index:63) | guardTeam: !client && hub_can(hr,'v') + company/proj scope | ASSIGNABLE_PERMISSION | hr.v | sensitive |
| Compliance finalize (attendance seal) | button | reports.finalize (ReportsController::finalize:192) | !client && (hub_can(hr,'e')\|\|owner) + company scope | ASSIGNABLE_PERMISSION | hr.e | sensitive |
| Client portal projects | route | portal.projects/portal.project (ClientPortalController:69,78; ClientPortalData::projectDetail:97) | PortalGuard(client-only) + hub_is_client gate + whereIn(client_id, hub_client_ids) + findOrFail; client-safe SELECT only | CLIENT_PORTAL_GOVERNED | — | sensitive |
| Project progress API | api | api/v1/reports/progress/{projectId} (V1Controller::progress:225) | hub_can(projects,'v') + tokenAllows(projects,v) + hub_scope findOrFail | ASSIGNABLE_PERMISSION | projects.v | normal |
| Delivery PSA board | route | delivery.psa (DeliveryController::psa:54) | hub_can(projects,'v'); tickets/quotes rows gated by their own view perm | ASSIGNABLE_PERMISSION | projects.v | normal |
| Apps↔Projects fix (bulk project_id write) | button | appsprojects.fix (AppsProjectsController::fix:28 -> AppsProjects::fixMissing:190) | controller gate apps.v\|\|projects.v; service enforces per-record hub_can(module,'e') | ASSIGNABLE_PERMISSION | apps.v\|\|projects.v (write per-module .e) | normal |
| Odoo per-project config | route | odoo.project (OdooController::project:67 via target:52), odoo.project.conn setConn:94 | hub_can(projects,'e') + hub_scope; setConn requires hub_is_owner | ASSIGNABLE_PERMISSION | projects.e / owner | sensitive |

### الموارد البشرية (24)

| السطح | النوع | المرجع | التوثيق الحالي | التصنيف | المفتاح | الحساسية |
|---|---|---|---|---|---|---|
| HR list / show / export (generic engine) | route | m.index/m.show/m.export → ModuleController.php:177,342,762 | hub_can(hr,v); export also hub_exporter() exp-flag + freeze/step-up belt (ModuleController.php:794) | ASSIGNABLE_PERMISSION | hr.v / hr.d(trash) / exp flag | sensitive |
| Employee 360 full profile | route | portal.employee → PortalController.php:30-76 | hub_can(hr,v); each tab re-gated by hub_can(tab.mod,v), ?tab= forged for unowned module → 403 (line 46) | ASSIGNABLE_PERMISSION | hr.v + per-tab module v | sensitive |
| بوابتي / my portal (self) | route | portal.me → PortalController.php:16-27 | auth only; queries own emp (user_id=auth id) + own tasks/leaves/attendance | SELF_SERVICE | — | normal |
| 360 reports tab (daily attendance+report) | tab | Employee360::dailyReports Employee360.php:132; catalog PortalController.php:90 | hub_can(updates,v) ONLY | ASSIGNABLE_PERMISSION | updates.v | sensitive |
| 360 overview relation counts | widget | Employee360::overview Employee360.php:20-62 | each count behind hub_can(module,v)+hub_scope; custody balance behind custody:v AND hub_field_mode(custody,amount)!=hide | ASSIGNABLE_PERMISSION | stations/assets/projects/tasks/phones/endpoints/custody .v | normal |
| Team directory (/team) | route | team → TeamController.php:12; TeamDirectory::cards TeamDirectory.php:23 | hub_can(hr,v); salary/iqamaExp/passExp behind hub_field_mode (TeamDirectory.php:61-63); hub_scope(hr) | ASSIGNABLE_PERMISSION | hr.v + field_rules | sensitive |
| Staff gaps: link/unlink/account/file/align | route | StaffController.php:26-140 | hub_can(hr,v/e/a); account creation hub_flag(users); Staff::mayTouch escalation guard (Staff.php:42) | ASSIGNABLE_PERMISSION | hr.e/a + users flag | owner_only |
| Employee↔account link escalation guard | form | Employee::booted saving() Employee.php:51-58; Staff::mayTouch Staff.php:42 | privileged target (owner / users flag) requires actor owner-or-users; last defense on raw user_id writes from generic form/import | SECURITY_INVARIANT | — | owner_only |
| Auto-close account on file status change | form | Staff::closeAccount Staff.php:225-251 | guards: not last active owner, not self, privileged account → notify not auto-stop | SECURITY_INVARIANT | — | owner_only |
| Payroll run generate/adjust/pay | button | PayrollController::act PayrollController.php:19 | hub_can(payroll,e); approve → hub_approver() (line 143); pay only checks status | ASSIGNABLE_PERMISSION | payroll.e + approve flag | sensitive |
| Employee financial custody wallet | route | custody-wallet.* EmployeeCustodyController.php:87-370 | hub_can(custody,v/e); employeeScoped via hub_scope(hr) (line 55); moves() explicit company isolation (line 63); charge needs banks:e | ASSIGNABLE_PERMISSION | custody.v/e + banks.e | sensitive |
| Recruit → hire (candidate to employee) | button | recruit.hire HireController.php:16 | hub_can(recruit,e) AND hub_can(hr,a); account creation hub_flag(users) (line 61) | ASSIGNABLE_PERMISSION | recruit.e + hr.a + users flag | sensitive |
| HR REST API index/show | api | api/v1/hr V1Controller.php:55,83; shape() 539 | resolveApi(hr,v) + token scope tokenAllows; shape() unsets hub_field_mode=hide and sec fields (line 552) | ASSIGNABLE_PERMISSION | hr.v + api token scope | sensitive |
| HR Mobile resource API | api | api/mobile/v1/hr MobileResourceController.php:48,66 | inherits apiIndex/apiShow (shape field_mode) + hub_scope + mobile context narrowing | ASSIGNABLE_PERMISSION | hr.v | sensitive |
| Salary + allowances fields | field | config/hub.php:5504(salary),5510(allow) | hub_field_mode(hr,salary/allow) — DEFAULT '' visible (helpers.php:1721) | ASSIGNABLE_PERMISSION | field_rules hr.salary/hr.allow (opt-out) | sensitive |
| IBAN + civil ID fields | field | config/hub.php:5422(civilId),5423(iban) | hub_field_mode(hr,iban/civilId) — DEFAULT visible | ASSIGNABLE_PERMISSION | field_rules hr.iban/hr.civilId (opt-out) | sensitive |
| Iqama + passport (id docs + expiry) | field | config/hub.php:5516,5522,5529,5535 | hub_field_mode(hr,iqama/iqamaExp/passport/passExp) — DEFAULT visible; team dir gates iqamaExp/passExp | ASSIGNABLE_PERMISSION | field_rules (opt-out) | sensitive |
| Contact/personal fields (phone,email,nationality,emergency) | field | config/hub.php:5419-5424 | hub_field_mode(hr,*) — DEFAULT visible; also searchable via ?q= unless hidden (Searchable.php) | ASSIGNABLE_PERMISSION | field_rules (opt-out) | sensitive |
| Performance rating (perf) | field | config/hub.php:5560; PortalController.php:212 | hub_field_mode(hr,perf)!=hide for workProfile rating | ASSIGNABLE_PERMISSION | field_rules hr.perf | sensitive |
| Leaves module | route | m/leaves; config 5605; bundle PortalController.php:307 | hub_can(leaves,v) | ASSIGNABLE_PERMISSION | leaves.v/a/e/d | normal |
| Attendance module | route | m/attend; config 7177; bundle PortalController.php:314 | hub_can(attend,v) | ASSIGNABLE_PERMISSION | attend.v | sensitive |
| HR disciplinary / records (hrlog) | route | m/hrlog; config 7502; bundle PortalController.php:327 | hub_can(hrlog,v) | ASSIGNABLE_PERMISSION | hrlog.v | sensitive |
| Work profile: activity/timeline/security | widget | PortalController::workProfile PortalController.php:201-230 | work stats: hr:v + per-unit hub_can; activity/timeline: hub_monitor(); security(Risk): hub_is_owner ONLY | ASSIGNABLE_PERMISSION | hr.v + monitor flag + owner | sensitive |
| Owner account creation for employee | form | Staff::makeAccountResult Staff.php:138-190 | hub_flag(users); granting owner role requires hub_is_owner (line 145) | OWNER_ONLY | users flag; owner for owner-role | owner_only |

### الحضور/التقارير (18)

| السطح | النوع | المرجع | التوثيق الحالي | التصنيف | المفتاح | الحساسية |
|---|---|---|---|---|---|---|
| مركز التقارير اليومية — نظرة اليوم | route | reports.index (GET reports/daily) | guardTeam(): !hub_is_client && hub_can(hr,v); rows via hub_company_scope(hub_scope(Employee,'hr')) | ASSIGNABLE_PERMISSION | hr.v (proposed reports.view_team) | sensitive |
| تفصيل يوم موظف (محتوى التقرير الكامل) | route | reports.day (GET reports/daily/day) ReportsController.php:110 | guardTeam hr.v; emp existence re-checked via hub_company_scope(hub_scope(Employee,'hr'))->exists() -> 404 | ASSIGNABLE_PERMISSION | hr.v (proposed reports.view_team) | sensitive |
| مركز المراجعة — طابور التقارير | route | reports.review (GET reports/review) ReportsController.php:133 | guardInternal + ReportReview::canReviewAny (owner\|\|hr.v\|\|updates.e); list scoped by reviewableUpdates() (company employees for hr.v, project scope for updates.e) | ASSIGNABLE_PERMISSION | hr.v \|\| updates.e (proposed reports.review) | sensitive |
| إجراء المراجعة (قبول/تنقيح/إعادة فتح) | route | reports.review.act (POST reports/review/{id}) ReportsController.php:161 | guardInternal + ReportReview::canReview($w): owner\|\|hr.e (UNSCOPED)\|\|(updates.e && project in hub_scope) | ASSIGNABLE_PERMISSION | hr.e \|\| updates.e+project (proposed reports.review) | sensitive |
| ختم الأثر الفعّال للحضور (finalize) | route | reports.finalize (POST reports/compliance/{id}/finalize) ReportsController.php:192 | guardInternal + hub_can(hr,e)\|\|owner; row check = hub_company_scope(Attendance,'attend')->whereKey->exists() | ASSIGNABLE_PERMISSION | hr.e (proposed attendance.finalize) | owner_only |
| تقريري اليوم (الذاتي) | route | reports.mine (GET my/report) ReportsController.php:213 | guardInternal + Workday::emp(auth) (own created_by only) | SELF_SERVICE | — | normal |
| شبكة الحضور الشهرية (المحاسب) | route | reports.monthly (GET reports/monthly) ReportsController.php:241 | guardMonthly(): hub_can(attend,v)\|\|hub_can(hr,v); emps via monthlyEmployees() = whereIn(company_id, hub_company_ids) | ASSIGNABLE_PERMISSION | attend.v \|\| hr.v (proposed attendance.monthly.view) | sensitive |
| سجل موظف واحد شهرياً | route | reports.monthly.employee ReportsController.php:256 | guardMonthly; emp re-checked via monthlyEmployees()->whereKey->exists() -> 404 | ASSIGNABLE_PERMISSION | attend.v \|\| hr.v | sensitive |
| تصدير الحضور الشهري CSV | export | reports.monthly.export ReportsController.php:267 | guardMonthly (attend.v\|\|hr.v) — same op as view; CSV injection neutralized | ASSIGNABLE_PERMISSION | attend.v \|\| hr.v (proposed attendance.export) | sensitive |
| تسجيل حضور | form | workday.in (POST workday/check-in) WorkdayController.php:16 | auth only; writes own emp row via Workday::emp; project_id/client_id validated existence-only (not scope) | SELF_SERVICE | — (own record) | normal |
| تسجيل انصراف | button | workday.out (POST workday/check-out) WorkdayController.php:30 | auth only; own emp row | SELF_SERVICE | — (own record) | normal |
| فريقي اليوم (مراجعة المدير) | route | workforce.team (GET workforce) WorkdayController.php:38 | hub_can(hr,v); teamCalc scoped by hub_company_scope(hub_scope(Employee,'hr')) | ASSIGNABLE_PERMISSION | hr.v | sensitive |
| نظرة القوى العاملة (تنفيذ) | route | workforce.overview (GET workforce/overview) WorkforceController.php:25 | hub_monitor() (monitor flag) + hub_org_analytics_guard() (blocks any company/client isolated account) | ASSIGNABLE_PERMISSION | monitor flag | sensitive |
| API نظرة اليوم للفريق | api | api.v1.reports.daily (ReportsApiController@teamDaily) :62 | ApiAuth (Bearer->user+role) + !hub_is_client + hub_can(hr,v); emps via hub_company_scope(hub_scope(Employee,'hr')) | ASSIGNABLE_PERMISSION | hr.v | sensitive |
| API تقريري اليوم (self) | api | api.v1.reports.my_daily (ReportsApiController@myDaily) :22 | ApiAuth + !hub_is_client + Workday::emp (own created_by) | SELF_SERVICE | — (self) | normal |
| API حالة امتثال اليوم (self) | api | api.v1.reports.today (ReportsApiController@todayCompliance) :51 | ApiAuth + !hub_is_client + Workday::emp (self) | SELF_SERVICE | — (self) | normal |
| جوال — تقرير اليوم (self) | api | mobile.work.today / mobile.work.daily_report (MobileReportsController@today) | mobile.session\|mobile.portal\|mobile.context + !hub_is_client + Workday::emp (self) | CLIENT_PORTAL_GOVERNED | — (self, client boundary via mobile.portal) | normal |
| تقرير التمويل (خارج النطاق) | route | reports.finance (Web\ReportController@finance) | not audited here (finance domain) | ASSIGNABLE_PERMISSION | — | sensitive |

### الماليّة (26)

| السطح | النوع | المرجع | التوثيق الحالي | التصنيف | المفتاح | الحساسية |
|---|---|---|---|---|---|---|
| تسجيل دفعة مالية (Invoice payment) | button | fin.act / app/Http/Controllers/Web/FinController.php:18-112 | hub_can(fin,e) + hub_scope(fin); bank movement re-gated hub_can(banks,e)+scope+currency inside txn (FinController.php:73-80) | ASSIGNABLE_PERMISSION | fin.e (+banks.e) | sensitive |
| سطور القيد وترحيله (Journal lines / post) | button | entries.line/entries.post/entries.line.drop / app/Http/Controllers/Web/EntryController.php:17-121 | hub_can(entries,e) + hub_scope(entries); acc/cc re-scoped via hub_scope(accounts2)/hub_scope(costc) (EntryController.php:41-48) | ASSIGNABLE_PERMISSION | entries.e | sensitive |
| مسار عرض السعر (Quote lifecycle act) | button | quotes.act / app/Http/Controllers/Web/QuoteController.php:74-97 | hub_can(quotes,e)+scope; toContract⇒contracts.a; toInvoice/msInvoice⇒fin.a; toProject⇒projects.a+engagements.a | ASSIGNABLE_PERMISSION | quotes.e (+fin.a/contracts.a/projects.a) | sensitive |
| سكّ فاتورة معلم دفع (Milestone invoice) | button | quotes.act do=ms.invoice / app/Http/Controllers/Web/QuoteController.php:165-264 | hub_can(fin,a); milestone read via scoped quote | ASSIGNABLE_PERMISSION | fin.a | sensitive |
| مستند/PDF/مقارنة العرض (Quote doc/pdf/diff) | download | quotes.doc/pdf/diff / app/Http/Controllers/Web/QuoteController.php:26-71,643-679 | hub_can(quotes,v)+scope; internal cost/margin gated by hub_field_mode(quotes,cost)==hide (doc:39-46, diff:666-669) | ASSIGNABLE_PERMISSION | quotes.v (+field_rule quotes.cost) | sensitive |
| بنّاء بنود ومراحل العرض (Quote builder lines) | form | quotes.line.* / app/Http/Controllers/Web/QuoteBuilderController.php:20-168 | hub_can(quotes,e)+scope; frozen once مقبول/محوّل | ASSIGNABLE_PERMISSION | quotes.e | sensitive |
| QuoteFlow التطبيق الجانبي | route | quoteflow/save/unlock / app/Http/Controllers/Web/QuoteFlowController.php:36-129 | hub_is_owner() only + second password (quoteflow.pass) + rate-limit | OWNER_ONLY | — | owner_only |
| مسار الشراء واعتماده (Purchase flow/approve) | button | purchases.act / app/Http/Controllers/Web/PurchaseController.php:67-112 | hub_can(purchases,e)+scope; approve⇒hub_approver()=global 'approve' flag (PurchaseController.php:108) | ASSIGNABLE_PERMISSION | purchases.e + flag:approve | sensitive |
| أمر الشراء PDF | download | purchases.doc / app/Http/Controllers/Web/PurchaseController.php:28-52 | hub_can(purchases,v)+scope; hub_field_mode(purchases,amount\|items) hide honored | ASSIGNABLE_PERMISSION | purchases.v | sensitive |
| تقييم الموردين (Supplier scores) | widget | supplierscores / app/Http/Controllers/Web/PurchaseController.php:19-25 | hub_can(suppliers,v) + hub_org_analytics_guard() | ASSIGNABLE_PERMISSION | suppliers.v | normal |
| مسيّر الرواتب (Payroll generate/adjust/approve/pay) | button | payroll.act / app/Http/Controllers/Web/PayrollController.php:19-182 | hub_can(payroll,e)+scope; approve⇒hub_approver()=global 'approve' flag (PayrollController.php:143) | ASSIGNABLE_PERMISSION | payroll.e + flag:approve | owner_only |
| ملحق/تجديد العقد (Contract amend/renew) | button | contract.amend/renew / app/Http/Controllers/Web/ContractActionsController.php:27-78 | hub_can(contracts,a)+scope; renew also hub_can(contracts,e) (writes original status) | ASSIGNABLE_PERMISSION | contracts.a (+contracts.e) | sensitive |
| مكتبة بنود العقد (Clause library) | form | esign.clause.store/destroy / app/Http/Controllers/Web/ContractActionsController.php:116-145 | hub_can(contracts,e); stored in Settings | ASSIGNABLE_PERMISSION | contracts.e | normal |
| تطبيق أمر التغيير (Change-order apply) | button | changeorders.apply / app/Http/Controllers/Web/ChangeOrderController.php:18-63 | hub_can(changeorders,e) AND hub_can(projects,e) (mutates project rev_exp/budget) + scope | ASSIGNABLE_PERMISSION | changeorders.e + projects.e | sensitive |
| لوحة التكاليف والربحية (Project P&L dashboard) | route | costs.index/costs.project / app/Http/Controllers/Web/CostController.php:12-72 | hub_monitor() = owner OR global 'monitor' flag; scoped via hub_scope(projects) | ASSIGNABLE_PERMISSION | flag:monitor | sensitive |
| تكلفة الخدمات + MRR (Service costs) | widget | servicecosts / app/Http/Controllers/Web/CostController.php:19-29 | hub_monitor() + hub_org_analytics_guard() | ASSIGNABLE_PERMISSION | flag:monitor | sensitive |
| لوحة المبيعات (Sales/CPQ dashboard) | route | sales.dashboard / app/Http/Controllers/Web/SalesController.php:14-20 | hub_is_owner() OR hub_monitor(); margin/cost columns via hub_field_mode(quotes,cost) | ASSIGNABLE_PERMISSION | flag:monitor (+field_rule quotes.cost) | sensitive |
| مركز التسعير (Pricing center) | route | pricing / app/Http/Controllers/Web/PricingController.php:10-15 | hub_can(plans,v) | ASSIGNABLE_PERMISSION | plans.v | normal |
| التقرير المالي (Finance report) | route | reports.finance / app/Http/Controllers/Web/ReportController.php:23-25 | hub_can(fin,v) + hub_scope(fin) + hub_company_scope | ASSIGNABLE_PERMISSION | fin.v | sensitive |
| تصدير CSV لوحدات المالية (Finance module export) | export | m.export + m.bulk do=export / app/Http/Controllers/Web/ModuleController.php:762-814,881-891 | hub_can(module,v) + hub_exporter()=global 'exp' flag (exportBelt:796); freeze switch + optional step-up | ASSIGNABLE_PERMISSION | module.v + flag:exp | sensitive |
| قوائم/صفحات/كانبان وحدات المالية (fin/entries/quotes/…) | route | m.index/show/board / app/Http/Controllers/Web/ModuleController.php:177-228,342-387,548-610 | hub_can(module,v)+hub_scope+company/client scope; field_mode hide applied on columns, board cards, filters, sort | ASSIGNABLE_PERMISSION | module.v (+field_rules) | sensitive |
| REST API لوحدات المالية | api | api/v1/{module} / app/Http/Controllers/Api/V1Controller.php:55-222,539-579 | resolveApi: client-boundary (PortalGuard::MODULE_ALLOW), hub_can(module,op), token scopes; shape() drops field_mode-hidden + sec DECLARED fields | ASSIGNABLE_PERMISSION | module.op + token scope | sensitive |
| عهدة الموظف المالية (Custody wallet) | button | custody-wallet.* / app/Http/Controllers/Web/EmployeeCustodyController.php:46-368 | hub_can(custody,v/e/approve) named ops + hub_scope(hr)+company isolation; expense/correct/reverse⇒custody.approve + hub_require_stepup | ASSIGNABLE_PERMISSION | custody.v/e/approve | sensitive |
| فواتير بوابة العميل (Client portal invoices) | route | portal.invoices/portal.invoice / app/Http/Controllers/Web/ClientPortalController.php:114-130 + app/Support/ClientPortalData.php:134-154 | hub_is_client gate; whereIn('client_id', hub_client_ids() ?? []) fail-closed; select() hand-picked client-safe columns (no cost/budget/internal) | CLIENT_PORTAL_GOVERNED | — | sensitive |
| فواتير بوابة العميل عبر الجوال (Mobile portal invoices) | api | mobile.portal.invoices.index/show / MobileClientPortalController → ClientPortalData | mobile.session+mobile.portal+mobile.context middleware; same ClientPortalData isolation | CLIENT_PORTAL_GOVERNED | — | sensitive |
| البنوك والصناديق (Bank accounts module) | field | banks module / config/hub.php:5705-5771 | hub_can(banks,v/e)+scope; iban(type text) & balance are ordinary fields visible with banks.v | ASSIGNABLE_PERMISSION | banks.v | sensitive |

### الأصول/النقاط (25)

| السطح | النوع | المرجع | التوثيق الحالي | التصنيف | المفتاح | الحساسية |
|---|---|---|---|---|---|---|
| عرض كتالوج/صنف العهد (custody.catalog/category/byCode/label/spec) | route | CustodyController.php:48,65,129,108,138 | hub_can(assets,v) + Custody::scoped() (hub_scope+hub_company_scope) | ASSIGNABLE_PERMISSION | assets.v | normal |
| تسليم/استرداد عهدة (custody.handover/recover) | form | CustodyController.php:175,201 | hub_can(assets,e) via asset($id,'e') | ASSIGNABLE_PERMISSION | assets.e | sensitive |
| تصريح نقل/خروج عهدة (custody.permit/return/cancel) | form | CustodyController.php:294,361,377 | hub_can(assets,e); permitOf scoped to asset_id | ASSIGNABLE_PERMISSION | assets.e | sensitive |
| تغيير حالة الأصل (custody.status) | button | CustodyController.php:228 | hub_can(assets,e); Custody::transition legal-transition map | ASSIGNABLE_PERMISSION | assets.e | normal |
| إسناد الأصل لمحطة (custody.station) | form | CustodyController.php:259 | hub_can(assets,e) + station re-scoped via hub_company_scope(hub_scope Station) | ASSIGNABLE_PERMISSION | assets.e | normal |
| حفظ المواصفات الداخلية (custody.specs) | form | CustodyController.php:160 | hub_can(assets,e) | ASSIGNABLE_PERMISSION | assets.e | sensitive |
| عهدة الموظف المالية (custody-wallet advance/charge/expense/…/reverse) | route | EmployeeCustodyController.php:140-368 | hub_can(custody,v/e/approve) + hub_scope(hr) + explicit company whereIn; correct/reverse behind hub_require_stepup; charge also needs banks:e+scope; client⇒404 (not in PortalGuard) | ASSIGNABLE_PERMISSION | custody.v/e/approve | sensitive |
| إسناد/إخلاء محطة (stations.assign/vacate/byCode) | form | StationController.php:63,110,49 | hub_can(stations,v/e) + hub_scope(stations) findOrFail + locked-row txn; hub_is_client(user) blocked | ASSIGNABLE_PERMISSION | stations.v/e | normal |
| تخصيص الأصول للمشاريع (web+api assign/end/list) | api | AssetProjectController.php:31-117 · AssetProjectApiController.php:27-109 | hub_is_client⇒404; hub_can(assets,e)&&hub_can(projects,v); both asset & project hub_scope'd (404 out-of-scope) | ASSIGNABLE_PERMISSION | assets.e + projects.v | normal |
| جلسات الجرد (inventory.center/show/freeze/scan) | route | InventoryController.php:80,92,144,210 | hub_can(assets,v/e) + explicit hub_company_ids whereIn; Custody::scoped snapshot; Identity::resolve scoped scan | ASSIGNABLE_PERMISSION | assets.v/e | normal |
| مصالحة/إغلاق الجرد (inventory.reconcile/close) | button | InventoryController.php:257,334 | hub_can(assets,e) + hub_require_stepup() | ASSIGNABLE_PERMISSION | assets.e | sensitive |
| ترحيل حركة مخزون (stockmv.act confirm/cancel) | button | StockController.php:26 | hub_can(stockmv,e) + hub_block_if_queued(stockmv) + hub_scope(stockmv) + locked-row txn | ASSIGNABLE_PERMISSION | stockmv.e | normal |
| مركز النقاط الطرفية (endpoints.index/show) | route | EndpointCentreController.php:32,39,76 | hub_is_client⇒404; hub_is_owner()\|\|hub_monitor(); company whereIn (404 cross-company) | ASSIGNABLE_PERMISSION | flag:monitor | sensitive |
| إصدار أمر لجهاز طرفي (endpoints.command / issue) | button | EndpointProtocolController.php:427-500 | hub_is_client⇒404; owner\|\|monitor; company whereIn; device.status==active; isolate/lock⇒hub_require_stepup; refresh/apply_policy⇒NO stepup | ASSIGNABLE_PERMISSION | flag:monitor (+stepup for isolate/lock) | owner_only |
| سك رمز تسجيل جهاز (enroll.mint) | button | EndpointEnrollController.php:33-79 | hub_is_client⇒404; owner\|\|monitor; hub_require_stepup; asset company-scoped + eligibility fail-closed | ASSIGNABLE_PERMISSION | flag:monitor (+stepup) | owner_only |
| تسجيل الجهاز (enroll.device) | api | EndpointEnrollController.php:83-187 | public + one-time enrollment token (findByPlain, atomic consume); rejects PRIVATE KEY material; P-256 PEM only; assignment from token not payload | SYSTEM_INTERNAL | — | sensitive |
| بروتوكول الجهاز الموقّع (heartbeat/event/commands.pull/result) | api | EndpointProtocolController.php:95,181,238,276 | endpoint.signature middleware (Es256 replay/nonce/ts); device from signed identity; privacyGate rejects surveillance fields | SYSTEM_INTERNAL | — | sensitive |
| بيان/تنزيل الوكيل للجهاز (endpoint.agent.manifest/download) | download | EndpointProtocolController.php:356,399 | endpoint.signature; os scoped to device.os; draft/withdrawn excluded; download_log row | SYSTEM_INTERNAL | — | normal |
| إدارة تكامل MDM (endpoints.mdm index/store/toggle/sync/health/delete) | route | EndpointMdmController.php:25-143 | hub_is_client⇒404; hub_is_owner() ONLY; writes behind hub_require_stepup | OWNER_ONLY | owner | owner_only |
| مركز إصدارات الوكيل (endpoints.releases index/store/publish/delete) | route | EndpointReleaseController.php:38-192 | hub_is_client⇒404; hub_is_owner() ONLY; hub_require_stepup; sha256/size server-computed; signing_status forced 'unsigned-dev' | OWNER_ONLY | owner | owner_only |
| تنزيل إصدار الوكيل (endpoints.releases.download) | download | EndpointReleaseController.php:199-219 | ONLY hub_is_client⇒404 — any authenticated internal user (no endpoints perm, no monitor) | PUBLIC_AUTHENTICATED_BASE | — | normal |
| CRUD عام للنقاط الطرفية (/m/endpoints index/show/update/destroy/export/bulk) | route | config/hub.php:5044 · ModuleController.php:15-19 | hub_can(endpoints,v/a/e/d) + hub_scope; NO monitor check; endpoints in RoleController 'أخرى' group ⇒ grantable | ASSIGNABLE_PERMISSION | endpoints.v/a/e/d (matrix) | sensitive |
| مركز الأصول الرقمية (digital.assets) | route | DigitalAssetsController.php:17 | hub_monitor() + hub_org_analytics_guard() | ASSIGNABLE_PERMISSION | flag:monitor | sensitive |
| دورة حياة الأصول (assets.life) | route | AssetLifeController.php:10 | hub_can(assets,v) | ASSIGNABLE_PERMISSION | assets.v | normal |
| أجهزة جلستي/الأمن (my.security.devices trust/revoke) | button | routes web MySecurityController | web\|auth (own session devices) | SELF_SERVICE | — | normal |

### الملفّات (22)

| السطح | النوع | المرجع | التوثيق الحالي | التصنيف | المفتاح | الحساسية |
|---|---|---|---|---|---|---|
| att.store — upload attachment(s) to any record | form | att.store (AttachmentController.php:35, AttachmentService.php:107) | guardRecord(module,record_id,'v') — module VIEW only | ASSIGNABLE_PERMISSION | module 'v' (proposed: documents.attach) | sensitive |
| att.dl — download attachment bytes | download | att.dl (AttachmentController.php:101 -> AttachmentService::download:152) | guardRecord v + av 'infected' 423 + download_log + classified audit; Content-Disposition: attachment | ASSIGNABLE_PERMISSION | module 'v' (proposed: documents.download) | sensitive |
| att.view — inline preview/stream attachment | download | att.view (AttachmentController.php:223 -> AttachmentService::stream:183) | guardRecord v + av gate + INLINE_MIMES allowlist (415 else) + nosniff + CSP default-src 'none' | ASSIGNABLE_PERMISSION | module 'v' | sensitive |
| att.zip — bulk download all record attachments | download | att.zip (AttachmentController.php:118) | guardRecord v; excludes infected; logs each file + classified audit | ASSIGNABLE_PERMISSION | module 'v' | sensitive |
| att.move / att.destroy — reorder / delete attachment | button | AttachmentController.php:66,229 | guardRecord v AND (uploaded_by==self \|\| hub_is_owner \|\| hub_can(module,'e')) | SELF_SERVICE | module 'e' or self | normal |
| file.show — serve any hub/ file by path | download | file.show (FileController.php:22) | mayRead(path): per-record hub_scope over module file/img cols + attachments(hub_read) + dm(from/to) + comments(record scope); infected 423; nosniff; inline allowlist | ASSIGNABLE_PERMISSION | per-record module 'v' | sensitive |
| upload.chunk / upload.finish — chunked upload staging | upload | UploadChunkController.php:19,41 | auth + ChunkedUpload::validToken; chunks stored in per-user dir; NO record binding until att.store re-guards | PUBLIC_AUTHENTICATED_BASE | — | normal |
| inboxdocs.index — inbox list + counts | route | inboxdocs.index (InboxDocController.php:37) | gate: hub_can('inboxdocs'\|'files','v'); scoped() company_id IN hub_company_ids OR NULL | ASSIGNABLE_PERMISSION | inboxdocs 'v' | sensitive |
| inboxdocs.store / classify | form | InboxDocController.php:66,89 | gate 'a'/'e'; classify uses scoped()->findOrFail + company-membership check + hub_read for record resolution | ASSIGNABLE_PERMISSION | inboxdocs 'a'/'e' | sensitive |
| inboxdocs.destroy | button | InboxDocController.php:161 | hub_is_owner() | OWNER_ONLY | owner | owner_only |
| dataroom.index/store/revoke — external share links | route | DataRoomController.php:27,55,92 | gate(): hub_secrets() (secrets flag or owner) | ASSIGNABLE_PERMISSION | flag:secrets (proposed: documents.share) | owner_only |
| share.show / share.unlock / share.file — public share consumption | download | DataRoomController.php:109,127,139 | token (Str::random 48) + optional password session; alive() checks revoked/expired 410; no_download -> watermark fail-closed | CLIENT_PORTAL_GOVERNED | token capability | sensitive |
| esign internal doc/pdf/certificate | download | EsignController.php:580,635,600 | gate(): hub_can('contracts','v') + guardRequest(filterVisible scope) | ASSIGNABLE_PERMISSION | contracts 'v' | sensitive |
| esign public sign.doc/sign.pdf/sign.cert | download | EsignController.php:870,654,618 | resolveToken + session('sign.ok.{token}') set only after unlock (password or emailed OTP) | CLIENT_PORTAL_GOVERNED | token + unlock session | sensitive |
| sign.verify / sign.verify.doc — public verification / QR open | route | EsignController.php:1050,1089 | unauth; signed-only (status='وُقّع'); per-IP rate limit (10/min, 20/min) | PUBLIC_AUTHENTICATED_BASE | — | normal |
| media.center — media/events center | route | media.center (MediaCenterController.php:12) | hub_can('media','v') OR hub_can('events','v') | ASSIGNABLE_PERMISSION | media/events 'v' | normal |
| mobile.files.download / stream | api | MobileFileController.php:257,277 | guardClientModule (client account MODULE_ALLOW 404) + AttachmentService::download/stream (guardRecord v) | ASSIGNABLE_PERMISSION | module 'v' | sensitive |
| mobile.files.upload_session/chunk/complete/attach | api | MobileFileController.php:60,113,154,218 | guardClientModule + guardRecord v at session-start AND at complete; idempotency by mobile_session | ASSIGNABLE_PERMISSION | module 'v' | sensitive |
| mobile.portal.documents.index/show — client app shared docs | api | MobileClientPortalController.php:109,120 | MobilePortalGuard (client-only) + Document::visibleToClient(hub_client_ids) fail-closed | CLIENT_PORTAL_GOVERNED | client boundary | sensitive |
| portal.documents / portal.document (web client shell) | route | ClientPortalController.php:94,103 | gate hub_is_client + ClientPortalData::documentDetail(visibleToClient)->findOrFail | CLIENT_PORTAL_GOVERNED | client boundary | sensitive |
| custody.permit.doc — printable custody permit | download | CustodyController.php:340 | asset($id): hub_can('assets','v') + hub_scope; permitOf validates permit belongs to asset | ASSIGNABLE_PERMISSION | assets 'v' | normal |
| endpoints.releases.download / endpoint.agent.download | download | EndpointReleaseController.php:218 / EndpointProtocolController.php:422 | guardOwner (hub_is_owner) / endpoint.signature HMAC middleware | OWNER_ONLY | owner / machine signature | owner_only |

### الإدارة/الأمن (40)

| السطح | النوع | المرجع | التوثيق الحالي | التصنيف | المفتاح | الحساسية |
|---|---|---|---|---|---|---|
| System settings edit/update/preview/restore | form | SettingController@edit/update:84 | hub_is_owner() (gate) + step-up on high-risk restore/import | OWNER_ONLY | — (owner hard governance) | owner_only |
| Settings export (whole-tenant config) | export | SettingController@export:470 | hub_is_owner() + abort on security.freeze_exports + DATA_EXPORT audit | OWNER_ONLY | — | owner_only |
| Users list/create/update/delete/restore | route | UserController@*:16 | hub_flag(users) (gate) + guardEscalation() owner-only for owner-role grant/target | ASSIGNABLE_PERMISSION | users flag | sensitive |
| Admin password reset / 2FA disable of another account | button | UserController@update:233 / @twofaOff:255 | hub_flag(users) + Staff::mayTouch (privilege ceiling) + step-up; revokes sessions | ASSIGNABLE_PERMISSION | users flag | sensitive |
| Roles create/edit/clone/delete (permission matrix + flags editor) | route | RoleController@*:33 | hub_is_owner() | OWNER_ONLY | — (owner hard governance) | owner_only |
| Access diagnostics /admin/access + role preview | route | AccessController@index/role:22 | hub_is_owner() | OWNER_ONLY | — | owner_only |
| Audit log index/show | route | AuditController@index/show:40,125 | hub_flag(audit) + Audit::scopedQuery (company/client scoping); out-of-scope show => 404 | ASSIGNABLE_PERMISSION | audit flag | sensitive |
| Audit coverage analyzer | route | AuditController@coverage:241 | hub_is_owner() | OWNER_ONLY | — | owner_only |
| Feature registry toggle (capability enable/disable) | button | FeatureController@toggle:113 | hub_is_owner() + step-up when Settings::isHighRisk; setEnabled refuses invariant/illegal transition | OWNER_ONLY | — | owner_only |
| System-invariant capabilities (never toggleable) | widget | FeatureRegistry::setEnabled:391 | security_class===invariant => throw; read-only in UI | SECURITY_INVARIANT | — | owner_only |
| Data room admin: create/revoke external share links | form | DataRoomController@store/revoke:21,55 | hub_secrets() (secrets OR owner) | ASSIGNABLE_PERMISSION | secrets flag | sensitive |
| Data room public viewer/unlock/file stream | download | DataRoomController@show/file:109,139 | unguessable token + optional password + expiry/revoke + watermark; inline-mime allowlist | CLIENT_PORTAL_GOVERNED | — | sensitive |
| Integrations center + guide | route | IntegrationController@index/guide:17 | hub_is_owner() | OWNER_ONLY | — | owner_only |
| n8n connect/save/test | form | N8nController@*:19 | hub_is_owner() + hub_outbound_ok SSRF guard on url; key encrypted | OWNER_ONLY | — | owner_only |
| Outbound webhooks CRUD/test/resend | route | WebhookController@*:18 | hub_is_owner() + SSRF guard; signed secret; audited | OWNER_ONLY | — | owner_only |
| Inbound hook admin (create/toggle/delete) | route | InboundHookController@index/store/*:26 | hub_is_owner() | OWNER_ONLY | — | owner_only |
| Inbound hook receive POST /hook/{token} | api | InboundHookController@receive:31 | unguessable token + enabled + optional HMAC (ts-bound, 5m replay window) + replay dedup + size cap + throttle:120,1 | SYSTEM_INTERNAL | — | normal |
| Security center dashboard | route | SecurityController@index:22 | hub_is_owner() | OWNER_ONLY | — | owner_only |
| Emergency freeze (exports/tokens) & lockdown | button | SecurityController@freeze/lockdown:241,1043 | hub_is_owner() + step-up to LIFT (enable is frictionless) | OWNER_ONLY | — | owner_only |
| Session/user revoke (admin) | button | SecurityController@revokeSession/revokeUser/revokeOthers:275,301,325 | hub_is_owner() (+ step-up on user/others); rotates remember-token | OWNER_ONLY | — | owner_only |
| Security findings/identity/sessions/devices/ips/event (read) | route | SecurityController@findingsReadGate:350 | hub_is_owner() \|\| hub_monitor() + PII masking (maskPII) + company scoping (visibleUserIds) + IP masking for non-owner | ASSIGNABLE_PERMISSION | monitor flag (read) | sensitive |
| API tokens center + admin revoke | route | SecurityController@tokens/revokeToken:913,962 | hub_is_owner() + credential step-up on revoke; token value/hash never rendered | OWNER_ONLY | — | owner_only |
| Vault secrets health center | route | SecurityController@secrets:989 | hub_is_owner(); secret_cipher never selected | OWNER_ONLY | — | owner_only |
| IP block/allow rules (create/extend/revoke) | form | SecurityController@blocksGate/blockStore:1070,1190 | hub_is_owner() (404 for others) + step-up + server-side owner-lockout protection | OWNER_ONLY | — | owner_only |
| Ops center + migrate/backup/maintenance/clear-cache/outbox-retry | route | OpsController@*:17 | hub_is_owner() + hub_require_ops_stepup on high-impact (migrate/maintenance/clearcache/outbox) | OWNER_ONLY | — | owner_only |
| Public health probe /healthz | api | OpsController@health:749 | none (throttle:30,1); Health::publicView redacts details for unknown | SYSTEM_INTERNAL | — | normal |
| Error center index/show/status/task + log search | route | ErrorCenterController@*:15 | hub_is_owner() (+ hub_can tasks 'a' for toTask); file snippet path-jailed to base_path excluding .env | OWNER_ONLY | — | owner_only |
| Browser error beacon POST /jslog | api | ErrorCenterController@jslog:410 | auth only, per-user daily cap + throttle:20,1 | SELF_SERVICE | — | normal |
| Control plane (six governance cards) | route | ControlController@gate:75 | hub_is_owner() \|\| hub_monitor(); per-card sub-gates (ops/errors owner-only, audit=audit flag, quality/execution need org-analytics guard) | ASSIGNABLE_PERMISSION | monitor flag + per-card | sensitive |
| Self API tokens create/rotate/revoke | form | ProfileController@tokenStore/Rotate/Revoke:25,83,116 | own user (where user_id=auth id) + freeze_tokens (423) + credential step-up on mint/rotate | SELF_SERVICE | self | sensitive |
| Messaging center (mail/telegram/test/retry) | form | MessagingController@*:26 | hub_is_owner() | OWNER_ONLY | — | owner_only |
| Odoo connections admin + defaults | route | OdooConnectionController@*:27 | hub_is_owner() + SSRF guard; keys encrypted | OWNER_ONLY | — | owner_only |
| Odoo record link/unlink/refresh | button | OdooController@target:52 | hub_can(module,'e') + hub_scope on record | ASSIGNABLE_PERMISSION | v/a/e/d (module edit) | normal |
| My security (own sessions/devices) | route | MySecurityController@*:19 | own user (where user_id=auth id) | SELF_SERVICE | self | normal |
| System map (navigation discovery) | route | SystemMapController@index:21 | auth only; visibility scoped inside InformationArchitecture (link !=access; destinations keep own gates) | PUBLIC_AUTHENTICATED_BASE | — | normal |
| Request trace /system/trace/{rid} | route | SystemTraceController@show:21 | hub_is_owner() \|\| hub_flag(audit) | ASSIGNABLE_PERMISSION | audit flag | sensitive |
| Remediation task creation | button | RemediationController@store:27 | hub_monitor() (owner\|\|monitor); each source scoped (hub_scope) + live-condition check; quality source owner-only | ASSIGNABLE_PERMISSION | monitor flag | normal |
| MDM enrollment-token mint POST /endpoints/enroll-token | api | EndpointEnrollController@mint:37 | client=>404; hub_is_owner() \|\| hub_monitor(); asset company-scoped (404 out of scope) | ASSIGNABLE_PERMISSION | monitor flag | sensitive |
| Demo reset/off (seed/purge fake data) | button | routes/web.php demo.reset/off:769 | role.is_owner + hub_require_ops_stepup | OWNER_ONLY | — | owner_only |
| Step-up identity verify | form | StepUpController@show/verify:16,36 | auth; verifies own password/passkey to mint fresh step-up window | SELF_SERVICE | — | sensitive |

### بوّابة العميل (20)

| السطح | النوع | المرجع | التوثيق الحالي | التصنيف | المفتاح | الحساسية |
|---|---|---|---|---|---|---|
| portal.home (client dashboard) | route | portal.home / ClientPortalController@home | PortalGuard(portal.*) + gate:hub_is_client; data via ClientPortalData (hub_client_ids()??[]) | CLIENT_PORTAL_GOVERNED | — | normal |
| portal.projects / portal.project | route | ClientPortalController@projects/@project | PortalGuard + ClientPortalData::projectRows/projectDetail (hub_scope+whereIn client_id, findOrFail=>404) | CLIENT_PORTAL_GOVERNED | projects.view (client audience) | normal |
| portal.invoices / portal.invoice | route | ClientPortalController@invoices/@invoice | PortalGuard + ClientPortalData::invoiceRows (whereIn kind CLIENT_INVOICE_KINDS + client_id) | CLIENT_PORTAL_GOVERNED | fin.view (sales/receipts only) | sensitive |
| portal.documents / portal.document | route | ClientPortalController@documents; Document::scopeVisibleToClient | PortalGuard + visibleToClient(audience in {client,both} AND client_id in ids); empty=>whereRaw('1=0') | CLIENT_PORTAL_GOVERNED | — | sensitive |
| portal.conversations / portal.conversation | route | ClientPortalController@conversation; ClientPortalData::conversationDetail | membership (ConversationMember) AND audience in {client,both}; internal messages filtered out | PARTICIPATION_GOVERNED | — | sensitive |
| portal.employee (employee 360) | route | PortalController@employee (PortalController.php:30-35) | PortalGuard whitelists portal.* => passes; controller = hub_can(hr,'v') + hub_scope ONLY (no hub_is_client block) | SECURITY_INVARIANT | hr.view | owner_only |
| portal.me (my portal) | route | PortalController@me | PortalGuard(portal.*); reads Employee where user_id=auth; bundle gated per-module by hub_can | SELF_SERVICE | — | normal |
| m.index/show/export/bulk for projects\|engagements\|fin | route | ModuleController via /m/{module}; PortalGuard::MODULE_ALLOW | PortalGuard MODULE_ALLOW (module-level only, NOT op) + resolve():hub_can + hub_scope | ASSIGNABLE_PERMISSION | projects/engagements/fin v/a/e/d | sensitive |
| m.store/update/destroy for allowed modules | route | ModuleController@store/update/destroy | MODULE_ALLOW + hub_can(a/e/d) + hub_scope | ASSIGNABLE_PERMISSION | projects/engagements/fin a/e/d | sensitive |
| mobile.context / mobile.bootstrap | api | MobileContextController@context/@bootstrap:59-60,99-100 -> contextDimension:281 | mobile.portal (NAME_ALLOW) + contextDimension(hub_scope). companies unscoped for clients | PUBLIC_AUTHENTICATED_BASE | — | sensitive |
| mobile.schema / mobile.schema.modules / mobile.navigation | api | MobileContextController@schema/@navigation; buildSchemaModules:332 | mobile.portal + client => clientModuleAllowed AND hub_can(v); nav=[]/clientIa() | CLIENT_PORTAL_GOVERNED | — | normal |
| mobile.search | search | MobileWorkController@search:186-189 | mobile.portal + client hits filtered by clientModuleAllowed after SearchController::results (hub_can+hub_scope) | CLIENT_PORTAL_GOVERNED | — | sensitive |
| mobile.files.download / stream | download | MobileFileController@download/stream:257-298 guardClientModule | mobile.portal + guardClientModule(clientModuleAllowed) + AttachmentService::download(guardRecord hub_can+hub_scope) | CLIENT_PORTAL_GOVERNED | — | sensitive |
| mobile.comments.* (read/write) | api | MobileCommController@comments/@postComment:180-198,262-298 | mobile.portal + isClient => clientModuleAllowed + CommentService::guardTarget + conversation audience check; internal forced false | PARTICIPATION_GOVERNED | — | sensitive |
| mobile.notifications.* / unread / target | notification | MobileCommController@notifications:62-155 | HubNotification::where('user_id',auth id) strictly | SELF_SERVICE | — | normal |
| mobile.dm.* / mobile.approvals.* / mobile.identity.resolve / mobile.tracking.* | api | MobilePortalGuard NAME_ALLOW (excluded) | mobile.portal => 404 for client (not whitelisted) | SECURITY_INVARIANT | — | sensitive |
| mobile.portal.* (MobileClientPortalController) | api | MobileClientPortalController@home/projects/invoices/... | mobile.portal (client-only; internal=>403) + ClientPortalData (fail-closed, findOrFail scoped) | CLIENT_PORTAL_GOVERNED | — | sensitive |
| clients.members.* (web) / mobile.clients.members.* | api | ClientMemberController@invite/setRole/revoke; MobileClientMembersController | web PortalGuard 404 for client + hub_can('clients','e') + hub_scope; owner-grant/revoke => hub_require_stepup | ASSIGNABLE_PERMISSION | clients.e | owner_only |
| client.switch (POST client-switch) | route | routes/web.php client.switch closure | web\|auth + PortalGuard => client 404 (not in NAME_ALLOW) | SELF_SERVICE | — | normal |
| sign/{token}/doc\|pdf\|certificate | download | EsignController@clientDoc/clientPdf/clientCertificate | web only (NO auth) — token-scoped public signer link | SYSTEM_INTERNAL | — | sensitive |

### 360/العلاقات (23)

| السطح | النوع | المرجع | التوثيق الحالي | التصنيف | المفتاح | الحساسية |
|---|---|---|---|---|---|---|
| graph.explore — Relationship Explorer page (tree + SVG) | route | graph.explore / RelationshipExplorerController@explore:60 | web\|auth + guardInternal() (hub_is_client OR hub_client_ids()!==null → 404) + RelationshipProjection root hub_read → 404 if unreadable | ASSIGNABLE_PERMISSION | root-module v (e.g. hr/assets/stations/projects v) | normal |
| graph.expand — incremental one-hop JSON | api | graph.expand / RelationshipExplorerController@expandNode:127 | web\|auth + guardInternal() + same RelationshipProjection guards; returns json($p) already fully gated | ASSIGNABLE_PERMISSION | root-module v | normal |
| Graph node inclusion (every node) | relationship | RelationshipProjection::build push→hub_read:147,164,220,252 | hub_read(module) per node = hub_can(v)+hub_scope+soft-delete; boundary never crossed via intermediate | ASSIGNABLE_PERMISSION | per-node module v | normal |
| Graph child counters (per node counts[]) | relationship | RelationshipProjection::build:170-173 | count computed on hub_read()-scoped base (clone $base)->count() | ASSIGNABLE_PERMISSION | child module v | normal |
| Graph asset↔project edge (asset_project) | edge | RelationshipProjection::build:208-239 | both endpoints via hub_read; active-only unless ?history; node label field-mode aware | ASSIGNABLE_PERMISSION | assets v AND projects v | normal |
| Graph derived station→project edge | edge | RelationshipProjection::build:245-263 | intermediate asset AND project both via hub_read; kind=derived tagged | ASSIGNABLE_PERMISSION | assets v AND projects v | normal |
| Employee360 overview counters (station/assets/projects/tasks/sim/endpoints/balance) | widget | Employee360::overview:19-62 | each behind its module hub_can(v); balance behind custody:v AND hub_field_mode(custody,amount)!=hide | ASSIGNABLE_PERMISSION | stations/assets/projects/tasks/phones/endpoints/custody v | sensitive |
| Employee360 stationHistory / custodyHistory / dailyReports | widget | Employee360:65,86,132 | stations:v / assets:v / updates:v respectively + hub_scope | ASSIGNABLE_PERMISSION | stations v / assets v / updates v | sensitive |
| Employee 360 tabs (profile/reports/assets/station/telecom/systems/endpoint/wallet) | tab | PortalController@employee:40-46,84-105 | hub_can(hr,v) for page; each tab guarded by module hub_can; forged ?tab= for unowned module → 403 | ASSIGNABLE_PERMISSION | hr v + per-tab module v | sensitive |
| Asset360 overview (holder/station/projects/endpoint/inventory) | widget | Asset360::overview:22-48 | holder hr:v, station stations:v, projects projects:v, endpoint endpoints:v, inventory assets:v | ASSIGNABLE_PERMISSION | hr/stations/projects/endpoints/assets v | sensitive |
| Asset360 lifecycle price / TCO / maintenance total | field | Asset360::lifecycle:106-113 | hub_field_mode(assets,price)!=hide gates price AND maint_total | ASSIGNABLE_PERMISSION | field_rules assets.price | sensitive |
| Asset 360 'projects' tab — asset↔project assignments (names + count) | widget | resources/views/modules/custom/assets.blade.php:22,113-117 → partials/asset_projects.blade.php:11,24,46 | ONLY !hub_is_client — no projects:v, no hub_scope; activeForAsset eager-loads project names unscoped | ASSIGNABLE_PERMISSION | — (should be projects v + hub_scope) | sensitive |
| Asset 360 'technical' tab — endpoint_devices partial | widget | resources/views/partials/endpoint_devices.blade.php:15-33 | !hub_is_client + hub_can(endpoints,v) + hub_company_ids() isolation + hw field-mode | ASSIGNABLE_PERMISSION | endpoints v | sensitive |
| Station360 overview (occupant/assets/endpoints/project/inventory) | widget | Station360::overview:22-47 | occupant hr:v, assets assets:v, endpoints endpoints:v, project projects:v, inventory assets:v; all hub_scope | ASSIGNABLE_PERMISSION | hr/assets/endpoints/projects v | sensitive |
| Station360 currentAssets / assetHistory | widget | Station360:50,64 | hub_can(assets,v) + hub_scope(assets) | ASSIGNABLE_PERMISSION | assets v | normal |
| Station360 projectContext (direct + derived) | widget | Station360::projectContext:113-139 | hub_can(projects,v); derived also hub_can(assets,v) + hub_scope(projects/assets) | ASSIGNABLE_PERMISSION | projects v (+assets v derived) | normal |
| Station 360 'people' tab — occupant + assignment history names | widget | resources/views/modules/custom/stations.blade.php:11-13,105,143-160 | stations:v (current_employee_id is a normal stations ref field → users) | ASSIGNABLE_PERMISSION | stations v | normal |
| Project 360 'assets' tab — project↔asset assignments (code/name/type/status + count) | widget | resources/views/modules/custom/projects.blade.php:63,202-203 → partials/project_assets.blade.php:8,35,45-48 | ONLY !hub_is_client — no assets:v, no hub_scope; activeForProject eager-loads asset fields unscoped | ASSIGNABLE_PERMISSION | — (should be assets v + hub_scope) | sensitive |
| Project 360 'finance' tab (P&L / cost / budget) | tab | resources/views/modules/custom/projects.blade.php:23,66,298 | !hub_is_client + hub_field_mode(projects,cost)!=hide AND hub_field_mode(projects,budget)!=hide | ASSIGNABLE_PERMISSION | field_rules projects.cost + projects.budget | sensitive |
| Related-record counters / list (record_list on every 360 activity tab) | widget | resources/views/partials/record_list.blade.php via hub_related helpers.php:3232-3268 | hub_can(child,v) per child module + hub_scope; client audience filter via scopeVisibleToClient | ASSIGNABLE_PERMISSION | child module v | normal |
| Contextual action links: assign/end asset↔project | button | projects.assets.assign / assets.projects.assign / assetproject.end (project_assets:53,69 / asset_projects:52,68) | controller: assets:e + projects:v + company-scope; hub_is_client→403; cross-company→404 (Project360Test) | ASSIGNABLE_PERMISSION | assets e + projects v | sensitive |
| Station 'employee 360' contextual link | button | resources/views/modules/custom/stations.blade.php:106-108 | hub_can(hr,v) on link; portal.employee target aborts 403 without hr:v + hub_scope | ASSIGNABLE_PERMISSION | hr v | normal |
| Saved graph views (explorer) | widget | RelationshipExplorerController@explore:104 | SavedView where user_id=auth id, module='graph' (owner-only rows) | SELF_SERVICE | owner of the saved view | normal |

### التصدير/الجماعيّ (20)

| السطح | النوع | المرجع | التوثيق الحالي | التصنيف | المفتاح | الحساسية |
|---|---|---|---|---|---|---|
| Generic module CSV export | export | m.export → ModuleController::export ModuleController.php:762-775 | hub_can(module,v) + exportBelt(): hub_exporter(exp flag)+freeze(423)+stepup+DATA_EXPORT audit; buildQuery hub_scope+company+client scope; columns=hub_visible_fields (hide honored), sec masked, 5000 cap, formula-injection neutralized | ASSIGNABLE_PERMISSION | module 'v' + flag 'exp' | sensitive |
| Bulk 'export selected' CSV | bulk | m.bulk do=export → ModuleController::bulk ModuleController.php:881-890 | hub_can(module,v) + same exportBelt() belt + hub_scope->whereIn(ids), ids capped 200 | ASSIGNABLE_PERMISSION | module 'v' + flag 'exp' | sensitive |
| Bulk status change | bulk | m.bulk do=status ModuleController.php:893-942 | hub_can(module,e) + status field hub_field_mode!=''→403 + hub_needs_approval blocks + per-row hub_scope->first + status-in-options | ASSIGNABLE_PERMISSION | module 'e' | normal |
| Bulk delete | bulk | m.bulk do=delete ModuleController.php:944-963 | hub_can(module,d) + hub_needs_approval blocks bulk + per-row hub_scope->first | ASSIGNABLE_PERMISSION | module 'd' | sensitive |
| Generic module import (form/map/run) | import | m.import,m.import.map,m.import.run → ImportController ImportController.php:20-197 | hub_can(module,a) + abort_if hub_scoped (full-scope accounts only) + per-field hub_field_mode!='' skip + company-isolation on rows (skip out-of-scope, default to allowed[0]) + scoped ref resolution | ASSIGNABLE_PERMISSION | module 'a' | sensitive |
| Monthly attendance CSV export | export | reports.monthly.export → ReportsController::monthlyExport ReportsController.php:267-301 | guardMonthly(): !hub_is_client + (attend:v OR hr:v); monthlyEmployees() company-scoped; BOM+formula-neutralized. NO freeze, NO audit, NO exp flag, NO stepup, NO field-mode | ASSIGNABLE_PERMISSION | attend 'v' OR hr 'v' | sensitive |
| Settings config export (JSON) | export | settings.export → SettingController::export SettingController.php:470-488 | gate()=hub_is_owner() + freeze(423) + hub_audit('تصدير'); excludes secrets/state | OWNER_ONLY | owner | owner_only |
| Settings config import (diff/apply) | import | settings.import + settings.import.apply → SettingController::import/importApply SettingController.php:499,538 | gate()=hub_is_owner(); import writes nothing (session plan); importApply hub_require_stepup on risky keys | OWNER_ONLY | owner | owner_only |
| Attachment single download | download | att.dl → AttachmentController::download → AttachmentService::download AttachmentService.php:152-173 | guardRecord(module,record,'v'): hub_can(v)+hub_scope->findOrFail (company/project/client isolation) + av infected 423 + download_log + classified-access audit + Content-Disposition:attachment | ASSIGNABLE_PERMISSION | parent module 'v' | sensitive |
| Attachment bulk ZIP (all record files) | download | att.zip → AttachmentController::zip AttachmentController.php:118-173 | guardRecord(module,record,'v') + infected excluded + per-file download_log + auditClassifiedAccess + tmp prune + deleteFileAfterSend | ASSIGNABLE_PERMISSION | parent module 'v' | sensitive |
| Mobile file download/stream API | download | mobile.files.download/stream → MobileFileController MobileFileController.php:257-285 | AttachmentService::download/stream guardRecord(v)+scope + guardClientModule (PortalGuard MODULE_ALLOW) over matrix; stream limited to INLINE_MIMES+nosniff+CSP | CLIENT_PORTAL_GOVERNED | parent module 'v' + portal allow-list | sensitive |
| Quote proposal PDF | download | quotes.pdf → QuoteController::pdf QuoteController.php:56-71 | hub_can(quotes,v) + hub_scope(Quote)->findOrFail + audit; hideTotal/hideItems field-mode applied in doc view | ASSIGNABLE_PERMISSION | quotes 'v' | sensitive |
| Change-order PDF | download | changeorders.pdf → ChangeOrderController::pdf ChangeOrderController.php:66-79 | hub_can(changeorders,v) + hub_scope->findOrFail + audit | ASSIGNABLE_PERMISSION | changeorders 'v' | sensitive |
| E-sign document PDF (internal) | download | esign.pdf → EsignController::pdf EsignController.php:635-651 | gate() + guardRequest(req); attachment disposition | ASSIGNABLE_PERMISSION | esign/contracts perm | sensitive |
| E-sign client PDF (public token) | download | sign.pdf → EsignController::clientPdf EsignController.php:654-669 | resolveToken(token)+session sign.ok proof; docHtml(evidence:false) redacts other signers' IP/ID; logs downloaded event | PARTICIPATION_GOVERNED | signed link token | sensitive |
| Batch asset-label print (A4) | print | identity.labels → IdentityController::labels IdentityController.php:335-352 | hub_can(assets,v) + Custody::scoped()->whereIn(ids) + BULK_MAX cap + hub_audit('طباعة ملصقات دفعية' DATA_EXPORT) | ASSIGNABLE_PERMISSION | assets 'v' | normal |
| Product / custody single label print | print | identity.product.label IdentityController.php:317; custody.label CustodyController.php:108 | hub_can(products\|assets,v) + company/hub scope->findOrFail; product label audited | ASSIGNABLE_PERMISSION | products 'v' / assets 'v' | normal |
| Data-room share file download | download | share.file → DataRoomController::file DataRoomController.php:139-190 | alive(token)+optional password session; no_download→attachment blocked; watermark burned into bytes (fail-closed); logView | CLIENT_PORTAL_GOVERNED | share link token | sensitive |
| Endpoint agent/release binary download | download | endpoints.releases.download EndpointReleaseController.php:197-218; endpoint.agent.download EndpointProtocolController.php:422 | web: auth + download_log + Content-Disposition:attachment; api: endpoint.signature middleware (machine) | SYSTEM_INTERNAL | signature / fleet perm | normal |
| Automation flows bulk enable/disable | bulk | flows.bulk → FlowController::bulk FlowController.php:85-100 | gate() + validated group + hub_audit; toggles config only, not data egress | ASSIGNABLE_PERMISSION | automation gate | normal |

### التنبيهات/الأتمتة (23)

| السطح | النوع | المرجع | التوثيق الحالي | التصنيف | المفتاح | الحساسية |
|---|---|---|---|---|---|---|
| مركز الإشعارات (الصفحة) | route | notifications.index | where user_id=auth()->id() (own only) | SELF_SERVICE | — | normal |
| عدّ غير المقروء | route | notifications.count | user_id=auth()->id() | SELF_SERVICE | — | normal |
| الجرس المصغّر | route | notifications.mini | user_id=auth()->id() | SELF_SERVICE | — | normal |
| فتح إشعار (go) | route | notifications.go | findOrFail(user_id=auth) ثم redirect m.show | SELF_SERVICE | — | normal |
| تحديد الكل مقروء | route | notifications.readall | user_id=auth()->id() | SELF_SERVICE | — | normal |
| إشعارات الجوال (قائمة) | api | mobile.notifications.index | HubNotification where user_id=auth()->id() | SELF_SERVICE | — | normal |
| وجهة إشعار الجوال | api | mobile.notifications.target | find(user_id=auth) | SELF_SERVICE | — | normal |
| تعليم إشعار مقروء (جوال) | api | mobile.notifications.read | find(user_id=auth) | SELF_SERVICE | — | normal |
| تسجيل/إلغاء رمز الدفع | api | mobile.push.register / unregister | PushService scoped to auth user; F7 dedup يبطل ربط الغير | SELF_SERVICE | — | normal |
| حالة/اختبار الدفع (إدارة جوال) | api | mobile.push.admin.status / admin.test | hub_is_owner() (403 وإلا) | OWNER_ONLY | owner | owner_only |
| اختبار الدفع (ويب) | route | mobileplatform.push.test | hub_is_owner()\|\|hub_flag('mobile'); يرسل لرموز المُنادي فقط | ASSIGNABLE_PERMISSION | flag:mobile | sensitive |
| مركز Webhooks | route | webhooks.index/store/toggle/destroy/test/log/resend | gate()=hub_is_owner() في كل دالة | OWNER_ONLY | owner | owner_only |
| استقبال Webhook وارد | api | hook.receive (POST /hook/{token}) | token عشوائي 48 + HMAC اختياري + طابع زمن ±5د + throttle:120,1 | SYSTEM_INTERNAL | — | sensitive |
| إدارة الويبهوك الوارد | route | hooks.index/store/toggle/destroy | gate()=hub_is_owner() | OWNER_ONLY | owner | owner_only |
| لوحة/حفظ/اختبار n8n | route | integrations.n8n / n8n.save / n8n.test | gate()=hub_is_owner() | OWNER_ONLY | owner | owner_only |
| اتصالات أودو | route | integrations.odoo.* | gate()=hub_is_owner() في كل دالة | OWNER_ONLY | owner | owner_only |
| مركز المراسلة (بريد/تلجرام) | route | integrations.messaging.* | gate()=hub_is_owner() في كل دالة | OWNER_ONLY | owner | owner_only |
| مركز التكاملات | route | integrations.index / guide | gate()=hub_is_owner() | OWNER_ONLY | owner | owner_only |
| المجدول (automation/outbox/digest/backup/…) | api | routes/console.php | console kernel + withoutOverlapping (بلا سياق مستخدم) | SYSTEM_INTERNAL | — | sensitive |
| تفريع الدفع (created hook) | notification | HubNotification::booted created → PushService::scheduleFanout | payloadFor: عنوان عام حسب النوع + جسم عام + target؛ لا نص خام | SYSTEM_INTERNAL | — | sensitive |
| إشعار المنشن/الرد | notification | CommentService::notifyAround/extractMentions | filterRecordVisible: hub_can(v)+hub_scope لكل مُشار إليه | PARTICIPATION_GOVERNED | module.v | sensitive |
| إشعار الموافقة | notification | ApprovalService:196-199 | hub_approvers_for(module,record) — منطّق لكل معتمِد | PARTICIPATION_GOVERNED | flag:approve | sensitive |
| إشعار قاعدة التنبيه اليومية | notification | AlertEngine:243-254 | hub_can(mod,v) [155-157] + hub_scope لكل مستلم [199-207] | ASSIGNABLE_PERMISSION | module.v + flag:monitor | sensitive |

### النطاق/IDOR (20)

| السطح | النوع | المرجع | التوثيق الحالي | التصنيف | المفتاح | الحساسية |
|---|---|---|---|---|---|---|
| Generic module CRUD (index/show/create/store/update/destroy) | route | ModuleController::findScoped app/Http/Controllers/Web/ModuleController.php:1229 | hub_can(module,op) + hub_scope(...)->findOrFail; fill() honors hub_field_mode | ASSIGNABLE_PERMISSION | v/a/e/d | normal |
| CSV export (list + bulk export) | export | ModuleController::exportBelt app/Http/Controllers/Web/ModuleController.php:794 | hub_exporter() single org-wide 'exp' flag + hub_scope + freeze/stepup belt; columns via hub_visible_fields, sec masked | ASSIGNABLE_PERMISSION | exp flag (not per-module) | sensitive |
| Secret reveal (sec fields) | button | ModuleController::revealSecret app/Http/Controllers/Web/ModuleController.php:394 | hub_copy_secrets() 'copySec' flag + row.allowed_ids allowlist + optional step-up + hub_field_mode + hub_scope; value never in HTML | SECURITY_INVARIANT | copySec flag (not per-module) | owner_only |
| REST API v1 record read/write | api | V1Controller::apiShow/apiStore/apiUpdate app/Http/Controllers/Api/V1Controller.php:83 | resolveApi (hub_can + client-boundary 404 + token scope) + hub_scope->findOrFail + shape() drops hide/sec fields | ASSIGNABLE_PERMISSION | v/a/e/d + token scopes | sensitive |
| Mobile resource CRUD + actions | api | MobileResourceController app/Http/Controllers/Api/MobileResourceController.php:66 | delegates to V1 apiShow/apiIndex/apiPatch (hub_scope + shape); MobileContext::apply narrows AND-only over hub_scope | ASSIGNABLE_PERMISSION | v/a/e/d | normal |
| File byte gateway (hub/*) | download | FileController::mayRead app/Http/Controllers/Web/FileController.php:157 | per-module hub_can + hub_scope on any record referencing the path; DM/comment/inbox parents re-derived; AV-infected blocked | ASSIGNABLE_PERMISSION | v of owning module | sensitive |
| Attachment download/preview/zip | download | AttachmentService::guardRecord app/Support/AttachmentService.php:214 | guardRecord = hub_can(module,v) + hub_scope->findOrFail(record_id) before any byte; classified-access audited | ASSIGNABLE_PERMISSION | v of owning module | sensitive |
| Comments store/react (record + feed + channel) | form | CommentService::guardTarget app/Support/CommentService.php:40 | feed=public authed; channel=guardConversation membership; module=hub_can(v)+hub_scope->findOrFail | PARTICIPATION_GOVERNED | v of target module / membership | normal |
| Direct messages (thread/send/react/search) | widget | DmController + DmService::reachable app/Http/Controllers/Web/DmController.php:163 | dmReachable (company scope) + inCompanyScope on message rows; parties-only for react/attachments | PARTICIPATION_GOVERNED | — | sensitive |
| Client portal (projects/documents/invoices/conversations) | api | ClientPortalData app/Support/ClientPortalData.php:97 | hub_client_ids()->whereIn(client_id) + hub_scope + Document::visibleToClient(audience) + conversation membership; internal messages stripped | CLIENT_PORTAL_GOVERNED | client account-type + membership | owner_only |
| Relationship graph explore/expand | route | RelationshipExplorerController app/Http/Controllers/Web/RelationshipExplorerController.php:60 | guardInternal (client/isolated => 404) + RelationshipProjection per-node hub_read (hub_can+hub_scope); counts on hub_read query | ASSIGNABLE_PERMISSION | v of each node module | sensitive |
| Global search (mini/index/results) | search | SearchController::query app/Http/Controllers/Web/SearchController.php:386 | per module hub_can(v)+hub_scope+hub_client_scope; Searchable trait excludes hide fields (Searchable.php:30) | ASSIGNABLE_PERMISSION | v of each module | normal |
| Esign sign-request management (edit/doc/certificate/decide) | route | EsignController::guardRequest app/Http/Controllers/Web/EsignController.php:164 | gate hub_can(contracts,op) + filterVisible: hub_scope(Contract) OR scoped link OR creator OR named approver | ASSIGNABLE_PERMISSION | contracts v/a/e | sensitive |
| Public signer surface (sign/{token}, verify) | route | routes sign.show / share.show | unguessable token is the capability; optional password; expiry/void; rotates on text edit | PUBLIC_AUTHENTICATED_BASE | token | sensitive |
| Data-room share links (s/{token}, s/{token}/file) | download | DataRoomController app/Http/Controllers/Web/DataRoomController.php:139 | creation gated hub_secrets(); access by token + optional password + expiry + no_download | PUBLIC_AUTHENTICATED_BASE | secrets flag to create; token to view | owner_only |
| Endpoint device protocol (heartbeat/pull/result/event) | api | EndpointProtocolController app/Http/Controllers/Api/EndpointProtocolController.php:238 | endpoint.signature middleware sets device; every query where('device_id',$device->id); atomic claim | SYSTEM_INTERNAL | device ES256 signature | sensitive |
| Endpoint command issue (admin) | button | EndpointProtocolController::issue app/Http/Controllers/Api/EndpointProtocolController.php:427 | client 404 + owner\|monitor + whereIn(company_id,hub_company_ids) + active-only + closed TYPES allowlist + step-up for risky | OWNER_ONLY | monitor flag / owner | owner_only |
| Notifications (list/count/go/readAll) | widget | NotificationController app/Http/Controllers/Web/NotificationController.php:52 | HubNotification::where('user_id',auth()->id())->findOrFail; go() redirects to target guarded by its own route | SELF_SERVICE | — | normal |
| Metrics ingest (api/v1/metrics) | api | V1Controller::metricsIngest app/Http/Controllers/Api/V1Controller.php:249 | per point resolveApi(module,'e') + hub_scope->whereKey->exists before any write; transaction | ASSIGNABLE_PERMISSION | e of target module + token | normal |
| Cost / profitability dashboard | route | CostController::index app/Http/Controllers/Web/CostController.php:31 | hub_monitor() flag; project P&L via hub_scope(Project); passes hub_hourly_rates() to view | ASSIGNABLE_PERMISSION | monitor flag | sensitive |

### المطابقة (24)

| السطح | النوع | المرجع | التوثيق الحالي | التصنيف | المفتاح | الحساسية |
|---|---|---|---|---|---|---|
| Generic web CRUD engine | route | m.index/m.store/m.show/m.update/m.destroy/m.edit/m.create/m.restore (ModuleController.php:18-19) | hub_can(user,module,op) via resolve() + hub_scope + guardProject/Company/Client; PortalGuard whitelists only engagements/projects/fin for clients | ASSIGNABLE_PERMISSION | module v/a/e/d | normal |
| Generic API v1 engine | api | api/v1/{module}* (V1Controller::resolveApi:355-384) | resolveApi: client-boundary (PortalGuard::MODULE_ALLOW) + hub_can + api-token scope; hub_scope on reads; hub_needs_approval on e/d | ASSIGNABLE_PERMISSION | module v/a/e/d + token scopes | normal |
| Mobile resource engine | api | api/mobile/v1/{module}* (MobileResourceController) | mobile.session+mobile.portal+mobile.context middleware; findScoped(hub_scope) + hub_can; MobilePortalGuard client boundary | ASSIGNABLE_PERMISSION | module v/a/e/d | normal |
| Communications oversight (compliance read of all conversations) | route | oversight.index / oversight.show (OversightController.php:51-70) | isOversightOfficer(): user->role->name === setting('collab.oversight_role') + hub_require_stepup + mandatory reason + company/client scope | OWNER_ONLY | — (role NAME match, NOT a matrix key or flag) | owner_only |
| Monthly attendance CSV export | export | reports.monthly.export (ReportsController.php:267 + guardMonthly:49) | guardMonthly(): hub_can(attend,v)\|\|hub_can(hr,v) ONLY — no hub_exporter()/exportBelt() | ASSIGNABLE_PERMISSION | attend v / hr v (exp flag NOT enforced) | sensitive |
| Generic module CSV export | export | m.export (ModuleController.php:762 + exportBelt:794-798) | hub_can(module,v) + hub_exporter() (exp flag) + freeze_exports gate + big-export stepup + audit | ASSIGNABLE_PERMISSION | module v + flag exp | sensitive |
| Secret field reveal | button | m.secret (ModuleController::revealSecret:394-417) | hub_can(module,v) + hub_copy_secrets (secrets\|copySec flag) + optional per-field/global stepup + audit | ASSIGNABLE_PERMISSION | module v + flag secrets/copySec | owner_only |
| Custody money movements (advance/charge/expense/deduction/transfer/correct) | form | custody.wallet.* (EmployeeCustodyController::can:46-48) | hub_can('custody','e') + hr scope + company scope | ASSIGNABLE_PERMISSION | custody e | sensitive |
| Custody permit/settlement/reversal | form | custody.wallet.settlement/reverse + custody.permit (EmployeeCustodyController.php:201,330,356) | hub_can('custody','approve') — a fine-grained matrix op | ASSIGNABLE_PERMISSION | custody approve (consumed, NOT editor-grantable) | owner_only |
| Approval decision (approve/reject deferred ops) | button | approvals.approve/reject (ApprovalService::decide:58-95) | hub_approver() (approve flag) + hub_can(module,op) + hub_scope target + version guard | ASSIGNABLE_PERMISSION | flag approve + module a/e/d | sensitive |
| Role editor | route | roles.* (RoleController::gate:31-34) | hub_is_owner() | OWNER_ONLY | owner | owner_only |
| User administration | route | users.* (UserController::gate:14-16 + guard:31-36) | hub_flag(users) + owner-account write protection (only owner edits/creates owner-role accounts) | ASSIGNABLE_PERMISSION | flag users | owner_only |
| Analytics dashboards (perf/sales/costs/svccosts/kpis/capacity/recs/impact/appquality/digital-assets) | route | performance/sales.dashboard/costs.index/... (hub_monitor gate in each controller) | hub_monitor() = flag monitor (owner bypass) | ASSIGNABLE_PERMISSION | flag monitor | sensitive |
| Owner governance centers (security/ops/errors/features/settings/integrations/access/quality/fields/flows) | route | security.*/ops.*/errors.*/features.*/settings.*/integrations.*/access.*/quality.*/fields.*/flows.* | hub_is_owner() per-controller gate() | OWNER_ONLY | owner | owner_only |
| Audit log | route | audit.* (AuditController) + SystemTraceController:21 | hub_flag(audit) (audit center) / owner\|\|audit (request trace) | ASSIGNABLE_PERMISSION | flag audit | owner_only |
| Data room / secret vault share | route | dataroom.* (DataRoomController:21) ; s/{token} public unlock | hub_secrets() (secrets flag) for admin side; token+password for public share (share.* [web] no auth) | ASSIGNABLE_PERMISSION | flag secrets | owner_only |
| Mobile platform admin | route | mobileplatform.* (MobilePlatformController::canView:45-49) + push/admin/* | hub_is_owner()\|\|hub_flag(mobile) (web); hub_is_owner() (mobile push admin) | ASSIGNABLE_PERMISSION | flag mobile | owner_only |
| Client portal (web + mobile) | route | portal.* (ClientPortalController) ; api/mobile/v1/portal/* | PortalGuard/MobilePortalGuard whitelist + hub_client_ids scope + document/conversation audience | CLIENT_PORTAL_GOVERNED | client boundary (account_type=client) | sensitive |
| Cross-entity relationship explorer | route | graph.explore/graph.expand (RelationshipExplorerController:44-46; RelationshipProjection) | guardInternal (block client + client-scoped) + every node via hub_read (hub_can+scope) + hub_field_mode masking | ASSIGNABLE_PERMISSION | per-node module v | sensitive |
| Message search across surfaces | search | search.messages (MessageSearchController) | feed company scope + channel membership (ConversationMember) + own DM threads (inCompanyScope) | PARTICIPATION_GOVERNED | conversation membership | normal |
| Self-service notifications / my security / profile | route | notifications.* (NotificationController) ; mysec.* ; profile.* | user_id = auth()->id() on every query; own sessions/devices/2FA | SELF_SERVICE | self (auth id) | normal |
| Role-shared dashboards | widget | boards.* + dashboard (Dashboard::visibleTo:41 role_id ; DashboardController::layoutFor:52-64) | role_id===user->role_id OR shared for LISTING; each widget re-gated WidgetRegistry::isVisible(viewer)+resolve(viewer) | SELF_SERVICE | role_id (visibility only) + per-widget gate | normal |
| Public health / PWA / activation / webhook receive | route | healthz/up/manifest/offline ; activate.* ; hook.receive ; sign.* s/{token} | throttle-only (health/pwa) ; token+throttle (activation/sign/share) ; HMAC+timestamp (hook.receive) | SYSTEM_INTERNAL | none / token / HMAC | normal |
| Endpoint/device agent protocol | api | api/v1/endpoint/* (EndpointProtocolController) [endpoint.signature] | endpoint.signature middleware (ES256 device signature); enroll throttled | SYSTEM_INTERNAL | device signature | sensitive |
