// Package network — شبكةُ **الجهاز نفسِه فقط** (الطور K · WP-K.2 · §45–62).
//
// الجامعُ يقرأ net.Interfaces حصراً: اسمُ الواجهة وMAC وعناوينُها وحالُها —
// و‏SSID الجهاز عبر واجهة المنصّة إن أتاحته وإلا not-configured بصدق.
// **لا مسحَ إطلاقاً:** لا اتصالَ بمضيفٍ آخر، لا نطاقات، لا ARP — الحزمةُ لا
// تفتح أيَّ اتصالٍ أصلاً (برهانُ grep في internal/guardrails)، وحمولتُها تسافر
// حدثَ kind=network_self ضمن allowlist أنواع الخادم.
package network

import (
	"net"
	"strings"

	"lynomia/agent/internal/platform"
)

// Interface — واجهةٌ محليّة واحدة: ميتاداتا الجهاز نفسِه لا غير.
type Interface struct {
	Name string   `json:"name"`
	MAC  string   `json:"mac"`
	Up   bool     `json:"up"`
	IPs  []string `json:"ips"`
}

// Collect — واجهاتُ الجهاز المحليّة من net.Interfaces — المصدرُ الوحيد.
func Collect() ([]Interface, error) {
	ifaces, err := net.Interfaces()
	if err != nil {
		return nil, err
	}
	out := make([]Interface, 0, len(ifaces))
	for _, ifi := range ifaces {
		entry := Interface{
			Name: ifi.Name,
			MAC:  ifi.HardwareAddr.String(),
			Up:   ifi.Flags&net.FlagUp != 0,
		}
		addrs, err := ifi.Addrs()
		if err == nil {
			for _, a := range addrs {
				entry.IPs = append(entry.IPs, a.String())
			}
		}
		out = append(out, entry)
	}
	return out, nil
}

// WifiPosture — وضعيّةُ Wi-Fi من **اتصال الجهاز نفسِه فقط** (§10 — لا مسحَ شبكة):
// SSID الذي يتّصل به الجهازُ (عبر واجهة المنصّة) وحالةُ قراءته الصادقة. الخادمُ
// يقيّمه على قائمة SSID المعتمدة — والوكيلُ لا يحكم ولا يمسح.
//
//   - `readable`    — قُرئ SSID اتصالِ الجهاز.
//   - `unavailable` — لم تُتِح المنصّةُ SSID (لا واجهةَ نظامٍ، أو غيرُ متّصل) —
//     يُبلَّغ بصدقٍ ولا يُدّعى اتصالٌ معتمد.
//
// (تمييزُ permission-denied/unsupported متاحٌ في عقد الخادم؛ واجهةُ المنصّة
// الحاليّة تعيد نجاحاً/فشلاً فحسب، فيُبلَّغ الفشلُ unavailable بصدق.)
func WifiPosture() map[string]any {
	status := "unavailable"
	ssid := ""
	if v, ok := platform.SSID(); ok && strings.TrimSpace(v) != "" {
		ssid = strings.TrimSpace(v)
		status = "readable"
	}
	return map[string]any{"ssid": ssid, "status": status}
}

// Summary — الحمولةُ السلكيّة لحدث network_self: الواجهاتُ المحليّة + SSID
// (أو not-configured حيث لا واجهةَ منصّةٍ تتيحه).
func Summary() (map[string]any, error) {
	ifaces, err := Collect()
	if err != nil {
		return nil, err
	}
	ssid := "not-configured"
	if v, ok := platform.SSID(); ok && v != "" {
		ssid = v
	}
	return map[string]any{"interfaces": ifaces, "ssid": ssid}, nil
}
