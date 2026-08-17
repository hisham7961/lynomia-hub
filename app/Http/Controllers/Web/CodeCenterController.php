<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\CodeHub;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * **مركزُ الكود المصدري** — صفحةُ إصداراتٍ على شاكلةِ ما يعرفه المطوّرون.
 *
 * الوحدةُ نفسُها جدولٌ يصلح للإدخال ولا يصلح للقراءة: «ما الجديد في النسخة
 * الأخيرة؟» كان جوابُه فتحَ سجلٍّ ونسخَ نصٍّ ومقارنةً بالذاكرة. هنا الإصداراتُ
 * كما تُقرأ: الأحدثُ متصدّرٌ بوسمه وشارته، وسجلُّ تغييراتٍ منسّق، وحِزَمٌ
 * تُنزَّل، ووتيرةٌ تقول أحيٌّ المشروع، وإصدارٌ جديدٌ يُكتب من الصفحة نفسها.
 *
 * والإنشاءُ يمرّ بمسار الوحدة القياسيّ (`m.store`) لا بمسارٍ موازٍ — فالتدقيق
 * والإصدارات ومسارات العمل والنطاق كلُّها تعمل كما تعمل في كل وحدة.
 */
class CodeCenterController extends Controller
{
    public function index(Request $r)
    {
        abort_unless(hub_can(auth()->user(), 'code', 'v'), 403, 'لا تملك عرض الكود المصدري');

        // الحصرُ بتطبيقٍ أو مشروع — ومعرّفٌ خارج نطاق القارئ لا يُظهر شيئاً
        $appId = hub_str($r->input('app')) ?: null;
        $projId = hub_str($r->input('project')) ?: null;

        $releases = CodeHub::releases($appId, $projId);
        $cadence = CodeHub::cadence($releases);

        // مرشّحاتٌ من داخل نطاق القارئ وحده
        $apps = hub_can(auth()->user(), 'apps', 'v')
            ? hub_scope(\App\Models\Application::query(), 'apps')
                ->orderBy('name')->limit(200)->pluck('name', 'id')
            : collect();
        $projects = hub_can(auth()->user(), 'projects', 'v')
            ? hub_scope(\App\Models\Project::query(), 'projects')
                ->orderByDesc('created_at')->limit(200)->pluck('name', 'id')
            : collect();

        // مستودعُ الكود: من الإصدارات نفسِها أوّلاً ثم من التطبيق المختار
        $repo = collect($releases)->pluck('row.repo')->filter()->first();
        if (! $repo && $appId && $apps->has($appId)) {
            $repo = DB::table('applications')->where('id', $appId)->value('git');
        }

        $branches = collect($releases)->pluck('row.branch')->filter()->unique()->take(8)->values()->all();
        $tags = collect($releases)->flatMap(fn ($x) => $x['tags'])->countBy()
            ->sortDesc()->take(12)->all();

        return view('code.center', [
            'releases' => $releases,
            'cadence'  => $cadence,
            'apps'     => $apps,
            'projects' => $projects,
            'appId'    => $appId,
            'projId'   => $projId,
            'repo'     => $repo,
            'branches' => $branches,
            'tags'     => $tags,
        ]);
    }
}
