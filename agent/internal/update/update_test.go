package update

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
)

func serveBinary(t *testing.T, payload []byte) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write(payload)
	}))
	t.Cleanup(srv.Close)
	return srv
}

// التجزئةُ الصحيحة: تنزيلٌ ثم تحقّقُ sha256 **قبل** التبديل ثم rename ذرّيّ.
func TestApplyGoodHashSwaps(t *testing.T) {
	payload := []byte("new-agent-binary-v2")
	srv := serveBinary(t, payload)
	sum := sha256.Sum256(payload)

	dir := t.TempDir()
	target := filepath.Join(dir, "lynomia-agent")
	if err := os.WriteFile(target, []byte("old-binary"), 0o755); err != nil {
		t.Fatal(err)
	}

	err := Apply(context.Background(), srv.Client(), Manifest{URL: srv.URL, SHA256: hex.EncodeToString(sum[:])}, target)
	if err != nil {
		t.Fatalf("Apply: %v", err)
	}
	got, _ := os.ReadFile(target)
	if string(got) != string(payload) {
		t.Fatalf("الهدفُ لم يُبدَّل: %q", got)
	}
	info, _ := os.Stat(target)
	if info.Mode().Perm()&0o100 == 0 {
		t.Fatalf("الهدفُ بلا إذن تنفيذٍ للمالك: %o", info.Mode().Perm())
	}
}

// **التجزئةُ الخاطئة ترفض:** الملفُ الهابط لا يُبدَّل ولا يبقى له أثرٌ مؤقت —
// والثنائيّةُ الحالية تبقى كما هي حرفياً.
func TestApplyBadHashRefusesAndKeepsCurrent(t *testing.T) {
	srv := serveBinary(t, []byte("tampered-binary"))
	other := sha256.Sum256([]byte("what-was-promised"))

	dir := t.TempDir()
	target := filepath.Join(dir, "lynomia-agent")
	if err := os.WriteFile(target, []byte("old-binary"), 0o755); err != nil {
		t.Fatal(err)
	}

	err := Apply(context.Background(), srv.Client(), Manifest{URL: srv.URL, SHA256: hex.EncodeToString(other[:])}, target)
	if err == nil {
		t.Fatal("تجزئةٌ لا تطابق قُبلت — تبديلٌ أعمى")
	}
	got, _ := os.ReadFile(target)
	if string(got) != "old-binary" {
		t.Fatalf("الثنائيّةُ الحالية مُسّت رغم الرفض: %q", got)
	}
	entries, _ := os.ReadDir(dir)
	if len(entries) != 1 {
		t.Fatalf("أثرٌ مؤقتٌ باقٍ بعد الرفض: %v", entries)
	}
}

// بيانٌ مشوَّه (تجزئةٌ ليست hex-64 أو عنوانٌ فارغ) يُرفَض قبل أي تنزيل.
func TestApplyRejectsMalformedManifest(t *testing.T) {
	target := filepath.Join(t.TempDir(), "agent")
	for _, m := range []Manifest{
		{URL: "", SHA256: "abcd"},
		{URL: "https://example.invalid/x", SHA256: ""},
		{URL: "https://example.invalid/x", SHA256: "zz"},
	} {
		if err := Apply(context.Background(), http.DefaultClient, m, target); err == nil {
			t.Fatalf("بيانٌ مشوَّه قُبل: %+v", m)
		}
	}
}

// جلبُ البيان {url, sha256} من الخادم.
func TestFetchManifest(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"url":"https://dl.example.com/agent","sha256":"` + hex.EncodeToString(make([]byte, 32)) + `"}`))
	}))
	defer srv.Close()
	m, err := FetchManifest(context.Background(), srv.Client(), srv.URL)
	if err != nil {
		t.Fatalf("FetchManifest: %v", err)
	}
	if m.URL != "https://dl.example.com/agent" || len(m.SHA256) != 64 {
		t.Fatalf("بيانٌ لا يطابق: %+v", m)
	}
}
