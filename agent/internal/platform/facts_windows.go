//go:build windows

package platform

import winplat "lynomia/agent/internal/platform/windows"

func platformHW() map[string]any { return winplat.HWFacts() }

// SSID عبر WLAN API يتطلب فكَّ بُنى wlanapi غيرَ الآمن — مؤجَّلٌ بصدق:
// not-configured خيرٌ من قراءةٍ هشّة (عقدُ الأمانة في الطور K).
func platformSSID() (string, bool) { return "", false }

func platformLockSession() error { return winplat.LockSession() }

func platformMirrorPolicy(values map[string]string) error { return winplat.MirrorPolicy(values) }
