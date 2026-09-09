<?php

namespace App\Support;

use App\Models\EndpointDevice;
use App\Models\EndpointMdmConnection;
use App\Models\EndpointPolicy;
use App\Models\VaultSecret;
use App\Support\Mdm\IntuneMdmProvider;
use App\Support\Mdm\JamfMdmProvider;
use App\Support\Mdm\MdmActionResult;
use App\Support\Mdm\MdmProvider;
use App\Support\Mdm\NullMdmProvider;

/**
 * **خدمةُ MDM** — تجريدُ المزوّد + قرارُ الوضع الفعليّ الصادق لفرض USB
 * (مسارُ التصحيح §8/§9 — نظيرُ `PushService`).
 *
 * **المزوّد (`provider`):** حاوية (اختبار) ثم وصلةُ الشركة المُفعَّلة (Intune/Jamf
 * باعتماداتٍ من `VaultSecret`)، وإلّا `NullMdmProvider`. الصفريُّ **رصدٌ فقط، لا
 * حجبَ زائف**؛ والمزوّدان الحقيقيّان جسرُهما الحيُّ مؤجَّلٌ فيبقيان رصداً فقط أيضاً.
 *
 * **الوضعُ الفعليّ (`effectiveUsbMode`) — جوهرُ الصدق (C15):** حتى لو حملت السياسةُ
 * `enforce=true` (بإقرارِ MDM في النموذج)، يبقى الوضعُ الفعليُّ **observe-only** ما
 * لم يكن هناك مزوّدٌ **قادرٌ على الفرض فعلاً** (`canEnforce`). فالواجهةُ لا تعرض
 * «محجوب» أبداً بلا فرضٍ حقيقيّ — تعرض ما يجري فعلاً: رصدٌ وإبلاغ.
 */
class MdmService
{
    /** أوضاعُ الفرض الفعليّة الصادقة (لا «blocked» بينها — لا حجبَ يُزعَم) */
    public const MODE_NOT_CONFIGURED = 'not-configured';
    public const MODE_OBSERVE_ONLY = 'observe-only';
    public const MODE_ENFORCE = 'enforce';

    /** وسومُ العرض العربية للأوضاع الفعليّة */
    public const MODE_LABELS = [
        self::MODE_NOT_CONFIGURED => 'غيرُ مُهيّأ (لا فرض)',
        self::MODE_OBSERVE_ONLY => 'رصدٌ فقط (لا حجب)',
        self::MODE_ENFORCE => 'فرضٌ عبر MDM',
    ];

    // ════════════════════════════ المزوّد ════════════════════════════

    /**
     * المزوّدُ الحاليّ — حاوية (اختبار) ثم وصلةٌ مُفعَّلة، وإلّا الصفريّ.
     * الصفريُّ والحقيقيّان (جسرُهما مؤجَّل) كلُّهم لا يزعمون حجباً (C15).
     */
    public static function provider(?EndpointMdmConnection $conn = null): MdmProvider
    {
        if (app()->bound(MdmProvider::class)) {
            return app(MdmProvider::class);        // مزوّدٌ محقونٌ (اختبار)
        }

        if ($conn && $conn->enabled && in_array((string) $conn->provider, EndpointMdmConnection::PROVIDERS, true)) {
            $creds = self::creds($conn);

            return match ((string) $conn->provider) {
                'intune' => new IntuneMdmProvider($creds),
                'jamf' => new JamfMdmProvider($creds),
                default => new NullMdmProvider(),
            };
        }

        return new NullMdmProvider();
    }

    /**
     * اعتماداتُ الوصلة — المستأجرُ من العمود، والسرُّ **يُقرأ من الخزنة عند الحاجة
     * فقط** (لا يُخزَّن في صفّ الوصلة ولا يُسجَّل قط). حضورٌ لا قيمةً يُمرَّر للمزوّد.
     */
    private static function creds(EndpointMdmConnection $conn): array
    {
        $secret = '';
        if ($conn->secret_id) {
            $vs = VaultSecret::find($conn->secret_id);
            if ($vs) {
                try {
                    $secret = (string) $vs->secret_cipher;   // الكاستُ يفكّ التشفير — لا يُسجَّل
                } catch (\Throwable $e) {
                    $secret = '';
                }
            }
        }

        return ['tenant' => (string) $conn->external_tenant, 'secret' => $secret];
    }

    /** الوصلةُ المُفعَّلة لشركةٍ — الخاصّةُ أولاً، ثم العامّة (company_id=null) */
    public static function connectionFor(?string $companyId): ?EndpointMdmConnection
    {
        if (! hub_has_col('endpoint_mdm_connections', 'provider')) {
            return null;   // سلامةُ ما قبل الهجرة
        }

        return EndpointMdmConnection::where('enabled', true)
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
            ->orderByRaw('CASE WHEN company_id IS NULL THEN 1 ELSE 0 END')   // الخاصّةُ تسبق العامّة
            ->orderByDesc('created_at')->orderByDesc('id')
            ->first();
    }

    // ════════════════════════════ الوضعُ الفعليّ ════════════════════════════

    /**
     * **الوضعُ الفعليُّ الصادقُ لفرض USB على جهازٍ بسياسةٍ** — لا يزعم «فرضاً» بلا
     * مزوّدٍ قادرٍ فعلاً، ولو طلبت السياسةُ ذلك:
     *  • `enforce`        — مزوّدٌ قادرٌ (canEnforce) **وسياسةٌ enforce=true**.
     *  • `not-configured` — وصلةٌ مطلوبةٌ لكنّ اعتماداتِها ناقصة.
     *  • `observe-only`   — الافتراضُ الصادق: يُرصَد ويُبلَّغ، لا يُحجَب (لا تكامل، أو
     *    تكاملٌ جسرُه الحيُّ مؤجَّل، أو سياسةٌ لا تطلب الفرض).
     */
    public static function effectiveUsbMode(?EndpointPolicy $policy, ?EndpointMdmConnection $conn): string
    {
        $p = self::provider($conn);

        if ($p->canEnforce() && $policy && (bool) $policy->enforce) {
            return self::MODE_ENFORCE;
        }
        if ($conn && ! $p->isConfigured()) {
            return self::MODE_NOT_CONFIGURED;
        }

        return self::MODE_OBSERVE_ONLY;
    }

    /**
     * حالةُ التكامل للإدارة — **صادقةٌ، بلا سرّ**: المزوّدُ، أهو مُهيّأ، أيقدر على
     * الفرض، والمستأجر (ليس سرّاً). لا سرَّ خزنةٍ في الردّ أبداً.
     */
    public static function status(?EndpointMdmConnection $conn = null): array
    {
        $p = self::provider($conn);

        return [
            'driver' => $p->name(),                    // null|intune|jamf
            'configured' => $p->isConfigured(),        // صدقُ NOT_CONFIGURED
            'can_enforce' => $p->canEnforce(),         // كلُّها false — الجسرُ مؤجَّل
            'enabled' => (bool) ($conn->enabled ?? false),
            'tenant' => $conn && filled($conn->external_tenant) ? (string) $conn->external_tenant : null, // ليس سرّاً
            'has_secret' => (bool) ($conn->secret_id ?? false),   // حضورٌ لا قيمة — لا يُعرَض السرّ
        ];
    }

    // ════════════════════════════ الفعلُ والصحّةُ والمزامنة ════════════════════════════

    /**
     * محاولةُ تسليمِ نيّةِ سياسةِ USB لطبقة MDM — عبر مزوّد الشركة. **لا يعيد قطّ
     * «حُجب»** (أقصاها synced). استثناءُ المزوّدِ ملتقَطٌ ⇒ failed. يُدقَّق الفعل.
     */
    public static function applyUsbPolicy(EndpointDevice $device, ?EndpointPolicy $policy): MdmActionResult
    {
        $conn = self::connectionFor($device->company_id ? (string) $device->company_id : null);
        $provider = self::provider($conn);
        $usbMode = $policy?->usb_mode ?? 'audit';

        try {
            $res = $provider->applyUsbPolicy($device, (string) $usbMode);
        } catch (\Throwable $e) {
            report($e);
            $res = MdmActionResult::failed('عطلٌ تقنيٌّ في طبقة MDM');
        }

        if (function_exists('hub_audit')) {
            hub_audit('محاولةُ فرضِ سياسةِ USB عبر MDM', 'endpoints', (string) $device->id,
                mb_substr((string) $device->hostname, 0, 80) . ' — ' . $provider->name() . '/' . $res->status);
        }

        return $res;
    }

    /**
     * فحصُ صحّةِ الوصلة — صادقٌ بلا سرّ، ويختم `last_health_*` حين تُمرَّر وصلة.
     */
    public static function health(?EndpointMdmConnection $conn = null): array
    {
        $h = self::provider($conn)->health();

        if ($conn && $conn->exists) {
            $conn->forceFill([
                'last_health_at' => now(),
                'last_health_status' => mb_substr((string) ($h['status'] ?? 'error'), 0, 20),
            ])->save();
        }

        return $h;
    }

    /**
     * مزامنةُ خريطةِ سياسات USB إلى طبقة MDM — يختم `last_sync_*` بصدق. بلا مزوّدٍ
     * قادرٍ لا مزامنةَ حيّة: الحالةُ observe-only/not-configured لا «synced» زائفة.
     */
    public static function sync(EndpointMdmConnection $conn): MdmActionResult
    {
        $p = self::provider($conn);
        $status = ! $p->isConfigured()
            ? MdmActionResult::notConfigured('اعتماداتُ المزوّد ناقصة — لا مزامنة')
            : ($p->canEnforce()
                ? MdmActionResult::synced('سُلِّمت خريطةُ السياسات لطبقة MDM')
                : MdmActionResult::observeOnly('التكاملُ مُهيّأٌ لكنّ جسرَ الفرض الحيَّ مؤجَّل — لا مزامنةَ فرضٍ حيّة'));

        $conn->forceFill([
            'last_sync_at' => now(),
            'last_sync_status' => mb_substr($status->status, 0, 20),
        ])->save();

        return $status;
    }
}
