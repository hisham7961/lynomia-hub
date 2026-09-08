package update

import (
	"errors"
	"testing"
)

// الترقيةُ العادية إلى أحدثَ: يمرّ (nil) ⇒ يُتابَع إلى Apply.
func TestPrecheckAllowsNewerVersion(t *testing.T) {
	if err := Precheck("0.2.0", Manifest{Version: "0.3.0"}); err != nil {
		t.Fatalf("ترقيةٌ إلى أحدثَ رُفضت: %v", err)
	}
}

// **رفضُ التنازل:** بيانٌ بنسخةٍ أقدمَ لا يُطبَّق — لا إرجاعَ آليّ للخلف.
func TestPrecheckRefusesDowngrade(t *testing.T) {
	err := Precheck("0.3.0", Manifest{Version: "0.2.0"})
	if err == nil {
		t.Fatal("تنازلٌ قُبل — إرجاعٌ أعمى للخلف")
	}
	if errors.Is(err, ErrUpToDate) {
		t.Fatalf("التنازلُ صُنّف «محدَّثٌ سلفاً»: %v", err)
	}
}

// **لا عملَ على المطابق:** نفسُ النسخة ⇒ ErrUpToDate (نجاحٌ صامتٌ لا تبديل).
func TestPrecheckSameVersionIsUpToDate(t *testing.T) {
	err := Precheck("1.4.0", Manifest{Version: "1.4.0"})
	if !errors.Is(err, ErrUpToDate) {
		t.Fatalf("نفسُ النسخة لم تُصنَّف ErrUpToDate: %v", err)
	}
}

// **جسرُ الترقية:** نسختُنا أقدمُ من الأدنى المتوافق ⇒ يُرفَض القفزُ المباشر.
func TestPrecheckRequiresBridgeWhenBelowMinAgent(t *testing.T) {
	err := Precheck("0.1.0", Manifest{Version: "2.0.0", MinAgentVersion: "1.0.0"})
	if err == nil {
		t.Fatal("قفزٌ مباشرٌ من دون جسرِ ترقيةٍ قُبل")
	}
	if errors.Is(err, ErrUpToDate) {
		t.Fatalf("جسرُ الترقية صُنّف «محدَّثٌ سلفاً»: %v", err)
	}
	// وعند بلوغِ الأدنى (أو تجاوزِه) يمرّ الجسرُ عادياً
	if err := Precheck("1.0.0", Manifest{Version: "2.0.0", MinAgentVersion: "1.0.0"}); err != nil {
		t.Fatalf("النسخةُ عند الأدنى المتوافق رُفضت: %v", err)
	}
}

// بيانٌ قديمٌ بلا حقل version: توافقٌ رجعيّ — يمرّ ويقرّر عقدُ التجزئة في Apply.
func TestPrecheckPassesWhenManifestHasNoVersion(t *testing.T) {
	if err := Precheck("0.2.0", Manifest{URL: "https://x/y", SHA256: "abc"}); err != nil {
		t.Fatalf("بيانٌ بلا version رُفض (كان ينبغي التوافقُ الرجعيّ): %v", err)
	}
}

// مقارنةُ semver: النواةُ عدديّةٌ، ولاحقةُ ما-قبل-الإصدار أدنى من نظيرتها بلا لاحقة.
func TestCompareVersions(t *testing.T) {
	cases := []struct {
		a, b string
		want int
	}{
		{"1.2.3", "1.2.3", 0},
		{"1.2.3", "1.2.4", -1},
		{"1.3.0", "1.2.9", 1},
		{"2.0.0", "1.9.9", 1},
		{"1.2.0-rc.1", "1.2.0", -1}, // ما-قبل-الإصدار أدنى
		{"1.2.0", "1.2.0-rc.1", 1},
		{"0.10.0", "0.9.0", 1}, // مقارنةٌ عدديّةٌ لا نصّيّة (10 > 9)
	}
	for _, c := range cases {
		if got := compareVersions(c.a, c.b); got != c.want {
			t.Fatalf("compareVersions(%q,%q)=%d أُريد %d", c.a, c.b, got, c.want)
		}
	}
}
