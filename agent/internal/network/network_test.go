package network

import (
	"net"
	"runtime"
	"testing"
)

// **الجهازُ نفسُه فقط:** كلُّ ما يخرج من الجامع موجودٌ في net.Interfaces —
// لا مضيفَ آخر، لا نطاقات، لا مسح.
func TestCollectSelfOnly(t *testing.T) {
	local, err := net.Interfaces()
	if err != nil {
		t.Skipf("net.Interfaces غيرُ متاح هنا: %v", err)
	}
	localNames := map[string]bool{}
	localAddrs := map[string]bool{}
	for _, ifi := range local {
		localNames[ifi.Name] = true
		addrs, _ := ifi.Addrs()
		for _, a := range addrs {
			localAddrs[a.String()] = true
		}
	}

	got, err := Collect()
	if err != nil {
		t.Fatalf("Collect: %v", err)
	}
	for _, ifc := range got {
		if !localNames[ifc.Name] {
			t.Fatalf("واجهةٌ ليست من واجهات الجهاز نفسِه: %q", ifc.Name)
		}
		for _, ip := range ifc.IPs {
			if !localAddrs[ip] {
				t.Fatalf("عنوانٌ ليس من عناوين الجهاز نفسِه: %q على %q", ip, ifc.Name)
			}
		}
	}
}

// شكلُ الملخّص السلكيّ (حدثُ kind=network_self): مفتاحان لا غير، وSSID على
// linux «not-configured» بأمانة (لا قارئَ منصّة بلا cgo).
func TestSummaryShape(t *testing.T) {
	s, err := Summary()
	if err != nil {
		t.Fatalf("Summary: %v", err)
	}
	if len(s) != 2 {
		t.Fatalf("مفتاحا الملخّص interfaces+ssid لا غير؛ وجدت %v", s)
	}
	if _, ok := s["interfaces"]; !ok {
		t.Fatal("الملخّص بلا interfaces")
	}
	ssid, ok := s["ssid"].(string)
	if !ok {
		t.Fatalf("ssid ليست نصاً: %T", s["ssid"])
	}
	if runtime.GOOS == "linux" && ssid != "not-configured" {
		t.Fatalf("على linux لا قارئَ SSID — المطلوب not-configured لا %q", ssid)
	}
}
