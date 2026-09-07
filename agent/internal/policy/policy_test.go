package policy

import (
	"encoding/json"
	"os"
	"path/filepath"
	"runtime"
	"testing"
)

// **تدقيقٌ لا فرض:** مفاتيحُ التقرير المسموحة تُطبَّق وتُخزَّن؛ وكلُّ طلبِ
// فرضٍ (حجبُ USB مثلاً) يُبلَّغ requires-MDM — لا ادّعاءَ حجبٍ زائفاً أبداً.
func TestApplySeparatesAuditFromEnforcement(t *testing.T) {
	dir := t.TempDir()
	res, err := Apply(dir, map[string]any{
		"tag":                 "vip",
		"report_interval_min": float64(10),
		"usb_block":           true,
		"enforce_encryption":  true,
	})
	if err != nil {
		t.Fatalf("Apply: %v", err)
	}
	if res.Applied["tag"] != "vip" {
		t.Fatalf("مفتاحُ التدقيق tag لم يُطبَّق: %+v", res.Applied)
	}
	if _, claimed := res.Applied["usb_block"]; claimed {
		t.Fatal("طلبُ فرضٍ ظهر في Applied — ادّعاءُ حجبٍ زائف")
	}
	wantMDM := map[string]bool{"usb_block": true, "enforce_encryption": true}
	if len(res.RequiresMDM) != 2 {
		t.Fatalf("طلبا الفرض يُبلَّغان requires-MDM؛ وجدت %v", res.RequiresMDM)
	}
	for _, k := range res.RequiresMDM {
		if !wantMDM[k] {
			t.Fatalf("مفتاحٌ غيرُ متوقَّع في RequiresMDM: %q", k)
		}
	}

	// الأثرُ المحليّ: policy.json ‏0600 يحمل المطبَّق لا المرفوض
	blob, err := os.ReadFile(filepath.Join(dir, "policy.json"))
	if err != nil {
		t.Fatalf("policy.json غائب: %v", err)
	}
	if runtime.GOOS != "windows" {
		info, _ := os.Stat(filepath.Join(dir, "policy.json"))
		if info.Mode().Perm()&0o077 != 0 {
			t.Fatalf("صلاحياتُ policy.json متراخية: %o", info.Mode().Perm())
		}
	}
	var stored map[string]any
	if err := json.Unmarshal(blob, &stored); err != nil {
		t.Fatal(err)
	}
	if stored["tag"] != "vip" {
		t.Fatalf("المخزونُ لا يحمل المطبَّق: %v", stored)
	}
	if _, ok := stored["usb_block"]; ok {
		t.Fatal("المخزونُ يحمل طلبَ فرضٍ لم يُطبَّق")
	}
}

// حمولةُ النتيجة تعلن الوضعَ audit-only دوماً وتُسمّي المؤجَّل إلى MDM.
func TestPayloadNeverClaimsEnforcement(t *testing.T) {
	dir := t.TempDir()
	res, err := Apply(dir, map[string]any{"usb_block": true})
	if err != nil {
		t.Fatal(err)
	}
	p := res.Payload()
	if p["mode"] != "audit-only" {
		t.Fatalf("mode = %v؛ المطلوب audit-only", p["mode"])
	}
	mdm, ok := p["requires_mdm"].([]string)
	if !ok || len(mdm) != 1 || mdm[0] != "usb_block" {
		t.Fatalf("requires_mdm لا يعلن المؤجَّل: %v", p["requires_mdm"])
	}
}

func TestApplyEmptyArgs(t *testing.T) {
	res, err := Apply(t.TempDir(), nil)
	if err != nil {
		t.Fatal(err)
	}
	if len(res.Applied) != 0 || len(res.RequiresMDM) != 0 {
		t.Fatalf("لا معاملات → لا أثر: %+v", res)
	}
}
