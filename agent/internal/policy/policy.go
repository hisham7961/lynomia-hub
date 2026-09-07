// Package policy — تطبيقُ سياسةٍ **تدقيقيّة لا فارضة** (الطور K · WP-K.2 · §45–62).
//
// أمرُ apply_policy يطبّق قيَمَ **إبلاغٍ** من allowlist مغلقةٍ (وسمٌ، إيقاعُ
// تقارير، تفعيلُ تدقيق USB/الوضعيّة) تُخزَّن محليّاً policy.json (‏0600)
// وتُمرأى على Windows في سجلّ المستخدم (قيَمُ قراءةٍ لا مفاتيحُ فرض). وكلُّ
// طلبِ **فرضٍ** (حجبُ USB، إجبارُ تشفير…) يُبلَّغ `requires-MDM` بالاسم —
// فلا حجبَ وهميّاً ولا ادّعاءَ إنفاذٍ لا يملكه وكيلُ مستخدمٍ بلا MDM
// (المؤجَّلُ الصريح في خطة الطور K).
package policy

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sort"

	"lynomia/agent/internal/platform"
)

const policyFileName = "policy.json"

// auditKeys — allowlist مفاتيحِ التدقيق التي يملك الوكيلُ تطبيقَها بنفسه:
// قيَمُ إبلاغٍ محضة — لا مفتاحَ سلوكٍ فارضٍ بينها.
var auditKeys = map[string]bool{
	"tag":                 true, // وسمُ أسطولٍ يظهر في الجرد
	"report_interval_min": true, // إيقاعُ التقارير المطلوب
	"usb_audit":           true, // تدقيقُ أحداث USB (إبلاغٌ لا حجب)
	"posture_audit":       true, // تدقيقُ الوضعيّة
}

// Result — حصيلةُ التطبيق الصادقة: المطبَّقُ فعلاً والمؤجَّلُ إلى MDM بالاسم.
type Result struct {
	Applied     map[string]any
	RequiresMDM []string
}

// Apply — يفرز المعاملات على الـallowlist: التدقيقيُّ يُطبَّق ويُخزَّن
// policy.json (‏0600) ويُمرأى على مخزن المنصّة؛ وسواه يُبلَّغ requires-MDM
// **دون أيّ أثرٍ** — لا ادّعاءَ إنفاذ.
func Apply(dir string, args map[string]any) (Result, error) {
	res := Result{Applied: map[string]any{}}
	for key, value := range args {
		if auditKeys[key] {
			res.Applied[key] = value
		} else {
			res.RequiresMDM = append(res.RequiresMDM, key)
		}
	}
	sort.Strings(res.RequiresMDM) // ترتيبٌ حتميّ للتقرير والاختبار

	if len(res.Applied) > 0 {
		if err := os.MkdirAll(dir, 0o700); err != nil {
			return res, err
		}
		blob, err := json.MarshalIndent(res.Applied, "", "  ")
		if err != nil {
			return res, err
		}
		if err := os.WriteFile(filepath.Join(dir, policyFileName), blob, 0o600); err != nil {
			return res, fmt.Errorf("كتابةُ policy.json: %w", err)
		}
		// مرآةُ المنصّة (سجلُّ Windows) قيَماً نصيّة — إبلاغٌ best-effort:
		// فشلُها لا يُبطل السجلَّ الأصل policy.json.
		mirror := map[string]string{}
		for k, v := range res.Applied {
			mirror[k] = fmt.Sprint(v)
		}
		_ = platform.MirrorPolicy(mirror)
	}
	return res, nil
}

// Payload — نتيجةُ الأمر السلكيّة: تعلن الوضعَ audit-only دوماً وتسمّي
// المؤجَّلَ إلى MDM — الصدقُ جزءٌ من العقد لا زينة.
func (r Result) Payload() map[string]any {
	p := map[string]any{
		"mode":         "audit-only",
		"applied":      r.Applied,
		"requires_mdm": r.RequiresMDM,
	}
	if r.RequiresMDM == nil {
		p["requires_mdm"] = []string{}
	}
	return p
}
