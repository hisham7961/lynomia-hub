package usb

import (
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
)

func writeAttr(t *testing.T, dir, name, val string) {
	t.Helper()
	if err := os.WriteFile(filepath.Join(dir, name), []byte(val+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
}

// لقطةٌ من شجرةِ sysfs وهمية: تُقرأ **سماتُ العتاد** (idVendor/idProduct/
// product/serial) لا غير — والمداخلُ بلا هويّة عتادٍ (موزّعات، ملفات) تُتجاهل.
func TestSnapshotDirReadsMetadataOnly(t *testing.T) {
	root := t.TempDir()
	dev := filepath.Join(root, "1-1")
	if err := os.MkdirAll(dev, 0o755); err != nil {
		t.Fatal(err)
	}
	writeAttr(t, dev, "idVendor", "0781")
	writeAttr(t, dev, "idProduct", "5567")
	writeAttr(t, dev, "product", "Cruzer Blade")
	writeAttr(t, dev, "serial", "4C530001")
	// موزّعٌ بلا idVendor يُتجاهل، وملفٌ غيرُ مجلدٍ يُتجاهل
	if err := os.MkdirAll(filepath.Join(root, "usb1"), 0o755); err != nil {
		t.Fatal(err)
	}
	writeAttr(t, root, "stray", "x")

	got, err := SnapshotDir(root)
	if err != nil {
		t.Fatalf("SnapshotDir: %v", err)
	}
	if len(got) != 1 {
		t.Fatalf("جهازٌ واحدٌ متوقَّع؛ وجدت %d: %v", len(got), got)
	}
	d, ok := got["1-1"]
	if !ok {
		t.Fatalf("المفتاح 1-1 غائب: %v", got)
	}
	if d.VendorID != "0781" || d.ProductID != "5567" || d.Product != "Cruzer Blade" || d.Serial != "4C530001" {
		t.Fatalf("سماتٌ ناقصة أو غيرُ مقصوصة: %+v", d)
	}
}

// الفرقُ بين لقطتين يولّد أحداثَ attach/detach بالميتاداتا.
func TestDiffEvents(t *testing.T) {
	a := Device{Key: "1-1", VendorID: "0781", ProductID: "5567", Product: "Cruzer", Serial: "S1"}
	b := Device{Key: "1-2", VendorID: "046d", ProductID: "c534", Product: "Receiver"}

	events := Diff(map[string]Device{"1-1": a}, map[string]Device{"1-2": b})
	if len(events) != 2 {
		t.Fatalf("حدثان متوقَّعان (detach+attach)؛ وجدت %d", len(events))
	}
	var attach, detach *Event
	for i := range events {
		switch events[i].Action {
		case "attach":
			attach = &events[i]
		case "detach":
			detach = &events[i]
		default:
			t.Fatalf("فعلٌ خارج {attach|detach}: %q", events[i].Action)
		}
	}
	if attach == nil || detach == nil {
		t.Fatalf("لا زوجَ attach/detach: %+v", events)
	}
	if attach.VendorID != "046d" || detach.Serial != "S1" {
		t.Fatalf("ميتاداتا الحدثين لا تطابق اللقطتين: %+v %+v", attach, detach)
	}
}

// **أحداثٌ لا محتوى:** بِنيةُ الحدث ميتاداتا عتادٍ حصراً — لا حقلَ محتوىً
// أو مسارٍ أو ملفاتٍ فيها أصلاً (برهانُ بِنيةٍ لا وعدُ سلوك).
func TestEventStructCarriesMetadataOnly(t *testing.T) {
	allowed := map[string]bool{
		"Action": true, "At": true, "Key": true,
		"VendorID": true, "ProductID": true, "Product": true, "Serial": true,
	}
	tt := reflect.TypeOf(Event{})
	for i := 0; i < tt.NumField(); i++ {
		f := tt.Field(i)
		if !allowed[f.Name] {
			t.Fatalf("حقلٌ خارج ميتاداتا العتاد في Event: %q", f.Name)
		}
		low := strings.ToLower(f.Name)
		for _, banned := range []string{"content", "path", "file", "data", "dir", "payload"} {
			if strings.Contains(low, banned) {
				t.Fatalf("حقلُ Event يوحي بمحتوىً: %q", f.Name)
			}
		}
	}
}

// الحمولةُ السلكيّة (meta حدثِ kind=usb): هويّةُ العتاد + **حضورُ** الرقم
// التسلسليّ لا نصُّه — أقلُّ ما يلزم الجرد.
func TestMetaWireShape(t *testing.T) {
	e := Event{Action: "attach", Key: "1-1", VendorID: "0781", ProductID: "5567", Product: "Cruzer", Serial: "S1"}
	m := e.Meta()
	want := []string{"action", "vendor_id", "product_id", "product", "serial_present"}
	if len(m) != len(want) {
		t.Fatalf("مفاتيحُ meta خمسةٌ لا غير؛ وجدت %v", m)
	}
	for _, k := range want {
		if _, ok := m[k]; !ok {
			t.Fatalf("مفتاح meta غائب: %q في %v", k, m)
		}
	}
	if sp, ok := m["serial_present"].(bool); !ok || !sp {
		t.Fatalf("serial_present منطقيّةٌ صادقة؛ وجدت %v", m["serial_present"])
	}
	if _, leaks := m["serial"]; leaks {
		t.Fatal("الرقمُ التسلسليّ الخامّ لا يسافر في meta — حضورُه يكفي")
	}
}
