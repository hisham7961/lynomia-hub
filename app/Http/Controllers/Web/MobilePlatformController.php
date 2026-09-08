<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\MobileSession;
use App\Models\PushToken;
use App\Support\MobilePlatform;
use App\Support\MobileSessionService;
use App\Support\PushService;
use Illuminate\Http\Request;

/**
 * **مركزُ منصّة تطبيق الهاتف** (Mobile Platform Center) — قنصليّةٌ إداريّةٌ واحدةٌ
 * فوق كلِّ قدرات الجوال القائمة: يفهم المسؤولُ ويضبط ويشغّل ويشخّص ويراقب من مكانٍ
 * واحد، دون أن يعرف أنّ الوظيفةَ مبعثرةٌ بين مسارٍ وإعدادٍ وجدولٍ وسجلّ.
 *
 * **لا خلفيّةَ جديدة:** كلُّ قراءةٍ تمرّ عبر `App\Support\MobilePlatform` التي تستدعي
 * الأنظمةَ القائمة (PushService/MobileOpenApi/النماذج/الإعدادات/Health). **الحرسُ في
 * المتحكّم** (لا في إخفاء التنقّل): مالكٌ أو حاملُ رايةِ «الجوال» (`mobile`) فقط —
 * الموظّفُ العاديُّ يُصَدّ ٤٠٣ ولو بلغ المسارَ مباشرةً.
 */
class MobilePlatformController extends Controller
{
    /** التبويباتُ المُنفَّذة (تنمو مع الأطوار) — key ⇒ label */
    private const TABS = [
        'overview'   => 'نظرة عامّة',
        'devices'    => 'المستخدمون والأجهزة',
        'push'       => 'الدفع',
        'operations' => 'التشغيل والصحّة',
    ];

    /** هل يرى المستخدمُ المركز؟ (مالكٌ أو رايةُ mobile) — المصدرُ الوحيد للحرس */
    public static function canView($user = null): bool
    {
        $u = $user ?? auth()->user();

        return $u !== null && (hub_is_owner($u) || hub_flag($u, 'mobile'));
    }

    protected function gate(): void
    {
        abort_unless(self::canView(), 403, 'مركزُ منصّة الجوال للمالك أو حاملِ رايةِ إدارةِ الجوال');
    }

    public function index(Request $r)
    {
        $this->gate();

        $tab = (string) hub_str($r->query('tab', 'overview'));
        if (! array_key_exists($tab, self::TABS)) $tab = 'overview';

        $data = ['tabs' => self::TABS, 'active' => $tab];
        $data += match ($tab) {
            'devices'    => $this->devicesData($r),
            'push'       => $this->pushData($r),
            'operations' => ['health' => MobilePlatform::health()],
            default      => ['ov' => MobilePlatform::overview(), 'scorecard' => MobilePlatform::scorecard()],
        };

        return view('mobile-platform.index', $data);
    }

    /** بياناتُ تبويب الأجهزة: تفصيلُ جهازٍ/جلسةٍ، أو قائمةٌ مُصفَّحةٌ مُرشَّحة */
    private function devicesData(Request $r): array
    {
        if (($iid = (string) $r->query('install', '')) !== '') {
            return ['view' => 'device', 'device' => MobilePlatform::deviceDetail($iid)];
        }
        if (($sid = (string) $r->query('session', '')) !== '') {
            return ['view' => 'session', 'session' => MobilePlatform::session($sid)];
        }

        $sub = in_array($v = (string) $r->query('view', 'sessions'), ['sessions', 'installs'], true) ? $v : 'sessions';
        $filters = [
            'platform' => (string) $r->query('platform', ''),
            'status'   => (string) $r->query('status', ''),
            'q'        => (string) hub_str($r->query('q', '')),
        ];

        return [
            'view'     => $sub,
            'filters'  => $filters,
            'rows'     => $sub === 'installs' ? MobilePlatform::installations($filters) : MobilePlatform::sessions($filters),
        ];
    }

    /**
     * بياناتُ تبويب الدفع (§16–19): حالةٌ صادقةٌ للمزوّد (حضورٌ لا قيمة) + عدُّ الرموز
     * الحيّة + تفصيلُ التسليم (لماذا فشل) + سجلٌّ مُرشَّحٌ مُصفَّح + عددُ أجهزةِ المُختبِر.
     */
    private function pushData(Request $r): array
    {
        $filters = [
            'status'   => (string) $r->query('status', ''),
            'provider' => (string) $r->query('provider', ''),
        ];

        return [
            'push'      => MobilePlatform::push(),
            'stats'     => MobilePlatform::pushDeliveryStats(),
            'breakdown' => MobilePlatform::deliveryBreakdown(),
            'filters'   => $filters,
            'log'       => MobilePlatform::deliveries($filters),
            'myTokens'  => self::myActiveTokens()->count(),
        ];
    }

    /** رموزُ الدفعِ الحيّةُ للمستخدمِ الحاليّ وحدَه (لاختبارِ الدفعِ الآمن) */
    private static function myActiveTokens()
    {
        $u = auth()->user();
        if (! $u || ! hub_has_col('push_tokens', 'token')) return collect();

        return PushToken::active()->where('user_id', $u->id)->orderBy('id')->get();
    }

    /**
     * **اختبارُ دفعٍ إداريٌّ آمن** (§19) — يُرسِل إشعاراً تجريبيّاً عامّاً إلى **رموزِ
     * المُختبِرِ الحيّةِ وحدَها** (جهازُه هو، لا جهازَ غيرِه — لا مراقبة)، عبر المزوّدِ
     * القائم **دون تجاوزِ ضبطٍ**: المزوّدُ الصفريُّ يقول `not_configured` صدقاً لا نجاحاً
     * مزيّفاً. الحمولةُ عامّةٌ (بلا نصٍّ حسّاس). النتيجةُ صادقةٌ لكلِّ محاولة، والاختبارُ
     * مُدقَّقٌ عبر `hub_audit` (بلا رمزٍ ولا سرّ). **لا يُنشئ صفَّ `push_deliveries`**
     * (ليس تفريعَ إشعارٍ حقيقيّ) كي لا يلوّثَ إحصاءَ الإنتاج.
     */
    public function pushTest(Request $r)
    {
        $this->gate();
        $u = auth()->user();
        $tokens = self::myActiveTokens();

        if ($tokens->isEmpty()) {
            return back()->with('warn', 'لا جهازَ مُسجَّلٌ باسمك لاختبار الدفع — سجّل جهازك في التطبيق أوّلاً');
        }

        $provider = PushService::provider();
        $payload = [
            'title'    => 'إشعارٌ تجريبيّ',
            'body'     => PushService::GENERIC_BODY,
            'category' => 'test',
            'data'     => ['category' => 'test'],
        ];

        $counts = [];
        foreach ($tokens as $tok) {
            try {
                $st = $provider->send((string) $tok->token, (string) $tok->platform, $payload)->status;
            } catch (\Throwable $e) {
                report($e);
                $st = 'failed';
            }
            $counts[$st] = ($counts[$st] ?? 0) + 1;
        }

        hub_audit('اختبارُ دفعٍ إداريّ', null, null, $u->name,
            ['after' => ['push_test' => ['driver' => $provider->name(), 'devices' => $tokens->count(), 'results' => $counts]]]);

        // رسالةٌ صادقةٌ عن النتيجة — لا تزييف
        if (($counts['not_configured'] ?? 0) === $tokens->count()) {
            return back()->with('warn', 'المزوّدُ غير مُهيّأ — لم يُرسَل شيء (NOT_CONFIGURED صدقاً، لا نجاحٌ مزيّف)');
        }
        $summary = collect($counts)->map(fn ($c, $s) => "{$s}×{$c}")->implode(' · ');

        return back()->with('ok', "📨 نُفِّذ الاختبارُ على {$tokens->count()} جهاز — {$summary}");
    }

    /**
     * **إبطالُ جلسةِ جوال** (§13) — عبر السكّة القائمة `MobileSessionService::revokeSession`
     * (لا حذفَ صفٍّ — يبقى الشاهد)، مع تدقيقٍ. مقصورٌ على المُصرَّح له (مالك/رايةُ mobile).
     */
    public function revokeSession(Request $r, string $id)
    {
        $this->gate();
        $s = MobileSession::find($id);
        abort_unless($s, 404);

        if (! $s->revoked_at) {
            MobileSessionService::revokeSession($s, 'إبطالٌ إداريٌّ من مركز منصّة الجوال');
            hub_audit('إبطالُ جلسةِ جوال', null, null, $s->user?->name,
                ['after' => ['mobile_session' => $s->id]]);
        }

        return back()->with('ok', '🔌 أُبطِلت الجلسة — يخرج جهازُها عند أول طلب');
    }
}
