// Package update — التحديثُ الذاتيّ **المتحقَّق** (الطور K · WP-K.2 · §45–62).
//
// البيانُ {url, sha256} يُجلَب، والثنائيّةُ تهبط إلى ملفٍ مؤقت **في مجلد
// الهدف نفسِه**، وتجزئتُها sha256 تُحسَب وتُقارَن بالبيان **قبل** أي تبديل:
// المطابقةُ تُتبَع بـrename ذرّيّ (المجلدُ الواحد يضمنه)، وعدمُها **رفضٌ
// قاطع** — يُمحى المؤقت وتبقى الثنائيّةُ الحالية بلا مساس. الملفُ الهابط لا
// يُنفَّذ هنا أبداً (لا إطلاقَ عملياتٍ في الشجرة كلِّها أصلاً — برهانُ
// guardrails) — التبديلُ كتابةُ ملفٍ لا غير، والنسخةُ الجديدة تعمل عند
// إعادة تشغيل الخدمة.
package update

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
)

// Manifest — بيانُ التحديث: عنوانُ الثنائيّة وتجزئتُها sha256 (hex-64)، ومعها
// حقولُ سلسلةِ الثقة (§5–§7) التي يرسلها الخادم: `Version` النسخةُ المعروضة،
// و`MinAgentVersion` أدنى نسخةِ وكيلٍ متوافقةٍ للترقية الآمنة (جسرُ الترقية).
// الحقولُ الزائدةُ الأخرى في JSON تُهمَل بلا كسر — العقدُ الأدنى {url, sha256}.
type Manifest struct {
	URL             string `json:"url"`
	SHA256          string `json:"sha256"`
	Version         string `json:"version"`
	MinAgentVersion string `json:"min_agent_version"`
}

// maxBinary — سقفُ حجم الثنائيّة الهابطة (دفاعاً عن القرص) — 256 م.ب.
const maxBinary = 256 << 20

// FetchManifest — يجلب بيانَ التحديث JSON من عنوانه.
func FetchManifest(ctx context.Context, httpc *http.Client, url string) (Manifest, error) {
	var m Manifest
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return m, err
	}
	resp, err := httpc.Do(req)
	if err != nil {
		return m, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return m, fmt.Errorf("جلبُ البيان: HTTP %d", resp.StatusCode)
	}
	raw, err := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if err != nil {
		return m, err
	}
	if err := json.Unmarshal(raw, &m); err != nil {
		return m, fmt.Errorf("بيانٌ غيرُ مفهوم: %w", err)
	}
	return m, nil
}

// validate — شكلُ البيان قبل أي تنزيل: عنوانٌ حاضرٌ وتجزئةٌ hex-64.
func validate(m Manifest) error {
	if strings.TrimSpace(m.URL) == "" {
		return errors.New("بيانُ تحديثٍ بلا عنوان")
	}
	sum := strings.ToLower(strings.TrimSpace(m.SHA256))
	if len(sum) != 64 {
		return errors.New("تجزئةُ البيان ليست sha256 hex (64 محرفاً)")
	}
	if _, err := hex.DecodeString(sum); err != nil {
		return errors.New("تجزئةُ البيان ليست hex صالحاً")
	}
	return nil
}

// Apply — ينزّل ويتحقق **ثم** يبدّل: أيُّ انحرافِ تجزئةٍ رفضٌ يمحو المؤقتَ
// ويترك الهدفَ حرفياً كما كان — لا تبديلَ أعمى ولا تنفيذَ لغير المتحقَّق.
func Apply(ctx context.Context, httpc *http.Client, m Manifest, target string) (err error) {
	if err := validate(m); err != nil {
		return err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, m.URL, nil)
	if err != nil {
		return err
	}
	resp, err := httpc.Do(req)
	if err != nil {
		return fmt.Errorf("تنزيلُ التحديث: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("تنزيلُ التحديث: HTTP %d", resp.StatusCode)
	}

	// المؤقتُ في مجلد الهدف نفسِه — شرطُ ذرّيّة rename على نظام الملفات الواحد
	tmp, err := os.CreateTemp(filepath.Dir(target), ".lynomia-agent-update-*")
	if err != nil {
		return err
	}
	tmpPath := tmp.Name()
	defer func() {
		if err != nil {
			_ = os.Remove(tmpPath) // الرفضُ لا يترك أثراً مؤقتاً
		}
	}()

	hasher := sha256.New()
	_, copyErr := io.Copy(io.MultiWriter(tmp, hasher), io.LimitReader(resp.Body, maxBinary))
	closeErr := tmp.Close()
	if copyErr != nil {
		err = fmt.Errorf("كتابةُ المؤقت: %w", copyErr)
		return err
	}
	if closeErr != nil {
		err = closeErr
		return err
	}

	// **التحقّقُ قبل التبديل** — جوهرُ العقد: انحرافٌ = رفضٌ قاطع.
	got := hex.EncodeToString(hasher.Sum(nil))
	want := strings.ToLower(strings.TrimSpace(m.SHA256))
	if got != want {
		err = fmt.Errorf("تجزئةُ التنزيل لا تطابق البيان (رفضٌ قاطع): وُجد %s والمطلوب %s", got, want)
		return err
	}

	if err = os.Chmod(tmpPath, 0o755); err != nil {
		return err
	}
	if err = os.Rename(tmpPath, target); err != nil {
		err = fmt.Errorf("التبديلُ الذرّيّ: %w", err)
		return err
	}
	return nil
}
