// Package security — قراءةُ الوضعيّة **الصادقة** (الطور K · WP-K.2 · §45–62).
//
// مرآةُ قاعدة C15 عند الخادم (heartbeat): القائمةُ المغلقة
// {active|inactive|not-configured} لا غير — وقارئٌ منعه النظامُ أو أعاد ما لا
// يُفهم **يهبط** إلى not-configured، فلا يدّعي الوكيلُ نشاطَ حمايةٍ لم يقرأها
// حرفياً. القرّاءُ لكل منصّةٍ خلف وسومِ بناءٍ (readers_*.go) يستندون إلى
// internal/platform/{windows,darwin} — وبديلُ المنصّة الغائبة صادقٌ: كلُّ
// الفحوص not-configured.
package security

import (
	"errors"
	"strings"
)

// Reader — قارئُ فحصٍ واحد: يعيد قراءتَه النصيّة أو خطأً (مَنْعاً أو غياباً).
type Reader func() (string, error)

// أخطاءُ العقد الموسَّع (§11) — يبلّغها القارئُ ليميّز الخادمُ الحالةَ الصادقة:
// «مُنع القارئ» يُبلَّغ permission-denied (**ليس امتثالاً**) لا يُطمَس not-configured.
// خطأٌ عامٌّ (بلا سِمة) يبقى not-configured كما كان — توافقٌ رجعيّ.
var (
	ErrPermissionDenied = errors.New("permission-denied")
	ErrUnavailable      = errors.New("unavailable")
	ErrUnsupported      = errors.New("unsupported")
)

// CanonicalChecks — الفحوصُ الأربعة القانونية؛ الغائبُ عن قرّاء المنصّة يُبلَّغ
// not-configured لا يُسكَت عنه.
var CanonicalChecks = []string{"firewall", "disk_encryption", "defender", "updates"}

// closed — القائمةُ المغلقة الموسَّعة (§11) نفسُها التي يفرضها الخادم (C15):
// active/inactive/permission-denied/unavailable/unsupported/not-configured.
var closed = map[string]bool{
	"active": true, "inactive": true, "permission-denied": true,
	"unavailable": true, "unsupported": true, "not-configured": true,
}

// CollectFrom — جوهرُ الصدق القابلُ للاختبار: قراءةٌ خارج القائمة المغلقة ⇒
// not-configured؛ وخطأٌ مُسمّىً يهبط لحالته الصادقة (permission-denied/unavailable/
// unsupported)، والعامُّ not-configured. **أبداً** لا active بلا إبلاغٍ حرفيّ.
func CollectFrom(readers map[string]Reader) map[string]string {
	posture := map[string]string{}
	for _, check := range CanonicalChecks {
		posture[check] = "not-configured"
	}
	for check, read := range readers {
		reading, err := read()
		if err != nil {
			switch {
			case errors.Is(err, ErrPermissionDenied):
				posture[check] = "permission-denied" // §11: مُنع القراءة ≠ امتثال
			case errors.Is(err, ErrUnsupported):
				posture[check] = "unsupported"
			case errors.Is(err, ErrUnavailable):
				posture[check] = "unavailable"
			default:
				posture[check] = "not-configured" // خطأٌ عامّ — توافقٌ رجعيّ
			}
			continue
		}
		reading = strings.ToLower(strings.TrimSpace(reading))
		if !closed[reading] {
			reading = "not-configured"
		}
		posture[check] = reading
	}
	return posture
}

// Collect — وضعيّةُ هذه المنصّة عبر قرّائها الموسومين.
func Collect() map[string]string {
	return CollectFrom(platformReaders())
}
