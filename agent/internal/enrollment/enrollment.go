// Package enrollment — تسجيلُ الجهاز لدى الخادم (الطور K · WP-K.1 · §45).
//
// مرآةُ `EndpointEnrollController::enroll`: طلبٌ واحد POST إلى
// `/api/v1/endpoint/enroll` يحمل الرمزَ وهويّةَ الجهاز والمفتاحَ **العامَّ** PEM
// وحدَه — المفتاحُ الخاصُّ لا يبلغ الحمولةَ أبداً (الخادمُ يرُدّ أيَّ مادةِ
// «PRIVATE KEY» بـ422 قبل كل شيء، وهنا حارسُ عمقٍ يمنعها قبل الإرسال أصلاً).
// ردُّ 201 يعلن `device_id` ومساراتِ البروتوكول الأربعة — تُخزَّن حرفياً في
// `state.json` (‏0600) ولا تُخترَع محليّاً؛ فبابُ الوكيل إلى مساراته هو ردُّ
// التسجيل لا ثابتٌ مدفون.
//
// التسجيلُ أحاديّ الطلقة: حالةٌ قائمةٌ تعني جهازاً مسجَّلاً — إعادةُ التسجيل
// تتطلب `--force` صراحةً (و`device_uuid` يبقى ثابتاً عبرها، فالخادمُ يميّز
// الهويّةَ المكرَّرة بنفسه ويرُدّ 409).
package enrollment

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
	"time"

	"lynomia/agent/internal/identity"
)

// EnrollPath — مسارُ التسجيل العامّ (بالرمز — غيرُ موقَّعٍ بعقد Es256 لأن
// الجهازَ لا هويّةَ له لدى الخادم بعد).
const EnrollPath = "/api/v1/endpoint/enroll"

const (
	stateFileName = "state.json"
	uuidFileName  = "device_uuid"
)

// ErrAlreadyEnrolled — حالةٌ قائمة: التسجيلُ أحاديّ الطلقة إلا بإجبارٍ صريح.
var ErrAlreadyEnrolled = errors.New("الجهازُ مسجَّلٌ فعلاً — أعد التسجيل بـ--force إن كنت تقصده")

// ErrNotEnrolled — لا حالةَ بعد: نفّذ enroll أولاً.
var ErrNotEnrolled = errors.New("الجهازُ غيرُ مسجَّلٍ بعد — نفّذ enroll أولاً")

// عقدُ device_uuid عند الخادم: ^[A-Za-z0-9._:-]{8,64}$
var uuidRe = regexp.MustCompile(`^[A-Za-z0-9._:-]{8,64}$`)

// State — ما أعلنه ردُّ التسجيل 201 حرفياً + هويّةُ الخادم المسجَّلُ لديه.
type State struct {
	Server             string    `json:"server"`
	DeviceID           string    `json:"device_id"`
	PubkeyFP           string    `json:"pubkey_fp"`
	DeviceUUID         string    `json:"device_uuid"`
	HeartbeatPath      string    `json:"heartbeat_path"`
	EventPath          string    `json:"event_path"`
	CommandsPullPath   string    `json:"commands_pull_path"`
	CommandsResultPath string    `json:"commands_result_path"`
	EnrolledAt         time.Time `json:"enrolled_at"`
}

// Options — مدخلاتُ التسجيل. Hostname الفارغ يُقرأ من النظام، وHTTP الفارغ
// عميلٌ افتراضيّ بمهلة.
type Options struct {
	Server       string
	Token        string
	AgentVersion string
	Hostname     string
	Force        bool
	HTTP         *http.Client
}

// LoadState — يحمّل حالةَ التسجيل المخزَّنة أو ErrNotEnrolled إن لم توجد.
func LoadState(dir string) (*State, error) {
	blob, err := os.ReadFile(filepath.Join(dir, stateFileName))
	if errors.Is(err, os.ErrNotExist) {
		return nil, ErrNotEnrolled
	}
	if err != nil {
		return nil, err
	}
	var st State
	if err := json.Unmarshal(blob, &st); err != nil {
		return nil, fmt.Errorf("حالةُ تسجيلٍ فاسدة: %w", err)
	}
	if st.DeviceID == "" {
		return nil, ErrNotEnrolled
	}
	return &st, nil
}

// OSName — يحوّل runtime.GOOS إلى قائمة الخادم المغلقة {windows|macos|linux}؛
// نظامٌ خارجها خطأٌ صريح — لا قيمةَ تُختلَق لإرضاء التحقق.
func OSName() (string, error) {
	switch runtime.GOOS {
	case "windows":
		return "windows", nil
	case "darwin":
		return "macos", nil
	case "linux":
		return "linux", nil
	}
	return "", fmt.Errorf("نظامُ تشغيلٍ خارج عقد الخادم: %s", runtime.GOOS)
}

// deviceUUID — هويّةُ عتادٍ مستقرّة: تُولَّد عشوائياً مرةً واحدة (32 hex من
// crypto/rand — داخل عقد الخادم) وتُخزَّن محليّاً فتثبت عبر إعادات التسجيل.
func deviceUUID(dir string) (string, error) {
	path := filepath.Join(dir, uuidFileName)
	if blob, err := os.ReadFile(path); err == nil {
		if u := strings.TrimSpace(string(blob)); uuidRe.MatchString(u) {
			return u, nil
		}
	}
	var raw [16]byte
	if _, err := rand.Read(raw[:]); err != nil {
		return "", fmt.Errorf("توليدُ device_uuid: %w", err)
	}
	u := hex.EncodeToString(raw[:])
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return "", err
	}
	if err := os.WriteFile(path, []byte(u), 0o600); err != nil {
		return "", err
	}
	return u, nil
}

// enrollResponse — جسمُ ردّ 201 كما يعلنه EndpointEnrollController حرفياً.
type enrollResponse struct {
	OK                 bool   `json:"ok"`
	DeviceID           string `json:"device_id"`
	PubkeyFP           string `json:"pubkey_fp"`
	HeartbeatPath      string `json:"heartbeat_path"`
	EventPath          string `json:"event_path"`
	CommandsPullPath   string `json:"commands_pull_path"`
	CommandsResultPath string `json:"commands_result_path"`
}

// Enroll — يسجّل الجهازَ ويخزّن الحالة. أحاديّ الطلقة (ErrAlreadyEnrolled بلا
// Force)، والحمولةُ تحمل المفتاحَ العامَّ وحدَه — حارسُ العمق أدناه يرفض أيَّ
// مادةِ مفتاحٍ خاصّ قبل أن تغادر الجهاز.
func Enroll(dir string, id *identity.Identity, opts Options) (*State, error) {
	if opts.Server == "" || opts.Token == "" {
		return nil, errors.New("عنوانُ الخادم ورمزُ التسجيل مطلوبان")
	}
	if _, err := LoadState(dir); err == nil && !opts.Force {
		return nil, ErrAlreadyEnrolled
	}

	osName, err := OSName()
	if err != nil {
		return nil, err
	}
	host := strings.TrimSpace(opts.Hostname)
	if host == "" {
		if host, err = os.Hostname(); err != nil {
			return nil, fmt.Errorf("قراءةُ اسم المضيف: %w", err)
		}
	}
	uuid, err := deviceUUID(dir)
	if err != nil {
		return nil, err
	}
	pubPEM, err := id.PublicPEM()
	if err != nil {
		return nil, err
	}

	payload := map[string]any{
		"token":       opts.Token,
		"device_uuid": uuid,
		"hostname":    host,
		"os":          osName,
		"public_key":  pubPEM,
	}
	if opts.AgentVersion != "" {
		payload["agent_version"] = opts.AgentVersion
	}
	body, err := json.Marshal(payload)
	if err != nil {
		return nil, err
	}
	// حارسُ العمق (نظيرُ حارس الخادم 422): لا حرفَ مفتاحٍ خاصّ يغادر الجهاز أبداً
	if bytes.Contains(body, []byte("PRIVATE KEY")) {
		return nil, errors.New("حمولةُ التسجيل تحمل مادةَ مفتاحٍ خاصّ — رفضٌ قاطعٌ قبل الإرسال")
	}

	httpc := opts.HTTP
	if httpc == nil {
		httpc = &http.Client{Timeout: 30 * time.Second}
	}
	req, err := http.NewRequestWithContext(context.Background(), http.MethodPost,
		strings.TrimRight(opts.Server, "/")+EnrollPath, bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")

	resp, err := httpc.Do(req)
	if err != nil {
		return nil, fmt.Errorf("طلبُ التسجيل: %w", err)
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))

	if resp.StatusCode != http.StatusCreated {
		// رسالةُ الخادم تُسطَّح كما هي (غلافُ Api::error يحمل message) — لا تخمين
		var apiErr struct {
			Message string `json:"message"`
		}
		_ = json.Unmarshal(raw, &apiErr)
		if apiErr.Message == "" {
			apiErr.Message = strings.TrimSpace(string(raw))
		}
		return nil, fmt.Errorf("رفض الخادمُ التسجيلَ (HTTP %d): %s", resp.StatusCode, apiErr.Message)
	}

	var er enrollResponse
	if err := json.Unmarshal(raw, &er); err != nil {
		return nil, fmt.Errorf("ردُّ تسجيلٍ غيرُ مفهوم: %w", err)
	}
	if er.DeviceID == "" || er.HeartbeatPath == "" || er.EventPath == "" ||
		er.CommandsPullPath == "" || er.CommandsResultPath == "" {
		return nil, errors.New("ردُّ التسجيل ناقصُ الإعلان (device_id أو مساراتُ البروتوكول)")
	}

	st := &State{
		Server:             strings.TrimRight(opts.Server, "/"),
		DeviceID:           er.DeviceID,
		PubkeyFP:           er.PubkeyFP,
		DeviceUUID:         uuid,
		HeartbeatPath:      er.HeartbeatPath,
		EventPath:          er.EventPath,
		CommandsPullPath:   er.CommandsPullPath,
		CommandsResultPath: er.CommandsResultPath,
		EnrolledAt:         time.Now().UTC(),
	}
	blob, err := json.MarshalIndent(st, "", "  ")
	if err != nil {
		return nil, err
	}
	if err := os.WriteFile(filepath.Join(dir, stateFileName), blob, 0o600); err != nil {
		return nil, fmt.Errorf("كتابةُ حالة التسجيل: %w", err)
	}
	return st, nil
}
