<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\MediaCenter;
use Illuminate\Http\Request;

/** مركز الإعلام والفعاليات — الظهورُ أثرٌ لا أرشيف */
class MediaCenterController extends Controller
{
    public function index(Request $r)
    {
        $u = auth()->user();
        abort_unless(hub_can($u, 'media', 'v') || hub_can($u, 'events', 'v'), 403);

        return view('media_center', ['m' => MediaCenter::all((bool) $r->query('fresh'))]);
    }
}
