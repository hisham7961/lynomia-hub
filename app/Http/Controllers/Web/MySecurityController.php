<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\UserDevice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * أمني ذاتي (v2.367): جلساتي وأجهزتي — لكلِّ مستخدمٍ على نفسه.
 *
 * كان إبطالُ الجلسات حكراً على المالك في مركز الأمن؛ فمن سُرقت جلستُه لم يكن
 * يملك طردَ المهاجم بيده. هنا يرى المستخدمُ جلساتِه وأجهزتَه الحيّة ويُبطلها —
 * على **صفوفه هو حصراً**، فلا وصولَ إلى جلسة أحدٍ سواه.
 */
class MySecurityController extends Controller
{
    public function index()
    {
        $u = auth()->user();
        $mine = (string) session('hub.sl', '');

        $sessions = DB::table('sessions_log')->where('user_id', $u->id)
            ->orderByDesc('last_seen_at')->limit(40)
            ->get(['id', 'device', 'device_id', 'ip', 'started_at', 'last_seen_at', 'revoked'])
            ->map(function ($s) use ($mine) {
                $s->live = ! $s->revoked && $s->last_seen_at && now()->diffInMinutes($s->last_seen_at) < 30;
                $s->mine = $s->id === $mine;

                return $s;
            });

        $devices = \Illuminate\Support\Facades\Schema::hasTable('user_devices')
            ? UserDevice::where('user_id', $u->id)->orderByDesc('last_seen_at')->get()
            : collect();

        // خطرُ جلستك الحالية — مفسَّراً بعوامله لا رقماً أعمى
        $risk = \App\Support\Risk::session($u);

        return view('security.mine', compact('sessions', 'devices', 'risk'));
    }

    public function revokeSession(string $id)
    {
        $u = auth()->user();
        $s = DB::table('sessions_log')->where('id', $id)->where('user_id', $u->id)->first(['id', 'ip']);
        abort_unless($s, 404);

        DB::table('sessions_log')->where('id', $id)->update(['revoked' => true]);
        $u->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        hub_audit('إنهاء جلستي', null, null, ($s->ip ?: 'بلا عنوان'));

        return back()->with('ok', '🔌 أُنهيت الجلسة — يخرج جهازُها عند أول طلب');
    }

    public function revokeOthers()
    {
        $u = auth()->user();
        $mine = (string) session('hub.sl', '');
        $n = DB::table('sessions_log')->where('user_id', $u->id)
            ->where('id', '!=', $mine)->where('revoked', false)->update(['revoked' => true]);
        $u->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        hub_audit('إنهاء جلساتي الأخرى', null, null, "{$n} جلسة");

        return back()->with('ok', "🔌 أُنهيت {$n} جلسة على أجهزتك الأخرى — جلستُك الحالية باقية");
    }

    public function trustDevice(string $id)
    {
        $dev = UserDevice::where('id', $id)->where('user_id', auth()->id())->firstOrFail();
        $dev->update(['trust' => 'موثوق']);
        hub_audit('توثيق جهازي', null, $dev->id, $dev->label);

        return back()->with('ok', '✅ وُثّق الجهاز');
    }

    public function revokeDevice(string $id)
    {
        $u = auth()->user();
        $dev = UserDevice::where('id', $id)->where('user_id', $u->id)->firstOrFail();
        // إبطالُ الجهاز يبطل جلساتِه دفعةً — الجلساتُ الموسومةُ به تُنهى
        DB::table('sessions_log')->where('user_id', $u->id)
            ->where('device_id', $dev->id)->where('revoked', false)->update(['revoked' => true]);
        $u->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        $dev->update(['trust' => 'مبطَل']);
        $dev->delete();
        hub_audit('إبطال جهازي', null, $dev->id, $dev->label);

        return back()->with('ok', '🔌 أُبطل الجهاز وأُنهيت جلساتُه — يعود «معلّقاً» إن سُجّل الدخول منه ثانيةً');
    }
}
