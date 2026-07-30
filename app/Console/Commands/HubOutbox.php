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
        OutboxMessage::where('state', 'sending')
            ->where('created_at', '<', now()->subMinutes(10))
            ->update(['state' => 'queued']);

        $batch = OutboxMessage::where('state', 'queued')
            ->whereIn('channel', ['tg', 'mail'])
            ->orderBy('created_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($batch->isEmpty()) { $this->info('لا رسائل بانتظار الإرسال'); return self::SUCCESS; }

        $sent = 0; $failed = 0;
        foreach ($batch as $msg) {
            // sending أولاً حتى لا تُرسَل مرتين لو تداخل تشغيلان
            if (! OutboxMessage::where('id', $msg->id)->where('state', 'queued')->update(['state' => 'sending'])) continue;

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

        return self::SUCCESS;
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
