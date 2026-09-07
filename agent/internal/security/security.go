// Package security — قراءةُ الوضعيّة **الصادقة** (الطور K · WP-K.2 · §45–62).
//
// مرآةُ قاعدة C15 عند الخادم (heartbeat): القائمةُ المغلقة
// {active|inactive|not-configured} لا غير — وقارئٌ منعه النظامُ أو أعاد ما لا
// يُفهم **يهبط** إلى not-configured، فلا يدّعي الوكيلُ نشاطَ حمايةٍ لم يقرأها
// حرفياً. القرّاءُ لكل منصّةٍ خلف وسومِ بناءٍ (readers_*.go) يستندون إلى
// internal/platform/{windows,darwin} — وبديلُ المنصّة الغائبة صادقٌ: كلُّ
// الفحوص not-configured.
package security

import "strings"

// Reader — قارئُ فحصٍ واحد: يعيد قراءتَه النصيّة أو خطأً (مَنْعاً أو غياباً).
type Reader func() (string, error)

// CanonicalChecks — الفحوصُ الأربعة القانونية؛ الغائبُ عن قرّاء المنصّة يُبلَّغ
// not-configured لا يُسكَت عنه.
var CanonicalChecks = []string{"firewall", "disk_encryption", "defender", "updates"}

// closed — القائمةُ المغلقة نفسُها التي يفرضها الخادم (C15).
var closed = map[string]bool{"active": true, "inactive": true, "not-configured": true}

// CollectFrom — جوهرُ الصدق القابلُ للاختبار: خطأُ القارئ أو قراءةٌ خارج
// القائمة المغلقة ⇒ not-configured — **أبداً** لا active بلا إبلاغٍ حرفيّ.
func CollectFrom(readers map[string]Reader) map[string]string {
	posture := map[string]string{}
	for _, check := range CanonicalChecks {
		posture[check] = "not-configured"
	}
	for check, read := range readers {
		reading, err := read()
		if err != nil {
			posture[check] = "not-configured"
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
