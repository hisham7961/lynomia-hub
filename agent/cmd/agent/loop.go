// حلقةُ البروتوكول (الطور K · WP-K.2 · §45–62): نبضٌ/أحداثٌ/أوامرُ فوق النقل
// الموقَّع من WP-K.1 — والمساراتُ كلُّها **من ردّ التسجيل** المخزَّن لا ثوابتُ
// مدفونة. كلُّ حمولةٍ صادرةٍ تمرّ ببوّابة privacy (مرآةِ مُصادِق الخادم)
// قبل أن تغادر الجهاز — دفاعٌ في العمق فوق جامعاتٍ نظيفةٍ بالبناء.
package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"time"

	"lynomia/agent/internal/commands"
	"lynomia/agent/internal/enrollment"
	"lynomia/agent/internal/identity"
	"lynomia/agent/internal/inventory"
	"lynomia/agent/internal/network"
	"lynomia/agent/internal/platform"
	"lynomia/agent/internal/policy"
	"lynomia/agent/internal/privacy"
	"lynomia/agent/internal/security"
	"lynomia/agent/internal/transport"
	"lynomia/agent/internal/usb"
)

// heartbeatResponse — ردُّ النبضة كما يعلنه الخادم: إيقاعُ المعايرة وعدُّ المستحقّ.
type heartbeatResponse struct {
	OK              bool `json:"ok"`
	IntervalMin     int  `json:"interval_min"`
	PendingCommands int  `json:"pending_commands"`
}

// agentLoop — عُدّةُ الحلقة: النقلُ الموقَّع وحالةُ التسجيل وعدّاءُ الأوامر.
type agentLoop struct {
	dir    string
	state  *enrollment.State
	client *transport.Client
	runner *commands.Runner
}

func newAgentLoop(dir string) (*agentLoop, error) {
	id, err := identity.Load(dir)
	if err != nil {
		return nil, fmt.Errorf("لا هويّةَ صالحة في %s — نفّذ enroll أولاً (%w)", dir, err)
	}
	st, err := enrollment.LoadState(dir)
	if err != nil {
		return nil, err
	}
	client, err := transport.NewClient(st.Server, st.DeviceID, id)
	if err != nil {
		return nil, err
	}
	dispatcher, err := commands.NewDispatcher(handlers(dir))
	if err != nil {
		return nil, err
	}
	return &agentLoop{
		dir:    dir,
		state:  st,
		client: client,
		runner: commands.NewRunner(client, st.CommandsPullPath, st.CommandsResultPath, dispatcher, id),
	}, nil
}

// handlers — منفّذو القائمة المغلقة الخمسة **بصدق التأثير**:
//   - refresh_inventory/refresh_posture: قراءةٌ وإبلاغ.
//   - apply_policy: تدقيقٌ فقط (allowlist) والفرضُ يُبلَّغ requires-MDM.
//   - isolate: **علامةٌ وإبلاغ** لا غير — ملفُ isolated.marker + نتيجةٌ تصرّح
//     أن العزل الشبكيّ الحقيقيّ يتطلب MDM؛ لا ادّعاءَ عزلٍ زائفاً.
//   - lock: قفلُ الجلسة عبر واجهة المنصّة حيث تتاح (Windows)، وإلا failed بصدق.
func handlers(dir string) map[string]commands.Handler {
	return map[string]commands.Handler{
		"refresh_inventory": func(ctx context.Context, cmd commands.Command) (map[string]any, error) {
			result := map[string]any{"hw": inventory.HW()}
			if host, err := os.Hostname(); err == nil && host != "" {
				result["hostname"] = host
			}
			return result, nil
		},
		"refresh_posture": func(ctx context.Context, cmd commands.Command) (map[string]any, error) {
			return map[string]any{"posture": security.Collect()}, nil
		},
		"apply_policy": func(ctx context.Context, cmd commands.Command) (map[string]any, error) {
			res, err := policy.Apply(dir, cmd.Args)
			return res.Payload(), err
		},
		"isolate": func(ctx context.Context, cmd commands.Command) (map[string]any, error) {
			marker := map[string]any{"marked_at": time.Now().UTC().Format(time.RFC3339)}
			if reason, ok := cmd.Args["reason"].(string); ok && reason != "" {
				marker["reason"] = reason
			}
			blob, err := json.MarshalIndent(marker, "", "  ")
			if err != nil {
				return nil, err
			}
			if err := os.WriteFile(filepath.Join(dir, "isolated.marker"), blob, 0o600); err != nil {
				return nil, err
			}
			return map[string]any{
				"effect":      "marked-and-reported",
				"enforcement": "requires-MDM",
				"note":        "علامةُ عزلٍ محليّة وإبلاغٌ فقط — العزلُ الشبكيّ الحقيقيّ يتطلب MDM",
			}, nil
		},
		"lock": func(ctx context.Context, cmd commands.Command) (map[string]any, error) {
			if err := platform.LockSession(); err != nil {
				return map[string]any{"enforcement": "requires-MDM"}, err
			}
			return map[string]any{"effect": "session-locked"}, nil
		},
	}
}

// postJSON — إرسالٌ موقَّعٌ واحد: **بوّابةُ الخصوصيّة قبل المغادرة** ثم النقل.
func (l *agentLoop) postJSON(ctx context.Context, path string, payload map[string]any, out any) error {
	if bad := privacy.Violations(payload); len(bad) != 0 {
		return fmt.Errorf("حمولةٌ تحمل مفاتيحَ مراقبة — رفضٌ قبل الإرسال: %v", bad)
	}
	body, err := json.Marshal(payload)
	if err != nil {
		return err
	}
	resp, err := l.client.Do(ctx, http.MethodPost, path, body)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		return fmt.Errorf("HTTP %d على %s", resp.StatusCode, path)
	}
	if out != nil {
		return json.Unmarshal(raw, out)
	}
	return nil
}

// heartbeat — نبضةٌ واحدة بجسم عقد heartbeat (جردٌ + وضعيّةٌ صادقة).
func (l *agentLoop) heartbeat(ctx context.Context) (*heartbeatResponse, error) {
	var hb heartbeatResponse
	err := l.postJSON(ctx, l.state.HeartbeatPath, inventory.HeartbeatBody(Version, security.Collect()), &hb)
	if err != nil {
		return nil, err
	}
	return &hb, nil
}

// postEvent — حدثٌ واحد بعقد event (kind من allowlist الخادم).
func (l *agentLoop) postEvent(ctx context.Context, kind, severity, summary string, meta map[string]any) error {
	return l.postJSON(ctx, l.state.EventPath, map[string]any{
		"kind": kind, "severity": severity, "summary": summary, "meta": meta,
	}, nil)
}

// networkSelfEvent — حدثُ kind=network_self: واجهاتُ الجهاز نفسِه لا غير.
func (l *agentLoop) networkSelfEvent(ctx context.Context) error {
	meta, err := network.Summary()
	if err != nil {
		return err
	}
	return l.postEvent(ctx, "network_self", "info", "جردُ واجهات الجهاز نفسِه", meta)
}

// watchUSB — مراقبةُ الوصل/الفصل بلقطاتٍ دوريّة؛ على منصّةٍ بلا مراقبٍ يعيد
// ErrUnsupported فيُبلَّغ الغيابُ بصدقٍ ولا يوهم أحداً.
func (l *agentLoop) watchUSB(ctx context.Context, every time.Duration) error {
	prev, err := usb.Snapshot()
	if err != nil {
		return err
	}
	ticker := time.NewTicker(every)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-ticker.C:
		}
		next, err := usb.Snapshot()
		if err != nil {
			continue
		}
		for _, e := range usb.Diff(prev, next) {
			if err := l.postEvent(ctx, "usb", "notice", e.Summary(), e.Meta()); err != nil {
				fmt.Fprintln(os.Stderr, "تعذّر إبلاغُ حدث USB:", err)
			}
		}
		prev = next
	}
}

// run — الحلقةُ الكاملة: نبضٌ يعايرُ إيقاعَه من ردّ الخادم، وسحبُ الأوامر كلَّ
// دورة، وحدثُ الشبكة عند البدء، ومراقبُ USB حيث يتاح. once تنفّذ دورةً واحدة.
func (l *agentLoop) run(ctx context.Context, once bool, intervalOverride time.Duration) error {
	if err := l.networkSelfEvent(ctx); err != nil {
		fmt.Fprintln(os.Stderr, "تعذّر حدثُ الشبكة الأوّل:", err)
	}
	if !once {
		go func() {
			if err := l.watchUSB(ctx, 10*time.Second); err != nil && !errors.Is(err, context.Canceled) {
				// غيابُ المراقب على هذه المنصّة يُقال صراحةً — لا ادّعاءَ مراقبة
				fmt.Fprintln(os.Stderr, "مراقبُ USB غيرُ فاعل:", err)
			}
		}()
	}

	for {
		interval := 5 * time.Minute
		hb, err := l.heartbeat(ctx)
		if err != nil {
			fmt.Fprintln(os.Stderr, "تعذّرت النبضة:", err)
		} else if hb.IntervalMin > 0 {
			interval = time.Duration(hb.IntervalMin) * time.Minute
		}
		if intervalOverride > 0 {
			interval = intervalOverride
		}

		if n, err := l.runner.RunOnce(ctx); err != nil {
			fmt.Fprintln(os.Stderr, "دورةُ الأوامر:", err)
		} else if n > 0 {
			fmt.Println("أُبلغت نتائجُ", n, "من الأوامر.")
		}

		if once {
			return nil
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(interval):
		}
	}
}
