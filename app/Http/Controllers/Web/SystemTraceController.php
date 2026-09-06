<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Correlation;

/**
 * (WP-1.4) صفحةُ **أثر الطلب** `system/trace/{rid}` — «هذا المعرّفُ في يدي،
 * ماذا فعل هذا الطلبُ في النظام؟». تجيب من قارئ `Correlation` الواحد.
 *
 * الحارس: المالك أو حاملُ علم `audit` — ومع الثاني تُخفى مصادرُ المالك ويُنطَّق
 * التدقيق (القارئُ نفسُه يفرض ذلك، لا العرض). معرّفٌ مجهول حالةٌ فارغة صادقة لا ٥٠٠.
 * الاسمُ `system.trace` — فالاسم `trace` مملوكٌ لسلسلة التسليم (TraceController).
 */
class SystemTraceController extends Controller
{
    public function show(string $rid)
    {
        $u = auth()->user();
        abort_unless(hub_is_owner($u) || hub_flag($u, 'audit'), 403, 'أثرُ الطلب للمالك أو حامل صلاحية التدقيق');

        $rid = mb_substr(trim($rid), 0, 64);
        $rows = Correlation::forRequestId($rid, $u);

        $counts = [];
        foreach ($rows as $row) $counts[$row['kind']] = ($counts[$row['kind']] ?? 0) + 1;
        $kpis = [];
        foreach (Correlation::KINDS as $k => $label) {
            if (isset($counts[$k])) $kpis[] = ['label' => $label, 'value' => $counts[$k], 'tone' => ''];
        }

        return view('system.trace', [
            'rid'      => $rid,
            'rows'     => $rows,
            'kpis'     => $kpis,
            'external' => Correlation::looksExternal($rid),
            'isOwner'  => hub_is_owner($u),
        ]);
    }
}
