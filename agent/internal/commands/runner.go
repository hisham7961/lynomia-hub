package commands

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
)

// Doer — ما يلزم من النقل الموقَّع (transport.Client يفي به) — فالعدّاءُ لا
// يبني طلباً غيرَ موقَّعٍ ولا يعرف مفتاحاً.
type Doer interface {
	Do(ctx context.Context, method, path string, body []byte) (*http.Response, error)
}

// Runner — دورةُ الأوامر الكاملة فوق مساري السحب والنتيجة **من ردّ التسجيل**
// (لا ثابتَ مدفوناً): سحبٌ → توزيعٌ على القائمة المغلقة → نتيجةٌ موقَّعةٌ بعقدها.
type Runner struct {
	doer       Doer
	pullPath   string
	resultPath string
	dispatcher *Dispatcher
	signer     ResultSigner
}

// NewRunner — يبني العدّاء على النقل الموقَّع ومسارَي البروتوكول المعلنَين.
func NewRunner(doer Doer, pullPath, resultPath string, d *Dispatcher, signer ResultSigner) *Runner {
	return &Runner{doer: doer, pullPath: pullPath, resultPath: resultPath, dispatcher: d, signer: signer}
}

// ParsePull — يفكّ ردَّ commandsPull: {ok, commands:[{id,type,args,ikey}]}.
func ParsePull(raw []byte) ([]Command, error) {
	var resp struct {
		OK       bool      `json:"ok"`
		Commands []Command `json:"commands"`
	}
	if err := json.Unmarshal(raw, &resp); err != nil {
		return nil, fmt.Errorf("ردُّ سحبٍ غيرُ مفهوم: %w", err)
	}
	if !resp.OK {
		return nil, errors.New("ردُّ السحب بلا ok")
	}
	return resp.Commands, nil
}

// RunOnce — دورةٌ واحدة: يسحب المستحقَّ ويوزّعه ويبلّغ نتيجةَ **كلِّ** أمرٍ
// (المرفوضُ failed مثل المنفَّذ) موقَّعةً بعقد النتيجة. يعيد عددَ المُبلَّغ.
func (r *Runner) RunOnce(ctx context.Context) (int, error) {
	raw, err := r.post(ctx, r.pullPath, []byte(`{}`))
	if err != nil {
		return 0, fmt.Errorf("سحبُ الأوامر: %w", err)
	}
	cmds, err := ParsePull(raw)
	if err != nil {
		return 0, err
	}

	reported := 0
	var errs []error
	for _, cmd := range cmds {
		outcome := r.dispatcher.Dispatch(ctx, cmd)
		if err := r.report(ctx, cmd, outcome); err != nil {
			errs = append(errs, fmt.Errorf("نتيجةُ %s: %w", cmd.ID, err))
			continue
		}
		reported++
	}
	return reported, errors.Join(errs...)
}

// report — يبلّغ نتيجةَ أمرٍ واحدٍ بعقد commandResult: result نصُّ JSON خامٌ
// وresult_sig توقيعُه على بايتاته الثابتة نفسِها.
func (r *Runner) report(ctx context.Context, cmd Command, outcome Outcome) error {
	resultRaw, err := json.Marshal(outcome.Result)
	if err != nil {
		return err
	}
	sig, err := SignResult(r.signer, cmd.IKey, outcome.State, resultRaw)
	if err != nil {
		return err
	}
	body, err := json.Marshal(map[string]any{
		"command_id": cmd.ID,
		"state":      outcome.State,
		"result":     string(resultRaw),
		"result_sig": sig,
	})
	if err != nil {
		return err
	}
	_, err = r.post(ctx, r.resultPath, body)
	return err
}

func (r *Runner) post(ctx context.Context, path string, body []byte) ([]byte, error) {
	resp, err := r.doer.Do(ctx, http.MethodPost, path, body)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		return nil, fmt.Errorf("HTTP %d: %s", resp.StatusCode, truncate(raw, 300))
	}
	return raw, nil
}

func truncate(b []byte, n int) string {
	if len(b) > n {
		b = b[:n]
	}
	return string(b)
}
