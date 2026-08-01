<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Compliance;

/**
 * لوحة الامتثال — الالتزام وأثره لا جدولُ تواريخ.
 * والغائب فيها أهمّ من الحاضر: ما لا سجلَّ له لا يُفحَص ولا يُنبَّه عنه.
 */
class ComplianceController extends Controller
{
    public function index()
    {
        abort_unless(hub_can(auth()->user(), 'compliance', 'v'), 403, 'لا تملك عرض سجل الامتثال');

        return view('compliance', [
            'pulse'   => Compliance::pulse(),
            'radar'   => Compliance::radar(),
            'missing' => Compliance::missing(),
            'gaps'    => Compliance::gaps(),
        ]);
    }
}
