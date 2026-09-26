<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Workforce\TeamDirectory;
use Illuminate\Http\Request;

/** دليل الفريق — وجوهٌ لا صفوف */
class TeamController extends Controller
{
    public function index(Request $r)
    {
        /*
         * البابُ لوجهَي الدليل (الجولة 1 · F4): `hr:v` يفتح الكاملَ كما كان،
         * والزميلُ الداخليُّ النشطُ (ملفُّ موظفٍ مربوطٌ بحسابه) يفتح الأدنى —
         * فمعرفةُ «من في قسمي ومن مديري» حاجةُ كلِّ موظفٍ لا أداةُ HR وحدَها.
         * من لا ملفَّ له ولا `hr:v` (وحسابُ العميل) يبقى مردوداً 403 كما كان.
         */
        abort_unless(TeamDirectory::mode(auth()->user()) !== null, 403,
            'دليل الفريق لموظفي المنشأة — يلزم ملفٌّ وظيفيٌّ مربوطٌ بحسابك أو صلاحيةُ ملفات الموظفين');

        return view('team', ['t' => TeamDirectory::all((bool) $r->query('fresh'))]);
    }
}
