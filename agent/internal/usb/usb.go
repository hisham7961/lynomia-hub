// Package usb — أحداثُ USB **ميتاداتا لا محتوى** (الطور K · WP-K.2 · §45–62).
//
// نموذجُ الحدث: attach/detach بهويّة العتاد (vendor/product/الاسم) و**حضورِ**
// الرقم التسلسليّ — لا محتوى ملفاتٍ ولا سردَ مجلداتٍ أبداً: بِنيةُ الحدث نفسُها
// بلا حقلٍ لذلك (يثبته اختبارُ الانعكاس)، وحمولتُه السلكيّة تسافر حدثَ
// kind=usb ضمن allowlist أنواع الخادم وتحت مُصادِق خصوصيّته.
//
// المراقبُ خلف وسومِ بناء: على linux لقطاتٌ دوريّة من **سمات** sysfs
// (idVendor/idProduct/product/serial — ملفاتُ سماتِ عتادٍ يكتبها النواة، لا
// ملفاتِ مستخدم)؛ وعلى Windows/macOS تتطلب المراقبةُ SetupAPI/IOKit بلا cgo
// فيُبلَّغ ErrUnsupported **بصدق** — لا مراقبَ وهميّاً.
package usb

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// ErrUnsupported — لا مراقبَ USB على هذه المنصّة بلا واجهةِ نظامٍ — يُبلَّغ لا يُدّعى.
var ErrUnsupported = errors.New("مراقبةُ USB غيرُ متاحة على هذه المنصّة")

// Device — جهازٌ حاضر: ميتاداتا عتادٍ حصراً.
type Device struct {
	Key       string // عنوانُ الناقل (اسمُ مدخل sysfs)
	VendorID  string
	ProductID string
	Product   string
	Serial    string
}

// Event — حدثُ وصلٍ/فصلٍ: الميتاداتا نفسُها لا غير (لا حقلَ محتوىً بالبناء).
type Event struct {
	Action    string // attach | detach
	At        time.Time
	Key       string
	VendorID  string
	ProductID string
	Product   string
	Serial    string
}

// readAttr — سمةُ عتادٍ واحدة من مدخل sysfs (سطرٌ يكتبه النواة).
func readAttr(dir, name string) string {
	blob, err := os.ReadFile(filepath.Join(dir, name))
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(blob))
}

// SnapshotDir — لقطةُ الأجهزة من شجرةِ sysfs (قابلةٌ للاختبار بشجرةٍ وهمية):
// المداخلُ ذاتُ هويّةِ عتادٍ (idVendor+idProduct) وحدَها تُحتسَب.
func SnapshotDir(root string) (map[string]Device, error) {
	entries, err := os.ReadDir(root)
	if err != nil {
		return nil, err
	}
	devices := map[string]Device{}
	for _, e := range entries {
		if !e.IsDir() {
			continue
		}
		dir := filepath.Join(root, e.Name())
		vendor := readAttr(dir, "idVendor")
		product := readAttr(dir, "idProduct")
		if vendor == "" || product == "" {
			continue
		}
		devices[e.Name()] = Device{
			Key:       e.Name(),
			VendorID:  vendor,
			ProductID: product,
			Product:   readAttr(dir, "product"),
			Serial:    readAttr(dir, "serial"),
		}
	}
	return devices, nil
}

// Diff — أحداثُ الفرق بين لقطتين: الغائبُ الجديد detach والحاضرُ الجديد attach.
func Diff(prev, next map[string]Device) []Event {
	now := time.Now().UTC()
	var events []Event
	for key, d := range prev {
		if _, still := next[key]; !still {
			events = append(events, fromDevice("detach", now, d))
		}
	}
	for key, d := range next {
		if _, was := prev[key]; !was {
			events = append(events, fromDevice("attach", now, d))
		}
	}
	return events
}

func fromDevice(action string, at time.Time, d Device) Event {
	return Event{Action: action, At: at, Key: d.Key,
		VendorID: d.VendorID, ProductID: d.ProductID, Product: d.Product, Serial: d.Serial}
}

// Meta — الحمولةُ السلكيّة لحدث kind=usb: هويّةُ العتاد + **حضورُ** الرقم
// التسلسليّ لا نصُّه — أقلُّ ما يلزم الجرد.
func (e Event) Meta() map[string]any {
	return map[string]any{
		"action":         e.Action,
		"vendor_id":      e.VendorID,
		"product_id":     e.ProductID,
		"product":        e.Product,
		"serial_present": e.Serial != "",
	}
}

// Summary — سطرُ الحدث المقروء (يُنقَّح ويُقصّ عند الخادم).
func (e Event) Summary() string {
	verb := "وصلُ"
	if e.Action == "detach" {
		verb = "فصلُ"
	}
	name := e.Product
	if name == "" {
		name = "جهاز USB"
	}
	return verb + " " + name + " (" + e.VendorID + ":" + e.ProductID + ")"
}
