# خطّةُ التنفيذ — Lynomia Enterprise Control Plane (spec §40، الأطوار ١–١٠)

> الفرع: `claude/lynomia-hub-enterprise-upgrade-xn4p2t` · الأساس: v2.400.3 · Laravel 12.69 · PHP 8.4 · ٨٢ وحدة في `config/hub.php` · ١٧٧ هجرة.
> هذه خطّةٌ تنفيذية مبنيّة على **حزمة الاكتشاف** (٩ مجالات، مُتحقَّقٌ منها بالأمر والاستعلام والتشغيل)، لا على قراءةٍ مجرّدة.
> كلُّ بندٍ يُسمّي الصنف/المسار/العرض **القائم** الذي يُوسَّع. **لا نظامَ ثانياً لما له نظام** (spec: ABSOLUTE DEVELOPMENT RULE).

---

## ٠) قواعدُ عملٍ تسري على كلّ الأطوار

### ٠.١ ترقيمُ حزم العمل
`WP-<رقم الطور>.<رقم الحزمة>` — كلُّ حزمةٍ وحدةُ عملٍ قابلةٌ للتنفيذ في worktree مستقلّ، ولها مجموعةُ ملفاتٍ مُعلَنة.

### ٠.٢ بوّابةُ نهاية كل طور (spec §41) — عشرُ خطواتٍ لا تُختصر
1. `./vendor/bin/phpunit` (SQLite) أخضر. 2. `./vendor/bin/phpunit -c phpunit.mysql.xml` (MySQL) أخضر.
3. إصلاحُ كل انحدار. 4. مراجعةُ الصلاحيات لكل مسارٍ جديد (owner/flag/monitor + hub_scope + hub_field_mode).
5. قياسُ كلفة الاستعلام (`DB::enableQueryLog` أو اختبارُ ميزانيةٍ على نمط `ScreenPerformanceTest`).
6. الجوّال (`.tblwrap` + `.cards` + فلاتر داخل `<details>`). 7. الوضع الليلي (توكنز فقط، لا ألوانٌ حرفية).
8. RTL (`<bdi class="mono ltr">` لكل IP/rid/hash/URL). 9. الحالاتُ الفارغة (`partials.empty` بنصٍّ صادق).
10. لا سرَّ مكشوف (اختبارُ `assertStringNotContainsString` على الرموز/الترويسات في كل شاشةٍ جديدة).
> **٦–٨ لا متصفّحَ لها في هذه البيئة** → تُغطّى باختبارات المصدر (`DesignSystemGuardTest`, `StyleVocabularyTest`, `MobileDensityTest`) وتُوسَم `COMMAND_VERIFIED` لا `RUNTIME_VERIFIED` (انظر §DEFERRED).

### ٠.٣ انضباطُ الدفعة (CLAUDE.md + CI)
- كلُّ دفعةٍ وظيفية: `VERSION` (minor للميزة، patch للإصلاح) + السطر الأول في `README.md` + مدخلٌ في سجلّ الإصدارات. خطّاف `.githooks/pre-push` يرفض غيرَ ذلك.
- أيُّ مسٍّ لـ`config/hub.php` أو مسارات API أو `VERSION` ⇐ `php artisan hub:openapi --out=docs/openapi.json` **وتُدرَج في الدفعة** (بوّابة CI تسقط بلا ذلك).
- كلُّ جدولٍ جديد يُعلَن في `HubBackup::RAW_TABLES` أو `HubBackup::EPHEMERAL` (يحرسه `EnterpriseHardeningRound1Test::test_backup_covers_every_table_or_declares_it_ephemeral`).
- كلُّ مفتاح `setting()` جديد يُعلَن في `config/hub_settings.php` (`groups` أو `internal`) — `SettingsCenterTest` يحرس الاتجاهين.
- كلُّ أمرٍ مجدول جديد: `Schedule::command()->withoutOverlapping($ttl)->onFailure(fn () => hub_schedule_failed(...))` + `Health::beat('<key>')` + مدخلٌ في `Health::JOBS` (وإلا فهو غيرُ مرئيّ لـ`/healthz`).
- الهجراتُ **إضافيةٌ فقط**، محروسةٌ بـ`hasTable/hasColumn`، وبعرضٍ صريحٍ لكل عمود نصّي (`hub_col_max` يقرأ العرضَ من مصدر الهجرة؛ عمودٌ بلا طولٍ صريح غيرُ مرئيّ لحرّاس العرض).
- الترتيبُ صريحٌ دائماً `orderBy(col)->orderBy('id')`؛ ولا `whereDate()` على عمودٍ مفهرَس (استخدم مدىً `>= from` و`< to`).

### ٠.٤ الملفّاتُ المشتركة (تتعارض حتماً بين الـworktrees) — بروتوكول الدمج
| الملف | من يمسّه | البروتوكول |
|---|---|---|
| `routes/web.php` | كلُّ طور | كلُّ طورٍ يُلحِق **كتلةً معلَّمةً** `// ── Control Plane: Phase N ──` في آخر مجموعة `auth`؛ الدمجُ يأخذ الكتلتين معاً (لا إعادةَ ترتيب). |
| `config/hub_settings.php` | ٢،٣،٤،٥،٧ | إضافةٌ في نهاية المجموعة المعنيّة أو في `internal`؛ الدمجُ يأخذ الاثنين. لا تحرير مدخلٍ قائم. |
| `config/hub.php` | ٥،٦،٨ | حقولُ `incidents`/`rules` وأحداثُ `events` — طورٌ واحدٌ في المرّة؛ بعد الدمج يُعاد توليد `docs/openapi.json`. |
| `app/Support/helpers.php` | **الطور ١ فقط** | كلُّ الدوالّ المشتركة تُولد في الطور ١؛ الأطوارُ اللاحقة تستهلك ولا تحرّر (تفادياً لتعارضٍ في ملفٍ من ٥١٠٠ سطر). |
| `app/Support/Health.php` | ٢،٤،٦ | حزمةٌ واحدة لكل طورٍ تملك `JOBS`/`check()`؛ تُدمج قبل غيرها. |
| `app/Http/Middleware/Observability.php` | ١ ثم ٢ | الطور ١ يُنهي عملَه قبل أن يبدأ الطور ٢ عليه. |
| `app/Console/Commands/HubAutomation.php` (كتلةُ الاحتفاظ) | ٣،٧ | تسلسل: ٣ ثم ٧. |
| `app/Support/ErrorLog.php` | ١ (توصيل Redactor) ثم ٣ (occurrences) | تسلسل. |
| `resources/views/layouts/app.blade.php` (شريط الإدارة) | ١٠ فقط | الأطوارُ ١–٩ لا تلمس الشريط؛ روابطُها تُسجَّل في `hub_admin_links()` (الطور ١) ويرسمها الطور ١٠. |
| `public/css/app.css` | ١ فقط | إضافةُ `--info/--infobg` و`.bdg.i` و`.cc-*` مرّةً واحدة. |
| `README.md` + `VERSION` | كلُّ دفعة | آخرُ commit في كلّ worktree قبل الدفع: rebase ثم رفعُ النسخة (لا تُعدَّل داخل عملٍ متوازٍ). |
| `docs/openapi.json` | مولَّد | **لا يُدمَج يدوياً** — يُعاد توليدُه بعد الدمج. |
| `docs/ARCHITECTURE.md` · `TECH_DEBT.md` · `RUNBOOKS.md` | نهايةُ كل طور | commit توثيقٍ واحدٌ في نهاية الطور. |
| `database/migrations/*` · `tests/Feature/*` | كلُّ حزمة | ملفاتٌ جديدة منفصلة ⇐ **لا تعارض**؛ اسمُ الهجرة يحمل الطور والحزمة لتفادي تصادم الطوابع. |

### ٠.٥ قراراتٌ لازمة (بافتراضٍ موصىً به كي لا يتوقّف التنفيذ)
| # | القرار | الافتراضُ الموصى به |
|---|---|---|
| ق١ | تفويضُ المراكز الجديدة | القراءة (`VIEW/INVESTIGATE`) لـ`hub_monitor()`/`hub_flag('audit')` **مع تنطيقٍ بالشركة** حيث تُعرض بياناتُ الوحدات؛ التشغيلُ والإدارة (`OPERATE/ADMINISTER`) `hub_is_owner()`. لا علمَ RBAC جديد. |
| ق٢ | تخزينُ تصنيف التدقيق | أعمدةٌ مخزّنة (category/severity/source/outcome/actor_type/session_id) **خارج `AuditEntry::SEALED`** — لازمةٌ للعدّ والتصفية على حجمٍ حقيقي؛ ولا تُقدَّم أبداً بوصفها «مختومة». |
| ق٣ | مخزنُ حالة التنبيه | جدولٌ واحد `alert_instances`؛ و`ActionCenter` يقرأ المفتوحَ منه إشارةً (`alert:<dedup_key>`) — فالإقرار سكّةٌ واحدة. |
| ق٤ | مخزنُ نتائج الأمن | `security_findings` (يلزمها severity/first_seen/resolved_at/evidence/owner) — ولا تُسجَّل إشاراتُها في `signal_states` بإقرارٍ ثانٍ. |
| ق٥ | مصدرُ p50/p95/p99 | مدرَّجٌ لوغاريتميّ (histogram) في `http_metric_buckets.hist` مع **إعلانٍ صريح** أنّها تقريبيّة بحدّ خطأِ الحاوية. لا تخزينَ لكل طلبٍ إلى الأبد. |
| ق٦ | الاحتفاظ بالتدقيق | يبقى «للأبد» افتراضياً؛ `audit.retention_days=0` وصفٌ لا مقصّ — لا كودَ تقليمٍ في هذه الدفعة. |
| ق٧ | تدويرُ رمز API لمستخدمٍ آخر | **لا** (يكشف النصَّ الصريح للمدير). المتاح للمالك: إبطالٌ/تعطيل فقط. |
| ق٨ | تاريخُ الإعدادات | جدولُ إسقاطٍ `setting_changes` (لأنّ قيدَ التدقيق واحدٌ لدفعةٍ كاملة و`audits.record_id` من نوع uuid فلا يسع المفتاح). `audits` تبقى الحقيقةَ المختومة. |

---

## ١) الأساساتُ المشتركة — «shared foundation» (الطور ١)

كلُّ طورٍ لاحق يستهلك هذه الواجهات ولا يعيد بناءها. ما لم يُذكَر أنه جديدٌ فهو **قائمٌ يُعاد استعماله**.

### أ) القائمُ الذي يُعاد استعماله كما هو (لا تُكرَّر)
| السكّة | الواجهة | الملف |
|---|---|---|
| معرّفُ الطلب | `Api::requestId(): ?string` + ترويسة `X-Request-Id` + `Log::withContext` | `app/Support/Api.php:80` · `app/Http/Middleware/Observability.php:18-41` |
| السلاسلُ الزمنية | `hub_metric_put(module, record_id, metric, value, at, source, meta)` · `hub_metric_series` · `hub_metric_latest` (null = «لا قياس») · `hub_metric_spark` | `helpers.php:4208-4290` · جدول `metric_points` (فريدٌ على module+record_id+metric+at) |
| نبضاتُ المجدولات | `Health::beat(job, ms, result, note)` + `Health::JOBS` + `hub_schedule_failed()` | `Health.php:48-57,363` · `helpers.php:639` |
| خبيئةُ الشاشات | `hub_screen(prefix, ttl, fn, tables)` (بصمةُ النطاق + ختمُ الجداول + `?fresh=1`) · `hub_cached` · `hub_scope_key` | `helpers.php:2563-2890` |
| الحرّاس | `hub_can` · `hub_scope` · `hub_company_scope` · `hub_client_ids` · `hub_field_mode` · `hub_is_owner` · `hub_flag` · `hub_monitor` · `hub_org_analytics_guard` · `hub_require_stepup` / `_ops` / `_credential` | `helpers.php` |
| الأثر | `Auditable` (إضافة/تعديل/حذف مختومة) · `hub_audit(action, module, record_id, name, extra)` · `SecurityEvents::CODES/codeFor/actions` | `app/Traits/Auditable.php` · `helpers.php:2500` · `SecurityEvents.php:27-105` |
| الإقرار/التأجيل | `ActionCenter::disposition(skey, ack|snooze|dismiss|reopen, until, note)` فوق `signal_states` (مفتاحٌ فريد، مدقَّق، «الحرج لا يُهمَل») | `ActionCenter.php:151-200` |
| الإقرار على سجلّ | `Acks::record()` + `config/hub_acks.php` + `record_acks` + `partials/acks.blade.php` (مُدمَجٌ سلفاً في `modules/show`) | `app/Support/Acks.php:115` |
| العروضُ المحفوظة | `saved_views` + `PrefController::storeView/defaultView/destroyView` + `SavedView::url()` | `PrefController.php:170-225` |
| الخطُّ الزمنيّ للسجل | `hub_timeline(module, record_id, limit)` + `partials/timeline.blade.php` | `helpers.php:2378-2470` |
| مكوّناتُ العرض | `partials/pagehead` · `partials/empty` · `partials/pagination(_simple)` · `partials/flash` · `partials/chart_donut` · أصنافُ `.card/.cards/.stat/.kpi/.tbl/.tblwrap/.bdg(ok|wn|bad|g)` | `resources/views/partials/*` · `public/css/app.css` |
| الخروجُ الآمن | `hub_outbound_ok($url)` + `hub_resolve_pin()` + نمطُ `Uptime::check()` (`up/code/ms/error`) | `helpers.php:4700+` · `Uptime.php:38-77` |
| مقارنةُ نافذتين | منطقُ `WidgetRegistry.php:120-131` و`CeoBoard.php:263-271` (pct = null حين الأساس صفر) | يُستخرَج في WP-1.6 |

### ب) الجديدُ في الطور ١ (ستةُ أعمدةٍ يقوم عليها كلُّ ما بعدها)

**١. `App\Support\TimeRange`** — مدىً زمنيٌّ واحد للمنصّة كلّها
```php
TimeRange::fromRequest(?Request $r = null, string $default = '7d'): self
  // ?range=1h|6h|24h|7d|30d|90d|custom  (+ ?from=&to= للمخصّص، ويقبل التاريخ والوقت)
  // توافقٌ رجعيّ: from/to وحدهما (شاشةُ التدقيق والقدرات والعروضُ المحفوظة) ⇒ custom
  // توافقُ API: created_from/created_to/updated_since (Api::timeFilters) تُقرأ ولا تُغيَّر
->from: Carbon   ->to: Carbon (حصريّ)   ->preset: string   ->label(): string (عربية)
->prev(): self                      // النافذةُ السابقةُ المساوية (spec §36)
->apply($q, string $col = 'created_at')  // >= from و< to — سارغابل، لا whereDate
->days(): float   ->minutes(): int   ->key(): string   // للمفتاح في hub_screen
->toQuery(): array                  // لبناء الروابط مع حفظ باقي المعاملات
hub_range(?Request $r = null, string $default = '7d'): TimeRange   // غلافٌ في helpers
```
المنطقةُ الزمنية: `config('app.timezone')` = Asia/Kuwait (كما يفعل `hub_metric_put`). عرضٌ: `partials/timerange.blade.php`.

**٢. `App\Support\Severity` + `OpStatus` + `IssueState`** — طبقةُ خرائط، لا مفرداتٍ سادسة
```php
Severity::LEVELS  = ['info','low','medium','high','critical'];
Severity::LABELS  = ['معلوماتي','منخفض','متوسط','مرتفع','حرج'];
Severity::TONE    = ['g','g','wn','bad','bad'];
Severity::normalize(?string $any): string   // يغطّي:
  // ErrorTaxonomy: INFO|WARNING|ERROR|HIGH|CRITICAL      (ErrorTaxonomy.php:19)
  // SecurityEvents: info|notice|warning|high             (SecurityEvents.php:66)
  // incidents (مذكّر): حرج|عالي|متوسط|منخفض             (config/hub.php:7991)
  // issues (مؤنّث): حرجة|عالية|متوسطة|منخفضة            (config/hub.php:2187)
  // ActionCenter: حرج|مهم|اطّلاع                        (ActionCenter.php:22)
  // نبرةُ الوضعية: ok|wn|bad                            (SecurityPosture::row)
  // hub_schedule_failed: ERROR|HIGH
Severity::label(l) · tone(l) · rank(l) · atLeast(a,b): bool
OpStatus::fromHealth(string $h): string   // HEALTHY|DEGRADED|UNAVAILABLE|MAINTENANCE|UNKNOWN ← وتسمياتُ spec (warning≈DEGRADED، critical≈UNAVAILABLE) للعرض فقط: **لا تُعاد تسميةُ ثوابت Health** (عقد /healthz محميٌّ باختبار)
IssueState::MAP = ['new'=>'جديد','investigating'=>'قيد التحقيق','in_progress'=>'قيد المعالجة','resolved'=>'محلول','ignored'=>'متجاهَل']
```
> قاعدة: **لا تحويلَ مدمِّرٍ لقيمةٍ مخزَّنة**؛ الخرائطُ للعرض والتصفية والعدّ.

**٣. `App\Support\Redactor`** — مُطهِّرٌ واحد تُفوَّض إليه السبعُ القائمة
```php
Redactor::text(?string $s): string       // مسارات /hook|sign|verify|s|w/{رمز} (القاعدةُ القائمة)
                                         // + ?token=/?key=/?password=/?api_key= في سلسلة الاستعلام
                                         // + Bearer <…> + JWT (eyJ…\..\..) + كتلةُ PEM + /bot<id>:<token>
                                         // + قيمُ SQL المقتبسة (قاعدةُ ErrorLog::safeMessage تبقى)
Redactor::arr(array $a, int $depth = 6): array   // طمسٌ بالمفتاح: password, passwd, pass, secret, token,
   // access_token, refresh_token, authorization, cookie, session, api_key, apikey, client_secret,
   // private_key, smtp_password, database_password (+ Audit::MASKED + AUDIT_SECRET من النماذج)
Redactor::fingerprint(string $v): string // 'sha256:'+16hex — نفسُ صيغة Auditable::auditRedact
```
التوصيل (تفويضٌ لا استبدال): `ErrorLog::redact` · `ErrorLog::safeMessage` · `Health::safe` · `Integrations::pulse` · `HubOutbox` (رسالةُ الخطأ) · `WebhookDispatcher.php:137` · `SecurityRadar::record` (path/detail) · `InboundHookController::receive` (payload قبل الإدراج) · مخرجُ بحث السجلّ (الطور ٣).

**٤. الترابط (Correlation)** — لا معرّفَ ثانياً
```php
Api::requestId(): ?string                       // كما هو — المصدرُ الوحيد
Api::requestSource(): string                    // جديد: web|api|console|hook  (للوسم لا للتخويل)
Api::requestIdIsExternal(): bool                // جديد: العميلُ أرسله ⇒ لا يُفترَض تفرّده
App\Support\Correlation::forRequestId(string $rid, $viewer): array
   // يجمع من: audits · error_events · outbox · webhook_deliveries · notifications_hub
   //          · access_denials · incidents   (كلٌّ بحارسِه ونطاقِه)
   // يعيد صفوفاً موحَّدة: ['at','kind','severity','title','why','url','meta']
```
مسار: `system/trace/{rid}` باسم **`system.trace`** — الاسمُ `trace` مملوكٌ لـ`TraceController` (سلسلةُ التسليم requests→deploys) ولا يُمَسّ.

**٥. مكوّناتُ مركز التحكّم (Blade)** — طبقةُ العرض الموحّدة (spec §12)
| المكوّن | المدخلات | الغرض |
|---|---|---|
| `partials/pagehead` (قائم) | crumb, icon, title, sub, slot | المستوى ١ — الحالة والفعل |
| `partials/cc/kpis.blade.php` (جديد) | `items[] = {label, value, tone, sub, url, hint}` | المستوى ٢ — بطاقاتُ KPI متجاوبة (`.cards`) |
| `partials/cc/trend.blade.php` (جديد) | `series[] = {at, value}`, `unit`, `empty` | المستوى ٣ — شريطٌ/سبارك من `hub_metric_series`، وحالةٌ فارغة صادقة |
| `partials/cc/findings.blade.php` (جديد) | `rows[] = {sev, title, why, fix, url, n, at, actions}` | المستوى ٤ — النتائجُ والتوصية (spec §34) |
| `partials/cc/freshness.blade.php` (جديد) | `at`, `ttl`, `fresh_url` | «آخر حساب · مخبّأ · تحديث» (spec §12.4) |
| `partials/cc/tabs.blade.php` (جديد) | `tabs[] = {key,label,url}`, `active` | `?tab=` مع حفظ باقي المعاملات |
| `partials/timerange.blade.php` (جديد) | `TimeRange $range` | كبسولاتُ المدى + مخصّص |
| `partials/empty` · `pagination` · `chart_donut` · `timeline` (قائمة) | — | تُستعمل ولا تُستنسخ |
CSS: إضافةُ `--info/--infobg` و`.bdg.i` في `:root` وفي `html[data-theme="dark"]` + أصنافُ `.cc-*` — **تعديلٌ واحدٌ يتيم** على `public/css/app.css`.

**٦. أوّليّاتُ القياس (metric primitives)**
```php
hub_metric_bucket(Carbon|string $at, int $minutes = 5): Carbon    // تقريبٌ لأسفل لحاوية
hub_window_pair(int $hours): array   // ['cur'=>[from,to], 'prev'=>[from,to]]  (spec §36)
hub_compare(float $cur, ?float $prev, int $minN = 0): array
   // ['cur','prev','delta','pct'|null,'n_ok'] — pct = null حين الأساس صفر (دلالةُ WidgetRegistry:126)
App\Support\Series::percentiles(array $hist, array $p = [50,95,99]): array  // من مدرَّجٍ لوغاريتميّ
App\Support\Series::mergeHist(array $a, array $b): array
```
قاعدةُ التخزين (spec §15): **`metric_points` هي المخزن**؛ لا جدولَ سلاسلَ ثانياً. الاستثناءُ الوحيدُ المسموح: `http_metric_buckets` (الطور ٢) لأنّ شكلَ `metric_points` (`record_id` uuid + قيمةٌ واحدة) لا يسع بُعدَ المسار ولا (count/sum/max/hist) في حاويةٍ واحدة.

---

## الطور ١ — FOUNDATION

**الهدف:** إنزالُ الأعمدة الستّة أعلاه + الأعمدةِ والفهارسِ التي يحتاجها أكثرُ من طور، بلا أيّ تغييرٍ سلوكيّ مرئيّ عدا صفحة `system.trace`.

### WP-1.1 — TimeRange + المكوّن
- **spec:** GLOBAL TIME RANGE · §12.2 · §36.
- **يُبنى:** `app/Support/TimeRange.php` (جديد) + `hub_range()` في `helpers.php` + `resources/views/partials/timerange.blade.php`.
  يقبل معاملاتِ اليوم كما هي: `from`/`to` (شاشةُ التدقيق `AuditController.php:46-47`، القدرات `CapacityController.php:27`)، و`created_from/created_to/updated_since` (`Api::timeFilters`)، و`d`/`days` (السوشال/API metrics). **لا يُعاد تسميةُ معاملٍ قائم** لأن `saved_views.query` يخزّن سلسلةَ الاستعلام حرفياً.
- **ملفّات:** إنشاء `app/Support/TimeRange.php`، `resources/views/partials/timerange.blade.php`؛ تعديل `app/Support/helpers.php` (دالّةٌ واحدة).
- **هجرة/فهارس:** لا شيء.
- **إعدادات:** لا شيء.
- **مسارات:** لا شيء.
- **اختبارات (تفشل أولاً):** `tests/Feature/TimeRangeTest.php` — كلُّ preset يعطي حدوداً صحيحة بتوقيت Asia/Kuwait؛ `to` حصريّ؛ مدىً معكوس يُصحَّح؛ قيمةٌ عدائية (`range=<script>`) لا تُسقط الصفحة (نمط `HostileInputTest`)؛ `from/to` القديمة تعطي نفسَ نتيجة شاشة التدقيق اليوم (انحدار).
- **القبول:** §43 (نوافذُ المقارنة) · §45 (متى؟) — ويُمكّن كلَّ شاشةٍ لاحقة.

### WP-1.2 — Severity / OpStatus / IssueState
- **spec:** GLOBAL SEVERITY MODEL · GLOBAL STATUS MODEL · §12.1.
- **يُبنى:** `app/Support/Severity.php`، `app/Support/OpStatus.php`، `app/Support/IssueState.php` (خرائطُ عرض) — تُغذّى من `ErrorTaxonomy::SEVERITIES/LABELS`، `SecurityEvents::SEVERITY_TONE`، `Health::LABELS/TONE`، خيارات `config/hub.php` للحوادث والمشاكل، `ActionCenter::RANK`.
- **ملفّات:** ثلاثةُ أصنافٍ جديدة + `public/css/app.css` (توكن `--info` و`.bdg.i`) — **هذا هو التعديلُ الوحيد على CSS في كل الخطّة**.
- **اختبارات:** `tests/Feature/SeverityMapTest.php` — كلُّ قيمةٍ حرفيّة موجودة في المستودع (تُجمَع من الثوابت لا تُكتب يدوياً) تُطبَّع إلى واحدةٍ من الخمس؛ المجهولُ ⇒ `info` بلا استثناء؛ `OpStatus` لا يغيّر ثوابت `Health` (اختبارُ عقد `/healthz` يبقى أخضر).
- **القبول:** §42.2 · §44.1 · §47 (ترتيبٌ واحدٌ للشدّة عبر المراكز).

### WP-1.3 — Redactor + توصيلُ السبعة
- **spec:** GLOBAL DIAGNOSTIC REDACTION ENGINE · §4.10 · §20 · §23.2.
- **يُبنى:** `app/Support/Redactor.php` (جديد) ثم تفويضُ: `ErrorLog::redact/safeMessage`، `Health::safe`، `Integrations::pulse`، `HubOutbox` (نصُّ الخطأ)، `WebhookDispatcher.php:137`، `SecurityRadar::record` (path/detail — اليومَ تُخزَّن رموزُ الروابط العامة المخمَّنة بنصّها)، `InboundHookController::receive` (payload).
- **ملفّات:** إنشاء `Redactor.php`؛ تعديل `app/Support/ErrorLog.php`, `Health.php`, `Integrations.php`, `app/Console/Commands/HubOutbox.php`, `app/Support/WebhookDispatcher.php`, `app/Support/SecurityRadar.php`, `app/Http/Controllers/Web/InboundHookController.php`.
- **اختبارات (تفشل أولاً — ثبت بالتنفيذ أنها تمرّ اليوم بالنصّ الصريح):** `tests/Feature/RedactorTest.php` — كلُّ مفتاحٍ من قائمة spec في مصفوفةٍ متداخلة؛ Bearer/JWT/PEM/lyn_*/`?token=`؛ ثباتُ الطمس (idempotent)؛ والاختباراتُ القائمة تبقى خضراء (`EnterpriseHardeningRound3Test::test_public_tokens_are_redacted_from_error_log`, `ErrorLeakAndNumericBoundRound5Test`, `SilentControlsRound6Test`, `SecretsNeverInAuditRound7Test`).
- **القبول:** §41/١٠ · §42 (لا سرَّ في تشخيص) · §44 (تتبّعٌ آمن).

### WP-1.4 — الترابط: أعمدةٌ + قارئ + صفحةُ `system.trace`
- **spec:** GLOBAL CORRELATION ENGINE · REQUEST CORRELATION VIEW · §42.10 · §45.
- **يُبنى:** توسيعُ `Observability` (وسمُ المصدر والخارجيّ فقط — لا معرّفَ جديد)، `App\Support\Correlation` (جديد)، `app/Http/Controllers/Web/SystemTraceController.php` (جديد)، `resources/views/system/trace.blade.php` (جديد يستعمل `partials/cc/*` و`<bdi class="mono ltr">`).
  الكتّاب: `SecurityRadar::record`, `InboundHookController::receive`, `hub_security_incident` يكتبون `request_id`.
- **هجرة (إضافية، محروسة):** `2026_09_1x_p1_correlation_ids.php`
  - `access_denials.request_id` string(40) nullable + index
  - `inbound_hook_events.request_id` string(40) nullable + index
  - `incidents.request_id` string(40) nullable + index
  - **فهرس** على `error_events.request_id` (العمودُ قائمٌ بلا فهرس — `COMMAND_VERIFIED`)
- **فهارسُ §14.1 المشتركة (نفسُ الهجرة):** `audits(user_id, created_at)`، `audits(action, created_at)`، `access_denials(ip, created_at)`، `user_ips(ip)`. **لا تُضاف** `audits(created_at)`/`(module,created_at)`/`(company_id,created_at)`/`(request_id)`/`(created_at,ip)` — قائمةٌ كلُّها (`COMMAND_VERIFIED` PRAGMA).
- **مسارات:** `GET system/trace/{rid}` اسم `system.trace` — داخل مجموعة `auth`؛ الحارس في المتحكّم: `hub_is_owner()` أو `hub_flag($u,'audit')`؛ ومع الثاني تُخفى مصادرُ المالك (error_events/الإعدادات) وتُنطَّق قيودُ التدقيق بـ`hub_company_ids` + مرشِّح الوحدات المرئية (نمط `WidgetRegistry.php:216-219`). `throttle:60,1`.
- **مجدول:** لا شيء.
- **اختبارات:** `tests/Feature/RequestTraceTest.php` — المالك يرى قيودَ التدقيق والخطأ والصادر والويبهوك والإشعار لمعرّفٍ واحد (يُقرأ من ترويسة استجابةٍ حقيقية)؛ حاملُ علم `audit` يرى التدقيقَ فقط ومنطَّقاً؛ الموظّفُ ٤٠٣؛ معرّفٌ مجهول ⇒ حالةٌ فارغة لا ٥٠٠؛ معرّفٌ مرسَلٌ من العميل يُعرَض موسوماً «خارجيّ» ولا يُشتقّ منه تخويل؛ لا ترويسةَ اعتمادٍ في الصفحة (`assertStringNotContainsString('Authorization')`). ويُوسَّع `ErrorManagementAndCorrelationTest:107-127` (لا يُستنسَخ) ليشمل `access_denials`/`incidents`.
- **القبول:** §45 «هل هناك request ID؟ وهل يتّصل بحادثة؟» · §42.10 · §44 «أيُّ طلبٍ سبّبه؟».

### WP-1.5 — مكوّناتُ مركز التحكّم + سجلُّ روابط الإدارة
- **spec:** §12، §12.1–12.5، §11، §29، §30.
- **يُبنى:** `partials/cc/{kpis,trend,findings,freshness,tabs}.blade.php` (جديدة)؛ `hub_admin_links($user): array` في `helpers.php` (كتالوجٌ واحد `{key,label,route,group,ok}`) — يُستهلَك لاحقاً من شريط الإدارة (الطور ١٠) ومن `SearchController::destinations` (الطور ١٠) بدل القائمتين المتباعدتين اليوم.
  `hub_screen_stamped(prefix, ttl, fn, tables): array{at, data}` (جديدة، لا تمسّ `hub_screen` القائمة) لتغذية `cc/freshness`.
- **ملفّات:** خمسةُ partials + `helpers.php` (دالّتان). **لا تُمَسّ** `layouts/app.blade.php` هنا.
- **اختبارات:** `tests/Feature/ControlCenterUiKitTest.php` — كلُّ صنفٍ يكتبه المكوّن معرَّفٌ في `app.css` (نمط `StyleVocabularyTest`)؛ `cc/trend` بلا نقاطٍ يطبع «سيبدأ القياس من الآن» ولا يرسم صفراً؛ `hub_admin_links` يطابق ما يظهر في شريط الإدارة اليوم (حارسُ انحدار قبل الطور ١٠)؛ الحالةُ الفارغة تمرّ عبر `partials.empty`.
- **القبول:** §41/٦–٩.

### WP-1.6 — أوّليّاتُ القياس والمقارنة
- **spec:** §15، §36، §37.
- **يُبنى:** `hub_metric_bucket`، `hub_window_pair`، `hub_compare` في `helpers.php`؛ `app/Support/Series.php` (percentiles/mergeHist)؛ وإعادةُ توجيه `WidgetRegistry.php:120-131` و`CeoBoard.php:263-271` إلى `hub_compare` (حذفُ نسختين متباعدتين).
- **اختبارات:** `tests/Feature/MetricPrimitivesTest.php` — الحاويةُ تُقرِّب لأسفل بدقّة ٥ دقائق عبر حدود الساعة؛ `pct === null` حين الأساس صفر؛ percentiles على توزيعٍ اصطناعيّ ضمن حدّ خطأ الحاوية المعلَن؛ لوحاتُ `WidgetRegistry`/`CeoBoard` تعطي نفسَ الأرقام بعد التوحيد (انحدار).
- **القبول:** §43 (مقارنةُ نافذتين) · §26 (لا نسبةٍ مخترَعة).

**توازي الطور ١:** `WP-1.1`, `WP-1.2`, `WP-1.3`, `WP-1.5` مجموعاتُ ملفاتٍ **منفصلة** ⇒ أربعةُ worktrees متوازية. `WP-1.4` يعتمد على `1.3` (طمسُ ما يُعرض) و`1.5` (المكوّنات) ⇒ يُدمَج بعدهما. `WP-1.6` مستقلٌّ إلا في `helpers.php`. **التعارضُ الحتمي:** `app/Support/helpers.php` (١.١، ١.٥، ١.٦) ⇒ تسلسلٌ داخليّ: ١.١ ← ١.٥ ← ١.٦، أو دمجٌ يدويٌّ بإلحاقٍ في نهاية الملف؛ و`routes/web.php` (١.٤ فقط) و`public/css/app.css` (١.٢ فقط).

---

## الطور ٢ — OBSERVABILITY

**الهدف:** أن يجيب المالكُ أسئلةَ §43 كلَّها بأرقامٍ من بيانات Lynomia نفسها: RED للمسارات، تاريخُ المجدولات، خريطةُ الاعتماديات، تاريخُ التوافر، وأساسُ SLO — مع حالاتٍ فارغةٍ صادقة قبل تراكم القياس.

**القائمُ الذي يُوسَّع (لا يُستبدل):** `Health` (٥ حالات، `JOBS`، `beat`، `check/ready/live`) · `SysMonitor` (cpu/memory/disk/tableConsumers/slowRoutes/busyRoutes/pulse) · `Observability` (قياسُ `$ms` قائمٌ سلفاً وتُهدَر قيمتُه) · `Uptime` + `hub_uptime` + `metric_points` · `Integrations::installed/pulse` · `OpsController` + `resources/views/ops/index.blade.php` · `api_usage` · `deployments` (وحدة `deploys`).

### WP-2.1 — عتباتٌ قابلةٌ للضبط (تُنجَز أولاً — تعتمد عليها بقيةُ الحزم)
- **spec:** §3.17 · §3.2.
- **يُبنى:** مفاتيحُ في `config/hub_settings.php` بمجموعة «🚧 التشغيل والمراقبة» بقيمٍ افتراضيةٍ **مساويةٍ لثوابت اليوم**، وقراءتُها عبر `setting()` داخل `rescue()` (كما `Observability.php:44`) في: `SysMonitor::cpu` (60/90)، `SysMonitor::memory` (75/90)، `Health::storage` (85/97)، `Health::db` (500ms)، `Health::outbox` (20/60 دقيقة)، `ops/index.blade.php:61` (نسخةُ ٨٥ المضمّنة)، و`SecurityPosture::backupFresh` (30/72h) الذي **يُوحَّد على** `Health::JOBS['backup']` (26/50h).
- **مفاتيح:** `ops.cpu_warn=60`, `ops.cpu_crit=90`, `ops.mem_warn=75`, `ops.mem_crit=90`, `ops.disk_warn=85`, `ops.disk_crit=97`, `ops.db_ms_warn=500`, `ops.queue_age_warn=20`, `ops.queue_age_crit=60`, `ops.http_p95_ms=1000`, `ops.scheduler_late_factor=1` (معامِلٌ على `Health::JOBS`).
- **ملفّات:** `config/hub_settings.php` · `app/Support/SysMonitor.php` · `app/Support/Health.php` · `app/Support/SecurityPosture.php` · `resources/views/ops/index.blade.php`.
- **اختبارات:** `OpsThresholdsTest` — ضبطُ `ops.disk_warn=50` يحوّل ٦٠٪ إلى DEGRADED؛ الافتراضياتُ تعيد سلوكَ اليوم حرفياً (انحدار على `HealthModelTest`)؛ `/healthz` يجيب حين تتعذّر قراءةُ الإعدادات (قاعدةٌ ساقطة).
- **القبول:** §43 «هل CPU/RAM/القرص بخير؟» · §48 «ما أثرُ تغييره؟».

### WP-2.2 — RED: التقاطُ الطلبات في حاوياتِ ٥ دقائق
- **spec:** §3.3 · §15 · §37 · §23.3.
- **يُبنى:** كتابةٌ واحدةٌ في `Observability::handle` بعد `$next` (بجوار التقاط البطء القائم الذي **يبقى** — يحرسه `AuditRound2Test:147`)؛ تطبيعُ المسار عبر **مطبِّعٍ واحد** يُستخرج من `ErrorLog::routePattern` (النسخةُ في `Observability.php:50-53` تُحذف لصالحه).
- **جدولٌ جديد (الاستثناءُ الوحيد المسموح):** `http_metric_buckets` — `bucket_at` datetime، `surface` string(8) web|api، `method` string(10)، `route` string(160)، `count`، `err4`، `err5`، `slow`، `sum_ms` ubigint، `max_ms`، `hist` json nullable، `updated_at`.
  **فهارس:** فريدٌ `(bucket_at, surface, method, route)` + `(route, bucket_at)`. **الكتابة:** UPDATE ثم `insertOrIgnore` (نمطُ `Api::countUsage` الذرّي، `Api.php:285-296`) داخل `try/catch` + `Schema::hasTable` مخبّأ — لا تُبطئ الطلبَ ولا تُسقطه.
  **الإعلان:** `HubBackup::EPHEMERAL` (تليمترياً لا سجلَّ أعمال).
- **مفاتيح:** `retention.http_buckets_days=90` (internal) — التقليمُ في كتلة `HubAutomation::pruneNotifications`.
- **اختبارات (تفشل أولاً):** `HttpMetricsTest` — 200/404/500 تزيد `count/err4/err5` في الحاوية الصحيحة؛ UUID ورقمٌ في المسار يندمجان في صفٍّ واحد؛ `files/*`/`storage/*` مستثناة؛ إسقاطُ الجدول لا يكسر الطلب؛ التقاطُ البطء القائم بلا تغيير؛ تشغيلٌ على MySQL (عرضُ `route` ١٦٠).
- **القبول:** §43 «ما p95؟ ما المسارات البطيئة؟» — وهي **أساسُ** ٢.٣/٢.٤/٢.٥.

### WP-2.3 — لقطةُ النظام كلَّ ٥ دقائق + تاريخُ المجدولات
- **spec:** §3.1 (منذ متى) · §3.12 · §16 · §3.15.
- **يُبنى:** أمرٌ جديد `hub:ops-snapshot` (`app/Console/Commands/HubOpsSnapshot.php`) كلَّ ٥ دقائق:
  - يكتب في `metric_points` (لا جدولَ جديد): `('ops','health','rank')`، `('ops','sys','cpu_pct'|'mem_pct'|'disk_pct'|'db_ms')`، ويومياً `('ops','db','rows:<table>')` من عدّاداتِ `SysMonitor::tableConsumers` المخبّأة.
  - **تاريخُ التشغيل:** يُضاف في `Health::beat()` نفسِها سطرٌ `hub_metric_put('ops', $job, 'run', $ms, now(), 'auto', ['result'=>…,'note'=>…])` — فتاريخُ كل مجدولٍ يوجد بلا جدولٍ جديد؛ ويُضاف توقيتُ `$ms` للأوامر الستّة التي تنبض بلا مدّة (`HubBackup`, `HubUptimeCheck`, `HubQualitySnapshot`, `HubMetricsSnapshot`, `HubDigest`, وخطّافُ `hub:audit-verify` في `routes/console.php`).
  - **كشفُ الإصدار (§3.15):** مقارنةُ `config('hub.version')` بـ`setting('ops.last_version')`؛ عند الاختلاف يُنشأ صفٌّ واحد في `deployments` (وحدة `deploys`) بـ`ver/env/deployed_at/migrations/meta.auto=true` — عبر `Deployment::create` (فيَجري `Auditable` وختمُ البيانات)، **بلا اختراع commit ولا بيانات GitHub**.
- **مجدول:** `Schedule::command('hub:ops-snapshot')->everyFiveMinutes()->withoutOverlapping(20)->onFailure(fn () => hub_schedule_failed('hub:ops-snapshot','QUEUE','ERROR'))` + `Health::JOBS['ops'] = ['لقطةُ التشغيل (كل ٥ دقائق)', 5, 15, 60]`.
- **ملفّات:** أمرٌ جديد · `routes/console.php` · `app/Support/Health.php` (JOBS + beat) · ٦ أوامرَ لإضافة `$ms` · `resources/views/ops/index.blade.php` (جدولُ المجدولات: آخرُ نجاح، آخرُ فشل، فشلٌ متتالٍ، الموعدُ المتوقّع، اتّجاهُ المدّة).
- **مفاتيح:** `ops.last_version` (internal).
- **اختبارات:** `OpsSnapshotTest` — التشغيلُ مرّتين في الدقيقة نفسها **يُحدِّث** ولا يكرّر (المفتاحُ الفريد)؛ `Health::JOBS` يشمل الأمرَ الجديد و`schedule:list` يُظهره؛ نبضةٌ فاشلة تُنتج صفَّ تاريخٍ بنتيجة fail؛ تغييرُ `config('hub.version')` يُنشئ صفَّ نشرٍ واحداً (وتشغيلٌ ثانٍ لا يُنشئ ثانياً)؛ ميزانيةُ استعلاماتٍ للأمر.
- **القبول:** §43 «هل المجدول يعمل؟» · §3.1 «منذ متى؟».

### WP-2.4 — جدولُ أداء المسارات + الانحدار (أداءً وأخطاءً)
- **spec:** §3.4 · §3.5 · §3.6 · §36 · §12.2.
- **يُبنى:** قسمٌ في `ops/index.blade.php` (أو `ops/routes.blade.php` تحت `ops.*` كي يبقى تمييزُ الشريط) يقرأ `http_metric_buckets` بـ`GROUP BY route, method` داخل `TimeRange`، ويرتّب بقائمةٍ بيضاء (`?sort=` بنمط `ErrorCenterController.php:38`)، ويحسب p50/p95/p99 بدمج `hist` (`Series::percentiles`) موسومةً **تقريبية**.
  الانحدار: `hub_window_pair` + `hub_compare` بشرطِ حدٍّ أدنى من الملاحظات؛ وانحدارُ الأخطاء من `error_events` (SUM(count) حسب kind ضمن النافذة) + `err4/err5` من الحاويات، مع عرضِ الدليل (القيمتان وحجمُ العيّنة).
- **مفاتيح:** `ops.regression_pct=30`, `ops.regression_min_n=100`.
- **ملفّات:** `app/Http/Controllers/Web/OpsController.php` (قارئٌ واحد) · عرضٌ جديد/قسم · لا هجرة.
- **اختبارات:** `RoutePerformanceTest` — عيّنةٌ صغيرة **لا** تُوسَم انحداراً؛ تجاوزُ العتبة مع حجمٍ كافٍ يُوسَم مع الدليل؛ الفرزُ يرفض عموداً خارج القائمة؛ صفوفُ UUID مجمَّعة؛ بلا بيانات ⇒ «لا توجد بيانات تاريخية كافية».
- **القبول:** §43 «هل تراجع الأداء؟ هل ارتفعت الأخطاء بعد إصدار؟» (مع ٢.٧).

### WP-2.5 — SLO + ميزانيةُ الخطأ
- **spec:** §3.10 · §26.
- **يُبنى:** بطاقةٌ في مركز التشغيل تقرأ الحاويات (زمن/خطأ) و`metric_points` (توافر) — كلُّها **مطفأةٌ ما لم تُضبط** المفاتيح.
- **مفاتيح:** `slo.availability_pct`, `slo.latency_ms`, `slo.latency_pct`, `slo.error_rate_pct`, `slo.window_days` (كلُّها فارغةٌ افتراضياً = غير مفعَّل).
- **اختبارات:** `SloTest` — بلا ضبطٍ لا تظهر أرقام؛ بضبطٍ وبياناتٍ كافية تُحسب SLI/الامتثال/الميزانيةُ المستهلكة والمتبقّية؛ بتاريخٍ ناقص تُطبع «لا توجد بيانات تاريخية كافية».
- **القبول:** §43 «هل تتحقّق SLO؟».

### WP-2.6 — الاعتماديات + تاريخُ التوافر + عملياتُ الطابور
- **spec:** §3.7 · §3.8 · §3.11 · §21.
- **يُبنى:** بطاقاتُ اعتماديةٍ **من الإعداد الفعليّ فقط** عبر `Integrations::installed()` (تُضاف `integration.<key>.last_ms` في `Integrations::pulse` وتُقاس مدّةُ `Odoo::rpc`)؛ قاعدةُ البيانات والتخزين والكاش والطابور من مكوّنات `Health`؛ البريدُ يظهر فقط إن لم يكن `log/array`.
  التوافر: تجميعٌ في القاعدة على `metric_points_series_idx` بدل تحميل ٣٠ يوماً إلى PHP (`hub_uptime` اليوم يجلب السلسلتين كاملتين)، وحسابُ **فتراتِ الانقطاع** (تتابعُ `up=0`) مع `hub_screen`.
  الطابور: أقدمُ/أحدثُ منتظر، الإنتاجية (المُسلَّم/ساعة من `delivered_at`)، الفشل/ساعة، `attempts`، وفعلان: **إعادةُ عنصرٍ واحد** (`POST admin/ops/outbox/{id}/retry` → يعيد الحالة إلى queued ثم `Artisan hub:outbox --only=<id>` القائم) و**معاينةُ فشل** تُخفي `text` لأنواع `sign_otp|otp` وتُقنّع الوجهة — **ونفسُ المقنّع يُطبَّق على `integrations/messaging.blade.php:147` الذي يطبع اليوم نصَّ الرسالة الفاشلة (وفيه رمزُ OTP)**.
- **هجرة:** لا شيء (اختياريّ لاحقاً: `outbox(state, next_at)`).
- **مسارات:** `POST admin/ops/outbox/{id}/retry` (اسم `ops.outbox.retry`) — `hub_is_owner()` + `hub_require_ops_stepup()` + `hub_audit('إعادة إرسال رسالة صادرة')` + `throttle:30,1`.
- **اختبارات:** `OpsDependenciesTest` + `OutboxOpsTest` — لا بطاقةَ أودو/بريد حين لا إعداد؛ `last_ms` يُخزَّن؛ إعادةُ عنصرٍ تتطلّب step-up وتُدقَّق؛ صفحةُ المعاينة لا تحوي نصَّ رسالةِ OTP؛ فتراتُ الانقطاع تُشتقّ من سلسلةٍ مبذورة؛ نسبةُ التوافر المجمَّعة في القاعدة == `hub_uptime` القديمة.
- **القبول:** §43 «هل اعتماديةٌ ساقطة؟ هل الطوابير متأخّرة؟».

### WP-2.7 — ترويسةُ الصحّة + النسخُ والهجرات والإصدارات
- **spec:** §3.1 · §3.13 · §3.14 · §3.16 · §25 · §37.
- **يُبنى:** ترويسةٌ واحدة (`partials/pagehead` + `cc/kpis`) تعرض الحالة و**منذ متى** (من سلسلة `ops/health/rank`) والمكوّناتِ الساقطة والنسخةَ والبيئة (`Health::check()` يُعيدهما سلفاً ولا يُعرضان)؛ تاريخُ النسخ الاحتياطي (ملفّات + محاولاتٌ فاشلة من تاريخ التشغيل) بلا استعادةٍ من الويب؛ دفعةُ الهجرة الحالية + آخرُ تشغيلٍ (من قيدِ تدقيق «تشغيل الترحيلات») + **توحيدُ** `OpsController::pendingMigrations` مع `hub_pending_migrations()` (نسختان اليوم)؛ ارتباطُ الإصدار (§3.16) من `deployments.deployed_at` مقابل `error_events`/الحاويات/`incidents` بحدٍّ أدنى للملاحظات.
  **تحمّلُ العطل (§25):** كلُّ بطاقةٍ في `try/catch` تُعيد «غير متاح» بدل ٥٠٠ — ثبت بالتشغيل أنّ إسقاط `outbox` يُسقط `/admin/ops` اليوم (`OpsController.php:62` بلا حارس) وكذلك `MessagingController::index`.
  **الكلفة:** تخبئةُ الأقسام الثقيلة بـ`hub_screen_stamped` ٦٠ ثانية + شارةُ `cc/freshness`؛ واستبدالُ تجميع `SysMonitor::pulse` في PHP بقراءةٍ من الحاويات.
- **اختبارات:** `OpsResilienceTest` (إسقاطُ جدولٍ ⇒ ٢٠٠ مع «غير متاح» والحالةُ الحرجة تبقى ظاهرة) · `OpsHeaderTest` (منذ متى/النسخة/البيئة) · `MigrationStatusTest` (دفعة + آخر تشغيل + عدّادٌ واحدٌ متّفق) · ميزانيةُ استعلاماتٍ للصفحة.
- **القبول:** §43 كامل · §41/٥.

**توازي الطور ٢:** `2.1` أولاً (تستهلكه ٢.٣ و٢.٧). ثم متوازياً في worktrees منفصلة: `2.2` (Observability + هجرة + ErrorLog::routePattern)، `2.3` (أمرٌ جديد + Health + أوامرُ النبض)، `2.6` (Integrations + Uptime + Outbox + Messaging)؛ ثم `2.4`+`2.5`+`2.7` بعد دمج `2.2`/`2.3` (تقرأ ما يكتبانه). **تعارضٌ حتميّ:** `resources/views/ops/index.blade.php` (٢.١، ٢.٣، ٢.٤، ٢.٦، ٢.٧) ⇒ يُقسَّم إلى أقسام `@include('ops.parts.*')` في أوّل حزمةٍ تمسّه ثم يعمل كلُّ WP في ملفِّ قسمِه؛ `Health.php` (٢.١، ٢.٣، ٢.٧)؛ `OpsController.php` (٢.٤، ٢.٦، ٢.٧) ⇒ دوالُّ قراءةٍ منفصلة؛ `config/hub_settings.php` (٢.١، ٢.٢، ٢.٤، ٢.٥)؛ `routes/web.php` (٢.٦)؛ `routes/console.php` (٢.٣).

---

## الطور ٣ — ERRORS

**الهدف:** أن يجيب المالكُ §44 كاملاً: أيُّ الأخطاء يهمّ، كم مرّة، كم مستخدماً، أيُّ طلبٍ سبّبه، أين في الشيفرة، هل عاد بعد إصلاح، ومن يعمل عليه.

**القائمُ الذي يُوسَّع:** `ErrorLog` (بصمة + دفع + إشعارٌ بسقفِ انفجار + إعادةُ فتح) · `ErrorTaxonomy` (١٥ فئة، ٥ شدّات) · `ErrorCenterController` + `ops/errors.blade.php` + `ops/error_show.blade.php` · `error_events` (٢٢ عموداً، فهارسُ status/severity/last_seen/category/kind/hash) · `toTask` (مهمّةٌ واحدة بذاكرةٍ في `meta.task_id`) · `page_visits`/`audits` (للفتات) · `Redactor` (الطور ١).

### WP-3.1 — بصمةٌ أدقّ + مطبِّعٌ واحد
- **spec:** §4.2 · §23.2.
- **يُبنى:** توسيعُ `ErrorTaxonomy::fingerprintOf` (اليومَ ثلاثُ قواعد: uuid، hex≥16، أرقام≥4) بـ: طوابعُ الوقت ISO/`Y-m-d H:i:s`، سلسلةُ الاستعلام بعد `?`، hex 8–15، JWT، أسماءُ الملفّات المؤقّتة. **دون** دمجٍ مفرط: الـhash يبقى `kind|fingerprint|file|line`.
  مطبِّعُ المسار يبقى **واحداً** (`ErrorLog::routePattern`) بعد أن حذفت WP-2.2 نسخةَ الوسيط.
- **اختبارات:** `FingerprintTest` (تفشل أولاً على الطوابع وسلسلة الاستعلام) — رسالتان تختلفان بطابعٍ زمنيّ ⇒ صفٌّ واحد؛ رسالتان بصنفٍ أو `file:line` مختلف ⇒ صفّان (منعُ الدمج المفرط)؛ اختبارُ UUID القائم يبقى أخضر.
- **القبول:** §44 «كم مرّة يقع؟».

### WP-3.2 — العيّناتُ المحدودة (occurrences)
- **spec:** §4.3 · §4.7 · §13 · §3.4 (المدّة).
- **جدولٌ جديد:** `error_occurrences` — `id` bigIncrements، `error_event_id` uuid (فهرس)، `occurred_at`، `request_id` string(40)، `user_id` uuid، `route` string(160)، `url` string(400) (مطموسة)، `method` string(10)، `release` string(20)، `status_code` smallint، `duration_ms` uint، `safe_context` json.
  **فهارس:** `(error_event_id, occurred_at)`، `(request_id)`، `(occurred_at)`. **الإعلان:** `HubBackup::EPHEMERAL`.
- **يُبنى:** الإدراجُ في `ErrorLog::capture` بعد `bump()/create` (رخيصٌ: INSERT واحد + حذفُ ما تجاوز السقف كلَّ N دفعة) — مع تمرير `$ms` من التقاط البطء في `Observability` لتصير مدّةُ الطلب حقيقيةً بدل «طبقة» نصّية.
- **مفاتيح:** `errors.occurrences_keep=50`, `retention.error_occurrences_days=30` (internal) + تقليمٌ **في كتلة `HubAutomation::pruneNotifications`** على دفعات (لا أمرَ جديد).
- **اختبارات:** `ErrorOccurrencesTest` — ٦٠ وقوعاً ⇒ بالضبط `keep` صفوفاً أحدثَها مع بقاء `count=60`؛ العيّنة تحمل request_id/route/release؛ نوعُ `slow` يخزّن `duration_ms`؛ إسقاطُ الجدول لا يكسر الالتقاط؛ MySQL (عرضُ ١٦٠/٤٠٠).
- **القبول:** §44 «أيُّ طلبٍ سبّبه؟ كم مستخدماً؟».

### WP-3.3 — دورةُ الحياة + الانحدار + الإسناد
- **spec:** §4.5 · §4.6 · §4.9 · §31.
- **هجرة (أعمدةٌ nullable على `error_events`):** `assignee_id` uuid (فهرس)، `priority` string(12) (مفرداتُ أولوية المهامّ)، `due_at` date، `resolved_at`، `resolved_by` uuid، `resolved_release` string(20)، `regressed_at`، `regression_release` string(20)، `ignored_reason` string(300)، `ignored_by` uuid، `muted_until`، `incident_id` uuid، `notes` text.
- **يُبنى:** توسيعُ قائمة الحالات إلى خمسٍ عبر `IssueState` (الطور ١): «جديد · قيد التحقيق · قيد المعالجة · محلول · متجاهَل» — تعديلُ `ErrorCenterController.php:149` (القائمةُ البيضاء) والمرشِّح في `ops/errors.blade.php:33`. الإهمالُ يشترط سبباً. الحلُّ يختم `resolved_at/by/release=config('hub.version')`. وإعادةُ الفتح في `ErrorLog::bump:117-121` (قائمة) تختم `regressed_at/regression_release`.
  **الإسناد** يستعمل `toTask` القائم (لا نظامَ مهامّ ثانياً) ويمرّر الوصفَ عبر `Redactor`.
  **الأثر (§31):** `hub_audit` لكل انتقالِ حالة وإسنادٍ وإهمالٍ وكتم — اليومَ `ErrorCenterController::status/toTask` بلا أثرٍ إطلاقاً؛ وتُسجَّل الصيغُ الجديدة في `SecurityEvents::CODES` كي تُصنَّف.
- **اختبارات:** `ErrorLifecycleTest` — إهمالٌ بلا سبب ⇒ ٤٢٢؛ حلٌّ ثم عودةٌ ⇒ `regressed_at` + إشعارٌ واحد + بطاقةُ «انحدارات» تعدّه؛ كلُّ انتقالٍ يكتب قيدَ تدقيق؛ الإسنادُ يُدقَّق ولا يكرّر المهمّة.
- **القبول:** §44 «هل أُصلح من قبل؟ هل هو انحدار؟ من يعمل عليه؟» · §45 (تغطيةُ أفعال مستوى التحكّم).

### WP-3.4 — لوحةُ الأخطاء التنفيذية + التفصيل + الفتات
- **spec:** §4.1 · §4.4 · §4.8 · §4.7 · §12.
- **يُبنى:** `ops/errors.blade.php`: عشرُ بطاقات (مفتوحة، حرجة، جديدةُ اليوم، وقوعاتُ ٢٤س من `error_occurrences`، انحدارات، مستخدمون متأثّرون، PHP/API/JS/بطيء) + رسمُ «الأخطاء عبر الزمن» من العيّنات (لا من `last_seen` كما تفعل `SysMonitor::pulse` فتنسب عدّاً كاملاً لساعةٍ واحدة) + `chart_donut` للفئات والشدّة + `partials/timerange`.
  `ops/error_show.blade.php`: جدولُ العيّنات (وقت · request_id ⇐ رابط `system.trace` · مستخدم · مسار · مدّة)، آخرُ المتأثّرين (`whereIn` على المعرّفات المعروضة فقط — لا `User::pluck` للجدول كلِّه كما اليوم في `:63,:112`)، خطُّ الإصدار (أول ظهور/الحل/الانحدار)، **فتاتٌ آمنة** (زياراتُ `page_visits` للمستخدم قبل الوقوع + قيودُ `audits` بنفس `request_id` — كلاهما مفهرس، ولا التقاطَ جديد)، وربطُ الحادثة/المهمّة.
  **قارئٌ واحد** `App\Support\ErrorStats` يستهلكه المركزُ و`OpsController.php:84-93` و`MorningController.php:140` و`Health::errors` (خمسُ نسخٍ اليوم).
- **اختبارات:** `ErrorDashboardTest` — بطاقةُ «حرجة» تطابق دلالةَ `Health::errors`؛ خطأٌ بعدّ ٥٠ موزّعٍ على ٥ ساعات يرسم ٥ أعمدة؛ الفتاتُ لا تُظهر زياراتِ مستخدمٍ آخر؛ عددُ الاستعلامات لا ينمو مع عدد المستخدمين.
- **القبول:** §44 كامل.

### WP-3.5 — إدارةُ الضجيج + بحثُ السجلّ المحدود
- **spec:** §4.12 · §4.11 · §4.10.
- **يُبنى:** كتمٌ لكل بصمة (`muted_until`) يُحترَم في `ErrorLog::tell/bump`؛ تبريدٌ لكل بصمة (مفتاحُ كاش `errnotify:fp:<hash>`)؛ إضافةُ `'error'` إلى `HubNotification::MUTEABLE` (سكّةُ الكتم لكل مستخدم قائمة)؛ و**تمريرُ الشدّة إلى `tell()`** لاستثناء `CRITICAL` من سقف الانفجار (اليومَ يسقط الحرجُ صامتاً بعد الثامن — مخالفةٌ صريحة لـ§4.12).
  بحثُ السجلّ: مسارُ قراءةٍ محدود يقرأ **الملفَّ الصحيح** (`daily` يكتب `laravel-YYYY-MM-DD.log`؛ `OpsController.php:109` يقرأ `laravel.log` غيرَ الموجود ⇒ الشريطُ ميّتٌ اليوم) أو قناة `json`، بسقف بايتاتٍ من الإعداد، بمرشِّحات level/request_id/route/q + `TimeRange`، وكلُّ سطرٍ عبر `Redactor` مع إسقاط `ip` لغير المالك.
- **مفاتيح:** `errors.notify_cooldown_min=15`, `ops.log_tail_kb=64` (internal).
- **مسارات:** `GET admin/errors/logs` اسم `errors.logs` — `hub_is_owner()` + `throttle:30,1`.
- **اختبارات:** `ErrorNoiseTest` (كتم/تبريد؛ CRITICAL يتجاوز السقف؛ المُهمَل الحرج يبقى في `Health::errors`) · `LogSearchTest` (يقرأ الملفَّ المؤرَّخ؛ لا يقرأ ملفاً بحجم ٢٠MB كاملاً؛ المخرجُ مطموس؛ ٤٠٣ لغير المالك).
- **القبول:** §44 «أيُّ الأخطاء يهمّ؟» · §4.12 «الحرجُ لا يختفي صامتاً».

**توازي الطور ٣:** `3.1` و`3.2` و`3.5` مجموعاتٌ منفصلة عملياً عدا `ErrorLog.php` (٣.١ التصنيف، ٣.٢ الالتقاط، ٣.٥ الإشعار) ⇒ **تسلسل ٣.١ ← ٣.٢ ← ٣.٥** أو تقسيمٌ داخل الملف بدوالَّ منفصلة ودمجٌ يدويّ. `3.3` (هجرة + متحكّم) و`3.4` (عروض + `ErrorStats`) متوازيان بعد ٣.٢. **تعارضٌ:** `ErrorCenterController.php` (٣.٣، ٣.٤، ٣.٥)، `ops/errors.blade.php` و`error_show.blade.php` (٣.٣، ٣.٤)، `HubAutomation` كتلةُ الاحتفاظ (٣.٢) — وهي نفسُها التي يمسّها الطور ٧ ⇒ رتّب ٣ قبل ٧.

---

## الطور ٤ — SECURITY

**الهدف:** أن يجيب المالكُ §42 كاملاً: الوضعيةُ وتاريخُها، النتائجُ الحرجة، الحساباتُ الأكثر انكشافاً، المميّزون بلا MFA، السلوكُ المريب، الأسرارُ البائتة، الاعتماداتُ الخطرة، الحوادثُ المفتوحة، وتفصيلُ حدثٍ أمنيّ وما يتّصل به.

**القائمُ الذي يُوسَّع:** `SecurityPosture` (١٩ فحصاً بشكل `{key,label,tone,why,n,fix,url}`) · `SecurityExposure` (خريطةُ الانكشاف بعواملَ مشروحة) · `SecurityRadar` + `AccessRadar` (`access_denials`) · `SecurityEvents` (٣٦ رمزاً فوق `audits`+`access_denials` — **سجلٌّ مشتقّ، ولا جدولَ أحداثٍ أمنية**) · `Sessions::revokeAll` · `Devices` + `user_devices` · `Risk::session/bands/privileged` · `StepUp` + `hub_require_stepup` · `RoleController::FLAGS/RISKY_FLAGS` · `api_tokens`/`ApiAuth` · `vault_secrets` · `hub_security_incident`.

### WP-4.1 — محرّكُ النتائج (findings) + التوصياتُ الحتميّة
- **spec:** §2.3 · §34 · §31 · §42.2.
- **جدولٌ جديد:** `security_findings` — `id` uuid، `code` string(60)، `entity_type` string(40) null، `entity_id` uuid null، `severity` string(12) (مفرداتُ `Severity`)، `title` string(200)، `description` text، `evidence` json، `remediation` text، `owner_id` uuid null، `status` string(20) (`open|acknowledged|resolved|ignored`)، `first_seen_at`، `last_seen_at`، `acknowledged_at/by`، `resolved_at`، `company_id` uuid null، `request_id` string(40) null، timestamps.
  **فهارس:** فريدٌ `(code, entity_type, entity_id)` · `(status, severity)` · `(last_seen_at)`. **الإعلان:** `HubBackup::RAW_TABLES` (حالةُ حَوكمةٍ لا تليمتري).
- **يُبنى:** `App\Support\SecurityFindings::reconcile()` — يمشي على `SecurityPosture::checks()` (الرموزُ هي `key` نفسُها: lockdown, twofa_priv, pw_stale, idle, vault_stale, api_stale, share_open, audit_chain, debug_mode, owners, default_pw, backup_fresh…) وعلى النتائج **لكل كيان** (`SecurityExposure::map` للمستخدمين، `SecurityPosture::apiStale` لكل رمز، `vaultRotation` لكل سرّ، `twofaPrivileged` للمميّزين) ⇒ upsert يحفظ `first_seen_at` ويُحدِّث `last_seen_at`، ويُغلق تلقائياً (`resolved`) ما اختفى شرطُه.
  الشدّةُ من نبرة الفحص عبر `Severity::normalize` (bad⇒high/critical حسب الرمز، wn⇒medium). التوصية = حقلُ `fix` + `url` القائمان في `SecurityPosture::row` (لا نصَّ يُخترَع).
  **قرار ق٤:** الإقرارُ يعيش في هذا الجدول؛ ولا يُسجَّل نفسُ الشرط إشارةً في `signal_states` (منعاً لإقرارين).
- **مسارات:** `GET admin/security/findings` (`security.findings`)، `GET admin/security/findings/{id}` (`security.finding`)، `POST .../ack`، `POST .../resolve` — الحارس: قراءةٌ `hub_is_owner() || hub_monitor()` (ق١) مع تنطيقِ الكيانات، والفعلُ `hub_is_owner()` + `hub_audit('إقرار نتيجة أمنية' / 'إغلاق نتيجة أمنية')`.
- **اختبارات (تفشل أولاً):** `SecurityFindingsTest` — تشغيلان يُنتجان صفّاً واحداً لكل (code, entity) مع `first_seen_at` محفوظ؛ زوالُ الشرط يُغلقه تلقائياً؛ الإقرارُ للمالك فقط ومدقَّق؛ الشدّةُ تطابق خريطة `Severity`؛ عرضُ التوصية من `fix/url`؛ MySQL.
- **القبول:** §42.2 «ما المشكلاتُ الأمنية الحرجة؟» · §33 (تغذيةُ قائمة الانتباه) · §34.

### WP-4.2 — تاريخُ الوضعية + لقطةٌ مجدولة
- **spec:** §2.2 · §16 · §26.
- **يُبنى:** أمرٌ `hub:security-snapshot` يوميّاً (٢٣:٤٠) يكتب في **`metric_points`** (لا جدولَ لقطاتٍ جديد — سابقةُ `DataQuality::snapshot` بـ`module='quality', record_id='org'`): `('security','org','score'|'bad'|'wn'|'priv_no_mfa'|'stale_secrets'|'suspicious'|'findings_critical'|'findings_high')` ثم `SecurityFindings::reconcile()`.
  العرض: `cc/trend` لليوم/٧/٣٠/٩٠ من `hub_metric_series`، وحالةٌ فارغة صادقة قبل أوّل لقطة (`hub_metric_latest` يُعيد null = «لا قياس»).
  **توحيدُ الدرجة:** في المنصّة درجتان متضاربتان — `SecurityPosture::summary()['score']` (نسبةُ الفحوص الناجحة) و`hub_health()['الأمن']` (معادلةٌ أخرى في `helpers.php`) ⇒ تُعتمد الأولى، ويقرأ الثاني اللقطةَ نفسَها.
- **مجدول:** `Schedule::command('hub:security-snapshot')->dailyAt('23:40')->withoutOverlapping(240)->onFailure(...)` + `Health::JOBS['security']` + `Health::beat('security')`.
- **اختبارات:** `SecurityPostureHistoryTest` — لقطتان بيومين ⇒ سلسلةٌ من نقطتين؛ إعادةُ التشغيل في اليوم نفسه تُحدِّث ولا تكرّر؛ بلا لقطات تظهر «سيبدأ القياس من الآن» ولا صفرٌ مُختلَق؛ الأمرُ في `Health::JOBS` و`schedule:list`.
- **القبول:** §42.1 «ما وضعيتي الأمنية؟» (مع الاتّجاه).

### WP-4.3 — مركزُ خطر الهويّة + مراجعةُ الامتيازات
- **spec:** §2.4 · §2.5 · §42.3–42.5.
- **يُبنى:** `App\Support\IdentityRisk` (**لا** `Support\Identity` — الاسمُ مأخوذٌ لمحلِّل هوية المنتج، ولا مسارات `identity.*`) يحسب لكل مستخدمٍ بـ**٦ استعلامات تجميعية** (`GROUP BY user_id`) على `sessions_log`, `user_devices`, `user_ips`, `access_denials`, `audits`, `webauthn_credentials` — كلُّها مفهرسةٌ على `user_id` (`COMMAND_VERIFIED`) — ثم يركّب العوامل من `Risk::privileged`, `RoleController::RISKY_FLAGS`, `Risk::bands` (منخفض/متوسط/عالٍ/حرج من `risk.band_*`), وفشلِ الدخول عبر `SecurityEvents::actions('AUTH_FAILURE'|'MFA_FAILURE')` (مفردةٌ واحدة بدل ثلاثِ قوائمَ حرفية متباعدة اليوم).
  مراجعةُ الامتيازات: مالكون، أدوارٌ خطرة، نطاق `all`، وصولُ شركاتٍ واسع (`users.companies` فارغة)، خاملون ٣٠/٦٠/٩٠ (بمفاتيحَ لا بثابتٍ مكرَّرٍ في ٥ مواضع)، بلا MFA، أدوارٌ تغيّرت مؤخّراً (`audits` على `roles`/`users` + `RoleController::trail`)، وامتيازاتٌ غيرُ مستعملة (مصفوفةُ الدور مقابل `audits(user_id, module)` في ٩٠ يوماً — الفهرسُ الجديد من WP-1.4).
  الأفعال: فتحُ المستخدم/الدور، إنهاءُ الجلسات، تعطيلُ الحساب، **إقرار/مراجعةٌ لاحقاً** عبر `security_findings` (نفسُ سكّة الإقرار).
- **مفاتيح:** `security.idle_days_1=30`, `security.idle_days_2=60`, `security.idle_days_3=90`, `security.secret_stale_days=180`, `security.token_unused_days=90` (تُقرأ في `SecurityPosture` و`SecurityController` بدل الثوابت المكرَّرة).
- **مسارات:** `GET admin/security/identity` (`security.identity`) · `GET admin/security/privileged` (`security.privileged`) — قراءةٌ `hub_is_owner() || hub_monitor()` مع `hub_field_mode` على بيانات HR وتنطيقِ الشركات؛ الأفعالُ للمالك.
- **اختبارات:** `IdentityRiskTest` — كلُّ عاملٍ في spec يظهر بنقاطه وتسميته؛ ميزانيةُ استعلامات (لا حلقةَ لكل مستخدم)؛ قارئٌ منطَّقٌ لا يرى مستخدمي شركةٍ أخرى؛ لا كلمةَ مرور/سرَّ TOTP في الاستجابة؛ `PrivilegedReviewTest` — الفئاتُ الثمانية + الإقرارُ يبقى ولا يُلغي امتيازاً تلقائياً (نمط `SecurityIncidentAndClassificationTest`).
- **القبول:** §42.3 · §42.4 · §42.5.

### WP-4.4 — الجلسات والأجهزة وذكاءُ العناوين
- **spec:** §2.6 · §2.7 · §2.8 · §18.
- **يُبنى:** مركزُ جلسات (`security.sessions`) بمرشِّحات (مستخدم/IP/حيّة أو مُنهاة/`TimeRange`) وترقيمٍ وترتيبٍ حتميّ (`last_seen_at desc, id desc` — اليومَ `SecurityController.php:57,69,108` بلا فاصلِ `id`)، عمودُ متصفّح/نظام عبر **`Devices::describe`** (يُرفَع إلى public بدل محلِّل UA ثانٍ)، وعمرُ الجلسة، ووسمُ «غير معتادة».
  **توحيد:** `LIVE_MIN=30` معرَّفٌ اليوم في ٤ مواضع ⇒ ثابتٌ واحد `Sessions::LIVE_MIN`؛ و`SecurityController::revokeSession/revokeUser` و`MySecurityController::revokeSession/revokeOthers` تُعاد جميعاً إلى `Sessions::revokeAll(User, exceptSessionId)`.
  تصنيفُ الأجهزة (معلّق/موثوق/مبطَل + «مريب») من `user_devices` (بما فيها المحذوفةُ ناعماً) بلا أيّ بصمةِ جهازٍ جديدة.
  ذكاءُ العناوين: قارئٌ واحدٌ لكل IP يوحّد `SecurityRadar::threats` و`SecurityController` knocking (نسختان اليوم) — أوّل/آخر ظهور (من `audits(created_at,ip)`)، مستخدمون مستهدَفون، دخولٌ ناجح/فاشل، رفضٌ، أفعالٌ مريبة، ووسمٌ (عاديّ/جديد/فشلٌ متكرّر/تعدّدُ حسابات/مريب). **بلا geo خارجيّ.**
- **هجرة (إضافية):** `sessions_log.revoked_at`, `revoked_by` uuid, `revoke_reason` string(120) (اليومَ `revoked` منطقيٌّ يخلط الخروجَ بالإنهاء الإداريّ) · `user_ips.first_seen_at` (بلا ملء رجعيّ) · فهرس `user_ips(ip)` (سبق في WP-1.4).
- **مسارات:** `GET admin/security/sessions` · `GET admin/security/devices` · `GET admin/security/ips` (+ `/{ip}`) — قراءةٌ للمالك (بيانات شخصية) أو `hub_monitor` مع تقنيعِ البريد/IP عبر `hub_field_mode`؛ `POST admin/security/users/{id}/revoke-others` (`security.user.revokeothers`).
  **§18:** إضافةُ `hub_require_stepup()` إلى `security.user.revoke` القائم (بلا تصعيدٍ اليوم) وإلى الفعل الجديد.
- **اختبارات:** `SessionCenterTest` — ترقيمٌ ثابت عبر الصفحات؛ «إنهاءُ الباقي» يُبقي جلستي؛ إنهاءُ الكلّ يطلب step-up ثم يُدقَّق ويدوّر «تذكّرني»؛ `revoked_at/by` تُملأ؛ `DeviceTrustTest`؛ `IpIntelTest` (تجميعاتٌ تعمل على MySQL بوضع `ONLY_FULL_GROUP_BY`).
- **القبول:** §42.5 · §18 (إنهاءُ كل الجلسات) · §41/٤.

### WP-4.5 — مركزُ رموز API + صحّةُ الأسرار + لوحةُ القيادة الأمنية
- **spec:** §2.9 · §2.10 · §2.1 · §18.
- **يُبنى:** جدولُ رموزٍ للمالك (اسم/مالك/نطاقات/إنشاء/انتهاء/آخر استعمال/آخر IP/عمر/امتياز/حالة) بتصنيفٍ **واحد** `App\Support\ApiTokens::classify()` يستهلكه `SecurityPosture::apiStale` أيضاً (منعاً لعتبتين). أفعال: إبطال/تعطيل بـstep-up ومدقَّقة — **لا تدويرَ لرمز غيرك** (ق٧).
  صحّةُ الأسرار من `vault_secrets` (عنوان/نوع/مالك/آخر تدوير/عمر/استعمال من قيود «عرض حساس» عبر فهرس `audits(module,record_id)`/خطر) — **بلا قيمةٍ ولا بصمة**.
  لوحةُ القيادة (§2.1): ١٥ بطاقة عبر `cc/kpis` تُجمَّع من `SecurityPosture::summary` + `SecurityExposure::summary` + `SecurityRadar::summary` + `SecurityFindings` (حرج/مرتفع) + `Integrations::api()` — **وتُستبدَل** `SecurityEvents::counts()` (تُحمِّل ٢٠٠٠–٦٠٠٠ صفّاً وتصنّفها في PHP عند كل تحميل) بعدّاتٍ SQL على `whereIn(action, SecurityEvents::actions(code))` مستفيدةً من الفهرس الجديد `audits(action, created_at)`.
- **هجرة:** `api_tokens.last_ip` string(60) (يُكتب في `ApiAuth` بنفس خنق الدقيقة القائم لـ`last_used_at`) · `api_tokens.revoked_at`, `revoked_by` (لعرض «مُبطَل» بدل الحذف الصلب؛ `ApiAuth` يرفض المُبطَل) · `vault_secrets.rotated_at` (يُختم في `VaultSecret::booted` حين يتغيّر `secret_cipher` فقط — لا عند تعديل ملاحظة).
- **مسارات:** `GET admin/security/tokens` · `GET admin/security/secrets` · `POST admin/security/tokens/{id}/revoke` (owner + `hub_require_credential_stepup` + `hub_audit` برمز `API_CREDENTIAL_REVOKED`).
- **اختبارات:** `TokenCenterTest` (تصنيفٌ صحيح؛ `ApiAuth` يرفض المُبطَل؛ `last_ip` يُكتب مرّةً في الدقيقة؛ لا `lyn_` في مصدر الصفحة) · `SecretHealthTest` (`rotated_at` لا يتغيّر بتعديل ملاحظة؛ لا قيمةَ سرٍّ في الصفحة — توسيعُ `VaultGuardTest`) · `SecurityDashboardTest` (ميزانيةُ استعلاماتٍ للصفحة، وكلُّ بطاقةٍ تطابق استعلامَها المرجعيّ).
- **القبول:** §42.6 · §42.7 · §42.1.

### WP-4.6 — تفصيلُ الحدث الأمنيّ + الربطُ بالحادثة
- **spec:** §42.9 · §42.10 · §2.11 (جزءُ الأمن).
- **يُبنى:** صفحةُ تفصيلٍ لصفٍّ في السجلّ الأمنيّ المشتقّ بمفتاح **المصدر+المعرّف** (`audits.id` أو `access_denials.id`) — `SecurityEvents::row()` تُضاف إليها المعرّفاتُ (اليومَ الصفوفُ بلا id)؛ وتعرض من/ماذا/متى/أين/`request_id` (⇐ `system.trace`)/الحادثةَ المرتبطة، وزرَّ «افتح حادثة» الذي يُمرَّر إلى `m.create` للوحدة `incidents` بحقولٍ مُعبّأة (آليّةُ التعبئة من سلسلة الاستعلام قائمةٌ في `ModuleController::create:230-246` — بلا مسارٍ جديد).
- **مسارات:** `GET admin/security/event/{source}/{id}` (`security.event`) — نفسُ حارس مركز الأمن.
- **اختبارات:** `SecurityEventDetailTest` — الصفّ يُفتح بمصدره ومعرّفه؛ خارجَ النطاق ⇒ ٤٠٤؛ `request_id` يربط بالتتبّع؛ لا ترويسةَ اعتماد؛ زرُّ الحادثة يُنشئ حادثةً واحدة بالأدلّة (بالاشتراك مع الطور ٦).
- **القبول:** §42.9 · §42.10.

**توازي الطور ٤:** `4.1`+`4.2` (نتائج + لقطة) متلازمتان (اللقطةُ تشغّل التوفيق) — worktree واحد. `4.3` و`4.4` و`4.5` مجموعاتٌ منفصلة (`IdentityRisk` · `Sessions/Devices/IPs` · `Tokens/Secrets`) ⇒ ثلاثةٌ متوازية. `4.6` بعد `4.1`. **تعارضٌ:** `SecurityController.php` و`resources/views/security/index.blade.php` (٤.٢، ٤.٣، ٤.٤، ٤.٥، ٤.٦) ⇒ قسِّم العرضَ إلى `security/parts/*.blade.php` في أوّل حزمةٍ ثم كلُّ WP في قسمِه؛ `SecurityPosture.php` (٤.١، ٤.٣، ٤.٥)؛ `config/hub_settings.php` (٤.٣، ٤.٥)؛ `routes/web.php` (٤.١، ٤.٣، ٤.٤، ٤.٥، ٤.٦) بكتلةٍ واحدة معلَّمة.

---

## الطور ٥ — AUDIT

**الهدف:** أن يجيب المدقّقُ §45 كاملاً — ويبقى الختمُ والتحقّقُ والأداءُ كما هي.

**القائمُ الذي يُوسَّع:** `AuditEntry` (سلسلةُ SHA-256، `SEALED`، `liveColumns()` التي تجعل الأعمدةَ الإضافية آمنةً قبل الهجرة) · `Audit::diff/verifyTail` · `AuditController` + `resources/views/audit/index.blade.php` · `HubAuditVerify` (فحصٌ كامل + reseal/rebuild) · `SecurityEvents` · `saved_views` · `hub_timeline` · `WidgetRegistry` (نمطُ التنطيق الأقوى).

### WP-5.1 — تصحيحُ التنطيق (يسبق كلَّ ميزة)
- **spec:** §17 · §45 · §23.1.
- **العيب (STATICALLY_REVIEWED):** `AuditController::index/pulse` لا يرشّح الصفوفَ بـ`hub_can(module,'v')` — فتُطبع أسماءُ سجلّاتٍ من وحداتٍ لا يراها القارئ وتُربط بـ`m.show` (`audit/index.blade.php:84`) بينما الـdiff وحده محجوب؛ ولا يُطبَّق `hub_client_ids` مطلقاً رغم أن ١٦ وحدةً تحمل عمودَ عميل.
- **يُبنى:** مرشِّحُ الوحدات المرئية + نطاقُ العميل في **مغلِّفٍ واحد** `Audit::scopedQuery($user)` يستهلكه `index` و`pulse` والعدّاداتُ الجديدة والتفصيل (اليومَ نسخُ نطاقٍ متعدّدة) — النمطُ المرجعيّ `WidgetRegistry.php:216-239`.
- **اختبارات (تفشل أولاً — لا حارسَ لها اليوم):** `AuditScopeLeakTest` — قارئٌ بلا `hr:v` لا يرى اسمَ سجلٍّ من `hr` ولا رابطَه ولا في القائمة المنسدلة؛ مستخدمٌ محصورٌ بعميلٍ لا يرى نشاطَ عميلٍ آخر؛ المرشِّحاتُ لا توسّع النطاق (نمط `SecurityCenterTest::test_audit_filters_do_not_bypass_scope`).
- **القبول:** §41/٤ · §17.

### WP-5.2 — تطبيعُ قيد التدقيق (أعمدةٌ غيرُ مختومة)
- **spec:** §1.2 · §1.1 · §1.4.
- **هجرة (nullable، **خارج `AuditEntry::SEALED`** كـ`company_id`/`request_id`):** `audits.category` string(24)، `severity` string(12)، `source` string(12) (web|api|console|hook)، `outcome` string(12) (success|failed|denied)، `actor_type` string(12)، `session_id` uuid null.
- **يُبنى:** ملءٌ في `Auditable::writeAudit` و`hub_audit` من: `SecurityEvents::codeFor()` (الفئةُ والشدّة للأفعال الأمنية)، ومجموعاتِ `config/hub.php` (FINANCE/DATA_CHANGE/DELETE/EXPORT/IMPORT/SETTINGS/INTEGRATION/SECRET_ACCESS/API/ADMINISTRATION)، و`Api::requestSource()` (الطور ١)، و`session('hub.sl')` لمعرّف الجلسة (موجودٌ منذ الدخول). و**مُترجِمُ قراءةٍ** للصفوف القديمة (null) يشتقّ الفئةَ من الفعل وقتَ العرض — بلا ملءٍ رجعيّ يمسّ الختم.
  **الاستعادةُ** تُوسَم بدقّة: `$m->restore()` يكتب اليوم «تعديل» بـ`deleted_at:null` (RUNTIME_VERIFIED) ⇒ الفئةُ RESTORE تُشتقّ من الفرق لا من فعلٍ جديد (و`hub_timeline` يذكر «استعادة» لفعلٍ لا يكتبه أحد — يُصحَّح).
- **اختبارات:** `AuditNormalizationTest` (تفشل أولاً) — الأعمدةُ تُملأ للويب/الـAPI/الطرفية؛ الصفوفُ القديمة تُصنَّف عند القراءة؛ **`Audit::verifyTail` و`hub:audit-verify` يبقيان أخضرين بعد الأعمدة الجديدة** (على المحرّكين)؛ عرضُ الأعمدة على MySQL (٢٤/١٢).
- **القبول:** §45 «هل كان حساساً؟» · §1.1 (العدّ المُنطَّق).

### WP-5.3 — النظرةُ التنفيذية + التحقيقُ المتقدّم + التحقيقاتُ المحفوظة
- **spec:** §1.1 · §1.4 · §1.5 · §12.2.
- **يُبنى:** ١٢ عدّاداً في `audit/index.blade.php` عبر `cc/kpis` **باستعلاماتٍ تجميعية** (لا `$base()->get()` لكل صفوف اليوم كما `AuditController.php:96`) مستفيدةً من `audits(action, created_at)` و`(created_at, ip)` و`(company_id, created_at)`؛ ومرشِّحاتٌ إضافية: severity/category/role/company/project/client/request_id/حسّاسٌ فقط/فاشلٌ فقط/بياناتٌ تغيّرت/طوارئ + `partials/timerange` (بدل `whereDate` الحاليّ غير السارغابل).
  التحقيقاتُ المحفوظة: **توسيعُ `saved_views`** (لا جدولَ ثانٍ) — استثناءُ `module='audit'` في `PrefController::storeView` (اليومَ يشترط `hub_mod()`) وفرعٌ في `SavedView::url()` إلى `audit.index`، وحارسُه `hub_flag('audit')`، وشرائحُ في شريط الأدوات (النموذجُ الكامل قائمٌ في `modules/index.blade.php:98-112`).
- **اختبارات:** `AuditInvestigationTest` — كلُّ مرشِّحٍ جديد لا يوسّع النطاق؛ العدّاداتُ تطابق استعلاماً مرجعياً؛ حفظُ تحقيقٍ وتطبيقُه وحذفُه (وسقفُ ٣٠) ومنعُ من لا يملك العلم؛ إنشاءُ التحقيق مدقَّق؛ ترقيمٌ ثابت.
- **القبول:** §45 «من/ماذا/متى/من أيّ IP؟» · §1.5.

### WP-5.4 — صفحةُ تفصيل قيد التدقيق
- **spec:** §1.3 · §45 · §42.10.
- **يُبنى:** `GET admin/audit/{id}` (`audit.show`) + `resources/views/audit/show.blade.php`: من (مستخدم/دور/نطاق/شركة) · ماذا (فعل/وحدة/سجل/فرقٌ عبر `Audit::diff` **ثم `Redactor`**) · متى · أين (IP/جهاز/جلسة) · الطلب (`request_id` ⇐ `system.trace` · المصدر · المسار) · الأمن (رمزُ `SecurityEvents::codeFor` · IP غيرُ معتاد عبر `Risk::session`/`user_ips` — لا حاسبَ ثالثاً) · النزاهة (`hash`/`prev_hash` + تحقّقٌ **موضعيّ** بمنطق `Audit::verifyTail:116-123` لا فحصٌ كامل) · العلاقات (خطأٌ/حادثةٌ/مهمّة بنفس `request_id` أو الرابط).
- **الحارس:** `hub_flag('audit')` + `Audit::scopedQuery` (WP-5.1) ⇒ خارجَ النطاق `findOrFail` ⇒ ٤٠٤ (لا ٤٠٣ يفشي الوجود).
- **اختبارات:** `AuditDetailTest` — ٤٠٤ خارج نطاق الشركة/العميل؛ ٤٠٣ بلا العلم؛ الفرقُ مقنَّعٌ حسب `hub_can`/`hub_field_mode`؛ لا سرَّ (إعادةُ استعمال تجهيزات `SecretsNeverInAuditRound7Test`)؛ تعديلٌ مباشرٌ في القاعدة يُظهر «عبث» في التحقّق الموضعيّ.
- **القبول:** §45 كامل (عدا التغطية) · §42.10.

### WP-5.5 — تاريخُ نزاهة السلسلة + محلّلُ التغطية + وصفُ الاحتفاظ
- **spec:** §1.6 · §1.7 · §1.8 · §31.
- **جدولٌ جديد:** `audit_verifications` — `id`، `mode` (auto|manual)، `initiated_by` uuid null، `request_id`، `started_at`، `finished_at`، `duration_ms`، `result` (ok|warn|fail)، `checked_rows`، `weak_rows`، `unsealed_rows`، `mismatch_rows`، `blank_rows`، `first_bad_id` bigint null، `message` string(500). **فهرس:** `(started_at)`. **الإعلان:** `HubBackup::RAW_TABLES` (دليلُ نزاهةٍ لا تليمتري — ولهذا لا يُدفَع إلى `metric_points` الذي يُقلَّم بعد ٣٦٥ يوماً).
  الكاتب: `HubAuditVerify::handle` (يملك العدّادات سلفاً) + زرُّ `ops.verifyaudit`. القرّاء: بطاقةٌ في `audit.index` و`ops/index`، ويبقى `Audit::verifyTail` وحده على تحميل الصفحة (**لا فحصَ كامل أبداً في طلب**).
- **محلّلُ التغطية (للمالك):** `GET admin/audit/coverage` (`audit.coverage`) — يقارن `hub_modules()` × وجودَ `Auditable` (٨٢/٨٢ اليوم) × كتالوجَ `SecurityEvents::CODES` × مواضعَ `hub_audit` الفعلية، ويُصنّف: مغطّى / مغطّى جزئياً (الاستعادةُ تُكتب «تعديل») / **يحتاج مراجعة** حيث لا يُثبَت إلا بالمسح النصّي — بلا يقينٍ مزيَّف (spec: "No fake certainty"). ويُظهر الثغراتِ المعروفة: تغييرُ حالة الخطأ و`toTask` (يُغلقان في WP-3.3) وCRUD العروضِ المحفوظة.
- **الاحتفاظ (§1.8):** مفاتيحُ وصفٍ فقط — `audit.retention_days=0` (=للأبد) و`audit.retention_policy` نصّ، تُعرَض للقراءة في الشاشة؛ **لا كودَ تقليم** (ق٦)، مع تحديثِ النصوص الثلاثة التي تَعِد بـ«للأبد» (`hub_settings.php`, `HubAutomation`, `docs/SECURITY_PLATFORM_MAP.md`) كي لا تتناقض.
- **اختبارات:** `AuditIntegrityHistoryTest` (كلُّ تشغيلٍ آليّ/يدويّ يكتب صفّاً بالعدّادات و`first_bad_id`؛ فتحُ الصفحة لا يُنشئ صفّاً ولا يشغّل فحصاً كاملاً — اختبارُ عدد استعلامات) · `AuditCoverageTest` (للمالك فقط؛ يعلن «يحتاج مراجعة» حيث لا إثباتَ؛ يرصد فعلاً غيرَ مغطّى) · `SettingsCenterTest` يبقى أخضر بالمفاتيح الجديدة.
- **القبول:** §45 «هل السلسلة سليمة؟ وهل ثمّة عملياتٌ مهمّة بلا تدقيق؟».

**توازي الطور ٥:** `5.1` أولاً (يغيّر مغلِّفَ الاستعلام الذي تبني عليه ٥.٣ و٥.٤). ثم `5.2` (هجرة + كتّاب) و`5.5` (أمر + جدول + شاشةُ تغطية) متوازيان؛ `5.3` و`5.4` بعد `5.2`. **تعارضٌ:** `AuditController.php` (٥.١، ٥.٣، ٥.٤)، `audit/index.blade.php` (٥.٣، ٥.٥)، `helpers.php` (٥.٢ يمسّ `hub_audit` و`hub_timeline` — **الاستثناءُ الوحيد** لقاعدة «helpers للطور ١»، ويُدمَج منفرداً)، `routes/web.php`، `config/hub_settings.php` (٥.٥).

---

## الطور ٦ — INCIDENTS (+ محرّكُ التنبيه §9)

**الهدف:** أن تصير الحادثةُ مركزَ قيادةٍ حقيقيّاً: خطٌّ زمنيّ من مصادرَ حقيقية، أدلّةٌ مرتبطة، معالجةٌ عبر المهامّ، ومراجعةُ ما بعد الحادثة — وأن يصير التنبيهُ ذا نافذةٍ وشدّةٍ وتبريدٍ وحالة.

**القائمُ الذي يُوسَّع:** وحدةُ `incidents` (بيانياً، بلا متحكّم — التوسعةُ عبر `resources/views/modules/custom/incidents.blade.php` المُستدعى سلفاً من `modules/show.blade.php:84`) · `hub_security_incident` (تكرارٌ بالعنوان/٦ ساعات) · `alert_rules` + `HubAutomation::alertRules` (نطاقٌ وصلاحيةٌ وتصعيدٌ وذاكرةُ تكرار) · `FlowRunner`/`HubEvents` · `record_acks`/`config/hub_acks.php` · `TraceController` (`/trace/incidents/{id}` قائمٌ ومربوطٌ من ترويسة السجل).

### WP-6.1 — رأسُ الحادثة والإقرارُ والقرار
- **spec:** §8.1 · §8.5 · §8.6 · §31.
- **هجرة:** `incidents.detected_at` · `resolution` text · `kind` string(20) مفهرس (مرآةُ `meta.kind` — يُلغي مسحَ `meta LIKE '%"kind":"security"%'` المتكرّر في `SecurityController.php:51` و`Health.php:334`) · `request_id` (سبق في WP-1.4).
  **الإقرار بلا عمود:** تسجيلُ `incidents` في `config/hub_acks.php` بـ`who = ['col'=>'lead_id','type'=>'one']` ⇒ يعطي `acknowledged_by/at` + IP/جهاز/ملاحظة + واجهةً + أثراً **بلا مخطّطٍ جديد** (سكّةُ `record_acks` قائمة ومدمَجة في صفحة السجل).
- **يُبنى:** بطاقةُ رأسٍ في `modules/custom/incidents.blade.php` (عنوان/شدّة/حالة/مالك/بدأت/كُشفت/أُقرّت/حُلّت/**مدّة**) — المدّة = `resolved_at - started_at` مع سقوطٍ إلى `downtime_min`، **بنفس تعريف** `hub_app_quality` لـMTTR كي لا تتناقض شاشتان.
  **شرطُ الإغلاق (§8.5):** إلزامُ `root_cause`+`resolution`+`prevention` للشدّة «حرج/عالي» فقط، مفروضاً في **ثلاثة** مسارات: `ModuleController::setStatus`, `::update`, و`Api\V1Controller` (يرث المحرّك) — عبر مفتاحٍ في السجلّ (`requires` بجوار `status_via_action` القائم) لا بفرعٍ في المتحكّم.
  **PIR (§8.6):** قسمٌ اختياريّ للشدّتين العاليتين يستعمل الحقولَ القائمة (`postmortem`, `lessons`, `prevention`, `review_date`, `att_id`) + ملخّصٌ وأثرٌ في `meta`.
- **اختبارات:** `IncidentHeaderTest` · `IncidentResolutionGateTest` (إغلاقٌ بلا سببٍ جذريّ ⇒ ٤٢٢ للحرج، ويمرّ للمنخفض؛ عبر السحب والتحديث والـAPI) · `IncidentAckTest` (الإقرارُ عبر `record_acks` مدقَّق).
- **القبول:** §8 · §31.

### WP-6.2 — الأدلّةُ المرتبطة والخطُّ الزمنيّ
- **spec:** §8.2 · §8.4 · §2.11 · §42.10.
- **جدولٌ جديد:** `incident_links` — `id`، `incident_id` uuid، `kind` string(24) (`error|audit|security|request|alert|task|deploy|note`)، `module` string(60) null، `record_id` uuid null، `ref` string(120) null (بصمةُ خطأ/`request_id`/`audits.id`/مفتاحُ تنبيه)، `summary` string(300)، `by` uuid null، `created_at`. **فهارس:** `(incident_id, created_at)`، `(module, record_id)`، `(ref)`. **الإعلان:** `HubBackup::RAW_TABLES`.
  السببُ الوجيه: `audits` مختومةٌ فلا يُضاف إليها `incident_id`، والأحداثُ الأمنية **مشتقّة** بلا جدول ⇒ الربطُ يحتاج جدولَ وصلٍ خفيفاً. وما له عمودُ مرجعٍ سلفاً (`deployments.incident_id`) **لا يحتاج صفَّ وصل** — يُقرأ عبر `hub_related()`.
- **يُبنى:** فرعُ `incidents` في `hub_timeline()` يدمج: `incident_links` + `meta.events` (أدلّةُ `hub_security_incident` — تُخزَّن اليومَ ولا يعرضها أحد) + `deployments.incident_id` + فروقَ الحالة/المالك من `audits`؛ وأفعالُ «اربط خطأً/قيدَ تدقيقٍ/حدثاً أمنياً/طلباً» من صفحات المصادر (زرٌّ في `errors.show`، `audit.show`، `security.event`).
  الأثرُ (§8.3): وحدةٌ/خدمةٌ متأثّرة من المراجع، ومستخدمون/أخطاءٌ ضمن `[started_at, resolved_at|now]` — مع **مصارحةٍ** بأن `error_events.count/users` عدّاداتٌ تراكمية (لا نافذية) فيُعرَض «أخطاءٌ نشطة في النافذة» لا عددٌ مخترَع؛ وزمنُ التعطّل من سلسلة `up` في `metric_points`.
- **مسارات:** `POST admin/incidents/{id}/link` (`incidents.link`) — `hub_can('incidents','e')` + `hub_audit`.
- **اختبارات:** `IncidentEvidenceTest` — الربطُ لا يكرّر (فريدٌ منطقيّ على incident+kind+ref)؛ الخطُّ الزمنيّ يرتّب المصادرَ الخمسة زمنياً؛ قارئٌ بلا `hr:v` لا يرى اسمَ سجلٍّ محجوب؛ `meta.events` تظهر.
- **القبول:** §8.2 · §8.4 · §42.10 · §44 «هل ثمّة حادثة/مهمّة؟».

### WP-6.3 — محرّكُ التنبيه: نافذة/شدّة/تبريد/حالة
- **spec:** §9.1 · §9.2 · §9.3 · §2.12 · §3.9.
- **هجرة:** توسيعُ `alert_rules` بأعمدةٍ nullable: `severity` string(12)، `domain` string(24) (`module|security|system|error|quality|execution`)، `source` string(80) (مثل `security.failed_logins`, `errors.critical_count`, `health.scheduler`)، `window_min` uint، `cooldown_min` uint، `auto_incident` bool؛ وحقولُها في سجلّ `config/hub.php` («rules») كي تتبعها الشاشةُ والـAPI.
  **جدولٌ جديد:** `alert_instances` — `id`، `rule_id` null، `dedup_key` string(191) **فريد**، `domain`، `module`، `record_id` null، `subject` string(120) null (IP/مستخدم للنوافذ)، `severity`، `title` string(300)، `status` (`triggered|acknowledged|resolved`)، `first_at`، `last_at`، `count`، `acknowledged_by/at`، `resolved_at`، `incident_id` null، `company_id` null، `request_id`. **فهارس:** فريدُ `dedup_key` · `(status, severity, last_at)` · `(incident_id)`. **الإعلان:** `HubBackup::RAW_TABLES`.
  السبب: ذاكرةُ التكرار اليوم هي `notifications_hub.kind='rule:<id>'` وهي **تُقلَّم بعد ٩٠/٣٦٥ يوماً** ⇒ التكرارُ والتصعيدُ يفقدان ذاكرتَهما صامتين.
- **يُبنى:** استخراجُ `HubAutomation::alertRules` (٣٨٥–٥٦٩) إلى `App\Support\AlertEngine` بلا تغيير سلوك (نطاقٌ لكل مستلِم، ترقيمٌ بمؤشّر المعرّف، تصعيد) — يستدعيه الأمرُ اليوميّ **و** أمرٌ جديد `hub:alerts-evaluate` كلَّ ٥ دقائق للقواعد ذات النافذة، ومصادرُه: `audits(action, created_at)` (فشلُ الدخول)، `access_denials(ip, created_at)`، `Health::check()` (§3.9)، `error_events`، `SecurityFindings`.
  **كشفُ الحوادث التشغيلية (§3.9):** تعميمُ `hub_security_incident` إلى `hub_open_incident(title, severity, kind, fingerprint, meta, dedupHours)` — التكرارُ **ببصمة** لا بنصّ العنوان، والحاليّ يبقى غلافاً متوافقاً. البصمات: `ops:db_unavailable`, `ops:disk_critical`, `ops:scheduler_dead:<job>`, `ops:outbox_backlog`, `ops:dep_failed:<key>`, `ops:error_spike`. وعند الشفاء يُضاف قيدُ «تعافت الخدمة» ولا يُغلق تلقائياً بصمت.
  **الإطلاق:** كلُّ حادثةٍ آليّة تمرّ بـ`HubEvents`/`Incident::create` فتعمل المساراتُ المبذورة («🚨 حادث حرج») — اليومَ `hub_security_incident` يتجاوزها.
  **حالةُ التنبيه (§9.3):** `triggered → acknowledged → resolved` (الحلُّ آليٌّ حين يزول الشرطُ في التقييم التالي)؛ والمفتوحُ يُقرأ إشارةً في `ActionCenter` (ق٣).
- **مفاتيح:** `security.alert_cooldown_min=60` (افتراضٌ حين لا تُضبط القاعدة).
- **مجدول:** `hub:alerts-evaluate` كلَّ ٥ دقائق + `Health::JOBS['alerts']` + beat + onFailure.
- **مسارات:** `GET admin/alerts` (`alerts.center` — **اسمٌ مختلف** عن `alerts` المملوك لرادار الانتهاء) · `POST admin/alerts/{id}/ack` · `POST admin/alerts/{id}/incident` — قراءةٌ `hub_is_owner() || hub_monitor()`، فعلٌ مالكٌ + `hub_audit`.
- **اختبارات:** `AlertEngineTest` (الاختباراتُ الخمسةُ القائمة تبقى خضراء بعد الاستخراج) · `AlertWindowTest` («X فشلاً في Y دقيقة» يُطلق مرّةً ويحترم التبريد؛ نفسُ IP لعدّة مستخدمين؛ دورُ مميّزٍ تغيّر؛ قفلُ الطوارئ) · `AlertLifecycleTest` (الإقرارُ يمنع تكرارَ الإشعار؛ العدّادُ ينمو؛ الصفُّ ينجو من تقليم الإشعارات) · `OpsIncidentDetectionTest` (كلُّ بصمةٍ تفتح حادثةً واحدة وتُلحِق الأدلّة؛ الشفاءُ يكتب قيداً؛ عدّادُ الحوادث الأمنية لا يشمل التشغيلية).
- **القبول:** §9 · §3.9 · §42.8.

**توازي الطور ٦:** `6.1` (سجلّ + عرض + إقرار) و`6.3` (محرّك + جداول + أمر) منفصلتان عملياً ⇒ متوازيتان؛ `6.2` بعدهما (يقرأ من الاثنين). **تعارضٌ:** `config/hub.php` (٦.١ حقولُ الحوادث، ٦.٣ حقولُ القواعد والأحداث) ⇒ تسلسلٌ إجباريّ أو دمجٌ يدويٌّ دقيق ثم **إعادةُ توليد `docs/openapi.json`**؛ `helpers.php` (`hub_timeline` في ٦.٢، `hub_security_incident` في ٦.٣) ⇒ تسلسل؛ `HubAutomation.php` (٦.٣)؛ `routes/console.php` (٦.٣ بعد ٢.٣).

---

## الطور ٧ — WORKFORCE

**الهدف:** §46 — ما أُنجز، ما تأخّر، ما توقّف، أين اختلال التوزيع، نسبةُ الالتزام بالمواعيد، أين الاختناق — **ومخاطرُ النشاط الأمني معزولةٌ تماماً عن الأداء**. ولا مراقبةَ شخصية (spec: NEVER screenshots/keystrokes/GPS شخصيّ).

**القائمُ الذي يُوسَّع:** `ActivityController` (حضورٌ من `sessions_log.last_seen_at`، زياراتٌ من `page_visits`، `riskProfile` ١٤ يوماً) · `Workday` (حضور/فريقي اليوم/بلاغات) · `hub_capacity` (حِمل/إتاحة/فوق الطاقة، بـ`from/to`) · `PerformanceController::peopleKpis` (٣٠ يوماً) · `PortalController::bundle` (منطَّقٌ لكل وحدة) · `TeamDirectory` · `hub_sla` + `SupportController` · `hub_recommendations`/`ActionCenter` (إشاراتُ `proj.stalled`, `proj.blockers`, `sla.breach`, `cap.over` قائمة) · `Delivery::leadTime/cadence` (شكلُ زمنِ الدورة الصحيح: وسيطٌ بجانب المتوسّط ووسمُ العيّنة).

### WP-7.1 — فصلُ الأمن عن الإنتاجية
- **spec:** §5.1 · §46 (الشرطُ الأخير).
- **يُبنى:** تقسيمُ `ActivityController::riskProfile` (يُعيد اليومَ الدرجةَ الأمنية **وساعاتِ العمل** في مصفوفةٍ واحدة) إلى: `Risk::activity($user, TimeRange)` (أمنيّ) و`Workforce::activity($user, TimeRange)` (عمليّ)؛ وإعادةُ تسمية العرض إلى **«مخاطر النشاط الأمني»** في `activity/show.blade.php` مع نقلِه إلى بطاقةٍ منفصلة، وطيُّ أثر الزيارات (١٢٠ صفّاً اليوم) داخل `<details>` للمالك (spec §5.8).
  الساعاتُ الليلية/خارجُ الدوام تُحسب من `sec.hours_start/end` (كما `Risk::session`) لا من ٠٨–١٦ ثابتةٍ في الشيفرة.
- **اختبارات:** `SecurityActivitySplitTest` — لا شاشةَ أداءٍ تقرأ الدرجةَ الأمنية (فحصُ مصدرٍ + تأكيدُ استجابة)؛ زياراتٌ ليليةٌ لا تغيّر «أُنجز/في الموعد»؛ التسميةُ الجديدة ظاهرة. ويُحدَّث `EsignLinksAndOpsTest:273` الذي يثبّت التسميةَ القديمة (انحدارٌ متوقَّع ومقصود).
- **القبول:** §46 «يبقى الأمنُ منفصلاً» · §5.1.

### WP-7.2 — نظرةُ القوى العاملة + الاتّجاهات
- **spec:** §5.2 · §5.4 · §5.5 · §5.6.
- **يُبنى:** `App\Support\WorkforceStats` — ثمانيةُ عدّاداتٍ بتجميعٍ في القاعدة: نشِطٌ اليوم (`sessions_log`)، أُنجز، متأخّر، نسبةُ الالتزام، تذاكرُ مفتوحة، خرقُ SLA (`hub_sla`)، مشاريعُ في خطر (`hub_project_health<55`)، اعتماداتٌ معلّقة — كلُّها عبر **`hub_open_scope`/`hub_closed_scope`** لا بقوائمَ حرفية (٦ نسخٍ اليوم بثلاث مفردات).
  نافذةُ ٧/٣٠/٩٠ عبر `TimeRange`؛ ولوحُ الأقسام من `employees.dept` (عبر `employees.user_id`، لا `tasks.dept` — قائمتاهما مختلفتان)؛ وميزانُ الحمل من صفوف `hub_capacity` (فوق الطاقة، بلا تكليف، تأخّرٌ كثيف، تشتّتُ التوزيع) **مع نصّ المنهجية** (كما `capacity.blade.php:73-79`).
- **مسارات:** `GET workforce/overview` (`workforce.overview`) — لا يُعاد استعمالُ اسم `workforce.team` القائم (فريقي اليوم) — الحارس: `hub_monitor()` + `hub_org_analytics_guard()` (نمطُ `CapacityController`).
- **اختبارات:** `WorkforceOverviewTest` — كلُّ بطاقةٍ تطابق استعلاماً مرجعياً على بذورٍ مصنوعة؛ حسابٌ محصورٌ بشركة ⇒ ٤٠٣ من الحارس؛ النافذةُ تغيّر النتائجَ حتميّاً؛ لا ترتيبَ للموظفين بعدد الزيارات (spec §5.5).
- **القبول:** §46 «ما أُنجز/تأخّر/الالتزام/اختلالُ التوزيع».

### WP-7.3 — ملفُّ عمل الموظف + خطُّه الزمنيّ
- **spec:** §5.3 · §5.8.
- **يُبنى:** توسيعُ `portal.employee` القائم (منطَّقٌ سلفاً بـ`hr:v` + `hub_can` لكل وحدة) ببطاقاتِ: العمل (مهامٌّ/منجَزة/مفتوحة/متأخّرة/الالتزام/تذاكرُ محلولة/متوسّطُ الحل/مشاريع/اعتماداتٌ أُنجزت من `approvals.decided_by/decided_at`)، والنشاط (أوّل/آخر ظهور، أيامٌ نشطة، **أفعالٌ ذاتُ معنى** = قيودُ تدقيقٍ من نوع إضافة/تعديل/حذف/تصدير على وحداتٍ يراها القارئ — لا «عرض حساس» ولا دخول)، والأمن في **بطاقةٍ منفصلة** (WP-7.1) للمالك.
  خطٌّ زمنيّ منتقىً لكل مستخدم: مهمّةٌ أُنجزت/تذكرةٌ حُلّت/اعتماد/تعليقٌ أو بلاغ/قيدٌ مهمّ — بمرشِّح الوحدات المرئية من `WidgetRegistry`؛ **والزياراتُ الخام لا تُصبّ افتراضياً**.
  حسابُ الأداء يُستخرج مرّةً في `WorkforceStats::person()` يستهلكه `PerformanceController` و`PortalController` (ثلاثُ نسخٍ متداخلة اليوم)، مع تصحيحِ عيبٍ قائم: `peopleKpis` يقرأ `employees.perf` بلا `hub_field_mode`.
- **اختبارات:** `EmployeeWorkProfileTest` — التنطيقُ محفوظ (قارئُ شركةٍ أخرى لا يرى)؛ `hub_field_mode` يخفي التقييمَ والراتب؛ الأفعالُ ذاتُ المعنى لا تشمل الدخولَ ولا العرضَ الحسّاس؛ الخطُّ الزمنيّ بلا زياراتٍ افتراضياً؛ لا `User::pluck` للجدول كلِّه.
- **القبول:** §46 · §5.3.

### WP-7.4 — الاختناقات + الاحتفاظ بالزيارات
- **spec:** §5.7 · §13 (ACTIVITY VISITS configurable).
- **يُبنى:** قراءةٌ واحدة تجمع ما هو **موجودٌ فعلاً**: مراحلُ الانتظار (اعتمادٌ `created_at→decided_at`؛ تذاكرُ «بانتظار العميل»؛ مهامّ «متوقفة») ومكوثُ الحالة من `audits` (`after.status`) ضمن نافذةٍ محدودة على فهرس `(module, record_id)`؛ وأكثرُ المعوّقات تكراراً من `work_updates.problems` و`tasks.late_reason`؛ ومهامٌّ راكدة (مفتوحةٌ و`updated_at` أقدمُ من عتبة)؛ **وإعادةُ الفتح** من عدّاد `meta.reopened` الذي يُضاف في `ModuleController` حيث يُمسح اليوم `meta.resolved_at` بصمت.
  ويقرأ إشاراتِ `ActionCenter` القائمة (`proj.stalled`, `proj.blockers`, `sla.breach`) بدل إعادة حسابها فتتّفق الشاشتان.
  **الاحتفاظ:** `retention.visits_days` (أدنى ٣٠، افتراضياً ٩٠) يُقرأ في `HubAutomation` بدل الرقم الثابت (الجلساتُ والعناوينُ مضبوطتان بمفاتيح؛ الزياراتُ وحدها ثابتة).
- **اختبارات:** `BottleneckTest` (مكوثٌ محسوبٌ من تاريخٍ مبذور؛ عدّادُ إعادة الفتح يزيد؛ الراكدُ يُرصد) · `VisitsRetentionTest` (المفتاحُ يُحترَم بحدٍّ أدنى؛ مُعلَنٌ في الكتالوج).
- **القبول:** §46 «أين الاختناقات؟» · §13.

**توازي الطور ٧:** `7.1` أولاً (يقسّم `riskProfile` الذي تقرؤه ٧.٣). ثم `7.2` و`7.4` متوازيان (`WorkforceStats` مقابل قارئ الاختناقات والاحتفاظ)؛ `7.3` بعد `7.2` (يستهلك `WorkforceStats::person`). **تعارضٌ:** `ActivityController.php` (٧.١، ٧.٣)، `PerformanceController.php` (٧.٣)، `HubAutomation` كتلةُ الاحتفاظ (٧.٤ — **بعد** الطور ٣)، `helpers.php` (لا شيء إن عاش الحسابُ في `App\Support\WorkforceStats`)، `routes/web.php`.

---

## الطور ٨ — QUALITY & EXECUTION

**الهدف:** §47 — مشكلاتُ جودة البيانات بشدّاتها، الوحداتُ المتدهورة، ما تحسّن، العملُ المتأخّر، مؤشّراتٌ خارج الهدف، أهدافٌ متعثّرة، ومهامُّ المعالجة — في **مركزٍ واحدٍ بتبويبات** فوق المحرّكات القائمة بلا استبدال.

**القائمُ الذي يُوسَّع:** `DataQuality` (قواعدُ مشتقّةٌ من السجلّ + `?qc=` الذي يفتح نفسَ القيد في قائمة الوحدة + لقطةٌ يومية في `metric_points`) · `QualityController` + `admin/quality.blade.php` · دمجُ العملاء (`QualityController::merge`) و`Identity::merge` (النموذجُ الأفضل: أثرٌ صريح + `meta.merged_into` + بديلٌ لا حذفٌ صلب) · `hub_kpis`/`KpiDef`/`KpiController` · `hub_okr_progress/pace/board` · `SupportController` (SLA) · `hub_project_health`/`hub_progress` · `Delivery::leadTime/cadence` · `ActionCenter`.

### WP-8.1 — قشرةُ المركز بتبويبات + النظرةُ التنفيذية
- **spec:** §6 (التبويبات) · §6.1 · §12.
- **يُبنى:** `?tab=overview|data|execution|kpi|okr|trends|actions` على `quality.index` القائم عبر `partials/cc/tabs`؛ والنظرةُ الثمانية: جودةُ البيانات٪ (`DataQuality::scan totals.score`)، إنجازُ العمل٪ **من `hub_kpis()`** (مؤشّرُ «✅ نسبة إنجاز المهام» المبذور موجود — يُصحَّح ليعدّ «منجزة» و«مكتملة» عبر `hub_closed_scope`)، الالتزام٪، متأخّر، مؤشّراتٌ على الهدف، تقدّمُ OKR (`hub_okr_board`)، مشكلاتٌ حرجة (`CeoBoard::risks` — يقرأ بـ`hub_read` فيجمع النطاقَ والصلاحية)، واتّجاهُ التحسّن (`DataQuality::history`).
  **بلا درجةٍ مركّبة** غير موثَّقة (spec §6.1).
- **الحارس:** التبويبُ «data» يبقى **للمالك** (مسحُ الجودة غيرُ منطَّق ويُظهر أسماءَ سجلّاتٍ من كل الوحدات تحت مفتاح كاشٍ عام `dq:scan`)؛ بقيةُ التبويبات `hub_monitor()` + `hub_org_analytics_guard()`.
- **اختبارات:** `QualityCenterTabsTest` — كلُّ تبويبٍ يُعرض بحارسِه؛ قارئُ `monitor` لا يرى عيّناتِ الجودة؛ الأرقامُ تطابق محرّكاتِها (لا حسابٌ ثانٍ).
- **القبول:** §47 (تجميع) · §6.

### WP-8.2 — شدّةُ الجودة + عمرُ النتيجة + اتّجاهُ الوحدات
- **spec:** §6.2 · §6.3 · §6.11.
- **يُبنى:** مفتاحُ `sev` لكل قاعدةٍ في `DataQuality::rules()/curated()` (لا جدولَ ولا محرّكَ ثانٍ): مرجعٌ مكسورٌ إلى fin/contracts/purchases/users ⇒ **حرج**؛ حقلٌ مطلوبٌ غائب ⇒ **مرتفع**؛ بريد/رابط/حالةٌ خارج الخيارات ⇒ **متوسط**؛ شركةٌ غائبة/بائتٌ ⇒ **منخفض** (spec: لا يُصنَّف كلُّ شيءٍ حرجاً)، بمفردات `Severity`.
  **الاتّجاهُ لكل وحدة:** `DataQuality::snapshot()` يكتب أيضاً `('quality', <module>, 'defects'|'score')` (اليومَ `org` فقط) — فيصير «الوحداتُ المتدهورة/المتحسّنة» محسوباً لا مُدَّعىً؛ و«أوّلُ رصد/العمر» من أوّل نقطةٍ في السلسلة.
- **اختبارات:** `QualitySeverityTest` (خريطةُ الشدّة؛ لا كلَّ شيءٍ حرج) · `QualityModuleTrendTest` (لقطتان بقيمتين ⇒ وسمُ تدهور/تحسّن؛ بلا لقطاتٍ ⇒ حالةٌ فارغة صادقة).
- **القبول:** §47 «أيُّ الوحدات تتدهور؟ ما الذي تحسّن؟».

### WP-8.3 — إدارةُ التكرار (دمجٌ آمنٌ ومدقَّق)
- **spec:** §6.4 · §31 · §23.5.
- **يُبنى:** رفعُ `QualityController::merge` إلى شكل `Identity::merge` (`app/Support/Identity.php:257-284`): معاينةٌ (نفسُ حلقة المراجع بـ`count()` بدل `update()`)، سببُ التشابه والحقلُ المطابق، مقارنةٌ جنباً إلى جنب، عددُ المراجع لكل مرشَّح، **قيدُ تدقيقٍ صريح** («دمج عملاء» بالمنقول و`request_id`) — اليومَ لا `hub_audit` إطلاقاً — و`meta.merged_into/merged_at`، وحذفٌ ناعمٌ فقط، وتحقّقٌ من أنّ المعرّفاتِ الممرَّرة تنتمي فعلاً لمجموعةِ تكرارٍ مكتشَفة (اليومَ قائمةٌ حرّة تُحلّ بـ`findOrFail` غيرِ منطَّق)، وتخبئةُ كشف التكرار بـ`hub_screen` (٥٠٬٠٠٠ صفٍّ في الذاكرة عند كل فتح).
- **اختبارات:** `DuplicateMergeTest` — المعاينةُ لا تكتب؛ التنفيذُ يكتب قيدَ دمجٍ واحداً؛ **سجلٌّ ثالثٌ غيرُ ذي صلة لا يُمَسّ** (فجوةُ §23.5 اليوم)؛ لا حذفَ صلب؛ معرّفٌ من خارج المجموعة يُرفض.
- **القبول:** §47 · §31.

### WP-8.4 — تحليلاتُ التنفيذ (مهامّ/مشاريع/تذاكر) + اتّجاهُها
- **spec:** §6.5 · §6.6 · §6.7 · §6.8 · §6.12.
- **يُبنى:** قارئٌ واحد `App\Support\ExecutionStats` بتجميعاتٍ منطَّقة: مخطّط/منجَز/مفتوح/متأخّر/متوقّف (`متوقفة`)/الإنجاز٪/الالتزام٪/الإنتاجية؛ وجدولُ مشاريعَ بتجميعٍ **بالجملة** (لا `hub_project_health` لكل صفٍّ = ٧ استعلاماتٍ لكلٍّ) مع عمودِ معوّقاتٍ من تجميع `work_updates.problems` القائم؛ وتدفّقُ المهامّ (أُنشئت/أُنجزت/أُعيد فتحُها/متأخّرة/زمنُ الدورة/الإنتاجية) بشكل `Delivery::leadTime` (وسيطٌ + وسمُ عيّنة)؛ وجودةُ التذاكر (فُتحت/حُلّت/SLA٪/متوسّطُ الاستجابة والحل/أُعيد فتحُها) مع تصحيحِ عيبٍ قائم: `SupportController` يعدّ «المفتوحة» من صفحةٍ محدودة بـ٦٠ صفّاً.
- **هجرة:** `tasks.completed_at` (nullable، مفهرس) يُختم عند دخول حالةٍ مغلقة ويُمسح عند إعادة الفتح (مرآةُ منطق `meta.resolved_at` للتذاكر في `ModuleController:974-984`) + `meta.reopened`؛ **وفهرسُ `tasks(due)`** (غائبٌ اليوم رغم أنّ كلَّ استعلام تأخّرٍ يرشّح به).
  السبب: نسبةُ الالتزام تُحسب اليومَ من `updated_at` («وقتُ الإغلاق التقريبي») فأيُّ تعديلٍ لاحق يُفسدها.
- **لقطةُ التنفيذ (§6.12):** ضمن `hub:quality-snapshot` القائم: `('execution','org','completion_pct'|'ontime_pct'|'overdue'|'open_issues')` (+ لكل مشروعٍ إن لزم، على نمط `marginSnapshot`).
- **اختبارات:** `ExecutionAnalyticsTest` (أرقامٌ تطابق البذور؛ تعديلٌ بعد الإنجاز لا يقلب «في الموعد»؛ ميزانيةُ استعلاماتٍ لا تنمو مع عدد المشاريع) · `ExecutionTrendTest` (لقطةٌ يومية تُحدَّث ولا تكرّر).
- **القبول:** §47 «ما العملُ المتأخّر؟» · §46 (بالاشتراك).

### WP-8.5 — مركزُ KPI + مركزُ OKR + أفعالُ التحسين
- **spec:** §6.9 · §6.10 · §6.13 · §31.
- **يُبنى:** KPI: مالكٌ ودورة (`kpi_defs.owner_id`, `period` — هجرةٌ إضافية)، انحرافٌ (`value-target` بإشارة `good`)، اتّجاهٌ من لقطةٍ يومية `('kpis', <id>, 'value')`، وصحّةٌ (على الهدف/تحذير/خارج الهدف) **مع قائمةِ «خارج الهدف»** (§47).
  **إصلاحٌ لازم (COMMAND_VERIFIED):** سبعةٌ من مؤشّرات البذر تُرشِّح حالاتٍ لا وجودَ لها في السجلّ (tasks «متأخرة»، tickets «مفتوحة»، clients «نشط»، fin «غير مدفوعة»، decisions «منفّذ» ≠ «منفَّذ»، compliance «مستوفى»، feats «مكتمل») فتقرأ ٠٫٠ أبداً — و٠ مقابل هدفٍ ٠ باتّجاه «down» يُعرَض **«على الهدف»**؛ تُصحَّح من `KpiController::catalog()` (والمتأخّرُ ليس حالةً ⇒ يُبنى على `due` أو يُحذف).
  OKR: عدّاداتُ متأخّر/متعثّر/راكد (من `key_results.read_at`/`objectives.computed_at`)، وتسلسلٌ بالمستوى + `project_id` (لا `parent_id` في المخطّط)، ومالكٌ باسمه؛ **ومصدرٌ واحد**: الشاشاتُ كلُّها تقرأ `hub_okr_progress`/`hub_okr_board` (اليومَ `/performance` يقرأ عمودَ `objectives.progress` المخزَّن فيتناقضان).
  أفعالُ التحسين (§6.13): زرُّ «أنشئ مهمّةَ معالجة» لكل نتيجةِ جودةٍ/هدفٍ متعثّر/خرقِ SLA — يستعمل **نظامَ المهامّ** كما يفعل `ErrorCenterController::toTask` (بذاكرةٍ تمنع التكرار)، ويُخزَّن الرابطُ العكسيّ بنمط البيت (عمود `task_id` على المصدر حيث يوجد، وإلا `tasks.meta.origin = {kind, module, key}`)، ويُدقَّق.
- **اختبارات:** `KpiCenterTest` (مالك/دورة/انحراف/اتّجاه؛ **كلُّ مؤشّرٍ مبذور يستعمل حالةً موجودةً في السجلّ** — يفشل اليوم) · `OkrCenterTest` (عدّادات؛ رقمٌ واحدٌ في الشاشتين) · `RemediationTaskTest` (مهمّةٌ واحدة لكل نتيجة؛ صلاحيةُ `tasks:a`؛ وراثةُ الشركة؛ أثرٌ).
- **القبول:** §47 كامل.

**توازي الطور ٨:** `8.2` و`8.3` (محرّكُ الجودة والدمج) مقابل `8.4` (تنفيذ + هجرة المهامّ) مقابل `8.5` (KPI/OKR + هجرة `kpi_defs`) ⇒ ثلاثةٌ متوازية؛ `8.1` (القشرة) أولاً أو أخيراً في worktree خاصّ. **تعارضٌ:** `admin/quality.blade.php` (٨.١، ٨.٢، ٨.٣) ⇒ أقسامٌ مستقلّة؛ `QualityController.php` (٨.١، ٨.٣)؛ `ModuleController.php` (٨.٤ ختمُ `completed_at` — وهو الملفُّ نفسُه الذي يمسّه الطور ٦ لشرط الإغلاق ⇒ رتّب ٦ قبل ٨)؛ `helpers.php` (٨.٥ إن مُسّت دوالُّ OKR/KPI — يُفضَّل وضعُ الجديد في `App\Support`)؛ `routes/web.php`.

---

## الطور ٩ — SETTINGS

**الهدف:** §48 — ما هذا الإعداد، ما افتراضيّه، ما الساري فعلاً، ما أثرُ تغييره، هل هو خطر، من غيّره آخر مرّة، هل أستعيده بأمان، هل التكاملُ يعمل، وهل أُصدِّر الإعداداتِ بأمان.

**القائمُ الذي يُوسَّع:** `config/hub_settings.php` (٧١ مفتاحاً معروضاً + ٢٩ داخليّاً، بحقول label/type/def/effect/where/risk) · `SettingController` (CHECKS + SECRETS + قناعُ `••••` + أثرٌ «قبل/بعد» ببصمةٍ للأسرار) · `setting()` + كاش `settings:all` · `MailSettings::fromScreen` · `Integrations::installed/pulse` · `SecurityPosture` (الرايات) · `StepUp`.

### WP-9.1 — نموذجُ المعلومات + القيمةُ السارية والمصدر
- **spec:** §7.1 · §7.3 · §7.4 · §7.2.
- **يُبنى:** حقولٌ **إضافية** لكل مدخلٍ في الكتالوج: `default` (قيمةٌ آليّة لا نثر)، `sensitive` (bool)، `validation` (تُنقَل من `SettingController::CHECKS` فتصير مصدراً واحداً)، `depends` (مجموعة)، `scope`، `restart`، `env_key`، `owner_route`، `doc`. و`internal` يصير مصفوفةً `{why, owner_route}` مع قبولِ الصيغة النصّية القديمة.
  `App\Support\Settings::effective(string $key): array{stored, default, effective, source, sensitive}` — يقرأ الأرضياتِ الحقيقية من الشيفرة (`hub_upload_cap` هو النموذجُ العامل: يقول **من** فرض الحدّ)، ويصنّف المصدرَ: default (لا صفّ) · database (صفٌّ موجود) · environment (`env_key` وقيمةٌ خالية — **بلا إظهار قيمةِ البيئة**) · dedicated module (`internal.owner_route`).
  **مشتقّاتُ الاتّساق:** `SettingController::SECRETS` تُشتقّ من `sensitive` — فيغلق عيبٌ قائم: `hub:set n8n.key` يخزّن نصّاً صريحاً بينما شاشةُ التكامل تشفّره.
  لوحةُ §7.2: إجمالي المفاتيح · المُغيَّر عن الافتراضي (وجودُ صفّ، بعد استثناء ٧ مفاتيحَ يبذرها `CoreSeeder` بقيمها الافتراضية) · عاليةُ الخطورة · أسرارٌ مضبوطة · تكاملاتٌ ناقصةُ الإعداد (`Integrations::installed()` = `CONFIGURATION_REQUIRED`) · راياتٌ نشطة.
- **اختبارات:** `SettingsModelTest` — كلُّ مدخلٍ معروضٍ له `default` آليّ؛ `default` يطابق القيمةَ الحرفية في نداء `setting('k', <lit>)` (مسحٌ ساكن بنمط `SettingsCenterTest::liveKeys`)؛ المصدرُ يُصنَّف صحيحاً للأربعة؛ لا قيمةَ بيئةٍ في HTML؛ `hub:set` يشفّر كلَّ مفتاحٍ `sensitive` (يفشل اليوم على `n8n.key`).
- **القبول:** §48 «ما افتراضيّه؟ ما الساري؟ من أين؟».

### WP-9.2 — كاتبٌ واحد + تاريخُ الإعداد + آخرُ تعديل
- **spec:** §7.5 · §7.6 · §31.
- **يُبنى:** `App\Support\Settings::put(string $key, $value, string $source, ?string $reason = null)` — نقطةُ الكتابة **الوحيدة**: تحقّق (من الكتالوج) → تشفيرٌ للحسّاس → كتابة → `Cache::forget('settings:all')` (منسوخٌ اليومَ في ١٥ موضعاً) → `hub_audit('تعديل إعدادات النظام')` بـ«قبل/بعد» ببصمةٍ للأسرار → صفٌّ في `setting_changes`.
  تُعاد إليه: `SettingController::put`، `MessagingController::mail/telegram`، `N8nController::save`، `OdooConnectionController::defaults`، `SecurityController::freeze/lockdown`، `OpsController::toggleMaintenance`، `HubSet` (بلا أثرٍ اليوم)، `HubImportJson` (بلا تحقّقٍ ولا أثر)، ومسارُ الاستيراد الجديد. (`Health::beat` و`Integrations::pulse` **يبقيان** خارجَه: حالةٌ تشغيلية لا إعداد.)
  **جدولٌ جديد (ق٨):** `setting_changes` — `id`، `key` string(120)، `before` json، `after` json (الأسرارُ مبصومة)، `user_id` uuid null، `source` string(20) (`screen|messaging|odoo|n8n|security|ops|cli|import|restore|demo`)، `request_id`، `audit_id` bigint null، `created_at`. **فهارس:** `(key, created_at)`، `(created_at)`. **الإعلان:** `HubBackup::RAW_TABLES`. البديلُ المرفوض: مسحُ `audits` — القيدُ واحدٌ لدفعةٍ كاملة (المفاتيحُ في `after._keys`)، والاسمُ مقصوصٌ إلى ٣٠٠ حرف، و`record_id` من نوع uuid فلا يسع المفتاح.
- **اختبارات:** `SettingsHistoryTest` — كتابةٌ من كلِّ مسارٍ من العشرة تُنتج صفَّ تاريخٍ وقيدَ تدقيق؛ الأسرارُ مبصومةٌ في الاثنين؛ «آخر تعديل (من/متى)» يظهر لكل مفتاح؛ `hub:set` صار يُدقَّق.
- **القبول:** §48 «من غيّره آخر مرّة؟» · §31.

### WP-9.3 — معاينةُ التغيير + استعادةُ الافتراضي + تحقّقُ التبعية
- **spec:** §7.7 · §7.8 · §7.9 · §18.
- **يُبنى:** معاينةٌ قبل الحفظ (خطوةُ dry-run تُعيد `A → B` لكل مفتاحٍ متغيّر مع وسمِ **عالي الخطورة** من `risk` + قائمةِ `SecurityEvents.php:98` النمطيّة `security.|auth.|sec.|api.token|risk.|2fa|maintenance.` مضافاً إليها `mail.*`, `odoo.*`, `files.max_kb`, `cost.work_*`) — واجهةُ التأكيد على سكّة `data-confirm` القائمة والنمطِ الموجود في `EsignController::preview`/`ImportController` (تسليمٌ عبر الجلسة).
  الاستعادة: **حذفُ الصف** = العودةُ إلى الافتراضي (كما تفعل شاشةُ الأمن لرايات الطوارئ) بدل تخزين `''` الذي يخلط «مفرَّغ» بـ«افتراضي»؛ لمفتاحٍ واحد ولمجموعة (نمطُ التسجيل ثم الحذف في `HubDemo::seedSettings/purge`)، بتأكيدٍ و`hub_require_stepup()` للمفاتيح عالية الخطورة، وبأثرٍ («استعادة افتراضي الإعدادات» — يُسجَّل في `SecurityEvents::CODES`).
  تحقّقُ التبعية كمجموعات: البريدُ (host ⇒ port/encryption/username/from_address — القاعدةُ موجودةٌ في `MessagingController` ومفقودةٌ في شاشة الإعدادات حيث تُحفظ المفاتيحُ فرادى فيصير المُرسِلُ نصفَ مضبوطٍ حيّاً)، أودو (url/db/user/key + **`hub_outbound_ok` الذي تفتقده شاشةُ الإعدادات ويطبّقه مركزُ التكامل**)، تلجرام (رمزٌ قبل قناة)، ساعاتُ العمل (`start < strict_from`, HH:MM)، نطاقاتُ الخطر (`band_medium < high < critical`). **ولا تُخترَع قاعدةٌ لا يقابلها كود** — مثالاً `notify.quiet` مبذورٌ بلا أيّ قارئ ⇒ لا تحقّقَ له.
- **مسارات:** `POST admin/settings/preview` (`settings.preview`) · `POST admin/settings/restore` (`settings.restore`) — مالكٌ + تأكيد + step-up للخطر + أثر.
- **اختبارات:** `SettingsPreviewTest` (المعاينةُ لا تكتب شيئاً — `assertDatabaseMissing` + الكاشُ سليم) · `SettingsRestoreTest` (الاستعادةُ تحذف الصفَّ لا تكتب `''`؛ مفتاحٌ خطرٌ يطلب step-up؛ مفتاحٌ افتراضيُّه «مُشغَّل» يعود مُشغَّلاً) · `SettingsDependencyTest` (مجموعةُ بريدٍ ناقصة تُرفض؛ أودو بعنوانٍ داخليّ يُرفض من شاشة الإعدادات أيضاً؛ لا قاعدةَ لـ`notify.quiet`).
- **القبول:** §48 «ما أثرُ تغييره؟ هل أستعيده بأمان؟» · §18 (تغييرُ إعدادٍ خطر).

### WP-9.4 — تصديرٌ/استيرادٌ آمن + اختباراتُ الاتصال + رايات التشغيل
- **spec:** §7.11 · §7.12 · §7.10 · §7.13 · §35.
- **يُبنى:** تصديرُ JSON للمفاتيح **غير الحسّاسة** المعروضة (+ داخليٍّ مُعلَنٍ `exportable`) مع `exported_at` و`version=config('hub.version')`، **مستثنياً** قيم `enc:` وصفوفَ الحالة (`heartbeat.*`, `integration.*`, `demo.*`, `custom.fields_seq`, `esign.tpl_seeded`) — وهي مفاتيحُ **لا مدخلَ لها في الكتالوج إطلاقاً** فتُصنَّف ببادئةٍ صريحة؛ ويحترم `security.freeze_exports` (٤٢٣) ويُدقَّق (`DATA_EXPORT`).
  الاستيرادُ بستّ خطواتٍ (رفع → تحقّقُ المفاتيح → فرق → وسمُ الخطر → تأكيد → تطبيق) عبر `Settings::put`؛ **المفاتيحُ الحسّاسة مرفوضةٌ دائماً**؛ والمجهولةُ تُرفض بتحذير؛ و`hub:import` (الاستعادةُ الكاملة) **يبقى كما هو** ولا يُخلط بهذا.
  اختباراتُ الاتصال (§7.10): توحيدُ فاحصَي أودو (مساران اليوم لنفس الاتصال) على `Uptime::check`-الشكل (`up/code/ms/error`) بزمنِ استجابةٍ ورسالةٍ **مطموسة عبر `Redactor`** (اليومَ تُعاد `$e->getMessage()` خاماً)، وإضافةُ فاحصٍ لـn8n بـ`hub_outbound_ok`، و`throttle:10,1` لكلّ فاحصٍ (بلا خنقٍ اليوم).
  الرايات (§7.13): بطاقةُ **قراءةٍ فقط** (صيانة/تجريبي/قفلُ الطوارئ/تجميدُ التصدير/تجميدُ الرموز) تربط إلى مالكِها (`ops.index` / `security.index`) — **بلا مفاتيحَ مكرّرة**؛ ويُضاف صفّان للتجميدين في `SecurityPosture::ORDER` كي تُقرأ من مصدرٍ واحد.
- **مسارات:** `GET admin/settings/export` (`settings.export`) · `POST admin/settings/import` (`settings.import`) · `POST admin/settings/import/apply` (`settings.import.apply`) — مالكٌ + أثرٌ + step-up عند وجود مفتاحٍ خطر.
- **اختبارات:** `SettingsExportTest` (لا `enc:` ولا مفاتيحَ حالة؛ ٤٢٣ عند التجميد؛ أثرٌ) · `SettingsImportTest` (مفتاحٌ مجهول/حسّاس/قيمةٌ مخالفة ⇒ رفض؛ الفرقُ قبل التطبيق؛ الأثر) · `ConnectionTestsTest` (زمنٌ ورسالةٌ مطموسة؛ عنوانٌ داخليّ مرفوض؛ خنقٌ فعّال) · `RuntimeFlagsCardTest` (لا نموذجَ تبديلٍ في صفحة الإعدادات).
- **القبول:** §48 «هل التكاملُ يعمل؟ هل أُصدِّر بأمان؟» · §35.

**توازي الطور ٩:** `9.1` أولاً (الكتالوجُ أساسُ الباقي). ثم `9.2` (الكاتبُ الواحد + الجدول) — **يجب أن يسبق** ٩.٣ و٩.٤ لأنهما يكتبان عبره. `9.3` و`9.4` متوازيان بعده. **تعارضٌ:** `config/hub_settings.php` (٩.١ يعيد تشكيل كل مدخل — تُدمَج وحدها أولاً)، `SettingController.php` (٩.١–٩.٤)، `settings/form.blade.php` (٩.١، ٩.٣، ٩.٤) ⇒ أقسامٌ مستقلّة، `routes/web.php`.

---

## الطور ١٠ — GLOBAL CONTROL CENTER

**الهدف:** ربطُ المراكز السبعة في مستوى قيادةٍ واحد: نظرةٌ عامّة، قائمةُ «يحتاج انتباهك»، تنقّلٌ وبحثٌ عابرٌ للمراكز — **بلا لوحةٍ عملاقةٍ تكرّر التفاصيل** (spec §32).

### WP-10.1 — نظرةُ التحكّم (System Control)
- **spec:** §32 · §12.
- **يُبنى:** صفحةٌ خفيفة `GET admin/control` (`control.index`) ببطاقاتٍ **قارئةٍ فقط** تربط إلى مراكزها: الأمن (الوضعية + نتائجُ حرجة من `SecurityFindings`) · التشغيل (`Health::check` + حادثةٌ نشطة) · الأخطاء (حرجٌ غيرُ محلول) · التدقيق (نزاهةُ السلسلة من `Audit::verifyTail` أو آخرِ صفٍّ في `audit_verifications`) · الجودة (`DataQuality` score) · التنفيذ (عملٌ حرجٌ متأخّر).
  كلُّ رقمٍ يُقرأ من قارئه لا يُعاد حسابُه؛ والصفحةُ مخبّأةٌ ٦٠ ثانية بـ`hub_screen_stamped` مع شارةِ نضارة — لأنّ `Health::check` وحدَه ٥٥ استعلاماً (`RUNTIME_VERIFIED`).
- **الحارس:** `hub_is_owner()` (وبطاقةٌ لكلّ ما يراه `hub_monitor` إن فُعّل ق١).
- **اختبارات:** `ControlHomeTest` — كلُّ بطاقةٍ تربط بمركزها؛ الأرقامُ تطابق قارئَها؛ ميزانيةُ استعلامات؛ ٤٠٣ للموظّف.
- **القبول:** §32 · §41/٥.

### WP-10.2 — قائمةُ «يحتاج انتباهك» + التوصيات
- **spec:** §33 · §34.
- **يُبنى:** `App\Support\AttentionQueue::items($user)` — منتِجٌ **ثانٍ** يُدمج في `ActionCenter::feed()` القائم (فتُعاد استعمالُ سكّة الإقرار/التأجيل في `signal_states` بلا مخزنٍ ثالث)، بمصادرَ سبعة فقط وبلا ضجيج: نتيجةٌ أمنية حرجة (`security_findings`) · تدهورُ النظام (`Health` غيرُ سليم) · خطأٌ حرجٌ غيرُ محلول · سلسلةُ تدقيقٍ مكسورة · مشكلةُ مجدول/طابور شديدة · مشكلةُ جودة بيانات حرجة (WP-8.2) · هدفٌ حرجٌ متأخّر. مفاتيحُ ثابتة: `health.<component>`, `audit.chain`, `error.critical:<hash>`, `sec.finding:<code>[:<entity>]`, `quality.<module>:<rule>`, `okr.<id>`, `alert:<dedup_key>`.
  الشكلُ الموحَّد = شكلُ `hub_recommendations` (`sev, ico, title, why, url, action, key, module, record_id`) مضافاً إليه `type` و`detected` و`owner`؛ والشدّةُ عبر `Severity`. والتوصيةُ (§34) حتميّةٌ من `fix/url` في `SecurityPosture::row` ومن خريطةِ توصياتٍ صغيرة لمكوّنات `Health` (اليومَ تحمل `why` بلا `fix/url`).
  **تحذيرُ تسمية:** كلمةُ «انتباه» مستعملةٌ سلفاً لعدّاد انتهاءِ الصلاحيات في `Workspaces::attentionByModule` (شارةُ الشريط الجانبي) ⇒ إمّا اسمٌ مميّز أو دمجُ ذلك العدّاد صراحةً.
- **مسارات:** يُعرض في `control.index` وفي `/recommendations` القائم (`recs`) — بلا مسارِ إقرارٍ جديد (`recs.act` قائم).
- **اختبارات:** `AttentionQueueTest` — المصادرُ السبعة تظهر بمفاتيحَ ثابتة؛ التأجيلُ عبر `recs.act` يُخفيها؛ الحرجُ لا يُهمَل (سلوكُ `ActionCenter` القائم)؛ لا تختلط الإشاراتُ التجارية إلا الحرجَ منها؛ لكلٍّ توصيةٌ ورابط.
- **القبول:** §33 · §34.

### WP-10.3 — التنقّل والبحثُ عابرُ المراكز
- **spec:** §11 · §10.
- **يُبنى:** شريطُ الإدارة (`layouts/app.blade.php`) يُرسَم من **`hub_admin_links()`** (WP-1.5) بمجموعاتِ spec: الأمنُ والرقابة (تدقيق/أمن/قوى عاملة) · التشغيل (تشغيل/أخطاء/حوادث/تنبيهات) · الجودةُ والحوكمة · الإعدادات (إعدادات/تكاملات/أدوار/مستخدمون) — **بلا كسرِ أيّ مسارٍ أو اسمِ مسار**؛ وتُضاف الحوادثُ (`m.index incidents`) والتنبيهاتُ (`alerts.center`) اللتان لا تظهران في الشريط اليوم.
  `SearchController::destinations` يقرأ **نفسَ الكتالوج** (القائمتان متباعدتان اليوم: البحثُ يفتقد «نشاط الموظفين» و«التكاملات»)؛ وتُضاف مطابقاتٌ تشغيلية: `request_id` (بنمط `Observability` نفسِه) ⇒ `system.trace`؛ معرّفُ خطأ ⇒ `errors.show`؛ IP ⇒ التدقيق/الرفض (بعلم `audit` أو المالك)؛ مفتاحُ إعدادٍ ⇒ `settings.edit#key`؛ مستخدمٌ ⇒ `users.edit` (بعلم `users`) — كلُّها **مشروطةٌ بنمطٍ في النصّ** كي لا يزداد عبءُ البحث (٨١ استعلام LIKE لكل ضغطة اليوم).
- **اختبارات:** `NavCatalogTest` (الشريطُ والبحثُ من مصدرٍ واحد؛ كلُّ مسارٍ قائمٍ ما زال يُحلّ؛ الحوادثُ والتنبيهاتُ ظاهرتان للمالك فقط) · `OperationalSearchTest` (المالك يجد rid/خطأً/IP/مفتاحاً؛ الموظّفُ لا يجد شيئاً منها؛ لا استعلاماتِ LIKE إضافية لنصٍّ عاديّ).
- **القبول:** §10 · §11 · §41/٤.

**توازي الطور ١٠:** `10.1` و`10.2` متلازمتان (البطاقاتُ تقرأ القائمة) ⇒ worktree واحد؛ `10.3` منفصل (شريطٌ + بحث) لكنه **يمسّ `layouts/app.blade.php`** الذي حُجز له وحده طوالَ الخطّة. الطور ١٠ **آخرُ الأطوار** لأن بطاقاتِه تقرأ ما تنتجه ٢–٩؛ ما لم يُنجَز بعدُ يظهر بحالةٍ فارغة صادقة لا برقمٍ صفر.

---

## مؤجَّلٌ (DEFERRED) — بما لا يمكن إنجازُه لغياب بياناتٍ أو بنيةٍ حقيقية

| البند | السبب الدقيق | ما يُسلَّم بدلَه |
|---|---|---|
| قِيَم p50/p95/p99 **مضبوطة** | تخزينُ مدّة كل طلبٍ إلى الأبد ممنوع (§3.3/§13)؛ فالحسابُ من مدرَّجٍ لوغاريتميّ | نسبٌ تقريبيةٌ **موسومةٌ** بحدّ خطأ الحاوية (ق٥) |
| تاريخُ الأداء/الوضعية/التنفيذ قبل النشر | لا لقطاتٍ سابقة، والتلفيقُ ممنوع (§26) | «سيبدأ القياس من الآن» / «لا توجد بيانات تاريخية كافية»، وتبدأ الرسومُ بالتراكم |
| SLO والانحدارُ وارتباطُ الإصدار | تحتاج نافذتين متراكمتين من `http_metric_buckets` | المحرّكُ يُبنى ويُختبَر ببذور؛ الشاشةُ تصرّح بنقص التاريخ |
| «التحميلُ المتزامن» في SATURATION (§3.3) | لا عاملَ طوابيرَ ولا عدّادَ اتصالاتٍ موثوق على استضافةٍ مشتركة بـ`QUEUE_CONNECTION=sync` | تأخّرُ الطابور (`Health::outbox`) + زمنُ القاعدة + الحاوياتُ — مع تصريحٍ بأن التزامنَ غيرُ مقيس |
| geo-IP / السفرُ المستحيل (§2.8) | لا مزوّدَ geo (وspec يجعله اختيارياً) | ذكاءُ عناوينَ **داخليّ** فقط (أول/آخر ظهور، فشلٌ متكرّر، تعدّدُ حسابات) |
| تحقّقُ تكاملٍ خارجيّ حقيقي (أودو/SMTP/تلجرام) | لا اعتماداتٍ ولا خوادمَ في بيئة التنفيذ (ENTERPRISE_UPGRADE_REPORT §F: BLOCKED) | أزرارُ اختبارٍ حقيقية + `Http::fake` في الحزمة؛ لا تُدَّعى `INTEGRATION_VERIFIED` |
| فحصُ الجوّال/الليل/RTL في متصفّح (§41/٦–٨) | لا متصفّحَ في الجلسة | اختباراتُ مصدرٍ (`DesignSystemGuardTest`, `StyleVocabularyTest`, `MobileDensityTest`) + مراجعةُ توكنز؛ توسَم `COMMAND_VERIFIED` |
| قياسُ أداءٍ على MySQL بحملٍ إنتاجيّ (§37) | لا بياناتِ إنتاج | ميزانياتُ عددِ استعلاماتٍ في الحزمة (نمط `ScreenPerformanceTest`) على المحرّكين |
| نسخٌ احتياطيّ خارج الخادم | لا قرصَ بعيدٍ مضبوط (TECH_DEBT #8/CFG-02) | تاريخُ النسخ ومحاولاتُ الفشل والتشفيرُ والتحقّق — والاستعادةُ تبقى CLI (`hub:import`) |
| استعادةُ نسخةٍ من الويب (§3.13) | لا بنيةَ آمنة لزرٍّ واحد | يبقى الأمرُ + كتيّبُ التشغيل، ويُصرَّح بذلك في الشاشة |
| `script-src` في CSP (§19) | ٢٢ سكربتاً مضمّناً و٤٥ معالجَ حدث (TECH_DEBT #12) | `Content-Security-Policy-Report-Only` خلف مفتاحٍ مطفأ افتراضياً + نقطةُ تقريرٍ اختيارية |
| تدويرُ رمز API لمستخدمٍ آخر (§2.9) | النصُّ الصريح يُسلَّم للمدير — قرارُ منتجٍ لا نقصُ بنية (ق٧) | إبطالٌ/تعطيلٌ بـstep-up وأثر |
| مصفوفةُ CI بـ`explicit_defaults_for_timestamp=OFF` / MariaDB 10.6 | لا صورةَ كهذه في CI (TECH_DEBT #2/#32) | يبقى ديناً موثّقاً؛ الهجراتُ الجديدة تُكتب بـ`nullable()`/`useCurrent()` احترازاً |
| «الحادثةُ تُغلق آلياً» عند شفاء الشرط | إغلاقٌ صامتٌ للأدلّة ممنوع (§13/§3.9) | قيدُ «تعافت الخدمة» + إبقاءُ الحادثة لقرارِ إنسان |
| ملءٌ رجعيّ لأعمدة التدقيق الجديدة | كتابةٌ جماعية على جدولٍ مختوم بقفلِ رأسِ سلسلةٍ طويل (TECH_DEBT #1 AUD-07) | مُترجِمُ قراءةٍ للصفوف القديمة؛ الأعمدةُ تُملأ للجديد فقط |
| `first_seen_at` لعناوين IP التاريخية | لا مصدرَ لما قبل العمود | يُملأ للجديد؛ وأوّلُ ظهورٍ تقريبيٌّ يُشتقّ من `audits(created_at,ip)` مع تصريح |

---

## خلاصةُ التسلسل والاعتماديات

```
الطور ١ (أساسات) ──┬─► ٢ (رصد)  ──┬─► ٤ (أمن)  ─┐
                    ├─► ٣ (أخطاء) ─┤              ├─► ٦ (حوادث/تنبيه) ─► ١٠ (مركز التحكّم)
                    └─► ٥ (تدقيق) ─┘              │
                       ٧ (قوى عاملة) ─ ٨ (جودة) ──┘        ٩ (إعدادات) ─► ١٠
```
- **إجباريّ:** ١ قبل الكلّ (TimeRange/Severity/Redactor/Correlation/المكوّنات/أوّليّات القياس).
- **إجباريّ:** ٢ قبل ٤.٢/٣.٤ (الحاويات والقياس) · ٣ قبل ٧ (كتلةُ الاحتفاظ) · ٦ قبل ٨ (`ModuleController`) · ١٠ أخيراً.
- **مستقلٌّ عملياً:** ٩ (الإعدادات) يمكن أن يجري بالتوازي مع ٤–٨ ما دام لا يمسّ إلا `SettingController`/`config/hub_settings.php`/`settings/*` — بشرطِ تنسيق مفاتيح الإعدادات مع ٢ و٤.

---

## مصفوفةٌ مرجعية لكل حزمةِ عمل (هجرة · مفاتيح · مسارات وحرّاسُها · مجدول · اختبار)

> «كلُّ المسارات تُضاف داخل `Route::middleware('auth')->group()` في `routes/web.php`؛ **لا حارسَ في الوسيط** — التخويلُ في دالّة `gate()` بالمتحكّم كما هو نمطُ المستودع (`OpsController.php:16`, `SecurityController.php:22`, `AuditController.php:21`)، ويُضاف `throttle` حيث الفعلُ مكلفٌ أو خارجيّ.»

| WP | هجرة/جدول | مفاتيحُ إعداد | مسارٌ (اسم) — الحارس | مجدول | ملفُّ اختبارٍ يفشل أولاً |
|---|---|---|---|---|---|
| 1.1 | — | — | — | — | `TimeRangeTest` |
| 1.2 | — | — | — | — | `SeverityMapTest` |
| 1.3 | — | — | — | — | `RedactorTest` |
| 1.4 | `access_denials.request_id`, `inbound_hook_events.request_id`, `incidents.request_id` (+فهارس) · فهرس `error_events.request_id` · `audits(user_id,created_at)`, `audits(action,created_at)`, `access_denials(ip,created_at)`, `user_ips(ip)` | — | `GET system/trace/{rid}` (`system.trace`) — `hub_is_owner()‖hub_flag('audit')` + `throttle:60,1` | — | `RequestTraceTest` |
| 1.5 | — | — | — | — | `ControlCenterUiKitTest` |
| 1.6 | — | — | — | — | `MetricPrimitivesTest` |
| 2.1 | — | `ops.cpu_warn/crit`, `ops.mem_warn/crit`, `ops.disk_warn/crit`, `ops.db_ms_warn`, `ops.queue_age_warn/crit`, `ops.http_p95_ms`, `ops.scheduler_late_factor` | — | — | `OpsThresholdsTest` |
| 2.2 | **جدول** `http_metric_buckets` (+فريد `(bucket_at,surface,method,route)` + `(route,bucket_at)`) — `EPHEMERAL` | `retention.http_buckets_days` | — | (تقليمٌ في `hub:automation`) | `HttpMetricsTest` |
| 2.3 | — (نقاطٌ في `metric_points`) | `ops.last_version` | — | **`hub:ops-snapshot`** كل ٥ د + `Health::JOBS['ops']` | `OpsSnapshotTest` |
| 2.4 | — | `ops.regression_pct`, `ops.regression_min_n` | قسمٌ في `ops.index` (+`?sort=`) — `hub_is_owner()` | — | `RoutePerformanceTest` |
| 2.5 | — | `slo.availability_pct`, `slo.latency_ms`, `slo.latency_pct`, `slo.error_rate_pct`, `slo.window_days` | — | — | `SloTest` |
| 2.6 | — | — | `POST admin/ops/outbox/{id}/retry` (`ops.outbox.retry`) — owner + `hub_require_ops_stepup` + `throttle:30,1` | — | `OpsDependenciesTest`, `OutboxOpsTest` |
| 2.7 | — | — | — | — | `OpsResilienceTest`, `OpsHeaderTest`, `MigrationStatusTest` |
| 3.1 | — | — | — | — | `FingerprintTest` |
| 3.2 | **جدول** `error_occurrences` (+`(error_event_id,occurred_at)`, `(request_id)`, `(occurred_at)`) — `EPHEMERAL` | `errors.occurrences_keep`, `retention.error_occurrences_days` | — | تقليمٌ في `hub:automation` | `ErrorOccurrencesTest` |
| 3.3 | أعمدةُ `error_events`: assignee_id(+فهرس), priority, due_at, resolved_at/by/release, regressed_at, regression_release, ignored_reason/by, muted_until, incident_id, notes | — | (توسيعُ `errors.status`/`errors.task` القائمين) — owner + `hub_audit` | — | `ErrorLifecycleTest` |
| 3.4 | — | — | — | — | `ErrorDashboardTest` |
| 3.5 | — | `errors.notify_cooldown_min`, `ops.log_tail_kb` | `GET admin/errors/logs` (`errors.logs`) — owner + `throttle:30,1` | — | `ErrorNoiseTest`, `LogSearchTest` |
| 4.1 | **جدول** `security_findings` (+فريد `(code,entity_type,entity_id)`, `(status,severity)`, `(last_seen_at)`) — `RAW_TABLES` | — | `GET admin/security/findings` · `/{id}` · `POST .../ack` · `POST .../resolve` — قراءةٌ `owner‖monitor`، فعلٌ owner + `hub_audit` | — | `SecurityFindingsTest` |
| 4.2 | — (نقاطٌ في `metric_points`) | — | — | **`hub:security-snapshot`** يومياً ٢٣:٤٠ + `Health::JOBS['security']` | `SecurityPostureHistoryTest` |
| 4.3 | — | `security.idle_days_1/2/3`, `security.secret_stale_days`, `security.token_unused_days` | `GET admin/security/identity` · `/privileged` — قراءةٌ `owner‖monitor` (+`hub_field_mode`)، أفعالٌ owner | — | `IdentityRiskTest`, `PrivilegedReviewTest` |
| 4.4 | `sessions_log.revoked_at/by/reason` · `user_ips.first_seen_at` | — | `GET admin/security/sessions` · `/devices` · `/ips[/{ip}]` — owner (بياناتٌ شخصية) · `POST admin/security/users/{id}/revoke-others` — owner + **step-up** (ويُضاف step-up للمسار القائم `security.user.revoke`) | — | `SessionCenterTest`, `DeviceTrustTest`, `IpIntelTest` |
| 4.5 | `api_tokens.last_ip/revoked_at/revoked_by` · `vault_secrets.rotated_at` | — | `GET admin/security/tokens` · `/secrets` · `POST admin/security/tokens/{id}/revoke` — owner + `hub_require_credential_stepup` + `hub_audit` | — | `TokenCenterTest`, `SecretHealthTest`, `SecurityDashboardTest` |
| 4.6 | — | — | `GET admin/security/event/{source}/{id}` (`security.event`) — نفسُ حارس المركز | — | `SecurityEventDetailTest` |
| 5.1 | — | — | (تصحيحُ `audit.index` القائم) — `hub_flag('audit')` | — | `AuditScopeLeakTest` |
| 5.2 | أعمدةُ `audits`: category, severity, source, outcome, actor_type, session_id (**خارج SEALED**) | — | — | — | `AuditNormalizationTest` |
| 5.3 | — | — | (`audit.index` + مرشِّحات) · إعادةُ استعمال `views.store/default/destroy` — `hub_flag('audit')` | — | `AuditInvestigationTest` |
| 5.4 | — | — | `GET admin/audit/{id}` (`audit.show`) — `hub_flag('audit')` + نطاقٌ ⇒ ٤٠٤ | — | `AuditDetailTest` |
| 5.5 | **جدول** `audit_verifications` (+`(started_at)`) — `RAW_TABLES` | `audit.retention_days`, `audit.retention_policy` | `GET admin/audit/coverage` (`audit.coverage`) — owner | (كاتبٌ داخل `hub:audit-verify` القائم) | `AuditIntegrityHistoryTest`, `AuditCoverageTest` |
| 6.1 | `incidents.detected_at`, `resolution`, `kind`(مفهرس) · تسجيلُ `incidents` في `config/hub_acks.php` | — | (مسارُ الوحدة القائم `m.status`/`m.update` + قاعدةُ `requires` في السجلّ) — `hub_can('incidents','e')` | — | `IncidentHeaderTest`, `IncidentResolutionGateTest`, `IncidentAckTest` |
| 6.2 | **جدول** `incident_links` (+`(incident_id,created_at)`, `(module,record_id)`, `(ref)`) — `RAW_TABLES` | — | `POST admin/incidents/{id}/link` (`incidents.link`) — `hub_can('incidents','e')` + `hub_audit` | — | `IncidentEvidenceTest` |
| 6.3 | أعمدةُ `alert_rules`: severity, domain, source, window_min, cooldown_min, auto_incident · **جدول** `alert_instances` (فريد `dedup_key`) — `RAW_TABLES` | `security.alert_cooldown_min` | `GET admin/alerts` (`alerts.center`) — `owner‖monitor` · `POST admin/alerts/{id}/ack` · `POST admin/alerts/{id}/incident` — owner + `hub_audit` | **`hub:alerts-evaluate`** كل ٥ د + `Health::JOBS['alerts']` | `AlertEngineTest`, `AlertWindowTest`, `AlertLifecycleTest`, `OpsIncidentDetectionTest` |
| 7.1 | — | — | (`activity.show` القائم) — owner | — | `SecurityActivitySplitTest` |
| 7.2 | — | — | `GET workforce/overview` (`workforce.overview`) — `hub_monitor()` + `hub_org_analytics_guard()` | — | `WorkforceOverviewTest` |
| 7.3 | — | — | (`portal.employee` القائم) — `hr:v` + `hub_scope` + `hub_field_mode` | — | `EmployeeWorkProfileTest` |
| 7.4 | `tasks`/`tickets` `meta.reopened` (بلا مخطّط) | `retention.visits_days` | قسمٌ في `workforce.overview` | تقليمٌ في `hub:automation` | `BottleneckTest`, `VisitsRetentionTest` |
| 8.1 | — | — | `quality.index?tab=` — «data» owner · الباقي `hub_monitor()`+`hub_org_analytics_guard()` | — | `QualityCenterTabsTest` |
| 8.2 | — (نقاطٌ لكل وحدةٍ في `metric_points`) | — | — | (داخل `hub:quality-snapshot`) | `QualitySeverityTest`, `QualityModuleTrendTest` |
| 8.3 | — | — | `POST admin/quality/merge/preview` (`quality.merge.preview`) — owner · (`quality.merge` القائم + أثرٌ صريح) | — | `DuplicateMergeTest` |
| 8.4 | `tasks.completed_at` (+فهرس) · **فهرس `tasks(due)`** | — | قسمٌ في `quality.index?tab=execution` | لقطةٌ داخل `hub:quality-snapshot` | `ExecutionAnalyticsTest`, `ExecutionTrendTest` |
| 8.5 | `kpi_defs.owner_id`, `kpi_defs.period` | — | (`kpis.*`/`okrs.*` القائمة) · `POST .../remediate` — `tasks:a` + `hub_audit` | لقطةُ KPI يومية في `hub:quality-snapshot` | `KpiCenterTest`, `OkrCenterTest`, `RemediationTaskTest` |
| 9.1 | — | (إعادةُ تشكيل الكتالوج: `default/sensitive/validation/depends/env_key/owner_route/doc`) | (`settings.edit` القائم) — owner | — | `SettingsModelTest` |
| 9.2 | **جدول** `setting_changes` (+`(key,created_at)`, `(created_at)`) — `RAW_TABLES` | — | (كلُّ كتّاب الإعدادات عبر `Settings::put`) | — | `SettingsHistoryTest` |
| 9.3 | — | — | `POST admin/settings/preview` · `POST admin/settings/restore` — owner + تأكيد + step-up للخطر + أثر | — | `SettingsPreviewTest`, `SettingsRestoreTest`, `SettingsDependencyTest` |
| 9.4 | — | (وسمُ `exportable` في الكتالوج) | `GET admin/settings/export` · `POST admin/settings/import` · `.../apply` — owner + `security.freeze_exports` + أثر · فاحصاتُ الاتصال + `throttle:10,1` | — | `SettingsExportTest`, `SettingsImportTest`, `ConnectionTestsTest`, `RuntimeFlagsCardTest` |
| 10.1 | — | — | `GET admin/control` (`control.index`) — owner | — | `ControlHomeTest` |
| 10.2 | — | — | (يُعرض في `control.index` و`recs`؛ الإقرارُ عبر `recs.act` القائم) | — | `AttentionQueueTest` |
| 10.3 | — | — | (شريطُ الإدارة + `search`/`search.mini` القائمان) — حرّاسٌ من `hub_admin_links()` | — | `NavCatalogTest`, `OperationalSearchTest` |

### أدلّةُ نهاية الطور (تُرفَق في وصف الدفعة)
`phpunit` (SQLite) + `phpunit -c phpunit.mysql.xml` — العددان · `php artisan hub:schema-check` · `php artisan schedule:list` (الأوامرُ الجديدة ظاهرة) · `php artisan hub:openapi` بلا انحراف · ميزانيةُ استعلاماتٍ للشاشات المعدَّلة (قبل/بعد) · `VERSION`+README.

---

## CRITIC FINDINGS

> تفنيدٌ للخطّة أعلاه مقابل **المستودع الفعليّ** (فرع `claude/lynomia-hub-enterprise-upgrade-xn4p2t`، v2.400.3). كلُّ بندٍ بدليلٍ `ملف:سطر` ووسمِ تحقّق: `COMMAND_VERIFIED` (أمر/grep/قراءة مصدر) · `STATICALLY_REVIEWED` (قراءةُ شيفرةٍ كاملة). لم تُعدَّل شيفرةٌ متتبَّعة.
> **قراءةٌ عامّة:** الخطّةُ سليمةُ البنية ولا تُنشئ نظاماً ثانياً حيث يوجد نظام — لا تصادمَ في اسمِ صنفٍ أو مسارٍ أو جدولٍ واحد (`COMMAND_VERIFIED`: صفرُ تطابقٍ لـ`TimeRange/Severity/Redactor/Correlation/Series/SecurityFindings/IdentityRisk/AlertEngine/…` في `app/`، وصفرُ تطابقٍ لأسماء المسارات الـ٢٣ المقترحة في `routes/`، وصفرُ تطابقٍ للجداول السبعة الجديدة في `database/migrations/`). العيوبُ أدناه في **التفاصيل الحاسمة**: مفتاحٌ فريدٌ لا يعمل، وحارسٌ يسقط، وقاعدةُ ترتيبٍ تُخالف نفسَها، وثقبٌ أمنيٌّ لم تلتقطه الخطّة.

### أ) تكرارٌ تُنشئه الخطّةُ نفسُها

**١) `WorkforceStats` (WP-7.2) و`ExecutionStats` (WP-8.4) عدّادان لنفس الشيء.**
الأول: «أُنجز · متأخّر · نسبةُ الالتزام · تذاكرُ مفتوحة · خرقُ SLA». الثاني: «مخطّط · منجَز · مفتوح · متأخّر · الإنجاز٪ · الالتزام٪ · الإنتاجية» + «فُتحت/حُلّت/SLA٪/متوسّطُ الاستجابة». مصدرُهما واحد (`tasks`, `tickets`, `hub_sla`) ونافذتُهما واحدة (`TimeRange`). هذا بالضبط «عدّادُ KPI/جودةٍ سادس» في DUPLICATION RISKS #17 الذي تحظره الخطّة. **الإصلاح:** قارئٌ واحد (`ExecutionStats`) بإسقاطين — `::org()` تستهلكه شاشةُ القوى العاملة و`::person($id)` تستهلكه بطاقةُ الموظف — ويُحذف `WorkforceStats` من WP-7.2 إلى `ExecutionStats::org()`. `STATICALLY_REVIEWED`

**٢) `incidents.resolution` (WP-6.1) عمودٌ يكرّر `steps` القائم.**
`database/migrations/2026_01_20_000001_create_incidents_table.php:26` = `$t->text('steps')` بتعليق «خطوات المعالجة بالترتيب الزمني»، و`:27-29` = `root_cause`/`lessons`/`prevention`. فثلاثيّةُ §8.5 (root cause · resolution · prevention) مغطّاةٌ بـ`root_cause`+`steps`+`prevention`. إضافةُ `resolution` تُنشئ حقلَ «كيف أُصلح» ثانياً في نموذجٍ من ٢٢ حقلاً. **الإصلاح:** احذف `resolution` من الهجرة، واشترط `steps` في بوّابة الإغلاق. `COMMAND_VERIFIED`

**٣) `hub_screen_stamped` (WP-1.5) مدخلُ تخبئةٍ ثانٍ.**
`hub_screen` ثلاثةُ أسطر (`helpers.php:2883-2887`) تلفّ `hub_cached(hub_scope_key + hub_data_stamp)`. دالّةٌ توأمٌ بجانبها تعني قارئين يجب أن يبقيا متطابقَين. **الإصلاح:** مُعامل رابع `bool $stamped = false` على `hub_screen` نفسِها (أو إعادةُ `['at'=>…, 'data'=>…]` خلف رايةٍ) — لا دالّة ثانية. `COMMAND_VERIFIED`

**٤) `App\Support\Settings::put()` (WP-9.2) يُعيد كتابةَ `SettingController::put()`.**
`SettingController.php:189-201` يفعل اليومَ: قراءةَ القديم، الكتابة، رصدَ التغيّر، وبصمةَ `sha256:` للأسرار — أي جوهرَ الكاتب الموحّد. بناءُ `Settings::put` **بجانبه** يترك نسختين لمنطق الفرق. **الإصلاح:** انقُل جسمَ `SettingController::put` إلى `Settings::put` واجعل المتحكّمَ يفوّض إليه في الدفعة نفسِها؛ ولا تترك المسارَين قائمين بين حزمتين. `STATICALLY_REVIEWED`

**٥) أربعةُ أسطحِ إقرارٍ حيّة بعد الخطّة.**
`signal_states` (ق٣ للتنبيه المفتوح) · `security_findings.acknowledged_*` (ق٤) · `record_acks` (WP-6.1 للحادثة) · و«مقروء» في `notifications_hub`. الخطّةُ تحسم اثنين بقرارَين ثم تفتح ثالثاً. **الإصلاح:** اكتب في §٠.٥ قاعدةً واحدة صريحة: «الإشارةُ المحسوبة ⇐ `signal_states`؛ الحالةُ الثابتة (finding/alert) ⇐ عمودُها في جدولها؛ السجلُّ الحقيقيّ ⇐ `record_acks`» — وارجع إليها في 4.1 و6.1 و6.3 و10.2. `STATICALLY_REVIEWED`

### ب) مخالفاتٌ للمواصفة أو للسلوك القائم

**٦) [حرج] المفتاحُ الفريد لـ`security_findings` لا يعمل للنتائج على مستوى المنظّمة.**
`فريدٌ (code, entity_type, entity_id)` مع عمودَي كيانٍ `nullable`. و`NULL` **متمايزٌ** في فهرسٍ فريدٍ على MySQL وSQLite معاً — فكلُّ تشغيلٍ لـ`reconcile()` يُدرج صفّاً جديداً لـ`debug_mode`/`lockdown`/`owners`/`backup_fresh` (لا كيانَ لها) بدل تحديثه: تكرارٌ يوميّ صامت، و`first_seen_at` يتجدّد، والإقرارُ يضيع. **الإصلاح:** قيمتان حارستان غيرُ فارغتَين (`entity_type='org'`, `entity_id=''`) — واختبارٌ يفشل أولاً: تشغيلان ⇒ صفٌّ واحد لنتيجةٍ بلا كيان، على المحرّكين. `COMMAND_VERIFIED` (تعريفُ الجدول في WP-4.1؛ دلالةُ NULL معياريّة في الاثنين)

**٧) [حرج] «استعادةُ الافتراضي = حذفُ الصف» (WP-9.3) تُعيد إشعالَ حارسٍ أطفأه المالك.**
`SettingController.php:141-144` يخزّن `'0'` صراحةً للمفاتيح الثنائية **بتعليقٍ يشرح السبب**: «`setting()` تردّ الافتراضي عند الفراغ، فمفتاحٌ افتراضيه مفعَّل كان يستحيل إطفاؤه من الشاشة». فحذفُ صفِّ `sec.hours_on` أو `sec.strict_files` (افتراضُهما `'1'`) يُعيدهما مشتعلَين. والخطّةُ تُقرّ بذلك في اختبارها («مفتاحٌ افتراضيُّه مُشغَّل يعود مُشغَّلاً») بوصفه سلوكاً مطلوباً — وهو للمفاتيح الأمنية انقلابُ حالةٍ لا استعادة. ويسقط أيضاً `SettingsCenterTest::test_a_switch_that_defaults_on_can_actually_be_turned_off` إن مسّت الدفعةُ مسارَ الحفظ. **الإصلاح:** الاستعادةُ **تكتب** `default` المُعلَن في الكتالوج (WP-9.1) لا تحذف الصفَّ؛ والحذفُ يبقى لأنواع `img`/`pass` وحدها. `COMMAND_VERIFIED`

**٨) [حرج · §35] تصديرُ الوحدات الجَماعيّ يتخطّى تجميدَ التصدير — ولا حزمةَ عملٍ تُصلحه.**
`ModuleController.php:608` يفرض `abort_if(setting('security.freeze_exports')==='1', 423)` على `export()`، بينما `bulk()` بـ`do='export'` (`:676-687`) يبثّ CSV بلا هذا الحارس وبلا عتبة `security.export_stepup_rows` وبلا وسمِ «تصدير كبير». وهو **الاستعمالُ الوحيد** للمفتاح خارج شاشة الأمن (`COMMAND_VERIFIED`: `grep -rn freeze_exports app/` ⇒ `Health:332`, `SecurityController:125,147`, `ModuleController:608`). §35 يشترط «honor existing global export freeze». **الإصلاح:** حزمةٌ في الطور ١ أو ٩ (اختبارٌ يفشل أولاً): استخرج حزامَ `export()` إلى خدمةٍ واحدة ومرِّر `bulk()` بها. لا تُغلق الترقيةُ وثقبُ مفتاح طوارئ مفتوح.

**٩) [§17.1] توسيعُ `hub_monitor` إلى بياناتٍ شخصيةٍ وأمنية قرارُ صلاحيّاتٍ لا تفصيلُ تنفيذ.**
ق١ تمنح القراءةَ لـ`hub_monitor()` في `security.findings` و`security.identity` و`security.privileged` و`alerts.center` وتبويبات الجودة. واليومَ `monitor` يفتح `/performance /kpis /capacity` — تحليلاتِ أعمال (`RUNTIME_VERIFIED` في تقرير الاكتشاف §٤). فالعلَمُ نفسُه يصير مفتاحاً لدرجاتِ خطرِ الأشخاص وعناوينِهم وجلساتِهم. ليس «RBAC جديداً» (§17.1 يجيزه) لكنه **توسيعُ امتيازٍ صامت** لحاملي علَمٍ قائم. **الإصلاح:** إمّا إبقاءُ 4.3/4.4 للمالك وحدَه، أو `hub_field_mode` إلزاميّ على البريد/الـIP لحامل `monitor` **مع اختبارِ تسريبٍ صريح** لكل مسارٍ من الخمسة — ويُذكر التوسيعُ في وصف الدفعة لا في جدولٍ داخليّ.

**١٠) `Severity::normalize()` يقبل «نبرة» ثم تناقضها WP-4.1.**
WP-1.2 يُدرج `ok|wn|bad` (نبرةُ `SecurityPosture::row`) ضمن مدخلات `normalize`، ثم WP-4.1 يقول «bad ⇒ high/critical **حسب الرمز**». فالتعيينُ ليس دالّةً على النبرة. و`SecurityPosture::row` (`SecurityPosture.php:314-318`) لا يحمل شدّةً أصلاً — `compact('key','label','tone','why','n','fix','url')`. **الإصلاح:** أخرِج النبراتِ من `normalize`، وأضِف `SecurityFindings::SEVERITY_BY_CODE` جدولاً صريحاً لكل رمزٍ من الـ١٩ (`SecurityPosture::ORDER`, `:21`). `COMMAND_VERIFIED`

### ج) ما أغفلته الخطّة مقابل §١–§٣٥

**١١) §28 (نقاطُ امتدادٍ لمراقبةٍ خارجية) — صفرُ ذكر** في الخطّة كلِّها، ولا في DEFERRED. §28 لا يوجب التنفيذ لكنه يوجب **بنيةً تسمح** به لاحقاً. **الإصلاح:** سطرٌ في DEFERRED بسببه، أو نقطةُ امتدادٍ واحدة في `ErrorLog::capture`/`Health::check`. `COMMAND_VERIFIED` (`grep -c '§28' phase-plan.md` ⇒ 0)

**١٢) §38 (التوثيق) — صفرُ ذكر، ومطلوبٌ في §50.**
§38 يسمّي ثمانيةَ موضوعاتٍ بعينها (بنيةُ مستوى التحكّم · احتفاظُ التليمتري · الفرقُ بين حدثٍ ونتيجةٍ وحادثة · معاني الشدّة · مقاييسُ SLO · ارتباطُ الطلب · التنقية · أمانُ تصدير/استيراد الإعدادات). §٠.٤ يكتفي بـ«commit توثيقٍ واحد في نهاية الطور» بلا محتوى. **الإصلاح:** حزمةُ `WP-10.4 — DOCS` تُسلّم `docs/CONTROL_PLANE.md` بالموضوعات الثمانية، وتُحدَّث `ARCHITECTURE.md`/`RUNBOOKS.md`/`TECH_DEBT.md`. `COMMAND_VERIFIED` (`grep -c '§38'` ⇒ 0)

**١٣) §29 (الوصولية) بندٌ مذكورٌ بلا عمل — والخطّةُ تُنشئ ديناً جديداً.**
WP-2.4 وWP-5.3 يُدخلان جداولَ بفرزٍ (`?sort=`)، وتقريرُ الاكتشاف قاس **٥٠١ عنصر `<th>` بلا `scope` وصفرَ `aria-sort`**. رأسٌ قابلٌ للفرز بلا `aria-sort` عيبٌ **جديد** لا موروث. **الإصلاح:** في WP-1.5: مكوّنٌ `partials/cc/th.blade.php` (`scope="col"` + `aria-sort`) تستعمله كلُّ الجداول الجديدة، وحارسٌ في `DesignSystemGuardTest` على الملفات الجديدة وحدها.

**١٤) §30 (الجوّال): `ops/index.blade.php` بصفر `.tblwrap` والخطّةُ تضيف إليه ثلاثةَ جداول** (٢.٤ المسارات، ٢.٦ الطابور والاعتماديات، ٢.٧ النسخ والهجرات والإصدارات) بلا بندٍ يلفّها. **الإصلاح:** يُضاف اللفُّ إلى WP-2.7 صراحةً مع تقسيم القالب إلى `ops/parts/*`.

**١٥) §13: تقليمُ `error_events` يحذف أدلّةً غيرَ محلولةٍ صامتاً — ولا حزمةَ تُصلحه.**
`HubAutomation.php:698-700`: صفٌّ أول يحذف `status='محلول'` بعد `keep`، وصفٌّ ثانٍ **يحذف كلَّ شيء** بعد `keep*2` بصرف النظر عن الحالة — أي عطلٌ حرجٌ مفتوح عمرُه ٣٦٠ يوماً يختفي. ولا `hub_audit` على التقليم كلِّه (`:656-745`). §13: «Do not prune production evidence silently». **الإصلاح:** استثنِ `status != 'محلول'` من الصفّ الثاني (أو استثنِ `severity in (HIGH, CRITICAL)`)، واكتب سطرَ تدقيقٍ واحداً بعدد المحذوف لكل جدول. `COMMAND_VERIFIED`

**١٦) §13: مفاتيحُ الاحتفاظ ناقصةٌ حيث تُضاعف الخطّةُ الحجم.**
مثبَّتٌ في الشيفرة بلا مفتاح: `notifications_hub` 90/365 (`HubAutomation:661-662`)، `inbound_hook_events` 90 (`:667`)، `metric_points` 365 (`:676-679`)، `page_visits` 90 (`:702-707`). الخطّةُ تضيف `retention.visits_days` وحدَه من هذه — بينما هي نفسُها تُضاعف حجمَ `metric_points` (لقطاتُ ops كلّ ٥ دقائق + تاريخُ نبضات + أمن/جودة/تنفيذ/KPI يوميّاً). **الإصلاح:** أضِف `retention.metric_points_days` و`retention.notifications_days` و`retention.inbound_hooks_days` في WP-2.3/2.2 (المكانُ الذي يزيد الحجم).

**١٧) §1.8: `audit.retention_days`/`audit.retention_policy` مفتاحان «للوصف فقط» — يُسقطان الحزمة.**
`SettingsCenterTest::test_no_exposed_key_is_dead` (`tests/Feature/SettingsCenterTest.php:31-39`) يُسقط أيَّ مفتاحٍ **معروضٍ** لا يقرؤه نداءُ `setting('<حرفيّ>')` في `app/`+`resources/`+`routes/` (`liveKeys()`, `:124-141`). وق٦ تنصّ «لا كودَ تقليمٍ في هذه الدفعة». **الإصلاح:** إمّا إعلانُهما في `SettingController::internal()` (لا يمسّهما هذا الاختبار)، أو قراءتُهما فعلاً في قالب/متحكّم `audit.index` بنداءٍ حرفيّ. `COMMAND_VERIFIED`

**١٨) §4.11/§25: البطاقةُ الميتة في `/admin/ops` تبقى بعد WP-3.5.**
`OpsController.php:107-118` يقرأ `storage_path('logs/laravel.log')` بينما `config/logging.php:9-10` سائقُه `daily` بمسارٍ يولّد `laravel-YYYY-MM-DD.log` ⇒ `is_file()` كاذبٌ دائماً والبطاقةُ فارغة. WP-3.5 يبني صفحةَ بحثٍ جديدة ولا يقول ماذا يحلّ بالبطاقة. **الإصلاح:** في WP-3.5 نفسِها: احذف الكتلةَ من `OpsController` واستبدلها برابطٍ إلى `errors.logs` — وإلا شُحن سطحان للسجلّ أحدُهما ميّت. `COMMAND_VERIFIED`

**١٩) §12.2: جداولُ الأمن والتشغيل تبقى بلا ترقيمٍ ولا فرز.**
WP-4.4 يُصلح ترتيبَ الجلسات، لكنّ `SecurityController.php:57,69,108` (جداولُ `.mini`) وجداولَ `ops/index` تبقى «أولَ ٢٥» بلا صفحاتٍ ولا فرز. §12.2 يشترط للجداول الكبيرة: ترقيم + فرز + فلاتر + ترتيبٌ حتميّ. **الإصلاح:** بندٌ صريح في WP-4.5 (لوحةُ القيادة) و WP-2.7.

### د) ترتيبٌ واعتماديّات

**٢٠) [مؤثّر] `tasks.completed_at` في الطور ٨ بينما الطور ٧ يحسب «نسبة الالتزام».**
WP-8.4 يضيف `tasks.completed_at` + فهرس `tasks(due)` ويصرّح بالسبب: «نسبةُ الالتزام تُحسب اليومَ من `updated_at` فأيُّ تعديلٍ لاحق يُفسدها». لكنّ WP-7.2 («نسبةُ الالتزام» في نظرة القوى العاملة) وWP-7.3 («الالتزام» في ملفّ الموظف) في الطور ٧ — **قبله** في مخطّط التسلسل. فالطور ٧ سيشحن ويختبر التعريفَ الخاطئ ثم يُبطله الطور ٨. **الإصلاح:** انقل هجرةَ `tasks.completed_at` + `tasks(due)` + ختمَها في `ModuleController` إلى **WP-1.4** (الحزمةُ التي تحمل أصلاً فهارسَ ما بعد الطور الأول)، ويبقى في 8.4 القارئُ والاتّجاه.

**٢١) قاعدةُ «`helpers.php` للطور ١ فقط» تنقضها الخطّةُ في ثلاثة مواضع.**
§٠.٤ تحصر `app/Support/helpers.php` في الطور ١، وWP-5.2 يُسمّى «الاستثناءُ الوحيد» — ثم: WP-6.2 يعدّل `hub_timeline`، وWP-6.3 يعمّم `hub_security_incident` إلى `hub_open_incident`، وWP-1.4 يُدرج `hub_security_incident` في كتّابه، وWP-8.5 يلمس دوالَّ OKR/KPI «إن مُسّت». **الإصلاح:** بدّل الصفَّ في جدول §٠.٤ إلى «الأطوار ١ ثم ٥ ثم ٦ — بهذا الترتيب، دفعةٌ واحدة لكل طور»، واحذف عبارة «الاستثناء الوحيد».

**٢٢) `routes/console.php` غائبٌ عن بروتوكول الملفّات المشتركة** رغم أن WP-2.3 و4.2 و6.3 يُلحق كلٌّ منها مجدولاً، و`Health::JOBS` يكسب ثلاثةَ مفاتيحَ عبر ثلاثة أطوار. **الإصلاح:** صفٌّ في جدول §٠.٤: «`routes/console.php` · ٢،٤،٦ · كتلةٌ معلَّمة في نهاية الملف».

**٢٣) تبريرُ `audit_verifications` يناقض قرارَ تاريخ المجدولات.**
WP-5.5: جدولٌ مستقلٌّ «ولهذا لا يُدفَع إلى `metric_points` الذي يُقلَّم بعد ٣٦٥ يوماً». وWP-2.3 يدفع **تاريخَ تشغيل كلّ المجدولات** إلى `metric_points` — و§3.12 يقول «Persist execution history safely». إمّا أن ٣٦٥ يوماً تكفي للاثنين، أو لا تكفي لأيٍّ منهما. **الإصلاح:** صرِّح في WP-2.3 أنّ تاريخَ التشغيل تليمترٌ عمرُه سنةٌ عمداً (وأضِف `retention.metric_points_days` من البند ١٦)، أو انقل تاريخَ التشغيل إلى `audit_verifications`-نمطٍ ثانٍ — والأولُ أرخص.

**٢٤) WP-4.1 يسبق ما يعتمد عليه.**
`reconcile()` يحتاج تعدادَ الكيانات، وتعدادُ الرموز يحتاج `ApiTokens::classify()` (WP-4.5) وتعدادُ الأسرار يحتاج `vault_secrets.rotated_at` (WP-4.5) وعتبةَ `security.secret_stale_days` (WP-4.3). فبناءُ 4.1 أولاً يعني كتابةَ تصنيفٍ مؤقّتٍ ثم استبداله. **الإصلاح:** أنجِز في 4.1 نتائجَ **المنظّمة** فقط (١٩ فحصاً بكيانٍ `org`)، وأجِّل نتائجَ الكيانات (رمز/سرّ/مستخدم) إلى ذيل 4.3 و4.5 حيث تُولد مُعدّاتُها.

### هـ) جدوى: SQLite + MySQL، وكلفةُ التشغيل

**٢٥) [حرج] `SecurityPosture` لا يعطي نتائجَ لكل كيان — والخطّةُ تفترض أنه يعطيها.**
WP-4.1: «يمشي على `SecurityPosture::apiStale` لكل رمز، `vaultRotation` لكل سرّ، `twofaPrivileged` للمميّزين». الواقع: الثلاثةُ `protected` (`SecurityPosture.php:122,197,208`) وكلٌّ منها يُعيد **صفّاً مجمَّعاً واحداً** عبر `row()` (`:314-318`) لا يحمل إلا `n`؛ واستعلاماتُها `->count()` فقط (`:199-201`, `:210-215`). فلا `entity_id` يُشتقّ منها. **الإصلاح:** حزمةٌ فرعية صريحة: `SecurityPosture::apiStaleIds()/vaultStaleIds()/privilegedNoMfaIds()` (تُعيد معرّفات، وتستهلكها الفحوصُ القائمة بـ`count()` عليها فلا يتفرّع المنطق)، وقدِّرها في ميزانية WP-4.1. `COMMAND_VERIFIED`

**٢٦) [حرج] كتابةُ دلاء HTTP داخل دورة الطلب.**
`Observability` مُلحقٌ بمجموعتَي `web` و`api` (`bootstrap/app.php:28,36`) و`handle()` يعمل **قبل إرسال الردّ**. WP-2.2 يضع UPDATE + `insertOrIgnore` بعد `$next` مباشرةً ⇒ كلُّ طلبٍ في النظام يدفع ثمنَ استعلامَين قبل أن يرى المستخدمُ البايتَ الأول، بما فيها طلباتُ HTMX الصغيرة. **الإصلاح:** انقلها إلى `public function terminate(Request $r, $response)` (تعمل بعد إرسال الردّ على FPM)؛ وانتبه لتنافسِ الصفّ الساخن: كلُّ الطلبات المتزامنة على أكثرِ المسارات ازدحاماً تتنافس على صفٍّ واحد في الحاوية الواحدة — أبقِ `try/catch` بلا معاملة. `COMMAND_VERIFIED`

**٢٧) [حرج · §14/§37] حسابُ p95 بدمج `hist` في PHP ينقل الجدولَ كلَّه إلى الذاكرة.**
لا دالّةَ نسبٍ مئويةٍ محمولة بين SQLite وMySQL 8، فـ`Series::mergeHist` تعني قراءةَ كلِّ دلاء النافذة: ٧ أيام × ٢٨٨ حاوية × عددِ المسارات (٣٦١ مساراً مسجّلاً) ⇒ عشراتُ الآلاف من الصفوف بحقل JSON لكلّ فتحةِ صفحة. §14: «Do not move giant datasets into PHP collections». **الإصلاح:** خطوتان — (١) `GROUP BY route, method` في القاعدة لـ`SUM(count)/SUM(sum_ms)/MAX(max_ms)/SUM(err4)/SUM(err5)` مع `ORDER BY SUM(count) DESC` و`LIMIT` (صفحةُ الجدول)، (٢) ثم قراءةُ `hist` لصفوف الصفحة المعروضة وحدها. اذكرها في WP-2.4 نصّاً.

**٢٨) `metric_points(at)` غيرُ مطلوبٍ في الخطّة — والتقليمُ يمسح الجدولَ كلَّه.**
`HubAutomation.php:676-679`: `DB::table('metric_points')->where('at','<',…)->delete()` بلا دفعات. والفهارسُ القائمة `(module,record_id,metric,at)` و`(metric,at)` تبدأ بعمودٍ آخر فلا تقود شرطَ `at` وحدَه (`COMMAND_VERIFIED` من الهجرة `2026_07_31_000016:30-32`). وتقريرُ الاكتشاف طلب الفهرسَ صراحةً — والخطّةُ **لم تُدرجه** في WP-1.4 ولا في غيرها. **الإصلاح:** أضِف `metric_points(at)` إلى هجرة WP-1.4، وحوّل التقليمَ إلى حلقةِ دفعاتٍ ٥٠٠٠ (نمطُ `page_visits` في `:702-707`).

**٢٩) `Health::beat()` ينسف كاشَ الإعدادات — والخطّةُ تُضاعف وتيرتَه.**
`Health.php:370` = `Cache::forget('settings:all')` في كلّ نبضة، و`setting()` يخبّئ المفتاحَ ٦٠٠ ثانية (`helpers.php:13`). اليومَ مجدولان كلَّ ٥ دقائق (`outbox`, `uptime`) ⇒ إبطالٌ كلَّ ~٢٫٥ دقيقة. تضيف الخطّةُ `hub:ops-snapshot` و`hub:alerts-evaluate` كلَّ ٥ دقائق ⇒ ~١٫٢٥ دقيقة، فيقرأ كلُّ طلبِ ويبٍ تقريباً جدولَ `settings` كاملاً. **الإصلاح:** أزِل `Cache::forget` من `beat` (النبضةُ لا يقرؤها `setting()`؛ يقرؤها `Health::scheduler` مباشرةً) أو انقل `heartbeat.*` خارجَ جدول `settings`. أضِف اختبارَ ميزانيةٍ يُثبت أن نبضةً لا تُبطل الكاش. `COMMAND_VERIFIED`

**٣٠) `hub_metric_put` يرفض `null` — وستُّ نبضاتٍ تُرسل `ms=null`.**
التوقيع `hub_metric_put(..., float $value, ...)` (`helpers.php:4209`). وWP-2.3 يضيف داخل `Health::beat($job, ?int $ms = null, …)` سطرَ `hub_metric_put('ops', $job, 'run', $ms, …)`. ستّةٌ من الثمانية تنبض بلا مدّة اليوم ⇒ `TypeError` داخل مهمّةٍ مجدولة. **الإصلاح:** `$ms === null ? -1 : (float) $ms` (أو تخطّي الكتابة وتسجيلُ النتيجة في `meta` فقط)، واختبارٌ لنبضةٍ بلا مدّة. `COMMAND_VERIFIED`

**٣١) `metric_points.record_id` هو `uuid` ⇒ `char(36)` على MySQL.**
`2026_07_31_000016:21`. السابقةُ `record_id='org'` تعمل، لكنّ كلَّ معرّفٍ جديدٍ تكتبه الخطّة (مفاتيحُ `Health::JOBS`، مفاتيحُ الوحدات في 8.2، `'kpis'/<uuid>`) يجب أن يبقى ≤٣٦ محرفاً، و`metric` ≤٤٠ (`'rows:<table>'` في 2.3). MySQL الصارمة ترمي، وSQLite تقبل صامتةً ⇒ عطلٌ يظهر على ساقِ CI الواحدة فقط. **الإصلاح:** اختبارٌ في `MetricPrimitivesTest` يمرّ على كلّ مفاتيح `Health::JOBS` وكلّ مفاتيح `hub_modules()` ويؤكّد الطولين.

**٣٢) مصفوفةُ CI تشمل PHP 8.2 — والخطّةُ لا تذكره.**
`.github/workflows/ci.yml:27` = `{ php: '8.2', engine: sqlite }`. الأصنافُ الستّة الجديدة (`TimeRange`, `Severity`, `OpStatus`, `IssueState`, `Redactor`, `Series`) ستُكتب على صندوقِ 8.4: ثوابتُ صنفٍ مُنمَّطة و`json_validate()` و`#[\Override]` (٨٫٣) و`array_find`/`array_any` وخطّافاتُ الخصائص (٨٫٤) تمرّ محلياً وتُسقط ساقاً واحدة. **الإصلاح:** سطرٌ في §٠.٣: «أرضيةُ اللغة ٨٫٢ — لا صياغةَ ٨٫٣/٨٫٤».

**٣٣) ثلاثُ شاشاتٍ جديدة تسقط من شبكة `AllScreensSmokeTest` صامتةً.**
`AllScreensSmokeTest::urls()` يستبدل `{id} {projectId} {user} {userId} {role} {key} {token} {version} {module}` **ثم يتخطّى أيَّ مسارٍ ما زال فيه `{`** (`tests/Feature/AllScreensSmokeTest.php:143-152`). فـ`system/trace/{rid}` و`admin/security/event/{source}/{id}` و`admin/security/ips/{ip}` تخرج من مسح الـ٥٠٠ بلا إعلان. **الإصلاح:** وسّع خريطة الاستبدال في الحزمة نفسِها التي تضيف المسار (WP-1.4، WP-4.4، WP-4.6)، وإلا فالوعدُ «لا شاشةَ تجيب بخطأ خادم» يصير كاذباً على أحدثِ الشاشات. `COMMAND_VERIFIED`

**٣٤) حارسُ صلاحيّاتِ الكتابة له سقفٌ عدديّ ستبلغه الخطّة.**
`WritingRoutesAuthzRound7Test` يمسح كلَّ POST/PUT/PATCH/DELETE داخل `auth` بحساب «عرضٌ فقط»، ثم `assertLessThan(60, count($unproven))` لما ردَّ ٤٠٤ على uuid عشوائيّ (`:104,123-127`). الخطّةُ تضيف ~١٢ مساراً كاتباً أكثرُها بشكل `{id}` ⇒ نموٌّ مباشرٌ في `unproven`. **الإصلاح:** قِس العددَ الحاليَّ قبل الطور ١، وإن قارب ٦٠ فارفع السقفَ في دفعةٍ مستقلّةٍ **مع تبريرٍ مكتوب** لا ضمن حزمةِ ميزة.

**٣٥) أعمدةُ الهجرة داخل حلقةٍ بمتغيّرٍ لا يراها حارسُ العرض.**
`hub_col_widths()` يقرأ **مصدرَ الهجرات** ويطابق كتلَ `Schema::create/table` (`helpers.php:2702-2712`)، و`2026_09_02_000004_platform_correlation_ids.php:22-27` يكتب `Schema::table($table, …)` داخل `foreach` ⇒ عرضُ `audits.request_id` غيرُ مرئيّ للحرّاس. WP-1.4 يتبنّى النمطَ نفسَه لثلاثة جداول. **الإصلاح:** كتلٌ حرفيّة (`Schema::table('access_denials', …)`) لا حلقة — فيرى `ColumnWidthGuardTest`/`ColumnFitsItsWriterTest` الأعمدةَ الجديدة. `COMMAND_VERIFIED`

**٣٦) عمودان بعرض ٣٠٠ يستقبلان نصّاً أطولَ منهما.**
`incident_links.summary` (٣٠٠) و`alert_instances.title` (٣٠٠) يُغذَّيان من `audits.name` (٣٠٠ أصلاً) ورسائلِ الأخطاء و`Health::c()['why']`. MySQL الصارمة ترمي حيث تبتر SQLite — وهو صنفُ العطل الذي أنشئ له `ColumnFitsItsWriterTest`. **الإصلاح:** `mb_substr` عند الكاتب لا عند القارئ، وأضِف الزوجَ إلى `ColumnFitsItsWriterTest` في الحزمة نفسِها.

**٣٧) إقرارُ الحادثة عبر `record_acks` لا يعمل للحوادث التي تهمّ.**
`Acks::targets()` يُعيد `[]` حين يكون عمودُ `who` فارغاً (`app/Support/Acks.php:38-44`)، و`incidents.lead_id` **nullable** (`2026_01_20_000001:24`) — والحوادثُ التي يفتحها `hub_security_incident` آلياً بلا قائد. فـ«أُقرّت» في ترويسة §8.1 يستحيل بلوغُها في الحالة الوحيدة التي تحتاجها. وزيادةً: `Acks::record` يختم `ver` (`:126`) و`reack` تُبطل الإقرارَ عند تغيّر النسخة ⇒ كلُّ تحريرٍ أثناء الاستجابة يُعيد طلبَ الإقرار. **الإصلاح:** إمّا فرضُ `lead_id` قبل الإقرار (وتعبئتُه آلياً بمالكٍ افتراضيّ عند الفتح الآليّ)، أو عمودا `acknowledged_at/by` كما فعلت ق٨ حين رفضت حشرَ تاريخ الإعدادات في `audits`. `COMMAND_VERIFIED`

**٣٨) `hub:ops-snapshot` كلَّ ٥ دقائق فوق `Health::check()` مخالفٌ لـ§16.**
`Health::check()` قيس بـ**٥٥ استعلاماً بلا كاش** (RUNTIME_VERIFIED في تقرير الاكتشاف)، و`SysMonitor::diskConsumers/tableConsumers` مسحُ ملفاتٍ وجداول. ٢٨٨ تشغيلاً/يوم ⇒ ~١٧ ألف استعلامٍ يومياً + مسوحُ قرصٍ على استضافةٍ مشتركة. §16: «Do not perform expensive full scans every minute». **الإصلاح:** لقطةُ الـ٥ دقائق تقتصر على `cpu_pct/mem_pct/disk_pct/db_ms` + `rank` من `Health::ready()` (لا `check()` الكامل)؛ ويُنقل `tableConsumers`/`diskConsumers` إلى لقطةٍ يومية — وهو ما تلمّح إليه الخطّة («ويومياً») دون أن تُخرج `Health::check` من المسار كلَّ ٥ دقائق.

**٣٩) فهارسُ الخطّة الجديدة خارج حارسِ الفهارس القائم.**
`MysqlPortabilityTest::test_hot_query_indexes_exist` (`:85-94`) يثبّت ثلاثةَ فهارسَ بالاسم. الفهارسُ الحاسمة الجديدة (فريدُ `http_metric_buckets`، فريدُ `security_findings`، `alert_instances.dedup_key`، `metric_points(at)`) لن يحرسها شيء ⇒ `dropIndex` لاحقٌ يمرّ صامتاً. **الإصلاح:** أضِف الأربعةَ إلى القائمة في حزمِها.

**٤٠) كاتبُ النشر الآليّ (WP-2.3) يُلوّث الاختبارات.**
الشرطُ `config('hub.version') !== setting('ops.last_version')` صحيحٌ دائماً في بيئةِ اختبارٍ نظيفة، فأولُ تشغيلٍ لـ`hub:ops-snapshot` في أيّ اختبارٍ يُنشئ صفَّ `deployments` بـ`Auditable` (قيدُ تدقيقٍ أيضاً). وذلك يمسّ أيَّ تأكيدٍ يعدّ سجلاتِ الوحدات أو قيودَ التدقيق. **الإصلاح:** اشترط `app()->runningInConsole() && ! app()->runningUnitTests()` أو `setting('ops.last_version')` غيرَ فارغٍ قبل أول كتابة (بذرةُ `ops.last_version` عند الترحيل)، وصرّح بذلك في `OpsSnapshotTest`.

### و) ما وُجد سليماً (لا تُعاد مراجعتُه)

- **لا تصادمَ أسماء:** صفرُ تطابقٍ لأصناف `App\Support` الستّة عشرَ المقترحة في `app/`؛ صفرٌ للمسارات الـ٢٣ في `routes/`؛ صفرٌ للجداول السبعة في `database/migrations/`. `COMMAND_VERIFIED`
- **الأعمدةُ الجديدة على `audits` آمنة:** `AuditEntry::SEALED` (`app/Models/AuditEntry.php:92-93`) ثابتٌ صريح، و`liveColumns()` (`:27-38`) تُجرّد المجهولَ قبل الإدراج، و`forgetColumnCache()` موجودةٌ للاختبارات ⇒ ق٢ صحيحة.
- **`SecurityEvents::CODES` يحوي `API_CREDENTIAL_REVOKED`** بالفعل (`SecurityEvents.php:57` ⇒ «إبطال مفتاح API») فلا يلزم رمزٌ جديد لـWP-4.5.
- **`deployments` جاهزٌ لـ§3.15:** `ver(80)`, `env(40)`, `deployed_at`, `migrations(40)`, `incident_id` (`2026_01_20_…create_deployments`) — لا هجرةَ لازمة.
- **`Sessions::revokeAll(User, ?string $exceptSessionId)`** بالتوقيع الذي تفترضه WP-4.4 (`app/Support/Sessions.php:22`).
- **`incidents.lead_id` قائم** فبنيةُ `config/hub_acks.php` صالحةٌ تقنياً (لكن انظر البند ٣٧).
- **لا بنيةَ خارجية ولا هجرةَ مدمّرة ولا كسرَ مسارٍ/عقدِ API في أيّ حزمة** (§22/§24/§27) — القاعدةُ محفوظةٌ في §٠.٣ ومطبَّقةٌ في كل مصفوفة الحزم.

### ز) ترتيبُ الإصلاح المقترح قبل بدء التنفيذ

1. **حاجزٌ أمنيّ قبل الطور ١:** البند ٨ (تجميدُ التصدير في `bulk`) والبند ١٥ (تقليمُ الأدلّة) — اختبارٌ يفشل أولاً في كلٍّ.
2. **تعديلاتٌ على الطور ١:** ٢٠ (`tasks.completed_at`+`tasks(due)`) · ٢٨ (`metric_points(at)`) · ٣٥ (كتلٌ حرفيّة في الهجرة) · ٣٢ (أرضيةُ PHP 8.2) · ٣٣ (خريطةُ استبدال المسح) · ٣ (لا `hub_screen_stamped`).
3. **تصحيحُ قرارات:** ٦ (فريدٌ بلا NULL) · ٧ (الاستعادة تكتب الافتراضي) · ١٠ (شدّةٌ لكل رمز) · ٩ (قرارُ `hub_monitor` مكتوب) · ٥ (قاعدةُ الإقرار الواحدة).
4. **إعادةُ تحجيمِ حزم:** ٢٥ (مُعدّاتُ الكيانات في 4.1) · ٢٤ (تسلسل 4.1 بعد 4.3/4.5 جزئياً) · ١ (قارئُ تنفيذٍ واحد) · ٢٧ (تجميعُ الدلاء في القاعدة) · ٣٨ (لقطةٌ رخيصة).
5. **بنودٌ تُضاف للخطّة:** ١١ (§28 في DEFERRED) · ١٢ (`WP-10.4 — DOCS`) · ١٣ (§29) · ١٤ (§30) · ١٦ (مفاتيحُ احتفاظ) · ١٨ (البطاقةُ الميتة) · ١٩ (§12.2) · ٢٢ (`routes/console.php`) · ٣٩ (حارسُ الفهارس).
