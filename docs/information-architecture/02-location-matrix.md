# 02 — مصفوفةُ المواقع (Location Matrix · IA · الطور 10)

> مُولَّدةٌ آليّاً من `InformationArchitecture::routeLocation()` على **كلِّ** مسارِ GET مُسمّى.
> **MISSING = 0**: كلُّ مسارٍ يُحَلُّ لموقعٍ (سطح/مجال/وحدة/مؤرشفة/دلو مُسمّى) — لا مسارَ بلا بيت.
> لا يُحرَّر يدويّاً؛ يُعاد توليدُه عند تغيّر المسارات أو `hub_ia.php`.

**إجماليُّ مسارات GET المُسمّاة: 218** — غيرُ مصنَّف: **0** · يتيم: **0** · بيتٌ مكرّر: **0**.

| المسار | URI | الموقعُ في IA | الحالة |
|---|---|---|---|
| `activate.show` | `/activate/{token}` | مداخل عامّة (public) | PUBLIC |
| `activity.index` | `/admin/activity` | الإدارة والنظام ‹ security | HOMED |
| `activity.show` | `/admin/activity/{id}` | الإدارة والنظام ‹ security | HOMED |
| `alerts` | `/alerts` | مهامّي · سطح | GLOBAL |
| `alerts.center` | `/admin/alerts` | الإدارة والنظام ‹ ops | HOMED |
| `appquality` | `/app-quality` | التقنية والبنية الرقمية ‹ apps | HOMED |
| `apps.center` | `/app/{id}` | التقنية والبنية الرقمية ‹ apps | HOMED |
| `appsprojects` | `/apps-projects` | التقنية والبنية الرقمية ‹ apps | HOMED |
| `assets.life` | `/assets-life` | الأصول والعقود والامتثال ‹ assets | HOMED |
| `att.dl` | `/attachments/{id}/dl` | مولّدات ووثائق (utility) | UTILITY |
| `att.view` | `/attachments/{id}/view` | مولّدات ووثائق (utility) | UTILITY |
| `att.zip` | `/attachments/{module}/{recordId}/zip` | مولّدات ووثائق (utility) | UTILITY |
| `audit.coverage` | `/admin/audit/coverage` | الإدارة والنظام ‹ security | HOMED |
| `audit.index` | `/admin/audit` | الإدارة والنظام ‹ security | HOMED |
| `audit.show` | `/admin/audit/{id}` | الإدارة والنظام ‹ security | HOMED |
| `boards.edit` | `/boards/{id}` | مهامّي · سطح | GLOBAL |
| `boards.index` | `/boards` | العمل والتسليم ‹ exec | HOMED |
| `calendar` | `/calendar` | مهامّي · سطح | GLOBAL |
| `capacity` | `/capacity` | الموظفون والموارد البشرية ‹ workforce | HOMED |
| `ceo` | `/ceo` | الرئيسية · سطح | GLOBAL |
| `changeorders.pdf` | `/changeorder/{id}/pdf` | مولّدات ووثائق (utility) | UTILITY |
| `code.center` | `/code-center` | التقنية والبنية الرقمية ‹ apps | HOMED |
| `compliance.board` | `/compliance-board` | الأصول والعقود والامتثال ‹ compliance | HOMED |
| `control.index` | `/admin/control` | الإدارة والنظام ‹ ops | HOMED |
| `conversations.index` | `/conversations` | مهامّي · سطح | GLOBAL |
| `conversations.show` | `/conversations/{id}` | مهامّي · سطح | GLOBAL |
| `costs.index` | `/costs` | المالية والمشتريات ‹ budgets | HOMED |
| `custody.catalog` | `/custody` | الأصول والعقود والامتثال ‹ assets | HOMED |
| `custody.category` | `/custody/cat/{code}` | الأصول والعقود والامتثال ‹ assets | HOMED |
| `custody.code` | `/c/{code}` | مداخل عامّة (public) | PUBLIC |
| `custody.label` | `/custody/{id}/label` | الأصول والعقود والامتثال ‹ assets | HOMED |
| `custody.permit.doc` | `/custody/{id}/permit/{permitId}` | الأصول والعقود والامتثال ‹ assets | HOMED |
| `custody.spec` | `/custody/{id}/spec` | الأصول والعقود والامتثال ‹ assets | HOMED |
| `custody.wallet.center` | `/custody-wallet` | الموظفون والموارد البشرية ‹ workforce | HOMED |
| `custody.wallet.employee` | `/custody-wallet/e/{id}` | الموظفون والموارد البشرية ‹ workforce | HOMED |
| `dashboard` | `//` | الرئيسية · سطح | GLOBAL |
| `dataroom.index` | `/dataroom` | الإدارة والنظام ‹ security | HOMED |
| `delivery` | `/delivery` | العمل والتسليم ‹ goals | HOMED |
| `delivery.psa` | `/delivery/psa` | العمل والتسليم ‹ goals | HOMED |
| `digital.assets` | `/digital-assets` | التقنية والبنية الرقمية ‹ social | HOMED |
| `dm.inbox` | `/dm` | مهامّي · سطح | GLOBAL |
| `dm.thread` | `/dm/{userId}` | مهامّي · سطح | GLOBAL |
| `endpoint.agent.download` | `/api/v1/endpoint/agent/download/{id}` | واجهة الموبايل والـAPI (api) | API |
| `endpoint.agent.manifest` | `/api/v1/endpoint/agent/manifest` | واجهة الموبايل والـAPI (api) | API |
| `endpoints.index` | `/endpoints` | التقنية والبنية الرقمية ‹ infra | HOMED |
| `endpoints.releases` | `/endpoints/releases` | التقنية والبنية الرقمية ‹ infra | HOMED |
| `endpoints.releases.download` | `/endpoints/releases/{id}/download` | التقنية والبنية الرقمية ‹ infra | HOMED |
| `endpoints.show` | `/endpoints/{id}` | التقنية والبنية الرقمية ‹ infra | HOMED |
| `errors.index` | `/admin/errors` | الإدارة والنظام ‹ ops | HOMED |
| `errors.logs` | `/admin/errors/logs` | الإدارة والنظام ‹ ops | HOMED |
| `errors.show` | `/admin/errors/{id}` | الإدارة والنظام ‹ ops | HOMED |
| `esign.cert` | `/esign/{id}/certificate` | الأصول والعقود والامتثال ‹ contracts | HOMED |
| `esign.doc` | `/esign/{id}/doc` | الأصول والعقود والامتثال ‹ contracts | HOMED |
| `esign.edit` | `/esign/{id}/edit` | الأصول والعقود والامتثال ‹ contracts | HOMED |
| `esign.index` | `/esign` | الأصول والعقود والامتثال ‹ contracts | HOMED |
| `esign.pdf` | `/esign/{id}/pdf` | الأصول والعقود والامتثال ‹ contracts | HOMED |
| `esign.tpl.edit` | `/esign/templates/{id}/edit` | الأصول والعقود والامتثال ‹ contracts | HOMED |
| `feed` | `/feed` | مهامّي · سطح | GLOBAL |
| `field.dashboard` | `/field` | العمليات الميدانية ‹ cycles | HOMED |
| `field.route` | `/field/route/{id}` | العمليات الميدانية ‹ cycles | HOMED |
| `field.sessions` | `/field/sessions` | العمليات الميدانية ‹ cycles | HOMED |
| `fields.index` | `/admin/fields` | الإدارة والنظام ‹ quality | HOMED |
| `file.show` | `/files/{path}` | مولّدات ووثائق (utility) | UTILITY |
| `flows.edit` | `/admin/flows/{id}/edit` | الإدارة والنظام ‹ quality | HOMED |
| `flows.index` | `/admin/flows` | الإدارة والنظام ‹ quality | HOMED |
| `flows.sandbox` | `/admin/flows/{id}/sandbox` | الإدارة والنظام ‹ quality | HOMED |
| `graph.expand` | `/graph/expand` | الكيانات والعلاقات ‹ relationships | HOMED |
| `graph.explore` | `/graph/explore` | الكيانات والعلاقات ‹ relationships | HOMED |
| `healthz` | `/healthz` | بنية تحتية (system) | SYSTEM |
| `hooks.index` | `/admin/integrations/hooks` | الإدارة والنظام ‹ settings | HOMED |
| `identity.center` | `/identity` | الأصول والعقود والامتثال ‹ assets | HOMED |
| `identity.labels` | `/identity/labels` | الأصول والعقود والامتثال ‹ assets | HOMED |
| `identity.product.label` | `/identity/product/{id}/label` | الأصول والعقود والامتثال ‹ assets | HOMED |
| `identity.resolve` | `/identity/resolve` | الأصول والعقود والامتثال ‹ assets | HOMED |
| `impact` | `/impact` | الكيانات والعلاقات ‹ relationships | HOMED |
| `inboxdocs.index` | `/inboxdocs` | مهامّي · سطح | GLOBAL |
| `innovation` | `/innovation` | المعرفة والمستندات ‹ kb | HOMED |
| `integrations.guide` | `/admin/integrations/guide` | الإدارة والنظام ‹ settings | HOMED |
| `integrations.index` | `/admin/integrations` | الإدارة والنظام ‹ settings | HOMED |
| `integrations.messaging` | `/admin/integrations/messaging` | الإدارة والنظام ‹ settings | HOMED |
| `integrations.n8n` | `/admin/integrations/n8n` | الإدارة والنظام ‹ settings | HOMED |
| `integrations.odoo` | `/admin/integrations/odoo` | الإدارة والنظام ‹ settings | HOMED |
| `inventory.center` | `/inventory` | الأصول والعقود والامتثال ‹ inventory | HOMED |
| `inventory.show` | `/inventory/{id}` | الأصول والعقود والامتثال ‹ inventory | HOMED |
| `journey` | `/journey/{id}` | الكيانات والعلاقات ‹ crm | HOMED |
| `kpis.index` | `/kpis` | الرئيسية · سطح | GLOBAL |
| `legal` | `/legal` | الأصول والعقود والامتثال ‹ contracts | HOMED |
| `login` | `/login` | مداخل عامّة (public) | PUBLIC |
| `login.otp` | `/login/otp` | مداخل عامّة (public) | PUBLIC |
| `m.board` | `/m/{module}/board` | العمل والتسليم ‹ exec | HOMED |
| `m.create` | `/m/{module}/create` | العمل والتسليم ‹ exec | HOMED |
| `m.edit` | `/m/{module}/{id}/edit` | العمل والتسليم ‹ exec | HOMED |
| `m.export` | `/m/{module}/export` | العمل والتسليم ‹ exec | HOMED |
| `m.import` | `/m/{module}/import` | العمل والتسليم ‹ exec | HOMED |
| `m.index` | `/m/{module}` | العمل والتسليم ‹ exec | HOMED |
| `m.show` | `/m/{module}/{id}` | العمل والتسليم ‹ exec | HOMED |
| `media.center` | `/media-center` | المعرفة والمستندات ‹ media | HOMED |
| `mobile.aasa` | `/.well-known/apple-app-site-association` | بنية تحتية (system) | SYSTEM |
| `mobile.app_config` | `/api/mobile/v1/app-config` | واجهة الموبايل والـAPI (api) | API |
| `mobile.approvals.index` | `/api/mobile/v1/approvals` | واجهة الموبايل والـAPI (api) | API |
| `mobile.approvals.show` | `/api/mobile/v1/approvals/{id}` | واجهة الموبايل والـAPI (api) | API |
| `mobile.assetlinks` | `/.well-known/assetlinks.json` | بنية تحتية (system) | SYSTEM |
| `mobile.auth.sessions.index` | `/api/mobile/v1/auth/sessions` | واجهة الموبايل والـAPI (api) | API |
| `mobile.bootstrap` | `/api/mobile/v1/bootstrap` | واجهة الموبايل والـAPI (api) | API |
| `mobile.comments.index` | `/api/mobile/v1/comments` | واجهة الموبايل والـAPI (api) | API |
| `mobile.context` | `/api/mobile/v1/context` | واجهة الموبايل والـAPI (api) | API |
| `mobile.dm.messages` | `/api/mobile/v1/dm/threads/{user}/messages` | واجهة الموبايل والـAPI (api) | API |
| `mobile.dm.threads` | `/api/mobile/v1/dm/threads` | واجهة الموبايل والـAPI (api) | API |
| `mobile.files.download` | `/api/mobile/v1/files/{id}/download` | واجهة الموبايل والـAPI (api) | API |
| `mobile.files.stream` | `/api/mobile/v1/files/{id}/stream` | واجهة الموبايل والـAPI (api) | API |
| `mobile.health` | `/api/mobile/v1/health` | واجهة الموبايل والـAPI (api) | API |
| `mobile.home` | `/api/mobile/v1/home` | واجهة الموبايل والـAPI (api) | API |
| `mobile.identity.resolve` | `/api/mobile/v1/identity/resolve/{q}` | واجهة الموبايل والـAPI (api) | API |
| `mobile.navigation` | `/api/mobile/v1/navigation` | واجهة الموبايل والـAPI (api) | API |
| `mobile.notifications.index` | `/api/mobile/v1/notifications` | واجهة الموبايل والـAPI (api) | API |
| `mobile.notifications.target` | `/api/mobile/v1/notifications/{id}/target` | واجهة الموبايل والـAPI (api) | API |
| `mobile.notifications.unread` | `/api/mobile/v1/notifications/unread-count` | واجهة الموبايل والـAPI (api) | API |
| `mobile.openapi` | `/api/mobile/v1/openapi.json` | واجهة الموبايل والـAPI (api) | API |
| `mobile.prefs.index` | `/api/mobile/v1/prefs` | واجهة الموبايل والـAPI (api) | API |
| `mobile.push.admin.status` | `/api/mobile/v1/push/admin/status` | واجهة الموبايل والـAPI (api) | API |
| `mobile.resource.actions` | `/api/mobile/v1/{module}/{id}/actions` | واجهة الموبايل والـAPI (api) | API |
| `mobile.resource.index` | `/api/mobile/v1/{module}` | واجهة الموبايل والـAPI (api) | API |
| `mobile.resource.show` | `/api/mobile/v1/{module}/{id}` | واجهة الموبايل والـAPI (api) | API |
| `mobile.schema` | `/api/mobile/v1/schema` | واجهة الموبايل والـAPI (api) | API |
| `mobile.schema.modules` | `/api/mobile/v1/schema/modules` | واجهة الموبايل والـAPI (api) | API |
| `mobile.search` | `/api/mobile/v1/search` | واجهة الموبايل والـAPI (api) | API |
| `mobile.sync` | `/api/mobile/v1/sync/{module}` | واجهة الموبايل والـAPI (api) | API |
| `morning` | `/morning` | مهامّي · سطح | GLOBAL |
| `mysec.index` | `/my/security` | مهامّي · سطح | GLOBAL |
| `notifications.count` | `/notifications/count` | مهامّي · سطح | GLOBAL |
| `notifications.go` | `/notifications/{id}/go` | مهامّي · سطح | GLOBAL |
| `notifications.index` | `/notifications` | مهامّي · سطح | GLOBAL |
| `notifications.mini` | `/notifications/mini` | مهامّي · سطح | GLOBAL |
| `odoo.project` | `/odoo/projects/{id}` | التقنية والبنية الرقمية ‹ apps | HOMED |
| `okrs.board` | `/okrs` | العمل والتسليم ‹ goals | HOMED |
| `ops.health` | `/admin/ops/health` | الإدارة والنظام ‹ ops | HOMED |
| `ops.index` | `/admin/ops` | الإدارة والنظام ‹ ops | HOMED |
| `ops.runbooks` | `/admin/ops/runbooks` | الإدارة والنظام ‹ ops | HOMED |
| `oversight.index` | `/oversight` | العمل والتسليم ‹ requests | HOMED |
| `oversight.show` | `/oversight/{id}` | العمل والتسليم ‹ requests | HOMED |
| `performance` | `/performance` | الموظفون والموارد البشرية ‹ workforce | HOMED |
| `policies.board` | `/policies` | المعرفة والمستندات ‹ policies | HOMED |
| `portal.conversation` | `/portal/conversations/{id}` | بوّابة العميل (portal) | PORTAL |
| `portal.conversations` | `/portal/conversations` | بوّابة العميل (portal) | PORTAL |
| `portal.document` | `/portal/documents/{id}` | بوّابة العميل (portal) | PORTAL |
| `portal.documents` | `/portal/documents` | بوّابة العميل (portal) | PORTAL |
| `portal.employee` | `/employee/{id}` | الموظفون والموارد البشرية ‹ files | HOMED |
| `portal.engagements` | `/portal/engagements` | بوّابة العميل (portal) | PORTAL |
| `portal.home` | `/portal` | بوّابة العميل (portal) | PORTAL |
| `portal.invoice` | `/portal/invoices/{id}` | بوّابة العميل (portal) | PORTAL |
| `portal.invoices` | `/portal/invoices` | بوّابة العميل (portal) | PORTAL |
| `portal.me` | `/me` | مهامّي · سطح | GLOBAL |
| `portal.project` | `/portal/projects/{id}` | بوّابة العميل (portal) | PORTAL |
| `portal.projects` | `/portal/projects` | بوّابة العميل (portal) | PORTAL |
| `prefs.edit` | `/personalize` | مهامّي · سطح | GLOBAL |
| `pricing` | `/pricing` | المالية والمشتريات ‹ reports | HOMED |
| `products.code` | `/p/{code}` | مداخل عامّة (public) | PUBLIC |
| `profile.edit` | `/profile` | مهامّي · سطح | GLOBAL |
| `purchases.doc` | `/purchase/{id}/doc` | مولّدات ووثائق (utility) | UTILITY |
| `pwa.icon` | `/pwa-icon.svg` | بنية تحتية (system) | SYSTEM |
| `pwa.manifest` | `/manifest.webmanifest` | بنية تحتية (system) | SYSTEM |
| `pwa.offline` | `/offline` | بنية تحتية (system) | SYSTEM |
| `quality.index` | `/admin/quality` | الإدارة والنظام ‹ quality | HOMED |
| `quoteflow` | `/apps/quoteflow` | الإدارة والنظام ‹ settings | HOMED |
| `quotes.diff` | `/quote/{id}/diff` | مولّدات ووثائق (utility) | UTILITY |
| `quotes.doc` | `/quote/{id}/doc` | مولّدات ووثائق (utility) | UTILITY |
| `quotes.pdf` | `/quote/{id}/pdf` | مولّدات ووثائق (utility) | UTILITY |
| `recs` | `/recommendations` | الرئيسية · سطح | GLOBAL |
| `reports.finance` | `/reports/finance` | المالية والمشتريات ‹ reports | HOMED |
| `roles.create` | `/admin/roles/create` | الإدارة والنظام ‹ users | HOMED |
| `roles.edit` | `/admin/roles/{role}/edit` | الإدارة والنظام ‹ users | HOMED |
| `roles.index` | `/admin/roles` | الإدارة والنظام ‹ users | HOMED |
| `sales.dashboard` | `/sales` | الكيانات والعلاقات ‹ crm | HOMED |
| `search` | `/search` | الرئيسية · سطح | GLOBAL |
| `search.mini` | `/search/mini` | الرئيسية · سطح | GLOBAL |
| `security.blocks` | `/admin/security/blocks` | الإدارة والنظام ‹ security | HOMED |
| `security.devices` | `/admin/security/devices` | الإدارة والنظام ‹ security | HOMED |
| `security.event` | `/admin/security/event/{source}/{id}` | الإدارة والنظام ‹ security | HOMED |
| `security.finding` | `/admin/security/findings/{id}` | الإدارة والنظام ‹ security | HOMED |
| `security.findings` | `/admin/security/findings` | الإدارة والنظام ‹ security | HOMED |
| `security.identity` | `/admin/security/identity` | الإدارة والنظام ‹ security | HOMED |
| `security.index` | `/admin/security` | الإدارة والنظام ‹ security | HOMED |
| `security.ip` | `/admin/security/ips/{ip}` | الإدارة والنظام ‹ security | HOMED |
| `security.ips` | `/admin/security/ips` | الإدارة والنظام ‹ security | HOMED |
| `security.privileged` | `/admin/security/privileged` | الإدارة والنظام ‹ security | HOMED |
| `security.secrets` | `/admin/security/secrets` | الإدارة والنظام ‹ security | HOMED |
| `security.sessions` | `/admin/security/sessions` | الإدارة والنظام ‹ security | HOMED |
| `security.tokens` | `/admin/security/tokens` | الإدارة والنظام ‹ security | HOMED |
| `servicecosts` | `/service-costs` | المالية والمشتريات ‹ budgets | HOMED |
| `settings.edit` | `/admin/settings` | الإدارة والنظام ‹ settings | HOMED |
| `settings.export` | `/admin/settings/export` | الإدارة والنظام ‹ settings | HOMED |
| `share.file` | `/s/{token}/file` | مداخل عامّة (public) | PUBLIC |
| `share.show` | `/s/{token}` | مداخل عامّة (public) | PUBLIC |
| `sign.cert` | `/sign/{token}/certificate` | مداخل عامّة (public) | PUBLIC |
| `sign.doc` | `/sign/{token}/doc` | مداخل عامّة (public) | PUBLIC |
| `sign.pdf` | `/sign/{token}/pdf` | مداخل عامّة (public) | PUBLIC |
| `sign.show` | `/sign/{token}` | مداخل عامّة (public) | PUBLIC |
| `sign.verify` | `/verify` | مداخل عامّة (public) | PUBLIC |
| `sign.verify.doc` | `/verify/{code}/doc` | مداخل عامّة (public) | PUBLIC |
| `social.index` | `/social` | التقنية والبنية الرقمية ‹ social | HOMED |
| `staff.index` | `/staff` | الموظفون والموارد البشرية ‹ files | HOMED |
| `stations.code` | `/s/{code}` | مداخل عامّة (public) | PUBLIC |
| `stepup.show` | `/stepup` | مهامّي · سطح | GLOBAL |
| `supplierscores` | `/supplier-scores` | المالية والمشتريات ‹ procurement | HOMED |
| `support` | `/support` | العمل والتسليم ‹ support | HOMED |
| `system-map` | `/system-map` | الرئيسية · سطح | GLOBAL |
| `system.trace` | `/system/trace/{rid}` | الإدارة والنظام ‹ ops | HOMED |
| `team` | `/team` | الموظفون والموارد البشرية ‹ files | HOMED |
| `tech.workspace` | `/w/digital/tech` | التقنية والبنية الرقمية ‹ apps | HOMED |
| `trace` | `/trace/{module}/{id}` | بنية تحتية (system) | SYSTEM |
| `users.create` | `/admin/users/create` | الإدارة والنظام ‹ users | HOMED |
| `users.edit` | `/admin/users/{user}/edit` | الإدارة والنظام ‹ users | HOMED |
| `users.index` | `/admin/users` | الإدارة والنظام ‹ users | HOMED |
| `webhooks.index` | `/admin/webhooks` | الإدارة والنظام ‹ settings | HOMED |
| `webhooks.log` | `/admin/webhooks/{id}/log` | الإدارة والنظام ‹ settings | HOMED |
| `workforce.overview` | `/workforce/overview` | الموظفون والموارد البشرية ‹ workforce | HOMED |
| `workforce.team` | `/workforce` | الموظفون والموارد البشرية ‹ workforce | HOMED |
| `workspace` | `/w/{key}` | الكيانات والعلاقات | HOMED |
