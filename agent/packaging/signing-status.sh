#!/usr/bin/env sh
# signing-status.sh — تقريرُ حالةِ توقيعٍ **صادقٌ** لقنوات التوزيع الثلاث
# (مسارُ التصحيح §6). يقرأ أسرارَ التوقيع من البيئة، ويُعلن NOT_CONFIGURED حين
# تغيب — **ولا يزعم «SIGNED» أبداً** بلا شهادةٍ حاضرة. مصدرُ الحقيقة الوحيد
# الذي يستدعيه CI (خطوةُ الوكيل) وتنطبق عليه اختباراتُ التقسية.
#
# القنواتُ الثلاث:
#   • Windows Authenticode (OV/EV)  ← WINDOWS_CODESIGN_PFX
#   • macOS Developer ID            ← APPLE_DEVELOPER_ID_P12
#   • macOS notarization (notarytool) ← APPLE_NOTARY_KEY (+ Developer ID)
#
# القاعدةُ (C15): «signed»/«notarized» لا يُطبَعان إلا حين يحضر السرُّ **و**
# يُتحقَّق من التوقيع فعلاً (خطوةٌ لاحقةٌ حين يُوصَل الجسر). غيابُ السرّ = NOT_CONFIGURED
# صريحٌ لا سقوطَ ولا تمثيل. يخرج 0 دوماً (تقريرٌ لا بوّابة).
set -eu

channel_status() {
	# $1 اسمُ القناة · $2 قيمةُ السرّ · $3 وصفٌ عربيّ
	if [ -n "${2:-}" ]; then
		# سرٌّ حاضرٌ لكنّ جسرَ التوقيع/التحقّق غيرُ موصولٍ بعدُ: لا نزعم «signed»
		printf '  %-22s CONFIGURED_SECRET_PRESENT (جسرُ التوقيع/التحقّق غيرُ موصولٍ بعدُ — يبقى unsigned بصدق)\n' "$1:"
	else
		printf '  %-22s NOT_CONFIGURED (%s)\n' "$1:" "$3"
	fi
}

echo "── حالةُ توقيع مثبِّتات ووكيل النقاط الطرفية (صادقةٌ — C15) ──"
channel_status "authenticode" "${WINDOWS_CODESIGN_PFX:-}" "لا شهادةَ Windows Authenticode (OV/EV) مُهيّأة"
channel_status "developer-id"  "${APPLE_DEVELOPER_ID_P12:-}" "لا شهادةَ Apple Developer ID مُهيّأة"
channel_status "notarization"  "${APPLE_NOTARY_KEY:-}" "لا مفتاحَ notarytool مُهيّأ"
echo "الخلاصة: UNSIGNED DEVELOPMENT BUILD — التحقّقُ عبر SHA-256 المنشورة لا عبر توقيعِ منصّة."
