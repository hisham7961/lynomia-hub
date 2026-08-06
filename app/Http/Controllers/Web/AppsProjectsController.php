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
        ['fixed' => $n, 'denied' => $d, 'modules' => $mods] = AppsProjects::fixMissing();

        // ثلاثُ حصائلَ لا حصيلةٌ واحدة: أُصلح · رُفض · لا شيءَ ناقص —
        // وكلٌّ بلونها. كانت الثلاثةُ رسالةً خضراءَ واحدة تصف حالتين متناقضتين.
        $r = back();
        if ($n) {
            $r->with('ok', "🔗 رُبط {$n} سجلاً بمشروع تطبيقه — وصارت تُحسب في مجاميع مشروعها وعدسته");
            if ($d) $r->with('warn', "وتُرك {$d} سجلاً: لا تملك تعديل "
                . implode('، ', array_slice($mods, 0, 4)) . ' — اطلبها ممّن يملكها');
        } elseif ($d) {
            $r->with('err', "{$d} سجلاً ناقصَ المشروع لا تملك تعديل وحداتها ("
                . implode('، ', array_slice($mods, 0, 4)) . ') — النقصُ قائمٌ ولم يُصلَح');
        } else {
            $r->with('ok', 'لا سجلَّ ناقصَ المشروع — كلُّ مرتبطٍ بتطبيقٍ يعرف مشروعه');
        }

        return $r;
    }
}
