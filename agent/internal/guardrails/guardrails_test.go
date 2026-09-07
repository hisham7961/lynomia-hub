// Package guardrails — برهانُ grep الآليّ لقواعد أمن الطور K على شجرة الوكيل
// كاملةً: لا shell، لا جامعاتِ مراقبة، لا مسحَ شبكة، ولا تبعيّةَ خارج المسموح.
// الحواجزُ هنا نصيّةٌ فوق المصدر نفسِه — فلا تُلتَفّ بتغليفٍ أو إعادة تسمية
// دالّةٍ داخلية. (الإبرُ تُبنى بالتسلسل كي لا يطابق الملفُ نفسَه.)
package guardrails

import (
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

func agentRoot(t *testing.T) string {
	t.Helper()
	_, file, _, ok := runtime.Caller(0)
	if !ok {
		t.Fatal("تعذّر تحديد جذر الشجرة")
	}
	return filepath.Clean(filepath.Join(filepath.Dir(file), "..", ".."))
}

func goFiles(t *testing.T, root string) map[string]string {
	t.Helper()
	files := map[string]string{}
	err := filepath.WalkDir(root, func(path string, d os.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if d.IsDir() || !strings.HasSuffix(path, ".go") {
			return nil
		}
		if strings.HasSuffix(path, "guardrails_test.go") {
			return nil // الملفُ الحارس نفسُه يحمل الإبر
		}
		blob, err := os.ReadFile(path)
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(root, path)
		files[filepath.ToSlash(rel)] = string(blob)
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	if len(files) < 10 {
		t.Fatalf("شجرةُ الوكيل أنحف من المتوقَّع (%d ملفات) — الجذرُ خاطئ؟", len(files))
	}
	return files
}

// **لا shell إطلاقاً** — في أيّ ملف، اختباراً كان أو تنفيذاً: لا os/exec ولا
// أيَّ بابِ إطلاقِ عمليّةٍ آخر.
func TestNoShellNoProcessSpawnAnywhere(t *testing.T) {
	needles := []string{
		"os/" + "exec",
		"exec" + ".Command",
		"syscall" + ".Exec",
		"StartProcess",
	}
	for rel, src := range goFiles(t, agentRoot(t)) {
		for _, needle := range needles {
			if strings.Contains(src, needle) {
				t.Errorf("%s: يحمل %q — الشجرةُ بلا shell وبلا إطلاق عمليات", rel, needle)
			}
		}
	}
}

// أسماءُ الأصداف لا تَرِد إلا في الاختبارات (مُدخلاتٍ مرفوضةً تثبت الرفض).
func TestNoShellWordsOutsideTests(t *testing.T) {
	needles := []string{"power" + "shell", "cmd" + ".exe", "/bin/" + "sh"}
	for rel, src := range goFiles(t, agentRoot(t)) {
		if strings.HasSuffix(rel, "_test.go") {
			continue
		}
		for _, needle := range needles {
			if strings.Contains(src, needle) {
				t.Errorf("%s: يحمل %q خارج الاختبارات", rel, needle)
			}
		}
	}
}

// **لا مراقبة:** ألفاظُ التجسّس لا تَرِد في التنفيذ إلا داخل internal/privacy —
// مرآةِ قائمة الرفض الخادمية `EndpointPrivacy::FORBIDDEN` (بوّابةُ عمقٍ قبل
// الإرسال) — فلا جامعَ يحملها اسماً ولا حقلاً.
func TestNoSurveillanceTokensOutsidePrivacyMirror(t *testing.T) {
	needles := []string{
		"key" + "log", "key" + "stroke", "screen" + "shot", "screen_" + "capture",
		"clip" + "board", "web" + "cam", "micro" + "phone", "browser_" + "history",
		"packet_" + "capture", "call_" + "record",
	}
	for rel, src := range goFiles(t, agentRoot(t)) {
		if strings.HasSuffix(rel, "_test.go") || strings.HasPrefix(rel, "internal/privacy/") {
			continue
		}
		low := strings.ToLower(src)
		for _, needle := range needles {
			if strings.Contains(low, needle) {
				t.Errorf("%s: يحمل لفظَ مراقبة %q", rel, needle)
			}
		}
	}
}

// **الجهازُ نفسُه فقط — لا مسح:** الجامعاتُ (جرد/شبكة/USB/وضعيّة/سياسة/منصّة)
// لا تفتح اتصالاً أصلاً: لا net/http فيها ولا Dial ولا Listen — الاتصالُ كلُّه
// عبر النقل الموقَّع إلى الخادم وحدَه.
func TestCollectorsNeverDial(t *testing.T) {
	collectorDirs := []string{
		"internal/inventory/", "internal/network/", "internal/usb/",
		"internal/security/", "internal/policy/", "internal/platform/",
	}
	needles := []string{`"net/http"`, ".Dial", "net.Listen", "DialTimeout", "ListenPacket"}
	for rel, src := range goFiles(t, agentRoot(t)) {
		inCollector := false
		for _, d := range collectorDirs {
			if strings.HasPrefix(rel, d) {
				inCollector = true
				break
			}
		}
		if !inCollector {
			continue
		}
		for _, needle := range needles {
			if strings.Contains(src, needle) {
				t.Errorf("%s: جامعٌ يحمل %q — الجامعاتُ تقرأ الجهازَ ولا تتّصل", rel, needle)
			}
		}
	}
}

// **المفتاحُ الخاصُّ لا يغادر:** تسلسلُه (MarshalECPrivateKey) محصورٌ في
// internal/identity (المخزنُ المحليّ 0600) — لا موضعَ ثانياً في الشجرة.
func TestPrivateKeySerializationConfinedToIdentity(t *testing.T) {
	for rel, src := range goFiles(t, agentRoot(t)) {
		if strings.HasPrefix(rel, "internal/identity/") {
			continue
		}
		if strings.Contains(src, "MarshalECPrivateKey") || strings.Contains(src, "EC PRIVATE KEY") {
			t.Errorf("%s: يسلسل مادةَ مفتاحٍ خاصّ خارج internal/identity", rel)
		}
	}
}

// التبعيّةُ الوحيدة المسموحة خارج المكتبة القياسية: golang.org/x/sys
// (سجلُّ Windows/حقائقُ النظام خلف وسوم) — لا حزمةَ طرفٍ ثالثٍ سواها.
func TestGoModAllowsOnlyXSys(t *testing.T) {
	blob, err := os.ReadFile(filepath.Join(agentRoot(t), "go.mod"))
	if err != nil {
		t.Fatal(err)
	}
	for _, line := range strings.Split(string(blob), "\n") {
		line = strings.TrimSpace(strings.TrimPrefix(strings.TrimSpace(line), "require "))
		// سطرُ تبعيّةٍ = مسارُ وحدةٍ فيه «/» — كلُّ ما سواه (module/go/أقواس) يُتجاهل
		if !strings.Contains(line, "/") || strings.HasPrefix(line, "module ") {
			continue
		}
		if !strings.HasPrefix(line, "golang.org/x/sys ") {
			t.Errorf("تبعيّةٌ خارج المسموح في go.mod: %q", line)
		}
	}
}
