# خريطة معمارية المنصّة — Lynomia Business Hub

> مرجعٌ لمن يعمل على المنصّة: أين تقع كلُّ قدرة، وعلى أيّ سكّةٍ تُبنى الإضافات. الأصلُ المعماريّ
> الثابت: **سكّتان** لا ثالثَ لهما — سجلُّ الوحدات `config/hub.php` (يُولّد الشاشات والـAPI
> والتحقق والتنطيق من تعريفٍ واحد)، والمفتاحُ متعدّدُ الأشكال `(module, record_id)` الذي تعلّق
> عليه كلُّ الخدمات المشتركة (تدقيق، مرفقات، تعليقات، نسخ، إشعارات، أحداث).
>
> **حُدِّثت في v2.603.6** بعد إعادة التنظيم (`docs/REORG_PLAN.md`): الأصنافُ في نطاقاتها، والسجلُّ
> والمساراتُ مقسومةٌ ملفّاتٍ، والمتحكّمان الأكبران يفوّضان إلى خدمات — **والسلوكُ لم يتغيّر**.

## ٠) خريطةُ المجلّدات

| المجلّد | ما فيه |
|---|---|
| `config/hub.php` + `resources/registry/modules/*.php` | سجلُّ الوحدات: ملفٌّ لكلِّ وحدة (٨٥)، تُحمَّل **بقائمةٍ صريحةٍ مرتّبة** في `config/hub.php` — الترتيبُ دلاليّ (القوائم والتنقّل). خارجَ `config/` عمداً لأنّ Laravel يحمّل كلَّ ما تحته مفتاحاً |
| `routes/web.php` + `routes/web/*.php` | مساراتُ الويب: الملفُّ الأمّ يُضمِّن ١٤ ملفّاً بالنطاق (`workspace` · `workforce` · `ask` · `assets` · `identity` · `collaboration` · `insights` · `odoo` · `profile` · `admin` · `ai-center` · `admin-platform` · `modules` · `control-plane`) **كلٌّ في موضعه** من مجموعة `auth` — فالترتيبُ الذي يحكم المطابقةَ محفوظ |
| `app/Http/Controllers/{Web,Api}` | المتحكّمات: رقيقةٌ حيث أمكن، والمنطقُ المشترك في `app/Support` |
| `app/Support/<النطاق>/` | المنطقُ بالنطاق — لا صنفَ مسطّحاً تحت `app/Support` (الجدول التالي) |
| `app/Support/helpers.php` | قلبُ الأمن بدوالّه العامّة (`hub_can` · `hub_scope` · `hub_field_mode` · `hub_audit` …) وأغلفةٌ من سطرٍ لمحرّكاتٍ صارت أصنافاً |
| `tools/` | أدواتُ النقل الآليّ التي بُنيت بها إعادةُ التنظيم (`move-classes` · `extract-helpers` · `extract-methods`) |
| `tests/Fixtures/structure/` | لقطاتُ البنية (المسارات · السجلّ · الأصناف · الدوالّ) — `php artisan hub:structure-snapshot --write` بعد تغييرٍ مقصود |

| النطاق (`App\Support\…`) | ما يملكه |
|---|---|
| `Ai` | مركزُ الذكاء: `Gateway` (LiteLLM) · `Governance` (السياسة والميزانية والدفتر) · `Routing` (الأغراض والملفّات) · `Catalog` · `Center` · `Ask` (اسأل المنصّة) · `Auditor` (المدقّق) · `GovernedCompletion` (البابُ الوحيد لنداء النموذج) |
| `Platform` | السكّةُ المشتركة: `Api` · `OpenApi` · `Audit` · `Settings` · `HubEvents` · `FlowRunner` · `Redactor` · `TimeRange` · `Severity` · `InformationArchitecture` · `StructureSnapshot` · و`Modules\*` (منطقُ محرّك الوحدات) |
| `Security` | المصادقةُ والجلسات والأجهزة وWebAuthn وTOTP والتصعيد والوضعُ الأمنيّ ونتائجُه |
| `Ops` | الصحّةُ والمراقبة والأخطاء والتنبيه والويبهوك والتكاملات وOdoo وتصنيفُ الحالة |
| `Insights` | مركزُ الفعل والتوصيات والمؤشّرات والـOKR وجودةُ البيانات والربحيّة ورادارُ الانتهاء |
| `Workforce` | يومُ العمل والحضور والتقارير اليومية ومراجعتُها وإحصاءاتُ التنفيذ وملفُّ الموظّف |
| `Documents` | العروضُ والعقود وقوالبُها والتصييرُ PDF والباركود/QR والعلامةُ المائيّة، و`Esign\EsignFinalizer` (إتمامُ التوقيع) |
| `Collaboration` | المحادثاتُ والرسائلُ المباشرة والتعليقاتُ والمرفقات والحضورُ والكتابة وبوّابةُ العميل |
| `Assets` · `Finance` · `Apps` | الأصولُ والعهد · القيودُ والتسعير والعملة · استوديو التطبيقات والتسليم |
| `Mobile` · `Push` · `Endpoint` · `Mdm` · `Discovery` | سطحُ الجوال (`/api/mobile/v1`) · مزوّدو الدفع · الأجهزةُ المُدارة · مزوّدو MDM · مزوّدو الباركود |

## ١) الطبقات

| الطبقة | أين | ما تملكه |
|---|---|---|
| سجلّ الوحدات | `config/hub.php` (٨٥ وحدة، كلٌّ في ملفّها تحت `resources/registry/modules/`) + `config/hub_settings.php` | الحقول وأنواعُها، المراجع، الحالات، الأعمدة، أعمدةُ العزل (`company_id`/`client_id`/`project_id`)، مفاتيحُ الإعدادات وشروحُها |
| محرّك الوحدات | `app/Http/Controllers/Web/ModuleController.php` — يفوّض البحثَ والتصفية والتصديرَ والتحقّقَ وحرّاسَ التنطيق وإبطالَ المشتقّ إلى `app/Support/Platform/Modules/*` | القائمة/النموذج/الحفظ/الحذف/الاستعادة/التصدير/الدفعات/اللوحات لكل وحدة، بحرّاسٍ واحدة: `hub_can` + `hub_scope` + `hub_field_mode` + `guardCompany` + `guardClient` |
| واجهة API | `app/Http/Controllers/Api/V1Controller.php` (يرث المحرّك) + `app/Support/Platform/Api.php` + `app/Support/Platform/OpenApi.php` | عقدُ الأخطاء الموحَّد (رموزٌ ثابتة + `request_id`)، الفرزُ بقائمةٍ بيضاء، المرشِّحاتُ الزمنية، `PATCH`، `If-Match`/`_version`، Idempotency-Key، مواصفةُ OpenAPI 3.1 المولَّدة من السجلّ (`/api/v1/openapi.json`, `hub:openapi`) |
| الدوالُّ المشتركة | `app/Support/helpers.php` | `hub_can`، `hub_scope`، `hub_company_scope`، `hub_field_mode`، `hub_ref_options_scoped`، `hub_guard_scope_input`، `hub_audit`، `hub_notify`، `hub_require_stepup` / `hub_require_credential_stepup`، `hub_outbound_ok` (حاجز SSRF/DNS)، `hub_security_incident`، `hub_schedule_failed`، `setting()` |
| الخدماتُ المشتركة | `app/Support/<النطاق>/*` (§٠) | `Audit` (سلسلة SHA-256 مختومة)، `ErrorLog` + `ErrorTaxonomy`، `Health`، `SecurityEvents`، `Sessions`، `StepUp`، `Totp`، `Webauthn`، `Devices`، `Risk`، `HubEvents` → `WebhookDispatcher` / `FlowRunner`، `Integrations` (سجلّ التكاملات وصحّتها)، `Odoo`، `Discovery\Engine`، `SysMonitor`، `Uptime`، `SchemaGuard` |
| الوسطاء | `app/Http/Middleware/*` | `SecurityHeaders` → `Observability` (X-Request-Id + سياقُ السجل) → `HubMaintenance` → `SessionSentry` → `WorkHours` → `TrackVisits` → `Require2faForPrivileged` → `AccessRadar`؛ وللـAPI: `ApiAuth` (رموز `lyn_`، نطاقات، IP، انتهاء، عدّاداتُ الاستخدام) |
| العملُ الخلفيّ | `routes/console.php` + `app/Console/Commands/*` | بلا عامل طوابير (`QUEUE_CONNECTION=sync`): كلُّ عملٍ مؤجَّل يمرّ بجداول (`outbox`، `webhook_deliveries`) ويُصرَف بأوامرَ مجدولة بنبضاتٍ (`heartbeat.<job>`) وخطّافِ فشلٍ (`onFailure` → مركز الأخطاء + حادثة عند فشل فحص السلسلة) |
| الواجهة | `resources/views/*` (Blade, RTL, HTMX) | شاشاتُ الوحدات المولَّدة (`modules/*`)، مراكزُ الإدارة (`ops/`, `security/`, `integrations/`, `settings/`, `errors/`)، صفحاتُ الأخطاء العربية (`errors/<code>.blade.php`) |

## ٢) مسارُ الطلب

```
Request → SecurityHeaders → Observability(request_id, Log::withContext) → HubMaintenance
        → auth → SessionSentry(الجلسة مُنهاة؟ IP مسموح؟) → WorkHours → TrackVisits
        → Require2faForPrivileged → AccessRadar(رصد الرفض) → Controller
Controller(ModuleController) → hub_can(module, verb) → hub_scope(query) → hub_field_mode(mask)
        → validate(من السجلّ) → guardCompany/guardClient → Model(Auditable::writeAudit)
        → HubEvents::fire(module.event) → WebhookDispatcher::queue + FlowRunner::run
Exception → bootstrap/app.php → Api::render (api/*) | صفحةُ خطأ عربية (web) ← ErrorLog::capture(taxonomy)
```

الاستثناءاتُ المتعمّدة من وسطاء الجلسة: `GET /healthz` (مسبارُ الصحّة) — يجيب JSON دائماً، وحالةُ
الصيانة/القفل عنده **حالةٌ** (`MAINTENANCE`) لا عطل.

## ٣) طبقةُ البيانات

- **جداولُ الوحدات** (٨٥): كلٌّ بأعمدةٍ من سجلّها؛ الحذفُ ناعم؛ `company_id`/`client_id`/`project_id` حيث يُعزَل.
- **جداولُ المنصّة**: `audits` + `audit_chain` (سلسلةٌ مختومة، `request_id` للربط)، `record_versions`، `attachments`، `comments`، `notifications_hub`، `outbox`، `webhook_deliveries`، `inbound_hook_events`، `error_events` (تصنيفٌ + شدّة + بصمةٌ + إصدار)، `sessions_log`، `access_denials`، `api_tokens` + `api_usage`، `metric_points`، `settings`، `idempotency_keys`، `record_identifiers` + `identity_lookups`.
- **الهجرات**: إضافيةٌ فقط (٢٦٣ ملفاً)، محروسةٌ بـ`hasTable/hasColumn`؛ `hub:schema-check` يقارن السجلَّ بالقاعدة.
- **النسخُ الاحتياطي**: `hub:backup` ينسخ جداولَ الوحدات + `RAW_TABLES` (الأتمتة والاعتماد والتوقيع والسلاسل) — وكلُّ جدولٍ إمّا منسوخٌ أو مُعلَنٌ في `HubBackup::EPHEMERAL` (يحرسه اختبارٌ).

## ٤) نموذجُ الأمان

| القدرة | الآلية | الاختبارُ الحارس |
|---|---|---|
| الصلاحية | مصفوفةُ الدور `matrix[module][v/a/e/d]` + أعلام (`exp`…) | `hub_can` في كل مسار؛ حزمةُ `*Authz*`/`*Round*` |
| النطاق | `role.scope` (all/proj/own…) + `users.companies` + `users.clients` → `hub_scope` + `hub_company_scope` | `EnterpriseHardeningRound1Test` (دمج/تسجيل/عملاء/قوائم) |
| قناعُ الحقول | `role.field_rules[module][field] = hide|ro` → `hub_field_mode` | `FieldRules*` |
| المصادقة | كلمة مرور + قفلٌ بعد ٥ محاولات، TOTP، مفاتيحُ مرور (WebAuthn)، أجهزةٌ موثوقة، «تذكّرني» مدوَّر | `Auth*`, `Webauthn*` |
| التصعيد | `StepUp` (نافذةٌ في الجلسة) قبل: القفل، الأسرار، التصدير الكبير، **وسكّ الاعتماد** (`security.stepup_credentials`) | `EnterpriseHardeningRound1Test::test_minting_credentials_requires_step_up` |
| سياسةُ ٢FA | `auth.2fa_required_priv` → `Require2faForPrivileged` (ويبٌ وJSON سواء: 428) | `test_2fa_policy_is_not_bypassed_by_json_accept` |
| الطوارئ | `security.lockdown`، `security.freeze_exports`، `security.freeze_tokens`، `maintenance.on` | `Security*`, `HealthModelTest` |
| الأثر | `hub_audit` + `Auditable` + `AUDIT_SECRET` (بصمةٌ لا نصّ) + `SecurityEvents` (تصنيفٌ قانونيّ: LOGIN_FAILED, MFA_ENABLED, PASSWORD_CHANGE, ROLE_CHANGED, INTEGRATION_CHANGED…) | `SecurityEventsTest`, `AuditRound*` |
| الخروج | `hub_outbound_ok` (منعُ العناوين الخاصة، تثبيتُ DNS) لكل نداءٍ خارجيّ | `DiscoveryTest`, `Monitor*` |

## ٥) العملُ الخلفيّ والموثوقية

| الأمر | الجدولة | النبضة | عند الفشل |
|---|---|---|---|
| `hub:outbox` | كل ٥ دقائق | `heartbeat.outbox` | `hub_schedule_failed` → مركز الأخطاء + إشعارُ المالكين |
| `hub:uptime-check` | كل ٥ دقائق | `heartbeat.uptime` | كذلك |
| `hub:automation` | يومياً ٠٦:٠٠ | `heartbeat.automation` | كذلك — ويشمل سياسةَ الاحتفاظ (`retention.*`) |
| `hub:backup` | يومياً ٠٣:٣٠ | `heartbeat.backup` (+`recordFailure`) | كذلك (HIGH) |
| `hub:metrics-snapshot` / `hub:quality-snapshot` | يومياً ٢٣:٤٥ / ٢٣:٥٠ | `heartbeat.metrics` / `heartbeat.quality` | كذلك |
| `hub:digest` | أسبوعياً | `heartbeat.digest` | كذلك |
| `hub:audit-verify` | أسبوعياً | `heartbeat.audit` | كذلك + **حادثةٌ أمنية** |

نموذجُ الصحّة (`App\Support\Ops\Health`): `live` (العملية حيّة) / `ready` (db, cache, storage, migrations, config)
/ `check` (الكامل: + المجدولات + التكاملات + الأمن) — بحالاتٍ خمس (`HEALTHY, DEGRADED, UNAVAILABLE, MAINTENANCE, UNKNOWN`)
تُعرض في مركز التشغيل وتُقرأ من `/healthz?probe=live|ready`.

الويبهوك الصادر: `WebhookDispatcher` — ٤xx دائمٌ يفشل فوراً، 408/425/429/5xx تُعاد بسلّم `BACKOFF` (بارتعاش) مع
احترام `Retry-After`، وبعد ١٠ فشلٍ متتالٍ يُوقَف الاشتراك ساعة. التسليماتُ تحمل `request_id` للربط.

## ٦) التكاملات

- **Odoo** (`App\Support\Ops\Odoo`): XML-RPC بقاطعِ دارةٍ ونبضةِ صحّة (`Integrations::pulse`).
- **الويبهوك** الصادر/الوارد (`webhooks`, `inbound_hooks`): توقيعُ HMAC، `event_id` لمنع التكرار، دورةُ حياةٍ مدوَّنة.
- **المراسلة**: بريد/تلجرام عبر `outbox` (لا إرسالَ مباشرٌ من الطلب إلا زرُّ الاختبار).
- **الاستكشاف** (`Discovery\Engine`): مزوّدو الباركود (UPCitemdb, OpenFoodFacts, OpenLibrary) بكاشٍ ٣٠ يوماً **للحاسم فقط**.
- **n8n**: رابطٌ ومفتاح من مركز التكامل.
- **الصحّة**: `Integrations::installed()` + `Integrations::judge()` لكل تكامل (`ok/degraded/down/unknown/off`) + آخرُ نجاح/فشل — تُعرض في مركز التكامل.

## ٧) الملاحظة والتشغيل

- `request_id` واحد عبر: الترويسة `X-Request-Id`، سياقُ السجل (`Log::withContext`)، `audits.request_id`، `outbox.request_id`، `webhook_deliveries.request_id`، `notifications_hub.request_id`، وصفحاتُ الأخطاء (يقرؤه المستخدم ليُبلّغ به).
- قناةُ سجلٍّ JSON (`config/logging.php: json`) للأدوات الخارجية.
- مركزُ الأخطاء: تجميعٌ بالبصمة، تصنيفٌ (`ErrorTaxonomy`)، شدّة، إصدار، مسار، عددُ المستخدمين المتأثّرين؛ الإشعارُ محدودٌ بنافذة ١٥ دقيقة، وبلاغاتُ المتصفّح لا تُشعر ولها سقفٌ يوميّ.
- كتيّباتُ التشغيل: `docs/RUNBOOKS.md` تُعرض داخل مركز التشغيل (`/admin/ops/runbooks`).

## ٨) أين تضيف ماذا (بلا تكرار)

| تريد… | السكّة |
|---|---|
| وحدةً جديدة | ملفٌّ في `resources/registry/modules/<key>.php` + سطرٌ في موضعه من قائمة `config/hub.php` + هجرةٌ إضافية — لا Controller ولا View |
| نداءً لنموذج ذكاء | `GovernedCompletion::authorize()` ثم `open()` ثم `call()` — لا `AiChat::complete` مباشرةً (يحرسه `GovernedCompletionTest`) |
| مساراتٍ لنطاقٍ قائم | ملفُّ نطاقه تحت `routes/web/` — وملفٌّ جديد يُضمَّن في موضعه من `routes/web.php` |
| مسارَ قراءةٍ مخصّصاً | `hub_scope` + `hub_can` + `hub_field_mode` قبل الاستعلام، و`hub_ref_options_scoped` للقوائم |
| مسارَ كتابةٍ مخصّصاً (خارج المحرّك) | `hub_guard_scope_input($data, [...])` + `Auditable` أو `hub_audit` |
| فعلاً أمنيّاً | `hub_audit` بفعلٍ يعرفه `SecurityEvents::CODES` (أو أضِف رمزاً هناك) |
| اعتماداً طويلَ الأمد | `hub_require_credential_stepup()` قبل السكّ |
| نداءً خارجيّاً | `hub_outbound_ok($url)` ثم `Http::withOptions(['curl' => hub_resolve_pin(...)])` |
| أمراً مجدولاً | `Schedule::command(...)->withoutOverlapping()->onFailure(fn () => hub_schedule_failed(...))` + `Health::beat('<job>')` + مدخلٌ في `Health::JOBS` |
| إعداداً | `config/hub_settings.php` (مدخلٌ أو إعلانٌ داخليّ) — يحرسه `SettingsCenterTest` |
| خطأً للعميل | `Api::abort(code, status, message)` أو `abort(<code>)` مع صفحةٍ عربية في `errors/` |

## ٩) الثوابت (لا تُخالف)

1. **الإضافةُ لا الكسر**: لا حذفَ مسار، ولا تغييرَ عقد، ولا هجرةَ مدمِّرة.
2. **الحزمةُ خضراء على المحرّكين** (SQLite وMySQL) قبل أيّ دفعة، و`VERSION` تُرفع مع كل دفعة.
3. **كلُّ عيبٍ أمنيّ اختبارٌ يفشل أولاً** ثم يُصلَح.
4. **الترتيبُ صريح** (`orderBy(...)->orderBy('id')`) — لا قرعةَ صفوف.
5. **لا سرَّ في السجلّات**: `AUDIT_SECRET`، بصماتٌ للقيم المشفَّرة، لا تتبّعَ مكدّسٍ للمستخدم.
6. **البنيةُ لا تتغيّر صامتةً**: لقطاتُ المسارات والسجلّ والأصناف والدوالّ (`StructureSnapshotTest`) تُسقط أيَّ فقدٍ أو إضافةٍ بلا لقطة.

## مستوى التحكّم المؤسسي (Control Plane) — الطور الأول (v2.401)

أعمدةٌ مشتركة تستهلكها كلُّ مراكز التحكّم اللاحقة ولا تُبنى مرّتين:

| العمود | الواجهة | الملف |
|---|---|---|
| المدى الزمنيّ الموحّد | `TimeRange::fromRequest()` / `->prev()` / `->apply($q,$col)` + `hub_range()` + `partials/timerange` | `app/Support/Platform/TimeRange.php` |
| خرائطُ الشدّة والحالة | `Severity::normalize/label/tone/rank` · `OpStatus::fromHealth` · `IssueState::MAP` — خرائطُ عرضٍ فوق المفردات القائمة، لا تحويلَ مخزَّن | `app/Support/Platform/Severity.php` · `Platform/IssueState.php` · `Ops/OpStatus.php` |
| المُطهِّر الواحد | `Redactor::text/arr/fingerprint/sql/json` — تُفوَّض إليه السبعُ القائمة (ErrorLog، Health::safe، Integrations::pulse، HubOutbox، WebhookDispatcher، SecurityRadar، InboundHook) | `app/Support/Platform/Redactor.php` |
| الترابط | `X-Request-Id` وحدَه (`Api::requestId/requestSource/requestIdIsExternal`)؛ قارئ `Correlation::forRequestId` وصفحة `system/trace/{rid}` (مالك أو علم `audit` منطَّقاً) | `app/Support/Ops/Correlation.php` |
| عُدّةُ مركز التحكّم | `partials/cc/{kpis,trend,findings,freshness,tabs,th}` + `hub_admin_links()` + `hub_screen(..., stamped:)` | `resources/views/partials/cc/` |
| أوّليّاتُ القياس | `hub_metric_bucket` · `hub_window_pair` · `hub_compare` (pct=null عند أساسٍ صفريّ) · `Series::percentiles/mergeHist` — و`metric_points` يبقى مخزنَ التاريخ الوحيد | `app/Support/Ops/Series.php` |

وحاجزٌ أمنيّ أُغلق قبل البناء: التصدير الجَماعيّ يمرّ بحزام `export()` نفسِه (تجميد/تصعيد/أثر)، وتقليمُ الاحتفاظ لا يحذف أدلّةً غيرَ محلولة عاليةَ الشدّة ويكتب أثرَ ما حذف. التقريران الكاملان: `docs/control-plane/DISCOVERY.md` (تصنيفُ ١٥٢ بنداً بالأدلة) و`PLAN.md` (٤١ حزمةَ عملٍ للأطوار ١–١٠ + ٤٠ تفنيداً).

## المراكزُ السبعة وقارئوها — الأطوار ٢–١٠

> **الوثيقةُ الكاملة: `docs/CONTROL_PLANE.md`** — ثمانيةُ موضوعات spec §38 (البنية · الاحتفاظ ·
> الحدث/النتيجة/الحادثة · الشدّة · SLO · الترابط · التنقية · تصدير الإعدادات) + نقاطُ الامتداد (§28).
> ما هنا خريطةٌ مختصرة: أينَ يقع كلُّ مركز، ومن **يملك** أرقامَه.

| المركز | الشاشة (اسمُ المسار) | القارئُ المالك للأرقام | جديدُه في القاعدة |
|---|---|---|---|
| التشغيل | `ops.index` · `ops.health` | `Health::check` · `SysMonitor` · `Series::percentiles` (مدرَّجٌ لوغاريتميّ) | `http_metric_buckets` |
| الأخطاء | `errors.index` · `errors.show` · `errors.logs` | `ErrorStats` · `ErrorTaxonomy` · `ErrorLog::capture` | `error_occurrences` + دورةُ حياةٍ على `error_events` |
| الأمن | `security.index` · `security.findings` · `security.identity` · `security.privileged` · `security.sessions` · `security.tokens` · `security.secrets` · `security.event` | `SecurityPosture` · `SecurityFindings` · `IdentityRisk` · `SecurityEvents` | `security_findings` |
| التدقيق | `audit.index` · `audit.show` · `audit.coverage` | `Audit::scopedQuery` · `Audit::verifyTail` | `audit_verifications` + أعمدةُ تصنيفٍ **خارج** الختم |
| الحوادثُ والتنبيه | `m.index` (‏`incidents`) · `alerts.center` · `incidents.link` | `AlertEngine` (‏`daily` + `evaluate` كلَّ ٥ د) | `alert_instances` · `incident_links` |
| الجودةُ والتنفيذ | `quality.index` · `workforce.overview` | `DataQuality` · `ExecutionStats` (‏`org`/`person` — عدّادٌ واحد بإسقاطات) · `KpiCentre` · `OkrCentre` | — (نقاطٌ في `metric_points`) |
| تهيئةُ النظام | `settings.edit` · `settings.preview` · `settings.restore` · `settings.export` · `settings.import` | `Settings` (‏`effective` · `put` — الكاتبُ الواحد) | `setting_changes` |

وفوقها مستوى القيادة: `control.index` (‏`ControlController` — ستُّ بطاقاتٍ قارئةٍ فقط تحيل ولا تحسب)
و`AttentionQueue::items` (صفُّ «يستدعي تدخّلك») يُدمج في `ActionCenter::signals` القائم ويُتصرَّف به
على `recs.act` — بلا مخزنِ إقرارٍ ثالث. والقاعدةُ في كلّ ما سبق: **لا رقمَ يُعاد حسابُه خارج مالكه**،
وما لا قياسَ له يُعرَض «—» لا صفراً.

وثلاثةُ مجدولاتٍ جديدة بنبضاتها في `Health::JOBS`: `hub:ops-snapshot` (كل ٥ د) ·
`hub:alerts-evaluate` (كل ٥ د) · `hub:security-snapshot` (يومياً).

## مركزُ الذكاء والمدقّق (v2.559 → v2.603)

> **الوثائق:** `docs/ai-hub/01-architecture.md` (البنية) · `docs/ai-hub/46-ai-roadmap.md` (الخطّةُ وقراراتُ المالك).

- **البوّابة:** LiteLLM خلف `Ai\Gateway`؛ وكلُّ نداءِ نموذجٍ يمرّ بـ`GovernedCompletion` — تفويضٌ (`AiGovernance`:
  السياسة والميزانية بحجزٍ مسبق) ثم دفترُ استهلاك (`ai_usage_events`) لكلِّ نداء، وسقفُ نداءاتٍ ومخرجاتٍ للجلسة.
- **اسأل المنصّة** (`Ai\Ask`): قراءةٌ فقط بستِّ أدوات (الخمسُ على سجلِّ الوحدات، و`hub_findings` على
  ملاحظات المدقّق عبر `AuditorSignals` نفسِه) وسابعةٍ `hub_semantic` حين يكون العقلُ الثاني جاهزاً، وذاكرةُ محادثةٍ لصاحبها وحدَه (`AskMemory`: مشفَّرة،
  وجوابُها يُعاد تحقّقُه عند كلِّ عرض، والمتابعةُ بالأسئلة لا بالأجوبة)، ومتاحٌ للجوال (`/api/mobile/v1/ask*`)، كلٌّ منها يمرّ بـ`hub_scope` + `hub_can` + `hub_field_mode`؛
  `WRITE_TOOLS = []`.
- **المساعدُ التنفيذيّ** (`Ai\Assist\DraftAssistant`): «مسودةٌ ثمّ تأكيد» — يقرأ السجلَّ بعين السائل ويقترح
  حقولاً بقائمةٍ بيضاء ويفتح نموذجَ الإنشاء معبّأً؛ الحفظُ فعلُ السائل عبر `ModuleController`. لا يكتب شيئاً.
- **العقلُ الثاني** (`Ai\Brain`): فهرسُ تضميناتٍ خلف `VectorStore` (لا نصَّ مخزَّناً — المقطعُ يُعاد قراءتُه من
  سجلّه)، والبحثُ يُرشَّح بـ`AskTools::visibleIds` ورؤيةِ الحقل قبل أن يبلغ أحداً؛ أداةُ `hub_semantic` في «اسأل Hub»
  تُعلَن فقط حين يكون جاهزاً (`brain.enabled` مطفأٌ افتراضاً). الفهرسةُ `hub:brain`.
- **المدقّق** (`Ai\Auditor`): هويّةُ خدمةٍ في الذاكرة لا تُحفظ، وكواشفُ قواعد (نسخُ التقرير · العائقُ المتكرّر ·
  ساعاتٌ بلا تقدّم · قرارٌ بلا مهمّة) وكواشفُ ذكاءٍ **مطفأةٌ افتراضاً** (`auditor.ai`). النتائجُ في `ai_findings` بمفتاحٍ
  ثابتٍ للشرط، و**يُعاد تنطيقُها لكلِّ مشاهد** قبل أن تظهر في مركز الفعل (`AuditorSignals`). لا يكتب سجلاتِ أعمال،
  والموظّفُ لا يرى حكمَه — يرى ملاحظةَ مديره فقط. وكاشفٌ يرفضه المديرون كثيراً يُطفئ نفسَه (`AuditorAccuracy`).
