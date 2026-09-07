//go:build windows

package security

import winplat "lynomia/agent/internal/platform/windows"

// قرّاءُ Windows من السجلّ (internal/platform/windows) — خطؤهم يهبط
// not-configured في CollectFrom.
func platformReaders() map[string]Reader {
	readers := map[string]Reader{}
	for check, read := range winplat.PostureReaders() {
		readers[check] = Reader(read)
	}
	return readers
}
