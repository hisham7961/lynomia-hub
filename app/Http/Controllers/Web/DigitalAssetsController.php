<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\DigitalAssets;
use Illuminate\Http\Request;

/**
 * مركز الأصول الرقمية — الجواب على «أضفتُه… ثم ماذا؟»
 *
 * لوحةٌ تجمع المنشأة كلها (ملكية، ونصف قطر انفجار، وصحة أسرار) — فهي كأخواتها
 * التحليلية: لحاملي المراقبة، وممنوعةٌ على الحساب المعزول بشركاتٍ محددة.
 */
class DigitalAssetsController extends Controller
{
    public function index(Request $r)
    {
        abort_unless(hub_monitor(), 403, 'مركز الأصول الرقمية للمالكين ومن يحمل صلاحية المتابعة');
        hub_org_analytics_guard();

        return view('digital_assets', [
            'd' => DigitalAssets::all((bool) $r->query('fresh')),
            'currency' => setting('app.currency', 'د.ك'),
        ]);
    }
}
