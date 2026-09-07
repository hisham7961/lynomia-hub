// Package platform — واجهةُ خصائص المنصّة (الطور K · WP-K.2 · §45–62).
//
// الوجهُ المحمولُ لما يختلف بين الأنظمة: حقائقُ العتاد، وSSID الجهاز نفسِه،
// وقفلُ الجلسة، ومرآةُ السياسة. التفاصيلُ الخاصّة تعيش خلف وسومِ بناءٍ في
// facts_*.go هنا وفي الحزمتين internal/platform/{windows,darwin} — وبديلُ
// المنصّة الغائبة **صادق**: يُعيد الغيابَ (خريطةٌ فارغة / ok=false /
// ErrUnsupported) ولا يختلق قيمة.
package platform

import "errors"

// ErrUnsupported — الفعلُ غيرُ متاحٍ على هذه المنصّة بلا MDM أو واجهةِ نظامٍ —
// يُبلَّغ صراحةً ولا يُدّعى نجاحُه.
var ErrUnsupported = errors.New("غيرُ متاحٍ على هذه المنصّة")

// HW — حقائقُ عتادٍ إضافيّة تبلغها واجهاتُ النظام (سجلُّ Windows عبر x/sys،
// وsysctl على macOS): os_version/cpu/model/memory_mb — الغائبُ يُحذَف لا يُختلَق.
func HW() map[string]any { return platformHW() }

// SSID — معرّفُ شبكة Wi-Fi التي يتّصل بها **هذا الجهازُ نفسُه** إن أتاحته
// المنصّة، وإلا ok=false (فيُبلَّغ not-configured) — لا مسحَ ولا جيران.
func SSID() (string, bool) { return platformSSID() }

// LockSession — قفلُ جلسة المستخدم الحالية عبر واجهة النظام (أمرُ lock):
// متاحٌ على Windows (LockWorkStation)، وErrUnsupported بصدقٍ حيث لا واجهةَ بلا MDM.
func LockSession() error { return platformLockSession() }

// MirrorPolicy — مرآةُ سياسة التدقيق إلى مخزن المنصّة (سجلُّ Windows تحت مفتاح
// المستخدم الحالي) — **قيَمُ إبلاغٍ لا فرضَ فيها**؛ حيث لا مخزنَ منصّةٍ فهي لا-شيء
// بلا ضرر (policy.json المحليّ يبقى السجلَّ الأصل).
func MirrorPolicy(values map[string]string) error { return platformMirrorPolicy(values) }
