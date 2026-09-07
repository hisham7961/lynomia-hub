//go:build darwin

// Package darwin — خصائصُ منصّة macOS (الطور K · WP-K.2 · §45–62): قراءاتُ
// sysctl عبر golang.org/x/sys/unix حصراً — لا صَدَفةَ ولا إطلاقَ عمليات ولا
// cgo. ما تعذّر يُحذَف أو يُبلَّغ خطأً فيهبط not-configured — لا اختلاق.
package darwin

import (
	"errors"

	"golang.org/x/sys/unix"
)

// HWFacts — حقائقُ العتاد من sysctl: os_version/cpu/model/memory_mb —
// كلُّ متعذِّرٍ يُحذَف.
func HWFacts() map[string]any {
	hw := map[string]any{}
	if v, err := unix.Sysctl("kern.osproductversion"); err == nil && v != "" {
		hw["os_version"] = "macOS " + v
	}
	if v, err := unix.Sysctl("machdep.cpu.brand_string"); err == nil && v != "" {
		hw["cpu"] = v
	}
	if v, err := unix.Sysctl("hw.model"); err == nil && v != "" {
		hw["model"] = v
	}
	if v, err := unix.SysctlUint64("hw.memsize"); err == nil && v > 0 {
		hw["memory_mb"] = v / (1024 * 1024)
	}
	return hw
}

// PostureReaders — وضعيّةُ macOS (جدارُ ALF، وFileVault، والتحديثات) تتطلب
// واجهاتِ إطارٍ أو MDM لا يبلغها Go بلا cgo — فالقرّاءُ يبلّغون التعذّرَ
// **بصدق** ويهبط كلُّ فحصٍ إلى not-configured؛ لا فحصَ يُختلَق نشيطاً.
func PostureReaders() map[string]func() (string, error) {
	unsupported := func(what string) func() (string, error) {
		return func() (string, error) { return "", errors.New(what + " يتطلب واجهةَ إطارٍ أو MDM") }
	}
	return map[string]func() (string, error){
		"firewall":        unsupported("وضعُ جدار الحماية"),
		"disk_encryption": unsupported("وضعُ FileVault"),
		"defender":        unsupported("وضعُ الحماية من البرمجيّات الخبيثة"),
		"updates":         unsupported("وضعُ التحديثات التلقائية"),
	}
}
