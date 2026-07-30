<?php

namespace App\Console\Commands;

use App\Models\OutboxMessage;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * عامل تسليم الرسائل الصادرة — يُفرغ صف outbox فعلياً:
 *  tg   → تلجرام عبر Bot API (التوكن من الإعداد notify.tg_token)
 *  mail → بريد عبر إعدادات Laravel (MAIL_* في .env)
 *
 * الوجهة: target في الرسالة، وإلا تُستنتج من تفضيلات المستخدم
 * (notify_prefs.tg / بريده)، وإلا قناة الشركة العامة notify.tg_chat.
 */
class HubOutbox extends Command
{
    protected $signature = 'hub:outbox {--retry : إعادة صف الرسائل الفاشلة} {--limit=50}';
    protected $description = 'إرسال رسائل outbox المصفوفة (تلجرام + بريد)';

    public function handle(): int
    {
        if ($this->option('retry')) {
            $n = OutboxMessage::where('state', 'failed')->update(['state' => 'queued', 'error' => null]);
            $this->info("أُعيد صف {$n} رسالة فاشلة");
        }

        // رسائل علقت في sending (انقطاع سابق) لأكثر من ١٠ دقائق تعود للصف
        // من لحظة الحجز لا الإنشاء — وإلا خطف تشغيلٌ رسالةً يرسلها تشغيلٌ آخر الآن فتُرسل مرتين
        OutboxMessage::where('state', 'sending')
            ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinutes(10)))
            ->update(['state' => 'queued', 'claimed_at' => null]);

        $batch = OutboxMessage::where('state', 'queued')
            ->whereIn('channel', ['tg', 'mail'])
            ->orderBy('created_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($batch->isEmpty()) {
            $this->info('لا رسائل بانتظار الإرسال');
            $this->webhooks();
            \App\Models\Setting::updateOrCreate(['key' => 'heartbeat.outbox'], ['value' => now()->toIso8601String()]);
            \Illuminate\Support\Facades\Cache::forget('settings:all');
            return self::SUCCESS;
        }

        $sent = 0; $failed = 0;
        foreach ($batch as $msg) {
            // sending أولاً حتى لا تُرسَل مرتين لو تداخل تشغيلان
            if (! OutboxMessage::where('id', $msg->id)->where('state', 'queued')
                    ->update(['state' => 'sending', 'claimed_at' => now()])) continue;

            try {
                match ($msg->channel) {
                    'tg'   => $this->telegram($msg),
                    'mail' => $this->mail($msg),
                };
                $msg->forceFill(['state' => 'sent', 'error' => null, 'delivered_at' => now()])->save();
                $sent++;
            } catch (\Throwable $e) {
                $msg->forceFill(['state' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 390)])->save();
                $failed++;
                $this->error("✗ {$msg->channel}: " . mb_substr($e->getMessage(), 0, 120));
            }
        }

        $this->info("أُرسل: {$sent} · فشل: {$failed}" . ($failed ? ' — أعدها لاحقاً بـ --retry' : ''));

        $this->webhooks();

        \App\Models\Setting::updateOrCreate(['key' => 'heartbeat.outbox'], ['value' => now()->toIso8601String()]);
        \Illuminate\Support\Facades\Cache::forget('settings:all');
        return self::SUCCESS;
    }

    /** تسليم Webhooks المستحقة (المصفوفة الآن أو التي حان موعد إعادتها) */
    protected function webhooks(): void
    {
        if ($this->option('retry')) {
            $n = \App\Models\WebhookDelivery::where('state', 'failed')
                ->update(['state' => 'queued', 'next_at' => null, 'tries' => 0, 'error' => null]);
            if ($n) $this->info("أُعيد صف {$n} تسليم webhook فاشل");
        }

        // تسليمات علقت في sending (انقطاع سابق) تعود للصف — بالقياس من لحظة الحجز
        \App\Models\WebhookDelivery::where('state', 'sending')
            ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinutes(10)))
            ->update(['state' => 'queued', 'claimed_at' => null]);

        // التصفية على مستوى الاستعلام لا بعده: كانت الدفعة تُقتطع أولاً ثم تُصفّى في PHP،
        // فاشتراكٌ معطَّل بمتراكمات قديمة يملأ الدفعة كلها ويُصفّى بالكامل — فيشلّ كل
        // الاشتراكات الحيّة إلى الأبد. الآن لا يدخل الدفعةَ إلا تسليمٌ اشتراكه جاهز فعلاً.
        $due = \App\Models\WebhookDelivery::with('webhook')
            ->where('state', 'queued')
            ->where(fn ($q) => $q->whereNull('next_at')->orWhere('next_at', '<=', now()))
            ->whereExists(fn ($q) => $q->from('webhooks')
                ->whereColumn('webhooks.id', 'webhook_deliveries.webhook_id')
                ->where('webhooks.active', true)
                ->where(fn ($w) => $w->whereNull('webhooks.paused_until')
                                     ->orWhere('webhooks.paused_until', '<=', now())))
            ->orderBy('created_at')
            ->limit((int) $this->option('limit'))
            ->get();

        // تقليم: التسليمات المسلَّمة أقدم من ٣٠ يوماً لا قيمة لها والجدول بلا سقف
        \App\Models\WebhookDelivery::where('state', 'sent')
            ->where('created_at', '<', now()->subDays(30))->limit(500)->delete();

        if ($due->isEmpty()) return;

        $ok = 0; $fail = 0;
        foreach ($due as $d) {
            // منع الإرسال المزدوج لو تداخل تشغيلان — نفس نمط outbox
            if (! \App\Models\WebhookDelivery::where('id', $d->id)->where('state', 'queued')
                    ->update(['state' => 'sending', 'claimed_at' => now()])) continue;
            \App\Support\WebhookDispatcher::send($d) ? $ok++ : $fail++;
        }
        $this->info("Webhooks — نجح: {$ok} · فشل/أُعيد جدولته: {$fail}");
    }

    protected function telegram(OutboxMessage $msg): void
    {
        $token = (string) setting('notify.tg_token', '');
        if ($token === '') throw new \RuntimeException('إعداد notify.tg_token فارغ — ضع توكن البوت من شاشة الإعدادات');

        $chat = $msg->target ?: $this->userPref($msg->user_id, 'tg') ?: (string) setting('notify.tg_chat', '');
        if ($chat === '') throw new \RuntimeException('لا وجهة تلجرام: لا target ولا tg في تفضيلات المستخدم ولا notify.tg_chat');

        $resp = Http::timeout(10)->asForm()->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chat,
            'text'    => $msg->text,
        ]);
        if (! $resp->successful() || ! $resp->json('ok')) {
            throw new \RuntimeException('تلجرام رفض الإرسال: ' . mb_substr((string) $resp->body(), 0, 200));
        }
    }

    protected function mail(OutboxMessage $msg): void
    {
        $to = $msg->target ?: $this->userPref($msg->user_id, 'email') ?: User::find($msg->user_id)?->email;
        if (! $to) throw new \RuntimeException('لا بريد وجهة للرسالة');

        Mail::raw($msg->text, function ($m) use ($to) {
            $m->to($to)->subject(setting('app.name', config('app.name')) . ' — تنبيه');
        });
    }

    /** قيمة من تفضيلات إشعارات المستخدم (عمود notify_prefs JSON) */
    protected function userPref(?string $userId, string $key): ?string
    {
        if (! $userId) return null;
        $u = User::find($userId);
        if (! $u) return null;
        $prefs = is_array($u->notify_prefs) ? $u->notify_prefs : (json_decode($u->notify_prefs ?? '[]', true) ?: []);
        $v = trim((string) ($prefs[$key] ?? ''));

        return $v !== '' ? $v : null;
    }
}
