//go:build !windows && !darwin

package security

// بديلُ المنصّات الأخرى (linux تطويراً): لا قارئَ منصّةٍ — فتهبط الفحوصُ
// القانونية كلُّها إلى not-configured **بصدق** في CollectFrom.
func platformReaders() map[string]Reader { return map[string]Reader{} }
