#!/usr/bin/env bash
# ═══ phpstan بنسخةٍ مثبّتةٍ وبصمةٍ محقَّقة — البند #36 و#31 (QE-05) ═══
#
# **لماذا لا `composer require --dev phpstan/phpstan`؟** الحزمةُ على Packagist
# بلا `source`، وأرشيفُها من codeload يعود `403` في بيئةِ البناء (§٤ب في
# docs/TECH_DEBT.md). أمّا ملفُّ `phpstan.phar` من صفحةِ الإصدارات فمتاح —
# فيُنزَّل مرّةً إلى مسارٍ مُهمَل في git، **ويُرفض إن لم تطابق بصمتُه المثبّتةَ
# هنا**: ثنائيٌّ يُنفَّذ على الشيفرةِ لا يُقبل بثقةٍ في الشبكة.
#
# الاستعمال:
#   tools/phpstan.sh                        # التحليل (يسقط عند أوّلِ خطأٍ جديد)
#   tools/phpstan.sh --generate-baseline    # إعادةُ توليدِ خطِّ الأساس — بعد إصلاحٍ فقط
#   PHPSTAN_PHAR=/path/phpstan.phar tools/phpstan.sh   # نسخةٌ محلّيّة (تُفحص بصمتُها كذلك)
#
# ترقيةُ النسخة: غيّر السطرين معاً، واحسب البصمةَ من الملفِّ المُنزَّل نفسِه
# (`sha256sum phpstan.phar`)، وأعِد توليدَ خطِّ الأساسِ في الدفعةِ نفسِها.
set -euo pipefail

PHPSTAN_VERSION="2.2.16"
PHPSTAN_SHA256="1a2fb5460c142502d3cd06272529c18b19fda004ada974bd2581cb9fd6c0a53b"

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cache="$root/tools/.phpstan"
phar="${PHPSTAN_PHAR:-$cache/phpstan-$PHPSTAN_VERSION.phar}"

sha_of() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1
    else shasum -a 256 "$1" | cut -d' ' -f1; fi
}

if [ ! -f "$phar" ]; then
    mkdir -p "$(dirname "$phar")"
    url="https://github.com/phpstan/phpstan/releases/download/$PHPSTAN_VERSION/phpstan.phar"
    echo "↓ phpstan $PHPSTAN_VERSION ← $url" >&2
    curl -fsSL --retry 3 -o "$phar.part" "$url"
    mv "$phar.part" "$phar"
fi

actual="$(sha_of "$phar")"
if [ "$actual" != "$PHPSTAN_SHA256" ]; then
    echo "✗ بصمةُ phpstan.phar لا تطابق النسخةَ المثبّتة $PHPSTAN_VERSION" >&2
    echo "  المتوقَّع: $PHPSTAN_SHA256" >&2
    echo "  الفعليّ:   $actual" >&2
    # الملفُّ المُنزَّل تالفٌ أو مُبدَّل — يُحذف كي لا يُعاد استعمالُه (المحلّيُّ الصريحُ لا يُمسّ)
    [ -z "${PHPSTAN_PHAR:-}" ] && rm -f "$phar"
    exit 1
fi

cd "$root"
exec php -d memory_limit="${PHPSTAN_MEMORY:-4G}" "$phar" analyse \
    --configuration=phpstan.neon --no-progress "$@"
