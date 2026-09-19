# بوّابة LiteLLM — دليلُ التشغيلِ والصيانة · المرحلة ١٫٥

> ⛔ **لم يُنفَّذ شيءٌ من هذا.** الملفّاتُ للمراجعة، والتشغيلُ بموافقةٍ صريحةٍ ثانية.
> 🚫 **ولا يُلمَس مشروعُ `lynomia-backend`** — ولا حتّى لفحصِ شبكتِه أو حاوياتِه.

## ١) الحقائقُ المثبَّتة — ومصادرُها

| الحقيقة | المصدر |
|---|---|
| `LITELLM_SALT_KEY` يشفّر اعتماداتِ المزوّدين في `LiteLLM_ProxyModelTable` و`LiteLLM_CredentialsTable` | [Security & Encryption FAQ](https://docs.litellm.ai/docs/proxy/security_encryption_faq) |
| **«Never change this key — encrypted data becomes unrecoverable»** · يُضبَط **قبل إضافةِ أيِّ نموذج** | المصدرُ نفسُه |
| إن لم يُضبَط، يُشفَّر بـ`LITELLM_MASTER_KEY` بدلاً منه | المصدرُ نفسُه |
| البوّابةُ تستعمل **PostgreSQL**، ولا تُعلِن الوثائقُ **أيَّ قيدِ نسخة** | [DB Info](https://docs.litellm.ai/docs/proxy/db_info) |
| فحصا الصحّةِ `/health/liveliness` و`/health/readiness` **بلا مصادقة** | [Health Checks](https://docs.litellm.ai/docs/proxy/health) |
| «pin a version, do not use `:latest`» · `main-stable` **مهجور** | [Production Best Practices](https://docs.litellm.ai/docs/proxy/prod) |

### 🔴 تصحيحٌ لحقيقةٍ ادُّعيت سابقاً

قيل في خطّةٍ سابقة: **«`DATABASE_URL` لا يُدوَّر بعد إضافةِ النماذج لأنّه يشفّر
الاعتمادات»**. **وهذا خطأ.**

المراجعةُ المباشرةُ لصفحةِ الأمنِ الرسميّة تقول إنّ **`LITELLM_SALT_KEY` وحدَه**
هو الذي يشفّر، وأنّ `DATABASE_URL` **سلسلةُ اتصالٍ لا تشفّر شيئاً**. مصدرُ
الخطأ: تلخيصٌ آليٌّ لصفحةِ النشرِ خلط الاثنين. **فالقيدُ الحقيقيُّ على مفتاحِ
الملحِ وحدَه**، و`DATABASE_URL` **قابلٌ للتغيير** (تدويرُ كلمةِ مرور، نقلُ
مضيف) ما دام يشير إلى البياناتِ نفسِها.

**ونتيجةٌ عمليّةٌ مهمّةٌ تترتّب:** لأنّ الملحَ يسقط إلى مفتاحِ الإدارةِ عند
غيابِه، فإنّ **ضبطَ `LITELLM_SALT_KEY` صراحةً هو ما يجعل مفتاحَ الإدارةِ قابلاً
للتدوير**. لولاه لكان تدويرُ مفتاحِ الإدارةِ متلفاً لكلِّ اعتماد.

### توافقُ الإصدارِ مع PostgreSQL 16.14

**لا تُعلِن وثائقُ LiteLLM قيدَ نسخةٍ لـPostgreSQL** — فلا يصحّ أن يُقال
«مدعومٌ رسميّاً» ولا «غيرُ مدعوم». والمختارُ **16.14** لسببين مقيسين: مِيجر 16
هو ما يعمل على هذا الخادمِ فعلاً لمشروعٍ إنتاجيٍّ آخر (تناسقُ تشغيل)، و16.x خطُّ
مستقرٌّ حيّ. **والتحقّقُ العمليُّ هو L1**: إقلاعُ البوّابةِ يطبّق هجراتِها على
القاعدة — فنجاحُه هو الدليل، لا إعلانٌ في وثيقة.

## ٢) الصورُ — الوسمُ والبصمة

| الخدمة | الصورة | البصمة | مصدرُ التحقّق |
|---|---|---|---|
| البوّابة | `ghcr.io/berriai/litellm:v1.101.0` | `sha256:d295634e09c648dcdb72c4cc2dd226f5fb87823a73e88cbbed6f205e4deb044b` | استعلامُ `ghcr.io/v2/berriai/litellm/manifests/v1.101.0` ⇐ ترويسة `docker-content-digest` |
| القاعدة | `postgres:16.14-alpine` | `sha256:57c72fd2a128e416c7fcc499958864df5301e940bca0a56f58fddf30ffc07777` | استعلامُ `registry-1.docker.io/v2/library/postgres/manifests/16.14-alpine` |

**ولماذا البصمةُ لا الوسمُ وحدَه؟** قِيس أنّ `postgres:16-alpine` المتحرّكَ
يشير الآن إلى `sha256:3c5c8892…` — **بصمةٍ مختلفةٍ عن `16.14-alpine`**. أي
أنّ الوسمَ المتحرّكَ **تحرّك فعلاً** منذ سحبِك. فالتثبيتُ ليس احتياطاً نظريّاً.

**وللتحقّقِ من البصمةِ على الخادمِ بعد السحب:**
```bash
docker image inspect ghcr.io/berriai/litellm:v1.101.0 --format '{{index .RepoDigests 0}}'
```

## ٣) الأسرار — التوليدُ والحفظ

**ثلاثةُ ضوابط:** (١) تُولَّد بـ`openssl rand` — مولّدٌ آمنٌ تعمّيّاً،
(٢) **تُكتب مباشرةً إلى الملفِّ ولا تُطبَع على الشاشةِ قطّ**، (٣) تُنشأ
الملفّاتُ بصلاحيّةٍ ضيّقةٍ **قبل** كتابةِ أيِّ سرٍّ فيها (`umask 077`).

**ولماذا `/etc/litellm/` لا المستودع؟** جذرُ المستودعِ على الإنتاج هو
`/home/lynomia/public_html/hub-lynomia-com` — أي **داخلَ `public_html`**. وقد
قُرئ `.htaccess` في الجذر: يعيد كتابةَ كلِّ طلبٍ إلى `public/` — فلا يُخدَم شيءٌ
من `deploy/` اليوم. **لكنّ هذه حمايةُ إعدادٍ لا حمايةُ موضع**: تعطيلُ
`mod_rewrite` أو تغييرُ الـvhost يكشف كلَّ ملفٍّ في الجذر دفعةً واحدة. فالسرُّ
يُوضَع حيث لا يبلغه خادمُ الوِب **بنيةً** — `/etc/litellm/` بصلاحيّة
`0600 root:root`، خارجَ Git وخارجَ `public_html` معاً.

**ولماذا لا تظهر في `history`؟** لأنّ السرَّ **لا يُمرَّر وسيطاً في سطرِ أمر**:
يُولَّد ويُكتب داخلَ نفسِ الأمرِ عبر إعادةِ توجيه. فالمكتوبُ في التاريخِ هو
*الأمرُ* لا *القيمة*. (وتُجنَّب `echo "$SECRET"` في كلِّ الخطوات.)

## ٤) كلُّ أمرٍ سيُنفَّذ — بترتيبِه وبمن يُنفّذه

> **العمودُ «root؟»**: المستخدمُ `lynomia` **ليس في sudoers** ولا في مجموعة
> `docker` — فكلُّ ما هو `root` يُنفَّذ بدخولٍ مباشرٍ كـroot.

### المرحلةُ أ — التحضير

| # | الأمر | root؟ |
|---|---|---|
| أ‑١ | `mkdir -p /etc/litellm && chmod 0700 /etc/litellm && chown root:root /etc/litellm` | **نعم** |
| أ‑٢ | `mkdir -p /home/lynomia/litellm-backups && chmod 0700 /home/lynomia/litellm-backups` | **نعم** |

### المرحلةُ ب — الأسرار (تُولَّد ولا تُطبَع)

| # | الأمر | root؟ |
|---|---|---|
| ب‑١ | `umask 077; PGPW="$(openssl rand -hex 24)"` — في **نفسِ الصدفةِ** دون طباعة | **نعم** |
| ب‑٢ | `printf 'POSTGRES_PASSWORD=%s\n' "$PGPW" > /etc/litellm/postgres.env` | **نعم** |
| ب‑٣ | `{ printf 'LITELLM_MASTER_KEY=sk-%s\n' "$(openssl rand -hex 32)"; printf 'LITELLM_SALT_KEY=%s\n' "$(openssl rand -hex 32)"; printf 'DATABASE_URL=postgresql://litellm:%s@litellm-postgres:5432/litellm\n' "$PGPW"; } > /etc/litellm/litellm.env` | **نعم** |
| ب‑٤ | `unset PGPW` | **نعم** |
| ب‑٥ | `chmod 0600 /etc/litellm/*.env && chown root:root /etc/litellm/*.env` | **نعم** |
| ب‑٦ | `ls -la /etc/litellm/` — **للتأكّدِ من الصلاحيّاتِ لا من القيم** | **نعم** |

**ب‑٧ · 🔑 نسخُ مفتاحِ الملحِ — إلزاميٌّ قبل أيِّ تشغيل:**
```bash
grep '^LITELLM_SALT_KEY=' /etc/litellm/litellm.env   # يُنسَخ إلى مخزنِ أسرارٍ خارجَ الخادم
```
> هذه **المرّةُ الوحيدةُ** التي يُعرَض فيها المفتاح. انقله إلى مديرِ كلماتٍ أو
> خزنةٍ **الآن**. بعد إضافةِ أوّلِ نموذجٍ لا سبيلَ لاستعادتِه، وفقدُه يعني
> فقدَ كلِّ اعتمادِ مزوّدٍ **بلا استرجاع**.

**وما يُكتب في Hub:** مفتاحُ الإدارةِ (`sk-…`) يُدخَل مرّةً في
**الإدارة ← الذكاء الاصطناعي** فيُحفَظ مشفّراً (`enc:`). **ولا يُدخَل مفتاحُ
الملحِ في Hub إطلاقاً** — لا شأنَ لـHub به.

### المرحلةُ ج — التشغيل

| # | الأمر | root؟ |
|---|---|---|
| ج‑١ | `cd /home/lynomia/public_html/hub-lynomia-com` | لا |
| ج‑٢ | `git fetch origin && git log --oneline HEAD..origin/<الفرع>` — معاينة | لا |
| ج‑٣ | دمجُ الفرعِ ليصلَ ملفُّ compose (بلا `reset`/`clean`) | لا |
| ج‑٤ | `docker compose -p litellm -f deploy/litellm/docker-compose.yml config -q` — **تحقّقٌ نحويٌّ بلا تشغيل** | **نعم** |
| ج‑٥ | `docker compose -p litellm -f deploy/litellm/docker-compose.yml pull` | **نعم** |
| ج‑٦ | `docker image inspect … --format '{{index .RepoDigests 0}}'` — مطابقةُ البصمة | **نعم** |
| ج‑٧ | `docker compose -p litellm -f deploy/litellm/docker-compose.yml up -d` | **نعم** |

## ٥) مستوياتُ الإثبات L1–L5

| المستوى | الأمر | النجاح |
|---|---|---|
| **L1** | `docker compose -p litellm -f deploy/litellm/docker-compose.yml ps` | حاويتان `(healthy)` |
| **L2** | `curl -fsS http://127.0.0.1:4000/health/liveliness` | ردٌّ 200 |
| **L2ب** | `curl -fsS http://127.0.0.1:4000/health/readiness` | يذكر حالةَ القاعدة |
| **L3** | أمرٌ من ثلاثةِ أسطر — أدناه (§٥‑أ) | 200 · قائمةٌ **فارغة** (لا مزوّد — وهو الصواب) |
| **L4** | في Hub: الإدارة ← الذكاء الاصطناعي ← حفظُ العنوانِ والمفتاح | يُحفَظ مشفّراً |
| **L5** | زرُّ «اختبار الاتصال» | `up === true` ⇒ **GATEWAY VERIFIED** |

### §٥‑أ · أمرُ L3 — ولماذا ليس سطراً واحداً

```bash
set -a; . /etc/litellm/litellm.env; set +a
printf 'url = "http://127.0.0.1:4000/v1/models"\nheader = "Authorization: Bearer %s"\n' \
  "$LITELLM_MASTER_KEY" | curl -fsS --config -
unset LITELLM_MASTER_KEY LITELLM_SALT_KEY DATABASE_URL POSTGRES_PASSWORD
```

**ولماذا هذا التعقيد؟** الصيغةُ البديهيّةُ `curl -H "Authorization: Bearer $KEY"`
تضع المفتاحَ **وسيطاً في سطرِ أمرِ `curl`** — و`argv` أيُّ عمليّةٍ **مقروءٌ
لكلِّ مستخدمي الخادم** عبر `/proc/<pid>/cmdline` و`ps aux`. وعلى هذا الخادمِ
**مشاريعُ أخرى ومستخدمون آخرون**، فالنافذةُ ليست نظريّة. و`printf` **بِنيةٌ
داخليّةٌ في bash** لا عمليّةٌ منفصلة، فلا `argv` لها أصلاً، و`--config -` يقرأ
من المجرى لا من سطرِ الأمر.

**و`GATEWAY VERIFIED` لا تعني:** MODEL GENERATION VERIFIED · PROVIDER
VERIFIED · AI ENABLED. **ولا يُربَط مزوّدٌ في المرحلة ١٫٥.**

### إثباتُ عدمِ الكشف (بندٌ مستقلّ)

```bash
# ① المنفذُ 4000 على loopback وحدَه — يجب ألّا يظهر 0.0.0.0:4000
ss -ltnp | grep ':4000'

# ② المنفذُ 5432 غيرُ منشورٍ من مشروعِنا إطلاقاً
docker compose -p litellm -f deploy/litellm/docker-compose.yml ps --format '{{.Service}} {{.Ports}}'

# ③ ومن خارجِ الخادم (من جهازك):
#    nc -vz -w 5 server1.lynomia.com 4000   ⇐ يجب أن يفشل
```

> **نقطةٌ تقنيّةٌ تُقال صراحةً:** داخلَ الحاوية تستمع العمليّةُ على `0.0.0.0`
> — **وهذا لازم**، إذ لو ربطت `127.0.0.1` داخلَ فضائِها الشبكيِّ الخاصِّ لما
> وصلها تمريرُ Docker. **والعزلُ يقع على المضيف** بـ`127.0.0.1:4000:4000`،
> ودليلُه الفحصانِ ① و③ أعلاه — لا إعلانٌ في ملف.

## ٦) النسخُ والاستعادة

```bash
# نسخٌ (قبل كلِّ ترقية) — `umask` **قبل** إعادةِ التوجيه، فلا تولد النسخةُ 0644 لحظةً
umask 077
docker compose -p litellm -f deploy/litellm/docker-compose.yml exec -T postgres \
  pg_dump -U litellm -d litellm --clean --if-exists \
  | gzip > /home/lynomia/litellm-backups/litellm-$(date -u +%Y%m%dT%H%M%SZ).sql.gz

# استعادة
gunzip -c <الملف>.sql.gz | docker compose -p litellm \
  -f deploy/litellm/docker-compose.yml exec -T postgres psql -U litellm -d litellm

# اختبارُ الاستعادةِ بلا مساسٍ بالحيّ — قاعدةٌ مؤقّتةٌ داخلَ الحاويةِ نفسِها
docker compose -p litellm -f deploy/litellm/docker-compose.yml exec -T postgres \
  psql -U litellm -d postgres -c 'CREATE DATABASE restore_test;'
gunzip -c <الملف>.sql.gz | docker compose -p litellm \
  -f deploy/litellm/docker-compose.yml exec -T postgres psql -U litellm -d restore_test
docker compose -p litellm -f deploy/litellm/docker-compose.yml exec -T postgres \
  psql -U litellm -d restore_test -c '\dt'      # جداولٌ موجودة ⇒ النسخةُ سليمة
docker compose -p litellm -f deploy/litellm/docker-compose.yml exec -T postgres \
  psql -U litellm -d postgres -c 'DROP DATABASE restore_test;'
```

> 🔑 **الاستعادةُ بلا مفتاحِ الملحِ نفسِه تُعيد صفوفاً لا تُفَكّ.** النسخةُ
> الكاملةُ = ملفُّ SQL **+** مفتاحُ الملح.

## ٧) الترقيةُ والتراجع

**ترقية:** نسخةٌ احتياطيّة ← تغييرُ الوسمِ **والبصمةِ** في compose ← `pull` ←
`up -d` ← L1–L3.

**تراجعٌ إلى `v1.100.1`:**
```bash
# ① نسخةٌ احتياطيّةٌ أوّلاً — دائماً
# ② بصمةُ الإصدارِ الهدف (تُستعلَم كما في §٢)
# ③ تُكتب في compose ثمّ:
docker compose -p litellm -f deploy/litellm/docker-compose.yml up -d
```

### ماذا يحدث للقاعدةِ عند التراجع؟ — بصراحة

**المجلّدُ لا يُمَسّ**: `down` بلا `-v` يُبقي `litellm_pgdata` كما هو.
**لكنّ هجراتِ LiteLLM تتقدّمُ ولا ترجع**: إن كان `v1.101.0` قد أضاف عموداً أو
جدولاً، فالمخطّطُ يبقى بعد التراجع. وإصدارٌ أقدمُ يقرأ مخطّطاً أحدثَ **قد يعمل
وقد لا يعمل** — ولا تَعِد وثائقُ LiteLLM بتوافقٍ خلفيٍّ للمخطَّط.

**فالتراجعُ الآمنُ درجتان:**
1. **تراجعُ شيفرةٍ فقط** (الأرجحُ نجاحاً بين إصدارين متجاورين): غيّر البصمة ثمّ
   `up -d`. إن أقلع وردّ `/health/liveliness` ⇒ تمّ.
2. **فشل؟ تراجعُ شيفرةٍ + بيانات**: أوقف ← احذف المجلّد ← أعد الإنشاء ←
   استعد النسخةَ المأخوذةَ **قبل** الترقية.
   ```bash
   docker compose -p litellm -f deploy/litellm/docker-compose.yml down
   docker volume rm litellm_pgdata
   docker compose -p litellm -f deploy/litellm/docker-compose.yml up -d postgres
   # ثمّ الاستعادةُ كما في §٦، ثمّ up -d
   ```
   **ولهذا كانت النسخةُ قبلَ الترقيةِ شرطاً لا نصيحة.**

## ٨) التراجعُ عند فشلِ أيِّ مستوى L1–L5

| الفاشل | التشخيصُ أوّلاً | التراجع |
|---|---|---|
| **L1** حاويةٌ غيرُ صحّيّة | `logs --tail=100 litellm` و`postgres` | `down` (بلا `-v`) · الأسبابُ الشائعة: أداةُ الفحصِ غائبةٌ من الصورة (يُعدَّل `healthcheck`) · القاعدةُ لم تجهز (يُرفَع `start_period`) |
| **L2** لا يردّ | `logs` + `docker port litellm-gateway` | `down` · فحصُ تضاربِ المنفذ |
| **L3** ‏401/403 | البوّابةُ حيّةٌ والمفتاحُ مرفوض | تُراجَع قيمةُ `LITELLM_MASTER_KEY` في ملفِّ البيئة ثمّ `up -d --force-recreate litellm` |
| **L4** Hub يرفض الحفظ | حارسُ الصادرِ أو التحقّق | لا تراجعَ على الخادم — إصلاحٌ في Hub |
| **L5** ‏`up === false` | رسالةُ `ConnectionProbe` تفرّق «لم يُجرَّب» عن «فشل» عن «مفتاحٌ مرفوض» | حسب الرسالة |

**والتراجعُ الكاملُ إلى ما قبلَ كلِّ شيء:**
```bash
docker compose -p litellm -f deploy/litellm/docker-compose.yml down -v   # يحذف المجلّد
docker image rm ghcr.io/berriai/litellm:v1.101.0 postgres:16.14-alpine
rm -rf /etc/litellm
```
**ولا يمسّ هذا شيئاً خارجَ مشروعِنا** — لا Hub، ولا `lynomia-backend`، ولا
Webuzo، ولا MariaDB، ولا `postgresql.service`.
