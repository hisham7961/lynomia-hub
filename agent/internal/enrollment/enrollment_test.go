package enrollment

// اختباراتُ التسجيل — تُكتب أولاً وتفشل أولاً. تعكس عقدَ
// EndpointEnrollController::enroll: حمولةُ token/device_uuid/hostname/os/public_key،
// وردُّ 201 بـdevice_id ومساراتِ البروتوكول الأربعة — و**لا حرفَ مفتاحٍ خاصّ
// في الحمولة أبداً** (الخادمُ يرُدّ أيَّ «PRIVATE KEY» بـ422 قبل كل شيء).

import (
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"

	"lynomia/agent/internal/identity"
)

func enrollResponseBody() string {
	return `{
		"ok": true,
		"device_id": "dev-123",
		"pubkey_fp": "aabbcc",
		"heartbeat_path": "/api/v1/endpoint/heartbeat",
		"event_path": "/api/v1/endpoint/event",
		"commands_pull_path": "/api/v1/endpoint/commands/pull",
		"commands_result_path": "/api/v1/endpoint/commands/result"
	}`
}

func TestEnrollSuccessStoresStateAndNeverSendsPrivateKey(t *testing.T) {
	dir := t.TempDir()
	id, err := identity.Generate(dir)
	if err != nil {
		t.Fatalf("identity.Generate: %v", err)
	}
	pubPEM, _ := id.PublicPEM()

	var captured []byte
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/endpoint/enroll" || r.Method != http.MethodPost {
			t.Errorf("طُرق %s %s — المتوقَّع POST /api/v1/endpoint/enroll", r.Method, r.URL.Path)
		}
		captured, _ = io.ReadAll(r.Body)
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusCreated)
		w.Write([]byte(enrollResponseBody()))
	}))
	defer srv.Close()

	st, err := Enroll(dir, id, Options{Server: srv.URL, Token: "tok-abc", AgentVersion: "0.1.0"})
	if err != nil {
		t.Fatalf("Enroll: %v", err)
	}

	// **الحاجزُ الأهمّ**: جسمُ التسجيل الملتقَط لا يحمل «PRIVATE KEY» أبداً
	if strings.Contains(string(captured), "PRIVATE KEY") {
		t.Fatal("حمولةُ التسجيل تحمل مادةَ مفتاحٍ خاصّ — خرقٌ قاطعٌ للعقد")
	}

	var sent map[string]any
	if err := json.Unmarshal(captured, &sent); err != nil {
		t.Fatalf("جسمُ التسجيل ليس JSON: %v", err)
	}
	if sent["token"] != "tok-abc" {
		t.Errorf("token = %v", sent["token"])
	}
	if sent["public_key"] != pubPEM {
		t.Error("public_key المرسَل لا يطابق PublicPEM")
	}
	if sent["os"] != "linux" {
		t.Errorf("os = %v — المتوقَّع linux على بيئة الاختبار", sent["os"])
	}
	if h, _ := sent["hostname"].(string); strings.TrimSpace(h) == "" {
		t.Error("hostname فارغ")
	}
	uuidRe := regexp.MustCompile(`^[A-Za-z0-9._:-]{8,64}$`)
	if u, _ := sent["device_uuid"].(string); !uuidRe.MatchString(u) {
		t.Errorf("device_uuid خارج عقد الخادم: %q", u)
	}

	// الحالةُ المخزَّنة: device_id ومساراتُ البروتوكول من ردّ 201 حرفياً
	if st.DeviceID != "dev-123" {
		t.Errorf("DeviceID = %q", st.DeviceID)
	}
	if st.HeartbeatPath != "/api/v1/endpoint/heartbeat" ||
		st.EventPath != "/api/v1/endpoint/event" ||
		st.CommandsPullPath != "/api/v1/endpoint/commands/pull" ||
		st.CommandsResultPath != "/api/v1/endpoint/commands/result" {
		t.Errorf("مساراتُ البروتوكول لم تُخزَّن كما أُعلنت: %+v", st)
	}

	info, err := os.Stat(filepath.Join(dir, "state.json"))
	if err != nil {
		t.Fatalf("state.json غيرُ مكتوب: %v", err)
	}
	if perm := info.Mode().Perm(); perm != 0o600 {
		t.Errorf("صلاحياتُ state.json = %o — المتوقَّع 0600", perm)
	}

	loaded, err := LoadState(dir)
	if err != nil {
		t.Fatalf("LoadState: %v", err)
	}
	if loaded.DeviceID != "dev-123" {
		t.Errorf("LoadState.DeviceID = %q", loaded.DeviceID)
	}
}

func TestEnrollSingleShotRefusesWithoutForce(t *testing.T) {
	dir := t.TempDir()
	id, err := identity.Generate(dir)
	if err != nil {
		t.Fatalf("identity.Generate: %v", err)
	}
	hits := 0
	var uuids []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hits++
		b, _ := io.ReadAll(r.Body)
		var sent map[string]any
		json.Unmarshal(b, &sent)
		u, _ := sent["device_uuid"].(string)
		uuids = append(uuids, u)
		w.WriteHeader(http.StatusCreated)
		w.Write([]byte(enrollResponseBody()))
	}))
	defer srv.Close()

	if _, err := Enroll(dir, id, Options{Server: srv.URL, Token: "t1"}); err != nil {
		t.Fatalf("Enroll (أولاً): %v", err)
	}
	// الثاني بلا --force يُرفَض محلياً قبل طرق الخادم
	if _, err := Enroll(dir, id, Options{Server: srv.URL, Token: "t2"}); !errors.Is(err, ErrAlreadyEnrolled) {
		t.Fatalf("المتوقَّع ErrAlreadyEnrolled — جاء: %v", err)
	}
	if hits != 1 {
		t.Fatalf("الخادمُ طُرق %d مرة بعد الرفض المحليّ — المتوقَّع 1", hits)
	}
	// وبـForce يُعاد — وهويّةُ device_uuid **ثابتة** عبر الإعادة
	if _, err := Enroll(dir, id, Options{Server: srv.URL, Token: "t3", Force: true}); err != nil {
		t.Fatalf("Enroll (Force): %v", err)
	}
	if hits != 2 || len(uuids) != 2 || uuids[0] != uuids[1] {
		t.Fatalf("device_uuid تبدّل بين تسجيلين: %v", uuids)
	}
}

func TestEnrollSurfacesConflict409Cleanly(t *testing.T) {
	dir := t.TempDir()
	id, err := identity.Generate(dir)
	if err != nil {
		t.Fatalf("identity.Generate: %v", err)
	}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusConflict)
		w.Write([]byte(`{"error":"رمزُ التسجيل استُهلك","code":"CONFLICT","message":"رمزُ التسجيل استُهلك — يُسَكّ رمزٌ جديد لكل جهاز"}`))
	}))
	defer srv.Close()

	_, err = Enroll(dir, id, Options{Server: srv.URL, Token: "used"})
	if err == nil {
		t.Fatal("ردُّ 409 لم يُسطَّح خطأً")
	}
	if !strings.Contains(err.Error(), "409") || !strings.Contains(err.Error(), "استُهلك") {
		t.Fatalf("خطأُ 409 لا يحمل الحالةَ ورسالةَ الخادم: %v", err)
	}
	// لا حالةَ تُكتب عند الفشل
	if _, err := LoadState(dir); !errors.Is(err, ErrNotEnrolled) {
		t.Fatalf("حالةٌ كُتبت رغم فشل التسجيل: %v", err)
	}
}

func TestOSNameMapsGOOSToServerContract(t *testing.T) {
	// على بيئة CI (linux) — القائمةُ المقبولة خادمياً: windows|macos|linux
	name, err := OSName()
	if err != nil {
		t.Fatalf("OSName: %v", err)
	}
	if name != "linux" {
		t.Fatalf("OSName = %q — المتوقَّع linux هنا", name)
	}
}
