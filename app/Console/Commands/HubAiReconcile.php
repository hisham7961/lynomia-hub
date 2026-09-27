<?php

namespace App\Console\Commands;

use App\Support\Ai\Center\AiReconcile;
use Illuminate\Console\Command;

/**
 * **تصالحُ حالةِ الذكاءِ بعد استعادةٍ أو ترحيل** (المرحلة ٢ · W9 · §١٧).
 *
 * تنصّ خطّةُ التعافي: «**فيلزم فحصُ تصالحٍ بعد كلِّ استعادة** — نموذجٌ في Hub
 * بلا نظيرٍ في البوّابة يُوسَم `orphaned` ويُستبعَد من التوجيهِ تلقائيّاً».
 * وهذا الأمرُ هو تلك الخطوةُ، ليُوضَع في كتيّبِ التشغيلِ بلا نقرةِ شاشة.
 *
 * **والمعاينةُ افتراضٌ لا الكتابة**: أمرٌ يُشغَّل في كتيّبِ تشغيلٍ ويكتب بلا
 * سؤالٍ خطرٌ. والكتابةُ بعلمٍ صريح `--apply`.
 */
class HubAiReconcile extends Command
{
    protected $signature = 'hub:ai-reconcile {--apply : يكتب الوسمَ ويرفعه — وبدونه معاينةٌ لا تكتب}';

    protected $description = 'مطابقةُ نماذجِ Hub بما تُعلنه بوّابةُ النماذج — ووسمُ اليتيمِ منها';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $r     = AiReconcile::run($apply);

        if (! $r['ok']) {
            $this->error('تعذّر التصالح: ' . $r['error']);

            return self::FAILURE;
        }

        $this->line('عندنا ' . $r['counts']['hub_models'] . ' نموذجاً · وتُعلن البوّابةُ '
            . $r['counts']['live_models']);

        foreach ([
            ['orphaned',        'يتيمٌ (لا تُعلنه البوّابة)'],
            ['restored',        'عاد فرُفع عنه الوسم'],
            ['unregistered',    'عند البوّابةِ باعتمادِنا ولا صفَّ له — **يُبلَّغ ولا يُستورَد**'],
            ['credential_gone', 'اعتمادٌ عندنا بلا أثرٍ في الخزنة'],
        ] as [$key, $label]) {
            if ($r[$key] === []) continue;
            $this->warn($label . ': ' . count($r[$key]));
            foreach ($r[$key] as $n) $this->line('  · ' . $n);
        }

        if ($r['error'] !== null) $this->warn($r['error']);

        $this->info($r['applied']
            ? 'كُتب الوسمُ — واليتيمُ مُستبعَدٌ من التوجيهِ الآن'
            : 'معاينةٌ فقط — أضِف ‎--apply لكتابةِ الوسم');

        return self::SUCCESS;
    }
}
