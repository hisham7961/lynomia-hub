<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\TeamDirectory;
use Illuminate\Http\Request;

/** دليل الفريق — وجوهٌ لا صفوف */
class TeamController extends Controller
{
    public function index(Request $r)
    {
        abort_unless(hub_can(auth()->user(), 'hr', 'v'), 403);

        return view('team', ['t' => TeamDirectory::all((bool) $r->query('fresh'))]);
    }
}
