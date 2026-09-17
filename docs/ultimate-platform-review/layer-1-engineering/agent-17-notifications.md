# الوكيل ١٧ — التنبيهاتُ والجدولةُ والأتمتة

> المراجعةُ الشاملة · طبقة ١ (الهندسة) · الجولة أ
> المستودع `/home/user/lynomia-hub` · الرأس المُجمَّد `1d90626` (النسخةُ العاملة `c06a945` · `VERSION=2.540.1`)
> **مراجعةٌ للقراءة فقط** — لم يُعدَّل شيءٌ خارج هذا الملف، ولم يُشغَّل أمرُ جدولةٍ ولا كتابةٍ على أيّ قاعدة.

---

## ٠) الخلاصةُ في ثلاثة أسطر

1. **لم يوجد نظيرٌ حيٌّ لعيب `notifications_hub.kind`**: كلُّ قيمةِ `kind` تُكتب اليومَ تسعُ عرضَ عمودِها (١٢٠ بعد `2026_08_02_000002`). وكذلك `text`/`target`/`error`/`dedup_key`/`title` — كلُّها مقصوصةٌ عند الكاتب أو عند النموذج.
2. **لكنّ صنفَ العيب لم يُغلَق**: الحارسُ (`ColumnFitsItsWriterTest`) **سجلٌّ يدويٌّ** يحرس ثلاثَ بادئاتٍ من أربع، و`OutboxMessage` **بلا قصٍّ مركزيّ** بخلاف `HubNotification` — فثلاثةُ كُتّابٍ يكتبون فيه بلا حدّ (A17-01، A17-02).
3. **أخطرُ ما وُجد ليس في العرض بل في النبضة**: زرُّ «إعادة الفاشل» في مركز المراسلة يُشغّل عاملَ التسليم كاملاً داخلَ طلبِ ويب فيكتب `heartbeat.outbox` — فكرونٌ ميتٌ يبقى «سليماً» ما دام أحدٌ ينقر الزرّ (A17-03). ويقاربه أنّ `hub:automation` **لا يفشل أبداً** فـ`onFailure` عليه حبرٌ على ورق (A17-05).

---

## ١) `HubNotification` — من يُنشئها، ولمن، وأتُقرأ وتُمسَح؟

### مواضعُ الإنشاء

| المُنشئ | الملف | النوع (`kind`) | المستلم |
|---|---|---|---|
| قواعدُ التنبيه اليوميّة | `app/Support/AlertEngine.php:245-254` | `rule:<uuid>` | المحدَّدُ في القاعدة، وإلّا المالكون + حاملو `monitor` |
| تصعيدُ SLA للتذاكر | `app/Support/AlertEngine.php` (`escalateTicketSla`) | `ticket` | المسنَدُ إليه + مديرُه، أو مديرُ المشروع، أو حَمَلةُ رايةِ الاعتماد |
| التنبيهاتُ النافذيّة | `AlertEngine::notifyRule` | `sec` | كمستلمي القاعدة |
| الميزانيّات | `app/Console/Commands/HubAutomation.php:310-316` | `budget:<uuid>` | المالكون/المراقبون **المنطَّقون** |
| مراقبو الأتمتة | `app/Console/Commands/HubAutomation.php:456-465` | حسب النداء | المالكون/المراقبون بعد `hub_can` + `hub_scope` |
| الموجزُ الأسبوعيّ | `app/Console/Commands/HubDigest.php:94-95` | `digest` | **المالكون النشطون حصراً** (`is_owner`) |
| مساراتُ العمل | `app/Support/FlowRunner.php:256-264` | `flow` | `hub_approvers_for` أو مستلمٌ مسمّى بعد `eligibleExplicitRecipient` |
| فوزُ العرض (مدمَج) | `app/Support/FlowRunner.php:107-124` | `quote.won` | مديرُ التنفيذ + صاحبُ العرض |
| حارسُ الدخول | `app/Support/LoginSentry.php:56` | `sec` | — |
| التوقيعُ الإلكترونيّ | `app/Http/Controllers/Web/EsignController.php:1062, 1405` | `sign` | — |
| العامُّ | `hub_notify()` — `app/Support/helpers.php:4001-4015` | `ack · approval · assign · custody · daily_report_missing · dm · error · leave · policy · react · sec · security · ticket` | حسب النداء |

### هل تحترم النطاقَ والصلاحيّة؟ — **نعم، في أربع طبقات**

1. **قبل الكتابة**: `AlertEngine::daily` يفرض `hub_can(…, 'v')` (سطر 155-157) **ثمّ** `hub_scope` لكلِّ مستلمٍ على حدة (197-208) — والاثنان **قبل** حدِّ الخمسين، بعد أن كانا بعدَه (v2.316/v2.337). `HubAutomation::notifyMonitors:450-457` يفرض الاثنين. `budgetsAuto:308` يفرض `hub_scope`. `FlowRunner::eligibleExplicitRecipient:223-237` يفرض الاثنين.
2. **عند العرض**: `hub_notification_text` (`app/Support/helpers.php:4026-4038`) يقنّع نصَّ إشعارٍ لوحدةٍ **فقد المستلمُ رؤيتَها بعد الإنشاء** — فلا يتسرّب اسمٌ مخزونٌ من الماضي.
3. **عند التسليم**: `HubOutbox::telegram:164-171` يحجب متنَ رسالةٍ شخصيّةِ الوجهة إن هبطت إلى القناة المشتركة.
4. **عند الدفع**: `PushService::payloadFor` لا يمرّر نصَّ الإشعار أصلاً (§A17-17).

**ثغرةٌ واحدة** في هذه السلسلة: رسائلُ المسارات في الصادر بلا `user_id` فتتجاوز حارسَ التسليم — A17-09.

### القراءةُ والمسح

- القراءة: `app/Http/Controllers/Web/NotificationController.php` — `go()` (52-60) يُقرّئ الإشعارَ المفرد ثم ينقل لوجهته عبر `NotificationLink::webUrl`؛ `readAll()` (62-68) يُقرّئ الكلَّ. كلاهما مقيّدٌ بـ`where('user_id', auth()->id())` ✓ والترقيمُ بفاصلِ `id` لا بـ`created_at` وحدَه (15-18) — احتياطاً من الكُتّابِ الدفعيّين.
- المسح: **لا مسحَ يدويّاً إطلاقاً**. التقليمُ آليٌّ في `HubAutomation::pruneNotifications:511-514`: المقروءُ بعد `retention.notifications_days` (٩٠ افتراضاً، بأرضيّةِ ٧)، وكلُّ شيءٍ بعد `max(365, …)`.
- **أثرٌ جانبيٌّ موثَّق**: التقليمُ يمحو ذاكرةَ التكرارِ للسكّة اليوميّة (`kind='rule:<id>'`، `AlertEngine.php:143-146, 214-217, 226-228`). عولج للسكّة النافذيّة بجدول `alert_instances` الدائم، ولم يُعالَج لليوميّة: قاعدةٌ بـ`every > 365` تفقد ذاكرتَها. نادرٌ، ومذكورٌ صراحةً في رأس `AlertEngine` — فليس عيباً مخفيّاً.

---

## ٢) قواعدُ التنبيه — هل تُطلق؟ وهل يوجد `kind` لا يتّسع له عمودُه؟

### سكّتان تعملان فعلاً

- **اليوميّة** `AlertEngine::daily()` — تُنادى من `HubAutomation::alertRules()` يوميّاً 06:00. تُقيّم القواعدَ **بلا `source`**: تسعةُ معاملاتٍ (`أكبر من`، `أصغر من`، `أكبر من عمود`، `فارغ`، `أيام متبقية أقل من` …). منعُ التكرار: `kind='rule:<id>' + record_id` خلال `every` يوماً، مدفوعاً إلى SQL **قبل** الحدّ. التصعيد: سلسلةٌ متّصلةٌ من الإشعارات تتجاوز `max(3, every*3)` ⇒ `🔺 مُتصاعد` + دفعٌ عبر كلِّ القنوات.
- **النافذيّة** `AlertEngine::evaluate()` — كلَّ ٥ دقائق من `hub:alerts-evaluate`. ستّةُ مصادرَ مسمّاة (`AlertEngine.php:43-50`): `security.failed_logins` (وإطلاقٌ لكلِّ IP عند رشِّ كلماتِ المرور)، `security.denials`، `security.role_change`، `security.lockdown`، `errors.critical_count`، `health.scheduler`. الذاكرةُ في `alert_instances.dedup_key` الفريد — **تنجو من التقليم**. الحالةُ `triggered → acknowledged → resolved`، والحلُّ آليٌّ بقيدٍ لا بحذف، والتبريدُ لكلِّ قاعدةٍ (`cooldown_min`، وإلّا `security.alert_cooldown_min`، افتراضاً ٦٠ د).

### فحصُ **كلِّ** قيمةِ `kind` مقابل عرضِ العمود

`notifications_hub.kind` و`outbox.kind`: كلاهما **١٢٠** محرفاً (`database/migrations/2026_01_01_000000_create_core_tables.php:134,146` = ٤٠، ثمّ `2026_08_02_000002_widen_notification_kind.php:29-33` ⇒ ١٢٠). والقياسُ بالمحارف لا بالبايتات في MySQL — فالعربيّةُ لا تضيّق الحدّ.

| القيمة | الطولُ الأقصى | العمود | يتّسع؟ |
|---|---|---|---|
| `rule:<uuid>` | ٥ + ٣٦ = **٤١** | ١٢٠ | ✓ (وكان يسقط على ٤٠ — العيبُ الأصليّ) |
| `budget:<uuid>` | ٧ + ٣٦ = **٤٣** | ١٢٠ | ✓ **لكن غيرُ مسجَّلٍ في الحارس** — A17-01 |
| `account_activation` | ١٨ | ١٢٠ | ✓ |
| `daily_report_missing` | ٢٠ | ١٢٠ | ✓ |
| `sign_reminder` · `sign_copy` · `sign_otp` | ≤ ١٣ | ١٢٠ | ✓ |
| `quote.won` · `digest` · `flow` · `sec` · `ticket` · `assign` · `approval` · `policy` · `custody` · `leave` · `dm` · `react` · `reply` · `mention` · `error` · `ack` · `test` | ≤ ١٠ | ١٢٠ | ✓ |
| `مستخدمون` (عربيّة) | ٨ محارف | ١٢٠ | ✓ |

**الأعمدةُ الأخرى في المسار — كلُّها مقصوصةٌ عند الكاتب:**

| العمود | العرض | القاصّ |
|---|---|---|
| `notifications_hub.text` | ٦٠٠ | **مركزيٌّ** في `HubNotification::booted` (`app/Models/HubNotification.php:38-42`) + `Str::limit(…, 590)` عند الكُتّاب |
| `outbox.text` | ٨٠٠ | **عند الكاتب فقط** — وثلاثةُ كُتّابٍ لا يقصّون (A17-02) |
| `outbox.error` | ٤٠٠ | `mb_substr($emsg, 0, 390)` — `HubOutbox.php:87` |
| `outbox.target` | ٣٠٠ | `hub_fit(…, 290)` في `FlowRunner:278`؛ بريدٌ خامٌّ في خمسة مواضع (بريدٌ صالحٌ ≤ ٢٥٤) |
| `alert_instances.dedup_key` | ١٩١ | `mb_substr(…, 191)` — `AlertEngine::dedupKey` |
| `alert_instances.title` | ٣٠٠ | `mb_substr(…, 300)` — `AlertEngine::upsert` |
| `alert_instances.subject` / `domain` / `module` / `request_id` | ١٢٠/٢٤/٦٠/٤٠ | `mb_substr` عند الكاتب جميعاً |
| `incidents.title` | ٣٠٠ | `Str::limit($title, 190, '')` — `helpers.php:846` |
| `incidents.kind` | ٢٠ | `mb_substr($kind, 0, 20)` |
| `push_deliveries.provider` / `error_category` | — | `hub_fit(…, 20)` / `hub_fit(…, 40)` |

**والبصمةُ `'alert:' . $key` (حتى ١٩٧ محرفاً) لا تدخل عموداً** — تُخزَّن في `incidents.meta` (JSON) و`hub_open_incident` كلُّه ملفوفٌ بـ`try/catch`. فلا خطرَ منها.

---

## ٣) جدولُ الجدولة

المصدر: `routes/console.php` (لا `Kernel`). كرونٌ واحدٌ: `* * * * * php artisan schedule:run`.

| المهمّة | الدوريّة | ماذا تفعل | حِمليّة إن أُعيدت؟ | `withoutOverlapping` | ماذا لو فشلت | من يعلم |
|---|---|---|---|---|---|---|
| `hub:automation` | يوميّاً **06:00** | متكرّرات · قواعدُ التنبيه اليوميّة · تذكيراتُ التوقيع · أتمتةُ العقود · الميزانيّات · الالتزامات · التقليم · OKR · ختمُ الغياب · مصالحةُ التقارير · تشذيبُ الإشارات · لقطةُ الهوامش | **جزئيّاً** — الميزانيّاتُ لا (A17-13)؛ الباقي نعم | ✓ ٢٤٠د | **`onFailure` ميتٌ عمليّاً**: الأمرُ يعود `SUCCESS` دائماً (A17-05) | **لا أحد** — `report($e)` إلى مركز الأخطاء فقط، بلا `severity=CRITICAL` |
| `hub:outbox` | كلَّ **٥ د** | تفريغُ الصادر (tg/mail) + تسليمُ الويبهوكس + تقليمُ المسلَّم | ✓ (حجزٌ شرطيّ `queued→sending`) | ✓ ٢٠د | `onFailure` ⇒ `hub_schedule_failed` · ونبضةٌ `partial` عند فشلٍ جزئيّ | مركزُ الأخطاء (مالكون + مراقبون) + نموذجُ الصحّة + مركزُ المراسلة |
| `hub:backup` | يوميّاً **03:30** | نسخةٌ احتياطيّة | ✓ | ✓ ٢٤٠د | `onFailure` بشدّة `HIGH` | نفسه |
| `hub:digest` | **السبت 07:00** | موجزٌ تنفيذيٌّ للمالكين (إشعار + tg) | ✓ (ختمُ اليوم، و`--force` للتجاوز) | ✓ ٢٤٠د | `onFailure` | نفسه |
| `hub:metrics-snapshot` | يوميّاً **23:45** | لقطةُ الأرقام المتحرّكة | ✓ | ✓ ٢٤٠د | `onFailure` | نفسه |
| `hub:uptime-check` | كلَّ **٥ د** | فحصٌ حيٌّ للسيرفرات والمواقع (قراءة) | ✓ | ✓ ٢٠د | `onFailure` | نفسه |
| `hub:quality-snapshot` | يوميّاً **23:50** | درجةُ جودةِ البيانات | ✓ | ✓ ٢٤٠د | `onFailure` | نفسه |
| `hub:audit-verify` | **الأحد 04:30** | فحصُ سلسلةِ التدقيق (قراءة) | ✓ | ✓ ٢٤٠د | `onFailure` `SECURITY/HIGH` + **`hub_security_incident` حرج** | نفسه + حادثةٌ أمنيّة + `onSuccess` ينبض بالمدّة |
| `hub:ops-snapshot` | كلَّ **٥ د** | رتبةُ الجاهزيّة ومواردُ الخادم في `metric_points` | ✓ | ✓ ٢٠د | `onFailure` + **كتابةُ `heartbeat.ops.meta` صراحةً** (الاشتقاقُ لا يعرف المفتاح — A17-07) | نفسه |
| `hub:security-snapshot` | يوميّاً **23:40** | درجةُ الوضعيّة الأمنيّة + تسويةُ النتائج | ✓ | ✓ ٢٤٠د | `onFailure` + كتابةُ `heartbeat.security.meta` صراحةً | نفسه |
| `hub:alerts-evaluate` | كلَّ **٥ د** | القواعدُ النافذيّة + كشفُ `ops:*` + تصعيدُ SLA + الحظرُ الآليّ | ✓ (`dedup_key` فريد) | ✓ ٢٠د | `onFailure` + كتابةُ `heartbeat.alerts.meta` صراحةً | نفسه |

**ملاحظاتٌ على الجدول:**
- المزاليجُ **بمدّةٍ صريحة** (٢٠ د للخمسيّة، ٢٤٠ د لليوميّة) — فمزلاجٌ يتيمٌ لا يحبس المهمّةَ ٢٤ ساعة (v2.399). ✓
- **لا `onOneServer` على أيّ مهمّة** — A17-08.
- `Health::JOBS` (`app/Support/Health.php:58-70`) يعرف الإحدى عشرةَ كلَّها، بنافذتي «متأخّرة/متعطّلة» لكلِّ دوريّة. ونبضةُ كلِّ أمرٍ مكتوبةٌ في الأمرِ نفسِه إلّا `audit` (تُكتب من `onSuccess` في `routes/console.php:43-45`).

---

## ٤) الأوامرُ الخمسةُ والعشرون — الإنتاجيُّ والخطِرُ وبلا حارس

| الأمر | إنتاجيّ؟ | خطِر؟ | من الويب؟ | الحارس |
|---|---|---|---|---|
| `hub:automation` · `hub:outbox` · `hub:backup` · `hub:digest` · `hub:metrics-snapshot` · `hub:uptime-check` · `hub:quality-snapshot` · `hub:audit-verify` · `hub:ops-snapshot` · `hub:security-snapshot` · `hub:alerts-evaluate` | **مجدولةٌ ١١** | لا | `hub:backup`/`hub:audit-verify`/`hub:outbox --only` | `OpsController::gate()` ✓ |
| `hub:demo --full` | لا | **نعم — يمسح ثمّ يبذر** (`HubDemo.php:51`) | `routes/web.php:830` | `abort_unless(is_owner)` + `hub_require_ops_stepup()` ✓ |
| `hub:demo --purge` | لا | **نعم — حذف** (`where('meta->demo', 1)` فقط) | `routes/web.php:837` | نفسُ الحارسين ✓ |
| `migrate --force` | نعم | **نعم** | `OpsController.php:723` | `gate()` + نسخةٌ احتياطيّةٌ قبله تُقرأ نتيجتُها صدقاً (710-720) ✓ |
| `optimize:clear` | نعم | لا | `OpsController.php:817` | `gate()` ✓ |
| `hub:schema-check` · `hub:flows-starter` · `hub:alerts-starter` · `hub:kpis-starter` | نعم | لا (إضافةٌ/قراءة) | `OpsController.php:856-873` | `gate()` ✓ |
| `hub:openapi` | بناء | لا | لا | — |
| `attendance:reconcile-reports` | نعم | لا | لا (يُنادى من `hub:automation`) | — |
| `hub:encrypt-vault` | نعم | **نعم — يغيّر الأسرارَ في مكانها** | لا | **بلا `confirm()` ولا `--force` ولا فحصِ بيئة** |
| `hub:privatize-files` | نعم | **نعم — ينقل ملفّاتٍ** | لا | **بلا حارس** |
| `hub:import-json` | نعم | **نعم — كتابةٌ جماعيّة** | لا | **بلا حارس** |
| `hub:roles-widen-core-work` | مرّةً | **نعم — يوسّع صلاحيّات** | لا | **بلا حارس** |
| `hub:payroll-months` · `hub:kpis-baseline` · `hub:set` | نعم | متوسّط (كتابة) | لا | **بلا حارس** |

`grep -rln "confirm(\|--force\|production" app/Console/Commands/` يعيد **`HubDigest.php` وحدَه** — فلا أمرَ من السبعةِ الكاتبةِ يسأل قبل أن يكتب على قاعدةِ إنتاج.

**الجواب على «أيمكن تشغيلُ أمرٍ مُدمِّرٍ من الويب؟»** — نعم: `hub:demo --purge/--full` و`migrate --force`. وكلُّها محروسةٌ بمالكٍ + تصعيدِ مصادقة (أو `gate()` + نسخةٍ احتياطيّة). لا ثغرةَ صلاحيّةٍ وُجدت هنا.

---

## ٥) الملاحظات

### A17-01 | متوسط | سِجِلُّ حارسِ العرض ناقص — بادئةٌ رابعةٌ تُكتب وليست فيه

- **الدليل**: `tests/Feature/ColumnFitsItsWriterTest.php:33-40` يحرس ثلاثاً: `notifications_hub.kind ← 'rule:'` و`outbox.kind ← 'rule:'` و`alert_instances.dedup_key ← 'alert:…'`. و`app/Console/Commands/HubAutomation.php:295, 311` يكتب `'kind' => 'budget:' . $b->id` — بادئةٌ رابعةٌ (٧ + ٣٦ = ٤٣) ليست في السجلّ. وكذلك `FlowRunner::QUOTE_WON = 'quote.won'` (`app/Support/FlowRunner.php:20`) و`'daily_report_missing'` و`'account_activation'` أنواعٌ ثابتةٌ غيرُ محروسة.
- **الأثر**: الحارسُ سِجِلٌّ يدويّ، والعيبُ الأصليُّ وُلد من بادئةٍ كُتبت ولم يُحسَب طولُها. اليومَ يتّسع ١٢٠ للجميع، لكنّ البابَ الذي دخل منه العيبُ ما زال مفتوحاً: بادئةٌ خامسةٌ بمعرّفَين (`x:<uuid>:<uuid>` = ٧٥+) أو عمودٌ يُضيَّق لاحقاً ⇒ صمتٌ في الإنتاج.
- **الإصلاح**: (١) أضِف `['notifications_hub', 'kind', 'budget:', 'HubAutomation::budgetsAuto']` للسجلّ الآن. (٢) استبدل السجلَّ اليدويَّ بمسحٍ آليٍّ للمصدر عن نمط `'kind'\s*=>\s*'([^']+)'\s*\.` واحسب `strlen($prefix) + 36` لكلِّ مطابقة — فالسجلُّ لا يُنسى.
- **اختبارُ الانحدار**: `test_no_unregistered_kind_prefix_escapes_the_guard` — يمسح `app/` ويفشل عند بادئةٍ غيرِ مسجَّلة.
- **الثقة**: عالية.

### A17-02 | متوسط | `OutboxMessage` بلا قصٍّ مركزيّ — ثلاثةُ كُتّابٍ بلا حدّ

- **الدليل**: `app/Models/HubNotification.php:38-42` يقصّ `text` مركزيّاً على `creating` بـ`hub_fit(…, hub_col_max('notifications_hub','text') ?? 590)` — «هنا يُحرَس كلُّ موضعِ إنشاءٍ بلا لمسه». **لا نظيرَ لهذا في `OutboxMessage`**: الدليلُ الحاسمُ أنّ `FlowRunner.php:270, 279` و`HubDigest.php:97` ينادون `hub_fit($text, hub_col_max('outbox','text') ?? 790)` **عند موضعِ النداء** — وهو نداءٌ زائدٌ لو كان النموذجُ يقصّ.
  والكُتّابُ الذين لا يقصّون:
  - `app/Console/Commands/HubAutomation.php:206-209` — تذكيرُ التوقيع: `'تذكير: وثيقة «' . Str::limit($req->title, 60) . '» بانتظار توقيعك منذ ' . $days . ' يوماً: ' . route('sign.show', $s->token)` — الرابطُ طولُه تابعٌ لـ`APP_URL`، ولا حدَّ على المجموع.
  - `app/Http/Controllers/Web/EsignController.php:1318` — النمطُ نفسُه.
  - `app/Http/Controllers/Web/MessagingController.php:74` — نصٌّ ثابتٌ قصير (آمن).
  - والبريدُ الخام في `target` (٣٠٠) في خمسة مواضع: `AccountActivation.php:95`، `EsignController.php:719, 831, 1317`، `HubAutomation.php:206`.
- **الأثر**: عرضُ ٨٠٠ يتّسع اليوم. لكن `APP_URL` طويلاً + عنوانَ وثيقةٍ طويلاً ⇒ MySQL ‏22001 ⇒ **تذكيرُ التوقيع لا يُرسَل أبداً، والاستثناءُ يُلتقط ويسقط في `catch { report($e); }`** (`HubAutomation.php:222`) فلا يعلم أحد. هذا **الشكلُ الحرفيُّ** لعيب `kind`، بابٌ آخر.
- **الإصلاح**: `static::creating` في `app/Models/OutboxMessage.php` يقصّ `text` و`target` و`error` إلى `hub_col_max('outbox', …)` — نظيرَ `HubNotification` حرفيّاً. ثمّ تصير نداءاتُ `hub_fit` عند المواضع احتياطاً لا شرطاً.
- **اختبارُ الانحدار**: `OutboxMessage::create(['text' => str_repeat('ب', 900), …])` ثمّ `assertSame(800, mb_strlen($msg->fresh()->text))` — يسقط اليومَ على MySQL.
- **الثقة**: متوسطة-عالية (استنتاجُ غيابِ الـhook من زيادةِ نداءِ `hub_fit` عند المواضع، لا من قراءةِ `app/Models/OutboxMessage.php` — نفدت ميزانيّةُ الأوامر؛ فليتحقّق المُصلِحُ منه أوّلاً بسطرٍ واحد).

### A17-03 | عالٍ | زرُّ «إعادة الفاشل» يزوّر نبضةَ المجدولة ويشغّل عاملَ التسليم داخل طلبِ ويب

- **الدليل**: `app/Http/Controllers/Web/MessagingController.php:161-162`
  ```php
  $n = OutboxMessage::where('state', 'failed')->update(['state' => 'queued', 'error' => null]);
  if ($n) Artisan::call('hub:outbox', ['--limit' => 50]);
  ```
  وبلا `--only`، فـ`HubOutbox::handle` يمضي إلى `$this->webhooks()` (سطر 102) ثمّ:
  ```php
  \App\Support\Health::beat('outbox', …, $failed ? 'partial' : 'ok', …);   // HubOutbox.php:105
  ```
- **الأثر الأوّل — تزويرُ النبضة**: `Health::scheduler` (`app/Support/Health.php:258-274`) يقيس حياةَ المجدولة بعمرِ `heartbeat.outbox`، ومصدرُ التنبيه `health.scheduler` في `AlertEngine::fireSource` يُطلق على `UNAVAILABLE` وحدَه. فكرونٌ ميتٌ يبقى «سليماً» ما دام أحدٌ ينقر الزرّ — وهو **بالضبط صنفُ العطلِ الصامت الذي بُنيت النبضةُ لكشفه**. وأسوأُ حالٍ: عاملٌ يفتح الشاشةَ كلَّ صباحٍ فينعش النبضةَ يوميّاً، والكرونُ متوقّفٌ منذ شهر.
- **الأثر الثاني — طلبٌ طويل**: خمسون رسالةً × `Http::timeout(10)` (`HubOutbox.php:173`) + حلقةُ `webhooks()` بـ`--limit=50` وكلُّ تسليمٍ بمهلةِ ١٠ ثوانٍ (`WebhookDispatcher:125`) ⇒ حتى ~١٦ دقيقةً داخل طلبِ ويبٍ واحد. وقد عولج هذا لزرِّ «التجريبيّة» صراحةً (`MessagingController.php:79`: «الرسالةُ التجريبية وحدَها (v2.399): كان الزرُّ يجرف الطابورَ كلَّه والويبهوك داخل طلب الويب») — **والإصلاحُ لم يشمل `retry()` الملاصقَ له**.
- **الإصلاح**: (١) `retry()` يعيد الصفَّ فقط ويترك التسليمَ للمجدولة (أو ينادي `--only` لكلِّ رسالةٍ بسقفٍ ≤ ٥). (٢) والأهمّ: `Health::beat` لا يُكتب إلّا من تشغيلةٍ **مجدولة** — راية `{--scheduled}` يمرّرها `routes/console.php`، أو `if (! app()->runningInConsole() || app()->bound('request'))` فلا تُكتب النبضةُ من طلبِ ويب.
- **اختبارُ الانحدار**:
  ```php
  $before = setting('heartbeat.outbox');
  $this->actingAs($owner)->post(route('messaging.retry'));
  $this->assertSame($before, setting('heartbeat.outbox'));   // يسقط اليوم
  ```
- **الثقة**: عالية (قرأتُ المسارَين كاملَين).

### A17-04 | متوسط | إعادةُ الشاشة لا تصفّر `attempts` — بابان يفعلان فعلين مختلفين

- **الدليل**: `app/Console/Commands/HubOutbox.php:30-31` (الطرفيّة) يصفّر `['attempts' => 0, 'next_at' => null]`. و`app/Http/Controllers/Web/MessagingController.php:161` (الشاشة) لا يفعل.
- **الأثر**: رسالةٌ بلغت `attempts=3` تُعاد من الشاشة ⇒ عند الالتقاط `$attempts = 4` و`$again = 4 < 3` كاذب (`HubOutbox.php:84-86`) ⇒ `failed` فوراً بعد محاولةٍ واحدة. فالزرُّ يعطي محاولةً، والطرفيّةُ تعطي ثلاثاً، والشاشةُ لا تقول ذلك.
- **الإصلاح**: دالّةٌ واحدة `OutboxMessage::requeueFailed(): int` ينادِيها البابان.
- **اختبارُ الانحدار**: رسالةٌ بـ`attempts=3, state=failed` ⇒ `post(route('messaging.retry'))` ⇒ `assertSame(0, $msg->fresh()->attempts)`.
- **الثقة**: عالية.

### A17-05 | متوسط | `hub:automation` لا يفشل أبداً — فـ`onFailure` عليه حبرٌ على ورق

- **الدليل**: `app/Console/Commands/HubAutomation.php:61-91`. كلُّ خطوةٍ معزولةٌ بـ`try { … } catch (\Throwable $e) { report($e); }`: `alertRules` (94-99)، `esignReminders` (222)، `budgetsAuto` (319)، `contractsAuto`، `obligationsAuto`، `pruneNotifications`، `okrRefresh`، `workdayClose` (35-38)، `reconcileReports` (55-58)، `signalsPrune`، `marginSnapshot`. ثمّ سطر 89-90:
  ```php
  if (! $this->dry) \App\Support\Health::beat('automation', (int) round((microtime(true) - $t0) * 1000));
  return self::SUCCESS;
  ```
  و`routes/console.php:18-19` يعلّق `->onFailure(fn () => hub_schedule_failed('hub:automation', 'QUEUE', 'ERROR'))` — ولا سبيلَ لإطلاقه.
- **الأثر**: العزلُ صحيحٌ في غرضِه (قاعدةٌ واحدةٌ لا تُسقط التذكيراتِ والعقود) — لكنّه في الوقتِ نفسِه **مَخبأُ صمت**. `Health::beat` بلا `result` يعني `ok`، فنموذجُ الصحّة يقول «الأتمتةُ في موعدها» ولو سقطت إحدى عشرةَ خطوةً. والخبرُ الوحيدُ: `report($e)` ⇒ `ErrorLog::exception` (`bootstrap/app.php:84`) ⇒ مركزُ الأخطاء — و`report()` لا يضع `severity='CRITICAL'` فلا يُطلق مصدرَ `errors.critical_count` (الذي يشترطها صراحةً). النتيجةُ: **قواعدُ التنبيه تسقط يوميّاً والنظامُ يقول إنّه بخير** — نفسُ نتيجةِ عيبِ `kind` الأصليّ، بآليّةٍ مختلفة.
- **الإصلاح**: عدّادُ خطواتٍ ساقطة؛ و
  ```php
  Health::beat('automation', $ms, $failedSteps ? 'partial' : 'ok', $failedSteps ? "سقطت {$failedSteps} خطوة" : null);
  ```
  فـ`Health::scheduler:272` يُنزل الحالةَ إلى `DEGRADED` عند `result !== 'ok'`، ويُرى في مركز التشغيل. (ولا يلزم `return FAILURE` — النبضةُ الصادقةُ تكفي وتُبقي بقيّةَ الخطوات تعمل.)
- **اختبارُ الانحدار**: احقن `AlertRule` بوحدةٍ غيرِ موجودةٍ ترمي، شغّل `hub:automation`، وأكّد `setting('heartbeat.automation.meta')['result'] === 'partial'`.
- **الثقة**: عالية.

### A17-06 | متوسط | فشلُ مجدولةٍ يكتب `.meta` ولا يجدّد الختم — فيُقرأ «لم تنبض» لا «نبضت وفشلت»

- **الدليل**: `app/Support/helpers.php:956-960` (`hub_schedule_failed`) يكتب `heartbeat.<job>.meta` فقط:
  ```php
  \App\Models\Setting::updateOrCreate(['key' => 'heartbeat.' . $job . '.meta'],
      ['value' => ['ms' => null, 'result' => 'fail', 'note' => 'فشل التشغيل المجدول', 'at' => now()->toIso8601String()]]);
  ```
  و`Health::scheduler` (`app/Support/Health.php:263-272`) يحسب العمرَ من `setting('heartbeat.' . $key)` — **الختمِ ISO الذي لم يُلمَس**.
- **الأثر**: أمرٌ يعمل ويفشل كلَّ خمسِ دقائق يبدو بعد ٦٠ دقيقةً `UNAVAILABLE` بسببِ «لم تنبض». ومصدرُ `health.scheduler` يكتب عنوانَ تنبيهٍ كاذباً: «المجدولة «…» متعطّلة — آخرُ نبضةٍ منذ N دقيقة». والكتيّبُ يوجّه إلى «cron غير مفعّل» والكرونُ يعمل بانتظامٍ ويفشل — تشخيصٌ يضيّع ساعةً في الحادثة الواحدة. و`Health::scheduler:279`: لو فشلت كلُّ المجدولات معاً لقالت الشاشةُ «سطر cron غير مفعّل على الخادم» وهو مفعّل.
- **الإصلاح**: `hub_schedule_failed` يكتب `heartbeat.<job>` = `now()->toIso8601String()` أيضاً — فالعمرُ صادقٌ و`result='fail'` يجعلها `DEGRADED` بالسببِ الصحيح.
- **اختبارُ الانحدار**: نادِ `hub_schedule_failed('hub:outbox')`، ثمّ أكّد أنّ `Health::check()['components']['scheduler']['data']['jobs']['outbox']['status'] === 'DEGRADED'` لا `UNAVAILABLE`.
- **الثقة**: عالية.

### A17-07 | منخفض | اشتقاقُ مفتاحِ النبضة يخطئ في ثلاثِ مجدولاتٍ — والتعويضُ يدويٌّ لا آليّ

- **الدليل**: `app/Support/helpers.php:950`:
  ```php
  $job = str_replace(['hub:', 'metrics-snapshot', 'quality-snapshot', 'uptime-check', 'audit-verify'],
                     ['',     'metrics',          'quality',          'uptime',       'audit'], $command);
  ```
  لا يعرف `ops-snapshot` ولا `security-snapshot` ولا `alerts-evaluate`. فـ`hub:ops-snapshot` ⇒ `'ops-snapshot'`، وليس `'ops'` الذي في `Health::JOBS`.
- **الأثر اليوم**: `routes/console.php:55-64, 74-83, 93-101` يعوّض بكتابةِ المفتاحِ الصحيح صراحةً بعد النداء — والتعليقُ يقول ذلك حرفيّاً («وقائمةُ اشتقاق `hub_schedule_failed` … لا تعرفه»). فالسلوكُ صحيح. لكنّ الأثرَ الباقي: ثلاثةُ صفوفٍ يتيمةٍ في `settings` (`heartbeat.ops-snapshot.meta` …) لا يقرؤها أحد، و**مجدولةٌ جديدةٌ تُضاف بلا تعويضٍ يدويّ تسقط صامتةً** — الحارسُ ذاكرةُ من يكتب.
- **الإصلاح**: خريطةٌ صريحة `const COMMAND_JOB = ['hub:ops-snapshot' => 'ops', …]` بجانب `Health::JOBS`، واختبارٌ يمرّ على كلِّ `Schedule::command` في `routes/console.php` ويؤكّد أنّ مفتاحَه في `Health::JOBS`.
- **الثقة**: عالية.

### A17-08 | منخفض | لا `onOneServer` على أيّ مجدولة

- **الدليل**: `routes/console.php:11-16` يقرّ بذلك نصّاً: «المزلاج يمنع التداخل على نفس العقدة (و`onOneServer` يُضاف عند تعدّد العُقد)». لا استدعاءَ له في الملفّ كلِّه.
- **الأثر**: على عقدتين خلف موازن، `hub:automation` يولّد المتكرّراتِ مرّتين. الحاجزُ الحقيقيُّ القائمُ هو مانعُ التكرار الدلاليّ: `AlertEngine::daily` يدفع `whereNotIn('id', …kind='rule:…')` إلى SQL، و`budgetsAuto` يفحص `exists()`، و`recurring` يقدّم موعدَ التوليد. لكنّ سباقاً في الثانية نفسِها يمرّ من `exists()` ثمّ `create()` بلا قفلٍ ولا قيدٍ فريد.
- **الإصلاح**: `->onOneServer()` + سائقُ كاشٍ مشترك (redis/db)، أو قيدٌ فريدٌ على `(user_id, kind, record_id, DATE(created_at))`.
- **الثقة**: عالية على الغياب؛ متوسّطة على الأثر (تابعٌ لطوبولوجيا النشر).

### A17-09 | متوسط | رسائلُ المسارات في الصادر بلا `user_id` — فتتجاوز حجبَ «المستلمِ بلا وجهة»

- **الدليل**: `app/Support/FlowRunner.php:268-282` ينشئ `OutboxMessage` بـ`kind='flow'` **وبلا `user_id`**. و`app/Console/Commands/HubOutbox.php:169-171` يحجب المتنَ فقط عند `$msg->user_id && ! $msg->target && ! $pref`:
  ```php
  if ($msg->user_id && ! $msg->target && ! $pref) {
      $text = '🔔 إشعارٌ شخصيٌّ لمستلمٍ بلا وجهةِ تلجرام خاصّة — التفاصيلُ في إشعاراتِ النظام';
  }
  ```
- **الأثر**: رسالةُ مسارٍ بقناة `tg` تسقط إلى `notify.tg_chat` المشتركة **بنصِّها كاملاً**، وفيه `{_display}` (اسمُ السجلّ، `FlowRunner:361-364`) وأيُّ حقلٍ غيرِ مصنَّفٍ حسّاساً. فالنطاقُ الذي يُحرَس في فرع `notify` (سطر 251-253) لا يُحرَس في فرعَي `tg`/`mail`.
- **التخفيفُ القائم**: `FlowRunner::tpl:334, 353` يرفض `sec/file/img` ويقنّع `hub_field_sensitive` بـ`••••` — فالتسريبُ محصورٌ في الأسماء والحقولِ العاديّة، ومن يكتب المسارَ يملك `/admin/flows` أصلاً. لذلك «متوسّط» لا «عالٍ».
- **الإصلاح**: وسمُ رسالةِ المسار صراحةً (`kind='flow'` معروفٌ سلفاً) فيُطبَّق الحجبُ نفسُه حين لا `target` ولا تفضيلٌ شخصيّ — أو يُلزَم المسارُ بوجهةٍ صريحة.
- **اختبارُ الانحدار**: مسارٌ بإجراء `tg` على سجلٍّ اسمُه مميَّزٌ ⇒ `assertStringNotContainsString($name, $outbox->text)` بعد التسليم إلى القناة المشتركة.
- **الثقة**: عالية.

### A17-10 | منخفض | إجراء `task` في المسارات بلا أهليّةِ مسنَدٍ ولا نطاق

- **الدليل**: `app/Support/FlowRunner.php:284-292` — `Task::create` بـ`'assignee_id' => $a['assignee'] ?? null` بلا `eligibleExplicitRecipient` (بخلاف فرع `notify` الملاصق، 251-253)، وبلا `company_id`، و`description` يحمل `self::display($def, $module, $m)` أي اسمَ السجلِّ المصدر.
- **الأثر**: تظهر مهمّةٌ في قائمةِ من لا يرى الوحدةَ الأصليّة، تحمل اسمَ سجلِّها في وصفها. والمهمّةُ بلا `company_id` قد تقع خارجَ عزلِ الشركات.
- **الإصلاح**: مرِّر `assignee` بالبوّابتين نفسِهما، وورِّث `company_id` من السجلِّ المصدر.
- **الثقة**: عالية.

### A17-11 | منخفض | `AlertEngine::upsert` خارجَ حارسِ الاستثناء — قاعدةٌ واحدةٌ تُسقط بقيّةَ التقييم

- **الدليل**: `app/Support/AlertEngine.php` (`evaluate`) — الـ`try/catch { report; continue; }` يلفّ **`fireSource` وحدَه**؛ أمّا `$this->upsert(...)`, `$this->autoBlock(...)`, `$this->resolveCleared(...)` فخارجَه، وكذلك `detectOps` و`escalateTicketSla` بعد الحلقة.
- **الأثر**: استثناءٌ في الكتابة (تصادمُ `dedup_key` في سباقِ عقدتين، أو عمودٌ يضيق) يخرج من `evaluate` كلِّه ⇒ القواعدُ الباقيةُ لا تُقيَّم، والحوادثُ التشغيليّةُ لا تُكشَف، وتصعيدُ SLA لا يقع. يُعلَم به على الأقلّ (`onFailure` في `routes/console.php:93-101`)، لكنّ نصفَ التقييمِ ضاع في الدورة.
- **الإصلاح**: انقل جسمَ الحلقةِ كلَّه داخل الـ`try` (نمطُ `HubAutomation::handle` نفسِه)، ولفّ `detectOps`/`escalateTicketSla` كلاًّ بحارسِه (`escalateTicketSla` ملفوفٌ بالفعل).
- **الثقة**: عالية.

### A17-12 | منخفض | `budgetsAuto`: منعُ تكرارٍ عامٌّ وإنشاءٌ منطَّق — تجويعٌ من البابِ الثالث

- **الدليل**: `app/Console/Commands/HubAutomation.php:296-299` ثمّ 306-316:
  ```php
  $dup = HubNotification::where('kind', 'budget:' . $b->id)
      ->where('created_at', '>=', now()->subDays(7))->exists();   // لأيِّ مستخدم
  if ($dup) continue;
  …
  foreach ($this->recipientUsers(null) as $ru) {
      if (! hub_scope(…, 'budgets', $ru)->exists()) continue;     // ثمّ يُنشأ للمنطَّقين وحدَهم
  ```
- **الأثر**: من اكتسب النطاقَ يومَ ٢ لا يُشعَر بميزانيّتِه حتى يومِ ٨. وهو **نفسُ صنفِ التجويع** الذي عولج مرّتين في `AlertEngine::daily` (v2.316 نقلت مانعَ التكرار قبل الحدّ، وv2.337 نقلت التنطيقَ قبله) — من بابٍ ثالثٍ لم يُطرَق.
- **الإصلاح**: `->where('user_id', $ru->id)` في فحصِ التكرار، داخلَ حلقةِ المستلمين — كما في `AlertEngine.php:214-217`.
- **اختبارُ الانحدار**: ميزانيّةٌ تتجاوز حدَّها؛ مستلمٌ (أ) في النطاق ومستلمٌ (ب) خارجَه ⇒ شغّل ⇒ أدخِل (ب) في النطاق ⇒ شغّل ⇒ أكّد أنّ لـ(ب) إشعاراً. يسقط اليوم.
- **الثقة**: عالية.

### A17-13 | منخفض | التذكيراتُ بمنطقةٍ واحدةٍ ثابتة، ولا ساعاتِ هدوءٍ للدفع

- **الدليل**: `config/app.php:8` — `'timezone' => env('APP_TIMEZONE', 'Asia/Kuwait')`. لا عمودَ منطقةٍ لكلِّ مستخدمٍ في أيِّ مسارِ تذكير: `HubAutomation::esignReminders:195-197` يقيس `now()->diffInDays($since, true)` بالأيّام، و`AlertEngine::daily` يستعمل `today()`/`whereDate`، والجدولةُ كلُّها بمنطقةِ التطبيق.
- **الأثر**: `hub:automation` الساعة 06:00 كويتيّاً = 03:00 بتوقيتِ غرينتش. ولأنّ `HubNotification::booted:77-79` يفرّع **كلَّ** إشعارٍ مُنشأٍ إلى دفعِ الجوال فوراً (`PushService::scheduleFanout`)، يرنّ جوالُ موظّفٍ خارجَ المنطقةِ في الثالثةِ فجراً. ولا مفهومَ «ساعاتِ هدوء» في أيِّ مكان.
- **الإصلاح**: `quiet_hours` في `users.notify_prefs` (العمودُ قائم)، يقرؤها `PushService::fanout` فتؤجّل التفريعَ لا تُلغيه. والتذكيرُ الزمنيُّ الدقيقُ (لا اليوميُّ) يُحسب بمنطقةِ المستلم.
- **الثقة**: عالية على الآليّة؛ متوسّطة على الأثر (تابعٌ لوجودِ مستخدمين خارج `Asia/Kuwait`).

### A17-14 | منخفض | لا جرسَ لطابورٍ صادرٍ متعطّل — `health.*` ليست مصدرَ تنبيه

- **الدليل**: `AlertEngine::SOURCES` (`app/Support/AlertEngine.php:43-50`) ستّةُ مصادرَ، منها `health.scheduler` وحدَه من عائلةِ الصحّة. و`Health::outbox` (`app/Support/Health.php:288-302`) يحسب `queued/failed24/oldest/lastError` ويجعل المكوّنَ `UNAVAILABLE` حين تعلَق رسالةٌ أطولَ من العتبة — **ولا قاعدةَ تنبيهٍ تقرأ هذا الحكم**.
- **الأثر**: طابورٌ فشل كلُّه (رمزُ تلجرام انتهت صلاحيّتُه، SMTP رفض) يبقى بلا جرسٍ حتى يفتح أحدٌ مركزَ التشغيل. والصادرُ هو **قناةُ الخروجِ الوحيدةُ للتنبيهات نفسِها** — فعطلُه يُسكِت كلَّ ما بُني فوقه.
- **الإصلاح**: أضِف `health.outbox` (وربّما `health.webhooks`) إلى `SOURCES` ودالّتَه في `fireSource` — تقرأ `$health['components']['outbox']` المحسوبَ سلفاً (مرّرَه `HubAlertsEvaluate` بالفعل)، كنمطِ `health.scheduler` حرفيّاً. والإشعارُ الناتجُ يصل عبر الجرسِ الداخليِّ لا عبر الصادرِ المعطّل.
- **الثقة**: عالية.

---

## ٦) ما وُجد **سليماً** (تثبيتٌ لا مجاملة)

### A17-15 | الاستدعاءاتُ الراجعةُ الخارجيّة — التوقيعُ والإعادةُ والتكرار

- **التوقيع**: `app/Support/WebhookDispatcher.php:131` — `'X-Hub-Signature' => 'sha256=' . hash_hmac('sha256', $d->payload, $h->secret)` على **الجسم الخام المخزون** لا على بنيةٍ يُعاد ترتيبُها ✓
- **منعُ التكرار عند المستقبِل**: `X-Hub-Event-Id` (سطر 129) + `X-Hub-Webhook` (130) ✓
- **الإعادة**: سلّمُ `self::BACKOFF` بعددِ محاولاتٍ محدود (156-168)؛ الخطأُ الدائم (`$permanent`) لا يُعاد؛ `Retry-After` مقروءٌ بسقفِ ٢٤ ساعة؛ الفاشلُ نهائيّاً `state='failed', next_at=null` ✓
- **التطهير**: `mb_substr(Redactor::text($e->getMessage()), 0, 390)` مقابل عمودِ ٤٠٠ (138) ✓
- **التصفيةُ في الاستعلام لا بعده**: `HubOutbox::webhooks:127-137` يستبعد الاشتراكاتِ المعطّلة/الموقوفة **داخل** `whereExists` — فاشتراكٌ ميتٌ بمتراكماتٍ لا يملأ الدفعةَ ويشلّ الأحياء ✓
- **الحجزُ الشرطيّ**: `update(['state'=>'sending'])` على `where('state','queued')` قبل الإرسال (148-149) ✓
- **غيرُ مفحوص**: الاستدعاءاتُ **الواردة** (`inbound_hook_events`) — خارج ما وسِعته الميزانيّة. أوصي بإسنادها لمن يراجع السطوحَ العلنيّة.

### A17-16 | الصندوقُ الصادر — الضماناتُ والشاشة

- **الضمانة**: «على الأقلّ مرّة» لا «مرّةً بالضبط». الحجزُ `queued→sending` بـ`update` شرطيّ (`HubOutbox.php:64-65`) يمنع الازدواجَ بين تشغيلتَين، واسترجاعُ العالقِ يُقاس **من لحظةِ الحجز** لا من الإنشاء (37-39) — فلا يخطف تشغيلٌ رسالةً يرسلها آخرُ الآن. لكنّ عطلاً بعد نداءِ تلجرام وقبل `save()` يُعيد الإرسالَ بعد عشرِ دقائق. لا مفتاحَ idempotency لدى المزوّد — مقبولٌ لتلجرام/البريد.
- **رسالةٌ فشلت ثلاثاً**: `attempts` يتصاعد بتباعدِ ٥ → ٣٠ → ١٢٠ دقيقة (`outbox.max_attempts`، افتراضاً ٣)، ثمّ `state='failed'` و`next_at=null` و`error` مطهَّرٌ من رمزِ البوت (`Redactor::text`) ومقصوصٌ إلى ٣٩٠.
- **أيراها أحد؟ نعم**: `Health::outbox` + `Integrations` (`app/Support/Integrations.php:286-290`) + مركزُ المراسلة (الجدول + «إعادة» + «تجريبيّة») + `OpsController:968-984`. والتقليمُ يمسحها بعد `retention.outbox_days` (١٨٠، بأرضيّةِ ٣٠) — `HubAutomation.php:558`.
- **الأولويّة**: `orderByRaw("CASE WHEN kind IN ('sign_otp','otp','test') THEN 0 ELSE 1 END")` (`HubOutbox.php:48`) — فرمزُ التوقيع الصالحُ عشرَ دقائق لا يقف خلف ستّين تقريراً ✓
- **الفجوةُ الوحيدة**: لا **جرسَ مبادِر** عند تعطّل الطابور — A17-14.

### A17-17 | الدفع (push) — الخصوصيّةُ ومنعُ التكرار سليمان، ولا إعادةَ عمداً

- `PushService::payloadFor` (`app/Support/PushService.php:203-227`) لا يمرّر نصَّ الإشعار قطّ: عنوانٌ عامٌّ من `KIND_TITLES` + `GENERIC_BODY` + `{module,id,action}` + عدُّ غير المقروء. و`baseKind` يختزل `rule:<uuid>` إلى `rule` فلا يتسرّب معرّفُ القاعدة ✓
- **الأنواعُ المكتومة لا تُدفَع**: hook الكتمِ يُرجع `false` من `creating` فلا يقع `created` أصلاً (`app/Models/HubNotification.php:44-79`) — بلا حارسٍ زائد ✓
- **التأجيلُ بعد الالتزام**: `DB::afterCommit` (126-135) فلا يُرجِع استثناءُ مزوّدٍ معاملةً ملتزمة ✓
- **منعُ تكرارِ الرمز**: رمزٌ لمالكٍ واحد؛ تسجيلُ رمزٍ كان لغيرِه يُبطِل ربطَه القديم ✓ والرمزُ غيرُ الصالح (`ERR_UNREGISTERED`) يُبطَل فوراً (176-178) ✓
- **لا إعادةَ للدفع**: `fanout` (145-195) يحاول **مرّةً**، يكتب `attempts=1`، ولا صفَّ ولا `next_at` ولا عاملَ إعادة. مقصودٌ ومُعلَن («الدفعُ إثراءٌ لا شرط») — ويستحقّ أن يُقال في شاشةِ الدفع كي لا يُبنى عليه توقّعٌ خاطئ.

### A17-18 | لا حلقةَ أتمتةٍ لا نهائيّة — ثلاثةُ حرّاسٍ مستقلّة

1. `FlowRunner::act` حالةُ `set` تحفظ بـ`saveQuietly()` (`app/Support/FlowRunner.php:299`) — فلا حدثَ يُبَثّ. والتعليقُ صريح: «بلا إطلاق مسارات جديدة — حماية من الحلقات».
2. `HubEvents::dispatch` (`app/Support/HubEvents.php:60-69`) يحمل رايةَ `$deriving` فلا يشتقّ حدثٌ دلاليٌّ دلاليّاً آخر.
3. `FlowRunner::fire` يُنادى من المتحكّمات ومن `hub_open_incident` صراحةً، **لا من hook نموذج** — فـ`Task::create` داخل `act` (284) لا يُطلق مساراتِ `tasks`، و`Incident::create` لا يُطلق إلّا النداءَ الصريحَ الواحد.
- **الملاحظةُ الوحيدة**: `$deriving` رايةٌ **ساكنة (static) بلا عدّادِ عمق** — لو سجّل مستهلكٌ عبر `HubEvents::listen` نداءً يعود إلى `FlowRunner::fire` لصارت الحلقةُ ممكنة. لا مستهلكَ اليومَ يفعل. عدّادُ عمقٍ بسقفٍ (مثلاً ٣) أرخصُ من انتظارِ أن يقع.
- **وبصلاحيّةِ من تُنفَّذ؟** بلا مستخدم: `tpl:328` ⇒ `auth()->user()->name ?? 'النظام'`، والقناعُ في 353 يحكم بـ**تصنيفِ الحقل** لا بقارئٍ بعينه — «ومن يقرأ النصَّ ليس من كتبه». فالأتمتةُ تعمل بصلاحيّةِ النظام، والحارسُ الوحيدُ على المستلم هو `eligibleExplicitRecipient`/`hub_approvers_for`. قرارٌ موثَّقٌ ومقصود، لا عيب.

---

## ٧) الأرقامُ المطلوبة

| السؤال | الجواب |
|---|---|
| **كم مهمّةً بلا إنذارِ فشل؟** | **١ من ١١** — `hub:automation` وحدَها (`onFailure` معلَّقٌ لكنّه لا يُطلق أبداً لأنّ الأمرَ يعود `SUCCESS` دائماً). العشرُ الباقيةُ لها `onFailure` فعّال، وثلاثٌ منها تكتب مفتاحَ نبضتها صراحةً لأنّ الاشتقاقَ يخطئ (A17-07). |
| **كم مساراً غيرَ حِمليّ؟** | **٣** — (١) `HubAutomation::budgetsAuto` (منعُ تكرارٍ عامٌّ + إنشاءٌ منطَّق، A17-12)؛ (٢) الصادرُ «على الأقلّ مرّة» — عطلٌ بين نداءِ المزوّد وحفظِ الحالة يُعيد الإرسالَ بعد ١٠ دقائق (A17-16)؛ (٣) غيابُ `onOneServer` — التوليدُ والإشعارُ يتضاعفان على عقدتَين (A17-08). و`AlertEngine::daily`/`evaluate` و`esignReminders` و`contractsAuto` و`recurring` و`pruneNotifications` **حِمليّةٌ** بمانعِ تكرارٍ حقيقيّ. |
| **هل وُجد نظيرٌ لعيبِ `kind`؟** | **لا نظيرَ حيّ**: كلُّ قيمةِ `kind` (١٤ نوعاً ثابتاً + بادئتان بمعرّف) تسعُ عرضَ ١٢٠، وكلُّ عمودٍ نصّيٍّ في مسار التنبيه/الجدولة مقصوصٌ عند كاتبِه أو عند نموذجِه. **لكن وُجدت ثغرتان من صنفِه**: سجلُّ الحارس يحرس ثلاثاً من أربعِ بادئات (A17-01)، و`OutboxMessage` بلا القصِّ المركزيِّ الذي يحمي `HubNotification` — وثلاثةُ كُتّابٍ فيه بلا حدّ، وفشلُهم يقع في `catch { report(); }` صامتاً (A17-02). فالبابُ الذي دخل منه العيبُ ما زال مفتوحاً وإن كان البيتُ نظيفاً اليوم. |

---

## ٨) ترتيبُ المعالجة المقترح

1. **A17-03** (عالٍ) — نبضةٌ مزوّرةٌ تُعمي نموذجَ الصحّة عن كرونٍ ميت. إصلاحٌ من سطرين.
2. **A17-05** (متوسط) — نبضةُ `automation` صادقةً (`partial`) فيُرى سقوطُ الخطوات.
3. **A17-02** (متوسط) — `static::creating` في `OutboxMessage`. يغلق صنفَ العيب لا حالتَه.
4. **A17-01** (متوسط) — سجلُّ الحارس آليّاً لا يدويّاً.
5. **A17-06** (متوسط) — ختمُ النبضة عند الفشل، فيصدق التشخيص.
6. **A17-09 · A17-12 · A17-14** (متوسط/منخفض) — تسريبُ قناةِ المسارات، تجويعُ الميزانيّات، جرسُ الطابورِ المتعطّل.
7. الباقي (A17-04 · A17-07 · A17-08 · A17-10 · A17-11 · A17-13) — نظافةٌ ومتانة.
