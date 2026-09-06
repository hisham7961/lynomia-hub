<?php

namespace App\Console\Commands;

use App\Models\Deployment;
use App\Support\Health;
use App\Support\SysMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * لقطةُ النظام كلَّ ٥ دقائق (WP-2.3 · spec §3.1/§3.12/§3.15) — في metric_points لا جدولَ جديد.
 *
 * **رخيصةٌ عمداً** (critic #38 · §16): تقرأ `Health::ready()` وحدها (قاعدة/خبيئة/تخزين/
 * مخطّط/إعداد — بضعةُ استعلامات) لا `Health::check()` الكامل (~٥٥ استعلاماً بلا كاش)،
 * ومعها قياسا المعالج والذاكرة من /proc — فلا يتحوّل «القياسُ» نفسُه عبئاً على استضافةٍ
 * مشتركة ٢٨٨ مرّةً في اليوم. والعدّاداتُ الثقيلة (عدُّ صفوفِ كل الجداول) تُكتب **يوميّاً**
 * فقط خلف حارسِ صفٍّ يوميّ. بها يُجاب «منذ متى؟» على كل رسمٍ في مركز التشغيل.
 */
class HubOpsSnapshot extends Command
{
    protected $signature = 'hub:ops-snapshot';
    protected $description = 'لقطةُ صحّة النظام وموارده كلَّ ٥ دقائق في السلسلة الزمنية + كشفُ تغيّر النسخة';

    public function handle(): int
    {
        $t0 = microtime(true);
        if (! Schema::hasTable('metric_points')) {
            $this->warn('جدول metric_points غائب — لا لقطة');

            return self::SUCCESS;
        }

        // حاويةُ ٥ دقائق: تشغيلان في الحاوية نفسِها (يدويٌّ فوق الكرون) يُحدّثان
        // النقطةَ نفسَها عبر المفتاح الفريد (module, record_id, metric, at) — لا تكرار.
        $bucket = hub_metric_bucket(now(), 5);

        $ready = Health::ready();
        hub_metric_put('ops', 'health', 'rank', (float) Health::rank($ready['status']), $bucket, 'auto',
            ['status' => $ready['status']]);

        // القياساتُ الأربعة الرخيصة — وما لا مصدرَ له لا يُكتب: «لا قياس» ليس صفراً
        $dbMs = $ready['components']['db']['data']['ms'] ?? null;
        if ($dbMs !== null) hub_metric_put('ops', 'sys', 'db_ms', (float) $dbMs, $bucket, 'auto');
        $diskPct = $ready['components']['storage']['data']['disk_pct'] ?? null;
        if ($diskPct !== null) hub_metric_put('ops', 'sys', 'disk_pct', (float) $diskPct, $bucket, 'auto');
        $cpu = SysMonitor::cpu();
        if (! empty($cpu['ok'])) hub_metric_put('ops', 'sys', 'cpu_pct', (float) $cpu['pct'], $bucket, 'auto');
        $mem = SysMonitor::memory();
        if (! empty($mem['ok'])) hub_metric_put('ops', 'sys', 'mem_pct', (float) $mem['pct'], $bucket, 'auto');

        $daily = $this->dailyTableCounts();
        $deployed = $this->detectDeployment();

        Health::beat('ops', (int) round((microtime(true) - $t0) * 1000), 'ok',
            'الجاهزية ' . $ready['status'] . ($deployed ? ' · نشرٌ مكتشَف ' . $deployed : ''));
        $this->info('لُقطت الجاهزية (' . $ready['status'] . ')'
            . ($daily ? " · {$daily} عدّادَ جدولٍ يوميّ" : '')
            . ($deployed ? " · سُجّل نشرٌ آليّ للنسخة {$deployed}" : ''));

        return self::SUCCESS;
    }

    /**
     * العدّاداتُ اليومية ('ops','db','rows:<جدول>') من SysMonitor::tableConsumers المخبّأة.
     * حارسُ الصفّ اليوميّ (critic #38): وجودُ نقطةٍ بتاريخ اليوم يعني أنّ المسحَ جرى —
     * فالتشغيلاتُ الـ٢٨٧ الباقية لا تعدّ صفوفَ ~٩٠ جدولاً، بل استعلامَ وجودٍ واحداً.
     */
    protected function dailyTableCounts(): int
    {
        $dayAt = now()->startOfDay();
        if (\App\Models\MetricPoint::where('module', 'ops')->where('record_id', 'db')
            ->where('at', $dayAt)->exists()) return 0;

        $n = 0;
        foreach (SysMonitor::tableConsumers() as $row) {
            // metric عرضُه ٤٠ — أطولُ 'rows:<جدول>' قائمٍ ٢٨ محرفاً، والقصُّ حزامُ أمانٍ لجدولٍ قادم
            hub_metric_put('ops', 'db', mb_substr('rows:' . $row['table'], 0, 40), (float) $row['n'], $dayAt, 'auto');
            $n++;
        }

        return $n;
    }

    /**
     * كشفُ النشر (§3.15): تغيّرُ `config('hub.version')` عن آخر نسخةٍ مرئيّة = نشرٌ وقع.
     * **البذرةُ أولاً** (critic #40): أولُ تشغيلٍ (تنصيبٌ جديد، بيئةُ اختبارٍ نظيفة) يبذر
     * `ops.last_version` **بلا** صفِّ نشرٍ — وإلا لوّث كلُّ اختبارٍ عدّادَ deployments
     * وقيودَ التدقيق. الصفُّ يُنشأ عبر النموذج فيَجري Auditable وختمُ السلسلة، **بلا
     * اختراع commit ولا بياناتِ GitHub** — يُسجَّل ما نعرفه فقط.
     */
    protected function detectDeployment(): ?string
    {
        $cur = Health::version();
        if ($cur === '') return null;
        $last = (string) setting('ops.last_version', '');
        if ($last === $cur) return null;

        $created = false;
        if ($last !== '') {
            try {
                $pending = (int) hub_pending_migrations();
                Deployment::create([
                    'ver' => mb_substr($cur, 0, 80),
                    'env' => mb_substr((string) config('app.env'), 0, 40),
                    'deployed_at' => now(),
                    'migrations' => mb_substr($pending ? "معلّقة: {$pending}" : 'مطبَّقة', 0, 40),
                    'notes' => 'سُجّل آلياً: لقطةُ التشغيل وجدت النسخةَ تغيّرت من ' . $last,
                    'meta' => ['auto' => true, 'from' => $last],
                ]);
                $created = true;
            } catch (\Throwable $e) {
                report($e);
            }
            // فشلَ الإنشاء ⇒ لا تُحدَّث البذرةُ، فيُعاد الكشفُ في التشغيلة التالية بدل أن يضيع النشرُ صامتاً
            if (! $created) return null;
        }

        \App\Models\Setting::updateOrCreate(['key' => 'ops.last_version'], ['value' => $cur]);
        Cache::forget('settings:all');   // كتابةٌ نادرة (بذرةٌ أو نشرٌ فعليّ) — الإبطالُ هنا مقبول

        return $created ? $cur : null;
    }
}
