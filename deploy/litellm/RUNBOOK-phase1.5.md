# كرّاسُ تنفيذِ المرحلة ١٫٥ — من يملك الخادمَ ينفّذ

> **مَن ينفّذ؟** أنت. الجلسةُ التي كتبت هذه الملفّاتِ تعمل في حاويةٍ معزولةٍ
> **بلا `ssh` ولا مفاتيحَ ولا خادمِ Docker** — لا تستطيع بلوغَ
> `server1.lynomia.com` ولم تبلغه قطّ. كلُّ ما عُرف عن الخادمِ في هذا المشروع
> وصل لأنّك لصقتَ مخرجاتِه.
>
> **نطاقُ هذا الكرّاس:** المراحل ١–١٠ حتّى **L3** فقط. **لا L4 ولا L5**، ولا
> ربطَ Hub، ولا مزوّد، ولا نموذج، ولا توليد.
>
> **قاعدةُ التوقّف:** عند أوّلِ فشلٍ غيرِ متوقَّع — **قف**، والصق المخرجات.
> ولا تجرّب إصلاحاً ارتجاليّاً.

## المحظوراتُ المطلقةُ في هذا الكرّاس

`down -v` · حذفُ `pgdata` · حذفُ `/etc/litellm` · `postgresql.service` · بايثون
النظام · MariaDB · Webuzo · الجدارُ الناريّ · إضافةُ `lynomia` إلى مجموعة
`docker` · وأيُّ لمسٍ لـ`lynomia-backend` **ولو للفحص**.

---

## مرحلة ١ — خطُّ الأساس (قبل أيِّ تغيير)

**كـroot.** قراءةٌ محضة، ولا شيءَ يُعدَّل.

```bash
set -uo pipefail
echo "### الوقت";        date -u '+%Y-%m-%dT%H:%M:%SZ (UTC)'
echo "### القرص";        df -h / /var/lib/docker 2>/dev/null | sort -u
echo "### الذاكرة";      free -m
echo "### الحاويات (سردٌ فقط — لا لمس)"
docker ps -a --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}'
echo "### المنفذُ 4000 — يجب أن يكون خالياً"
ss -ltnp | grep ':4000\b' || echo "✅ لا مستمعَ على 4000"
echo "### 5432 — سردٌ للعلمِ لا حاجزٌ (اقرأ الملاحظةَ أدناه)"
ss -ltnp | grep ':5432\b' || echo "(لا مستمع)"
echo "### مجلّدات/شبكاتُ مشروعِنا — يجب ألّا توجد بعد"
docker volume ls  | grep -E 'litellm' || echo "✅ لا مجلّد"
docker network ls | grep -E 'litellm' || echo "✅ لا شبكة"
```

**كـ`lynomia`** (في مستودع Hub — قراءةٌ محضة):

```bash
cd /home/lynomia/public_html/hub-lynomia-com
git rev-parse --short HEAD; git rev-parse --abbrev-ref HEAD; cat VERSION
git status --porcelain | head       # يُتوقَّع: نظيفٌ أو تغييراتٌ تعرفها
```

> **حاجز ①:** **المنفذُ 4000 وحدَه حاجز.** إن كان مشغولاً — **قف**.
>
> **أمّا 5432 على المضيفِ فليس حاجزاً، ولو كان مشغولاً.** قاعدتُنا **لا تنشر
> منفذاً على المضيفِ إطلاقاً** (لا مفتاح `ports:` فيها)، فهي تستمع داخلَ فضائها
> الشبكيِّ الخاصِّ وحدَه، وتُبلَغ بالاسم `litellm-postgres:5432` على
> `litellm_net`. فوجودُ PostgreSQL نظاميٍّ على `127.0.0.1:5432` **لا يتعارض
> ولا يُمَسّ**. (وهذا بالضبط ما اشتُري بقرارِ «لا `ports` للقاعدة».)
> — *تصحيحُ نصٍّ سابقٍ في هذا الكرّاس جعل 5432 حاجزاً؛ وكان خطأً.*

---

## مرحلة ٢ — نقلُ الملفّين ثمّ إنشاءُ `/etc/litellm`

### ٢‑أ · كـ`lynomia` — جلبٌ لا يغيّر `HEAD` ولا شجرةَ العمل

```bash
set -euo pipefail
cd /home/lynomia/public_html/hub-lynomia-com
B=origin/claude/lynomia-hub-enterprise-upgrade-xn4p2t
git fetch origin claude/lynomia-hub-enterprise-upgrade-xn4p2t   # مراجعُ التتبّعِ فقط
mkdir -p /home/lynomia/litellm-stage/config
git show $B:deploy/litellm/docker-compose.yml         > /home/lynomia/litellm-stage/docker-compose.yml
git show $B:deploy/litellm/config/litellm-config.yaml > /home/lynomia/litellm-stage/config/litellm-config.yaml
sha256sum /home/lynomia/litellm-stage/docker-compose.yml \
          /home/lynomia/litellm-stage/config/litellm-config.yaml
git rev-parse --short HEAD && git status --porcelain | head   # لم يتغيّر شيء ✔
```

**البصمتانِ المتوقَّعتان:**
```
8f1743ff5b751b8790ec43e46fdcc0a9b15f9f43e90c87e58f9cd9da7846d9fe  docker-compose.yml
8f1a366583089f1c326c377afb60836dbead06a745159d6d7064ee586a670ba6  config/litellm-config.yaml
```
> **حاجز ②:** بصمةٌ مختلفة ⇒ **قف**. المحتوى ليس ما رُوجِع.

### ٢‑ب · كـroot — التركيبُ والمجلّدات

```bash
set -euo pipefail
install -d -o root -g root -m 0755 /opt/litellm /opt/litellm/config
install -o root -g root -m 0644 /home/lynomia/litellm-stage/docker-compose.yml          /opt/litellm/
install -o root -g root -m 0644 /home/lynomia/litellm-stage/config/litellm-config.yaml  /opt/litellm/config/
sha256sum /opt/litellm/docker-compose.yml /opt/litellm/config/litellm-config.yaml  # يطابق أعلاه

install -d -o root -g root -m 0700 /etc/litellm
install -d -o root -g root -m 0700 /var/backups/litellm
ls -ld /etc/litellm /opt/litellm /var/backups/litellm
```

---

## مرحلة ٣ — توليدُ الأسرار (لا تُطبَع، ولا تدخل `argv`، ولا `history`)

**كـroot، في صدفةٍ واحدة.** كلُّ سطرٍ يكتب مباشرةً بإعادةِ توجيه، و`printf`
بِنيةٌ داخليّةٌ لا عمليّةٌ منفصلة — فلا `argv` يقرؤه `ps`.

```bash
set -euo pipefail
umask 077
PGPW="$(openssl rand -hex 24)"

printf 'POSTGRES_PASSWORD=%s\n' "$PGPW" > /etc/litellm/postgres.env

{ printf 'LITELLM_MASTER_KEY=sk-%s\n' "$(openssl rand -hex 32)"
  printf 'LITELLM_SALT_KEY=%s\n'      "$(openssl rand -hex 32)"
  printf 'DATABASE_URL=postgresql://litellm:%s@litellm-postgres:5432/litellm\n' "$PGPW"
} > /etc/litellm/litellm.env

unset PGPW
chmod 0600 /etc/litellm/*.env
chown root:root /etc/litellm/*.env
```

### 🔑 ٣‑ب · نسخُ مفتاحِ الملحِ — إلزاميٌّ **قبل** أيِّ تشغيل

```bash
grep '^LITELLM_SALT_KEY=' /etc/litellm/litellm.env
```

> **هذه المرّةُ الوحيدةُ التي يُعرَض فيها.** انقله الآن إلى مديرِ كلماتٍ أو
> خزنةٍ **خارجَ الخادم**. بعد إضافةِ أوّلِ نموذجٍ لا سبيلَ لاستعادتِه، وفقدُه =
> فقدُ كلِّ اعتمادِ مزوّدٍ بلا استرجاع. **ولا تلصقه لي ولا في أيِّ محادثة.**

---

## مرحلة ٤ — تحقّقٌ قبلَ التشغيل (بلا كشفِ قيمة)

```bash
set -euo pipefail
CS="docker compose -f /opt/litellm/docker-compose.yml"

echo "### ① الصلاحيّاتُ — لا المحتوى"
ls -l /etc/litellm/           # المتوقَّع: -rw------- root root  لكلِّ ملف
stat -c '%n %a %U:%G' /etc/litellm /etc/litellm/*.env

echo "### ② أنّ الملفَّ صالحٌ — دون طباعةِ أيِّ بيئةٍ محلولة"
$CS config -q && echo "✅ صالحٌ نحويّاً (والأمرُ لا يطبع شيئاً أصلاً)"

echo "### ③ البنيةُ بأسماءِ المتغيّراتِ لا قيمِها"
$CS config --format json | jq '{
  project: .name,
  services: (.services | map_values({
    image, container_name, restart,
    ports: (.ports // "لا منفذ"),
    mem_limit, memswap_limit, cpus,
    depends_on: (.depends_on // "لا"),
    healthcheck: .healthcheck.test,
    logging: .logging.options,
    command,
    أسماءُ_المتغيّرات: ((.environment // {}) | keys)      # ← المفاتيحُ وحدَها
  })),
  networks: (.networks | map_values(.name)),
  volumes:  (.volumes  | map_values(.name))
}'
```

> **حاجز ③ — تحقّق بعينيك قبل المضيّ:** `postgres.ports = "لا منفذ"` ·
> `litellm.ports` يحوي `host_ip: 127.0.0.1` · `mem_limit` ‏`1073741824` و
> `268435456` · لا مفتاحَ `deploy` · `networks = litellm_net` ·
> `volumes = litellm_pgdata` · و**لا قيمةَ سرٍّ في المخرجات**.

---

## مرحلة ٥ — سحبُ الصورِ المثبَّتةِ وحدَها

```bash
set -euo pipefail
CS="docker compose -f /opt/litellm/docker-compose.yml"
$CS pull

echo "### مطابقةُ البصمات"
docker image inspect ghcr.io/berriai/litellm:v1.101.0 --format '{{index .RepoDigests 0}}'
docker image inspect postgres:16.14-alpine            --format '{{index .RepoDigests 0}}'
docker images --format '{{.Repository}}:{{.Tag}} {{.Size}}' | grep -E 'litellm|postgres'
```

**المتوقَّع:**
```
ghcr.io/berriai/litellm@sha256:d295634e09c648dcdb72c4cc2dd226f5fb87823a73e88cbbed6f205e4deb044b
postgres@sha256:57c72fd2a128e416c7fcc499958864df5301e940bca0a56f58fddf30ffc07777
```
> **حاجز ④:** بصمةٌ مختلفة ⇒ **قف فوراً**.

---

## مرحلة ٦ — PostgreSQL وحدَها أوّلاً

```bash
set -euo pipefail
CS="docker compose -f /opt/litellm/docker-compose.yml"
$CS up -d postgres

for i in $(seq 1 40); do
  st=$(docker inspect -f '{{.State.Health.Status}}' litellm-postgres 2>/dev/null || echo missing)
  echo "[$i] $st"; [ "$st" = healthy ] && break; sleep 5
done
```

```bash
echo "### لا منفذَ على المضيف"
docker port litellm-postgres && echo "❌ يوجد منفذ!" || echo "✅ لا مخرجات = لا منفذ منشور"
ss -ltnp | grep ':5432' || echo "✅ لا مستمعَ على 5432"

echo "### الحدودُ الفعليّة"
docker inspect litellm-postgres --format \
  'Memory={{.HostConfig.Memory}} Swap={{.HostConfig.MemorySwap}} NanoCpus={{.HostConfig.NanoCpus}} Restart={{.HostConfig.RestartPolicy.Name}}'
# المتوقَّع: Memory=268435456 Swap=268435456 NanoCpus=250000000 Restart=unless-stopped

echo "### الشبكةُ والمجلّدُ يخصّاننا وحدَنا"
docker inspect litellm-postgres --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}'
docker network inspect litellm_net --format '{{range .Containers}}{{.Name}} {{end}}'
docker volume inspect litellm_pgdata --format '{{.Name}} {{.Mountpoint}}'
```

> **حاجز ⑤:** لم تصل `healthy`، أو ظهر منفذ، أو حدٌّ = صفر، أو ظهرت حاويةٌ
> غريبةٌ في `litellm_net` ⇒ **قف ولا تشغّل LiteLLM**. والصق:
> `docker compose -f /opt/litellm/docker-compose.yml logs --tail=100 postgres`

---

## مرحلة ٧ — LiteLLM

```bash
set -euo pipefail
CS="docker compose -f /opt/litellm/docker-compose.yml"
$CS up -d litellm

# الإقلاعُ الأوّلُ يطبّق ١٦٥ هجرة — امنحه حتّى خمسِ دقائق
for i in $(seq 1 60); do
  st=$(docker inspect -f '{{.State.Health.Status}}' litellm-gateway 2>/dev/null || echo missing)
  echo "[$i] $st"; [ "$st" = healthy ] && break; sleep 5
done
```

```bash
echo "### الربطُ على المضيف"
docker port litellm-gateway                      # المتوقَّع: 4000/tcp -> 127.0.0.1:4000

echo "### أقُتِلت لنفادِ الذاكرة؟ (الخادمُ بلا swap — فالتجاوزُ قتلٌ لا إبطاء)"
docker inspect litellm-gateway --format 'OOMKilled={{.State.OOMKilled}} ExitCode={{.State.ExitCode}} Restarts={{.RestartCount}}'

echo "### الحدودُ والسياساتُ الفعليّة"
docker inspect litellm-gateway --format \
  'Memory={{.HostConfig.Memory}} Swap={{.HostConfig.MemorySwap}} NanoCpus={{.HostConfig.NanoCpus}} Restart={{.HostConfig.RestartPolicy.Name}} Log={{.HostConfig.LogConfig.Type}} {{.HostConfig.LogConfig.Config}}'
# المتوقَّع: Memory=1073741824 Swap=1073741824 NanoCpus=1000000000
#            Restart=unless-stopped Log=json-file map[max-file:3 max-size:10m]
```

> **حاجز ⑥:** لم تصل `healthy`؟ اقرأ السجلَّ **بمرشِّحِ الحجبِ** أدناه (§ملحق)
> قبل لصقِ أيِّ شيء.

---

## مرحلة ٨ — L1 · L2 · L3

```bash
CS="docker compose -f /opt/litellm/docker-compose.yml"

echo "══ L1 ══"; $CS ps

echo "══ L2 ══"
curl -fsS http://127.0.0.1:4000/health/liveliness; echo
curl -fsS http://127.0.0.1:4000/health/readiness; echo
```

```bash
echo "══ L3 ══ (المفتاحُ لا يمرّ في argv)"
set -a; . /etc/litellm/litellm.env; set +a
printf 'url = "http://127.0.0.1:4000/v1/models"\nheader = "Authorization: Bearer %s"\n' \
  "$LITELLM_MASTER_KEY" | curl -fsS --config - ; echo
unset LITELLM_MASTER_KEY LITELLM_SALT_KEY DATABASE_URL POSTGRES_PASSWORD
```

**النجاحُ في L3 = ‏200 وقائمةٌ `data` فارغة.** الفراغُ هو الصواب: لا مزوّدَ في
المرحلة ١٫٥. و**401/403** تعني أنّ البوّابةَ حيّةٌ والمفتاحَ مرفوض — وهي حالةٌ
مختلفةٌ عن «لا تردّ».

---

## مرحلة ٩ — إثباتُ العزل

```bash
echo "① 4000 على loopback وحدَه — ويجب ألّا يظهر 0.0.0.0 ولا :::"
ss -ltnp | grep ':4000'
echo "② 5432 غيرُ منشور"
ss -ltnp | grep ':5432' || echo "✅ لا مستمع"
docker port litellm-postgres || echo "✅ لا منفذ"
```

**ومن جهازك أنت (لا من الخادم):**
```bash
nc -vz -w 5 server1.lynomia.com 4000     # يجب أن يفشل
nc -vz -w 5 server1.lynomia.com 5432     # يجب أن يفشل
```

> لا `firewall`. ولا اختبارَ عبر `lynomia-backend` ولا لمسَ شيءٍ منه.

---

## مرحلة ١٠ — أوّلُ نسخةٍ احتياطيّةٍ + تحقّقٌ غيرُ تدميريّ

```bash
set -euo pipefail
set -o pipefail            # ❗ لولاها لكتب gzip ملفاً صالحاً فارغاً عند فشلِ pg_dump
umask 077
CS="docker compose -f /opt/litellm/docker-compose.yml"
B="/var/backups/litellm/litellm-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"

$CS exec -T postgres pg_dump -U litellm -d litellm --clean --if-exists | gzip > "$B"
echo "حالةُ الخروج: $?"
ls -l "$B"
```

```bash
echo "① غيرُ صفريّ";  test -s "$B" && echo PASS || echo FAIL
echo "② ضغطٌ سليم";  gzip -t "$B" && echo PASS || echo FAIL
echo "③ عددُ الجداول"; gunzip -c "$B" | grep -c '^CREATE TABLE'
echo "④ غيرُ مبتور"
gunzip -c "$B" | tail -2 | grep -q 'PostgreSQL database dump complete' && echo PASS || echo FAIL
```

**والعتبةُ ④ هي الحاسمة:** ضغطٌ سليمٌ لا يثبت أنّ الملفَّ غيرُ مبتور — سطرُ
الختامِ وحدَه يثبته.

> **ولا استعادة.** لا فوق قاعدةِ الإنتاج ولا في قاعدةٍ مؤقّتة. اختبارُ
> الاستعادةِ (§١٢‑ج في `README.md`) ينشئ قاعدةً مؤقّتةً داخلَ الحاويةِ نفسِها،
> **ولا يُنفَّذ إلّا بموافقةٍ مستقلّةٍ عليه**.

---

## تقريرُ الـCheckpoint — أمرٌ واحدٌ يخرج بالحقولِ المطلوبةِ وحدَها

```bash
CS="docker compose -f /opt/litellm/docker-compose.yml"
{
echo "=== ① الحاويات والصحّة ==="
docker inspect litellm-postgres litellm-gateway --format \
 '{{.Name}} | state={{.State.Status}} | health={{if .State.Health}}{{.State.Health.Status}}{{else}}لا فحص{{end}} | restart={{.HostConfig.RestartPolicy.Name}} | OOMKilled={{.State.OOMKilled}} | RestartCount={{.RestartCount}}'

echo "=== ② الصورةُ والبصمةُ الفعليّة ==="
for c in litellm-postgres litellm-gateway; do
  img=$(docker inspect $c --format '{{.Config.Image}}')
  printf '%s -> %s\n' "$c" "$(docker image inspect "$img" --format '{{index .RepoDigests 0}}')"
done

echo "=== ③ حدودُ المعالجِ والذاكرةِ الفعليّة ==="
docker inspect litellm-postgres litellm-gateway --format \
 '{{.Name}} | Memory={{.HostConfig.Memory}} | MemorySwap={{.HostConfig.MemorySwap}} | NanoCpus={{.HostConfig.NanoCpus}} | Log={{.HostConfig.LogConfig.Config}}'

echo "=== ④ المنافذُ (بلا أسرار) ==="
echo "-- litellm-gateway --"; docker port litellm-gateway || echo "لا منفذ"
echo "-- litellm-postgres --"; docker port litellm-postgres || echo "لا منفذ ✅"
ss -ltnp | grep -E ':(4000|5432)\b' || echo "لا مستمعَ على أيٍّ منهما"

echo "=== ⑤ حجمُ pgdata ==="
$CS exec -T postgres du -sh /var/lib/postgresql/data/pgdata 2>/dev/null
docker system df -v 2>/dev/null | grep -E 'litellm_pgdata' || true

echo "=== ⑥ الشبكةُ والمجلّدُ — من فيهما ==="
docker network inspect litellm_net --format 'litellm_net: {{range .Containers}}{{.Name}} {{end}}'

echo "=== ⑦ التحذيراتُ والأخطاء (مع حجبِ الأسرار) ==="
$CS logs --tail=200 2>&1 \
  | grep -iE 'error|warn|fail|exception|traceback' \
  | sed -E 's#(postgresql://[^:]+:)[^@]+@#\1***REDACTED***@#g; s#sk-[A-Za-z0-9_-]{6,}#sk-***REDACTED***#g' \
  | tail -40 || echo "لا تحذيراتٍ في آخرِ ٢٠٠ سطر"

echo "=== ⑧ القرصُ والذاكرةُ الآن (للمقارنةِ بخطِّ الأساس) ==="
df -h / | tail -1; free -m | head -2
} 2>&1
```

> **قبل اللصق:** مرشِّحُ الحجبِ في ⑦ يمسح `DATABASE_URL` ومفاتيحَ `sk-`.
> **وامسح بعينك أيضاً** — لا تلصق سرّاً ولا `DATABASE_URL` كاملاً.

### ملحق · قراءةُ السجلِّ بأمانٍ في أيِّ لحظة

```bash
docker compose -f /opt/litellm/docker-compose.yml logs --tail=120 litellm 2>&1 \
  | sed -E 's#(postgresql://[^:]+:)[^@]+@#\1***REDACTED***@#g; s#sk-[A-Za-z0-9_-]{6,}#sk-***REDACTED***#g'
```

---

## ما تغيّر على الخادمِ بعد هذا الكرّاس — الجردُ الكامل

| المُنشَأ | النوع |
|---|---|
| `/opt/litellm/` + ملفّان | مجلّدٌ جديد · `0755 root:root` |
| `/etc/litellm/` + ملفّا بيئة | مجلّدٌ جديد · `0700` والملفّان `0600 root:root` |
| `/home/lynomia/litellm-stage/` | مجلّدُ مرحلةٍ مؤقّت (يجوز حذفُه بعد المطابقة) |
| `/var/backups/litellm/` + نسخةٌ واحدة | مجلّدٌ جديد · `0700 root:root` |
| صورتان | ≈ ١٫٤ غيغا |
| حاويتان · شبكة `litellm_net` · مجلّد `litellm_pgdata` | جديدةٌ كلُّها |
| مراجعُ تتبّعِ git في مستودع Hub | `fetch` فقط — **لا `HEAD` ولا شجرةُ عمل** |

**ولم يُمَسّ:** Hub · `lynomia-backend` · Webuzo · MariaDB · `postgresql.service`
· الجدارُ الناريّ · بايثون النظام · عضويّاتُ المجموعات.
