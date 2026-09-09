package security

import (
	"errors"
	"runtime"
	"testing"
)

// **الوضعيّةُ الصادقة (مرآةُ C15):** قارئٌ مُنع أو أعاد قيمةً خارج القائمة
// المغلقة {active|inactive|not-configured} يهبط إلى not-configured —
// **أبداً** لا يصير active إلا بإبلاغٍ حرفيّ صريح.
func TestCollectFromDegradesHonestly(t *testing.T) {
	cases := []struct {
		name   string
		reader Reader
		want   string
	}{
		{"قراءةٌ صريحة active", func() (string, error) { return "active", nil }, "active"},
		{"قراءةٌ صريحة inactive", func() (string, error) { return "inactive", nil }, "inactive"},
		{"تطبيعُ الحالة والمسافات", func() (string, error) { return " ACTIVE ", nil }, "active"},
		{"قارئٌ ممنوع (خطأ نظام)", func() (string, error) { return "", errors.New("access denied") }, "not-configured"},
		{"قارئٌ ممنوع لا يدّعي active", func() (string, error) { return "active", errors.New("denied") }, "not-configured"},
		{"ادّعاءٌ خارج القائمة", func() (string, error) { return "enabled", nil }, "not-configured"},
		{"قيمةٌ فارغة", func() (string, error) { return "", nil }, "not-configured"},
		{"not-configured صريحة", func() (string, error) { return "not-configured", nil }, "not-configured"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got := CollectFrom(map[string]Reader{"firewall": tc.reader})
			if got["firewall"] != tc.want {
				t.Fatalf("firewall = %q؛ المطلوب %q", got["firewall"], tc.want)
			}
		})
	}
}

// **العقدُ الموسَّع (§11):** خطأٌ مُسمّىً يهبط لحالته الصادقة (لا يُطمَس
// not-configured)، والقراءةُ الصريحةُ لحالةٍ موسَّعةٍ تُحفَظ كما هي. «مُنع
// القراءة» permission-denied — **ليس امتثالاً** — لا يُخلَط بـnot-configured.
func TestCollectFromExpandedContract(t *testing.T) {
	cases := []struct {
		name   string
		reader Reader
		want   string
	}{
		{"مُنع القراءة (سِمة)", func() (string, error) { return "", ErrPermissionDenied }, "permission-denied"},
		{"غيرُ مدعوم (سِمة)", func() (string, error) { return "", ErrUnsupported }, "unsupported"},
		{"تعذّر (سِمة)", func() (string, error) { return "", ErrUnavailable }, "unavailable"},
		{"مُنع القراءة لا يُطمَس active", func() (string, error) { return "active", ErrPermissionDenied }, "permission-denied"},
		{"قراءةٌ صريحة permission-denied", func() (string, error) { return "permission-denied", nil }, "permission-denied"},
		{"قراءةٌ صريحة unavailable", func() (string, error) { return "unavailable", nil }, "unavailable"},
		{"قراءةٌ صريحة unsupported", func() (string, error) { return "unsupported", nil }, "unsupported"},
		{"خطأٌ عامٌّ يبقى not-configured (توافقٌ رجعيّ)", func() (string, error) { return "", errors.New("x") }, "not-configured"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got := CollectFrom(map[string]Reader{"firewall": tc.reader})
			if got["firewall"] != tc.want {
				t.Fatalf("firewall = %q؛ المطلوب %q", got["firewall"], tc.want)
			}
		})
	}
}

// الفحوصُ القانونية الأربعة تحضر دوماً — الغائبُ يُبلَّغ not-configured لا يُسكَت عنه.
func TestCollectFromFillsCanonicalChecks(t *testing.T) {
	got := CollectFrom(map[string]Reader{})
	for _, check := range CanonicalChecks {
		if got[check] != "not-configured" {
			t.Fatalf("الفحص %q غائبٌ أو غيرُ صادق: %q", check, got[check])
		}
	}
	if len(CanonicalChecks) != 4 {
		t.Fatalf("الفحوصُ القانونية أربعة (firewall/disk_encryption/defender/updates)؛ وجدت %d", len(CanonicalChecks))
	}
}

// Collect على هذه المنصة: كلُّ قيمةٍ داخل القائمة المغلقة؛ وعلى linux (بديلُ
// التطوير الأمين بلا قرّاء منصّة) الكلُّ not-configured — لا اختلاقَ نشاط.
func TestCollectClosedSetAndHonestStub(t *testing.T) {
	got := Collect()
	for check, v := range got {
		if v != "active" && v != "inactive" && v != "not-configured" {
			t.Fatalf("قراءةُ %q خارج القائمة المغلقة: %q", check, v)
		}
	}
	if runtime.GOOS == "linux" {
		for check, v := range got {
			if v != "not-configured" {
				t.Fatalf("على linux لا قارئَ منصّة — %q يجب أن تكون not-configured لا %q", check, v)
			}
		}
	}
}
