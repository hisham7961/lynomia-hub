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
    protected $signature = 'hub:digest {--dry : عرض التقرير دون إرساله} {--force : إرسالٌ ولو أُرسل اليوم}';
    protected $description = 'إرسال التقرير التنفيذي الدوري للمالكين';

    public function handle(): int
    {
        // (v2.399) تشغيلٌ يدويّ + الكرون الأسبوعي كانا يُرسلان التقريرَ مرّتين في اليوم نفسه
        if (! $this->option('dry') && ! $this->option('force')) {
            $last = setting('heartbeat.digest');
            if ($last && \Illuminate\Support\Carbon::parse($last)->isToday()) {
                $this->info('أُرسل التقرير اليوم — لا تكرار (استعمل --force)');
                return self::SUCCESS;
            }
        }
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

        // v2.124: نبض العقود — أرقام حقيقية فقط، والسطر يُحذف كله عند الصفر
        try {
            $active = \App\Models\Contract::where('status', 'ساري')->count();
            $exp30 = \App\Models\Contract::where('status', 'ساري')->whereNotNull('date_end')
                ->whereBetween('date_end', [now()->toDateString(), now()->addDays(30)->toDateString()])->count();
            $unsigned = \App\Models\SignRequest::where('status', 'بانتظار التوقيع')
                ->whereNull('cancelled_at')->count();
            $obDue = Schema::hasTable('contract_obligations')
                ? \App\Models\ContractObligation::whereNotIn('status', ['مكتمل', 'ملغي'])
                    ->whereNotNull('due')->whereDate('due', '<=', now()->addDays(30))->count()
                : 0;
            if ($active + $exp30 + $unsigned + $obDue > 0) {
                $lines[] = "📜 العقود: {$active} سارٍ · {$exp30} ينتهي خلال ٣٠ يوماً · {$unsigned} بانتظار توقيع · {$obDue} التزام يستحق خلال شهر.";
            }
        } catch (\Throwable $e) {
            // قسم العقود إثراء — لا يُسقط التقرير
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
                'text' => hub_fit($text, hub_col_max('outbox', 'text') ?? 790), 'state' => 'queued', 'created_at' => now()]);
        }

        \App\Support\Health::beat('digest');

        $this->info('أُرسل التقرير إلى ' . $owners->count() . ' مالك');

        return self::SUCCESS;
    }
}
