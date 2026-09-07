// Package inventory — جردُ الجهاز الصادق (الطور K · WP-K.2 · §45–62).
//
// الحقائقُ من واجهات Go والمنصّة حصراً (runtime/os + internal/platform عبر
// وسوم البناء): ما لا تبلغه واجهةٌ **يُحذَف** لا يُختلَق. الحمولةُ تطابق شكلَ
// heartbeat عند الخادم (hostname/agent_version/hw/posture) ومفاتيحُها من
// قائمةٍ معلومةٍ نظيفةٍ من ألفاظ المراقبة (يثبتها الاختبارُ ببوّابة privacy).
package inventory

import (
	"os"
	"runtime"

	"lynomia/agent/internal/enrollment"
	"lynomia/agent/internal/platform"
)

// HW — حقائقُ العتاد: الأساسُ المحمول (os/arch/cpu_count) + حقائقُ المنصّة
// الموسومة (os_version/cpu/model/memory_mb حيث تتاح).
func HW() map[string]any {
	hw := map[string]any{
		"arch":      runtime.GOARCH,
		"cpu_count": runtime.NumCPU(),
	}
	// اسمُ النظام على قائمة الخادم المغلقة {windows|macos|linux}؛ الخارجُ عنها
	// يُحذَف بصدقٍ (لا يبلغ التسجيلَ أصلاً على نظامٍ كهذا).
	if osName, err := enrollment.OSName(); err == nil {
		hw["os"] = osName
	}
	for k, v := range platform.HW() {
		hw[k] = v
	}
	return hw
}

// HeartbeatBody — جسمُ النبضة بعقد heartbeat حرفياً: الحقولُ الأربعة لا غير،
// والغائبُ (اسمُ مضيفٍ تعذّر) يُحذَف لا يُختلَق.
func HeartbeatBody(agentVersion string, posture map[string]string) map[string]any {
	body := map[string]any{
		"agent_version": agentVersion,
		"hw":            HW(),
	}
	if host, err := os.Hostname(); err == nil && host != "" {
		body["hostname"] = host
	}
	if posture != nil {
		body["posture"] = posture
	}
	return body
}
