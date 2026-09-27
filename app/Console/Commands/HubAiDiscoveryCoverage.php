<?php

namespace App\Console\Commands;

use App\Support\Ai\Catalog\AiCatalog;
use App\Support\Ai\Gateway\LiteLlmAdmin;
use Illuminate\Console\Command;

/**
 * **تغطيةُ الاكتشاف — مقيسةٌ لا مُدّعاة.**
 *
 * يجيب سؤالاً واحداً بالأرقام: **كم مزوّداً يستطيع Hub اكتشافَ نماذجِه،
 * وبأيِّ آليّة، وكم يبقى للتسجيلِ اليدويِّ ولماذا؟**
 *
 * والمصدرانِ كلاهما موجودٌ في النظامِ لا مكتوبٌ هنا:
 *
 *  ① **تصنيفُ الاكتشافِ في الكتالوج** (`discovery`) — مولَّدٌ بقياسِ مصدرِ
 *     البوّابةِ في المرحلةِ الثانية.
 *  ② **كتالوجُ البوّابةِ الحيّ** (`--live`) — يُسأل فيُعَدُّ لكلِّ مفتاحِ
 *     مزوّدٍ كم نموذجاً يحمل فعلاً. وهو **القياسُ الحاسم**: تصنيفٌ يقول
 *     «كتالوج» ولا مُدخَلَ واحداً تحته **ادّعاءٌ** يُكشَف هنا.
 */
class HubAiDiscoveryCoverage extends Command
{
    protected $signature = 'hub:ai-discovery-coverage {--live : يسأل البوّابةَ ويَعُدّ المُدخَلاتِ فعلاً}
                                                      {--json : يطبع JSON بدل الجدول}';

    protected $description = 'تغطيةُ اكتشافِ النماذج: كم مزوّداً يُكتشَف تلقائيّاً وبأيِّ آليّة';

    public function handle(): int
    {
        $rows  = [];
        $live  = [];

        if ($this->option('live')) {
            $res = LiteLlmAdmin::modelCostMap();
            if (! $res['ok']) {
                $this->error('تعذّر قراءةُ كتالوجِ البوّابة: ' . (string) $res['error']);

                return self::FAILURE;
            }
            foreach ((array) ($res['data'] ?? []) as $entry) {
                if (! is_array($entry)) continue;
                $p = (string) ($entry['litellm_provider'] ?? '');
                if ($p === '') continue;
                $live[$p] = ($live[$p] ?? 0) + 1;
            }
        }

        foreach (AiCatalog::all() as $key => $def) {
            $llKey = (string) ($def['litellm_key'] ?? '');
            $rows[] = [
                'key'         => (string) $key,
                'litellm_key' => $llKey,
                'declared'    => (string) ($def['discovery'] ?? 'manual'),
                'catalog_models' => $this->option('live') ? (int) ($live[$llKey] ?? 0) : null,
            ];
        }

        usort($rows, static fn ($a, $b) => [$a['declared'], $a['key']] <=> [$b['declared'], $b['key']]);

        $byDeclared = [];
        $withModels = 0;
        $claimedButEmpty = [];
        foreach ($rows as $r) {
            $byDeclared[$r['declared']] = ($byDeclared[$r['declared']] ?? 0) + 1;
            if (($r['catalog_models'] ?? 0) > 0) $withModels++;
            if ($this->option('live') && $r['declared'] !== 'manual' && (int) $r['catalog_models'] === 0) {
                $claimedButEmpty[] = $r['key'];
            }
        }
        ksort($byDeclared);

        $summary = [
            'providers'            => count($rows),
            'by_declared_discovery' => $byDeclared,
            'with_catalog_models'  => $this->option('live') ? $withModels : null,
            'declared_auto_but_catalog_empty' => $this->option('live') ? $claimedButEmpty : null,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode(['summary' => $summary, 'providers' => $rows],
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('مزوّدون: ' . $summary['providers']);
        foreach ($byDeclared as $k => $n) $this->line("  {$k}: {$n}");
        if ($this->option('live')) {
            $this->info('لهم مُدخَلاتٌ في كتالوجِ البوّابة: ' . $withModels);
            if ($claimedButEmpty !== []) {
                $this->warn('يُعلَن لهم اكتشافٌ والكتالوجُ خالٍ: ' . count($claimedButEmpty));
            }
        }

        return self::SUCCESS;
    }
}
