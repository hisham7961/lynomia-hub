<?php

namespace App\Console\Commands;

use App\Support\Uptime;
use Illuminate\Console\Command;

/**
 * دورة الفحص الحيّ: كل هدفٍ مفعّلة مراقبته يُطلب فيُسجَّل توافره وزمنه.
 * المطفأ لا يُفحص — المراقبة اختيارٌ لا فرض، ولا تُرسَل طلباتٌ بلا إذن.
 */
class HubUptimeCheck extends Command
{
    protected $signature = 'hub:uptime-check {--module= : قصر الفحص على وحدة}';
    protected $description = 'فحص حيّ لتوافر السيرفرات والمواقع المراقَبة وتسجيل زمن الاستجابة';

    public function handle(): int
    {
        $only = $this->option('module');
        $n = $down = 0;

        foreach (Uptime::enabled() as [$mk, $row]) {
            if ($only && $mk !== $only) continue;
            $r = Uptime::check($mk, $row);
            $n++;
            if ($r['up'] === false) {
                $down++;
                $this->warn(sprintf('✗ %s — %s (%s)', $row->name ?? $row->id,
                    $r['error'] ?: ('رمز ' . $r['code']), $r['ms'] . 'ms'));
            } elseif ($r['up'] === null) {
                $this->line(sprintf('… %s — تُخطّي: %s', $row->name ?? $row->id, $r['error']));
            } else {
                $this->line(sprintf('✓ %s — %dms', $row->name ?? $row->id, $r['ms']));
            }
        }

        \App\Support\Health::beat('uptime', null, 'ok', $down ? "{$down} من {$n} معطّل" : null);
        $this->info("فُحص {$n} هدفاً، منها {$down} معطّل.");

        return self::SUCCESS;
    }
}
