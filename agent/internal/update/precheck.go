// precheck — بوّابةُ الترقية الآمنة **قبل** التنزيل والتبديل (§7).
//
// عقدُ `Apply` يضمن ألّا تُبدَّل الثنائيّةُ إلا بتجزئةٍ مطابقة — لكنّه لا يعرف
// **أيَّ** نسخةٍ نحن عليها. هنا يُضاف الحارسُ الدلاليّ للتحديث الذاتيّ:
//
//   - **لا تنازل (no-downgrade):** بيانٌ بنسخةٍ أقدمَ من الحالية يُرفَض — لا
//     يُعاد الوكيلُ إلى الخلف آلياً (هجومُ إرجاعٍ أو خطأُ طرحٍ مرحليّ).
//   - **لا عملَ على المطابق (up-to-date):** بيانٌ بنفس النسخة = لا شيء ليُفعَل —
//     `ErrUpToDate` (نجاحٌ صامتٌ لا خطأ) كي لا يُنزَّل ويُبدَّل بلا داعٍ كلَّ دورة.
//   - **جسرُ الترقية (bridge):** إن حدّد البيانُ `MinAgentVersion` وكانت نسختُنا
//     أقدمَ منها، فالقفزُ المباشرُ غيرُ آمنٍ (هجراتُ حالةٍ متتابعة مثلاً) — يُرفَض
//     بطلبِ الترقية عبر نسخةٍ وسيطة، لا بتبديلٍ أعمى.
//
// المقارنةُ semver مبسّطةٌ (major.minor.patch): لاحقةُ ما-قبل-الإصدار (مثل
// `-rc.1`) تجعل النسخةَ **أدنى** من نظيرتها دون لاحقة (1.2.0-rc < 1.2.0) — كافٍ
// لحارس الترقية، وحتميٌّ لا يعتمد على تبعيّةٍ خارجية (الوكيلُ بلا اعتماداتٍ خارج
// golang.org/x/sys).
package update

import (
	"errors"
	"strconv"
	"strings"
)

// ErrUpToDate — البيانُ لا يقدّم نسخةً أحدثَ: لا تنزيلَ ولا تبديل (نجاحٌ صامت).
var ErrUpToDate = errors.New("الوكيلُ محدَّثٌ سلفاً — لا نسخةَ أحدثَ في البيان")

// Precheck — يقرّر هل يُطبَّق هذا البيانُ على وكيلٍ نسختُه `current`.
//
// يعيد nil حين يجب المتابعةُ إلى Apply؛ و`ErrUpToDate` حين لا جديدَ (يعامله
// المتصلُ نجاحاً)؛ وخطأً صريحاً على التنازل أو حين يلزم جسرُ ترقية.
func Precheck(current string, m Manifest) error {
	// بيانٌ بلا نسخةٍ معلنة: لا حكمَ دلاليّاً ممكناً — نترك القرارَ لعقد التجزئة
	// في Apply (توافقٌ رجعيٌّ مع بياناتٍ قديمةٍ لا تحمل version).
	want := strings.TrimSpace(m.Version)
	if want == "" {
		return nil
	}

	cur := strings.TrimSpace(current)
	if cur != "" {
		switch compareVersions(want, cur) {
		case 0:
			return ErrUpToDate // نفسُ النسخة — لا عمل
		case -1:
			return errors.New("رفضُ تنازل: نسخةُ البيان " + want + " أقدمُ من الحالية " + cur)
		}
	}

	// جسرُ الترقية: نسختُنا أقدمُ من الأدنى المتوافق ⇒ لا قفزَ مباشر
	if min := strings.TrimSpace(m.MinAgentVersion); min != "" && cur != "" {
		if compareVersions(cur, min) < 0 {
			return errors.New("جسرُ ترقيةٍ مطلوب: النسخةُ الحالية " + cur +
				" أقدمُ من الأدنى المتوافق " + min + " — رقِّ عبر نسخةٍ وسيطةٍ أولاً")
		}
	}

	return nil
}

// compareVersions — يعيد ‑1 إن a<b، و0 إن تساوتا (دلاليّاً)، و+1 إن a>b.
// يقارن major.minor.patch عدديّاً، ثم يعدّ وجودَ لاحقةِ ما-قبل-الإصدار أدنى.
func compareVersions(a, b string) int {
	an, apre := splitSemver(a)
	bn, bpre := splitSemver(b)
	for i := 0; i < 3; i++ {
		if an[i] != bn[i] {
			if an[i] < bn[i] {
				return -1
			}
			return 1
		}
	}
	// النواةُ متساوية: بلا لاحقةٍ أعلى من ذاتِ لاحقةٍ (1.2.0 > 1.2.0-rc)
	switch {
	case apre == bpre:
		return 0
	case apre && !bpre:
		return -1
	case !apre && bpre:
		return 1
	default:
		// كلاهما ذو لاحقة: مقارنةٌ نصّيّةٌ حتميّة (كافيةٌ لحارس الترقية)
		return strings.Compare(a, b)
	}
}

// splitSemver — يفكّك "1.2.3-rc.1" إلى [1,2,3] ووجودِ لاحقة. المكوّناتُ غيرُ
// العدديّة أو الغائبةُ تُقرأ صفراً (متسامحٌ مع صيغٍ ناقصةٍ لا يكسر الحارس).
func splitSemver(v string) ([3]int, bool) {
	var out [3]int
	v = strings.TrimSpace(v)
	pre := false
	// اقتطاعُ لاحقةِ ما-قبل-الإصدار عند أوّل '-' أو '+'
	if i := strings.IndexAny(v, "-+"); i >= 0 {
		pre = true
		v = v[:i]
	}
	parts := strings.Split(v, ".")
	for i := 0; i < 3 && i < len(parts); i++ {
		n, err := strconv.Atoi(strings.TrimSpace(parts[i]))
		if err == nil && n >= 0 {
			out[i] = n
		}
	}
	return out, pre
}
