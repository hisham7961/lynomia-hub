#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# جردُ الحالةِ قبل خطّةِ التشغيل — **قراءةٌ فقط، لا يغيّر شيئاً**.
#
# لا install · لا update · لا upgrade · لا remove · لا restart/start/stop
# لا enable/disable · لا chmod/chown · لا firewall · لا CREATE/ALTER/DROP
# لا git reset/clean/checkout.
#
# التشغيل:  bash inspect.sh 2>&1 | tee /tmp/litellm-inspect.txt
# وبعضُ الأقسامِ يطلب sudo للاستعلامِ فقط — تُكتب كلمةُ المرورِ مرّةً.
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail
hdr() { printf '\n\033[1m━━━ %s ━━━\033[0m\n' "$*"; }
run() { printf '\n$ %s\n' "$*"; eval "$@" 2>&1 | sed 's/^/  /'; }

printf '═══ جردُ الحالة — %s — %s ═══\n' "$(hostname 2>/dev/null)" "$(date -u '+%Y-%m-%d %H:%M UTC')"

hdr 'أ) Docker — أحيٌّ العفريتُ أم مسألةُ صلاحيّة؟'
run 'systemctl is-active docker 2>&1; systemctl is-enabled docker 2>&1'
run 'sudo systemctl status docker --no-pager -l 2>&1 | head -20'
run 'ls -l /var/run/docker.sock 2>&1'
run 'id -nG 2>&1'
run 'sudo docker info --format "Server={{.ServerVersion}} Driver={{.Driver}} Root={{.DockerRootDir}} Containers={{.Containers}} Running={{.ContainersRunning}}" 2>&1'
run 'sudo docker ps -a --format "{{.Names}}\t{{.Image}}\t{{.Status}}" 2>&1 | head -20'
run 'sudo docker network ls 2>&1 | head'

hdr 'ب) Python — هل يوجد ‎3.10+ دون لمسِ python3 النظام؟'
run 'python3 -V 2>&1; readlink -f "$(command -v python3)" 2>&1'
run 'ls -1 /usr/bin/python3.* /usr/local/bin/python3.* 2>/dev/null'
run 'for v in 3.10 3.11 3.12 3.13; do command -v python$v >/dev/null && echo "python$v: $(python$v -V 2>&1)"; done'
run 'dnf list --available "python3.1*" 2>/dev/null | head -20'
run 'python3 -m ensurepip --version 2>&1 | head -2'
run 'ls -d /opt/alt/python3* /opt/python3* 2>/dev/null'

hdr 'ج) PostgreSQL — الخادمُ الحقيقيّ ومَن يديره'
run 'psql --version 2>&1'
run 'sudo ss -ltnp 2>&1 | grep -E ":5432|postgres" '
run 'systemctl list-units --type=service --all 2>&1 | grep -iE "postgres|pgsql"'
run 'sudo systemctl status postgresql --no-pager -l 2>&1 | head -15'
run 'sudo -u postgres psql -tAc "select version();" 2>&1 | head -2'
run 'sudo -u postgres psql -tAc "show data_directory;" 2>&1'
run 'sudo -u postgres psql -tAc "show listen_addresses;" 2>&1'
run 'sudo -u postgres psql -tAc "show port;" 2>&1'
run 'sudo -u postgres psql -tAc "show hba_file;" 2>&1'
# pg_hba بلا أسرار: النوعُ والقاعدةُ والمستخدمُ والعنوانُ وطريقةُ المصادقة فقط
run 'sudo -u postgres psql -tAF"|" -c "select type,database,user_name,address,auth_method from pg_hba_file_rules order by line_number;" 2>&1 | head -25'
run 'sudo -u postgres psql -tAF"|" -c "select datname, pg_size_pretty(pg_database_size(datname)) from pg_database where not datistemplate order by 1;" 2>&1'
run 'sudo -u postgres psql -tAF"|" -c "select rolname, rolsuper, rolcreatedb, rolcreaterole, rolcanlogin from pg_roles where rolname not like \"pg\\_%\" order by 1;" 2>&1'
run 'sudo -u postgres psql -tAc "select count(*) from pg_stat_activity;" 2>&1'
run 'sudo -u postgres psql -tAF"|" -c "select name,setting from pg_settings where name in (\"max_connections\",\"shared_buffers\",\"work_mem\",\"server_version\");" 2>&1'
run 'ps -o pid,rss,etime,cmd -C postgres 2>&1 | head -8'

hdr 'د) هل Webuzo هو مَن يدير PostgreSQL؟'
run 'ls -d /usr/local/webuzo 2>&1; cat /usr/local/webuzo/version 2>/dev/null'
run 'ls -1 /usr/local/webuzo/ 2>/dev/null | head -20'
run 'rpm -q postgresql-server postgresql 2>&1'
run 'rpm -qi postgresql-server 2>&1 | grep -E "^(Name|Version|Vendor|Build Date|Packager)" '
run 'ls -d /usr/local/webuzo/*postgre* /usr/local/apps/*postgre* 2>/dev/null'

hdr 'هـ) الأمنُ والعزل — SELinux والجدار'
run 'getenforce 2>&1; sestatus 2>&1 | head -5'
run 'sudo firewall-cmd --state 2>&1; sudo firewall-cmd --list-all 2>&1 | head -20'
run 'sudo iptables -S 2>&1 | head -20'

hdr 'و) الموارد — لقطةٌ أدقّ'
run 'free -m 2>&1'
run 'nproc 2>&1; uptime 2>&1'
run 'df -h / /home 2>&1'
run 'ps -eo pid,pmem,rss,comm --sort=-rss 2>&1 | head -12'
run 'systemd-detect-virt 2>&1'

hdr 'ز) مستودعُ الإنتاج — قراءةٌ فقط'
cd /home/lynomia/public_html/hub-lynomia-com 2>/dev/null || { echo "  !! المسارُ غيرُ موجود"; exit 0; }
run 'pwd; git rev-parse --abbrev-ref HEAD; git rev-parse HEAD'
run 'cat VERSION 2>&1'
run 'git status --porcelain 2>&1 | head -20'
run 'ls -la public/storage 2>&1'
run 'readlink -f public/storage 2>&1'
run 'git log --oneline -5 2>&1'
run 'git remote -v 2>&1'
# هل وصلت المرحلةُ ١؟ — بلا أيِّ تعديل
run 'git cat-file -e 7a44550 2>&1 && echo "7a44550 معروفٌ محلّيّاً" || echo "7a44550 غيرُ معروفٍ محلّيّاً (يلزم git fetch)"'
run 'git merge-base --is-ancestor 7a44550 HEAD 2>/dev/null && echo "المرحلةُ ١ ضمن ancestry" || echo "المرحلةُ ١ ليست ضمن ancestry"'
run 'ls -l deploy/litellm/preflight.sh app/Support/AiGateway.php 2>&1'

printf '\n\033[1m═══ انتهى الجرد — قراءةٌ فقط، لم يُغيَّر شيء ═══\033[0m\n'
