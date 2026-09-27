# فهرسُ الوثائق — Lynomia Business Hub

> **الحيُّ هنا، والتاريخيُّ في `archive/`.** الوثيقةُ الحيّة تصف النظامَ كما هو ويُحدَّث ما يتغيّر منها
> مع الشيفرة (وبعضُها تقرؤه الاختبارات فلا ينحرف صامتاً). والمؤرشفةُ لقطةٌ من إصدارٍ مضى — تُقرأ
> لفهم **لماذا** صار الشيءُ كما هو، لا لمعرفة **أين** هو اليوم؛ مساراتُها وأسماءُ أصنافها كما كانت حينها.
>
> النقلُ إلى الأرشيف في v2.603.6 (`REORG_PLAN.md` §R7) — وكلُّ مرجعٍ إليها في الشيفرة والوثائق أُعيدت
> كتابتُه، ولم ينكسر رابطٌ نسبيّ.

## ١) ابدأ هنا

| الوثيقة | ما فيها |
|---|---|
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | خريطةُ المعمارية: المجلّدات والنطاقات، الطبقات، مسارُ الطلب، الأمن، العملُ الخلفيّ، **وأين تضيف ماذا** |
| [`../CLAUDE.md`](../CLAUDE.md) | اصطلاحاتُ العمل: رفعُ النسخة، المحرّكان، ترتيبُ الصفوف، الإضافةُ لا الكسر |
| [`TECH_DEBT.md`](TECH_DEBT.md) | سجلُّ الدَّين التقنيّ بأدلّته (`TechDebtTruthTest` · `ControlPlaneDocsTest` تقرآنه؛ وسقوفُه مفروضةٌ شيفرةً في `TechDebtCeilingTest`) |
| [`REORG_PLAN.md`](REORG_PLAN.md) | خطّةُ إعادة التنظيم R0–R7 وما نُفّذ منها وما لم يُنفَّذ وسببُه |
| [`architecture-decisions.md`](architecture-decisions.md) | سجلُّ القرارات المعمارية (المشكلة · الخيارات · المختار · السبب) |

## ٢) التشغيل والعقود

| الوثيقة | ما فيها |
|---|---|
| [`RUNBOOKS.md`](RUNBOOKS.md) | كتيّباتُ الأعطال — تُعرض داخل مركز التشغيل (`/admin/ops/runbooks`) |
| [`CONTROL_PLANE.md`](CONTROL_PLANE.md) · [`control-plane/`](control-plane/) | مستوى التحكّم المؤسسي: المراكز وقارئوها، الاحتفاظ، الشدّة، الترابط |
| [`API.md`](API.md) · [`openapi.json`](openapi.json) | واجهةُ REST v1 — والمواصفةُ **مولَّدة** (`php artisan hub:openapi --out=docs/openapi.json`) لا تُحرَّر |
| [`performance/`](performance/) | خطُّ أساسِ الأداء تحت حِمل وأداتُه |

## ٣) النطاقات

| المجلّد | النطاق |
|---|---|
| [`ai-hub/`](ai-hub/) | مركزُ الذكاء: البنية (`01`) · البوّابة وLiteLLM · الحوكمة والدفتر · اسأل المنصّة · **خارطةُ الطريق والمدقّق (`46-ai-roadmap.md`)** |
| [`mobile-readiness/`](mobile-readiness/) · [`mobile-platform-center/`](mobile-platform-center/) | سطحُ الجوال `/api/mobile/v1` وعقدُه (`mobile-capabilities.json` مولَّد) · مركزُ منصّة الجوال — تُعرضان داخل المركز |
| [`permissions-360/`](permissions-360/) · [`permissions-reconciliation/`](permissions-reconciliation/) | معمارُ الصلاحيّات والرؤية ومطابقتُه |
| [`attendance-reporting/`](attendance-reporting/) | الحضور × التقارير اليومية × المراجعة والامتثال |
| [`collaboration/`](collaboration/) | المحادثات والقنوات والرسائل المباشرة والحضور والغرف |
| [`entity360/`](entity360/) · [`project360/`](project360/) | صفحاتُ الكيان 360 (الموظّف · المحطّة · الأصل) · المشروع 360 وربطُ الأصل |
| [`endpoint/`](endpoint/) | وكيلُ النقاط الطرفية والأجهزة المُدارة |
| [`features/`](features/) | سجلُّ القدرات (`config/hub_features.php`) وإدارتُها |
| [`information-architecture/`](information-architecture/) · [`discoverability/`](discoverability/) | خريطةُ التنقّل وقواعدُه · مبادئُ الاكتشاف |

## ٤) الأرشيف — [`archive/`](archive/)

| الوثيقة | لقطةُ |
|---|---|
| `ARCHITECTURE_AUDIT.md` · `system-development-master-plan.md` | تدقيقُ المعمارية وخطّةُ التوحيد الأولى · الخطّةُ الرئيسية (~v2.66–v2.134) |
| `ADVERSARIAL_AUDIT.md` · `SYSTEM_AUDIT.md` | التدقيقُ العدائيّ (v2.67–v2.77) · تدقيقُ النظام العميق (v2.385) |
| `FIELD_FORCE_MAP.md` · `QUOTATION_MAP.md` · `CPQ_MAP.md` · `SECURITY_PLATFORM_MAP.md` · `INTELLIGENCE_MAP.md` | خرائطُ «قبل أوّل سطر» للأنظمة (v2.365–v2.400) |
| `ENTERPRISE_UPGRADE_REPORT.md` · `work-os/` | تقريرُ الترقية المؤسّسية (v2.400) · برنامجُ Work OS (v2.408–v2.420) |
| `human-simulation/` · `month-simulation/` · `expert-council/` · `final-audit/` · `ultimate-platform-review/` | برامجُ المحاكاة والمجلس والتدقيق الختاميّ والمراجعة السداسيّة — أُغلقت، والمفتوحُ من الدَّين يُتابَع في `TECH_DEBT.md` |
| `deployment/` · `security-audit-v2600/` | خطّةُ نشر v2.555→v2.559 · بلاغاتُ التدقيق الأمنيّ v2.600 وأحكامُها |
