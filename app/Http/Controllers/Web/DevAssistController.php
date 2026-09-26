<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ErrorEvent;
use App\Support\Ai\Dev\ErrorTriage;
use Illuminate\Http\Request;

/**
 * **مساعدُ التطوير** (المرحلة ٥) — «اشرح هذا الخطأ» في مركز الأخطاء: شرحٌ واقتراحُ إصلاحٍ ونموذجُ «مشكلة»
 * معبّأٌ يحفظه صاحبُه. لا يكتب شيئاً.
 */
class DevAssistController extends Controller
{
    public function explain(Request $r, string $id)
    {
        // بابُ مركز الأخطاء نفسُه أوّلاً — ثمّ جاهزيّةُ المساعد
        abort_unless(hub_is_owner(), 403, 'مركز الأخطاء للمالكين فقط');
        abort_unless(ErrorTriage::ready($r->user()), 403, 'مساعدُ التطوير غيرُ متاحٍ لك الآن');
        $e = ErrorEvent::findOrFail($id);

        return view('ai.dev_error', ['e' => $e, 'result' => ErrorTriage::explain($r->user(), $e)]);
    }
}
