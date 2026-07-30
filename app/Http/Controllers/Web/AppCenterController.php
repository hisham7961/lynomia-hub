<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Support\Facades\DB;

/**
 * مركز التطبيق: النسخة وحالات مراجعة المتاجر، نسبة الإنجاز الحية،
 * خط زمني للإصدارات، الأعطال والتذاكر المفتوحة، وبنود الخطة بأوزانها.
 */
class AppCenterController extends Controller
{
    public function show(string $id)
    {
        abort_unless(hub_can(auth()->user(), 'apps', 'v'), 403);
        $app = hub_scope(Application::query(), 'apps')->findOrFail($id);

        // نسبة الإنجاز من مشروع التطبيق
        $progress = $app->project_id ? hub_progress($app->project_id) : null;
        $project  = $app->project_id
            ? DB::table('projects')->where('id', $app->project_id)->first(['id', 'name'])
            : null;

        // خط الإصدارات الزمني — النطاق يُفرض على الابن: رؤية التطبيق لا تمنح رؤية إصداراته
        $releases = hub_scope(DB::table('code_releases')->whereNull('deleted_at'), 'code')
            ->where('app_id', $app->id)
            ->orderByDesc('date')->orderByDesc('created_at')
            ->limit(30)->get();

        // الأعطال والتذاكر المفتوحة على هذا التطبيق — «مفتوح» من التعريف الموحَّد
        // كل استعلام يُبنى من جديد ومُنطَّقاً — والعدّاد يُنطَّق كذلك وإلا فضح الرقمُ المخفيَّ
        $scoped = fn (string $table, string $mk) => hub_open_scope(
            hub_scope(DB::table($table)->whereNull('deleted_at'), $mk)->where('app_id', $app->id)
        );
        $issues = $scoped('issues', 'issues')
            ->orderByDesc('created_at')->limit(6)->get(['id', 'title', 'severity', 'status']);
        $issuesN = $scoped('issues', 'issues')->count();
        $tickets = $scoped('tickets', 'tickets')
            ->orderByDesc('created_at')->limit(6)->get(['id', 'subject', 'priority', 'status']);
        $ticketsN = $scoped('tickets', 'tickets')->count();

        // أثقل بنود الخطة — مصدر النسبة أمام الفريق
        $feats = $app->project_id
            ? hub_scope(DB::table('plan_items')->whereNull('deleted_at'), 'feats')
                ->where('project_id', $app->project_id)
                ->tap(fn ($q) => hub_open_scope($q))
                ->orderByDesc('weight')->limit(10)
                ->get(['id', 'title', 'type', 'weight', 'progress', 'status', 'test'])
            : collect();

        return view('app-center.show', compact('app', 'progress', 'project', 'releases',
            'issues', 'issuesN', 'tickets', 'ticketsN', 'feats'));
    }
}
