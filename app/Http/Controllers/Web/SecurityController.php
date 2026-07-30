<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** مركز الأمان — عين المالك على كل ما يمس أمن النظام، مع قفل الطوارئ */
class SecurityController extends Controller
{
    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403, 'مركز الأمان للمالكين فقط');
    }

    public function index()
    {
        $this->gate();

        $users = DB::table('users')->whereNull('deleted_at');

        // المؤشرات
        $kpi = [
            'users'   => (clone $users)->count(),
            'locked'  => (clone $users)->whereNotNull('locked_until')->where('locked_until', '>', now())->count(),
            'idle'    => (clone $users)->where('status', '!=', 'موقوف')
                            ->where(fn ($w) => $w->whereNull('last_login_at')->orWhere('last_login_at', '<', now()->subDays(60)))->count(),
            'twofa'   => (clone $users)->where('totp_enabled', 1)->count(),
            'failed7' => DB::table('audits')->where('action', 'دخول فاشل')->where('created_at', '>=', now()->subDays(7))->count(),
            'stale'   => DB::table('vault_secrets')->whereNull('deleted_at')->where('updated_at', '<', now()->subDays(180))->count(),
        ];

        // آخر الجلسات (دخول ناجح)
        $sessions = DB::table('sessions_log')
            ->leftJoin('users', 'users.id', '=', 'sessions_log.user_id')
            ->orderByDesc('sessions_log.started_at')->limit(12)
            ->get(['users.name as uname', 'sessions_log.ip', 'sessions_log.device', 'sessions_log.started_at']);

        // آخر المحاولات الفاشلة
        $failed = DB::table('audits')->where('action', 'دخول فاشل')
            ->orderByDesc('created_at')->limit(10)->get(['name', 'ip', 'device', 'created_at']);

        // الخاملون
        $idleUsers = (clone $users)->where('status', '!=', 'موقوف')
            ->where(fn ($w) => $w->whereNull('last_login_at')->orWhere('last_login_at', '<', now()->subDays(60)))
            ->orderBy('last_login_at')->limit(10)->get(['id', 'name', 'email', 'last_login_at', 'totp_enabled']);

        // أسرار لم تُغيَّر منذ ٦ أشهر
        $staleSecrets = DB::table('vault_secrets')->whereNull('deleted_at')
            ->where('updated_at', '<', now()->subDays(180))
            ->orderBy('updated_at')->limit(10)->get(['id', 'title', 'type', 'updated_at']);

        // مراجعة الأدوار والصلاحيات
        $roles = DB::table('roles')->get()->map(function ($r) {
            $matrix = json_decode($r->matrix ?? '[]', true) ?: [];
            $flags  = array_keys(array_filter(json_decode($r->flags ?? '[]', true) ?: []));
            return (object) [
                'name' => $r->name, 'is_owner' => (bool) $r->is_owner, 'scope' => $r->scope,
                'mods' => count(array_filter($matrix, fn ($m) => array_filter((array) $m))),
                'flags' => $flags,
                'users' => DB::table('users')->whereNull('deleted_at')->where('role_id', $r->id)->count(),
            ];
        });

        // سجل التصدير
        $exports = DB::table('audits')->where('action', 'تصدير')
            ->leftJoin('users', 'users.id', '=', 'audits.user_id')
            ->orderByDesc('audits.created_at')->limit(8)
            ->get(['users.name as uname', 'audits.module', 'audits.name', 'audits.ip', 'audits.created_at']);

        return view('security.index', [
            'kpi' => $kpi, 'sessions' => $sessions, 'failed' => $failed,
            'idleUsers' => $idleUsers, 'staleSecrets' => $staleSecrets,
            'roles' => $roles, 'exports' => $exports,
            'lockdown' => (bool) setting('security.lockdown', false),
        ]);
    }

    /** قفل الطوارئ: تعليق كل الوصول عدا المالكين — ويُسجَّل في التدقيق */
    public function lockdown()
    {
        $this->gate();
        $on = ! setting('security.lockdown', false);

        if ($on) Setting::updateOrCreate(['key' => 'security.lockdown'], ['value' => '1']);
        else Setting::where('key', 'security.lockdown')->delete();
        Cache::forget('settings:all');

        \App\Models\AuditEntry::create([
            'user_id' => auth()->id(),
            'action'  => $on ? 'تفعيل قفل الطوارئ' : 'رفع قفل الطوارئ',
            'name'    => auth()->user()->name,
            'ip'      => request()->ip(),
            'device'  => substr((string) request()->userAgent(), 0, 200),
            'created_at' => now(),
        ]);

        return back()->with('ok', $on ? '🔒 فُعّل قفل الطوارئ — الجلسات غير المالكة عُلّقت فوراً' : '🔓 رُفع قفل الطوارئ');
    }
}
