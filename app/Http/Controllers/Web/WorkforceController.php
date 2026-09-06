<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\ExecutionStats;
use Illuminate\Http\Request;

/**
 * نظرةُ القوى العاملة (WP-7.2 · spec §46): ما أُنجز، ما تأخّر، نسبةُ الالتزام،
 * أين اختلالُ التوزيع — أرقامُ **تنفيذٍ** لا مراقبةٌ شخصية: لا زيارات، لا
 * ترتيبَ موظفين بالنشاط، ولا أيَّ درجةٍ أمنية (تلك في مركز الأمان وحدَه).
 */
class WorkforceController extends Controller
{
    /** حارسُ لوحات المنشأة — نمطُ CapacityController حرفياً */
    protected function gate(): void
    {
        abort_unless(hub_monitor(),
            403, 'هذه اللوحة للمالكين ومن يحمل صلاحية المتابعة');
        // أرقامُها تجمع عبر كل الشركات بلا تنطيق — تُمنع عن الحساب المعزول
        hub_org_analytics_guard();
    }

    public function overview(Request $r)
    {
        $this->gate();

        $range = hub_range($r);   // 7d افتراضاً، و30d/90d من كبسولات الشريط
        $x = ExecutionStats::org($range, $r->user());

        // فرزُ لوح الأقسام (روابطُ partials.cc.th) — مفاتيحُ بيضاء لا إدخالٌ حر،
        // وكسرُ التعادل باسم القسم دائماً فالترتيبُ حتميٌّ على المحرّكين
        $keys = ['dept' => 'dept', 'heads' => 'heads', 'open' => 'open',
                 'overdue' => 'overdue', 'done' => 'done', 'ontime' => 'on_time_pct'];
        $sort = $keys[hub_str($r->query('sort'))] ?? 'open';
        $asc = strtolower(hub_str($r->query('dir'))) === 'asc';
        usort($x['departments'], function ($a, $b) use ($sort, $asc) {
            $va = $a[$sort] ?? -1; $vb = $b[$sort] ?? -1;   // «—» (null) في الذيل دائماً
            $c = $sort === 'dept' ? strcmp($a['dept'], $b['dept']) : ($va <=> $vb);

            return ($asc ? $c : -$c) ?: strcmp($a['dept'], $b['dept']);
        });

        // ── Control Plane: Phase 7 (WP-7.4) ── قارئُ الاختناقات: مراحلُ الانتظار
        // ومكوثُ الحالة والمعوّقاتُ والراكدُ وإعادةُ الفتح — وإشاراتُ مركز الفعل
        // (proj.stalled/proj.blockers/sla.breach) تُقرأ كما هي فتتّفق الشاشتان.
        $bn = ExecutionStats::bottlenecks($range);

        return view('workforce.overview', ['x' => $x, 'range' => $range, 'bn' => $bn]);
    }
}
