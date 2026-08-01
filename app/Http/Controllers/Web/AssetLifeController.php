<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\AssetLife;

/** العهدة ودورة حياة الأصول — الحقول الأغلى ثمناً كانت تُملأ ولا تُقرأ */
class AssetLifeController extends Controller
{
    public function index()
    {
        abort_unless(hub_can(auth()->user(), 'assets', 'v'), 403, 'لا تملك عرض الأصول والعهد');

        return view('assets_life', [
            'sum'     => AssetLife::summary(),
            'flags'   => AssetLife::flags(),
            'replace' => AssetLife::replace(),
            'custody' => AssetLife::custody(),
        ]);
    }
}
