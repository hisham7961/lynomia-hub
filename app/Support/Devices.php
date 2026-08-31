<?php

namespace App\Support;

use App\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * ثقة الأجهزة — هوية جهازٍ ثابتة عبر كوكي طويل العمر، وربطُها بالمستخدم.
 *
 * مبدأ التصميم: **إشارةٌ للخطر لا سلاحٌ للحجب**. جهازٌ جديد يرفع الخطر
 * ويطلب احتكاكاً أعلى (المرحلة ج)، ولا يمنع الدخول بذاته. وبلا بصمةٍ غازية:
 * الهوية كوكي عشوائيّ موقَّع لا Canvas/WebGL fingerprint.
 */
class Devices
{
    public const COOKIE = 'lyn_did';
    public const TTL_DAYS = 400;

    /** القيمة الخام للكوكي إن وُجدت، وإلا null (لا نُنشئ في القارئ) */
    public static function currentCookie(Request $r): ?string
    {
        $v = (string) $r->cookie(self::COOKIE, '');

        return $v !== '' ? $v : null;
    }

    /**
     * يضمن وجود كوكي جهازٍ ويُرجع صفَّ الجهاز للمستخدم، منشئاً «معلّقاً» عند
     * أول ظهور. يُستدعى عند إتمام الدخول. يُلحق الكوكي على الاستجابة عبر
     * `Cookie::queue` فيصل المتصفح دون تغيير المتحكّمات.
     */
    public static function bindOnLogin($user, Request $r): ?UserDevice
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('user_devices')) return null;

        $raw = self::currentCookie($r);
        if ($raw === null) {
            $raw = Str::random(48);
            Cookie::queue(Cookie::make(self::COOKIE, $raw, self::TTL_DAYS * 24 * 60,
                null, null, true, true, false, 'Lax'));
        }
        $hash = hash('sha256', $raw);

        $dev = UserDevice::withTrashed()
            ->where('user_id', $user->id)->where('cookie_hash', $hash)->first();

        [$label, $platform] = self::describe((string) $r->userAgent());

        if ($dev) {
            $dev->forceFill([
                'last_ip' => $r->ip(), 'last_seen_at' => now(), 'deleted_at' => null,
                // جهازٌ عاد بعد إبطالٍ يعود «معلّقاً» لا موثوقاً — يُراجَع من جديد
                'trust' => $dev->trust === 'مبطَل' ? 'معلّق' : $dev->trust,
            ])->save();

            return $dev;
        }

        return UserDevice::create([
            'user_id' => $user->id, 'cookie_hash' => $hash,
            'label' => $label, 'platform' => $platform, 'trust' => 'معلّق',
            'first_ip' => $r->ip(), 'last_ip' => $r->ip(),
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    /** هل هذا جهازٌ معروفٌ للمستخدم (رأيناه قبل الآن)؟ — إشارةٌ لحارس الدخول */
    public static function isKnown($user, Request $r): bool
    {
        $raw = self::currentCookie($r);
        if ($raw === null || ! \Illuminate\Support\Facades\Schema::hasTable('user_devices')) return false;

        return UserDevice::where('user_id', $user->id)
            ->where('cookie_hash', hash('sha256', $raw))->exists();
    }

    /** وسمٌ خفيفٌ يقرأه الإنسان من سلسلة المتصفح — لا بصمة */
    protected static function describe(string $ua): array
    {
        $ua = trim($ua);
        $os = 'نظام غير معروف';
        foreach (['Windows' => 'Windows', 'Mac OS' => 'macOS', 'Macintosh' => 'macOS', 'iPhone' => 'iOS',
            'iPad' => 'iPadOS', 'Android' => 'Android', 'Linux' => 'Linux'] as $needle => $name) {
            if (stripos($ua, $needle) !== false) { $os = $name; break; }
        }
        $browser = 'متصفح';
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox',
            'Safari' => 'Safari'] as $needle => $name) {
            if (stripos($ua, $needle) !== false) { $browser = $name; break; }
        }

        return [hub_fit($browser . ' · ' . $os, 200), $os];
    }
}
