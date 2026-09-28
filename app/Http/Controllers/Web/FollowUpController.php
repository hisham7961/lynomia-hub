<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AiCommitment;
use App\Support\Ai\FollowUp\FollowUp;
use Illuminate\Http\Request;

/**
 * **«متابعاتي» و«متابعات فريقي»** — التزاماتُ التقارير التي يتابعها مساعدُ Hub (docs/ai-hub/47 §العمود ب).
 * صاحبُ الالتزام يجيب بنقرة؛ والمديرُ المباشر يرى فريقه وحدَه (والمالكُ الجميع). والعميلُ ٤٠٤.
 */
class FollowUpController extends Controller
{
    public function mine()
    {
        $u = $this->user();

        return view('followups.mine', [
            'open' => FollowUp::mine($u),
            'closed' => FollowUp::mine($u, false),
            'enabled' => FollowUp::enabled(),
            'muted' => in_array('followup', \App\Support\Platform\PrefService::mute($u), true),
        ]);
    }

    public function answer(Request $r, string $id)
    {
        $u = $this->user();
        $d = $r->validate([
            'answer' => ['required', 'string', 'in:' . implode(',', array_keys(FollowUp::ANSWERS))],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $c = AiCommitment::query()->find($id);
        abort_if($c === null || (string) $c->user_id !== (string) $u->id, 404);

        $res = FollowUp::answer($u, $c, $d['answer'], $d['date'] ?? null, $d['note'] ?? null);

        return back()->with($res['ok'] ? 'ok' : 'err', $res['ok'] ? 'سُجّل ردّك: ' . FollowUp::ANSWERS[$d['answer']] : $res['why']);
    }

    public function team()
    {
        $u = $this->user();
        $ids = FollowUp::teamUserIds($u);
        abort_if($ids === [], 404);
        $rows = FollowUp::team($u);
        $users = \App\Models\User::query()->whereIn('id', $rows->pluck('user_id')->unique()->values()->all())->get(['id', 'name', 'prefs']);

        return view('followups.team', [
            'rows' => $rows,
            'names' => $users->pluck('name', 'id')->all(),
            'muted' => $users->filter(fn ($x) => in_array('followup', (array) data_get($x->prefs, 'mute', []), true))
                ->pluck('id')->map(fn ($x) => (string) $x)->all(),
            'accuracy' => FollowUp::accuracy(),
        ]);
    }

    public function close(Request $r, string $id)
    {
        $u = $this->user();
        $d = $r->validate(['status' => ['required', 'in:done,dropped']]);
        $c = AiCommitment::query()->find($id);
        abort_if($c === null || ! FollowUp::manages($u, (string) $c->user_id) || (string) $c->user_id === (string) $u->id, 404);

        $res = FollowUp::managerClose($u, $c, $d['status']);

        return back()->with($res['ok'] ? 'ok' : 'err', $res['ok'] ? 'أُغلق الالتزام' : $res['why']);
    }

    private function user(): \App\Models\User
    {
        $u = auth()->user();
        abort_if(! $u instanceof \App\Models\User || hub_is_client($u) || ! FollowUp::ready(), 404);

        return $u;
    }
}
