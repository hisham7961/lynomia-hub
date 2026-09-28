<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Ai\Kpi\KpiInsights;
use Illuminate\Http\Request;

/**
 * **مؤشّراتٌ ذكيّة** (docs/ai-hub/47 §العمود هـ) — «تحليل الذكاء الآن» واعتمادُ/رفضُ الهدف المقترح.
 * الحارسُ بابُ باني المؤشّرات نفسُه (`opsAnalytics`) — لا بابٌ ثانٍ ينحرف.
 */
class KpiInsightController extends Controller
{
    protected function gate(): void
    {
        abort_unless(hub_monitor_group('opsAnalytics'), 403, 'باني المؤشرات للمالكين ومن يحمل صلاحية المتابعة');
    }

    public function refresh()
    {
        $this->gate();
        abort_unless(KpiInsights::ready(), 404);
        $r = KpiInsights::run(false, true, auth()->user());

        return back()->with($r['code'] ? 'err' : 'ok', $r['code'] ? 'تعذّر التحليل: ' . $r['code']
            : "حُلِّل {$r['analysed']} مؤشّراً · {$r['ideas']} مؤشّراً مقترحاً");
    }

    public function target(Request $r, string $id)
    {
        $this->gate();
        $d = $r->validate(['action' => ['required', 'in:applied,rejected']]);
        $res = KpiInsights::decideTarget(auth()->user(), $id, $d['action']);

        return back()->with($res['ok'] ? 'ok' : 'err', $res['ok']
            ? ($d['action'] === 'applied' ? '🎯 اعتُمد الهدفُ المقترح' : 'رُفض الهدفُ المقترح') : $res['why']);
    }
}
