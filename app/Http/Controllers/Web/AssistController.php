<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Ai\Assist\DraftAssistant;
use Illuminate\Http\Request;

/**
 * **المساعدُ التنفيذيّ** (المرحلة ٣) — زرٌّ على صفحة السجلّ يعرض مسودةً ولا يكتب شيئاً:
 * نموذجُ الإنشاء القائمُ معبّأً (يحفظه السائلُ بصلاحيّاته) أو نصُّ ردٍّ ينسخه بنفسه.
 */
class AssistController extends Controller
{
    public function draft(Request $r)
    {
        $u = $r->user();
        abort_unless(DraftAssistant::ready($u), 403, 'المساعدُ التنفيذيُّ غيرُ متاحٍ لك الآن');
        $data = $r->validate([
            'kind' => ['required', 'string', 'in:' . implode(',', array_keys(DraftAssistant::KINDS))],
            'module' => ['required', 'string', 'max:60'],
            'id' => ['required', 'string', 'max:64'],
        ]);
        abort_unless(hub_mod($data['module']) !== null, 404);

        return view('ai.assist', [
            'result' => DraftAssistant::draft($u, $data['kind'], $data['module'], $data['id']),
            'def' => DraftAssistant::KINDS[$data['kind']],
        ]);
    }
}
