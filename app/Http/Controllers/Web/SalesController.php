<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Finance\SalesBoard;

/**
 * لوحةُ المبيعات وتحليلاتُ العروض (CPQ د) — للمالكين ولحاملي المراقبة، بنمطِ
 * `FieldController::dashboard`: بوّابةٌ في المتحكم، تجميعٌ مُخبَّأ، عرضُ لوحة.
 */
class SalesController extends Controller
{
    public function dashboard()
    {
        // الرايةُ تُسمّى باسمِها في شاشةِ الأدوار لا بوصفٍ عامّ (الخاتمة · X5)
        abort_unless(hub_is_owner() || hub_monitor_group('finAnalytics'), 403,
            'لوحةُ المبيعات للمالكين ولمن يحمل راية «لوحاتُ الماليّةِ والتكاليف» '
            . '(أو رايةَ المراقبة الشاملة) — تُمنح من شاشة الأدوار، قسم الرايات.');
        $internal = hub_field_mode(auth()->user(), 'quotes', 'cost') !== 'hide';

        return view('sales.dashboard', ['d' => SalesBoard::data(), 'internal' => $internal]);
    }
}
