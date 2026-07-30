<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\WebhookDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** مركز Webhooks — اشتراكات موقعة لأنظمة خارجية (n8n وأشباهه) */
class WebhookController extends Controller
{
    protected function gate(): void
    {
        abort_unless(auth()->user()?->role?->is_owner, 403, 'مركز Webhooks للمالكين فقط');
    }

    public function index()
    {
        $this->gate();

        return view('admin.webhooks', [
            'hooks'   => Webhook::withCount('deliveries')->orderBy('created_at')->get(),
            'modules' => collect(config('hub.modules'))->map(fn ($d) => $d['label']),
        ]);
    }

    public function store(Request $r)
    {
        $this->gate();
        $d = $r->validate([
            'name'   => ['required', 'string', 'max:120'],
            'url'    => ['required', 'url', 'max:500'],
            'events' => ['required', 'string', 'max:2000'],
        ], [], ['name' => 'الاسم', 'url' => 'الرابط', 'events' => 'الأحداث']);

        Webhook::create($d + ['secret' => 'whs_' . Str::random(40), 'active' => true]);

        return back()->with('ok', 'أُنشئ الاشتراك — انسخ السر من الجدول لتتحقق من التوقيع عند المستقبل');
    }

    public function toggle(string $id)
    {
        $this->gate();
        $h = Webhook::findOrFail($id);
        $h->forceFill(['active' => ! $h->active, 'paused_until' => null, 'fail_streak' => 0])->save();

        return back()->with('ok', $h->active ? 'فُعّل الاشتراك' : 'عُطّل الاشتراك');
    }

    public function destroy(string $id)
    {
        $this->gate();
        $h = Webhook::findOrFail($id);
        WebhookDelivery::where('webhook_id', $h->id)->delete();
        $h->delete();

        return back()->with('ok', 'حُذف الاشتراك وسجل محاولاته');
    }

    /** اختبار فوري: تسليم ping متزامن والنتيجة في الرسالة */
    public function test(string $id)
    {
        $this->gate();
        $h = Webhook::findOrFail($id);
        $d = WebhookDelivery::create([
            'webhook_id' => $h->id, 'event' => 'hub.ping', 'event_id' => (string) Str::uuid(),
            'payload' => json_encode([
                'event' => 'hub.ping', 'at' => now()->toIso8601String(),
                'note'  => 'اختبار من مركز Webhooks — وقّع الجسم بالسر وقارن بترويسة X-Hub-Signature',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'state' => 'queued', 'created_at' => now(),
        ]);
        $ok = WebhookDispatcher::send($d);
        $d->refresh();

        return back()->with($ok ? 'ok' : 'err',
            $ok ? "نجح الاختبار — HTTP {$d->code} خلال {$d->ms}ms"
                : 'فشل الاختبار: ' . ($d->error ?: 'بلا تفاصيل'));
    }

    /** سجل المحاولات لاشتراك واحد */
    public function log(string $id)
    {
        $this->gate();
        $h = Webhook::findOrFail($id);

        return view('admin.webhook_log', [
            'h'    => $h,
            'rows' => WebhookDelivery::where('webhook_id', $h->id)
                        ->orderByDesc('created_at')->paginate(25),
        ]);
    }

    /** إعادة إرسال تسليم بعينه فوراً */
    public function resend(string $id, string $did)
    {
        $this->gate();
        $d = WebhookDelivery::where('webhook_id', $id)->findOrFail($did);
        $ok = WebhookDispatcher::send($d);
        $d->refresh();

        return back()->with($ok ? 'ok' : 'err',
            $ok ? "أُعيد الإرسال بنجاح — HTTP {$d->code}" : 'فشلت الإعادة: ' . ($d->error ?: 'بلا تفاصيل'));
    }
}
