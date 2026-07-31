<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Uptime;

/** فحصٌ حيّ عند الطلب — «افحص الآن» بدل انتظار الدورة المجدولة */
class MonitorController extends Controller
{
    public function check(string $module, string $id)
    {
        abort_unless(isset(Uptime::TARGETS[$module]), 404, 'هذه الوحدة غير قابلة للمراقبة');
        abort_unless(hub_can(auth()->user(), $module, 'e'), 403, 'الفحص الحيّ يتطلب صلاحية تعديل الوحدة');

        $def = hub_mod($module);
        $class = '\\App\\Models\\' . $def['model'];
        $row = hub_scope($class::query(), $module)->findOrFail($id);

        $r = Uptime::check($module, $row, 'manual');
        hub_audit('فحص حيّ', $module, $row->id, $r['up'] === null ? ($r['error'] ?? '—')
            : (($r['up'] ? 'يعمل' : 'معطّل') . ' · ' . $r['ms'] . 'ms'));

        return back()->with($r['up'] ? 'ok' : 'err', match (true) {
            $r['up'] === null => 'تعذّر الفحص: ' . $r['error'],
            $r['up'] => 'الهدف يعمل — رمز ' . $r['code'] . ' في ' . $r['ms'] . ' مللي ثانية',
            default => 'الهدف لا يستجيب: ' . ($r['error'] ?: 'رمز ' . $r['code']),
        });
    }
}
