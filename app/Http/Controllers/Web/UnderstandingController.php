<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Ai\Reports\DigestAccess;
use App\Support\Ai\Understanding\ProjectUnderstanding;
use Illuminate\Http\Request;

/**
 * **ملفُّ فهم المشروع واقتراحاتُه** (docs/ai-hub/47 §العمود ج) — «تحديث الفهم» لمن يملك تحديثَ الملخّص،
 * و«حوّلها مهمّة» / «تجاهل» لكلِّ اقتراح. الحرّاسُ في `ProjectUnderstanding::forViewer` (نطاقُ المشروع ·
 * updates:v · الحجب · files:v لما بُني من الوثائق)، والعميلُ ٤٠٤.
 */
class UnderstandingController extends Controller
{
    public function refresh(string $id)
    {
        $u = auth()->user();
        abort_if(! $u instanceof \App\Models\User || hub_is_client($u) || DigestAccess::project($u, $id) === null, 404);
        abort_unless(DigestAccess::canRefresh($u) && ProjectUnderstanding::ready(), 403);

        $r = ProjectUnderstanding::run(false, $id, $u, true);
        hub_audit(ProjectUnderstanding::AUDIT_REFRESH, 'projects', $id, 'ملفُّ فهم المشروع');

        return back()->with($r['built'] > 0 ? 'ok' : 'err', $r['built'] > 0 ? 'حُدِّث ملفُّ فهم المشروع'
            : 'لم يُحدَّث: ' . ($r['code'] ?? ($r['failed'] ? 'ردٌّ غيرُ صالح من النموذج' : 'لا مصادرَ كافية للفهم')));
    }

    public function suggestion(Request $r, string $id, string $sid)
    {
        $u = auth()->user();
        abort_if(! $u instanceof \App\Models\User || hub_is_client($u), 404);
        $d = $r->validate(['action' => ['required', 'in:task,dismiss'], 'reason' => ['nullable', 'string', 'max:200']]);

        $s = ProjectUnderstanding::decide($u, $id, $sid, $d['action'], $d['reason'] ?? null);
        abort_if($s === null, 404);

        if ($d['action'] === 'task') {
            // مسودةٌ لا حفظ: نموذجُ المهمّة مملوءٌ بالاقتراح ومشروعِه — والحفظُ بصلاحيّة المستخدم وتحقّق النموذج
            return redirect()->route('m.create', ['module' => 'tasks', 'title' => \Illuminate\Support\Str::limit($s['text'], 180, '…'), 'projectId' => $id]);
        }

        return back()->with('ok', 'تُجوهل الاقتراح');
    }
}
