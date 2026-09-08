<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\MobileSession;
use App\Support\MobilePlatform;
use App\Support\MobileSessionService;
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
