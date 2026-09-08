<?php

namespace App\Support;

use App\Models\HubNotification;
use App\Models\PushDelivery;
use App\Models\PushToken;
use App\Models\User;
use App\Support\Push\FcmPushProvider;
use App\Support\Push\NullPushProvider;
use App\Support\Push\PushProvider;
use Illuminate\Support\Facades\DB;

/**
 * **خدمةُ الدفع** — تجريدُ المزوّد + تفريعُ الإشعار الداخليّ إلى دفعِ الجوال
 * (Mobile Readiness · الطور E · E.5/E.6 · spec §Push).
 *
 * **المزوّد (`provider`):** `NullPushProvider` افتراضاً (بلا اعتماداتٍ ⇒
 * `not_configured`، **لا نجاحٌ مُزيَّف**)، أو `FcmPushProvider` حين يقول السائقُ
 * والاعتماداتُ (setting/config) إنّه FCM. الاختبارُ يحقن مزوّداً عبر الحاوية
 * (`app()->instance(PushProvider::class, …)`) فلا حالةٌ ساكنةٌ تتسرّب بين الاختبارات.
 *
 * **التفريعُ (`fanout`):** لكلِّ رمزِ دفعٍ حيٍّ لصاحبِ الإشعار يبني حمولةً **آمنةً
 * بالبناء** (عنوانٌ عامٌّ حسب النوع + تصنيفٌ + رابطٌ عميق `{module,id,action}` +
 * عددُ غير المقروء — **لا نصَّ الإشعارِ الحسّاس**)، يحاول التسليم، ويسجّل صفَّ
 * `push_deliveries` لكلِّ محاولة. **استثناءُ المزوّدِ مُلتقَط** — الإشعارُ الداخليُّ
 * الملتزَمُ سلفاً لا يُفقَد (spec §Push · Critic F6).
 *
 * **الوصلُ (`scheduleFanout`):** يُستدعى من hook «created» على `HubNotification`
 * عبر `DB::afterCommit` — **بعد الالتزام لا سطريّاً**: استثناءٌ سطريٌّ في «created»
 * داخلَ معاملةٍ (اعتمادٌ/استيعابُ مقاييس/مسار) يُرجِع الإشعارَ. والأنواعُ المكتومة
 * لا تبلغ «created» أصلاً (hook الكتمِ يُلغي «creating») فلا تُدفَع (Critic F6).
 *
 * **التسجيلُ (`register`) + إزالةُ التكرار (Critic F7):** رمزٌ واحدٌ لمالكٍ واحد.
 * تسجيلُ رمزٍ كان لمالكٍ آخر يُبطِل ربطَه القديم (أيَّ مزوّد) ويعيد التوجيهَ للحاليّ —
 * وإلّا استمرّ الأوّلُ يتلقّى إشعاراتِ الثاني (تسريبُ دفعٍ عابرٌ للمستخدمين).
 */
class PushService
{
    /** نصُّ جسمٍ عامٌّ — لا يحمل تفصيلاً حسّاساً (العنوانُ يحمل التصنيف) */
    public const GENERIC_BODY = 'افتح التطبيق للاطّلاع على التفاصيل';

    /**
     * **خريطةُ العناوين العامّة حسب النوع** — عنوانٌ قصيرٌ آمنٌ لكلِّ نوعِ إشعار،
     * **لا يُردَّد فيه نصُّ الإشعارِ الخام** (قد يحمل سرّاً/رقماً ماليّاً/جسمَ رسالة).
     * الأنواعُ ذاتُ اللاحقة (`rule:<uuid>`) تُختزَل إلى جذرها (`rule`) قبل البحث.
     */
    public const KIND_TITLES = [
        'assign'             => 'إسنادٌ جديد',
        'approval'           => 'طلبُ موافقة',
        'sign'               => 'توقيعٌ إلكترونيّ',
        'sec'                => 'تنبيهٌ أمنيّ',
        'dm'                 => 'رسالةٌ جديدة',
        'mention'            => 'أشار إليك أحدُهم',
        'reply'              => 'ردٌّ جديد',
        'react'              => 'تفاعلٌ جديد',
        'flow'               => 'تنبيهُ مسارِ عمل',
        'error'              => 'عطلٌ تقنيّ',
        'digest'             => 'موجزُك الدوريّ',
        'policy'             => 'سياسةٌ تتطلّب إقراراً',
        'account_activation' => 'تفعيلُ الحساب',
        'rule'               => 'تنبيه',
        'test'               => 'إشعارٌ تجريبيّ',
    ];

    /** العنوانُ الافتراضيُّ لنوعٍ غيرِ مُخرَّط — عامٌّ لا يُسرّب */
    public const KIND_TITLE_DEFAULT = 'إشعارٌ جديد';

    // ════════════════════════════ المزوّد ════════════════════════════

    /**
     * المزوّدُ الحاليّ — حاوية (اختبار) ثم سائقٌ من الإعدادات، وإلّا الصفريّ.
     * الصفريُّ يقول `not_configured` صدقاً — لا يُزيّف نجاحاً أبداً.
     */
    public static function provider(): PushProvider
    {
        if (app()->bound(PushProvider::class)) {
            return app(PushProvider::class);        // مزوّدٌ محقونٌ (اختبار/إدارة)
        }

        $driver = strtolower(trim((string) setting('mobile.push_driver', config('hub.mobile.push.driver', ''))));
        if ($driver === 'fcm') {
            $fcm = new FcmPushProvider(self::fcmCreds());
            if ($fcm->isConfigured()) return $fcm;   // النداءُ الحقيقيُّ محجوبٌ خلف الاعتماد
        }

        return new NullPushProvider();
    }

    /**
     * حالةُ الدفع للإدارة (E.7) — **صادقةٌ، بلا سرّ**: السائقُ وهل هو مُهيّأ.
     * لا مفتاحَ خاصّ ولا رمزَ وصولٍ في الردّ أبداً (spec §Push «never show private key»).
     */
    public static function status(): array
    {
        $p = self::provider();
        $driver = strtolower(trim((string) setting('mobile.push_driver', config('hub.mobile.push.driver', ''))));

        $creds = self::fcmCreds();

        return [
            'driver'           => $p->name(),               // null|fcm
            'configured'       => $p->isConfigured(),        // صدقُ NOT_CONFIGURED
            'requested'        => $driver !== '' ? $driver : null,   // ما طُلب في الإعدادات
            'has_project_id'   => trim((string) $creds['project_id']) !== '',     // حضورٌ لا قيمة
            'has_access_token' => trim((string) $creds['access_token']) !== '',   // حضورٌ لا قيمة — لا يُعرَض المفتاحُ قط
        ];
    }

    /** اعتماداتُ FCM من الإعدادات — **إعدادٌ خارجيٌّ لا يُختلَق** (لا تُسجَّل قط) */
    private static function fcmCreds(): array
    {
        return [
            'project_id'   => (string) setting('mobile.push_fcm_project_id', config('hub.mobile.push.fcm.project_id', '')),
            'access_token' => (string) setting('mobile.push_fcm_access_token', ''),
        ];
    }

    // ════════════════════════════ التفريع ════════════════════════════

    /**
     * جدولةُ التفريعِ بعد الالتزام (Critic F6) — يستدعيها hook «created». مؤجَّلٌ
     * عبر `DB::afterCommit`: يشغّله بعد التزامِ المعاملةِ المحيطة، **وفوراً** إن لم
     * تكن ثمّة معاملة (سلوكُ Laravel الافتراضيّ). ملتقَطٌ كي لا يُفقَد الإشعارُ الملتزَم.
     */
    public static function scheduleFanout(HubNotification $n): void
    {
        DB::afterCommit(function () use ($n) {
            try {
                self::fanout($n);
            } catch (\Throwable $e) {
                report($e);   // الإشعارُ الداخليُّ نجا — الدفعُ إثراءٌ لا شرط
            }
        });
    }

    /**
     * **تفريعُ إشعارٍ إلى دفعِ الجوال** — لكلِّ رمزٍ حيٍّ لصاحبِه: يبني حمولةً آمنة،
     * يحاول التسليم، يسجّل `push_deliveries`. المزوّدُ الصفريُّ ⇒ `not_configured`
     * (لا تزييف). استثناءُ المزوّدِ مُلتقَطٌ ⇒ `failed` — والإشعارُ الداخليُّ ينجو.
     *
     * لا صفوفَ تسليمٍ حين لا رموز (لا محاولةَ = لا سجلّ). عامٌّ كي يُستدعى مباشرةً
     * في الاختبار (afterCommit لا يُطلق تحت RefreshDatabase، فيُختبَر المنطقُ مباشرةً).
     */
    public static function fanout(HubNotification $n): void
    {
        if (! $n->user_id || ! self::tablesReady()) return;

        $tokens = PushToken::active()->where('user_id', $n->user_id)
            ->orderBy('created_at')->orderBy('id')->get();
        if ($tokens->isEmpty()) return;   // لا محاولةَ ⇒ لا سجلّ

        $provider = self::provider();
        $payload  = self::payloadFor($n);   // حمولةٌ آمنةٌ (تُبنى مرّةً — العدّادُ واحدٌ للكلّ)

        foreach ($tokens as $tok) {
            $row = [
                'notification_id' => (string) $n->id,
                'installation_id' => $tok->installation_id,
                'provider'        => $tok->provider !== null ? hub_fit((string) $tok->provider, 20) : null,
                'status'          => 'queued',
                'error_category'  => null,
                'attempts'        => 0,
                'queued_at'       => now(),
                'attempted_at'    => null,
            ];

            try {
                $res = $provider->send((string) $tok->token, (string) $tok->platform, $payload);
                $row['status']         = in_array($res->status, PushDelivery::STATUSES, true) ? $res->status : 'failed';
                $row['error_category'] = $res->errorCategory !== null ? hub_fit($res->errorCategory, 40) : null;
                $row['attempts']       = 1;
                $row['attempted_at']   = now();

                // رمزٌ لم يعد صالحاً لدى المزوّد ⇒ أبطِله كي لا نعاود إليه
                if ($res->errorCategory === PushDelivery::ERR_UNREGISTERED) {
                    $tok->forceFill(['revoked_at' => now(), 'updated_at' => now()])->save();
                }
            } catch (\Throwable $e) {
                // Critic F6: عطلُ المزوّدِ لا يُفقِد الإشعارَ الداخليّ (التزم سلفاً) —
                // يُسجَّل failed بصنفٍ تقنيّ (لا نصُّ الاستثناء) ونمضي للرمز التالي.
                $row['status']         = 'failed';
                $row['error_category'] = PushDelivery::ERR_EXCEPTION;
                $row['attempts']       = 1;
                $row['attempted_at']   = now();
                report($e);
            }

            try {
                PushDelivery::create($row);
            } catch (\Throwable $e) {
                report($e);   // سجلُّ التسليم إثراءٌ — تعذّرُ كتابته لا يكسر شيئاً
            }
        }
    }

    /**
     * **الحمولةُ الآمنةُ بالبناء** (spec §Push privacy): عنوانٌ عامٌّ حسب النوع +
     * جسمٌ عامّ + تصنيفٌ + وجهةٌ قانونيّة `{module,id,action}` + عددُ غير المقروء.
     * **لا نصَّ الإشعارِ الخام** (قد يحمل سرّاً/رقماً ماليّاً/جسمَ DM أو تعليق).
     */
    public static function payloadFor(HubNotification $n): array
    {
        $base  = self::baseKind((string) $n->kind);
        $title = self::KIND_TITLES[$base] ?? self::KIND_TITLE_DEFAULT;

        $data = [
            'notification_id' => (string) $n->id,
            'category'        => $base,                          // تصنيفٌ آليّ (لا نصّ)
            'unread'          => (string) self::unreadFor((string) $n->user_id),
        ];
        // الوجهةُ القانونيّة (لا رابطَ ويبٍ ولا اسمَ شاشة) — من المحلِّل الموحّد
        if ($target = NotificationLink::target($n)) {
            $data['module'] = $target['module'];
            $data['id']     = $target['id'];
            $data['action'] = $target['action'];
        }

        return [
            'title'    => $title,
            'body'     => self::GENERIC_BODY,
            'category' => $base,
            'data'     => $data,
        ];
    }

    // ════════════════════════════ التسجيلُ والإبطال ════════════════════════════

    /**
     * **تسجيلُ/تأكيدُ رمزِ دفعٍ** — إزالةُ التكرار (Critic F7): رمزٌ لمالكٍ واحد.
     *
     * (١) يُبطِل كلَّ ربطٍ حيٍّ لنفس الرمز (أيَّ مزوّد) يملكه غيرُ (المستخدم+التنصيب)
     *     الحاليّ — كي يتوقّف المالكُ السابقُ عن تلقّي إشعاراتِ الحاليّ (تسريبُ دفع).
     * (٢) ثم يعيد استعمالَ صفِّ `(provider, token)` إن وُجد (يعيد توجيهَه ويحييه)،
     *     أو ينشئ صفّاً جديداً. القيدُ الفريدُ `(provider, token)` يحسم التسابق.
     *
     * كلُّ نصٍّ يُقصّ بـ`hub_fit` قبل الكتابة (عرضُ MySQL الصارم). لا يُسجَّل الرمزُ قط.
     */
    public static function register(User $user, string $installationId, string $platform,
                                    ?string $provider, string $token): PushToken
    {
        $platform = (string) hub_fit($platform, 10);
        $provider = $provider !== null && $provider !== '' ? hub_fit($provider, 20) : null;
        $token    = (string) hub_fit($token, 512);

        // (١) F7 — أبطِل كلَّ ربطٍ حيٍّ لنفس الرمز يملكه غيرُ الحاليّ (أيَّ مزوّد)
        PushToken::whereNull('revoked_at')
            ->where('token', $token)
            ->where(fn ($w) => $w->where('user_id', '!=', $user->id)
                ->orWhere('installation_id', '!=', $installationId))
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        // (٢) upsert صفِّ (provider, token) للمالك الحاليّ
        $existing = PushToken::where('provider', $provider)->where('token', $token)->first();
        if ($existing) {
            $existing->forceFill([
                'user_id'           => $user->id,
                'installation_id'   => $installationId,
                'platform'          => $platform,
                'revoked_at'        => null,          // إحياءٌ + إعادةُ توجيه
                'last_confirmed_at' => now(),
                'updated_at'        => now(),
            ])->save();

            return $existing;
        }

        return PushToken::create([
            'installation_id'   => $installationId,
            'user_id'           => $user->id,
            'platform'          => $platform,
            'provider'          => $provider,
            'token'             => $token,
            'last_confirmed_at' => now(),
        ]);
    }

    /**
     * **إبطالُ رمزٍ** — مقصورٌ على رموزِ المستخدم الحاليّ (لا يُبطِل أحدٌ رمزَ غيره —
     * لا IDOR). يعيد عددَ المُبطَل. يُستدعى من `push/unregister` ومن الخروجِ الشامل.
     */
    public static function revoke(User $user, ?string $provider, string $token): int
    {
        $token = (string) hub_fit($token, 512);

        return PushToken::whereNull('revoked_at')
            ->where('user_id', $user->id)
            ->where('token', $token)
            ->when($provider !== null && $provider !== '',
                fn ($q) => $q->where('provider', hub_fit($provider, 20)))
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    // ════════════════════════════ داخليّ ════════════════════════════

    /** جذرُ النوع — `rule:<uuid>` ⇒ `rule` (بحثُ العنوانِ العامّ بالجذر) */
    private static function baseKind(string $kind): string
    {
        return strpos($kind, ':') !== false ? substr($kind, 0, strpos($kind, ':')) : $kind;
    }

    /** عددُ غير المقروء لصاحبِ الإشعار — هويّتُه وحدَها (لا نطاقَ سواه) */
    private static function unreadFor(string $userId): int
    {
        return (int) HubNotification::where('user_id', $userId)->where('read', false)->count();
    }

    /** هل جدولا الدفعِ حاضران؟ (سلامةُ ما قبل الهجرة — hub_has_col مخبّأ) */
    private static function tablesReady(): bool
    {
        return hub_has_col('push_tokens', 'token') && hub_has_col('push_deliveries', 'status');
    }
}
