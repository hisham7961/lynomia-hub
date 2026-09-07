//go:build !windows && !darwin

package platform

// بديلُ المنصّات الأخرى (linux تطويراً) — **صادقٌ**: لا حقائقَ منصّةٍ تُختلَق،
// لا SSID، لا قفلَ جلسة، لا مخزنَ مرآة.

func platformHW() map[string]any { return map[string]any{} }

func platformSSID() (string, bool) { return "", false }

func platformLockSession() error { return ErrUnsupported }

func platformMirrorPolicy(map[string]string) error { return nil }
