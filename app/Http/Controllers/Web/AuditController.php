<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditController extends Controller
{
    public function index(Request $r)
    {
        abort_unless(hub_flag(auth()->user(), 'audit'), 403);

        $rows = DB::table('audits')
            ->leftJoin('users', 'users.id', '=', 'audits.user_id')
            ->select('audits.*', 'users.name as user_name')
            ->when(hub_scoped(auth()->user()), function ($q) {
                $u = auth()->user();
                $q->where(fn ($w) => $w->where('audits.user_id', $u->id)
                                       ->orWhereIn('audits.project_id', $u->visibleProjectIds()));
            })
            ->when($r->input('module'), fn ($q, $m) => $q->where('audits.module', $m))
            ->when($r->input('q'), fn ($q, $t) => $q->where('audits.name', 'LIKE', "%$t%"))
            ->orderByDesc('audits.created_at')
            // بلا COUNT(*) إجمالي — أسرع الجداول نمواً والعدّ مع join يثقل كل صفحة
            ->simplePaginate(40)->withQueryString();

        return view('audit.index', compact('rows'));
    }
}
