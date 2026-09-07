//go:build darwin

package platform

import macplat "lynomia/agent/internal/platform/darwin"

func platformHW() map[string]any { return macplat.HWFacts() }

// SSID على macOS يتطلب CoreWLAN (‏Objective-C ⇒ cgo المحظور) — مؤجَّلٌ بصدق.
func platformSSID() (string, bool) { return "", false }

// قفلُ الجلسة على macOS بلا cgo غيرُ متاحٍ بواجهةٍ مستقرّة — يُبلَّغ صراحةً.
func platformLockSession() error { return ErrUnsupported }

func platformMirrorPolicy(map[string]string) error { return nil }
