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

        // خط الإصدارات الزمني
        $releases = DB::table('code_releases')->whereNull('deleted_at')
            ->where('app_id', $app->id)
            ->orderByDesc('date')->orderByDesc('created_at')
            ->limit(30)->get();

        // الأعطال والتذاكر المفتوحة على هذا التطبيق
        $openish = fn ($q) => $q->where(fn ($w) => $w->whereNull('status')
            ->orWhere(fn ($x) => $x->where('status', 'NOT LIKE', '%مغلق%')
                                   ->where('status', 'NOT LIKE', '%محلول%')
                                   ->where('status', 'NOT LIKE', '%ملغ%')));
        $issues = $openish(DB::table('issues')->whereNull('deleted_at')->where('app_id', $app->id))
            ->orderByDesc('created_at')->limit(6)->get(['id', 'title', 'severity', 'status']);
        $issuesN = $openish(DB::table('issues')->whereNull('deleted_at')->where('app_id', $app->id))->count();
        $tickets = $openish(DB::table('tickets')->whereNull('deleted_at')->where('app_id', $app->id))
            ->orderByDesc('created_at')->limit(6)->get(['id', 'subject', 'priority', 'status']);
        $ticketsN = $openish(DB::table('tickets')->whereNull('deleted_at')->where('app_id', $app->id))->count();

        // أثقل بنود الخطة — مصدر النسبة أمام الفريق
        $feats = $app->project_id
            ? DB::table('plan_items')->whereNull('deleted_at')
                ->where('project_id', $app->project_id)
                ->where(fn ($w) => $w->whereNull('status')->orWhere('status', 'NOT LIKE', '%ملغ%'))
                ->orderByDesc('weight')->limit(10)
                ->get(['id', 'title', 'type', 'weight', 'progress', 'status', 'test'])
            : collect();

        return view('app-center.show', compact('app', 'progress', 'project', 'releases',
            'issues', 'issuesN', 'tickets', 'ticketsN', 'feats'));
    }
}
