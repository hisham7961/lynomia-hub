<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\SecurityPosture;
use App\Support\SecurityRadar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** مركز الأمان — وضعيةٌ حيّة، وجلساتٌ تُنهى فعلاً، وقفل طوارئ */
class SecurityController extends Controller
{
    /** بعد هذه المدة تُعدّ الجلسة منتهيةً لا نشطة */
    protected const LIVE_MIN = 30;

    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403, 'مركز الأمان للمالكين فقط');
    }

    public function index()
    {
        $this->gate();

        $users = DB::table('users')->whereNull('deleted_at');

        $kpi = [
            'users'   => (clone $users)->count(),
            'locked'  => (clone $users)->whereNotNull('locked_until')->where('locked_until', '>', now())->count(),
            'idle'    => (clone $users)->where('status', '!=', 'موقوف')
                            ->where(fn ($w) => $w->whereNull('last_login_at')->orWhere('last_login_at', '<', now()->subDays(60)))->count(),
            'twofa'   => (clone $users)->where('totp_enabled', 1)->count(),
            'failed7' => DB::table('audits')->where('action', 'دخول فاشل')->where('created_at', '>=', now()->subDays(7))->count(),
            'denied7' => Schema::hasTable('access_denials')
                ? DB::table('access_denials')->where('created_at', '>=', now()->subDays(7))->count() : 0,
            'stale'   => DB::table('vault_secrets')->whereNull('deleted_at')->where('updated_at', '<', now()->subDays(180))->count(),
            'live'    => DB::table('sessions_log')->where('revoked', false)
                            ->where('last_seen_at', '>=', now()->subMinutes(self::LIVE_MIN))->count(),
        ];

        // الجلسات: النشطة أولاً ثم الأحدث — ولكلٍّ زرّ إنهاء
        $sessions = DB::table('sessions_log')
            ->leftJoin('users', 'users.id', '=', 'sessions_log.user_id')
            ->orderByDesc('sessions_log.last_seen_at')->limit(25)
            ->get(['sessions_log.id', 'sessions_log.user_id', 'users.name as uname', 'sessions_log.ip',
                   'sessions_log.device', 'sessions_log.started_at', 'sessions_log.last_seen_at', 'sessions_log.revoked'])
            ->map(function ($s) {
                $s->live = ! $s->revoked && $s->last_seen_at
                    && \Illuminate\Support\Carbon::parse($s->last_seen_at)->gt(now()->subMinutes(self::LIVE_MIN));
                $s->mine = (string) session('hub.sl', '') === (string) $s->id;

                return $s;
            });

        $failed = DB::table('audits')->where('action', 'دخول فاشل')
            ->orderByDesc('created_at')->limit(10)->get(['name', 'ip', 'device', 'created_at']);

        // عناوين تطرق أكثر من حساب: تخمينٌ لا نسيان.
        // مجمَّعٌ في try: تجميعٌ بـHAVING على محرّكاتٍ مضبوطةٍ بصرامة قد يُرفض،
        // وبطاقةٌ ناقصة أهون من لوحة أمانٍ لا تُفتح.
        try {
            $knocking = DB::table('audits')->where('action', 'دخول فاشل')
                ->where('created_at', '>=', now()->subDays(7))->whereNotNull('ip')
                ->groupBy('ip')->havingRaw('COUNT(DISTINCT name) > 1')
                ->orderByRaw('COUNT(*) DESC')->limit(6)
                ->get([DB::raw('ip'), DB::raw('COUNT(*) as hits'), DB::raw('COUNT(DISTINCT name) as targets')]);
        } catch (\Throwable $e) {
            \App\Support\ErrorLog::capture('php', 'security: تعذّر تجميع العناوين الطارقة — ' . $e->getMessage(),
                $e->getFile(), $e->getLine());
            $knocking = collect();
        }

        $idleUsers = (clone $users)->where('status', '!=', 'موقوف')
            ->where(fn ($w) => $w->whereNull('last_login_at')->orWhere('last_login_at', '<', now()->subDays(60)))
            ->orderBy('last_login_at')->limit(10)->get(['id', 'name', 'email', 'last_login_at', 'totp_enabled']);

        $staleSecrets = DB::table('vault_secrets')->whereNull('deleted_at')
            ->where('updated_at', '<', now()->subDays(180))
            ->orderBy('updated_at')->limit(10)->get(['id', 'title', 'type', 'updated_at']);

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

        $exports = DB::table('audits')->where('action', 'تصدير')
            ->leftJoin('users', 'users.id', '=', 'audits.user_id')
            ->orderByDesc('audits.created_at')->limit(8)
            ->get(['users.name as uname', 'audits.module', 'audits.name', 'audits.ip', 'audits.created_at']);

        // الفحوص تُحسب مرةً واحدة: كانت تُنفَّذ مرتين (اللوحة ثم الملخّص) —
        // اثنان وثلاثون استعلاماً حيث يكفي ستة عشر
        $posture = SecurityPosture::checks();

        return view('security.index', [
            'kpi' => $kpi, 'sessions' => $sessions, 'failed' => $failed, 'knocking' => $knocking,
            'idleUsers' => $idleUsers, 'staleSecrets' => $staleSecrets,
            'roles' => $roles, 'exports' => $exports,
            'posture' => $posture, 'summary' => SecurityPosture::summary($posture),
            'lockdown' => (bool) setting('security.lockdown', false),
            // رادارُ الكشف الحيّ: محاولاتُ الوصول المرفوضة وتخمينُ الروابط + العناوين الطارقة
            'radar' => SecurityRadar::summary(), 'denials' => SecurityRadar::recent(),
            'threats' => SecurityRadar::threats(),
        ]);
    }

    /** إنهاء جلسةٍ بعينها — يسري عند أول طلبٍ لصاحبها، ويُبطل كعكة «تذكّرني» */
    public function revokeSession(string $id)
    {
        $this->gate();

        $s = DB::table('sessions_log')->where('id', $id)->first(['id', 'user_id', 'ip']);
        abort_unless($s, 404);

        DB::table('sessions_log')->where('id', $id)->update(['revoked' => true]);
        $this->cycleRemember($s->user_id);

        $name = DB::table('users')->where('id', $s->user_id)->value('name');
        hub_audit('إنهاء جلسة', null, null, $name . ' — ' . ($s->ip ?: 'بلا عنوان'));

        return back()->with('ok', '🔌 أُنهيت الجلسة — يخرج الجهاز عند أول طلب، ورمز «تذكّرني» دُوِّر فلا يُبعث منه');
    }

    /** إنهاء كل جلسات مستخدم — للجهاز المفقود وللمغادر */
    public function revokeUser(string $userId)
    {
        $this->gate();

        $n = DB::table('sessions_log')->where('user_id', $userId)->where('revoked', false)->update(['revoked' => true]);
        $this->cycleRemember($userId);

        $name = DB::table('users')->where('id', $userId)->value('name');
        hub_audit('إنهاء جلسات مستخدم', null, null, $name . " — {$n} جلسة");

        return back()->with('ok', "🔌 أُنهيت {$n} جلسة لـ«{$name}» على كل الأجهزة");
    }

    /**
     * تدوير رمز «تذكّرني»: بغيره تُبعث الجلسة المُنهاة من كعكةٍ عمرها ٤٠٠ يوم
     * عند أول طلب — أي أن «الإنهاء» يكون وسماً في جدولٍ لا إخراجاً.
     */
    protected function cycleRemember(string $userId): void
    {
        DB::table('users')->where('id', $userId)->update(['remember_token' => Str::random(60)]);
    }

    /** قفل الطوارئ: تعليق كل الوصول عدا المالكين — ويُسجَّل في التدقيق */
    public function lockdown()
    {
        $this->gate();
        $on = ! setting('security.lockdown', false);

        if ($on) Setting::updateOrCreate(['key' => 'security.lockdown'], ['value' => '1']);
        else Setting::where('key', 'security.lockdown')->delete();
        Cache::forget('settings:all');

        hub_audit($on ? 'تفعيل قفل الطوارئ' : 'رفع قفل الطوارئ', null, null, auth()->user()->name);

        return back()->with('ok', $on ? '🔒 فُعّل قفل الطوارئ — الجلسات غير المالكة عُلّقت فوراً' : '🔓 رُفع قفل الطوارئ');
    }
}
