<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ReportDigest;
use App\Support\Ai\Reports\DigestAccess;
use App\Support\Ai\Reports\ProjectReportDigest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * **«تقارير حسب المشروع»** — ملخّصُ الذكاء لكلِّ مشروعٍ في قسم التقارير، وتفصيلُه مع أحدث تقاريره،
 * و«تحديث الآن». الحرّاس كلُّها من `DigestAccess` (العميلُ ٤٠٤، ومشروعٌ خارج النطاق ٤٠٤).
 */
class ProjectDigestController extends Controller
{
    /** أقصى مشاريعَ في القائمة — والباقي بالبحث */
    public const LIST_LIMIT = 200;

    /** أحدثُ تقاريرِ المشروع في التفصيل */
    public const RECENT = 30;

    protected function guard(): \App\Models\User
    {
        $u = auth()->user();
        abort_if(hub_is_client($u), 404);
        abort_unless(DigestAccess::canUse($u), 403);

        return $u;
    }

    public function index(Request $r)
    {
        $u = $this->guard();
        $q = trim((string) $r->query('q', ''));

        // المشروعُ يُختار لا يُكتب (طلب المالك): الاختيارُ يفتح صفحةَ ملخّصه — بنطاق القارئ، وما خارجَه ٤٠٤
        $pick = trim((string) $r->query('project', ''));
        if ($pick !== '') {
            abort_unless(DigestAccess::project($u, $pick) !== null, 404);

            return redirect()->route('reports.projects.show', $pick);
        }
        $options = DigestAccess::projects($u)->orderBy('name')->orderBy('id')->limit(1000)->pluck('name', 'id')->all();

        $visible = DigestAccess::projects($u);
        if ($q !== '') $visible->where('name', 'like', '%' . $q . '%');
        $digests = ReportDigest::query()
            ->whereIn('project_id', (clone $visible)->select('id'))
            ->orderByDesc('generated_at')->orderBy('id')->limit(self::LIST_LIMIT)->get();
        $pids = $digests->pluck('project_id')->map(fn ($x) => (string) $x)->all();
        $names = $pids === [] ? [] : DigestAccess::projects($u)->whereIn('id', $pids)->pluck('name', 'id')->all();

        // «متأخّر»: تقريرٌ أحدثُ ممّا شمله الملخّص — بعين القارئ نفسِه
        $latest = $pids === [] ? collect() : hub_scope(DB::table('work_updates')->whereNull('deleted_at'), 'updates', $u)
            ->whereIn('project_id', $pids)->groupBy('project_id')
            ->selectRaw('project_id, MAX(created_at) AS last_at')->get()
            ->mapWithKeys(fn ($x) => [(string) $x->project_id => (string) $x->last_at]);

        // مشاريعُ نشطةٌ في نطاقه لها تقاريرُ في النافذة ولم تُلخَّص بعد
        $waiting = hub_scope(DB::table('work_updates')->whereNull('deleted_at'), 'updates', $u)
            ->whereIn('project_id', (clone $visible)->select('id'))
            ->whereNotIn('project_id', ReportDigest::query()->select('project_id'))
            ->where('work_date', '>=', now()->subDays(ProjectReportDigest::days())->toDateString())
            ->distinct()->count('project_id');

        return view('reports.projects', [
            'digests' => $digests, 'names' => $names, 'latest' => $latest, 'waiting' => $waiting, 'q' => $q,
            'options' => $options,
            'masked' => DigestAccess::masked($u), 'why' => ProjectReportDigest::whyNot(),
        ]);
    }

    public function show(string $id)
    {
        $u = $this->guard();
        $project = DigestAccess::project($u, $id);
        abort_if($project === null, 404);

        $digest = ReportDigest::query()->where('project_id', $project->id)->orderBy('id')->first();

        // أحدثُ التقارير — بنطاق القارئ، وكلُّ حقلٍ بـhub_field_mode
        $reports = hub_scope(DB::table('work_updates')->whereNull('deleted_at'), 'updates', $u)
            ->where('project_id', $project->id)
            ->orderByDesc('work_date')->orderByDesc('created_at')->orderByDesc('id')->limit(self::RECENT)
            ->get(['id', 'created_by', 'work_date', 'done', 'doing', 'problems', 'needs', 'next', 'hours', 'progress', 'review_status']);
        $see = [];
        foreach (array_merge(ProjectReportDigest::TEXT_FIELDS, ['hours', 'progress']) as $f) {
            $see[$f] = hub_field_mode($u, 'updates', $f) !== 'hide';
        }
        $authors = hub_ref_labels('users', $reports->pluck('created_by')->filter()->unique()->values()->all());

        return view('reports.project', [
            'project' => $project, 'digest' => $digest, 'reports' => $reports, 'see' => $see, 'authors' => $authors,
            'masked' => DigestAccess::masked($u), 'canRefresh' => DigestAccess::canRefresh($u) && ProjectReportDigest::ready(),
            'why' => ProjectReportDigest::whyNot(),
            // ملفُّ فهم المشروع واقتراحاتُه (المرحلة ٥) — لمن يراه وحدَه
            'understanding' => \App\Support\Ai\Understanding\ProjectUnderstanding::forViewer($u, (string) $project->id),
            'canUnderstand' => DigestAccess::canRefresh($u) && \App\Support\Ai\Understanding\ProjectUnderstanding::ready(),
            // مصادرُ الفهم (المرحلة ٤): أسماءُ الملفّات وحالتُها ولقطةُ الموقع — بلا نصٍّ في الصفحة
            'sources' => \App\Support\Ai\Sources\ProjectSources::ready() ? \App\Support\Ai\Sources\ProjectSources::summary((string) $project->id) : null,
        ]);
    }

    /** «تحديث الآن» — مخنوقٌ بالمسار، ومقفولٌ لكلِّ مشروع (لا نداءان متزامنان)، ومدقَّق */
    public function refresh(string $id)
    {
        $u = $this->guard();
        abort_unless(DigestAccess::canRefresh($u), 403);
        $project = DigestAccess::project($u, $id);
        abort_if($project === null, 404);

        if (! ProjectReportDigest::ready()) {
            return back()->with('err', (string) ProjectReportDigest::whyNot());
        }
        // حدُّ المشروع فوق حدِّ المستخدم: نقرةٌ واحدةٌ في الدقيقة لكلِّ مشروع — مهما تعدّد النقّارون
        if (! Cache::add('report-digest-refresh:' . $project->id, 1, 60)) {
            return back()->with('err', 'حُدِّث هذا الملخّصُ قبل لحظات — أعد المحاولة بعد دقيقة');
        }

        $res = ProjectReportDigest::refresh($u, (string) $project->id);

        return redirect()->route('reports.projects.show', $project->id)->with($res['ok'] ? 'ok' : 'err', $res['message']);
    }
}
