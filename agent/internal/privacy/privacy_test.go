package privacy

import (
	"reflect"
	"testing"
)

// مرآةُ اختبارات `EndpointPrivacy::violations` عند الخادم: المطابقةُ بالمفتاح
// وبالعمق، بعد تطبيعٍ (حروفٌ صغيرة والفواصلُ `_`)، وبالاحتواء لا بالتساوي.
func TestViolationsFlagsSurveillanceKeys(t *testing.T) {
	cases := []struct {
		name    string
		payload map[string]any
		want    []string
	}{
		{
			name:    "نظيف — جردٌ وعتاد",
			payload: map[string]any{"hostname": "pc-1", "hw": map[string]any{"cpu": "i7", "memory_mb": 8192}},
			want:    nil,
		},
		{
			name:    "مفتاحٌ متشعّب",
			payload: map[string]any{"meta": map[string]any{"screenshot": "x"}},
			want:    []string{"meta.screenshot"},
		},
		{
			name:    "الاحتواءُ لا التساوي",
			payload: map[string]any{"usb_keylogger_found": true},
			want:    []string{"usb_keylogger_found"},
		},
		{
			name:    "التطبيع — فواصلُ وحروفٌ كبيرة",
			payload: map[string]any{"Browser-History": []any{}},
			want:    []string{"Browser-History"},
		},
		{
			name:    "القيمةُ لا تُفحص — المفتاحُ وحدَه",
			payload: map[string]any{"summary": "لا لقطاتَ هنا"},
			want:    nil,
		},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got := Violations(tc.payload)
			if len(got) == 0 && len(tc.want) == 0 {
				return
			}
			if !reflect.DeepEqual(got, tc.want) {
				t.Fatalf("Violations = %v؛ المطلوب %v", got, tc.want)
			}
		})
	}
}
