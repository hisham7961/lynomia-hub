package inventory

import (
	"os"
	"runtime"
	"testing"

	"lynomia/agent/internal/privacy"
)

// جردُ العتاد: حقائقُ Go APIs لا غير، ومفاتيحُه من قائمةٍ معلومة — ما لا
// تبلغه واجهةُ النظام يُحذَف لا يُختلَق.
func TestHWFactsShape(t *testing.T) {
	hw := HW()
	allowed := map[string]bool{
		"os": true, "arch": true, "cpu_count": true,
		"os_version": true, "cpu": true, "model": true, "memory_mb": true,
	}
	for k := range hw {
		if !allowed[k] {
			t.Fatalf("مفتاحُ جردٍ خارج القائمة المعلومة: %q", k)
		}
	}
	if hw["arch"] != runtime.GOARCH {
		t.Fatalf("arch = %v؛ المطلوب %s", hw["arch"], runtime.GOARCH)
	}
	if n, ok := hw["cpu_count"].(int); !ok || n < 1 {
		t.Fatalf("cpu_count غيرُ سليم: %v", hw["cpu_count"])
	}
	if runtime.GOOS == "linux" && hw["os"] != "linux" {
		t.Fatalf("os = %v؛ المطلوب linux (قائمةُ الخادم المغلقة)", hw["os"])
	}
}

// جسمُ النبضة يطابق شكلَ heartbeat عند الخادم: hostname/agent_version/hw/posture
// لا غير — ونظيفٌ من مفاتيح المراقبة (بوّابةُ الخصوصيّة عمقاً قبل الإرسال).
func TestHeartbeatBodyShape(t *testing.T) {
	body := HeartbeatBody("0.2.0", map[string]string{"firewall": "not-configured"})
	allowed := map[string]bool{"hostname": true, "agent_version": true, "hw": true, "posture": true}
	for k := range body {
		if !allowed[k] {
			t.Fatalf("حقلُ نبضةٍ خارج عقد heartbeat: %q", k)
		}
	}
	if body["agent_version"] != "0.2.0" {
		t.Fatalf("agent_version = %v", body["agent_version"])
	}
	host, _ := os.Hostname()
	if host != "" && body["hostname"] != host {
		t.Fatalf("hostname = %v؛ المطلوب %q", body["hostname"], host)
	}
	if bad := privacy.Violations(body); len(bad) != 0 {
		t.Fatalf("جسمُ النبضة يحمل مفاتيحَ مراقبة: %v", bad)
	}
}
