package transport

// اختباراتُ النقل — تُكتب أولاً وتفشل أولاً. متّجهاتُ السلسلة القانونية أدناه
// **طُوبقت بايتاً بايتاً** ضدّ `App\Support\Es256::canonical` في الخادم الحيّ
// قبل تثبيتها — فالمرآةُ مثبتةٌ لا مُدَّعاة.

import (
	"context"
	"crypto/ecdsa"
	"crypto/sha256"
	"crypto/x509"
	"encoding/base64"
	"encoding/pem"
	"math"
	"net/http"
	"net/http/httptest"
	"regexp"
	"strconv"
	"testing"
	"time"

	"lynomia/agent/internal/identity"
)

func TestCanonicalVectors(t *testing.T) {
	cases := []struct {
		name                          string
		method, path, ts, nonce, body string
		want                          string
	}{
		{
			// تجزئةُ الجسم الفارغ = تجزئةُ السلسلة الفارغة (عقد Es256 البند ١)
			name: "empty-body", method: "POST", path: "/api/v1/endpoint/heartbeat",
			ts: "1700000000", nonce: "abcdefgh", body: "",
			want: "POST\n/api/v1/endpoint/heartbeat\n1700000000\nabcdefgh\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
		},
		{
			name: "json-body", method: "POST", path: "/api/v1/endpoint/event",
			ts: "1712345678", nonce: "n0.nce_-XYZ", body: `{"hostname":"pc-01"}`,
			want: "POST\n/api/v1/endpoint/event\n1712345678\nn0.nce_-XYZ\n63fc9ac5cf439859cbcbdc99c5114f4a161cd09ddd8ea1a786e2a34b6e7dee8d",
		},
		{
			// الفعلُ يُرفَع حروفاً كبيرة — مرآةُ strtoupper في الخادم
			name: "lowercase-method", method: "get", path: "/api/v1/endpoint/commands/pull",
			ts: "1700000000", nonce: "zzzz9999", body: `{"a":1}`,
			want: "GET\n/api/v1/endpoint/commands/pull\n1700000000\nzzzz9999\n015abd7f5cc57a2dd94b7590f04ad8084273905ee33ec5cebeae62276a97f862",
		},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			got := Canonical(c.method, c.path, c.ts, c.nonce, []byte(c.body))
			if got != c.want {
				t.Fatalf("السلسلةُ القانونية تخالف عقدَ الخادم:\n got=%q\nwant=%q", got, c.want)
			}
		})
	}
}

func TestNonceCharsetLengthUniqueness(t *testing.T) {
	// عقدُ الـnonce: 8–64 من [A-Za-z0-9._-] فريدٌ لكل طلب (Es256 البند ١)
	re := regexp.MustCompile(`^[A-Za-z0-9._-]{8,64}$`)
	seen := map[string]bool{}
	for i := 0; i < 200; i++ {
		n, err := NewNonce()
		if err != nil {
			t.Fatalf("NewNonce: %v", err)
		}
		if !re.MatchString(n) {
			t.Fatalf("nonce خارج العقد: %q", n)
		}
		if seen[n] {
			t.Fatalf("nonce مكرَّر: %q", n)
		}
		seen[n] = true
	}
}

func TestClientRejectsBadPaths(t *testing.T) {
	id, err := identity.Generate(t.TempDir())
	if err != nil {
		t.Fatalf("identity.Generate: %v", err)
	}
	c, err := NewClient("https://example.invalid", "dev-1", id)
	if err != nil {
		t.Fatalf("NewClient: %v", err)
	}
	// PATH مطلقٌ مبدوءٌ بـ«/» **بلا** سلسلة استعلام — عقدُ Es256 حرفياً
	for _, p := range []string{"api/v1/x", "/api/v1/x?y=1", "/api/v1/x#f", ""} {
		if _, err := c.Do(context.Background(), "POST", p, nil); err == nil {
			t.Fatalf("مسارٌ خارج العقد قُبل: %q", p)
		}
	}
}

// خادمُ اختبارٍ يعيد حسابَ السلسلة القانونية من الترويسات الواصلة ويتحقق
// بالمفتاح العامّ — مرآةُ ما يفعله وسيطُ EndpointSignature في الخادم حرفياً.
func TestClientSignsRequestsPerContract(t *testing.T) {
	dir := t.TempDir()
	id, err := identity.Generate(dir)
	if err != nil {
		t.Fatalf("identity.Generate: %v", err)
	}
	pubPEM, _ := id.PublicPEM()
	block, _ := pem.Decode([]byte(pubPEM))
	parsed, err := x509.ParsePKIXPublicKey(block.Bytes)
	if err != nil {
		t.Fatalf("ParsePKIXPublicKey: %v", err)
	}
	pub := parsed.(*ecdsa.PublicKey)

	body := []byte(`{"agent_version":"0.1.0"}`)
	nonceRe := regexp.MustCompile(`^[A-Za-z0-9._-]{8,64}$`)
	var handled bool

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		handled = true
		devID := r.Header.Get("X-Endpoint-Id")
		ts := r.Header.Get("X-Endpoint-Timestamp")
		nonce := r.Header.Get("X-Endpoint-Nonce")
		sigB64 := r.Header.Get("X-Endpoint-Signature")
		if devID != "dev-42" {
			t.Errorf("X-Endpoint-Id = %q — المتوقَّع dev-42", devID)
		}
		if !nonceRe.MatchString(nonce) {
			t.Errorf("nonce واصلٌ خارج العقد: %q", nonce)
		}
		// الطابعُ عددٌ صحيحٌ داخل ±300 ثانية (النافذةُ المفروضة خادمياً)
		sec, err := strconv.ParseInt(ts, 10, 64)
		if err != nil || math.Abs(float64(time.Now().Unix()-sec)) > 300 {
			t.Errorf("طابعٌ خارج النافذة أو ليس عدداً: %q", ts)
		}

		got := make([]byte, r.ContentLength)
		if _, err := r.Body.Read(got); err != nil && err.Error() != "EOF" {
			t.Errorf("قراءةُ الجسم: %v", err)
		}
		canonical := Canonical(r.Method, r.URL.Path, ts, nonce, got)
		der, err := base64.StdEncoding.DecodeString(sigB64)
		if err != nil {
			t.Errorf("التوقيعُ ليس base64: %v", err)
		}
		digest := sha256.Sum256([]byte(canonical))
		if !ecdsa.VerifyASN1(pub, digest[:], der) {
			t.Error("توقيعُ العميل لا يصحّ على السلسلة القانونية — المرآةُ مكسورة")
		}

		// عبثٌ بأي جزء → التحقق يفشل (الربطُ كامل: body/timestamp/nonce)
		for name, bad := range map[string]string{
			"body":      Canonical(r.Method, r.URL.Path, ts, nonce, append([]byte{'x'}, got...)),
			"timestamp": Canonical(r.Method, r.URL.Path, ts+"1", nonce, got),
			"nonce":     Canonical(r.Method, r.URL.Path, ts, nonce+"x", got),
		} {
			d := sha256.Sum256([]byte(bad))
			if ecdsa.VerifyASN1(pub, d[:], der) {
				t.Errorf("عبثٌ بـ%s مرّ بالتحقق", name)
			}
		}

		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"ok":true}`))
	}))
	defer srv.Close()

	c, err := NewClient(srv.URL, "dev-42", id)
	if err != nil {
		t.Fatalf("NewClient: %v", err)
	}
	resp, err := c.Do(context.Background(), "POST", "/api/v1/endpoint/heartbeat", body)
	if err != nil {
		t.Fatalf("Do: %v", err)
	}
	resp.Body.Close()
	if !handled {
		t.Fatal("الخادمُ لم يُطرَق")
	}
}

func TestClockSkewDiscipline(t *testing.T) {
	// خادمٌ ساعتُه متقدمةٌ ٢٠ دقيقة: الطلبُ الأول يسقط خارج النافذة (401)
	// وترويسةُ Date تكشف الانحراف — فالطلبُ الثاني يجب أن يقع داخل ±300 ثانية
	// من ساعة الخادم (انضباطُ الساعة في العميل).
	skew := 20 * time.Minute
	var stamps []int64
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		sec, _ := strconv.ParseInt(r.Header.Get("X-Endpoint-Timestamp"), 10, 64)
		stamps = append(stamps, sec)
		serverNow := time.Now().Add(skew)
		w.Header().Set("Date", serverNow.UTC().Format(http.TimeFormat))
		if math.Abs(float64(serverNow.Unix()-sec)) > 300 {
			w.WriteHeader(http.StatusUnauthorized)
			w.Write([]byte(`{"code":"UNAUTHENTICATED"}`))
			return
		}
		w.Write([]byte(`{"ok":true}`))
	}))
	defer srv.Close()

	id, err := identity.Generate(t.TempDir())
	if err != nil {
		t.Fatalf("identity.Generate: %v", err)
	}
	c, err := NewClient(srv.URL, "dev-9", id)
	if err != nil {
		t.Fatalf("NewClient: %v", err)
	}

	r1, err := c.Do(context.Background(), "POST", "/api/v1/endpoint/heartbeat", nil)
	if err != nil {
		t.Fatalf("Do (أولاً): %v", err)
	}
	r1.Body.Close()
	r2, err := c.Do(context.Background(), "POST", "/api/v1/endpoint/heartbeat", nil)
	if err != nil {
		t.Fatalf("Do (ثانياً): %v", err)
	}
	r2.Body.Close()

	if len(stamps) != 2 {
		t.Fatalf("عددُ الطلبات %d — المتوقَّع 2", len(stamps))
	}
	serverNow := time.Now().Add(skew).Unix()
	if diff := math.Abs(float64(serverNow - stamps[1])); diff > 300 {
		t.Fatalf("بعد رؤية Date بقي الطابعُ خارج نافذة الخادم (فرقُ %v ثانية) — لا انضباطَ ساعة", diff)
	}
	if r2.StatusCode != http.StatusOK {
		t.Fatalf("الطلبُ الثاني %d — المتوقَّع 200 بعد الانضباط", r2.StatusCode)
	}
}
