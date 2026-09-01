<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\SalesBoard;

/**
 * لوحةُ المبيعات وتحليلاتُ العروض (CPQ د) — للمالكين ولحاملي المراقبة، بنمطِ
 * `FieldController::dashboard`: بوّابةٌ في المتحكم، تجميعٌ مُخبَّأ، عرضُ لوحة.
 */
class SalesController extends Controller
{
    public function dashboard()
    {
        abort_unless(hub_is_owner() || hub_monitor(), 403, 'لوحةُ المبيعات للمالكين وحاملي المراقبة');
        $internal = hub_field_mode(auth()->user(), 'quotes', 'cost') !== 'hide';

        return view('sales.dashboard', ['d' => SalesBoard::data(), 'internal' => $internal]);
    }
}
