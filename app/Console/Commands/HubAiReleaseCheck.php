<?php

namespace App\Console\Commands;

use App\Support\AiReleaseCheck;
use Illuminate\Console\Command;

/**
 * **بوّابةُ إطلاقِ مسارِ الذكاء** (المرحلة ٥ · W12) — تقرأ ولا تكتب.
 *
 * ```bash
 * php artisan hub:ai-release-check            # جدولٌ يُقرَأ
 * php artisan hub:ai-release-check --json     # نتيجةٌ تُقرَأ آليّاً
 * php artisan hub:ai-release-check --strict   # WARN تُسقط كما يُسقط FAIL
 * ```
 *
 * ── **ورمزُ الخروجِ يقول الحكم** ──
 *
 * `0` لـ`PASS` · `1` لـ`FAIL` · و`WARN` **تعود `0` افتراضاً و`1` مع
 * `--strict`**. والافتراضُ متساهلٌ عن قصد: البوّابةُ تُشغَّل في CI على مستودعٍ
 * بلا مزوّدٍ ولا نموذجٍ مُهيَّأ، فكلُّ فحصِ تشغيلٍ فيها `WARN` بطبيعتِه — ولو
 * أسقطت لَصارت ضجيجاً يُعطَّل بعد أسبوع.
 *
 * **ولا يعني ذلك أنّ `WARN` نجاح.** المخرَجُ يطبعها مفصّلةً بسببِها ووجهةِ
 * إصلاحِها، والحكمُ المطبوعُ يقولها باسمِها: **«نجاحٌ بتحفّظات»**.
 */
final class HubAiReleaseCheck extends Command
{
    protected $signature = 'hub:ai-release-check
        {--json : يطبع نتيجةً مقروءةً آليّاً}
        {--strict : يجعل WARN تُسقط كما تُسقط FAIL}';

    protected $description = 'بوّابةُ إطلاقِ مسارِ الذكاء — قراءةٌ فقط، بلا نداءِ مزوّدٍ ولا كلفة';

    /** الحكمُ المطبوعُ بالعربيّة — والرمزُ الإنجليزيُّ يبقى للآلة */
    private const VERDICT = [
        AiReleaseCheck::PASS => 'RELEASE GATE PASS — بوّابةُ الإطلاقِ مفتوحة',
        AiReleaseCheck::WARN => 'RELEASE GATE PASS WITH WARNINGS — نجاحٌ بتحفّظاتٍ تُقرَأ',
        AiReleaseCheck::FAIL => 'RELEASE GATE BLOCKED — شرطُ إطلاقٍ منقوض',
    ];

    public function handle(): int
    {
        $r = AiReleaseCheck::run();

        if ($this->option('json')) {
            $this->line((string) json_encode($r,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $this->exit($r);
        }

        $this->table(
            ['', 'المعرّف', 'الفحص', 'السبب'],
            array_map(static fn (array $c) => [
                match ($c['status']) {
                    AiReleaseCheck::PASS => '✔',
                    AiReleaseCheck::WARN => '⚠',
                    default              => '✘',
                },
                $c['id'],
                $c['title'],
                $c['reason'],
            ], $r['checks'])
        );

        // **وكلُّ ما ليس `PASS` يُعاد بوجهةِ إصلاحِه** — فلا يُترَك القارئُ يبحث
        foreach ($r['checks'] as $c) {
            if ($c['status'] === AiReleaseCheck::PASS || $c['fix'] === null) continue;
            $this->line("  · [{$c['id']}] → {$c['fix']}");
        }

        $counts = $r['counts'];
        $this->newLine();
        $this->line("PASS {$counts[AiReleaseCheck::PASS]}"
            . " · WARN {$counts[AiReleaseCheck::WARN]}"
            . " · FAIL {$counts[AiReleaseCheck::FAIL]}");

        $line = self::VERDICT[$r['verdict']];
        match ($r['verdict']) {
            AiReleaseCheck::PASS => $this->info($line),
            AiReleaseCheck::WARN => $this->warn($line),
            default              => $this->error($line),
        };

        return $this->exit($r);
    }

    private function exit(array $r): int
    {
        if ($r['verdict'] === AiReleaseCheck::FAIL) return self::FAILURE;

        return $r['verdict'] === AiReleaseCheck::WARN && $this->option('strict')
            ? self::FAILURE : self::SUCCESS;
    }
}
