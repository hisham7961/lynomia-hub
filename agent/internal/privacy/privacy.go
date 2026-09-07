// Package privacy — **مرآةُ مُصادِق الخصوصيّة الخادميّ** (الطور K · §45):
// النسخةُ العميلة من `App\Support\EndpointPrivacy::violations` حرفياً — بوّابةُ
// عمقٍ قبل الإرسال: الخادمُ يرُدّ حقولَ المراقبة 422 ولا يخزّنها، والوكيلُ
// السليم لا يبنيها أصلاً — فإن بناها عيبٌ ما، تُمسَك هنا قبل أن تغادر الجهاز.
//
// المطابقةُ بالمفتاح وبالعمق بعد تطبيعٍ (حروفٌ صغيرة، الفواصلُ `_`)،
// وبالاحتواء لا بالتساوي — fail-closed عمداً كما عند الخادم.
//
// هذه الحزمةُ هي الموضعُ **الوحيد** في شجرة الوكيل الذي يحمل ألفاظَ المراقبة
// (قائمةَ رفضٍ لا جامعاً) — ويثبت ذلك برهانُ grep في internal/guardrails.
package privacy

import "strings"

// forbidden — مرآةُ `EndpointPrivacy::FORBIDDEN` بالحرف.
var forbidden = []string{
	// لوحةُ المفاتيح
	"keystroke", "keylog", "keyboard_capture",
	// الشاشة
	"screenshot", "screen_capture", "screencap", "screen_record", "screen_grab",
	// الحافظة
	"clipboard",
	// التصفّح والتاريخ
	"browsing", "browser_history", "history", "visited_url", "web_activity",
	// محتوى الملفات وحصادُها
	"file_content", "file_dump", "file_harvest", "document_content",
	// الكاميرا والميكروفون
	"camera", "webcam", "microphone", "mic_capture", "audio_capture", "video_capture",
	// المكالمات والرسائل
	"sms", "call_log", "call_record", "call_intercept", "message_log",
	"message_content", "chat_log", "im_log", "wiretap",
	// التقاطُ الشبكة (محتوىً لا وضعية — network_self وضعيّةُ الجهاز نفسِه فمسموحة)
	"packet_capture", "pcap", "traffic_dump",
}

var normalizer = strings.NewReplacer("-", "_", " ", "_", ".", "_")

// Violations — مساراتُ مفاتيح المراقبة في الحمولة بالعمق (`meta.screenshot`)
// أو nil إن نظيفة — المفاتيحُ وحدَها تُفحَص لا القيَم (مرآةُ الخادم).
func Violations(payload map[string]any) []string {
	return walk(payload, "")
}

func walk(payload map[string]any, prefix string) []string {
	var bad []string
	for key, value := range payload {
		path := key
		if prefix != "" {
			path = prefix + "." + key
		}
		norm := strings.ToLower(normalizer.Replace(key))
		for _, token := range forbidden {
			if strings.Contains(norm, token) {
				bad = append(bad, path)
				break
			}
		}
		if nested, ok := value.(map[string]any); ok {
			bad = append(bad, walk(nested, path)...)
		}
	}
	return bad
}
