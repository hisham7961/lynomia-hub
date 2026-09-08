<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\MobilePlatform;
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
            'operations' => ['health' => MobilePlatform::health()],
            default      => ['ov' => MobilePlatform::overview(), 'scorecard' => MobilePlatform::scorecard()],
        };

        return view('mobile-platform.index', $data);
    }
}
