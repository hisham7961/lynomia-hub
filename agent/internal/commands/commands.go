// Package commands — سحبُ الأوامر وتوزيعُها على **القائمة المغلقة** (الطور K ·
// WP-K.2 · §45–62): مرآةُ `EndpointCommand::TYPES` و`commandsPull`/
// `commandResult` عند الخادم حرفياً.
//
// مرآةُ C10 عميلاً: الخمسةُ لا غير — refresh_inventory/refresh_posture/
// apply_policy/isolate/lock. النوعُ المجهول (وكلُّ صيغِ الأصداف) **يُرفَض
// ويُبلَّغ failed دون أن يُنفَّذ شيء** — لا معالجَ يُستدعى ولا تمريرَ حرّ،
// وتسجيلُ معالجٍ لنوعٍ خارج القائمة مرفوضٌ من الأساس. المطابقةُ حرفيّة:
// لا تسامحَ حالةٍ ولا مسافات.
package commands

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
)

// Types — القائمةُ المغلقة الخمسة: مرآةُ `EndpointCommand::TYPES` بالحرف.
var Types = []string{"refresh_inventory", "refresh_posture", "apply_policy", "isolate", "lock"}

var typeSet = func() map[string]bool {
	m := map[string]bool{}
	for _, t := range Types {
		m[t] = true
	}
	return m
}()

// Command — عنصرُ ردّ commandsPull حرفياً: {id, type, args, ikey}.
type Command struct {
	ID   string         `json:"id"`
	Type string         `json:"type"`
	Args map[string]any `json:"args"`
	IKey string         `json:"ikey"`
}

// Handler — منفّذُ نوعٍ واحدٍ من القائمة المغلقة: يعيد نتيجتَه أو خطأه الصادق.
type Handler func(ctx context.Context, cmd Command) (map[string]any, error)

// Outcome — حصيلةُ التوزيع بعقد commandResult: state ∈ {done|failed} ونتيجةٌ
// تسافر نصَّ JSON خاماً.
type Outcome struct {
	State  string
	Result map[string]any
}

// Dispatcher — موزّعٌ فوق القائمة المغلقة حصراً.
type Dispatcher struct {
	handlers map[string]Handler
}

// NewDispatcher — يبني الموزّع رافضاً أيَّ تسجيلٍ لنوعٍ خارج القائمة المغلقة —
// فلا بابَ يُفتح لاحقاً بتسجيلٍ «مؤقت».
func NewDispatcher(handlers map[string]Handler) (*Dispatcher, error) {
	for typ := range handlers {
		if !typeSet[typ] {
			return nil, fmt.Errorf("نوعٌ خارج القائمة المغلقة لا يُسجَّل له معالج: %q", typ)
		}
	}
	own := make(map[string]Handler, len(handlers))
	for typ, h := range handlers {
		own[typ] = h
	}
	return &Dispatcher{handlers: own}, nil
}

// Dispatch — يوزّع أمراً واحداً: المجهولُ يُرفَض failed **قبل** أي تنفيذ،
// والمعلومُ غيرُ الموصول على هذه المنصّة failed بصدق، وخطأُ المعالج failed
// بسببه — done لا تُبلَّغ إلا عن تنفيذٍ فعليّ نظيف.
func (d *Dispatcher) Dispatch(ctx context.Context, cmd Command) Outcome {
	if !typeSet[cmd.Type] {
		typ := cmd.Type
		if len(typ) > 60 {
			typ = typ[:60]
		}
		return Outcome{State: "failed", Result: map[string]any{
			"error": "unknown-command-type",
			"type":  typ,
		}}
	}
	handler, wired := d.handlers[cmd.Type]
	if !wired {
		return Outcome{State: "failed", Result: map[string]any{
			"error": "not-supported-on-this-agent",
			"type":  cmd.Type,
		}}
	}
	result, err := handler(ctx, cmd)
	if result == nil {
		result = map[string]any{}
	}
	if err != nil {
		result["error"] = err.Error()
		return Outcome{State: "failed", Result: result}
	}
	return Outcome{State: "done", Result: result}
}

// SignResult — **مرآةُ عقد توقيع النتيجة** المعلنِ في docblock
// ‏`EndpointProtocolController`: ‏ES256 على
//
//	IKEY + "\n" + STATE + "\n" + sha256hex(RESULT_RAW)
//
// حيث RESULT_RAW بايتاتُ حقل result كما تسافر حرفياً (نصُّ JSON خامٌ — لا
// إعادةَ ترميزٍ تبدّل ترتيبَ المفاتيح؛ درسُ MySQL 8)، والغائبُ تجزئتُه تجزئةُ
// السلسلة الفارغة. الموقِّعُ بدائيّةُ ES256 الواحدة نفسُها (identity.Sign).
func SignResult(signer ResultSigner, ikey, state string, resultRaw []byte) (string, error) {
	sum := sha256.Sum256(resultRaw)
	return signer.Sign([]byte(ikey + "\n" + state + "\n" + hex.EncodeToString(sum[:])))
}

// ResultSigner — ما يلزم من الهويّة: توقيعُ بايتاتٍ إلى base64(DER) لا غير.
type ResultSigner interface {
	Sign(data []byte) (string, error)
}
