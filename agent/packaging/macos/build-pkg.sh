#!/usr/bin/env sh
# build-pkg.sh — بناءُ مثبِّت macOS PKG للوكيل (مسارُ التصحيح §6).
#
#   sh build-pkg.sh <path-to-binary> <version> [out-dir]
#
# يبني PKG **غيرَ موقَّع** عبر pkgbuild/productbuild إن توفّرت الأدوات (ماك)، ثم:
#   • Developer ID   — يوقّع **فقط** حين يحضر APPLE_DEVELOPER_ID_P12،
#   • notarization   — يوثّق **فقط** حين يحضر APPLE_NOTARY_KEY،
# وإلا NOT_CONFIGURED صادقاً بلا ادّعاء (C15). حيث لا pkgbuild (منصّةُ CI غيرُ ماك)
# يتخطّى بلطفٍ بلا فشل.
set -eu

BIN="${1:?الاستعمال: build-pkg.sh <path-to-binary> <version> [out-dir]}"
VERSION="${2:?رقمُ النسخة مطلوب}"
OUT="${3:-dist}"
HERE="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"

mkdir -p "$OUT"
PKG="$OUT/lynomia-agent-$VERSION.pkg"

if ! command -v pkgbuild >/dev/null 2>&1 || ! command -v productbuild >/dev/null 2>&1; then
	echo "ℹ pkgbuild/productbuild غيرُ متوفّرٍ على هذا المضيف — بناءُ PKG يجري على مضيف macOS."
	echo "  الأصولُ صالحة: $HERE/lynomia-agent.plist + $HERE/scripts/postinstall (يسجّلان الخدمةَ launchd)."
	exit 0
fi

echo "▶ تجهيزُ جذر التثبيت من: $BIN (النسخة $VERSION)"
ROOT="$(mktemp -d)"
mkdir -p "$ROOT/usr/local/lynomia" "$ROOT/Library/LaunchDaemons"
cp "$BIN" "$ROOT/usr/local/lynomia/lynomia-agent"
chmod 0755 "$ROOT/usr/local/lynomia/lynomia-agent"
cp "$HERE/lynomia-agent.plist" "$ROOT/Library/LaunchDaemons/com.lynomia.endpoint-agent.plist"

echo "▶ بناءُ PKG غيرِ موقَّع"
pkgbuild --root "$ROOT" \
	--identifier com.lynomia.endpoint-agent \
	--version "$VERSION" \
	--scripts "$HERE/scripts" \
	--install-location / \
	"$OUT/lynomia-agent-component.pkg"
productbuild --package "$OUT/lynomia-agent-component.pkg" "$PKG"
echo "✅ PKG: $PKG (UNSIGNED DEVELOPMENT BUILD)"

# ── Developer ID — لا يُشغَّل إلا بشهادةٍ حاضرة ──
if [ -n "${APPLE_DEVELOPER_ID_P12:-}" ]; then
	echo "⚠ سرُّ Developer ID حاضرٌ لكنّ جسرَ productsign/التحقّق غيرُ موصولٍ بعدُ — يبقى unsigned بصدق."
else
	echo "🔓 developer-id: NOT_CONFIGURED — لا شهادةَ Apple Developer ID."
fi

# ── notarization (notarytool) — لا يُشغَّل إلا بمفتاحٍ حاضر ──
if [ -n "${APPLE_NOTARY_KEY:-}" ]; then
	echo "⚠ مفتاحُ notarytool حاضرٌ لكنّ جسرَ التوثيق/الانتظار غيرُ موصولٍ بعدُ — يبقى غيرَ موثَّق بصدق."
else
	echo "🔓 notarization: NOT_CONFIGURED — لا مفتاحَ notarytool؛ التحقّقُ عبر SHA-256."
fi

if command -v shasum >/dev/null 2>&1; then
	shasum -a 256 "$PKG"
fi
rm -rf "$ROOT"
