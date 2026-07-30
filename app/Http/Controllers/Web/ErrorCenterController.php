<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ErrorEvent;
use App\Support\ErrorLog;
use Illuminate\Http\Request;

/** مركز الأخطاء والسجلات — تجميع وتتبع ومعالجة */
class ErrorCenterController extends Controller
{
    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403, 'مركز الأخطاء للمالكين فقط');
    }

    public function index(Request $r)
    {
        $this->gate();
        $q = ErrorEvent::orderByDesc('last_seen');
        if ($st = $r->query('st')) $q->where('status', $st);
        if ($k = $r->query('k')) $q->where('kind', $k);

        return view('ops.errors', [
            'rows' => $q->paginate(25)->withQueryString(),
            'st' => $r->query('st', ''), 'k' => $r->query('k', ''),
            'users' => \App\Models\User::pluck('name', 'id'),
        ]);
    }

    public function status(Request $r, string $id)
    {
        $this->gate();
        $r->validate(['to' => 'required|in:جديد,قيد المعالجة,محلول']);
        ErrorEvent::findOrFail($id)->forceFill(['status' => $r->input('to')])->save();

        return back()->with('ok', 'حُدّثت حالة الخطأ');
    }

    /** استقبال أخطاء المتصفح (sendBeacon) */
    public function jslog(Request $r)
    {
        $d = $r->validate([
            'message' => ['required', 'string', 'max:400'],
            'source'  => ['nullable', 'string', 'max:250'],
            'line'    => ['nullable', 'integer'],
        ]);
        ErrorLog::capture('js', $d['message'], $d['source'] ?? null, (int) ($d['line'] ?? 0));

        return response()->noContent();
    }
}
