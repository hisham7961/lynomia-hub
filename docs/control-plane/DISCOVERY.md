# تقرير الاستكشاف الإلزاميّ قبل البرمجة — Lynomia Enterprise Control Plane

> الفرع `claude/lynomia-hub-enterprise-upgrade-xn4p2t` · `VERSION` **2.400.3** · Laravel 12.69.1 / PHP 8.4.19 · 177 هجرة · 361 مساراً · 82 وحدةً في `config/hub.php` · 359 ملفَّ اختبارٍ (~2106 اختباراً).
>
> **وسوم التحقّق:** `RUNTIME_VERIFIED` (شُغِّل الطلبُ/الأمر ورُصد ناتجُه) · `COMMAND_VERIFIED` (أمرٌ أو grep أو PRAGMA) · `STATICALLY_REVIEWED` (قراءةُ شيفرةٍ كاملة). كلُّ قاعدةِ بياناتٍ لُمست هنا **SQLite رميّة** في `scratchpad/cp/*.sqlite`؛ لم تُمسّ `hub_test` ولا `storage/hub-local.sqlite`، ولم يُشغَّل `migrate:fresh/refresh/reset`.
>
> **قاعدةٌ حاكمة:** حيث اختلف القارئ مع المُفنِّد، الحالةُ المصحَّحة هي المعتمدة (تُعلَّم ✔ في جدول التصنيف).

---

### RUNTIME BASELINE

**كيف قِيس:** اختبارٌ مؤقّت `tests/Feature/ZzRuntimeProbeTest.php` (حُذف بعده؛ `git status --short` نظيف) مرّ على **75 مساراً** بارداً ودافئاً بحساب استعلاماتٍ عبر `DB::listen`، ثم اختبارٌ ثانٍ لمصفوفة الوصول (owner/employee/viewer) على 41 صفحة. البيئة: SQLite `:memory:`، `CACHE_STORE=array`، `Http::fake()`. الأرقامُ منقولةٌ إلى MySQL في **عدد الاستعلامات** لا في الزمن.

**١) صفحاتٌ معطوبة: لا شيء.** لم تُرجِع أيُّ صفحةٍ من الـ75 خطأ 500 للمالك (`RUNTIME_VERIFIED`). حارسان متعمَّدان فقط:
- `/admin/roles/{ownerRole}/edit` → 422 «دور المالك يتجاوز كل الصلاحيات» (`RoleController.php:202`)؛ دورٌ غيرُ مالكٍ → 200.
- `/m/users` → 404 متعمَّد (`ModuleController.php:18`) لأنّ للمستخدمين شاشتَهم `/admin/users`.

**٢) ما لا وجودَ له أصلاً (`COMMAND_VERIFIED` عبر `route:list` على 361 مساراً):** لا `/system/trace/{requestId}`، ولا صفحةَ تفصيلِ حدثِ تدقيقٍ (`audit.index` وحدَه)، ولا صفحةَ حدثٍ أمنيّ، ولا `security findings`/`posture history`/`identity risk`/`privileged review`/`token center`/`secret health`، ولا `route performance`/`SLO`/`release history`، ولا مركزَ حوادثَ خارج محرّك الوحدات (`m/incidents`)، ولا صفحةَ «Control Center Home». الاسم `trace` **محجوز** لـ`TraceController` (سلسلةُ التسليم requests→feats→tasks→changes→deploys).

**٣) صفحاتٌ ثقيلة (باردة/دافئة — استعلامات):**

| الصفحة | باردة | دافئة | السبب المُقاس |
|---|---|---|---|
| `/admin/quality?fresh=1` | 489 | 489 | `fresh` يُسقط الكاش (`DataQuality.php:232`) — 344 منها استبطانُ مخطّط |
| `/admin/quality` | 475 | 5 | `Cache::remember` 600 ثانية (`DataQuality.php:234`) |
| `/admin/ops` | 258 | 74 | 107 `Schema::hasTable` (`SysMonitor.php:132,142` + `Health.php:245-333`) |
| `/admin/activity` | 204 | 9 | 127 استبطاناً من بناء خريطة الأبناء (`helpers.php:955-967` rememberForever) |
| `/alerts?fresh=1` | 188 | 188 | مشيُ `hub_expiry_fields` + `DATE()` غيرُ قابلٍ للفهرسة |
| `/m/incidents/{id}` | 179 | 14 | 164 استبطاناً (أول بناءٍ لخريطة الأبناء) |
| `/ceo` | 119 | 30 | 18 `SUM(fin_documents)` |
| `/search?q=…` | 84 | 84 | 81 `COUNT LIKE` لكل وحدةٍ مرئية — **أبطأ صفحة (93 مللي)** |
| `/admin/security` | 79 | 75 | 20 `hasTable` + عدّادات users/audits/access_denials |
| `/healthz` · `/admin/ops/health` | 55 · 56 | 55 | `Health::check` كامل بلا كاش |

**السببُ الجذريّ الأول:** 50–92٪ من استعلامات الصفحات الثقيلة **استبطانُ مخطّط** (`hasTable/hasColumn/getColumnListing`) — يطابق `TECH_DEBT #25 (PERF-05/08)`. **الثاني:** تجميعٌ في PHP بدل SQL: `AuditController::pulse` يحمّل صفوفَ اليوم كلَّها (`:96`)، `SysMonitor::pulse` يحمّل كلَّ `page_visits` و`error_events` لـ24 ساعة (`:190-215`)، `SecurityEvents::counts` يجلب حتى 6000 صفٍّ ويصنّف في PHP (`:166-172`)، `hub_uptime` يقرأ 30 يوماً من النقاط. **الثالث:** N+1 لكل كِيان: `/performance` ثلاثةُ استعلاماتٍ لكل موظف، `/admin/activity/{id}` عدُّ تدقيقٍ لكل يومٍ من 14.

**٤) مصفوفة الوصول (`RUNTIME_VERIFIED`):** employee وviewer يُمنعان 403 من كل `/admin/*` ومن `/kpis /performance /ceo /app-quality /capacity`؛ ويصلان 200 إلى `/alerts /okrs /search /staff /team /trace /workforce /healthz /my/security /morning /m/incidents`. البوّابات: `hub_is_owner` (ops/errors/security/activity/quality/flows/integrations/settings/roles/webhooks)، `hub_flag('audit')`، `hub_flag('users')`، `hub_monitor` (performance/kpis/capacity)، `hub_can` (alerts/staff/team/workday/okrs).

**٥) المجدولات (`COMMAND_VERIFIED` `schedule:list` — ثمانية):** `hub:automation` 06:00 · `hub:outbox` كل 5د · `hub:backup` 03:30 · `hub:digest` سبت 07:00 · `hub:metrics-snapshot` 23:45 · `hub:uptime-check` كل 5د · `hub:quality-snapshot` 23:50 · `hub:audit-verify` أحد 04:30. كلٌّ منها `withoutOverlapping` + `onFailure(hub_schedule_failed)` + `Health::beat`. **النبضُ يُخزَّن في `settings` (`heartbeat.<job>` + `.meta{ms,result,note}`) — آخرُ تشغيلٍ فقط، بلا تاريخِ تشغيلات** (`Health.php:363-375`). عتباتُ التأخّر/الموت مثبَّتةٌ في `Health::JOBS` لا في الإعدادات.

**٦) أوامرُ التحقّق (كلُّها exit 0 على قاعدةٍ رميّة):** `hub:schema-check` → «القاعدة تطابق ما يقرؤه الكود»؛ `hub:audit-verify` → «لا سجلات مسلسلة بعد»؛ `hub:uptime-check`/`hub:metrics-snapshot`/`hub:quality-snapshot`/`hub:digest` تعمل وتُبقي نتائجَها في `metric_points`/`settings`. إجماليّاً **18 أمرَ `hub:`**.

---

### EXISTING SYSTEMS TO REUSE

> القاعدة: كلُّ ما تحت هذا العنوان **يُوسَّع لا يُستبدَل**. ما لم يُذكر هنا وله بديلٌ في «DUPLICATION RISKS» فهو تكرارٌ محظور.

#### أ) السكك العابرة (Global rails)

| السكّة | الشيفرة | ما تعطيه |
|---|---|---|
| ارتباطُ الطلب `X-Request-Id` | `Observability.php:18-41` (توليد/قبولُ معرّفِ العميل بنمط `^[A-Za-z0-9][A-Za-z0-9._:-]{7,63}$` + `Log::withContext` + ترويسةُ الردّ)، `Api::requestId()` `Api.php:80-95` | معرّفٌ واحدٌ للطلب يُكتب تلقائياً في `audits` (`Auditable.php:104`, `helpers.php:2525`)، `outbox`, `webhook_deliveries`, `notifications_hub`, `error_events` |
| التدقيق المختوم | `Auditable` (`app/Traits/Auditable.php:12-107`)، `AuditEntry` (سلسلةُ SHA-256، `:52-160`)، `Audit::diff` (`app/Support/Audit.php:31-79`)، `Audit::verifyTail` (`:88-145`)، `hub:audit-verify` | إضافة/تعديل/حذف مختومةٌ لكل الوحدات الـ82 + فرقٌ مقنَّعٌ بالصلاحيات + تحقّقُ ذيلٍ لكل تحميلِ صفحة |
| تصنيفُ الأحداث الأمنية | `SecurityEvents::CODES` (36 رمزاً) `SecurityEvents.php:27-105`، `recent()/counts()` `:119-172` | خريطةُ ~60 نصّاً عربياً → رمز + شدّة، وسجلٌّ موحّد فوق `audits`+`access_denials` بلا جدولٍ جديد |
| تصنيفُ الأخطاء | `ErrorTaxonomy` (`:15-113` تصنيف/شدّة/بصمة)، `ErrorLog::capture/bump/tell` (`:32-206`) | بصمةٌ ودمجٌ وتنبيهٌ بسقفِ عاصفة (8/15د) وإعادةُ فتحٍ عند العودة |
| الصحّة | `Health::check/JOBS/beat/watchdog` (`Health.php:25-430`) + `/healthz` + `ops.health` | 12 مكوِّناً بخمس حالات + نبضاتٌ + كلبُ حراسةٍ يومي |
| الشدّة والوضع البصريّ | `Health::TONE`، `SecurityEvents::SEVERITY_TONE`، `hub_tone()` `helpers.php:896`، أصنافُ `ok/wn/bad/g` | نغمةٌ واحدةٌ للواجهة (لا يوجد رمزُ `info` — انظر UI) |
| التنطيق والصلاحية | `hub_scope` · `hub_can` · `hub_field_mode` · `hub_company_ids` · `hub_client_ids` · `hub_read` · `hub_open_scope`/`hub_closed_states` (`helpers.php:2270-2365`) | كلُّ قارئٍ جديد يمرّ بها |
| التصعيد الأمني | `hub_require_stepup` / `hub_require_ops_stepup` / `hub_require_credential_stepup` (`helpers.php:606-717`) + `StepUp` | نافذةُ تأكيدٍ للأفعال الخطرة |
| الخروجُ الآمن | `hub_outbound_ok` + `hub_resolve_pin` (`helpers.php:4703-4711`) | حارسُ SSRF وتثبيتُ DNS لكل نداءٍ خارجيّ |
| السلاسلُ الزمنية | `metric_points` (فريد `module,record_id,metric,at`) + `hub_metric_put/series/latest/growth/spark` (`helpers.php:4208-4337`) | مخزَنُ **كلِّ** لقطةٍ تاريخية (uptime كل 5د، quality/org يومياً، projects/pl_margin) |
| الإشعار والتسليم | `hub_notify`/`HubNotification` + `OutboxMessage` + `hub:outbox` + `FlowRunner`/`HubEvents` | قناةٌ واحدةٌ للإشعار داخل النظام وتلجرام والبريد |
| الحفظ والاحتفاظ | `HubAutomation::pruneNotifications` (`:656-745`, 13 جدولاً) + `HubBackup::RAW_TABLES/EPHEMERAL` | موضعٌ واحدٌ للتقليم، وحارسُ تغطيةِ النسخ الاحتياطي |
| الشاشاتُ المخبوءة | `hub_screen`/`hub_cached`/`hub_scope_key`/`hub_data_stamp` + `?fresh=1` (`helpers.php:2563-2892`) | كاشٌ مُنطَّقٌ بالمستخدم مع بصمةِ جدول |
| مكوّناتُ الواجهة | `partials/pagehead`, `partials/empty`, `partials/pagination[_simple]`, `partials/flash`, `partials/timeline`, `.card/.kpi/.cards/.stat/.tblwrap/.bdg` (`public/css/app.css`) | لا نظامَ تصميمٍ جديد |
| الإعدادات | `config/hub_settings.php` (7 مجموعات/71 مفتاحاً معروضاً + 29 داخلياً) + `SettingController::CHECKS/SECRETS` | أيُّ مفتاحٍ جديد يُعلَن هنا وإلا سقط `SettingsCenterTest` |

#### ب) لكل مركز

- **التدقيق:** `AuditController` (فلاتر module/user/action/ip/from/to/q + تنطيقٌ + `simplePaginate(40)` بترتيبٍ حاسم `:52-54`)، نبضاتُ اليوم `:71-127`، `WidgetRegistry` «آخر النشاطات» `:206-244` (**نموذجُ التنطيق الأقوى**: يُرشّح بالوحدات المرئية)، `hub_timeline` `helpers.php:2378-2470`، `SavedView` + `PrefController::storeView` `:170-221`، `HubAuditVerify` (+ `--rebuild`/`companyMismatch`).
- **الأمن:** `SecurityPosture` (19 فحصاً بشكل `key/label/tone/why/n/fix/url` `:314-317`)، `SecurityExposure::map/summary` `:33-125`، `SecurityRadar` + `AccessRadar` (`access_denials`)، `Sessions::revokeAll` `Sessions.php:22-39`، `LoginSentry` + `user_ips`، `Devices` (`user_devices`, ثقة معلّق/موثوق/مبطَل)، `Risk::session/privileged`، `Totp`/`Webauthn`/`ApiToken`/`ApiAuth`، مفاتيحُ الطوارئ (`lockdown`/`freeze_exports`/`freeze_tokens`)، `hub_security_incident` `helpers.php:559-598`، `SecurityHeaders`.
- **التشغيل:** `SysMonitor` (cpu/memory/disk/tables/slow/busy/pulse)، `OpsController` (نسخ/ترحيل/صيانة/كاش/تحقّقُ سلسلة + `hub_require_ops_stepup`)، `Uptime::check` (`up`/`latency` إلى `metric_points`) + `hub_uptime`، `Integrations::installed/pulse/judge` (ستُّ حالاتٍ قانونية)، `HubBackup` (تشفير/`--verify`/تدوير)، `hub_pending_migrations` + `SchemaGuard`، `Health::beat` وسِجلّ `Health::JOBS`.
- **الأخطاء:** `ErrorCenterController` (فلاتر st/k/cat/sev/q/sort + مقتطفُ شيفرةٍ محميٌّ من اجتياز المسار `:80-91` + `toTask` مُتفرِّدٌ عبر `meta.task_id`)، التقاطُ الطلب البطيء في `Observability:43-55`، قناةُ سجلٍّ JSON.
- **القوى العاملة:** `TrackVisits`→`page_visits`، `SessionSentry` (نبضةُ حضورٍ ≤ مرة/دقيقة)، `ActivityController::riskProfile`، `Workday` (حضور/انصراف/كنسُ نهايةِ اليوم/`teamToday`)، `hub_capacity` (**الوحيدُ الذي يقبل `from/to`**)، `PerformanceController::peopleKpis`، `PortalController::bundle` (**الوحيدُ الذي يطبّق `hub_can` لكل وحدةٍ فرعية**)، `TeamDirectory`، `Staff`.
- **الجودة:** `DataQuality::rules/apply/scan/snapshot/history` (قواعدُ مشتقّةٌ من السجلّ + `?qc=` يفتح نفسَ الصفوف المعدودة)، `QualityController::merge` (دمجُ عملاء يُعيد توجيهَ كل مرجعٍ في السجلّ)، `Identity::merge` (**النموذجُ الأصحّ للدمج**: `hub_audit('دمج منتجات')` + `meta.merged_into`)، `hub_kpi_metric/hub_kpis`، `hub_okr_progress/pace/board`، `hub_sla`، `hub_project_health`، `hub_recommendations` + `ActionCenter` + `signal_states` (ack/snooze/dismiss مُدقَّق).
- **الإعدادات:** كتالوج `config/hub_settings.php`، `SettingController` (قناعُ الأسرار + بصمةُ sha256 في فرق التدقيق `:197`)، `MailSettings::apply`، `Odoo` (قاطعُ دائرة + SSRF)، `Integrations::pulse`، `HubSet`.
- **الحوادث والتنبيهات:** وحدةُ `incidents` (بيانيّةٌ بلا Controller) + `hub_security_incident` (دمجٌ 6 ساعات)، `alert_rules` + `HubAutomation::alertRules` (تنطيقٌ لكل مستلم + دمجٌ عبر `notifications_hub.kind='rule:<id>'` + تصعيد)، `flows` + `FlowRunner` + `HubEvents` (28 حدثاً دلالياً)، `TraceController` (سلسلةُ التسليم — **يُربَط لا يُعاد بناؤه**).

#### ج) مفرداتُ الشدّة والحالة المستعملة فعلاً (لا تُضَف سادسة)

- **شدّة:** `ErrorTaxonomy::SEVERITIES` = `INFO|WARNING|ERROR|HIGH|CRITICAL` (`:19-31`، عربيّتها معلومة/تحذير/خطأ/عالٍ/حرج) · `SecurityEvents` = `info|notice|warning|high` (`:27-66`) · `incidents.severity` = حرج/عالي/متوسط/منخفض (`config/hub.php:7991-7992`) · `issues.severity` مؤنَّثة = حرجة/عالية/متوسطة/منخفضة (`:2187-2190`) · `Risk::bands` = منخفض/متوسط/عالٍ/حرج · `ActionCenter::RANK` = حرج/مهم/اطّلاع · `hub_schedule_failed` = `ERROR`/`HIGH`. **«معلوماتي» غيرُ موجودةٍ في الشيفرة إطلاقاً** (`COMMAND_VERIFIED`).
- **حالة تشغيلية:** `Health` = `HEALTHY|DEGRADED|UNAVAILABLE|MAINTENANCE|UNKNOWN` (سليم/متدهور/متعطّل/صيانة/غير معلوم) · `Integrations` = `CONNECTED|DEGRADED|FAILED|DISABLED|CONFIGURATION_REQUIRED|UNKNOWN` (`:25-40`).
- **دورةُ حياة العطل:** `error_events.status` = جديد/قيد المعالجة/محلول (`ErrorCenterController.php:149`) — **بلا investigating/ignored** · `incidents.status` = مفتوح/قيد المعالجة/مُحتوى/مُستعاد/مغلق بتقرير · `signal_states.state` = open/ack/snoozed/dismissed · `access_denials.kind` = وصول مرفوض/تخمين رابط · `outbox.state`/`webhook_deliveries.state` = queued/sending/sent/failed · `alert_rules.status` = مفعّلة/متوقفة · ثقةُ الجهاز = معلّق/موثوق/مبطَل.
- **أفعالُ التدقيق:** ~120–150 نصّاً عربياً حرّاً (إضافة/تعديل/حذف/تصدير/استيراد/عرض حساس/دخول ناجح|فاشل|مريب/تفعيل قفل الطوارئ…). **لا فعلَ «استعادة»**: الاستعادةُ تُكتب «تعديل» بفرق `deleted_at` (`RUNTIME_VERIFIED`) بينما `hub_timeline:2400` يسمّي «استعادة» غيرَ موجودة.

#### د) مرشّحاتُ الوقت المستعملة فعلاً (لا يوجد مكوِّنٌ مشترك — `COMMAND_VERIFIED` أن `hub_range/hub_period/hub_window` غيرُ موجودة)

| الشاشة/الطبقة | المُعامل | الآلية |
|---|---|---|
| `audit.index` | `from`/`to` (تاريخ فقط) | `whereDate(audits.created_at)` `AuditController.php:46-47` — **غيرُ قابلٍ للفهرسة** |
| API عام | `created_from`/`created_to`/`updated_since` | `Api::timeFilters` `Api.php:256-274` — **عقدٌ موثَّق ومُختبَر** (`docs/API.md:49-50`) |
| قوائم الوحدات | `fl[i][f|o|v]` بعمليّاتِ before/after | `ModuleController.php:98-108,163-164` |
| `capacity` | `from`/`to` (Carbon + سقف 732 يوماً) | `helpers.php:2972-2985` — **أنظفُ تحليلٍ قائم** |
| social / API metrics | `d` (7..365) / `days` (1..730) | `SocialController.php:21` / `V1Controller.php:318` |
| security / ops / activity / errors | **لا مُعامل** | نوافذُ مثبَّتة: 7د/60د/180د (`SecurityController.php:39-91`)، 7د + `pulse(24h)` (`OpsController.php:86-92`)، 14د (`ActivityController.php:89`)، `subDay()` (`ErrorCenterController.php:46-47`) |

المنطقةُ الزمنية: `config/app.php:8` = `Asia/Kuwait` (يُطبِّعها `hub_metric_put:4213-4216`). لا منطقةَ لكلِّ مستخدم. `?fresh=1` هو عرفُ تجاوزِ الكاش.

#### هـ) الجداولُ الحاملةُ لـ`request_id` (`COMMAND_VERIFIED` — PRAGMA على قاعدةٍ مُهاجَرة)

- **موجودٌ ومفهرَس:** `audits`, `outbox`, `webhook_deliveries`, `notifications_hub` (هجرة `2026_09_02_000004_platform_correlation_ids`).
- **موجودٌ بلا فهرس:** `error_events.request_id` (`2026_01_06_000001:22`).
- **غائب:** `access_denials`, `sessions_log`, `page_visits`, `incidents`, `inbound_hook_events`, `api_usage`, `user_ips`, `user_devices`, `api_tokens`, `metric_points`, `tasks`, `tickets`.
- **أصدقاءُ زائفون (مفاتيحُ أجنبية لا ارتباطُ طلب):** `plan_items.request_id`, `contract_signers/contract_events/contract_approval_steps.request_id`, `feats.request_id` — **يُمنع ضمُّها في شاشة الارتباط**.

---

### GAPS

> ما ليس موجوداً، بدقّة. كلُّ بندٍ مُثبَتٌ بأمرٍ أو قراءةٍ كاملة (التفاصيل في جدول التصنيف).

**عابر للمراكز**
1. **شاشةُ ارتباط الطلب** `/system/trace/{requestId}` معدومة: لا شيء في المستودع يستعلم أيَّ جدولٍ بـ`request_id` (`COMMAND_VERIFIED`). الاسم `trace` محجوز → يلزم اسمٌ مغاير (`system.trace`).
2. **`request_id` ناقصٌ** على `access_denials` و`inbound_hook_events` و`incidents` (وغيرُ مفهرسٍ على `error_events`) — فتنقطع السلسلةُ عند الرفض والويبهوك الوارد والحادث.
3. **لا مكوِّنَ مدى زمنيّ مشترك**: عشرُ آليّاتٍ متنافرة، ولا سوابقَ لدقّةِ الساعة (1h/6h/24h) في أيّ شاشة.
4. **لا طبقةَ تعيينِ شدّة** موحّدة فوق المفردات الستّ؛ ولا قيمتَي `investigating`/`ignored` في دورة حياة العطل.
5. **لا محرِّكَ تنقيةٍ مركزيّ**: سبعُ دوالَّ ضيّقة (`ErrorLog::redact` مساراتٌ عامّةٌ فقط، `safeMessage` لـ`QueryException` فقط، `Auditable::auditRedact`، قناعُ `SettingController`، `Integrations.php:69`، `Health::safe`، `HubOutbox.php:80`). **`COMMAND_VERIFIED` بـ`php -r`:** `Authorization: Bearer …` و`password=…` و`api_key=lyn_…` وJWT وكتلةُ PEM تمرّ **بلا تغيير**؛ و`error_events.url` يُخزَّن بسلسلةِ الاستعلام كاملة.
6. **لا تصديرَ لأيّ مركزِ تحكّم**: مسارُ التصدير الوحيد `m/{module}/export`؛ ومسارُ التصدير الجَماعيّ (`ModuleController.php:676-687`) **يتخطّى** حارسَ `security.freeze_exports` وعتبةَ التصعيد.
7. **لا لقطاتِ نظامٍ ولا وضعٍ أمنيّ**: `Health::check` و`SecurityPosture::checks` و`SysMonitor::cpu/memory` تُحسب حيّةً في كل تحميل ولا تُحفظ إطلاقاً.
8. **مقاييسُ الطلب معدومة**: لا عدداً/حالةً/مدّةً لأيّ طلبٍ ناجح — `Observability` يقيس `$ms` ويحفظ **الطلبَ البطيء فقط** كـ`error_events kind=slow` بنصِّ شريحةٍ لا برقم. فلا معدّل، ولا 4xx/5xx، ولا p50/p95/p99، ولا انحدارَ أداء، ولا SLO، ولا ربطَ إصدارٍ بأخطاء.
9. **الاحتفاظ** مثبَّتٌ في الشيفرة لخمسة جداول (`notifications 90/365`, `inbound_hook_events 90`, `metric_points 365`, `page_visits 90`, `access_denials 90د/50 ألف صف`) بلا مفاتيحِ إعداد، والتقليمُ صامت (لا سطرَ تدقيق)، و`error_events` يُحذف بعد ضعفِ النافذة **بصرف النظر عن الحالة** (`HubAutomation.php:698-700`) — أي حذفُ أدلّةٍ غيرِ محلولة.
10. **الملاحة والبحث**: قائمتان يدويّتان للروابط الإدارية تختلفان فعلاً (`layouts/app.blade.php:135-172` مقابل `SearchController::destinations:158-175`)؛ ولا بحثَ بـرقم الطلب/معرّف الخطأ/IP/مفتاح إعداد/مستخدم (المستخدمون مستثنَون صراحةً `:187`).

**مركز التدقيق**
11. لا **صفحةَ تفصيلِ حدث** (WHO/WHAT/WHERE/REQUEST/INTEGRITY/RELATIONSHIPS) — الروابطُ تذهب إلى `m.show` لا إلى الصفّ.
12. لا أعمدةَ تطبيعٍ (`category/severity/source/outcome/actor_type/session_id`) فيُستحيل عدُّ «حرج/حسّاس/فاشل» أو ترشيحُه بكفاءة.
13. لا **محلِّلَ تغطية** يقول أيُّ عمليّاتٍ حسّاسةٍ بلا أثر (وثبت أن `ErrorCenterController::status/toTask` و`SavedView` CRUD بلا تدقيق).
14. لا **تاريخَ فحوصِ سلامة**: نتيجةُ `hub:audit-verify` تعيش في نبضةٍ واحدة، ورقمُ أوّلِ صفٍّ تالفٍ يُطبع على الطرفية فقط.
15. لا **سياسةَ احتفاظٍ معلَنة** للتدقيق: لا مفتاح `audit.*` في الكتالوج مع وعدٍ نصّيٍّ بالحفظ الأبدي في ثلاثة مواضع.
16. **ثغرةُ قراءة:** قائمةُ التدقيق لا تُرشّح الصفوفَ بـ`hub_can(module,'v')` (تُطبع أسماءُ سجلّاتِ وحداتٍ لا يراها القارئ) ولا تطبّق `hub_client_ids` مطلقاً.

**مركز الأمن**
17. لا **محرّكَ نتائج (findings)** ثابت: صفوفُ `SecurityPosture` تُحسب لحظيّاً بلا first/last seen ولا ack/resolve ولا مالك.
18. لا **تاريخَ وضعٍ أمنيّ** (2.2) ولا **مركزَ مخاطرِ هوية** عابرٍ للمستخدمين (2.4) ولا **مراجعةَ صلاحياتٍ مميّزة** (2.5) ولا **مركزَ جلسات** بفلاتر/ترقيم (2.6) ولا **تصنيفَ أجهزةٍ** على مستوى المنظّمة (2.7) ولا **ذكاءَ IP** (2.8) ولا **مركزَ رموزِ API** (2.9) ولا **صحّةَ أسرار** (2.10).
19. **قواعدُ التنبيه لا تستطيع التعبيرَ عن شرطٍ أمنيّ**: المحرّك يقارن عموداً في جدول وحدةٍ فقط، ويعمل **مرّةً يومياً**؛ فلا «X محاولةَ دخولٍ فاشلة في Y دقيقة».
20. **أفعالٌ خطرة بلا تصعيد:** `security.user.revoke` (إنهاءُ كلِّ جلسات مستخدم)، إيقافُ حساب (`users.update status=موقوف`)، وتغييرُ مفاتيحِ الإعدادات الخطرة — كلُّها بلا `hub_require_stepup`.
21. `api_tokens` بلا `last_ip` ولا `revoked_at` (الإلغاءُ حذفٌ صلب)، و`vault_secrets` بلا `rotated_at` (القِدَمُ يُقاس بـ`updated_at` فيصفّره تعديلُ ملاحظة).

**مركز التشغيل**
22. لا **جداولَ تجميعٍ لمقاييس HTTP** (يلزم مخزَنٌ واحد، انظر DATA MODEL) — يترتّب عليه غيابُ 3.3/3.4/3.5/3.6/3.10/3.16 و«p95» كلِّها.
23. لا **كشفَ حوادثَ تشغيليّ آليّ** رغم توفّر الإشارات في `Health::check`: الكلبُ يُشعِر فقط، و`hub_schedule_failed` يفتح حادثاً لـ`hub:audit-verify` وحدَه.
24. لا **تاريخَ تشغيلاتِ مجدولات** ولا مدّةً لستّة أوامرَ من الثمانية (تُنبض بـ`ms=null`).
25. **العتباتُ مثبَّتة** (CPU 60/90، ذاكرة 75/90، قرص 85/97، DB 500مللي، طابور 20/60د) وبعضُها متناقض: نضارةُ النسخة 26/50 ساعة في `Health::JOBS` مقابل 30/72 في `SecurityPosture::backupFresh`.
26. **شاشاتٌ غيرُ متحمِّلةٍ للعطل:** `RUNTIME_VERIFIED` أنّ إسقاطَ جدول `outbox` يجعل `/admin/ops` يرمي `QueryException` (`OpsController.php:62` بلا حارس) بينما `Health` نفسُه يُبلغ `UNKNOWN` بأدب. الأمرُ نفسُه في `MessagingController::index:35`.
27. **`ops.backup`/`verifyaudit`/`schemacheck`/`starters`/`testerror`** بلا تصعيد رغم أن الترحيل والصيانة والكاش تطلبه.
28. **قراءةُ ذيل السجلّ ميتة**: `OpsController.php:109` يقرأ `storage/logs/laravel.log` بينما سائقُ `daily` يكتب `laravel-YYYY-MM-DD.log` (`COMMAND_VERIFIED`) → البطاقةُ فارغةٌ دائماً. ولا بحثَ سجلّاتٍ أصلاً.

**مركز الأخطاء**
29. لا **جدولَ ظهورات (occurrences)**: الصفُّ مجمَّعٌ فقط، و`request_id` يُكتب **عند أول ظهورٍ فقط**، والمدّةُ الحقيقية للطلب البطيء تُهمَل، فلا عيّناتِ طلبٍ ولا «أخطاء عبر الزمن» صحيحة ولا مستخدمون متأثّرون بالزمن.
30. لا **دورةَ حياةٍ كاملة** (investigating/ignored بسببٍ + `resolved_at/by/release` + علامةُ انتكاسة) ولا **إسناد** (مسؤول/أولوية/موعد) ولا **فتاتِ مسار** ولا **ربطِ إصدار** ولا **بحثِ سجلّات**.
31. **بصمةٌ ضعيفة**: `fingerprintOf` يطبّع UUID و16+ خانةً ست عشرية و4+ أرقام فقط — الطوابعُ الزمنية وسلاسلُ الاستعلام والمعرّفاتُ القصيرة وJWT تُنشئ بصماتٍ متعدّدة للعطل الواحد.
32. **سقفُ العاصفة يُسقط CRITICAL**: `ErrorLog::tell` يخرج قبل معرفة الشدّة (`:175-178`) → عطلٌ حرجٌ أثناء عاصفةٍ قد لا يُشعِر أحداً.

**القوى العاملة**
33. لا **فصلَ عنوانيّ** بين «مخاطر النشاط الأمني» والإنتاجية: `riskProfile` يُعيد الدرجةَ الأمنية وساعاتِ العمل في مصفوفةٍ واحدة وتُعرضان جنباً إلى جنب.
34. لا نظرةَ **منظّمة/فريق**: لا لوحةَ 8 بطاقات، ولا تجميعَ قسم، ولا توازنَ حِمل، ولا تحليلَ اختناق (مُدَدُ المراحل/إعاداتُ الفتح/المهامُّ الراكدة)، ولا نوافذَ 7/30/90.
35. **وقتُ الإنجاز تقريبيّ**: `tasks` بلا `completed_at`؛ On-time محسوبٌ من `updated_at` فيفسده أيُّ تعديلٍ لاحق.
36. لا **خطَّ زمنٍ للموظف** منقّى؛ وصفحةُ النشاط تسكب 120 زيارةً خاماً افتراضياً.

**الجودة والتنفيذ**
37. لا **مركزَ موحّدٍ بتبويبات**؛ ولا شدّةَ لنتائج جودة البيانات؛ ولا مالكاً/أوّلَ رصدٍ/عمراً/اتجاهاً لكل نتيجة (لا سلاسلَ لكل وحدة — تُخزَّن `quality/org` فقط).
38. **الدمجُ ناقصُ الأثر**: `QualityController::merge` بلا `hub_audit` صريح ولا معاينة ولا عدّ مراجع ولا مقارنةٍ جنبَ جنب (بينما `Identity::merge` يفعلها).
39. **KPI**: بلا مالك/فترة/تباين/تاريخ؛ و**سبعةٌ من 26 مؤشراً مبذوراً تُرشّح حالاتٍ غيرَ موجودةٍ في السجلّ** (`COMMAND_VERIFIED`) فتعطي 0.0 دائماً — والأسوأ أن `hub_kpis` يصبغ 0.0 مقابل هدفٍ 0 بـ«على الهدف»، ويُرسله `hub:digest` أسبوعياً.
40. **OKR**: رقمان متناقضان للتقدّم (عمودُ `objectives.progress` في `/performance` مقابل `hub_okr_progress` في `/m/okrs`)، وبلا عدّاداتِ متأخر/متعثّر/راكد.
41. لا **ربطَ إصلاحٍ** من نتيجةِ جودةٍ أو هدفٍ متعثّرٍ أو خرقِ SLA إلى مهمّة (الأخطاءُ وحدَها لها هذا الجسر).

**الإعدادات**
42. الكتالوج بلا **قيمةٍ افتراضيةٍ آليّة** (النصُّ نثريّ) ولا `sensitive/validation/depends/scope/restart/owner_route` — فيستحيل «المعدَّل عن الافتراضي» و«القيمة الفعّالة» و«المصدر».
43. لا **معاينةَ تغيير** ولا **استعادةَ افتراضي** ولا **تصدير/استيراد آمن** ولا **تحقّقَ اعتماديّاتٍ جماعيّ** (يُحفظ `mail.*` منقوصاً من شاشة الإعدادات بينما مركزُ المراسلة يفرض المجموعة).
44. **14 مسارَ كتابةٍ للإعدادات، واحدٌ فقط يسجّل before/after**؛ و`hub:set` يكتب أيَّ مفتاحٍ بلا تحقّقٍ ولا تدقيقٍ ويترك `n8n.key` **نصّاً صريحاً**.
45. الكتالوج **ليس جرداً كاملاً**: مفاتيحُ حيّةٌ غيرُ معلَنةٍ (`custom.fields_seq`, `demo.settings`, `heartbeat.*`, `integration.*.last_*`، وحلقةُ `mail.<k>`) لأن حارسَ الاختبار يمسح النصوصَ الحرفية فقط.

**الحوادث والتنبيهات**
46. `incidents` بلا `detected_at`/`acknowledged_*`/`fingerprint`/`kind`/`request_id`؛ والمدّةُ يدويّةٌ (`downtime_min`)؛ وكشفُ الحوادث الأمنية يدمج **بنصّ العنوان** لا ببصمة.
47. لا **دليلَ مرتبط** بالحادث: لا جدولَ ربطٍ عامّ في المستودع (`COMMAND_VERIFIED`)، و`meta.events` لا يُعرَض في أيّ قالب.
48. لا **حالةَ تنبيهٍ** (triggered/acknowledged/resolved): التنبيهُ صفوفُ إشعارٍ بعلَم `read` فقط، وذاكرتُه تُمحى بتقليم الإشعارات (90/365 يوماً).
49. لا **زرَّ «افتح حادثة»** من خطأٍ أو حدثٍ أمنيّ أو نتيجة؛ ولا فعلَ `incident` في `FlowRunner`؛ ولا مراجعةَ ما بعد الحادث.
50. لا **طابورَ انتباهٍ للمالك**: `ActionCenter` قائمٌ وممتاز لكن منتجيه الخمسةَ عشرَ كلَّهم أعمالٌ تجارية — لا صحّةَ ولا سلسلةَ تدقيقٍ ولا خطأً حرجاً ولا جودةَ بيانات.

---

### DUPLICATION RISKS

> ما **يُمنع** بناؤه مرّتين. لكلِّ بندٍ السكّةُ الواجبة.

**١) معرّفُ ارتباطٍ ثانٍ.** `X-Request-Id` هو الوحيد؛ لا `trace_id`. وانتبه: `request_id` في `plan_items`/`contract_*`/`feats` مفتاحٌ أجنبيّ لوحدة الطلبات — قصرُ البحثِ على جداول الارتباط الخمسة إلزاميّ. وفي الطرفيّة `Api::requestId()` يُنتج معرّفاً واحداً لكل عملية، بينما `ErrorLog` يقرأ سمةَ الطلب فيحصل على `null` → وسمُ `source=console` لا مولِّدٌ ثالث.
**٢) صفحةُ `trace` ثانية.** الاسمُ والقالبُ محجوزان لـ`TraceController`؛ استعمل `system.trace` واربط إليه.
**٣) مُحلِّلُ مدىً زمنيٍّ حادي عشر.** الحلُّ يقبل `from/to` القديمة (وإلا كسر `saved_views` المخزَّنة كسلاسلِ استعلام) ويصالح `Api::timeFilters` (عقدٌ عامّ مُختبَر) بدل اختراع أسماء.
**٤) مفرداتُ شدّةٍ سابعة.** طبقةُ تعيينٍ واحدة تغطّي المفردات الستّ؛ ولاحظ اختلافَ التذكير/التأنيث (incidents مذكَّرة، issues مؤنَّثة) الذي تُداريه اليومَ `LIKE '%حرج%'`.
**٥) دالّةُ تنقيةٍ ثامنة.** `Support\Redactor` واحدة تُفوَّض إليها السبعُ القائمة، ومفاتيحُها تبدأ من `Audit::MASKED` + `AUDIT_SECRET` (موجودةٌ في نموذجين فقط). تُطبَّق عند **الكتابة والعرض معاً** وإلا بقيت السجلّاتُ القديمة مكشوفة (`admin/webhook_log.blade.php:23` يطبع 3000 حرفٍ من الجسم؛ `inbound_hook_events.payload` يخزّن 64 ك.ب خاماً).
**٦) جدولُ أحداثٍ أمنيّةٍ.** `SecurityEvents` **مشتقّ عمداً** فوق `audits`+`access_denials` — لا تُنشئ `security_events`. النتائج (findings) هي الحالةُ الوحيدة التي تستحقّ الثبات.
**٧) مخزَنُ سلاسلَ زمنيّةٍ ثانٍ.** `metric_points` يستضيف لقطاتِ الصحّة والوضع الأمنيّ والجودة والتنفيذ (سابقةُ `record_id='org'` قائمةٌ في `DataQuality.php:314`). الاستثناءُ الوحيد المبرَّر: **دِلاءُ HTTP** (بُعدُ المسار + count/sum/max/histogram لا يسعها بنيةُ النقطة) — ولاحظ أن `api_usage` أُنشئ صراحةً لإبقاء عدّادات الطلبات خارج `metric_points` (`2026_09_02_000005:9-13`).
**٨) عدّادُ نبضاتٍ ثانٍ.** أيُّ أمرٍ جديد يُسجَّل في `Health::JOBS` + `routes/console.php` بـ`withoutOverlapping`+`onFailure`+`beat`، وإلا كان خفيّاً عن `/healthz` وجدول التشغيل. وتذكّر أن كلَّ نبضةٍ تُبطل كاش `settings:all`.
**٩) آليّةُ دمجٍ/تهدئةٍ رابعة.** القائم: دمجُ الحادث بالعنوان 6 ساعات، سقفُ عاصفة `ErrorLog` 8/15د، كلبُ حراسةٍ مرّة/يوم، دمجُ قاعدةِ التنبيه عبر `notifications_hub.kind='rule:<id>'`. التهدئةُ الجديدة تركب هذه لا تُضيف مخزَناً.
**١٠) سجلُّ إقرارٍ رابع.** `signal_states`+`ActionCenter::disposition` للإشاراتِ المحسوبة، و`record_acks`+`config/hub_acks.php` للسجلّات الحقيقية (وهي **الطريقُ الصحيح** لإقرار الحادثة: تسجيلٌ في `hub_acks` يعطي `acknowledged_by/at/ip/device` بلا هجرة). لا تُضِف `acknowledged_at` عموداً ثالثاً. و`TECH_DEBT #29` يطلب أصلاً توحيدَ `policy_acks` نحو `record_acks`.
**١١) محرّكُ تنبيهٍ ثانٍ.** استخرج `HubAutomation::alertRules` (`:385-569`) إلى `Support\AlertEngine` واستدعِه من الأمر اليوميّ ومن أيّ مقيِّمٍ متكرّر؛ ترقيمُه بالمؤشّر وتنطيقُه لكل مستلمٍ وتصعيدُه محروسةٌ بخمسة أصنافِ اختبار.
**١٢) قائمةُ روابطَ إداريّةٍ ثالثة.** كتالوجٌ واحد (key/label/route/gate/group) يقرؤه شريطُ الإدارة و`SearchController::destinations` معاً؛ انتبه أن مفتاح `alerts` في `hub_top_links` يعني **رادارَ الانتهاء** لا قواعدَ التنبيه.
**١٣) تعريفٌ ثانٍ لـ«المميّز»/«الخامل»/«القديم».** المميّز يُعرَّف في ثلاثة مواضع (`RoleController::RISKY_FLAGS` هو المرجع)، والخمول 60 يوماً مكرَّرٌ في خمسة، وقِدَمُ السرّ 180 في ثلاثة، ونافذةُ الجلسة الحيّة 30 دقيقةً في أربعة.
**١٤) إلغاءُ جلساتٍ رابع.** `Sessions::revokeAll` موجود، ومع ذلك يُعاد تنفيذُه في `SecurityController::revokeSession/revokeUser` و`MySecurityController::revokeSession/revokeOthers`.
**١٥) قارئُ نشاطٍ خامس.** `WidgetRegistry` (الأدقُّ تنطيقاً)، `WorkspaceController`، `hub_timeline`، `SecurityEvents::recent`، `AuditController` — يُستخرج بانٍ واحد مُنطَّق.
**١٦) مُحلِّلُ مسارٍ ثالث.** `ErrorTaxonomy::fingerprintOf` و`ErrorLog::routePattern` و`Observability.php:50-53` ثلاثُ نسخٍ من التعبير نفسِه.
**١٧) عدّادُ KPI/جودةٍ سادس.** عدّادُ الأخطاء محسوبٌ في خمسة مواضع (`ErrorCenterController`، `OpsController`، `MorningController`، `SysMonitor`، `Health::errors`) — قارئٌ واحد يُغذّيها.
**١٨) مقياسُ زمنِ الدورة/الإنتاجيّة الثاني.** `Delivery::leadTime/cadence` تعرّفهما بشكلٍ ناضج (وسيطٌ بجانب المتوسّط + علَمُ «عيّنة»)، و`hub_app_quality` يعرّف MTTR ووقتَ إصلاح العطل — تُتبنّى أشكالُها.
**١٩) تصديرٌ CSV ثانٍ.** `ModuleController::export+streamCsv` يحمل السلسلةَ الكاملة (علَمُ المصدِّر → 423 عند التجميد → التنطيق → تصعيدٌ عند الحجم → تدقيق → قناعُ حقول `sec` → تحييدُ صيغِ Excel) — تُستخرج خدمةً بدل نسخِها.
**٢٠) مقلِّمُ احتفاظٍ ثانٍ.** كلُّ جدولٍ جديد يُقلَّم داخل كتلة `HubAutomation` نفسِها، ومفاتيحُه تُعلَن في الكتالوج، ويُدرَج في `HubBackup::RAW_TABLES` أو `EPHEMERAL` وإلا سقط حارسُ النسخ.
**٢١) لوحةُ معلوماتٍ عملاقةٌ ثانية.** `Health::check` و`SecurityPosture` و`SecurityExposure` و`Delivery::breaks` لكلٍّ صفحتُه؛ طابورُ الانتباه **يستدعي ويربط** لا يُعيد الحساب (و`Health::check` وحدَه 55 استعلاماً غيرَ مخبوء).
**٢٢) تسميةٌ تصطدم:** `Support\Identity` + مسارات `identity.*` = هويّةُ المنتجات (لا مخاطرُ الهوية) · وحدة `apis` = تكاملاتٌ خارجية (لا `api_tokens`) · `workforce.team` = «فريقي اليوم» (حضور) · وحدة `issues` = سجلُّ مخاطرِ مشروع (لا مجموعةُ أخطاء) · «يحتاج انتباهاً» مستعملةٌ في `Workspaces::attentionByModule`.
**٢٣) بذرةُ حالةٍ مغلقةٍ سادسة وعشرون.** `hub_closed_states/hub_open_scope/hub_closed_scope` هي السكّة؛ ومع ذلك تُكتب القائمةُ حرفيّاً في ستّة مواضع (`MorningController:83`, `hub_project_health:2201`, `hub_capacity:3025`, `ModuleController:961`, `SupportController:12`, `PerformanceController:117`). أيُّ حالةٍ جديدة (`متجاهَل`) تُسجَّل هناك أوّلاً وإلا حُسبت «مفتوحة».

---

### DATA MODEL CHANGES

> **إضافيّةٌ فقط**: كلُّ عمودٍ `nullable` بحارس `hasColumn/hasTable`، وكلُّ عرضٍ نصّيٍّ مُعلَن (MySQL صارمة)، ولا حذفَ ولا تغييرَ نوع. كلُّ جدولٍ جديد يُدرَج في `HubBackup::RAW_TABLES` أو `EPHEMERAL` ويُقلَّم في `HubAutomation`.

**أعمدةٌ على جداولَ قائمة**

| الجدول | الإضافة | السبب |
|---|---|---|
| `audits` | `category(24)`, `severity(12)`, `source(12: web/api/console/webhook)`, `outcome(12)`, `actor_type(12)`, `session_id(uuid)` — **خارج `AuditEntry::SEALED`** | 1.2/1.4/1.1: عدٌّ وترشيحٌ على النطاق. الختمُ لا يتأثّر (نمطُ `company_id`/`request_id`)، و`liveColumns()` يجعل الكتابةَ آمنةً قبل الهجرة. يُصرَّح أن هذه أعمدةٌ **غيرُ مختومة** فلا تُقدَّم كدليلٍ مانعٍ للعبث |
| `error_events` | `assignee_id(uuid)`, `priority(12)`, `due_at`, `resolved_at`, `resolved_by(uuid)`, `resolved_release(20)`, `regressed_at`, `regression_release(20)`, `ignored_reason(300)`, `ignored_by(uuid)`, `muted_until`, `incident_id(uuid)`, `notes(text)` + توسيعُ مفردات `status` بـ«قيد التحقيق»/«متجاهَل» (العمود 20 حرفاً يكفي) | 4.5/4.6/4.9/4.12/44 + GLOBAL-STATUS. تُحدَّث قائمةُ `in:` في `ErrorCenterController.php:149` ومرشّحُ القالب، **وتُسجَّل «متجاهَل» في `hub_closed_states`** |
| `incidents` | `detected_at`, `fingerprint(120)`, `kind(20)`, `request_id(40)` | 8.1 + 3.9: دمجٌ ببصمةٍ بدل نصِّ العنوان، وإسقاطُ `meta LIKE '%"kind":"security"%'` من ثلاثة قرّاء. **الإقرارُ (acknowledged) عبر `config/hub_acks.php`+`record_acks` لا بعمودٍ جديد** |
| `access_denials` | `request_id(40)` (+ اختياريّ `route(160)`, `status(smallint)`) | GLOBAL-CORR-2 و42.10: `SecurityRadar::record` يملك الطلبَ ولا يخزّن المعرّف |
| `inbound_hook_events` | `request_id(40)`, `state(12)`, `error(300)` | §20: الحالةُ اليومَ ثابتةٌ 200 والصفُّ يُكتب عند النجاح فقط، فالفشلُ (401/404) غيرُ مرئيّ في أيّ مكان |
| `sessions_log` | `revoked_at`, `revoked_by(uuid)`, `revoke_reason(120)` | 2.6: `revoked` اليومَ يخلط الخروجَ الذاتيّ بالإلغاء الإداريّ |
| `user_ips` | `first_seen_at` (بلا ملءٍ رجعيّ) | 2.8 «أول ظهور» |
| `api_tokens` | `last_ip(60)`, `revoked_at`, `revoked_by(uuid)` | 2.9: لا IP أخير، والإلغاءُ حذفٌ صلبٌ فلا حالةَ تُعرَض. يُفرَض `revoked_at` في `ApiAuth` |
| `vault_secrets` | `rotated_at` (يُختم في `VaultSecret::booted` عند تغيّر `secret_cipher` فقط) | 2.10: القِدَمُ اليومَ من `updated_at` يصفّره تعديلُ ملاحظة |
| `alert_rules` | `severity(40)`, `domain(24)`, `source(80)`, `window_min`, `cooldown_min`, `auto_incident(bool)` | 9.1/2.12: العمودُ `every` عشريٌّ بالأيام والمحرّك يقصره على `max(1,(int))` |
| `tasks` | `completed_at` | 5.4/6.7: On-time اليومَ من `updated_at` (تعليقُ `PerformanceController.php:106` يعترف بالتقريب) |
| `kpi_defs` | `owner_id(uuid)`, `period(40)` | 6.9 |
| `metric_points` | (اختياريّ) `n`, `sum`, `min`, `max`, `p95` أعمدةٌ عشريّةٌ فارغة | §15 لدِلاءِ النظام منخفضة الحجم — أو تُحفظ في `meta` بلا هجرة |

**جداولُ جديدة (أربعةٌ فقط، ولكلٍّ مبرِّرٌ لماذا لا تسعُه سكّةٌ قائمة)**

1. **`http_metric_buckets`** — `bucket_at(5د)`, `surface(web|api)`, `method`, `route(160 منمَّط)`, `count`, `err4`, `err5`, `slow`, `sum_ms`, `max_ms`, `hist(json)`; فريدٌ على (bucket_at, surface, method, route). **لماذا:** لا تليمترَ طلبٍ في المستودع، و`metric_points` بنيتُه (قيمةٌ واحدة، `record_id` uuid) لا تحمل بُعدَ المسار ولا التجميعات. يُكتب بجملةٍ واحدةٍ (نمطُ `Api::countUsage` upsert) خارج معاملة الطلب وبـtry/catch.
2. **`error_occurrences`** — `error_event_id`, `occurred_at`, `request_id`, `user_id`, `route`, `url(منقّى)`, `method`, `status_code`, `duration_ms`, `release`, `safe_context(json)`؛ سقفٌ لكل عطل (`errors.occurrences_keep=50`) + احتفاظٌ زمنيّ. **لماذا:** الصفُّ المجمَّع لا يحفظ إلا أوّلَ `request_id`. يُدرَج في `EPHEMERAL` مثلَ أبيه.
3. **`security_findings`** — `code`, `entity_type`, `entity_id`, `severity`, `title`, `description`, `evidence(json)`, `remediation`, `owner_id`, `status(open|acknowledged|resolved|ignored)`, `first_seen_at`, `last_seen_at`, `acknowledged_*`, `resolved_at`, `company_id`, `request_id`؛ فريدٌ على (code, entity_type, entity_id). **قرارٌ مطلوب:** إمّا هذا الجدول **أو** تسجيلُ صفوف `SecurityPosture` كإشاراتٍ في `signal_states` بمفتاح `sec:<CODE>:<entity>` (بلا هجرة، لكن بلا شدّةٍ ولا أوّلِ رصدٍ ولا مالك) — **لا الاثنان** وإلا صار الإقرارُ مزدوجاً.
4. **`audit_verifications`** — `mode(auto|manual)`, `initiated_by`, `request_id`, `started_at`, `finished_at`, `duration_ms`, `result`, `checked_rows`, `weak_rows`, `unsealed_rows`, `mismatch_rows`, `first_bad_id`, `message(500)`. **لماذا:** النبضةُ تحفظ آخرَ نتيجةٍ فقط ولا تحمل عدّادات. ملاحظةٌ للتنفيذ: **تاريخُ تشغيلِ بقيّة المجدولات لا يستحقّ جدولاً** — يُكتب نقاطاً في `metric_points` (`module='ops'`, `record_id='<job>'`, `metric='run'`, `value=ms`, `meta{result,note}`) من `Health::beat`/`hub_schedule_failed`.

**تغييراتُ إعدادٍ/سجلّ (بلا هجرة)**
- `config/hub_settings.php`: حقولٌ إضافيةٌ لكل مدخل (`default` آليّة، `sensitive`, `validation`, `depends`, `scope`, `restart`, `env_key`, `owner_route`, `doc`) وتحويلُ `internal` من نصٍّ إلى `{why, owner_route}` مع قبول الشكل القديم؛ ومفاتيحُ جديدة: `retention.visits_days|metric_points_days|notifications_days|inbound_hooks_days|denials_days|error_occurrences_days`, `audit.retention_days` (0 = للأبد) `audit.retention_policy`, `errors.occurrences_keep|notify_cooldown_min`, `ops.cpu_warn|cpu_crit|mem_warn|mem_crit|disk_warn|disk_crit|db_ms_warn|queue_age_warn|queue_age_crit|http_p95_ms|regression_pct|regression_min_n|log_tail_kb|last_version`, `slo.availability_pct|latency_ms|latency_pct|error_rate_pct|window_days`, `security.snapshot_on|alert_cooldown_min|idle_days_*`. **كلُّ مفتاحٍ غيرِ معلَنٍ يُسقط `SettingsCenterTest`.**
- `config/hub.php`: حقولُ `incidents` الجديدة؛ أحداثٌ دلاليّة (`security.finding_critical`, `users.risk_alert`, `ops.component_down`) ليعمل عليها `FlowRunner`؛ وحقولُ `rules` الجديدة لتظهر في نموذج الوحدة وAPI.
- `config/hub_acks.php`: مدخلُ `incidents` (`who = lead_id`) لتحصيل «تسلَّم الحادثة» عبر `record_acks`.
- `SecurityEvents::CODES`: تسجيلُ الأفعال الجديدة (استعادةُ افتراضي الإعدادات، تصدير/استيراد الإعدادات، إقرارُ نتيجة، فتحُ حادثة من حدث) وإلا لم تُصنَّف.

---

### INDEXES

**موجودٌ فعلاً (`COMMAND_VERIFIED` — PRAGMA على قاعدةٍ مُهاجَرة رميّة): لا تُكرَّر**
- `audits`: `(created_at,ip)`, `(action)`, `(request_id)`, `(company_id,created_at)`, `(module,record_id)`, `(hash)`, `(module,created_at)`, `(created_at)`, `(project_id)`, `(record_id)`, `(module)`, `(user_id)`.
- `error_events`: `hash` فريد, `kind`, `user_id`, `status`, `last_seen`, `category`, `severity`.
- `page_visits`: `(user_id,at)`, `(at)` · `sessions_log`: `user_id`, `last_seen_at`, `device_id` · `access_denials`: `kind`, `user_id`, `ip`, `created_at` · `user_ips`: `user_id`, فريد `(user_id,ip)` · `user_devices`: `user_id`, `cookie_hash`, `last_seen_at`, فريد `(user_id,cookie_hash)`.
- `metric_points`: فريد `(module,record_id,metric,at)` + `(module,record_id,metric,at)` + `(metric,at)` · `notifications_hub`: `(user_id,read)`, `(module,record_id)`, `kind`, `read`, `created_at`, `request_id` · `outbox`: `state`, `created_at`, `next_at`, `kind`, `channel`, `user_id`, `request_id` · `webhook_deliveries`: `(state,next_at)`, `next_at`, `webhook_id`, `request_id`.
- `incidents`: `(status,severity)`, `status`, `severity`, `started_at`, `resolved_at`, `created_at`, `(project_id,created_at)` · `api_usage`: `day`, فريد `(day,token_id)` · `api_tokens`: فريد `token_hash`, `user_id` · `saved_views`: `user_id`, `(user_id,module)` · `objectives`: `due`, `status`, `level`, `period`, `owner_id` · `tasks`: `status`, `(status,project_id)`, `assignee_id`, `created_at`, `dept`, `priority`, `company_id` · `settings`: المفتاحُ الأساس فقط · `audit_chain`: بلا فهارس (ثلاثةُ أعمدة).

**مطلوبٌ (مع المبرِّر — لا فهرسَ بلا استعلامٍ يقودُه)**

| الجدول | الأعمدة | المبرّر |
|---|---|---|
| `audits` | `(user_id, created_at)` | ترشيحُ مستخدمٍ + ترتيبٌ زمنيّ: `AuditController:43,52`، `ActivityController:105-106,153-156`، `Risk.php:99-106`، وخطُّ زمنِ الموظف |
| `audits` | `(action, created_at)` | نوافذُ الأحداث الأمنية: `SecurityController:41,75-79,106`، `Risk::session`, `SecurityEvents::recent`, نبضاتُ التدقيق، وقواعدُ التنبيه المزمَّنة |
| `error_events` | `(request_id)` | شاشةُ الارتباط وصفحةُ التفصيل (العمودُ قائمٌ بلا فهرس) |
| `error_events` | `(status, last_seen)` — **بعد القياس** | `Health::errors:315` وعدّادات «المفتوح خلال نافذة»؛ الفهارسُ المفردةُ قد تكفي |
| `access_denials` | `(ip, created_at)`, `(request_id)` | تجميعُ IP داخل نافذة (`SecurityRadar::threats:68-74`) وربطُ الرفض بالطلب |
| `metric_points` | `(at)` | حذفُ الاحتفاظ `where at <` (`HubAutomation:678-680`) لا يقوده فهرسٌ حاليّ، والجدولُ يستقبل 288 نقطةً/هدف/يوم |
| `user_ips` | `(ip)` | «أيُّ مستخدمين لمسَهم هذا الـIP» — الفريدُ يبدأ بـ`user_id` |
| `tasks` | `(due)` | كلُّ استعلامِ تأخّر (`PerformanceController:112`, `hub_project_health:2216`, `hub_capacity:3033`, `WidgetRegistry:190`) — **مع إزالة `whereDate/strftime` وإلا لم يُستعمل** |
| `tasks` | `(completed_at)` | فقط إن أُضيف العمود: إنتاجيّةٌ وزمنُ دورةٍ لكل فترة |
| `outbox` | `(state, next_at)` — اختياريّ | استعلامُ العامل كل 5 دقائق؛ السابقةُ `wd_due_idx` على `webhook_deliveries` |
| `incidents` | `(kind, status)` | بعد إضافة `kind`: يستبدل مسحَ `meta LIKE` في `SecurityController:51` و`Health.php:334` |
| `security_findings` (جديد) | فريد `(code, entity_type, entity_id)`, `(status, severity)`, `(last_seen_at)` | upsert لكل تسوية + عدُّ المفتوح بالشدّة |
| `http_metric_buckets` (جديد) | فريد `(bucket_at, surface, method, route)`, `(route, bucket_at)` | upsert لكل طلب + جدولُ أداءِ المسارات والاتجاه |
| `error_occurrences` (جديد) | `(error_event_id, occurred_at)`, `(request_id)`, `(occurred_at)` | أحدثُ العيّنات + الارتباط + التقليم |
| `audit_verifications` (جديد) | `(started_at)` | ترتيبُ التاريخ |
| `sessions_log` | `(started_at)` / `(revoked, last_seen_at)` — **أولويةٌ دنيا** | `OpsController:102` وعدُّ «حيّ الآن»؛ الجدولُ صغيرٌ اليوم |

**قواعد:** لا فهرسَ مكرَّراً (تُفحص `Schema::getIndexes` أولاً كما في `MysqlPortabilityTest:85-94`)، وأسماءُ الفهارس ≤64 حرفاً، والهجرةُ تتخطّى الموجودَ (نمطُ `2026_09_02_000008`).

---

### UI CHANGES

**صفحاتٌ جديدة (كلُّها تحت أسماءِ مساراتٍ لا تصطدم)**
- `system/trace/{rid}` (اسم `system.trace`) — ملخّصُ الطلب + تدقيقاتٌ + أحداثٌ أمنية + أخطاء + outbox/webhook/إشعارات + الحادثةُ المرتبطة؛ بوّابةٌ لكل مصدرٍ على حِدة؛ المعرّفاتُ داخل `<bdi class="mono ltr">`؛ **لا ترويسةَ طلبٍ إطلاقاً**.
- `audit/show.blade.php` (تفصيلُ حدث) + `audit/coverage.blade.php` (محلِّلُ تغطية، للمالك) + قسمُ تاريخِ فحوصِ السلامة.
- الأمن: `security/findings`, `security/identity` (**لا تُسمَّ `identity.*`**), `security/privileged`, `security/sessions`, `security/devices`, `security/ips`, `security/tokens`, `security/secrets`, `security/event` (تفصيلُ حدثٍ بمفتاح source+id), `security/alerts`.
- التشغيل: أقسامٌ داخل `ops/*` (إشاراتٌ ذهبية، جدولُ أداءِ المسارات مع `?sort=` مُبيَّض، خريطةُ اعتماديّاتٍ للمُعَدّ فقط، ملخّصُ التوفّر وفتراتُ الانقطاع، SLO، عمليّاتُ الطابور، تاريخُ المجدولات والنسخ، تاريخُ الإصدارات) + `admin/errors/logs` (بحثُ سجلّاتٍ مقيَّد).
- القوى العاملة: نظرةُ منظّمة (8 بطاقات) + ملفُّ الموظف + تجميعُ القسم + توازنُ الحمل + الاختناقات.
- الجودة: قشرةٌ بتبويبات على `admin/quality` (`?tab=`) + معاينةُ الدمج + جدولُ النتائج بالشدّة.
- الإعدادات: معاينةُ التغيير، تاريخُ المفتاح، الاستيراد، بطاقةُ أعلامِ التشغيل (**روابطُ فقط، بلا مفاتيحِ تبديل**).
- الحوادث: `modules/custom/incidents.blade.php` عبر الخطّاف القائم (`modules/show.blade.php:84`) — ترويسة، أثر، دليلٌ مرتبط، حلٌّ ومراجعةُ ما بعد الحادث.

**صفحاتٌ تُعدَّل**
- `audit/index`: نظرةٌ تنفيذيّة (12 عدّاداً بتجميعِ SQL لا بتحميل الصفوف)، فلاتر (شدّة/تصنيف/دور/شركة/مشروع/`request_id`/حسّاس/فاشل/طوارئ/مدى زمنيّ)، عمودُ `request_id`، شارةُ شدّة، رابطُ التفصيل، «تحقيقاتٌ محفوظة» (نفسُ عناصر `modules/index.blade.php:98-112`).
- `ops/index`: تبنّي `partials.pagehead`، لفُّ الجداول بـ`.tblwrap` (**صفرٌ اليوم**)، «منذ متى» للحالة، طابعُ نضارة، تحويلُ بطاقةِ ذيل السجلّ إلى رابطِ بحث، مصدرُ نبضةِ 24 ساعة من الدِلاء.
- `security/index`: 15 بطاقةً، مدىً زمنيّ بدل 7 أيامٍ مثبَّتة، بطاقةُ النتائج المفتوحة، روابطُ المراكز الفرعية، ربطُ صفوف السجلّ الموحّد بالتفصيل.
- `ops/errors` + `ops/error_show`: 10 بطاقات + رسومٌ (نمطُ `partials/chart_donut` وأشرطةِ `ops/index:80-97`)، مدىً زمنيّ، دورةُ حياةٍ (تحقيق/تجاهلٌ بسبب/حلّ)، إسناد، خطُّ إصدارٍ وانتكاسة، جدولُ ظهورات، مستخدمون متأثّرون، فتاتُ مسار، `request_id` كرابط.
- `activity/*`: إعادةُ تسمية «نسبة الشك» → «مخاطر النشاط الأمني» وفصلُها عن ساعات العمل، وطيُّ أثر الزيارات في `<details>`.
- `admin/quality`, `integrations/index` (زمنُ الاستجابة لكل تكامل)، `webhooks/log` و`hooks` (عمودُ `request_id` وحالةُ المعالجة والجسدُ المنقّى)، `profile` (IP الأخير/العمر/الحالة للرموز)، `partials/livemon` (فتراتُ الانقطاع)، `partials/timeline` (حملُ معرّفِ التدقيق للربط)، `layouts/app.blade.php` (إعادةُ تجميعِ شريط الإدارة من كتالوجٍ واحد + مدخلا Incidents/Alerts).

**عناصرُ مشتركةٌ جديدة**: `partials/timerange.blade.php` (يستهلك مُحلِّلاً واحداً ويقبل `from/to` القديمة)، `partials/freshness.blade.php` (وُلِّد في/مخبوءٌ حتى/`?fresh=1`)، شارةُ شدّةٍ موحّدة.

**قيودُ الواجهة (يحرسها اختبار):** كلُّ صنفٍ يُكتب يجب أن يوجد في `app.css` وكلُّ `var()` معرَّف (`StyleVocabularyTest`, `DesignSystemGuardTest`)؛ الجداولُ داخل `.tblwrap`؛ الحالاتُ الفارغة عبر `partials.empty` (اليومَ في ثلاثة مواضعَ فقط من المراكز، و`activity/index:20` يستعمل `@foreach` بلا فرعٍ فارغ)؛ `<label class="vh">` للفلاتر (501 عنصرَ `<th>` بلا `scope`)؛ `.ltr` يستعمل `unicode-bidi:embed` — تُضاف `.bdi` بـ`isolate` للصفحات الجديدة بدل تغييرٍ عامٍّ يمسّ 65 ملفاً؛ الفلاتر الثانوية داخل `<details>` على الجوال (نمطُ `modules/index.blade.php:67-80`)؛ ولا رمزَ `--info` في اللوحة → يُضاف رمزاً جديداً في `:root` وفي `html[data-theme=dark]` معاً أو يُستعمل `.bdg.g`.

---

### TESTS

> القاعدة الحاكمة (CLAUDE.md): **كلُّ عيبٍ اختبارٌ يفشل أولاً**، والحزمةُ خضراءُ على المحرّكين. أضِف الاختباراتِ في ملفاتٍ موضوعية بأسماءِ ادّعاءٍ إنجليزيةٍ ورسائلِ تأكيدٍ عربية.

**عابر**
1. `Redactor`: وحدةٌ لكل مفتاحٍ من قائمة المواصفة (password…database_password) داخل مصفوفاتٍ متداخلة، ولكل نمطِ قيمة (Bearer، JWT، كتلةُ PEM، `?token=`، مفتاحُ `lyn_`)؛ ثم اختبارٌ من الطرف للطرف: التقاطُ استثناءٍ يحمل `Bearer` و`password=` لا يترك أثراً في `error_events.message/url/trace` ولا في نصِّ الإشعار. تُبقي `EnterpriseHardeningRound3Test` و`ErrorLeakAndNumericBoundRound5Test` خضراوين.
2. مدىً زمنيّ: كلُّ سابقة (1h/6h/24h/7d/30d/90d) تُنتج حدوداً صحيحةً بتوقيت `Asia/Kuwait`؛ مدىً مخصّصٌ مقلوبٌ يُرفض بلا 500؛ **`from/to` القديمةُ في التدقيق تُرشِّح كما كانت** (تمديدُ `SecurityCenterTest::test_audit_filters_by_who_did_what_and_when`).
3. تعيينُ الشدّة: كلُّ قيمةٍ في المفردات الستّ تُعيَّن إلى `info|low|medium|high|critical` بتسميةٍ عربية، والمجهولُ → `info` بلا استثناء.
4. شاشةُ الارتباط: المالكُ يرى الطبقاتِ الستَّ لمعرّفٍ واحد؛ حاملُ علَم `audit` يرى التدقيقاتِ فقط؛ الموظف 403؛ معرّفٌ مجهول → حالةٌ فارغة؛ معرّفٌ من العميل يحوي HTML يُهرَّب؛ **لا ترويسةَ اعتمادٍ في الناتج**؛ و`error_events.request_id` مفهرَس (`Schema::getIndexes`).
5. التصدير: تصديرُ أيّ مركزٍ يحترم `security.freeze_exports` (423)، ويُطبِّق التنطيق، ويكتب أثرَ «تصدير»، ويقنّع حقولَ `sec`، ويحيّد `=+-@`؛ **وتصديرُ الوحدات الجَماعيّ يُصبح خاضعاً للتجميد أيضاً (يفشل أولاً)**.

**التدقيق** — تفصيلُ حدثٍ: 404 خارج نطاق الشركة/العميل، 403 بلا علَم، فرقٌ مقنَّعٌ بـ`hub_can`/`hub_field_mode`، تحقّقُ تجزئةٍ محليّ يكشف عبثاً بعد `UPDATE` مباشر · القائمةُ والعدّادات تُرشَّح بالوحدات المرئية ونطاقِ العميل (**يفشل على الشيفرة الحالية**) · كلُّ فلترٍ جديد لا يوسّع النطاق · التطبيعُ يملأ الأعمدةَ الجديدة بينما تبقى صفوفُ الماضي مصنَّفةً عند القراءة و`verifyTail` سليمة · كتاباتُ الطرفية `source='console'` · تحقيقاتٌ محفوظةٌ بوحدةٍ زائفة `audit` (سقف 30، مُدقَّقة) · كلُّ تشغيلِ تحقّقٍ (آليٍّ ويدويّ) يكتب صفَّ تاريخٍ واحداً، **وتحميلُ الصفحة لا يُشغّل فحصاً كاملاً** (عدُّ استعلاماتٍ/صفوف) · محلِّلُ التغطية للمالك يُعلِن «يحتاج مراجعة» حيث الدليلُ ساكن ويكشف عمليّةً غيرَ مؤرَّخة (تغييرُ حالة الخطأ) حتى تُؤرَّخ.

**الأمن** — تسويةُ النتائج: صفٌّ واحدٌ لكل (code, entity) يحفظ `first_seen_at` ويحدّث `last_seen_at` ويُحلّ آلياً عند زوال الشرط · الإقرارُ/الحلُّ للمالك ومُدقَّق · لقطةُ الوضع تكتب صفّاً يومياً (تكرارُ التشغيل لا يُضاعف) وتُظهر **حالةً فارغةً صادقة** قبل أول لقطة · مركزُ الهوية يُظهر كلَّ عاملٍ بنقاطه ويُنجز بعددِ استعلاماتٍ ثابت (لا حلقةَ مستخدم) ولا يسرّب حقولَ كلمةِ مرور/TOTP · مركزُ الجلسات: ترتيبٌ ثابتٌ عبر الصفحات، «إنهاءُ البقيّة» يُبقي جلستي، «إنهاءُ الكلّ» يطلب تصعيداً ثم ينجح ويُدقَّق · تصنيفُ الأجهزة يشمل المحذوفةَ ناعماً · ذكاءُ IP يمرّ على MySQL الصارمة (`GROUP BY … HAVING COUNT(DISTINCT …)`) · مركزُ الرموز: تصنيفٌ صحيح، إلغاءٌ إداريٌّ بتصعيدٍ ومُدقَّق، `ApiAuth` يرفض `revoked_at`، ولا نصَّ رمزٍ في الصفحة · `rotated_at` يُختم عند تغيّر السرّ لا عند تعديل ملاحظة · قواعدُ التنبيه المزمَّنة تُطلق مرّةً ثم تحترم التهدئة · **تصعيدٌ (يفشل أولاً)** على `security.user.revoke` وإيقافِ الحساب ومفاتيحِ الإعدادات الخطرة.

**التشغيل** — التقاطُ RED: صفٌّ واحدٌ لكل (دلو، سطح، طريقة، مسار) لاستجابات 200/404/500 مع `err4/err5/slow/sum_ms/max_ms`؛ تخطّي `files/*`; **فشلُ الكتابة لا يكسر الاستجابة**؛ والتقاطُ البطيء القديم يبقى أخضر · النسبُ المئوية من مدرَّجٍ مُعلَنٍ أنّه تقريبيّ، ودون الحدّ الأدنى تُعرض «لا توجد بياناتٌ كافية» بلا أرقام · الانحدارُ يُعلَن فقط عند استيفاء نافذتين وحدٍّ أدنى للعيّنة · كشفُ الحوادث: كلُّ إشارةٍ تفتح حادثةً واحدةً ببصمةٍ وتُلحق دليلاً عند التكرار وتكتب «تعافت الخدمة» عند الزوال، **وعدَّادُ الحوادث الأمنية لا يشمل حوادثَ التشغيل** · تاريخُ المجدولات يُشتقّ من نقاط `metric_points` مع بقاء دلالة `HealthModelTest` · العتباتُ من الإعدادات تُغيّر النغمةَ فعلاً والافتراضاتُ تُعيد سلوكَ اليوم · **تحمّلُ العطل: `/admin/ops` يردّ 200 بشاراتِ «غير متاح» بعد إسقاط `outbox`/`error_events`** (يفشل اليوم) · ميزانيةُ استعلاماتٍ للصفحات الجديدة (نمطُ `ScreenPerformanceTest`).

**الأخطاء** — سقفُ الظهورات (60 ظهوراً → N صفّاً، الأحدثُ باقٍ، العدُّ 60) وحملُها `request_id/route/duration_ms` · بصمةٌ محسَّنة: الطوابعُ الزمنية وسلاسلُ الاستعلام والمعرّفاتُ القصيرة وJWT تُوحَّد، **والمختلفُ حقيقةً يبقى منفصلاً** · الانتكاسة: حلٌّ (يختم `resolved_*`) ثم ظهورٌ → إعادةُ فتحٍ + `regressed_at` + إشعارٌ مرّةً · التجاهلُ بلا سببٍ 422، وبسببٍ يُدقَّق، **والحرجُ المتجاهَل يبقى في `Health::errors`** · **`CRITICAL` يتجاوز سقفَ العاصفة (يفشل أولاً)** · بحثُ السجلّات محدودُ البايتات ولا يقرأ الملفَّ كاملاً (والمسارُ الميت يُصحَّح).

**القوى العاملة والجودة والإعدادات والحوادث** — فصلُ العنوان الأمنيّ عن الإنتاجية (لا بطاقةَ عملٍ تقرأ `riskProfile`) · بطاقاتُ النظرة تساوي الاستعلامَ المباشر وتُمنع عن المُنطَّقين · `completed_at` يقود On-time (تعديلٌ بعد الإنجاز لا يقلبها) · خطُّ زمنِ الموظف لا يسكب الزيارات ويحترم رؤيةَ الوحدات · شدّةُ جودةِ البيانات ليست كلُّها «حرج» · الدمجُ: معاينةٌ لا تكتب، وتنفيذٌ يكتب أثراً صريحاً بعددِ المنقول ولا يحذف حذفاً صلباً · **مؤشّراتُ البذرة لا تُرشِّح حالةً خارج خيارات السجلّ (يفشل على سبعةٍ اليوم)** · تقدّمُ OKR رقمٌ واحد · الإعدادات: القيمةُ الفعّالة تطابق الحدودَ في الشيفرة، الاستعادةُ تحذف الصفَّ (لا `''`) وتطلب تصعيداً للمفاتيح الخطرة، المعاينةُ لا تكتب شيئاً، الاستيرادُ يرفض المجهولَ والسرّيَّ، `hub:set n8n.key` يُشفَّر (**يفشل اليوم**) · الحوادث: الإقرارُ عبر `record_acks`، الحلُّ يشترط سبباً جذرياً للحرج/العالي في المسارات الثلاثة (`setStatus`، `update`، API الوارث)، الدليلُ المرتبط يُقيَّد بالصلاحية، وفتحُ حادثةٍ من حدثٍ يدمج ببصمةٍ ويُطلق `HubEvents`.

**قيودُ العُدّة التي تسقط الحزمةَ إن أُهملت**
- `SettingsCenterTest` (كلُّ مفتاحٍ حيٍّ مُعلَن والعكس) · `EnterpriseHardeningRound1Test` (كلُّ جدولٍ في النسخة أو `EPHEMERAL`) · `AllScreensSmokeTest` (كلُّ GET يصمد لأربعة قرّاءٍ ولمراجعَ معلّقةٍ ونصوصٍ طويلة؛ المُعاملاتُ عبر `hub_str`) · `WritingRoutesAuthzRound7Test` (المشاهدُ يُرفض) و`UnreachableActionsRound7Test` (لكل مسارِ كتابةٍ بابٌ في الواجهة) · `GuardSpeaksInPlaceTest` (صفحةٌ عربيةٌ لكل رمزِ إيقاف) · `MysqlPortabilityTest` (لا PRAGMA، لا `LIKE` على JSON، لا `orderBy('created_at')` على `audits`) · `ColumnWidthGuardTest`/`ColumnFitsItsWriterTest` (عرضُ العمود يطابق ما يُكتب فيه) · `VersionConsistencyTest` + خطّاف `pre-push` + وظيفةُ CI (رفعُ `VERSION` + سطرُ README) · بوّابةُ انحراف `docs/openapi.json` في CI · تشغيلُ `phpunit.mysql.xml` قبل الدفع.
- مزالقُ مثبَتة: `Http::fake` **يكدّس** والأولُ يفوز · لا `preventStrayRequests` · `hub.dns` يمرّر كلَّ مضيفٍ افتراضياً · `seedCore` يُطفئ التصعيدَ وساعاتِ العمل · الإعداداتُ عبر `hubSetting()` فقط (وإلا بقي الكاش) · ترتيبٌ صريحٌ في كل قائمةٍ جديدة · `hub_test` تُمسح في كل تشغيلِ MySQL — لا تُوجَّه إليها مجسّات.

---

### CLASSIFICATION TABLE

> `EXISTS_STRONG` يُعاد استعماله كما هو · `EXISTS_WEAK` يُطوَّر · `PARTIAL` بعضُ القطع موجود · `OVERLAPS` شيئان يتداخلان فيُوحَّدان · `MISSING` معدوم. **✔ = حالةٌ/دليلٌ صحّحه المُفنِّد وهو المعتمد.**

#### أ) عابرٌ للمراكز (Global · §10–16 · §17–21 · §25 · §29–37)

| البند | الحالة | الدليل | التحقّق |
|---|---|---|---|
| GLOBAL — `X-Request-Id` معرّفاً أوّلياً | EXISTS_STRONG | `Observability.php:18-41` يولّد/يقبل ويُعيد الترويسة؛ `Api::requestId()` `Api.php:80-95` هو المنفذ الوحيد الذي يستعمله `Auditable:104`, `helpers:2525`, `OutboxMessage:21`, `WebhookDelivery:25`, `HubNotification:42` | RUNTIME_VERIFIED |
| GLOBAL — نشرُ المعرّف إلى السجلّات ✔ | PARTIAL | مفهرَسٌ على audits/outbox/webhook_deliveries/notifications_hub؛ `error_events.request_id` **بلا فهرس**؛ غائبٌ عن access_denials/inbound_hook_events/incidents/sessions_log | COMMAND_VERIFIED (PRAGMA) |
| GLOBAL — شاشةُ الارتباط `/system/trace/{rid}` ✔ | MISSING | 361 مساراً: `trace/{module}/{id}` وحدَه وهو `TraceController` (سلسلةُ التسليم `:17-22`)؛ لا استعلامَ بـ`request_id` في المستودع؛ العرضُ نصٌّ فقط في `ops/error_show:42`, `security/index:213` | COMMAND_VERIFIED |
| GLOBAL — مكوِّنُ المدى الزمنيّ ✔ | MISSING | لا `hub_range/hub_period/hub_window`؛ عشرُ آليّات: `AuditController:46-47` (whereDate)، `Api::timeFilters:256-274`، `ModuleController:98-108`، `CapacityController:27`، `SocialController:21`، `V1Controller:318`، ونوافذُ مثبَّتة | COMMAND_VERIFIED |
| GLOBAL — نموذجُ الشدّة ✔ | OVERLAPS | ستُّ مفردات: `ErrorTaxonomy:19-31`, `SecurityEvents:27-66`, `incidents` مذكَّرة `hub.php:7991`, `issues` مؤنَّثة `:2187`, `Risk::bands`, `ActionCenter::RANK`؛ «معلوماتي» غيرُ موجودة | STATICALLY_REVIEWED |
| GLOBAL — نموذجُ الحالة ✔ | PARTIAL | تشغيليّاً: `Health.php:25-42` + `Integrations:25-40` تكفيان بتعيين warning→DEGRADED/critical→UNAVAILABLE؛ دورةُ العطل ثلاثُ قيمٍ فقط (`ErrorCenterController:149`) بلا investigating/ignored | STATICALLY_REVIEWED |
| GLOBAL — محرّكُ التنقية ✔ | OVERLAPS | سبعةُ مُنقّياتٍ ضيّقة (`ErrorLog:23-27,235-244`, `Auditable:50-63`, `SettingController:197`, `Integrations:69`, `Health:416-419`, `HubOutbox:80`)؛ `php -r`: Bearer/JWT/PEM/`password=`/`api_key=` تمرّ بلا تغيير | COMMAND_VERIFIED |
| 10. البحث العامّ في مركز التحكّم ✔ | PARTIAL | `SearchController::mini/index` يبحث سجلّاتِ الوحدات بـLIKE مُنطَّقاً `:196-203`؛ المستخدمون مستثنَون `:187`؛ لا بحثَ بـrid/معرّفِ خطأ/IP/مفتاحِ إعداد | STATICALLY_REVIEWED |
| 11. إعادةُ تنظيم الملاحة ✔ | EXISTS_WEAK | مقاطعُ شريط الإدارة مثبَّتةٌ في `layouts/app.blade.php:133-173`، وقائمةٌ ثانيةٌ في `SearchController:158-175` تختلف فعلاً (تنقص activity/integrations)؛ Incidents/Alerts خارجَ الشريط | STATICALLY_REVIEWED |
| 12. معيارُ الواجهة المؤسّسيّ ✔ | EXISTS_WEAK | L1/L2 موجودان لكن مكرَّرَين يدويّاً: أربعُ صفحاتٍ تُعيد بناء `partials/pagehead` (`security/index:4-7`, `ops/index:5-15`, `ops/errors:4-7`, `admin/quality:4-7`)؛ L3 = نبضةُ 24س فقط؛ L5 معدوم | COMMAND_VERIFIED |
| 12.1 ألوانُ الحالة | EXISTS_STRONG | رموزُ `--ok/--wn/--bad` + `.bdg/.kpi/.flash` مع نسخةٍ داكنة (`app.css:19,106-125,289-303,581-599`)؛ ينقص رمزُ `info` فقط | STATICALLY_REVIEWED |
| 12.2 الجداول الكبيرة ✔ | PARTIAL | ترقيمٌ حاسمٌ في التدقيق والأخطاء والويبهوك (`AuditController:52`, `ErrorCenterController:38-39`, `WebhookController:109`)؛ الأمن/التشغيل جداولُ `.mini` بلا ترقيمٍ ولا فرز، و`SecurityController:57,69,108` ترتيبٌ بلا فاصلِ `id` | STATICALLY_REVIEWED |
| 12.3 الحالاتُ الفارغة ✔ | EXISTS_WEAK | `class="empty"` في 65 موضعاً/52 ملفاً لكن داخل المراكز ثلاثةٌ فقط؛ الأمن/التشغيل `<td class="sub">`؛ `activity/index:20` `@foreach` بلا فرعٍ فارغ | COMMAND_VERIFIED |
| 12.4 نضارةُ البيانات ✔ | PARTIAL | الطابعُ قائمٌ في `admin/quality:43` (`DataQuality::scan()['totals']['at']`) و`livemon:24-25` و13 زرَّ `?fresh=1`؛ لكن `hub_screen` لا يُعيد زمنَ التوليد فلا طابعَ لباقي الشاشات | STATICALLY_REVIEWED |
| 12.5 RTL وعزلُ القيم التقنية ✔ | EXISTS_WEAK | `.ltr{unicode-bidi:embed}` `app.css:50` مستعملٌ 428 مرّة في 65 ملفاً؛ `<bdi>` ثلاثُ مرّاتٍ في سطرٍ واحد (`admin/quality:110`) — `embed` قد يُعيد ترتيبَ الترقيم بجانب العربية | COMMAND_VERIFIED |
| 13. الاحتفاظ والتخزين ✔ | PARTIAL | 13 جدولاً تُقلَّم داخل دالّةٍ اسمُها `pruneNotifications` (`HubAutomation:656-745`) بسطرِ خرجٍ واحد؛ خمسُ نوافذَ مثبَّتة؛ `error_events` يُحذف بعد ضعفِ النافذة **بلا نظرٍ للحالة** `:698-700` | STATICALLY_REVIEWED |
| 14.1 مراجعةُ الفهارس (إجمالاً) | PARTIAL | `audits` مغطّاةٌ إلا `(user_id,created_at)` و`(action,created_at)`؛ `error_events` ينقصه `request_id`؛ `page_visits`/`sessions_log` مغطّيان؛ `access_denials` بلا `(ip,created_at)` | COMMAND_VERIFIED |
| 15. تصميمُ تخزين المقاييس ✔ | EXISTS_WEAK | `metric_points` نقطةٌ واحدةٌ لكل مفتاح/زمن بلا count/sum/min/max؛ التجميعُ عند القراءة (`hub_uptime:4757`)؛ و`api_usage` أُنشئ صراحةً لإبقاء عدّادات الطلبات خارجَه (`2026_09_02_000005:9-13`) | COMMAND_VERIFIED |
| 16. اللقطاتُ المجدولة ✔ | PARTIAL | يوميّاً: `hub:quality-snapshot`→`quality/org`، `marginSnapshot`→`projects/pl_margin`، `Metrics::snapshotAll`؛ كل 5د: `uptime`. **لا لقطةَ صحّةِ نظامٍ ولا وضعٍ أمنيّ** | COMMAND_VERIFIED |
| 17. ضبطُ الوصول (إجمالاً) | EXISTS_STRONG | `hub_is_owner` على ops/errors/security/activity/quality/flows/settings؛ `hub_flag('audit')`؛ `hub_monitor`؛ الموظف/المشاهد 403 على كل `/admin/*` | RUNTIME_VERIFIED |
| 17.1 تقسيمُ الأدوار VIEW/INVESTIGATE/OPERATE/ADMINISTER ✔ | PARTIAL | `RoleController::FLAGS` (users/audit/approve/monitor/secrets/copySec/exp)؛ `monitor` هو علَمُ العرض المفوَّض فعلاً (`ErrorLog::watchers:201-206`) رغم أن مركزَ الأخطاء للمالك فقط | STATICALLY_REVIEWED |
| 18. الأفعالُ عالية الخطورة | EXISTS_WEAK | تصعيدٌ موجود: قفلُ الطوارئ `SecurityController:219`، رفعُ التجميد `:155`، ترحيلٌ/صيانة/كاش `OpsController:161,193,315`، `twofaOff:263`، تصعيدُ الدور `RoleController:229`، الرموز/المفاتيح. **غائبٌ**: إنهاءُ كلِّ الجلسات `:186-203`، إيقافُ حساب `UserController:164`، مفاتيحُ الإعدادات الخطرة | COMMAND_VERIFIED |
| 19. ترويساتُ الأمان | EXISTS_STRONG | `SecurityHeaders.php:11-61` (frame/nosniff/referrer/permissions/HSTS فوق HTTPS/CSP محافظة/no-store) + CSP أشدّ في خدمة المرفقات؛ ينقص `script-src` عمداً (`TECH_DEBT #12`) | STATICALLY_REVIEWED |
| 20. ملاحظةُ API/الويبهوك ✔ | PARTIAL | `api_usage` (طلبات/أخطاء/مللي لكل رمز/يوم) + `webhook_deliveries` (code/ms/error/request_id)؛ `inbound_hook_events.status` **ثابتةٌ 200** والصفُّ يُكتب عند النجاح فقط (`InboundHookController:49,53,70`) فالفشلُ غيرُ مرئيّ | STATICALLY_REVIEWED |
| 21. صحّةُ التكاملات | EXISTS_STRONG | `Integrations::installed/judge/pulse` بستِّ حالاتٍ قانونية + آخرُ نجاحٍ/فشلٍ/خطأ؛ يظهر في `Health::integrations:283-308`؛ ينقص زمنُ الاستجابة لأودو/البريد | STATICALLY_REVIEWED |
| 25. شاشاتٌ تتحمّل العطل ✔ | EXISTS_WEAK | بعد `Schema::drop('outbox')`: `OpsController->index()` يرمي `QueryException` (`:62` بلا حارس) بينما `Health::check` يردّ `UNKNOWN` «الجدول غائب» `:245`؛ `MessagingController:35` مثلُه | RUNTIME_VERIFIED |
| 29. الوصولية ✔ | EXISTS_WEAK | مقوّماتٌ موجودة (skip link، `:focus-visible`، `.vh` 36 مرّة، aria-live، combobox)؛ لكن **501 `<th>` بلا `scope`** و**صفرُ `aria-sort`**، وفلاترُ التدقيق بـ`title=` لا `<label>` | COMMAND_VERIFIED |
| 30. الجوال ✔ | EXISTS_WEAK | `.tblwrap` و`.cards` وشريطٌ قابلٌ للتمرير و`.advbox{min-width:0}` عند 760px موجودة؛ لكن `ops/index` **صفرُ `.tblwrap`**، والفلاترُ غيرُ قابلةٍ للطيّ في المراكز | COMMAND_VERIFIED |
| 31. قابليّةُ تدقيقِ مستوى التحكّم | PARTIAL | مُدقَّق: الجلسات/القفل/التجميد/النسخ/الترحيل/الكاش/تحقّقُ السلسلة/الرموز/الإعدادات (before/after) والتدفّقات. **غيرُ مُدقَّق**: تغييرُ حالة الخطأ و`toTask` (`ErrorCenterController:118-151`)، و`SavedView` CRUD | COMMAND_VERIFIED |
| 32. صفحةُ مركز التحكّم الرئيسة | MISSING | لا مسار؛ الأقربُ `/` (38 استعلاماً) و`/ceo` (119، للمالك) و`/morning` (16) | COMMAND_VERIFIED |
| 33. طابورُ انتباه المالك ✔ | OVERLAPS | `ActionCenter::feed` + `hub_recommendations` (15 منتِجاً، شدّةٌ + ack/snooze عبر `signal_states`) قائمٌ **لكنّ منتجيه كلَّهم أعمالٌ تجارية**؛ إشاراتُ التحكّم في `Health`/`Audit::verifyTail`/`ErrorEvent`/`SecurityPosture` غيرُ موصولة | STATICALLY_REVIEWED |
| 34. الأفعالُ الموصى بها ✔ | PARTIAL | `SecurityPosture::row` يُخرج `{key,label,tone,why,n,fix,url}` لـ19 فحصاً (`:314-317`) — الشكلُ المطلوبُ نفسُه؛ `hub_recommendations` تحمل `action+url`؛ ينقص وصلُها بـ`Health::c` وصفحاتُ الوجهة | STATICALLY_REVIEWED |
| 35. تصديرٌ متّسق ✔ | PARTIAL | `ModuleController::export+streamCsv:601-662` يحوي الخمسةَ (علَم/تجميد 423/تنطيق/تدقيق/قناع+تحييد)؛ **مسارُ التصدير الوحيد** — والتصديرُ الجَماعيّ `:676-687` يتخطّى التجميد والتصعيد | COMMAND_VERIFIED |
| 36. خطُّ الأساس التاريخيّ ✔ | OVERLAPS | مقارنتان قائمتان: `WidgetRegistry:120-131` (7د مقابل السابقة، pct=null عند صفر) و`CeoBoard:263-271`، و`hub_metric_growth:4256`؛ لا دالّةَ نافذتين مشتركة | STATICALLY_REVIEWED |
| 37. كلفةُ الاستعلام ✔ | EXISTS_WEAK | مقيسٌ حيّاً: `Health::check`=55، `hub_uptime`=4 (بسحبِ السلسلة كاملة)، `SysMonitor::pulse`=4 (بسحبِ صفوف 24س)، `/admin/ops` 258/74، `/search` 84 | RUNTIME_VERIFIED |
| 22/24/39 سلامةُ التغيير وتوافقُ القاعدة والنسخة | EXISTS_STRONG | CI ثلاثيّةُ المصفوفة (8.2/8.4 sqlite + 8.4 mysql) + `composer audit` + بوّابةُ انحراف OpenAPI + `hub:schema-check` على MySQL + وظيفةُ VERSION + خطّاف `pre-push` | COMMAND_VERIFIED |
| 23/26/27 الاختبارات ومنعُ الزيف والبنية الزائدة | EXISTS_STRONG | 359 ملفَّ اختبار/~2106 اختباراً بمكانِس شاملة (`AllScreensSmokeTest`, `WritingRoutesAuthzRound7Test`, `UnreachableActionsRound7Test`)؛ ولا صفَّ انتظارٍ ولا خدمةً خارجية (`QUEUE=sync`) | COMMAND_VERIFIED |

#### ب) مركز التدقيق والتحقيق (§1 · 14.1 · 17 · 45)

| البند | الحالة | الدليل | التحقّق |
|---|---|---|---|
| §1 قائمةُ الإبقاء (سلسلة/فرق/فلاتر/تنطيق/نبضات) | EXISTS_STRONG | `AuditController:23-54,71-127`, `Audit::diff:31-79`, `verifyTail:88-137`, `AuditEntry:52-99`, `HubAuditVerify` — ومحروسةٌ بـ`AuditChainTest`/`SecurityCenterTest` | RUNTIME_VERIFIED |
| 1.1 نظرةٌ تنفيذية (12 عدّاداً) | PARTIAL | الموجود: سلامةُ السلسلة + حذف/تصدير/خارج الدوام/IP جديد (`:71-127`, `index.blade:7-29`)؛ `SecurityEvents::counts` غيرُ منطَّقٍ ومقطوعٌ عند `limit*3`؛ و`pulse` يحمّل صفوفَ اليوم كلَّها `:96` | STATICALLY_REVIEWED |
| 1.2 تطبيعُ حدث التدقيق | PARTIAL | موجود: `request_id`, `company_id`, `project_id`, `user_id`؛ معدوم: `category/severity/source/outcome/actor_type/session_id`؛ المصنِّفُ اللحظيّ `SecurityEvents::codeFor:81-105` هو البذرة | COMMAND_VERIFIED |
| 1.3 صفحةُ تفصيل الحدث | MISSING | `route:list --name=audit` → `audit.index` فقط؛ لا `show()` في المتحكّم؛ الروابطُ إلى `m.show` (`index.blade:84`) | COMMAND_VERIFIED |
| 1.4 فلاترُ التحقيق المتقدّم | PARTIAL | الموجود: module/user/action/ip/from/to/q `:42-49`؛ المعدوم: شدّة/تصنيف/دور/شركة/مشروع/عميل/`request_id`/حسّاس/فاشل/طوارئ/وقتُ اليوم | STATICALLY_REVIEWED |
| 1.5 تحقيقاتٌ محفوظة | PARTIAL | `saved_views` + ثلاثةُ مسارات + واجهةٌ كاملة في `modules/index.blade:98-112`؛ لكن `PrefController:175` يرفض ما ليس وحدةً و`SavedView::url:18` يبني `m.index` فقط | COMMAND_VERIFIED |
| 1.6 محلِّلُ تغطية التدقيق | MISSING | لا شيء باسم coverage في التدقيق (الموجودُ لتدفّقاتٍ ودوراتٍ وثنائيّةِ عامل)؛ المدخلاتُ جاهزة: 82 وحدةً كلُّها `Auditable`، و`SecurityEvents::CODES`، ومواضعُ `hub_audit` المحصورة | COMMAND_VERIFIED |
| 1.7 تاريخُ سلامة السلسلة | PARTIAL | النتيجةُ في نبضةٍ واحدة (`heartbeat.audit.meta`)؛ الفشلُ يفتح حادثاً (`helpers:653`)؛ التشغيلُ اليدويّ يعرض ناتجاً عابراً (`OpsController:344-349`)؛ لا جدولَ تشغيلات | COMMAND_VERIFIED |
| 1.8 احتفاظُ التدقيق (بيانات وصفية) | MISSING | لا مفتاح `audit.*` في الكتالوج؛ ووعدُ «تبقى للأبد» في ثلاثة نصوص (`hub_settings:185`, `HubAutomation:686`, `SECURITY_PLATFORM_MAP:48`)؛ ولا مسارَ تقليمٍ أصلاً | COMMAND_VERIFIED |
| 14.1 فهارسُ `audits` | EXISTS_STRONG | اثنا عشرَ فهرساً قائماً (PRAGMA)؛ ينقص `(user_id,created_at)` و`(action,created_at)` فقط | COMMAND_VERIFIED |
| 17 ضبطُ الوصول لمسارات التدقيق | EXISTS_WEAK | `hub_flag('audit')` + تنطيقُ مشروعٍ/شركة `:27-41`؛ **بلا `hub_can(module,'v')` للصفوف** (الاسمُ يُطبع بينما الفرقُ مخفيّ `Audit.php:46-48`) و**بلا `hub_client_ids`** رغم 16 وحدةً بعمود عميل | STATICALLY_REVIEWED |
| 45 اختبارُ القبول — التدقيق | PARTIAL | مُجابٌ: من/ماذا/قبل-بعد/متى/IP/سلامةُ السلسلة. غيرُ مُجاب: حسّاسٌ؟ `request_id`؟ (مخزَّنٌ غيرُ معروض) مرتبطٌ بحادثة؟ عمليّاتٌ بلا تغطية؟ | STATICALLY_REVIEWED |

#### ج) مركز العمليّات الأمنية (§2 · 17 · 18 · 19 · 42)

| البند | الحالة | الدليل | التحقّق |
|---|---|---|---|
| §2 حفظُ القدرات القائمة | EXISTS_STRONG | `SecurityController:25-229`, `SecurityPosture:21-24`, `SecurityRadar:24-117`, `SecurityExposure:33-125`, `SecurityEvents:27-173`, `Sessions:22-39`, قفلُ الحساب `AuthController:200-221` — ومحروسةٌ بستّةِ أصنافِ اختبار | RUNTIME_VERIFIED |
| 2.1 لوحةُ القيادة الأمنية (15 مقياساً) ✔ | PARTIAL | تسعُ بطاقاتٍ + وضعٌ + انكشافٌ + رادار (`:35-52,122-133`)؛ لا مُعامل مدىً (7 أيامٍ مثبَّتة) ولا كاش ولا بطاقةَ نتائجَ حرجة ولا معدّلَ فشلٍ ولا رموزَ خطِرة | STATICALLY_REVIEWED |
| 2.2 تاريخُ الوضع الأمنيّ ✔ | MISSING | لا كاتبَ ولا قارئَ ولا أمرَ لقطة؛ لا صفوفَ `metric_points` لوحدة `security`. **السكّةُ الصحيحة**: سابقةُ `DataQuality::snapshot:308-318` (`module='quality', record_id='org'`) + `hub_metric_latest` يعيد null = «لا قياسَ بعد» | COMMAND_VERIFIED |
| 2.3 محرّكُ النتائج الأمنية ✔ | PARTIAL | الكشفُ لحظيٌّ في `SecurityPosture::row:314-318` (19 فحصاً) وفي `apiStale:208-221`/`vaultRotation:197-206`/`twofaPrivileged:122-139`؛ لا ثباتَ ولا first/last ولا ack. **سكّةُ الإقرار موجودة**: `ActionCenter::disposition:151-198` فوق `signal_states` | STATICALLY_REVIEWED |
| 2.4 مركزُ مخاطر الهوية ✔ | PARTIAL | `SecurityExposure::map` مميّزون فقط وبسقف 40؛ `users.index` يعرض دوراً/2FA/جلسةً حيّة/آخرَ دخول (`users/index.blade:41-62`)؛ لا جدولَ عابراً لكل المستخدمين. **تحذير**: `Support\Identity` ومسارات `identity.*` محجوزةٌ لهويّة المنتجات | STATICALLY_REVIEWED |
| 2.5 مراجعةُ الصلاحيات المميّزة | PARTIAL | المكوّناتُ موجودة (مالكون/أعلامٌ خطِرة/نطاق all/خمولُ 60 يوماً/تغييرُ أدوارٍ من `audits`)؛ لا صفحةَ مراجعةٍ ولا شرائحَ 30/60/90 ولا حسابَ «صلاحيةٍ غيرِ مستعملة» ولا إقرارٍ محفوظ | STATICALLY_REVIEWED |
| 2.6 مركزُ التحكّم بالجلسات ✔ | EXISTS_WEAK | 25 صفّاً بلا فلاترَ ولا ترقيم (`:55-66`)؛ `Sessions::revokeAll` يُعاد تنفيذُه في أربعة مواضع؛ نافذةُ 30 دقيقةً مكرَّرةٌ أربع مرّات؛ لا `revoked_at/by/reason` والخروجُ الذاتيّ يُسجَّل «مُلغاة» | COMMAND_VERIFIED |
| 2.7 تصنيفُ ثقة الأجهزة | PARTIAL | `user_devices` فيه trust/kind/first_ip/last_ip/first_seen_at/deleted_at وفهارسُه كافية؛ لا صفحةَ مالكٍ ولا مصنِّفاً (known/new/suspicious/revoked) — بلا حاجةٍ لتغيير مخطّط | COMMAND_VERIFIED |
| 2.8 ذكاءُ IP داخليّ ✔ | PARTIAL | `user_ips` (hits/last_seen) + `SecurityRadar::threats` + تجميعُ `SecurityController:75-79`؛ «أوّلُ ظهور» مشتقٌّ أصلاً من `audits(created_at,ip)` و`user_devices.first_ip`؛ الناقصُ فعلاً فهرسُ `user_ips(ip)` لا العمود | COMMAND_VERIFIED |
| 2.9 مركزُ أمن رموز API | PARTIAL | `api_tokens` = id/user/name/hash/expires/last_used/scopes/allowed_ips فقط؛ لا `last_ip` ولا `revoked_at`؛ الإلغاءُ حذفٌ صلبٌ `ProfileController:116-126`؛ لا مسارَ إدارةٍ إطلاقاً | COMMAND_VERIFIED |
| 2.10 صحّةُ الأسرار | PARTIAL | `vault_secrets` بلا `rotated_at` (القِدَمُ من `updated_at`, `SecurityPosture:200`)؛ الاستعمالُ من أثر «عرض حساس» (`ModuleController:401`)؛ القيمةُ لا تُطبع أبداً (`VaultGuardTest`) | COMMAND_VERIFIED |
| 2.11 تدفّقُ الحادثة الأمنية ✔ | PARTIAL | `hub_security_incident:559-598` يفتح/يدمج ويُلحق `meta.events`؛ **لا قالبَ يعرض `meta.events`**؛ لا زرَّ فتحٍ من حدث؛ والتدفّقُ المبذور «حادث حرج» يعمل على `severity=حرج` فيولّد مهمّةً بالفعل | STATICALLY_REVIEWED |
| 2.12 قواعدُ تنبيهٍ أمنية | PARTIAL | المحرّكُ يقارن عموداً في جدول وحدة (`HubAutomation:405-424`) ويعمل يوميّاً 06:00؛ لا نافذةَ ولا عتبةَ ولا تهدئة؛ `notifications_hub.kind` 120 حرفاً يسع `secrule:<uuid>` | COMMAND_VERIFIED |
| 18 (رفعُ القفل / رفعُ التجميد / الترحيل) | EXISTS_STRONG | `SecurityController:215-229`, `:143-166`, `OpsController:190-241` (تصعيدٌ + نسخةٌ قبل الترحيل + تدقيق)؛ الاستعادةُ CLI فقط (لا مسارَ ويب) | COMMAND_VERIFIED |
| 18 (إنهاءُ كلِّ الجلسات / إيقافُ حساب / إعدادٌ خطِر) | EXISTS_WEAK | `SecurityController:186-203` بلا تصعيدٍ ويُعيد تنفيذَ `Sessions::revokeAll`؛ `UserController:164` إيقافٌ بلا تصعيد؛ `SettingController:97-200` بلا تصعيدٍ رغم تصنيف `SecurityEvents:98` | STATICALLY_REVIEWED |
| 42.1–42.4 (وضعٌ/انكشافٌ/بلا 2FA) | EXISTS_STRONG | `SecurityPosture::summary` (`security/index:48-78`)، `SecurityExposure::map` (`:92-134`)، عدُّ المميّزين بلا 2FA `:122-139` + `users.index?twofa=off` | STATICALLY_REVIEWED |
| 42.2/42.5/42.6/42.7/42.8 (نتائجُ حرجة/سلوكٌ مريب/أسرارٌ قديمة/رموزٌ خطِرة/حوادثُ مفتوحة) | EXISTS_WEAK | ترتيبُ «bad» أولاً بلا ثباتٍ ولا إقرار؛ لا ترتيبَ مستخدمين؛ قائمةُ الأسرار محدودةٌ بعشرة على `updated_at`؛ الرموزُ عدَدٌ فقط؛ الحوادثُ عدَدٌ عبر `meta LIKE` | STATICALLY_REVIEWED |
| 42.9 تفصيلُ حدثٍ أمنيّ | MISSING | `SecurityEvents::row:175-183` بلا معرّف؛ لا مسارَ تفصيل — رغم توفّر `audits.id` و`access_denials.id` وفهرسِ `request_id` | COMMAND_VERIFIED |
| 42.10 ربطُ الحدث بالتدقيق/الخطأ/الطلب | PARTIAL | `request_id` على خمسةِ جداول ويظهر في صفوف `SecurityEvents:139`؛ لا شاشةَ ربط؛ و`access_denials` بلا عمود | COMMAND_VERIFIED |

#### د) مركز التشغيل والملاحظة (§3 · 15 · 16 · 21 · 25 · 43)

| البند | الحالة | الدليل | التحقّق |
|---|---|---|---|
| 3.1 ترويسةُ صحّة النظام ✔ | EXISTS_WEAK | `Health::check` يُخرج `version` و`at` (`:391-392`) لكنّ البطاقةَ لا تعرضهما (`ops/index:19-44`)؛ لا حالةَ محفوظةٌ فلا «منذ متى»؛ وتسميةُ المواصفة WARNING/CRITICAL تقابل DEGRADED/UNAVAILABLE | STATICALLY_REVIEWED |
| 3.2 مقاييسُ البنية | EXISTS_STRONG | `SysMonitor::cpu/memory/diskConsumers/tableConsumers` + كتلةُ DB في `OpsController:24-42` — معروضةٌ ومختبَرة (`MonitorDepthTest`) | STATICALLY_REVIEWED |
| 3.3 الإشاراتُ الذهبية (RED بدِلاءِ 5 دقائق) | PARTIAL | `Observability:22,43` يقيس `$ms` لكلِّ طلبٍ ويحفظ البطيءَ فقط؛ `page_visits` مخنوقٌ (GET + مرّة/دقيقتين) فليس معدَّلَ طلبات؛ لا 4xx/5xx ولا مُدَدٍ ولا نسبٍ مئوية | STATICALLY_REVIEWED |
| 3.4 جدولُ أداء المسارات ✔ | PARTIAL | `slowRoutes` تُجمِّع بـ`error_events.url` (fullUrl بمعرّفاتٍ واستعلام) لا بالنمط، و`busyRoutes` بـ`page_visits.path` الخام رغم وجود عمود `route`؛ المُنمِّطُ الصحيح `ErrorLog::routePattern:150-156` | STATICALLY_REVIEWED |
| 3.5 انحدارُ الأداء | MISSING | `grep -rn 'p95|percentile|regress' app/` → صفر؛ ولا تجميعاتٍ تاريخيةً للمقارنة | COMMAND_VERIFIED |
| 3.6 انحدارُ معدّل الأخطاء | MISSING | المصادرُ موجودة (`error_events.kind/count/last_seen/release`، `api_usage.errors`) بلا أيّ مقارنةِ نافذتين | STATICALLY_REVIEWED |
| 3.7 خريطةُ الاعتماديّات | PARTIAL | `Health::dependencies:96-109` خريطةُ قدراتٍ ثابتة + `Integrations::installed` بطاقاتٌ بحالةٍ وآخرِ نجاح/فشل؛ الزمنُ متاحٌ للويبهوك وDB فقط؛ صحّةُ البريد إعداديّةٌ لا اتصاليّة | STATICALLY_REVIEWED |
| 3.8 تاريخُ التوفّر | EXISTS_WEAK | `hub_uptime:4763-4784` يعطي نسبةً/آخرَ فحصٍ/شرارة (null عند غياب القياس) وسلسلةً كل 5 دقائق؛ لا فتراتِ انقطاعٍ ولا ملخّصَ مركزٍ، والسلسلةُ تُحمَّل كاملةً إلى PHP | STATICALLY_REVIEWED |
| 3.9 كشفُ الحوادث التشغيلية | PARTIAL | الإشاراتُ في `Health` (db/storage/scheduler/outbox/integrations/errors)؛ الدمجُ موجودٌ في `hub_security_incident`؛ لكنّ الحادثَ لا يُفتح إلا لفشلِ `hub:audit-verify` (`helpers:654`)، والكلبُ يُشعِر فقط | STATICALLY_REVIEWED |
| 3.10 SLI/SLO وميزانيةُ الخطأ | MISSING | لا مفاتيح `slo.*` ولا شيفرة؛ المتاحُ نغماتٌ ثابتة 99/95 في `hub_uptime` ومعدّلُ خطأ `Api::usage` | COMMAND_VERIFIED |
| 3.11 عمليّاتُ الطابور ✔ | EXISTS_WEAK | عدٌّ بالحالة + `Health::outbox` (queued/failed_24h/oldest/last_error) + `--only` + إعادةُ الفاشل جماعيّاً؛ لا أحدثَ منتظرٍ ولا إنتاجيّةً ولا محاولاتٍ معروضةً ولا إعادةَ عنصر. **وخطرٌ قائم**: `messaging.blade:147` يطبع نصَّ الرسالة الفاشلة ومنها رمزُ OTP | COMMAND_VERIFIED |
| 3.12 عمليّاتُ المجدولات ✔ | EXISTS_WEAK | `Health::JOBS` + `scheduler()` (حالة/عمر/تأخّر) لكنّ الميتا تُكتب فوقَ سابقتها؛ والمدّةُ تُمرَّر من أمرين فقط (`HubOutbox:57,104`, `HubAutomation:72`) والبقيّةُ `ms=null`؛ ولا تُحرَّك النبضةُ عند الفشل | COMMAND_VERIFIED |
| 3.13 عمليّاتُ النسخ ✔ | EXISTS_WEAK | أحدثُ ملفٍّ بالوقت + زرّ + تشفيرٌ/تحقّقٌ + تدوير 14؛ لا تاريخَ ولا محاولاتٍ فاشلة؛ وعتبةُ النضارة متضاربة: 26/50 ساعة (`Health.php:52`) مقابل 30/72 (`SecurityPosture:305`) | COMMAND_VERIFIED |
| 3.14 حالةُ الترحيل | EXISTS_WEAK | قائمةٌ وزرٌّ وتصعيدٌ ونسخةٌ قبلَه وتدقيقٌ وشريطُ تنبيه؛ تنفيذان للكشف (`OpsController:172-184` مقابل `hub_pending_migrations`)؛ لا رقمَ دفعةٍ ولا آخرَ تشغيلٍ (لا طابعَ في جدول `migrations`) | STATICALLY_REVIEWED |
| 3.15 تاريخُ النشر والإصدارات ✔ | PARTIAL | وحدةُ `deploys`/جدولُ `deployments` بحقولٍ كافيةٍ وفهارسَ جاهزة، **بلا كاتبٍ آليّ** (بحثُ الشيفرة: قرّاءٌ فقط)؛ و`.cpanel.yml` لا يكتب شيئاً؛ `Health::version()` هو مصدرُ النسخة | STATICALLY_REVIEWED |
| 3.16 ربطُ الإصدار بالأخطاء | MISSING | لا شيفرة؛ المدخلاتُ حاضرة: `error_events.release` (يُختم `ErrorLog:66`)، `incidents.started_at`، `deployments.deployed_at` | STATICALLY_REVIEWED |
| 3.17 عتباتٌ قابلةٌ للضبط ✔ | PARTIAL | القابل: `ops.slow_ms`, `monitor.timeout`, `outbox.max_attempts`, `ops.watchdog`. المثبَّت: CPU 60/90، ذاكرة 75/90، قرص 85/97 (**ومرّةً ثانيةً في القالب** `ops/index:61`)، DB 500مللي، طابور 20/60، ويبهوك 30د، ونضارةُ نسخةٍ متضاربة | STATICALLY_REVIEWED |
| 43 اختبارُ القبول — التشغيل | PARTIAL | مُجاب: صحّةُ المنصّة/القاعدة، CPU/RAM/قرص، اعتماديّةٌ ساقطة، تأخّرُ الطابور، عملُ المجدولات، مساراتٌ بطيئة (كعدَدِ أحداث). غيرُ مُجاب: p95، انحدار، SLO، أخطاءٌ بعد إصدار | STATICALLY_REVIEWED |

#### هـ) مركز الأخطاء والسجلّات (§4 · 14.1 · 44)

| البند | الحالة | الدليل | التحقّق |
|---|---|---|---|
| 4.1 لوحةُ الأخطاء التنفيذية ✔ | PARTIAL | `ErrorCenterController:42-55` (جديد/قيد المعالجة/اليوم/ظهورات 24س + تجميعٌ بالنوع والشدّة)؛ لا مفتوحٌ إجماليّ ولا حرجٌ ولا انتكاساتٌ ولا متأثّرون ولا رسوم؛ **الأدواتُ حاضرة**: `partials/chart_donut`, أشرطةُ `ops/index:80-97`, `hub_metric_spark` | STATICALLY_REVIEWED |
| 4.2 محرّكُ البصمة | EXISTS_WEAK | `ErrorTaxonomy::fingerprintOf:106-113` يطبّع UUID/hex≥16/أرقام≥4 فقط؛ `php -r`: الطابعُ الزمنيّ يُشوَّه جزئياً وسلسلةُ الاستعلام و«User 42» و`deadbeef` وJWT تبقى | COMMAND_VERIFIED |
| 4.3 ظهوراتٌ محدودة | MISSING | `error_events` 22 عموداً مجمَّعاً؛ `bump():108-113` يزيد العدَّ ويكتب فوقَ url/user؛ `request_id` من أوّلِ ظهورٍ فقط؛ لا جدولَ ظهورات | COMMAND_VERIFIED |
| 4.4 صفحةُ تفصيل الخطأ ✔ | PARTIAL | تعرض الرسالة/الملف/السطر/الأثر/الشدّة/التصنيف/الإصدار/المسار/`request_id`/مقتطفاً محميّاً/مهمّةً؛ ينقص: انتكاسة، متأثّرون، عيّنات، ربطُ حادثة، مسؤول، ملاحظات. **جسرٌ جاهز**: `route('trace',['tasks',$taskId])` عبر `meta.task_id` | STATICALLY_REVIEWED |
| 4.5 دورةُ الحياة | EXISTS_WEAK | ثلاثُ حالاتٍ عربيةٍ فقط (`:149`)؛ إعادةُ الفتح تُبدّل الحالةَ بلا علامة (`ErrorLog:117-121`)؛ لا `resolved_at/by/release` ولا سببَ تجاهل؛ **ولا تدقيقَ للتحوّلات** (`ErrorEvent` بلا `Auditable`) | COMMAND_VERIFIED |
| 4.6 الإسناد | PARTIAL | `toTask:118-144` مُتفرِّدٌ عبر `meta.task_id` ومحروسٌ باختبار؛ لا `assignee_id/priority/due_at` على العطل نفسِه | STATICALLY_REVIEWED |
| 4.7 المستخدمون المتأثّرون | EXISTS_WEAK | عدَّادٌ + `meta.users` ≤50 (`ErrorLog:132-147`) ويُعرَض عدداً فقط؛ ولا أحدثَ متأثّرين؛ ويُحلُّ الاسمُ بـ`User::pluck` لكلِّ المستخدمين مرّتين | STATICALLY_REVIEWED |
| 4.8 فتاتُ المسار | MISSING | لا ذكرَ لـbreadcrumb في التطبيق؛ المادّةُ جاهزة: `page_visits(user_id,path,route,at)` مفهرَسٌ + `audits.request_id` مفهرَس — قراءةٌ فقط بلا التقاطٍ جديد | COMMAND_VERIFIED |
| 4.9 انتكاسةُ الإصدار ✔ | PARTIAL | `error_events.release` يُختم مرّةً (`ErrorLog:66`) و`bump` لا يقرؤه؛ لا `resolved_release/regression_release`؛ **و`deployments.ver/deployed_at` هما تاريخُ الإصدار الجاهزُ للمطابقة** | STATICALLY_REVIEWED |
| 4.10 سلامةُ أثر المكدّس | EXISTS_WEAK | الأثرُ مقصوصٌ 12000 حرف ويمرّ بـ`redact` (مساراتٌ فقط)، والمقتطفُ محميٌّ من اجتياز المسار ومحروسٌ باختبار؛ لا تنقيةَ Bearer/JWT/PEM، و`zend.exception_ignore_args` غيرُ مضمونٍ في الإنتاج | COMMAND_VERIFIED |
| 4.11 بحثُ السجلّات ✔ | PARTIAL | `OpsController:107-118` يقرأ آخرَ 64ك.ب من `laravel.log` — **وسائقُ `daily` يكتب `laravel-YYYY-MM-DD.log` فالبطاقةُ ميتةٌ دائماً**؛ قناةُ JSON مضبوطةٌ بلا قارئ؛ ولا فلاتر | COMMAND_VERIFIED |
| 4.12 إدارةُ الضجيج ✔ | PARTIAL | تجميعٌ ببصمة + سقفُ عاصفة 8/15د + سقفُ jslog اليوميّ + شرائحُ البطء + JS لا يُشعِر؛ لا تجاهلَ/كتمَ لكل بصمة ولا تهدئة؛ **و`tell()` يخرج قبل معرفة الشدّة فيُسقط الحرج**. سكّةُ الكتم للمستخدم موجودة (`HubNotification::MUTEABLE`) و«error» ليست فيها | STATICALLY_REVIEWED |
| 14.1 فهارسُ `error_events` | PARTIAL | موجود: hash فريد/kind/user_id/status/last_seen/category/severity؛ **ينقص `request_id`** (والمركّبُ `(status,last_seen)` بعد قياس) | COMMAND_VERIFIED |
| 44 اختبارُ القبول — الأخطاء ✔ | PARTIAL | مُجاب: أيُّها مهمّ، كم تكرّر، أين في الشيفرة، مهمّةُ الإصلاح. غيرُ مُجاب: الطلبُ المسبِّب (معرّفُ أوّلِ ظهورٍ فقط)، أصُلح قبلاً/انتكس، من يعمل عليه، أيُّ حادثة | STATICALLY_REVIEWED |

#### و) مركز القوى العاملة (§5 · 14.1 · 46)

| البند | الحالة | الدليل | التحقّق |
|---|---|---|---|
| 5.1 فصلُ الأمن عن الإنتاجية | PARTIAL | `riskProfile:132-186` أمنيٌّ في جوهره لكنه يُعيد ساعاتِ العمل في المصفوفة نفسِها ويُعرَض بعنوان «نسبة الشك» بجانبها (`activity/show:13-37`)؛ وساعاتُ الليل 08–16 مثبَّتةٌ خلافاً لـ`Risk::session` الذي يقرأ الإعداد | STATICALLY_REVIEWED |
| 5.2 نظرةُ المنظّمة (8 بطاقات) ✔ | PARTIAL | القطعُ موجودةٌ متفرّقة: `CeoController:45-49` (مفتوح/متأخر)، `CeoBoard::awaitingCalc:38-46` (اعتماداتٌ معلّقة)، `SupportController:37-41` (تذاكر/SLA)، `hub_project_health<55`، `Workday::teamToday`؛ ولا نسبةَ إنجازٍ ولا التزامٍ بالموعد على مستوى المنظّمة | STATICALLY_REVIEWED |
| 5.3 ملفُّ عمل الموظف | PARTIAL | `PortalController::bundle:43-151` (مُنطَّقٌ بـ`hub_can` لكل وحدة) + `peopleKpis:94-135` + `ActivityController::show`؛ لا صفحةَ تجمعُ الأربعةَ ولا «أيامَ نشاطٍ»/«أفعالٍ ذاتِ معنى» ولا عدَّ اعتماداتٍ منجَزة | STATICALLY_REVIEWED |
| 5.4 تحليلُ الاتجاه 7/30/90 ✔ | PARTIAL | كلُّ النوافذ مثبَّتة (30 يوماً في `PerformanceController:96`/`SupportController:42`، 14 في النشاط، 7 في `WidgetRegistry:120-131` مع مقارنةٍ سابقة)؛ لا سلاسلَ مهامّ/تذاكرَ في `metric_points`؛ ووقتُ الإنجاز `updated_at` | COMMAND_VERIFIED |
| 5.5 نظرةُ الفريق بالقسم | PARTIAL | `employees.dept` مفهرَسٌ ومجمَّعٌ في `TeamDirectory:95`؛ لا تجميعَ حِملٍ/إنجازٍ/تأخّرٍ/SLA/معوَّقٍ بالقسم؛ **وخياراتُ `tasks.dept` تخالف `hr.dept`** فالاشتقاقُ يكون عبر `assignee_id→employees.user_id` | COMMAND_VERIFIED |
| 5.6 توازنُ الحمل | PARTIAL | `hub_capacity:2967-3103` يعطي محجوزاً/متاحاً/حِملاً/فوق الطاقة/خاملاً وشرحَ منهجيّة (`capacity.blade:73-79`)؛ لا قوائمَ «بلا إسناد»/«متأخّراتٌ ≥ N» ولا مقياسَ توزيع | STATICALLY_REVIEWED |
| 5.7 تحليلُ الاختناقات ✔ | PARTIAL | إشاراتٌ قائمة: `proj.stalled:3636-3670`, `proj.blockers:3680-3700` (من `work_updates.problems`), `sla.breach`, اعتماداتٌ منتظرةٌ >3 أيام؛ لا مُدَدَ مراحلَ ولا عدَّ إعادةِ فتحٍ ولا «مهامَّ راكدة» | STATICALLY_REVIEWED |
| 5.8 خطُّ زمن الموظف ✔ | PARTIAL | `hub_timeline` لكلِّ سجلّ + ودجت `audits` مُنطَّقة + فلترُ مستخدمٍ في `AuditController:42-48`؛ لا قارئَ منقّى لكل مستخدم، و`SecurityEvents::recent` بلا مُعامل مستخدم، والزياراتُ تُسكب 120 صفّاً افتراضياً | STATICALLY_REVIEWED |
| 14.1 فهارسُ `page_visits` و`sessions_log` | EXISTS_STRONG | `(user_id,at)` + `(at)`؛ و`user_id` + `last_seen_at` + `device_id` — لا تُكرَّر | COMMAND_VERIFIED |
| 46 اختبارُ القبول — القوى العاملة ✔ | PARTIAL | مُجابٌ جزئياً: المتأخّرُ على مستوى المنظّمة (`CeoController:49`)، المعوَّقُ اليوم (`Workday::teamCalc`)، الاختناقاتُ كإشارات. غيرُ مُجاب: نسبةُ الالتزام بالموعد إجمالاً، عدمُ توازنِ الفريق، الاتجاهُ 7/30/90 | STATICALLY_REVIEWED |

#### ز) مركز الجودة والتنفيذ (§6 · 47)

| البند | الحالة | الدليل | التحقّق |
|---|---|---|---|
| §6 مركزٌ موحّدٌ بتبويبات | MISSING | `/admin/quality` جودةُ بياناتٍ فقط رغم عنوانه؛ التنفيذُ موزَّعٌ على `/performance` `/support` `/capacity` `/okrs` `/kpis`؛ ولا مُعامل `tab` في أيّ متحكّم | COMMAND_VERIFIED |
| 6.1 النظرةُ التنفيذية ✔ | PARTIAL | جودةٌ ٪ + اتجاهٌ من اللقطات؛ **نسبةُ الإنجاز موجودةٌ أصلاً كمؤشّرٍ مبذور** «نسبة إنجاز المهام» يُقيَّم بصلاحيّةٍ وتنطيقٍ داخل `hub_kpi_metric` لكنه لا يُعرَض خارجَ `/kpis`؛ ولا عدّادَ «KPI على الهدف» | STATICALLY_REVIEWED |
| 6.2 جودةُ البيانات (توسعة) | PARTIAL | القواعدُ مشتقّةٌ من السجلّ + عدٌّ + عيّناتٌ + `?qc=` يفتح نفسَ الصفوف؛ ينقص: شدّة، أوّلُ رصد، عمر، اتجاهٌ لكل وحدة، مالك | STATICALLY_REVIEWED |
| 6.3 شدّةُ نتائج الجودة | MISSING | لا مفتاح `sev` في أيّ قاعدة (`DataQuality::rules:40-177`)؛ النغمةُ من نسبة 30٪ فقط (`admin/quality:139`) | STATICALLY_REVIEWED |
| 6.4 إدارةُ التكرارات ✔ | OVERLAPS | محرّكان: `QualityController::merge:51-95` (عملاء، يعيد التوجيهَ بجداولَ خام **بلا `hub_audit` صريح ولا معاينة ولا عدِّ مراجع**) و`Identity::merge:257-284` (منتجات، بأثرٍ صريحٍ و`meta.merged_into` واسمٍ بديل) — النموذجُ الثاني هو الصحيح | STATICALLY_REVIEWED |
| 6.5 تحليلاتُ التنفيذ | PARTIAL | لكلِّ شخصٍ 30 يوماً (`peopleKpis:105-134`) + دونات الحالات + قائمةُ المتأخّر؛ لا قارئَ مجمَّعاً (مخطَّط/منجَز/مفتوح/متأخر/معوَّق/٪/التزام/إنتاجية)؛ و«معوَّق» = حالةُ «متوقفة» | COMMAND_VERIFIED |
| 6.6 جدولُ تنفيذ المشاريع ✔ | PARTIAL | `hub_project_health:2169-2261` لكلِّ مشروعٍ (وبكلفةِ ~7 استعلاماتٍ لكلٍّ) و`hub_progress`؛ عمودُ «المعوّقات» جاهزٌ كتجميعِ `work_updates.problems` عبر 14 يوماً (`helpers:3687-3709`)؛ لا جدولَ عابراً للمشاريع | STATICALLY_REVIEWED |
| 6.7 تحليلُ تدفّق المهامّ ✔ | PARTIAL | زمنُ الدورة والإنتاجيّة معرَّفان بنضجٍ لخطِّ التسليم (`Delivery::leadTime:39-81` بوسيطٍ وعلَمِ عيّنة، `cadence:216-243`) وللأعطال (`hub_app_quality:3162`)؛ للمهامّ: لا `completed_at` ولا عدَّ إعادةِ فتح | COMMAND_VERIFIED |
| 6.8 جودةُ التذاكر ✔ | EXISTS_WEAK | `SupportController:36-61` (مفتوح/متأخرُ ردٍّ/متأخرُ حلٍّ/متوسّطات/٪SLA/30 يوماً)؛ **العدّادُ «مفتوح» مقيَّدٌ بحجم الصفحة** (`limit(60)` ثم `count()`)، والمتوسّطاتُ من ≤300 صفٍّ مغلق، و`hub_sla` يسقط إلى `updated_at` عند غياب `meta.resolved_at` | STATICALLY_REVIEWED |
| 6.9 مركزُ KPI ✔ | EXISTS_WEAK | صيغٌ آمنةٌ مُقيَّمةٌ بصلاحيّةٍ وتنطيقٍ وشرحٍ عربيّ ونغمةِ هدف؛ لا مالكَ ولا فترةَ ولا تباينَ ولا تاريخ؛ **وسبعةٌ من 26 مؤشّراً مبذوراً تُرشِّح حالاتٍ غيرَ موجودة** (tasks «متأخرة»، tickets «مفتوحة»، clients «نشط»…) فتقرأ 0.0 وتُصبَغ «على الهدف» | COMMAND_VERIFIED |
| 6.10 مركزُ OKR ✔ | EXISTS_WEAK | تقدّمٌ موزونٌ + إيقاعٌ + لوحةٌ بعدّاداتِ متأخّر/متقدّم؛ **رقمان متناقضان**: `/performance` يقرأ عمود `objectives.progress` بينما `/m/okrs` يحسب `hub_okr_progress`؛ ولا عدَّ متأخّرٍ/متعثّرٍ/راكد؛ ولا `parent_id` | COMMAND_VERIFIED |
| 6.11 اتجاهُ الجودة | EXISTS_WEAK | لقطةٌ يوميّةٌ تكتب `score/defects/clean_modules` لـ`quality/org` وتُقرأ 60 يوماً؛ الرسمُ للدرجة فقط، و`clean_modules` لا يُقرأ، ولا سلاسلَ لكل وحدةٍ فيستحيل «وحداتٌ تتدهور» | STATICALLY_REVIEWED |
| 6.12 اتجاهُ التنفيذ | PARTIAL | لا لقطةَ تنفيذ؛ **النمطُ جاهز**: `marginSnapshot:88-113` يكتب نقطةً لكلِّ مشروعٍ يومياً، و`DataQuality::snapshot` يكتب `org` | STATICALLY_REVIEWED |
| 6.13 أفعالُ التحسين ✔ | PARTIAL | فتحُ السجلّات عبر `?qc=` وروابطِ العيّنات؛ إنشاءُ المهامّ موجودٌ للأخطاء والتعليقات فقط؛ **والنمطُ البيتيّ للربط عمودُ `task_id` على المصدر** (`comments.task_id`, `error_events.meta.task_id`, `documents.task_id`) لا أعمدةُ مصدرٍ على `tasks` | COMMAND_VERIFIED |
| 47 اختبارُ القبول — الجودة | PARTIAL | مُجاب: مشاكلُ الجودة، ما تحسّن (على مستوى المنظّمة)، أهدافٌ متعثّرة (بفلترِ حالة). غيرُ مُجاب: وحداتٌ تتدهور، KPI خارجَ الهدف كقائمة، مهامُّ الإصلاح القائمة | STATICALLY_REVIEWED |

#### ح) مركز إعدادات النظام (§7 · 48)

| البند | الحالة | الدليل | التحقّق |
|---|---|---|---|
| 7.1 نموذجُ معلومات الإعداد ✔ | PARTIAL | الكتالوج: 7 مجموعات/71 مفتاحاً بـ`label/type/def/effect/where/risk` + 29 مفتاحاً داخليّاً نصّاً؛ التحقّقُ خارجَه (`CHECKS`) والسرّيةُ خارجَه (`SECRETS`) **وناقصة**: `n8n.key` يُخزَّن مشفَّراً من الشاشة ونصّاً من `hub:set` | COMMAND_VERIFIED |
| 7.2 لوحةُ الإعدادات ✔ | PARTIAL | عدّادا المفاتيح والخطِرة في القالب `:9-18`؛ **«المعدَّل عن الافتراضي» محسوبٌ اليوم بوجود الصفّ** (`Setting::whereIn(exposedKeys())`) مع سبعةِ مفاتيحَ مبذورةٍ استثناءً؛ لا بطاقاتِ أسرارٍ/تكاملاتٍ/أعلامٍ | STATICALLY_REVIEWED |
| 7.3 القيمةُ الفعّالة ✔ | PARTIAL | «مضبوط/غير مضبوط» للأسرار موجودٌ فعلاً (`form.blade:79`)؛ والنموذجُ الصحيح قائم: `hub_upload_cap:3241-3277` يقول **من** فرض الحدّ؛ لكنّ الحدودَ منثورةٌ في ~24 موضعَ شيفرة لا في الكتالوج | STATICALLY_REVIEWED |
| 7.4 مصدرُ القيمة ✔ | EXISTS_WEAK | يُعرَض للبريد فقط (`messaging.blade:39`)؛ سطحُ البيئة محصورٌ في `APP_NAME`/`MAIL_*`/`SESSION_LIFETIME`/`HUB_OUTBOUND`؛ والنصُّ النثريّ يذكر البديلَ أصلاً فيلزم ترقيتُه إلى حقلٍ آليّ | COMMAND_VERIFIED |
| 7.5 آخرُ تعديل ✔ | PARTIAL | `settings.updated_at` قائمٌ ولا يُعرَض؛ **و`/admin/audit?module=settings` يعرض قبل/بعد لكلِّ مفتاحٍ اليوم** لأن «settings» ليست وحدةً فيتخطّى حارسُ `hub_can`؛ لكن 13 مسارَ كتابةٍ آخرَ لا يسجّل القيم | STATICALLY_REVIEWED |
| 7.6 تاريخُ الإعداد ✔ | EXISTS_WEAK | القراءةُ ممكنةٌ عبر `?module=settings&q=<key>` (`SettingController:177` يكتب المفاتيحَ في `name`)؛ لكنّ `name` مقصوصٌ إلى 300 حرف و`LIKE` بلا فهرس، والكتّابُ الآخرون بلا before/after | STATICALLY_REVIEWED |
| 7.7 معاينةُ التغيير | MISSING | `update:97-187` يكتب فوراً ويومض «حُفظ ما صحّ»؛ لا خطوةَ تأكيد؛ **والأدواتُ جاهزة**: فرقُ `put():189-201`، `risk` لكلِّ مفتاح، ونمطُ `SecurityEvents:97-99` لتمييز الخطِر، وسابقتا معاينةٍ في `EsignController::preview` و`ImportController` | COMMAND_VERIFIED |
| 7.8 استعادةُ الافتراضي ✔ | PARTIAL | حذفُ الصفّ = استعادةٌ (سابقةُ `SecurityController:158,223`)، وحذفُ الصورة يحذف؛ لكنّ النصَّ يُخزَّن `''` والمفتاحُ الثنائيّ `'0'`؛ ولا مسارَ استعادةٍ ولا تصعيد؛ وسابقةُ الاستعادة الجماعية في `HubDemo::purge` (بلا تدقيق) | STATICALLY_REVIEWED |
| 7.9 تحقّقُ الاعتماديّات | PARTIAL | مجموعاتٌ مفروضةٌ في مراكزها (`MessagingController:98-108`, `Odoo::ready:116-120`, `OdooConnectionController:197-218`) وغيرُ مفروضةٍ في شاشة الإعدادات؛ ولا ترتيبَ لنطاقاتِ الخطر ولا لساعات العمل؛ **و`notify.quiet` مبذورٌ بلا قارئ** فلا تُبنَ عليه قاعدة | COMMAND_VERIFIED |
| 7.10 اختباراتُ الاتصال ✔ | PARTIAL | أودو مسارَان لنفس الاتصال، والبريد/تلجرام عبر الطابور الحقيقيّ، والويبهوك يعرض الرمزَ والزمن؛ لا زمنَ لأودو/البريد، ولا تنقيةَ لنصِّ الخطأ، ولا خانقَ على المسارات، ولا اختبارَ لـn8n. **النموذج**: `Uptime::check:38-77` يعيد `up/code/ms/error` بحارسِ SSRF | COMMAND_VERIFIED |
| 7.11 تصديرٌ آمن للإعدادات | MISSING | لا مسارَ ولا أمر؛ المسارُ الوحيد `HubBackup:182-184` يُصدّر كلَّ الصفوف بما فيها نصوصُ `enc:` | COMMAND_VERIFIED |
| 7.12 استيرادٌ آمن للإعدادات | MISSING | `HubImportJson:127-135` يكتب أيَّ مفتاحٍ بلا تحقّقٍ ولا فرقٍ ولا تدقيق (مسارُ استعادةٍ لا استيراد)؛ لا رفعَ ملفٍّ في الواجهة | COMMAND_VERIFIED |
| 7.13 أعلامُ التشغيل ✔ | PARTIAL | لكلِّ علَمٍ مالكُه وتصعيدُه (صيانة/عرض/قفل/تجميدان) وشريطُ تنبيهٍ للعرض؛ **`SecurityPosture::ORDER` لا يحوي التجميدَين** فسطحُهما `security/index` و`Integrations:220` فقط؛ والإعداداتُ تسردها نصّاً بلا روابط | STATICALLY_REVIEWED |
| 48 اختبارُ القبول — الإعدادات ✔ | PARTIAL | مُجاب: ما هو/الافتراضيُّ نثراً/الخطِر/من غيّره (عبر التدقيق)/هل التكاملُ يعمل (عبر مركز التكاملات). غيرُ مُجاب: القيمةُ الفعّالة، الاستعادةُ الآمنة، التصديرُ الآمن، ماذا لو | STATICALLY_REVIEWED |

#### ط) الحوادث والتنبيهات والتدفّقات (§8 · §9 · تقاطعات 2.11/2.12/3.9/31)

| البند | الحالة | الدليل | التحقّق |
|---|---|---|---|
| 8.1 ترويسةُ الحادثة ✔ | PARTIAL | `incidents` فيه عنوانٌ/شدّةٌ/حالةٌ/قائدٌ/بدءٌ/حلٌّ/تعطّلٌ يدويّ؛ لا `detected_at` ولا إقرار. **الإقرارُ بلا هجرة**: `config/hub_acks.php` + `record_acks` + `partials/acks` مُضمَّنٌ أصلاً في `modules/show:82` | COMMAND_VERIFIED |
| 8.2 خطُّ زمن الحادثة ✔ | PARTIAL | `hub_timeline` يدمج التدقيقَ والتعليقاتِ والمرفقاتِ والنسخ وله فرعٌ لكلِّ وحدة؛ و`/trace/incidents/{id}` قائمٌ ومربوطٌ من الترويسة؛ لكن لا أحدَ يكتب صفوفاً بـ(module='incidents') من الأخطاء/الأمن/التنبيهات | COMMAND_VERIFIED |
| 8.3 الأثر ✔ | PARTIAL | حقولٌ نصّيّةٌ + مراجعُ تطبيق/خادم/مشروع؛ `hub_uptime` يحسب التوفّرَ وزمنَ الاستجابة من `metric_points`؛ **تحذير**: `error_events.count/users` عدّاداتٌ تراكميّةٌ مدى الحياة فلا تُشتقّ منها أرقامُ نافذةٍ | STATICALLY_REVIEWED |
| 8.4 الدليلُ المرتبط ✔ | PARTIAL | المفتاحُ متعدّدُ الأشكال قائمٌ في audits/comments/attachments/notifications، و`deployments.incident_id` يظهر تلقائياً عبر `hub_related`؛ **لا جدولَ ربطٍ عامّاً في المستودع** والتدقيقُ مختومٌ فلا يُضاف إليه عمود | COMMAND_VERIFIED |
| 8.5 اشتراطُ الحلّ ✔ | PARTIAL | الحقولُ موجودة (سببٌ جذريّ/خطوات/دروس/وقاية) والتذكيرُ مبذورٌ كقاعدةِ تنبيه؛ لا فرضَ إطلاقاً — و`status_via_action` **مانعٌ لا مُشترِط**، والفرضُ يلزم في ثلاثة مسارات (`setStatus`, `update`, وAPI الوارث) | STATICALLY_REVIEWED |
| 8.6 مراجعةُ ما بعد الحادث | EXISTS_WEAK | `postmortem` منطقيّ + دروسٌ + وقايةٌ + `review_date` (يغذّي رادارَ الانتهاء) وتدفّقٌ مبذور؛ لا ملخّصَ ولا أثرَ ولا صفحةَ مراجعةٍ ولا ارتباطاً بالشدّة | STATICALLY_REVIEWED |
| 9.1 حقولُ قاعدة التنبيه ✔ | PARTIAL | القائم: اسم/وحدة/حقل/عامل/قيمة/رسالة/مستلم/قناة/كل-كم-يوم/حالة؛ **`every` عشريٌّ بالأيام فالتهدئةُ دون اليوم مخزَّنةٌ أصلاً لكنّ المحرّك يقصرها `max(1,(int))`**؛ لا شدّةَ ولا نافذةَ ولا مجالَ ولا مصدرَ غيرَ الوحدات | COMMAND_VERIFIED |
| 9.2 إزالةُ التكرار ✔ | EXISTS_WEAK | دمجٌ عبر `notifications_hub.kind='rule:<id>'` + تصعيدٌ + دمجُ الحادثة 6 ساعات + سقفُ عاصفةٍ + كلبٌ يوميّ؛ **لا تهدئةَ للتدفّقات إطلاقاً**؛ **و`LoginSentry:38-60` يُشعِر كلَّ معتمِدٍ عند كلِّ دخولٍ من IP غيرِ مألوفٍ أو خارجَ الدوام بلا تهدئة**؛ وذاكرةُ الدمج تُمحى بالتقليم | STATICALLY_REVIEWED |
| 9.3 حالةُ التنبيه | MISSING | التنبيهُ صفوفُ `notifications_hub` بعلَم `read` فقط؛ `signal_states` مقيَّدٌ بمفاتيح التوصيات (`ActionCenter:151-157`) | COMMAND_VERIFIED |
| 9.4 تنبيهٌ ← حادثة ✔ | PARTIAL | التلقائيُّ محصورٌ في قفلِ الحساب وفشلِ فحص السلسلة؛ **واليدويّ ممكنٌ بلا شيفرة**: `route('m.create',['incidents','title'=>…,'severity'=>…])` لأن `ModuleController::create:230-246` يملأ أيَّ حقلٍ من الاستعلام؛ ولا فعلَ `incident` في `FlowRunner` | STATICALLY_REVIEWED |
| 2.11/3.9 كشفٌ آليّ وربطُ دليلٍ (تقاطع) | PARTIAL | إشاراتٌ جاهزةٌ في `Health::check`، ودمجٌ جاهزٌ في `hub_security_incident`؛ الناقصُ خريطةُ بصمةٍ لكل إشارةٍ وكاتبٌ في وظيفة اللقطة، وعرضُ `meta.events` | STATICALLY_REVIEWED |
| 2.12 قواعدُ أمنيّةٌ مزمَّنة (تقاطع) | PARTIAL | لا نوعَ قاعدةٍ نافذيّ ولا مقيِّمَ كلِّ 5 دقائق؛ `hub:outbox`/`hub:uptime-check` سابقةُ الإيقاع، و`SecurityEvents::actions()` مفرداتُ الشرط الجاهزة | COMMAND_VERIFIED |
| 31 تدقيقُ أفعال التحكّم (تقاطع) | PARTIAL | `Incident`/`AlertRule` عليهما `Auditable`، والتدفّقاتُ تُدقَّق تحت وحدة `autos`، ودَعوةُ الإشارات مُدقَّقة؛ الناقصُ تدقيقُ أفعال الأخطاء الجديدة وتسجيلُ ألفاظِها في `SecurityEvents::CODES` | COMMAND_VERIFIED |

---

**خلاصةُ الحالات (152 بنداً مصنَّفاً):** `EXISTS_STRONG` 14 · `EXISTS_WEAK` 32 · `PARTIAL` 81 · `OVERLAPS` 5 · `MISSING` 20. **لا بندَ واحدٍ يبدأ من الصفر التقنيّ**: لكلِّ `MISSING` سكّةٌ قائمةٌ تحملُه (`metric_points` للتاريخ، `signal_states`/`record_acks` للإقرار، `saved_views` للتحقيقات، `Api::timeFilters` للمدى، `ModuleController::create` لفتح الحادثة، `Identity::merge` للدمج، `Uptime::check` لاختبار الاتصال).
