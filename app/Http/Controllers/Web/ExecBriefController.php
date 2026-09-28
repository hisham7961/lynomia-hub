<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Ai\Brief\ExecBrief;

/**
 * **موجزُ الأسبوع** (docs/ai-hub/47 §العمود و) — للمالك وحدَه: خمسةُ أمورٍ تحتاج قرارَه، وزرُّ «أعد بناءه».
 */
class ExecBriefController extends Controller
{
    public function index()
    {
        $u = auth()->user();
        abort_unless($u instanceof \App\Models\User && hub_is_owner($u) && ExecBrief::ready(), 404);
        $b = ExecBrief::latest($u);

        return view('ai.brief', ['brief' => $b, 'items' => $b ? (array) json_decode((string) $b->items, true) : [],
            'enabled' => ExecBrief::enabled(), 'week' => ExecBrief::week()]);
    }

    public function refresh()
    {
        $u = auth()->user();
        abort_unless($u instanceof \App\Models\User && hub_is_owner($u) && ExecBrief::ready(), 404);
        $r = ExecBrief::run(false, true, $u);

        return back()->with($r['built'] ? 'ok' : 'err', $r['built'] ? 'بُني موجزُ الأسبوع' : 'تعذّر البناء: ' . ($r['code'] ?? '—'));
    }
}
