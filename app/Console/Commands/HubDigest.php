<?php

namespace App\Console\Commands;

use App\Models\HubNotification;
use App\Models\OutboxMessage;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * التقرير التنفيذي الدوري: يؤلّف ملخصاً من محرّكات النظام (التوصيات، المؤشرات،
 * صحة الشركة) ويصله للمالكين إشعاراً داخلياً + رسالة صادرة (تلجرام/بريد).
 * التوصيات تُحسب بصلاحيات مالكٍ فعلي كي تكون الأرقام كاملة لا مقصوصة بنطاق.
 */
class HubDigest extends Command
{
    protected $signature = 'hub:digest {--dry : عرض التقرير دون إرساله}';
    protected $description = 'إرسال التقرير التنفيذي الدوري للمالكين';

    public function handle(): int
    {
        $owner = User::whereNull('deleted_at')->where('status', 'نشط')
            ->whereHas('role', fn ($q) => $q->where('is_owner', true))->first();
        if (! $owner) {
            $this->warn('لا مالك نشط — لا تقرير');
            return self::SUCCESS;
        }

        // نُدخل المالك في الجلسة كي ترى المحرّكات المعتمدة على auth() مستخدماً فعلياً
        // (رادار الانتهاءات وغيره) — أرقام كاملة غير مقصوصة بنطاق
        \Illuminate\Support\Facades\Auth::login($owner);

        $recs = function_exists('hub_recommendations') ? hub_recommendations(true) : ['items' => [], 'counts' => []];
        $lines = ['📊 تقريرك التنفيذي — ' . now()->translatedFormat('j F Y')];

        $c = $recs['counts'] ?? [];
        if (array_sum($c ?: [0]) > 0) {
            $lines[] = "التوصيات: {$c['حرج']} حرجة · {$c['مهم']} مهمة · {$c['اطّلاع']} للاطّلاع.";
            foreach (array_slice(array_filter($recs['items'], fn ($i) => $i['sev'] === 'حرج'), 0, 5) as $i) {
                $lines[] = '• ' . $i['title'] . ' — ' . $i['why'];
            }
        } else {
            $lines[] = 'لا توصيات حرجة الآن — أو أن البيانات غير مكتملة بعد.';
        }

        // أبرز مؤشرات KPI المخصصة
        if (Schema::hasTable('kpi_defs') && function_exists('hub_kpis')) {
            $kpis = collect(hub_kpis($owner))->filter(fn ($k) => $k['value'] !== null)->take(5);
            foreach ($kpis as $k) {
                $v = rtrim(rtrim(number_format((float) $k['value'], 1), '0'), '.');
                $lines[] = '📈 ' . $k['name'] . ': ' . $v . ($k['unit'] ? ' ' . $k['unit'] : '');
            }
        }

        $text = implode("\n", $lines);

        if ($this->option('dry')) {
            $this->line($text);
            return self::SUCCESS;
        }

        // لكل مالك نشط: إشعار داخلي + رسالة صادرة (توجَّه لقناته في hub:outbox)
        $owners = User::whereNull('deleted_at')->where('status', 'نشط')
            ->whereHas('role', fn ($q) => $q->where('is_owner', true))->get();
        foreach ($owners as $u) {
            HubNotification::create(['user_id' => $u->id, 'kind' => 'digest',
                'text' => \Illuminate\Support\Str::limit($text, 590), 'read' => false, 'created_at' => now()]);
            OutboxMessage::create(['user_id' => $u->id, 'kind' => 'digest', 'channel' => 'tg',
                'text' => mb_substr($text, 0, 3500), 'state' => 'queued', 'created_at' => now()]);
        }

        \App\Models\Setting::updateOrCreate(['key' => 'heartbeat.digest'], ['value' => now()->toIso8601String()]);
        \Illuminate\Support\Facades\Cache::forget('settings:all');

        $this->info('أُرسل التقرير إلى ' . $owners->count() . ' مالك');

        return self::SUCCESS;
    }
}
