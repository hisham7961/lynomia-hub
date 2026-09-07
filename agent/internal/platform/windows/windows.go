//go:build windows

// Package windows — خصائصُ منصّة Windows (الطور K · WP-K.2 · §45–62): قراءاتُ
// **سجلٍّ** وواجهاتُ نظامٍ عبر golang.org/x/sys حصراً — لا صَدَفةَ ولا إطلاقَ
// عمليات. ما تعذّرت قراءتُه (مَنعٌ أو غياب) يُحذَف أو يُبلَّغ خطأً فيهبط عند
// المستهلِك إلى not-configured — لا اختلاق.
package windows

import (
	"errors"
	"unsafe"

	xwin "golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"
)

var (
	moduser32           = xwin.NewLazySystemDLL("user32.dll")
	modkernel32         = xwin.NewLazySystemDLL("kernel32.dll")
	procLockWorkStation = moduser32.NewProc("LockWorkStation")
	procGlobalMemStatus = modkernel32.NewProc("GlobalMemoryStatusEx")
)

// regString — قيمةُ سجلٍّ نصيّة من HKLM؛ الفشلُ غيابٌ لا قيمةٌ مختلَقة.
func regString(path, name string) (string, bool) {
	k, err := registry.OpenKey(registry.LOCAL_MACHINE, path, registry.QUERY_VALUE)
	if err != nil {
		return "", false
	}
	defer k.Close()
	v, _, err := k.GetStringValue(name)
	if err != nil || v == "" {
		return "", false
	}
	return v, true
}

// regDword — قيمةُ سجلٍّ عددية من HKLM.
func regDword(path, name string) (uint64, bool) {
	k, err := registry.OpenKey(registry.LOCAL_MACHINE, path, registry.QUERY_VALUE)
	if err != nil {
		return 0, false
	}
	defer k.Close()
	v, _, err := k.GetIntegerValue(name)
	if err != nil {
		return 0, false
	}
	return v, true
}

// memoryStatusEx — بِنيةُ GlobalMemoryStatusEx كما يعرّفها النظام.
type memoryStatusEx struct {
	Length               uint32
	MemoryLoad           uint32
	TotalPhys            uint64
	AvailPhys            uint64
	TotalPageFile        uint64
	AvailPageFile        uint64
	TotalVirtual         uint64
	AvailVirtual         uint64
	AvailExtendedVirtual uint64
}

// HWFacts — حقائقُ العتاد من السجلّ وواجهة الذاكرة: os_version/cpu/model/
// memory_mb — كلُّ متعذِّرٍ يُحذَف.
func HWFacts() map[string]any {
	hw := map[string]any{}

	const nt = `SOFTWARE\Microsoft\Windows NT\CurrentVersion`
	if product, ok := regString(nt, "ProductName"); ok {
		if display, ok := regString(nt, "DisplayVersion"); ok {
			product += " " + display
		}
		hw["os_version"] = product
	}
	if cpu, ok := regString(`HARDWARE\DESCRIPTION\System\CentralProcessor\0`, "ProcessorNameString"); ok {
		hw["cpu"] = cpu
	}
	const sysinfo = `SYSTEM\CurrentControlSet\Control\SystemInformation`
	if maker, ok := regString(sysinfo, "SystemManufacturer"); ok {
		if model, ok := regString(sysinfo, "SystemProductName"); ok {
			hw["model"] = maker + " " + model
		}
	}

	var mem memoryStatusEx
	mem.Length = uint32(unsafe.Sizeof(mem))
	if r1, _, _ := procGlobalMemStatus.Call(uintptr(unsafe.Pointer(&mem))); r1 != 0 {
		hw["memory_mb"] = mem.TotalPhys / (1024 * 1024)
	}
	return hw
}

// PostureReaders — قرّاءُ الوضعيّة من السجلّ؛ خطأُ القراءة يهبط not-configured
// عند security.CollectFrom — القارئُ لا يقرّر بديلاً عن الحقيقة.
func PostureReaders() map[string]func() (string, error) {
	return map[string]func() (string, error){
		"firewall": func() (string, error) {
			v, ok := regDword(`SYSTEM\CurrentControlSet\Services\SharedAccess\Parameters\FirewallPolicy\StandardProfile`, "EnableFirewall")
			if !ok {
				return "", errors.New("قراءةُ جدار الحماية متعذّرة")
			}
			if v == 1 {
				return "active", nil
			}
			return "inactive", nil
		},
		"defender": func() (string, error) {
			// القيمةُ الغائبة شائعةٌ والحمايةُ تعمل افتراضاً — الغيابُ يُبلَّغ
			// not-configured بصدقٍ لا يُفسَّر نشاطاً.
			v, ok := regDword(`SOFTWARE\Microsoft\Windows Defender\Real-Time Protection`, "DisableRealtimeMonitoring")
			if !ok {
				return "", errors.New("قراءةُ الحماية الفورية متعذّرة")
			}
			if v == 0 {
				return "active", nil
			}
			return "inactive", nil
		},
		"updates": func() (string, error) {
			v, ok := regDword(`SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate\AU`, "NoAutoUpdate")
			if !ok {
				return "", errors.New("قراءةُ سياسة التحديثات متعذّرة")
			}
			if v == 0 {
				return "active", nil
			}
			return "inactive", nil
		},
		"disk_encryption": func() (string, error) {
			// وضعُ BitLocker يتطلب WMI/MDM — بلا قراءةٍ حقيقيّة لا ادّعاء.
			return "", errors.New("وضعُ تشفير القرص يتطلب WMI/MDM")
		},
	}
}

// LockSession — قفلُ جلسة المستخدم الحالية عبر user32!LockWorkStation —
// فعلُ أمانٍ تطبيقيٌّ آمنٌ (أمرُ lock) لا إطلاقَ عمليّةٍ فيه.
func LockSession() error {
	r1, _, callErr := procLockWorkStation.Call()
	if r1 == 0 {
		if callErr != nil {
			return callErr
		}
		return errors.New("رفض النظامُ قفلَ الجلسة")
	}
	return nil
}

// MirrorPolicy — مرآةُ سياسة التدقيق تحت HKCU\Software\Lynomia\Agent\Policy:
// **قيَمُ إبلاغٍ** يقرأها الجردُ والدعمُ — لا مفتاحَ فرضٍ يُكتب هنا أبداً.
func MirrorPolicy(values map[string]string) error {
	k, _, err := registry.CreateKey(registry.CURRENT_USER, `Software\Lynomia\Agent\Policy`, registry.SET_VALUE)
	if err != nil {
		return err
	}
	defer k.Close()
	for name, v := range values {
		if err := k.SetStringValue(name, v); err != nil {
			return err
		}
	}
	return nil
}
