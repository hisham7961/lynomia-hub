// Package transport — النقلُ الموقَّع (الطور K · WP-K.1 · §45): **مرآةُ عقد
// التوقيع** المكتوب مرةً واحدةً في docblock ‏`App\Support\Es256` — لا عقدَ ثانياً
// هنا، والسلسلةُ القانونية تُبنى بالحرف كما يبنيها الخادم:
//
//	METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + sha256hex(BODY)
//
// PATH مطلقٌ مبدوءٌ بـ«/» بلا سلسلة استعلام؛ TIMESTAMP ثواني يونكس نصّاً؛
// NONCE ‏8–64 من [A-Za-z0-9._-] فريدٌ لكل طلب؛ وتجزئةُ الجسم الفارغ تجزئةُ
// السلسلة الفارغة. التوقيعُ base64(DER) يسافر في `X-Endpoint-Signature` ومعه
// `X-Endpoint-Id` و`X-Endpoint-Timestamp` (نافذةُ الخادم ±300 ثانية) و`X-Endpoint-Nonce`.
//
// انضباطُ الساعة: العميلُ يراقب ترويسةَ `Date` في كل ردٍّ ويحفظ الانحرافَ عن
// ساعة الخادم — فجهازٌ ساعتُه منحرفةٌ يعود إلى داخل النافذة من الطلب التالي
// بلا تدخّلٍ يدويّ (والخادمُ يبقى الحكمَ: خارجُ النافذة 401 دوماً).
package transport

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"
)

// ترويساتُ العقد — أسماؤها في docblock ‏Es256 البند ٣ حرفياً.
const (
	HeaderID        = "X-Endpoint-Id"
	HeaderTimestamp = "X-Endpoint-Timestamp"
	HeaderNonce     = "X-Endpoint-Nonce"
	HeaderSignature = "X-Endpoint-Signature"
)

// ClockWindow — نافذةُ قبول الطابع عند الخادم (±300 ثانية — عقدُ Es256 البند ٣).
const ClockWindow = 300 * time.Second

// skewThreshold — دون هذا الفرق عن ساعة الخادم لا انحرافَ يُحفَظ (ضجيجُ شبكة).
const skewThreshold = 30 * time.Second

// Canonical — السلسلةُ القانونية الموقَّعة: مرآةُ `Es256::canonical` بالحرف.
func Canonical(method, path, timestamp, nonce string, body []byte) string {
	sum := sha256.Sum256(body)
	return strings.ToUpper(method) + "\n" + path + "\n" + timestamp + "\n" + nonce + "\n" + hex.EncodeToString(sum[:])
}

// NewNonce — ‏nonce من crypto/rand: ‏24 بايتاً على base64url بلا حشو تعطي 32
// حرفاً من [A-Za-z0-9_-] — داخل عقد الـnonce (‏8–64 من [A-Za-z0-9._-]).
func NewNonce() (string, error) {
	var b [24]byte
	if _, err := rand.Read(b[:]); err != nil {
		return "", fmt.Errorf("توليدُ nonce: %w", err)
	}
	return base64.RawURLEncoding.EncodeToString(b[:]), nil
}

// Signer — ما يحتاجه النقلُ من الهويّة: توقيعُ بايتاتٍ إلى base64(DER) لا غير —
// فلا يرى النقلُ المفتاحَ الخاصَّ ولا يستطيع تسليكَه.
type Signer interface {
	Sign(data []byte) (string, error)
}

// Client — عميلُ HTTP موقِّع: كلُّ طلبٍ يخرج منه يحمل الترويساتِ الأربع
// وتوقيعاً على السلسلة القانونية — لا مسارَ طلبٍ غيرَ موقَّعٍ فيه.
type Client struct {
	base     string
	deviceID string
	signer   Signer
	http     *http.Client

	mu   sync.Mutex
	skew time.Duration
	now  func() time.Time // قابلةٌ للحقن في الاختبارات
}

// NewClient — يبني العميلَ الموقِّع على خادمٍ وهويّةِ جهازٍ (X-Endpoint-Id).
func NewClient(baseURL, deviceID string, signer Signer) (*Client, error) {
	u, err := url.Parse(baseURL)
	if err != nil || u.Scheme == "" || u.Host == "" {
		return nil, fmt.Errorf("عنوانُ خادمٍ غيرُ صالح: %q", baseURL)
	}
	if deviceID == "" {
		return nil, errors.New("معرّفُ الجهاز (X-Endpoint-Id) مطلوب")
	}
	if signer == nil {
		return nil, errors.New("موقِّعٌ مطلوب — لا طلبَ بلا توقيع")
	}
	return &Client{
		base:     strings.TrimRight(baseURL, "/"),
		deviceID: deviceID,
		signer:   signer,
		http:     &http.Client{Timeout: 30 * time.Second},
		now:      time.Now,
	}, nil
}

// Do — يرسل طلباً موقَّعاً بعقد Es256. `path` مطلقٌ مبدوءٌ بـ«/» **بلا** سلسلة
// استعلامٍ (البند ١ من العقد — ما يُوقَّع هو ما يصل حرفياً).
func (c *Client) Do(ctx context.Context, method, path string, body []byte) (*http.Response, error) {
	if !strings.HasPrefix(path, "/") || strings.ContainsAny(path, "?#") {
		return nil, fmt.Errorf("مسارٌ خارج عقد التوقيع (مطلقٌ بلا استعلام): %q", path)
	}

	ts := strconv.FormatInt(c.now().Add(c.currentSkew()).Unix(), 10)
	nonce, err := NewNonce()
	if err != nil {
		return nil, err
	}
	sig, err := c.signer.Sign([]byte(Canonical(method, path, ts, nonce, body)))
	if err != nil {
		return nil, err
	}

	req, err := http.NewRequestWithContext(ctx, strings.ToUpper(method), c.base+path, bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set(HeaderID, c.deviceID)
	req.Header.Set(HeaderTimestamp, ts)
	req.Header.Set(HeaderNonce, nonce)
	req.Header.Set(HeaderSignature, sig)

	resp, err := c.http.Do(req)
	if err != nil {
		return nil, err
	}
	c.observeServerDate(resp)
	return resp, nil
}

// observeServerDate — انضباطُ الساعة: يقرأ `Date` من الردّ ويحفظ الانحرافَ عن
// الساعة المحليّة إن جاوز العتبة — فالطلبُ التالي يقع داخل نافذة ±300 ثانية.
func (c *Client) observeServerDate(resp *http.Response) {
	raw := resp.Header.Get("Date")
	if raw == "" {
		return
	}
	server, err := http.ParseTime(raw)
	if err != nil {
		return
	}
	delta := server.Sub(c.now())
	c.mu.Lock()
	defer c.mu.Unlock()
	if delta > skewThreshold || delta < -skewThreshold {
		c.skew = delta
	} else {
		c.skew = 0
	}
}

func (c *Client) currentSkew() time.Duration {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.skew
}
