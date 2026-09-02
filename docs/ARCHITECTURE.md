# خريطة معمارية المنصّة — Lynomia Business Hub

> مرجعٌ لمن يعمل على المنصّة: أين تقع كلُّ قدرة، وعلى أيّ سكّةٍ تُبنى الإضافات. الأصلُ المعماريّ
> الثابت: **سكّتان** لا ثالثَ لهما — سجلُّ الوحدات `config/hub.php` (يُولّد الشاشات والـAPI
> والتحقق والتنطيق من تعريفٍ واحد)، والمفتاحُ متعدّدُ الأشكال `(module, record_id)` الذي تعلّق
> عليه كلُّ الخدمات المشتركة (تدقيق، مرفقات، تعليقات، نسخ، إشعارات، أحداث).

## ١) الطبقات

| الطبقة | أين | ما تملكه |
|---|---|---|
| سجلّ الوحدات | `config/hub.php` (٨٢ وحدة) + `config/hub_settings.php` | الحقول وأنواعُها، المراجع، الحالات، الأعمدة، أعمدةُ العزل (`company_id`/`client_id`/`project_id`)، مفاتيحُ الإعدادات وشروحُها |
| محرّك الوحدات | `app/Http/Controllers/Web/ModuleController.php` | القائمة/النموذج/الحفظ/الحذف/الاستعادة/التصدير/الدفعات/اللوحات لكل وحدة، بحرّاسٍ واحدة: `hub_can` + `hub_scope` + `hub_field_mode` + `guardCompany` + `guardClient` |
| واجهة API | `app/Http/Controllers/Api/V1Controller.php` (يرث المحرّك) + `app/Support/Api.php` + `app/Support/OpenApi.php` | عقدُ الأخطاء الموحَّد (رموزٌ ثابتة + `request_id`)، الفرزُ بقائمةٍ بيضاء، المرشِّحاتُ الزمنية، `PATCH`، `If-Match`/`_version`، Idempotency-Key، مواصفةُ OpenAPI 3.1 المولَّدة من السجلّ (`/api/v1/openapi.json`, `hub:openapi`) |
| الدوالُّ المشتركة | `app/Support/helpers.php` | `hub_can`، `hub_scope`، `hub_company_scope`، `hub_field_mode`، `hub_ref_options_scoped`، `hub_guard_scope_input`، `hub_audit`، `hub_notify`، `hub_require_stepup` / `hub_require_credential_stepup`، `hub_outbound_ok` (حاجز SSRF/DNS)، `hub_security_incident`، `hub_schedule_failed`، `setting()` |
| الخدماتُ المشتركة | `app/Support/*` | `Audit` (سلسلة SHA-256 مختومة)، `ErrorLog` + `ErrorTaxonomy`، `Health`، `SecurityEvents`، `Sessions`، `StepUp`، `Totp`، `Webauthn`، `Devices`، `Risk`، `HubEvents` → `WebhookDispatcher` / `FlowRunner`، `Integrations` (سجلّ التكاملات وصحّتها)، `Odoo`، `Discovery\Engine`، `SysMonitor`، `Uptime`، `SchemaGuard` |
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

- **جداولُ الوحدات** (٨٢): كلٌّ بأعمدةٍ من سجلّها؛ الحذفُ ناعم؛ `company_id`/`client_id`/`project_id` حيث يُعزَل.
- **جداولُ المنصّة**: `audits` + `audit_chain` (سلسلةٌ مختومة، `request_id` للربط)، `record_versions`، `attachments`، `comments`، `notifications_hub`، `outbox`، `webhook_deliveries`، `inbound_hook_events`، `error_events` (تصنيفٌ + شدّة + بصمةٌ + إصدار)، `sessions_log`، `access_denials`، `api_tokens` + `api_usage`، `metric_points`، `settings`، `idempotency_keys`، `record_identifiers` + `identity_lookups`.
- **الهجرات**: إضافيةٌ فقط (١٧٢ ملفاً)، محروسةٌ بـ`hasTable/hasColumn`؛ `hub:schema-check` يقارن السجلَّ بالقاعدة.
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

نموذجُ الصحّة (`App\Support\Health`): `live` (العملية حيّة) / `ready` (db, cache, storage, migrations, config)
/ `check` (الكامل: + المجدولات + التكاملات + الأمن) — بحالاتٍ خمس (`HEALTHY, DEGRADED, UNAVAILABLE, MAINTENANCE, UNKNOWN`)
تُعرض في مركز التشغيل وتُقرأ من `/healthz?probe=live|ready`.

الويبهوك الصادر: `WebhookDispatcher` — ٤xx دائمٌ يفشل فوراً، 408/425/429/5xx تُعاد بسلّم `BACKOFF` (بارتعاش) مع
احترام `Retry-After`، وبعد ١٠ فشلٍ متتالٍ يُوقَف الاشتراك ساعة. التسليماتُ تحمل `request_id` للربط.

## ٦) التكاملات

- **Odoo** (`App\Support\Odoo`): XML-RPC بقاطعِ دارةٍ ونبضةِ صحّة (`Integrations::pulse`).
- **الويبهوك** الصادر/الوارد (`webhooks`, `inbound_hooks`): توقيعُ HMAC، `event_id` لمنع التكرار، دورةُ حياةٍ مدوَّنة.
- **المراسلة**: بريد/تلجرام عبر `outbox` (لا إرسالَ مباشرٌ من الطلب إلا زرُّ الاختبار).
- **الاستكشاف** (`Discovery\Engine`): مزوّدو الباركود (UPCitemdb, OpenFoodFacts, OpenLibrary) بكاشٍ ٣٠ يوماً **للحاسم فقط**.
- **n8n**: رابطٌ ومفتاح من مركز التكامل.
- **الصحّة**: `Integrations::health()` لكل تكامل (`ok/degraded/down/unknown/off`) + آخرُ نجاح/فشل — تُعرض في مركز التكامل.

## ٧) الملاحظة والتشغيل

- `request_id` واحد عبر: الترويسة `X-Request-Id`، سياقُ السجل (`Log::withContext`)، `audits.request_id`، `outbox.request_id`، `webhook_deliveries.request_id`، `notifications_hub.request_id`، وصفحاتُ الأخطاء (يقرؤه المستخدم ليُبلّغ به).
- قناةُ سجلٍّ JSON (`config/logging.php: json`) للأدوات الخارجية.
- مركزُ الأخطاء: تجميعٌ بالبصمة، تصنيفٌ (`ErrorTaxonomy`)، شدّة، إصدار، مسار، عددُ المستخدمين المتأثّرين؛ الإشعارُ محدودٌ بنافذة ١٥ دقيقة، وبلاغاتُ المتصفّح لا تُشعر ولها سقفٌ يوميّ.
- كتيّباتُ التشغيل: `docs/RUNBOOKS.md` تُعرض داخل مركز التشغيل (`/admin/ops/runbooks`).

## ٨) أين تضيف ماذا (بلا تكرار)

| تريد… | السكّة |
|---|---|
| وحدةً جديدة | مدخلٌ في `config/hub.php` + هجرةٌ إضافية — لا Controller ولا View |
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
