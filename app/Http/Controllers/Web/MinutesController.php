<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Decision;
use App\Models\Meeting;
use App\Models\Task;
use Illuminate\Http\Request;

/**
 * «استخرج من المحضر»: كان محضر الاجتماع نصّاً حرّاً لا يولّد شيئاً — والقرارات
 * تُعاد كتابتها يدوياً في وحدتها، والمهام كذلك. الأسطر المختارة تصير قرارات
 * (مربوطة بالاجتماع) ومهامَّ (مربوطة بقرارها) بنقرة واحدة.
 */
class MinutesController extends Controller
{
    public function extract(Request $r, string $id)
    {
        abort_unless(hub_can(auth()->user(), 'meetings', 'e'), 403, 'الاستخراج يتطلب صلاحية تعديل الاجتماعات');
        $m = hub_scope(Meeting::query(), 'meetings')->findOrFail($id);

        $d = $r->validate([
            'decisions'   => 'nullable|array',
            'decisions.*' => 'string|max:300',
            'tasks'       => 'nullable|array',
            'tasks.*'     => 'string|max:300',
        ]);

        $lines = fn (array $x) => array_values(array_filter(array_map('trim', $x), fn ($s) => $s !== ''));
        $decLines = $lines((array) ($d['decisions'] ?? []));
        $taskLines = $lines((array) ($d['tasks'] ?? []));
        abort_if(! $decLines && ! $taskLines, 422, 'اختر سطراً واحداً على الأقل من المحضر');

        abort_if($decLines && ! hub_can(auth()->user(), 'decisions', 'a'), 403, 'إنشاء القرارات يتطلب صلاحيتها');
        abort_if($taskLines && ! hub_can(auth()->user(), 'tasks', 'a'), 403, 'إنشاء المهام يتطلب صلاحيتها');

        $madeD = 0; $madeT = 0; $firstDecision = null;
        foreach ($decLines as $line) {
            $dec = Decision::create([
                'title' => \Illuminate\Support\Str::limit($line, 280, ''),
                'meeting_id' => $m->id,
                'project_id' => $m->project_id,
                'company_id' => $m->company_id,
                'client_id' => $m->client_id,
                'date' => substr((string) ($m->dt ?: now()), 0, 10),
                'status' => 'لم يبدأ',
                'notes' => 'استُخرج من محضر: ' . $m->title,
            ]);
            $firstDecision ??= $dec->id;
            $madeD++;
        }

        foreach ($taskLines as $line) {
            Task::create([
                'title' => \Illuminate\Support\Str::limit($line, 280, ''),
                'status' => 'جديدة',
                'project_id' => $m->project_id,
                'company_id' => $m->company_id,
                // المهمة تُنسب لقرار الاجتماع إن استُخرج معها — فيُقاس تنفيذ القرار
                'decision_id' => $firstDecision,
                'description' => 'استُخرجت من محضر: ' . $m->title,
            ]);
            $madeT++;
        }

        // الاجتماع الذي وُثّق محضره انعقد فعلاً
        if (blank($m->status) || $m->status === 'مجدول' || $m->status === 'بانتظار المحضر') {
            $m->status = 'انعقد';
            $m->save();
        }
        hub_audit('استخراج محضر', 'meetings', $m->id, $m->title . " — {$madeD} قرار، {$madeT} مهمة");

        return back()->with('ok', "📋 استُخرج من المحضر: {$madeD} قرار و{$madeT} مهمة — مربوطة بالاجتماع");
    }
}
