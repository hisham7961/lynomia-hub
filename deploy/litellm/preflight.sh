#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# فحصُ جاهزيّةِ الخادم لتشغيل بوّابة LiteLLM — **قراءةٌ فقط، لا يغيّر شيئاً**.
#
# لماذا سكربتٌ لا ادّعاء: بيئةُ التطوير التي كُتب فيها هذا الدمجُ **معزولةٌ عن
# خادمِك** ولا تصل إليه، فلا يصحّ أن يُقال «Docker متاح» أو «systemd يعمل» دون
# تشغيلٍ على الخادمِ نفسِه. وVPS لا يعني بالضرورة صلاحيّةَ root ولا إمكانَ
# Docker: لوحاتُ التحكّم (Webuzo · cPanel · غيرُهما) تختلف في دعمِها لتشغيلِ
# Docker على الخادمِ نفسِه، وبعضُها على CloudLinux + CageFS يحجب الرؤية.
# **ولا يُفترَض شيءٌ عن لوحتِك من اسمِها** — هذا الفحصُ يحسم بالدليل.
#
# التشغيل (على خادمِ Hub، عبر SSH):
#     bash deploy/litellm/preflight.sh
#
# ثمّ انسخ المخرجاتِ كما هي — بها يُختار وضعُ التشغيل.
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

ok()   { printf '  ✅ %s\n' "$*"; }
no()   { printf '  ❌ %s\n' "$*"; }
warn() { printf '  ⚠️  %s\n' "$*"; }
hdr()  { printf '\n\033[1m%s\033[0m\n' "$*"; }

printf '═══ فحصُ جاهزيّةِ الخادم لِـLiteLLM ═══\n'
printf 'التاريخ: %s\nالمضيف: %s\n' "$(date -u '+%Y-%m-%d %H:%M UTC')" "$(hostname 2>/dev/null || echo '?')"

hdr '١) الهويّةُ والصلاحيّة'
printf '  المستخدم: %s (uid=%s)\n' "$(id -un 2>/dev/null)" "$(id -u 2>/dev/null)"
if [ "$(id -u)" = "0" ]; then ok 'root — صلاحيّةٌ كاملة'
elif sudo -n true 2>/dev/null; then ok 'sudo بلا كلمةِ مرور'
elif command -v sudo >/dev/null 2>&1; then warn 'sudo موجودٌ ويطلب كلمةَ مرور — أعِد التشغيلَ بـ sudo'
else no 'لا root ولا sudo — التنصيبُ كخدمةِ نظامٍ متعذّر'; fi

hdr '٢) نظامُ التشغيلِ ولوحةُ الإدارة'
[ -r /etc/os-release ] && . /etc/os-release && printf '  التوزيعة: %s %s\n' "${NAME:-?}" "${VERSION_ID:-}"
# اللوحاتُ المعروفة — وغيابُ واحدةٍ ليس عطلاً، بل خبرٌ عن أيُّها يُدير
if [ -e /usr/local/cpanel/version ]; then ok "cPanel: $(cat /usr/local/cpanel/version 2>/dev/null)"
elif [ -d /usr/local/webuzo ]; then ok "Webuzo: $(cat /usr/local/webuzo/version 2>/dev/null || echo 'مثبَّت')"
elif [ -d /usr/local/cwpsrv ]; then ok 'CWP مثبَّت'
elif [ -d /usr/local/psa ]; then ok 'Plesk مثبَّت'
else printf '  لا لوحةَ تحكّمٍ معروفةً — إدارةٌ مباشرة\n'; fi
if [ -e /etc/cloudlinux-release ] || grep -qi cloudlinux /etc/os-release 2>/dev/null; then
    warn 'CloudLinux — CageFS قد يحجب رؤيةَ العمليّات والمسارات لمستخدمي cPanel'
    command -v cagefsctl >/dev/null 2>&1 && printf '    cagefsctl موجود\n'
fi

hdr '٣) systemd'
if [ -d /run/systemd/system ]; then
    ok "systemd يعمل — $(systemctl --version 2>/dev/null | head -1)"
    if systemctl list-units --type=service >/dev/null 2>&1; then ok 'يمكن الاستعلامُ عن الوحدات'
    else warn 'systemctl لا يستجيب لهذا المستخدم — يلزم root'; fi
else no 'systemd غيرُ نشط (قد تكون حاويةَ OpenVZ/LXC) — لا خدمةَ دائمةً بهذه الطريقة'; fi

hdr '٤) Docker'
if command -v docker >/dev/null 2>&1; then
    printf '  ثُنائيّة: %s\n' "$(docker --version 2>/dev/null)"
    if docker info >/dev/null 2>&1; then ok 'العفريتُ يعمل والمستخدمُ يصل إليه'
    else warn 'docker مثبَّتٌ ولا يُوصَل إليه (العفريتُ متوقّفٌ أو تنقص الصلاحيّة)'; fi
    docker compose version >/dev/null 2>&1 && ok "compose: $(docker compose version --short 2>/dev/null)" \
        || warn 'docker compose (v2) غيرُ متاح'
else
    no 'Docker غيرُ مثبَّت'
    [ -e /usr/local/cpanel/version ] && warn 'وعلى cPanel تحديداً: تنصيبُه غيرُ مدعومٍ رسميّاً'
fi

hdr '٥) Python (يلزم LiteLLM ≥ 3.8؛ والموصى 3.11+)'
for p in python3.13 python3.12 python3.11 python3.10 python3; do
    command -v "$p" >/dev/null 2>&1 && printf '  %s → %s\n' "$p" "$("$p" --version 2>&1)"
done
command -v python3 >/dev/null 2>&1 || no 'لا python3'
python3 -c 'import venv' 2>/dev/null && ok 'وحدةُ venv متاحة' || warn 'venv غيرُ متاحة — قد تلزم حزمةُ python3-venv'
command -v pip3 >/dev/null 2>&1 && ok "pip3: $(pip3 --version 2>&1 | cut -d' ' -f2)" || warn 'لا pip3'

hdr '٦) الذاكرةُ والقرص'
free -h 2>/dev/null | awk 'NR<=2{printf "  %s\n",$0}' || printf '  free غيرُ متاح\n'
df -h / 2>/dev/null | awk 'NR<=2{printf "  %s\n",$0}'
printf '  الأنوية: %s\n' "$(nproc 2>/dev/null || echo '?')"

hdr '٧) المنافذُ الداخليّة (البوّابةُ لا تُكشَف للإنترنت)'
for port in 4000 4001 8000; do
    if (command -v ss >/dev/null 2>&1 && ss -ltn 2>/dev/null | grep -q ":${port}\b") \
    || (command -v netstat >/dev/null 2>&1 && netstat -ltn 2>/dev/null | grep -q ":${port}\b"); then
        warn "المنفذ ${port} مشغولٌ سلفاً"
    else ok "المنفذ ${port} حرّ"; fi
done
printf '  ملاحظة: البوّابةُ تُربَط بـ 127.0.0.1 حصراً — فلا قاعدةَ جدارٍ ناريٍّ تُفتَح.\n'

hdr '٨) قاعدةُ بياناتِ البوّابة — **متطلّبُ المرحلة ٢، يُفحَص الآن**'
printf '  إدارةُ المزوّدين والنماذجِ من الواجهةِ **بصورةٍ دائمة** تحتاج قاعدةً\n'
printf '  (DATABASE_URL / PostgreSQL). وبلا قاعدةٍ يعمل الوكيلُ من config.yaml\n'
printf '  وحدَه، فما يُدخَل من الواجهةِ يضيع عند إعادةِ التشغيل.\n'
if command -v psql >/dev/null 2>&1; then ok "عميل PostgreSQL: $(psql --version 2>&1 | awk '{print $3}')"
else warn 'لا عميلَ psql'; fi
# **خادمٌ** لا عميلٌ فقط — العميلُ وحدَه لا يخزّن شيئاً
if (command -v ss >/dev/null 2>&1 && ss -ltn 2>/dev/null | grep -q ':5432\b') \
|| (command -v netstat >/dev/null 2>&1 && netstat -ltn 2>/dev/null | grep -q ':5432\b'); then
    ok 'خادمُ PostgreSQL يستمع على 5432'
elif command -v pg_isready >/dev/null 2>&1 && pg_isready -q 2>/dev/null; then ok 'pg_isready: الخادمُ يستجيب'
else warn 'لا خادمَ PostgreSQL مستمعاً — يلزم تنصيبُه قبل المرحلة ٢ (أو قبول ضياعِ ما يُدخَل من الواجهة)'; fi
command -v mysql >/dev/null 2>&1 && printf '  (mysql عميل: %s — لا يصلح بديلاً لمتطلّبِ LiteLLM)\n' "$(mysql --version 2>&1 | awk '{print $3}')"

hdr '٩) الخروجُ إلى الإنترنت (يلزم للوصولِ إلى المزوّدين)'
if command -v curl >/dev/null 2>&1; then
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 8 https://pypi.org/simple/ 2>/dev/null)
    [ "$code" = "200" ] && ok 'HTTPS صادرٌ يعمل (pypi.org)' || warn "HTTPS صادرٌ متعذّرٌ أو محجوب (code=$code)"
else warn 'curl غيرُ موجود'; fi

hdr '═══ الخلاصة ═══'
printf '  انسخ كلَّ ما سبق. القراءة:\n'
printf '   • root/sudo + Docker يعمل            ⇒ وضعُ compose (deploy/litellm/docker-compose.yml)\n'
printf '   • root/sudo + systemd بلا Docker     ⇒ وضعُ systemd + venv\n'
printf '   • لا root ولا systemd                ⇒ لا بوّابةَ على هذا الخادم — بوّابةٌ مُدارةٌ أو خادمٌ ثانٍ\n'
printf '\n  ولا شيءَ مِن هذا يُعطّل المرحلةَ الأولى: Hub يكلّم البوّابةَ عبر HTTP\n'
printf '  أيّاً كان مقرُّها، والمركزُ يقول بصراحة «البوّابةُ غيرُ مهيّأة» حتّى تُهيَّأ.\n'
printf '\n  ⚠️  وهذا الفحصُ **للقراءةِ فقط**: لم يُنصَّب شيءٌ ولم يُغيَّر إعدادٌ.\n'
printf '      ونجاحُه يقول إنّ المقوّماتِ حاضرةٌ — **لا إنّ الخدمةَ تعمل**.\n'
printf '      والدليلُ على التشغيل وحدَه: فحصُ الاتصالِ من داخلِ مركزِ Hub.\n'
