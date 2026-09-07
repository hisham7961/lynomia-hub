//go:build darwin

package security

import macplat "lynomia/agent/internal/platform/darwin"

// قرّاءُ macOS (internal/platform/darwin) — يبلّغون التعذّرَ بصدقٍ فيهبط
// كلُّ فحصٍ إلى not-configured.
func platformReaders() map[string]Reader {
	readers := map[string]Reader{}
	for check, read := range macplat.PostureReaders() {
		readers[check] = Reader(read)
	}
	return readers
}
