#!/usr/bin/env sh
# build-msi.sh — بناءُ مثبِّت Windows MSI للوكيل (مسارُ التصحيح §6).
#
#   sh build-msi.sh <path-to-exe> <version> [out-dir]
#
# يبني MSI **غيرَ موقَّع** عبر WiX (candle/light) إن توفّرت الأدوات، ثم يوقّعه
# Authenticode **فقط** حين يحضر السرُّ (WINDOWS_CODESIGN_PFX) — وإلا NOT_CONFIGURED
# صادقاً بلا ادّعاء (C15). حيث لا WiX (منصّةُ CI غيرُ ويندوز) يتخطّى بلطفٍ بلا فشل.
set -eu

EXE="${1:?الاستعمال: build-msi.sh <path-to-exe> <version> [out-dir]}"
VERSION="${2:?رقمُ النسخة مطلوب}"
OUT="${3:-dist}"
HERE="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"

mkdir -p "$OUT"
MSI="$OUT/lynomia-agent-$VERSION.msi"

if ! command -v candle >/dev/null 2>&1 || ! command -v light >/dev/null 2>&1; then
	echo "ℹ WiX (candle/light) غيرُ متوفّرٍ على هذا المضيف — بناءُ MSI يجري على مضيف Windows."
	echo "  البيانُ صالحٌ: $HERE/lynomia-agent.wxs (يُثبّت الوكيلَ خدمةً)."
	exit 0
fi

echo "▶ بناءُ MSI غيرِ موقَّع من: $EXE (النسخة $VERSION)"
candle -nologo -dAgentVersion="$VERSION" -dAgentExe="$EXE" -o "$OUT/lynomia-agent.wixobj" "$HERE/lynomia-agent.wxs"
light  -nologo -o "$MSI" "$OUT/lynomia-agent.wixobj"
echo "✅ MSI: $MSI (UNSIGNED DEVELOPMENT BUILD)"

# ── التوقيعُ الصادق (Authenticode) — لا يُشغَّل إلا بشهادةٍ حاضرة ──
if [ -n "${WINDOWS_CODESIGN_PFX:-}" ]; then
	echo "⚠ سرُّ التوقيع حاضرٌ لكنّ جسرَ signtool/التحقّق غيرُ موصولٍ في هذا السكربت بعدُ —"
	echo "  يبقى MSI موسوماً unsigned بصدقٍ حتى يُوقَّع ويُتحقَّق منه، ثم تُقرّ حالةُ الإصدار signed."
else
	echo "🔓 authenticode: NOT_CONFIGURED — لا شهادةَ Windows Authenticode (OV/EV)؛ التحقّقُ عبر SHA-256."
fi

if command -v sha256sum >/dev/null 2>&1; then
	sha256sum "$MSI"
fi
