<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Idea;
use App\Models\Project;

/**
 * مركز الابتكار: لوحة الأفكار مرتّبة بدرجة ICE (الأثر × الثقة × السهولة)،
 * وترقية الفكرة المعتمدة إلى مشروع بنقرة — يرث عنوانها ووصفها ويربطهما.
 */
class InnovationController extends Controller
{
    public function index()
    {
        abort_unless(hub_can(auth()->user(), 'ideas', 'v'), 403);

        $ideas = hub_scope(Idea::query(), 'ideas')->orderByDesc('created_at')->limit(300)->get()
            ->map(function ($i) {
                $i->ice = $i->iceScore();
                return $i;
            })
            ->sortByDesc(fn ($i) => $i->ice ?? -1)->values();

        $byUsers = \Illuminate\Support\Facades\DB::table('users')
            ->whereIn('id', $ideas->pluck('by_id')->filter())->pluck('name', 'id');

        return view('innovation', [
            'ideas' => $ideas,
            'byUsers' => $byUsers,
            'scored' => $ideas->filter(fn ($i) => $i->ice !== null)->count(),
        ]);
    }

    /** ترقية فكرة إلى مشروع — للمعتمدة، ولمن يملك إضافة مشاريع */
    public function promote(string $id)
    {
        abort_unless(hub_can(auth()->user(), 'ideas', 'e'), 403);
        abort_unless(hub_can(auth()->user(), 'projects', 'a'), 403, 'ترقية الفكرة لمشروع تتطلب صلاحية إضافة مشاريع');

        $idea = hub_scope(Idea::query(), 'ideas')->findOrFail($id);
        abort_if($idea->project_id, 422, 'رُقّيت هذه الفكرة لمشروع من قبل');

        // «تخطيط» من خيارات المشاريع المعلنة (كانت «قيد التخطيط» فلا يظهر المشروع
        // في أي عمود كانبان)، والشركة تُورَّث كوراثة الإنشاء العادي — الشركة النشطة
        // وإلا أولى شركات المعزول — فلا يولد المشروع يتيماً يختفي عن صاحبه
        $cid = (string) session('hub.company', '');
        $allowed = hub_company_ids();
        if ($cid === '' || ($allowed !== null && ! in_array($cid, $allowed, true))) {
            $cid = $allowed[0] ?? null;
        }
        $project = Project::create([
            'name' => \Illuminate\Support\Str::limit((string) $idea->title, 120, ''),
            'status' => 'تخطيط',
            'company_id' => $cid ?: null,
            'description' => trim("من مركز الابتكار.\n\nالمشكلة: " . (string) $idea->problem . "\n\nالفكرة: " . (string) $idea->idea),
        ]);

        $idea->update(['project_id' => $project->id, 'status' => 'قيد التنفيذ']);

        return redirect()->route('m.show', ['projects', $project->id])
            ->with('ok', '🚀 رُقّيت الفكرة إلى مشروع — أكمل تخطيطه من هنا');
    }
}
