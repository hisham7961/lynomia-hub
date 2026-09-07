//go:build !linux

package usb

// Snapshot على Windows/macOS يتطلب SetupAPI/IOKit بلا cgo — مؤجَّلٌ **بصدق**:
// ErrUnsupported يُبلَّغ فيسكت مراقبُ الحلقة بلا ادّعاء (عقدُ الأمانة K).
func Snapshot() (map[string]Device, error) { return nil, ErrUnsupported }
