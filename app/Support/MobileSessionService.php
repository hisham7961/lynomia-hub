<?php

namespace App\Support;

use App\Models\MobileInstallation;
use App\Models\MobileSession;
use App\Models\MobileStepupGrant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * **خدمةُ جلسات الجوال** — سكُّ الرمزَين وتدويرُهما وإبطالُهما — Mobile
 * Readiness · الطور B · SF-1.
 *
 * قاعدةٌ واحدة تحكم كلَّ ما هنا: الرمزان (الوصولُ والتحديث) يُخزَّنان **تجزئةَ
 * sha256 حصراً** (نمطُ `ApiToken.token_hash` — INVENTORY §3a). النصُّ الصريحُ
 * يُعاد للمُنادي مرّةً في زوجٍ طازج، ولا يُخزَّن ولا يُسجَّل ولا يُدقَّق أبداً.
 * ومُدَدُ الصلاحية من الإعدادات: الوصولُ `mobile.access_ttl_min` (١٥ دقيقة
 * افتراضاً)، والتحديثُ `mobile.refresh_ttl_days` (٣٠ يوماً افتراضاً).
 *
 * **آلةُ التدوير لمرّةٍ واحدة + كشفُ الإعادة (single-use + reuse defense):**
 * كلُّ تدويرٍ ينشئ صفّاً جديداً في **العائلة نفسِها**، يحمل `prev_refresh_hash`
 * = تجزئةَ رمزِ التحديث المُستهلَك، ويُبطِل الصفَّ القديم. فإعادةُ استعمالِ رمزِ
 * تحديثٍ مُدوَّرٍ (تُطابِق صفّاً مُبطَلاً بالتجزئة نفسِها، أو تُطابِق
 * `prev_refresh_hash`) = **إشارةُ هجوم** → تُبطَل العائلةُ كلُّها ويُعاد وسمُ
 * `reuse` كي يرصده المُنادي (SecurityRadar + تنبيه، الطور B.4).
 *
 * **حتميّةٌ لا قرعة (C13):** البحثُ بـ`refresh_hash` (فريدٌ ⇒ صفٌّ واحدٌ على
 * الأكثر)، وكلُّ استعلامٍ قد يتساوى فيه صفّان يحمل `->orderBy('id')`.
 *
 * كلُّ الطرائق ساكنة (نمطُ `Totp`/`StepUp`/`SecurityRadar`).
 */
class MobileSessionService
{
    /** مهلةُ رمز الوصول من الإعدادات (دقائق · افتراضاً ١٥) */
    public static function accessTtlAt(): Carbon
    {
        return now()->addMinutes(max(1, (int) setting('mobile.access_ttl_min', 15)));
    }

    /** مهلةُ رمز التحديث من الإعدادات (أيام · افتراضاً ٣٠) */
    public static function refreshTtlAt(): Carbon
    {
        return now()->addDays(max(1, (int) setting('mobile.refresh_ttl_days', 30)));
    }

    /** رمزُ وصولٍ صريحٌ جديد — يُعاد مرّةً ثم يُخزَّن تجزئةً فقط (نمطُ `lyn_` في ApiToken) */
    protected static function newAccessPlain(): string
    {
        return 'lyma_' . Str::random(48);
    }

    /** رمزُ تحديثٍ صريحٌ جديد — يُعاد مرّةً ثم يُخزَّن تجزئةً فقط */
    protected static function newRefreshPlain(): string
    {
        return 'lymr_' . Str::random(48);
    }

    /**
     * **سكُّ جلسةٍ جديدة** (عائلةٌ جديدة لكل تسجيل دخول).
     *
     * @return array{0:MobileSession,1:string,2:string} [الجلسة, رمزُ الوصول الصريح, رمزُ التحديث الصريح]
     */
    public static function mint(User $user, MobileInstallation $installation,
                               ?string $ip = null, ?string $appVersion = null, ?string $platform = null): array
    {
        $access  = self::newAccessPlain();
        $refresh = self::newRefreshPlain();

        $session = MobileSession::create([
            'user_id'            => $user->id,
            'installation_id'    => $installation->id,
            'family_id'          => (string) Str::uuid(),   // عائلةٌ جديدة لكل تسجيل دخول
            'access_hash'        => hash('sha256', $access),
            'refresh_hash'       => hash('sha256', $refresh),
            'prev_refresh_hash'  => null,
            'access_expires_at'  => self::accessTtlAt(),
            'refresh_expires_at' => self::refreshTtlAt(),
            'last_used_at'       => now(),
            'last_ip'            => hub_fit($ip, 60),
            'app_version'        => hub_fit($appVersion, 40),
            'platform'           => hub_fit($platform, 10),
        ]);

        return [$session, $access, $refresh];
    }

    /**
     * **تدويرُ رمزِ التحديث** — لمرّةٍ واحدة، مع دفاعٍ عن الإعادة.
     *
     * النتائجُ الثلاث (المُنادي يترجمها لأكواد Api):
     *  • `['result'=>'rotated','session'=>MobileSession,'access'=>string,'refresh'=>string]`
     *    — زوجٌ جديدٌ في العائلة نفسِها؛ الصفُّ القديمُ أُبطِل.
     *  • `['result'=>'reuse','family_id'=>string]` — رمزٌ مُدوَّرٌ أُعيد استعمالُه:
     *    **العائلةُ أُبطِلت هنا**؛ على المُنادي رصدُه (SecurityRadar + REFRESH_TOKEN_INVALID).
     *  • `['result'=>'invalid']` — رمزٌ لا يُعرَف أو انتهى (لا هجومَ ⇒ لا إبطالَ عائلة).
     *
     * @return array{result:string,session?:MobileSession,access?:string,refresh?:string,family_id?:string}
     */
    public static function rotate(string $refreshPlain,
                                  ?string $ip = null, ?string $appVersion = null, ?string $platform = null): array
    {
        $h = hash('sha256', $refreshPlain);

        // البحثُ بـrefresh_hash فريدٌ ⇒ صفٌّ واحدٌ على الأكثر (orderBy دفاعيّ · C13)
        $row = MobileSession::where('refresh_hash', $h)->orderBy('id')->first();

        if (! $row) {
            // لم يُعرَف كـrefresh_hash حيّ — فهل هو رمزٌ مُدوَّرٌ صار prev لصفٍّ لاحق؟ ⇒ إعادةٌ
            $chained = MobileSession::where('prev_refresh_hash', $h)->orderBy('id')->first();
            if ($chained) {
                self::revokeFamily($chained->family_id, 'إعادةُ استخدامِ رمزِ تحديثٍ مُدوَّر');

                return ['result' => 'reuse', 'family_id' => $chained->family_id];
            }

            return ['result' => 'invalid'];
        }

        // صفٌّ بالتجزئة نفسِها لكنه مُبطَل ⇒ رمزٌ استُهلك بالتدوير ثم أُعيد ⇒ إعادةٌ
        if ($row->revoked_at) {
            self::revokeFamily($row->family_id, 'إعادةُ استخدامِ رمزِ تحديثٍ مُبطَل');

            return ['result' => 'reuse', 'family_id' => $row->family_id];
        }

        // رمزٌ حيٌّ لكنه انتهى ⇒ غيرُ صالحٍ (انتهاءٌ لا هجوم — فلا تُبطَل العائلة)
        if (! $row->refresh_expires_at || now()->gt($row->refresh_expires_at)) {
            return ['result' => 'invalid'];
        }

        // تدويرٌ صالح: زوجٌ جديدٌ في العائلة نفسِها، والقديمُ يُبطَل (وصولاً وتحديثاً)
        $access  = self::newAccessPlain();
        $refresh = self::newRefreshPlain();

        $next = MobileSession::create([
            'user_id'            => $row->user_id,
            'installation_id'    => $row->installation_id,
            'family_id'          => $row->family_id,           // العائلةُ نفسُها
            'access_hash'        => hash('sha256', $access),
            'refresh_hash'       => hash('sha256', $refresh),
            'prev_refresh_hash'  => $h,                        // رمزُ التحديث المُستهلَك (سلسلةُ التدوير)
            'access_expires_at'  => self::accessTtlAt(),
            'refresh_expires_at' => self::refreshTtlAt(),
            'last_used_at'       => now(),
            'last_ip'            => hub_fit($ip, 60),
            'app_version'        => hub_fit($appVersion ?? $row->app_version, 40),
            'platform'           => hub_fit($platform ?? $row->platform, 10),
        ]);

        $row->forceFill([
            'revoked_at'     => now(),
            'revoked_reason' => hub_fit('دُوِّرت — أُصدر رمزُ تحديثٍ جديد', 160),
        ])->save();

        return ['result' => 'rotated', 'session' => $next, 'access' => $access, 'refresh' => $refresh];
    }

    /** إبطالُ جلسةٍ واحدة (خروج / إلغاءٌ من «جلساتي») — ناعمٌ يُبقي الشاهد */
    public static function revokeSession(MobileSession $session, string $reason): void
    {
        if ($session->revoked_at) return;
        $session->forceFill([
            'revoked_at'     => now(),
            'revoked_reason' => hub_fit($reason, 160),
        ])->save();
    }

    /** إبطالُ كلِّ جلسات المستخدم (logout-all / حدثٌ أمنيّ) */
    public static function revokeAllForUser(User $user, string $reason): void
    {
        MobileSession::where('user_id', $user->id)->whereNull('revoked_at')
            ->update([
                'revoked_at'     => now(),
                'revoked_reason' => hub_fit($reason, 160),
                'updated_at'     => now(),   // التحديثُ الجماعيّ يتجاوز طوابعَ Eloquent
            ]);
    }

    /** إبطالُ عائلةٍ كاملة (كشفُ إعادةِ رمزِ التحديث) */
    public static function revokeFamily(string $familyId, string $reason): void
    {
        MobileSession::where('family_id', $familyId)->whereNull('revoked_at')
            ->update([
                'revoked_at'     => now(),
                'revoked_reason' => hub_fit($reason, 160),
                'updated_at'     => now(),   // التحديثُ الجماعيّ يتجاوز طوابعَ Eloquent
            ]);
    }

    /**
     * **هل لهذه الجلسةِ مِنحةُ تصعيدٍ ساريةٌ لهذا الغرض؟** — النقطةُ الواحدة التي
     * تستهلكها الأطوارُ اللاحقة (D/E/F) قبل أيّ فعلٍ حسّاس: تُعيد `STEP_UP_REQUIRED`
     * (٤٢٨) حين تُعيد هذه `false`. المِنحةُ مربوطةٌ بـ(الجلسة+الغرض) لا بـ`session()`
     * (Critic F11): غيرُ مُستهلَكةٍ ولم تنتهِ بعد. حتميّةٌ بـ`orderBy('id')` (C13).
     */
    public static function mobileStepUpFresh(MobileSession $session, string $purpose): bool
    {
        return MobileStepupGrant::where('mobile_session_id', $session->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->orderBy('id')
            ->exists();
    }
}
