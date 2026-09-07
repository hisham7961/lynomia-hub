<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\EndpointCommand;
use App\Models\EndpointDevice;
use App\Models\EndpointEvent;
use App\Models\EndpointPolicy;
use App\Models\User;

/**
 * **مركزُ النقاط الطرفية** — Work OS · الطور J · WP-J.3 · §43/§63.
 *
 * شاشتا قراءةٍ فوق سجل WP-J.1 وبروتوكول WP-J.2 — لا كاتبَ هنا: الأوامرُ تمرّ
 * بمسار `endpoints.command` المقفل (القائمةُ المغلقة + step-up للخطيرين)،
 * والتسجيلُ بمسار `enroll.mint`؛ هذا المركزُ يعرض ويصل لا يكتب.
 *
 * **الجمهورُ داخليّ حصراً** (قاعدةُ الطور J): حسابُ العميل ٤٠٤ فوق كل شيء —
 * `PortalGuard` قائمةٌ بيضاءُ لا تضمّ `endpoints.*`، ودفاعٌ ثانٍ هنا في العمق؛
 * والداخليُّ بلا رايةِ مراقبٍ ٤٠٣ (أسطولُ الأجهزة رقابةٌ لا شاشةَ عموم).
 * وعزلُ الشركة على كل قارئ: جهازُ شركةٍ خارج نطاقي ٤٠٤ لا تسريبَ وجود.
 *
 * **الوضعيّةُ صادقة (C15):** كلُّ فحصٍ يُعرَض **كما خُزّن** من نبضة الوكيل
 * الموقَّعة — القراءةُ التي منعها النظامُ خُزّنت 'not-configured' وتُعرَض
 * «غير مُهيّأ / تعذّرت القراءة»، **أبداً** لا «فعّالة». وسياساتُ USB بلا MDM
 * رصدٌ فقط — اللوحةُ تصارح «يتطلب MDM / رصدٌ فقط» ولا تدّعي حجباً.
 */
class EndpointCentreController extends Controller
{
    /** حارسُ المركز: العميلُ ٤٠٤ فوق المصفوفة، ثم مالك/مراقب — نمطُ enroll.mint */
    protected function guard(): void
    {
        abort_if(hub_is_client(auth()->user()), 404);
        abort_unless(hub_is_owner() || hub_monitor(), 403, 'مركزُ النقاط الطرفية للمالك أو المراقب');
    }

    /** الأسطول: قائمةٌ حتميّةُ الترتيب (hostname ثم id — لا قرعةَ إدراج) وبطاقاتُ حال */
    public function index()
    {
        $this->guard();

        $cids = hub_company_ids();
        $scoped = fn () => EndpointDevice::query()
            ->when($cids !== null, fn ($q) => $q->whereIn('company_id', $cids));

        $devices = $scoped()->orderBy('hostname')->orderBy('id')->limit(200)->get();

        // «الصامتُ عن النبض» يُقاس بمضاعفات الإيقاع المعلَن للوكلاء — القارئُ
        // الحقيقيّ للإعداد نفسِه الذي يبلّغه heartbeat (لا عتبةَ ثانية)
        $interval = max(1, (int) setting('endpoint.heartbeat_interval_min', 5));
        $silentBefore = now()->subMinutes($interval * 3);
        $silent = $devices->filter(fn ($d) => $d->status === 'active'
            && ($d->last_heartbeat_at === null || $d->last_heartbeat_at->lt($silentBefore)));

        // أسماءُ الحاملين دفعةً واحدة — لا استعلامَ لكل صفّ
        $holders = User::whereIn('id', $devices->pluck('employee_id')->filter()->unique())
            ->get(['id', 'name'])->keyBy('id');

        return view('endpoints.index', [
            'devices' => $devices,
            'holders' => $holders,
            'silentIds' => $silent->pluck('id')->all(),
            'intervalMin' => $interval,
            'stats' => [
                'total' => $devices->count(),
                'active' => $devices->where('status', 'active')->count(),
                'silent' => $silent->count(),
                'events7' => EndpointEvent::whereIn('device_id', $devices->pluck('id'))
                    ->where('created_at', '>=', now()->subDays(7))->count(),
            ],
        ]);
    }

    /** صفحةُ الجهاز: الهويّة + الوضعيّةُ الصادقة + سياسةُ USB + الأوامرُ والأحداث */
    public function show(string $id)
    {
        $this->guard();

        // عبرَ شركةٍ: ٤٠٤ واحد للمعدوم والأجنبيّ — لا تسريبَ وجود (نمطُ issue)
        $cids = hub_company_ids();
        $device = EndpointDevice::whereKey($id)
            ->when($cids !== null, fn ($q) => $q->whereIn('company_id', $cids))
            ->first();
        abort_unless($device, 404);

        // ترتيبٌ حتميّ في القائمتين: الأحدثُ أولاً وid كاسرُ التعادل (درسُ CLAUDE.md)
        $events = EndpointEvent::where('device_id', $device->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(30)->get();
        $commands = EndpointCommand::where('device_id', $device->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get();

        // السياسةُ المسنَدة — والغيابُ يُقال صادقاً (لا سياسةَ افتراضيةً تُدّعى)
        $policy = $device->policy_id ? EndpointPolicy::find($device->policy_id) : null;

        return view('endpoints.show', [
            'device' => $device,
            'events' => $events,
            'commands' => $commands,
            'policy' => $policy,
            'holder' => $device->employee_id ? User::find($device->employee_id) : null,
        ]);
    }
}
