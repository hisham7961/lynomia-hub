<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\WidgetRegistry;

/**
 * اللوحة صارت مستهلكاً رقيقاً لسجل الودجات: كل بطاقة تُعرَّف مرة واحدة في
 * `WidgetRegistry` باسمها وبوابتها ومُحضِّرها، وهذا المتحكم يجمع ما يراه المستخدم.
 * المتغيّرات المُمرَّرة للقالب لم تتغيّر — السلوك مطابق والقالب لم يُمَس.
 */
class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $hid  = WidgetRegistry::hiddenFor($user);

        $cards      = WidgetRegistry::resolve('counts', $user) ?? [];
        $kpis       = WidgetRegistry::resolve('kpis', $user) ?? [];
        $expiry     = WidgetRegistry::resolve('expiry', $user) ?? collect();
        $apps       = WidgetRegistry::resolve('apps', $user) ?? collect();
        $taskSlices = WidgetRegistry::resolve('donut', $user) ?? [];
        $audits     = WidgetRegistry::resolve('audits', $user) ?? collect();

        // ودجة المواعيد تُعيد صفوفها وأسماء أعمدتها معاً — القالب يحتاجها مفكوكة
        $dueBox = WidgetRegistry::resolve('due', $user)
            ?? ['rows' => collect(), 'dueCol' => null, 'stCol' => null, 'disp' => null];
        $due    = $dueBox['rows'];
        $dueCol = $dueBox['dueCol'];
        $stCol  = $dueBox['stCol'];
        $disp   = $dueBox['disp'];

        return view('dashboard', compact('cards', 'audits', 'due', 'dueCol', 'stCol',
            'disp', 'expiry', 'apps', 'taskSlices', 'hid', 'kpis'));
    }
}
