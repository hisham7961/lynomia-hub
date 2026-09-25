<?php

namespace App\Console\Commands;

use App\Support\Ai\Catalog\AiAuthSchemas;
use App\Support\Ai\Catalog\AiProviderCoverage;
use App\Support\Ai\Catalog\AiProviderRegistry;
use Illuminate\Console\Command;

/**
 * **يكتب مصفوفةَ التغطيةِ وثيقةً** — مولَّدةً من الخريطةِ لا مكتوبةً بيد.
 *
 * وبـ`--check` يقارن فيسقط إن انحرفت الوثيقةُ عن الخريطة — فلا يبقى في
 * المستودعِ جدولُ تغطيةٍ يصف حالةً انقضت.
 */
final class HubAiCoverage extends Command
{
    protected $signature = 'hub:ai-coverage
        {--out=docs/ai-hub/21-coverage-matrix.md : وجهةُ الكتابة}
        {--check : يقارن ولا يكتب}';

    protected $description = 'يولّد مصفوفةَ تغطيةِ المزوّدين من خريطةِ السجلّ';

    public function handle(): int
    {
        $md  = $this->render();
        $out = base_path((string) $this->option('out'));

        if ($this->option('check')) {
            $now = is_file($out) ? (string) file_get_contents($out) : '';
            if (trim($now) !== trim($md)) {
                $this->error('مصفوفةُ التغطيةِ منحرفةٌ عن الخريطة — أعِد التوليد.');

                return self::FAILURE;
            }
            $this->info('مصفوفةُ التغطيةِ مطابقةٌ للخريطة.');

            return self::SUCCESS;
        }

        file_put_contents($out, $md);
        $s = AiProviderCoverage::summary();
        $this->info("وُلِّدت مصفوفةُ {$s['total']} مزوّداً ({$s['configurable']} قابلاً للإعداد) → " . $this->option('out'));

        return self::SUCCESS;
    }

    private function render(): string
    {
        $s      = AiProviderCoverage::summary();
        $counts = AiProviderCoverage::counts();
        $lines  = [];

        $lines[] = '# مصفوفةُ تغطيةِ المزوّدين — **مولَّدةٌ لا تُحرَّر**';
        $lines[] = '';
        $lines[] = '> تُولَّد بـ`php artisan hub:ai-coverage` من `config/ai_providers.php`،';
        $lines[] = '> وتلك تُولَّد من قياسِ مصدرِ LiteLLM. **وتحريرُ هذا الملفِّ بيدٍ يُمحى**';
        $lines[] = '> عند أوّلِ توليد؛ والتصحيحُ موضعُه قاعدةٌ في `AiProviderRegistry`.';
        $lines[] = '';
        $lines[] = '## الخلاصة';
        $lines[] = '';
        $lines[] = '| القياس | العدد |';
        $lines[] = '|--------|------|';
        $lines[] = '| إصدارُ LiteLLM المقيس | `' . ($s['litellm_version'] ?? '?') . '` |';
        $lines[] = '| المزوّدون كلُّهم | ' . $s['total'] . ' |';
        $lines[] = '| **القابلون للإعدادِ من Hub** | **' . $s['configurable'] . '** |';
        $lines[] = '';
        $lines[] = '| الحالة | العدد | معناها |';
        $lines[] = '|--------|------|--------|';
        foreach (AiProviderCoverage::ORDER as $st) {
            $lines[] = '| `' . $st . '` | ' . ($counts[$st] ?? 0) . ' | ' . (AiProviderCoverage::LABELS[$st] ?? '') . ' |';
        }
        $lines[] = '';
        $lines[] = '### عائلاتُ المصادقةِ على القابلِ للإعداد';
        $lines[] = '';
        $lines[] = '| العائلة | العدد | الوصف |';
        $lines[] = '|---------|------|-------|';
        foreach (AiProviderCoverage::byAuth() as $family => $n) {
            $lines[] = '| `' . $family . '` | ' . $n . ' | ' . AiAuthSchemas::label($family) . ' |';
        }
        $lines[] = '';
        $lines[] = '### أوضاعُ اكتشافِ النماذجِ على القابلِ للإعداد';
        $lines[] = '';
        $lines[] = '| الوضع | العدد |';
        $lines[] = '|-------|------|';
        foreach (AiProviderCoverage::byDiscovery() as $mode => $n) {
            $lines[] = '| `' . $mode . '` | ' . $n . ' |';
        }
        $lines[] = '';
        $lines[] = '## الاستثناءاتُ بأسبابِها';
        $lines[] = '';
        $lines[] = '**ولا سطرَ هنا معناه «لم يُضَف بعد».** كلُّ صفٍّ سببُه تقنيٌّ مقروءٌ من';
        $lines[] = 'قياسِ المصدر: إمّا أنّ المزوّدَ لا سطحَ محادثةٍ له في البوّابةِ أصلاً،';
        $lines[] = 'وإمّا أنّ مصادقتَه لا تُدخَل من شاشةٍ البتّة.';
        $lines[] = '';
        foreach (AiProviderCoverage::exceptions() as $why => $who) {
            sort($who);
            $lines[] = '- **' . $why . '** (' . count($who) . '): `' . implode('` · `', $who) . '`';
        }
        $lines[] = '';
        $lines[] = '## المصفوفةُ كاملةً';
        $lines[] = '';
        $lines[] = '| المزوّد (اسمُ البوّابة) | الحالة | المصادقة | اكتشافُ النماذج | ملاحظة |';
        $lines[] = '|---|---|---|---|---|';
        foreach (AiProviderCoverage::matrix() as $r) {
            $note = $r['configurable'] ? '' : AiProviderCoverage::reasonText($r['reason']);
            $lines[] = '| `' . $r['slug'] . '` | `' . $r['status'] . '` | '
                . ($r['auth'] === null ? '—' : '`' . $r['auth'] . '`') . ' | '
                . ($r['configurable'] ? '`' . $r['discovery'] . '`' : '—') . ' | ' . $note . ' |';
        }
        $lines[] = '';
        $lines[] = '## كيف يُضاف مزوّدٌ مستقبلاً';
        $lines[] = '';
        $lines[] = '**في الحالةِ الغالبة: لا شيء.** مزوّدٌ جديدٌ في ترقيةِ البوّابةِ يظهر في';
        $lines[] = '`GET /model/settings`، ويسقط إلى العائلةِ الافتراضيّةِ `'
            . AiProviderRegistry::DEFAULT_AUTH . '` فيُنتج';
        $lines[] = 'مدخلَ كتالوجٍ صالحاً بلا سطرِ شيفرةٍ ولا سطرِ إعداد. ويكفي **تحديثُ';
        $lines[] = 'قائمةِ المزوّدين** من شاشةِ المزوّدين.';
        $lines[] = '';
        $lines[] = 'وإن كان شكلُه مختلفاً عن الافتراض:';
        $lines[] = '';
        $lines[] = '1. أعِد القياسَ على الإصدارِ الجديد:';
        $lines[] = '   `python3 deploy/litellm/tools/measure_providers.py <مصدر> > deploy/litellm/measured/litellm-<نسخة>.json`';
        $lines[] = '2. `php artisan hub:ai-provider-map` ثمّ `php artisan hub:ai-coverage`.';
        $lines[] = '3. إن لم تُصِبه قاعدةٌ قائمة، **أضِف قاعدةً نمطيّةً** في';
        $lines[] = '   `AiProviderRegistry::classify()` — لا سطراً باسمِه.';
        $lines[] = '4. وإن كان شكلُه فريداً حقّاً، أضِف **عائلةً** في `config/ai_auth.php`.';
        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }
}
