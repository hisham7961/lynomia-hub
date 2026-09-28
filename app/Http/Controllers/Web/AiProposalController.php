<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AiProposal;
use App\Support\Ai\Proposals\ProposalService;
use Illuminate\Http\Request;

/**
 * **صندوقُ اقتراحات الذكاء** — ما ينتظر قرارَك من تغييراتٍ اقترحها الذكاء، مع دليلِ كلٍّ منها.
 * الحرّاسُ كلُّها في `ProposalService` (صلاحيّةُ التعديل + النطاق + حجبُ الحقل)، والعميلُ ٤٠٤.
 */
class AiProposalController extends Controller
{
    public function index()
    {
        $u = auth()->user();
        abort_if(hub_is_client($u), 404);

        $items = ProposalService::openFor($u);

        return view('ai.proposals.index', [
            'items' => $items,
            'titles' => ProposalService::titles($items),
            'evidence' => $items->mapWithKeys(fn ($p) => [$p->id => ProposalService::evidenceFor($u, $p)])->all(),
            'enabled' => ProposalService::enabled(),
            'accuracy' => collect(array_keys(ProposalService::KINDS))
                ->mapWithKeys(fn ($k) => [$k => ProposalService::accuracy($k)])
                ->filter(fn ($a) => $a['decisions'] > 0)->all(),
        ]);
    }

    public function apply(Request $r, string $id)
    {
        [$u, $p] = $this->find($id);
        $d = $r->validate(['value' => ['nullable', 'string', 'max:2000']]);
        $res = ProposalService::apply($u, $p, $d['value'] ?? null);

        return back()->with($res['ok'] ? 'ok' : 'err', $res['ok']
            ? 'طُبِّق الاقتراح: ' . ProposalService::label($p->kind)
            : 'لم يُطبَّق: ' . $res['why']);
    }

    public function reject(Request $r, string $id)
    {
        [$u, $p] = $this->find($id);
        $d = $r->validate(['reason' => ['nullable', 'string', 'max:300']]);
        $res = ProposalService::reject($u, $p, $d['reason'] ?? null);

        return back()->with($res['ok'] ? 'ok' : 'err', $res['ok'] ? 'رُفض الاقتراح' : 'تعذّر الرفض: ' . $res['why']);
    }

    /** اقتراحٌ لا يملك القارئُ قرارَه يُردّ ٤٠٤ — لا يُكشف وجودُه */
    private function find(string $id): array
    {
        $u = auth()->user();
        abort_if(hub_is_client($u) || ! ProposalService::ready(), 404);
        $p = AiProposal::query()->find($id);
        abort_if($p === null || ! ProposalService::canAct($u, $p), 404);

        return [$u, $p];
    }
}
