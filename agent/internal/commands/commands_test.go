package commands

import (
	"bytes"
	"context"
	"crypto/ecdsa"
	"crypto/sha256"
	"crypto/x509"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"io"
	"net/http"
	"strings"
	"testing"

	"lynomia/agent/internal/identity"
)

// موزّعٌ فوق القائمة المغلقة الخمسة بجواسيسَ تسجّل الاستدعاء.
func spyDispatcher(t *testing.T, called map[string]int) *Dispatcher {
	t.Helper()
	handlers := map[string]Handler{}
	for _, typ := range Types {
		typ := typ
		handlers[typ] = func(ctx context.Context, cmd Command) (map[string]any, error) {
			called[typ]++
			return map[string]any{"echo": typ}, nil
		}
	}
	d, err := NewDispatcher(handlers)
	if err != nil {
		t.Fatalf("NewDispatcher: %v", err)
	}
	return d
}

// **القائمةُ المغلقة (مرآةُ C10):** نوعٌ غيرُ مُدرَجٍ — ومنه كلُّ صيغِ الأصداف —
// يُرفَض ويُبلَّغ failed **ولا يُنفَّذ شيء**: لا معالجَ يُستدعى، لا تمريرَ حرّ.
func TestDispatchRejectsUnknownAndShellLikeTypes(t *testing.T) {
	cases := []string{
		"run_shell",
		"cmd /c dir",
		"powershell -enc SQBFAFgA",
		"/bin/sh -c id",
		"wipe",
		"refresh_inventory ", // المطابقةُ حرفيّة — لا تسامحَ مسافات
		"REFRESH_POSTURE",    // ولا تسامحَ حالة
		"",
	}
	for _, typ := range cases {
		t.Run("رفض "+typ, func(t *testing.T) {
			called := map[string]int{}
			d := spyDispatcher(t, called)
			out := d.Dispatch(context.Background(), Command{ID: "c1", Type: typ, IKey: "k1"})
			if out.State != "failed" {
				t.Fatalf("النوع %q: الحالة %q؛ المطلوب failed", typ, out.State)
			}
			if out.Result["error"] != "unknown-command-type" {
				t.Fatalf("النوع %q: النتيجة لا تعلن الرفض: %v", typ, out.Result)
			}
			for k, n := range called {
				if n != 0 {
					t.Fatalf("النوع %q استدعى معالجاً (%s) — تمريرٌ محظور", typ, k)
				}
			}
		})
	}
}

// تسجيلُ معالجٍ لنوعٍ خارج القائمة مرفوضٌ من الأساس — لا بابَ يُفتح لاحقاً.
func TestNewDispatcherRefusesTypesOutsideClosedList(t *testing.T) {
	_, err := NewDispatcher(map[string]Handler{
		"run_shell": func(ctx context.Context, cmd Command) (map[string]any, error) { return nil, nil },
	})
	if err == nil {
		t.Fatal("تسجيلُ run_shell قُبل — القائمةُ لم تعد مغلقة")
	}
}

func TestDispatchRunsKnownType(t *testing.T) {
	called := map[string]int{}
	d := spyDispatcher(t, called)
	out := d.Dispatch(context.Background(), Command{ID: "c1", Type: "refresh_posture", IKey: "k1"})
	if out.State != "done" || called["refresh_posture"] != 1 {
		t.Fatalf("refresh_posture لم يُنفَّذ نظيفاً: %+v called=%v", out, called)
	}
	if out.Result["echo"] != "refresh_posture" {
		t.Fatalf("نتيجةُ المعالج لم تصل: %v", out.Result)
	}
}

// نوعٌ داخل القائمة بلا معالجٍ على هذه المنصة: failed بصدق لا تنفيذَ وهميّ.
func TestDispatchUnwiredTypeFailsHonestly(t *testing.T) {
	d, err := NewDispatcher(map[string]Handler{})
	if err != nil {
		t.Fatal(err)
	}
	out := d.Dispatch(context.Background(), Command{ID: "c1", Type: "lock", IKey: "k1"})
	if out.State != "failed" || out.Result["error"] != "not-supported-on-this-agent" {
		t.Fatalf("النوعُ غيرُ الموصول لم يُبلَّغ بصدق: %+v", out)
	}
}

func TestDispatchHandlerErrorReportsFailed(t *testing.T) {
	d, err := NewDispatcher(map[string]Handler{
		"isolate": func(ctx context.Context, cmd Command) (map[string]any, error) {
			return map[string]any{"partial": true}, io.ErrUnexpectedEOF
		},
	})
	if err != nil {
		t.Fatal(err)
	}
	out := d.Dispatch(context.Background(), Command{ID: "c1", Type: "isolate", IKey: "k1"})
	if out.State != "failed" {
		t.Fatalf("خطأُ المعالج لم يُبلَّغ failed: %+v", out)
	}
	if out.Result["error"] == nil {
		t.Fatalf("النتيجة بلا سبب الفشل: %v", out.Result)
	}
}

func TestParsePull(t *testing.T) {
	raw := []byte(`{"ok":true,"commands":[
		{"id":"cmd-1","type":"refresh_inventory","args":null,"ikey":"ik-1"},
		{"id":"cmd-2","type":"apply_policy","args":{"tag":"vip"},"ikey":"ik-2"}]}`)
	cmds, err := ParsePull(raw)
	if err != nil {
		t.Fatalf("ParsePull: %v", err)
	}
	if len(cmds) != 2 || cmds[0].ID != "cmd-1" || cmds[1].Args["tag"] != "vip" || cmds[1].IKey != "ik-2" {
		t.Fatalf("تحليلُ السحب لا يطابق شكلَ commandsPull: %+v", cmds)
	}
}

// **مرآةُ عقد توقيع النتيجة** (docblock ‏EndpointProtocolController):
// ‏ES256 على IKEY + "\n" + STATE + "\n" + sha256hex(RESULT_RAW).
func TestSignResultMirrorsServerContract(t *testing.T) {
	id, err := identity.Generate(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	raw := []byte(`{"posture":{"firewall":"active"}}`)
	sig, err := SignResult(id, "ik-9", "done", raw)
	if err != nil {
		t.Fatal(err)
	}

	pubPEM, err := id.PublicPEM()
	if err != nil {
		t.Fatal(err)
	}
	block, _ := pem.Decode([]byte(pubPEM))
	pubAny, err := x509.ParsePKIXPublicKey(block.Bytes)
	if err != nil {
		t.Fatal(err)
	}
	pub := pubAny.(*ecdsa.PublicKey)

	sum := sha256.Sum256(raw)
	canonical := "ik-9\ndone\n" + hex.EncodeToString(sum[:])
	digest := sha256.Sum256([]byte(canonical))
	der, err := base64.StdEncoding.DecodeString(sig)
	if err != nil {
		t.Fatal(err)
	}
	if !ecdsa.VerifyASN1(pub, digest[:], der) {
		t.Fatal("توقيعُ النتيجة لا يصحّ على السلسلة القانونية للخادم")
	}

	// الجسمُ الغائب تجزئتُه تجزئةُ السلسلة الفارغة (البند نفسُه في العقد)
	sigEmpty, err := SignResult(id, "ik-9", "failed", nil)
	if err != nil {
		t.Fatal(err)
	}
	sumE := sha256.Sum256(nil)
	canonicalE := "ik-9\nfailed\n" + hex.EncodeToString(sumE[:])
	digestE := sha256.Sum256([]byte(canonicalE))
	derE, _ := base64.StdEncoding.DecodeString(sigEmpty)
	if !ecdsa.VerifyASN1(pub, digestE[:], derE) {
		t.Fatal("توقيعُ النتيجة الفارغة لا يصحّ")
	}
}

// ناقلٌ وهميّ يسجّل الطلبات ويرُدّ ردوداً معلَّبة.
type fakeDoer struct {
	pullBody string
	requests []struct {
		Path string
		Body []byte
	}
}

func (f *fakeDoer) Do(ctx context.Context, method, path string, body []byte) (*http.Response, error) {
	f.requests = append(f.requests, struct {
		Path string
		Body []byte
	}{path, append([]byte(nil), body...)})
	respBody := `{"ok":true}`
	if path == "/api/v1/endpoint/commands/pull" {
		respBody = f.pullBody
	}
	return &http.Response{
		StatusCode: http.StatusOK,
		Body:       io.NopCloser(bytes.NewReader([]byte(respBody))),
		Header:     http.Header{},
	}, nil
}

// دورةُ السحب→التوزيع→النتيجة كاملةً: المعلومُ يُنفَّذ done، والمجهولُ
// (run_shell) يُبلَّغ failed **دون تنفيذ**، وكلُّ نتيجةٍ نصُّ JSON موقَّعٌ بعقده.
func TestRunnerRoundTrip(t *testing.T) {
	id, err := identity.Generate(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	called := map[string]int{}
	d := spyDispatcher(t, called)
	doer := &fakeDoer{pullBody: `{"ok":true,"commands":[
		{"id":"cmd-1","type":"refresh_posture","args":null,"ikey":"ik-1"},
		{"id":"cmd-2","type":"run_shell","args":{"cmdline":"dir"},"ikey":"ik-2"}]}`}

	r := NewRunner(doer, "/api/v1/endpoint/commands/pull", "/api/v1/endpoint/commands/result", d, id)
	n, err := r.RunOnce(context.Background())
	if err != nil {
		t.Fatalf("RunOnce: %v", err)
	}
	if n != 2 {
		t.Fatalf("أمران يُبلَّغان؛ وجدت %d", n)
	}
	if called["refresh_posture"] != 1 {
		t.Fatal("المعلومُ لم يُنفَّذ")
	}
	if len(called) != 1 {
		t.Fatalf("المجهولُ استدعى معالجاً: %v", called)
	}

	var results []map[string]any
	for _, req := range doer.requests {
		if req.Path != "/api/v1/endpoint/commands/result" {
			continue
		}
		var m map[string]any
		if err := json.Unmarshal(req.Body, &m); err != nil {
			t.Fatal(err)
		}
		results = append(results, m)
	}
	if len(results) != 2 {
		t.Fatalf("نتيجتان تُرسَلان؛ وجدت %d", len(results))
	}
	states := map[string]string{}
	for _, m := range results {
		states[m["command_id"].(string)] = m["state"].(string)
		raw, ok := m["result"].(string)
		if !ok || !strings.HasPrefix(strings.TrimSpace(raw), "{") {
			t.Fatalf("حقلُ result نصُّ JSON خامٌ لا كائن: %v", m["result"])
		}
		if sig, ok := m["result_sig"].(string); !ok || sig == "" {
			t.Fatalf("النتيجة بلا توقيع result_sig: %v", m)
		}
	}
	if states["cmd-1"] != "done" || states["cmd-2"] != "failed" {
		t.Fatalf("الحالتان لا تطابقان (done للمعلوم، failed للمجهول): %v", states)
	}
}
