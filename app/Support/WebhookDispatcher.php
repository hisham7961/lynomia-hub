<?php

namespace App\Support;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * مركز Webhooks: عند أي حدث (إنشاء/تعديل/تحول حالة) يُصفّ تسليم لكل اشتراك مطابق،
 * والإرسال الفعلي يتم في عامل التسليم (hub:outbox) بتوقيع HMAC وإعادات متباعدة.
 *
 * ترويسات كل طلب: X-Hub-Event، X-Hub-Event-Id (لمنع التكرار عند المستقبل)،
 * X-Hub-Signature: sha256=hex(hmac_sha256(body, secret)).
 */
class WebhookDispatcher
{
    /** فترات الإعادة بالدقائق حسب رقم المحاولة الفاشلة */
    public const BACKOFF = [1, 5, 15, 60, 180, 720];

    /** بعد كم فشلاً متتالياً يوقف الاشتراك مؤقتاً (ساعة) */
    public const PAUSE_AFTER = 10;

    /** @param string $event created|updated|status */
    public static function fire(string $event, string $module, Model $m, ?string $statusTo = null): void
    {
        try {
            // يشمل الموقوف مؤقتاً عمداً: الإيقاف تباعدٌ آلي لا إلغاء اشتراك، فأحداثه
            // تُصفّ بموعد ما بعد الإيقاف بدل أن تضيع. أما المعطَّل يدوياً فقرار صريح فتُهمل أحداثه.
            // تُقرأ مع **كل** كتابة في النظام — فتُخبّأ دقيقة وتُنسف عند أي تعديل اشتراك
            $hooks = \Illuminate\Support\Facades\Cache::remember('webhooks:active', 60,
                fn () => Webhook::where('active', true)->get());
            if ($hooks->isEmpty()) return;

            // الحدث الدلالي مُسمّى بنطاقه أصلاً (`invoice.paid`) فلا يُسبق باسم الوحدة،
            // والخام يبقى `module.event` كما كان — فاشتراكات الويبهوكس القائمة لا تتأثر.
            $name = str_contains($event, '.') ? $event : $module . '.' . $event;
            $hooks = $hooks->filter(fn ($h) => $h->wants($name));
            if ($hooks->isEmpty()) return;

            $payload = json_encode(self::payload($event, $module, $m, $statusTo),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $eventId = (string) Str::uuid();

            foreach ($hooks as $h) {
                $paused = $h->paused_until && $h->paused_until->isFuture();
                WebhookDelivery::create([
                    'webhook_id' => $h->id, 'event' => $name, 'event_id' => $eventId,
                    'payload' => $payload, 'state' => 'queued', 'created_at' => now(),
                    'next_at' => $paused ? $h->paused_until : null,
                ]);
            }
        } catch (\Throwable $e) {
            // الـ webhooks لا تكسر العملية الأصلية أبداً
        }
    }

    /** جسم الرسالة: حقول السجل من سجل الوحدات فقط — بلا أسرار ولا أعمدة داخلية */
    protected static function payload(string $event, string $module, Model $m, ?string $statusTo): array
    {
        $def = hub_mod($module);
        $data = [];
        foreach (($def['fields'] ?? []) as $f) {
            if (in_array($f['type'] ?? '', ['sec', 'file', 'img'], true)) continue;   // لا أسرار ولا مسارات ملفات
            $v = $m->{$f['col']} ?? null;
            $data[$f['key']] = $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v;
        }

        return [
            // الحدث الدلاليّ مُسمّى بنطاقه أصلاً (invoice.paid) فلا يُسبق باسم الوحدة
            // ثانيةً — تطبيعٌ يطابق اسمَ الحدث في fire وترويسةِ X-Hub-Event
            'event'     => str_contains($event, '.') ? $event : $module . '.' . $event,
            'module'    => $module,
            'label'     => $def['label'] ?? $module,
            'record_id' => (string) $m->id,
            'display'   => (string) ($m->{hub_display_col($module)} ?? $m->id),
            'status_to' => $statusTo,
            'by'        => auth()->user()?->name,
            'at'        => now()->toIso8601String(),
            'data'      => $data,
        ];
    }

    /**
     * كتابة حالة التسليم مباشرةً بالاستعلام لا بالنموذج.
     * السبب: عامل التسليم يحجز الصف بتحديث مباشر إلى «sending»، فتصير حالة النموذج
     * في الذاكرة مخالفة لما في القاعدة، وأي قيمة تُعاد لأصلها (queued ← queued)
     * تبدو «غير متسخة» فيتخطاها save() ويبقى الصف عالقاً في sending.
     */
    protected static function persist(WebhookDelivery $d, array $attrs): void
    {
        WebhookDelivery::whereKey($d->id)->update($attrs);
        $d->forceFill($attrs)->syncOriginal();
    }

    /**
     * الإرسال الفعلي لتسليم واحد. يحدّث المحاولة والاشتراك، ويجدول الإعادة عند الفشل.
     * يعيد true عند نجاح (رد 2xx).
     */
    public static function send(WebhookDelivery $d): bool
    {
        $h = $d->webhook;
        if (! $h) { $d->forceFill(['state' => 'failed', 'error' => 'اشتراك محذوف'])->save(); return false; }

        $t0 = microtime(true);
        $code = null; $err = null; $ok = false;

        try {
            // **لا اتّباع لإعادة التوجيه**: بوابة SSRF (hub_outbound_ok) تفحص الوجهة
            // وقت الإنشاء فقط، ووجهةٌ عامّة تردّ 302 نحو 169.254.169.254/الداخل كانت
            // تُتّبع فيصير التسليمُ مِجَسّاً على الشبكة الداخلية. نفس حارس Uptime.php.
            $resp = Http::withOptions(['allow_redirects' => false])
                ->timeout(10)
                ->withBody($d->payload, 'application/json')
                ->withHeaders([
                    'X-Hub-Event'     => $d->event,
                    'X-Hub-Event-Id'  => $d->event_id,
                    'X-Hub-Webhook'   => $h->id,
                    'X-Hub-Signature' => 'sha256=' . hash_hmac('sha256', $d->payload, $h->secret),
                ])->post($h->url);
            $code = $resp->status();
            $ok = $resp->successful();
            if (! $ok) $err = 'رد غير ناجح: HTTP ' . $code;
        } catch (\Throwable $e) {
            $err = mb_substr($e->getMessage(), 0, 390);
        }

        $ms = (int) round((microtime(true) - $t0) * 1000);

        if ($ok) {
            self::persist($d, ['state' => 'sent', 'tries' => $d->tries + 1, 'next_at' => null,
                               'code' => $code, 'ms' => $ms, 'error' => null, 'delivered_at' => now()]);
            $wasPaused = (bool) $h->paused_until;
            $h->forceFill(['fail_streak' => 0, 'paused_until' => null, 'runs' => $h->runs + 1,
                           'last_at' => now(), 'last_ok' => true])->save();
            // رفعُ الإيقاف يظهر فوراً: كان كاشُ الاشتراكات الحية يبقى قديماً
            // حتى دقيقة فتتأخر الأحداثُ اللاحقة بعد نجاحٍ يدويّ
            if ($wasPaused) \Illuminate\Support\Facades\Cache::forget('webhooks:active');

            return true;
        }

        $tries = $d->tries + 1;
        $retry = $tries <= count(self::BACKOFF);
        self::persist($d, [
            'state' => $retry ? 'queued' : 'failed', 'tries' => $tries,
            'next_at' => $retry ? now()->addMinutes(self::BACKOFF[$tries - 1]) : null,
            'code' => $code, 'ms' => $ms, 'error' => $err,
        ]);

        $streak = $h->fail_streak + 1;
        $h->forceFill([
            'fail_streak' => $streak, 'runs' => $h->runs + 1, 'last_at' => now(), 'last_ok' => false,
            'paused_until' => $streak >= self::PAUSE_AFTER ? now()->addHour() : $h->paused_until,
        ])->save();

        return false;
    }
}
