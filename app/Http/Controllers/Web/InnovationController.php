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

        // العدسة قبل القصّ لا بعده. والطريق مزدوج: عمود المشروع يعني «المشروع
        // الناتج إن رُقّيت» — فوحده يُخفي كل فكرةٍ لم تُرقَّ بعد، وهي جوهر
        // الصفحة. فيُضاف الطريق عبر الخدمة: فكرةٌ تطوّر خدمةً في المشروع.
        $lens = hub_lens();
        /*
         * **البسطُ والمقامُ كلاهما من القاعدة** (W-5 · الطور ١٦٧). كانت
         * الجملةُ «{المقيَّمة} من {الكل} فكرة مقيَّمة» تقرأ طرفَيها من
         * الثلاثمئةِ المحمَّلةِ للعرض — فالنسبةُ متّسقةٌ مع نفسِها وتصف
         * **الصفحةَ لا المنشأة**. و«المقيَّمة» تعريفُها في `Idea::iceScore()`:
         * أثرٌ وثقةٌ وسهولةٌ لا شيءَ منها فارغ — وهو شرطٌ يُكتب استعلاماً.
         */
        $ideasQ = fn () => hub_scope(Idea::query(), 'ideas')
            ->when($lens['id'], fn ($q) => $q->where(fn ($w) => $w->where('project_id', $lens['id'])
                ->orWhereIn('service_id', \Illuminate\Support\Facades\DB::table('services')
                    ->select('id')->where('project_id', $lens['id']))));
        $ideasN = $ideasQ()->count();
        $scoredN = $ideasQ()->whereNotNull('impact')->whereNotNull('confidence')
            ->whereNotNull('ease')->count();
        $ideas = $ideasQ()
            ->orderByDesc('created_at')->limit(300)->get()
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
            'contributors' => \App\Support\Innovation::contributors(),
            'pulse' => \App\Support\Innovation::pulse(),
            'atts' => \App\Support\Innovation::attachmentCounts($ideas->pluck('id')->all()),
            'scored' => $scoredN,
            'ideasN' => $ideasN,
            'lens' => $lens,
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
        /*
         * **معاملةٌ على الفكرةِ مقفولةً — كتابتان واقعةٌ واحدة** (البند #3 · DI-07).
         *
         * الإنشاءُ والربطُ كانا كتابتَين مكشوفتَين، وفيهما عطلان:
         *
         *  · **سقوطُ الربطِ يترك مشروعاً يتيماً** — لا فكرةَ تشير إليه، ويظهر
         *    في كانبان المشاريعِ كعملٍ حقيقيّ، والفكرةُ تبقى قابلةً للترقية
         *    فيُولَد له توأم.
         *  · **والفحصُ خارجَ القفلِ قرعة** — نقرتان متزامنتان (أو نقرةٌ
         *    وإعادةُ إرسال) تمرّان معاً على `abort_if` فارغ، فيُنشأ مشروعان
         *    ويُهمَل أوّلُهما حين يكتب الثاني `project_id` فوقَه.
         *
         * والفحصُ فوقُ يبقى كما هو: ردٌّ ٤٢٢ سريعٌ بلا قفلٍ في الحالةِ الشائعة.
         * **وهذا هنا هو الفاصلُ لا ذاك.**
         */
        $project = \Illuminate\Support\Facades\DB::transaction(function () use ($id, $cid) {
            $idea = hub_scope(Idea::query(), 'ideas')->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($idea->project_id, 422, 'رُقّيت هذه الفكرة لمشروع من قبل');

            $project = Project::create([
                'name' => \Illuminate\Support\Str::limit((string) $idea->title, 120, ''),
                'status' => 'تخطيط',
                'company_id' => $cid ?: null,
                'description' => trim("من مركز الابتكار.\n\nالمشكلة: " . (string) $idea->problem . "\n\nالفكرة: " . (string) $idea->idea),
            ]);

            $idea->update(['project_id' => $project->id, 'status' => 'قيد التنفيذ']);

            return $project;
        });

        return redirect()->route('m.show', ['projects', $project->id])
            ->with('ok', '🚀 رُقّيت الفكرة إلى مشروع — أكمل تخطيطه من هنا');
    }
}
