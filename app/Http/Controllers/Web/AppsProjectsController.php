<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\AppsProjects;

/**
 * التطبيقات والمشاريع: علاقةٌ واحدة محسومة.
 * السجل يقول «التطبيق يتبع مشروعاً»، وثماني وحداتٍ تعرض الحقلين معاً بلا رابط.
 */
class AppsProjectsController extends Controller
{
    protected function gate(): void
    {
        abort_unless(hub_can(auth()->user(), 'apps', 'v') || hub_can(auth()->user(), 'projects', 'v'),
            403, 'يتطلب عرض التطبيقات أو المشاريع');
    }

    public function index()
    {
        $this->gate();

        return view('apps_projects', ['d' => AppsProjects::scan()]);
    }

    /** ملء المشروع من التطبيق حيث كان فارغاً — والتناقض لا يُمسّ */
    public function fix()
    {
        $this->gate();
        $n = AppsProjects::fixMissing();

        return back()->with('ok', $n
            ? "🔗 رُبط {$n} سجلاً بمشروع تطبيقه — وصارت تُحسب في مجاميع مشروعها وعدسته"
            : 'لا سجلَّ ناقصَ المشروع — أو لا تملك تعديل وحداتها');
    }
}
